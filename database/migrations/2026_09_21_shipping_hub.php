<?php
/**
 * ShopInnKart - Migration: third-party shipping integration hub.
 *
 * The shape here follows one requirement: a new courier must be addable
 * without touching the order, payment, product or customer modules. So the
 * courier-specific part is a driver class, and the database holds only what
 * every courier has in common:
 *
 *   shipping_providers   one row per integration, with encrypted credentials
 *   shipments            one row per consignment, keyed to an order
 *   shipment_events      the tracking timeline, one row per status change
 *   shipping_api_logs    every request and response, for when a courier
 *                        insists it never received the booking
 *
 * A shipment is NOT a column set bolted onto `orders`. One order can ship in
 * several parcels, be cancelled and rebooked with another courier, and then
 * come back as an RTO - each of those is its own consignment with its own AWB
 * and its own timeline. `orders.tracking_number` and `orders.courier_name`
 * stay where they are for the manual flow that exists today; nothing reads
 * them differently because of this migration.
 *
 * Credentials are stored encrypted with the application key, the same way SMTP
 * passwords already are. The column is TEXT because ciphertext is longer than
 * the secret and because a courier may need five fields where another needs two.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_21_shipping_hub.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_shipping_hub_run(): array
{
    $applied = [];
    $errors  = [];

    $run = static function (string $label, callable $fn) use (&$applied, &$errors): void {
        try {
            $line = $fn();
            if ($line !== null) {
                $applied[] = is_string($line) ? $line : $label;
            }
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    // =======================================================================
    //  1. shipping_providers
    // =======================================================================
    $run('+ table shipping_providers', static function (): ?string {
        if (mig_table_exists('shipping_providers')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `shipping_providers` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,

                /* Matches the driver registered in ShippingProviderFactory. A row
                   whose code has no driver is shown in the admin as unavailable
                   rather than offered and then failing at booking time. */
                `code`            VARCHAR(40) NOT NULL,
                `name`            VARCHAR(120) NOT NULL,

                `status`          ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
                `mode`            ENUM('test','live') NOT NULL DEFAULT 'test',
                `is_default`      TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order`      INT NOT NULL DEFAULT 0,

                /* Encrypted with the application key - never readable from a
                   database dump alone. Shape differs per courier, so it is a
                   JSON blob rather than a column per field: adding Delhivery
                   must not mean an ALTER TABLE. */
                `credentials`     TEXT NULL COMMENT 'Encrypted JSON: api key, secret, user, password, tokens',

                /* Pickup/warehouse address and the tax identity the courier
                   prints on the label. */
                `pickup_name`     VARCHAR(150) NULL,
                `pickup_phone`    VARCHAR(20) NULL,
                `pickup_email`    VARCHAR(190) NULL,
                `pickup_address`  VARCHAR(255) NULL,
                `pickup_city`     VARCHAR(100) NULL,
                `pickup_state`    VARCHAR(100) NULL,
                `pickup_pincode`  VARCHAR(10) NULL,
                `pickup_country`  VARCHAR(100) NOT NULL DEFAULT 'India',
                `gst_number`      VARCHAR(20) NULL,

                `supports_cod`    TINYINT(1) NOT NULL DEFAULT 1,
                `supports_return` TINYINT(1) NOT NULL DEFAULT 1,

                /* Webhook: the secret verifies the signature on an inbound call.
                   Stored encrypted for the same reason the credentials are. */
                `webhook_secret`  TEXT NULL,
                `webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0,

                /* Health, written by the connection test and by every API call,
                   so the admin list can say whether this integration is actually
                   working rather than merely switched on. */
                `last_checked_at` DATETIME NULL,
                `last_status`     ENUM('unknown','ok','failed') NOT NULL DEFAULT 'unknown',
                `last_message`    VARCHAR(255) NULL,

                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_shipping_provider_code` (`code`),
                KEY `idx_shipping_provider_status` (`status`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table shipping_providers';
    });

    // =======================================================================
    //  2. shipments
    // =======================================================================
    $run('+ table shipments', static function (): ?string {
        if (mig_table_exists('shipments')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `shipments` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`        INT UNSIGNED NOT NULL,
                `provider_id`     INT UNSIGNED NULL COMMENT 'NULL once a provider row is deleted; the shipment still happened',
                `provider_code`   VARCHAR(40) NOT NULL COMMENT 'Kept verbatim: which integration booked this, even if the row goes',

                /* What the courier calls it. `courier_name` is the sub-carrier an
                   aggregator picked - Shiprocket books, Delhivery delivers. */
                `awb`             VARCHAR(64) NULL,
                `courier_name`    VARCHAR(100) NULL,
                `shipment_ref`    VARCHAR(80) NULL COMMENT 'The provider order/shipment id, needed for cancel and track',

                `status`          VARCHAR(40) NOT NULL DEFAULT 'pending'
                                  COMMENT 'pending|ready|booked|pickup_scheduled|in_transit|out_for_delivery|delivered|cancelled|rto_initiated|rto_delivered|returned|failed',
                `status_detail`   VARCHAR(255) NULL COMMENT 'The courier own wording, unchanged',

                `is_cod`          TINYINT(1) NOT NULL DEFAULT 0,
                `cod_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
                `shipping_charge` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'What the courier charged US, not the customer',

                `weight_grams`    INT UNSIGNED NULL,
                `length_cm`       DECIMAL(8,2) NULL,
                `width_cm`        DECIMAL(8,2) NULL,
                `height_cm`       DECIMAL(8,2) NULL,

                `label_url`       VARCHAR(500) NULL,
                `manifest_url`    VARCHAR(500) NULL,
                `tracking_url`    VARCHAR(500) NULL,

                `pickup_date`     DATE NULL,
                `expected_at`     DATE NULL COMMENT 'Courier promised delivery date',
                `shipped_at`      DATETIME NULL,
                `delivered_at`    DATETIME NULL,

                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                KEY `idx_shipment_order` (`order_id`),
                KEY `idx_shipment_status` (`status`, `created_at`),
                /* The tracking page searches by AWB, and a webhook resolves the
                   shipment from the AWB the courier sends back. */
                KEY `idx_shipment_awb` (`awb`),
                KEY `idx_shipment_ref` (`provider_code`, `shipment_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table shipments';
    });

    // =======================================================================
    //  3. shipment_events
    // =======================================================================
    $run('+ table shipment_events', static function (): ?string {
        if (mig_table_exists('shipment_events')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `shipment_events` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `shipment_id`    INT UNSIGNED NOT NULL,

                `status`         VARCHAR(40) NOT NULL,
                `message`        VARCHAR(255) NULL,
                `location`       VARCHAR(150) NULL,
                `occurred_at`    DATETIME NOT NULL COMMENT 'When the courier says it happened, not when we heard',

                `source`         ENUM('webhook','poll','manual') NOT NULL DEFAULT 'webhook',

                /* Idempotency. A courier will resend the same event, and a poll
                   will return an event a webhook already delivered. The unique
                   key below makes the second copy a no-op instead of a second
                   row - which is what stops a timeline showing 'Delivered'
                   three times and a notification firing three times. */
                `event_key`      VARCHAR(120) NOT NULL COMMENT 'Provider event id, or a hash of status+occurred_at',

                `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_shipment_event` (`shipment_id`, `event_key`),
                KEY `idx_shipment_event_time` (`shipment_id`, `occurred_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table shipment_events';
    });

    // =======================================================================
    //  4. shipping_api_logs
    // =======================================================================
    $run('+ table shipping_api_logs', static function (): ?string {
        if (mig_table_exists('shipping_api_logs')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `shipping_api_logs` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `provider_code`  VARCHAR(40) NOT NULL,
                `shipment_id`    INT UNSIGNED NULL,
                `operation`      VARCHAR(40) NOT NULL COMMENT 'rates|serviceability|create|awb|label|manifest|pickup|track|cancel|return',

                `endpoint`       VARCHAR(255) NULL,
                `http_status`    SMALLINT UNSIGNED NULL,
                `duration_ms`    INT UNSIGNED NULL,
                `ok`             TINYINT(1) NOT NULL DEFAULT 0,

                /* Bodies are stored with credentials masked by the caller. A log
                   that quietly records an API key is a second place to leak it. */
                `request_body`   MEDIUMTEXT NULL,
                `response_body`  MEDIUMTEXT NULL,
                `error`          VARCHAR(500) NULL,

                `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                KEY `idx_shipping_log_provider` (`provider_code`, `created_at`),
                KEY `idx_shipping_log_shipment` (`shipment_id`),
                KEY `idx_shipping_log_failed` (`ok`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table shipping_api_logs';
    });

    // =======================================================================
    //  5. orders gets a pointer to its current shipment
    // =======================================================================
    $run('+ orders.shipment_id', static function (): ?string {
        return mig_add_column(
            'orders',
            'shipment_id',
            "INT UNSIGNED NULL COMMENT 'Current/primary consignment' AFTER `courier_name`"
        ) ? '+ orders.shipment_id' : null;
    });

    $run('+ index orders.shipment_id', static function (): ?string {
        return mig_add_index(
            'orders',
            'idx_order_shipment',
            "KEY `idx_order_shipment` (`shipment_id`)"
        ) ? '+ index orders.shipment_id' : null;
    });

    // =======================================================================
    //  5b. The provider-side order id, which some couriers cancel by
    // =======================================================================
    $run('+ shipments.order_ref', static function (): ?string {
        // Shiprocket tracks by shipment id but cancels by ORDER id - two
        // different numbers for one booking. Without this column a booked
        // consignment could be tracked but never cancelled from the admin.
        return mig_add_column(
            'shipments',
            'order_ref',
            "VARCHAR(80) NULL COMMENT 'Provider order id, where it differs from shipment_ref' AFTER `shipment_ref`"
        );
    });

    // =======================================================================
    //  6. The mock provider, so the hub is usable before any account exists
    // =======================================================================
    $run('+ mock shipping provider', static function (): ?string {
        if (Database::exists('shipping_providers', '`code` = :c', ['c' => 'mock'])) {
            return null;
        }
        Database::insert('shipping_providers', [
            'code'          => 'mock',
            'name'          => 'Mock Courier (testing)',
            'status'        => 'inactive',
            'mode'          => 'test',
            'supports_cod'  => 1,
            'sort_order'    => 99,
            'last_status'   => 'unknown',
        ]);
        return '+ mock shipping provider';
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_shipping_hub_run();
    echo "Shipping hub migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
