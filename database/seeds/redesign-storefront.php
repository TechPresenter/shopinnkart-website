<?php
/**
 * ShopInnKart - Seed: storefront redesign content (2026-09-14).
 *
 * The redesign is mostly CSS and templates, but a few of its decisions live in
 * admin-owned rows, so they are applied here where they can be read, re-run
 * and reversed from the admin rather than hidden in a template:
 *
 *   1. Header appearance   classic layout, desktop search field, no compare icon
 *   2. Navigation          shopping destinations in the bar; support pages move
 *                          to the drawer and the footer
 *   3. Homepage            nine sections with one job each; duplicates off
 *   4. Promotional noise   the two on-load popups, the announcements and bank
 *                          offers that promised things checkout cannot do, and
 *                          FAQ answers written for the old electronics catalogue
 *
 * Everything is DEACTIVATED, never deleted — each row can be switched back on
 * from its admin screen. Idempotent: safe to run again.
 *
 * Run from the project root:
 *     php database/seeds/redesign-storefront.php
 */

declare(strict_types=1);

// Command line only, like every other seed and migration: this rewrites
// homepage sections, menus and popup rows, and nothing on the web should be
// able to set the storefront back to a seeded state.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/init.php';

$log = [];
$say = static function (string $line) use (&$log): void { $log[] = $line; };

// ===========================================================================
//  1. Header appearance (Admin > Appearance > Header)
// ===========================================================================
$headerSettings = [
    'menu_style'          => 'classic',  // was "mega": nav on a tinted pill bar
    'header_search_from'  => '1024',     // was "never": no search field at any width
    'header_search_width' => '380',
    'header_height'       => '72',
    'header_show_compare' => '0',        // compare stays on cards and in the drawer
];
foreach ($headerSettings as $key => $value) {
    if ((string) setting($key, '') !== $value) {
        setting_save($key, $value, 'header', 'text');
        $say("header    $key = $value");
    }
}

// ===========================================================================
//  2. Navigation
// ===========================================================================
$menuId = static fn (string $location): int =>
    (int) Database::fetchColumn('SELECT `id` FROM `menus` WHERE `location` = :l', ['l' => $location]);

$deactivateTopLevel = static function (int $menu, array $urls) use ($say): void {
    foreach ($urls as $url) {
        $n = Database::query(
            "UPDATE `menu_items` SET `status` = 'inactive'
             WHERE `menu_id` = :m AND `parent_id` IS NULL AND `url` = :u AND `status` = 'active'",
            ['m' => $menu, 'u' => $url]
        )->rowCount();
        if ($n > 0) {
            $say("menu {$menu}    off: $url");
        }
    }
};

$ensureTopLevel = static function (int $menu, string $label, string $url, int $sort) use ($say): void {
    $row = Database::fetch(
        'SELECT `id`, `status` FROM `menu_items` WHERE `menu_id` = :m AND `parent_id` IS NULL AND `url` = :u',
        ['m' => $menu, 'u' => $url]
    );
    if ($row === null) {
        Database::insert('menu_items', [
            'menu_id' => $menu, 'label' => $label, 'url' => $url,
            'link_type' => 'custom', 'sort_order' => $sort, 'status' => 'active',
        ]);
        $say("menu {$menu}    +  $label");
    } elseif ($row['status'] !== 'active') {
        Database::update('menu_items', ['status' => 'active'], '`id` = :id', ['id' => (int) $row['id']]);
        $say("menu {$menu}    on: $label");
    }
};

if (($main = $menuId('main')) > 0) {
    // Home is the wordmark; About, Blog, FAQs and Contact live in the footer.
    $deactivateTopLevel($main, ['index.php', 'about.php', 'blog.php', 'faq.php', 'contact.php']);
    $ensureTopLevel($main, 'New arrivals', 'new-arrivals.php', 30);
    $ensureTopLevel($main, 'Best sellers', 'best-sellers.php', 40);
}
if (($mobile = $menuId('mobile')) > 0) {
    // Home is in the bottom bar; Contact is already in the drawer's support group.
    $deactivateTopLevel($mobile, ['index.php', 'contact.php']);
    $ensureTopLevel($mobile, 'New arrivals', 'new-arrivals.php', 22);
    $ensureTopLevel($mobile, 'Best sellers', 'best-sellers.php', 24);
}
$renamed = Database::query("UPDATE `menu_items` SET `label` = 'All products' WHERE `label` = 'All Products'")->rowCount();
if ($renamed > 0) {
    $say("menu      'All Products' -> 'All products' ($renamed)");
}

