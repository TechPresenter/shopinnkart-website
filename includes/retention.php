<?php
/**
 * ShopInnKart - Data retention.
 *
 * One place that decides how long the store keeps what it has already
 * collected, and one job that enforces it. Before this, seven tables grew
 * without a ceiling: security_events, login_history, activity_logs,
 * error_logs, rate_limits, notification_queue and search_logs. On the test
 * database alone they were tens of thousands of rows, and every one of them
 * holds an IP address, an email address or a search a customer typed.
 *
 * Two rules shape the defaults:
 *
 *   - Evidence has a floor. security_events, login_history, activity_logs and
 *     error_logs answer "what happened when the store was attacked", so their
 *     retention cannot be set below RETENTION_EVIDENCE_FLOOR_DAYS - otherwise
 *     the retention screen becomes a way to erase the audit trail, which is
 *     exactly what admin/logs/_shared.php already refuses to allow.
 *   - Everything else is the opposite. A search log or a delivered email is
 *     not evidence, it is personal data the store no longer needs, so shorter
 *     is better and the floor is one day.
 *
 * Deleting is chunked and capped. A single unbounded DELETE over a hundred
 * thousand rows takes a lock long enough to stall checkout, and a cron job
 * that takes the site down once a night is worse than the rows it removed.
 */

declare(strict_types=1);

/**
 * How long the audit trail is kept at the very least.
 *
 * The same 180 days admin/logs/_shared.php enforces on the clear-log button.
 * Written out again here rather than included, because this file runs from
 * cron where the admin side is not loaded, and a floor that is only enforced
 * in the UI is not a floor.
 */
const RETENTION_EVIDENCE_FLOOR_DAYS = 180;

/** Rows deleted per statement. Small enough that the lock is never felt. */
const RETENTION_BATCH_ROWS = 2000;

/** Batches per table per run. The cap that stops one run running all night. */
const RETENTION_MAX_BATCHES = 25;

/**
 * Everything that is pruned, and on what terms.
 *
 * `evidence` marks the tables that cannot be set below the floor.
 * `where` is an extra condition: notification_queue only ever drops mail that
 * has already been dealt with, because a pending row is work the store still
 * owes somebody.
 *
 * @return array<string, array{label:string,column:string,days:int,setting:string,evidence:bool,where:string,why:string}>
 */
function retention_tables(): array
{
    return [
        'security_events' => [
            'label'    => 'Security events',
            'column'   => 'created_at',
            'days'     => 365,
            'setting'  => 'sec_retention_security_events',
            'evidence' => true,
            'where'    => '',
            'why'      => 'Refused logins, lockouts, privilege changes. Kept a year: the point of '
                . 'the log is to still be there when an intrusion is noticed months late.',
        ],
        'login_history' => [
            'label'    => 'Login history',
            'column'   => 'created_at',
            'days'     => 365,
            'setting'  => 'sec_retention_login_history',
            'evidence' => true,
            'where'    => '',
            'why'      => 'Every sign-in and every failure, with its IP address. A year covers '
                . '"has this account been used from somewhere strange".',
        ],
        'activity_logs' => [
            'label'    => 'Activity log',
            'column'   => 'created_at',
            'days'     => 730,
            'setting'  => 'sec_retention_activity_logs',
            'evidence' => true,
            'where'    => '',
            'why'      => 'What each admin did. Two years, because "who changed this price" is '
                . 'asked long after the fact.',
        ],
        'error_logs' => [
            'label'    => 'Error log',
            'column'   => 'created_at',
            'days'     => 180,
            'setting'  => 'sec_retention_error_logs',
            'evidence' => true,
            'where'    => '',
            'why'      => 'Stack traces and request context. Useful for a season, a liability '
                . 'after that.',
        ],
        'search_logs' => [
            'label'    => 'Search log',
            'column'   => 'created_at',
            'days'     => 180,
            'setting'  => 'sec_retention_search_logs',
            'evidence' => false,
            'where'    => '',
            'why'      => 'What customers typed into the search box, with their IP. Not evidence '
                . 'of anything - set it as short as the reports you actually read.',
        ],
        'notification_queue' => [
            'label'    => 'Sent and failed email',
            'column'   => 'created_at',
            'days'     => 180,
            'setting'  => 'sec_retention_notification_queue',
            'evidence' => false,
            'where'    => "`status` IN ('sent', 'failed')",
            'why'      => 'The rendered body of every message, which for a password reset is the '
                . 'reset link itself. Pending rows are never touched - they are mail the store '
                . 'still owes somebody.',
        ],
        'seo_404_log' => [
            'label'    => 'Broken-link monitor',
            // Not created_at: a dead URL that is still being hit every day is
            // still a live problem, however long ago it first appeared. Age is
            // measured from the last visit, so only links nobody follows any
            // more age out.
            'column'   => 'last_seen_at',
            'days'     => 90,
            'setting'  => 'seo_404_retention_days',
            'evidence' => false,
            'where'    => '',
            'why'      => 'Paths visitors asked for and did not get. Holds no IP, no browser and '
                . 'no visitor id - just the path, where the link was and a counter - so this is '
                . 'housekeeping rather than a privacy window. Ninety days is long enough to see a '
                . 'seasonal link go stale.',
        ],
        'rate_limits' => [
            'label'    => 'Rate-limit counters',
            'column'   => 'expires_at',
            'days'     => 7,
            'setting'  => 'sec_retention_rate_limits',
            'evidence' => false,
            'where'    => '',
            'why'      => 'Short-lived counters that age out on their own; this only sweeps up '
                . 'what the opportunistic cleanup missed.',
        ],
    ];
}

