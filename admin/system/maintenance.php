<?php
/**
 * ShopInnKart Admin - System status and housekeeping.
 *
 * Everything on this page is measured live. When a hosting move breaks an
 * extension or turns a folder read-only, this is the screen that says so
 * instead of the storefront failing quietly at upload time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.edit');

$selfUrl    = admin_url('system/maintenance.php');
$backupsDir = STORAGE_PATH . '/backups';

/**
 * Recursive size of a directory.
 * Returns zeroes for a missing folder rather than throwing — a missing folder
 * is a finding this page wants to report, not an error page.
 *
 * @return array{bytes:int,files:int,exists:bool}
 */
function maintenance_dir_stats(string $path): array
{
    if (!is_dir($path)) {
        return ['bytes' => 0, 'files' => 0, 'exists' => false];
    }

    $bytes = 0;
    $files = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
                $files++;
            }
        }
    } catch (Throwable $e) {
        // An unreadable sub-folder should not take the whole page down.
        ErrorHandler::log('warning', 'Maintenance size scan failed for ' . $path . ': ' . $e->getMessage());
    }

    return ['bytes' => $bytes, 'files' => $files, 'exists' => true];
}

/** Log files under /storage/logs older than the cut-off, oldest first. */
function maintenance_stale_logs(int $days = 30): array
{
    $cutoff = time() - ($days * 86400);
    $stale  = [];

    foreach (glob(LOG_PATH . '/*.log') ?: [] as $file) {
        $modified = (int) @filemtime($file);
        if ($modified > 0 && $modified < $cutoff) {
            $stale[] = ['path' => $file, 'bytes' => (int) @filesize($file), 'modified' => $modified];
        }
    }

    usort($stale, static fn (array $a, array $b): int => $a['modified'] <=> $b['modified']);
    return $stale;
}

// ---------------------------------------------------------------------------
// Housekeeping actions
// ---------------------------------------------------------------------------
$op = (string) input('op', '');

