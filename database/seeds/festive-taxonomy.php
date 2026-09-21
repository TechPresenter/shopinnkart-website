<?php
/**
 * ShopInnKart - Align the taxonomy and navigation with the real catalogue.
 *
 * The store shipped with a seeded electronics tree — 30 categories, 18 brands
 * and 29 navigation entries for Smartphones, Laptops, Audio and the rest. Those
 * products are gone; the store now sells festive and decorative lighting, so
 * every one of those categories held zero products and every nav link led to an
 * empty listing.
 *
 * This removes the dead taxonomy and rebuilds the main and mobile menus around
 * the three categories that actually have stock. Idempotent: re-running finds
 * nothing to remove and rewrites the same menu.
 *
 *     php database/seeds/festive-taxonomy.php          apply
 *     php database/seeds/festive-taxonomy.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/**
 * The nav, in order. Seven inline items: includes/navbar.php collapses
 * anything past NAV_INLINE_LIMIT into an overflow menu, and the header audit
 * measured seven as the ceiling that still fits at 1024px.
 */
function festive_nav_plan(): array
{
    // Six top-level entries. The catalogue is reached through Shop and through
    // the category rail on the homepage rather than by putting three category
    // links in the header, which left no room for the content pages.
    return [
        ['label' => 'Home',    'type' => 'custom', 'url' => 'index.php'],
        ['label' => 'Shop',    'type' => 'custom', 'url' => 'shop.php'],
        ['label' => 'About',   'type' => 'custom', 'url' => 'about.php'],
        ['label' => 'Blog',    'type' => 'custom', 'url' => 'blog.php'],
        ['label' => 'FAQs',    'type' => 'custom', 'url' => 'faq.php'],
        ['label' => 'Contact', 'type' => 'custom', 'url' => 'contact.php'],
    ];
}

/**
 * The category children hang under Shop as a dropdown, so the catalogue is one
 * hover away without spending a top-level slot on each department.
 */
function festive_nav_children(): array
{
    return [
        'Shop' => [
            ['label' => 'All Products',            'slug' => null],
            ['label' => 'String & Curtain Lights', 'slug' => 'string-curtain-lights'],
            ['label' => 'Diyas & LED Candles',     'slug' => 'diyas-led-candles'],
            ['label' => 'Lamps & Projectors',      'slug' => 'lamps-projectors'],
        ],
    ];
}

/** Categories that hold stock, plus their parent, are keepers. */
function festive_live_category_ids(): array
{
    $withProducts = Database::fetchColumnAll(
        'SELECT DISTINCT `category_id` FROM `products` WHERE `category_id` IS NOT NULL'
    );
    if ($withProducts === []) {
        return [];
    }

    [$in, $params] = Database::inPlaceholders($withProducts, 'k');
    $parents = Database::fetchColumnAll(
        'SELECT DISTINCT `parent_id` FROM `categories` WHERE `id` IN (' . $in . ') AND `parent_id` IS NOT NULL',
        $params
    );

    return array_values(array_unique(array_map('intval', array_merge($withProducts, $parents))));
}

