<?php
/**
 * ShopInnKart - Combo detail page.
 *
 * Served as /combo/<slug> by the rewrite in .htaccess, which hands the slug
 * over as ?slug=. Every number on this page comes from combo_decorate(); the
 * only thing the browser gets to choose is how many sets it wants, and
 * cart_add_combo() clamps even that against the same ceiling printed here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// Named $combo, not $product: includes/navbar.php reuses $product as a loop
// variable at global scope, so anything called $product dies at the header.
$slug  = trim((string) input('slug', ''));
$combo = $slug === '' ? null : combo_find($slug);

// combo_find() has already refused anything a shopper may not see. The second
// test is the one that matters here: a set that has lost a component, run out
// of stock or stopped being cheaper than its parts must not be reachable by
// URL after it has disappeared from /combos.
$items = $combo === null ? [] : combo_items((int) $combo['id']);
if ($combo === null || !combo_is_sellable($combo, $items)) {
    require __DIR__ . '/404.php';
    exit;
}

$comboId = (int) $combo['id'];
$combo   = combo_decorate($combo, $items);
$pricing = $combo['pricing'];

// Counted once per session, the way record_product_view() counts products: a
// refresh is not a second view.
$seenCombos = $_SESSION['_viewed_combos'] ?? [];
if (!in_array($comboId, $seenCombos, true)) {
    Database::query('UPDATE `combos` SET `views` = `views` + 1 WHERE `id` = :id', ['id' => $comboId]);
    $seenCombos[] = $comboId;
    $_SESSION['_viewed_combos'] = array_slice($seenCombos, -50);
}

// ---------------------------------------------------------------------------
//  Gallery
// ---------------------------------------------------------------------------
$gallery = [];
if (trim((string) ($combo['image'] ?? '')) !== '') {
    $gallery[] = ['image' => (string) $combo['image'], 'alt_text' => (string) $combo['name']];
}
foreach (Database::fetchAll(
    'SELECT `image`, `alt_text` FROM `combo_images` WHERE `combo_id` = :id ORDER BY `sort_order`, `id`',
    ['id' => $comboId]
) as $galleryRow) {
    $gallery[] = $galleryRow;
}

// The banner is a wide crop, so it goes last: it is worth having in the strip
// but it is the worst of the set inside a square frame.
if (trim((string) ($combo['banner'] ?? '')) !== '') {
    $gallery[] = ['image' => (string) $combo['banner'], 'alt_text' => (string) $combo['name']];
}
if ($gallery === []) {
    // img_url(null) resolves to the shared placeholder, so the frame is never empty.
    $gallery[] = ['image' => null, 'alt_text' => (string) $combo['name']];
}

// ---------------------------------------------------------------------------
//  Buy box
// ---------------------------------------------------------------------------
// The same ceiling cart_add_combo() applies server-side. Printing a different
// one is how a shopper asks for four sets, is silently given two, and finds the
// basket disagreeing with the page they bought from.
$maxSets = max(1, min((int) $combo['available'], max(1, (int) $combo['max_per_order'])));

// Admin descriptions arrive as rich text or as plain paragraphs; keep both readable.
$description     = trim((string) ($combo['description'] ?? ''));
$descriptionHtml = $description === ''
    ? ''
    : (strip_tags($description) === $description ? nl2br(e($description)) : sanitize_html($description));

// ---------------------------------------------------------------------------
//  Breadcrumbs & SEO
// ---------------------------------------------------------------------------
$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Combo Offers', 'url' => url('combos')],
    ['label' => (string) $combo['name'], 'url' => $combo['url']],
];

$schemaImages = [];
foreach ($gallery as $galleryImage) {
    $schemaImages[] = img_url($galleryImage['image']);
}

seo_set([
    'canonical' => $combo['url'],
    'og_type'   => 'product',
]);
seo_from_entity($combo, [
    'title'       => (string) $combo['name'],
    'description' => str_limit((string) ($combo['subtitle'] ?: strip_tags($description)), 260, ''),
    'og_image'    => (string) ($combo['og_image'] ?: $combo['image'] ?: $combo['banner']),
], 'combo');

// A combo is one purchasable thing at one price, so it is a Product with one
// Offer - and the Offer carries `price`, never `regular`, because that figure
// is a comparison and not something anyone can buy the set for.
seo_add_schema([
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => (string) $combo['name'],
    'image'       => $schemaImages,
    'description' => str_limit((string) ($combo['subtitle'] ?: strip_tags($description)), 400, ''),
    'url'         => $combo['url'],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => $combo['url'],
        'priceCurrency' => (string) setting('currency_code', CURRENCY),
        'price'         => number_format((float) $pricing['price'], 2, '.', ''),
        'availability'  => (int) $combo['available'] > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller'        => ['@type' => 'Organization', 'name' => (string) setting('store_name', SITE_NAME)],
    ],
    // What is actually in the box, each pointing at its own product page.
    'isRelatedTo' => array_map(static fn (array $part): array => [
        '@type' => 'Product',
        'name'  => (string) $part['name'],
        'url'   => (string) $part['url'],
    ], $combo['items']),
]);
seo_add_schema(seo_breadcrumb_schema($crumbs));

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container sik-section sik-section--sm">

    <?= breadcrumbs($crumbs) ?>

    <div class="sik-pdp" style="margin-top:var(--sp-5)">

        <!-- =========================== Gallery =========================== -->
        <div data-gallery>
            <div style="position:relative">
                <div class="sik-gallery__main" data-gallery-main>
                    <img src="<?= e(img_url($gallery[0]['image'])) ?>"
                         alt="<?= e($gallery[0]['alt_text'] ?: $combo['name']) ?>"
                         width="720" height="720" fetchpriority="high" decoding="async">
                </div>

                <?php if ((int) $combo['percent'] > 0): ?>
                    <span class="sik-badge sik-badge--orange" style="position:absolute;top:12px;left:12px;z-index:2">
                        <?= (int) $combo['percent'] ?>% OFF
                    </span>
                <?php endif; ?>

                <?php // Kept outside [data-gallery-main] so expanding does not also trigger the zoom toggle. ?>
                <button type="button" class="sik-iconbtn" data-gallery-expand
                        style="position:absolute;top:12px;right:12px;z-index:2"
                        aria-label="View <?= e($combo['name']) ?> full screen">
                    <?= icon('search', 'w-4 h-4') ?>
                </button>
            </div>

            <?php if (count($gallery) > 1): ?>
                <div class="sik-gallery__thumbs">
                    <?php foreach ($gallery as $index => $galleryImage): ?>
                        <button type="button"
                                class="sik-gallery__thumb <?= $index === 0 ? 'is-active' : '' ?>"
                                data-gallery-thumb="<?= e(img_url($galleryImage['image'])) ?>"
                                data-gallery-alt="<?= e($galleryImage['alt_text'] ?: $combo['name']) ?>"
                                aria-label="View image <?= $index + 1 ?> of <?= count($gallery) ?>">
                            <img src="<?= e(img_url($galleryImage['image'])) ?>"
                                 alt="<?= e($galleryImage['alt_text'] ?: $combo['name']) ?>"
                                 width="72" height="72" loading="lazy" decoding="async">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================== Buy box ============================ -->
        <div>
            <?php if ($combo['badge'] !== null): ?>
                <span class="sik-badge sik-badge--<?= e_attr($combo['badge']['tone']) ?>">
                    <?= e($combo['badge']['label']) ?>
                </span>
            <?php endif; ?>

            <h1 class="sik-pdp__title" style="margin-top:var(--sp-2)"><?= e($combo['name']) ?></h1>

            <?php if (trim((string) $combo['subtitle']) !== ''): ?>
                <p style="font-size:14px;line-height:1.7;color:var(--sik-muted);margin-top:var(--sp-2)">
                    <?= e($combo['subtitle']) ?>
                </p>
            <?php endif; ?>

            <div class="sik-pdp__meta" style="margin-top:var(--sp-3);align-items:center">
                <span>
                    <?= (int) $combo['item_count'] ?> product<?= (int) $combo['item_count'] === 1 ? '' : 's' ?>
                </span>
                <span>
                    <?= (int) $combo['unit_count'] ?> item<?= (int) $combo['unit_count'] === 1 ? '' : 's' ?> in the box
                </span>
                <?php if ((int) $combo['sold_count'] > 0): ?>
                    <span><?= (int) $combo['sold_count'] ?> sold</span>
                <?php endif; ?>
            </div>

            <?php // The struck-through figure is `regular` - what these same products cost
                  // TODAY bought one at a time - and never `mrp`. Most of this catalogue is
                  // already discounted, so an MRP comparison would advertise a saving the
                  // shopper could get anyway by adding the items separately. ?>
            <div class="sik-pdp__price">
                <span class="sik-price"><?= e($combo['price_display']) ?></span>
                <span class="sik-price--mrp"><?= e($combo['regular_display']) ?></span>
                <?php // Hidden at 0 the way product.php hides its own, and the way the gallery
                      // badge above already is: combo_is_sellable() only guarantees saving > 0,
                      // so a few rupees off a four-figure set rounds to 0% and would print
                      // "0% OFF" beside a real struck-through price. ?>
                <?php if ((int) $combo['percent'] > 0): ?>
                    <span class="sik-price--off"><?= (int) $combo['percent'] ?>% OFF</span>
                <?php endif; ?>
            </div>

            <div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                <span class="sik-status sik-status--<?= !empty($combo['low_stock']) ? 'amber' : 'green' ?>">
                    <?php if (!empty($combo['low_stock'])): ?>
                        Only <?= (int) $combo['available'] ?> set<?= (int) $combo['available'] === 1 ? '' : 's' ?> left
                    <?php else: ?>
                        In stock
                    <?php endif; ?>
                </span>
                <span style="font-size:13px;font-weight:600;color:var(--sik-success-ink)">
                    You save <?= e($combo['saving_display']) ?> against buying these separately
                </span>
                <span style="font-size:12px;color:var(--sik-muted)">Inclusive of all taxes</span>
            </div>

            <!-- ----------------------- In this combo ---------------------- -->
            <div class="sik-panel" style="margin-bottom:var(--sp-5)">
                <div class="sik-panel__head">
                    <span class="sik-panel__title"><?= icon('package', 'w-4 h-4') ?> In this combo</span>
                </div>
                <div class="sik-panel__body">
                    <ul style="display:grid;gap:var(--sp-3);margin:0;padding:0;list-style:none">
                        <?php foreach ($combo['items'] as $item): ?>
                            <li>
                                <a href="<?= e($item['url']) ?>"
                                   style="display:flex;align-items:center;gap:var(--sp-3);color:inherit">
                                    <img src="<?= e($item['image_url']) ?>" alt="" width="56" height="56" loading="lazy"
                                         style="width:56px;height:56px;object-fit:contain;flex:none;background:var(--sik-media);border:1px solid var(--sik-line);border-radius:var(--sik-radius-sm)">
                                    <span style="min-width:0;flex:1">
                                        <span style="display:block;font-size:14px;font-weight:600;color:var(--sik-ink)">
                                            <?= e($item['name']) ?>
                                        </span>
                                        <span style="font-size:12px;color:var(--sik-muted)">
                                            Qty <?= (int) $item['quantity'] ?><?php
                                            // The unit price is only worth the room when it is not
                                            // simply the line price repeated.
                                            if ((int) $item['quantity'] > 1): ?>
                                                &times; <?= e(money((float) $item['unit_price'])) ?> each
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span style="margin-left:auto;font-size:14px;font-weight:700;color:var(--sik-ink);white-space:nowrap">
                                        <?= e(money((float) $item['line_price'])) ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div style="display:flex;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-4);padding-top:var(--sp-3);border-top:1px solid var(--sik-line);font-size:13px">
                        <span style="color:var(--sik-muted)">Bought separately today</span>
                        <strong style="color:var(--sik-ink)"><?= e($combo['regular_display']) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-2);font-size:13px">
                        <span style="color:var(--sik-muted)">As a combo</span>
                        <strong style="color:var(--sik-success-ink)"><?= e($combo['price_display']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- ------------------------ Description ----------------------- -->
            <?php if ($descriptionHtml !== ''): ?>
                <div class="sik-prose" style="margin-bottom:var(--sp-5)"><?= $descriptionHtml ?></div>
            <?php endif; ?>

            <!-- ------------------------- Buy control ---------------------- -->
            <form data-combo-add>
                <?= csrf_field() ?>
                <input type="hidden" name="combo_id" value="<?= $comboId ?>">

                <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                    <span style="font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em">Sets</span>
                    <div class="sik-qty">
                        <button type="button" data-qty-minus aria-label="Decrease number of sets"><?= icon('minus', 'w-4 h-4') ?></button>
                        <input type="number" id="sikComboQty" name="quantity" value="1" min="1" max="<?= $maxSets ?>"
                               step="1" inputmode="numeric" aria-label="Number of sets">
                        <button type="button" data-qty-plus aria-label="Increase number of sets"><?= icon('plus', 'w-4 h-4') ?></button>
                    </div>
                    <span style="font-size:12px;color:var(--sik-muted)">Max <?= $maxSets ?> per order</span>
                </div>

                <button type="submit" class="sik-btn sik-btn--primary sik-btn--lg sik-btn--block">
                    <?= icon('cart', 'w-5 h-5') ?><span class="sik-btn__label">Add combo to cart</span>
                </button>
            </form>

            <?php // Says only what the design actually guarantees. A combo goes into the
                  // basket as its component lines under one combo_group, so shipping and
                  // returns run the paths that already handle those products - it makes no
                  // promise about warranty, which is a per-product matter and not this
                  // page's to make. ?>
            <p class="sik-help" style="margin-top:var(--sp-3)">
                The set is added as its individual products under one combo line, so delivery and
                returns work exactly as they do when you buy them on their own.
            </p>
        </div>
    </div>
</div>

<script>
/* cart.js owns Cart.add() for products; a combo posts a combo id and a number
   of sets instead, so this page drives its own submit. Bound natively rather
   than through SIK.on(): every script in the footer is deferred, so window.SIK
   does not exist yet while this tag is being parsed - only by the time an
   actual submit fires. */
