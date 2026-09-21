<?php
/**
 * ShopInnKart - Migration: storefront redesign.
 *
 *   1. Theme modes. `theme_mode_default` (system | light | dark) and
 *      `theme_toggle_enabled`, both edited in Admin > Settings > Theme.
 *
 *   2. Offers. An "offer" is a coupon the store has chosen to advertise, not a
 *      second discount table: the discount, dates, usage limits and the
 *      product / category / brand restrictions already live on `coupons` and
 *      `coupon_restrictions`, and validate_coupon() already enforces them at
 *      checkout. Advertising something the checkout would not honour is the
 *      failure this design rules out — so the only new columns are about
 *      presentation: whether to show it, what to call it, where, and in what
 *      order.
 *
 * Idempotent, like the migrations it borrows helpers from. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_14_storefront_redesign.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// mig_table_exists() / mig_add_column() / mig_add_index() are declared at global
// scope in the first migration; redeclaring them would fatal.
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_storefront_redesign_run(): array
{
    $applied = [];
    $errors  = [];

    // =======================================================================
    //  1. Theme mode settings
    // =======================================================================
    $settings = [
        // key, value, type, label, sort
        ['theme_mode_default',   'system', 'select',  'Default colour mode',               40],
        ['theme_toggle_enabled', '1',      'boolean', 'Let shoppers switch light and dark', 41],
    ];
    foreach ($settings as [$key, $value, $type, $label, $sort]) {
        try {
            if (!Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                Database::insert('settings', [
                    'setting_group' => 'theme',
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'setting_type'  => $type,
                    'label'         => $label,
                    'sort_order'    => $sort,
                ]);
                $applied[] = '+ setting ' . $key;
            }
        } catch (Throwable $e) {
            $errors[] = 'setting ' . $key . ': ' . $e->getMessage();
        }
    }

    // =======================================================================
    //  2. Offer presentation on coupons
    //
    //  offer_visible defaults to 0 for every existing row: no coupon becomes a
    //  public promotion until someone in the admin decides it should.
    // =======================================================================
    $columns = [
        'offer_title'      => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Customer-facing headline; the description is used when empty' AFTER `description`",
        'offer_visible'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Advertise this coupon as an offer on the storefront' AFTER `status`",
        'offer_placements' => "VARCHAR(60) NOT NULL DEFAULT 'product,cart' COMMENT 'Comma list of: home, product, cart' AFTER `offer_visible`",
        'offer_sort'       => "INT NOT NULL DEFAULT 0 COMMENT 'Lower shows first' AFTER `offer_placements`",
    ];
    foreach ($columns as $column => $definition) {
        try {
            $line = mig_add_column('coupons', $column, $definition);
            if ($line !== null) {
                $applied[] = $line;
            }
        } catch (Throwable $e) {
            $errors[] = 'coupons.' . $column . ': ' . $e->getMessage();
        }
    }

    try {
        $line = mig_add_index('coupons', 'idx_coupons_offer', 'INDEX `idx_coupons_offer` (`offer_visible`, `status`, `offer_sort`)');
        if ($line !== null) {
            $applied[] = $line;
        }
    } catch (Throwable $e) {
        $errors[] = 'coupons index idx_coupons_offer: ' . $e->getMessage();
    }

    if ($applied !== []) {
        settings_cache_generation(true);
        cache_bust();
    }

    return ['applied' => $applied, 'errors' => $errors];
}

// ---------------------------------------------------------------------------
//  CLI entry point
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = migration_storefront_redesign_run();

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
