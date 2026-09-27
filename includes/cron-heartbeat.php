<?php
/**
 * ShopInnKart - did this scheduled job actually run?
 *
 * WHY A STAMP AND NOT AN INFERENCE
 * Every cron check this project had was an inference from data: "a message has
 * been sent, so the mail worker must be running", "there is a row in
 * `backups`, so backups must be happening". Both are wrong in the same two
 * ways. They pass forever once the condition has been true once - a cron
 * removed last week still looks healthy because a message was sent last month.
 * And on a fresh deploy they pass on history that travelled inside the
 * imported SQL dump, so a laptop's mail log vouches for a live server's cron.
 *
 * A heartbeat has neither problem. The job writes the moment it ran, on the
 * machine it ran on, and a missing or stale stamp is the absence of a run
 * rather than the absence of work.
 *
 * ON DISK, NOT IN THE DATABASE, for three reasons: a stamp is about this
 * machine and must not travel in a dump; the retention sweep must never be
 * able to delete it; and a job whose whole problem is that the database is
 * unreachable should still be able to say it tried.
 *
 * Every failure is swallowed. A worker must never fail because it could not
 * write a note about itself.
 */

declare(strict_types=1);

/** Where one job's stamp lives. Names are constrained, not interpolated. */
function cron_heartbeat_file(string $job): string
{
    $job = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $job) ?? '');

    return $job === '' ? '' : STORAGE_PATH . '/cron/' . $job . '.json';
}

/**
 * Record that a job has just run.
 *
 * Called at the END of a worker, so a stamp means "finished", not "started" -
 * a job that dies halfway should look like a job that did not run.
 *
 * @param array<string,scalar> $detail anything worth seeing on the status line
 */
function cron_heartbeat(string $job, array $detail = []): void
{
    try {
        $file = cron_heartbeat_file($job);
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        // The folder holds no secrets, but it sits under the web root on a
        // default install, and storage/ is refused wholesale by .htaccess.
        // These are the same two guards backup_dir_guard() writes.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess',
                "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }

        @file_put_contents($file, (string) json_encode([
            'job'    => $job,
            'at'     => date('c'),
            'unix'   => time(),
            'host'   => php_uname('n'),
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    } catch (Throwable $e) {
        // Deliberately silent: see the note above.
    }
}

/**
 * Stamp this job when the process ends, however it ends.
 *
 * One line at the top of a worker, because the workers do not share an exit
 * path - one returns through a finally, one calls a $finish() closure, four
 * exit() from different branches - and a stamp added to each of those by hand
 * is a stamp that will be missing from the seventh worker somebody writes.
 *
 * A NON-ZERO EXIT STILL STAMPS, and that is the point. This answers "is the
 * schedule wired up on this machine", not "did the work succeed" - the job's
 * own output and exit code answer that. A backup that ran and failed is a
 * different problem from a backup that never ran, and conflating them is how
 * the old inference-based checks went wrong.
 *
 * A FATAL does not stamp: the process did not finish, so neither did the job.
 */
function cron_heartbeat_on_finish(string $job, callable $detail = null): void
{
    register_shutdown_function(static function () use ($job, $detail): void {
        $fatal = error_get_last();
        if ($fatal !== null
            && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $extra = [];
        if ($detail !== null) {
            try {
                $extra = (array) $detail();
            } catch (Throwable $e) {
                $extra = [];
            }
        }

        cron_heartbeat($job, $extra);
    });
}

/**
 * When a job last finished, or null if it never has here.
 *
 * @return array{at:string,unix:int,age:int,host:string,detail:array}|null
 */
function cron_heartbeat_read(string $job): ?array
{
    $file = cron_heartbeat_file($job);
    if ($file === '' || !is_file($file)) {
        return null;
    }

    $raw = json_decode((string) @file_get_contents($file), true);
    if (!is_array($raw) || !isset($raw['unix'])) {
        return null;
    }

    return [
        'at'     => (string) ($raw['at'] ?? ''),
        'unix'   => (int) $raw['unix'],
        'age'    => max(0, time() - (int) $raw['unix']),
        'host'   => (string) ($raw['host'] ?? ''),
        'detail' => is_array($raw['detail'] ?? null) ? $raw['detail'] : [],
    ];
}

/**
 * The jobs docs/GO-LIVE.md tells the owner to schedule, and how late each one
 * may be before its silence means something.
 *
 * The windows are generous on purpose - roughly three missed runs - because a
 * shared host will skip a minute under load and an alarm that cries on the
 * first skip is one nobody reads.
 *
 * @return array<string, array{label:string, every:string, stale:int, required:bool}>
 */
function cron_heartbeat_jobs(): array
{
    return [
        'send-queued-emails' => [
            'label'    => 'Email queue',
            'every'    => 'every minute',
            'stale'    => 600,
            // The only one where silence means customers get nothing at all.
            'required' => true,
        ],
        'security-monitor' => [
            'label'    => 'Security monitor',
            'every'    => 'every 5 minutes',
            'stale'    => 1800,
            'required' => false,
        ],
        'refresh-shipments' => [
            'label'    => 'Courier tracking',
            'every'    => 'every 30 minutes',
            'stale'    => 7200,
            'required' => false,
        ],
        'analytics-rollup' => [
            'label'    => 'Analytics rollup',
            'every'    => 'nightly',
            'stale'    => 172800,
            'required' => false,
        ],
        'backup' => [
            'label'    => 'Backup',
            'every'    => 'nightly',
            'stale'    => 172800,
            'required' => false,
        ],
        'prune-logs' => [
            'label'    => 'Log retention',
            'every'    => 'nightly',
            'stale'    => 172800,
            'required' => false,
        ],
    ];
}
