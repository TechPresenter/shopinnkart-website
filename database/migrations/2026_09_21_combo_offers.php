<?php
/**
 * ShopInnKart - Migration: combo offers (bundles).
 *
 * A combo is a curated set of products sold together at a set price. The
 * design decision that shapes every table here:
 *
 *   A combo in the basket is ONE line to the shopper and SEVERAL lines to the
 *   warehouse.
 *
 * The alternative - a combo as its own pseudo-product - means reimplementing
 * stock, tax, variants, fulfilment and returns for a second kind of thing.
 * Instead the cart holds the component rows it already knows how to hold,
 * tagged with `combo_id` and a `combo_group`, and the cart renders a group as
 * one card. So:
 *
 *   - inventory decrements per product, through the existing path
 *   - order_items still lists what has to be picked and packed
 *   - tax, HSN and variant handling are untouched
 *   - the combo's saving is one discount line against the group
 *
 * `combo_group` rather than `combo_id` alone, because a shopper can buy the
 * same combo twice and the two instances must stay separable.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_21_combo_offers.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// mig_table_exists() / mig_column_exists() / mig_add_column() / mig_add_index()
// are declared at global scope in the first migration.
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_combo_offers_run(): array
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
    //  1. combos
    // =======================================================================
    $run('+ table combos', static function (): ?string {
        if (mig_table_exists('combos')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `combos` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`             VARCHAR(200) NOT NULL,
                `slug`             VARCHAR(220) NOT NULL,
                `subtitle`         VARCHAR(255) NULL,
                `description`      TEXT NULL,
                `image`            VARCHAR(255) NULL COMMENT 'Card thumbnail',
                `banner`           VARCHAR(255) NULL COMMENT 'Wide banner for the combo page',

                /* Pricing. `mrp` is the sum of the components at full price and is
                   RECOMPUTED on save, never typed - a stored MRP that drifts from
                   the catalogue is how a store ends up advertising a saving it is
                   not giving. `price` is what the shopper pays for the set. */
                `pricing_mode`     ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed'
                                   COMMENT 'fixed = price is set; percentage = price derived from discount_percent',
                `price`            DECIMAL(12,2) NOT NULL DEFAULT 0,
                `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
                `mrp`              DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Computed: sum of component MRP x qty',

                /* Availability. `stock_mode` = track caps how many sets can be
                   sold beyond what the component stock already allows. */
                `stock_mode`       ENUM('components','track') NOT NULL DEFAULT 'components',
                `stock`            INT NULL,
                `sold_count`       INT UNSIGNED NOT NULL DEFAULT 0,
                `max_per_order`    TINYINT UNSIGNED NOT NULL DEFAULT 5,

                `is_featured`      TINYINT(1) NOT NULL DEFAULT 0,
                `is_flash`         TINYINT(1) NOT NULL DEFAULT 0,
                `is_best_seller`   TINYINT(1) NOT NULL DEFAULT 0,
                `badge_text`       VARCHAR(40) NULL COMMENT 'Overrides the derived badge',

                `coupon_id`        INT UNSIGNED NULL COMMENT 'A coupon advertised with this combo',

                `start_date`       DATETIME NULL,
                `end_date`         DATETIME NULL,
                `sort_order`       INT NOT NULL DEFAULT 0,
                `status`           ENUM('active','inactive','draft') NOT NULL DEFAULT 'draft',

                /* The same SEO columns categories carry, so seo_from_entity()
                   works on a combo with no special case. */
                `meta_title`       VARCHAR(255) NULL,
                `meta_description` TEXT NULL,
                `og_image`         VARCHAR(255) NULL,

                `views`            INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_combos_slug` (`slug`),
                KEY `ix_combos_live` (`status`, `start_date`, `end_date`),
                KEY `ix_combos_sort` (`sort_order`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table combos';
    });

    // =======================================================================
    //  2. combo_items - what is in the set
    // =======================================================================
    $run('+ table combo_items', static function (): ?string {
        if (mig_table_exists('combo_items')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `combo_items` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `combo_id`   INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `variant_id` INT UNSIGNED NULL,
                `quantity`   INT UNSIGNED NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'Drag order in the admin builder',
                PRIMARY KEY (`id`),
                KEY `ix_combo_items_combo` (`combo_id`, `sort_order`),
                KEY `ix_combo_items_product` (`product_id`),
                CONSTRAINT `fk_combo_items_combo` FOREIGN KEY (`combo_id`)
                    REFERENCES `combos` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table combo_items';
    });

    // =======================================================================
    //  3. combo_images - the gallery
    // =======================================================================
    $run('+ table combo_images', static function (): ?string {
        if (mig_table_exists('combo_images')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `combo_images` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `combo_id`   INT UNSIGNED NOT NULL,
                `image`      VARCHAR(255) NOT NULL,
                `alt_text`   VARCHAR(255) NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `ix_combo_images_combo` (`combo_id`, `sort_order`),
                CONSTRAINT `fk_combo_images_combo` FOREIGN KEY (`combo_id`)
                    REFERENCES `combos` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table combo_images';
    });

    // =======================================================================
    //  4. The basket and the order remember which lines were a set
    // =======================================================================
    $run('cart_items.combo_id', static fn () => mig_add_column(
        'cart_items',
        'combo_id',
        'INT UNSIGNED NULL COMMENT "Set this line belongs to" AFTER `variant_id`'
    ));
    $run('cart_items.combo_group', static fn () => mig_add_column(
        'cart_items',
        'combo_group',
        'CHAR(13) NULL COMMENT "One instance of a combo; the same combo added twice has two groups" AFTER `combo_id`'
    ));
    $run('ix_cart_items_combo', static fn () => mig_add_index(
        'cart_items',
        'ix_cart_items_combo',
        'INDEX `ix_cart_items_combo` (`cart_id`, `combo_group`)'
    ));

    $run('order_items.combo_id', static fn () => mig_add_column(
        'order_items',
        'combo_id',
        'INT UNSIGNED NULL AFTER `variant_id`'
    ));
    $run('order_items.combo_name', static fn () => mig_add_column(
        'order_items',
        'combo_name',
        'VARCHAR(200) NULL COMMENT "Copied at order time, like product_name" AFTER `combo_id`'
    ));
    $run('order_items.combo_group', static fn () => mig_add_column(
        'order_items',
        'combo_group',
        'CHAR(13) NULL AFTER `combo_name`'
    ));
    $run('ix_order_items_combo', static fn () => mig_add_index(
        'order_items',
        'ix_order_items_combo',
        'INDEX `ix_order_items_combo` (`combo_id`)'
    ));

    // =======================================================================
    //  5. A homepage widget type, so the builder can place combos
    // =======================================================================
    $run('homepage_sections combo widget', static function (): ?string {
        if (!mig_table_exists('homepage_sections')) {
            return null;
        }
        if (Database::exists('homepage_sections', '`section_key` = :k', ['k' => 'home_combo_offers'])) {
            return null;
        }
        Database::insert('homepage_sections', [
            'section_key'  => 'home_combo_offers',
            'zone'         => 'home',
            'widget_type'  => 'combo_grid',
            'title'        => 'Combo offers',
            'subtitle'     => 'Buy the set and save',
            'link_text'    => 'All combos',
            'link_url'     => 'combos.php',
            'layout'       => 'grid',
            'card_style'   => 'standard',
            'item_limit'   => 6,
            'cols_desktop' => 3,
            'cols_tablet'  => 2,
            'cols_mobile'  => 1,
            'container'    => 'boxed',
            'padding'      => 'md',
            'lazy_load'    => 0,
            'sort_order'   => 45,
            // Off until the operator has built a combo. A section that renders
            // nothing is harmless, but one an operator has to discover is worse
            // than one they switch on themselves.
            'status'       => 'inactive',
        ]);
        return '+ homepage section home_combo_offers';
    });

    return ['applied' => $applied, 'errors' => $errors];
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_combo_offers_run();

    echo "Combo offers migration\n";
    echo str_repeat('-', 54), "\n";
    foreach ($result['applied'] as $line) {
        echo '  ', $line, "\n";
    }
    if ($result['applied'] === []) {
        echo "  nothing to do - already applied\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ', $line, "\n";
    }
    echo str_repeat('-', 54), "\n";
    echo count($result['applied']), " applied, ", count($result['errors']), " error(s)\n";

    if (function_exists('cache_bust')) {
        cache_bust();
    }
    exit($result['errors'] === [] ? 0 : 1);
}
