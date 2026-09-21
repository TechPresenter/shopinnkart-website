<?php
/**
 * ShopInnKart - Shopping cart.
 *
 * Quantities, prices and totals are recomputed from the database on every
 * render, so nothing the browser sends can change what is charged. The POST
 * handlers below keep the page usable with JavaScript disabled; cart.js
 * intercepts the very same controls and calls /api/cart/* when it is loaded.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

// ---------------------------------------------------------------------------
// No-JS fallbacks
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    $action = (string) input('action', '');
    $itemId = input_int('item_id');

    switch ($action) {
        case 'set_qty':
        case 'qty_up':
        case 'qty_down':
            $quantity = max(0, input_int('quantity', 1));
            if ($action === 'qty_up') {
                $quantity++;
            } elseif ($action === 'qty_down') {
                $quantity--;
            }
            $result = cart_update($itemId, $quantity);
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'remove_combo':
            // One control removes the set. Removing its lines one at a time
            // would leave a part-set priced as loose products.
            $removedCombo = cart_remove_combo((string) input('combo_group', ''));
            flash($removedCombo ? 'success' : 'error', $removedCombo ? 'Combo removed.' : 'That combo is no longer in your cart.');
            redirect(url('cart.php'));
            break;

        case 'remove':
            $removed = cart_remove($itemId);
            flash($removed ? 'success' : 'error', $removed ? 'Item removed.' : 'That item is no longer in your cart.');
            break;

        case 'apply_coupon':
            $result = cart_apply_coupon((string) input('code', ''));
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'remove_coupon':
            cart_remove_coupon();
            flash('success', 'Coupon removed.');
            break;
    }

    // Checkout posts its coupon form here when JavaScript is off, because
    // checkout.php's own POST places the order. Only these two destinations
    // are accepted, so the parameter cannot become an open redirect.
    $returnTo = (string) input('return', '');
    redirect(url($returnTo === 'checkout' ? 'checkout.php' : 'cart.php'));
}

seo_from_page('cart');
seo_set([
    'robots'    => 'noindex, follow',
    'canonical' => url('cart.php'),
]);

$items = cart_items();

// Stock can fall after an item is added. Persist the cap now so checkout does
// not later refuse a quantity the shelf can no longer cover, and say so.
$reducedLines = [];
$outOfStockLines = [];
foreach ($items as $item) {
    if ($item['quantity'] <= 0) {
        $outOfStockLines[] = $item;
    } elseif ($item['requested_qty'] > $item['quantity']) {
        cart_update($item['item_id'], $item['quantity']);
        $reducedLines[] = $item;
    }
}

$totals   = cart_totals($items);
$display  = $totals['display'];
$taxLabel = (string) $totals['tax_label'];

$minOrder     = setting_float('min_order_amount', 0);
$belowMinimum = $minOrder > 0 && $totals['subtotal'] < $minOrder;
$canCheckout  = $items !== [] && $outOfStockLines === [] && !$belowMinimum;

/**
 * Coupons anybody may see. Account-locked coupons (a `user` restriction) stay
 * hidden; the rest are checked against this cart so only the ones that would
 * actually apply get a one-click button.
 */
// Offers the store switched on for the cart (Marketing > Offers & Coupons >
// "Show as an offer"). Each one is still run through validate_coupon() against
// this cart, so the list says plainly which apply now and what the others need.
// It used to list every active coupon, advertised or not.
$offerCoupons = [];
if ($items !== []) {
    foreach (public_offers('cart', null, 8) as $coupon) {
        if ($totals['coupon_code'] !== null && strcasecmp((string) $coupon['code'], (string) $totals['coupon_code']) === 0) {
            continue;
        }
        $check = validate_coupon((string) $coupon['code'], (float) $totals['subtotal'], $items);
        $offerCoupons[] = $coupon + [
            'eligible' => (bool) $check['ok'],
            'reason'   => (string) $check['message'],
            'saving'   => (float) $check['discount'],
        ];
    }
}

require INCLUDES_PATH . '/header.php';
?>

<?php // data-cart-page tells cart.js it is on the full page, where emptying the
      // cart reloads into the server-rendered empty state. ?>
