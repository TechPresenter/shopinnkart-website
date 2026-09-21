<?php
/**
 * ShopInnKart API - Product lookup for the admin marketing pickers.
 *
 * Deals, flash sales and coupon restrictions all attach products by name or
 * SKU. The storefront endpoint (/api/products/search.php) writes every query
 * into search_logs, which would fill the shopper-facing trending list with
 * whatever an admin typed, so the pickers ask this endpoint instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);
api_require_admin();

// Anyone who can open one of the marketing forms may look products up there.
$allowed = [
    'products.view',
    'coupons.create', 'coupons.edit',
    'deals.create', 'deals.edit',
    'flash_sales.create', 'flash_sales.edit',
];
if (!array_filter($allowed, 'admin_can')) {
    json_error('You do not have permission to browse products.', [], 403, 'forbidden');
}

$term  = trim((string) input('q', ''));
$limit = max(1, min(20, input_int('limit', 8)));

if (mb_strlen($term) < 2) {
    json_success('OK', ['products' => []]);
}

// LIMIT cannot be bound with emulated prepares off, so it is cast here after
// being clamped above.
$products = Database::fetchAll(
    "SELECT p.`id`, p.`name`, p.`sku`, p.`price`, p.`sale_price`, p.`stock`, p.`main_image`,
            p.`status`, b.`name` AS brand_name
     FROM `products` p
     LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
     WHERE p.`name` LIKE :q_name OR p.`sku` LIKE :q_sku OR p.`model_number` LIKE :q_model
     ORDER BY p.`sold_count` DESC, p.`name` ASC
     LIMIT " . (int) $limit,
    ['q_name' => '%' . $term . '%', 'q_sku' => '%' . $term . '%', 'q_model' => '%' . $term . '%']
);

$payload = array_map(static function (array $product): array {
    $price = ($product['sale_price'] !== null && (float) $product['sale_price'] > 0)
        ? (float) $product['sale_price']
        : (float) $product['price'];

    // "meta" is the single grey line the picker prints under the name; building
    // it here keeps the currency formatting on the PHP side.
    $meta = array_filter([
        (string) $product['sku'],
        money($price),
        (int) $product['stock'] > 0 ? (int) $product['stock'] . ' in stock' : 'Out of stock',
        $product['status'] !== 'active' ? ucfirst((string) $product['status']) : '',
    ]);

    return [
        'id'        => (int) $product['id'],
        'name'      => (string) $product['name'],
        'sku'       => (string) $product['sku'],
        'brand'     => (string) ($product['brand_name'] ?? ''),
        'price'     => $price,
        'stock'     => (int) $product['stock'],
        'image_url' => img_url($product['main_image']),
        'meta'      => implode(' · ', $meta),
    ];
}, $products);

json_success('OK', ['products' => $payload]);