document.addEventListener('submit', async function (event) {
    var form = event.target.closest('[data-combo-add]');
    if (!form) return;

    // Prevented before the SIK guard: this form has no action, so letting it
    // submit natively would reload the page with the token in the query string
    // and add nothing to the basket.
    event.preventDefault();
    if (!window.SIK) return;

    var button = form.querySelector('button[type="submit"]');
    if (button && !SIK.showLoader(button)) return;

    // Straight off the form, so the CSRF token, the combo id and the quantity
    // are exactly the fields PHP rendered - and the quantity is whatever the
    // stepper clamped it to against the max the server will also enforce.
    var result = await SIK.post('cart/add-combo.php', Object.fromEntries(new FormData(form).entries()));

    if (button) SIK.hideLoader(button);

    if (!result.success) {
        SIK.toast(result.message || 'Could not add this combo to your cart.', 'error');
        return;
    }

    // One payload, every cart surface: the header count, the totals and the
    // drawer, the same way cart.js applies its own.
    if (SIK.cart) SIK.cart.apply(result.data);

    SIK.toast(result.message || 'Combo added to your cart', 'success', {
        action: { label: 'View cart', href: SIK.config.baseUrl + '/cart.php' }
    });

    if (result.data && result.data.open_drawer !== false) SIK.openDrawer('sikCartDrawer');
});
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
