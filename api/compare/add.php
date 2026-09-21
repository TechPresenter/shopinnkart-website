<?php
/**
 * POST /api/compare/add.php - toggle a product in the comparison list.
 *
 * One endpoint for both directions so a single button can flip state; the
 * reply says which way it went and hands back the redrawn bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/_helpers.php';

api_require_method(['POST']);
api_require_csrf();

// Admin > Settings > Widgets can switch comparison off entirely, or restrict it
// to signed-in customers.
api_require_feature('compare');

$v = new Validator(request_all(), ['product_id' => 'Product']);
$v->required('product_id')->integer('product_id');

if ($v->fails()) {
    json_validation_error($v->errors());
}

$result = compare_toggle(request_int('product_id'));

if (!$result['ok']) {
    // A refusal from the feature gate carries its own status and code; a full
    // list is a conflict with the current state and a missing product is a 404.
    // Either way the client just shows the message.
    if (isset($result['code'])) {
        json_error($result['message'], [], (int) $result['status'], (string) $result['code']);
    }
    $status = get_product(request_int('product_id')) === null ? 404 : 409;
    json_error($result['message'], [], $status);
}

$items = compare_bar_items(true);

json_success($result['message'], [
    'added' => $result['added'],
    'count' => count($items),
    'items' => $items,
    'max'   => compare_max(),
]);
