<?php
/**
 * ShopInnKart - Invoice PDF download.
 *
 * The only route to a stored invoice PDF. The files themselves live under
 * storage/invoices/, which storage/.htaccess and the root RedirectMatch both
 * block, so there is no way to reach one without passing the check below.
 *
 * Authorisation mirrors invoice.php exactly: the signed-in owner, a guest who
 * placed the order in this session, or staff with orders.view.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

// ---------------------------------------------------------------------------
//  Locate the order
// ---------------------------------------------------------------------------
$orderNumber = trim((string) input('order', ''));
$invoiceNumber = trim((string) input('invoice', ''));
$orderId = input_int('id', 0);

$invoice = null;
if ($invoiceNumber !== '') {
    $invoice = get_invoice_by_number($invoiceNumber);
    $order = $invoice === null ? null : get_order((int) $invoice['order_id']);
} else {
    $order = $orderNumber !== '' ? get_order_by_number($orderNumber) : ($orderId > 0 ? get_order($orderId) : null);
}

if ($order === null) {
    require __DIR__ . '/404.php';
    exit;
}

// ---------------------------------------------------------------------------
//  Authorise before anything else touches the filesystem
// ---------------------------------------------------------------------------
if (!invoice_viewable($invoice ?? [], $order)) {
    require __DIR__ . '/403.php';
    exit;
}

if ($invoice === null) {
    $invoice = get_invoice_for_order((int) $order['id']);
}

// An order that reached this page without an invoice gets one now, provided it
// is in a state that deserves one.
if ($invoice === null) {
    $invoice = issue_order_invoice($order);
}

if ($invoice === null) {
    http_response_code(404);
    seo_set(['title' => 'Invoice not available', 'robots' => 'noindex, nofollow']);
    require INCLUDES_PATH . '/header.php';
    ?>
    <div class="sik-container sik-section sik-section--sm">
        <div class="sik-empty">
            <?= icon('file-text', 'w-12 h-12') ?>
            <p class="sik-empty__title">No invoice for this order yet</p>
            <p class="sik-empty__text">
                An invoice is raised once the order is confirmed or the payment succeeds.
                Order <strong><?= e((string) $order['order_number']) ?></strong> is currently
                <strong><?= e(ORDER_STATUSES[$order['status']] ?? (string) $order['status']) ?></strong>.
            </p>
            <a class="sik-btn sik-btn--outline sik-btn--sm"
               href="<?= e(url('order-details.php?order=' . rawurlencode((string) $order['order_number']))) ?>">Back to order</a>
        </div>
    </div>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
//  Rate limit: rendering a PDF is not free, and this endpoint is public-facing
// ---------------------------------------------------------------------------
if (!mail_rate_limit_hit('invoice_download_' . (int) $invoice['id'], 20, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    exit('Too many invoice downloads. Please wait a minute and try again.');
}

$pdf = invoice_render_pdf($invoice);

if (!$pdf['ok'] || $pdf['path'] === null) {
    // Fall back to the printable HTML invoice rather than a dead end.
    ErrorHandler::log('warning', 'Invoice PDF unavailable on download for ' . $invoice['invoice_number'] . ': ' . (string) $pdf['error']);
    flash('warning', 'The PDF could not be produced just now. Here is the printable invoice instead.');
    redirect(url('invoice.php?order=' . rawurlencode((string) $order['order_number'])));
    exit;
}

// ---------------------------------------------------------------------------
//  Stream it
// ---------------------------------------------------------------------------
$size = filesize($pdf['path']);
$disposition = input('view', '') === '1' ? 'inline' : 'attachment';
$filename = $pdf['filename'] ?? 'invoice.pdf';

// Anything already buffered would corrupt the binary body.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . ($size === false ? 0 : $size));
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
// An invoice is personal data — never let a shared cache keep a copy.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($pdf['path']);
exit;
