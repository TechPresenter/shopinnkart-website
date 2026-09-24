<?php
/**
 * ShopInnKart Admin - Order detail.
 *
 * Everything about one order on a single screen: the lines and money on the
 * left, who/where/how-paid on the right, and the actions that move it forward.
 * The mutations themselves live in their own POST-only endpoints so this file
 * stays a read view plus one small side effect (resending the confirmation).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

$orderId = input_int('id');
$order   = $orderId > 0 ? get_order($orderId) : null;

if ($order === null) {
    flash('error', 'That order no longer exists.');
    redirect(admin_url('orders/'));
}

$canEdit = admin_can('orders.edit');

// Invoice and email actions. They are small side effects on a read view, which
// is why they live here rather than in their own endpoints — each one still
// re-checks orders.edit and the CSRF token.
$postAction = is_post() ? (string) input('action', '') : '';
$selfUrl = admin_url('orders/view.php?id=' . $orderId);

if ($postAction !== '') {
    admin_require('orders.edit');
    csrf_require();
}

/** Note an admin action against the order so the history explains itself. */
$recordOrderNote = static function (string $note) use ($orderId, $order): void {
    Database::insert('order_status_history', [
        'order_id'   => $orderId,
        'status'     => (string) $order['status'],
        'note'       => mb_substr($note, 0, 500),
        'changed_by' => 'admin',
        'admin_id'   => admin_id(),
    ]);
};

if ($postAction === 'resend_email') {
    // Resending is deliberate, so it bypasses the idempotency key that stops
    // duplicate gateway callbacks re-queueing the automatic copy.
    if (!mail_rate_limit_hit('order_email_' . $orderId, 5, 60)) {
        flash('error', 'Too many resends for this order. Please wait a minute.');
        redirect($selfUrl);
    }

    notify_order_placed($order, true);

    $recordOrderNote('Confirmation email re-sent to ' . $order['customer_email']);
    log_activity('order.email_resent', 'order', $orderId,
        'Re-queued the confirmation email for ' . $order['order_number']);
    admin_after_write();

    $result = process_notification_queue(3);
    flash(
        $result['sent'] > 0 ? 'success' : 'info',
        $result['sent'] > 0
            ? 'Confirmation email sent to ' . $order['customer_email'] . '.'
            : 'Confirmation email queued for ' . $order['customer_email'] . '. It will go out on the next queue run.'
    );
    redirect($selfUrl);
}

if ($postAction === 'generate_invoice') {
    // force = true so staff can raise an invoice for an order the automatic
    // rules would skip (an unpaid prepaid order being settled by hand).
    $result = generate_invoice_for_order($orderId, true);

    if (!$result['ok'] || $result['invoice'] === null) {
        flash('error', 'Could not raise an invoice: ' . (string) $result['error']);
        redirect($selfUrl);
    }

    $pdf = invoice_render_pdf($result['invoice']);

    $recordOrderNote('Invoice ' . $result['invoice']['invoice_number'] . ' raised manually');
    log_activity('invoice.manual', 'order', $orderId,
        'Raised invoice ' . $result['invoice']['invoice_number'] . ' for ' . $order['order_number']);
    admin_after_write();

    flash(
        $pdf['ok'] ? 'success' : 'warning',
        $result['created']
            ? 'Invoice ' . $result['invoice']['invoice_number'] . ' raised.'
                . ($pdf['ok'] ? '' : ' The PDF failed: ' . (string) $pdf['error'])
            : 'This order already had invoice ' . $result['invoice']['invoice_number'] . '.'
    );
    redirect($selfUrl);
}

