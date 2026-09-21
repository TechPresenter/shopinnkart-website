<?php
/**
 * POST /api/cart/remove.php - drop one line from the cart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

$v = new Validator(request_all(), ['item_id' => 'Cart item']);
$v->required('item_id')->integer('item_id');

if ($v->fails()) {
    json_validation_error($v->errors());
}

// cart_remove() scopes the delete to the current cart, so a foreign id simply misses.
if (!cart_remove(request_int('item_id'))) {
    json_error('That item is not in your cart.', [], 404);
}

$items = cart_items();

json_success('Item removed.', [
    'count'  => cart_count(),
    'items'  => $items,
    'totals' => cart_totals($items),
]);
