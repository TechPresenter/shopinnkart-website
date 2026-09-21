<?php
/**
 * ShopInnKart - Brand list endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);

$featuredOnly = input_bool('featured');
$brands = all_brands($featuredOnly);

$limit = input_int('limit');
if ($limit > 0) {
    $brands = array_slice($brands, 0, min(100, $limit));
}

$items = array_map(static fn (array $brand): array => [
    'id'            => (int) $brand['id'],
    'name'          => (string) $brand['name'],
    'slug'          => (string) $brand['slug'],
    'url'           => brand_url((string) $brand['slug']),
    'logo_url'      => img_url($brand['logo'], 'assets/images/placeholders/no-image.svg'),
    'description'   => str_limit($brand['description'], 140),
    'is_featured'   => (int) $brand['is_featured'] === 1,
    'product_count' => (int) ($brand['product_count'] ?? 0),
], $brands);

json_success('OK', [
    'brands' => $items,
    'total'  => count($items),
]);
