<?php
/**
 * ShopInnKart Admin - Database backup and restore.
 *
 * The screen. Everything it does lives in includes/backup.php, which the cron
 * job bin/backup.php shares, so the nightly backup and the one taken by hand
 * are the same backup in the same format - a dump written by two code paths
 * is a dump that only one of them can read.
 *
 * A dump is the whole application in one file: every bcrypt hash to crack
 * offline, every address, every gateway secret. So it is not a settings
 * screen. It needs system.backup (no seeded role has it), the actor's own
 * password to create, download or restore one, and the file on disk is
 * encrypted - a stolen backups folder is a folder of noise rather than the
 * database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('system.backup');

require_once ADMIN_PATH . '/includes/rbac.php';
require_once INCLUDES_PATH . '/backup.php';

$selfUrl = admin_url('system/backup.php');

// Reading the folder can throw when a host refuses to create it. That is a
// message on the page, not a 500.
$dirError = '';
try {
    $dirStatus = backup_dir_status();
} catch (Throwable $e) {
    $dirError  = $e->getMessage();
    $dirStatus = ['path' => STORAGE_PATH . '/backups', 'exists' => false, 'writable' => false,
                  'outside_web_root' => false, 'guarded' => false, 'custom' => false, 'custom_requested' => ''];
}

/** The record behind an id in a POST, or a redirect with a message. */
function backup_record_or_bounce(int $id, string $back): array
{
    $record = $id > 0 ? Database::fetch('SELECT * FROM `backups` WHERE `id` = :id', ['id' => $id]) : null;
    if ($record === null) {
        flash('error', 'That backup record no longer exists.');
        redirect($back);
    }

    return $record;
}

/**
 * The passphrase to use for one request.
 *
 * A typed one wins over the configured one, because the reason to type one is
 * that this particular file has to be readable somewhere the settings of this
 * installation do not reach.
 */
function backup_request_passphrase(): string
{
    $typed = trim((string) input('passphrase', ''));
    return $typed !== '' ? $typed : backup_passphrase();
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------
$op = (string) input('op', '');

// ---------------------------------------------------------------------------
// Download - POST, not GET.
//
// It used to be a GET "because it changes nothing", but what it does is hand
// over every password hash and address in the shop. A GET is reachable from an
// <img> tag, a bookmark or a pasted link, is logged in proxies and browser
// history, and needs no CSRF token. So the whole database now leaves only on a
// POST that carries a CSRF token and the operator's own password.
// ---------------------------------------------------------------------------
if (!is_post() && (int) ($_GET['download'] ?? 0) > 0) {
    flash('error', 'Downloading a backup needs your password. Use the Download button on this page.');
    redirect($selfUrl);
}

if (is_post() && $op === 'download') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $record = backup_record_or_bounce(input_int('id'), $selfUrl);

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('download a database backup'));
        redirect($selfUrl);
    }

    $path = backup_resolve_path((string) $record['filename']);
    if ($path === null) {
        flash('error', 'The file for that backup is missing from the backups folder. You can delete the record below.');
        redirect($selfUrl);
    }

    log_activity('backup.downloaded', 'backup', (int) $record['id'],
        'Downloaded backup "' . basename($path) . '"');
    security_event('backup.downloaded', 'critical', [
        'backup_id' => (int) $record['id'],
        'filename'  => basename($path),
        'bytes'     => (int) filesize($path),
    ], (int) $admin['id'], 'admin');
    admin_after_write();

    // Decrypted on the way out, in frames, so the plaintext exists only in
    // memory and only one chunk at a time.
    try {
        $stream = BackupCipher::read($path, backup_request_passphrase());
        $first  = $stream->current();
    } catch (Throwable $e) {
        flash('error', 'That backup could not be read: ' . $e->getMessage());
        redirect($selfUrl);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // No Content-Length: the plaintext is longer than the file on disk, and
    // guessing it wrong truncates the download.
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'
        . preg_replace('/\.enc$/', '', basename($path)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $first;
    $stream->next();
    while ($stream->valid()) {
        echo $stream->current();
        flush();
        $stream->next();
    }
    exit;
}

if (is_post() && $op === 'create') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('create a database backup'));
        redirect($selfUrl);
    }

    $note   = mb_substr(trim((string) input('note', '')), 0, 255);
    $typed  = trim((string) input('passphrase', ''));
    $useOwn = (string) input('protection', '') === 'passphrase';

    if ($useOwn && mb_strlen($typed) < 12) {
        flash('error', 'A backup passphrase has to be at least 12 characters. '
            . 'It is the only thing between the file and whoever finds it.');
        redirect($selfUrl);
    }

    ignore_user_abort(true);

    try {
        $result = backup_create([
            'note'       => $note,
            'source'     => 'manual',
            'admin_id'   => (int) $admin['id'],
            'admin_name' => (string) $admin['name'],
            'passphrase' => $useOwn ? $typed : backup_passphrase(),
        ]);

        log_activity('backup.created', 'backup', $result['id'],
            'Created backup "' . $result['filename'] . '" - ' . $result['tables'] . ' tables, '
            . number_format($result['rows']) . ' rows, ' . format_bytes($result['bytes']));
        security_event('backup.created', 'high', [
            'backup_id'  => $result['id'],
            'filename'   => $result['filename'],
            'tables'     => $result['tables'],
            'rows'       => $result['rows'],
            'protection' => $result['protection'],
        ], (int) $admin['id'], 'admin');
        admin_after_write();

        flash('success', 'Backup created: ' . $result['tables'] . ' tables and '
            . number_format($result['rows']) . ' rows written to ' . $result['filename']
            . ' (' . format_bytes($result['bytes']) . ', ' . $result['protection'] . ').'
            . ($result['pruned'] > 0 ? ' ' . $result['pruned'] . ' older backup(s) removed by retention.' : ''));
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Database backup failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        security_event('backup.failed', 'high', ['error' => $e->getMessage()], (int) $admin['id'], 'admin');
        flash('error', 'The backup did not finish: ' . $e->getMessage()
            . ' Any partial file was left in the backups folder so you can inspect it.');
    }

    redirect($selfUrl);
}

