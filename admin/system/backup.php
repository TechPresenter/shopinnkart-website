<?php
/**
 * ShopInnKart Admin - Database backup.
 *
 * A pure-PHP dump: SHOW TABLES, then SHOW CREATE TABLE, then chunked SELECTs
 * written out as escaped INSERTs. mysqldump is not assumed to exist, and
 * shell_exec() is not assumed to be enabled — plenty of shared hosts have
 * neither, and a backup button that only works on some servers is worse than
 * none because it is trusted until the day it is needed.
 *
 * A dump is the whole application in one file: every bcrypt hash to crack
 * offline, every address, every gateway secret. So it is not a settings
 * screen. It needs system.backup (no seeded role has it), the actor's own
 * password to create or download one, and the file on disk is encrypted with
 * the application key — a stolen /storage/backups is then a directory of
 * noise rather than the database.
 *
 * Because they are encrypted, backups do not survive losing config/app.key.php.
 * That is the trade: a readable dump on the webserver is a breach waiting for
 * one path-traversal bug, and the key lives outside the dump on purpose.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('system.backup');

require_once ADMIN_PATH . '/includes/rbac.php';

$backupsDir = STORAGE_PATH . '/backups';
$selfUrl    = admin_url('system/backup.php');

/** Rows fetched per SELECT. Small enough that a large table never has to fit in memory. */
const BACKUP_CHUNK_ROWS = 500;

/** Rows per INSERT statement in the dump file. */
const BACKUP_INSERT_ROWS = 100;

/** Plaintext bytes per encrypted frame. Bounds memory on both write and read. */
const BACKUP_CRYPT_CHUNK = 262144;

/** First line of an encrypted dump; anything else is a legacy plaintext .sql. */
const BACKUP_MAGIC = "SIKBAK1\n";

/** Make sure /storage/backups exists and stays out of the web's reach. */
function backup_dir(string $dir): string
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The backups folder could not be created: ' . $dir);
    }

    // A dump holds password hashes and every customer address, so the folder
    // gets its own deny rule rather than relying on the one above it.
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    return $dir;
}

/**
 * Resolve a stored filename to a real path inside the backups folder.
 * Returns null for anything that escapes the folder or does not exist.
 */
function backup_resolve_path(string $dir, string $filename): ?string
{
    $name = basename(str_replace('\\', '/', $filename));
    // .sql is still accepted so dumps taken before encryption stay downloadable.
    if (preg_match('/^[A-Za-z0-9._-]+\.sql(\.enc)?$/', $name) !== 1) {
        return null;
    }

    $real    = realpath($dir . '/' . $name);
    $dirReal = realpath($dir);
    if ($real === false || $dirReal === false) {
        return null;
    }

    $real    = str_replace('\\', '/', $real);
    $dirReal = rtrim(str_replace('\\', '/', $dirReal), '/');

    return strpos($real, $dirReal . '/') === 0 && is_file($real) ? $real : null;
}

/**
 * Streaming AES-256-GCM writer for a dump file.
 *
 * Frame format after the magic line: 4-byte big-endian ciphertext length,
 * 12-byte IV, 16-byte tag, ciphertext. Framing rather than one giant
 * openssl_encrypt() is what keeps a multi-gigabyte dump inside PHP's memory
 * limit, and each frame is authenticated on its own so a truncated or edited
 * file fails loudly instead of decrypting to garbage.
 */
final class BackupCipher
{
    /** @var resource|null */
    private $handle;
    private string $buffer = '';

