<?php
/**
 * ShopInnKart Admin - Product performance report.
 *
 * What sold, what people looked at and did not buy, and what is about to run
 * out. Sales figures cover the selected window; the view counter on products is
 * a lifetime total, so the two are always labelled apart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$filters    = report_filter_state();
$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];
$revenueSql = report_revenue_sql();

$canEditProducts = admin_can('products.edit');

$summary = Database::fetch(
    'SELECT COALESCE(SUM(oi.`quantity`), 0)                AS units,
            COALESCE(SUM(oi.`subtotal`), 0)                AS revenue,
            COUNT(DISTINCT oi.`product_id`)                AS products_sold,
            COUNT(DISTINCT o.`id`)                         AS orders
     FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to',
    $dateParams
) ?? [];

$catalogueCount = Database::count('products', "`status` = 'active'");
$lowStockCount  = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `products` WHERE `stock` > 0 AND `stock` <= `low_stock_threshold`'
);
$outOfStockCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` WHERE `stock` <= 0');

$topByUnits   = report_top_products($filters['from_dt'], $filters['to_dt'], 'units', 10);
$topByRevenue = report_top_products($filters['from_dt'], $filters['to_dt'], 'revenue', 10);
$soldByProduct = report_units_sold_by_product($filters['from_dt'], $filters['to_dt']);

$mostViewed = Database::fetchAll(
    "SELECT `id`, `name`, `slug`, `sku`, `main_image`, `views`, `stock`, `sold_count`, `status`
     FROM `products`
     WHERE `views` > 0
     ORDER BY `views` DESC, `name` ASC
     LIMIT 10"
);

// "High views, low sales" needs a floor for "high", and the catalogue's own
// average is a more honest floor than a number picked out of the air.
$averageViews = (int) round((float) Database::fetchColumn(
    "SELECT COALESCE(AVG(`views`), 0) FROM `products` WHERE `status` = 'active' AND `views` > 0"
));
$viewFloor = max(1, $averageViews);

$worstPerformers = Database::fetchAll(
    "SELECT p.`id`, p.`name`, p.`slug`, p.`sku`, p.`main_image`, p.`views`, p.`stock`, p.`status`,
            COALESCE(s.`units`, 0)   AS units,
            COALESCE(s.`revenue`, 0) AS revenue
     FROM `products` p
     LEFT JOIN (
        SELECT oi.`product_id`, SUM(oi.`quantity`) AS units, SUM(oi.`subtotal`) AS revenue
        FROM `order_items` oi
        INNER JOIN `orders` o ON o.`id` = oi.`order_id`
        WHERE " . $revenueSql . " AND o.`created_at` BETWEEN :from AND :to
        GROUP BY oi.`product_id`
     ) s ON s.`product_id` = p.`id`
     WHERE p.`status` = 'active' AND p.`views` >= :floor
     ORDER BY (COALESCE(s.`units`, 0) / GREATEST(p.`views`, 1)) ASC, p.`views` DESC
     LIMIT 10",
    $dateParams + ['floor' => $viewFloor]
);

$lowStock = Database::fetchAll(
    'SELECT `id`, `name`, `sku`, `main_image`, `stock`, `low_stock_threshold`, `sold_count`, `status`
     FROM `products`
     WHERE `stock` > 0 AND `stock` <= `low_stock_threshold`
     ORDER BY `stock` ASC, `sold_count` DESC
     LIMIT 15'
);

$outOfStock = Database::fetchAll(
    'SELECT `id`, `name`, `sku`, `main_image`, `stock`, `sold_count`, `views`, `status`, `updated_at`
     FROM `products`
     WHERE `stock` <= 0
     ORDER BY `sold_count` DESC, `views` DESC
     LIMIT 15'
);

$byCategory = Database::fetchAll(
    'SELECT c.`id` AS category_id,
            COALESCE(c.`name`, \'Uncategorised\') AS name,
            SUM(oi.`quantity`)     AS units,
            SUM(oi.`subtotal`)     AS revenue,
            COUNT(DISTINCT o.`id`) AS orders
     FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     LEFT JOIN `products` p ON p.`id` = oi.`product_id`
     LEFT JOIN `categories` c ON c.`id` = p.`category_id`
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY c.`id`, name
     ORDER BY revenue DESC
     LIMIT 15',
    $dateParams
);

$byBrand = Database::fetchAll(
    'SELECT b.`id` AS brand_id,
            COALESCE(b.`name`, \'No brand\') AS name,
            SUM(oi.`quantity`)     AS units,
            SUM(oi.`subtotal`)     AS revenue,
            COUNT(DISTINCT o.`id`) AS orders
     FROM `order_items` oi
     INNER JOIN `orders` o ON o.`id` = oi.`order_id`
     LEFT JOIN `products` p ON p.`id` = oi.`product_id`
     LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
     WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
     GROUP BY b.`id`, name
     ORDER BY revenue DESC
     LIMIT 15',
    $dateParams
);

$categoryMax = (float) ($byCategory[0]['revenue'] ?? 0);
$brandMax    = (float) ($byBrand[0]['revenue'] ?? 0);

/** Link to the product editor when the admin may open it, plain text otherwise. */
$productLink = static function (array $row) use ($canEditProducts): string {
    $name = e(str_limit((string) $row['name'], 46));
    return $canEditProducts
        ? '<a href="' . e(admin_url('products/edit.php?id=' . (int) $row['id'])) . '">' . $name . '</a>'
        : $name;
};

