<?php
/**
 * ShopInnKart - Rewrite the homepage banners for the lighting catalogue.
 *
 * All six banners were electronics copy, and the visible damage was worse than
 * the titles suggested. Verified against includes/widgets.php (hero ~line 646,
 * promo ~line 1104):
 *
 *   - `subtitle` is NEVER rendered on the storefront. It appears only in admin.
 *     Rewriting title + subtitle alone would have left the live copy untouched.
 *   - `description` is the customer-facing sentence (.sik-hero__text /
 *     .sik-promo__text) and held the worst claims:
 *       #1 "Over 12,000 hand-picked products from Apple, Samsung, Sony, Dell,
 *          ASUS" - the catalogue is 11 products and none of those brands
 *       #2 "exchange bonuses up to Rs8,000 and 24-month no-cost EMI"
 *       #5 "SAVE UP TO Rs35,000" - the dearest product sells for Rs399
 *       #6 "Bluetooth calling watches start at just Rs1,799"
 *   - The headline is `title` + `title_accent` rendered as one heading, which
 *     is why "UP TO" / "SAVE UP TO" / "FLAT" looked truncated - the accents
 *     carried "40% OFF", "Rs35,000", "30% OFF".
 *   - Promo button URLs pointed at dead categories: shop.php?category=audio,
 *     =laptops, =wearables. Live slugs are string-curtain-lights,
 *     diyas-led-candles, lamps-projectors.
 *   - The promo widget shows ONE banner picked at random (array_rand), so 4, 5
 *     and 6 each have to stand alone.
 *
 * EVERY NUMBER BELOW IS CHECKED AGAINST THE LIVE DATABASE:
 *   11 active products, selling Rs149-Rs399
 *   9 of 11 discounted; deepest is 86% off, so "up to 80% off" is true
 *   free_shipping_threshold 999, return_window_days 7, COD is the only gateway
 * No claim is made that the store cannot support. Badges are stored in sentence
 * case because the CSS already uppercases them.
 *
 *     php database/seeds/festive-banners.php          apply
 *     php database/seeds/festive-banners.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

function festive_banner_plan(): array
{
    return [
        1 => [
            'title'        => 'Light up',
            'title_accent' => 'every celebration',
            'subtitle'     => 'String lights, curtain lights, LED diyas and lamps for Diwali, weddings and everyday warmth.',
            'description'  => 'Fairy and curtain lights, flameless diyas, crystal table lamps and galaxy projectors — a small range, chosen carefully, all of it under ₹400. Free delivery over ₹999.',
            'badge'        => 'Welcome to ShopInnKart',
            'button_text'  => 'Shop the range',
            'button_url'   => 'shop.php',
            'button2_text' => 'See offers',
            'button2_url'  => 'deals.php',
        ],
        2 => [
            'title'        => 'Curtain lights',
            'title_accent' => 'for the whole wall',
            'subtitle'     => 'Star, snowflake and diya curtains that turn a plain wall into a backdrop.',
            'description'  => 'Star and snowflake curtains, 300 LED fairy curtains with a remote, and hanging diya strands. USB or mains, warm white or multicolour, eight modes as standard.',
            'badge'        => 'Backdrop ready',
            'button_text'  => 'Shop curtain lights',
            'button_url'   => 'shop.php?category=string-curtain-lights',
            'button2_text' => 'All products',
            'button2_url'  => 'shop.php',
        ],
        3 => [
            'title'        => 'Up to 80% off',
            'title_accent' => 'this festive season',
            'subtitle'     => 'Nine of our eleven products are discounted right now.',
            'description'  => 'Curtain lights, table lamps and projector lamps at their lowest of the season. Cash on Delivery available, and 7 days to send anything back.',
            'badge'        => 'Limited period offer',
            'button_text'  => 'Shop the sale',
            'button_url'   => 'deals.php',
            'button2_text' => 'Browse all',
            'button2_url'  => 'shop.php',
        ],
        4 => [
            'title'        => 'From',
            'title_accent' => '₹149',
            'subtitle'     => 'LED diyas and tea light candles.',
            'description'  => 'Flameless diyas and acrylic tea lights — safe near children, pets and curtains, and they pack away for next year.',
            'badge'        => 'Diyas & candles',
            'button_text'  => 'Shop diyas',
            'button_url'   => 'shop.php?category=diyas-led-candles',
            'button2_text' => '',
            'button2_url'  => '',
        ],
        5 => [
            'title'        => 'Up to',
            'title_accent' => '83% off',
            'subtitle'     => 'Table lamps and galaxy projectors.',
            'description'  => 'Crystal table lamps with touch and remote control, and astronaut galaxy projectors for a ceiling that does the decorating.',
            'badge'        => 'Lamps & projectors',
            'button_text'  => 'Shop lamps',
            'button_url'   => 'shop.php?category=lamps-projectors',
            'button2_text' => '',
            'button2_url'  => '',
        ],
        6 => [
            'title'        => 'Free delivery',
            'title_accent' => 'over ₹999',
            'subtitle'     => 'On every order, anywhere we deliver.',
            'description'  => 'Dispatched within one working day. Pay the courier on delivery if you prefer, and raise a return from your orders page within 7 days.',
            'badge'        => 'Delivery & returns',
            'button_text'  => 'Start shopping',
            'button_url'   => 'shop.php',
            'button2_text' => '',
            'button2_url'  => '',
        ],
    ];
}

function festive_banners_run(bool $dryRun = false): array
{
    $report = ['updated' => [], 'missing' => [], 'deadLinks' => []];

    // Every button URL must resolve to a live category, or the banner sends the
    // shopper to an empty listing - which is how the old ones broke.
    $slugs = Database::fetchColumnAll("SELECT `slug` FROM `categories` WHERE `status` = 'active'");

    foreach (festive_banner_plan() as $id => $fields) {
        $row = Database::fetch('SELECT `id`, `title`, `title_accent` FROM `banners` WHERE `id` = :id', ['id' => $id]);
        if (!$row) {
            $report['missing'][] = $id;
            continue;
        }

        foreach (['button_url', 'button2_url'] as $key) {
            $url = (string) $fields[$key];
            if ($url !== '' && preg_match('/category=([a-z0-9-]+)/', $url, $m) === 1
                && !in_array($m[1], $slugs, true)) {
                $report['deadLinks'][] = $id . ' -> ' . $url;
            }
        }

        $report['updated'][] = [
            'id'   => $id,
            'from' => trim($row['title'] . ' ' . $row['title_accent']),
            'to'   => trim($fields['title'] . ' ' . $fields['title_accent']),
        ];

        if (!$dryRun) {
            Database::update('banners', $fields, '`id` = :id', ['id' => $id]);
        }
    }

    if (!$dryRun) {
        if (function_exists('admin_after_write')) {
            admin_after_write();
        } elseif (function_exists('cache_bust')) {
            cache_bust();
        }
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_banners_run(in_array('--dry', $argv, true));

    foreach ($result['updated'] as $u) {
        printf('  #%d  %-34s -> %s%s', $u['id'], $u['from'], $u['to'], PHP_EOL);
    }
    foreach ($result['missing'] as $id) {
        echo '  MISSING banner ', $id, PHP_EOL;
    }
    foreach ($result['deadLinks'] as $d) {
        echo '  DEAD LINK: ', $d, PHP_EOL;
    }
    printf('%sBanners rewritten: %d   Dead links: %d%s',
        PHP_EOL, count($result['updated']), count($result['deadLinks']), PHP_EOL);
    exit(0);
}