if (is_post() && $op === 'verify') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $record = backup_record_or_bounce(input_int('id'), $selfUrl);
    $check  = backup_verify($record, backup_request_passphrase());

    Database::update('backups', [
        'verified_at'  => date('Y-m-d H:i:s'),
        'verify_error' => $check['ok'] ? null : mb_substr($check['error'], 0, 255),
    ], '`id` = :id', ['id' => (int) $record['id']]);

    if (!$check['ok']) {
        security_event('backup.verify_failed', 'high',
            ['backup_id' => (int) $record['id'], 'error' => $check['error']], (int) $admin['id'], 'admin');
    }
    admin_after_write();

    flash($check['ok'] ? 'success' : 'error', $check['ok']
        ? 'Checked: the file still matches its checksum and reads back as '
          . number_format($check['statements']) . ' SQL statements.'
        : 'That backup did not pass: ' . $check['error']);

    redirect($selfUrl);
}

if (is_post() && $op === 'selftest') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $test = backup_selftest(backup_request_passphrase());
    $_SESSION['_backup_selftest'] = $test;
    admin_after_write();

    flash($test['ok'] ? 'success' : 'error', $test['ok']
        ? 'The backup path works on this server.'
        : 'The backup path does not work here yet - see below.');

    redirect($selfUrl . '#selftest');
}

