<?php
/**
 * ShopInnKart Admin - Returns and refunds.
 *
 * Two things live here: the form that moves a delivered order into Returned or
 * Refunded, and the log of everything that has already gone that way. The move
 * itself goes through update_order_status(), which is what puts the stock back
 * on the shelf and rolls the coupon usage back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

$canEdit = admin_can('orders.edit');

/** The only two destinations this screen offers. */
$returnTargets = [
    ORDER_STATUS_RETURNED => ORDER_STATUSES[ORDER_STATUS_RETURNED],
    ORDER_STATUS_REFUNDED => ORDER_STATUSES[ORDER_STATUS_REFUNDED],
];

// ---------------------------------------------------------------------------
//  Customer request queue: approve / reject / close
//
//  Deliberately does NOT move the order status. Marking an order Returned
//  restocks every unit and reverses the coupon — while the goods are still in
//  the customer's house. That step stays with the form below, which staff run
//  once the parcel is physically back.
// ---------------------------------------------------------------------------
if (is_post() && input('action', '') === 'decide_return') {
    admin_require('orders.edit');
    csrf_require();

    $requestId = input_int('request_id');
    $decision  = (string) input('decision', '');
    $note      = trim((string) input('admin_note', ''));

    $result = decide_return_request($requestId, $decision, $note, admin_id());

    if ($result['ok']) {
        process_notification_queue(3);
        admin_after_write();
        flash('success', $result['message'] . ' The customer has been emailed.');
    } else {
        flash('error', $result['message']);
    }

    redirect(admin_url('orders/returns.php'));
}

if (is_post()) {
    admin_require('orders.edit');
    csrf_require();

    $orderId   = input_int('id');
    $newStatus = (string) input('status', '');
    $reason    = mb_substr(trim((string) input('reason', '')), 0, 255);
    $order     = $orderId > 0 ? get_order($orderId) : null;

    if ($order === null) {
        flash('error', 'Choose an order to process.');
        redirect(admin_url('orders/returns.php'));
    }
    if (!array_key_exists($newStatus, $returnTargets)) {
        flash('error', 'Choose whether the order is being returned or refunded.');
        redirect(admin_url('orders/returns.php'));
    }
    if ($reason === '') {
        flash('error', 'A reason is required so the customer record explains itself later.');
        redirect(admin_url('orders/returns.php'));
    }

    // Delivered goods can be returned or refunded outright; a return that has
    // already come back can still move on to a refund.
    $allowedFrom = $newStatus === ORDER_STATUS_REFUNDED
        ? [ORDER_STATUS_DELIVERED, ORDER_STATUS_RETURNED]
        : [ORDER_STATUS_DELIVERED];

    if (!in_array((string) $order['status'], $allowedFrom, true)) {
        flash('error', 'Order ' . $order['order_number'] . ' is ' . ORDER_STATUSES[$order['status']]
            . ' and cannot be marked ' . $returnTargets[$newStatus] . '.');
        redirect(admin_url('orders/returns.php'));
    }

    Database::update('orders', ['return_reason' => $reason], '`id` = :id', ['id' => $orderId]);

    // Stock comes back on the first move into a releasing status; a return that
    // later becomes a refund must not be restocked twice.
    $restocks = !in_array((string) $order['status'], STOCK_RELEASING_STATUSES, true);

    $result = update_order_status($orderId, $newStatus, $reason, 'admin');

    if (!$result['ok']) {
        flash('error', $result['message']);
        redirect(admin_url('orders/returns.php'));
    }

    log_activity('order.returned', 'order', $orderId,
        'Order ' . $order['order_number'] . ' marked ' . $returnTargets[$newStatus] . ': ' . $reason);
    admin_after_write();

    flash('success', 'Order ' . $order['order_number'] . ' marked ' . $returnTargets[$newStatus] . '.'
        . ($restocks ? ' Stock has been restored.' : ''));
    redirect(admin_url('orders/view.php?id=' . $orderId));
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------
$returnedCount = (int) Database::fetchColumn("SELECT COUNT(*) FROM `orders` WHERE `status` = 'returned'");
$refundedCount = (int) Database::fetchColumn("SELECT COUNT(*) FROM `orders` WHERE `status` = 'refunded'");
$refundedValue = (float) Database::fetchColumn(
    "SELECT COALESCE(SUM(`total_amount`), 0) FROM `orders` WHERE `status` IN ('returned','refunded')"
);
$deliveredCount = (int) Database::fetchColumn("SELECT COUNT(*) FROM `orders` WHERE `status` = 'delivered'");

// Customer-raised requests awaiting a decision, newest first.
$requestCounts  = return_request_counts();
$pendingReturns = Database::fetchAll(
    "SELECT r.*, o.`status` AS `order_status`, o.`total_amount`, o.`delivered_at`
     FROM `return_requests` r
     LEFT JOIN `orders` o ON o.`id` = r.`order_id`
     WHERE r.`status` IN ('pending', 'approved')
     ORDER BY FIELD(r.`status`, 'pending', 'approved'), r.`id` DESC
     LIMIT 100"
);

$total = $returnedCount + $refundedCount;
$pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$rows = Database::fetchAll(
    "SELECT `id`, `order_number`, `customer_name`, `customer_email`, `total_amount`,
            `status`, `payment_status`, `return_reason`, `delivered_at`, `updated_at`
     FROM `orders`
     WHERE `status` IN ('returned','refunded')
     ORDER BY `updated_at` DESC, `id` DESC
     LIMIT " . $limit . ' OFFSET ' . $offset
);

// Candidates for the form. Capped because this is a picker, not a report — the
// order list is the right tool for finding something older.
$eligible = $canEdit
    ? Database::fetchAll(
        "SELECT `id`, `order_number`, `customer_name`, `total_amount`, `status`, `delivered_at`
         FROM `orders`
         WHERE `status` IN ('delivered','returned')
         ORDER BY COALESCE(`delivered_at`, `updated_at`) DESC
         LIMIT 200"
    )
    : [];

$pageTitle    = 'Returns & Refunds';
$pageSubtitle = number_format($returnedCount) . ' returned · ' . number_format($refundedCount) . ' refunded.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Orders', 'url' => admin_url('orders/')],
    ['label' => 'Returns'],
];
$pageActions  = '<a class="ad-btn" href="' . e(admin_url('orders/?status=delivered')) . '">'
    . icon('truck', 'w-4 h-4') . ' Delivered orders</a>'
    . '<a class="ad-btn" href="' . e(admin_url('orders/')) . '">All orders</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Awaiting review', number_format((int) ($requestCounts['pending'] ?? 0)), 'clock',
        (int) ($requestCounts['pending'] ?? 0) > 0 ? 'amber' : 'green', 'Customer requests') ?>
    <?= admin_stat_card('Returned', number_format($returnedCount), 'refresh', 'amber',
        'Goods back with the store') ?>
    <?= admin_stat_card('Refunded', number_format($refundedCount), 'wallet', 'red',
        'Money returned to the customer') ?>
    <?= admin_stat_card('Value returned', money($refundedValue), 'percent', 'navy',
        'Order value across both states') ?>