$pageTitle    = 'Product Report';
$pageSubtitle = report_range_subtitle($filters);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Reports'],
    ['label' => 'Products'],
];

$pageActions = '';
if (admin_can('products.view')) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('products/inventory.php')) . '">'
        . icon('package', 'w-4 h-4') . ' Inventory</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= report_nav('products', $filters) ?>
    <?= report_filter_bar('products', $filters) ?>
</div>

<!-- ============================ Headline tiles =========================== -->
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Units Sold', number_format((int) ($summary['units'] ?? 0)), 'package', 'primary',
        'Across ' . number_format((int) ($summary['orders'] ?? 0)) . ' orders') ?>
    <?= admin_stat_card('Product Revenue', money((float) ($summary['revenue'] ?? 0)), 'wallet', 'green',
        'Line subtotals, before shipping and tax') ?>
    <?= admin_stat_card('Products Sold', number_format((int) ($summary['products_sold'] ?? 0)), 'chart', 'blue',
        'Of ' . number_format($catalogueCount) . ' active products') ?>
    <?= admin_stat_card('Out of Stock', number_format($outOfStockCount), 'alert', 'red',
        number_format($lowStockCount) . ' more running low',
        admin_can('products.view') ? admin_url('products/inventory.php?filter=out') : null) ?>
</div>

