<?php
/**
 * ShopInnKart - Analytics daily rollup and clean-up (CLI).
 *
 * Turns yesterday's raw visits into the daily totals every report reads, then
 * deletes raw rows past the retention window. Two jobs in one entry point on
 * purpose: the purge must never run without the rollup that preserves what it
 * is about to delete, and a single cron line cannot be half-installed.
 *
 * Schedule it once a day, a few minutes after midnight STORE time:
 *
 *   Linux crontab (Hostinger):
 *     10 0 * * * /usr/bin/php /home/USER/domains/example.com/ecomweb/bin/analytics-rollup.php --quiet
 *
 *   Windows Task Scheduler (XAMPP):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\ecomweb\bin\analytics-rollup.php --quiet
 *
 * NO CRON? Nothing breaks. The reports roll up on demand when an admin opens
 * them (Settings > Analytics > "Update totals when a report is opened"), at
 * most once every fifteen minutes and at most three days per page load. The
 * cron job is faster, quieter and catches up further; the fallback means a
 * host without cron still shows yesterday's numbers.
 *
 * Options:
 *   --days=N          roll at most N days this run (default 400)
 *   --day=YYYY-MM-DD  roll exactly this day and stop, cursors untouched
 *   --rebuild=DATE    recompute every day from DATE to today, ignoring the
 *                     watermarks. For after a schema fix or an import.
 *   --no-commerce     skip the trailing re-derivation of units and revenue
 *   --no-purge        roll up, delete nothing
 *   --purge-only      delete only, roll up nothing
 *   --dry-run         count what the PURGE would delete, delete nothing. The
 *                     rollup still runs: it writes totals, never removes
 *                     anything, and the purge cannot be judged without it
 *   --max-batches=N   purge batches of 2,000 rows per table (default 25)
 *   --quiet           print only on error, for cron
 *
 * What this job does NOT prune: the application's own logs. Error, search and
 * email logs have their own windows in Admin > Security > Settings and their
 * own nightly job, bin/prune-logs.php. Two schedules deleting from one table
 * on different rules is how a retention promise quietly becomes untrue.
 *
 * Exit codes: 0 fine, 1 something failed, 2 another run held the lock.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI only.');
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/analytics/rollup.php';

$options = getopt('', ['days::', 'day::', 'rebuild::', 'no-commerce', 'no-purge', 'purge-only',
    'dry-run', 'max-batches::', 'quiet']);

$quiet  = array_key_exists('quiet', $options);
$dryRun = array_key_exists('dry-run', $options);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo $message, PHP_EOL;
    }
};

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
};

if (!analytics_tables_ready() || !an_rollup_table_ready()) {
    $fail('The analytics tables are missing. Run:');
    $fail('  php database/migrations/2026_09_24_analytics_tables.php');
    $fail('  php database/migrations/2026_09_24_analytics_rollups.php');
    exit(1);
}

// One run at a time. Two would each delete the other's rows between the
// DELETE and the INSERT of the same day, and the survivor would be a day
// missing half its traffic. The lock belongs to the connection, so a crashed
// run releases it by disconnecting - there is nothing to clean up by hand.
if (!analytics_rollup_lock()) {
    $say('Another analytics rollup is in progress - exiting.');
    exit(2);
}

$exit = 0;

try {
    // ---------------------------------------------------------------- rollup
    if (!array_key_exists('purge-only', $options)) {
        $result = analytics_rollup_run([
            'days'     => isset($options['days']) && is_string($options['days'])
                ? (int) $options['days']
                : 400,
            'day'      => isset($options['day']) && is_string($options['day']) && $options['day'] !== ''
                ? $options['day']
                : null,
            'rebuild'  => isset($options['rebuild']) && is_string($options['rebuild']) && $options['rebuild'] !== ''
                ? $options['rebuild']
                : null,
            'commerce' => !array_key_exists('no-commerce', $options),
        ]);

        $say(sprintf(
            'Rollup   %d day%s, %s rollup rows, %.1f s%s',
            count($result['days']),
            count($result['days']) === 1 ? '' : 's',
            number_format($result['rows']),
            $result['ms'] / 1000,
            $result['commerce'] > 0 ? sprintf('  (+%d day(s) of sales re-derived)', $result['commerce']) : ''
        ));

        if ($result['days'] !== []) {
            $say('         ' . reset($result['days']) . ' .. ' . end($result['days']));
        }
        if ($result['through'] !== '') {
            $say('         totals complete through ' . $result['through']);
        }

        foreach ($result['skipped'] as $day => $why) {
            $say('         skipped ' . $day . ': ' . $why);
        }

        foreach ($result['errors'] as $day => $message) {
            $fail('ERROR    ' . $day . ': ' . $message);
            $exit = 1;
        }
    }

    // ----------------------------------------------------------------- purge
    if (!array_key_exists('no-purge', $options)) {
        $purge = analytics_purge([
            'dry_run'     => $dryRun,
            'max_batches' => isset($options['max-batches']) && is_string($options['max-batches'])
                ? (int) $options['max-batches']
                : AN_PURGE_BATCHES,
        ]);

        $total = array_sum($purge['deleted']);

        if ($purge['through'] === '') {
            $say('Purge    nothing yet: no day has been rolled up, and raw rows are never');
            $say('         deleted before the totals that replace them exist.');
        } else {
            $say(sprintf(
                '%-8s %s row%s past %d days%s',
                $dryRun ? 'Purge?' : 'Purge',
                number_format($total),
                $total === 1 ? '' : 's',
                analytics_retention_days(),
                $dryRun ? ' (dry run - nothing deleted)' : ''
            ));

            foreach ($purge['deleted'] as $table => $count) {
                if ($count > 0) {
                    $say(sprintf(
                        '         %-14s %s%s',
                        $table,
                        number_format($count),
                        in_array($table, $purge['capped'], true) ? '   [hit the batch cap - more remains]' : ''
                    ));
                }
            }

            $say('         raw rows now start at ' . date('Y-m-d', strtotime($purge['cutoff'])));
        }
    }
} catch (Throwable $e) {
    $fail('ERROR    ' . $e->getMessage());
    ErrorHandler::log('error', 'analytics-rollup.php failed: ' . $e->getMessage());
    $exit = 1;
} finally {
    analytics_rollup_unlock();
}

if ($exit === 0 && $quiet === false) {
    $say('Logs (error, search, email) are pruned separately by bin/prune-logs.php.');
}

exit($exit);
