<?php
/**
 * POST /api/orders/track.php
 *
 * Public order tracking. No login, but an order number on its own proves
 * nothing - the caller must also supply the email address or mobile number
 * recorded on the order, otherwise guessed numbers would expose a stranger's
 * delivery details.
 *
 * Params: order_number, email OR phone
 * Returns: { html } - the tracking panel, byte-for-byte the same component
 *          track-order.php renders server side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';
require_once INCLUDES_PATH . '/order-tracking.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('order_track', 12, 300);

$validator = new Validator(request_all(), [
    'order_number' => 'Order number',
    'email'        => 'Email address',
    'phone'        => 'Mobile number',
]);

$validator->required('order_number')->max('order_number', 40)
    ->requiredAny(['email', 'phone'], 'Enter the email address or mobile number used on the order.')
    ->email('email')
    ->phone('phone');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$orderNumber = trim((string) request_input('order_number', ''));
$email = strtolower(trim((string) request_input('email', '')));
$phone = normalize_phone((string) request_input('phone', ''));

$order = get_order_by_number($orderNumber);

// One message for "no such order" and "contact does not match", so the
// response cannot be used to confirm that an order number exists.
$notFound = 'We could not find an order matching those details. Please check the order number and try again.';

if ($order === null) {
    json_error($notFound, [], 404);
}

$matched = false;
if ($email !== '' && hash_equals(strtolower((string) $order['customer_email']), $email)) {
    $matched = true;
}
if (!$matched && $phone !== null) {
    foreach ([$order['customer_phone'], $order['shipping_phone']] as $stored) {
        if (normalize_phone((string) $stored) === $phone) {
            $matched = true;
            break;
        }
    }
}

if (!$matched) {
    json_error($notFound, [], 404);
}

$orderId = (int) $order['id'];
$pill = api_order_status_pill((string) $order['status']);

// invoice.php has its own, stricter gate (owner session or staff), so the
// button is only offered to a viewer it will actually open for.
$invoiceUrl = null;
if (order_has_invoice($order)) {
    $viewerId = current_user_id();
    $owns = $viewerId !== null && $order['user_id'] !== null && (int) $order['user_id'] === $viewerId;
    if ($owns || session_placed_order((string) $order['order_number'])) {
        $invoiceUrl = url('invoice.php?order=' . rawurlencode((string) $order['order_number']));
    }
}

$html = order_tracking_panel_html($order, get_order_items($orderId), [
    'anchor'      => 'tracking-result',
    'invoice_url' => $invoiceUrl,
]);

json_success('Order found.', [
    'html'         => $html,
    'order_number' => (string) $order['order_number'],
    'status'       => (string) $order['status'],
    'status_label' => $pill['label'],
]);
