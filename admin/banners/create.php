<?php
/**
 * ShopInnKart Admin - Create a banner.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.create');

require_once __DIR__ . '/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = banner_form_save(null);
    if ($result['ok']) {
        flash('success', 'Banner created.');
        redirect(admin_url('banners/edit.php?id=' . (int) $result['id']));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$banner = banner_form_state(null);
// A new banner defaults to the position the admin was looking at.
$position = admin_filter('position', array_keys(banner_positions()));
if (!is_post() && $position !== '') {
    $banner['position'] = $position;
}

$isEdit       = false;
$pageTitle    = 'Add Banner';
$pageSubtitle = 'Artwork and copy for one of the storefront banner slots.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Banners',   'url' => admin_url('banners/')],
    ['label' => 'Add Banner'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
