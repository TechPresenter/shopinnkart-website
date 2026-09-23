<?php
/**
 * ShopInnKart Admin - Save courier / tracking / expected delivery.
 *
 * Tracking details are what the customer chases support about, so every change
 * is appended to the order's status history rather than silently overwritten.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('orders.edit');

$orderId = input_int('id');
$order   = $orderId > 0 ? get_order($orderId) : null;

if ($order === null) {
    flash('error', 'That order no longer exists.');
    redirect(admin_url('orders/'));
}

// A courier integration owns courier_name and tracking_number while it has a
// live consignment for this order. A manual form opened before the booking can
// still be submitted after it, and would put a stale AWB in front of the
// customer until the next webhook quietly replaced it. Asked only once the
// shipping tables exist: before the migration there is no integration, and
// this form is the only way to record tracking at all.
require_once INCLUDES_PATH . '/shipping-service.php';
if (shipping_hub_installed() && shipment_live_for_order($orderId) !== null) {
    flash('error', 'This order has a courier-booked shipment. Change it from the shipment page instead.');
    redirect(admin_url('shipping/book.php?order=' . $orderId));
}

$courier  = mb_substr(trim((string) input('courier_name', '')), 0, 100);
$tracking = mb_substr(trim((string) input('tracking_number', '')), 0, 100);
$eta      = trim((string) input('estimated_delivery', ''));

// The date column is a DATE; anything unparseable is treated as "not set".
$etaDate = null;
if ($eta !== '') {
    $timestamp = strtotime($eta);
    if ($timestamp === false) {
        flash('error', 'The estimated delivery date could not be read.');
        redirect(admin_url('orders/view.php?id=' . $orderId));
    }
    $etaDate = date('Y-m-d', $timestamp);
}

$changes = [];
if ($courier !== (string) ($order['courier_name'] ?? '')) {
    $changes[] = 'courier ' . ($courier !== '' ? '"' . $courier . '"' : 'cleared');
}
if ($tracking !== (string) ($order['tracking_number'] ?? '')) {
    $changes[] = 'tracking ' . ($tracking !== '' ? $tracking : 'cleared');
}
if ((string) $etaDate !== (string) ($order['estimated_delivery'] ?? '')) {
    $changes[] = 'expected delivery ' . ($etaDate !== null ? format_date($etaDate) : 'cleared');
}

if ($changes === []) {
    flash('info', 'Nothing changed on the shipment details.');
    redirect(admin_url('orders/view.php?id=' . $orderId));
}

Database::update('orders', [
    'courier_name'       => $courier !== '' ? $courier : null,
    'tracking_number'    => $tracking !== '' ? $tracking : null,
    'estimated_delivery' => $etaDate,
], '`id` = :id', ['id' => $orderId]);

Database::insert('order_status_history', [
    'order_id'   => $orderId,
    'status'     => (string) $order['status'],
    'note'       => mb_substr('Shipment updated: ' . implode(', ', $changes), 0, 500),
    'changed_by' => 'admin',
    'admin_id'   => admin_id(),
]);

log_activity('order.shipping_updated', 'order', $orderId,
    'Order ' . $order['order_number'] . ': ' . implode(', ', $changes));
admin_after_write();

flash('success', 'Shipment details saved.');
redirect(admin_url('orders/view.php?id=' . $orderId));