if (is_post() && $op === 'restore') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $record = backup_record_or_bounce(input_int('id'), $selfUrl);

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('restore a database backup'));
        redirect($selfUrl . '#restore');
    }

    // A typed word, not just a checkbox: restoring is the one action on this
    // screen with no undo, and a mis-click has to be impossible rather than
    // unlikely.
    if (strtoupper(trim((string) input('confirm_phrase', ''))) !== 'RESTORE') {
        flash('error', 'Type RESTORE in the confirmation box to go ahead. Nothing was changed.');
        redirect($selfUrl . '#restore');
    }

    $path = backup_resolve_path((string) $record['filename']);
    if ($path === null) {
        flash('error', 'The file for that backup is missing from the backups folder.');
        redirect($selfUrl . '#restore');
    }

    $only = array_values(array_filter(array_map(
        static fn (string $t): string => trim($t),
        explode(',', (string) input('tables', ''))
    )));

    $passphrase = backup_request_passphrase();
    $messages   = [];

    // A safety copy first, by default. The restore itself cannot be undone,
    // so the only way back to "before" is a dump taken a second earlier.
    if ((string) input('safety', '1') === '1') {
        try {
            $safety = backup_create([
                'note'       => 'Taken automatically before restoring ' . $record['filename'],
                'source'     => 'manual',
                'admin_id'   => (int) $admin['id'],
                'admin_name' => (string) $admin['name'],
            ]);
            $messages[] = 'A safety backup was taken first (' . $safety['filename'] . ').';
        } catch (Throwable $e) {
            flash('error', 'The safety backup failed, so nothing was restored: ' . $e->getMessage()
                . ' Untick the safety copy to go ahead without one.');
            redirect($selfUrl . '#restore');
        }
    }

    security_event('backup.restore_started', 'critical', [
        'backup_id' => (int) $record['id'],
        'filename'  => (string) $record['filename'],
        'tables'    => $only,
    ], (int) $admin['id'], 'admin');

    $result = backup_restore($path, [
        'passphrase' => $passphrase,
        'tables'     => $only !== [] ? $only : null,
    ]);

    log_activity('backup.restored', 'backup', (int) $record['id'],
        'Restored "' . $record['filename'] . '" - ' . $result['statements'] . ' statements, '
        . count($result['tables']) . ' tables, ' . ($result['complete'] ? 'complete' : 'INCOMPLETE'));
    security_event($result['complete'] ? 'backup.restored' : 'backup.restore_failed', 'critical', [
        'backup_id'  => (int) $record['id'],
        'statements' => $result['statements'],
        'tables'     => count($result['tables']),
        'complete'   => $result['complete'],
        'errors'     => array_slice($result['errors'], 0, 3),
    ], (int) $admin['id'], 'admin');
    admin_after_write();

    $messages[] = number_format($result['statements']) . ' statements ran across '
        . count($result['tables']) . ' table(s) in ' . $result['seconds'] . 's.';

    if ($result['complete']) {
        flash('success', 'Restore finished. ' . implode(' ', $messages));
    } else {
        flash('error', 'The restore did NOT finish, so the database is part-way between two states. '
            . implode(' ', $messages) . ' ' . implode(' ', array_slice($result['errors'], 0, 2)));
    }

    redirect($selfUrl);
}

if (is_post() && $op === 'delete') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $backupId = input_int('id');
    $record   = backup_record_or_bounce($backupId, $selfUrl);

    $path = backup_resolve_path((string) $record['filename']);
    if ($path !== null) {
        @unlink($path);
    }

    Database::delete('backups', '`id` = :id', ['id' => $backupId]);

    log_activity('backup.deleted', 'backup', $backupId,
        'Deleted backup "' . (string) $record['filename'] . '"');
    security_event('backup.deleted', 'medium',
        ['backup_id' => $backupId, 'filename' => (string) $record['filename']],
        (int) $admin['id'], 'admin');
    admin_after_write();

    flash('success', 'Backup "' . $record['filename'] . '" deleted.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// Files in the folder that no record points at
//
// A plain SQL dump somebody took over SSH is the same liability as one taken
// today, so the screen offers to encrypt it in place rather than only naming
// it. Deleting one asks for the password: it may be the only copy of the
// database that exists.
// ---------------------------------------------------------------------------
if (is_post() && $op === 'secure_file') {
    admin_require_action('system.backup');

    $name = (string) input('file', '');

    try {
        $secured = backup_secure_stray($name, [
            'admin_id'   => (int) $admin['id'],
            'admin_name' => (string) ($admin['name'] ?? ''),
        ]);
    } catch (Throwable $e) {
        flash('error', 'That file could not be encrypted: ' . $e->getMessage());
        redirect($selfUrl);
    }

    log_activity('backup.secured', 'backup', (int) $secured['id'],
        'Encrypted a plain dump that was sitting in the backups folder: ' . basename($name));
    security_event('backup.stray_encrypted', 'high', [
        'was'        => basename($name),
        'now'        => $secured['filename'],
        'protection' => $secured['protection'],
        'bytes'      => $secured['bytes'],
    ], (int) $admin['id'], 'admin');
    admin_after_write();

    flash('success', 'Encrypted as "' . $secured['filename'] . '" ('
        . format_bytes($secured['bytes']) . '). The plain copy has been removed.');
    redirect($selfUrl);
}

if (is_post() && $op === 'delete_file') {
    admin_require_action('system.backup');

    $name = (string) input('file', '');
    $path = backup_stray_path($name);

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('delete a file from the backups folder'));
        redirect($selfUrl);
    }
    if ($path === null) {
        flash('error', 'That file is not in the backups folder.');
        redirect($selfUrl);
    }

    $bytes      = (int) filesize($path);
    $protection = BackupCipher::protection($path);
    @unlink($path);

    log_activity('backup.file_deleted', 'backup', 0,
        'Deleted "' . basename($path) . '" from the backups folder');
    security_event('backup.stray_deleted', 'high',
        ['filename' => basename($path), 'bytes' => $bytes, 'protection' => $protection],
        (int) $admin['id'], 'admin');
    admin_after_write();

    flash('success', '"' . basename($path) . '" deleted from the backups folder.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------
$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = Database::count('backups');
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$backups = Database::fetchAll(
    "SELECT `id`, `filename`, `size_bytes`, `checksum`, `protection`, `tables_count`, `rows_count`,
            `source`, `verified_at`, `verify_error`, `admin_id`, `admin_name`, `note`, `created_at`
     FROM `backups`
     ORDER BY `id` DESC
     LIMIT {$limit} OFFSET {$offset}"
);

// Mark records whose file has gone missing so the list never offers a download
// that will only bounce back with an error.
foreach ($backups as $index => $row) {
    $backups[$index]['path'] = $dirError === '' ? backup_resolve_path((string) $row['filename']) : null;
}

// Whatever else is in the folder. Listed on every page of the table rather
// than only the first: a plain dump nobody can see is a plain dump nobody
// deals with.
$strays = $dirError === '' ? backup_untracked_files() : [];

$storedBytes = (int) Database::fetchColumn('SELECT COALESCE(SUM(`size_bytes`), 0) FROM `backups`');
$latest      = Database::fetchColumn('SELECT `created_at` FROM `backups` ORDER BY `id` DESC LIMIT 1');
$dbSize      = (int) Database::fetchColumn(
    'SELECT COALESCE(SUM(`data_length` + `index_length`), 0)
     FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = :db',
    ['db' => DB_NAME]
);
$tableCount  = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `information_schema`.`TABLES`
     WHERE `TABLE_SCHEMA` = :db AND `TABLE_TYPE` = 'BASE TABLE'",
    ['db' => DB_NAME]
);

