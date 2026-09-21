<?php
/**
 * ShopInnKart Admin - Dashboard.
 *
 * Every number here is a live query. Charts are plain CSS bars — a store
 * this size does not need a charting library shipped to every admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$admin = admin_require('dashboard.view');

$pageTitle = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . $admin['name'] . '. Here is how the store is doing.';

// ---------------------------------------------------------------------------
// Revenue only counts orders that actually earned money — see
// REVENUE_ORDER_STATUSES in config/constants.php, which the reports module and
// the customer screens read from too.
// ---------------------------------------------------------------------------
const DASH_REVENUE_STATUSES = REVENUE_ORDER_STATUSES_SQL;

$revenueWhere = "`status` IN (" . DASH_REVENUE_STATUSES . ")";

$totalRevenue   = (float) Database::fetchColumn("SELECT COALESCE(SUM(`total_amount`),0) FROM `orders` WHERE {$revenueWhere}");
$todayRevenue   = (float) Database::fetchColumn("SELECT COALESCE(SUM(`total_amount`),0) FROM `orders` WHERE {$revenueWhere} AND DATE(`created_at`) = CURDATE()");
$monthRevenue   = (float) Database::fetchColumn("SELECT COALESCE(SUM(`total_amount`),0) FROM `orders` WHERE {$revenueWhere} AND `created_at` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$lastMonthRev   = (float) Database::fetchColumn(
    "SELECT COALESCE(SUM(`total_amount`),0) FROM `orders`
     WHERE {$revenueWhere}
       AND `created_at` >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
       AND `created_at` <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);

$orderCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `orders` GROUP BY `status`');
$totalOrders = array_sum($orderCounts);

$totalCustomers = Database::count('users');
$newCustomers   = (int) Database::fetchColumn('SELECT COUNT(*) FROM `users` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)');

$totalProducts  = Database::count('products');
$activeProducts = Database::count('products', "`status` = 'active'");
$lowStock       = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` WHERE `stock` > 0 AND `stock` <= `low_stock_threshold`');
$outOfStock     = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` WHERE `stock` <= 0');

$pendingReviews = Database::count('reviews', "`status` = 'pending'");
$totalReviews   = Database::count('reviews', "`status` = 'approved'");
$newMessages    = Database::count('contact_messages', "`status` = 'new'");
$subscribers    = Database::count('newsletter_subscribers', "`status` = 'active'");

$avgOrderValue = $totalOrders > 0 ? $totalRevenue / max(1, array_sum(array_intersect_key(
    $orderCounts,
    array_flip(['confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'])
))) : 0.0;

$monthDelta = admin_delta($monthRevenue, $lastMonthRev);

// ---------------------------------------------------------------------------
// 14-day revenue series. Days with no orders must still appear, so the series
// is built from a date range rather than from the rows that happen to exist.
// ---------------------------------------------------------------------------
$salesRows = Database::fetchPairs(
    "SELECT DATE(`created_at`) AS d, COALESCE(SUM(`total_amount`),0)
     FROM `orders`
     WHERE {$revenueWhere} AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(`created_at`)"
);
$orderRows = Database::fetchPairs(
    "SELECT DATE(`created_at`) AS d, COUNT(*)
     FROM `orders`
     WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(`created_at`)"
);

$salesSeries = [];
$orderSeries = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('d M', strtotime($date));
    $salesSeries[] = ['label' => $label, 'value' => (float) ($salesRows[$date] ?? 0)];
    $orderSeries[] = ['label' => $label, 'value' => (int) ($orderRows[$date] ?? 0)];
}

// ---------------------------------------------------------------------------
// Lists
// ---------------------------------------------------------------------------
$recentOrders = Database::fetchAll(
    'SELECT `id`, `order_number`, `customer_name`, `total_amount`, `status`, `payment_status`, `created_at`
     FROM `orders` ORDER BY `id` DESC LIMIT 8'
);

// The status test has to be a WHERE on an inner join, not an ON of a LEFT JOIN.
// In the ON position it only decides whether the `orders` columns come back
// NULL — the `order_items` row survives either way, so SUM() quietly added the
// lines of cancelled, returned and refunded orders to each product's revenue.
// The LEFT JOIN also returned the entire catalogue, so never-sold products
// padded the list at 0.00 and the "No sales data yet" empty state below could
// never be reached.
$topProducts = Database::fetchAll(
    "SELECT p.`id`, p.`name`, p.`slug`, p.`main_image`, p.`sold_count`, p.`stock`,
            SUM(oi.`subtotal`) AS revenue
     FROM `products` p
     JOIN `order_items` oi ON oi.`product_id` = p.`id`
     JOIN `orders` o ON o.`id` = oi.`order_id`
     WHERE o.`status` IN (" . DASH_REVENUE_STATUSES . ")
     GROUP BY p.`id`
     ORDER BY revenue DESC, p.`sold_count` DESC
     LIMIT 6"
);
$topRevenue = max(1.0, (float) ($topProducts[0]['revenue'] ?? 1));

$lowStockProducts = Database::fetchAll(
    'SELECT `id`, `name`, `sku`, `main_image`, `stock`, `low_stock_threshold`
     FROM `products`
     WHERE `stock` <= `low_stock_threshold`
     ORDER BY `stock` ASC, `name` ASC
     LIMIT 6'
);

$recentCustomers = Database::fetchAll(
    'SELECT `id`, `first_name`, `last_name`, `email`, `created_at`
     FROM `users` ORDER BY `id` DESC LIMIT 5'
);

$recentActivity = admin_can('logs.view')
    ? Database::fetchAll('SELECT * FROM `activity_logs` ORDER BY `id` DESC LIMIT 8')
    : [];

$pageActions = '';
if (admin_can('products.create')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('products/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Product</a>';
}
if (admin_can('reports.view')) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('reports/sales.php')) . '">'
        . icon('chart', 'w-4 h-4') . ' Reports</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<!-- ============================== Stat tiles ============================= -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Total Revenue', money($totalRevenue), 'wallet', 'primary',
        'Lifetime, excluding cancelled', admin_can('reports.view') ? admin_url('reports/sales.php') : null) ?>
    <?= admin_stat_card("Today's Revenue", money($todayRevenue), 'trending', 'green',
        money($monthRevenue) . ' this month') ?>
    <?= admin_stat_card('Total Orders', number_format($totalOrders), 'cart', 'blue',
        (int) ($orderCounts['pending'] ?? 0) . ' awaiting action',
        admin_can('orders.view') ? admin_url('orders/') : null) ?>
    <?= admin_stat_card('Customers', number_format($totalCustomers), 'users', 'violet',
        '+' . $newCustomers . ' in 30 days',
        admin_can('customers.view') ? admin_url('customers/') : null) ?>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Products', number_format($activeProducts) . ' / ' . number_format($totalProducts), 'package', 'navy',
        'Active / total',
        admin_can('products.view') ? admin_url('products/') : null) ?>
    <?= admin_stat_card('Low Stock', number_format($lowStock), 'alert', 'amber',
        'At or below threshold',
        admin_can('products.view') ? admin_url('products/inventory.php?filter=low') : null) ?>
    <?= admin_stat_card('Out of Stock', number_format($outOfStock), 'alert', 'red',
        'Needs restocking',
        admin_can('products.view') ? admin_url('products/inventory.php?filter=out') : null) ?>
    <?= admin_stat_card('Avg Order Value', money($avgOrderValue), 'percent', 'green',
        number_format($totalReviews) . ' reviews · ' . number_format($subscribers) . ' subscribers') ?>
</div>

<?php if ($lowStock > 0 || $outOfStock > 0 || $pendingReviews > 0 || $newMessages > 0): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('bell', 'w-5 h-5') ?>
        <div>
            <strong>Needs your attention:</strong>
            <?php
            $notices = [];
            if ($outOfStock > 0)     { $notices[] = $outOfStock . ' product(s) out of stock'; }
            if ($lowStock > 0)       { $notices[] = $lowStock . ' running low'; }
            if ($pendingReviews > 0) { $notices[] = $pendingReviews . ' review(s) awaiting moderation'; }
            if ($newMessages > 0)    { $notices[] = $newMessages . ' unread message(s)'; }
            echo e(implode(' · ', $notices));
            ?>
        </div>
    </div>
<?php endif; ?>

<!-- ================================ Charts ============================== -->
<div class="ad-grid ad-grid--2" style="margin-bottom:0">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Revenue &mdash; last 14 days</div>
                <div class="ad-card__sub">
                    <?= e(money($monthRevenue)) ?> this month
                    <?= admin_delta_badge($monthDelta) ?>
                    vs last month
                </div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($salesSeries, '₹', 190) ?></div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Orders &mdash; last 14 days</div>
                <div class="ad-card__sub"><?= number_format(array_sum(array_column($orderSeries, 'value'))) ?> orders in this window</div>
            </div>
        </div>
        <div class="ad-card__body"><?= admin_bar_chart($orderSeries, '', 190) ?></div>
    </div>
</div>

<!-- ============================ Order pipeline =========================== -->
<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">Order pipeline</div>
        <?php if (admin_can('orders.view')): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('orders/')) ?>">View all orders</a>
        <?php endif; ?>
    </div>
    <div class="ad-card__body">
        <div class="ad-grid ad-grid--4" style="gap:10px">
            <?php foreach (ORDER_STATUSES as $status => $label): ?>
                <?php $count = (int) ($orderCounts[$status] ?? 0); ?>
                <a class="ad-stat" style="padding:12px"
                   href="<?= e(admin_url('orders/?status=' . urlencode($status))) ?>">
                    <span class="ad-stat__body">
                        <span class="ad-stat__value" style="font-size:17px"><?= number_format($count) ?></span>
                        <span class="ad-stat__label"><?= e($label) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================= Recent orders + top ========================= -->
<div class="ad-grid ad-grid--sidebar">
    <div>
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Recent orders</div>
                <?php if (admin_can('orders.view')): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('orders/')) ?>">View all</a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($recentOrders === []): ?>
                    <?= admin_empty('No orders yet', 'Orders will appear here as soon as customers start buying.', null, null, 'cart') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="ad-table__num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <?php if (admin_can('orders.view')): ?>
                                                <a class="ad-mono" style="font-weight:700"
                                                   href="<?= e(admin_url('orders/view.php?id=' . (int) $order['id'])) ?>">
                                                    <?= e($order['order_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="ad-mono"><?= e($order['order_number']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($order['customer_name']) ?></td>
                                        <td class="ad-muted"><?= e(format_date($order['created_at'], 'd M, g:i A')) ?></td>
                                        <td><?= admin_status_badge((string) $order['status']) ?></td>
                                        <td class="ad-table__num"><strong><?= e(money((float) $order['total_amount'])) ?></strong></td>
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
                <div class="ad-card__title">Top products by revenue</div>
            </div>
            <div class="ad-card__body">
                <?php if ($topProducts === []): ?>
                    <p class="ad-muted">No sales data yet.</p>
                <?php else: ?>
                    <?php foreach ($topProducts as $product): ?>
                        <?= admin_progress_row(
                            (string) $product['name'],
                            (float) $product['revenue'],
                            $topRevenue,
                            money((float) $product['revenue']) . '  ·  ' . (int) $product['sold_count'] . ' sold',
                            admin_can('products.edit') ? admin_url('products/edit.php?id=' . (int) $product['id']) : null
                        ) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <?php if ($lowStockProducts !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div class="ad-card__title">Stock alerts</div>
                    <?php if (admin_can('products.view')): ?>
                        <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/inventory.php')) ?>">Manage</a>
                    <?php endif; ?>
                </div>
                <div class="ad-card__body" style="display:grid;gap:12px">
                    <?php foreach ($lowStockProducts as $product): ?>
                        <div class="ad-cellflex">
                            <img class="ad-thumb" src="<?= e(img_url($product['main_image'])) ?>" alt="" loading="lazy">
                            <div style="flex:1;min-width:0">
                                <div class="ad-cellflex__name" style="font-size:13px"><?= e(str_limit($product['name'], 42)) ?></div>
                                <div class="ad-cellflex__meta"><?= e($product['sku']) ?></div>
                            </div>
                            <?= admin_stock_badge((int) $product['stock'], (int) $product['low_stock_threshold']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($recentCustomers !== [] && admin_can('customers.view')): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div class="ad-card__title">New customers</div>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('customers/')) ?>">View all</a>
                </div>
                <div class="ad-card__body" style="display:grid;gap:12px">
                    <?php foreach ($recentCustomers as $customer): ?>
                        <a class="ad-cellflex" href="<?= e(admin_url('customers/view.php?id=' . (int) $customer['id'])) ?>">
                            <span class="ad-avatar"><?= e(initials($customer['first_name'] . ' ' . (string) $customer['last_name'])) ?></span>
                            <span style="flex:1;min-width:0">
                                <span class="ad-cellflex__name" style="display:block;font-size:13px">
                                    <?= e(trim($customer['first_name'] . ' ' . (string) $customer['last_name'])) ?>
                                </span>
                                <span class="ad-cellflex__meta"><?= e($customer['email']) ?></span>
                            </span>
                            <span class="ad-cellflex__meta"><?= e(time_ago($customer['created_at'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($recentActivity !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div class="ad-card__title">Recent activity</div>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('logs/activity.php')) ?>">View log</a>
                </div>
                <div class="ad-card__body" style="display:grid;gap:11px;font-size:13px">
                    <?php foreach ($recentActivity as $entry): ?>
                        <div style="display:flex;gap:9px;align-items:flex-start">
                            <span style="color:var(--ad-muted);margin-top:2px"><?= icon('clock', 'w-4 h-4') ?></span>
                            <div style="min-width:0">
                                <div><?= e($entry['description'] ?: $entry['action']) ?></div>
                                <div class="ad-cellflex__meta">
                                    <?= e($entry['admin_name'] ?: 'System') ?> · <?= e(time_ago($entry['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