<!-- ============================= Best sellers ============================ -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Best sellers by units</div>
                <div class="ad-card__sub">Quantity shipped in <?= e($filters['label']) ?></div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($topByUnits === []): ?>
                <?= admin_empty('Nothing sold in this range',
                    'Try a wider date range to see which products are moving.', null, null, 'package') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Units</th>
                                <th class="ad-table__num">Orders</th>
                                <th class="ad-table__num">Revenue</th>
                                <th class="ad-table__num">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topByUnits as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><strong><?= number_format((int) $row['units']) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['orders']) ?></td>
                                    <td class="ad-table__num"><?= e(money((float) $row['revenue'])) ?></td>
                                    <td class="ad-table__num"><?= admin_stock_badge((int) $row['stock']) ?></td>
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
            <div>
                <div class="ad-card__title">Best sellers by revenue</div>
                <div class="ad-card__sub">Line subtotals in <?= e($filters['label']) ?></div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($topByRevenue === []): ?>
                <?= admin_empty('No revenue in this range',
                    'Revenue appears once orders in this window reach a revenue-earning status.',
                    null, null, 'wallet') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Revenue</th>
                                <th class="ad-table__num">Units</th>
                                <th class="ad-table__num">Avg price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topByRevenue as $row): ?>
                                <?php $units = max(1, (int) $row['units']); ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $row['revenue'])) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['units']) ?></td>
                                    <td class="ad-table__num"><?= e(money((float) $row['revenue'] / $units)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ======================= Attention: views vs sales ===================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Most viewed</div>
                <div class="ad-card__sub">Lifetime view counter, sales from <?= e($filters['label']) ?></div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($mostViewed === []): ?>
                <?= admin_empty('No product views recorded yet',
                    'Views are counted on the storefront product page.', null, null, 'eye') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Views</th>
                                <th class="ad-table__num">Units in range</th>
                                <th class="ad-table__num">Revenue in range</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mostViewed as $row): ?>
                                <?php $sold = $soldByProduct[(int) $row['id']] ?? ['units' => 0, 'revenue' => 0.0]; ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><strong><?= number_format((int) $row['views']) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format($sold['units']) ?></td>
                                    <td class="ad-table__num"><?= e(money($sold['revenue'])) ?></td>
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
            <div>
                <div class="ad-card__title">Worst performers</div>
                <div class="ad-card__sub">
                    At least <?= number_format($viewFloor) ?> views (the catalogue average) and the fewest sales
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($worstPerformers === []): ?>
                <?= admin_empty('Not enough view data yet',
                    'This list needs products with views above the catalogue average.', null, null, 'trending') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Views</th>
                                <th class="ad-table__num">Units</th>
                                <th class="ad-table__num">Units per 100 views</th>
                                <th class="ad-table__num">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($worstPerformers as $row): ?>
                                <?php $rate = ((int) $row['units'] / max(1, (int) $row['views'])) * 100; ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['views']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['units']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(number_format($rate, 2)) ?></strong></td>
                                    <td class="ad-table__num"><?= admin_stock_badge((int) $row['stock']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- =============================== Stock ================================= -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Low stock</div>
                <div class="ad-card__sub"><?= number_format($lowStockCount) ?> at or below their threshold</div>
            </div>
            <?php if (admin_can('products.edit')): ?>
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/inventory.php?filter=low')) ?>">Restock</a>
            <?php endif; ?>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($lowStock === []): ?>
                <?= admin_empty('Nothing running low', 'Every product is above its low-stock threshold.',
                    null, null, 'check-circle') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Stock</th>
                                <th class="ad-table__num">Threshold</th>
                                <th class="ad-table__num">Lifetime sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStock as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num">
                                        <?= admin_stock_badge((int) $row['stock'], (int) $row['low_stock_threshold']) ?>
                                    </td>
                                    <td class="ad-table__num"><?= number_format((int) $row['low_stock_threshold']) ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['sold_count']) ?></td>
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
            <div>
                <div class="ad-card__title">Out of stock</div>
                <div class="ad-card__sub">Ranked by lifetime demand &mdash; restock the top of this list first</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <?php if ($outOfStock === []): ?>
                <?= admin_empty('Everything is in stock', 'No product has hit zero.', null, null, 'check-circle') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="ad-table__num">Lifetime sold</th>
                                <th class="ad-table__num">Views</th>
                                <th>Status</th>
                                <th>Last updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outOfStock as $row): ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($row['main_image'])) ?>" alt="" loading="lazy">
                                            <div style="min-width:0">
                                                <div class="ad-cellflex__name"><?= $productLink($row) ?></div>
                                                <div class="ad-cellflex__meta ad-mono"><?= e((string) $row['sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ad-table__num"><strong><?= number_format((int) $row['sold_count']) ?></strong></td>
                                    <td class="ad-table__num"><?= number_format((int) $row['views']) ?></td>
                                    <td><?= admin_state_badge((string) $row['status']) ?></td>
                                    <td class="ad-muted"><?= e(time_ago($row['updated_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================= Category and brand ========================== -->
<div class="ad-grid ad-grid--2">
    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">Revenue by category</div>
        </div>
        <div class="ad-card__body">
            <?php if ($byCategory === []): ?>
                <p class="ad-muted">No category revenue in this range.</p>
            <?php else: ?>
                <?php foreach ($byCategory as $row): ?>
                    <?= admin_progress_row(
                        (string) $row['name'],
                        (float) $row['revenue'],
                        $categoryMax,
                        money((float) $row['revenue']) . '  ·  ' . number_format((int) $row['units']) . ' units',
                        ($row['category_id'] !== null && admin_can('categories.edit'))
                            ? admin_url('categories/edit.php?id=' . (int) $row['category_id'])
                            : null
                    ) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">Revenue by brand</div>
        </div>
        <div class="ad-card__body">
            <?php if ($byBrand === []): ?>
                <p class="ad-muted">No brand revenue in this range.</p>
            <?php else: ?>
                <?php foreach ($byBrand as $row): ?>
                    <?= admin_progress_row(
                        (string) $row['name'],
                        (float) $row['revenue'],
                        $brandMax,
                        money((float) $row['revenue']) . '  ·  ' . number_format((int) $row['units']) . ' units',
                        ($row['brand_id'] !== null && admin_can('brands.edit'))
                            ? admin_url('brands/edit.php?id=' . (int) $row['brand_id'])
                            : null
                    ) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