if ($postAction === 'regenerate_pdf') {
    $invoice = get_invoice_for_order($orderId);

    if ($invoice === null) {
        flash('error', 'This order has no invoice to render.');
        redirect($selfUrl);
    }

    // Re-freeze the snapshot so the PDF picks up any settings changed since.
    Database::update('invoices', [
        'snapshot' => json_encode(invoice_build_data($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], '`id` = :id', ['id' => (int) $invoice['id']]);

    $pdf = invoice_render_pdf(get_invoice((int) $invoice['id']), true);

    log_activity('invoice.pdf_regenerated', 'order', $orderId,
        'Regenerated the PDF for ' . $invoice['invoice_number']);
    admin_after_write();

    flash($pdf['ok'] ? 'success' : 'error',
        $pdf['ok'] ? 'PDF regenerated for ' . $invoice['invoice_number'] . '.' : 'PDF failed: ' . (string) $pdf['error']);
    redirect($selfUrl);
}

if ($postAction === 'email_invoice') {
    $invoice = get_invoice_for_order($orderId);

    if ($invoice === null) {
        flash('error', 'Raise an invoice first — there is nothing to email.');
        redirect($selfUrl);
    }
    if (!mail_rate_limit_hit('order_email_' . $orderId, 5, 60)) {
        flash('error', 'Too many emails for this order. Please wait a minute.');
        redirect($selfUrl);
    }

    notify_invoice_generated($order, $invoice, true);

    $recordOrderNote('Invoice ' . $invoice['invoice_number'] . ' emailed to ' . $order['customer_email']);
    log_activity('invoice.emailed', 'order', $orderId,
        'Emailed invoice ' . $invoice['invoice_number'] . ' to ' . $order['customer_email']);
    admin_after_write();

    $result = process_notification_queue(3);
    flash(
        $result['sent'] > 0 ? 'success' : 'info',
        $result['sent'] > 0
            ? 'Invoice ' . $invoice['invoice_number'] . ' emailed to ' . $order['customer_email'] . '.'
            : 'Invoice email queued for ' . $order['customer_email'] . '.'
    );
    redirect($selfUrl);
}

if ($postAction === 'custom_email') {
    $subject = trim((string) input('subject', ''));
    $message = trim((string) input('message', ''));

    if ($subject === '' || $message === '') {
        flash('error', 'A custom email needs both a subject and a message.');
        redirect($selfUrl);
    }
    if (mb_strlen($subject) > 200) {
        flash('error', 'Keep the subject under 200 characters.');
        redirect($selfUrl);
    }
    if (!mail_rate_limit_hit('order_email_' . $orderId, 5, 60)) {
        flash('error', 'Too many emails for this order. Please wait a minute.');
        redirect($selfUrl);
    }

    $attachments = [];
    $attachedName = '';
    if (input_bool('attach_invoice')) {
        $invoice = get_invoice_for_order($orderId);
        if ($invoice !== null) {
            $pdf = invoice_render_pdf($invoice);
            if ($pdf['ok'] && $pdf['path'] !== null) {
                $attachments[] = ['path' => $pdf['path'], 'name' => $pdf['filename']];
                $attachedName = (string) $pdf['filename'];
            }
        }
    }

    // The admin's message is plain text turned into paragraphs — never raw
    // HTML — so nothing an admin types can inject markup into the mail.
    $bodyHtml = '<p style="margin:0 0 16px 0">Hi ' . e((string) $order['customer_name']) . ',</p>';
    foreach (preg_split('/\n{2,}/', $message) ?: [] as $paragraph) {
        $bodyHtml .= '<p style="margin:0 0 14px 0">' . nl2br(e(trim($paragraph))) . '</p>';
    }
    $bodyHtml .= '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">'
        . 'Regarding order ' . e((string) $order['order_number']) . '.</p>';

    $result = send_email_detailed(
        (string) $order['customer_email'],
        $subject,
        $bodyHtml,
        $attachments,
        ['to_name' => (string) $order['customer_name']]
    );

    // Custom mail bypasses the template queue, so it is logged explicitly —
    // otherwise it would be the one kind of email with no record.
    Database::insert('notification_queue', [
        'channel'         => 'email',
        'template_key'    => null,
        'email_type'      => 'custom',
        'recipient'       => (string) $order['customer_email'],
        'recipient_name'  => (string) $order['customer_name'],
        'recipient_type'  => 'customer',
        'subject'         => mb_substr($subject, 0, 255),
        'body'            => $bodyHtml,
        'body_text'       => $message,
        'reference_type'  => 'order',
        'reference_id'    => $orderId,
        'order_id'        => $orderId,
        'user_id'         => $order['user_id'] === null ? null : (int) $order['user_id'],
        'attachment_name' => $attachedName !== '' ? $attachedName : null,
        'status'          => $result['ok'] ? 'sent' : 'failed',
        'attempts'        => 1,
        'sent_at'         => $result['ok'] ? date('Y-m-d H:i:s') : null,
        'last_attempt_at' => date('Y-m-d H:i:s'),
        'error'           => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 500),
        'smtp_response'   => $result['smtp'] === null ? null : mb_substr((string) $result['smtp'], 0, 500),
    ]);

    $recordOrderNote('Custom email sent to ' . $order['customer_email'] . ': ' . $subject);
    log_activity('order.custom_email', 'order', $orderId,
        'Sent a custom email to ' . $order['customer_email'] . ' (' . ($result['ok'] ? 'accepted' : 'failed') . ')');
    admin_after_write();

    flash($result['ok'] ? 'success' : 'error',
        $result['ok']
            ? 'Email sent to ' . $order['customer_email'] . '.'
            : 'Send failed: ' . (string) $result['error']);
    redirect($selfUrl);
}

$items   = get_order_items($orderId);
$history = get_order_history($orderId);

// Admin names for the history entries, resolved in one query.
$adminIds = array_values(array_unique(array_filter(array_map(
    static fn (array $row): ?int => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
    $history
))));
$adminNames = [];
if ($adminIds !== []) {
    [$placeholders, $adminParams] = Database::inPlaceholders($adminIds, 'a');
    $adminNames = Database::fetchPairs(
        'SELECT `id`, `name` FROM `admins` WHERE `id` IN (' . $placeholders . ')',
        $adminParams
    );
}

$payment = Database::fetch(
    'SELECT * FROM `payments` WHERE `order_id` = :id ORDER BY `id` DESC LIMIT 1',
    ['id' => $orderId]
);
$paymentMethodName = (string) Database::fetchColumn(
    'SELECT `name` FROM `payment_methods` WHERE `code` = :c LIMIT 1',
    ['c' => (string) $order['payment_method']]
) ?: strtoupper((string) $order['payment_method']);
$shippingMethodName = (string) Database::fetchColumn(
    'SELECT `name` FROM `shipping_methods` WHERE `code` = :c LIMIT 1',
    ['c' => (string) $order['shipping_method']]
) ?: ucfirst((string) $order['shipping_method']);

// Guest orders have no account, so count the customer's history by email.
$lifetimeOrders = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `orders` WHERE `customer_email` = :email',
    ['email' => (string) $order['customer_email']]
);
$lifetimeValue = (float) Database::fetchColumn(
    "SELECT COALESCE(SUM(`total_amount`), 0) FROM `orders`
     WHERE `customer_email` = :email
       AND `status` IN ('confirmed','processing','packed','shipped','out_for_delivery','delivered')",
    ['email' => (string) $order['customer_email']]
);