/** The configured retention for one table, floored where it is evidence. */
function retention_days(string $table): int
{
    $spec = retention_tables()[$table] ?? null;
    if ($spec === null) {
        return 0;
    }

    $days  = setting_int($spec['setting'], $spec['days']);
    $floor = $spec['evidence'] ? RETENTION_EVIDENCE_FLOOR_DAYS : 1;

    return max($floor, min(3650, $days));
}

/**
 * How many rows are currently past their retention, without deleting any.
 *
 * @return array<string, array{label:string,days:int,total:int,expired:int,error:string}>
 */
function retention_preview(): array
{
    $out = [];

    foreach (retention_tables() as $table => $spec) {
        $days = retention_days($table);
        $row  = ['label' => $spec['label'], 'days' => $days, 'total' => 0, 'expired' => 0, 'error' => ''];

        try {
            $where = retention_where($spec, $days);
            $row['total']   = (int) Database::fetchColumn(sprintf('SELECT COUNT(*) FROM `%s`', $table));
            $row['expired'] = (int) Database::fetchColumn(
                sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $where['sql']),
                $where['params']
            );
        } catch (Throwable $e) {
            // A table another migration has not created yet is not an error
            // worth failing a dashboard over.
            $row['error'] = $e->getMessage();
        }

        $out[$table] = $row;
    }

    return $out;
}

/** The WHERE clause that selects expired rows for one table. */
function retention_where(array $spec, int $days): array
{
    $sql = sprintf('`%s` < :cutoff', $spec['column']);
    if ($spec['where'] !== '') {
        $sql = $spec['where'] . ' AND ' . $sql;
    }

    return [
        'sql'    => $sql,
        'params' => ['cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400))],
    ];
}

/**
 * Delete what is past its retention.
 *
 * @param array{dry_run?:bool,tables?:string[],max_batches?:int} $options
 * @return array{deleted:array<string,int>,total:int,capped:string[],errors:array<string,string>,seconds:float,dry_run:bool}
 */
function retention_run(array $options = []): array
{
    $dryRun     = (bool) ($options['dry_run'] ?? false);
    $only       = isset($options['tables']) && is_array($options['tables'])
        ? array_map('strval', $options['tables'])
        : null;
    $maxBatches = max(1, min(1000, (int) ($options['max_batches'] ?? RETENTION_MAX_BATCHES)));

    $began  = microtime(true);
    $result = ['deleted' => [], 'total' => 0, 'capped' => [], 'errors' => [],
               'seconds' => 0.0, 'dry_run' => $dryRun];

    foreach (retention_tables() as $table => $spec) {
        if ($only !== null && !in_array($table, $only, true)) {
            continue;
        }

        $days  = retention_days($table);
        $where = retention_where($spec, $days);

        try {
            if ($dryRun) {
                $result['deleted'][$table] = (int) Database::fetchColumn(
                    sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $where['sql']),
                    $where['params']
                );
                $result['total'] += $result['deleted'][$table];
                continue;
            }

            $removed = 0;
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                // LIMIT is a literal: PDO cannot bind it with emulated
                // prepares off, and the value never comes from a request.
                $affected = Database::query(
                    sprintf('DELETE FROM `%s` WHERE %s LIMIT %d', $table, $where['sql'], RETENTION_BATCH_ROWS),
                    $where['params']
                )->rowCount();

                $removed += $affected;
                if ($affected < RETENTION_BATCH_ROWS) {
                    break;   // nothing left to take
                }

                // The cap is real: a table still full at the last batch is
                // reported as capped rather than quietly left half-pruned.
                if ($batch === $maxBatches - 1) {
                    $result['capped'][] = $table;
                }
            }

            $result['deleted'][$table] = $removed;
            $result['total'] += $removed;
        } catch (Throwable $e) {
            $result['errors'][$table] = $e->getMessage();
        }
    }

    $result['seconds'] = round(microtime(true) - $began, 2);

    if (!$dryRun) {
        retention_record_run($result);
    }

    return $result;
}

/** Remember the last run so the admin card can say when it happened. */
function retention_record_run(array $result): void
{
    try {
        setting_save('sec_retention_last_run', (string) json_encode([
            'at'      => date('Y-m-d H:i:s'),
            'total'   => (int) $result['total'],
            'seconds' => $result['seconds'],
            'capped'  => $result['capped'],
            'errors'  => array_keys($result['errors']),
            'by'      => PHP_SAPI === 'cli' ? 'cron' : 'admin',
        ], JSON_UNESCAPED_SLASHES), 'security', 'json');

        if ($result['total'] > 0 || $result['errors'] !== []) {
            security_event('retention.pruned', $result['errors'] === [] ? 'info' : 'low', [
                'deleted' => $result['deleted'],
                'capped'  => $result['capped'],
                'errors'  => $result['errors'],
            ]);
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'retention_record_run failed: ' . $e->getMessage());
    }
}

/** The last run, decoded, or null when retention has never been enforced. */
function retention_last_run(): ?array
{
    $raw = (string) setting('sec_retention_last_run', '');
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}
