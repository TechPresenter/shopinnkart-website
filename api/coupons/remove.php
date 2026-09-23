<?php
/**
 * POST /api/coupons/remove.php - detach the coupon from the cart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

cart_remove_coupon();

// `carts` was just written - drop the memoised row so the totals below see it.
// cart_row(), not get_or_create_cart(): a session with no cart has nothing to
// remove a coupon from, and must not be given one for asking.
cart_row(true);

$items = cart_items();

json_success('Coupon removed.', [
    'count'  => cart_count(),
    'items'  => $items,
    'totals' => cart_totals($items),
]);
