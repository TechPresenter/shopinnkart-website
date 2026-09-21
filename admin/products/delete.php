<?php
/**
 * ShopInnKart Admin - Delete a product.
 *
 * POST only. A product that has been bought is part of somebody's order
 * history, so it is deactivated rather than removed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('products.delete');

require_once ADMIN_PATH . '/products/_shared.php';

$productId = max(0, (int) input('id', 0));
$product   = $productId > 0
    ? Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => $productId])
    : null;

if ($product === null) {
    flash('error', 'That product could not be found.');
    redirect(admin_url('products/'));
}

$liveLines = product_live_order_lines($productId);

if ($liveLines > 0) {
    // Deleting would blank the product on those order lines, so deactivate.
    if ($product['status'] === 'active') {
        Database::update('products', ['status' => 'inactive'], '`id` = :id', ['id' => $productId]);
        log_activity('product.deactivated', 'product', $productId,
            'Deactivated "' . $product['name'] . '" instead of deleting (' . $liveLines . ' live order line(s))');
        admin_after_write();

        flash('warning', '"' . $product['name'] . '" appears on ' . $liveLines
            . ' order line(s) that are not cancelled, so it cannot be deleted. It has been set to Inactive instead — '
            . 'it is gone from the storefront and the order history stays intact.');
    } else {
        flash('warning', '"' . $product['name'] . '" appears on ' . $liveLines
            . ' order line(s) that are not cancelled, so it cannot be deleted. It is already '
            . $product['status'] . ', so nothing changed.');
    }

    redirect(admin_url('products/edit.php?id=' . $productId));
}

// Collect every file this product owns before the rows disappear.
$files = [$product['main_image'], $product['hover_image']];
$files = array_merge($files, Database::fetchColumnAll(
    'SELECT `image` FROM `product_images` WHERE `product_id` = :id',
    ['id' => $productId]
));
$files = array_merge($files, Database::fetchColumnAll(
    'SELECT `image` FROM `product_variants` WHERE `product_id` = :id AND `image` IS NOT NULL',
    ['id' => $productId]
));

// Every child table cascades from products, so one delete is enough.
Database::delete('products', '`id` = :id', ['id' => $productId]);

foreach ($files as $file) {
    delete_upload($file === null ? null : (string) $file);
}

log_activity('product.deleted', 'product', $productId,
    'Deleted product "' . $product['name'] . '" (' . $product['sku'] . ')');
admin_after_write();

flash('success', '"' . $product['name'] . '" and everything it owned have been deleted.');
redirect(admin_url('products/'));
