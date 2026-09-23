<?php
/**
 * POST /api/cart/coupon.php - apply or remove the cart's coupon.
 *
 * Checkout could not do either: its own POST places the order, so a coupon
 * form there had nowhere to submit. This gives both pages one endpoint, and it
 * calls cart_apply_coupon()/cart_remove_coupon() rather than re-deriving
 * anything - a second discount calculation is a second answer able to disagree
 * with the one the order is actually written from.
 *
 * Answers the same shape as the other cart endpoints so a single response can
 * refresh the badge, the mini-cart and the totals panel together.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

$action = strtolower(trim((string) request_input('action', 'apply')));

if ($action === 'remove') {
    cart_remove_coupon();
    $items = cart_items();

    json_success('Coupon removed.', [
        'count'  => cart_count(),
        'items'  => $items,
        'totals' => cart_totals($items),
    ]);
}

$v = new Validator(request_all(), ['code' => 'Coupon code']);
$v->required('code')->max('code', 60);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$result = cart_apply_coupon((string) request_input('code', ''));

if (!$result['ok']) {
    // cart_apply_coupon() owns the guessing budget (see coupon_guess_allowed);
    // an exhausted one is a 429 here rather than another 422, so the browser
    // can tell "wrong code" from "stop trying" without reading the sentence.
    if (!empty($result['rate_limited'])) {
        if (!headers_sent()) {
            header('Retry-After: ' . rate_limit_retry_after(900));
        }
        json_error($result['message'], [], 429, 'rate_limit');
    }

    // 422, not 404: the code may well exist and simply not apply to this cart
    // (minimum spend, first-order only, expired). The message says which.
    json_error($result['message'], ['code' => $result['message']], 422);
}

$items = cart_items();

json_success($result['message'], [
    'count'  => cart_count(),
    'items'  => $items,
    'totals' => cart_totals($items),
]);
