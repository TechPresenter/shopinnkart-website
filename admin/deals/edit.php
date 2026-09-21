<?php
/**
 * ShopInnKart Admin - Edit a deal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('deals.edit');

require_once __DIR__ . '/_save.php';

$id = input_int('id');
if ($id <= 0 || !Database::exists('deals', '`id` = :id', ['id' => $id])) {
    flash('error', 'That deal no longer exists.');
    redirect(admin_url('deals/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = deal_form_save($id);
    if ($result['ok']) {
        flash('success', 'Deal saved.');
        redirect(admin_url('deals/edit.php?id=' . $id));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$deal = deal_form_state($id);

$productCount = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `deal_products` WHERE `deal_id` = :id',
    ['id' => $id]
);

$isEdit       = true;
$pageTitle    = 'Edit Deal';
$pageSubtitle = $deal['title'] . ' · ' . $productCount . ' product' . ($productCount === 1 ? '' : 's')
    . ' · ' . number_format((int) ($deal['stock_sold'] ?? 0)) . ' sold';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Deals',     'url' => admin_url('deals/')],
    ['label' => (string) $deal['title']],
];

$pageActions = '';
if (admin_can('deals.delete')) {
    // Written out instead of admin_delete_form() so the page action can be a
    // full labelled button rather than an icon.
    $confirm = json_encode('Delete "' . $deal['title'] . '"? Its product list goes with it.');
    $pageActions = '<form method="post" action="' . e(admin_url('deals/delete.php')) . '" class="ad-inline-form"'
        . ' onsubmit="return confirm(' . e_attr((string) $confirm) . ')">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
