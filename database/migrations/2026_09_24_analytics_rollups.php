<?php
/**
 * ShopInnKart - Migration: the two rollup tables phase B3 needs (B3).
 *
 * B2's migration created the five an_daily_* tables the original plan named.
 * Building the rollup against them turned up two reads that they cannot
 * answer quickly, and both are on the hot path of every screen B4 will draw:
 *
 *   1. THE HEADLINE NUMBERS FOR A DAY.
 *      an_daily_traffic is a cube keyed by (day, channel, source, device,
 *      country, region, visitor_type). Asking it for "sessions yesterday"
 *      means summing every row of that day, and asking for a 90-day sparkline
 *      means summing ninety days of them - thousands of rows for twelve
 *      numbers. `an_daily_totals` is one row per day, so the KPI strip and the
 *      chart beside it are a ninety-row range scan of a primary key.
 *
 *      It also holds the numbers the cube CANNOT hold. `visitors` in the cube
 *      is distinct WITHIN a row: one visitor who arrives from search and comes
 *      back from an email is 1 in each of two rows, and adding the rows says
 *      2. The day's true distinct count only exists if something computes it
 *      for the whole day, which is what this table is for.
 *
 *      And it separates two things every analytics tool quietly conflates:
 *      ORDERS THE STORE TOOK (from `orders`, the same revenue statuses the
 *      reports use) and ORDERS ANALYTICS COULD ATTRIBUTE to a visit (from the
 *      purchase events). The second is always the smaller: a shopper with an
 *      ad blocker still buys. A dashboard that prints the attributed number
 *      under the word "orders" is a dashboard that disagrees with the orders
 *      screen, and the owner is right to believe the orders screen.
 *
 *   2. EVENT COUNTS.
 *      "How many add-to-carts yesterday" had no home: an_events is raw and
 *      purged after the retention window, and none of the daily tables counts
 *      by event name. `an_daily_event` is one row per (day, event), which is
 *      what the funnel's middle steps and the marketing tiles read.
 *
 * Both are ROLLUPS: kept forever, rewritten in full for any day that changes,
 * never added to incrementally. That is what makes re-running the rollup for
 * a day produce identical rows rather than doubled ones.
 *
 * Idempotent. Never DROPs. A table that already exists is left exactly as it
 * is, so a second run reports "nothing to do".
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_analytics_rollups.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_analytics_rollups_run(): array
{
    $applied = [];
    $errors  = [];

    $run = static function (string $label, callable $fn) use (&$applied, &$errors): void {
        try {
            $line = $fn();
            if ($line !== null && $line !== false) {
                $applied[] = is_string($line) ? $line : $label;
            }
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    $tables = [

        // One row per day. Every number a dashboard shows first.
        //
        // The column names say where each one comes from, because the two
        // families answer different questions and must never be added
        // together:
        //   orders / revenue / units   - the store's own books, from `orders`,
        //                                filtered by the same REVENUE_ORDER_
        //                                STATUSES the reports use. Re-derived
        //                                for the trailing window so a
        //                                cancellation flows back into last
        //                                week's figure.
        //   attr_orders / attr_revenue - the part of that which analytics
        //                                could tie to a visit. Smaller by the
        //                                share of shoppers whose beacon never
        //                                arrived; the gap is a number B4 shows
        //                                rather than hides.
        'an_daily_totals' => "CREATE TABLE `an_daily_totals` (
            `day`               DATE NOT NULL,
            `sessions`          INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `visitors`          INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Distinct for THIS day. Approximate, and not additive across days',
            `new_visitors`      INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 in anonymous mode: a daily key cannot know',
            `known_type`        INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sessions whose new-vs-returning IS known, so a screen can say what share',
            `pageviews`         INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_sessions`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `engaged_ms`        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            `bounces`           INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sessions - engaged_sessions, stored so the screen does no arithmetic it could get wrong',
            `view_sessions`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `cart_sessions`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `checkout_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `purchase_sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `attr_orders`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `attr_revenue`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `orders`            INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'From `orders`. The number the orders screen shows',
            `revenue`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `units`             INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sales (units): SUM(order_items.quantity)',
            `rolled_at`         DATETIME NOT NULL,
            PRIMARY KEY (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // One row per (day, event name). `sessions` is distinct sessions, so
        // "3 people added to a cart" and "3 adds by one person" are different
        // numbers instead of the same one.
        'an_daily_event' => "CREATE TABLE `an_daily_event` (
            `day`      DATE NOT NULL,
            `name`     TINYINT(3) UNSIGNED NOT NULL COMMENT 'AN_EVENT_IDS in includes/analytics/classify.php',
            `events`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `sessions` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Distinct sessions. Unattributed events (session_id NULL) are in `events` and not here',
            `qty`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `value`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`day`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $table => $ddl) {
        $run('+ table ' . $table, static function () use ($table, $ddl): ?string {
            if (mig_table_exists($table)) {
                return null;
            }
            Database::query($ddl);
            return '+ table ' . $table;
        });
    }

    // -----------------------------------------------------------------------
    //  Settings B3 introduces
    // -----------------------------------------------------------------------
    // analytics_retention_days already exists (B2). These three are the rest
    // of the housekeeping, and every one has a working default in code, so a
    // store that never opens the screen behaves correctly.
    $settings = [
        // How far back a run re-derives units and revenue from `orders`. An
        // order confirmed, cancelled or refunded days after it was placed
        // changes a day that was already rolled up; this is how long that
        // correction still reaches it. 45 days covers a normal returns window
        // without re-reading a quarter of the orders table every night.
        'analytics_commerce_window_days' => ['45', 'analytics', 'number'],
        // A store with no cron rolls up on demand when an admin opens a
        // report. Off means the numbers only move when the cron job runs -
        // correct for a host with cron, and one less thing happening inside a
        // page load.
        'analytics_rollup_on_demand'     => ['1', 'analytics', 'boolean'],
        // Written by the rollup, read by the screen: the last run's summary.
        // A setting rather than an an_state row because the screen already
        // reads settings, and because it is the owner's information, not the
        // collector's state.
        'analytics_rollup_last_run'      => ['', 'analytics', 'text'],
    ];

    foreach ($settings as $key => [$value, $group, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $group, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, $group, $type);
            return '+ setting ' . $key . ($value !== '' ? ' = ' . $value : '');
        });
    }

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_analytics_rollups_run();
    echo "Analytics migration (B3: rollup tables)\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  nothing to do\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
