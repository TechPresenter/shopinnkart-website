<?php
/**
 * ShopInnKart - Printable tax invoice.
 *
 * Same gate as order-details.php: a signed-in customer only reaches an order
 * whose user_id matches theirs. Guest checkouts have no user_id at all, so
 * the session that placed the order is the only other way in - without that
 * a guest could never obtain the invoice for an order they just paid for.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

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

$userId = current_user_id();
$owns = $userId !== null && $order['user_id'] !== null && (int) $order['user_id'] === $userId;

/**
 * Staff need this page too. admin/orders/index.php and admin/orders/view.php
 * both link here for "Print Invoice", but the only ways in were "owns the
 * order" or "placed it in this session" — neither is ever true for an admin,
 * so that button returned 403 every single time.
 *
 * admin_can() lives in includes/auth.php (already loaded) and returns false
 * for anyone without an admin session, so this grants nothing to customers.
 */
$isStaff = admin_can('orders.view');

// The same 404 as "no such order" above. A 403 here told a signed-in customer
// walking sequential ids exactly which ones exist - the store's order volume,
// and a list of valid numbers to try on the tracking page.
if (!$owns && !$isStaff && !session_placed_order((string) $order['order_number'])) {
    require __DIR__ . '/404.php';
    exit;
}

$orderId = (int) $order['id'];

// hsn_code lives on the product, not on the order line snapshot.
$items = Database::fetchAll(
    'SELECT oi.*, p.`hsn_code`
     FROM `order_items` oi
     LEFT JOIN `products` p ON p.`id` = oi.`product_id`
     WHERE oi.`order_id` = :id
     ORDER BY oi.`id`',
    ['id' => $orderId]
);

$storeName    = (string) setting('store_name', SITE_NAME);
$storeAddress = (string) setting('store_address', '');
$storeEmail   = (string) setting('store_email', '');
$storePhone   = (string) setting('store_phone', '');
$gstin        = (string) setting('gst_number', '');
$taxLabel     = (string) setting('tax_label', 'GST');

/**
 * Prefer the issued invoice record. Its number is sequential, unique and the
 * one printed on the PDF the customer already has; the derived
 * "PREFIX-<order number>" below is only a fallback for orders that predate
 * invoice records, so the page never shows a number that contradicts the PDF.
 */
$invoiceRecord = get_invoice_for_order($orderId);

$invoiceNumber = $invoiceRecord !== null
    ? (string) $invoiceRecord['invoice_number']
    : (string) setting('invoice_prefix', INVOICE_PREFIX) . '-' . (string) $order['order_number'];

$invoiceDate = $invoiceRecord !== null
    ? (string) $invoiceRecord['invoice_date']
    : ($order['confirmed_at'] ?: $order['created_at']);

$paymentName = (string) (Database::fetchColumn(
    'SELECT `name` FROM `payment_methods` WHERE `code` = :code LIMIT 1',
    ['code' => (string) $order['payment_method']]
) ?? ucfirst(str_replace('_', ' ', (string) $order['payment_method'])));

$paymentStatus = (string) $order['payment_status'];

// Bill-to falls back to the delivery address, which is what create_order()
// stores when the customer keeps "billing same as delivery" ticked.
$billing = [
    'name'    => (string) ($order['billing_name'] ?: $order['shipping_name']),
    'phone'   => (string) ($order['billing_phone'] ?: $order['shipping_phone']),
    'address' => (string) ($order['billing_address'] ?: $order['shipping_address']),
    'city'    => (string) ($order['billing_city'] ?: $order['shipping_city']),
    'state'   => (string) ($order['billing_state'] ?: $order['shipping_state']),
    'pincode' => (string) ($order['billing_pincode'] ?: $order['shipping_pincode']),
    'country' => (string) ($order['billing_country'] ?: $order['shipping_country']),
];

$totalTaxable = 0.0;
$totalTax = 0.0;
foreach ($items as $item) {
    // A line whose stored total matches its subtotal was priced tax-inclusive,
    // so the tax has to come out of the line rather than sit on top of it.
    $inclusive = abs((float) $item['total'] - (float) $item['subtotal']) < 0.01;
    $totalTaxable += $inclusive
        ? money_round((float) $item['subtotal'] - (float) $item['tax_amount'])
        : (float) $item['subtotal'];
    $totalTax += (float) $item['tax_amount'];
}

