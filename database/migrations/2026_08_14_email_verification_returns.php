<?php
/**
 * ShopInnKart - Migration: email verification tokens and return requests.
 *
 * Idempotent, like the invoice/email migration it borrows its helpers from.
 * Never DROPs or rewrites an existing table — schema.sql does that, and only
 * for fresh installs.
 *
 * Run from the project root:
 *     php database/migrations/2026_08_14_email_verification_returns.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// mig_table_exists() / mig_add_column() / mig_add_index() live in the previous
// migration at global scope. Redeclaring them here would fatal the moment both
// files were loaded in one request, so reuse them instead.
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_verification_returns_run(): array
{
    $applied = [];
    $errors  = [];

    // =======================================================================
    //  1. email_verifications
    //
    //  Shaped on password_resets, plus a user_id. A separate table rather than
    //  token columns on `users` because that holds only one outstanding token,
    //  keeps no record of how many verification mails went out, and ALTERs the
    //  busiest table in the schema. The codebase already made this choice once,
    //  for password resets.
    // =======================================================================
    if (!mig_table_exists('email_verifications')) {
        Database::query(
            "CREATE TABLE `email_verifications` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    INT UNSIGNED NOT NULL,
                `email`      VARCHAR(190) NOT NULL COMMENT 'The address being proved, snapshotted at issue time',
                `token_hash` VARCHAR(255) NOT NULL COMMENT 'sha256 of the plain token — a DB leak hands out no working links',
                `expires_at` DATETIME NOT NULL,
                `used_at`    DATETIME NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_everify_user` (`user_id`),
                KEY `idx_everify_token` (`token_hash`(191)),
                KEY `idx_everify_email` (`email`),
                CONSTRAINT `fk_everify_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $applied[] = '+ table email_verifications';
    }

    // =======================================================================
    //  2. return_requests
    //
    //  Returns are whole-order today: admin/orders/returns.php flips the order
    //  status and stores orders.return_reason, and nothing anywhere addresses a
    //  single line. This table adds the missing half — the customer's request
    //  and its approval trail — without pretending to support partial returns
    //  the rest of the app cannot honour.
    // =======================================================================
    if (!mig_table_exists('return_requests')) {
        Database::query(
            "CREATE TABLE `return_requests` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`      INT UNSIGNED NOT NULL,
                `order_number`  VARCHAR(40) NOT NULL COMMENT 'Snapshot so the list reads correctly without a join',
                `user_id`       INT UNSIGNED NULL COMMENT 'NULL for a guest order',
                `customer_name` VARCHAR(150) NOT NULL,
                `customer_email` VARCHAR(190) NOT NULL,

                `type`          ENUM('return','exchange') NOT NULL DEFAULT 'return',
                `reason`        VARCHAR(80) NOT NULL COMMENT 'One of the fixed reason codes',
                `comment`       TEXT NULL COMMENT 'The customer''s own words',

                `status`        ENUM('pending','approved','rejected','completed','cancelled') NOT NULL DEFAULT 'pending',
                `admin_note`    VARCHAR(500) NULL COMMENT 'Shown to the customer in the decision email',
                `decided_at`    DATETIME NULL,
                `decided_by`    INT UNSIGNED NULL COMMENT 'admins.id',

                -- 1 while the request is still live, NULL once it is closed.
                -- MySQL treats NULLs as distinct in a UNIQUE index, so pairing
                -- this with order_id permits exactly one OPEN request per order
                -- while leaving any number of historical ones.
                `open_marker`   TINYINT UNSIGNED GENERATED ALWAYS AS
                                (CASE WHEN `status` IN ('pending','approved') THEN 1 ELSE NULL END) VIRTUAL,

                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                KEY `idx_return_order` (`order_id`),
                KEY `idx_return_status` (`status`, `created_at`),
                KEY `idx_return_user` (`user_id`),
                KEY `idx_return_email` (`customer_email`),
                CONSTRAINT `fk_return_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_return_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $applied[] = '+ table return_requests';
    }

    // One open request per order. A customer double-submitting the form, or
    // hitting back and resubmitting, must not raise a second ticket — and the
    // admin queue must never show the same order twice.
    try {
        $line = mig_add_index(
            'return_requests',
            'uq_return_open',
            'UNIQUE KEY `uq_return_open` (`order_id`, `open_marker`)'
        );
        if ($line !== null) {
            $applied[] = $line;
        }
    } catch (Throwable $e) {
        $errors[] = 'return_requests index uq_return_open: ' . $e->getMessage();
    }

    return ['applied' => $applied, 'errors' => $errors];
}

// ---------------------------------------------------------------------------
//  CLI entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = migration_verification_returns_run();

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
