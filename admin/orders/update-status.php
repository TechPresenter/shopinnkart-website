<?php
/**
 * ShopInnKart Admin - Move an order (or a selection of orders) to a new status.
 *
 * update_order_status() already owns the hard parts — stock release, coupon
 * rollback, payment state, the history row and the customer email. This
 * endpoint only validates the request and reports what happened.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('orders.edit');

// The bulk bar posts its target status as `bulk_action`; the detail screen
// posts `status`. Both land here so there is one place that changes a status.
$status = (string) (input('status', '') ?: input('bulk_action', ''));
$note   = mb_substr(trim((string) input('note', '')), 0, 500);
$ids    = array_values(array_unique(array_filter(array_map('intval', input_array('ids')))));

// Only the fulfilment path may be driven from here. Cancelled, returned and
// refunded each require a reason and have guards this endpoint does not
// duplicate (cancel.php refuses delivered -> cancelled, returns.php restricts
// which statuses may become a return or a refund), so they are refused rather
// than quietly accepted from the bulk bar.
if (!in_array($status, ORDER_TIMELINE, true)) {
    flash('error', array_key_exists($status, ORDER_STATUSES)
        ? 'Use "Cancel order" or the Returns screen for that state — a reason is required.'
        : 'Choose a valid order status.');
    redirect_back('admin/orders/');
}

$isBulk = $ids !== [];

if (!$isBulk) {
    $singleId = input_int('id');
    if ($singleId > 0) {
        $ids = [$singleId];
    }
}

if ($ids === []) {
    flash('error', 'No order was selected.');
    redirect_back('admin/orders/');
}

$changed = 0;
$skipped = [];

foreach ($ids as $id) {
    $order = get_order($id);
    if ($order === null) {
        $skipped[] = '#' . $id . ' (not found)';
        continue;
    }

    // Cancelled/returned/refunded orders have already given their stock back.
    // Reviving one would need the stock re-reserved, which is a different job.
    if (in_array((string) $order['status'], STOCK_RELEASING_STATUSES, true)) {
        $skipped[] = $order['order_number'] . ' (already ' . ORDER_STATUSES[$order['status']] . ')';
        continue;
    }

    // Fulfilment only ever moves forward. Rewinding leaves delivered_at set and,
    // on a COD order, payment_status stuck at 'paid' for a state that has not
    // been reached yet.
    $fromIndex = array_search((string) $order['status'], ORDER_TIMELINE, true);
    $toIndex   = array_search($status, ORDER_TIMELINE, true);
    if ($fromIndex !== false && $toIndex !== false && $toIndex <= $fromIndex) {
        $skipped[] = $order['order_number'] . ($toIndex === $fromIndex
            ? ' (already ' . ORDER_STATUSES[$order['status']] . ')'
            : ' (cannot move back from ' . ORDER_STATUSES[$order['status']] . ')');
        continue;
    }

    $result = update_order_status($id, $status, $note !== '' ? $note : null, 'admin');
    if ($result['ok']) {
        $changed++;
    } else {
        $skipped[] = $order['order_number'] . ' (' . $result['message'] . ')';
    }
}

if ($changed > 0) {
    // update_order_status() writes its own activity row per order, so only the
    // bulk run needs a summary line on top of that.
    if ($isBulk) {
        log_activity('order.bulk_status', 'order', null,
            'Bulk status change to ' . ORDER_STATUSES[$status] . ' on ' . $changed . ' order(s)');
    }
    admin_after_write();
    flash('success', $changed === 1
        ? 'Order marked as ' . ORDER_STATUSES[$status] . '.'
        : $changed . ' orders marked as ' . ORDER_STATUSES[$status] . '.');
}

if ($skipped !== []) {
    flash('warning', 'Skipped: ' . implode(', ', array_slice($skipped, 0, 5))
        . (count($skipped) > 5 ? ' and ' . (count($skipped) - 5) . ' more' : '') . '.');
}

// A single change came from the detail screen; a bulk change came from the list.
if (!$isBulk) {
    redirect(admin_url('orders/view.php?id=' . $ids[0]));
}
redirect_back('admin/orders/');
