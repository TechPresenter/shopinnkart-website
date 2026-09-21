<?php
/**
 * ShopInnKart Admin - Sales report.
 *
 * Revenue over the selected window, every headline number measured against the
 * equivalent window immediately before it, then split by how customers paid and
 * how the goods shipped. Cancelled, returned and refunded orders are excluded
 * everywhere by report_revenue_sql().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$filters  = report_filter_state();
$totals   = report_sales_totals($filters['from_dt'], $filters['to_dt']);
$previous = report_sales_totals($filters['prev_from_dt'], $filters['prev_to_dt']);

$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];
$bucket     = report_bucket_sql('o.`created_at`', $filters['group']);
$revenueSql = report_revenue_sql();

$revenueSeries = report_fill_series(
    Database::fetchPairs(
        'SELECT ' . $bucket . ' AS bucket, COALESCE(SUM(o.`total_amount`), 0)
         FROM `orders` o
         WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
         GROUP BY bucket',
        $dateParams
    ),
    $filters
);

$orderSeries = report_fill_series(
    Database::fetchPairs(
        'SELECT ' . $bucket . ' AS bucket, COUNT(*)
         FROM `orders` o
         WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
         GROUP BY bucket',
        $dateParams
    ),
    $filters
);

// The best single day (or month) in the window is the number people ask for
// straight after seeing the chart.
$bestBucket = 0.0;
foreach ($revenueSeries as $point) {
    $bestBucket = max($bestBucket, (float) $point['value']);
}

$byPayment = Database::fetchAll(
    'SELECT o.`payment_method` AS code,
            COUNT(*)                               AS orders,
            COALESCE(SUM(o.`total_amount`), 0)     AS revenue,
            COALESCE(SUM(o.`payment_charge`), 0)   AS charges,
            COALESCE(SUM(o.`payment_discount`), 0) AS discounts,
            COALESCE(SUM(CASE WHEN o.`payment_status` = \'paid\' THEN 1 ELSE 0 END), 0) AS paid_orders
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY o.`payment_method`
     ORDER BY revenue DESC, orders DESC',
    $dateParams
);

$byShipping = Database::fetchAll(
    'SELECT o.`shipping_method` AS code,
            COUNT(*)                              AS orders,
            COALESCE(SUM(o.`total_amount`), 0)    AS revenue,
            COALESCE(SUM(o.`shipping_amount`), 0) AS collected,
            COALESCE(SUM(CASE WHEN o.`shipping_amount` = 0 THEN 1 ELSE 0 END), 0) AS free_orders
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY o.`shipping_method`
     ORDER BY revenue DESC, orders DESC',
    $dateParams
);

$paymentNames  = report_payment_method_names();
$shippingNames = report_shipping_method_names();

$pageTitle    = 'Sales Report';
$pageSubtitle = report_range_subtitle($filters);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reports'],
    ['label' => 'Sales'],
];

$pageActions = '';
if (admin_can('orders.view')) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('orders/?' . http_build_query($filters['query']))) . '">'
        . icon('cart', 'w-4 h-4') . ' Open these orders</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= report_nav('sales', $filters) ?>
    <?= report_filter_bar('sales', $filters) ?>
</div>

<!-- ============================ Headline tiles =========================== -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= report_stat_card('Revenue', money($totals['revenue']), 'wallet', 'primary',
        $totals['revenue'], $previous['revenue'], 'vs ' . money($previous['revenue']) . ' before') ?>
    <?= report_stat_card('Orders', number_format($totals['orders']), 'cart', 'blue',
        (float) $totals['orders'], (float) $previous['orders'], 'vs ' . number_format($previous['orders']) . ' before') ?>
    <?= report_stat_card('Avg Order Value', money($totals['aov']), 'percent', 'green',
        $totals['aov'], $previous['aov'], 'vs ' . money($previous['aov']) . ' before') ?>
    <?= report_stat_card('Units Sold', number_format($totals['units']), 'package', 'violet',
        (float) $totals['units'], (float) $previous['units'], 'vs ' . number_format($previous['units']) . ' before') ?>
</div>

<div class="ad-grid ad-grid--3" style="margin-bottom:18px">
    <?= report_stat_card('Discount Given', money($totals['discount']), 'tag', 'amber',
        $totals['discount'], $previous['discount'],
        report_percent_text(report_percent($totals['discount'], $totals['revenue'])) . ' of revenue') ?>
    <?= report_stat_card('Shipping Collected', money($totals['shipping']), 'truck', 'navy',
        $totals['shipping'], $previous['shipping'], 'vs ' . money($previous['shipping']) . ' before') ?>
    <?= report_stat_card('Tax Collected', money($totals['tax']), 'shield', 'red',
        $totals['tax'], $previous['tax'], 'vs ' . money($previous['tax']) . ' before') ?>
</div>

<!-- ================================ Charts ============================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Revenue by <?= e($filters['group']) ?></div>
                <div class="ad-card__sub">
                    <?= e(money($totals['revenue'])) ?> across <?= e($filters['label']) ?>
                    <?php if ($bestBucket > 0): ?>
                        &middot; best <?= e($filters['group']) ?> <?= e(money($bestBucket)) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($revenueSeries, '₹', 210) ?></div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Orders by <?= e($filters['group']) ?></div>
                <div class="ad-card__sub">
                    <?= number_format($totals['orders']) ?> revenue-earning orders
                    &middot; <?= number_format($totals['units']) ?> units
                </div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($orderSeries, '', 210) ?></div>
    </div>
</div>

<!-- ============================== Breakdowns ============================= -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">By payment method</div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($byPayment === []): ?>
                <?= admin_empty('No payments in this range',
                    'Widen the date range, or check that orders in this window reached a revenue-earning status.',
                    null, null, 'credit-card') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Paid</th>
                                <th class="ad-table__num">Revenue</th>
                                <th class="ad-table__num">Share</th>
                                <th class="ad-table__num">Fees / discounts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byPayment as $row): ?>
                                <?php $share = report_percent((float) $row['revenue'], $totals['revenue']); ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex__name">
                                            <?= e($paymentNames[$row['code']] ?? strtoupper((string) $row['code'])) ?>
                                        </div>
                                        <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['code']) ?></div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['orders']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['paid_orders']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $row['revenue'])) ?></strong></td>
                                    <td class="ad-table__num"><?= e(report_percent_text($share)) ?></td>
                                    <td class="ad-table__num">
                                        <?php if ((float) $row['charges'] > 0): ?>
                                            +<?= e(money((float) $row['charges'])) ?>
                                        <?php endif; ?>
                                        <?php if ((float) $row['discounts'] > 0): ?>
                                            <span class="ad-muted">&minus;<?= e(money((float) $row['discounts'])) ?></span>
                                        <?php endif; ?>
                                        <?php if ((float) $row['charges'] <= 0 && (float) $row['discounts'] <= 0): ?>
                                            <span class="ad-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">By shipping method</div>
            <span class="ad-muted" style="font-size:12.5px">
                <?= e(money($totals['shipping'])) ?> collected
            </span>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($byShipping === []): ?>
                <?= admin_empty('No shipments in this range',
                    'Shipping totals appear once orders in this window reach a revenue-earning status.',
                    null, null, 'truck') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Shipped free</th>
                                <th class="ad-table__num">Revenue</th>
                                <th class="ad-table__num">Share</th>
                                <th class="ad-table__num">Shipping collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byShipping as $row): ?>
                                <?php $share = report_percent((float) $row['revenue'], $totals['revenue']); ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex__name">
                                            <?= e($shippingNames[$row['code']] ?? ucfirst((string) $row['code'])) ?>
                                        </div>
                                        <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['code']) ?></div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['orders']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['free_orders']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $row['revenue'])) ?></strong></td>
                                    <td class="ad-table__num"><?= e(report_percent_text($share)) ?></td>
                                    <td class="ad-table__num"><?= e(money((float) $row['collected'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