if (is_post() && $op !== '') {
    admin_require_action('settings.edit');   // POST + CSRF + permission

    switch ($op) {
        case 'clear_cache':
            $freed   = Cache::size();
            $cleared = Cache::flush();

            log_activity('system.cache_cleared', 'system', null,
                'Cleared the file cache: ' . $cleared . ' file(s), ' . format_bytes($freed));
            admin_after_write();

            flash('success', $cleared > 0
                ? $cleared . ' cache file(s) removed, ' . format_bytes($freed) . ' freed.'
                : 'The cache was already empty.');
            redirect($selfUrl);

        case 'clear_temp':
            $stale = maintenance_stale_logs(30);
            $freed = 0;
            $count = 0;
            foreach ($stale as $file) {
                if (@unlink($file['path'])) {
                    $freed += $file['bytes'];
                    $count++;
                }
            }

            log_activity('system.logs_pruned', 'system', null,
                'Pruned ' . $count . ' log file(s) older than 30 days, ' . format_bytes($freed));
            admin_after_write();

            flash($count > 0 ? 'success' : 'info', $count > 0
                ? $count . ' log file(s) older than 30 days deleted, ' . format_bytes($freed) . ' freed.'
                : 'No log file is older than 30 days yet — nothing to prune.');
            redirect($selfUrl);

        case 'process_queue':
            $result = process_notification_queue(50);

            log_activity('system.queue_processed', 'system', null,
                'Processed the notification queue: ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
            admin_after_write();

            if ($result['sent'] === 0 && $result['failed'] === 0) {
                flash('info', 'The queue had nothing pending.');
            } else {
                flash($result['failed'] > 0 ? 'warning' : 'success',
                    $result['sent'] . ' notification(s) sent, ' . $result['failed'] . ' failed. '
                    . ($result['failed'] > 0 ? 'Failed rows are retried up to three times.' : ''));
            }
            redirect($selfUrl);

        default:
            flash('error', 'That maintenance action is not recognised.');
            redirect($selfUrl);
    }
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------
$phpOk      = version_compare(PHP_VERSION, '8.0.0', '>=');
$mysqlVer   = (string) Database::fetchColumn('SELECT VERSION()');
$dbSize     = (int) Database::fetchColumn(
    'SELECT COALESCE(SUM(`data_length` + `index_length`), 0)
     FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = :db',
    ['db' => DB_NAME]
);
$tableCount = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `information_schema`.`TABLES`
     WHERE `TABLE_SCHEMA` = :db AND `TABLE_TYPE` = 'BASE TABLE'",
    ['db' => DB_NAME]
);

// Each row is "any of these extensions satisfies it", which is how the image
// requirement works: GD or Imagick, either is fine.
$extensionChecks = [
    ['label' => 'pdo_mysql', 'candidates' => ['pdo_mysql'], 'why' => 'Every database query in the application.'],
    ['label' => 'mbstring',  'candidates' => ['mbstring'],  'why' => 'UTF-8 safe string handling for names, addresses and search.'],
    ['label' => 'fileinfo',  'candidates' => ['fileinfo'],  'why' => 'Sniffs the real MIME type of an upload instead of trusting the browser.'],
    ['label' => 'gd or imagick', 'candidates' => ['gd', 'imagick'], 'why' => 'Validates and resizes product imagery.'],
    ['label' => 'json',      'candidates' => ['json'],      'why' => 'Role permissions, product specs and every API response.'],
    ['label' => 'openssl',   'candidates' => ['openssl'],   'why' => 'Secure tokens, password resets and HTTPS requests.'],
];

$extensionFailures = 0;
foreach ($extensionChecks as $index => $check) {
    $loaded = [];
    foreach ($check['candidates'] as $candidate) {
        if (extension_loaded($candidate)) {
            $loaded[] = $candidate;
        }
    }
    $extensionChecks[$index]['loaded'] = $loaded;
    $extensionChecks[$index]['ok']     = $loaded !== [];
    if ($loaded === []) {
        $extensionFailures++;
    }
}

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------
$uploadStats  = maintenance_dir_stats(UPLOAD_PATH);
$storageStats = maintenance_dir_stats(STORAGE_PATH);
$cacheBytes   = Cache::size();
$staleLogs    = maintenance_stale_logs(30);
$staleBytes   = array_sum(array_column($staleLogs, 'bytes'));

$freeSpace  = (float) @disk_free_space(ROOT_PATH);
$totalSpace = (float) @disk_total_space(ROOT_PATH);

$writableChecks = [
    ['label' => 'Uploads',       'path' => UPLOAD_PATH,              'why' => 'Product, brand, banner and avatar images.'],
    ['label' => 'Cache',         'path' => STORAGE_PATH . '/cache',  'why' => 'Menus, widget data and the sidebar counters.'],
    ['label' => 'Logs',          'path' => LOG_PATH,                 'why' => 'Error and application logs.'],
    ['label' => 'Backups',       'path' => $backupsDir,              'why' => 'Database dumps written by the backup screen.'],
];

$writableFailures = 0;
foreach ($writableChecks as $index => $check) {
    $exists   = is_dir($check['path']);
    $writable = $exists && is_writable($check['path']);
    $writableChecks[$index]['exists']   = $exists;
    $writableChecks[$index]['writable'] = $writable;
    if (!$writable) {
        $writableFailures++;
    }
}

// ---------------------------------------------------------------------------
// Notification queue
// ---------------------------------------------------------------------------
$queueCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `notification_queue` GROUP BY `status`');
$queuePending = (int) ($queueCounts['pending'] ?? 0);
$queueSent    = (int) ($queueCounts['sent'] ?? 0);
$queueFailed  = (int) ($queueCounts['failed'] ?? 0);
$queueOldest  = Database::fetchColumn(
    "SELECT `created_at` FROM `notification_queue` WHERE `status` = 'pending' ORDER BY `id` ASC LIMIT 1"
);
$queueRecentFailures = Database::fetchAll(
    "SELECT `recipient`, `template_key`, `attempts`, `error`, `created_at`
     FROM `notification_queue` WHERE `status` = 'failed' ORDER BY `id` DESC LIMIT 5"
);

$pageTitle    = 'Maintenance';
$pageSubtitle = 'System status, storage usage and the housekeeping jobs that keep them healthy.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Maintenance'],
];
$pageActions  = '<a class="ad-btn" href="' . e(admin_url('system/backup.php')) . '">'
    . icon('download', 'w-4 h-4') . ' Database Backup</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($extensionFailures > 0 || $writableFailures > 0 || !$phpOk): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>This server needs attention.</strong>
            <?php
            $problems = [];
            if (!$phpOk)                { $problems[] = 'PHP is older than 8.0'; }
            if ($extensionFailures > 0) { $problems[] = $extensionFailures . ' required extension(s) missing'; }
            if ($writableFailures > 0)  { $problems[] = $writableFailures . ' folder(s) not writable'; }
            echo e(implode(' · ', $problems));
            ?>
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('PHP', PHP_VERSION, 'cpu', $phpOk ? 'green' : 'red',
        $phpOk ? 'Meets the 8.0 minimum' : 'Upgrade to 8.0 or newer') ?>
    <?= admin_stat_card('Database', $mysqlVer, 'ssd', 'blue',
        number_format($tableCount) . ' tables · ' . format_bytes($dbSize)) ?>
    <?= admin_stat_card('Uploads', format_bytes($uploadStats['bytes']), 'package', 'violet',
        number_format($uploadStats['files']) . ' files in /uploads') ?>
    <?= admin_stat_card('Storage', format_bytes($storageStats['bytes']), 'router', 'navy',
        number_format($storageStats['files']) . ' files in /storage') ?>
