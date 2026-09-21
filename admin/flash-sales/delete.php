<?php
/**
 * ShopInnKart Admin - Delete a flash sale.
 *
 * flash_sale_products cascades, so the per-product sale prices and their
 * stock_sold counters go with the sale. The products themselves keep their
 * own price and stock.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('flash_sales.delete');

$id = input_int('id');
$sale = $id > 0
    ? Database::fetch('SELECT `id`, `name` FROM `flash_sales` WHERE `id` = :id', ['id' => $id])
    : null;

if ($sale === null) {
    flash('error', 'That flash sale no longer exists.');
    redirect(admin_url('flash-sales/'));
}

$totals = Database::fetch(
    'SELECT COUNT(*) AS product_count, COALESCE(SUM(`stock_sold`), 0) AS sold
     FROM `flash_sale_products` WHERE `flash_sale_id` = :id',
    ['id' => $id]
) ?? ['product_count' => 0, 'sold' => 0];

Database::delete('flash_sales', '`id` = :id', ['id' => $id]);

log_activity(
    'flash_sale.deleted',
    'flash_sale',
    $id,
    'Deleted flash sale "' . $sale['name'] . '" with ' . (int) $totals['product_count']
        . ' product(s) and ' . (int) $totals['sold'] . ' unit(s) sold'
);
admin_after_write();

flash('success', 'Flash sale "' . $sale['name'] . '" deleted.');
redirect(admin_url('flash-sales/'));