seo_set([
    'title'  => 'Invoice ' . $invoiceNumber,
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';
?>

<div class="sik-container sik-section sik-section--sm">

    <div class="flex flex-wrap items-center justify-between gap-3 no-print" style="margin-bottom:var(--sp-5)">
        <a class="sik-btn sik-btn--ghost sik-btn--sm"
           href="<?= e(url('order-success.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
            <?= icon('arrow-left', 'w-4 h-4') ?> Back to order
        </a>
        <div class="flex flex-wrap gap-2">
            <?php if ($invoiceRecord !== null): ?>
                <a class="sik-btn sik-btn--primary sik-btn--sm"
                   href="<?= e(url('invoice-download.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                    <?= icon('download', 'w-4 h-4') ?><span class="sik-btn__label">Download PDF</span>
                </a>
            <?php endif; ?>
            <button type="button" class="sik-btn sik-btn--navy sik-btn--sm" onclick="window.print()">
                <?= icon('printer', 'w-4 h-4') ?><span class="sik-btn__label">Print</span>
            </button>
        </div>
    </div>

    <?php
    // A cancelled order must not hand out a document that reads like a live
    // tax invoice. Which of the two notices shows depends on whether anything
    // was actually supplied - see invoice_settle_for_status().
    $isCancelledInvoice = $invoiceRecord !== null && (string) $invoiceRecord['status'] === 'cancelled';
    $creditNotes = $invoiceRecord === null ? [] : get_credit_notes_for_order($orderId);
    ?>

    <?php if ($isCancelledInvoice): ?>
        <div class="sik-alert sik-alert--warning" style="margin-bottom:var(--sp-4)">
            <strong>This invoice has been cancelled.</strong>
            Order <?= e((string) $order['order_number']) ?> was
            <?= e(strtolower(ORDER_STATUSES[$order['status']] ?? (string) $order['status'])) ?>
            before anything was dispatched, so nothing was supplied against it and nothing is owed.
        </div>
    <?php elseif ($creditNotes !== []): ?>
        <div class="sik-alert sik-alert--info" style="margin-bottom:var(--sp-4)">
            <strong>Credited.</strong>
            <?= count($creditNotes) === 1 ? 'A credit note has' : count($creditNotes) . ' credit notes have' ?>
            been raised against this invoice:
            <?php foreach ($creditNotes as $index => $note): ?>
                <?= $index > 0 ? ', ' : '' ?><strong><?= e((string) $note['note_number']) ?></strong>
                for <?= e(money((float) $note['total_amount'])) ?>
            <?php endforeach; ?>.
            The invoice itself stands; the credit note reverses it.
        </div>
    <?php endif; ?>

    <div class="sik-invoice sik-panel">

        <!-- =========================== Header =========================== -->
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <img src="<?= e(brand_logo_src()) ?>"
                     alt="<?= e($storeName) ?>" width="180" height="42" style="height:40px;width:auto">
                <p class="text-xs text-muted" style="margin-top:var(--sp-3);line-height:1.65;max-width:280px">
                    <?php if ($storeAddress !== ''): ?><?= e($storeAddress) ?><br><?php endif; ?>
                    <?php if ($storeEmail !== ''): ?><?= e($storeEmail) ?><?php endif; ?>
                    <?php if ($storePhone !== ''): ?> &middot; <?= e($storePhone) ?><?php endif; ?>
                </p>
                <?php if ($gstin !== ''): ?>
                    <p class="text-xs font-semibold" style="margin-top:var(--sp-2)">GSTIN: <?= e($gstin) ?></p>
                <?php endif; ?>
            </div>

            <div class="text-right">
                <h1 style="font-size:19px;font-weight:800;color:var(--sik-navy);text-transform:uppercase;letter-spacing:.06em">
                    Tax Invoice
                </h1>
                <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.8">
                    Invoice no. <strong style="color:var(--sik-text)"><?= e($invoiceNumber) ?></strong><br>
                    Invoice date <strong style="color:var(--sik-text)"><?= e(format_date($invoiceDate)) ?></strong><br>
                    Order no. <strong style="color:var(--sik-text)"><?= e((string) $order['order_number']) ?></strong><br>
                    Order date <strong style="color:var(--sik-text)"><?= e(format_date($order['created_at'])) ?></strong>
                </p>
            </div>
        </div>

        <hr class="sik-divider">

        <!-- ========================== Addresses ========================== -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-muted font-semibold">Billed to</p>
                <p class="text-sm" style="margin-top:var(--sp-2);line-height:1.7">
                    <strong><?= e($billing['name']) ?></strong><br>
                    <?= e($billing['address']) ?><br>
                    <?= e($billing['city']) ?>, <?= e($billing['state']) ?> <?= e($billing['pincode']) ?><br>
                    <?= e($billing['country']) ?><br>
                    <?= e($billing['phone']) ?><br>
                    <?= e((string) $order['customer_email']) ?>
                </p>
            </div>

            <div>
                <p class="text-xs uppercase tracking-wide text-muted font-semibold">Shipped to</p>
                <p class="text-sm" style="margin-top:var(--sp-2);line-height:1.7">
                    <strong><?= e((string) $order['shipping_name']) ?></strong><br>
                    <?= e((string) $order['shipping_address']) ?><?php if (!empty($order['shipping_address2'])): ?><br><?= e((string) $order['shipping_address2']) ?><?php endif; ?><br>
                    <?php if (!empty($order['shipping_landmark'])): ?>Near <?= e((string) $order['shipping_landmark']) ?><br><?php endif; ?>
                    <?= e((string) $order['shipping_city']) ?>, <?= e((string) $order['shipping_state']) ?> <?= e((string) $order['shipping_pincode']) ?><br>
                    <?= e((string) $order['shipping_country']) ?><br>
                    <?= e((string) $order['shipping_phone']) ?>
                </p>
            </div>
        </div>

        <hr class="sik-divider">

        <!-- ============================ Items ============================ -->
        <?php if ($items === []): ?>
            <div class="sik-empty sik-empty--sm">
                <?= icon('package', 'w-12 h-12') ?>
                <p class="sik-empty__title">This order has no invoiced lines</p>
                <p class="sik-empty__text">Contact support quoting <?= e((string) $order['order_number']) ?> and we will reissue it.</p>
                <a class="sik-btn sik-btn--outline sik-btn--sm no-print" href="<?= e(url('contact.php')) ?>">Contact support</a>
            </div>
        <?php else: ?>
            <div class="sik-scroll-x">
                <table style="min-width:640px">
                    <caption class="sik-sr">Invoice line items with <?= e($taxLabel) ?> breakdown</caption>
                    <thead>
                        <tr>
                            <th scope="col" style="width:34px">#</th>
                            <th scope="col">Item</th>
                            <th scope="col">HSN</th>
                            <th scope="col" style="text-align:right">Unit price</th>
                            <th scope="col" style="text-align:right">Qty</th>
                            <th scope="col" style="text-align:right">Taxable value</th>
                            <th scope="col" style="text-align:right"><?= e($taxLabel) ?> %</th>
                            <th scope="col" style="text-align:right"><?= e($taxLabel) ?> amount</th>
                            <th scope="col" style="text-align:right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                            <?php
                            $lineInclusive = abs((float) $item['total'] - (float) $item['subtotal']) < 0.01;
                            $lineTaxable = $lineInclusive
                                ? money_round((float) $item['subtotal'] - (float) $item['tax_amount'])
                                : (float) $item['subtotal'];
                            ?>
                            <tr>
                                <td><?= e((string) ($index + 1)) ?></td>
                                <td>
                                    <strong style="font-size:12.5px"><?= e((string) $item['product_name']) ?></strong>
                                    <?php if (!empty($item['variant_name'])): ?>
                                        <span class="block text-xs text-muted"><?= e((string) $item['variant_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="block text-xs text-muted">SKU <?= e((string) $item['product_sku']) ?></span>
                                </td>
                                <td><?= e($item['hsn_code'] !== null && $item['hsn_code'] !== '' ? (string) $item['hsn_code'] : '—') ?></td>
                                <td style="text-align:right"><?= e(money((float) $item['price'])) ?></td>
                                <td style="text-align:right"><?= e((string) $item['quantity']) ?></td>
                                <td style="text-align:right"><?= e(money($lineTaxable)) ?></td>
                                <td style="text-align:right"><?= e(rtrim(rtrim(number_format((float) $item['tax_rate'], 2, '.', ''), '0'), '.')) ?>%</td>
                                <td style="text-align:right"><?= e(money((float) $item['tax_amount'])) ?></td>
                                <td style="text-align:right"><strong><?= e(money((float) $item['total'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <?php /* An order-level coupon is applied after these lines, so say so
                                     rather than let two different GST figures look contradictory. */ ?>
                            <td colspan="5" style="text-align:right;font-weight:700">
                                Line totals<?= (float) $order['discount_amount'] > 0 ? ' (before order discount)' : '' ?>
                            </td>
                            <td style="text-align:right;font-weight:700"><?= e(money($totalTaxable)) ?></td>
                            <td></td>
                            <td style="text-align:right;font-weight:700"><?= e(money($totalTax)) ?></td>
                            <td style="text-align:right;font-weight:700"><?= e(money($totalTaxable + $totalTax)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>

        <!-- =========================== Summary =========================== -->
        <div class="flex justify-end" style="margin-top:var(--sp-6)">
            <div style="width:100%;max-width:340px">
                <div class="sik-summary">
                    <div class="sik-summary__row">
                        <span class="text-muted">Subtotal</span>
                        <span><?= e(money((float) $order['subtotal'])) ?></span>
                    </div>

                    <div class="sik-summary__row">
                        <span class="text-muted">
                            Discount
                            <?php if (!empty($order['coupon_code'])): ?>
                                <span class="sik-badge sik-badge--soft"><?= e((string) $order['coupon_code']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span><?= (float) $order['discount_amount'] > 0 ? '- ' . e(money((float) $order['discount_amount'])) : e(money(0)) ?></span>
                    </div>

                    <div class="sik-summary__row">
                        <span class="text-muted">Shipping</span>
                        <span><?= (float) $order['shipping_amount'] > 0 ? e(money((float) $order['shipping_amount'])) : 'FREE' ?></span>
                    </div>

                    <div class="sik-summary__row">
                        <span class="text-muted">
                            <?= e($taxLabel) ?>
                            <?php if ($totalTax > 0 && abs((float) $order['subtotal'] - ($totalTaxable + $totalTax)) < 1.0): ?>
                                <span class="text-xs">(included in item prices)</span>
                            <?php endif; ?>
                        </span>
                        <span><?= e(money((float) $order['tax_amount'])) ?></span>
                    </div>

                    <div class="sik-summary__row sik-summary__row--total">
                        <span>Grand total</span>
                        <span><?= e(money((float) $order['total_amount'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <hr class="sik-divider">

        <!-- =========================== Payment =========================== -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-muted font-semibold">Payment</p>
                <p class="text-sm" style="margin-top:var(--sp-2)">
                    <strong><?= e($paymentName) ?></strong> &middot;
                    <?= e(PAYMENT_STATUSES[$paymentStatus] ?? ucfirst($paymentStatus)) ?>
                </p>
            </div>
            <span class="sik-status sik-status--<?= $paymentStatus === PAYMENT_STATUS_PAID ? 'green' : 'amber' ?>">
                <?= e(PAYMENT_STATUSES[$paymentStatus] ?? ucfirst($paymentStatus)) ?>
            </span>
        </div>

        <p class="text-xs text-muted" style="margin-top:var(--sp-6);line-height:1.7">
            This is a computer generated invoice and does not need a signature.
            Prices are in <?= e((string) setting('currency_code', CURRENCY)) ?>.
            Keep it for warranty and returns &mdash; service centres ask for it as proof of purchase.
        </p>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
