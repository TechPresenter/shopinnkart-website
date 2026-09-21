<?php
/**
 * ShopInnKart Admin - CSV export of the current product filter selection.
 *
 * Reads exactly the same clause the list screen renders, and writes exactly
 * the column order import.php expects, so a file can go out, be edited in a
 * spreadsheet, and come straight back in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.view');

require_once ADMIN_PATH . '/products/_shared.php';

$query = product_list_filters();
$order = product_list_order();

$products = Database::fetchAll(
    'SELECT p.*, b.`name` AS brand_name, c.`name` AS category_name
     FROM `products` p
     LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
     LEFT JOIN `categories` c ON c.`id` = p.`category_id`
     WHERE ' . $query['where'] . '
     ORDER BY ' . $order['sql'],
    $query['params']
);

// One pass for every tag link beats a subquery per row.
$tagsByProduct = [];
foreach (Database::fetchAll(
    'SELECT pt.`product_id`, t.`name` FROM `product_tags` pt
     INNER JOIN `tags` t ON t.`id` = pt.`tag_id` ORDER BY t.`name`'
) as $link) {
    $tagsByProduct[(int) $link['product_id']][] = (string) $link['name'];
}

$columns = product_csv_columns();

$rows = [];
foreach ($products as $product) {
    $productId = (int) $product['id'];

    $values = [
        'sku'                 => $product['sku'],
        'name'                => $product['name'],
        'slug'                => $product['slug'],
        'brand'               => $product['brand_name'],
        'category'            => $product['category_name'],
        'short_description'   => $product['short_description'],
        'description'         => $product['description'],
        'price'               => $product['price'],
        'sale_price'          => $product['sale_price'],
        'cost_price'          => $product['cost_price'],
        'stock'               => $product['stock'],
        'low_stock_threshold' => $product['low_stock_threshold'],
        'weight'              => $product['weight'],
        'tax_rate'            => $product['tax_rate'],
        'hsn_code'            => $product['hsn_code'],
        'warranty'            => $product['warranty'],
        'emi_text'            => $product['emi_text'],
        'manufacturer'        => $product['manufacturer'],
        'model_number'        => $product['model_number'],
        'part_number'         => $product['part_number'],
        'compatibility'       => $product['compatibility'],
        'min_order_qty'       => $product['min_order_qty'],
        'max_order_qty'       => $product['max_order_qty'],
        'cod_available'       => (int) $product['cod_available'],
        'free_shipping'       => (int) $product['free_shipping'],
        'status'              => $product['status'],
        'is_featured'         => (int) $product['is_featured'],
        'is_new_arrival'      => (int) $product['is_new_arrival'],
        'is_best_seller'      => (int) $product['is_best_seller'],
        'is_trending'         => (int) $product['is_trending'],
        'badge_text'          => $product['badge_text'],
        'badge_color'         => $product['badge_color'],
        'video_url'           => $product['video_url'],
        'meta_title'          => $product['meta_title'],
        'meta_description'    => $product['meta_description'],
        'main_image'          => $product['main_image'],
        'tags'                => implode('|', $tagsByProduct[$productId] ?? []),
    ];

    $row = [];
    foreach ($columns as $column) {
        $row[] = $values[$column] ?? '';
    }
    $rows[] = $row;
}

log_activity('product.exported', 'product', null, count($rows) . ' product(s) exported to CSV');

stream_csv('products-' . date('Y-m-d-His') . '.csv', $columns, $rows);
