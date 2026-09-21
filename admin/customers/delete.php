<?php
/**
 * ShopInnKart Admin - Delete a customer.
 *
 * A customer with orders is never deleted. Their orders are accounting and
 * tax records that have to survive the account, and the order rows would only
 * be orphaned anyway (the FK is ON DELETE SET NULL). Blocking the account
 * achieves what the admin actually wants without losing the history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// POST + CSRF + customers.delete, all in one guard.
admin_require_action('customers.delete');

$customerId = (int) input_int('id');

if ($customerId <= 0) {
    flash('error', 'No customer was selected.');
    redirect(admin_url('customers/'));
}

$customer = Database::fetch(
    'SELECT `id`, `first_name`, `last_name`, `email` FROM `users` WHERE `id` = :id LIMIT 1',
    ['id' => $customerId]
);

if ($customer === null) {
    flash('error', 'That customer has already been deleted.');
    redirect(admin_url('customers/'));
}

$customerName = trim($customer['first_name'] . ' ' . (string) ($customer['last_name'] ?? ''));
$orderCount   = Database::count('orders', '`user_id` = :id', ['id' => $customerId]);

if ($orderCount > 0) {
    flash(
        'warning',
        $customerName . ' cannot be deleted: ' . number_format($orderCount) . ' order'
        . ($orderCount === 1 ? '' : 's') . ' are attached to this account and the order history has to '
        . 'stay on record. Block the account instead - the customer can no longer sign in, but every '
        . 'invoice and report keeps its data.'
    );
    redirect(admin_url('customers/view.php?id=' . $customerId));
}

Database::transaction(static function () use ($customerId): void {
    // These tables reference the user without a foreign key, so nothing else
    // clears them. They are personal browsing trails with no audit value.
    Database::delete('recently_viewed', '`user_id` = :id', ['id' => $customerId]);
    Database::delete('search_logs', '`user_id` = :id', ['id' => $customerId]);
    Database::delete('stock_alerts', '`user_id` = :id', ['id' => $customerId]);
    Database::delete('coupon_usage', '`user_id` = :id', ['id' => $customerId]);

    // user_addresses, wishlists (and their items), carts and compare_items all
    // cascade from this row. login_history is deliberately left alone: it is a
    // security audit trail keyed by the address that was typed.
    Database::delete('users', '`id` = :id', ['id' => $customerId]);
});

log_activity(
    'customer.deleted',
    'user',
    $customerId,
    'Deleted customer "' . $customerName . '" (' . $customer['email'] . ')'
);
admin_after_write();

flash('success', $customerName . ' has been deleted along with their addresses, wishlist and cart.');
redirect(admin_url('customers/'));
