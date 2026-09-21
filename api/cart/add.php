<?php
/**
 * POST /api/cart/add.php - add a product (optionally a variant) to the cart.
 *
 * The browser only sends ids and a quantity. Availability, stock caps and every
 * price come from cart_add()/cart_items() server-side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

$v = new Validator(request_all(), ['product_id' => 'Product', 'variant_id' => 'Option']);
$v->required('product_id')->integer('product_id')
  ->integer('variant_id')
  ->integer('quantity')->between('quantity', 1, 999);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$variantId = request_int('variant_id', 0);
$quantity  = request_int('quantity', 1);

$result = cart_add(request_int('product_id'), $variantId > 0 ? $variantId : null, max(1, $quantity));

if (!$result['ok']) {
    // Match the documented status table: a product that does not exist (or is no
    // longer visible) is a 404; a stock cap or line-limit clash is a 409.
    $status = get_product(request_int('product_id')) === null ? 404 : 409;
    json_error($result['message'], [], $status);
}

$items = cart_items();

json_success($result['message'], [
    'count'       => cart_count(),
    'items'       => $items,
    'totals'      => cart_totals($items),
    'item_id'     => $result['item_id'],
    'open_drawer' => true,
]);
