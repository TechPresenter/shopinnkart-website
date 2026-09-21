<?php
/**
 * GET /api/orders/list.php
 *
 * The signed-in customer's order history, paginated.
 *
 * Params: page, per_page, status
 * Returns: { items, pagination, status, counts }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_require_method(['GET']);
$user = api_require_login();

$page = max(1, request_int('page', 1));
$perPage = min(50, max(1, request_int('per_page', 10)));

$status = (string) request_input('status', '');
if ($status !== '' && !array_key_exists($status, ORDER_STATUSES)) {
    $status = '';
}

$result = customer_orders((int) $user['id'], $page, $perPage, $status);

$items = [];
foreach ($result['items'] as $order) {
    $row = api_order_public($order);
    $row['item_count'] = (int) $order['item_count'];
    $row['units'] = array_sum(array_map(static fn ($item) => (int) $item['quantity'], $order['items']));
    $row['items'] = array_map(static fn ($item) => [
        'id'           => (int) $item['id'],
        'product_id'   => $item['product_id'] === null ? null : (int) $item['product_id'],
        'product_name' => (string) $item['product_name'],
        'variant_name' => $item['variant_name'],
        'quantity'     => (int) $item['quantity'],
        'image_url'    => $item['image_url'],
        'url'          => $item['url'],
        'price_display'    => $item['price_display'],
        'subtotal_display' => $item['subtotal_display'],
    ], $order['items']);
    $items[] = $row;
}

// Tab counts so the account page can label its status filters without a
// second round trip.
$counts = Database::fetchPairs(
    'SELECT `status`, COUNT(*) FROM `orders` WHERE `user_id` = :uid GROUP BY `status`',
    ['uid' => (int) $user['id']]
);

json_paginated(
    $items,
    $result['pagination'],
    $items === [] ? 'You have not placed any orders yet.' : 'OK',
    [
        'status' => $status,
        'counts' => array_map('intval', $counts),
    ]
);
