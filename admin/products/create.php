<?php
/**
 * ShopInnKart Admin - Add a product.
 *
 * The form lives in _form.php and the writing lives in _save.php, so this
 * file only decides "new row" and where to go afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.create');

require_once ADMIN_PATH . '/products/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = product_form_save(null);
    if ($result['ok']) {
        flash('success', 'Product created. Add images and variants below.');
        redirect(admin_url('products/edit.php?id=' . (int) $result['id']));
    }
    $errors = $result['errors'];
}

$state = product_form_state(null);

$pageTitle    = 'Add Product';
$pageSubtitle = 'Everything except the name, slug, SKU and price can be filled in later.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products', 'url' => admin_url('products/')],
    ['label' => 'Add Product'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('products/import.php')) . '">'
    . icon('upload', 'w-4 h-4') . ' Import CSV</a>';

require ADMIN_PATH . '/includes/header.php';
require ADMIN_PATH . '/products/_form.php';
require ADMIN_PATH . '/includes/footer.php';
