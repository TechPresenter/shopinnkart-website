<?php
/**
 * ShopInnKart - Clear the last electronics-era rows out of a live store.
 *
 * The festive seeds before this one rebuilt the catalogue, taxonomy, banners,
 * blog, SEO and offers. A sweep of every text column afterwards still found
 * phone-and-laptop data in the corners nobody had opened:
 *
 *   - order lines pointing at device-*.svg art (laptop, TV, earbuds), which
 *     blocks retiring those files;
 *   - the three expired deals and two flash sales, still titled "Samsung
 *     55-inch Crystal 4K Smart TV", "Weekend Laptop Fest" and "Midnight Tech";
 *   - an inactive stock pop-in linking to /product/lg-55-oled-evo-c4;
 *   - twelve unused tags (5G, 4K, Gaming, Noise Cancelling...);
 *   - the Colour attribute's values, still phone finishes (Midnight Black,
 *     Titanium Grey, Rose Gold) on a store that sells warm and multicolour light;
 *   - the brand rail subtitle promising "official brand stores, genuine warranty".
 *
 * Deliberately NOT touched: orders, invoices, contact messages and the six
 * deactivated testimonials. Those are records of what happened (or seed demo
 * data the owner may prefer to delete outright), and rewriting their wording
 * would falsify history. Order lines only lose their picture, never their name.
 *
 * Idempotent: every change matches on the old value, so a second run finds
 * nothing to do. Tags and colour values are only renamed while no product or
 * variant uses them.
 *
 *     php database/seeds/festive-leftovers.php          apply
 *     php database/seeds/festive-leftovers.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/** Neutral art for an order line whose product no longer exists. */
const FESTIVE_LEFTOVER_NO_IMAGE = 'assets/images/placeholders/no-image.svg';

/**
 * Deal copy keyed by the electronics title it replaces. Prices, percentages,
 * dates and stock counters stay as they are: these deals have already ended,
 * so only the wording an admin reads changes.
 */
function festive_leftover_deal_copy(): array
{
    return [
        'Deal of the Day: Samsung 55-inch Crystal 4K Smart TV' => [
            'title'       => 'Deal of the Day: The Purple Tree Diya Curtain Light',
            'description' => 'Twelve hanging diyas on a 2.5 m curtain with eight lighting modes, at its lowest price of the season. Limited to 40 units.',
            'button_sku'  => 'SIK-FLT-1005',
        ],
        'Weekend Laptop Fest' => [
            'title'       => 'Weekend Curtain Light Fest',
            'subtitle'    => 'Star, leaf and fairy curtain lights for the weekend',
            'description' => 'Four hand-picked curtain lights at their lowest price of the season. Doors open this weekend.',
            'button_url'  => 'shop.php?category=string-curtain-lights',
        ],
        'Audio Days Clearance' => [
            'title'       => 'Lamps & Projectors Clearance',
            'subtitle'    => 'Table lamps and projector lamps - sale ended',
            'description' => 'A four day clearance on crystal table lamps and galaxy projector lamps. Stock sold out at 118 of 120 units.',
            'button_url'  => 'shop.php?category=lamps-projectors',
        ],
    ];
}

function festive_leftover_flash_sale_copy(): array
{
    return [
        'Midnight Tech Flash Sale' => ['name' => 'Midnight Festive Flash Sale'],
        'Weekend Wearables Flash'  => ['name' => 'Weekend Diya Flash', 'subtitle' => 'Diyas and tea lights - sale closed'],
    ];
}

/** Old tag slug => [new name, new slug]. Budget Pick and Premium already fit. */
function festive_leftover_tag_names(): array
{
    return [
        '5g'               => ['Diwali', 'diwali'],
        'noise-cancelling' => ['Christmas', 'christmas'],
        'gaming'           => ['Wedding Decor', 'wedding-decor'],
        '4k'               => ['Warm White', 'warm-white'],
        'fast-charging'    => ['Multicolour', 'multicolour'],
        'water-resistant'  => ['Remote Control', 'remote-control'],
        'wireless'         => ['USB Powered', 'usb-powered'],
        'creator'          => ['Kids Room', 'kids-room'],
        'work-from-home'   => ['Pooja Room', 'pooja-room'],
        'student'          => ['Gift Pick', 'gift-pick'],
    ];
}

