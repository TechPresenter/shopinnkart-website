<?php
/**
 * ShopInnKart Admin - Edit a banner.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.edit');

require_once __DIR__ . '/_save.php';

$id = input_int('id');
if ($id <= 0 || !Database::exists('banners', '`id` = :id', ['id' => $id])) {
    flash('error', 'That banner no longer exists.');
    redirect(admin_url('banners/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $result = banner_form_save($id);
    if ($result['ok']) {
        flash('success', 'Banner saved.');
        redirect(admin_url('banners/edit.php?id=' . $id));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$banner = banner_form_state($id);
$label  = trim((string) ($banner['title'] ?? '')) !== '' ? (string) $banner['title'] : 'Banner #' . $id;

$isEdit       = true;
$pageTitle    = 'Edit Banner';
$pageSubtitle = $label . ' · ' . (banner_positions()[(string) $banner['position']] ?? (string) $banner['position']);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Banners',   'url' => admin_url('banners/')],
    ['label' => $label],
];

$pageActions = '';
if (admin_can('banners.delete')) {
    // Written out instead of admin_delete_form() so the page action can be a
    // full labelled button rather than an icon.
    $confirm = ('Delete "' . $label . '"? Its uploaded images are removed from the server too.');
    $pageActions = '<form method="post" action="' . e(admin_url('banners/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
