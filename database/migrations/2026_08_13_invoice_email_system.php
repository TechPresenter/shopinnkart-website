<?php
/**
 * ShopInnKart - Migration: invoice records, PDF bills, SMTP email + email log.
 *
 * Idempotent by design: every statement is guarded by an information_schema
 * check, so running it twice is a no-op. The live database already holds real
 * orders, so this never DROPs or rewrites an existing table — schema.sql does
 * that and is only for fresh installs.
 *
 * Run from the project root:
 *     php database/migrations/2026_08_13_invoice_email_system.php
 *
 * The admin System screen calls migration_invoice_email_run() directly.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// ---------------------------------------------------------------------------
//  Schema introspection helpers
// ---------------------------------------------------------------------------

function mig_table_exists(string $table): bool
{
    return (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `information_schema`.`TABLES`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t',
        ['t' => $table]
    ) > 0;
}

function mig_column_exists(string $table, string $column): bool
{
    return (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `COLUMN_NAME` = :c',
        ['t' => $table, 'c' => $column]
    ) > 0;
}

function mig_index_exists(string $table, string $index): bool
{
    return (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
         WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `INDEX_NAME` = :i',
        ['t' => $table, 'i' => $index]
    ) > 0;
}

/** Add a column only when it is absent. Returns a log line, or null. */
function mig_add_column(string $table, string $column, string $definition): ?string
{
    if (!mig_table_exists($table) || mig_column_exists($table, $column)) {
        return null;
    }
    Database::query(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    return sprintf('+ column %s.%s', $table, $column);
}

/** Add an index only when it is absent. Returns a log line, or null. */
function mig_add_index(string $table, string $index, string $definition): ?string
{
    if (!mig_table_exists($table) || mig_index_exists($table, $index)) {
        return null;
    }
    Database::query(sprintf('ALTER TABLE `%s` ADD %s', $table, $definition));
    return sprintf('+ index %s.%s', $table, $index);
}

// ---------------------------------------------------------------------------
//  Migration
// ---------------------------------------------------------------------------

/**
 * @return array{applied:string[], skipped:int, errors:string[]}
 */
function migration_invoice_email_run(): array
{
    $applied = [];
    $errors  = [];

    // =======================================================================
    //  1. invoice_counters — atomic sequential numbering
    //
    //  A COUNT(*)-based number (which is how order numbers are built) can hand
    //  the same value to two concurrent checkouts. A tax invoice number must
    //  never repeat or skip, so it comes from a row we increment atomically
    //  with LAST_INSERT_ID(expr) instead.
    // =======================================================================
    if (!mig_table_exists('invoice_counters')) {
        Database::query(
            "CREATE TABLE `invoice_counters` (
                `series`      VARCHAR(40) NOT NULL COMMENT 'e.g. INV-2026',
                `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`series`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $applied[] = '+ table invoice_counters';
    }

    // =======================================================================
    //  2. invoices
    //
    //  UNIQUE(order_id) is the whole duplicate-invoice defence. A repeated
    //  payment webhook races straight into that constraint and the second
    //  writer reads back the row the first one committed.
    // =======================================================================
    if (!mig_table_exists('invoices')) {
        Database::query(
            "CREATE TABLE `invoices` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `invoice_number`  VARCHAR(60) NOT NULL,
                `series`          VARCHAR(40) NOT NULL,
                `sequence_no`     INT UNSIGNED NOT NULL,

                `order_id`        INT UNSIGNED NOT NULL,
                `order_number`    VARCHAR(40) NOT NULL,
                `user_id`         INT UNSIGNED NULL COMMENT 'NULL for guest checkout',

                `customer_name`   VARCHAR(150) NOT NULL,
                `customer_email`  VARCHAR(190) NOT NULL,
                `customer_phone`  VARCHAR(20) NULL,

                `invoice_date`    DATETIME NOT NULL,
                `currency`        VARCHAR(10) NOT NULL DEFAULT 'INR',

                `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `discount_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `coupon_code`      VARCHAR(60) NULL,
                `shipping_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `tax_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `payment_charge`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `payment_discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `total_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `amount_paid`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `balance_due`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,

                `payment_status`  VARCHAR(20) NOT NULL DEFAULT 'pending',
                `payment_method`  VARCHAR(40) NOT NULL DEFAULT 'cod',
                `place_of_supply` VARCHAR(100) NULL,
                `seller_gstin`    VARCHAR(20) NULL,

                `snapshot`        LONGTEXT NULL COMMENT 'JSON: company block, addresses, priced lines, tax rows, terms — frozen at issue time',

                `pdf_path`        VARCHAR(255) NULL COMMENT 'Relative to STORAGE_PATH. Never rendered to the browser.',
                `pdf_filename`    VARCHAR(160) NULL,
                `pdf_size`        INT UNSIGNED NULL,
                `pdf_generated_at` DATETIME NULL,
                `pdf_error`       VARCHAR(500) NULL,

                `status`          ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_invoice_number` (`invoice_number`),
                UNIQUE KEY `uq_invoice_order` (`order_id`),
                UNIQUE KEY `uq_invoice_series_seq` (`series`, `sequence_no`),
                KEY `idx_invoice_user` (`user_id`),
                KEY `idx_invoice_date` (`invoice_date`),
                KEY `idx_invoice_email` (`customer_email`),
                CONSTRAINT `fk_invoice_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_invoice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $applied[] = '+ table invoices';
    }

    // =======================================================================
    //  3. notification_queue becomes the email log
    //
    //  The app already queues every message here with recipient/subject/body/
    //  attempts/error/sent_at. Rather than stand up a second, parallel
    //  email_logs table that would immediately disagree with this one, the
    //  existing queue gains the columns an email log needs.
    // =======================================================================
    $queueColumns = [
        'email_type'      => "VARCHAR(40) NULL COMMENT 'order_confirmation, invoice, payment_failed, ...' AFTER `template_key`",
        'recipient_name'  => 'VARCHAR(150) NULL AFTER `recipient`',
        'recipient_type'  => "ENUM('customer','admin') NOT NULL DEFAULT 'customer' AFTER `recipient_name`",
        'body_text'       => 'LONGTEXT NULL COMMENT \'Plain-text fallback part\' AFTER `body`',
        'user_id'         => 'INT UNSIGNED NULL AFTER `reference_id`',
        'order_id'        => 'INT UNSIGNED NULL AFTER `user_id`',
        'invoice_id'      => 'INT UNSIGNED NULL AFTER `order_id`',
        'attachment_path' => "VARCHAR(255) NULL COMMENT 'Relative to STORAGE_PATH' AFTER `invoice_id`",
        'attachment_name' => 'VARCHAR(160) NULL AFTER `attachment_path`',
        'smtp_response'   => 'VARCHAR(500) NULL AFTER `error`',
        'idempotency_key' => "VARCHAR(150) NULL COMMENT 'Blocks a duplicate webhook re-queueing the same mail'",
        'last_attempt_at' => 'DATETIME NULL AFTER `sent_at`',
    ];
    foreach ($queueColumns as $column => $definition) {
        try {
            $line = mig_add_column('notification_queue', $column, $definition);
            if ($line !== null) {
                $applied[] = $line;
            }
        } catch (Throwable $e) {
            $errors[] = 'notification_queue.' . $column . ': ' . $e->getMessage();
        }
    }

    foreach ([
        'uq_queue_idempotency' => 'UNIQUE KEY `uq_queue_idempotency` (`idempotency_key`)',
        'idx_queue_order'      => 'KEY `idx_queue_order` (`order_id`)',
        'idx_queue_user'       => 'KEY `idx_queue_user` (`user_id`)',
        'idx_queue_recipient'  => 'KEY `idx_queue_recipient` (`recipient`)',
        'idx_queue_type'       => 'KEY `idx_queue_type` (`email_type`, `status`)',
    ] as $index => $definition) {
        try {
            $line = mig_add_index('notification_queue', $index, $definition);
            if ($line !== null) {
                $applied[] = $line;
            }
        } catch (Throwable $e) {
            $errors[] = 'notification_queue index ' . $index . ': ' . $e->getMessage();
        }
    }

    // =======================================================================
    //  4. notification_templates gains the fields the admin editor needs
    // =======================================================================
    $templateColumns = [
        'description'    => 'VARCHAR(255) NULL AFTER `name`',
        'body_text'      => 'LONGTEXT NULL COMMENT \'Plain-text fallback; auto-derived from HTML when blank\' AFTER `body`',
        'recipient_type' => "ENUM('customer','admin') NOT NULL DEFAULT 'customer' AFTER `channel`",
        'is_system'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ships with the app — Reset to default restores it'",
        'attach_invoice' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Attach the order PDF invoice when sending'",
    ];
    foreach ($templateColumns as $column => $definition) {
        try {
            $line = mig_add_column('notification_templates', $column, $definition);
            if ($line !== null) {
                $applied[] = $line;
            }
        } catch (Throwable $e) {
            $errors[] = 'notification_templates.' . $column . ': ' . $e->getMessage();
        }
    }

    // The `variables` column ships as VARCHAR(500); the richer invoice/order
    // templates list more placeholders than that holds.
    try {
        $variablesType = (string) Database::fetchColumn(
            'SELECT `COLUMN_TYPE` FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `COLUMN_NAME` = :c',
            ['t' => 'notification_templates', 'c' => 'variables']
        );
        if (stripos($variablesType, 'varchar') === 0) {
            Database::query('ALTER TABLE `notification_templates` MODIFY COLUMN `variables` TEXT NULL COMMENT \'Comma list of {{placeholders}} available\'');
            $applied[] = '~ notification_templates.variables widened to TEXT';
        }
    } catch (Throwable $e) {
        $errors[] = 'notification_templates.variables: ' . $e->getMessage();
    }

    // =======================================================================
    //  5. Payment callback idempotency
    //
    //  payment_transactions exists but has never been written to. It is the
    //  natural ledger for gateway events, and a UNIQUE gateway_event_id is
    //  what makes a replayed webhook a no-op instead of a second payment.
    // =======================================================================
    foreach ([
        'gateway'          => "VARCHAR(40) NOT NULL DEFAULT '' AFTER `order_id`",
        'gateway_event_id' => "VARCHAR(190) NULL COMMENT 'Gateway-supplied event/payment id — the dedupe key' AFTER `event`",
        'status'           => "VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `amount`",
        'message'          => 'VARCHAR(500) NULL AFTER `status`',
    ] as $column => $definition) {
        try {
            $line = mig_add_column('payment_transactions', $column, $definition);
            if ($line !== null) {
                $applied[] = $line;
            }
        } catch (Throwable $e) {
            $errors[] = 'payment_transactions.' . $column . ': ' . $e->getMessage();
        }
    }

    try {
        $line = mig_add_index(
            'payment_transactions',
            'uq_txn_event',
            'UNIQUE KEY `uq_txn_event` (`gateway`, `gateway_event_id`)'
        );
        if ($line !== null) {
            $applied[] = $line;
        }
    } catch (Throwable $e) {
        $errors[] = 'payment_transactions index uq_txn_event: ' . $e->getMessage();
    }

    // payment_transactions.payment_id is NOT NULL with an FK; a callback that
    // arrives before any payment row exists needs it to be nullable.
    try {
        $nullable = (string) Database::fetchColumn(
            'SELECT `IS_NULLABLE` FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `COLUMN_NAME` = :c',
            ['t' => 'payment_transactions', 'c' => 'payment_id']
        );
        if (strtoupper($nullable) === 'NO') {
            Database::query('ALTER TABLE `payment_transactions` MODIFY COLUMN `payment_id` INT UNSIGNED NULL');
            $applied[] = '~ payment_transactions.payment_id made nullable';
        }
    } catch (Throwable $e) {
        $errors[] = 'payment_transactions.payment_id: ' . $e->getMessage();
    }

    // One gateway reference can only ever belong to one payment row. NULLs are
    // exempt from a MySQL UNIQUE index, so COD rows are unaffected.
    try {
        $line = mig_add_index('payments', 'uq_payment_reference', 'UNIQUE KEY `uq_payment_reference` (`gateway`, `reference`)');
        if ($line !== null) {
            $applied[] = $line;
        }
    } catch (Throwable $e) {
        $errors[] = 'payments index uq_payment_reference: ' . $e->getMessage();
    }

    return ['applied' => $applied, 'skipped' => 0, 'errors' => $errors];
}

// ---------------------------------------------------------------------------
//  CLI entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = migration_invoice_email_run();

    foreach ($result['applied'] as $line) {
        echo '  ', $line, PHP_EOL;
    }
    if ($result['applied'] === []) {
        echo '  Nothing to do — schema already up to date.', PHP_EOL;
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ', $line, PHP_EOL;
    }

    echo PHP_EOL, $result['errors'] === [] ? 'Migration complete.' : 'Migration finished with errors.', PHP_EOL;
    exit($result['errors'] === [] ? 0 : 1);
}