    public function __construct(string $path)
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the backup file for writing.');
        }
        $this->handle = $handle;
        fwrite($this->handle, BACKUP_MAGIC);
    }

    /**
     * The 32-byte key both directions derive from the application key.
     *
     * Its own purpose label, so a backup is not readable with the key that
     * decrypts stored SMTP and gateway secrets, and vice versa.
     */
    private static function key(): string
    {
        $derived = app_key_derive('backup-dump');
        if ($derived === '') {
            throw new RuntimeException(
                'No application key is available, so a backup cannot be encrypted. '
                . 'Check that config/app.key.php exists and is readable.'
            );
        }

        return hash('sha256', $derived, true);
    }

    public function write(string $text): void
    {
        $this->buffer .= $text;
        while (strlen($this->buffer) >= BACKUP_CRYPT_CHUNK) {
            $this->seal(substr($this->buffer, 0, BACKUP_CRYPT_CHUNK));
            $this->buffer = substr($this->buffer, BACKUP_CRYPT_CHUNK);
        }
    }

    private function seal(string $plain): void
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        fwrite($this->handle, pack('N', strlen($cipher)) . $iv . $tag . $cipher);
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        if ($this->buffer !== '') {
            $this->seal($this->buffer);
            $this->buffer = '';
        }
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Yield the plaintext of a dump, frame by frame.
     * A file without the magic line is a legacy plaintext dump and is streamed as-is.
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened.');
        }

        try {
            $magic = (string) fread($handle, strlen(BACKUP_MAGIC));
            if ($magic !== BACKUP_MAGIC) {
                yield $magic;
                while (!feof($handle)) {
                    yield (string) fread($handle, BACKUP_CRYPT_CHUNK);
                }
                return;
            }

            while (!feof($handle)) {
                $header = (string) fread($handle, 4);
                if (strlen($header) < 4) {
                    return;   // clean end of file
                }

                $length = (int) (unpack('N', $header)[1] ?? 0);
                $frame  = (string) fread($handle, 28 + $length);
                if (strlen($frame) < 28 + $length) {
                    throw new RuntimeException('The backup file is truncated.');
                }

                $plain = openssl_decrypt(
                    substr($frame, 28),
                    'aes-256-gcm',
                    self::key(),
                    OPENSSL_RAW_DATA,
                    substr($frame, 0, 12),
                    substr($frame, 12, 16)
                );

                if ($plain === false) {
                    throw new RuntimeException(
                        'This backup cannot be decrypted with the current application key. '
                        . 'It was taken with a different config/app.key.php.'
                    );
                }

                yield $plain;
            }
        } finally {
            fclose($handle);
        }
    }
}

/** Quote one column value for an INSERT statement. */
function backup_quote(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $string = (string) $value;

    // Binary columns cannot survive a quoted string literal, so they go out as
    // hex. Nothing in the shipped schema is binary, but a plugin table might be.
    if (!mb_check_encoding($string, 'UTF-8')) {
        return '0x' . bin2hex($string);
    }

    return $pdo->quote($string);
}

/**
 * Write a full dump of every base table to $filePath.
 *
 * @return array{tables:int,rows:int,bytes:int}
 */
function backup_write_dump(string $filePath): array
{
    $pdo = Database::connect();

    $tables = array_values(array_filter(
        Database::fetchColumnAll("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'"),
        static fn ($name): bool => preg_match('/^[A-Za-z0-9_$]+$/', (string) $name) === 1
    ));

    // Written through the cipher: no plaintext copy of the database ever
    // touches the disk, not even briefly.
    $out      = new BackupCipher($filePath);
    $rowTotal = 0;

    try {
        $out->write("-- " . SITE_NAME . " database backup\n");
        $out->write("-- Database: " . DB_NAME . "\n");
        $out->write("-- Generated: " . date('Y-m-d H:i:s') . " (" . date_default_timezone_get() . ")\n");
        $out->write("-- Server: " . (string) Database::fetchColumn('SELECT VERSION()') . "\n");
        $out->write("-- Tables: " . count($tables) . "\n\n");
        $out->write("SET NAMES utf8mb4;\n");
        $out->write("SET FOREIGN_KEY_CHECKS = 0;\n");
        $out->write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = Database::fetch(sprintf('SHOW CREATE TABLE `%s`', $table));
            $ddl    = (string) ($create['Create Table'] ?? '');
            if ($ddl === '') {
                continue;
            }

            $out->write("-- ---------------------------------------------------------------\n");
            $out->write("-- Table: {$table}\n");
            $out->write("-- ---------------------------------------------------------------\n");
            $out->write("DROP TABLE IF EXISTS `{$table}`;\n");
            $out->write($ddl . ";\n\n");

            $count = (int) Database::fetchColumn(sprintf('SELECT COUNT(*) FROM `%s`', $table));
            if ($count === 0) {
                continue;
            }

            $offset  = 0;
            $pending = [];
            $columns = null;

            while ($offset < $count) {
                // LIMIT/OFFSET are integers cast here: PDO cannot bind them
                // while emulated prepares are off.
                $chunk = Database::fetchAll(sprintf(
                    'SELECT * FROM `%s` LIMIT %d OFFSET %d',
                    $table,
                    BACKUP_CHUNK_ROWS,
                    $offset
                ));
                if ($chunk === []) {
                    break;
                }

                foreach ($chunk as $row) {
                    if ($columns === null) {
                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = backup_quote($pdo, $value);
                    }
                    $pending[] = '(' . implode(', ', $values) . ')';
                    $rowTotal++;

                    if (count($pending) >= BACKUP_INSERT_ROWS) {
                        $out->write("INSERT INTO `{$table}` ({$columns}) VALUES\n"
                            . implode(",\n", $pending) . ";\n");
                        $pending = [];
                    }
                }

                $offset += BACKUP_CHUNK_ROWS;
            }

            if ($pending !== [] && $columns !== null) {
                $out->write("INSERT INTO `{$table}` ({$columns}) VALUES\n"
                    . implode(",\n", $pending) . ";\n");
            }
            $out->write("\n");
        }

        $out->write("SET FOREIGN_KEY_CHECKS = 1;\n");
        $out->write("-- End of backup\n");
    } finally {
        $out->close();
    }

    clearstatcache(true, $filePath);

    return [
        'tables' => count($tables),
        'rows'   => $rowTotal,
        'bytes'  => (int) filesize($filePath),
    ];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------
