<?php
/**
 * POST /api/cart/add-combo.php - add a combo (bundle) to the cart.
 *
 * The browser sends a combo id and how many sets it wants. Everything else -
 * which products are in the set, how many of each, what it costs and whether
 * there is stock for it - is decided server-side by cart_add_combo(), because
 * the combo's contents and price are exactly what a client must not be able to
 * choose.
 *
 * The set lands in the basket as its component lines under one `combo_group`,
 * so stock, tax and fulfilment keep running through the paths that already
 * handle products. See includes/combo-functions.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

$v = new Validator(request_all(), ['combo_id' => 'Combo']);
$v->required('combo_id')->integer('combo_id')
  ->integer('quantity')->between('quantity', 1, 99);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$comboId = request_int('combo_id');
$result  = cart_add_combo($comboId, max(1, request_int('quantity', 1)));

if (!$result['ok']) {
    // Same status split as add.php: a combo that does not exist (or is no
    // longer one a shopper may see) is a 404; anything else - out of stock, or
    // a set that has stopped being worth selling - is a 409.
    $status = combo_find($comboId) === null ? 404 : 409;
    json_error($result['message'], [], $status);
}

$items = cart_items();

json_success($result['message'], [
    'count'       => cart_count(),
    'items'       => $items,
    'totals'      => cart_totals($items),
    'combo_group' => $result['group'],
    'open_drawer' => true,
]);