function festive_taxonomy_run(bool $dryRun = false): array
{
    $report = ['categories_removed' => 0, 'brands_removed' => 0, 'menu_items_written' => 0, 'kept' => [], 'notes' => []];

    $keep = festive_live_category_ids();
    if ($keep === []) {
        $report['notes'][] = 'No product carries a category — refusing to delete anything.';
        return $report;
    }

    [$keepIn, $keepParams] = Database::inPlaceholders($keep, 'k');

    $report['kept'] = Database::fetchAll(
        'SELECT `id`, `name`, `slug`, `parent_id` FROM `categories` WHERE `id` IN (' . $keepIn . ') ORDER BY `parent_id` IS NOT NULL, `id`',
        $keepParams
    );

    $doomed = Database::fetchColumnAll(
        'SELECT `id` FROM `categories` WHERE `id` NOT IN (' . $keepIn . ')',
        $keepParams
    );

    $emptyBrands = Database::fetchColumnAll(
        'SELECT b.`id` FROM `brands` b WHERE NOT EXISTS (SELECT 1 FROM `products` p WHERE p.`brand_id` = b.`id`)'
    );

    if ($dryRun) {
        $report['categories_removed'] = count($doomed);
        $report['brands_removed'] = count($emptyBrands);
        $report['notes'][] = 'Dry run — nothing written.';
        return $report;
    }

    Database::transaction(static function () use ($doomed, $emptyBrands, &$report) {
        if ($doomed !== []) {
            [$in, $params] = Database::inPlaceholders($doomed, 'd');

            // menu_items has no foreign key to categories, so a plain DELETE on
            // categories would leave the nav pointing at listings that no
            // longer exist. Clear the references first.
            Database::query("DELETE FROM `menu_items` WHERE `link_type` = 'category' AND `reference_id` IN ($in)", $params);
            Database::query("DELETE FROM `coupon_restrictions` WHERE `restriction_type` = 'category' AND `reference_id` IN ($in)", $params);
            Database::query("DELETE FROM `homepage_section_items` WHERE `item_type` = 'category' AND `item_id` IN ($in)", $params);

            $report['categories_removed'] = Database::query("DELETE FROM `categories` WHERE `id` IN ($in)", $params)->rowCount();
        }

        if ($emptyBrands !== []) {
            [$bIn, $bParams] = Database::inPlaceholders($emptyBrands, 'b');
            Database::query("DELETE FROM `menu_items` WHERE `link_type` = 'brand' AND `reference_id` IN ($bIn)", $bParams);
            $report['brands_removed'] = Database::query("DELETE FROM `brands` WHERE `id` IN ($bIn)", $bParams)->rowCount();
        }
    });

    // ---- Rebuild the main and mobile menus --------------------------------
    $slugToId = Database::fetchPairs('SELECT `slug`, `id` FROM `categories`');

    foreach (['main' => 1, 'mobile' => 2] as $location => $_) {
        $menuId = (int) Database::fetchColumn('SELECT `id` FROM `menus` WHERE `location` = :l LIMIT 1', ['l' => $location]);
        if ($menuId === 0) {
            continue;
        }

        Database::delete('menu_items', '`menu_id` = :m', ['m' => $menuId]);

        $sort = 0;
        foreach (festive_nav_plan() as $entry) {
            $sort += 10;

            if ($entry['type'] === 'category') {
                $categoryId = (int) ($slugToId[$entry['slug']] ?? 0);
                if ($categoryId === 0) {
                    continue;   // category was renamed or removed — skip rather than emit a dead link
                }
                $url = 'shop.php?category=' . $entry['slug'];
            } else {
                $categoryId = null;
                $url = $entry['url'];
            }

            $parentItemId = Database::insert('menu_items', [
                'menu_id'      => $menuId,
                'parent_id'    => null,
                'label'        => $entry['label'],
                'link_type'    => $entry['type'],
                'reference_id' => $categoryId,
                'url'          => $url,
                'sort_order'   => $sort,
                'status'       => STATUS_ACTIVE,
            ]);
            $report['menu_items_written']++;

            foreach (festive_nav_children()[$entry['label']] ?? [] as $childIndex => $child) {
                $childCategoryId = $child['slug'] === null ? null : (int) ($slugToId[$child['slug']] ?? 0);
                if ($child['slug'] !== null && $childCategoryId === 0) {
                    continue;
                }

                Database::insert('menu_items', [
                    'menu_id'      => $menuId,
                    'parent_id'    => $parentItemId,
                    'label'        => $child['label'],
                    'link_type'    => $child['slug'] === null ? 'custom' : 'category',
                    'reference_id' => $childCategoryId ?: null,
                    'url'          => $child['slug'] === null
                        ? 'shop.php'
                        : 'shop.php?category=' . $child['slug'],
                    'sort_order'   => ($childIndex + 1) * 10,
                    'status'       => STATUS_ACTIVE,
                ]);
                $report['menu_items_written']++;
            }
        }
    }

    // admin_after_write() lives in admin/includes/functions.php, which the CLI
    // bootstrap does not load; cache_bust() is the part that matters here.
    if (function_exists('admin_after_write')) {
        admin_after_write();
    } elseif (function_exists('cache_bust')) {
        cache_bust();
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_taxonomy_run(in_array('--dry', $argv, true));

    echo 'Categories kept:', PHP_EOL;
    foreach ($result['kept'] as $row) {
        printf('  %s#%-3d %s (%s)%s', $row['parent_id'] ? '  ' : '', $row['id'], $row['name'], $row['slug'], PHP_EOL);
    }
    printf('%sCategories removed : %d%sBrands removed     : %d%sMenu items written : %d%s',
        PHP_EOL, $result['categories_removed'], PHP_EOL, $result['brands_removed'], PHP_EOL, $result['menu_items_written'], PHP_EOL);
    foreach ($result['notes'] as $note) {
        echo '  ! ', $note, PHP_EOL;
    }
    exit(0);
}