</div>

<!-- ==================== Customer request queue ==================== -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Return &amp; exchange requests</div>
            <div class="ad-card__sub">
                Raised by customers from their order page. Approving emails them pickup instructions;
                it does <strong>not</strong> move the order or restock anything — do that below once
                the parcel is physically back.
            </div>
        </div>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($pendingReturns === []): ?>
            <div class="sik-empty sik-empty--sm">
                <?= icon('check-circle', 'w-10 h-10') ?>
                <p class="sik-empty__title">Nothing waiting</p>
                <p class="sik-empty__text">No open return or exchange requests.</p>
            </div>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Type &amp; reason</th>
                        <th>Their comment</th>
                        <th>Raised</th>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th class="ad-table__actions">Decision</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingReturns as $request): ?>
                        <?php $requestStatus = (string) $request['status']; ?>
                        <tr>
                            <td>
                                <a href="<?= e(admin_url('orders/view.php?id=' . (int) $request['order_id'])) ?>">
                                    <?= e((string) $request['order_number']) ?>
                                </a>
                                <div class="ad-muted" style="font-size:11.5px">
                                    <?= e(money((float) ($request['total_amount'] ?? 0))) ?>
                                    &middot; <?= e(ORDER_STATUSES[$request['order_status']] ?? (string) $request['order_status']) ?>
                                </div>
                            </td>
                            <td>
                                <div class="ad-cellflex__name"><?= e((string) $request['customer_name']) ?></div>
                                <div class="ad-muted" style="font-size:11.5px"><?= e((string) $request['customer_email']) ?></div>
                            </td>
                            <td>
                                <span class="sik-badge sik-badge--soft"><?= e(ucfirst((string) $request['type'])) ?></span>
                                <div style="font-size:12.5px;margin-top:4px">
                                    <?= e(return_reason_label((string) $request['reason'])) ?>
                                </div>
                            </td>
                            <td style="max-width:260px;font-size:12.5px">
                                <?= (string) ($request['comment'] ?? '') !== ''
                                    ? nl2br(e(mb_strimwidth((string) $request['comment'], 0, 220, '…')))
                                    : '<span class="ad-muted">—</span>' ?>
                            </td>
                            <td style="white-space:nowrap;font-size:12.5px">
                                <?= e(format_datetime($request['created_at'])) ?>
                            </td>
                            <td>
                                <span class="sik-status sik-status--<?= e(RETURN_STATUS_COLORS[$requestStatus] ?? 'gray') ?>">
                                    <?= e(RETURN_STATUSES[$requestStatus] ?? ucfirst($requestStatus)) ?>
                                </span>
                            </td>
                            <?php if ($canEdit): ?>
                                <td class="ad-table__actions">
                                    <form method="post" action="<?= e(admin_url('orders/returns.php')) ?>"
                                          style="display:grid;gap:6px;min-width:230px">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decide_return">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">

                                        <input class="sik-input" type="text" name="admin_note" maxlength="500"
                                               placeholder="<?= $requestStatus === 'pending'
                                                   ? 'Note to the customer (required to reject)'
                                                   : 'Note to the customer' ?>">

                                        <div class="ad-btngroup">
                                            <?php if ($requestStatus === RETURN_STATUS_PENDING): ?>
                                                <button type="submit" name="decision" value="<?= e(RETURN_STATUS_APPROVED) ?>"
                                                        class="ad-btn ad-btn--sm ad-btn--success">Approve</button>
                                                <button type="submit" name="decision" value="<?= e(RETURN_STATUS_REJECTED) ?>"
                                                        class="ad-btn ad-btn--sm ad-btn--danger-ghost">Reject</button>
                                            <?php else: ?>
                                                <button type="submit" name="decision" value="<?= e(RETURN_STATUS_COMPLETED) ?>"
                                                        class="ad-btn ad-btn--sm">Mark completed</button>
                                                <button type="submit" name="decision" value="<?= e(RETURN_STATUS_CANCELLED) ?>"
                                                        class="ad-btn ad-btn--sm ad-btn--danger-ghost">Cancel</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Process a return</div>
                <div class="ad-card__sub">
                    Restores every unit to stock, rolls back any coupon use, and marks the payment
                    refunded when you choose Refunded.
                </div>
            </div>
        </div>
        <div class="ad-card__body">
            <?php if ($eligible === []): ?>
                <?= admin_empty(
                    'Nothing is eligible yet',
                    'Only delivered orders can be returned. ' . number_format($deliveredCount)
                        . ' order(s) are currently delivered.',
                    'View delivered orders',
                    admin_url('orders/?status=delivered'),
                    'truck'
                ) ?>
            <?php else: ?>
                <form class="ad-form" method="post" action="<?= e(admin_url('orders/returns.php')) ?>"
                      data-guard-unsaved>
                    <?= csrf_field() ?>
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="returnOrder">Order <span class="req">*</span></label>
                            <select class="sik-select" id="returnOrder" name="id" required>
                                <option value="">Select a delivered order…</option>
                                <?php foreach ($eligible as $candidate): ?>
                                    <option value="<?= (int) $candidate['id'] ?>"
                                            <?= (int) $candidate['id'] === input_int('id') ? 'selected' : '' ?>>
                                        <?= e($candidate['order_number']) ?>
                                        &mdash; <?= e($candidate['customer_name']) ?>
                                        &mdash; <?= e(money((float) $candidate['total_amount'])) ?>
                                        (<?= e(ORDER_STATUSES[$candidate['status']]) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="sik-help">The 200 most recently delivered orders.</span>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="returnStatus">Mark as <span class="req">*</span></label>
                            <select class="sik-select" id="returnStatus" name="status" required>
                                <?= admin_options($returnTargets, ORDER_STATUS_RETURNED) ?>
                            </select>
                            <span class="sik-help">
                                Refunded also sets the payment record to refunded.
                            </span>
                        </div>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="returnReason">Reason <span class="req">*</span></label>
                        <textarea class="sik-textarea" id="returnReason" name="reason" rows="2" required
                                  maxlength="255" style="min-height:70px"
                                  placeholder="Damaged in transit, wrong item shipped, customer changed their mind…"></textarea>
                    </div>
                    <div>
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('refresh', 'w-4 h-4') ?> Process return
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">Returned &amp; refunded orders</div>
        <span class="ad-muted" style="font-size:12.5px"><?= number_format($total) ?> total</span>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($rows === []): ?>
            <?= admin_empty(
                'No returns yet',
                'Orders you mark as returned or refunded will be listed here with their reason.',
                null,
                null,
                'refresh'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Reason</th>
                            <th>Delivered</th>
                            <th class="ad-table__num">Value</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <a class="ad-mono" style="font-weight:700"
                                       href="<?= e(admin_url('orders/view.php?id=' . (int) $row['id'])) ?>">
                                        <?= e($row['order_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="ad-cellflex__name"><?= e($row['customer_name']) ?></div>
                                    <div class="ad-cellflex__meta"><?= e($row['customer_email']) ?></div>
                                </td>
                                <td class="ad-muted"><?= e(str_limit((string) $row['return_reason'], 60)) ?: '—' ?></td>
                                <td class="ad-muted" style="white-space:nowrap">
                                    <?= $row['delivered_at'] ? e(format_date($row['delivered_at'], 'd M Y')) : '—' ?>
                                </td>
                                <td class="ad-table__num"><?= e(money((float) $row['total_amount'])) ?></td>
                                <td><?= admin_state_badge((string) $row['payment_status']) ?></td>
                                <td><?= admin_status_badge((string) $row['status']) ?></td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon ad-btn--sm"
                                       href="<?= e(admin_url('orders/view.php?id=' . (int) $row['id'])) ?>"
                                       title="View order" aria-label="View order"><?= icon('eye', 'w-4 h-4') ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot" style="display:flex;justify-content:flex-end">
            <?= admin_pagination($pagination, admin_url('orders/returns.php')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
