<?php
/**
 * POST /api/coupons/validate.php - check a coupon, and optionally apply it.
 *
 * apply=false previews the discount without touching the cart; apply=true
 * stores it. Either way the discount is computed from the database cart, never
 * from anything the browser sent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

// Coupon codes are guessable, so cap how fast they can be tried. The budget is
// the one cart_apply_coupon() uses (includes/cart-functions.php): it lives in
// the database and is keyed on the address and the basket, so the old trick of
// dropping the cookie to reset a $_SESSION counter no longer works, and this
// endpoint no longer has a quota of its own separate from /api/cart/coupon.php.
$guessBudget = coupon_guess_allowed();
if (!$guessBudget['ok']) {
    if (!headers_sent()) {
        header('Retry-After: ' . max(1, (int) $guessBudget['retry_after']));
    }
    json_error($guessBudget['message'], [], 429, 'rate_limit');
}

$v = new Validator(request_all(), ['code' => 'Coupon code']);
$v->required('code')->max('code', 60);

if ($v->fails()) {
    json_validation_error($v->errors());
}

$code  = (string) request_input('code', '');
$apply = request_bool('apply');

$items = cart_items();
if ($items === []) {
    json_error('Your cart is empty.', ['code' => 'Add something to your cart before using a coupon.'], 409);
}

if ($apply) {
    $result = cart_apply_coupon($code);
    if (!$result['ok']) {
        if (!empty($result['rate_limited'])) {
            if (!headers_sent()) {
                header('Retry-After: ' . rate_limit_retry_after(900));
            }
            json_error($result['message'], [], 429, 'rate_limit');
        }
        json_error($result['message'], ['code' => $result['message']], 422);
    }

    // `carts` was just written - drop the memoised row so the totals below see it.
    get_or_create_cart(true);

    $items  = cart_items();
    $totals = cart_totals($items);

    json_success($result['message'], [
        'count'  => cart_count(),
        'items'  => $items,
        'totals' => $totals,
        'coupon' => [
            'code'             => $totals['coupon_code'],
            'discount'         => $totals['discount'],
            'discount_display' => $totals['display']['discount'],
            'applied'          => true,
        ],
    ]);
}

// Preview only: report what the coupon would take off without storing it. A
// preview is exactly as good as an apply for guessing, so a wrong code costs
// the same here as it does there.
$subtotal = money_round((float) array_sum(array_column($items, 'subtotal')));
$check    = validate_coupon($code, $subtotal, $items);
coupon_guess_record($check['ok'] ? null : ($check['reason'] ?? 'unknown'));

if (!$check['ok']) {
    json_error($check['message'], ['code' => $check['message']], 422);
}

json_success('This coupon is valid.', [
    'count'  => cart_count(),
    'items'  => $items,
    'totals' => cart_totals($items),
    'coupon' => [
        'code'             => $check['coupon']['code'],
        'discount'         => $check['discount'],
        'discount_display' => money($check['discount']),
        'free_shipping'    => $check['free_shipping'],
        'applied'          => false,
    ],
]);
