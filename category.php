<?php
/**
 * ShopInnKart - Category listing.
 *
 * /category/<slug> via the rewrite in .htaccess, ?slug=<slug> without it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

$slug = trim((string) input('slug', ''));
$category = $slug === '' ? null : get_category_by_slug($slug);

if ($category === null) {
    require ROOT_PATH . '/404.php';
    exit;
}

$categoryId = (int) $category['id'];

$crumbs = [['label' => 'Home', 'url' => url()], ['label' => 'Shop', 'url' => url('shop.php')]];
foreach (category_ancestors($categoryId) as $ancestor) {
    $crumbs[] = [
        'label' => (string) $ancestor['name'],
        // The category itself is the current page, so it gets no link.
        'url'   => (int) $ancestor['id'] === $categoryId ? null : category_url((string) $ancestor['slug']),
    ];
}

// Direct children become quick links; deeper levels stay in the sidebar tree.
$children = [];
foreach (all_categories() as $row) {
    if ((int) ($row['parent_id'] ?? 0) === $categoryId) {
        $children[] = [
            'label' => (string) $row['name'],
            'url'   => category_url((string) $row['slug']),
            'count' => (int) $row['product_count'],
        ];
    }
}

$listing = product_listing_query([
    'base'        => ['category' => (string) $category['slug']],
    'title'       => (string) $category['name'],
    'subtitle'    => $children === []
        ? null
        : 'Browse ' . count($children) . ' sub ' . (count($children) === 1 ? 'category' : 'categories') . ' below.',
    'description' => (string) ($category['description'] ?? ''),
    'banner'      => $category['banner'] ?? null,
    'breadcrumbs' => $crumbs,
    'pills'       => $children,
    'pills_label' => 'Sub categories of ' . $category['name'],
    'clear_url'   => category_url((string) $category['slug']),
]);

// The listing decides the baseline robots value (a filtered or deep-paged view
// is deliberately not indexed); seo_from_entity() then lets the record's own
// robots field override it, which is the only way an admin can force-index or
// force-exclude one specific category.
seo_set(array_filter([
    'canonical' => category_url((string) $category['slug']),
    'og_type'   => 'website',
    'robots'    => $listing['robots'],
]));
seo_from_entity($category, [
    'title'       => (string) $category['name'],
    'description' => str_limit((string) $category['description'], 160)
        ?: 'Shop ' . $category['name'] . ' online at the best prices.',
    'og_image'    => (string) ($category['banner'] ?: $category['image']),
]);
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], (string) $category['name']));

require INCLUDES_PATH . '/header.php';

render_zone('shop_top');
product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
