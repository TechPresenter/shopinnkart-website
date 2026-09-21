<?php
/**
 * ShopInnKart - Single order view.
 *
 * Accepts ?order=<order_number> (the form used in notification emails and the
 * /order/<number> clean URL) or ?id=<order_id>.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';
require_once INCLUDES_PATH . '/order-tracking.php';

$user = require_login();
$userId = (int) $user['id'];

$orderNumber = trim((string) input('order', ''));
$orderId = input_int('id', 0);

$order = null;
if ($orderNumber !== '') {
    $order = get_order_by_number($orderNumber);
} elseif ($orderId > 0) {
    $order = get_order($orderId);
}

if ($order === null) {
    require __DIR__ . '/404.php';
    exit;
}

// Guest orders have no user_id at all, so an exact match is the only way in.
if ($order['user_id'] === null || (int) $order['user_id'] !== $userId) {
    require __DIR__ . '/403.php';
    exit;
}

$orderId = (int) $order['id'];

// ---------------------------------------------------------------------------
//  "Email the invoice to me"
//
//  Ownership was already proved above, so the only extra work is CSRF, a rate
//  limit, and sending strictly to the address stored on the order — never to
//  one supplied in the request.
// ---------------------------------------------------------------------------
$invoiceResendAllowed = true;

if (is_post() && input('action', '') === 'resend_invoice') {
    csrf_require();

    $invoice = get_invoice_for_order($orderId);

    if ($invoice === null) {
        flash('error', 'There is no invoice for this order yet.');
    } elseif (!mail_rate_limit_hit('invoice_resend_' . $orderId, 3, 300)) {
        flash('error', 'You have requested this a few times already. Please wait a few minutes and try again.');
    } else {
        notify_invoice_generated($order, $invoice, true);
        process_notification_queue(2);

        flash('success', 'Your invoice is on its way to ' . mask_email((string) $order['customer_email']) . '.');
    }

    redirect(url('order-details.php?id=' . $orderId));
}

// ---------------------------------------------------------------------------
//  Return / exchange request
//
//  Ownership was proved above. Eligibility is re-checked inside
//  create_return_request() because the form may have been rendered before the
//  window closed or the warehouse moved the order on.
// ---------------------------------------------------------------------------
if (is_post() && input('action', '') === 'request_return') {
    csrf_require();

    $returnType = (string) input('return_type', 'return');
    $reasonCode = (string) input('reason', '');
    $comment = trim((string) input('comment', ''));

    if (mb_strlen($comment) > 2000) {
        $comment = mb_substr($comment, 0, 2000);
    }

    if (!mail_rate_limit_hit('return_request_' . $orderId, 3, 3600)) {
        flash('error', 'You have already sent this a few times. Please give us a little time to reply.');
    } else {
        $result = create_return_request($order, $reasonCode, $comment, $returnType);

        if ($result['ok']) {
            process_notification_queue(2);
            flash('success', $result['message'] . ' We will email you the outcome within one working day.');
        } else {
            flash('error', $result['message']);
        }
    }

    redirect(url('order-details.php?id=' . $orderId));
}

$returnRequests = get_return_requests_for_order($orderId);
$openReturn = get_open_return_request($orderId);
$returnEligible = can_request_return($order);

$items = get_order_items($orderId);
$payment = Database::fetch('SELECT * FROM `payments` WHERE `order_id` = :id ORDER BY `id` DESC LIMIT 1', ['id' => $orderId]);
$canCancel = can_cancel_order($order);
$isDelivered = $order['status'] === ORDER_STATUS_DELIVERED;

// Which of these products has the customer already reviewed? Used to show a
// "write a review" prompt only where one is still missing.
$reviewedProductIds = [];
if ($isDelivered) {
    $productIds = array_values(array_unique(array_filter(array_map(
        static fn (array $item): ?int => $item['product_id'] === null ? null : (int) $item['product_id'],
        $items
    ))));

    if ($productIds !== []) {
        [$placeholders, $params] = Database::inPlaceholders($productIds, 'p');
        $reviewedProductIds = array_map('intval', Database::fetchColumnAll(
            'SELECT `product_id` FROM `reviews` WHERE `user_id` = :uid AND `product_id` IN (' . $placeholders . ')',
            array_merge(['uid' => $userId], $params)
        ));
    }
}

$paymentMethodLabel = Database::fetchColumn(
    'SELECT `name` FROM `payment_methods` WHERE `code` = :code LIMIT 1',
    ['code' => (string) $order['payment_method']]
);
$paymentMethodLabel = $paymentMethodLabel !== null && $paymentMethodLabel !== false
    ? (string) $paymentMethodLabel
    : strtoupper((string) $order['payment_method']);

$paymentStatusColors = [
    PAYMENT_STATUS_PENDING  => 'amber',
    PAYMENT_STATUS_PAID     => 'green',
    PAYMENT_STATUS_FAILED   => 'red',
    PAYMENT_STATUS_REFUNDED => 'gray',
];

$totalSaving = 0.0;
foreach ($items as $item) {
    $totalSaving += max(0.0, ((float) $item['mrp'] - (float) $item['price'])) * (int) $item['quantity'];
}

seo_set([
    'title'  => 'Order #' . $order['order_number'],
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('orders', [
    'title'    => 'Order #' . $order['order_number'],
    'subtitle' => 'Placed on ' . format_datetime($order['created_at']),
    'action'   => '<a class="sik-viewall" href="' . e(url('orders.php')) . '">' . icon('arrow-left', 'w-3.5 h-3.5') . ' All orders</a>',
]);
?>

<div style="display:flex;flex-wrap:wrap;gap:var(--sp-3);align-items:center;margin-bottom:var(--sp-5)">
    <?= account_status_pill((string) $order['status']) ?>
    <span class="sik-status sik-status--<?= e($paymentStatusColors[$order['payment_status']] ?? 'gray') ?>">
        Payment <?= e(PAYMENT_STATUSES[$order['payment_status']] ?? (string) $order['payment_status']) ?>
    </span>
    <?php if (!empty($order['estimated_delivery']) && !$isDelivered && $order['status'] !== ORDER_STATUS_CANCELLED): ?>
        <span class="sik-badge sik-badge--soft">Expected by <?= e(format_date($order['estimated_delivery'])) ?></span>
    <?php endif; ?>
</div>

<?php if ($order['status'] === ORDER_STATUS_CANCELLED && !empty($order['cancel_reason'])): ?>
    <div class="sik-alert sik-alert--error" style="margin-bottom:var(--sp-5)">
        <?= icon('info', 'w-5 h-5') ?>
        <span>This order was cancelled. Reason: <?= e($order['cancel_reason']) ?></span>
    </div>
<?php endif; ?>

<div class="sik-panel" id="track" style="margin-bottom:var(--sp-5)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Order Tracking</h2>
        <?php if (!empty($order['tracking_number'])): ?>
            <span style="font-size:12.5px;color:var(--sik-muted)">
                <?= e((string) ($order['courier_name'] ?: 'Courier')) ?>
                &middot; <strong style="color:var(--sik-text)"><?= e($order['tracking_number']) ?></strong>
            </span>
        <?php endif; ?>
    </div>
    <div class="sik-panel__body">
        <?php // The same timeline the public tracking page draws, from the same
              // view model: stage icon, real timestamp and the recorded note as
              // the description. The separate list of notes that used to sit
              // under the rail is gone - each note is now on its own stage. ?>
        <?= order_timeline_html(order_tracking_stages($order)) ?>
    </div>
</div>

<div class="sik-panel" style="margin-bottom:var(--sp-5)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Items (<?= count($items) ?>)</h2>
    </div>
    <div class="sik-panel__body" style="padding:0">
        <div class="sik-scroll-x">
            <table style="width:100%;border-collapse:collapse;min-width:560px">
                <caption class="sik-sr">Products in order <?= e($order['order_number']) ?></caption>
                <thead>
                    <tr style="background:var(--sik-soft)">
                        <th scope="col" style="text-align:left;padding:var(--sp-3) var(--sp-5);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--sik-muted)">Product</th>
                        <th scope="col" style="text-align:right;padding:var(--sp-3) var(--sp-3);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--sik-muted)">Price</th>
                        <th scope="col" style="text-align:center;padding:var(--sp-3) var(--sp-3);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--sik-muted)">Qty</th>
                        <th scope="col" style="text-align:right;padding:var(--sp-3) var(--sp-5);font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--sik-muted)">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $productId = $item['product_id'] === null ? 0 : (int) $item['product_id'];
                        $reviewUrl = $item['product_slug'] ? product_url((string) $item['product_slug']) . '#reviews' : null;
                        $alreadyReviewed = in_array($productId, $reviewedProductIds, true);
                        ?>
                        <tr style="border-top:1px solid var(--sik-border)">
                            <td style="padding:var(--sp-4) var(--sp-5)">
                                <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
                                    <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['product_name']) ?>"
                                         width="52" height="52" loading="lazy"
                                         style="width:52px;height:52px;object-fit:contain;border:1px solid var(--sik-border);border-radius:8px;background:#fff;padding:var(--sp-1);flex:none">
                                    <div style="min-width:0">
                                        <?php if ($item['url'] !== null): ?>
                                            <a href="<?= e($item['url']) ?>" style="font-size:13.5px;font-weight:600;display:block"><?= e($item['product_name']) ?></a>
                                        <?php else: ?>
                                            <span style="font-size:13.5px;font-weight:600;display:block"><?= e($item['product_name']) ?></span>
                                        <?php endif; ?>
                                        <span style="font-size:12px;color:var(--sik-muted)">
                                            <?php if (!empty($item['variant_name'])): ?><?= e($item['variant_name']) ?> &middot; <?php endif; ?>
                                            SKU <?= e($item['product_sku']) ?>
                                        </span>

                                        <?php if ($isDelivered && $reviewUrl !== null): ?>
                                            <div style="margin-top:var(--sp-2)">
                                                <?php if ($alreadyReviewed): ?>
                                                    <span style="font-size:12px;color:var(--sik-success);font-weight:600">
                                                        <?= icon('check-circle', 'w-3.5 h-3.5') ?> You reviewed this
                                                    </span>
                                                <?php else: ?>
                                                    <a class="sik-btn sik-btn--ghost sik-btn--sm" href="<?= e($reviewUrl) ?>">
                                                        <?= icon('star', 'w-3.5 h-3.5') ?> <span class="sik-btn__label">Write a review</span>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:var(--sp-4) var(--sp-3);text-align:right;font-size:13px;white-space:nowrap">
                                <?= e($item['price_display']) ?>
                                <?php if ((float) $item['mrp'] > (float) $item['price']): ?>
                                    <span style="display:block;font-size:11.5px;color:var(--sik-muted);text-decoration:line-through"><?= e(money((float) $item['mrp'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:var(--sp-4) var(--sp-3);text-align:center;font-size:13px"><?= (int) $item['quantity'] ?></td>
                            <td style="padding:var(--sp-4) var(--sp-5);text-align:right;font-size:13.5px;font-weight:700;white-space:nowrap"><?= e($item['subtotal_display']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2;margin-bottom:var(--sp-5)">
    <div class="sik-panel">
        <div class="sik-panel__head"><h2 class="sik-panel__title">Delivery Address</h2></div>
        <div class="sik-panel__body" style="font-size:13.5px;line-height:1.75">
            <strong><?= e($order['shipping_name']) ?></strong>
            <div style="color:var(--sik-muted)">
                <?= e($order['shipping_address']) ?><br>
                <?php if (!empty($order['shipping_address2'])): ?><?= e($order['shipping_address2']) ?><br><?php endif; ?>
                <?php if (!empty($order['shipping_landmark'])): ?>Near <?= e($order['shipping_landmark']) ?><br><?php endif; ?>
                <?= e($order['shipping_city']) ?>, <?= e($order['shipping_state']) ?> - <?= e($order['shipping_pincode']) ?><br>
                <?= e($order['shipping_country']) ?>
            </div>
            <div style="margin-top:var(--sp-2)"><?= icon('phone', 'w-3.5 h-3.5') ?> <?= e($order['shipping_phone']) ?></div>
        </div>
    </div>

    <div class="sik-panel">
        <div class="sik-panel__head"><h2 class="sik-panel__title">Billing Address</h2></div>
        <div class="sik-panel__body" style="font-size:13.5px;line-height:1.75">
            <?php if (empty($order['billing_name'])): ?>
                <p style="color:var(--sik-muted)">Same as the delivery address.</p>
            <?php else: ?>
                <strong><?= e($order['billing_name']) ?></strong>
                <div style="color:var(--sik-muted)">
                    <?= e((string) $order['billing_address']) ?><br>
                    <?= e((string) $order['billing_city']) ?>, <?= e((string) $order['billing_state']) ?> - <?= e((string) $order['billing_pincode']) ?><br>
                    <?= e((string) $order['billing_country']) ?>
                </div>
                <?php if (!empty($order['billing_phone'])): ?>
                    <div style="margin-top:var(--sp-2)"><?= icon('phone', 'w-3.5 h-3.5') ?> <?= e($order['billing_phone']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2;margin-bottom:var(--sp-5)">
    <div class="sik-panel">
        <div class="sik-panel__head"><h2 class="sik-panel__title">Payment</h2></div>
        <div class="sik-panel__body">
            <div class="sik-summary">
                <div class="sik-summary__row">
                    <span style="color:var(--sik-muted)">Method</span>
                    <span style="font-weight:600"><?= e($paymentMethodLabel) ?></span>
                </div>
                <div class="sik-summary__row">
                    <span style="color:var(--sik-muted)">Status</span>
                    <span class="sik-status sik-status--<?= e($paymentStatusColors[$order['payment_status']] ?? 'gray') ?>">
                        <?= e(PAYMENT_STATUSES[$order['payment_status']] ?? (string) $order['payment_status']) ?>
                    </span>
                </div>
                <?php if ($payment !== null && !empty($payment['reference'])): ?>
                    <div class="sik-summary__row">
                        <span style="color:var(--sik-muted)">Reference</span>
                        <span style="font-weight:600;word-break:break-all"><?= e($payment['reference']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($payment !== null && !empty($payment['paid_at'])): ?>
                    <div class="sik-summary__row">
                        <span style="color:var(--sik-muted)">Paid on</span>
                        <span style="font-weight:600"><?= e(format_datetime($payment['paid_at'])) ?></span>
                    </div>
                <?php endif; ?>
                <div class="sik-summary__row">
                    <span style="color:var(--sik-muted)">Shipping method</span>
                    <span style="font-weight:600"><?= e(ucfirst((string) $order['shipping_method'])) ?></span>
                </div>
            </div>

            <?php if (!empty($order['customer_note'])): ?>
                <p style="margin-top:var(--sp-4);font-size:12.5px;color:var(--sik-muted)">
                    <strong style="color:var(--sik-text)">Your note:</strong> <?= e($order['customer_note']) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="sik-panel">
        <div class="sik-panel__head"><h2 class="sik-panel__title">Order Summary</h2></div>
        <div class="sik-panel__body">
            <div class="sik-summary">
                <div class="sik-summary__row">
                    <span>Subtotal</span><span><?= e(money((float) $order['subtotal'])) ?></span>
                </div>
                <?php if ((float) $order['discount_amount'] > 0): ?>
                    <div class="sik-summary__row sik-summary__row--save">
                        <span>Discount<?= !empty($order['coupon_code']) ? ' (' . e($order['coupon_code']) . ')' : '' ?></span>
                        <span>- <?= e(money((float) $order['discount_amount'])) ?></span>
                    </div>
                <?php endif; ?>
                <div class="sik-summary__row">
                    <span>Shipping</span>
                    <span><?= (float) $order['shipping_amount'] > 0 ? e(money((float) $order['shipping_amount'])) : 'Free' ?></span>
                </div>
                <?php if ((float) $order['tax_amount'] > 0): ?>
                    <div class="sik-summary__row">
                        <span>Tax</span><span><?= e(money((float) $order['tax_amount'])) ?></span>
                    </div>
                <?php endif; ?>
                <div class="sik-summary__row sik-summary__row--total">
                    <span>Total</span><span><?= e(money((float) $order['total_amount'])) ?></span>
                </div>
                <?php if ($totalSaving > 0): ?>
                    <div class="sik-summary__row sik-summary__row--save">
                        <span>You saved</span><span><?= e(money($totalSaving)) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="sik-panel">
    <div class="sik-panel__body">
        <div class="sik-ordercard__actions">
            <?php // Same invoice rule the list cards use: no document for an order
                  // that was never confirmed, or that was cancelled. ?>
            <?php if (order_has_invoice($order)): ?>
                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('invoice.php?id=' . $orderId)) ?>">
                    <?= icon('file-text', 'w-4 h-4') ?> <span class="sik-btn__label">View invoice</span>
                </a>
                <a class="sik-btn sik-btn--outline sik-btn--sm"
                   href="<?= e(url('invoice-download.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                    <?= icon('download', 'w-4 h-4') ?> <span class="sik-btn__label">Download PDF</span>
                </a>
                <?php // Only a signed-in owner may have it re-sent, and only to the
                      // address already on the order — never to one supplied here. ?>
                <?php if ($invoiceResendAllowed): ?>
                    <form method="post" action="<?= e(url('order-details.php?id=' . $orderId)) ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend_invoice">
                        <button type="submit" class="sik-btn sik-btn--ghost sik-btn--sm">
                            <?= icon('mail', 'w-4 h-4') ?>
                            <span class="sik-btn__label">Email it to me</span>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm" data-reorder="<?= $orderId ?>">
                <?= icon('refresh', 'w-4 h-4') ?> <span class="sik-btn__label">Reorder</span>
            </button>
            <?php if ($canCancel): ?>
                <button type="button" class="sik-btn sik-btn--danger sik-btn--sm" data-cancel-order="<?= $orderId ?>">
                    <?= icon('close', 'w-4 h-4') ?> <span class="sik-btn__label">Cancel order</span>
                </button>
            <?php endif; ?>
            <?php // Used to be a dead-end link to the contact form. It now opens
                  // the real request form below. ?>
            <?php if ($returnEligible['ok']): ?>
                <a class="sik-btn sik-btn--ghost sik-btn--sm" href="#return-request">
                    <?= icon('rotate', 'w-4 h-4') ?> <span class="sik-btn__label">Return or exchange</span>
                </a>
            <?php endif; ?>
            <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('contact.php')) ?>">
                <?= icon('headset', 'w-4 h-4') ?> <span class="sik-btn__label">Need help</span>
            </a>
        </div>

        <?php // One definition of this copy, shared with the order cards. ?>
        <?= account_order_help_html(array_merge($order, ['can_cancel' => $canCancel])) ?>

        <!-- ==================== Returns & exchanges ==================== -->
        <?php if ($returnRequests !== [] || $returnEligible['ok']): ?>
            <div id="return-request" style="margin-top:var(--sp-5)">
                <hr class="sik-divider">

                <?php if ($returnRequests !== []): ?>
                    <p class="text-xs uppercase tracking-wide text-muted font-semibold" style="margin-bottom:var(--sp-3)">
                        Returns &amp; exchanges
                    </p>

                    <?php foreach ($returnRequests as $request): ?>
                        <?php $requestStatus = (string) $request['status']; ?>
                        <div class="sik-panel" style="padding:var(--sp-4);margin-bottom:var(--sp-3)">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <strong class="text-sm">
                                        <?= e(ucfirst((string) $request['type'])) ?> request
                                        &middot; <?= e(return_reason_label((string) $request['reason'])) ?>
                                    </strong>
                                    <span class="block text-xs text-muted" style="margin-top:var(--sp-1)">
                                        Raised <?= e(format_datetime($request['created_at'])) ?>
                                    </span>
                                </div>
                                <span class="sik-status sik-status--<?= e(RETURN_STATUS_COLORS[$requestStatus] ?? 'gray') ?>">
                                    <?= e(RETURN_STATUSES[$requestStatus] ?? ucfirst($requestStatus)) ?>
                                </span>
                            </div>

                            <?php if (!empty($request['comment'])): ?>
                                <p class="text-sm" style="margin-top:var(--sp-3)">
                                    <span class="text-muted">You wrote:</span><br>
                                    <?= nl2br(e((string) $request['comment'])) ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($request['admin_note'])): ?>
                                <p class="text-sm" style="margin-top:var(--sp-3);padding:var(--sp-3);background:var(--sik-surface-2, #f9fafb);border-radius:8px">
                                    <span class="text-muted">Our reply:</span><br>
                                    <?= nl2br(e((string) $request['admin_note'])) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($returnEligible['ok']): ?>
                    <div class="sik-panel" style="padding:var(--sp-4)">
                        <p class="text-sm font-semibold">Request a return or exchange</p>
                        <p class="text-xs text-muted" style="margin-top:var(--sp-1)">
                            <?= (int) return_days_left($order) ?> day<?= return_days_left($order) === 1 ? '' : 's' ?>
                            left in the return window for this order.
                        </p>

                        <form method="post" action="<?= e(url('order-details.php?id=' . $orderId)) ?>"
                              style="margin-top:var(--sp-4);display:grid;gap:var(--sp-3)">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="request_return">

                            <?php if (setting_bool('exchange_enabled', true)): ?>
                                <div>
                                    <label class="sik-label" for="returnType">What would you like?</label>
                                    <select class="sik-input" id="returnType" name="return_type">
                                        <option value="return">Return for a refund</option>
                                        <option value="exchange">Exchange for the same item</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div>
                                <label class="sik-label" for="returnReason">Reason <span class="req">*</span></label>
                                <select class="sik-input" id="returnReason" name="reason" required>
                                    <option value="">Choose a reason</option>
                                    <?php foreach (RETURN_REASONS as $code => $label): ?>
                                        <option value="<?= e($code) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="sik-label" for="returnComment">Anything else we should know?</label>
                                <textarea class="sik-textarea" id="returnComment" name="comment" rows="3"
                                          maxlength="2000"
                                          placeholder="Tell us what went wrong, so we can sort it out faster."></textarea>
                            </div>

                            <div>
                                <button type="submit" class="sik-btn sik-btn--primary sik-btn--sm">
                                    <?= icon('rotate', 'w-4 h-4') ?> Submit request
                                </button>
                            </div>
                        </form>
                    </div>
                <?php elseif ($openReturn === null && $returnRequests === []): ?>
                    <p class="text-xs text-muted"><?= e($returnEligible['reason']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
