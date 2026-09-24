<?php
/**
 * ShopInnKart Admin - Edit a combo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Combos reuse the coupons permission rather than declaring their own: there
// is no `combos` module in PERMISSION_MODULES, and an unregistered key
// resolves to "nobody", which would hide this screen from every role.
$admin = admin_require('coupons.edit');

require_once __DIR__ . '/_save.php';

$id = input_int('id');
if ($id <= 0 || !Database::exists('combos', '`id` = :id', ['id' => $id])) {
    flash('error', 'That combo no longer exists.');
    redirect(admin_url('combos/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = combo_form_save($id);
    if ($result['ok']) {
        flash('success', 'Combo saved.');
        redirect(admin_url('combos/edit.php?id=' . $id));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$combo = combo_form_state($id);
$label = trim((string) ($combo['name'] ?? '')) !== '' ? (string) $combo['name'] : 'Combo #' . $id;

$isEdit       = true;
$pageTitle    = 'Edit Combo';
$pageSubtitle = $label . ' · ' . (combo_status_options()[(string) $combo['status']] ?? (string) $combo['status']);
$breadcrumbs  = [
    ['label' => 'Dashboard',    'url' => admin_url('dashboard.php')],
    ['label' => 'Combo Offers', 'url' => admin_url('combos/')],
    ['label' => str_limit($label, 48)],
];

// combo_url() is the one place the storefront path is spelled out; the link is
// offered even for a draft, because seeing the 404 is how an operator learns
// the combo is not live yet.
$pageActions = '<a class="ad-btn" href="' . e(combo_url((string) $combo['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

if (admin_can('coupons.delete')) {
    // Written out instead of admin_delete_form() so the page action can be a
    // full labelled button rather than an icon.
    $confirm = ('Delete "' . $label . '"? Its uploaded images are removed from the server too.');
    $pageActions .= '<form method="post" action="' . e(admin_url('combos/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
