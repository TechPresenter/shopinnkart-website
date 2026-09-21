<?php
/**
 * GET /api/cart/get.php - the authoritative cart state.
 *
 * Every cart endpoint answers with the same shape so one response can refresh
 * the badge, the mini-cart drawer and the totals panel together.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);

$items = cart_items();

json_success('OK', [
    'count'  => cart_count(),
    'items'  => $items,
    'totals' => cart_totals($items),
]);
