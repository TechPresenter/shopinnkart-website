<?php
/**
 * POST /api/orders/cancel.php
 *
 * Customer-initiated cancellation. cancel_order() restores stock, releases
 * the coupon and journals the status change.
 *
 * Params: order_id, reason
 * Returns: { order_id, status, status_label, status_color }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('order_cancel', 10, 300);

$validator = new Validator(request_all(), [
    'order_id' => 'Order',
    'reason'   => 'Reason',
]);
$validator->required('order_id')->integer('order_id')
    ->required('reason')->min('reason', 3)->max('reason', 255);

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$order = api_require_own_order((int) $user['id'], request_int('order_id', 0));

// Whether an order is still cancellable is a server decision - the button
// may have been rendered before the warehouse moved it on.
if (!can_cancel_order($order)) {
    json_error(
        'This order can no longer be cancelled. Please contact support for help.',
        [],
        409
    );
}

$reason = trim((string) request_input('reason', ''));
$result = cancel_order((int) $order['id'], $reason, 'customer');

if (!$result['ok']) {
    json_error($result['message'], [], 409);
}

$updated = get_order((int) $order['id']);
$pill = api_order_status_pill((string) $updated['status']);

json_success($result['message'], [
    'order_id'     => (int) $updated['id'],
    'order_number' => (string) $updated['order_number'],
    'status'       => (string) $updated['status'],
    'status_label' => $pill['label'],
    'status_color' => $pill['color'],
]);
