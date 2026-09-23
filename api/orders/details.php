<?php
/**
 * GET /api/orders/details.php
 *
 * One order belonging to the signed-in customer, with its lines, status
 * history and delivery timeline.
 *
 * Params: id OR order_number
 * Returns: { order, items, history, timeline }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_require_method(['GET']);
$user = api_require_login();

$orderId = request_int('id', 0);
$orderNumber = trim((string) request_input('order_number', ''));

if ($orderId <= 0 && $orderNumber === '') {
    json_validation_error(['id' => 'An order id or order number is required.']);
}

// Answers the same 404 whether the order is someone else's or does not exist.
$order = api_require_own_order((int) $user['id'], $orderId, $orderNumber);
$orderId = (int) $order['id'];

$items = array_map(static fn ($item) => [
    'id'           => (int) $item['id'],
    'product_id'   => $item['product_id'] === null ? null : (int) $item['product_id'],
    'variant_id'   => $item['variant_id'] === null ? null : (int) $item['variant_id'],
    'product_name' => (string) $item['product_name'],
    'product_sku'  => (string) $item['product_sku'],
    'variant_name' => $item['variant_name'],
    'quantity'     => (int) $item['quantity'],
    'mrp'          => (float) $item['mrp'],
    'price'        => (float) $item['price'],
    'subtotal'     => (float) $item['subtotal'],
    'total'        => (float) $item['total'],
    'image_url'    => $item['image_url'],
    'url'          => $item['url'],
    'price_display'    => $item['price_display'],
    'subtotal_display' => $item['subtotal_display'],
    // A deleted product cannot be reviewed or bought again.
    'is_available' => $item['product_id'] !== null && $item['url'] !== null,
], get_order_items($orderId));

$history = array_map(static function ($entry) {
    $pill = api_order_status_pill((string) $entry['status']);
    return [
        'status'     => (string) $entry['status'],
        'label'      => $pill['label'],
        'color'      => $pill['color'],
        'note'       => $entry['note'],
        'changed_by' => (string) $entry['changed_by'],
        'at'         => $entry['created_at'],
        'at_display' => format_datetime($entry['created_at']),
    ];
}, get_order_history($orderId));

$timeline = array_map(static fn ($step) => [
    'status'     => $step['status'],
    'label'      => $step['label'],
    'done'       => (bool) $step['done'],
    'current'    => (bool) ($step['current'] ?? false),
    'at'         => $step['at'],
    'at_display' => $step['at'] ? format_datetime($step['at']) : null,
], order_timeline($order));

json_success('OK', [
    'order'    => api_order_public($order),
    'items'    => $items,
    'history'  => $history,
    'timeline' => $timeline,
]);
