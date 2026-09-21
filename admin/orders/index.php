<?php
/**
 * ShopInnKart Admin - Order list.
 *
 * One screen for the whole order book: status tabs with live counts, the
 * filter bar, the money summary for whatever is currently selected, and a
 * bulk status change. Every number is a live query.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once __DIR__ . '/_filters.php';

$canEdit = admin_can('orders.edit');

$filters = order_filter_state();
$summary = order_filter_summary($filters);

// Counts for the tabs: the same filter set, grouped by status.
$statusCounts = Database::fetchPairs(
    'SELECT o.`status`, COUNT(*) FROM `orders` o WHERE ' . $filters['base_where'] . ' GROUP BY o.`status`',
    $filters['base_params']
);
$allCount = array_sum(array_map('intval', $statusCounts));

$total = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `orders` o WHERE ' . $filters['where'],
    $filters['params']
);
$pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$orders = Database::fetchAll(
    'SELECT o.`id`, o.`order_number`, o.`user_id`, o.`customer_name`, o.`customer_email`,
            o.`created_at`, o.`total_amount`, o.`status`, o.`payment_status`, o.`payment_method`,
            (SELECT COUNT(*) FROM `order_items` oi WHERE oi.`order_id` = o.`id`) AS line_count,
            (SELECT COALESCE(SUM(oi2.`quantity`), 0) FROM `order_items` oi2 WHERE oi2.`order_id` = o.`id`) AS unit_count
     FROM `orders` o
     WHERE ' . $filters['where'] . '
     ORDER BY ' . $filters['order_by'] . '
     LIMIT ' . $limit . ' OFFSET ' . $offset,
    $filters['params']
);

$exportQuery = $_GET;
unset($exportQuery['page'], $exportQuery['mode']);

$pageTitle    = 'Orders';
$pageSubtitle = number_format($total) . ' order' . ($total === 1 ? '' : 's') . ' in the current view.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Orders'],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('orders/returns.php')) . '">'
    . icon('refresh', 'w-4 h-4') . ' Returns</a>'
    . '<a class="ad-btn" href="' . e(admin_url('orders/export.php') . '?' . http_build_query($exportQuery)) . '">'
    . icon('download', 'w-4 h-4') . ' Export orders</a>'
    . '<a class="ad-btn" href="' . e(admin_url('orders/export.php') . '?' . http_build_query($exportQuery + ['mode' => 'items'])) . '">'
    . icon('download', 'w-4 h-4') . ' Export line items</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<!-- ============================ Summary tiles ============================ -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Orders', number_format($summary['orders']), 'cart', 'blue',
        $filters['is_filtered'] ? 'Matching the current filter' : 'All time') ?>
    <?= admin_stat_card('Revenue', money($summary['revenue']), 'wallet', 'primary',
        'Excludes cancelled, returned and refunded') ?>
    <?= admin_stat_card('Avg Order Value', money($summary['aov']), 'percent', 'green',
        'Across revenue-earning orders') ?>
    <?= admin_stat_card('Units', number_format($summary['units']), 'package', 'violet',
        'Total quantity across all lines') ?>
</div>

<div class="ad-card">

    <!-- ------------------------------ Tabs ------------------------------ -->
    <div class="ad-tabs">
        <a class="ad-tab <?= $filters['status'] === '' ? 'is-active' : '' ?>"
           href="<?= e(url_with(['status' => null, 'page' => null])) ?>">
            All <span class="ad-tab__count"><?= number_format($allCount) ?></span>
        </a>
        <?php foreach (ORDER_STATUSES as $statusKey => $statusLabel): ?>
            <a class="ad-tab <?= $filters['status'] === $statusKey ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $statusKey, 'page' => null])) ?>">
                <?= e($statusLabel) ?>
                <span class="ad-tab__count"><?= number_format((int) ($statusCounts[$statusKey] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ----------------------------- Filters ---------------------------- -->
    <form class="ad-filters" method="get" action="<?= e(admin_url('orders/')) ?>">
        <?php if ($filters['status'] !== ''): ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <?php endif; ?>
        <?php if ($filters['sort'] !== 'created_at' || $filters['dir'] !== 'desc'): ?>
            <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
            <input type="hidden" name="dir" value="<?= e($filters['dir']) ?>">
        <?php endif; ?>

        <span class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="orderSearch">Search orders</label>
            <input class="sik-input" type="search" id="orderSearch" name="q" data-filter-search
                   value="<?= e($filters['q']) ?>" placeholder="Order number, name, email or phone"
                   autocomplete="off">
        </span>

        <label class="sik-sr" for="filterPaymentStatus">Payment status</label>
        <select class="sik-select" id="filterPaymentStatus" name="payment_status" data-auto-submit>
            <?= admin_options(PAYMENT_STATUSES, $filters['payment_status'], 'Any payment status') ?>
        </select>

        <label class="sik-sr" for="filterPaymentMethod">Payment method</label>
        <select class="sik-select" id="filterPaymentMethod" name="payment_method" data-auto-submit>
            <?= admin_options($filters['payment_methods'], $filters['payment_method'], 'Any payment method') ?>
        </select>

        <label class="sik-sr" for="filterRange">Date range</label>
        <select class="sik-select" id="filterRange" name="range" data-range-preset>
            <?= admin_options(ORDER_DATE_PRESETS, $filters['range']) ?>
        </select>

        <span data-range-custom style="display:flex;gap:8px;align-items:center"<?= $filters['range'] === 'custom' ? '' : ' hidden' ?>>
            <label class="sik-sr" for="filterFrom">From date</label>
            <input class="sik-input" type="date" id="filterFrom" name="from"
                   value="<?= e((string) ($_GET['from'] ?? $filters['from'] ?? '')) ?>">
            <label class="sik-sr" for="filterTo">To date</label>
            <input class="sik-input" type="date" id="filterTo" name="to"
                   value="<?= e((string) ($_GET['to'] ?? $filters['to'] ?? '')) ?>">
        </span>

        <label class="sik-sr" for="filterMinTotal">Minimum total</label>
        <input class="sik-input" type="number" id="filterMinTotal" name="min_total" min="0" step="1"
               style="min-width:110px" placeholder="Min ₹"
               value="<?= $filters['min_total'] !== null ? e((string) $filters['min_total']) : '' ?>">

        <label class="sik-sr" for="filterMaxTotal">Maximum total</label>
        <input class="sik-input" type="number" id="filterMaxTotal" name="max_total" min="0" step="1"
               style="min-width:110px" placeholder="Max ₹"
               value="<?= $filters['max_total'] !== null ? e((string) $filters['max_total']) : '' ?>">

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Apply</button>
        <?php if ($filters['is_filtered']): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('orders/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <!-- --------------------------- Bulk action -------------------------- -->
    <?php if ($canEdit): ?>
        <form class="ad-bulk" method="post" action="<?= e(admin_url('orders/update-status.php')) ?>"
              data-bulk-bar data-bulk-form>
            <?= csrf_field() ?>
            <span><strong data-bulk-count>0</strong> selected</span>
            <label class="sik-sr" for="bulkStatus">New status</label>
            <select class="sik-select" id="bulkStatus" name="bulk_action" required>
                <?php
                // Fulfilment path only. Cancel, return and refund each need a
                // reason, so they belong to the order detail screen and the
                // Returns screen — update-status.php refuses them either way.
                echo admin_options(
                    array_combine(ORDER_TIMELINE, array_map(
                        static fn (string $s): string => ORDER_STATUSES[$s],
                        ORDER_TIMELINE
                    )),
                    '',
                    'Change status to…'
                );
                ?>
            </select>
            <label class="sik-sr" for="bulkNote">Note</label>
            <input class="sik-input" type="text" id="bulkNote" name="note" maxlength="500"
                   placeholder="Note for the status history (optional)">
            <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">Apply</button>
        </form>
    <?php endif; ?>

    <!-- ------------------------------ Table ----------------------------- -->
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($orders === []): ?>
            <?= admin_empty(
                $filters['is_filtered'] ? 'No orders match these filters' : 'No orders yet',
                $filters['is_filtered']
                    ? 'Try a wider date range, or clear the filters to see the whole order book.'
                    : 'Orders will appear here as soon as customers start buying.',
                $filters['is_filtered'] ? 'Clear filters' : null,
                $filters['is_filtered'] ? admin_url('orders/') : null,
                'cart'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <?php if ($canEdit): ?>
                                <th class="ad-table__check">
                                    <label class="sik-sr" for="checkAllOrders">Select all orders</label>
                                    <input type="checkbox" id="checkAllOrders" data-check-all>
                                </th>
                            <?php endif; ?>
                            <th><?= admin_sort_header('Order', 'order_number', $filters['sort'], $filters['dir']) ?></th>
                            <th>Customer</th>
                            <th><?= admin_sort_header('Date', 'created_at', $filters['sort'], $filters['dir']) ?></th>
                            <th class="ad-table__num">Items</th>
                            <th class="ad-table__num"><?= admin_sort_header('Total', 'total_amount', $filters['sort'], $filters['dir']) ?></th>
                            <th>Payment</th>
                            <th><?= admin_sort_header('Status', 'status', $filters['sort'], $filters['dir']) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php $orderId = (int) $order['id']; ?>
                            <tr>
                                <?php if ($canEdit): ?>
                                    <td class="ad-table__check">
                                        <label class="sik-sr" for="order<?= $orderId ?>">Select order <?= e($order['order_number']) ?></label>
                                        <input type="checkbox" id="order<?= $orderId ?>" data-check-row value="<?= $orderId ?>">
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div class="ad-cellflex">
                                        <a class="ad-mono" style="font-weight:700"
                                           href="<?= e(admin_url('orders/view.php?id=' . $orderId)) ?>">
                                            <?= e($order['order_number']) ?>
                                        </a>
                                        <button type="button" class="ad-btn ad-btn--icon ad-btn--sm"
                                                data-copy="<?= e_attr($order['order_number']) ?>"
                                                title="Copy order number" aria-label="Copy order number">
                                            <?= icon('copy', 'w-3.5 h-3.5') ?>
                                        </button>
                                    </div>
                                </td>

                                <td>
                                    <div class="ad-cellflex__name"><?= e($order['customer_name']) ?></div>
                                    <div class="ad-cellflex__meta"><?= e($order['customer_email']) ?></div>
                                </td>

                                <td class="ad-muted" style="white-space:nowrap">
                                    <?= e(format_date($order['created_at'], 'd M Y')) ?><br>
                                    <span class="ad-cellflex__meta"><?= e(format_date($order['created_at'], 'g:i A')) ?></span>
                                </td>

                                <td class="ad-table__num">
                                    <strong><?= number_format((int) $order['unit_count']) ?></strong>
                                    <div class="ad-cellflex__meta"><?= (int) $order['line_count'] ?> line<?= (int) $order['line_count'] === 1 ? '' : 's' ?></div>
                                </td>

                                <td class="ad-table__num"><strong><?= e(money((float) $order['total_amount'])) ?></strong></td>

                                <td>
                                    <?= admin_state_badge((string) $order['payment_status']) ?>
                                    <div class="ad-cellflex__meta">
                                        <?= e($filters['payment_methods'][$order['payment_method']] ?? strtoupper((string) $order['payment_method'])) ?>
                                    </div>
                                </td>

                                <td><?= admin_status_badge((string) $order['status']) ?></td>

                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon ad-btn--sm"
                                       href="<?= e(admin_url('orders/view.php?id=' . $orderId)) ?>"
                                       title="View order" aria-label="View order"><?= icon('eye', 'w-4 h-4') ?></a>
                                    <a class="ad-btn ad-btn--icon ad-btn--sm" target="_blank" rel="noopener"
                                       href="<?= e(url('invoice.php?order=' . urlencode((string) $order['order_number']))) ?>"
                                       title="Print invoice" aria-label="Print invoice"><?= icon('download', 'w-4 h-4') ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <span class="ad-muted" style="font-size:12.5px">
                Showing <?= number_format($pagination['from']) ?>&ndash;<?= number_format($pagination['to']) ?>
                of <?= number_format($pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination, admin_url('orders/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
