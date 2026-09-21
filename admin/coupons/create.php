<?php
/**
 * ShopInnKart Admin - Create a coupon.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('coupons.create');

require_once __DIR__ . '/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = coupon_form_save(null);
    if ($result['ok']) {
        flash('success', 'Coupon created.');
        redirect(admin_url('coupons/edit.php?id=' . (int) $result['id']));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$coupon = coupon_form_state(null);

$isEdit       = false;
$pageTitle    = 'Add Coupon';
$pageSubtitle = 'A code customers type at the cart. Restrictions are optional.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Coupons',   'url' => admin_url('coupons/')],
    ['label' => 'Add Coupon'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
