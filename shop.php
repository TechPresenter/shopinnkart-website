<?php
/**
 * ShopInnKart - Shop.
 *
 * The unfiltered catalogue. Everything below the header comes from the shared
 * listing partial, so shop, category, brand, search and the curated pages all
 * behave identically.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Shop'],
];

$listing = product_listing_query([
    'title'       => 'Shop All Products',
    'subtitle'    => 'String lights, curtain lights, diyas, LED candles, lamps and projectors — filter your way to the right one.',
    'breadcrumbs' => $crumbs,
]);

seo_from_page('shop');
seo_set(array_filter([
    'og_type' => 'website',
    'robots'  => $listing['robots'],
]));
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], 'Shop All Products'));

require INCLUDES_PATH . '/header.php';

render_zone('shop_top');
product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
