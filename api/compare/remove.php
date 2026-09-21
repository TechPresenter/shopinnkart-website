<?php
/**
 * POST /api/compare/remove.php - drop one product from the comparison list,
 * or empty it with clear=true.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/_helpers.php';

api_require_method(['POST']);
api_require_csrf();
api_require_feature('compare');

$clear = request_bool('clear');

if ($clear) {
    compare_clear();
    $message = 'Comparison cleared.';
} else {
    $v = new Validator(request_all(), ['product_id' => 'Product']);
    $v->required('product_id')->integer('product_id');

    if ($v->fails()) {
        json_validation_error($v->errors());
    }

    // Idempotent: the caller wants the product out, so a miss is not an error.
    $message = compare_remove(request_int('product_id'))
        ? 'Removed from compare.'
        : 'That product was not in your comparison.';
}

$items = compare_bar_items(true);

json_success($message, [
    'count' => count($items),
    'items' => $items,
    'max'   => compare_max(),
]);
