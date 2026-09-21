<?php
/**
 * ShopInnKart - Live search suggestions.
 *
 * Feeds the search dropdown: what to show while the field is empty (trending
 * terms, browsable categories, popular products) and what to show while it is
 * being typed into (matching products, categories and brands, plus near-miss
 * terms when nothing matches).
 *
 * Response shape is fixed - the storefront panel and both admin product
 * pickers read `data.products[]`, so keys may be added here but never renamed
 * or removed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/search-functions.php';

api_require_method(['GET']);

// ---------------------------------------------------------------------------
//  Idle panel: nothing typed yet.
//  Everything here is admin-owned data (the log, the category tree), fetched
//  once per page and reused, which is why the dropdown opens instantly.
// ---------------------------------------------------------------------------
if (input_bool('popular')) {
    json_success('OK', [
        'popular'    => search_popular_terms(8),
        'categories' => search_top_categories(8),
        'products'   => search_popular_products(4),
    ]);
}

// ---------------------------------------------------------------------------
//  Suggestions for a term
// ---------------------------------------------------------------------------
$query = trim((string) input('q', ''));
$limit = max(1, min(20, input_int('limit', 6)));

if (mb_strlen($query) < 2) {
    json_success('OK', [
        'query'            => $query,
        'products'         => [],
        'categories'       => [],
        'brands'           => [],
        'total'            => 0,
        'suggestions'      => [],
        'popular_products' => [],
    ]);
}

$found = query_products(['q' => $query, 'per_page' => $limit, 'sort' => 'popularity']);
$total = (int) $found['pagination']['total'];

$products = array_map('search_product_row', $found['items']);

$like = ['like' => '%' . $query . '%'];

$categories = array_map(static fn (array $row): array => [
    'id'            => (int) $row['id'],
    'name'          => (string) $row['name'],
    'slug'          => (string) $row['slug'],
    'url'           => category_url((string) $row['slug']),
    'image_url'     => img_url($row['image']),
    'product_count' => (int) $row['product_count'],
], Database::fetchAll(
    "SELECT c.`id`, c.`name`, c.`slug`, c.`image`,
            (SELECT COUNT(*) FROM `products` p
             WHERE p.`category_id` = c.`id` AND " . product_visible_sql() . ") AS product_count
     FROM `categories` c
     WHERE c.`status` = 'active' AND c.`name` LIKE :like
     ORDER BY c.`sort_order`, c.`name`
     LIMIT 4",
    $like
));

$brands = array_map(static fn (array $row): array => [
    'id'       => (int) $row['id'],
    'name'     => (string) $row['name'],
    'slug'     => (string) $row['slug'],
    'url'      => brand_url((string) $row['slug']),
    // img_url() always returns something, so the panel needs to be told
    // separately whether that something is a real logo or the placeholder.
    'has_logo' => trim((string) ($row['logo'] ?? '')) !== '',
    'logo_url' => img_url($row['logo']),
], Database::fetchAll(
    "SELECT `id`, `name`, `slug`, `logo` FROM `brands`
     WHERE `status` = 'active' AND `name` LIKE :like
     ORDER BY `sort_order`, `name`
     LIMIT 6",
    $like
));

search_log_query($query, $total);

$nothingMatched = $products === [] && $categories === [] && $brands === [];

json_success('OK', [
    'query'            => $query,
    'products'         => $products,
    'categories'       => $categories,
    'brands'           => $brands,
    'total'            => $total,
    'suggestions'      => $nothingMatched ? search_suggestions($query, 5) : [],
    // A dead end is where shoppers leave. Even a failed query gets something
    // to click, so the panel always has a way forward.
    'popular_products' => $nothingMatched ? search_popular_products(4) : [],
]);
