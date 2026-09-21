<?php
/**
 * ShopInnKart - Order confirmation.
 *
 * An order number alone is guessable, so the page is shown only to the
 * customer who owns the order or to the session that just placed it.
 * Anyone else is sent to public tracking, which asks for a matching email
 * or phone number before it reveals anything.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

$orderNumber = trim((string) input('order', ''));
$order = $orderNumber === '' ? null : get_order_by_number($orderNumber);

if ($order === null) {
    flash('error', 'We could not find that order.');
    redirect(url('track-order.php'));
}

$userId = current_user_id();
$owns = $userId !== null && $order['user_id'] !== null && (int) $order['user_id'] === $userId;

if (!$owns && !session_placed_order((string) $order['order_number'])) {
    flash('info', 'Please confirm the email address or mobile number on the order to view it.');
    redirect(url('track-order.php?order=' . rawurlencode((string) $order['order_number'])));
}

$orderId  = (int) $order['id'];
$items    = get_order_items($orderId);
$timeline = order_timeline($order);
$status   = (string) $order['status'];
$pill     = api_order_status_pill($status);

$paymentName = (string) (Database::fetchColumn(
    'SELECT `name` FROM `payment_methods` WHERE `code` = :code LIMIT 1',
    ['code' => (string) $order['payment_method']]
) ?? ucfirst(str_replace('_', ' ', (string) $order['payment_method'])));

$paymentStatus = (string) $order['payment_status'];
$isCancelled = in_array($status, STOCK_RELEASING_STATUSES, true);

$taxLabel = (string) setting('tax_label', 'GST');
$units = 0;
foreach ($items as $item) {
    $units += (int) $item['quantity'];
}

seo_set([
    'title'       => 'Order ' . $order['order_number'] . ' confirmed',
    'description' => 'Your ShopInnKart order has been placed.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';
?>

<div class="sik-container sik-section sik-section--sm">

    <div class="sik-panel" style="text-align:center">
        <div class="sik-panel__body" style="padding:var(--sp-7) var(--sp-5)">
            <span class="sik-successmark">
                <?= icon('check', '') ?>
            </span>

            <h1 style="font-size:clamp(20px,3vw,26px);font-weight:800;color:var(--sik-navy)">
                <?php if ($isCancelled): ?>
                    This order is <?= e(strtolower($pill['label'])) ?>
                <?php else: ?>
                    Thank you<?= $order['customer_name'] !== '' ? ', ' . e(explode(' ', (string) $order['customer_name'])[0]) : '' ?>. Your order is placed.
                <?php endif; ?>
            </h1>

            <p class="text-muted text-sm" style="max-width:460px;margin:var(--sp-2) auto 0;line-height:1.7">
                A confirmation has been sent to <strong><?= e((string) $order['customer_email']) ?></strong>.
                Keep the order number handy for any conversation about this order.
            </p>

            <div class="flex flex-wrap items-center justify-center gap-2" style="margin-top:var(--sp-5)">
                <span class="sik-badge sik-badge--navy" style="font-size:13px;letter-spacing:.05em">
                    <?= e((string) $order['order_number']) ?>
                </span>
                <button type="button" class="sik-iconbtn" data-copy="<?= e_attr($order['order_number']) ?>"
                        aria-label="Copy order number"><?= icon('copy', 'w-4 h-4') ?></button>
                <span class="sik-status sik-status--<?= e($pill['color']) ?>"><?= e($pill['label']) ?></span>
            </div>

            <p class="text-xs text-muted" style="margin-top:var(--sp-3)">
                Placed on <?= e(format_datetime($order['created_at'])) ?>
            </p>
        </div>
    </div>

    <!-- ============================ Key facts ============================ -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4" style="margin-top:var(--sp-5)">
        <div class="sik-panel">
            <div class="sik-panel__body">
                <span class="text-xs uppercase tracking-wide text-muted font-semibold">
                    <?= $status === ORDER_STATUS_DELIVERED ? 'Delivered on' : 'Estimated delivery' ?>
                </span>
                <p class="font-bold" style="font-size:15px;margin-top:var(--sp-2)">
                    <?php if ($isCancelled): ?>
                        Not applicable
                    <?php elseif ($status === ORDER_STATUS_DELIVERED && !empty($order['delivered_at'])): ?>
                        <?= e(format_date($order['delivered_at'])) ?>
                    <?php elseif (!empty($order['estimated_delivery'])): ?>
                        <?= e(format_date($order['estimated_delivery'], 'D, d M Y')) ?>
                    <?php else: ?>
                        We will confirm shortly
                    <?php endif; ?>
                </p>
                <p class="text-xs text-muted" style="margin-top:var(--sp-1)">
                    <?= icon('truck', 'w-3.5 h-3.5') ?>
                    <?= e(ucfirst((string) $order['shipping_method'])) ?> delivery
                </p>
            </div>
        </div>

        <div class="sik-panel">
            <div class="sik-panel__body">
                <span class="text-xs uppercase tracking-wide text-muted font-semibold">Payment</span>
                <p class="font-bold" style="font-size:15px;margin-top:var(--sp-2)"><?= e($paymentName) ?></p>
                <p class="text-xs text-muted" style="margin-top:var(--sp-1)">
                    <?= e(PAYMENT_STATUSES[$paymentStatus] ?? ucfirst($paymentStatus)) ?>
                    &middot; <?= e(money((float) $order['total_amount'])) ?>
                </p>
            </div>
        </div>

        <div class="sik-panel">
            <div class="sik-panel__body">
                <span class="text-xs uppercase tracking-wide text-muted font-semibold">Delivering to</span>
                <p class="font-bold" style="font-size:15px;margin-top:var(--sp-2)"><?= e((string) $order['shipping_name']) ?></p>
                <p class="text-xs text-muted" style="margin-top:var(--sp-1);line-height:1.6">
                    <?= e((string) $order['shipping_address']) ?><?php if (!empty($order['shipping_address2'])): ?>, <?= e((string) $order['shipping_address2']) ?><?php endif; ?><br>
                    <?= e((string) $order['shipping_city']) ?>, <?= e((string) $order['shipping_state']) ?> <?= e((string) $order['shipping_pincode']) ?>
                </p>
            </div>
        </div>
    </div>

    <div class="sik-checkout" style="margin-top:var(--sp-5)">

        <!-- =========================== Items ============================ -->
        <div style="display:grid;gap:var(--sp-5)">
            <section class="sik-panel">
                <div class="sik-panel__head">
                    <h2 class="sik-panel__title">
                        <?= e((string) count($items)) ?> <?= count($items) === 1 ? 'item' : 'items' ?>
                        &middot; <?= e((string) $units) ?> unit<?= $units === 1 ? '' : 's' ?>
                    </h2>
                </div>

                <?php if ($items === []): ?>
                    <div class="sik-empty">
                        <?= icon('package', 'w-12 h-12') ?>
                        <p class="sik-empty__title">No items recorded on this order</p>
                        <p class="sik-empty__text">Please contact support and quote the order number above.</p>
                        <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('contact.php')) ?>">Contact support</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <div class="flex items-start gap-3 p-4 border-t border-line">
                            <?php if ($item['url'] !== null): ?>
                                <a href="<?= e((string) $item['url']) ?>" class="flex-none">
                                    <img class="sik-minicart__img" src="<?= e((string) $item['image_url']) ?>"
                                         alt="<?= e((string) $item['product_name']) ?>" width="62" height="62" loading="lazy">
                                </a>
                            <?php else: ?>
                                <img class="sik-minicart__img flex-none" src="<?= e((string) $item['image_url']) ?>"
                                     alt="<?= e((string) $item['product_name']) ?>" width="62" height="62" loading="lazy">
                            <?php endif; ?>

                            <div class="flex-1 min-w-0">
                                <?php if ($item['url'] !== null): ?>
                                    <a href="<?= e((string) $item['url']) ?>" class="block font-semibold text-sm" style="color:var(--sik-navy)">
                                        <?= e((string) $item['product_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="block font-semibold text-sm"><?= e((string) $item['product_name']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($item['variant_name'])): ?>
                                    <span class="block text-xs text-muted" style="margin-top:2px"><?= e((string) $item['variant_name']) ?></span>
                                <?php endif; ?>
                                <span class="block text-xs text-muted" style="margin-top:2px">
                                    <?= e((string) $item['price_display']) ?> &times; <?= e((string) $item['quantity']) ?>
                                </span>
                            </div>

                            <span class="text-sm font-bold whitespace-nowrap"><?= e((string) $item['subtotal_display']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- ========================= Timeline ======================== -->
            <section class="sik-panel">
                <div class="sik-panel__head">
                    <h2 class="sik-panel__title">What happens next</h2>
                </div>
                <div class="sik-panel__body">
                    <div class="sik-timeline">
                        <?php foreach ($timeline as $step): ?>
                            <div class="sik-timeline__step<?= $step['done'] ? ' is-done' : '' ?><?= !empty($step['current']) ? ' is-current' : '' ?>">
                                <span class="sik-timeline__dot"><?= icon('check', 'w-3.5 h-3.5') ?></span>
                                <p class="sik-timeline__label"><?= e((string) $step['label']) ?></p>
                                <?php if (!empty($step['at'])): ?>
                                    <p class="sik-timeline__time"><?= e(format_datetime($step['at'])) ?></p>
                                <?php elseif (!empty($step['current'])): ?>
                                    <p class="sik-timeline__time">In progress</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($order['customer_note'])): ?>
                        <hr class="sik-divider">
                        <p class="text-xs uppercase tracking-wide text-muted font-semibold">Your note</p>
                        <p class="text-sm" style="margin-top:var(--sp-2);line-height:1.7"><?= e((string) $order['customer_note']) ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- ========================== Totals =========================== -->
        <aside class="sik-checkout__aside" style="display:grid;gap:var(--sp-4)">
            <div class="sik-panel">
                <div class="sik-panel__head">
                    <h2 class="sik-panel__title">Payment summary</h2>
                </div>
                <div class="sik-panel__body">
                    <div class="sik-summary">
                        <div class="sik-summary__row">
                            <span class="text-muted">Subtotal</span>
                            <span><?= e(money((float) $order['subtotal'])) ?></span>
                        </div>

                        <?php if ((float) $order['discount_amount'] > 0): ?>
                            <div class="sik-summary__row">
                                <span class="text-muted">
                                    Discount
                                    <?php if (!empty($order['coupon_code'])): ?>
                                        <span class="sik-badge sik-badge--soft"><?= e((string) $order['coupon_code']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span style="color:var(--sik-success)">- <?= e(money((float) $order['discount_amount'])) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="sik-summary__row">
                            <span class="text-muted">Delivery</span>
                            <span><?= (float) $order['shipping_amount'] > 0 ? e(money((float) $order['shipping_amount'])) : 'FREE' ?></span>
                        </div>

                        <?php if ((float) $order['tax_amount'] > 0): ?>
                            <div class="sik-summary__row">
                                <span class="text-muted">
                                    <?= setting_bool('tax_inclusive', true) ? 'Includes ' . e($taxLabel) : e($taxLabel) ?>
                                </span>
                                <span><?= e(money((float) $order['tax_amount'])) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="sik-summary__row sik-summary__row--total">
                            <span>Total paid<?= $paymentStatus === PAYMENT_STATUS_PAID ? '' : ' / payable' ?></span>
                            <span><?= e(money((float) $order['total_amount'])) ?></span>
                        </div>
                    </div>

                    <div style="display:grid;gap:var(--sp-3);margin-top:var(--sp-5)">
                        <a class="sik-btn sik-btn--primary sik-btn--block"
                           href="<?= e(url('track-order.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                            <?= icon('truck', 'w-4 h-4') ?> Track Order
                        </a>
                        <a class="sik-btn sik-btn--outline sik-btn--block"
                           href="<?= e(url('invoice.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                            <?= icon('file-text', 'w-4 h-4') ?> View Invoice
                        </a>
                        <?php if (get_invoice_for_order((int) $order['id']) !== null): ?>
                            <a class="sik-btn sik-btn--outline sik-btn--block"
                               href="<?= e(url('invoice-download.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                                <?= icon('download', 'w-4 h-4') ?> Download PDF
                            </a>
                        <?php endif; ?>
                        <?php if ($owns): ?>
                            <a class="sik-btn sik-btn--ghost sik-btn--block"
                               href="<?= e(url('order-details.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                                Order details
                            </a>
                        <?php endif; ?>
                        <a class="sik-btn sik-btn--ghost sik-btn--block" href="<?= e(url('shop.php')) ?>">Continue Shopping</a>
                    </div>
                </div>
            </div>

            <?php if (!$owns): ?>
                <div class="sik-panel">
                    <div class="sik-panel__body">
                        <p class="font-semibold text-sm">Keep this order at hand</p>
                        <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.7">
                            You checked out as a guest. Create an account with
                            <strong><?= e((string) $order['customer_email']) ?></strong> to keep every order,
                            invoice and return in one place.
                        </p>
                        <a class="sik-btn sik-btn--outline sik-btn--sm sik-btn--block" style="margin-top:var(--sp-3)"
                           href="<?= e(url('register.php')) ?>">Create an account</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sik-panel">
                <div class="sik-panel__body">
                    <p class="font-semibold text-sm">Need help with this order?</p>
                    <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.7">
                        Our team answers within one business day. Quote
                        <strong><?= e((string) $order['order_number']) ?></strong> and we will pick it straight up.
                    </p>
                    <a class="sik-btn sik-btn--ghost sik-btn--sm sik-btn--block" style="margin-top:var(--sp-3)"
                       href="<?= e(url('contact.php')) ?>"><?= icon('headset', 'w-4 h-4') ?> Contact support</a>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
