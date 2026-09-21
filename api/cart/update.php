<?php
/**
 * POST /api/cart/update.php - change the quantity of one cart line.
 *
 * Quantity 0 removes the line. cart_update() clamps the requested quantity to
 * live stock and the product's per-order limit, so the reply can differ from
 * what was asked for.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

$v = new Validator(request_all(), ['item_id' => 'Cart item', 'quantity' => 'Quantity']);
$v->required('item_id')->integer('item_id')
  ->required('quantity')->integer('quantity')->between('quantity', 0, 999);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$result = cart_update(request_int('item_id'), request_int('quantity'));

if (!$result['ok']) {
    json_error($result['message'], [], 409);
}

$items = cart_items();

json_success($result['message'], [
    'count'    => cart_count(),
    'items'    => $items,
    'totals'   => cart_totals($items),
    'quantity' => $result['quantity'] ?? 0,
]);
