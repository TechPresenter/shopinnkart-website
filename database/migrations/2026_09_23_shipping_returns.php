<?php
/**
 * ShopInnKart - Migration: returns, RTO and courier milestone notifications.
 *
 *   shipments.direction            forward (the parcel going out) or return (the
 *                                  reverse pickup coming back). A return
 *                                  consignment has an AWB, scans and a timeline
 *                                  of its own, but it must NEVER hold the
 *                                  order's "one live consignment" slot - a
 *                                  return reaching "delivered" would otherwise
 *                                  mark the ORDER delivered and, for COD, paid.
 *   shipments.parent_shipment_id   the forward consignment this one is sending
 *                                  back, so the desk can show both legs of one
 *                                  parcel together.
 *   shipments.return_request_id    the customer's own request (return_requests)
 *                                  this pickup was booked against, where there
 *                                  is one. An RTO has none: nobody asked.
 *   shipments.return_reason        why it is coming back, in the words whoever
 *                                  booked it used.
 *
 *   shipment_notifications         one row per (consignment, milestone). The
 *                                  courier resends events and the poller comes
 *                                  round again, so "have we already told the
 *                                  customer this?" needs an answer that outlives
 *                                  the notification_queue row - which
 *                                  send-queued-emails.php prunes once sent.
 *
 * Also installs the three new email templates (pickup scheduled, RTO, return
 * pickup) through the shared seeder, so "Reset to default" in the admin editor
 * and this migration can never disagree about what they say.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_shipping_returns.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_shipping_returns_run(): array
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

    if (!mig_table_exists('shipments')) {
        return ['applied' => [], 'errors' => ['The shipping hub is not installed: run 2026_09_21_shipping_hub.php first.']];
    }

    // -----------------------------------------------------------------------
    //  1. The reverse leg's columns
    // -----------------------------------------------------------------------

    $run('+ shipments.direction', static function (): ?string {
        return mig_add_column(
            'shipments',
            'direction',
            "ENUM('forward','return') NOT NULL DEFAULT 'forward' "
            . "COMMENT 'A return consignment never holds the order live-shipment slot' AFTER `provider_code`"
        ) ? '+ shipments.direction' : null;
    });

    $run('+ shipments.parent_shipment_id', static function (): ?string {
        return mig_add_column(
            'shipments',
            'parent_shipment_id',
            "INT(10) UNSIGNED NULL COMMENT 'The forward consignment this return is sending back' AFTER `direction`"
        ) ? '+ shipments.parent_shipment_id' : null;
    });

    $run('+ shipments.return_request_id', static function (): ?string {
        return mig_add_column(
            'shipments',
            'return_request_id',
            "INT(10) UNSIGNED NULL COMMENT 'return_requests.id, where the customer asked; an RTO has none' AFTER `parent_shipment_id`"
        ) ? '+ shipments.return_request_id' : null;
    });

    $run('+ shipments.return_reason', static function (): ?string {
        return mig_add_column(
            'shipments',
            'return_reason',
            "VARCHAR(255) NULL COMMENT 'Why it is coming back, in the booker''s own words' AFTER `return_request_id`"
        ) ? '+ shipments.return_reason' : null;
    });

    // The Returns desk and shipment_live_for_order() both ask "the newest
    // consignment of this direction for this order"; without the index that is
    // a scan of every shipment the order ever had.
    $run('+ index shipments.idx_shipment_direction', static function (): ?string {
        return mig_add_index(
            'shipments',
            'idx_shipment_direction',
            'KEY `idx_shipment_direction` (`order_id`, `direction`, `status`)'
        ) ? '+ index shipments.idx_shipment_direction' : null;
    });

    $run('+ index shipments.idx_shipment_parent', static function (): ?string {
        return mig_add_index(
            'shipments',
            'idx_shipment_parent',
            'KEY `idx_shipment_parent` (`parent_shipment_id`)'
        ) ? '+ index shipments.idx_shipment_parent' : null;
    });

    // -----------------------------------------------------------------------
    //  2. The "have we told them?" ledger
    // -----------------------------------------------------------------------

    $run('+ table shipment_notifications', static function (): ?string {
        if (mig_table_exists('shipment_notifications')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `shipment_notifications` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `shipment_id` INT(10) UNSIGNED NOT NULL,
                `order_id` INT(10) UNSIGNED NOT NULL,
                `milestone` VARCHAR(40) NOT NULL COMMENT 'shipped, pickup_scheduled, rto, return_pickup, ...',
                `template_key` VARCHAR(80) NOT NULL,
                `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
                `queued` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = claimed but the queue refused it (no template, opted out)',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_shipment_milestone` (`shipment_id`, `milestone`),
                KEY `idx_shipment_notification_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table shipment_notifications';
    });

    // -----------------------------------------------------------------------
    //  3. Existing rows are all forward legs
    // -----------------------------------------------------------------------
    // The column defaults to 'forward', so this only matters on a database
    // where somebody added the column by hand with a different default.
    $run('~ existing shipments marked forward', static function (): ?string {
        $n = Database::query("UPDATE `shipments` SET `direction` = 'forward' WHERE `direction` IS NULL")->rowCount();
        return $n > 0 ? '~ ' . $n . ' shipment(s) marked forward' : null;
    });

    // -----------------------------------------------------------------------
    //  4. The three new customer emails
    // -----------------------------------------------------------------------
    // Through the shared seeder rather than an INSERT here: it installs what is
    // missing and leaves an edited template alone, and it is the same array
    // the admin editor's "Reset to default" reads.
    $run('+ courier milestone templates', static function (): ?string {
        require_once dirname(__DIR__) . '/seeds/email-templates.php';
        $result = seed_email_templates(false);
        return (int) $result['templates_added'] > 0
            ? '+ ' . (int) $result['templates_added'] . ' notification template(s)'
            : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_shipping_returns_run();
    echo "Shipping returns migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
