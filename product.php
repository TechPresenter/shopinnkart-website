<?php
/**
 * ShopInnKart - Product detail page.
 *
 * Served as /product/<slug> by the rewrite in .htaccess, which hands the slug
 * over as ?slug=. Every price on this page comes from product_effective_price()
 * via get_product_detail(); nothing here trusts a number from the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

// Named $detail, not $product: includes/navbar.php reuses $product as a loop
// variable at global scope, so anything called $product dies at the header.
$slug = trim((string) input('slug', ''));
$detail = $slug === '' ? null : get_product_detail($slug);

if ($detail === null) {
    require __DIR__ . '/404.php';
    exit;
}

$productId  = (int) $detail['id'];
$categoryId = (int) ($detail['category_id'] ?? 0);
$threshold  = (int) ($detail['low_stock_threshold'] ?? 5);

record_product_view($productId);

// ---------------------------------------------------------------------------
//  Variants & pricing
// ---------------------------------------------------------------------------
$variants          = $detail['variants'];
$variantAttributes = $detail['variant_attributes'];

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

$pricing = product_effective_price($detail, $defaultVariant);

// The buy box opens on the default variant, but the Notify Me swap keys off
// the whole product: a shopper can still switch to a variant that is in stock.
$shownStock = $defaultVariant !== null ? (int) $defaultVariant['stock'] : (int) $detail['stock'];
$totalStock = $variants === []
    ? (int) $detail['stock']
    : array_sum(array_map(static fn ($v) => max(0, (int) $v['stock']), $variants));
$inStock = $totalStock > 0;

$stockState = stock_status($shownStock, $threshold);
$stockTone  = [STOCK_IN => 'green', STOCK_LOW => 'amber', STOCK_OUT => 'red'][$stockState];

$minQty = max(1, (int) $detail['min_order_qty']);
$maxQty = max($minQty, min((int) $detail['max_order_qty'], max(1, $shownStock)));

$currentSku = $defaultVariant !== null ? (string) $defaultVariant['sku'] : (string) $detail['sku'];

// ---------------------------------------------------------------------------
//  Reviews
// ---------------------------------------------------------------------------
$reviewSorts = [
    'newest'  => 'Most recent',
    'helpful' => 'Most helpful',
    'highest' => 'Highest rated',
    'lowest'  => 'Lowest rated',
    'oldest'  => 'Oldest first',
];
$reviewSort = (string) input('review_sort', 'newest');
if (!isset($reviewSorts[$reviewSort])) {
    $reviewSort = 'newest';
}
$reviewPage = max(1, input_int('review_page', 1));

$breakdown = review_breakdown($productId);
$reviews   = product_reviews($productId, $reviewPage, 5, $reviewSort);

$user            = current_user();
$requirePurchase = setting_bool('reviews_require_purchase', true);
$hasPurchased    = $user !== null && has_purchased_product((int) $user['id'], $productId);
$alreadyReviewed = $user !== null && Database::exists(
    'reviews',
    '`product_id` = :pid AND `user_id` = :uid',
    ['pid' => $productId, 'uid' => (int) $user['id']]
);
$canReview = $user !== null && !$alreadyReviewed && (!$requirePurchase || $hasPurchased);

/** Review pager / sort links keep the reader on the Reviews tab. */
$productSlug = (string) $detail['slug'];
$reviewUrl = static function (int $page, string $sort) use ($productSlug): string {
    return product_url($productSlug)
        . '?review_sort=' . rawurlencode($sort) . '&review_page=' . $page . '#reviews';
};

// ---------------------------------------------------------------------------
//  Offers, breadcrumbs, SEO
// ---------------------------------------------------------------------------
// The bank_offers query that stood here is gone with the panel that read it:
// an unmanaged table with no admin screen, queried on every product view to
// fill a box that now draws from the coupon engine instead.

$crumbs = [['label' => 'Home', 'url' => url()]];
foreach (category_ancestors($categoryId) as $ancestor) {
    $crumbs[] = ['label' => (string) $ancestor['name'], 'url' => category_url((string) $ancestor['slug'])];
}
$crumbs[] = ['label' => (string) $detail['name'], 'url' => product_url((string) $detail['slug'])];

// Defaults first, then the record's own SEO fields on top. seo_from_entity()
// handles meta title/description, canonical, robots, the OG trio and any custom
// JSON-LD the admin saved, so every entity page resolves them identically.
seo_set([
    'canonical' => product_url((string) $detail['slug']),
    'og_type'   => 'product',
]);
seo_from_entity($detail, [
    'title'       => (string) $detail['name'],
    'description' => str_limit((string) ($detail['short_description'] ?: $detail['description']), 260, ''),
    'og_image'    => (string) $detail['main_image'],
]);
seo_add_schema(seo_product_schema($detail));
seo_add_schema(seo_breadcrumb_schema($crumbs));

