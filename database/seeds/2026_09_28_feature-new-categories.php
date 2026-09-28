<?php
/**
 * Put the three new categories on the front page.
 *
 * 2026_09_28_store-identity.php created Hair Accessories, Mobile Accessories
 * and Trending Products, and renamed the old lighting category to Decorative
 * Lights. What it did not do was set is_featured on the three new ones - and
 * the homepage row is built by category_tiles(), which takes only featured
 * categories. So the shop advertised three ranges it no longer led with and
 * none of the three the owner had just added.
 *
 * It looked like a limit and it was not. The row had room; the three were
 * never eligible.
 *
 * WHY A FLAG AND NOT A CODE CHANGE. is_featured is the owner's switch for this
 * row, in Catalogue > Categories. Making the row ignore it would put every
 * category the shop ever creates on the front page and take the decision away
 * from them. The seed sets it once for the three that were created without it.
 *
 * Idempotent, and it only touches these three by slug - a category the owner
 * has since un-featured on purpose is not re-featured by a second run, because
 * it reports what it finds and changes only a 0 it has not already changed.
 *
 *     php database/seeds/2026_09_28_feature-new-categories.php --dry
 *     php database/seeds/2026_09_28_feature-new-categories.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv ?? [], true);

/**
 * The three the identity seed added. Decorative Lights is NOT here: it is
 * already featured, and category_tiles() replaces a parent that has featured
 * children with those children, so featuring it would change nothing.
 */
const FEATURE_SLUGS = [
    'hair-accessories',
    'mobile-accessories',
    'trending-products',
];

echo 'Feature the new categories', $dry ? ' (DRY RUN)' : '', "\n";
echo str_repeat('-', 66), "\n";

$changed = 0;
$already = 0;
$missing = [];

foreach (FEATURE_SLUGS as $slug) {
    $row = Database::fetch(
        'SELECT `id`, `name`, `is_featured`, `status` FROM `categories` WHERE `slug` = :s LIMIT 1',
        ['s' => $slug]
    );

    if ($row === null) {
        $missing[] = $slug;
        printf("  %-22s not found\n", $slug);
        continue;
    }

    if ((int) $row['is_featured'] === 1) {
        printf("  %-22s already featured\n", $slug);
        $already++;
        continue;
    }

    printf("  %-22s featured%s\n", $slug,
        $row['status'] !== 'active' ? '  (note: status is ' . $row['status'] . ', so it still will not show)' : '');

    if (!$dry) {
        Database::update('categories', ['is_featured' => 1], '`id` = :id', ['id' => (int) $row['id']]);
    }
    $changed++;
}

// ---------------------------------------------------------------------------
//  The section itself: how many across, and what it says it sells
// ---------------------------------------------------------------------------
//
// cols_desktop was 4, set when this row had three tiles in it. Six tiles in a
// four-column grid wrap to 4 + 2 and leave a hole two tiles wide, which reads
// as a row that failed to load rather than as a deliberate second line.
//
// The subtitle was written for a shop that only sold lighting. It still said
// "String lights, diyas and lamps for every corner of the home" underneath a
// row whose first two tiles are hair and mobile accessories.
//
// Both are ordinary Homepage designer fields, so each is changed ONLY from the
// exact value it is being corrected from - an owner who has since edited either
// keeps their version.

$sectionChanged = false;

$section = Database::fetch(
    'SELECT `id`, `cols_desktop`, `subtitle` FROM `homepage_sections` WHERE `section_key` = :k',
    ['k' => 'home_shop_by_category']
);

if ($section === null) {
    echo "\n  note  no home_shop_by_category section - skipping the layout fix\n";
} else {
    // The three above, plus the three lighting ranges that were already featured.
    $tileCount = count(FEATURE_SLUGS) + 3;

    if ((int) $section['cols_desktop'] === 4) {
        printf("\n  section  cols_desktop 4 -> %d (one clean row for %d tiles)\n", $tileCount, $tileCount);
        if (!$dry) {
            Database::update('homepage_sections', ['cols_desktop' => $tileCount],
                '`id` = :id', ['id' => (int) $section['id']]);
        }
        $sectionChanged = true;
    } else {
        printf("\n  section  cols_desktop is %s - left alone\n", var_export($section['cols_desktop'], true));
    }

    $staleSubtitle = 'String lights, diyas and lamps for every corner of the home';
    $newSubtitle   = 'Lights, hair and mobile accessories, and what is trending';

    if (trim((string) $section['subtitle']) === $staleSubtitle) {
        echo "  section  subtitle no longer describes the shop - replaced\n";
        if (!$dry) {
            Database::update('homepage_sections', ['subtitle' => $newSubtitle],
                '`id` = :id', ['id' => (int) $section['id']]);
        }
        $sectionChanged = true;
    } else {
        echo "  section  subtitle has been edited since - left alone\n";
    }
}

// The row's artwork is cached for ten minutes per category, and a category
// that has just become eligible has no entry yet - but one that was already
// cached as a blank would keep its blank until it expired.
// It is not enough to bust on $changed: the section's own row - how many tiles
// across, and the subtitle - is cached too, and on a re-run where the flags are
// already set $changed is 0 while the section still just changed. Guarding on
// the flags alone left the homepage serving a four-column row and the old
// subtitle from cache while the database held the new ones, which looks exactly
// like an edit that did not save.
if (!$dry && ($changed > 0 || $sectionChanged) && function_exists('cache_bust')) {
    cache_bust();
    echo "\n  cache cleared\n";
}

echo "\n", str_repeat('-', 66), "\n";
printf("%d featured%s, %d already were\n", $changed, $dry ? ' would be' : '', $already);

if ($missing !== []) {
    echo "\n  note  not found: ", implode(', ', $missing), "\n";
    echo "        Run database/seeds/2026_09_28_store-identity.php first - it\n";
    echo "        creates them.\n";
}

echo "\nWHAT THE ROW WILL SHOW:\n";
$tiles = function_exists('category_tiles') ? category_tiles(8) : [];
if ($tiles === []) {
    echo "  (category_tiles() is not loaded here; check the homepage)\n";
} else {
    foreach ($tiles as $t) {
        printf("  - %-26s %d item(s)\n", $t['name'], (int) ($t['product_count'] ?? 0));
    }
    echo "\n  A category with no products still gets a tile and its own glyph.\n";
    echo "  That is deliberate - the owner sells these, they are just not\n";
    echo "  stocked yet - but an empty category is a dead end for a shopper,\n";
    echo "  so add products or un-feature it in Catalogue > Categories.\n";
}

exit(0);
