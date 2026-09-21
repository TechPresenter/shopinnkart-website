<?php
/**
 * ShopInnKart - Best sellers.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Best Sellers'],
];

$listing = product_listing_query([
    'base'         => ['best' => 1],
    'default_sort' => 'best_selling',
    // best_selling is not in the shared sort menu, so it is added here.
    'sorts'        => ['best_selling' => 'Most Sold'] + PRODUCT_SORT_OPTIONS,
    'title'        => 'Best Sellers',
    'subtitle'     => 'The products our customers buy most, ranked by units sold.',
    'breadcrumbs'  => $crumbs,
    'clear_url'    => url('best-sellers.php'),
    'toolbar_note' => 'ranked by sales',
    'empty'        => static function (): void {
        ?>
        <div class="sik-empty" style="grid-column:1/-1">
            <?= icon('award', 'w-14 h-14') ?>
            <p class="sik-empty__title">No best sellers match these filters</p>
            <p class="sik-empty__text">Clear the filters to see the full top-sellers list, or browse the catalogue.</p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('best-sellers.php')) ?>" data-filter-clear>
                    Show all best sellers
                </a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">Browse all products</a>
            </div>
        </div>
        <?php
    },
]);

seo_from_page('best-sellers');
seo_set(array_filter([
    'og_type' => 'website',
    'robots'  => $listing['robots'],
]));
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], 'Best Sellers'));

require INCLUDES_PATH . '/header.php';

render_zone('shop_top');
product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
