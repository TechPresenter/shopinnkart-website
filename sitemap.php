<?php
/**
 * ShopInnKart - XML sitemap (served as /sitemap.xml).
 *
 * Only live rows are listed: active products, categories, brands and CMS
 * pages, plus published blog posts. Routes whose PHP file is not present are
 * skipped so the sitemap never advertises a 404.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

/** @var array<string,array{loc:string,lastmod:?string,changefreq:string,priority:string}> */
$entries = [];

$add = static function (string $loc, ?string $lastmod, string $changefreq, string $priority) use (&$entries): void {
    // Keyed by URL so a slug that owns both a fixed route and a CMS row is
    // only listed once.
    if (isset($entries[$loc])) {
        return;
    }
    $timestamp = $lastmod === null ? false : strtotime($lastmod);
    $entries[$loc] = [
        'loc'        => $loc,
        'lastmod'    => $timestamp === false ? null : date('c', $timestamp),
        'changefreq' => $changefreq,
        'priority'   => $priority,
    ];
};

$routeExists = static fn (string $script): bool => is_file(ROOT_PATH . '/' . $script);

// --- Homepage --------------------------------------------------------------
$newestProduct = Database::fetchColumn('SELECT MAX(`updated_at`) FROM `products`');
$add(url(), is_string($newestProduct) ? $newestProduct : null, 'daily', '1.0');

// --- Static catalogue and support routes -----------------------------------
$staticRoutes = [
    'shop'          => ['shop.php', 'daily', '0.9'],
    'deals'         => ['deals.php', 'daily', '0.9'],
    'new-arrivals'  => ['new-arrivals.php', 'daily', '0.8'],
    'best-sellers'  => ['best-sellers.php', 'daily', '0.8'],
    'brands'        => ['brands.php', 'weekly', '0.7'],
    'blog'          => ['blog.php', 'daily', '0.7'],
    'contact'       => ['contact.php', 'monthly', '0.6'],
    'about'         => ['about.php', 'monthly', '0.5'],
    'faq'           => ['faq.php', 'monthly', '0.6'],
    'track-order'   => ['track-order.php', 'monthly', '0.5'],
];
foreach ($staticRoutes as $path => [$script, $changefreq, $priority]) {
    if ($routeExists($script)) {
        $add(url($path), null, $changefreq, $priority);
    }
}

// --- Categories ------------------------------------------------------------
if ($routeExists('category.php')) {
    foreach (Database::fetchAll(
        "SELECT `slug`, `updated_at` FROM `categories` WHERE `status` = 'active' ORDER BY `sort_order`, `name`"
    ) as $row) {
        $add(category_url((string) $row['slug']), (string) $row['updated_at'], 'weekly', '0.8');
    }
}

// --- Brands ----------------------------------------------------------------
if ($routeExists('brand.php')) {
    foreach (Database::fetchAll(
        "SELECT `slug`, `updated_at` FROM `brands` WHERE `status` = 'active' ORDER BY `sort_order`, `name`"
    ) as $row) {
        $add(brand_url((string) $row['slug']), (string) $row['updated_at'], 'weekly', '0.6');
    }
}

// --- Products --------------------------------------------------------------
if ($routeExists('product.php')) {
    foreach (Database::fetchAll(
        'SELECT p.`slug`, p.`updated_at` FROM `products` p
          WHERE ' . product_visible_sql('p') . '
          ORDER BY p.`updated_at` DESC'
    ) as $row) {
        $add(product_url((string) $row['slug']), (string) $row['updated_at'], 'weekly', '0.8');
    }
}

// --- CMS pages -------------------------------------------------------------
// Both sections below are switchable from Admin > Settings > SEO. A store that
// keeps its policies and blog out of search should not have to edit this file,
// and excluding them here is the honest counterpart to a noindex directive:
// nothing is submitted that the operator does not want indexed.
if (setting_bool('sitemap_include_pages', true)):
foreach (Database::fetchAll(
    "SELECT `slug`, `updated_at` FROM `pages` WHERE `status` = 'active' ORDER BY `sort_order`, `title`"
) as $row) {
    $slug = (string) $row['slug'];
    $fixed = cms_fixed_routes()[$slug] ?? null;

    // A slug with a fixed route is only listed if that route's file exists;
    // otherwise it is still reachable through /page/<slug>.
    if ($fixed !== null && !$routeExists($fixed . '.php')) {
        $fixed = null;
    }
    $loc = $fixed !== null ? url($fixed) : page_url($slug);

    $add($loc, (string) $row['updated_at'], 'monthly', $fixed !== null ? '0.6' : '0.4');
}
endif;

// --- Blog posts ------------------------------------------------------------
if (setting_bool('sitemap_include_blog', true) && $routeExists('blog-post.php')) {
    foreach (Database::fetchAll(
        'SELECT p.`slug`, p.`updated_at` FROM `blog_posts` p
          WHERE ' . blog_visible_sql('p') . '
          ORDER BY p.`published_at` DESC'
    ) as $row) {
        $add(blog_url((string) $row['slug']), (string) $row['updated_at'], 'monthly', '0.6');
    }
}

// ---------------------------------------------------------------------------
//  Output
// ---------------------------------------------------------------------------
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Content-Type: application/xml; charset=utf-8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($entries as $entry) {
    echo '  <url>' . "\n";
    echo '    <loc>' . e($entry['loc']) . '</loc>' . "\n";
    if ($entry['lastmod'] !== null) {
        echo '    <lastmod>' . e($entry['lastmod']) . '</lastmod>' . "\n";
    }
    echo '    <changefreq>' . e($entry['changefreq']) . '</changefreq>' . "\n";
    echo '    <priority>' . e($entry['priority']) . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>' . "\n";