// ===========================================================================
//  3. Homepage (Admin > Content > Homepage Builder)
// ===========================================================================
$sections = [
    'home_hero_slider'         => ['sort_order' => 10, 'status' => 'active'],
    'home_shop_by_category'    => ['sort_order' => 20, 'title' => 'Shop by category', 'title_accent' => null,
                                   'subtitle' => 'String lights, diyas and lamps for every corner of the home',
                                   'link_text' => 'View all', 'layout' => 'grid', 'item_limit' => 8,
                                   'cols_desktop' => 4, 'cols_tablet' => 3, 'cols_mobile' => 2],
    // No product carries the featured flag, so "Featured" rendered nothing, and
    // with eleven products any second grid repeats the first. One grid of real
    // best sellers (sold_count when nothing is flagged) does the job; Featured
    // is left ready to switch on once products are flagged in the catalogue.
    'home_featured_picks'      => ['sort_order' => 30, 'status' => 'inactive', 'title' => 'Featured',
                                   'title_accent' => null, 'subtitle' => 'Handpicked pieces for the season',
                                   'link_text' => 'View all', 'link_url' => 'shop.php?featured=1',
                                   'data_source' => 'featured', 'item_limit' => 4, 'lazy_load' => 0],
    'home_best_sellers'        => ['sort_order' => 40, 'title' => 'Best sellers', 'title_accent' => null,
                                   'subtitle' => 'What shoppers are bringing home', 'link_text' => 'View all',
                                   'item_limit' => 8],
    // Deal and flash sale render nothing unless one is live, so they stay on.
    'home_deal_of_the_day'     => ['sort_order' => 50, 'title' => 'Deal of the day', 'title_accent' => null,
                                   'link_text' => 'All deals'],
    'home_flash_sale'          => ['sort_order' => 60, 'title' => 'Flash sale', 'title_accent' => null,
                                   'link_text' => 'See all'],
    'home_promo_banner_band'   => ['sort_order' => 70, 'title' => null, 'title_accent' => null,
                                   'subtitle' => null, 'link_text' => null],
    'home_recently_viewed'     => ['sort_order' => 80, 'title' => 'Recently viewed', 'title_accent' => null,
                                   'link_text' => 'Clear'],
    'home_newsletter'          => ['sort_order' => 110, 'title' => 'Stay in the loop', 'title_accent' => null,
                                   'subtitle' => 'New collections and offers, a couple of emails a month. Unsubscribe any time.'],

    // Off: each repeats something already on the page.
    'home_announcement_ticker' => ['status' => 'inactive'],   // second copy of the announcement bar
    'home_trust_strip'         => ['status' => 'inactive'],   // static delivery / COD / returns claims
    'home_new_arrivals'        => ['status' => 'inactive', 'title' => 'New arrivals', 'title_accent' => null],
    'home_trending_now'        => ['status' => 'inactive', 'title' => 'Trending now', 'title_accent' => null],
    'home_top_brands'          => ['status' => 'inactive', 'title' => 'Brands', 'title_accent' => null],
    'home_why_shop_with_us'    => ['status' => 'inactive'],   // unverifiable statistics
    'home_customer_reviews'    => ['status' => 'inactive'],   // testimonials; replaced by real product reviews
];
foreach ($sections as $key => $columns) {
    $row = Database::fetch('SELECT * FROM `homepage_sections` WHERE `section_key` = :k', ['k' => $key]);
    if ($row === null) {
        continue;
    }
    $changes = [];
    foreach ($columns as $column => $value) {
        if ((string) ($row[$column] ?? '') !== (string) ($value ?? '') || ($value === null && $row[$column] !== null)) {
            $changes[$column] = $value;
        }
    }
    if ($changes !== []) {
        Database::update('homepage_sections', $changes, '`id` = :id', ['id' => (int) $row['id']]);
        $say("home      $key: " . implode(', ', array_keys($changes)));
    }
}

