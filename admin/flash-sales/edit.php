<?php
/**
 * ShopInnKart Admin - Edit a flash sale.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('flash_sales.edit');

require_once __DIR__ . '/_save.php';

$id = input_int('id');
if ($id <= 0 || !Database::exists('flash_sales', '`id` = :id', ['id' => $id])) {
    flash('error', 'That flash sale no longer exists.');
    redirect(admin_url('flash-sales/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = flash_sale_form_save($id);
    if ($result['ok']) {
        flash('success', 'Flash sale saved.');
        redirect(admin_url('flash-sales/edit.php?id=' . $id));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$sale = flash_sale_form_state($id);

$totals = Database::fetch(
    'SELECT COUNT(*) AS product_count, COALESCE(SUM(`stock_sold`), 0) AS sold
     FROM `flash_sale_products` WHERE `flash_sale_id` = :id',
    ['id' => $id]
) ?? ['product_count' => 0, 'sold' => 0];

$isEdit       = true;
$pageTitle    = 'Edit Flash Sale';
$pageSubtitle = $sale['name'] . ' · ' . (int) $totals['product_count'] . ' product'
    . ((int) $totals['product_count'] === 1 ? '' : 's') . ' · ' . number_format((int) $totals['sold']) . ' units sold';
$breadcrumbs  = [
    ['label' => 'Dashboard',   'url' => admin_url('dashboard.php')],
    ['label' => 'Flash Sales', 'url' => admin_url('flash-sales/')],
    ['label' => (string) $sale['name']],
];

$pageActions = '';
if (admin_can('flash_sales.delete')) {
    // Written out instead of admin_delete_form() so the page action can be a
    // full labelled button rather than an icon.
    $confirm = ('Delete "' . $sale['name'] . '"? Its product prices and sold counters go with it.');
    $pageActions = '<form method="post" action="' . e(admin_url('flash-sales/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