$alertHours   = max(1, setting_int('sec_backup_alert_hours', 48));
$backupIsStale = $latest === null || strtotime((string) $latest) < time() - ($alertHours * 3600);
$configured   = backup_passphrase_source();
$selfTest     = $_SESSION['_backup_selftest'] ?? null;
unset($_SESSION['_backup_selftest']);

$pageTitle    = 'Database Backup';
$pageSubtitle = $total > 0
    ? number_format($total) . ' backup(s) on disk - last taken ' . time_ago($latest)
    : 'No backup has been taken yet.';
$breadcrumbs  = [
    ['label' => 'Dashboard',   'url' => admin_url('dashboard.php')],
    ['label' => 'Maintenance', 'url' => admin_url('system/maintenance.php')],
    ['label' => 'Backup'],
];
$pageActions  = '<a class="ad-btn" href="' . e(admin_url('system/maintenance.php')) . '">'
    . icon('settings', 'w-4 h-4') . ' Maintenance</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($dirError !== ''): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>There is nowhere to write a backup.</strong>
            <?= e($dirError) ?>
        </div>
    </div>
<?php endif; ?>

<div class="sik-alert sik-alert--<?= $backupIsStale ? 'warning' : 'info' ?>">
    <?= icon($backupIsStale ? 'alert' : 'info', 'w-5 h-5') ?>
    <div>
        <?php if ($backupIsStale): ?>
            <strong>No backup in the last <?= (int) $alertHours ?> hours.</strong>
            Take one now, and put <code class="ad-mono">bin/backup.php</code> on a nightly cron so it
            stops depending on somebody remembering.
        <?php else: ?>
            <strong>This is a safety net, not a backup strategy.</strong>
            The dump lives on the same disk as the site, so it does not survive a drive failure or a
            compromised server. Download a copy somewhere else, and keep whatever snapshot or
            off-site backup your host provides.
        <?php endif; ?>
    </div>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Database size', format_bytes($dbSize), 'ssd', 'blue',
        number_format($tableCount) . ' tables') ?>
    <?= admin_stat_card('Backups stored', number_format($total), 'package', 'violet',
        format_bytes($storedBytes) . ' on disk, keeping ' . (backup_keep() > 0 ? backup_keep() : 'all')) ?>
    <?= admin_stat_card('Last backup', $latest !== null ? time_ago($latest) : 'Never', 'clock',
        $backupIsStale ? 'amber' : 'green',
        $latest !== null ? format_datetime($latest, 'd M Y, g:i A') : 'Take one before your next big change') ?>
    <?= admin_stat_card('Backups folder',
        $dirStatus['writable'] ? ($dirStatus['outside_web_root'] ? 'Outside web root' : 'Writable') : 'Not writable',
        'router', $dirStatus['writable'] ? ($dirStatus['outside_web_root'] ? 'green' : 'amber') : 'red',
        $dirStatus['path']) ?>
