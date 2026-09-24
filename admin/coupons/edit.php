<?php
/**
 * ShopInnKart Admin - Edit a coupon.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('coupons.edit');

require_once __DIR__ . '/_save.php';

$id = input_int('id');
$exists = $id > 0 && Database::exists('coupons', '`id` = :id', ['id' => $id]);

if (!$exists) {
    flash('error', 'That coupon no longer exists.');
    redirect(admin_url('coupons/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = coupon_form_save($id);
    if ($result['ok']) {
        flash('success', 'Coupon saved.');
        redirect(admin_url('coupons/edit.php?id=' . $id));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$coupon = coupon_form_state($id);

$redemptions = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `coupon_usage` WHERE `coupon_id` = :id',
    ['id' => $id]
);
$given = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(`discount`), 0) FROM `coupon_usage` WHERE `coupon_id` = :id',
    ['id' => $id]
);

$isEdit       = true;
$pageTitle    = 'Edit Coupon';
$pageSubtitle = $coupon['code'] . ' · ' . number_format($redemptions) . ' redemption'
    . ($redemptions === 1 ? '' : 's') . ' · ' . money($given) . ' given away';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Coupons',   'url' => admin_url('coupons/')],
    ['label' => (string) $coupon['code']],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('coupons/usage.php?id=' . $id)) . '">'
    . icon('users', 'w-4 h-4') . ' Usage</a>';

if (admin_can('coupons.delete')) {
    // Written out instead of admin_delete_form() so the page action can be a
    // full labelled button rather than an icon.
    $confirm = ('Delete "' . $coupon['code'] . '"? Its redemption history goes with it.');
    $pageActions .= '<form method="post" action="' . e(admin_url('coupons/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
