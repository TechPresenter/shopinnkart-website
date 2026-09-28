<?php
/**
 * ShopInnKart - Seed: "Shop by category" section copy (2026-09-28).
 *
 * The section used to show three tiles, all of them ranges inside one
 * department — Diyas & LED Candles, Lamps & Projectors, String & Curtain
 * Lights — and its subtitle described exactly that: "String lights, diyas and
 * lamps for every corner of the home". The renderer now shows every
 * department the store has, so that line is a claim about the section that
 * the section stopped making: it names the lighting range and says nothing
 * about hair accessories, mobile accessories or the trending line, which the
 * shopper can now see sitting right underneath it.
 *
 * The copy lives in an admin-owned row (Admin > Content > Homepage), so it is
 * changed here where an operator can read it, re-run it and edit it back,
 * rather than being hard-coded in the renderer.
 *
 * Nothing else about the section is touched. Its layout, its column counts and
 * its "featured only" setting are all still whatever the builder says: the
 * renderer reads them, and a seed that overwrote them would take a decision
 * away from the person who owns that screen.
 *
 * Idempotent: it only writes when the stored line is still the old one, so an
 * operator who has since written their own subtitle keeps it.
 *
 * Run from the project root:
 *     php database/seeds/2026_09_28_shop-by-category-copy.php
 */

declare(strict_types=1);

// Command line only, like every other seed: nothing on the web should be able
// to put storefront copy back to a seeded state.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/init.php';

$old = 'String lights, diyas and lamps for every corner of the home';
$new = 'Every department in the shop, and the ranges inside them';

$row = Database::fetch(
    "SELECT `id`, `subtitle` FROM `homepage_sections` WHERE `section_key` = 'home_shop_by_category'"
);

if ($row === null) {
    echo "  No home_shop_by_category row — nothing to do.\n";
    exit;
}

$current = (string) ($row['subtitle'] ?? '');

if ($current === $new) {
    echo "  Nothing to do — already applied.\n";
} elseif ($current !== $old && $current !== '') {
    echo "  Left alone — the subtitle has been edited since:\n    \"$current\"\n";
} else {
    Database::update('homepage_sections', ['subtitle' => $new], '`id` = :id', ['id' => (int) $row['id']]);
    cache_bust();
    echo "  homepage  shop by category subtitle -> \"$new\"\n";
}

echo "\nShop by category copy seed complete.\n";
