<?php
/**
 * ShopInnKart - Product detail endpoint.
 *
 * view=quick returns the Quick View modal body; anything else returns the
 * decorated product as JSON for scripted use.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

api_require_method(['GET']);

$productId = input_int('id');
$slug = trim((string) input('slug', ''));

if ($productId <= 0 && $slug === '') {
    json_validation_error(['id' => 'Provide a product id or slug.']);
}

if ($slug === '') {
    $slug = (string) (Database::fetchColumn(
        'SELECT p.`slug` FROM `products` p WHERE p.`id` = :id AND ' . product_visible_sql() . ' LIMIT 1',
        ['id' => $productId]
    ) ?? '');
}

$product = $slug === '' ? null : get_product_detail($slug);

if ($product === null) {
    json_error('That product is no longer available.', [], 404);
}

// Never ship buying prices or scheduling internals to the browser.
foreach (['cost_price', 'hsn_code', 'vendor_id', 'published_at'] as $internal) {
    unset($product[$internal]);
}

if (input('view', '') !== 'quick') {
    json_success('OK', ['product' => $product]);
}

// ---------------------------------------------------------------------------
//  Quick View markup
// ---------------------------------------------------------------------------
$variants = $product['variants'];
$defaultVariant = null;
foreach ($variants as $variant) {
    if ((int) $variant['is_default'] === 1) {
        $defaultVariant = $variant;
        break;
    }
}
if ($defaultVariant === null && $variants !== []) {
    $defaultVariant = $variants[0];
}

// Only the fields assets/js/products.js reads - variant rows also carry
// timestamps and raw prices that the client has no business seeing.
$variantPayload = array_map(static function (array $variant): array {
    return [
        'id'            => (int) $variant['id'],
        'sku'           => (string) $variant['sku'],
        'variant_name'  => (string) $variant['variant_name'],
        'is_default'    => (int) $variant['is_default'],
        'price_display' => (string) $variant['price_display'],
        'mrp_display'   => (string) $variant['mrp_display'],
        'discount'      => (int) $variant['discount'],
        'stock'         => (int) $variant['stock'],
        'stock_state'   => (string) $variant['stock_state'],
        'stock_label'   => (string) $variant['stock_label'],
        'in_stock'      => (bool) $variant['in_stock'],
        'image_url'     => (string) $variant['image_url'],
        'attributes'    => array_map(static fn (array $a): array => [
            'attribute_id'       => (int) $a['attribute_id'],
            'attribute_value_id' => (int) $a['attribute_value_id'],
        ], $variant['attributes'] ?? []),
    ];
}, $variants);

$activeStock = $defaultVariant !== null ? (int) $defaultVariant['stock'] : (int) $product['stock'];
$stockState  = $defaultVariant !== null ? (string) $defaultVariant['stock_state'] : (string) $product['stock_state'];
$stockLabel  = $defaultVariant !== null ? (string) $defaultVariant['stock_label'] : (string) $product['stock_label'];
$stockTone   = ['in_stock' => 'green', 'low_stock' => 'amber'][$stockState] ?? 'red';

$priceDisplay = $defaultVariant !== null ? (string) $defaultVariant['price_display'] : (string) $product['price_display'];
$mrpDisplay   = $defaultVariant !== null ? (string) $defaultVariant['mrp_display'] : (string) $product['mrp_display'];
$discount     = $defaultVariant !== null ? (int) $defaultVariant['discount'] : (int) $product['discount'];

$maxQty = max(1, min((int) $product['max_order_qty'], $activeStock > 0 ? $activeStock : (int) $product['max_order_qty']));
$minQty = max(1, (int) $product['min_order_qty']);

$images = $product['images'];

ob_start();
?>
<div class="sik-pdp" style="padding:22px" data-quickview-body>
    <div data-gallery>
        <div class="sik-gallery__main" data-gallery-main>
            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>"
                 width="520" height="520" decoding="async">
            <?php if (count($images) > 1): ?>
                <button type="button" class="sik-iconbtn" data-gallery-expand
                        style="position:absolute;right:12px;bottom:12px;z-index:2" aria-label="View larger image">
                    <?= icon('search', 'w-4 h-4') ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if (count($images) > 1): ?>
            <div class="sik-gallery__thumbs">
                <?php foreach ($images as $index => $image): ?>
                    <?php $imageUrl = img_url($image['image']); ?>
                    <button type="button" class="sik-gallery__thumb <?= $index === 0 ? 'is-active' : '' ?>"
                            data-gallery-thumb="<?= e_attr($imageUrl) ?>"
                            data-gallery-alt="<?= e_attr($image['alt_text'] ?: $product['name']) ?>"
                            aria-label="Image <?= $index + 1 ?> of <?= count($images) ?>">
                        <img src="<?= e($imageUrl) ?>" alt="" width="72" height="72" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if (!empty($product['brand_name'])): ?>
            <a href="<?= e(brand_url((string) $product['brand_slug'])) ?>" class="sik-card__brand"
               style="display:inline-block;margin-bottom:6px"><?= e($product['brand_name']) ?></a>
        <?php endif; ?>

        <h2 class="sik-pdp__title"><?= e($product['name']) ?></h2>

        <div class="sik-pdp__meta" style="margin-top:10px;align-items:center">
            <?php if ((int) $product['rating_count'] > 0): ?>
                <span style="display:inline-flex;align-items:center;gap:7px">
                    <?= rating_stars((float) $product['rating_avg'], 'w-4 h-4') ?>
                    <?= e(number_format((float) $product['rating_avg'], 1)) ?>
                    <span>(<?= (int) $product['rating_count'] ?> reviews)</span>
                </span>
            <?php else: ?>
                <span>No reviews yet</span>
            <?php endif; ?>
            <span>SKU: <b data-variant-sku><?= e($defaultVariant['sku'] ?? $product['sku']) ?></b></span>
        </div>

        <div class="sik-pdp__price">
            <span class="sik-price" data-variant-price><?= e($priceDisplay) ?></span>
            <span class="sik-price--mrp" data-variant-mrp <?= $discount > 0 ? '' : 'hidden' ?>><?= e($mrpDisplay) ?></span>
            <span class="sik-price--off" data-variant-off <?= $discount > 0 ? '' : 'hidden' ?>><?= $discount ?>% OFF</span>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px">
            <span class="sik-status sik-status--<?= e_attr($stockTone) ?>" data-variant-stock><?= e($stockLabel) ?></span>
            <?php if (!empty($product['warranty'])): ?>
                <span class="sik-badge sik-badge--soft"><?= e($product['warranty']) ?></span>
            <?php endif; ?>
            <?php if ((int) $product['cod_available'] === 1): ?>
                <span class="sik-badge sik-badge--soft">COD available</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($product['short_description'])): ?>
            <p style="font-size:13.5px;color:var(--sik-muted);line-height:1.6;margin-bottom:18px">
                <?= e(str_limit($product['short_description'], 220)) ?>
            </p>
        <?php endif; ?>

        <form onsubmit="return false" data-quickview-form>
            <input type="hidden" id="sikSelectedVariant" name="variant_id"
                   value="<?= $defaultVariant !== null ? (int) $defaultVariant['id'] : '' ?>">

            <?php if ($variantPayload !== []): ?>
                <?php // Single-quoted: e_json() escapes apostrophes but leaves JSON's own double quotes intact. ?>
                <div data-variants='<?= e_json($variantPayload) ?>'>
                    <?php foreach ($product['variant_attributes'] as $group): ?>
                        <div class="sik-variant">
                            <div class="sik-variant__label"><?= e($group['name']) ?></div>
                            <?php if ($group['type'] === 'color'): ?>
                                <div class="sik-swatches">
                                    <?php foreach ($group['values'] as $value): ?>
                                        <button type="button" class="sik-swatch" data-variant-option
                                                data-attribute-id="<?= (int) $group['id'] ?>"
                                                data-value-id="<?= (int) $value['id'] ?>"
                                                style="background:<?= e_attr($value['color_code'] ?: '#E5E7EB') ?>"
                                                title="<?= e_attr($value['value']) ?>"
                                                aria-label="<?= e_attr($group['name'] . ': ' . $value['value']) ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="sik-pills">
                                    <?php foreach ($group['values'] as $value): ?>
                                        <button type="button" class="sik-pill" data-variant-option
                                                data-attribute-id="<?= (int) $group['id'] ?>"
                                                data-value-id="<?= (int) $value['id'] ?>">
                                            <?= e($value['value']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px">
                <label class="sik-sr" for="sikQty">Quantity</label>
                <div class="sik-qty">
                    <button type="button" data-qty-minus aria-label="Decrease quantity"><?= icon('minus', 'w-4 h-4') ?></button>
                    <input type="number" id="sikQty" name="quantity" value="<?= $minQty ?>"
                           min="<?= $minQty ?>" max="<?= $maxQty ?>" step="1" inputmode="numeric">
                    <button type="button" data-qty-plus aria-label="Increase quantity"><?= icon('plus', 'w-4 h-4') ?></button>
                </div>
                <span style="font-size:12px;color:var(--sik-muted)">Max <?= $maxQty ?> per order</span>
            </div>

            <?php
            // Quick View has its own variant picker above, so it renders the
            // same add_to_cart_button() the product page does - variant_from
            // and all - rather than a near-copy that could drift from it.
            $quickProduct = $product;
            $quickProduct['stock_state'] = $stockState;
            $quickProduct['in_stock']    = $activeStock > 0;

            $quickOpts = [
                'block'        => false,
                'variant_from' => '#sikSelectedVariant',
                'qty_from'     => '#sikQty',
            ];
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <?= add_to_cart_button($quickProduct, $quickOpts + ['tone' => 'primary']) ?>
                <?= add_to_cart_button($quickProduct, $quickOpts + ['mode' => 'buy', 'tone' => 'navy']) ?>

                <?php // Quick View is the product detail in a modal, so it follows the
                      // product-detail switches in Admin > Settings > Widgets and uses
                      // the same shared control the page itself does. ?>
                <?= wishlist_shows_on('pdp') ? wishlist_button((int) $product['id'], (string) $product['name']) : '' ?>
                <?= compare_shows_on('pdp') ? compare_button((int) $product['id'], (string) $product['name']) : '' ?>
            </div>
        </form>

        <?php if ($product['features'] !== []): ?>
            <ul style="margin-top:18px;display:grid;gap:7px;font-size:13px;color:var(--sik-muted)">
                <?php foreach (array_slice($product['features'], 0, 4) as $feature): ?>
                    <li style="display:flex;gap:8px;align-items:flex-start">
                        <span style="color:var(--sik-success);flex:none"><?= icon('check', 'w-4 h-4') ?></span>
                        <span><?= e($feature) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a class="sik-viewall" href="<?= e($product['url']) ?>" style="margin-top:18px;display:inline-flex">
            View full details <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
        </a>
    </div>
</div>
<?php
json_success('OK', ['html' => (string) ob_get_clean()]);
