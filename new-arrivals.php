<?php
/**
 * ShopInnKart - New arrivals.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'New Arrivals'],
];

$listing = product_listing_query([
    'base'         => ['new' => 1],
    'default_sort' => 'newest',
    'title'        => 'New Arrivals',
    'subtitle'     => 'The latest launches on our shelves, newest first.',
    'breadcrumbs'  => $crumbs,
    'clear_url'    => url('new-arrivals.php'),
    'toolbar_note' => 'just landed',
    'empty'        => static function (): void {
        ?>
        <div class="sik-empty" style="grid-column:1/-1">
            <?= icon('package', 'w-14 h-14') ?>
            <p class="sik-empty__title">No new arrivals match these filters</p>
            <p class="sik-empty__text">Clear the filters to see every recent launch, or browse the full catalogue.</p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('new-arrivals.php')) ?>" data-filter-clear>
                    Show all new arrivals
                </a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">Browse all products</a>
            </div>
        </div>
        <?php
    },
]);

seo_from_page('new-arrivals');
seo_set(array_filter([
    'og_type' => 'website',
    'robots'  => $listing['robots'],
]));
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], 'New Arrivals'));

require INCLUDES_PATH . '/header.php';

render_zone('shop_top');
product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
