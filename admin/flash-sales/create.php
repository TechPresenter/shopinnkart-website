<?php
/**
 * ShopInnKart Admin - Create a flash sale.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('flash_sales.create');

require_once __DIR__ . '/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = flash_sale_form_save(null);
    if ($result['ok']) {
        flash('success', 'Flash sale created.');
        redirect(admin_url('flash-sales/edit.php?id=' . (int) $result['id']));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$sale = flash_sale_form_state(null);

$isEdit       = false;
$pageTitle    = 'Add Flash Sale';
$pageSubtitle = 'A short window where selected products drop to a sale price.';
$breadcrumbs  = [
    ['label' => 'Dashboard',    'url' => admin_url('dashboard.php')],
    ['label' => 'Flash Sales',  'url' => admin_url('flash-sales/')],
    ['label' => 'Add Flash Sale'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
