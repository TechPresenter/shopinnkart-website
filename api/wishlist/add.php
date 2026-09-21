<?php
/**
 * POST /api/wishlist/add.php - toggle a product in the wishlist.
 *
 * The same endpoint adds and removes so a heart button needs one call; the
 * reply says which way it went.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

// Admin > Settings > Widgets can switch the wishlist off entirely, or restrict
// it to signed-in customers. Refused here as well as inside wishlist_toggle():
// the guard is what makes the status code right (403/401 rather than a 409).
api_require_feature('wishlist');

$v = new Validator(request_all(), ['product_id' => 'Product']);
$v->required('product_id')->integer('product_id');

if ($v->fails()) {
    json_validation_error($v->errors());
}

$result = wishlist_toggle(request_int('product_id'));

if (!$result['ok']) {
    // A refusal from the feature gate carries its own status and code (the
    // setting can change between the guard above and this call); otherwise
    // match the documented status table: an unknown product is a 404, not a
    // conflict with existing state.
    if (isset($result['code'])) {
        json_error($result['message'], [], (int) $result['status'], (string) $result['code']);
    }
    $status = get_product(request_int('product_id')) === null ? 404 : 409;
    json_error($result['message'], [], $status);
}

json_success($result['message'], [
    'added' => $result['added'],
    'count' => $result['count'],
]);
