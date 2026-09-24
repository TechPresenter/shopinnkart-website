<?php
/**
 * ShopInnKart - Brand listing.
 *
 * /brand/<slug> via the rewrite in .htaccess, ?slug=<slug> without it.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

$slug = trim((string) input('slug', ''));
$brand = $slug === '' ? null : get_brand_by_slug($slug);

if ($brand === null) {
    require ROOT_PATH . '/404.php';
    exit;
}

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Brands', 'url' => url('brands.php')],
    ['label' => (string) $brand['name']],
];

$listing = product_listing_query([
    'base'        => ['brand' => (string) $brand['slug']],
    'title'       => $brand['name'] . ' Products',
    'subtitle'    => 'Genuine ' . $brand['name'] . ' stock with full manufacturer warranty.',
    'description' => (string) ($brand['description'] ?? ''),
    'logo'        => $brand['logo'] ?? null,
    'breadcrumbs' => $crumbs,
    'clear_url'   => brand_url((string) $brand['slug']),
]);

seo_set(array_filter([
    'canonical' => brand_url((string) $brand['slug']),
    'og_type'   => 'website',
    'robots'    => $listing['robots'],
]));
seo_from_entity($brand, [
    'title'       => (string) $brand['name'],
    'description' => str_limit((string) $brand['description'], 160)
        ?: 'Shop the full ' . $brand['name'] . ' range online at the best prices.',
    'og_image'    => (string) $brand['logo'],
], 'brand');
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], (string) $brand['name']));

require INCLUDES_PATH . '/header.php';

render_zone('shop_top');
product_listing_render($listing);
?>

<?php if (!empty($brand['website'])): ?>
    <div class="sik-container" style="padding-bottom:var(--sp-10)">
        <div class="sik-panel">
            <div class="sik-panel__body" style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap">
                <?= icon('external', 'w-5 h-5') ?>
                <div style="flex:1;min-width:200px">
                    <strong style="display:block;font-size:14px">About <?= e($brand['name']) ?></strong>
                    <span style="font-size:13px;color:var(--sik-muted)">
                        Product specifications, manuals and support are published on the official site.
                    </span>
                </div>
                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e((string) $brand['website']) ?>"
                   target="_blank" rel="noopener noreferrer nofollow">
                    Visit <?= e($brand['name']) ?><?= icon('arrow-right', 'w-4 h-4') ?>
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
