<?php
/**
 * Shared payload builder for the compare endpoints.
 *
 * The floating compare bar only needs an id, a name, a link and a thumbnail,
 * and all three endpoints must answer with exactly the same shape.
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/init.php';

if (!function_exists('compare_bar_items')) {
    /**
     * @param bool $fresh Re-read the ids first - compare_product_ids() memoises,
     *                    so a list built after an add/remove would be stale.
     */
    function compare_bar_items(bool $fresh = false): array
    {
        if ($fresh) {
            compare_product_ids(true);
        }

        $items = [];
        foreach (compare_products()['products'] as $product) {
            $items[] = [
                'id'        => (int) $product['id'],
                'name'      => (string) $product['name'],
                'url'       => $product['url'],
                'image_url' => $product['image_url'],
            ];
        }
        return $items;
    }
}
