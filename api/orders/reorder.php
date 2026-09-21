<?php
/**
 * POST /api/orders/reorder.php
 *
 * Puts a past order's lines back in the cart. Items whose product was
 * removed, unpublished or sold out are skipped rather than failing the whole
 * request - the response reports how many made it.
 *
 * Params: order_id
 * Returns: { count, items, totals, added, skipped, skipped_items }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('order_reorder', 15, 300);

$validator = new Validator(request_all(), ['order_id' => 'Order']);
$validator->required('order_id')->integer('order_id');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$order = api_require_own_order((int) $user['id'], request_int('order_id', 0));
$lines = get_order_items((int) $order['id']);

if ($lines === []) {
    json_error('This order has no items to reorder.', [], 409);
}

$added = 0;
$skipped = 0;
$skippedItems = [];

foreach ($lines as $line) {
    // The product row is gone entirely - nothing left to add.
    if ($line['product_id'] === null) {
        $skipped++;
        $skippedItems[] = (string) $line['product_name'];
        continue;
    }

    // cart_add re-reads the product, its visibility and its live stock, so an
    // unavailable line simply reports back instead of being added.
    $result = cart_add(
        (int) $line['product_id'],
        $line['variant_id'] === null ? null : (int) $line['variant_id'],
        max(1, (int) $line['quantity'])
    );

    if ($result['ok']) {
        $added++;
    } else {
        $skipped++;
        $skippedItems[] = (string) $line['product_name'];
    }
}

if ($added === 0) {
    json_error('None of the items from this order are available right now.', [], 409);
}

if ($skipped === 0) {
    $message = $added === 1
        ? '1 item added back to your cart.'
        : $added . ' items added back to your cart.';
} else {
    $message = sprintf(
        '%d of %d items added back to your cart. %d %s no longer available.',
        $added,
        $added + $skipped,
        $skipped,
        $skipped === 1 ? 'item is' : 'items are'
    );
}

$items = cart_items();

json_success($message, [
    'count'         => cart_count(),
    'items'         => $items,
    'totals'        => cart_totals($items),
    'added'         => $added,
    'skipped'       => $skipped,
    'skipped_items' => $skippedItems,
]);
