<?php
/**
 * ShopInnKart - Migration: commerce-integrity fixes on the pricing side.
 *
 * Adds the three columns the one pricing engine needs, and clears out the
 * empty guest carts the old create-on-read cart_count() left behind.
 *
 * Idempotent by design: every statement is guarded by an information_schema
 * check, so running it twice is a no-op. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_commerce_pricing.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';   // mig_* helpers

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_commerce_pricing_run(): array
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

    // The set's own saving, kept apart from the coupon's. The order used to
    // carry neither: create_order() had no combo logic at all, so a shopper
    // who agreed to a bundle price was charged the full component price.
    $run('+ orders.combo_discount', static function (): ?string {
        return mig_add_column(
            'orders',
            'combo_discount',
            "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`"
        );
    });

    // The per-customer coupon limit is counted on identity now - account id
    // when signed in, email and phone either way - so a registered customer
    // cannot sign out and use a once-per-customer coupon again as a guest.
    $run('+ coupon_usage.phone', static function (): ?string {
        return mig_add_column('coupon_usage', 'phone', 'VARCHAR(20) NULL AFTER `email`');
    });

    $run('+ coupon_usage identity indexes', static function (): ?string {
        $lines = [];
        $email = mig_add_index('coupon_usage', 'idx_usage_identity_email', 'INDEX `idx_usage_identity_email` (`coupon_id`, `email`)');
        if ($email !== null) {
            $lines[] = $email;
        }
        $phone = mig_add_index('coupon_usage', 'idx_usage_identity_phone', 'INDEX `idx_usage_identity_phone` (`coupon_id`, `phone`)');
        if ($phone !== null) {
            $lines[] = $phone;
        }

        return $lines === [] ? null : implode(', ', $lines);
    });

    // Backfill the phone from the order the usage belongs to, so limits that
    // are already in force keep counting the same customers.
    $run('~ backfill coupon_usage.phone', static function (): ?string {
        if (!mig_column_exists('coupon_usage', 'phone')) {
            return null;
        }
        $filled = Database::query(
            'UPDATE `coupon_usage` cu
             INNER JOIN `orders` o ON o.`id` = cu.`order_id`
             SET cu.`phone` = o.`customer_phone`
             WHERE cu.`phone` IS NULL AND o.`customer_phone` <> \'\''
        )->rowCount();

        return $filled > 0 ? '~ backfilled ' . $filled . ' coupon_usage phone(s)' : null;
    });

    // cart_count() runs from includes/header.php on every page and used to
    // create a row for every session that reached it, crawlers included. The
    // reader never writes now; this clears what it left. Only guest carts,
    // only empty ones, only ones nobody has touched for a week - a signed-in
    // customer's cart and anything with a line or a coupon in it stays.
    $run('~ prune empty guest carts', static function (): ?string {
        $pruned = Database::query(
            'DELETE FROM `carts`
              WHERE `user_id` IS NULL
                AND `coupon_id` IS NULL AND `coupon_code` IS NULL
                AND `updated_at` < (NOW() - INTERVAL 7 DAY)
                AND NOT EXISTS (SELECT 1 FROM `cart_items` ci WHERE ci.`cart_id` = `carts`.`id`)'
        )->rowCount();

        return $pruned > 0 ? '~ pruned ' . $pruned . ' empty guest cart(s)' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_commerce_pricing_run();
    echo "Commerce pricing migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
