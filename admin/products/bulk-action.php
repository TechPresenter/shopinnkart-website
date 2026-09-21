<?php
/**
 * ShopInnKart Admin - Bulk actions on the product list.
 *
 * POST only. Everything here is a status/flag flip except delete, which
 * needs its own permission and obeys the same order guard as delete.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('products.edit');

require_once ADMIN_PATH . '/products/_shared.php';

$listUrl = admin_url('products/');

/** action => the columns it writes. */
$updates = [
    'activate'   => ['status' => 'active'],
    'deactivate' => ['status' => 'inactive'],
    'feature'    => ['is_featured' => 1],
    'unfeature'  => ['is_featured' => 0],
    'mark_new'   => ['is_new_arrival' => 1],
    'mark_best'  => ['is_best_seller' => 1],
];

$labels = [
    'activate'   => 'activated',
    'deactivate' => 'deactivated',
    'feature'    => 'marked as featured',
    'unfeature'  => 'removed from featured',
    'mark_new'   => 'marked as new arrivals',
    'mark_best'  => 'marked as best sellers',
];

$action = (string) input('bulk_action', '');
$ids    = array_values(array_unique(array_filter(array_map('intval', input_array('ids')))));

if ($ids === []) {
    flash('error', 'Select at least one product first.');
    redirect_back($listUrl);
}

if ($action !== 'delete' && !isset($updates[$action])) {
    flash('error', 'That bulk action is not recognised.');
    redirect_back($listUrl);
}

[$placeholders, $params] = Database::inPlaceholders($ids, 'id');

// ---------------------------------------------------------------------------
//  Flag / status changes
// ---------------------------------------------------------------------------
if ($action !== 'delete') {
    $affected = Database::update('products', $updates[$action], '`id` IN (' . $placeholders . ')', $params);

    log_activity('product.bulk_' . $action, 'product', null,
        $affected . ' product(s) ' . $labels[$action]);
    admin_after_write();

    flash('success', $affected . ' product(s) ' . $labels[$action] . '.');
    redirect_back($listUrl);
}

// ---------------------------------------------------------------------------
//  Delete
// ---------------------------------------------------------------------------
if (!admin_can('products.delete')) {
    flash('error', 'You do not have permission to delete products.');
    redirect_back($listUrl);
}

$products = Database::fetchAll(
    'SELECT `id`, `name`, `sku`, `status`, `main_image`, `hover_image`
     FROM `products` WHERE `id` IN (' . $placeholders . ')',
    $params
);

$deleted     = 0;
$deactivated = 0;
$blocked     = [];

foreach ($products as $product) {
    $productId = (int) $product['id'];

    if (product_live_order_lines($productId) > 0) {
        // Same rule as delete.php: sold products are demoted, never removed.
        if ($product['status'] === 'active') {
            Database::update('products', ['status' => 'inactive'], '`id` = :id', ['id' => $productId]);
            $deactivated++;
        }
        $blocked[] = (string) $product['name'];
        continue;
    }

    $files = [$product['main_image'], $product['hover_image']];
    $files = array_merge($files, Database::fetchColumnAll(
        'SELECT `image` FROM `product_images` WHERE `product_id` = :id',
        ['id' => $productId]
    ));
    $files = array_merge($files, Database::fetchColumnAll(
        'SELECT `image` FROM `product_variants` WHERE `product_id` = :id AND `image` IS NOT NULL',
        ['id' => $productId]
    ));

    Database::delete('products', '`id` = :id', ['id' => $productId]);

    foreach ($files as $file) {
        delete_upload($file === null ? null : (string) $file);
    }
    $deleted++;
}

log_activity('product.bulk_delete', 'product', null,
    $deleted . ' product(s) deleted, ' . count($blocked) . ' kept because they appear on live orders');
admin_after_write();

if ($deleted > 0) {
    flash('success', $deleted . ' product(s) deleted.');
}
if ($blocked !== []) {
    flash('warning', count($blocked) . ' product(s) appear on orders that are not cancelled and cannot be deleted: '
        . str_limit(implode(', ', $blocked), 180) . '. '
        . ($deactivated > 0 ? $deactivated . ' of them were set to Inactive instead.' : 'They were already inactive.'));
}

redirect_back($listUrl);
