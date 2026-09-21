<?php
/**
 * ShopInnKart Admin - Inventory.
 *
 * Every change here goes through adjust_stock(), so the products table and
 * the stock_movements journal can never disagree about how much is on hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.view');

require_once ADMIN_PATH . '/products/_shared.php';

$canEdit  = admin_can('products.edit');
$reasons  = product_movement_reasons();
$selfUrl  = admin_url('products/inventory.php');

// ---------------------------------------------------------------------------
//  Writes
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    if (!$canEdit) {
        flash('error', 'You do not have permission to change stock.');
        redirect_back($selfUrl);
    }

    $action = (string) input('action', '') !== '' ? (string) input('action', '') : (string) input('bulk_action', '');

    // --- single row adjustment ---------------------------------------------
    if ($action === 'adjust') {
        $productId = max(0, input_int('product_id'));
        $variantId = max(0, input_int('variant_id'));
        $delta     = input_int('delta');
        $reason    = (string) input('reason', 'adjust');
        $note      = trim((string) input('note', ''));

        $product = $productId > 0
            ? Database::fetch('SELECT `id`, `name`, `sku`, `stock`, `has_variants` FROM `products` WHERE `id` = :id', ['id' => $productId])
            : null;

        if ($product === null) {
            flash('error', 'That product could not be found.');
        } elseif ($delta === 0) {
            flash('error', 'Enter how many units to add (5) or remove (-5).');
        } elseif (!array_key_exists($reason, $reasons)) {
            flash('error', 'Choose a valid reason for the adjustment.');
        } elseif ((int) $product['has_variants'] === 1 && $variantId === 0) {
            flash('error', 'This product holds its stock in variants — pick the variant to adjust.');
        } elseif ($variantId > 0 && !Database::exists('product_variants', '`id` = :id AND `product_id` = :pid', ['id' => $variantId, 'pid' => $productId])) {
            flash('error', 'That variant does not belong to this product.');
        } else {
            adjust_stock(
                $productId,
                $variantId > 0 ? $variantId : null,
                $delta,
                $reason,
                'inventory',
                $productId,
                $note !== '' ? mb_substr($note, 0, 255) : $reasons[$reason]
            );

            log_activity('product.stock_adjusted', 'product', $productId,
                ($delta > 0 ? '+' : '') . $delta . ' units on "' . $product['name'] . '" (' . $reasons[$reason] . ')');
            admin_after_write();

            flash('success', 'Stock updated: ' . ($delta > 0 ? '+' : '') . $delta . ' on ' . $product['sku'] . '.');
        }

        redirect_back($selfUrl);
    }

    // --- bulk "set stock to" ------------------------------------------------
    if ($action === 'set_stock') {
        $ids    = array_values(array_unique(array_filter(array_map('intval', input_array('ids')))));
        $target = input_int('bulk_stock', -1);

        if ($ids === []) {
            flash('error', 'Select at least one product first.');
        } elseif ($target < 0) {
            flash('error', 'Enter the stock level to set (zero or more).');
        } else {
            [$placeholders, $params] = Database::inPlaceholders($ids, 'id');
            $rows = Database::fetchAll(
                'SELECT `id`, `sku`, `stock`, `has_variants` FROM `products` WHERE `id` IN (' . $placeholders . ')',
                $params
            );

            $changed = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if ((int) $row['has_variants'] === 1) {
                    // The parent total is derived from its variants; setting it here would lie.
                    $skipped++;
                    continue;
                }
                $delta = $target - (int) $row['stock'];
                if ($delta === 0) {
                    continue;
                }
                adjust_stock((int) $row['id'], null, $delta, 'adjust', 'inventory_bulk', (int) $row['id'],
                    'Bulk set to ' . $target);
                $changed++;
            }

            log_activity('product.stock_bulk_set', 'product', null,
                $changed . ' product(s) set to ' . $target . ' units');
            admin_after_write();

            flash('success', $changed . ' product(s) set to ' . $target . ' units.');
            if ($skipped > 0) {
                flash('warning', $skipped . ' product(s) were skipped because their stock comes from variants. '
                    . 'Adjust those variant by variant.');
            }
        }

        redirect_back($selfUrl);
    }

    flash('error', 'That action is not recognised.');
    redirect_back($selfUrl);
}

// ---------------------------------------------------------------------------
//  Reads
// ---------------------------------------------------------------------------
$filter = admin_filter('filter', ['all', 'low', 'out'], 'all');
$search = trim((string) ($_GET['q'] ?? ''));

$where  = ['1'];
$params = [];

if ($filter === 'low') {
    $where[] = 'p.`stock` > 0 AND p.`stock` <= p.`low_stock_threshold`';
} elseif ($filter === 'out') {
    $where[] = 'p.`stock` <= 0';
}
if ($search !== '') {
    $where[] = '(p.`name` LIKE :q1 OR p.`sku` LIKE :q2 OR p.`model_number` LIKE :q3)';
    $like = '%' . $search . '%';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` p WHERE ' . $whereSql, $params);
$pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$products = Database::fetchAll(
    'SELECT p.`id`, p.`name`, p.`sku`, p.`main_image`, p.`stock`, p.`low_stock_threshold`,
            p.`status`, p.`has_variants`, p.`sold_count`, p.`cost_price`, p.`price`,
            c.`name` AS category_name
     FROM `products` p
     LEFT JOIN `categories` c ON c.`id` = p.`category_id`
     WHERE ' . $whereSql . '
     ORDER BY p.`stock` ASC, p.`name` ASC
     LIMIT ' . $limit . ' OFFSET ' . $offset,
    $params
);

// Variants for the rows on this page, so each one can be adjusted directly.
$variantsByProduct = [];
$variantProductIds = array_values(array_filter(
    array_map(static fn (array $p): int => (int) $p['id'], $products),
    static fn (int $id): bool => $id > 0
));
if ($variantProductIds !== []) {
    [$placeholders, $variantParams] = Database::inPlaceholders($variantProductIds, 'p');
    foreach (Database::fetchAll(
        'SELECT `id`, `product_id`, `variant_name`, `sku`, `stock`, `status`
         FROM `product_variants` WHERE `product_id` IN (' . $placeholders . ')
         ORDER BY `is_default` DESC, `id`',
        $variantParams
    ) as $variant) {
        $variantsByProduct[(int) $variant['product_id']][] = $variant;
    }
}

$counts = [
    'all' => Database::count('products'),
    'low' => (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` WHERE `stock` > 0 AND `stock` <= `low_stock_threshold`'),
    'out' => (int) Database::fetchColumn('SELECT COUNT(*) FROM `products` WHERE `stock` <= 0'),
];
$totalUnits = (int) Database::fetchColumn('SELECT COALESCE(SUM(`stock`), 0) FROM `products`');
$stockValue = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(`stock` * COALESCE(`cost_price`, COALESCE(NULLIF(`sale_price`, 0), `price`))), 0) FROM `products`'
);

$movements = Database::fetchAll(
    'SELECT sm.*, p.`name` AS product_name, p.`sku` AS product_sku, pv.`variant_name`, ad.`name` AS admin_name
     FROM `stock_movements` sm
     INNER JOIN `products` p ON p.`id` = sm.`product_id`
     LEFT JOIN `product_variants` pv ON pv.`id` = sm.`variant_id`
     LEFT JOIN `admins` ad ON ad.`id` = sm.`admin_id`
     ORDER BY sm.`id` DESC
     LIMIT 40'
);

$pageTitle    = 'Inventory';
$pageSubtitle = 'Adjust stock in place. Every change is journalled with who made it and why.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products', 'url' => admin_url('products/')],
    ['label' => 'Inventory'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('products/')) . '">'
    . icon('list', 'w-4 h-4') . ' Product list</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Products tracked', number_format($counts['all']), 'package', 'navy',
        number_format($totalUnits) . ' units on hand') ?>
    <?= admin_stat_card('Running low', number_format($counts['low']), 'alert', 'amber',
        'At or below their threshold', $selfUrl . '?filter=low') ?>
    <?= admin_stat_card('Out of stock', number_format($counts['out']), 'alert', 'red',
        'Nothing left to sell', $selfUrl . '?filter=out') ?>
    <?= admin_stat_card('Stock value', money($stockValue), 'wallet', 'green',
        'At cost price where known') ?>
</div>

<div class="ad-card">
    <div class="ad-tabs">
        <a class="ad-tab <?= $filter === 'all' ? 'is-active' : '' ?>" href="<?= e(url_with(['filter' => null, 'page' => null])) ?>">
            All <span class="ad-tab__count"><?= number_format($counts['all']) ?></span>
        </a>
        <a class="ad-tab <?= $filter === 'low' ? 'is-active' : '' ?>" href="<?= e(url_with(['filter' => 'low', 'page' => null])) ?>">
            Low stock <span class="ad-tab__count"><?= number_format($counts['low']) ?></span>
        </a>
        <a class="ad-tab <?= $filter === 'out' ? 'is-active' : '' ?>" href="<?= e(url_with(['filter' => 'out', 'page' => null])) ?>">
            Out of stock <span class="ad-tab__count"><?= number_format($counts['out']) ?></span>
        </a>
    </div>

    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>">
        <?php if ($filter !== 'all'): ?>
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="inventorySearch">Search products</label>
            <input type="search" class="sik-input" id="inventorySearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Name, SKU or model&hellip;">
        </div>
        <button type="submit" class="ad-btn"><?= icon('filter', 'w-4 h-4') ?> Search</button>
        <a class="ad-btn" href="<?= e($selfUrl) ?>"><?= icon('refresh', 'w-4 h-4') ?> Reset</a>
    </form>

    <?php if ($canEdit): ?>
        <form class="ad-bulk" method="post" action="<?= e($selfUrl) ?>" data-bulk-bar data-bulk-form>
            <?= csrf_field() ?>
            <strong><span data-bulk-count>0</span> selected</strong>
            <select class="sik-select" name="bulk_action" aria-label="Bulk stock action">
                <option value="set_stock">Set stock to</option>
            </select>
            <label class="sik-sr" for="bulkStock">Stock level</label>
            <input type="number" class="sik-input" id="bulkStock" name="bulk_stock" min="0" step="1"
                   value="0" style="width:110px" required>
            <button type="submit" class="ad-btn ad-btn--primary">Apply</button>
        </form>
    <?php endif; ?>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($products === []): ?>
            <?= admin_empty(
                $filter === 'out' ? 'Nothing is out of stock' : ($filter === 'low' ? 'Nothing is running low' : 'No products yet'),
                $filter === 'all'
                    ? 'Add a product and its stock will be tracked here.'
                    : 'Good news — no products match this filter right now.',
                admin_can('products.create') ? 'Add Product' : null,
                admin_can('products.create') ? admin_url('products/create.php') : null
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table" style="min-width:940px">
                    <thead>
                        <tr>
                            <?php if ($canEdit): ?>
                                <th class="ad-table__check">
                                    <input type="checkbox" data-check-all aria-label="Select all rows on this page">
                                </th>
                            <?php endif; ?>
                            <th>Product</th>
                            <th>Category</th>
                            <th class="ad-table__num">Threshold</th>
                            <th class="ad-table__num">Sold</th>
                            <th>Stock</th>
                            <?php if ($canEdit): ?><th>Adjust</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $rowId       = (int) $product['id'];
                            $rowVariants = $variantsByProduct[$rowId] ?? [];
                            ?>
                            <tr>
                                <?php if ($canEdit): ?>
                                    <td class="ad-table__check">
                                        <input type="checkbox" data-check-row value="<?= $rowId ?>"
                                               aria-label="Select <?= e($product['name']) ?>">
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($product['main_image'])) ?>" alt="" loading="lazy">
                                        <div style="min-width:0">
                                            <div class="ad-cellflex__name">
                                                <a href="<?= e(admin_url('products/view.php?id=' . $rowId)) ?>">
                                                    <?= e(str_limit((string) $product['name'], 46)) ?>
                                                </a>
                                            </div>
                                            <div class="ad-cellflex__meta">
                                                <?= e((string) $product['sku']) ?>
                                                <?= $rowVariants !== [] ? ' · ' . count($rowVariants) . ' variant(s)' : '' ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="ad-muted"><?= e((string) ($product['category_name'] ?? '—')) ?></td>
                                <td class="ad-table__num"><?= (int) $product['low_stock_threshold'] ?></td>
                                <td class="ad-table__num"><?= number_format((int) $product['sold_count']) ?></td>

                                <td>
                                    <?= admin_stock_badge((int) $product['stock'], (int) $product['low_stock_threshold']) ?>
                                    <?php if ($rowVariants !== []): ?>
                                        <div class="ad-cellflex__meta" style="margin-top:4px">
                                            <?php foreach ($rowVariants as $variant): ?>
                                                <div><?= e((string) $variant['variant_name']) ?>: <?= (int) $variant['stock'] ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <?php if ($canEdit): ?>
                                    <td>
                                        <form method="post" action="<?= e($selfUrl) ?>"
                                              style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="adjust">
                                            <input type="hidden" name="product_id" value="<?= $rowId ?>">

                                            <?php if ($rowVariants !== []): ?>
                                                <label class="sik-sr" for="variant<?= $rowId ?>">Variant</label>
                                                <select class="sik-select" id="variant<?= $rowId ?>" name="variant_id"
                                                        style="width:auto;padding:7px 28px 7px 10px;font-size:12.5px">
                                                    <?php foreach ($rowVariants as $variant): ?>
                                                        <option value="<?= (int) $variant['id'] ?>">
                                                            <?= e((string) $variant['variant_name']) ?> (<?= (int) $variant['stock'] ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" name="variant_id" value="0">
                                            <?php endif; ?>

                                            <label class="sik-sr" for="delta<?= $rowId ?>">Change</label>
                                            <input type="number" class="sik-input" id="delta<?= $rowId ?>" name="delta"
                                                   step="1" required placeholder="&plusmn;0"
                                                   style="width:82px;padding:7px 10px;font-size:12.5px">

                                            <label class="sik-sr" for="reason<?= $rowId ?>">Reason</label>
                                            <select class="sik-select" id="reason<?= $rowId ?>" name="reason"
                                                    style="width:auto;padding:7px 28px 7px 10px;font-size:12.5px">
                                                <?= admin_options($reasons, 'restock') ?>
                                            </select>

                                            <label class="sik-sr" for="note<?= $rowId ?>">Note</label>
                                            <input type="text" class="sik-input" id="note<?= $rowId ?>" name="note"
                                                   maxlength="255" placeholder="Note (optional)"
                                                   style="width:150px;padding:7px 10px;font-size:12.5px">

                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">Apply</button>
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

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted" style="font-size:12.5px">
                Showing <?= number_format($pagination['from']) ?>–<?= number_format($pagination['to']) ?>
                of <?= number_format($pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Stock movement log</div>
            <div class="ad-card__sub">The last 40 changes across the whole catalogue.</div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($movements === []): ?>
            <?= admin_empty('No stock movements yet', 'Orders, cancellations, imports and manual adjustments all land here.', null, null, 'clock') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>When</th><th>Product</th><th>Variant</th><th>Type</th>
                            <th class="ad-table__num">Change</th><th class="ad-table__num">Before → after</th>
                            <th>By</th><th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <?php $quantity = (int) $movement['quantity']; ?>
                            <tr>
                                <td class="ad-muted"><?= e(format_datetime($movement['created_at'], 'd M, g:i A')) ?></td>
                                <td>
                                    <a href="<?= e(admin_url('products/view.php?id=' . (int) $movement['product_id'])) ?>">
                                        <?= e(str_limit((string) $movement['product_name'], 34)) ?>
                                    </a>
                                    <div class="ad-cellflex__meta"><?= e((string) $movement['product_sku']) ?></div>
                                </td>
                                <td class="ad-muted"><?= e((string) ($movement['variant_name'] ?? '—')) ?></td>
                                <td><span class="sik-status sik-status--gray"><?= e(ucfirst((string) $movement['movement_type'])) ?></span></td>
                                <td class="ad-table__num" style="color:<?= $quantity < 0 ? '#B91C1C' : '#15803D' ?>;font-weight:700">
                                    <?= $quantity > 0 ? '+' : '' ?><?= $quantity ?>
                                </td>
                                <td class="ad-table__num ad-muted">
                                    <?= (int) $movement['stock_before'] ?> &rarr; <?= (int) $movement['stock_after'] ?>
                                </td>
                                <td class="ad-muted"><?= e((string) ($movement['admin_name'] ?? 'System')) ?></td>
                                <td class="ad-muted"><?= e(str_limit((string) ($movement['note'] ?? ''), 50)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