</div>

<?php if ($dirStatus['writable'] && !$dirStatus['outside_web_root']): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            The backups sit <strong>inside the document root</strong>, kept from being served by an
            <code class="ad-mono">.htaccess</code> rule. That rule is one server misconfiguration away
            from being ignored. If your host lets you write above <code class="ad-mono">public_html</code>,
            put the full path in <a href="<?= e(admin_url('security/settings.php#backups')) ?>">Security
            Settings &rsaquo; Backups</a> and the files move out of the web's reach entirely.
        </div>
    </div>
<?php endif; ?>

<?php if ($dirStatus['custom_requested'] !== '' && !$dirStatus['custom']): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            The folder you set &mdash; <code class="ad-mono"><?= e($dirStatus['custom_requested']) ?></code> &mdash;
            cannot be created or written to, so backups are going to
            <code class="ad-mono"><?= e($dirStatus['path']) ?></code> instead.
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Create a backup</div>
            <div class="ad-card__sub">
                Every table is dumped with its structure and its rows, in pure PHP &mdash; no mysqldump required.
            </div>
        </div>
    </div>
    <form method="post" action="<?= e($selfUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="create">

        <div class="ad-card__body">
            <div class="sik-alert sik-alert--info">
                <?= icon('info', 'w-5 h-5') ?>
                <div>
                    The current database is <strong><?= e(format_bytes($dbSize)) ?></strong> across
                    <?= number_format($tableCount) ?> tables. A large catalogue with a long order history
                    can take a minute or more &mdash; leave the tab open until the page reloads.
                    The finished file contains password hashes and customer addresses, so treat it
                    exactly like the database itself.
                </div>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="backupNote">Note (optional)</label>
                <input class="sik-input" type="text" id="backupNote" name="note" maxlength="255"
                       placeholder="e.g. Before the Diwali price update">
                <span class="sik-help">Shown in the list so you know which backup is which.</span>
            </div>

            <div class="ad-field">
                <span class="sik-label">How this file is protected</span>
                <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px">
                    <input type="radio" name="protection" value="configured" checked style="margin-top:4px">
                    <span>
                        <strong>Use the store's setting</strong>
                        &mdash; <?= $configured === 'none'
                            ? 'the application key (config/app.key.php)'
                            : 'the backup passphrase set ' . ($configured === 'env' ? 'in the environment' : 'in Security Settings') ?>.
                        <span class="sik-help" style="display:block">
                            <?= $configured === 'none'
                                ? 'Nothing to remember. But the dump dies with the key file: lose config/app.key.php and this backup is noise.'
                                : 'The same passphrase the nightly cron job uses.' ?>
                        </span>
                    </span>
                </label>
                <label style="display:flex;gap:8px;align-items:flex-start">
                    <input type="radio" name="protection" value="passphrase" style="margin-top:4px">
                    <span>
                        <strong>Protect this one with a passphrase I type now</strong>
                        <span class="sik-help" style="display:block">
                            For the copy you are about to take off this server. It can be restored
                            anywhere with the passphrase and nothing else &mdash; and it cannot be
                            restored at all without it. Nobody can reset it for you.
                        </span>
                    </span>
                </label>
                <input class="sik-input" type="password" name="passphrase" autocomplete="new-password"
                       minlength="12" placeholder="At least 12 characters" style="max-width:340px;margin-top:8px">
            </div>

            <?= admin_reauth_field('create a database backup') ?>
        </div>

        <div class="ad-card__foot">
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('download', 'w-4 h-4') ?> Create backup
            </button>
        </div>
    </form>
</div>

