<?php
/**
 * ShopInnKart Admin - Delete a deal.
 *
 * deal_products cascades, so the attached price list goes with the deal and
 * the products themselves are untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('deals.delete');

$id = input_int('id');
$deal = $id > 0
    ? Database::fetch('SELECT `id`, `title`, `stock_sold` FROM `deals` WHERE `id` = :id', ['id' => $id])
    : null;

if ($deal === null) {
    flash('error', 'That deal no longer exists.');
    redirect(admin_url('deals/'));
}

$productCount = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `deal_products` WHERE `deal_id` = :id',
    ['id' => $id]
);

Database::delete('deals', '`id` = :id', ['id' => $id]);

log_activity(
    'deal.deleted',
    'deal',
    $id,
    'Deleted deal "' . $deal['title'] . '" and its ' . $productCount . ' attached product(s)'
);
admin_after_write();

flash('success', 'Deal "' . $deal['title'] . '" deleted.');
redirect(admin_url('deals/'));