<div class="sik-container sik-section sik-section--sm sik-cartpage" data-cart-page>

    <?= breadcrumbs([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'Cart'],
    ]) ?>

    <header class="sik-pagehead">
        <h1 class="sik-pagehead__title">Your cart</h1>
        <?php if ($items !== []): ?>
            <p class="sik-pagehead__meta">
                <span data-cart-units><?= (int) $totals['units'] ?> item<?= $totals['units'] === 1 ? '' : 's' ?></span>
            </p>
        <?php endif; ?>
    </header>

<?php if ($items === []): ?>
    <div class="sik-empty">
        <?= icon('bag', 'w-12 h-12') ?>
        <h2 class="sik-empty__title">Your cart is empty</h2>
        <p class="sik-empty__text">Find something to light up your space. Anything you add will wait for you here.</p>
        <div class="sik-empty__actions">
            <a class="sik-btn sik-btn--primary sik-btn--lg" href="<?= e(url('shop.php')) ?>">Start shopping</a>
            <?php if (wishlist_shows_on('header') && wishlist_count() > 0): ?>
                <a class="sik-btn sik-btn--outline sik-btn--lg" href="<?= e(url('wishlist.php')) ?>">
                    <?= icon('heart', 'w-4 h-4') ?> Saved items (<?= (int) wishlist_count() ?>)
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

    <?php if (!empty($totals['coupon_message']) || $reducedLines !== [] || $outOfStockLines !== [] || $belowMinimum): ?>
        <div class="sik-cartpage__alerts">
            <?php if (!empty($totals['coupon_message'])): ?>
                <div class="sik-alert sik-alert--warning">
                    <?= icon('tag', 'w-4 h-4') ?>
                    <span>Your coupon was removed: <?= e((string) $totals['coupon_message']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($reducedLines !== []): ?>
                <div class="sik-alert sik-alert--warning">
                    <?= icon('alert', 'w-4 h-4') ?>
                    <span>
                        Stock changed while these were in your cart, so the quantity was reduced:
                        <?php foreach ($reducedLines as $index => $line): ?>
                            <?= $index > 0 ? '; ' : '' ?><strong><?= e($line['name']) ?></strong>
                            (<?= (int) $line['requested_qty'] ?> &rarr; <?= (int) $line['quantity'] ?>)
                        <?php endforeach; ?>.
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($outOfStockLines !== []): ?>
                <div class="sik-alert sik-alert--error" id="sikCartBlocked">
                    <?= icon('alert', 'w-4 h-4') ?>
                    <span>
                        <?php foreach ($outOfStockLines as $index => $line): ?>
                            <?= $index > 0 ? ', ' : '' ?><strong><?= e($line['name']) ?></strong>
                        <?php endforeach; ?>
                        <?= count($outOfStockLines) === 1 ? 'is' : 'are' ?> out of stock.
                        Remove <?= count($outOfStockLines) === 1 ? 'it' : 'them' ?> to check out.
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($belowMinimum): ?>
                <div class="sik-alert sik-alert--info" id="sikCartMinimum">
                    <?= icon('info', 'w-4 h-4') ?>
                    <span>The minimum order is <?= e(money($minOrder)) ?>.
                        Add <?= e(money($minOrder - (float) $totals['subtotal'])) ?> more to check out.</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="sik-cartpage__layout">

        <!-- ========================= Line items ========================= -->
        <section aria-label="Items in your cart">
            <div class="sik-cartlist">
                <?php
                /* A combo occupies one card; everything else keeps its own.
                   cart_combo_groups() decides what is still a whole set - a
                   line edited down to fewer than the recipe needs stops being
                   one, and falls through to the loose list below. */
                $comboGroups = cart_combo_groups($items);
                $inCombo     = [];
                foreach ($comboGroups as $comboGroup) {
                    foreach ($comboGroup['line_ids'] as $comboLineId) {
                        $inCombo[$comboLineId] = true;
                    }
                }
                ?>

                <?php foreach ($comboGroups as $comboGroup): ?>
                    <?php $comboData = $comboGroup['combo']; ?>
                    <form method="post" action="<?= e(url('cart.php')) ?>" class="sik-cartline sik-cartline--combo">
                        <?= csrf_field() ?>
                        <input type="hidden" name="combo_group" value="<?= e_attr($comboGroup['group']) ?>">

                        <a class="sik-cartline__media" href="<?= e($comboData['url']) ?>" tabindex="-1" aria-hidden="true">
                            <img src="<?= e($comboData['image_url']) ?>" alt="" width="112" height="112" loading="lazy">
                        </a>

                        <div class="sik-cartline__body">
                            <div class="sik-cartline__top">
                                <div class="sik-cartline__info">
                                    <span class="sik-gchip sik-cartline__combochip"><?= icon('package', 'w-3.5 h-3.5') ?> Combo</span>
                                    <a class="sik-cartline__name" href="<?= e($comboData['url']) ?>"><?= e($comboData['name']) ?></a>
                                    <p class="sik-cartline__meta">
                                        <?= (int) $comboGroup['sets'] ?> <?= (int) $comboGroup['sets'] === 1 ? 'set' : 'sets' ?>
                                        &middot; <?= count($comboGroup['lines']) ?> products
                                    </p>
                                    <?php // What the set contains, so the card is not a black box. ?>
                                    <ul class="sik-cartline__combolist">
                                        <?php foreach ($comboGroup['lines'] as $comboLine): ?>
                                            <li>
                                                <img src="<?= e($comboLine['image_url']) ?>" alt="" width="28" height="28" loading="lazy">
                                                <span><?= e($comboLine['name']) ?></span>
                                                <b>&times;<?= (int) $comboLine['quantity'] ?></b>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <div class="sik-cartline__prices">
                                    <span class="sik-cartline__total"><?= e(money($comboGroup['price'])) ?></span>
                                    <span class="sik-cartline__was"><?= e(money($comboGroup['regular'])) ?></span>
                                    <span class="sik-cartline__saved">You save <?= e(money($comboGroup['saving'])) ?></span>
                                </div>
                            </div>

                            <div class="sik-cartline__actions">
                                <?php // No stepper: changing a set's size means re-adding it from
                                      // the combo page, where the per-order cap and the stock for
                                      // every component are checked together. ?>
                                <a class="sik-cartline__max" href="<?= e($comboData['url']) ?>">Change quantity</a>

                                <button type="submit" name="action" value="remove_combo" class="sik-cartline__remove"
                                        aria-label="Remove <?= e_attr($comboData['name']) ?> from cart">
                                    <?= icon('trash', 'w-4 h-4') ?><span class="sik-btn__label">Remove</span>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endforeach; ?>

                <?php foreach ($items as $item): ?>
                    <?php if (isset($inCombo[(int) $item['item_id']])) { continue; } ?>
                    <?php
                    $lineId   = (int) $item['item_id'];
                    $maxQty   = max(1, (int) $item['max_qty']);
                    $isOut    = (int) $item['quantity'] <= 0;
                    $savedPct = discount_percent($item['mrp'], $item['price']);
                    ?>
                    <?php // One form per line, so every control still works without JavaScript. ?>
                    <form method="post" action="<?= e(url('cart.php')) ?>"
                          class="sik-cartline<?= $isOut ? ' is-out' : '' ?>" data-cart-row="<?= $lineId ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= $lineId ?>">

                        <a class="sik-cartline__media" href="<?= e($item['url']) ?>" tabindex="-1" aria-hidden="true">
                            <img src="<?= e($item['image_url']) ?>" alt="" width="112" height="112" loading="lazy">
                        </a>

                        <div class="sik-cartline__body">
                            <div class="sik-cartline__top">
                                <div class="sik-cartline__info">
                                    <a class="sik-cartline__name" href="<?= e($item['url']) ?>"><?= e($item['name']) ?></a>
                                    <?php if (!empty($item['variant_name'])): ?>
                                        <p class="sik-cartline__meta"><?= e($item['variant_name']) ?></p>
                                    <?php endif; ?>
                                    <p class="sik-cartline__each">
                                        <span class="sik-num"><?= e($item['price_display']) ?></span>
                                        <?php if ((float) $item['mrp'] > (float) $item['price']): ?>
                                            <span class="sik-price--mrp"><?= e($item['mrp_display']) ?></span>
                                            <span class="sik-price--off"><?= (int) $savedPct ?>% off</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="sik-cartline__total" data-line-subtotal><?= e($item['subtotal_display']) ?></span>
                            </div>

                            <?php if ($isOut): ?>
                                <p class="sik-cartline__warn is-error"><?= icon('alert', 'w-4 h-4') ?> Out of stock</p>
                            <?php elseif ($item['stock_state'] === STOCK_LOW): ?>
                                <p class="sik-cartline__warn"><?= icon('clock', 'w-4 h-4') ?> Only <?= (int) $item['stock'] ?> left</p>
                            <?php endif; ?>

                            <div class="sik-cartline__controls">
                                <?php if (!$isOut): ?>
                                    <button type="submit" name="action" value="set_qty" class="sik-sr" tabindex="-1">Update quantity</button>
                                    <div class="sik-qty">
                                        <button type="submit" name="action" value="qty_down"
                                                data-qty-minus aria-label="Decrease quantity of <?= e($item['name']) ?>">
                                            <?= icon('minus', 'w-4 h-4') ?>
                                        </button>
                                        <label class="sik-sr" for="sikQty<?= $lineId ?>">Quantity of <?= e($item['name']) ?></label>
                                        <input type="number" id="sikQty<?= $lineId ?>" name="quantity"
                                               data-cart-qty data-item-id="<?= $lineId ?>"
                                               value="<?= (int) $item['quantity'] ?>" min="1" max="<?= $maxQty ?>" step="1"
                                               inputmode="numeric">
                                        <button type="submit" name="action" value="qty_up"
                                                data-qty-plus aria-label="Increase quantity of <?= e($item['name']) ?>">
                                            <?= icon('plus', 'w-4 h-4') ?>
                                        </button>
                                    </div>
                                    <?php if ($maxQty < 10): ?>
                                        <span class="sik-cartline__max">Max <?= $maxQty ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <button type="submit" name="action" value="remove" class="sik-cartline__remove"
                                        data-cart-remove data-item-id="<?= $lineId ?>"
                                        aria-label="Remove <?= e($item['name']) ?> from cart">
                                    <?= icon('trash', 'w-4 h-4') ?><span class="sik-btn__label">Remove</span>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>

            <a class="sik-cartpage__back" href="<?= e(url('shop.php')) ?>">
                <?= icon('arrow-left', 'w-4 h-4') ?> Continue shopping
            </a>
        </section>

        <!-- ========================== Summary =========================== -->
        <aside class="sik-checkout__aside">
            <div class="sik-summarycard">
                <h2 class="sik-summarycard__title">Order summary</h2>

                <div class="sik-shipnote" data-ship-bar<?= (float) $totals['free_ship_remaining'] <= 0 ? ' hidden' : '' ?>>
                    Add <strong data-ship-remaining><?= e($display['remaining']) ?></strong> more for free delivery
                    <div class="sik-progress">
                        <span data-ship-progress style="width:<?= (int) $totals['free_ship_progress'] ?>%"></span>
                    </div>
                </div>
                <div class="sik-shipnote sik-shipnote--done" data-ship-done<?= (float) $totals['free_ship_remaining'] > 0 ? ' hidden' : '' ?>>
                    <?= icon('truck', 'w-4 h-4') ?> <span>Free delivery on this order</span>
                </div>

                <div class="sik-summary">
                    <div class="sik-summary__row">
                        <span>Subtotal</span>
                        <span data-total="subtotal"><?= e($display['subtotal']) ?></span>
                    </div>
                    <div class="sik-summary__row" data-total-row="discount"<?= (float) $totals['discount'] > 0 ? '' : ' hidden' ?>>
                        <span>
                            Discount
                            <span class="sik-tag" data-coupon-badge<?= $totals['coupon_code'] !== null ? '' : ' hidden' ?>><?= e((string) ($totals['coupon_code'] ?? '')) ?></span>
                        </span>
                        <span style="color:var(--sik-success-ink)" data-total="discount">- <?= e($display['discount']) ?></span>
                    </div>
                    <div class="sik-summary__row">
                        <span>Delivery</span>
                        <span data-total="shipping"><?= e($display['shipping']) ?></span>
                    </div>
                    <div class="sik-summary__row" data-total-row="tax"<?= (float) $totals['tax'] > 0 ? '' : ' hidden' ?>>
                        <span><?= $totals['tax_inclusive'] ? 'Includes ' . e($taxLabel) : e($taxLabel) ?></span>
                        <span data-total="tax"><?= e($display['tax']) ?></span>
                    </div>
                    <div class="sik-summary__row sik-summary__row--total">
                        <span>Total</span>
                        <span data-total="total"><?= e($display['total']) ?></span>
                    </div>
                    <div class="sik-summary__row sik-summary__row--save" data-total-row="saving"<?= (float) $totals['total_saving'] > 0 ? '' : ' hidden' ?>>
                        <span>You save</span>
                        <span data-total="saving"><?= e($display['saving']) ?></span>
                    </div>
                </div>

                <div class="sik-summarycard__cta">
                    <?php if ($canCheckout): ?>
                        <a class="sik-btn sik-btn--primary sik-btn--lg sik-btn--block" href="<?= e(url('checkout.php')) ?>">
                            Checkout <?= icon('arrow-right', 'w-4 h-4') ?>
                        </a>
                    <?php else: ?>
                        <button type="button" class="sik-btn sik-btn--primary sik-btn--lg sik-btn--block" disabled
                                aria-describedby="<?= $outOfStockLines !== [] ? 'sikCartBlocked' : 'sikCartMinimum' ?>">
                            Checkout
                        </button>
                    <?php endif; ?>
                    <?php if ($totals['tax_inclusive']): ?>
                        <p class="sik-summarycard__note">Prices include <?= e($taxLabel) ?>.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php $hasCoupon = $totals['coupon_code'] !== null; ?>
            <div class="sik-summarycard" data-coupon-panel>
                <h2 class="sik-summarycard__title">Coupons &amp; offers</h2>

                <div data-coupon-applied<?= $hasCoupon ? '' : ' hidden' ?>>
                    <form method="post" action="<?= e(url('cart.php')) ?>" class="sik-coupon__on">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_coupon">
                        <span class="min-w-0">
                            <strong>
                                <?= icon('check-circle', 'w-4 h-4') ?>
                                <span data-coupon-code><?= e((string) ($totals['coupon_code'] ?? '')) ?></span> applied
                            </strong>
                            <span class="sik-coupon__saving">
                                You save <span data-coupon-saving><?= e($display['discount']) ?></span> on this order
                            </span>
                        </span>
                        <button type="submit" class="sik-btn sik-btn--ghost sik-btn--sm" data-coupon-remove>
                            <span class="sik-btn__label">Remove</span>
                        </button>
                    </form>
                </div>

                <form method="post" action="<?= e(url('cart.php')) ?>" data-coupon-form
                      class="sik-couponform"<?= $hasCoupon ? ' hidden' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_coupon">
                    <label class="sik-sr" for="sikCouponCode">Coupon code</label>
                    <input type="text" class="sik-input" id="sikCouponCode" name="code"
                           placeholder="Coupon code" autocomplete="off" maxlength="60" required>
                    <button type="submit" class="sik-btn sik-btn--navy">
                        <span class="sik-btn__label">Apply</span>
                    </button>
                </form>

                <?php if ($offerCoupons !== []): ?>
                    <?php // Offers the store switched on for the cart. One coupon applies at a
                          // time, so the list steps aside while one is on. ?>
                    <div class="sik-coupon__offers" data-coupon-offers<?= $hasCoupon ? ' hidden' : '' ?>>
                        <p class="sik-coupon__label">Available for this order</p>
                        <?php foreach ($offerCoupons as $offer): ?>
                            <div class="sik-offer<?= $offer['eligible'] ? '' : ' is-locked' ?>">
                                <span class="sik-offer__icon" aria-hidden="true">
                                    <?= icon((string) $offer['type'] === COUPON_TYPE_FREE_SHIPPING ? 'truck' : 'tag', 'w-4 h-4') ?>
                                </span>
                                <span class="sik-offer__body">
                                    <span class="sik-offer__title"><?= e((string) $offer['headline']) ?></span>
                                    <span class="sik-offer__terms">
                                        <?= e($offer['eligible'] ? ((string) $offer['terms'] !== '' ? (string) $offer['terms'] : 'Tap to apply') : (string) $offer['reason']) ?>
                                    </span>
                                </span>
                                <?php if ($offer['eligible']): ?>
                                    <form method="post" action="<?= e(url('cart.php')) ?>" class="sik-offer__form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="apply_coupon">
                                        <input type="hidden" name="code" value="<?= e((string) $offer['code']) ?>">
                                        <button type="submit" class="sik-offer__code" data-coupon-apply="<?= e((string) $offer['code']) ?>"
                                                aria-label="Apply code <?= e((string) $offer['code']) ?>">
                                            <?= e((string) $offer['code']) ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="sik-offer__code is-disabled" aria-hidden="true"><?= e((string) $offer['code']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
<?php endif; ?>
</div>

<?php // Admin > Homepage Builder > Cart zone (recommendations). ?>
<?php render_zone('cart'); ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
