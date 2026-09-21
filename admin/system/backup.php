<?php
/**
 * ShopInnKart Admin - Database backup.
 *
 * A pure-PHP dump: SHOW TABLES, then SHOW CREATE TABLE, then chunked SELECTs
 * written out as escaped INSERTs. mysqldump is not assumed to exist, and
 * shell_exec() is not assumed to be enabled — plenty of shared hosts have
 * neither, and a backup button that only works on some servers is worse than
 * none because it is trusted until the day it is needed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.edit');

$backupsDir = STORAGE_PATH . '/backups';
$selfUrl    = admin_url('system/backup.php');

/** Rows fetched per SELECT. Small enough that a large table never has to fit in memory. */
const BACKUP_CHUNK_ROWS = 500;

/** Rows per INSERT statement in the dump file. */
const BACKUP_INSERT_ROWS = 100;

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
    if (preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name) !== 1) {
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

    $handle = @fopen($filePath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Could not open the backup file for writing.');
    }

    $rowTotal = 0;

    try {
        fwrite($handle, "-- " . SITE_NAME . " database backup\n");
        fwrite($handle, "-- Database: " . DB_NAME . "\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . " (" . date_default_timezone_get() . ")\n");
        fwrite($handle, "-- Server: " . (string) Database::fetchColumn('SELECT VERSION()') . "\n");
        fwrite($handle, "-- Tables: " . count($tables) . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = Database::fetch(sprintf('SHOW CREATE TABLE `%s`', $table));
            $ddl    = (string) ($create['Create Table'] ?? '');
            if ($ddl === '') {
                continue;
            }

            fwrite($handle, "-- ---------------------------------------------------------------\n");
            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "-- ---------------------------------------------------------------\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $ddl . ";\n\n");

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
                        fwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                            . implode(",\n", $pending) . ";\n");
                        $pending = [];
                    }
                }

                $offset += BACKUP_CHUNK_ROWS;
            }

            if ($pending !== [] && $columns !== null) {
                fwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                    . implode(",\n", $pending) . ";\n");
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "-- End of backup\n");
    } finally {
        fclose($handle);
    }

    clearstatcache(true, $filePath);

    return [
        'tables' => count($tables),
        'rows'   => $rowTotal,
        'bytes'  => (int) filesize($filePath),
    ];
}

// ---------------------------------------------------------------------------
// Download — GET, because it changes nothing. The path is still resolved
// against the backups folder so a crafted id can never reach another file.
// ---------------------------------------------------------------------------
$downloadId = (int) ($_GET['download'] ?? 0);

if ($downloadId > 0) {
    $record = Database::fetch('SELECT `id`, `filename` FROM `backups` WHERE `id` = :id', ['id' => $downloadId]);

    if ($record === null) {
        flash('error', 'That backup record no longer exists.');
        redirect($selfUrl);
    }

    $path = backup_resolve_path($backupsDir, (string) $record['filename']);
    if ($path === null) {
        flash('error', 'The file for that backup is missing from /storage/backups. You can delete the record below.');
        redirect($selfUrl);
    }

    log_activity('backup.downloaded', 'backup', (int) $record['id'],
        'Downloaded backup "' . basename($path) . '"');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Streamed in chunks so a large dump never has to fit in memory.
    $handle = fopen($path, 'rb');
    if ($handle !== false) {
        while (!feof($handle)) {
            echo fread($handle, 262144);
            flush();
        }
        fclose($handle);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------
$op = (string) input('op', '');

if (is_post() && $op === 'create') {
    admin_require_action('settings.edit');   // POST + CSRF + permission

    $note = mb_substr(trim((string) input('note', '')), 0, 255);

    // A catalogue-sized dump can outlast the default limits.
    @set_time_limit(600);
    ignore_user_abort(true);

    try {
        backup_dir($backupsDir);

        $filename = 'shopinnkart-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
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
    admin_require_action('settings.edit');   // POST + CSRF + permission

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
                                        <a class="ad-btn ad-btn--icon"
                                           href="<?= e($selfUrl . '?download=' . $rowId) ?>"
                                           title="Download" aria-label="Download <?= e_attr($row['filename']) ?>">
                                            <?= icon('download', 'w-4 h-4') ?>
                                        </a>
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
            The file is plain SQL. To restore, import it into an empty database with phpMyAdmin,
            or from a shell:
        </p>
        <pre class="ad-mono" style="margin-top:10px;padding:12px;background:#F9FAFB;border:1px solid var(--ad-border);
             border-radius:8px;overflow-x:auto">mysql -u &lt;user&gt; -p <?= e(DB_NAME) ?> &lt; shopinnkart-YYYYmmdd-HHiiss.sql</pre>
        <p class="ad-muted" style="margin-top:10px">
            Each table is dropped and recreated on import, and foreign key checks are switched off
            for the run, so the order the tables appear in does not matter.
        </p>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
