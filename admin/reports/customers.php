<?php
/**
 * ShopInnKart Admin - Customer report.
 *
 * Who bought in this window and whether they had bought before. Guests have no
 * account, so a customer is identified by their user id when there is one and
 * by their email otherwise — the same person checking out twice as a guest
 * still counts once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$filters    = report_filter_state();
$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];
$revenueSql = report_revenue_sql();

$canViewCustomers = admin_can('customers.view');

$mix      = report_new_vs_returning($filters['from_dt'], $filters['to_dt']);
$prevMix  = report_new_vs_returning($filters['prev_from_dt'], $filters['prev_to_dt']);
$lifetime = report_lifetime_stats();

$buyers        = $mix['new_customers'] + $mix['returning_customers'];
$buyerOrders   = $mix['new_orders'] + $mix['returning_orders'];
$buyerRevenue  = $mix['new_revenue'] + $mix['returning_revenue'];

$signups = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `users` WHERE `created_at` BETWEEN :from AND :to',
    $dateParams
);
$prevSignups = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `users` WHERE `created_at` BETWEEN :from AND :to',
    ['from' => $filters['prev_from_dt'], 'to' => $filters['prev_to_dt']]
);

$signupSeries = report_fill_series(
    Database::fetchPairs(
        'SELECT ' . report_bucket_sql('`created_at`', $filters['group']) . ' AS bucket, COUNT(*)
         FROM `users`
         WHERE `created_at` BETWEEN :from AND :to
         GROUP BY bucket',
        $dateParams
    ),
    $filters
);

$topCustomers = Database::fetchAll(
    'SELECT u.`id`, u.`first_name`, u.`last_name`, u.`email`, u.`status`, u.`created_at`,
            COUNT(o.`id`)                      AS lifetime_orders,
            COALESCE(SUM(o.`total_amount`), 0) AS lifetime_spend,
            MAX(o.`created_at`)                AS last_order_at,
            COALESCE(SUM(CASE WHEN o.`created_at` BETWEEN :spend_from AND :spend_to
                              THEN o.`total_amount` ELSE 0 END), 0) AS period_spend,
            COALESCE(SUM(CASE WHEN o.`created_at` BETWEEN :count_from AND :count_to
                              THEN 1 ELSE 0 END), 0)                AS period_orders
     FROM `users` u
     INNER JOIN `orders` o ON o.`user_id` = u.`id` AND ' . $revenueSql . '
     GROUP BY u.`id`, u.`first_name`, u.`last_name`, u.`email`, u.`status`, u.`created_at`
     ORDER BY lifetime_spend DESC, lifetime_orders DESC
     LIMIT 15',
    [
        'spend_from' => $filters['from_dt'], 'spend_to' => $filters['to_dt'],
        'count_from' => $filters['from_dt'], 'count_to' => $filters['to_dt'],
    ]
);

$customerKey = 'COALESCE(CAST(o.`user_id` AS CHAR), o.`customer_email`)';

$byCity = Database::fetchAll(
    'SELECT o.`shipping_city` AS city, o.`shipping_state` AS state,
            COUNT(DISTINCT ' . $customerKey . ')  AS customers,
            COUNT(*)                              AS orders,
            COALESCE(SUM(o.`total_amount`), 0)    AS revenue
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY o.`shipping_state`, o.`shipping_city`
     ORDER BY revenue DESC, orders DESC
     LIMIT 20',
    $dateParams
);

$byState = Database::fetchAll(
    'SELECT o.`shipping_state` AS state,
            COUNT(DISTINCT ' . $customerKey . ')  AS customers,
            COUNT(*)                              AS orders,
            COALESCE(SUM(o.`total_amount`), 0)    AS revenue
     FROM `orders` o
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY o.`shipping_state`
     ORDER BY revenue DESC, orders DESC
     LIMIT 15',
    $dateParams
);

$stateMax = (float) ($byState[0]['revenue'] ?? 0);

$pageTitle    = 'Customer Report';
$pageSubtitle = report_range_subtitle($filters);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reports'],
    ['label' => 'Customers'],
];

$pageActions = '';
if ($canViewCustomers) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('customers/')) . '">'
        . icon('users', 'w-4 h-4') . ' All customers</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= report_nav('customers', $filters) ?>
    <?= report_filter_bar('customers', $filters) ?>
</div>

<!-- ============================ Headline tiles =========================== -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= report_stat_card('New Customers', number_format($mix['new_customers']), 'user', 'primary',
        (float) $mix['new_customers'], (float) $prevMix['new_customers'],
        'First order ever fell in this range') ?>
    <?= report_stat_card('Returning Customers', number_format($mix['returning_customers']), 'users', 'blue',
        (float) $mix['returning_customers'], (float) $prevMix['returning_customers'],
        'Had ordered before this range') ?>
    <?= report_stat_card('Signups', number_format($signups), 'award', 'violet',
        (float) $signups, (float) $prevSignups,
        'vs ' . number_format($prevSignups) . ' before') ?>
    <?= admin_stat_card('Avg Lifetime Value', money($lifetime['avg_ltv']), 'wallet', 'green',
        'All time, across ' . number_format($lifetime['customers']) . ' buyers') ?>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Repeat Purchase Rate', report_percent_text($lifetime['repeat_rate']), 'refresh', 'navy',
        number_format($lifetime['repeat_customers']) . ' buyers with 2+ orders, all time') ?>
    <?= admin_stat_card('Orders per Customer', number_format($lifetime['avg_orders'], 2), 'cart', 'blue',
        'All time average') ?>
    <?= admin_stat_card('Buyers in Range', number_format($buyers), 'users', 'amber',
        number_format($buyerOrders) . ' orders · ' . money($buyerRevenue)) ?>
    <?= admin_stat_card('Revenue per Buyer', money($buyers > 0 ? $buyerRevenue / $buyers : 0.0), 'percent', 'primary',
        'Within ' . $filters['label']) ?>
</div>

<!-- ====================== New vs returning + signups ===================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">New vs returning</div>
                <div class="ad-card__sub">Buyers in <?= e($filters['label']) ?>, split by whether they had ordered before</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($buyers === 0): ?>
                <?= admin_empty('Nobody bought in this range',
                    'Widen the date range to see the customer mix.', null, null, 'users') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Segment</th>
                                <th class="ad-table__num">Customers</th>
                                <th class="ad-table__num">Share</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Revenue</th>
                                <th class="ad-table__num">Avg order</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="sik-status sik-status--green">New</span></td>
                                <td class="ad-table__num"><strong><?= number_format($mix['new_customers']) ?></strong></td>
                                <td class="ad-table__num">
                                    <?= e(report_percent_text(report_percent((float) $mix['new_customers'], (float) $buyers))) ?>
                                </td>
                                <td class="ad-table__num"><?= number_format($mix['new_orders']) ?></td>
                                <td class="ad-table__num"><?= e(money($mix['new_revenue'])) ?></td>
                                <td class="ad-table__num">
                                    <?= e(money($mix['new_orders'] > 0 ? $mix['new_revenue'] / $mix['new_orders'] : 0.0)) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="sik-status sik-status--blue">Returning</span></td>
                                <td class="ad-table__num"><strong><?= number_format($mix['returning_customers']) ?></strong></td>
                                <td class="ad-table__num">
                                    <?= e(report_percent_text(report_percent((float) $mix['returning_customers'], (float) $buyers))) ?>
                                </td>
                                <td class="ad-table__num"><?= number_format($mix['returning_orders']) ?></td>
                                <td class="ad-table__num"><?= e(money($mix['returning_revenue'])) ?></td>
                                <td class="ad-table__num">
                                    <?= e(money($mix['returning_orders'] > 0 ? $mix['returning_revenue'] / $mix['returning_orders'] : 0.0)) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Signups by <?= e($filters['group']) ?></div>
                <div class="ad-card__sub"><?= number_format($signups) ?> new accounts in <?= e($filters['label']) ?></div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($signupSeries, '', 210) ?></div>
    </div>
</div>

<!-- ============================ Top customers =========================== -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Top customers by lifetime spend</div>
            <div class="ad-card__sub">Registered accounts only &mdash; guest checkouts have no customer record</div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($topCustomers === []): ?>
            <?= admin_empty('No customer spend yet',
                'This list fills up once registered customers place revenue-earning orders.',
                null, null, 'users') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="ad-table__num">Lifetime spend</th>
                            <th class="ad-table__num">Lifetime orders</th>
                            <th class="ad-table__num">Avg order</th>
                            <th class="ad-table__num">In this range</th>
                            <th>Last order</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCustomers as $row): ?>
                            <?php
                            $name = trim($row['first_name'] . ' ' . (string) $row['last_name']);
                            $orders = max(1, (int) $row['lifetime_orders']);
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <span class="ad-avatar"><?= e(initials($name)) ?></span>
                                        <div style="min-width:0">
                                            <div class="ad-cellflex__name">
                                                <?php if ($canViewCustomers): ?>
                                                    <a href="<?= e(admin_url('customers/view.php?id=' . (int) $row['id'])) ?>">
                                                        <?= e($name) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($name) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ad-cellflex__meta"><?= e($row['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="ad-table__num"><strong><?= e(money((float) $row['lifetime_spend'])) ?></strong></td>
                                <td class="ad-table__num"><?= number_format((int) $row['lifetime_orders']) ?></td>
                                <td class="ad-table__num"><?= e(money((float) $row['lifetime_spend'] / $orders)) ?></td>
                                <td class="ad-table__num">
                                    <?= e(money((float) $row['period_spend'])) ?>
                                    <div class="ad-cellflex__meta"><?= number_format((int) $row['period_orders']) ?> orders</div>
                                </td>
                                <td class="ad-muted" style="white-space:nowrap"><?= e(format_date($row['last_order_at'], 'd M Y')) ?></td>
                                <td><?= admin_state_badge((string) $row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================ Where they are ========================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Top cities</div>
                <div class="ad-card__sub">From the shipping address on revenue-earning orders</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($byCity === []): ?>
                <?= admin_empty('No delivery addresses in this range',
                    'City figures come from orders shipped in this window.', null, null, 'location') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>City</th>
                                <th>State</th>
                                <th class="ad-table__num">Customers</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byCity as $row): ?>
                                <tr>
                                    <td><strong><?= e((string) $row['city']) ?></strong></td>
                                    <td class="ad-muted"><?= e((string) $row['state']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['customers']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['orders']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $row['revenue'])) ?></strong></td>
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
            <div class="ad-card__title">Revenue by state</div>
        </div>
        <div class="ad-card__body">
            <?php if ($byState === []): ?>
                <p class="ad-muted">No state revenue in this range.</p>
            <?php else: ?>
                <?php foreach ($byState as $row): ?>
                    <?= admin_progress_row(
                        (string) $row['state'],
                        (float) $row['revenue'],
                        $stateMax,
                        money((float) $row['revenue']) . '  ·  ' . number_format((int) $row['customers']) . ' customers'
                    ) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