$status     = (string) $order['status'];
$isClosed   = in_array($status, STOCK_RELEASING_STATUSES, true);
$canCancel  = $canEdit && !$isClosed && $status !== ORDER_STATUS_DELIVERED;
$invoiceUrl = url('invoice.php?order=' . urlencode((string) $order['order_number']));

// ---------------------------------------------------------------------------
// Invoice + email state for the panel further down.
// ---------------------------------------------------------------------------
$invoice = get_invoice_for_order($orderId);
$invoicePdfPath = $invoice === null ? null : invoice_pdf_path($invoice);
$invoiceDownloadUrl = $invoice === null
    ? ''
    : url('invoice-download.php?invoice=' . rawurlencode((string) $invoice['invoice_number']));

$emailHistory = Database::fetchAll(
    'SELECT * FROM `notification_queue`
     WHERE `order_id` = :id OR (`reference_type` = \'order\' AND `reference_id` = :id2)
     ORDER BY `id` DESC LIMIT 25',
    ['id' => $orderId, 'id2' => $orderId]
);

$emailCounts = ['sent' => 0, 'pending' => 0, 'failed' => 0];
foreach ($emailHistory as $entry) {
    $key = (string) $entry['status'];
    if (isset($emailCounts[$key])) {
        $emailCounts[$key]++;
    }
}

$paymentEvents = Database::fetchAll(
    'SELECT * FROM `payment_transactions` WHERE `order_id` = :id ORDER BY `id` DESC LIMIT 10',
    ['id' => $orderId]
);

// Cancel and return capture a reason, so they have their own actions; the
// select only walks the order forward along the fulfilment path.
$forwardStatuses = [];
foreach (ORDER_TIMELINE as $step) {
    $forwardStatuses[$step] = ORDER_STATUSES[$step];
}

// ---------------------------------------------------------------------------
// Money summary.
//
// cart_totals() builds the stored total as:
//     (subtotal - discount) + shipping + payment_charge - payment_discount
// and only adds tax on top when the store is NOT tax-inclusive. The summary
// used to omit both payment columns and print tax as an additive row
// regardless, so on a tax-inclusive store (the default) the printed lines
// exceeded the grand total by exactly the tax, on every order.
//
// Whether tax was additive is decided per order by testing it against the
// stored total rather than by reading the current `tax_inclusive` setting:
// the setting can be changed long after an order was placed, and the order's
// own numbers are the authority for what it charged.
// ---------------------------------------------------------------------------
$sumSubtotal    = (float) $order['subtotal'];
$sumDiscount    = (float) $order['discount_amount'];
$sumShipping    = (float) $order['shipping_amount'];
$sumTax         = (float) $order['tax_amount'];
$sumPayCharge   = (float) ($order['payment_charge'] ?? 0);
$sumPayDiscount = (float) ($order['payment_discount'] ?? 0);
$sumTotal       = (float) $order['total_amount'];

$sumBase       = $sumSubtotal - $sumDiscount + $sumShipping + $sumPayCharge - $sumPayDiscount;
$taxIsAdditive = abs(($sumBase + $sumTax) - $sumTotal) < 0.01
    && abs($sumBase - $sumTotal) >= 0.01;

// Anything still unaccounted for is shown rather than hidden, so the column
// the admin reads always sums to the grand total they are looking at.
$sumResidual = $sumTotal - ($sumBase + ($taxIsAdditive ? $sumTax : 0.0));

$pageTitle    = 'Order ' . $order['order_number'];
$pageSubtitle = 'Placed ' . format_datetime($order['created_at']) . ' · ' . $lifetimeOrders
    . ' order' . ($lifetimeOrders === 1 ? '' : 's') . ' from this customer.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Orders', 'url' => admin_url('orders/')],
    ['label' => (string) $order['order_number']],
];

$pageActions = '<a class="ad-btn" target="_blank" rel="noopener" href="' . e($invoiceUrl) . '">'
    . icon('file-text', 'w-4 h-4') . ' View Invoice</a>';
if ($invoice !== null) {
    $pageActions .= '<a class="ad-btn" href="' . e($invoiceDownloadUrl) . '">'
        . icon('download', 'w-4 h-4') . ' Download PDF</a>';
}
if ($canEdit) {
    $pageActions .= '<button type="submit" form="resendEmailForm" class="ad-btn">'
        . icon('mail', 'w-4 h-4') . ' Resend confirmation</button>';
}
if ($canCancel) {
    $pageActions .= '<button type="button" class="ad-btn ad-btn--danger" data-modal-open="cancelOrderModal">'
        . icon('close', 'w-4 h-4') . ' Cancel order</button>';
}
$pageActions .= '<a class="ad-btn" href="' . e(admin_url('orders/')) . '">Back to orders</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($canEdit): ?>
    <form id="resendEmailForm" method="post" action="<?= e(admin_url('orders/view.php?id=' . $orderId)) ?>" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend_email">
    </form>
<?php endif; ?>

