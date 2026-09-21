<?php
/**
 * ShopInnKart - Rewrite the page SEO for the catalogue that exists.
 *
 * seo_settings shipped with copy for the seeded electronics store, so the
 * browser tab on the shop read "Shop All Electronics Online" and the homepage
 * title offered "Smartphones, Laptops, Audio & Smart Devices" — on a store that
 * sells string lights, diyas and table lamps. These are the strings search
 * engines and social cards use, so they were the most visible wrong copy left
 * on the site.
 *
 * Only the rows whose copy names a department are touched. Cart, Checkout,
 * Track Order and Contact were already catalogue-neutral and are left alone.
 *
 * Idempotent: re-running writes the same values.
 *
 *     php database/seeds/festive-seo.php          apply
 *     php database/seeds/festive-seo.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/** page_key => [meta_title, meta_description] */
function festive_seo_plan(): array
{
    return [
        'home' => [
            'ShopInnKart — Festive & Decorative Lighting Online in India',
            'String and curtain lights, diyas, LED candles, table lamps and projectors. Genuine products, fast delivery and secure payments across India.',
        ],
        'shop' => [
            'Shop Festive & Decorative Lighting | ShopInnKart',
            'Browse string lights, curtain lights, diyas, LED candles, table lamps and projector lamps, with filters for category, brand, price and rating.',
        ],
        'deals' => [
            "Today's Best Lighting Deals | ShopInnKart",
            'Limited-time offers on festive string lights, curtain lights, diyas and decorative lamps. Grab them before the sale ends.',
        ],
        'new-arrivals' => [
            'New Arrivals in Festive Lighting | ShopInnKart',
            'The newest string lights, curtain lights, diyas and decorative lamps, freshly added to ShopInnKart.',
        ],
        'best-sellers' => [
            'Best Selling Festive Lighting | ShopInnKart',
            'The lights our customers buy most — top rated curtain lights, fairy lights, diyas and table lamps.',
        ],
        'brands' => [
            'Shop by Brand | ShopInnKart',
            'Explore the lighting brands we stock, from fairy and curtain lights to decorative lamps and LED candles.',
        ],
        'blog' => [
            'Decor Guides & Lighting Ideas | ShopInnKart',
            'Styling guides, festive decor ideas and buying advice to help you choose the right lights for your home.',
        ],
    ];
}

function festive_seo_run(bool $dryRun = false): array
{
    $report = ['updated' => [], 'missing' => []];

    foreach (festive_seo_plan() as $key => [$title, $description]) {
        $row = Database::fetch(
            'SELECT `id`, `meta_title` FROM `seo_settings` WHERE `page_key` = :k LIMIT 1',
            ['k' => $key]
        );

        if (!$row) {
            $report['missing'][] = $key;
            continue;
        }

        $report['updated'][] = ['key' => $key, 'from' => (string) $row['meta_title'], 'to' => $title];

        if (!$dryRun) {
            Database::update('seo_settings', [
                'meta_title'       => $title,
                'meta_description' => $description,
            ], '`id` = :id', ['id' => (int) $row['id']]);
        }
    }

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
    $result = festive_seo_run(in_array('--dry', $argv, true));

    foreach ($result['updated'] as $r) {
        printf('  %-13s %s%s                %s-> %s%s', $r['key'], $r['from'], PHP_EOL, '', $r['to'], PHP_EOL);
    }
    foreach ($result['missing'] as $key) {
        echo '  missing  ', $key, PHP_EOL;
    }
    printf('%sRows updated: %d%s', PHP_EOL, count($result['updated']), PHP_EOL);
    exit(0);
}
