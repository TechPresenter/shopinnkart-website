<?php
/**
 * ShopInnKart Admin - Order operations report.
 *
 * Where orders are in the pipeline, how long each hand-off takes, and why the
 * ones that fell out fell out. Timings come from the confirmed_at / shipped_at /
 * delivered_at stamps, so an order only counts towards a stage once it has
 * actually reached it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$filters    = report_filter_state();
$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];

$byStatus = Database::fetchAll(
    'SELECT o.`status`,
            COUNT(*)                           AS orders,
            COALESCE(SUM(o.`total_amount`), 0) AS value,
            COALESCE(SUM(o.`discount_amount`), 0) AS discount
     FROM `orders` o
     WHERE o.`created_at` BETWEEN :from AND :to
     GROUP BY o.`status`',
    $dateParams
);

// Keyed by status so the table can walk ORDER_STATUSES in lifecycle order
// rather than in whatever order the rows came back.
$statusRows = [];
$totalOrders = 0;
$totalValue = 0.0;
$revenueOrders = 0;
$revenueValue = 0.0;
foreach ($byStatus as $row) {
    $statusRows[(string) $row['status']] = $row;
    $totalOrders += (int) $row['orders'];
    $totalValue  += (float) $row['value'];
    if (report_status_earns((string) $row['status'])) {
        $revenueOrders += (int) $row['orders'];
        $revenueValue  += (float) $row['value'];
    }
}

$funnel = Database::fetch(
    'SELECT COUNT(*) AS placed,
            COALESCE(SUM(CASE WHEN o.`confirmed_at` IS NOT NULL THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN o.`shipped_at`   IS NOT NULL THEN 1 ELSE 0 END), 0) AS shipped,
            COALESCE(SUM(CASE WHEN o.`delivered_at` IS NOT NULL THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN o.`cancelled_at` IS NOT NULL THEN 1 ELSE 0 END), 0) AS cancelled
     FROM `orders` o
     WHERE o.`created_at` BETWEEN :from AND :to',
    $dateParams
) ?? [];

// AVG skips NULLs, so each stage is averaged only over the orders that
// actually completed it. Negative gaps would mean the stamps were written out
// of order, so they are left out rather than dragging the average down.
$timings = Database::fetch(
    'SELECT AVG(CASE WHEN o.`confirmed_at` > o.`created_at`
                     THEN TIMESTAMPDIFF(MINUTE, o.`created_at`, o.`confirmed_at`) END)   AS to_confirm,
            AVG(CASE WHEN o.`shipped_at` > o.`confirmed_at`
                     THEN TIMESTAMPDIFF(MINUTE, o.`confirmed_at`, o.`shipped_at`) END)   AS to_ship,
            AVG(CASE WHEN o.`delivered_at` > o.`shipped_at`
                     THEN TIMESTAMPDIFF(MINUTE, o.`shipped_at`, o.`delivered_at`) END)   AS to_deliver,
            AVG(CASE WHEN o.`delivered_at` > o.`created_at`
                     THEN TIMESTAMPDIFF(MINUTE, o.`created_at`, o.`delivered_at`) END)   AS end_to_end,
            COUNT(o.`delivered_at`) AS delivered_count
     FROM `orders` o
     WHERE o.`created_at` BETWEEN :from AND :to',
    $dateParams
) ?? [];

$cancelReasons = Database::fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(o.`cancel_reason`), ''), 'Not stated') AS reason,
            COUNT(*)                           AS orders,
            COALESCE(SUM(o.`total_amount`), 0) AS value
     FROM `orders` o
     WHERE o.`status` = 'cancelled' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY reason
     ORDER BY orders DESC, value DESC
     LIMIT 12",
    $dateParams
);

$returnReasons = Database::fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(o.`return_reason`), ''), 'Not stated') AS reason,
            COUNT(*)                           AS orders,
            COALESCE(SUM(o.`total_amount`), 0) AS value
     FROM `orders` o
     WHERE o.`status` IN ('returned', 'refunded') AND o.`created_at` BETWEEN :from AND :to
     GROUP BY reason
     ORDER BY orders DESC, value DESC
     LIMIT 12",
    $dateParams
);

$cancelledOrders = (int) ($statusRows[ORDER_STATUS_CANCELLED]['orders'] ?? 0);
$cancelRate      = report_percent((float) $cancelledOrders, (float) $totalOrders);
$deliveredOrders = (int) ($statusRows[ORDER_STATUS_DELIVERED]['orders'] ?? 0);
$placed          = (int) ($funnel['placed'] ?? 0);

$funnelSteps = [
    ['label' => 'Placed',    'value' => $placed],
    ['label' => 'Confirmed', 'value' => (int) ($funnel['confirmed'] ?? 0)],
    ['label' => 'Shipped',   'value' => (int) ($funnel['shipped'] ?? 0)],
    ['label' => 'Delivered', 'value' => (int) ($funnel['delivered'] ?? 0)],
];

$pageTitle    = 'Order Report';
$pageSubtitle = report_range_subtitle($filters);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reports'],
    ['label' => 'Orders'],
];

$pageActions = '';
if (admin_can('orders.view')) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('orders/?' . http_build_query($filters['query']))) . '">'
        . icon('cart', 'w-4 h-4') . ' Open these orders</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= report_nav('orders', $filters) ?>
    <?= report_filter_bar('orders', $filters) ?>
</div>

<!-- ============================ Headline tiles =========================== -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Orders Placed', number_format($totalOrders), 'cart', 'blue',
        'Every status, ' . $filters['label']) ?>
    <?= admin_stat_card('Revenue-Earning', number_format($revenueOrders), 'wallet', 'primary',
        money($revenueValue) . ' · ' . report_percent_text(report_percent((float) $revenueOrders, (float) $totalOrders)) . ' of orders') ?>
    <?= admin_stat_card('Cancellation Rate', report_percent_text($cancelRate), 'close', 'red',
        number_format($cancelledOrders) . ' cancelled in this range') ?>
    <?= admin_stat_card('Placed to Delivered', report_duration(
            isset($timings['end_to_end']) ? (float) $timings['end_to_end'] : null
        ), 'clock', 'green',
        'Averaged over ' . number_format($deliveredOrders) . ' delivered orders') ?>
</div>

<!-- ========================= Status breakdown ============================ -->
<div class="ad-grid ad-grid--sidebar">
    <div>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Orders and value by status</div>
                    <div class="ad-card__sub">
                        Cancelled, returned and refunded orders carry a value but never count as revenue
                    </div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($totalOrders === 0): ?>
                    <?= admin_empty('No orders in this range',
                        'Widen the date range to see the order book.', null, null, 'cart') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th class="ad-table__num">Orders</th>
                                    <th class="ad-table__num">Share</th>
                                    <th class="ad-table__num">Order value</th>
                                    <th class="ad-table__num">Discount</th>
                                    <th>Counts as revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (ORDER_STATUSES as $status => $label): ?>
                                    <?php
                                    $row = $statusRows[$status] ?? null;
                                    if ($row === null) {
                                        continue;
                                    }
                                    $count = (int) $row['orders'];
                                    ?>
                                    <tr>
                                        <td>
                                            <?= admin_status_badge($status) ?>
                                            <?php if (admin_can('orders.view')): ?>
                                                <a class="ad-cellflex__meta" style="display:block"
                                                   href="<?= e(admin_url('orders/?' . http_build_query(
                                                       $filters['query'] + ['status' => $status]
                                                   ))) ?>">View orders</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-table__num"><strong><?= number_format($count) ?></strong></td>
                                        <td class="ad-table__num">
                                            <?= e(report_percent_text(report_percent((float) $count, (float) $totalOrders))) ?>
                                        </td>
                                        <td class="ad-table__num"><?= e(money((float) $row['value'])) ?></td>
                                        <td class="ad-table__num"><?= e(money((float) $row['discount'])) ?></td>
                                        <td>
                                            <?php if (report_status_earns($status)): ?>
                                                <span class="sik-status sik-status--green">Yes</span>
                                            <?php else: ?>
                                                <span class="sik-status sik-status--gray">No</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:var(--ad-bg)">
                                    <td><strong>Total</strong></td>
                                    <td class="ad-table__num"><strong><?= number_format($totalOrders) ?></strong></td>
                                    <td class="ad-table__num">100.0%</td>
                                    <td class="ad-table__num"><strong><?= e(money($totalValue)) ?></strong></td>
                                    <td class="ad-table__num"></td>
                                    <td class="ad-muted"><?= e(money($revenueValue)) ?> revenue</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Why orders were cancelled</div>
                    <div class="ad-card__sub"><?= number_format($cancelledOrders) ?> cancellations in <?= e($filters['label']) ?></div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($cancelReasons === []): ?>
                    <?= admin_empty('No cancellations in this range',
                        'Nothing was cancelled in this window.', null, null, 'check-circle') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Reason</th>
                                    <th class="ad-table__num">Orders</th>
                                    <th class="ad-table__num">Share of cancellations</th>
                                    <th class="ad-table__num">Value lost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cancelReasons as $row): ?>
                                    <tr>
                                        <td><?= e(str_limit((string) $row['reason'], 90)) ?></td>
                                        <td class="ad-table__num"><strong><?= number_format((int) $row['orders']) ?></strong></td>
                                        <td class="ad-table__num">
                                            <?= e(report_percent_text(report_percent((float) $row['orders'], (float) $cancelledOrders))) ?>
                                        </td>
                                        <td class="ad-table__num"><?= e(money((float) $row['value'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($returnReasons !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div class="ad-card__title">Why orders came back</div>
                </div>
                <div class="ad-card__body ad-card__body--flush">
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Reason</th>
                                    <th class="ad-table__num">Orders</th>
                                    <th class="ad-table__num">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnReasons as $row): ?>
                                    <tr>
                                        <td><?= e(str_limit((string) $row['reason'], 90)) ?></td>
                                        <td class="ad-table__num"><strong><?= number_format((int) $row['orders']) ?></strong></td>
                                        <td class="ad-table__num"><?= e(money((float) $row['value'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Fulfilment funnel</div>
                    <div class="ad-card__sub">Share of orders placed in this range that reached each stage</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if ($placed === 0): ?>
                    <p class="ad-muted">No orders were placed in this range.</p>
                <?php else: ?>
                    <?php foreach ($funnelSteps as $step): ?>
                        <?= admin_progress_row(
                            $step['label'],
                            (float) $step['value'],
                            (float) $placed,
                            number_format($step['value']) . '  ·  '
                                . report_percent_text(report_percent((float) $step['value'], (float) $placed))
                        ) ?>
                    <?php endforeach; ?>
                    <?php if ((int) ($funnel['cancelled'] ?? 0) > 0): ?>
                        <p class="ad-muted" style="font-size:var(--ad-text-xs);margin-top:12px">
                            <?= number_format((int) $funnel['cancelled']) ?> of these orders were cancelled along the way.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Average time per stage</div>
                    <div class="ad-card__sub">Only orders that reached the stage are averaged</div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <tbody>
                            <tr>
                                <td>Placed &rarr; Confirmed</td>
                                <td class="ad-table__num">
                                    <strong><?= e(report_duration(isset($timings['to_confirm']) ? (float) $timings['to_confirm'] : null)) ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td>Confirmed &rarr; Shipped</td>
                                <td class="ad-table__num">
                                    <strong><?= e(report_duration(isset($timings['to_ship']) ? (float) $timings['to_ship'] : null)) ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td>Shipped &rarr; Delivered</td>
                                <td class="ad-table__num">
                                    <strong><?= e(report_duration(isset($timings['to_deliver']) ? (float) $timings['to_deliver'] : null)) ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Placed &rarr; Delivered</strong></td>
                                <td class="ad-table__num">
                                    <strong><?= e(report_duration(isset($timings['end_to_end']) ? (float) $timings['end_to_end'] : null)) ?></strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ad-card__foot ad-muted" style="font-size:var(--ad-text-xs)">
                Based on <?= number_format((int) ($timings['delivered_count'] ?? 0)) ?> delivered order<?= (int) ($timings['delivered_count'] ?? 0) === 1 ? '' : 's' ?>.
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
