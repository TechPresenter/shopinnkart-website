<?php
/**
 * ShopInnKart Admin - Product list.
 *
 * Filters, sorting and the CSV export all read the same clause from
 * product_list_filters(), so "export" always means "what I am looking at".
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.view');

require_once ADMIN_PATH . '/products/_shared.php';

$query   = product_list_filters();
$filters = $query['filters'];
$order   = product_list_order();

$total      = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` p WHERE ' . $query['where'], $query['params']);
$pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$products = Database::fetchAll(
    'SELECT p.`id`, p.`name`, p.`slug`, p.`sku`, p.`main_image`, p.`price`, p.`sale_price`,
            p.`stock`, p.`low_stock_threshold`, p.`status`, p.`sold_count`, p.`rating_avg`,
            p.`rating_count`, p.`has_variants`, p.`is_featured`, p.`is_new_arrival`,
            p.`is_best_seller`, p.`is_trending`, p.`created_at`,
            b.`name` AS brand_name, c.`name` AS category_name
     FROM `products` p
     LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
     LEFT JOIN `categories` c ON c.`id` = p.`category_id`
     WHERE ' . $query['where'] . '
     ORDER BY ' . $order['sql'] . '
     LIMIT ' . $limit . ' OFFSET ' . $offset,
    $query['params']
);

$statusCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `products` GROUP BY `status`');
$allCount     = array_sum($statusCounts);

$categoryOptions = admin_category_options($filters['category_id'] > 0 ? $filters['category_id'] : null, null, 'All categories');
$brandOptions    = admin_lookup('brands');

$pageTitle    = 'Products';
$pageSubtitle = number_format($total) . ' of ' . number_format($allCount) . ' products match the current filters.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products'],
];

$exportQuery = $_GET;
unset($exportQuery['page']);

$pageActions = '<a class="ad-btn" href="' . e(admin_url('products/export.php') . ($exportQuery === [] ? '' : '?' . http_build_query($exportQuery))) . '">'
    . icon('download', 'w-4 h-4') . ' Export CSV</a>';

if (admin_can('products.edit')) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('products/inventory.php')) . '">'
        . icon('package', 'w-4 h-4') . ' Inventory</a>';
}
if (admin_can('products.create')) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('products/import.php')) . '">'
        . icon('upload', 'w-4 h-4') . ' Import CSV</a>'
        . '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('products/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Product</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-tabs">
        <a class="ad-tab <?= $filters['status'] === '' ? 'is-active' : '' ?>" href="<?= e(url_with(['status' => null, 'page' => null])) ?>">
            All <span class="ad-tab__count"><?= number_format($allCount) ?></span>
        </a>
        <?php foreach (product_status_options() as $statusKey => $statusLabel): ?>
            <a class="ad-tab <?= $filters['status'] === $statusKey ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $statusKey, 'page' => null])) ?>">
                <?= e($statusLabel) ?>
                <span class="ad-tab__count"><?= number_format((int) ($statusCounts[$statusKey] ?? 0)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('products/')) ?>">
        <?php if ($filters['status'] !== ''): ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($order['sort']) ?>">
        <input type="hidden" name="dir" value="<?= e($order['dir']) ?>">

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="productSearch">Search products</label>
            <input type="search" class="sik-input" id="productSearch" name="q" data-filter-search
                   value="<?= e($filters['q']) ?>" placeholder="Name, SKU, model or part number&hellip;">
        </div>

        <select class="sik-select" name="category_id" data-auto-submit aria-label="Filter by category">
            <?= $categoryOptions ?>
        </select>

        <select class="sik-select" name="brand_id" data-auto-submit aria-label="Filter by brand">
            <?= admin_options($brandOptions, $filters['brand_id'] > 0 ? $filters['brand_id'] : '', 'All brands') ?>
        </select>

        <select class="sik-select" name="stock" data-auto-submit aria-label="Filter by stock level">
            <?= admin_options(product_stock_options(), $filters['stock'], 'Any stock level') ?>
        </select>

        <select class="sik-select" name="flag" data-auto-submit aria-label="Filter by placement flag">
            <?= admin_options(product_flag_options(), $filters['flag'], 'Any flag') ?>
        </select>

        <button type="submit" class="ad-btn"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <a class="ad-btn" href="<?= e(admin_url('products/')) ?>"><?= icon('refresh', 'w-4 h-4') ?> Reset</a>
    </form>

    <?php if (admin_can('products.edit')): ?>
        <form class="ad-bulk" method="post" action="<?= e(admin_url('products/bulk-action.php')) ?>"
              data-bulk-bar data-bulk-form>
            <?= csrf_field() ?>
            <strong><span data-bulk-count>0</span> selected</strong>
            <select class="sik-select" name="bulk_action" required aria-label="Bulk action">
                <option value="">Choose an action&hellip;</option>
                <option value="activate">Set status: Active</option>
                <option value="deactivate">Set status: Inactive</option>
                <option value="feature">Mark featured</option>
                <option value="unfeature">Remove featured</option>
                <option value="mark_new">Mark new arrival</option>
                <option value="mark_best">Mark best seller</option>
                <?php if (admin_can('products.delete')): ?>
                    <option value="delete">Delete permanently</option>
                <?php endif; ?>
            </select>
            <button type="submit" class="ad-btn ad-btn--primary">Apply</button>
        </form>
    <?php endif; ?>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($products === []): ?>
            <?= admin_empty(
                'No products match',
                $total === 0 && $allCount === 0
                    ? 'Add your first product, or import a CSV to bring in the whole catalogue at once.'
                    : 'Nothing matched these filters. Clear them and try again.',
                admin_can('products.create') ? 'Add Product' : null,
                admin_can('products.create') ? admin_url('products/create.php') : null
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <?php if (admin_can('products.edit')): ?>
                                <th class="ad-table__check">
                                    <input type="checkbox" data-check-all aria-label="Select all rows on this page">
                                </th>
                            <?php endif; ?>
                            <th><?= admin_sort_header('Product', 'name', $order['sort'], $order['dir']) ?></th>
                            <th><?= admin_sort_header('SKU', 'sku', $order['sort'], $order['dir']) ?></th>
                            <th>Category</th>
                            <th>Brand</th>
                            <th class="ad-table__num"><?= admin_sort_header('Price', 'price', $order['sort'], $order['dir']) ?></th>
                            <th><?= admin_sort_header('Stock', 'stock', $order['sort'], $order['dir']) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Sold', 'sold_count', $order['sort'], $order['dir']) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Rating', 'rating_avg', $order['sort'], $order['dir']) ?></th>
                            <th>Status</th>
                            <th class="ad-table__num"><?= admin_sort_header('Added', 'created_at', $order['sort'], $order['dir']) ?></th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $productId = (int) $product['id'];
                            $price     = (float) $product['price'];
                            $sale      = $product['sale_price'] === null ? 0.0 : (float) $product['sale_price'];
                            $onSale    = $sale > 0 && $sale < $price;

                            $flags = [];
                            if ((int) $product['is_featured'] === 1)    { $flags[] = 'Featured'; }
                            if ((int) $product['is_new_arrival'] === 1) { $flags[] = 'New'; }
                            if ((int) $product['is_best_seller'] === 1) { $flags[] = 'Best seller'; }
                            if ((int) $product['is_trending'] === 1)    { $flags[] = 'Trending'; }
                            if ((int) $product['has_variants'] === 1)   { $flags[] = 'Variants'; }
                            ?>
                            <tr>
                                <?php if (admin_can('products.edit')): ?>
                                    <td class="ad-table__check">
                                        <input type="checkbox" data-check-row value="<?= $productId ?>"
                                               aria-label="Select <?= e($product['name']) ?>">
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($product['main_image'])) ?>" alt="" loading="lazy">
                                        <div style="min-width:0">
                                            <div class="ad-cellflex__name">
                                                <a href="<?= e(admin_url('products/view.php?id=' . $productId)) ?>">
                                                    <?= e(str_limit((string) $product['name'], 60)) ?>
                                                </a>
                                            </div>
                                            <?php if ($flags !== []): ?>
                                                <div class="ad-cellflex__meta"><?= e(implode(' · ', $flags)) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td class="ad-mono"><?= e($product['sku']) ?></td>
                                <td class="ad-muted"><?= e((string) ($product['category_name'] ?? '—')) ?></td>
                                <td class="ad-muted"><?= e((string) ($product['brand_name'] ?? '—')) ?></td>

                                <td class="ad-table__num">
                                    <strong><?= e(money($onSale ? $sale : $price)) ?></strong>
                                    <?php if ($onSale): ?>
                                        <div><span class="sik-price--mrp"><?= e(money($price)) ?></span></div>
                                    <?php endif; ?>
                                </td>

                                <td><?= admin_stock_badge((int) $product['stock'], (int) $product['low_stock_threshold']) ?></td>
                                <td class="ad-table__num"><?= number_format((int) $product['sold_count']) ?></td>
                                <td class="ad-table__num">
                                    <?= (int) $product['rating_count'] > 0
                                        ? e(number_format((float) $product['rating_avg'], 1)) . ' <span class="ad-muted">(' . (int) $product['rating_count'] . ')</span>'
                                        : '<span class="ad-muted">—</span>' ?>
                                </td>
                                <td><?= admin_state_badge((string) $product['status']) ?></td>
                                <td class="ad-table__num ad-muted"><?= e(format_date($product['created_at'], 'd M Y')) ?></td>

                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" href="<?= e(admin_url('products/view.php?id=' . $productId)) ?>"
                                       title="View" aria-label="View"><?= icon('eye', 'w-4 h-4') ?></a>

                                    <?php if (admin_can('products.edit')): ?>
                                        <a class="ad-btn ad-btn--icon" href="<?= e(admin_url('products/edit.php?id=' . $productId)) ?>"
                                           title="Edit" aria-label="Edit"><?= icon('edit', 'w-4 h-4') ?></a>
                                    <?php endif; ?>

                                    <?php if (admin_can('products.create')): ?>
                                        <form method="post" action="<?= e(admin_url('products/duplicate.php')) ?>" class="ad-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $productId ?>">
                                            <button type="submit" class="ad-btn ad-btn--icon" title="Duplicate" aria-label="Duplicate">
                                                <?= icon('copy', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (admin_can('products.delete')): ?>
                                        <?= admin_delete_form(
                                            admin_url('products/delete.php'),
                                            $productId,
                                            'Delete "' . $product['name'] . '"? Its images, specs and variants go with it.'
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted" style="font-size:12.5px">
                Showing <?= number_format($pagination['from']) ?>–<?= number_format($pagination['to']) ?>
                of <?= number_format($pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination, admin_url('products/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