// Admin descriptions arrive as rich text or as plain paragraphs; keep both readable.
$description     = trim((string) $detail['description']);
$descriptionHtml = $description === ''
    ? ''
    : (strip_tags($description) === $description ? nl2br(e($description)) : sanitize_html($description));

$galleryImages = $detail['images'];
$returnDays    = setting_int('return_window_days', 7);
$freeShipAt    = setting_float('free_shipping_threshold', 999);

require INCLUDES_PATH . '/header.php';
?>
<style>
    /* app.css styles rendered ratings, not the rating *input*, and .sik-btn has
       no toggled state - both are only ever needed on this page. */
    /* The --tap area has to grow this button IN FLOW. Five out-of-flow 44px
       ::after circles on the old 32px pitch would overlap, and because a later
       sibling paints over an earlier one, a click on the right half of star N
       would have registered as star N+1 — the shared extender in app.css is
       wrong for a row of adjacent targets. Padding tiles the five hit areas
       edge to edge instead, which is also why the gap is now 0. */
    .sik-ratepick { display: inline-flex; }
    .sik-ratepick button {
        background: none; border: 0; line-height: 0;
        padding: calc((var(--tap) - var(--icon-xl)) / 2);
        cursor: pointer; color: #D1D5DB;
        transition: color var(--dur-fast) var(--ease-out),
                    transform var(--dur-fast) var(--ease-out);
    }
    /* The component owns the glyph size; the call site passed `w-6 h-6`. */
    .sik-ratepick button svg { width: var(--icon-xl); height: var(--icon-xl); }
    .sik-ratepick button:hover { transform: scale(1.14); }
    /* Was #F59E0B, a different amber from the --sik-star that .sik-star paints
       the rendered rating with, so the stars changed colour between picking a
       rating and seeing it back. */
    .sik-ratepick button.is-selected { color: var(--sik-star); }
    .sik-btn.is-active {
        background: var(--sik-primary-soft);
        border-color: var(--sik-primary);
        color: var(--sik-primary);
    }
</style>