$op = (string) input('op', '');

// ---------------------------------------------------------------------------
// Download — POST, not GET.
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

    $record = Database::fetch('SELECT `id`, `filename` FROM `backups` WHERE `id` = :id', ['id' => input_int('id')]);

    if ($record === null) {
        flash('error', 'That backup record no longer exists.');
        redirect($selfUrl);
    }

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('download a database backup'));
        redirect($selfUrl);
    }

    $path = backup_resolve_path($backupsDir, (string) $record['filename']);
    if ($path === null) {
        flash('error', 'The file for that backup is missing from /storage/backups. You can delete the record below.');
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
        $stream = BackupCipher::read($path);
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

    $note = mb_substr(trim((string) input('note', '')), 0, 255);

    // A catalogue-sized dump can outlast the default limits.
    @set_time_limit(600);
    ignore_user_abort(true);

    try {
        backup_dir($backupsDir);

        $filename = 'shopinnkart-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.enc';
        $result   = backup_write_dump($backupsDir . '/' . $filename);

        $backupId = Database::insert('backups', [
            'filename'   => $filename,
            'size_bytes' => $result['bytes'],
            'admin_id'   => (int) $admin['id'],
            'admin_name' => (string) $admin['name'],
            'note'       => $note !== '' ? $note : null,
        ]);

        log_activity('backup.created', 'backup', $backupId,
            'Created backup "' . $filename . '" — ' . $result['tables'] . ' tables, '
            . number_format($result['rows']) . ' rows, ' . format_bytes($result['bytes']));
        security_event('backup.created', 'high', [
            'backup_id' => $backupId,
            'filename'  => $filename,
            'tables'    => $result['tables'],
            'rows'      => $result['rows'],
        ], (int) $admin['id'], 'admin');
        admin_after_write();

        flash('success', 'Backup created: ' . $result['tables'] . ' tables and '
            . number_format($result['rows']) . ' rows written to ' . $filename
            . ' (' . format_bytes($result['bytes']) . ').');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Database backup failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        flash('error', 'The backup did not finish: ' . $e->getMessage()
            . ' Any partial file was left in /storage/backups so you can inspect it.');
    }

    redirect($selfUrl);
}