/**
 * Old colour slug => [value, slug, swatch]. Multicolour and colour-changing
 * light have no single honest hex, so they get no swatch (the storefront
 * falls back to a neutral chip labelled with the name).
 */
function festive_leftover_colour_values(): array
{
    return [
        'midnight-black' => ['Warm White', 'warm-white', '#FFD9A0'],
        'titanium-grey'  => ['Cool White', 'cool-white', '#EEF4FF'],
        'silver'         => ['Golden Yellow', 'golden-yellow', '#F5B700'],
        'ocean-blue'     => ['Blue', 'blue', '#2F7DE1'],
        'forest-green'   => ['Green', 'green', '#22A55B'],
        'sunset-orange'  => ['Multicolour', 'multicolour', null],
        'pearl-white'    => ['Pink', 'pink', '#ED1857'],
        'rose-gold'      => ['RGB Colour Changing', 'rgb-colour-changing', null],
    ];
}

/** The product the dead stock pop-in should point at. */
const FESTIVE_LEFTOVER_POPIN_SKU = 'SIK-FLT-3001';

function festive_leftovers_run(bool $dryRun = false): array
{
    $report = [];
    $write = static function (callable $fn) use ($dryRun): int {
        return $dryRun ? 0 : (int) $fn();
    };
    $slugFor = static function (string $sku): ?string {
        $slug = Database::fetchColumn('SELECT `slug` FROM `products` WHERE `sku` = :s LIMIT 1', ['s' => $sku]);
        return $slug !== false && $slug !== null ? (string) $slug : null;
    };

    // ---- Order lines: drop the device art, keep the name ------------------
    $lines = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `order_items` WHERE `product_image` LIKE 'assets/images/placeholders/device-%'"
    );
    $write(static fn () => Database::query(
        "UPDATE `order_items` SET `product_image` = :img WHERE `product_image` LIKE 'assets/images/placeholders/device-%'",
        ['img' => FESTIVE_LEFTOVER_NO_IMAGE]
    )->rowCount());
    $report['order lines repointed to no-image.svg'] = $lines;

    // ---- Deals --------------------------------------------------------------
    $deals = 0;
    foreach (festive_leftover_deal_copy() as $oldTitle => $copy) {
        $id = (int) Database::fetchColumn('SELECT `id` FROM `deals` WHERE `title` = :t LIMIT 1', ['t' => $oldTitle]);
        if ($id === 0) {
            continue;
        }
        if (isset($copy['button_sku'])) {
            $slug = $slugFor($copy['button_sku']);
            $copy['button_url'] = $slug !== null ? 'product.php?slug=' . $slug : 'deals.php';
            unset($copy['button_sku']);
        }
        $write(static fn () => Database::update('deals', $copy, '`id` = :id', ['id' => $id]));
        $deals++;
    }
    $report['deals reworded'] = $deals;

    // ---- Flash sales --------------------------------------------------------
    $sales = 0;
    foreach (festive_leftover_flash_sale_copy() as $oldName => $copy) {
        $id = (int) Database::fetchColumn('SELECT `id` FROM `flash_sales` WHERE `name` = :n LIMIT 1', ['n' => $oldName]);
        if ($id === 0) {
            continue;
        }
        $write(static fn () => Database::update('flash_sales', $copy, '`id` = :id', ['id' => $id]));
        $sales++;
    }
    $report['flash sales renamed'] = $sales;

    // ---- The stock pop-in that linked to a television ---------------------
    $popins = 0;
    $slug = $slugFor(FESTIVE_LEFTOVER_POPIN_SKU);
    if ($slug !== null) {
        $popins = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `popups` WHERE `button_url` LIKE '%lg-55-oled-evo-c4%'"
        );
        $write(static fn () => Database::query(
            "UPDATE `popups` SET `button_url` = :u WHERE `button_url` LIKE '%lg-55-oled-evo-c4%'",
            ['u' => 'product.php?slug=' . $slug]
        )->rowCount());
    }
    $report['pop-ins relinked'] = $popins;

    // ---- Tags ---------------------------------------------------------------
    $tags = 0;
    $tagsKept = [];
    foreach (festive_leftover_tag_names() as $oldSlug => [$name, $newSlug]) {
        $tag = Database::fetch('SELECT `id`, `name` FROM `tags` WHERE `slug` = :s LIMIT 1', ['s' => $oldSlug]);
        if (!$tag) {
            continue;
        }
        // A tag somebody attached to a product is real data now, whatever it says.
        if (Database::exists('product_tags', '`tag_id` = :t', ['t' => (int) $tag['id']])) {
            $tagsKept[] = $tag['name'];
            continue;
        }
        if (Database::exists('tags', '`slug` = :s', ['s' => $newSlug])) {
            $tagsKept[] = $tag['name'] . ' (' . $newSlug . ' already exists)';
            continue;
        }
        $write(static fn () => Database::update('tags', ['name' => $name, 'slug' => $newSlug], '`id` = :id', ['id' => (int) $tag['id']]));
        $tags++;
    }
    $report['tags renamed'] = $tags;

    // ---- Colour values -------------------------------------------------------
    $colours = 0;
    $coloursKept = [];
    $colourId = (int) Database::fetchColumn("SELECT `id` FROM `attributes` WHERE `slug` = 'colour' LIMIT 1");
    if ($colourId > 0) {
        foreach (festive_leftover_colour_values() as $oldSlug => [$value, $newSlug, $swatch]) {
            $row = Database::fetch(
                'SELECT `id`, `value` FROM `attribute_values` WHERE `attribute_id` = :a AND `slug` = :s LIMIT 1',
                ['a' => $colourId, 's' => $oldSlug]
            );
            if (!$row) {
                continue;
            }
            if (Database::exists('product_variant_attributes', '`attribute_value_id` = :v', ['v' => (int) $row['id']])) {
                $coloursKept[] = $row['value'];
                continue;
            }
            $write(static fn () => Database::update(
                'attribute_values',
                ['value' => $value, 'slug' => $newSlug, 'color_code' => $swatch],
                '`id` = :id',
                ['id' => (int) $row['id']]
            ));
            $colours++;
        }
    }
    $report['colour values renamed'] = $colours;

    // ---- Brand rail subtitle --------------------------------------------------
    $rails = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `homepage_sections` WHERE `subtitle` = 'Official brand stores, genuine warranty'"
    );
    $write(static fn () => Database::query(
        "UPDATE `homepage_sections` SET `subtitle` = 'The lighting makers we stock' WHERE `subtitle` = 'Official brand stores, genuine warranty'"
    )->rowCount());
    $report['brand rail subtitles'] = $rails;

    if (!$dryRun) {
        if (function_exists('admin_after_write')) {
            admin_after_write();
        } elseif (function_exists('cache_bust')) {
            cache_bust();
        }
    }

    return ['counts' => $report, 'tags_kept' => $tagsKept, 'colours_kept' => $coloursKept];
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dry = in_array('--dry', $argv, true);
    $result = festive_leftovers_run($dry);

    if ($dry) {
        echo '(dry run - nothing written; counts are what would change)', PHP_EOL;
    }
    foreach ($result['counts'] as $label => $count) {
        printf('  %-40s %d%s', $label, $count, PHP_EOL);
    }
    foreach ($result['tags_kept'] as $name) {
        echo '  ! tag in use, left alone: ', $name, PHP_EOL;
    }
    foreach ($result['colours_kept'] as $name) {
        echo '  ! colour used by a variant, left alone: ', $name, PHP_EOL;
    }
    exit(0);
}