<div class="sik-container sik-section sik-section--sm" data-tabs>

    <?= breadcrumbs($crumbs) ?>

    <div class="sik-pdp" style="margin-top:var(--sp-5)">

        <!-- =========================== Gallery =========================== -->
        <div data-gallery>
            <div style="position:relative">
                <div class="sik-gallery__main" data-gallery-main>
                    <img src="<?= e(img_url($galleryImages[0]['image'] ?? $detail['main_image'])) ?>"
                         alt="<?= e($galleryImages[0]['alt_text'] ?? $detail['name']) ?>"
                         width="720" height="720" fetchpriority="high" decoding="async">
                </div>

                <?php if ((int) $pricing['discount'] > 0): ?>
                    <span class="sik-badge sik-badge--orange" style="position:absolute;top:12px;left:12px;z-index:2">
                        <?= (int) $pricing['discount'] ?>% OFF
                    </span>
                <?php endif; ?>

                <!-- Kept outside [data-gallery-main] so expanding does not also trigger the zoom toggle. -->
                <button type="button" class="sik-iconbtn" data-gallery-expand
                        style="position:absolute;top:12px;right:12px;z-index:2;background:#fff"
                        aria-label="View <?= e($detail['name']) ?> full screen">
                    <?= icon('search', 'w-4 h-4') ?>
                </button>
            </div>

            <?php if (count($galleryImages) > 1): ?>
                <div class="sik-gallery__thumbs">
                    <?php foreach ($galleryImages as $index => $image): ?>
                        <button type="button"
                                class="sik-gallery__thumb <?= $index === 0 ? 'is-active' : '' ?>"
                                data-gallery-thumb="<?= e(img_url($image['image'])) ?>"
                                data-gallery-alt="<?= e($image['alt_text'] ?: $detail['name']) ?>"
                                aria-label="View image <?= $index + 1 ?> of <?= count($galleryImages) ?>">
                            <img src="<?= e(img_url($image['image'])) ?>"
                                 alt="<?= e($image['alt_text'] ?: $detail['name']) ?>"
                                 width="72" height="72" loading="lazy" decoding="async">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================== Buy box ============================ -->
        <div>
            <?php if (!empty($detail['brand_name'])): ?>
                <a href="<?= e(brand_url((string) $detail['brand_slug'])) ?>"
                   style="display:inline-flex;align-items:center;gap:var(--sp-2);font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--sik-primary)">
                    <?= e($detail['brand_name']) ?> <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                </a>
            <?php endif; ?>

            <h1 class="sik-pdp__title" style="margin-top:var(--sp-2)"><?= e($detail['name']) ?></h1>

            <div class="sik-pdp__meta" style="margin-top:var(--sp-3);align-items:center">
                <?php if ((int) $detail['rating_count'] > 0): ?>
                    <a href="#reviews" data-tab="reviews"
                       style="display:inline-flex;align-items:center;gap:var(--sp-2);color:inherit">
                        <?= rating_stars((float) $detail['rating_avg'], 'w-4 h-4') ?>
                        <strong style="color:var(--sik-text)"><?= e(number_format((float) $detail['rating_avg'], 1)) ?></strong>
                        <span><?= (int) $detail['rating_count'] ?> ratings</span>
                    </a>
                <?php else: ?>
                    <a href="#reviews" data-tab="reviews" style="color:inherit">Be the first to review this product</a>
                <?php endif; ?>

                <span>SKU: <b data-variant-sku style="color:var(--sik-text);font-weight:600"><?= e($currentSku) ?></b></span>

                <?php if ((int) $detail['sold_count'] > 0): ?>
                    <span><?= (int) $detail['sold_count'] ?> sold</span>
                <?php endif; ?>
            </div>

            <div class="sik-pdp__price">
                <span class="sik-price" data-variant-price><?= e(money($pricing['price'])) ?></span>
                <span class="sik-price--mrp" data-variant-mrp <?= $pricing['discount'] > 0 ? '' : 'hidden' ?>>
                    <?= e(money($pricing['mrp'])) ?>
                </span>
                <span class="sik-price--off" data-variant-off <?= $pricing['discount'] > 0 ? '' : 'hidden' ?>>
                    <?= (int) $pricing['discount'] ?>% OFF
                </span>
            </div>

            <div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                <span class="sik-status sik-status--<?= e($stockTone) ?>" data-variant-stock>
                    <?= e(stock_label($shownStock, $threshold)) ?>
                </span>
                <?php if ($pricing['saving'] > 0): ?>
                    <span style="font-size:13px;font-weight:600;color:var(--sik-success)">
                        You save <?= e(money($pricing['saving'])) ?>
                    </span>
                <?php endif; ?>
                <span style="font-size:12px;color:var(--sik-muted)">Inclusive of all taxes</span>
            </div>

            <?php if (!empty($detail['short_description'])): ?>
                <p style="font-size:14px;line-height:1.7;color:var(--sik-muted);margin-bottom:var(--sp-5)">
                    <?= e($detail['short_description']) ?>
                </p>
            <?php endif; ?>

            <!-- ---------------------- Variant pickers --------------------- -->
            <?php if ($variants !== []): ?>
                <?php // Single-quoted: e_json() hex-escapes ' & < >, but leaves JSON's own " intact. ?>
                <div data-variants='<?= e_json($variants) ?>'>
                    <?php foreach ($variantAttributes as $attributeSlug => $group): ?>
                        <div class="sik-variant">
                            <span class="sik-variant__label" id="sikAttr<?= (int) $group['id'] ?>">
                                <?= e($group['name']) ?>
                            </span>
                            <div class="<?= $group['type'] === 'color' ? 'sik-swatches' : 'sik-pills' ?>"
                                 role="group" aria-labelledby="sikAttr<?= (int) $group['id'] ?>">
                                <?php foreach ($group['values'] as $value): ?>
                                    <?php
                                    // color_code is admin-entered free text, so only a literal hex
                                    // colour is ever allowed into the style attribute.
                                    $swatch = preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string) $value['color_code']) === 1
                                        ? (string) $value['color_code']
                                        : '#E5E7EB';
                                    ?>
                                    <button type="button"
                                            class="<?= $group['type'] === 'color' ? 'sik-swatch' : 'sik-pill' ?>"
                                            data-variant-option
                                            data-attribute-id="<?= (int) $group['id'] ?>"
                                            data-value-id="<?= (int) $value['id'] ?>"
                                            <?= $group['type'] === 'color' ? 'style="background:' . e_attr($swatch) . '"' : '' ?>
                                            title="<?= e($group['name'] . ': ' . $value['value']) ?>"
                                            aria-label="<?= e($group['name'] . ': ' . $value['value']) ?>">
                                        <?= $group['type'] === 'color' ? '' : e($value['value']) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($inStock): ?>
                        <input type="hidden" id="sikSelectedVariant"
                               value="<?= $defaultVariant !== null ? (int) $defaultVariant['id'] : '' ?>">
                    <?php endif; ?>
                </div>
            <?php elseif ($inStock): ?>
                <input type="hidden" id="sikSelectedVariant" value="">
            <?php endif; ?>

            <!-- ------------------------- Actions -------------------------- -->
            <?php if ($inStock): ?>
                <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                    <span style="font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em">Quantity</span>
                    <div class="sik-qty">
                        <button type="button" data-qty-minus aria-label="Decrease quantity"><?= icon('minus', 'w-4 h-4') ?></button>
                        <input type="number" id="sikQty" value="<?= $minQty ?>" min="<?= $minQty ?>" max="<?= $maxQty ?>"
                               step="1" inputmode="numeric" aria-label="Quantity">
                        <button type="button" data-qty-plus aria-label="Increase quantity"><?= icon('plus', 'w-4 h-4') ?></button>
                    </div>
                    <span style="font-size:12px;color:var(--sik-muted)">Max <?= $maxQty ?> per order</span>
                </div>

                <?php
                // The one Add to Cart component, sized up for the buy box. This
                // page has a real variant picker, so variant_from is passed and
                // no "choose options" redirect is needed - the choice is here.
                //
                // The button opens on the *shown* variant's availability, not
                // the product row's: a product can be in stock overall while the
                // variant on screen is not. products.js keeps it in step as the
                // shopper changes the selection.
                $buyProduct = $detail;
                $buyProduct['stock_state'] = $stockState;
                $buyProduct['in_stock']    = $shownStock > 0;

                $buyOpts = [
                    'size'         => 'lg',
                    'variant_from' => '#sikSelectedVariant',
                    'qty_from'     => '#sikQty',
                ];
                ?>
                <div class="grid gap-2 sm:grid-cols-2" style="margin-bottom:var(--sp-4)">
                    <?= add_to_cart_button($buyProduct, $buyOpts + ['tone' => 'outline']) ?>
                    <?= add_to_cart_button($buyProduct, $buyOpts + ['mode' => 'buy', 'tone' => 'primary']) ?>
                </div>
            <?php else: ?>
                <div class="sik-panel" style="margin-bottom:var(--sp-4)">
                    <div class="sik-panel__body">
                        <strong style="display:block;font-size:14px;margin-bottom:var(--sp-1)">Currently out of stock</strong>
                        <p style="font-size:13px;color:var(--sik-muted);margin-bottom:var(--sp-4)">
                            Leave your email and we will tell you the moment <?= e($detail['name']) ?> is back.
                        </p>
                        <form data-stock-alert-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                            <?php // Doubles as the variant target the variant picker keeps in sync. ?>
                            <input type="hidden" name="variant_id" id="sikSelectedVariant"
                                   value="<?= $defaultVariant !== null ? (int) $defaultVariant['id'] : '' ?>">
                            <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap">
                                <label class="sik-sr" for="sikStockAlertEmail">Email address</label>
                                <input class="sik-input" id="sikStockAlertEmail" type="email" name="email" required
                                       style="flex:1;min-width:180px"
                                       autocomplete="email" placeholder="you@example.com"
                                       value="<?= e($user['email'] ?? '') ?>">
                                <button type="submit" class="sik-btn sik-btn--primary">
                                    <?= icon('bell', 'w-4 h-4') ?><span class="sik-btn__label">Notify Me</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-6)">
                <?php // Both controls come from the shared renderer in widgets.php, and
                      // Admin > Settings > Widgets decides whether this surface shows
                      // them at all. Share is not a saved list, so it is never gated. ?>
                <?= wishlist_shows_on('pdp') ? wishlist_button($productId, (string) $detail['name'], ['style' => 'ghost']) : '' ?>
                <?= compare_shows_on('pdp') ? compare_button($productId, (string) $detail['name'], ['style' => 'ghost']) : '' ?>
                <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm"
                        data-share="<?= e(product_url((string) $detail['slug'])) ?>"
                        data-share-title="<?= e($detail['name']) ?>">
                    <?= icon('external', 'w-4 h-4') ?><span class="sik-btn__label">Share</span>
                </button>
            </div>

            <?php /* The EMI / warranty / Cash on Delivery / returns tile grid that
                     stood here was removed: three of its four claims were printed
                     whatever the store actually offered - "EMI available" and
                     "Brand warranty" fell back to those strings when the product
                     carried neither. What is genuinely true of an order is said
                     once, by the delivery panel below and by the offers that
                     checkout will really honour. */ ?>

            <!-- ---------------------- Pincode check ----------------------- -->
            <div class="sik-panel" style="margin-bottom:var(--sp-5)">
                <div class="sik-panel__head">
                    <span class="sik-panel__title"><?= icon('location', 'w-4 h-4') ?> Delivery &amp; Availability</span>
                </div>
                <div class="sik-panel__body">
                    <form class="sik-pincode" data-pincode-form>
                        <label class="sik-sr" for="sikPincode">PIN code</label>
                        <input class="sik-input" id="sikPincode" name="pincode" type="text" inputmode="numeric"
                               maxlength="6" pattern="[1-9][0-9]{5}" placeholder="Enter 6-digit PIN code" required>
                        <button type="submit" class="sik-btn sik-btn--navy" data-product-id="<?= $productId ?>">
                            <span class="sik-btn__label">Check</span>
                        </button>
                    </form>
                    <div class="sik-alert sik-alert--info" data-pincode-result hidden style="margin-top:var(--sp-3)"></div>
                    <p class="sik-help" style="margin-top:var(--sp-3)">
                        <?= (int) $detail['free_shipping'] === 1
                            ? 'Free delivery on this product.'
                            : 'Free delivery on orders above ' . e(money($freeShipAt)) . '.' ?>
                    </p>
                </div>
            </div>

            <!-- -------------------------- Offers -------------------------- -->
            <?php
            /* The offers a shopper can actually use on THIS product, built from
               the coupon engine rather than from the unmanaged bank_offers
               table the old panel read. public_offers() applies the same
               catalogue rules validate_coupon() applies at checkout, so an
               offer shown here cannot be refused in the basket - which is what
               the previous panel could not promise. */
            $productOffers = public_offers('product', $detail);
            ?>
            <?php if ($productOffers !== []): ?>
                <div class="sik-panel">
                    <div class="sik-panel__head">
                        <span class="sik-panel__title"><?= icon('percent', 'w-4 h-4') ?> Offers on this item</span>
                    </div>
                    <div class="sik-panel__body">
                        <div class="sik-offers">
                            <?php foreach ($productOffers as $productOffer): ?>
                                <?= offer_card($productOffer) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================== Tabs ============================== -->
    <div id="reviews" style="margin-top:var(--sp-9)">
        <?php /* The tab panels below open at h3 ("Shipping", "N customer reviews").
                 Without this the page jumps h1 -> h3. The tab strip is the visible
                 label, so the heading itself is for assistive tech only. */ ?>
        <h2 class="sik-sr">Product information</h2>

        <div class="sik-tabs" role="tablist" aria-label="Product information">
            <?php
            $tabs = [
                'description'    => 'Description',
                'specifications' => 'Specifications',
                'features'       => 'Features',
                'reviews'        => 'Reviews (' . (int) $breakdown['total'] . ')',
                'warranty'       => 'Warranty',
                'shipping'       => 'Shipping & Returns',
            ];
            $firstTab = true;
            foreach ($tabs as $key => $label):
                ?>
                <button type="button" class="sik-tab <?= $firstTab ? 'is-active' : '' ?>"
                        role="tab" id="sikTab-<?= e($key) ?>" aria-controls="sikPanel-<?= e($key) ?>"
                        aria-selected="<?= $firstTab ? 'true' : 'false' ?>"
                        data-tab="<?= e($key) ?>"><?= e($label) ?></button>
                <?php
                $firstTab = false;
            endforeach;
            ?>
        </div>

        <!-- --------------------------- Description ---------------------- -->
        <div class="sik-tabpanel is-active" role="tabpanel" id="sikPanel-description"
             aria-labelledby="sikTab-description" data-tabpanel="description">
            <?php if ($descriptionHtml !== ''): ?>
                <div class="sik-prose" style="max-width:80ch"><?= $descriptionHtml ?></div>
            <?php else: ?>
                <div class="sik-empty">
                    <?= icon('info', 'w-12 h-12') ?>
                    <h3 class="sik-empty__title">No description yet</h3>
                    <p class="sik-empty__text">
                        Full details for this product are still being written. The Specifications tab has the
                        confirmed numbers in the meantime.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ------------------------ Specifications ---------------------- -->
        <div class="sik-tabpanel" role="tabpanel" id="sikPanel-specifications"
             aria-labelledby="sikTab-specifications" data-tabpanel="specifications">
            <?php if ($detail['specifications'] === []): ?>
                <div class="sik-empty">
                    <?= icon('list', 'w-12 h-12') ?>
                    <h3 class="sik-empty__title">Specifications coming soon</h3>
                    <p class="sik-empty__text">We are still confirming the technical sheet for this product.</p>
                </div>
            <?php else: ?>
                <div style="display:grid;gap:var(--sp-2);max-width:760px">
                    <?php foreach ($detail['specifications'] as $groupName => $specs): ?>
                        <table class="sik-spectable">
                            <caption><?= e($groupName) ?></caption>
                            <tbody>
                                <?php foreach ($specs as $spec): ?>
                                    <tr>
                                        <th scope="row"><?= e($spec['spec_key']) ?></th>
                                        <td><?= e($spec['spec_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- --------------------------- Features ------------------------- -->
        <div class="sik-tabpanel" role="tabpanel" id="sikPanel-features"
             aria-labelledby="sikTab-features" data-tabpanel="features">
            <?php if ($detail['features'] === []): ?>
                <div class="sik-empty">
                    <?= icon('zap', 'w-12 h-12') ?>
                    <h3 class="sik-empty__title">No highlights listed</h3>
                    <p class="sik-empty__text">The key highlights for this product have not been added yet.</p>
                </div>
            <?php else: ?>
                <ul style="display:grid;gap:var(--sp-3);max-width:760px">
                    <?php foreach ($detail['features'] as $feature): ?>
                        <li style="display:flex;gap:var(--sp-3);align-items:flex-start;font-size:14px;line-height:1.65">
                            <span style="color:var(--sik-success);flex:none"><?= icon('check-circle', 'w-5 h-5') ?></span>
                            <span><?= e($feature) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- ---------------------------- Reviews ------------------------- -->
        <div class="sik-tabpanel" role="tabpanel" id="sikPanel-reviews"
             aria-labelledby="sikTab-reviews" data-tabpanel="reviews">

            <!-- minmax(0, 1fr), never a bare 1fr: this is the grid that once cost
                 96px of horizontal overflow at 320px — a bare fr track floors at
                 the widest unbreakable child (a long review word, the rating bar). -->
            <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:var(--sp-6)">
                <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:var(--sp-6);align-items:start">

                    <!-- Rating summary + breakdown -->
                    <div style="display:flex;gap:var(--sp-6);flex-wrap:wrap;align-items:center;padding:var(--sp-5);background:var(--sik-soft);border:1px solid var(--sik-border);border-radius:var(--sik-radius)">
                        <div style="text-align:center;min-width:120px">
                            <div style="font-size:40px;font-weight:800;line-height:1;color:var(--sik-navy)">
                                <?= e(number_format((float) $detail['rating_avg'], 1)) ?>
                            </div>
                            <div style="margin:var(--sp-2) 0 var(--sp-1)"><?= rating_stars((float) $detail['rating_avg'], 'w-4 h-4') ?></div>
                            <div style="font-size:12.5px;color:var(--sik-muted)">
                                <?= (int) $breakdown['total'] ?> review<?= (int) $breakdown['total'] === 1 ? '' : 's' ?>
                            </div>
                        </div>

                        <div style="flex:1;min-width:min(100%,240px);display:grid;gap:var(--sp-2)">
                            <?php foreach ($breakdown['stars'] as $star => $row): ?>
                                <div style="display:flex;align-items:center;gap:var(--sp-3);font-size:12.5px">
                                    <span style="width:44px;flex:none;color:var(--sik-muted)"><?= (int) $star ?> star</span>
                                    <span class="sik-progress" style="flex:1">
                                        <span style="width:<?= (int) $row['percent'] ?>%"></span>
                                    </span>
                                    <span style="width:38px;flex:none;text-align:right;color:var(--sik-muted)"><?= (int) $row['count'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Write a review -->
                    <?php if ($canReview): ?>
                        <div class="sik-panel">
                            <div class="sik-panel__head"><span class="sik-panel__title">Write a review</span></div>
                            <div class="sik-panel__body">
                                <form data-review-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">

                                    <div class="sik-field" data-star-group>
                                        <span class="sik-label">Your rating <span class="req">*</span></span>
                                        <input type="hidden" name="rating" value="5">
                                        <div class="sik-ratepick">
                                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                                <button type="button" class="is-selected"
                                                        data-star-input="<?= $star ?>"
                                                        aria-label="Rate <?= $star ?> star<?= $star === 1 ? '' : 's' ?>">
                                                    <?= icon('star', '', true) ?>
                                                </button>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <div class="sik-field">
                                        <label class="sik-label" for="sikReviewTitle">Headline <span class="req">*</span></label>
                                        <input class="sik-input" id="sikReviewTitle" name="title" type="text"
                                               maxlength="200" required placeholder="Sums up your experience in a line">
                                    </div>

                                    <div class="sik-field">
                                        <label class="sik-label" for="sikReviewComment">Your review <span class="req">*</span></label>
                                        <textarea class="sik-textarea" id="sikReviewComment" name="comment" required
                                                  maxlength="2000" placeholder="What did you like or dislike? How did you use it?"></textarea>
                                        <span class="sik-help">Minimum 10 characters. Reviews are published after moderation.</span>
                                    </div>

                                    <button type="submit" class="sik-btn sik-btn--primary">
                                        <span class="sik-btn__label">Submit review</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($user === null): ?>
                        <div class="sik-alert sik-alert--info">
                            <?= icon('user', 'w-5 h-5') ?>
                            <span>
                                <a href="<?= e(url('login.php')) ?>" style="font-weight:700;text-decoration:underline">Sign in</a>
                                to write a review for <?= e($detail['name']) ?>.
                            </span>
                        </div>
                    <?php elseif ($alreadyReviewed): ?>
                        <div class="sik-alert sik-alert--success">
                            <?= icon('check-circle', 'w-5 h-5') ?>
                            <span>You have already reviewed this product. Thanks for the feedback.</span>
                        </div>
                    <?php else: ?>
                        <div class="sik-alert sik-alert--warning">
                            <?= icon('info', 'w-5 h-5') ?>
                            <span>
                                Only verified buyers can review this product. Once your order for it has shipped,
                                the review form opens here.
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Review list -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap;margin-bottom:var(--sp-2)">
                        <h3 style="font-size:16px;font-weight:700">
                            <?= (int) $reviews['pagination']['total'] ?> customer review<?= (int) $reviews['pagination']['total'] === 1 ? '' : 's' ?>
                        </h3>
                        <?php if ($reviews['items'] !== []): ?>
                            <div style="display:flex;align-items:center;gap:var(--sp-2)">
                                <label class="sik-sr" for="sikReviewSort">Sort reviews</label>
                                <select class="sik-select" id="sikReviewSort" data-review-sort style="width:auto">
                                    <?php foreach ($reviewSorts as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $value === $reviewSort ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($reviews['items'] === []): ?>
                        <div class="sik-empty">
                            <?= icon('star', 'w-12 h-12') ?>
                            <h3 class="sik-empty__title">No reviews yet</h3>
                            <p class="sik-empty__text">
                                Be the first to tell other shoppers what <?= e($detail['name']) ?> is really like.
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reviews['items'] as $review): ?>
                            <article style="padding:var(--sp-5) 0;border-bottom:1px solid var(--sik-border)">
                                <div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap">
                                    <?= rating_stars((float) $review['rating'], 'w-4 h-4') ?>
                                    <strong style="font-size:14px"><?= e($review['title']) ?></strong>
                                    <?php if ((int) $review['verified_purchase'] === 1): ?>
                                        <span class="sik-badge sik-badge--green">Verified Purchase</span>
                                    <?php endif; ?>
                                </div>

                                <div style="font-size:12px;color:var(--sik-muted);margin-top:var(--sp-1)">
                                    <?= e($review['customer_name']) ?> &middot; <?= e(time_ago($review['created_at'])) ?>
                                </div>

                                <?php if (!empty($review['comment'])): ?>
                                    <p style="font-size:14px;line-height:1.7;margin-top:var(--sp-2)"><?= nl2br(e($review['comment'])) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($review['admin_reply'])): ?>
                                    <div style="margin-top:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:var(--sik-soft);border-left:3px solid var(--sik-primary);border-radius:var(--sik-radius-sm)">
                                        <strong style="display:block;font-size:12.5px;margin-bottom:3px">
                                            Reply from <?= e(setting('store_name', SITE_NAME)) ?>
                                        </strong>
                                        <span style="font-size:13px;color:var(--sik-muted);line-height:1.65">
                                            <?= nl2br(e($review['admin_reply'])) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ((int) $review['helpful_count'] > 0): ?>
                                    <div style="font-size:12px;color:var(--sik-muted);margin-top:var(--sp-2)">
                                        <?= (int) $review['helpful_count'] ?> shopper<?= (int) $review['helpful_count'] === 1 ? '' : 's' ?> found this helpful
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>

                        <?php if ($reviews['pagination']['last'] > 1): ?>
                            <nav class="sik-pager" aria-label="Review pages">
                                <a class="sik-pager__link <?= $reviews['pagination']['current'] <= 1 ? 'is-disabled' : '' ?>"
                                   href="<?= e($reviewUrl(max(1, $reviews['pagination']['current'] - 1), $reviewSort)) ?>"
                                   aria-label="Previous page of reviews"><?= icon('chevron-left', 'w-4 h-4') ?></a>

                                <?php foreach ($reviews['pagination']['pages'] as $page): ?>
                                    <?php if (!is_int($page)): ?>
                                        <span class="sik-pager__gap"><?= e($page) ?></span>
                                    <?php else: ?>
                                        <a class="sik-pager__link <?= $page === $reviews['pagination']['current'] ? 'is-current' : '' ?>"
                                           href="<?= e($reviewUrl($page, $reviewSort)) ?>"><?= $page ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <a class="sik-pager__link <?= $reviews['pagination']['current'] >= $reviews['pagination']['last'] ? 'is-disabled' : '' ?>"
                                   href="<?= e($reviewUrl(min($reviews['pagination']['last'], $reviews['pagination']['current'] + 1), $reviewSort)) ?>"
                                   aria-label="Next page of reviews"><?= icon('chevron-right', 'w-4 h-4') ?></a>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ---------------------------- Warranty ------------------------ -->
        <div class="sik-tabpanel" role="tabpanel" id="sikPanel-warranty"
             aria-labelledby="sikTab-warranty" data-tabpanel="warranty">
            <div class="sik-prose" style="max-width:80ch">
                <p>
                    <strong><?= e($detail['warranty'] ?: 'Standard manufacturer warranty') ?></strong>
                    <?php if (!empty($detail['brand_name'])): ?>
                        &mdash; serviced through authorised <?= e($detail['brand_name']) ?> centres across India.
                    <?php endif; ?>
                </p>
                <ul>
                    <li>Keep the invoice from your ShopInnKart order; it is the proof of purchase a service centre asks for.</li>
                    <li>Warranty covers manufacturing defects only. Physical damage, liquid damage and unauthorised repairs are excluded.</li>
                    <li>Dead-on-arrival units reported within 48 hours of delivery are replaced by us, not the service centre.</li>
                    <?php if (!empty($detail['manufacturer'])): ?>
                        <li>Manufactured / marketed by <?= e($detail['manufacturer']) ?>.</li>
                    <?php endif; ?>
                    <?php if (!empty($detail['model_number'])): ?>
                        <li>Quote model number <?= e($detail['model_number']) ?> when raising a service request.</li>
                    <?php endif; ?>
                </ul>
                <p><a href="<?= e(page_url('warranty-policy')) ?>">Read the full warranty policy</a></p>
            </div>
        </div>

        <!-- ---------------------- Shipping & Returns -------------------- -->
        <div class="sik-tabpanel" role="tabpanel" id="sikPanel-shipping"
             aria-labelledby="sikTab-shipping" data-tabpanel="shipping">
            <div class="sik-prose" style="max-width:80ch">
                <h3>Shipping</h3>
                <ul>
                    <li>
                        <?= (int) $detail['free_shipping'] === 1
                            ? 'This product ships free anywhere we deliver.'
                            : 'Delivery is free on orders above ' . e(money($freeShipAt))
                              . '; below that a flat ' . e(money(setting_float('default_shipping_cost', 79))) . ' applies.' ?>
                    </li>
                    <li>Orders are packed the same working day when placed before 4 PM IST.</li>
                    <li>Use the PIN code checker in the buy box for the exact delivery date to your address.</li>
                    <li>
                        <?= (int) $detail['cod_available'] === 1 && setting_bool('cod_enabled', true)
                            ? 'Cash on Delivery is available on this product.'
                            : 'This product is prepaid only and cannot be ordered with Cash on Delivery.' ?>
                    </li>
                </ul>

                <h3>Returns</h3>
                <ul>
                    <li><?= (int) $returnDays ?> days from delivery to raise a return or replacement.</li>
                    <li>The item must be unused, with all accessories, freebies and the original packaging.</li>
                    <li>Pickup is arranged from your address; refunds land back on the original payment method.</li>
                </ul>
                <p>
                    <a href="<?= e(page_url('shipping-policy')) ?>">Shipping policy</a>
                    &nbsp;&middot;&nbsp;
                    <a href="<?= e(page_url('return-policy')) ?>">Return policy</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php
// Related products and frequently-bought-together are widget rows in the
// product_bottom zone, so the store owner controls both from the admin.
// Rendered inline even when the row is marked lazy: the lazy shell fetches by
// section key alone, which would drop the product context these widgets need.
render_zone('product_bottom', [
    'product_id'  => $productId,
    'category_id' => $categoryId,
    'force'       => true,
]);

$recentlyViewed = recently_viewed_products(8, $productId);
if ($recentlyViewed !== []):
?>
<section class="sik-section">
    <div class="sik-container">
        <div class="sik-heading sik-heading--row">
            <div>
                <h2 class="sik-heading__title">RECENTLY <span class="sik-heading__accent">VIEWED</span></h2>
            </div>
            <a class="sik-viewall" href="<?= e(url('shop.php')) ?>">
                Browse all <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
            </a>
        </div>
        <?= product_grid($recentlyViewed, [], 'compact') ?>
    </div>
</section>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
