<?php
/**
 * ShopInnKart - Data retention (CLI).
 *
 * Enforces the windows set in Admin > Security > Settings. Seven tables grow
 * on every request and nothing used to shrink them: security_events,
 * login_history, activity_logs, error_logs, rate_limits, notification_queue
 * and search_logs. Every one of them holds an IP address, an email address or
 * something a customer typed.
 *
 * It also sweeps up export bundles nobody collected, which are the single most
 * sensitive files the store ever writes.
 *
 * Schedule it daily, after the backup:
 *
 *   Linux crontab:
 *     40 3 * * * /usr/bin/php /home/USER/domains/example.com/ecomweb/bin/prune-logs.php --quiet
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\prune-logs.php --quiet
 *
 * Options:
 *   --dry-run          count what would go, delete nothing
 *   --tables=a,b       only these tables
 *   --max-batches=N    batches of 2,000 rows per table (default 25 = 50,000)
 *   --quiet            only print on error
 *
 * The batch cap is not a detail. One unbounded DELETE over a hundred thousand
 * rows holds a lock long enough to stall checkout, so a run takes what it can
 * and says when it hit the cap; the next night takes the rest.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/retention.php';
require_once INCLUDES_PATH . '/privacy.php';

$options = getopt('', ['dry-run', 'tables::', 'max-batches::', 'quiet']);
$quiet   = array_key_exists('quiet', $options);
$dryRun  = array_key_exists('dry-run', $options);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo $message, PHP_EOL;
    }
};

// Two runs at once would each take a batch of the same rows and double the
// lock time, which is the one thing the batching exists to avoid.
$locked = (int) Database::fetchColumn("SELECT GET_LOCK('sik_retention', 0)") === 1;
if (!$locked) {
    $say('Another retention run is in progress - exiting.');
    exit(0);
}

$result = retention_run([
    'dry_run'     => $dryRun,
    'tables'      => isset($options['tables']) && is_string($options['tables']) && $options['tables'] !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $options['tables']))))
        : null,
    'max_batches' => isset($options['max-batches']) && is_string($options['max-batches'])
        ? (int) $options['max-batches']
        : RETENTION_MAX_BATCHES,
]);

$say($dryRun ? 'Retention (dry run - nothing deleted)' : 'Retention');

foreach (retention_tables() as $table => $spec) {
    if (!array_key_exists($table, $result['deleted']) && !isset($result['errors'][$table])) {
        continue;
    }
    $say(sprintf(
        '  %-22s %-7s past %3d days%s',
        $table,
        number_format((int) ($result['deleted'][$table] ?? 0)),
        retention_days($table),
        in_array($table, $result['capped'], true) ? '   [hit the batch cap - more remains]' : ''
    ));
}

$bundles = 0;
if (!$dryRun) {
    try {
        $bundles = privacy_prune_bundles();
        if ($bundles > 0) {
            $say('  ' . str_pad('export bundles', 22) . number_format($bundles) . ' uncollected file(s) deleted');
        }
    } catch (Throwable $e) {
        $result['errors']['privacy_bundles'] = $e->getMessage();
    }
}

$say(sprintf('  %s row(s) in %ss.', number_format($result['total']), $result['seconds']));

foreach ($result['errors'] as $table => $message) {
    fwrite(STDERR, '  ERROR ' . $table . ': ' . $message . PHP_EOL);
}

Database::query("SELECT RELEASE_LOCK('sik_retention')");

exit($result['errors'] === [] ? 0 : 1);