</div>

<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">PHP extensions</div>
                <div class="ad-card__sub">
                    <?= $extensionFailures === 0
                        ? 'Everything the application needs is loaded.'
                        : $extensionFailures . ' missing — the features below will fail until they are enabled in php.ini.' ?>
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr><th>Extension</th><th>Used for</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($extensionChecks as $check): ?>
                            <tr>
                                <td class="ad-mono"><?= e($check['label']) ?></td>
                                <td class="ad-muted"><?= e($check['why']) ?></td>
                                <td>
                                    <?php if ($check['ok']): ?>
                                        <span class="sik-status sik-status--green">
                                            Loaded<?= count($check['candidates']) > 1 ? ' (' . e(implode(', ', $check['loaded'])) . ')' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--red">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td class="ad-mono">upload limits</td>
                            <td class="ad-muted">Largest image the server will accept.</td>
                            <td>
                                <span class="ad-mono">
                                    <?= e((string) ini_get('upload_max_filesize')) ?> /
                                    <?= e((string) ini_get('post_max_size')) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="ad-mono">memory_limit</td>
                            <td class="ad-muted">Ceiling for a single request, including a backup run.</td>
                            <td><span class="ad-mono"><?= e((string) ini_get('memory_limit')) ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Directory writability</div>
                <div class="ad-card__sub">
                    <?= $writableFailures === 0
                        ? 'Every runtime folder can be written to.'
                        : 'Fix the permissions (0775) on the folders marked below.' ?>
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr><th>Folder</th><th>Used for</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($writableChecks as $check): ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex__name"><?= e($check['label']) ?></div>
                                    <div class="ad-cellflex__meta ad-mono" style="word-break:break-all">
                                        <?= e(str_replace('\\', '/', $check['path'])) ?>
                                    </div>
                                </td>
                                <td class="ad-muted"><?= e($check['why']) ?></td>
                                <td>
                                    <?php if ($check['writable']): ?>
                                        <span class="sik-status sik-status--green">Writable</span>
                                    <?php elseif ($check['exists']): ?>
                                        <span class="sik-status sik-status--red">Read-only</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--amber">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($totalSpace > 0): ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex__name">Disk</div>
                                    <div class="ad-cellflex__meta">Volume holding the application</div>
                                </td>
                                <td class="ad-muted">
                                    <?= e(format_bytes((int) ($totalSpace - $freeSpace))) ?> used of
                                    <?= e(format_bytes((int) $totalSpace)) ?>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $freeSpace < 536870912 ? 'red' : 'green' ?>">
                                        <?= e(format_bytes((int) $freeSpace)) ?> free
                                    </span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Cache</div>
                <div class="ad-card__sub">
                    Menus, homepage widget data and the sidebar counters are cached to
                    <code>/storage/cache</code>.
                </div>
            </div>
        </div>
        <div class="ad-card__body">
            <p style="font-size:13.5px;line-height:1.6">
                Currently holding <strong><?= e(format_bytes($cacheBytes)) ?></strong>.
                Every admin write already busts it, so this button is for the times a change
                came from outside the panel &mdash; a direct database edit, for instance.
            </p>
        </div>
        <form method="post" action="<?= e($selfUrl) ?>" class="ad-card__foot">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="clear_cache">
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('refresh', 'w-4 h-4') ?> Clear cache
            </button>
        </form>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Temporary files</div>
                <div class="ad-card__sub">Application logs in <code>/storage/logs</code> older than 30 days.</div>
            </div>
        </div>
        <div class="ad-card__body">
            <?php if ($staleLogs === []): ?>
                <p style="font-size:13.5px;line-height:1.6">
                    Nothing to prune &mdash; no log file is older than 30 days.
                </p>
            <?php else: ?>
                <p style="font-size:13.5px;line-height:1.6">
                    <strong><?= count($staleLogs) ?> file(s)</strong> totalling
                    <strong><?= e(format_bytes($staleBytes)) ?></strong> can be removed.
                    Oldest: <?= e(basename($staleLogs[0]['path'])) ?>
                    (<?= e(format_date($staleLogs[0]['modified'], 'd M Y')) ?>).
                </p>
                <p class="ad-muted" style="font-size:12.5px;margin-top:8px">
                    Database rows in the error log are untouched &mdash; clear those from the Error Log screen.
                </p>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= e($selfUrl) ?>" class="ad-card__foot"
              onsubmit="return confirm('Delete log files older than 30 days? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="clear_temp">
            <button type="submit" class="ad-btn<?= $staleLogs === [] ? '' : ' ad-btn--danger' ?>"
                    <?= $staleLogs === [] ? 'disabled' : '' ?>>
                <?= icon('trash', 'w-4 h-4') ?> Clear temporary files
            </button>
        </form>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Notification queue</div>
            <div class="ad-card__sub">
                Order and account emails are queued, never sent inline, so checkout never waits on a mail server.
            </div>
        </div>
        <form method="post" action="<?= e($selfUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="process_queue">
            <button type="submit" class="ad-btn ad-btn--primary ad-btn--sm">
                <?= icon('mail', 'w-4 h-4') ?> Process queue now
            </button>
        </form>
    </div>
    <div class="ad-card__body">
        <div class="ad-grid ad-grid--3" style="gap:10px">
            <?= admin_stat_card('Pending', number_format($queuePending), 'clock',
                $queuePending > 0 ? 'amber' : 'green',
                $queueOldest !== null ? 'Oldest queued ' . time_ago($queueOldest) : 'Nothing waiting') ?>
            <?= admin_stat_card('Sent', number_format($queueSent), 'check-circle', 'green', 'Delivered to the mail server') ?>
            <?= admin_stat_card('Failed', number_format($queueFailed), 'alert',
                $queueFailed > 0 ? 'red' : 'green', 'Given up after 3 attempts') ?>
        </div>

        <?php if ($queueRecentFailures !== []): ?>
            <div class="ad-tablewrap" style="margin-top:16px">
                <table class="ad-table">
                    <thead>
                        <tr><th>Recipient</th><th>Template</th><th>Attempts</th><th>Last error</th><th>Queued</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queueRecentFailures as $failure): ?>
                            <tr>
                                <td><?= e($failure['recipient']) ?></td>
                                <td class="ad-mono"><?= e($failure['template_key'] ?: '—') ?></td>
                                <td class="ad-table__num"><?= (int) $failure['attempts'] ?></td>
                                <td class="ad-muted"><?= e(str_limit((string) ($failure['error'] ?? ''), 90) ?: '—') ?></td>
                                <td class="ad-muted"><?= e(time_ago($failure['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($queuePending === 0 && $queueSent === 0 && $queueFailed === 0): ?>
            <p class="ad-muted" style="margin-top:12px;font-size:13px">
                The queue is empty. It fills up as orders are placed and accounts are created.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
