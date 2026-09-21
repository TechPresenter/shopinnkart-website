<?php
/**
 * POST /api/wishlist/remove.php - take a product off the wishlist.
 *
 * Idempotent: removing something that is already gone still succeeds, because
 * the caller only wants the product out of the list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_require_feature('wishlist');

$v = new Validator(request_all(), ['product_id' => 'Product']);
$v->required('product_id')->integer('product_id');

if ($v->fails()) {
    json_validation_error($v->errors());
}

$removed = wishlist_remove(request_int('product_id'));

json_success($removed ? 'Removed from wishlist.' : 'That item was not in your wishlist.', [
    'count' => wishlist_count(),
]);
