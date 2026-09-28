<?php
/**
 * ShopInnKart - Migration: the integration send queue.
 *
 * WHY A QUEUE AND NOT A CALL
 * --------------------------
 * The Meta Conversions API and the custom webhook both talk to a machine we do
 * not control. Sending inline, from the request that placed the order, hands
 * that machine two powers it should never have: it can make a checkout slow by
 * being slow, and it can lose a conversion by being down - because the only
 * moment we would ever have tried is the moment it was unreachable.
 *
 * So nothing sends during a request. An event is written here, and
 * bin/send-integration-events.php delivers it from cron with backoff, exactly
 * the shape `notification_queue` and bin/send-queued-emails.php already have -
 * an operator who has debugged one has debugged both.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY GUARD
 * ---------------------------------------
 * `uq_event (channel, event_id)` is not a tidiness constraint, it is what makes
 * the whole design safe. The event_id is derived from the event name and the
 * order number, so it is the same string every time anybody computes it. That
 * means:
 *
 *   - the cron harvester can re-scan an order it has already seen and the
 *     second insert is simply refused, so the watermark below only has to be
 *     roughly right instead of exactly right;
 *   - a retry sends the SAME id, so Meta deduplicates our own second attempt
 *     rather than counting the purchase twice;
 *   - and if the browser pixel is ever taught to fire Purchase, it can emit
 *     this same id as its `eventID` and Meta will collapse the pair.
 *
 * WHAT IS IN `payload`
 * -------------------
 * The exact bytes that will be sent, built once at queue time. They have to be
 * frozen rather than rebuilt at send time for two reasons: the webhook
 * signature is computed over the body, so a rebuilt body would not match a
 * signature a retry had already published; and an `order.paid` event delivered
 * an hour late should describe the order as it was when it was paid, not as it
 * is now.
 *
 * For Meta that payload contains only SHA-256 hashes of customer data - no
 * readable email or phone is ever written to this table. For the webhook it
 * does contain the order's customer name, email and address, because that is
 * the point of a webhook to an endpoint the owner controls; those are the same
 * fields already sitting in `orders`, so this adds no new kind of data to the
 * database, only a second copy with its own retention.
 *
 * ONE INDEX ON A TABLE THIS MIGRATION DOES NOT OWN
 * ------------------------------------------------
 * The harvester asks `orders` for rows changed since a watermark, once a
 * minute. `orders` has no index on `updated_at`, so that question is a full
 * table scan - 4,494 rows here, and a great deal more on a live store, every
 * minute forever. The index is added additively under a distinctive name and
 * is skipped when already present; nothing else about `orders` is touched.
 *
 * Idempotent. Never DROPs. Re-running reports "nothing to do".
 *
 *     php database/migrations/2026_09_28_integration_queue.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

/** The settings group every key this migration creates is filed under. */
const INTEGRATION_QUEUE_GROUP = 'integrations';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_integration_queue_run(): array
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

    $tableExists = static function (string $table): bool {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t',
            ['t' => $table]
        ) > 0;
    };

    $indexExists = static function (string $table, string $index): bool {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `INDEX_NAME` = :i',
            ['t' => $table, 'i' => $index]
        ) > 0;
    };

    // -----------------------------------------------------------------------
    //  Tables
    // -----------------------------------------------------------------------
    $tables = [

        // One row per event per channel. `status` separates the four outcomes
        // an operator has to tell apart: still trying, delivered, given up on,
        // and deliberately not sent (consent refused, or the channel is off).
        'integration_queue' => "CREATE TABLE `integration_queue` (
            `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `channel`         ENUM('meta_capi','webhook') NOT NULL,
            `event`           VARCHAR(40) NOT NULL COMMENT 'Purchase, order.paid, ...',
            `event_id`        VARCHAR(100) NOT NULL COMMENT 'The dedup id. Identical in the browser pixel',
            `order_id`        INT(10) UNSIGNED NULL,
            `payload`         LONGTEXT NOT NULL COMMENT 'The exact bytes to send. Meta payloads carry hashes only',
            `consent`         ENUM('granted','refused','unknown','not_applicable') NOT NULL DEFAULT 'unknown'
                              COMMENT 'The marketing decision in force when this event was queued',
            `status`          ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            `attempts`        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
            `next_attempt_at` DATETIME NOT NULL,
            `last_attempt_at` DATETIME NULL,
            `sent_at`         DATETIME NULL,
            `http_status`     SMALLINT(5) UNSIGNED NULL,
            `response`        VARCHAR(500) NULL COMMENT 'Truncated. Secrets are masked before it is written',
            `error`           VARCHAR(500) NULL,
            `is_test`         TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_event` (`channel`, `event_id`),
            KEY `ix_due` (`status`, `next_attempt_at`),
            KEY `ix_order` (`order_id`),
            KEY `ix_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // The harvester's watermark, and nothing else. On disk it would not
        // survive a deploy that replaces the tree; in `settings` it would show
        // up on a settings screen as a field somebody can edit by accident.
        'integration_state' => "CREATE TABLE `integration_state` (
            `name`       VARCHAR(60) NOT NULL,
            `value`      VARCHAR(190) NOT NULL DEFAULT '',
            `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $table => $ddl) {
        $run('+ table ' . $table, static function () use ($table, $ddl, $tableExists): ?string {
            if ($tableExists($table)) {
                return null;
            }
            Database::query($ddl);
            return '+ table ' . $table;
        });
    }

    // -----------------------------------------------------------------------
    //  The one index on `orders` - see the file docblock
    // -----------------------------------------------------------------------
    $run('~ index orders.ix_orders_updated_at', static function () use ($indexExists, $tableExists): ?string {
        if (!$tableExists('orders') || $indexExists('orders', 'ix_orders_updated_at')) {
            return null;
        }
        Database::query('ALTER TABLE `orders` ADD KEY `ix_orders_updated_at` (`updated_at`)');
        return '~ index orders.ix_orders_updated_at (harvester scans by updated_at)';
    });

    // -----------------------------------------------------------------------
    //  Settings
    // -----------------------------------------------------------------------
    // Both senders ship OFF. A store that has pasted a token is not a store
    // that has agreed to start sending, and a migration that switches on
    // traffic to a third party is a migration nobody can safely run.
    $settings = [
        'meta_capi_enabled'           => ['0', 'boolean'],
        'integration_webhook_enabled' => ['0', 'boolean'],
        // Which events the owner wants posted, comma separated. Defaulting to
        // order.paid alone rather than everything: the endpoint gets to opt in
        // to volume, not discover it.
        'integration_webhook_events'  => ['order.paid', 'text'],
        // The queue doubles as the send log, and the webhook copy of an order
        // holds customer data, so it must not grow forever.
        'integration_queue_retention_days' => ['30', 'number'],
    ];

    foreach ($settings as $key => [$value, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, INTEGRATION_QUEUE_GROUP, $type);
            return '+ setting ' . $key . ' = ' . ($value === '' ? '(blank)' : $value);
        });
    }

    $run('= settings cache', static function (): ?string {
        settings_cache_generation(true);
        if (function_exists('cache_bust')) {
            cache_bust();
        }
        return null;
    });

    // No new permissions: the screen is still settings.view to read and
    // settings.edit to change, and the worker is CLI.

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/_cli.php';
    migration_refuse_arguments($argv);

    $result = migration_integration_queue_run();
    echo "Integration queue migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  (nothing to do - already applied)\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
