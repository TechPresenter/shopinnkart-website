<?php
/**
 * ShopInnKart Admin - Edit a product.
 *
 * Same form and same save path as create.php; only the target row differs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.edit');

require_once ADMIN_PATH . '/products/_save.php';

$productId = max(0, (int) ($_GET['id'] ?? 0));
$product   = $productId > 0
    ? Database::fetch('SELECT `id`, `name`, `sku`, `slug`, `status` FROM `products` WHERE `id` = :id', ['id' => $productId])
    : null;

if ($product === null) {
    flash('error', 'That product could not be found.');
    redirect(admin_url('products/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = product_form_save($productId);
    if ($result['ok']) {
        flash('success', 'Product saved.');
        redirect(admin_url('products/edit.php?id=' . $productId));
    }
    $errors = $result['errors'];
}

$state = product_form_state($productId);
if ($state === []) {
    flash('error', 'That product could not be found.');
    redirect(admin_url('products/'));
}

$pageTitle    = 'Edit Product';
$pageSubtitle = $product['name'] . '  ·  ' . $product['sku'];
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products', 'url' => admin_url('products/')],
    ['label' => str_limit((string) $product['name'], 48)],
];

$pageActions = '<a class="ad-btn" href="' . e(product_url((string) $product['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' Storefront</a>'
    . '<a class="ad-btn" href="' . e(admin_url('products/view.php?id=' . $productId)) . '">'
    . icon('eye', 'w-4 h-4') . ' View</a>';

if (admin_can('products.create')) {
    $pageActions .= '<form method="post" action="' . e(admin_url('products/duplicate.php')) . '" class="ad-inline-form">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $productId . '">'
        . '<button type="submit" class="ad-btn">' . icon('copy', 'w-4 h-4') . ' Duplicate</button>'
        . '</form>';
}

if (admin_can('products.delete')) {
    $pageActions .= admin_delete_form(
        admin_url('products/delete.php'),
        $productId,
        'Delete "' . $product['name'] . '"? This also deletes its images, specs and variants.'
    );
}

require ADMIN_PATH . '/includes/header.php';
require ADMIN_PATH . '/products/_form.php';
require ADMIN_PATH . '/includes/footer.php';