<div class="ad-card" id="selftest">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Does backing up work on this server?</div>
            <div class="ad-card__sub">
                Dumps one small table, encrypts it, checksums it and reads it back &mdash; about a second,
                and it answers the question without dumping the whole catalogue.
            </div>
        </div>
        <form method="post" action="<?= e($selfUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="selftest">
            <button type="submit" class="ad-btn"><?= icon('check', 'w-4 h-4') ?> Run the check</button>
        </form>
    </div>
    <?php if (is_array($selfTest)): ?>
        <div class="ad-card__body" style="display:grid;gap:8px">
            <?php foreach ($selfTest['steps'] as $step): ?>
                <div style="display:flex;gap:10px;align-items:center;font-size:13.5px">
                    <span class="sik-status sik-status--<?= $step['ok'] ? 'green' : 'red' ?>">
                        <?= $step['ok'] ? 'ok' : 'no' ?>
                    </span>
                    <span><?= e((string) $step['label']) ?></span>
                    <?php if ((string) $step['detail'] !== ''): ?>
                        <span class="ad-muted ad-mono" style="font-size:12px;word-break:break-all">
                            <?= e((string) $step['detail']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Existing backups</div>
            <div class="ad-card__sub">Newest first. Download the ones you want to keep off this server.</div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($backups === []): ?>
            <?= admin_empty(
                'No backups yet',
                'Take one now, and again before any change you are not certain about.',
                null,
                null,
                'package'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Taken</th>
                            <th>Protection</th>
                            <th class="ad-table__num">Size</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $row): ?>
                            <?php
                            $rowId   = (int) $row['id'];
                            $missing = $row['path'] === null;
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-mono" style="word-break:break-all"><?= e($row['filename']) ?></div>
                                    <?php if (!empty($row['note'])): ?>
                                        <div class="ad-cellflex__meta"><?= e($row['note']) ?></div>
                                    <?php endif; ?>
                                    <div class="ad-cellflex__meta">
                                        <?= number_format((int) $row['tables_count']) ?> tables,
                                        <?= number_format((int) $row['rows_count']) ?> rows
                                        <?php if (!empty($row['checksum'])): ?>
                                            &middot; sha256 <span class="ad-mono"><?= e(substr((string) $row['checksum'], 0, 12)) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($missing): ?>
                                        <div class="ad-cellflex__meta" style="color:#DC2626">
                                            File is no longer in the backups folder
                                        </div>
                                    <?php elseif (!empty($row['verify_error'])): ?>
                                        <div class="ad-cellflex__meta" style="color:#DC2626">
                                            Last check failed: <?= e($row['verify_error']) ?>
                                        </div>
                                    <?php elseif (!empty($row['verified_at'])): ?>
                                        <div class="ad-cellflex__meta">
                                            Checked <?= e(time_ago($row['verified_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <div><?= e(format_datetime($row['created_at'], 'd M Y, g:i A')) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= e(time_ago($row['created_at'])) ?>
                                        &middot; <?= e((string) $row['source']) ?>
                                        <?php if (!empty($row['admin_name'])): ?>
                                            &middot; <?= e((string) $row['admin_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $row['protection'] === 'plaintext' ? 'red' : 'green' ?>">
                                        <?= e((string) $row['protection']) ?>
                                    </span>
                                </td>
                                <td class="ad-table__num"><?= e(format_bytes((int) $row['size_bytes'])) ?></td>
                                <td class="ad-table__actions">
                                    <?php if (!$missing): ?>
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="verify">
                                            <input type="hidden" name="id" value="<?= $rowId ?>">
                                            <button type="submit" class="ad-btn ad-btn--icon"
                                                    title="Check this file is still readable"
                                                    aria-label="Check <?= e_attr($row['filename']) ?>">
                                                <?= icon('check', 'w-4 h-4') ?>
                                            </button>
                                        </form>

                                        <!-- POST, with the operator's own password: the file is the
                                             whole database, so it never leaves on a bare link. -->
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                              style="display:flex;gap:6px;align-items:center">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="download">
                                            <input type="hidden" name="id" value="<?= $rowId ?>">
                                            <label class="sik-sr" for="dlpw<?= $rowId ?>">Your password</label>
                                            <input class="sik-input sik-input--sm" type="password"
                                                   id="dlpw<?= $rowId ?>" name="reauth_password"
                                                   autocomplete="current-password" placeholder="Your password"
                                                   style="max-width:150px" required>
                                            <?php if ((string) $row['protection'] === 'passphrase'): ?>
                                                <label class="sik-sr" for="dlpp<?= $rowId ?>">Backup passphrase</label>
                                                <input class="sik-input sik-input--sm" type="password"
                                                       id="dlpp<?= $rowId ?>" name="passphrase"
                                                       autocomplete="off" placeholder="Backup passphrase"
                                                       style="max-width:160px">
                                            <?php endif; ?>
                                            <button type="submit" class="ad-btn ad-btn--icon"
                                                    title="Download" aria-label="Download <?= e_attr($row['filename']) ?>">
                                                <?= icon('download', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                          <?= admin_confirm_form_attrs(
                                              'The file "' . $row['filename'] . '" is removed from the server for good.',
                                              ['title' => 'Delete this backup?', 'label' => 'Delete backup']
                                          ) ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="op" value="delete">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                                title="Delete" aria-label="Delete <?= e_attr($row['filename']) ?>">
                                            <?= icon('trash', 'w-4 h-4') ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($strays !== []): ?>
    <?php $plaintext = array_filter($strays, static fn (array $f): bool => $f['protection'] === 'plaintext'); ?>
    <div class="ad-card" id="strays">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Other files in the backups folder</div>
                <div class="ad-card__sub">
                    Nothing in the list above points at these. They are usually dumps taken by hand
                    over SSH, or by an older version of the store.
                </div>
            </div>
        </div>
        <div class="ad-card__body" style="display:grid;gap:14px">
            <?php if ($plaintext !== []): ?>
                <!-- Said plainly, because a plaintext dump is the single worst file
                     that can be sitting on a shared host: it is every password hash,
                     every address and every gateway secret, readable by anyone who
                     gets at the filesystem. -->
                <div class="sik-alert sik-alert--error">
                    <strong><?= count($plaintext) ?> of these
                    <?= count($plaintext) === 1 ? 'is a plain SQL dump' : 'are plain SQL dumps' ?>.</strong>
                    A plain dump is every password hash, every customer address and every payment
                    secret in one readable file. The folder is denied over HTTP, but that is the
                    only thing standing in front of it. Encrypt them or delete them.
                </div>
            <?php endif; ?>

            <table class="ad-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Last changed</th>
                        <th>Protection</th>
                        <th class="ad-table__num">Size</th>
                        <th class="ad-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($strays as $stray): ?>
                        <tr>
                            <td class="ad-mono" style="word-break:break-all"><?= e($stray['name']) ?></td>
                            <td style="white-space:nowrap"><?= e(format_datetime(date('Y-m-d H:i:s', $stray['modified']), 'd M Y, g:i A')) ?></td>
                            <td>
                                <span class="sik-status sik-status--<?= $stray['protection'] === 'plaintext' ? 'red' : 'green' ?>">
                                    <?= $stray['protection'] === 'plaintext' ? 'Plain SQL' : e($stray['protection']) ?>
                                </span>
                            </td>
                            <td class="ad-table__num"><?= e(format_bytes($stray['bytes'])) ?></td>
                            <td class="ad-table__actions">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                    <?php if ($stray['protection'] === 'plaintext'): ?>
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="secure_file">
                                            <input type="hidden" name="file" value="<?= e_attr($stray['name']) ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm">Encrypt it</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php
                                    /* A3 found this comment INSIDE the <form>
                                       start tag, where it had been since the
                                       tag was written. An HTML comment is not
                                       a thing a start tag can contain: the
                                       parser reads `<!--` as an attribute name
                                       and the `>` of `-->` as the end of the
                                       tag - so every attribute written after
                                       it, the onsubmit guard included, was
                                       rendering as plain text on the page.
                                       That delete button had no guard at all.
                                       It is a PHP comment now, outside the tag.

                                       What it said, and why it no longer
                                       applies: the filename was deliberately
                                       kept out of the question because it
                                       comes off the filesystem, and an
                                       apostrophe in it would have closed the
                                       JavaScript string. The helper puts it in
                                       an HTML attribute rather than a JS
                                       literal, so the name can finally be
                                       shown - which is the whole point of
                                       asking. */
                                    ?>
                                    <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                          <?= admin_confirm_form_attrs(
                                              'The file "' . $stray['name'] . '" is deleted from the backups folder. This cannot be undone.',
                                              ['title' => 'Delete this stray file?', 'label' => 'Delete file']
                                          ) ?>
                                          style="display:flex;gap:6px;align-items:center">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="op" value="delete_file">
                                        <input type="hidden" name="file" value="<?= e_attr($stray['name']) ?>">
                                        <label class="sik-sr" for="sp<?= e_attr(md5($stray['name'])) ?>">Your password</label>
                                        <input class="sik-input sik-input--sm" type="password"
                                               id="sp<?= e_attr(md5($stray['name'])) ?>" name="reauth_password"
                                               autocomplete="current-password" placeholder="Your password"
                                               style="max-width:150px" required>
                                        <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="ad-card" id="restore">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Restore</div>
            <div class="ad-card__sub">Put a backup back. Read the list before you do.</div>
        </div>
    </div>
    <div class="ad-card__body" style="display:grid;gap:14px">
        <div class="sik-alert sik-alert--warning">
            <?= icon('alert', 'w-5 h-5') ?>
            <div>
                <strong>What a restore here can and cannot do</strong>
                <ul style="margin:8px 0 0 0;padding-left:20px;line-height:1.7">
                    <?php foreach (backup_restore_caveats() as $caveat): ?>
                        <li><?= e($caveat) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <?php if ($backups === []): ?>
            <p class="ad-muted" style="font-size:13.5px">There is nothing to restore yet.</p>
        <?php else: ?>
            <form method="post" action="<?= e($selfUrl) ?>" style="display:grid;gap:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="restore">

                <div class="ad-field">
                    <label class="sik-label" for="restoreId">Backup to restore</label>
                    <select class="sik-input" id="restoreId" name="id" style="max-width:520px">
                        <?php foreach ($backups as $row): ?>
                            <?php if ($row['path'] === null) { continue; } ?>
                            <option value="<?= (int) $row['id'] ?>">
                                <?= e($row['filename']) ?>
                                &mdash; <?= e(format_datetime($row['created_at'], 'd M Y, g:i A')) ?>
                                (<?= e(format_bytes((int) $row['size_bytes'])) ?>, <?= e((string) $row['protection']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="restoreTables">Only these tables (optional)</label>
                    <input class="sik-input" type="text" id="restoreTables" name="tables"
                           placeholder="products, product_images" style="max-width:520px">
                    <span class="sik-help">
                        Comma separated. Leave empty to restore everything. Naming one table is the
                        recovery you usually want after a bad bulk edit &mdash; and it is the only
                        version of this that finishes quickly on a big database.
                    </span>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="restorePassphrase">Backup passphrase (only if the file has one)</label>
                    <input class="sik-input" type="password" id="restorePassphrase" name="passphrase"
                           autocomplete="off" style="max-width:340px">
                </div>

                <label style="display:flex;gap:8px;align-items:flex-start">
                    <input type="checkbox" name="safety" value="1" checked style="margin-top:4px">
                    <span>
                        <strong>Take a safety backup first</strong>
                        <span class="sik-help" style="display:block">
                            The only way back to how things are right now. Leave it on unless the
                            database is already too broken to dump.
                        </span>
                    </span>
                </label>

                <div class="ad-field">
                    <label class="sik-label" for="restoreConfirm">Type RESTORE to confirm <span class="req">*</span></label>
                    <input class="sik-input" type="text" id="restoreConfirm" name="confirm_phrase"
                           autocomplete="off" style="max-width:200px" placeholder="RESTORE">
                </div>

                <?= admin_reauth_field('restore a database backup') ?>

                <div>
                    <?php
                    /* The brief's "database restore" case. It does NOT get the
                       dialog's requireText, and that is deliberate: the form
                       above already makes the operator type RESTORE into
                       confirm_phrase, and THAT one is checked on the server.
                       A second typed box in the dialog would be ceremony that
                       teaches people to type without reading. */
                    ?>
                    <button type="submit" class="ad-btn ad-btn--danger"
                            <?= admin_confirm_attrs(
                                'The live database is replaced with the contents of this backup. This cannot be undone.',
                                ['title' => 'Restore over live data?', 'label' => 'Restore backup']
                            ) ?>>
                        <?= icon('alert', 'w-4 h-4') ?> Restore this backup
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <div style="font-size:13.5px;line-height:1.7">
            <p style="margin:0 0 10px 0">
                The file on the server is <strong>encrypted</strong>
                <?= $configured === 'none'
                    ? "with this installation's application key (<code class=\"ad-mono\">config/app.key.php</code>)"
                    : 'with the backup passphrase' ?>
                &mdash; copying it off the disk gives an attacker noise, not your database. The
                <em>download</em> is decrypted on the way out, so what lands in your Downloads folder
                is plain SQL. Keep that copy somewhere safe, and keep a copy of
                <?= $configured === 'none' ? 'the key file' : 'the passphrase' ?>: a dump left on the
                server cannot be restored without it.
            </p>
            <p style="margin:0 0 10px 0">
                To restore the downloaded copy by hand instead, import it into an empty database with
                phpMyAdmin, or from a shell:
            </p>
            <?php // No overflow-x:auto: the restore command line scrolled 121-151px sideways at
                  // 360-390px. admin.css wraps pre.ad-mono, and an inline rule outranked it. ?>
            <pre class="ad-mono" style="margin:0;padding:12px;background:#F9FAFB;border:1px solid var(--ad-border);
                 border-radius:8px">mysql -u &lt;user&gt; -p <?= e(DB_NAME) ?> &lt; shopinnkart-YYYYmmdd-HHiiss.sql</pre>
            <p class="ad-muted" style="margin:10px 0 0 0">
                Each table is dropped and recreated on import, and foreign key checks are switched off
                for the run, so the order the tables appear in does not matter.
            </p>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
