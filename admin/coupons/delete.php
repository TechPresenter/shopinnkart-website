<?php
/**
 * ShopInnKart Admin - Delete a coupon.
 *
 * The foreign keys cascade, so the restrictions and the redemption history go
 * with the coupon. That history is what the marketing report counts, so the
 * number lost is written into the activity log before the row disappears.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('coupons.delete');

$id = input_int('id');
$coupon = $id > 0
    ? Database::fetch('SELECT `id`, `code`, `used_count` FROM `coupons` WHERE `id` = :id', ['id' => $id])
    : null;

if ($coupon === null) {
    flash('error', 'That coupon no longer exists.');
    redirect(admin_url('coupons/'));
}

$redemptions = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `coupon_usage` WHERE `coupon_id` = :id',
    ['id' => $id]
);

Database::delete('coupons', '`id` = :id', ['id' => $id]);

log_activity(
    'coupon.deleted',
    'coupon',
    $id,
    'Deleted coupon "' . $coupon['code'] . '"'
        . ($redemptions > 0 ? ' and ' . $redemptions . ' redemption record(s)' : '')
);
admin_after_write();

flash('success', 'Coupon "' . $coupon['code'] . '" deleted.');
redirect(admin_url('coupons/'));
