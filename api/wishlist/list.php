<?php
/**
 * GET /api/wishlist/list.php - the wishlist as decorated product rows.
 *
 * Guests get their session-backed list; signing in merges it into the account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);

// A disabled wishlist has no list to hand back - reading it is refused for the
// same reason writing it is.
api_require_feature('wishlist');

// wishlist_products() drops rows that are no longer visible, so count the
// rendered items rather than the stored ids.
$items = wishlist_products();

json_success('OK', [
    'count' => count($items),
    'items' => $items,
]);
