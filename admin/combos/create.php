<?php
/**
 * ShopInnKart Admin - Create a combo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Combos reuse the coupons permission rather than declaring their own: there
// is no `combos` module in PERMISSION_MODULES, and an unregistered key
// resolves to "nobody", which would hide this screen from every role.
$admin = admin_require('coupons.create');

require_once __DIR__ . '/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = combo_form_save(null);
    if ($result['ok']) {
        flash('success', 'Combo created.');
        redirect(admin_url('combos/edit.php?id=' . (int) $result['id']));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$combo = combo_form_state(null);

$isEdit       = false;
$pageTitle    = 'Add Combo';
$pageSubtitle = 'A set of products sold together for less than they cost apart.';
$breadcrumbs  = [
    ['label' => 'Dashboard',     'url' => admin_url('dashboard.php')],
    ['label' => 'Combo Offers',  'url' => admin_url('combos/')],
    ['label' => 'Add Combo'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