if (is_post() && $op === 'delete') {
    admin_require_action('system.backup');   // POST + CSRF + permission

    $backupId = input_int('id');
    $record   = $backupId > 0
        ? Database::fetch('SELECT `id`, `filename` FROM `backups` WHERE `id` = :id', ['id' => $backupId])
        : null;

    if ($record === null) {
        flash('error', 'That backup record no longer exists.');
        redirect($selfUrl);
    }

    $path = backup_resolve_path($backupsDir, (string) $record['filename']);
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
// Read
// ---------------------------------------------------------------------------
$dirExists   = is_dir($backupsDir);
$dirWritable = $dirExists && is_writable($backupsDir);

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = Database::count('backups');
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$backups = Database::fetchAll(
    "SELECT `id`, `filename`, `size_bytes`, `admin_id`, `admin_name`, `note`, `created_at`
     FROM `backups`
     ORDER BY `id` DESC
     LIMIT {$limit} OFFSET {$offset}"
);

// Mark records whose file has gone missing so the list never offers a download
// that will only bounce back with an error.
foreach ($backups as $index => $row) {
    $backups[$index]['path'] = backup_resolve_path($backupsDir, (string) $row['filename']);
}

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

$pageTitle    = 'Database Backup';
$pageSubtitle = $total > 0
    ? number_format($total) . ' backup(s) on disk · last taken ' . time_ago($latest)
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

<div class="sik-alert sik-alert--warning">
    <?= icon('alert', 'w-5 h-5') ?>
    <div>
        <strong>This is a safety net, not a backup strategy.</strong>
        The dump lives on the same disk as the site, so it does not survive a drive failure or a
        compromised server. Download a copy somewhere else, and keep whatever snapshot or
        off-site backup your host provides.
    </div>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Database size', format_bytes($dbSize), 'ssd', 'blue',
        number_format($tableCount) . ' tables') ?>
    <?= admin_stat_card('Backups stored', number_format($total), 'package', 'violet',
        format_bytes($storedBytes) . ' on disk') ?>
    <?= admin_stat_card('Last backup', $latest !== null ? time_ago($latest) : 'Never', 'clock',
        $latest !== null ? 'green' : 'amber',
        $latest !== null ? format_datetime($latest, 'd M Y, g:i A') : 'Take one before your next big change') ?>
    <?= admin_stat_card('Backups folder', $dirWritable ? 'Writable' : ($dirExists ? 'Read-only' : 'Missing'),
        'router', $dirWritable ? 'green' : 'red', 'storage/backups') ?>
</div>

<?php if (!$dirWritable): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <code>/storage/backups</code> is <?= $dirExists ? 'not writable' : 'missing' ?>.
            Creating a backup will try to fix it, but if that fails set the folder to 0775
            and make sure PHP owns it.
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

            <?= admin_reauth_field('create a database backup') ?>
        </div>

        <div class="ad-card__foot">
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('download', 'w-4 h-4') ?> Create backup
            </button>
        </div>
    </form>
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
                            <th>By</th>
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
                                    <?php if ($missing): ?>
                                        <div class="ad-cellflex__meta" style="color:#DC2626">
                                            File is no longer in /storage/backups
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <div><?= e(format_datetime($row['created_at'], 'd M Y, g:i A')) ?></div>
                                    <div class="ad-cellflex__meta"><?= e(time_ago($row['created_at'])) ?></div>
                                </td>
                                <td><?= e($row['admin_name'] ?: 'System') ?></td>
                                <td class="ad-table__num"><?= e(format_bytes((int) $row['size_bytes'])) ?></td>
                                <td class="ad-table__actions">
                                    <?php if (!$missing): ?>
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
                                            <button type="submit" class="ad-btn ad-btn--icon"
                                                    title="Download" aria-label="Download <?= e_attr($row['filename']) ?>">
                                                <?= icon('download', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                          onsubmit="return confirm(<?= e_attr((string) json_encode(
                                              'Delete the backup "' . $row['filename'] . '"? The file is removed from the server for good.'
                                          )) ?>)">
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

<div class="ad-card">
    <div class="ad-card__head"><div class="ad-card__title">Restoring</div></div>
    <div class="ad-card__body" style="font-size:13.5px;line-height:1.7">
        <p>
            The file on the server is <strong>encrypted</strong> with this installation's application key
            (<code>config/app.key.php</code>) &mdash; copying it off the disk gives an attacker noise, not
            your database. The <em>download</em> is decrypted on the way out, so what lands in your
            Downloads folder is plain SQL. Keep that copy somewhere safe, and keep a copy of the key
            file: a dump left on the server cannot be restored without it.
        </p>
        <p>
            To restore, import the downloaded file into an empty database with phpMyAdmin,
            or from a shell:
        </p>
        <?php // No overflow-x:auto: the restore command line scrolled 121-151px sideways at
              // 360-390px. admin.css wraps pre.ad-mono, and an inline rule outranked it. ?>
        <pre class="ad-mono" style="margin-top:10px;padding:12px;background:#F9FAFB;border:1px solid var(--ad-border);
             border-radius:8px">mysql -u &lt;user&gt; -p <?= e(DB_NAME) ?> &lt; shopinnkart-YYYYmmdd-HHiiss.sql</pre>
        <p class="ad-muted" style="margin-top:10px">
            Each table is dropped and recreated on import, and foreign key checks are switched off
            for the run, so the order the tables appear in does not matter.
        </p>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