<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
    <?= admin_status_badge($status) ?>
    <?= admin_state_badge((string) $order['payment_status']) ?>
    <span class="ad-mono ad-muted"><?= e($order['order_number']) ?></span>
    <button type="button" class="ad-btn ad-btn--icon ad-btn--sm"
            data-copy="<?= e_attr($order['order_number']) ?>"
            title="Copy order number" aria-label="Copy order number"><?= icon('copy', 'w-3.5 h-3.5') ?></button>
</div>

<div class="ad-grid ad-grid--sidebar">

    <!-- =========================== Left column =========================== -->
    <div>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Items</div>
                    <div class="ad-card__sub"><?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?> · <?= e($shippingMethodName) ?></div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($items === []): ?>
                    <?= admin_empty('No line items', 'This order has no items recorded against it.', null, null, 'package') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th colspan="2">Product</th>
                                    <th>SKU</th>
                                    <th class="ad-table__num">Unit price</th>
                                    <th class="ad-table__num">Qty</th>
                                    <th class="ad-table__num">Tax</th>
                                    <th class="ad-table__num">Line total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td style="width:56px">
                                            <img class="ad-thumb" src="<?= e($item['image_url']) ?>"
                                                 alt="" width="42" height="42" loading="lazy">
                                        </td>
                                        <td>
                                            <?php if ($item['url'] !== null): ?>
                                                <a class="ad-cellflex__name" target="_blank" rel="noopener"
                                                   href="<?= e($item['url']) ?>"><?= e($item['product_name']) ?></a>
                                            <?php else: ?>
                                                <span class="ad-cellflex__name"><?= e($item['product_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['variant_name'])): ?>
                                                <div class="ad-cellflex__meta"><?= e($item['variant_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ((float) $item['mrp'] > (float) $item['price']): ?>
                                                <div class="ad-cellflex__meta">
                                                    MRP <?= e(money((float) $item['mrp'])) ?>
                                                    · <?= discount_percent($item['mrp'], $item['price']) ?>% off
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-mono"><?= e($item['product_sku']) ?></td>
                                        <td class="ad-table__num"><?= e(money((float) $item['price'])) ?></td>
                                        <td class="ad-table__num"><?= (int) $item['quantity'] ?></td>
                                        <td class="ad-table__num">
                                            <?= e(money((float) $item['tax_amount'])) ?>
                                            <div class="ad-cellflex__meta"><?= e(rtrim(rtrim(number_format((float) $item['tax_rate'], 2), '0'), '.')) ?>%</div>
                                        </td>
                                        <td class="ad-table__num"><strong><?= e(money((float) $item['total'])) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ad-card__foot">
                <div class="sik-summary" style="max-width:360px;margin-left:auto">
                    <div class="sik-summary__row">
                        <span>Subtotal<?= $taxIsAdditive ? '' : ' <span class="ad-muted">(incl. tax)</span>' ?></span>
                        <span><?= e(money($sumSubtotal)) ?></span>
                    </div>
                    <?php if ($sumDiscount > 0): ?>
                        <div class="sik-summary__row sik-summary__row--save">
                            <span>
                                Discount
                                <?php if (!empty($order['coupon_code'])): ?>
                                    <span class="ad-mono">(<?= e($order['coupon_code']) ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span>&minus; <?= e(money($sumDiscount)) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="sik-summary__row">
                        <span>Shipping</span>
                        <span><?= $sumShipping > 0 ? e(money($sumShipping)) : 'Free' ?></span>
                    </div>
                    <?php if ($sumPayCharge > 0): ?>
                        <div class="sik-summary__row">
                            <span>Payment charge</span><span><?= e(money($sumPayCharge)) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($sumPayDiscount > 0): ?>
                        <div class="sik-summary__row sik-summary__row--save">
                            <span>Payment discount</span>
                            <span>&minus; <?= e(money($sumPayDiscount)) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="sik-summary__row">
                        <span>
                            Tax
                            <?php if (!$taxIsAdditive): ?>
                                <span class="ad-muted">(included above)</span>
                            <?php endif; ?>
                        </span>
                        <span><?= e(money($sumTax)) ?></span>
                    </div>
                    <?php if (abs($sumResidual) >= 0.01): ?>
                        <div class="sik-summary__row">
                            <span>Adjustment</span>
                            <span><?= ($sumResidual < 0 ? '&minus; ' : '') . e(money(abs($sumResidual))) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="sik-summary__row sik-summary__row--total">
                        <span>Grand total</span><span><?= e(money($sumTotal)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($order['customer_note']) || !empty($order['admin_note'])
                  || !empty($order['cancel_reason']) || !empty($order['return_reason'])): ?>
            <div class="ad-card">
                <div class="ad-card__head"><div class="ad-card__title">Notes</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <?php if (!empty($order['customer_note'])): ?>
                        <div>
                            <div class="ad-cellflex__meta">Customer note</div>
                            <p style="margin-top:4px"><?= nl2br(e($order['customer_note'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['admin_note'])): ?>
                        <div>
                            <div class="ad-cellflex__meta">Internal note</div>
                            <p style="margin-top:4px"><?= nl2br(e($order['admin_note'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['cancel_reason'])): ?>
                        <div>
                            <div class="ad-cellflex__meta">Cancellation reason</div>
                            <p style="margin-top:4px"><?= e($order['cancel_reason']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['return_reason'])): ?>
                        <div>
                            <div class="ad-cellflex__meta">Return reason</div>
                            <p style="margin-top:4px"><?= e($order['return_reason']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Status history</div></div>
            <div class="ad-card__body">
                <?php if ($history === []): ?>
                    <p class="ad-muted">No status changes recorded yet.</p>
                <?php else: ?>
                    <div class="sik-timeline">
                        <?php $lastIndex = count($history) - 1; ?>
                        <?php foreach ($history as $index => $entry): ?>
                            <div class="sik-timeline__step is-done <?= $index === $lastIndex ? 'is-current' : '' ?>">
                                <span class="sik-timeline__dot"><?= icon('check', 'w-3 h-3') ?></span>
                                <div class="sik-timeline__label">
                                    <?= e(ORDER_STATUSES[$entry['status']] ?? ucfirst((string) $entry['status'])) ?>
                                </div>
                                <?php if (!empty($entry['note'])): ?>
                                    <div style="font-size:13px;margin-top:3px"><?= e($entry['note']) ?></div>
                                <?php endif; ?>
                                <div class="sik-timeline__time">
                                    <?= e(format_datetime($entry['created_at'])) ?>
                                    ·
                                    <?php if ($entry['admin_id'] !== null && isset($adminNames[(int) $entry['admin_id']])): ?>
                                        <?= e((string) $adminNames[(int) $entry['admin_id']]) ?>
                                    <?php else: ?>
                                        <?= e(ucfirst((string) $entry['changed_by'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ====================== Email history ====================== -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Email history</div>
                    <div class="ad-card__sub">
                        <?php if ($emailHistory === []): ?>
                            Nothing has been sent for this order yet.
                        <?php else: ?>
                            <?= (int) $emailCounts['sent'] ?> sent
                            · <?= (int) $emailCounts['pending'] ?> pending
                            · <?= (int) $emailCounts['failed'] ?> failed
                        <?php endif; ?>
                    </div>
                </div>
                <a class="ad-btn ad-btn--sm"
                   href="<?= e(admin_url('settings/email-log.php?q=' . rawurlencode((string) $order['customer_email']))) ?>">
                    Open in email log
                </a>
            </div>

            <div class="ad-card__body ad-card__body--flush">
                <?php if ($emailHistory === []): ?>
                    <div class="sik-empty sik-empty--sm">
                        <?= icon('mail', 'w-10 h-10') ?>
                        <p class="sik-empty__text">
                            No email has been queued against this order. Use "Resend confirmation" above
                            to send one now.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                            <tr>
                                <th>Subject</th>
                                <th>To</th>
                                <th>Attachment</th>
                                <th>Status</th>
                                <th>When</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($emailHistory as $entry): ?>
                                <?php
                                $entryStatus = (string) $entry['status'];
                                $tone = ['sent' => 'green', 'pending' => 'amber', 'failed' => 'red'][$entryStatus] ?? 'gray';
                                ?>
                                <tr>
                                    <td style="max-width:260px">
                                        <div><?= e((string) ($entry['subject'] ?? '—')) ?></div>
                                        <span class="ad-muted ad-mono" style="font-size:11px">
                                            <?= e((string) ($entry['email_type'] ?: $entry['template_key'] ?: 'custom')) ?>
                                        </span>
                                        <?php if ((string) ($entry['error'] ?? '') !== ''): ?>
                                            <div style="font-size:11.5px;color:#b91c1c">
                                                <?= e(mb_strimwidth((string) $entry['error'], 0, 110, '…')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:var(--ad-text-sm)">
                                        <?= e((string) $entry['recipient']) ?>
                                        <?php if ((string) $entry['recipient_type'] === 'admin'): ?>
                                            <span class="sik-badge sik-badge--soft">admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ad-mono" style="font-size:11.5px">
                                        <?= (string) ($entry['attachment_name'] ?? '') !== ''
                                            ? e((string) $entry['attachment_name'])
                                            : '<span class="ad-muted">—</span>' ?>
                                    </td>
                                    <td>
                                        <span class="sik-status sik-status--<?= e($tone) ?>"><?= e(ucfirst($entryStatus)) ?></span>
                                    </td>
                                    <td style="white-space:nowrap;font-size:var(--ad-text-sm)">
                                        <?= e(format_datetime($entry['sent_at'] ?: $entry['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($paymentEvents !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Payment events</div>
                        <div class="ad-card__sub">Gateway callbacks recorded against this order.</div>
                    </div>
                </div>
                <div class="ad-card__body ad-card__body--flush">
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Event</th><th>Gateway</th><th>Amount</th><th>Status</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($paymentEvents as $event): ?>
                                <tr>
                                    <td class="ad-mono" style="font-size:11.5px"><?= e((string) $event['event']) ?></td>
                                    <td><?= e(strtoupper((string) $event['gateway'])) ?></td>
                                    <td><?= e(money((float) $event['amount'])) ?></td>
                                    <td><?= e(ucfirst((string) $event['status'])) ?></td>
                                    <td style="white-space:nowrap;font-size:var(--ad-text-sm)"><?= e(format_datetime($event['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- =========================== Right column ========================== -->
    <div>
        <!-- ========================= Invoice ========================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Invoice</div>
                    <div class="ad-card__sub">
                        <?= $invoice === null ? 'Not raised yet' : 'Issued ' . e(format_date((string) $invoice['invoice_date'])) ?>
                    </div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if ($invoice === null): ?>
                    <p class="ad-muted" style="font-size:13px;margin:0 0 12px 0">
                        No invoice has been raised for this order.
                        <?php if ((string) $order['payment_status'] === PAYMENT_STATUS_FAILED): ?>
                            A failed payment is not invoiced automatically.
                        <?php elseif ((string) $order['status'] === ORDER_STATUS_PENDING): ?>
                            One is raised automatically when the order is confirmed or the payment succeeds.
                        <?php endif; ?>
                    </p>
                    <?php if ($canEdit): ?>
                        <form method="post" action="<?= e($selfUrl) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="generate_invoice">
                            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                                <?= icon('file-text', 'w-4 h-4') ?> Raise invoice now
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="font-size:13.5px;display:grid;gap:8px">
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Invoice no.</span>
                            <span class="ad-mono"><strong><?= e((string) $invoice['invoice_number']) ?></strong></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Invoice date</span>
                            <span><?= e(format_date((string) $invoice['invoice_date'])) ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Amount paid</span>
                            <span><?= e(money((float) $invoice['amount_paid'])) ?></span>
                        </div>
                        <?php // What became of it. A cancelled invoice declared no
                              // supply; a credit note reverses one that stands. ?>
                        <?php if ((string) $invoice['status'] === 'cancelled'): ?>
                            <div style="display:flex;justify-content:space-between;gap:12px">
                                <span class="ad-muted">Status</span>
                                <span style="color:#b91c1c;font-weight:600">Cancelled &mdash; nothing supplied</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach (get_credit_notes_for_order($orderId) as $creditNote): ?>
                            <div style="display:flex;justify-content:space-between;gap:12px">
                                <span class="ad-muted">Credit note</span>
                                <span class="ad-mono"><?= e((string) $creditNote['note_number']) ?>
                                    &middot; <?= e(money((float) $creditNote['total_amount'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ((float) $invoice['balance_due'] > 0): ?>
                            <div style="display:flex;justify-content:space-between;gap:12px">
                                <span class="ad-muted">Balance due</span>
                                <span style="color:#b45309;font-weight:600"><?= e(money((float) $invoice['balance_due'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                            <span class="ad-muted">PDF</span>
                            <span>
                                <?php if ($invoicePdfPath !== null): ?>
                                    <span class="sik-status sik-status--green">Stored</span>
                                    <span class="ad-muted" style="font-size:11.5px">
                                        <?= e(format_bytes((int) $invoice['pdf_size'])) ?>
                                    </span>
                                <?php elseif ((string) ($invoice['pdf_error'] ?? '') !== ''): ?>
                                    <span class="sik-status sik-status--red">Failed</span>
                                <?php else: ?>
                                    <span class="sik-status sik-status--amber">Not rendered</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <?php if ((string) ($invoice['pdf_error'] ?? '') !== ''): ?>
                        <p style="font-size:12px;color:#b91c1c;margin:0 0 12px 0">
                            <?= e((string) $invoice['pdf_error']) ?>
                        </p>
                    <?php endif; ?>

                    <div style="display:grid;gap:8px;margin-top:12px">
                        <a class="ad-btn ad-btn--block" href="<?= e($invoiceDownloadUrl) ?>">
                            <?= icon('download', 'w-4 h-4') ?> Download PDF
                        </a>
                        <a class="ad-btn ad-btn--block" target="_blank" rel="noopener"
                           href="<?= e($invoiceDownloadUrl . '&view=1') ?>">
                            <?= icon('eye', 'w-4 h-4') ?> View PDF
                        </a>
                        <?php if ($canEdit): ?>
                            <form method="post" action="<?= e($selfUrl) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="email_invoice">
                                <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                                    <?= icon('mail', 'w-4 h-4') ?> Email invoice to customer
                                </button>
                            </form>
                            <form method="post" action="<?= e($selfUrl) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="regenerate_pdf">
                                <button type="submit" class="ad-btn ad-btn--block ad-btn--sm">
                                    <?= icon('refresh', 'w-4 h-4') ?> Regenerate PDF
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ====================== Send custom email ====================== -->
        <?php if ($canEdit): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Send a custom email</div>
                        <div class="ad-card__sub">Goes to <?= e((string) $order['customer_email']) ?>.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <form class="ad-form" method="post" action="<?= e($selfUrl) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="custom_email">

                        <div class="ad-field">
                            <label class="sik-label" for="customSubject">Subject</label>
                            <input class="sik-input" type="text" id="customSubject" name="subject"
                                   maxlength="200" required
                                   placeholder="About your order <?= e((string) $order['order_number']) ?>">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="customMessage">Message</label>
                            <textarea class="sik-textarea" id="customMessage" name="message" rows="5" required
                                      placeholder="Write in plain text. Blank lines become paragraphs."></textarea>
                            <span class="sik-help">
                                Sent as plain text inside the branded shell — HTML you type is escaped, not rendered.
                            </span>
                        </div>
                        <?php if ($invoice !== null): ?>
                            <div class="ad-field">
                                <label class="sik-switch">
                                    <input type="checkbox" name="attach_invoice" value="1">
                                    <span>Attach invoice <?= e((string) $invoice['invoice_number']) ?></span>
                                </label>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                            <?= icon('send', 'w-4 h-4') ?> Send email
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <div class="ad-card">
                <div class="ad-card__head"><div class="ad-card__title">Update status</div></div>
                <div class="ad-card__body">
                    <?php if ($isClosed): ?>
                        <p class="ad-muted">
                            This order is <?= e(ORDER_STATUSES[$status]) ?> and its stock has already been
                            released, so it can no longer be moved through fulfilment.
                        </p>
                    <?php else: ?>
                        <form class="ad-form" method="post" action="<?= e(admin_url('orders/update-status.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $orderId ?>">
                            <div class="ad-field">
                                <label class="sik-label" for="newStatus">New status</label>
                                <select class="sik-select" id="newStatus" name="status" required>
                                    <?= admin_options($forwardStatuses, $status) ?>
                                </select>
                                <span class="sik-help">
                                    Stock, coupon usage and payment state are handled automatically.
                                </span>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="statusNote">Note (optional)</label>
                                <textarea class="sik-textarea" id="statusNote" name="note" rows="2" maxlength="500"
                                          style="min-height:70px"
                                          placeholder="Shown in the status history"></textarea>
                            </div>
                            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                                <?= icon('check', 'w-4 h-4') ?> Save status
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Customer</div></div>
            <div class="ad-card__body" style="display:grid;gap:9px;font-size:13.5px">
                <div class="ad-cellflex">
                    <span class="ad-avatar"><?= e(initials((string) $order['customer_name'])) ?></span>
                    <span style="min-width:0">
                        <span class="ad-cellflex__name" style="display:block"><?= e($order['customer_name']) ?></span>
                        <span class="ad-cellflex__meta">
                            <?= $order['user_id'] !== null ? 'Registered customer' : 'Guest checkout' ?>
                        </span>
                    </span>
                </div>
                <div><a href="mailto:<?= e_attr($order['customer_email']) ?>"><?= e($order['customer_email']) ?></a></div>
                <div><a href="tel:<?= e_attr($order['customer_phone']) ?>"><?= e($order['customer_phone']) ?></a></div>
                <div class="ad-muted">
                    <?= number_format($lifetimeOrders) ?> lifetime order<?= $lifetimeOrders === 1 ? '' : 's' ?>
                    · <?= e(money($lifetimeValue)) ?> spent
                </div>
                <?php if ($order['user_id'] !== null && admin_can('customers.view')): ?>
                    <a class="ad-btn ad-btn--sm ad-btn--block"
                       href="<?= e(admin_url('customers/view.php?id=' . (int) $order['user_id'])) ?>">
                        <?= icon('user', 'w-4 h-4') ?> Open customer record
                    </a>
                <?php endif; ?>
                <a class="ad-btn ad-btn--sm ad-btn--block"
                   href="<?= e(admin_url('orders/?q=' . urlencode((string) $order['customer_email']))) ?>">
                    <?= icon('cart', 'w-4 h-4') ?> All orders from this customer
                </a>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Shipping address</div></div>
            <div class="ad-card__body" style="font-size:13.5px;line-height:1.7">
                <strong><?= e($order['shipping_name']) ?></strong><br>
                <?= e($order['shipping_address']) ?><br>
                <?php if (!empty($order['shipping_address2'])): ?><?= e($order['shipping_address2']) ?><br><?php endif; ?>
                <?php if (!empty($order['shipping_landmark'])): ?>
                    <span class="ad-muted">Near <?= e($order['shipping_landmark']) ?></span><br>
                <?php endif; ?>
                <?= e($order['shipping_city']) ?>, <?= e($order['shipping_state']) ?> <?= e($order['shipping_pincode']) ?><br>
                <?= e($order['shipping_country']) ?><br>
                <a href="tel:<?= e_attr($order['shipping_phone']) ?>"><?= e($order['shipping_phone']) ?></a>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Billing address</div></div>
            <div class="ad-card__body" style="font-size:13.5px;line-height:1.7">
                <?php if (empty($order['billing_address'])): ?>
                    <span class="ad-muted">Same as the shipping address.</span>
                <?php else: ?>
                    <strong><?= e((string) $order['billing_name']) ?></strong><br>
                    <?= e((string) $order['billing_address']) ?><br>
                    <?= e((string) $order['billing_city']) ?>, <?= e((string) $order['billing_state']) ?> <?= e((string) $order['billing_pincode']) ?><br>
                    <?= e((string) $order['billing_country']) ?>
                    <?php if (!empty($order['billing_phone'])): ?>
                        <br><a href="tel:<?= e_attr($order['billing_phone']) ?>"><?= e($order['billing_phone']) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Payment</div></div>
            <div class="ad-card__body" style="display:grid;gap:9px;font-size:13.5px">
                <div style="display:flex;justify-content:space-between;gap:12px">
                    <span class="ad-muted">Method</span><span><?= e($paymentMethodName) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                    <span class="ad-muted">Status</span><?= admin_state_badge((string) $order['payment_status']) ?>
                </div>
                <div style="display:flex;justify-content:space-between;gap:12px">
                    <span class="ad-muted">Amount</span>
                    <strong><?= e(money((float) ($payment['amount'] ?? $order['total_amount']))) ?></strong>
                </div>
                <?php if (!empty($payment['reference'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:12px">
                        <span class="ad-muted">Reference</span><span class="ad-mono"><?= e($payment['reference']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($payment['paid_at'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:12px">
                        <span class="ad-muted">Paid on</span><span><?= e(format_datetime($payment['paid_at'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // The consignment the courier integration manages for this order, if
        // any. Through the panel helper, which answers "none" on a site that
        // has deployed the code but not run the shipping migration - instead
        // of taking this page down for every order with a missing-table 500.
        $shipPanel       = shipping_order_panel($orderId);
        $liveShipment    = $shipPanel['live'];
        $couriersActive  = $shipPanel['couriers'];
        // The rule shipping_book() applies, so the button is not offered for
        // an order the booking would refuse - a delivered one above all, where
        // a second consignment had the courier collect COD twice.
        $bookBlocked     = shipping_order_block_reason($order);
        ?>
        <div class="ad-card">
            <div class="ad-card__head"><div class="ad-card__title">Shipment</div></div>
            <div class="ad-card__body">
                <?php if ($liveShipment !== null): ?>
                    <?php $liveStatus = (string) $liveShipment['status']; ?>
                    <div style="display:grid;gap:9px;font-size:13.5px">
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Status</span>
                            <span class="sik-status sik-status--<?= e_attr(shipping_status_tone($liveStatus)) ?>">
                                <?= e(shipping_status_label($liveStatus)) ?>
                            </span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Courier</span>
                            <span><?= e((string) ($liveShipment['courier_name'] ?? '')) ?: e((string) $liveShipment['provider_code']) ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">AWB</span>
                            <span class="ad-mono"><?= e((string) ($liveShipment['awb'] ?? '')) ?: 'not assigned' ?></span>
                        </div>
                    </div>
                    <p class="ad-muted" style="margin:10px 0 12px;font-size:var(--ad-text-xs)">
                        Managed by the courier integration - tracking updates arrive automatically.
                    </p>
                    <a class="ad-btn ad-btn--block" href="<?= e(admin_url('shipping/book.php?order=' . $orderId)) ?>">
                        <?= icon('truck', 'w-4 h-4') ?> Manage shipment
                    </a>
                <?php elseif (!$canEdit): ?>
                    <div style="display:grid;gap:9px;font-size:13.5px">
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Courier</span><span><?= e((string) ($order['courier_name'] ?? '')) ?: '—' ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Tracking</span><span class="ad-mono"><?= e((string) ($order['tracking_number'] ?? '')) ?: '—' ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:12px">
                            <span class="ad-muted">Expected</span>
                            <span><?= $order['estimated_delivery'] ? e(format_date($order['estimated_delivery'])) : '—' ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($couriersActive && $bookBlocked === null): ?>
                        <a class="ad-btn ad-btn--primary ad-btn--block" style="margin-bottom:14px"
                           href="<?= e(admin_url('shipping/book.php?order=' . $orderId)) ?>">
                            <?= icon('truck', 'w-4 h-4') ?> Book with a courier
                        </a>
                        <p class="ad-muted" style="margin:0 0 10px;font-size:var(--ad-text-xs)">Or record a parcel sent some other way:</p>
                    <?php endif; ?>
                    <form class="ad-form" method="post" action="<?= e(admin_url('orders/update-shipping.php')) ?>"
                          data-guard-unsaved>
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $orderId ?>">
                        <div class="ad-field">
                            <label class="sik-label" for="courierName">Courier</label>
                            <input class="sik-input" type="text" id="courierName" name="courier_name" maxlength="100"
                                   value="<?= e((string) ($order['courier_name'] ?? '')) ?>"
                                   placeholder="Bluedart, Delhivery, …">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="trackingNumber">Tracking number</label>
                            <input class="sik-input" type="text" id="trackingNumber" name="tracking_number" maxlength="100"
                                   value="<?= e((string) ($order['tracking_number'] ?? '')) ?>">
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="estimatedDelivery">Estimated delivery</label>
                            <input class="sik-input" type="date" id="estimatedDelivery" name="estimated_delivery"
                                   value="<?= e((string) ($order['estimated_delivery'] ?? '')) ?>">
                        </div>
                        <button type="submit" class="ad-btn ad-btn--block">
                            <?= icon('truck', 'w-4 h-4') ?> Save shipment details
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canCancel): ?>
    <div class="ad-modal" id="cancelOrderModal" role="dialog" aria-modal="true" aria-labelledby="cancelOrderTitle">
        <div class="ad-modal__backdrop"></div>
        <div class="ad-modal__panel ad-modal__panel--sm">
            <form method="post" action="<?= e(admin_url('orders/cancel.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $orderId ?>">
                <div class="ad-modal__head">
                    <div class="ad-card__title" id="cancelOrderTitle">Cancel order <?= e($order['order_number']) ?></div>
                    <button type="button" class="ad-btn ad-btn--icon" data-modal-close aria-label="Close">
                        <?= icon('close', 'w-4 h-4') ?>
                    </button>
                </div>
                <div class="ad-modal__body">
                    <p class="ad-muted" style="margin-bottom:12px">
                        Every unit goes back into stock and any coupon use is rolled back.
                        The customer is emailed automatically.
                    </p>
                    <div class="ad-field">
                        <label class="sik-label" for="cancelReason">Reason <span class="req">*</span></label>
                        <textarea class="sik-textarea" id="cancelReason" name="reason" rows="3" required
                                  maxlength="255" style="min-height:80px"
                                  placeholder="Why is this order being cancelled?"></textarea>
                    </div>
                </div>
                <div class="ad-modal__foot" style="display:flex;gap:9px;justify-content:flex-end">
                    <button type="button" class="ad-btn" data-modal-close>Keep order</button>
                    <button type="submit" class="ad-btn ad-btn--danger">Cancel this order</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
