<?php
/**
 * ShopInnKart Admin - Cancel an order.
 *
 * cancel_order() stores the reason and hands off to update_order_status(),
 * which puts the stock back, rolls the coupon usage back and emails the
 * customer. Nothing here duplicates that.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('orders.edit');

$orderId = input_int('id');
$reason  = mb_substr(trim((string) input('reason', '')), 0, 255);

$order = $orderId > 0 ? get_order($orderId) : null;

if ($order === null) {
    flash('error', 'That order no longer exists.');
    redirect(admin_url('orders/'));
}

if ($reason === '') {
    flash('error', 'A cancellation reason is required.');
    redirect(admin_url('orders/view.php?id=' . $orderId));
}

$status = (string) $order['status'];

if (in_array($status, STOCK_RELEASING_STATUSES, true)) {
    flash('warning', 'Order ' . $order['order_number'] . ' is already ' . ORDER_STATUSES[$status] . '.');
    redirect(admin_url('orders/view.php?id=' . $orderId));
}

// A delivered order is a return, not a cancellation — the goods are with the
// customer and the refund path is different.
if ($status === ORDER_STATUS_DELIVERED) {
    flash('error', 'This order has already been delivered. Process it as a return instead.');
    redirect(admin_url('orders/returns.php'));
}

// changedBy 'admin' skips the customer cancellation window on purpose: support
// must be able to cancel an order the customer no longer can.
$result = cancel_order($orderId, $reason, 'admin');

if (!$result['ok']) {
    flash('error', $result['message']);
    redirect(admin_url('orders/view.php?id=' . $orderId));
}

log_activity('order.cancelled', 'order', $orderId,
    'Cancelled ' . $order['order_number'] . ': ' . $reason);
admin_after_write();

flash('success', 'Order ' . $order['order_number'] . ' cancelled and stock returned.');
redirect(admin_url('orders/view.php?id=' . $orderId));
