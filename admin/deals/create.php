<?php
/**
 * ShopInnKart Admin - Create a deal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('deals.create');

require_once __DIR__ . '/_save.php';

$errors = [];

if (is_post()) {
    csrf_require();

    $result = deal_form_save(null);
    if ($result['ok']) {
        flash('success', 'Deal created.');
        redirect(admin_url('deals/edit.php?id=' . (int) $result['id']));
    }

    $errors = $result['errors'];
    flash('error', 'Please correct the highlighted fields.');
}

$deal = deal_form_state(null);

$isEdit       = false;
$pageTitle    = 'Add Deal';
$pageSubtitle = 'A timed promotion with a headline product and its own price list.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Deals',     'url' => admin_url('deals/')],
    ['label' => 'Add Deal'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
