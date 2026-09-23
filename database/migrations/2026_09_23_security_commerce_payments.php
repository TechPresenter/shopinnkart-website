<?php
/**
 * ShopInnKart - Migration: commerce security, payments side.
 *
 * Three records the payment path could not keep honestly before:
 *
 *   payment_refunds  every refund a gateway reports, with its own amount. The
 *                    order's payment status is then DERIVED from the sum, so a
 *                    Rs 1 refund can no longer mark a Rs 12,000 order refunded.
 *   partially_refunded
 *                    the state that record makes possible, added to
 *                    orders.payment_status and payments.status.
 *   credit_notes     the document that cancels supplied goods. A tax invoice
 *                    for goods that WERE supplied is never withdrawn - GST law
 *                    wants a numbered credit note against it instead - while an
 *                    order cancelled before dispatch supplied nothing, so its
 *                    invoice is simply marked cancelled.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_security_commerce_payments.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_commerce_payments_run(): array
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

    // -----------------------------------------------------------------------
    //  1. Every refund on its own row
    // -----------------------------------------------------------------------
    $run('+ table payment_refunds', static function (): ?string {
        if (mig_table_exists('payment_refunds')) {
            return null;
        }

        Database::query(
            "CREATE TABLE `payment_refunds` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`     INT UNSIGNED NOT NULL,
                `payment_id`   INT UNSIGNED NULL,
                `gateway`      VARCHAR(40) NOT NULL,
                `reference`    VARCHAR(190) NULL COMMENT 'The gateway refund id',
                `event_id`     VARCHAR(190) NULL COMMENT 'The webhook delivery that reported it',
                `amount`       DECIMAL(12,2) NOT NULL,
                `currency`     VARCHAR(10) NOT NULL DEFAULT 'INR',
                `reason`       VARCHAR(255) NULL,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                /* One gateway refund id is one refund however many times it is
                   redelivered - the amounts are SUMMED, so a duplicate row
                   would refund the order twice on paper. */
                UNIQUE KEY `uq_refund_ref` (`gateway`, `reference`),
                KEY `idx_refund_order` (`order_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table payment_refunds';
    });

    // -----------------------------------------------------------------------
    //  2. The state a partial refund leaves the order in
    // -----------------------------------------------------------------------
    $run('~ payment_status enums', static function (): ?string {
        $changed = [];

        $widen = static function (string $table, string $column, string $definition) use (&$changed): void {
            $type = (string) Database::fetchColumn(
                'SELECT `COLUMN_TYPE` FROM `information_schema`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `COLUMN_NAME` = :c',
                ['t' => $table, 'c' => $column]
            );
            if ($type === '' || strpos($type, 'partially_refunded') !== false) {
                return;
            }
            Database::query(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $definition));
            $changed[] = $table . '.' . $column;
        };

        $widen('orders', 'payment_status',
            "ENUM('pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'pending'");
        $widen('payments', 'status',
            "ENUM('pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'pending'");

        return $changed === [] ? null : '~ ' . implode(', ', $changed) . " now accept 'partially_refunded'";
    });

    // -----------------------------------------------------------------------
    //  3. Credit notes, in their own series
    // -----------------------------------------------------------------------
    $run('+ table credit_notes', static function (): ?string {
        if (mig_table_exists('credit_notes')) {
            return null;
        }

        Database::query(
            "CREATE TABLE `credit_notes` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `note_number`     VARCHAR(60) NOT NULL,
                `series`          VARCHAR(40) NOT NULL,
                `sequence_no`     INT UNSIGNED NOT NULL,
                `invoice_id`      INT UNSIGNED NOT NULL,
                `invoice_number`  VARCHAR(60) NOT NULL,
                `order_id`        INT UNSIGNED NOT NULL,
                `order_number`    VARCHAR(40) NOT NULL,
                `user_id`         INT UNSIGNED NULL,
                `customer_name`   VARCHAR(150) NOT NULL,
                `customer_email`  VARCHAR(190) NOT NULL,
                `note_date`       DATETIME NOT NULL,
                `currency`        VARCHAR(10) NOT NULL,
                `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `tax_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `total_amount`    DECIMAL(12,2) NOT NULL,
                `reason`          VARCHAR(255) NULL,
                /* What caused it: 'order:returned', 'refund:<id>'. UNIQUE with
                   the order so one cause raises exactly one note however many
                   times the status moves or the gateway redelivers. */
                `cause`           VARCHAR(60) NOT NULL,
                `snapshot`        LONGTEXT NULL,
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_note_number` (`note_number`),
                UNIQUE KEY `uq_note_cause` (`order_id`, `cause`),
                KEY `idx_note_invoice` (`invoice_id`),
                KEY `idx_note_series` (`series`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table credit_notes';
    });

    $run('+ credit note settings', static function (): ?string {
        $added = 0;
        foreach ([['credit_note_prefix', 'CRN', 'text']] as [$key, $value, $type]) {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                continue;
            }
            setting_save($key, $value, 'invoice', $type);
            $added++;
        }

        return $added > 0 ? '+ credit note settings (' . $added . ')' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_commerce_payments_run();
    echo "Commerce security (payments) migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