// Colours saved against the old orange-and-navy theme. Printed inline they
// override the new palette — the newsletter band was #F4511E, which is not
// even the store's current brand colour — and a light band such as the deal's
// #FFF6F2 would put light dark-mode text on a light background. Cleared so the
// sections follow the theme; an operator can still set one (light mode only).
$tinted = Database::query(
    "UPDATE `homepage_sections` SET `bg_color` = NULL, `text_color` = NULL
     WHERE `zone` = 'home' AND (`bg_color` IS NOT NULL OR `text_color` IS NOT NULL)"
)->rowCount();
if ($tinted > 0) {
    $say("home      legacy section colours cleared ($tinted)");
}
$tintedBanners = Database::query(
    "UPDATE `banners` SET `bg_color` = NULL, `text_color` = NULL
     WHERE `bg_color` = '#0F2143' OR `text_color` = '#FFFFFF'"
)->rowCount();
if ($tintedBanners > 0) {
    $say("banners   legacy navy / white colours cleared ($tintedBanners)");
}

$newSections = [
    [
        'section_key' => 'home_product_reviews', 'zone' => 'home', 'widget_type' => 'reviews',
        'title' => 'What customers say', 'subtitle' => 'Verified reviews from recent orders',
        'data_source' => 'auto', 'item_limit' => 6, 'layout' => 'grid', 'card_style' => 'standard',
        'cols_desktop' => 3, 'cols_tablet' => 2, 'cols_mobile' => 1, 'padding' => 'md',
        'sort_order' => 90, 'status' => 'active',
    ],
    [
        'section_key' => 'home_faq', 'zone' => 'home', 'widget_type' => 'faq',
        'title' => 'Frequently asked questions', 'subtitle' => 'Delivery, returns and payments, answered briefly',
        'link_text' => 'All FAQs', 'link_url' => 'faq.php',
        'data_source' => 'auto', 'item_limit' => 6, 'layout' => 'list', 'card_style' => 'standard',
        'cols_desktop' => 1, 'cols_tablet' => 1, 'cols_mobile' => 1, 'padding' => 'md',
        'sort_order' => 100, 'status' => 'active',
    ],
];
foreach ($newSections as $section) {
    if (!Database::exists('homepage_sections', '`section_key` = :k', ['k' => $section['section_key']])) {
        Database::insert('homepage_sections', $section);
        $say('home      + ' . $section['section_key']);
    }
}

// ===========================================================================
//  3b. Footer (Admin > Content > Footer Builder)
//
//  Six columns said a lot of things twice: an "About" column repeating the
//  brand blurb beside it, and Shipping / Returns / Refunds / Privacy / Terms
//  listed in two columns AND in the legal bar underneath. The legal bar keeps
//  the policies; the columns keep navigation.
// ===========================================================================
$columnId = static fn (string $title): int =>
    (int) Database::fetchColumn('SELECT `id` FROM `footer_columns` WHERE `title` = :t', ['t' => $title]);

foreach (['About ShopInnKart'] as $title) {
    $n = Database::query("UPDATE `footer_columns` SET `status` = 'inactive' WHERE `title` = :t AND `status` = 'active'", ['t' => $title])->rowCount();
    if ($n > 0) {
        $say("footer    column off: $title");
    }
}
foreach (['Quick Links' => 'Shop', 'Customer Service' => 'Help', 'Contact Us' => 'Contact'] as $from => $to) {
    $n = Database::query('UPDATE `footer_columns` SET `title` = :to WHERE `title` = :from', ['to' => $to, 'from' => $from])->rowCount();
    if ($n > 0) {
        $say("footer    column '$from' -> '$to'");
    }
}

