<?php
/**
 * ShopInnKart Admin - Delete a brand.
 *
 * Nothing blocks the delete: fk_product_brand is ON DELETE SET NULL, so the
 * products survive without a brand. That is a silent data change, so it only
 * runs when the form explicitly acknowledged it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('brands.delete');

$id = input_int('id');
$brand = $id > 0
    ? Database::fetch('SELECT `id`, `name`, `logo` FROM `brands` WHERE `id` = :id', ['id' => $id])
    : null;

if ($brand === null) {
    flash('error', 'That brand no longer exists.');
    redirect(admin_url('brands/'));
}

$productCount = Database::count('products', '`brand_id` = :id', ['id' => $id]);

if ((string) input('confirm', '0') !== '1') {
    flash('error', $productCount > 0
        ? 'Deleting "' . $brand['name'] . '" would leave ' . $productCount . ' product'
            . ($productCount === 1 ? '' : 's') . ' with no brand. Confirm from the brand list or edit screen.'
        : 'That delete was not confirmed. Use the delete button on the brand list or edit screen.');
    redirect(admin_url('brands/'));
}

delete_upload($brand['logo']);
Database::delete('brands', '`id` = :id', ['id' => $id]);
// The record is gone, so its extended SEO row has nothing to describe.
seo_entity_meta_delete('brand', $id);


log_activity(
    'brand.deleted',
    'brand',
    $id,
    'Deleted brand "' . $brand['name'] . '"'
        . ($productCount > 0 ? ' — ' . $productCount . ' product(s) left without a brand' : '')
);
admin_after_write();

flash('success', $productCount > 0
    ? 'Brand "' . $brand['name'] . '" deleted. ' . $productCount . ' product'
        . ($productCount === 1 ? '' : 's') . ' now ha' . ($productCount === 1 ? 's' : 've') . ' no brand.'
    : 'Brand "' . $brand['name'] . '" deleted.');
redirect(admin_url('brands/'));