$footerOff = ['index.php', 'deals.php', 'page.php?slug=shipping-policy', 'page.php?slug=return-policy',
              'page.php?slug=refund-policy', 'page.php?slug=warranty-policy',
              'page.php?slug=privacy-policy', 'page.php?slug=terms-conditions'];
foreach ($footerOff as $url) {
    $n = Database::query("UPDATE `footer_links` SET `status` = 'inactive' WHERE `url` = :u AND `status` = 'active'", ['u' => $url])->rowCount();
    if ($n > 0) {
        $say("footer    link off: $url");
    }
}

// Track order is help, not shopping.
if (($help = $columnId('Help')) > 0) {
    $n = Database::query("UPDATE `footer_links` SET `column_id` = :c, `sort_order` = 2 WHERE `url` = 'track-order.php' AND `column_id` <> :c2",
        ['c' => $help, 'c2' => $help])->rowCount();
    if ($n > 0) {
        $say('footer    Track order -> Help');
    }
}

$footerLabels = ['Shop' => 'Shop all', 'Best Sellers' => 'Best sellers', 'New Arrivals' => 'New arrivals',
                 'Track Order' => 'Track order', 'Contact Us' => 'Contact us', 'About Us' => 'About us'];
foreach ($footerLabels as $from => $to) {
    $n = Database::query('UPDATE `footer_links` SET `label` = :to WHERE BINARY `label` = :from', ['to' => $to, 'from' => $from])->rowCount();
    if ($n > 0) {
        $say("footer    '$from' -> '$to'");
    }
}

// ===========================================================================
//  4. Promotional noise
// ===========================================================================
$off = static function (string $table, string $where, array $params, string $what) use ($say): void {
    $n = Database::query("UPDATE `$table` SET `status` = 'inactive' WHERE `status` = 'active' AND ($where)", $params)->rowCount();
    if ($n > 0) {
        $say(str_pad($table, 10) . "off: $what ($n)");
    }
};

// Both fired on page load, before a visitor had seen a single product; the
// cart reminder also appeared on an empty cart. The exit-intent one stays.
$off('popups', '`name` IN (:a, :b)', ['a' => 'Welcome Newsletter Coupon', 'b' => 'Free Shipping Reminder'], 'on-load popups');

// "Extra 10% off on prepaid orders": no online gateway is active.
// "24/7 support": business_hours is Mon-Sat, 9 to 8.
$off('announcements', '`text` LIKE :a OR `text` LIKE :b', ['a' => 'Extra 10% OFF on prepaid%', 'b' => '24/7 customer support%'], 'announcements checkout cannot honour');

// "FREE SHIPPING on every prepaid order above ₹999": the threshold is real,
// "prepaid" is not — Cash on Delivery is the only way to pay. Reworded from the
// setting itself so the bar says exactly what checkout does.
$threshold = setting_float('free_shipping_threshold', 0);
if ($threshold > 0 && setting_bool('free_shipping_enabled', true)) {
    $n = Database::query(
        "UPDATE `announcements` SET `text` = :t, `subtext` = NULL WHERE `text` LIKE 'FREE SHIPPING on every prepaid%'",
        ['t' => 'Free delivery on orders over ' . money($threshold)]
    )->rowCount();
    if ($n > 0) {
        $say('announcements  free-delivery line reworded from free_shipping_threshold');
    }
}

// Card, UPI, wallet and EMI offers with no gateway to apply them, and no admin
// screen to manage them. Offers now come from coupons marked for display.
$off('bank_offers', '1', [], 'bank / card / EMI offers');

// Electronics-era answers: "genuine and covered by India warranty", "no-cost
// EMI", and "safe to pay online" on a store with no online payment.
$off('faqs', '`question` LIKE :a OR `question` LIKE :b OR `question` LIKE :c',
    ['a' => 'Are the products on ShopInnKart genuine%', 'b' => 'What does no-cost EMI%', 'c' => 'Is it safe to pay online%'],
    'FAQs about warranty, EMI and online payment');

settings_cache_generation(true);
cache_bust();

echo $log === [] ? "  Nothing to do — already applied.\n" : '  ' . implode("\n  ", $log) . "\n";
echo "\nRedesign content seed complete.\n";
