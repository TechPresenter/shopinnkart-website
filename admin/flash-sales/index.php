<?php
/**
 * ShopInnKart Admin - Flash sales list.
 *
 * The storefront only ever runs one flash sale — the active one ending
 * soonest — so the list makes it obvious which row that currently is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('flash_sales.view');

require_once ADMIN_PATH . '/includes/marketing.php';

$search = trim((string) ($_GET['q'] ?? ''));
$state  = admin_filter('state', array_keys(marketing_state_options()));
$type   = admin_filter('type', ['percentage', 'fixed']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'     => 'f.`name`',
    'discount' => 'f.`discount_value`',
    'starts'   => 'f.`start_time`',
    'ends'     => 'f.`end_time`',
    'created'  => 'f.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'starts');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(f.`name` LIKE :q_name OR f.`subtitle` LIKE :q_sub)';
    $params['q_name'] = $params['q_sub'] = '%' . $search . '%';
}
if ($type !== '') {
    $where[] = 'f.`discount_type` = :type';
    $params['type'] = $type;
}
if ($state !== '') {
    $where[] = '(' . marketing_state_where($state, 'f', 'start_time', 'end_time') . ')';
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `flash_sales` f WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$sales = Database::fetchAll(
    "SELECT f.`id`, f.`name`, f.`subtitle`, f.`discount_type`, f.`discount_value`, f.`stock_limit`,
            f.`start_time`, f.`end_time`, f.`status`,
            (SELECT COUNT(*) FROM `flash_sale_products` fp WHERE fp.`flash_sale_id` = f.`id`) AS product_count,
            (SELECT COALESCE(SUM(fp.`stock_sold`), 0) FROM `flash_sale_products` fp WHERE fp.`flash_sale_id` = f.`id`) AS sold,
            (SELECT COALESCE(SUM(fp.`stock_limit`), 0) FROM `flash_sale_products` fp WHERE fp.`flash_sale_id` = f.`id`) AS capped
     FROM `flash_sales` f
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, f.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'       => Database::count('flash_sales'),
    'live'      => Database::count('flash_sales', marketing_state_where('live', 'flash_sales', 'start_time', 'end_time')),
    'scheduled' => Database::count('flash_sales', marketing_state_where('scheduled', 'flash_sales', 'start_time', 'end_time')),
    'expired'   => Database::count('flash_sales', marketing_state_where('expired', 'flash_sales', 'start_time', 'end_time')),
];

// The one the storefront is actually serving right now.
$runningId = (int) Database::fetchColumn(
    "SELECT `id` FROM `flash_sales`
     WHERE `status` = 'active' AND `start_time` <= NOW() AND `end_time` >= NOW()
     ORDER BY `end_time` ASC LIMIT 1"
);

$canEdit    = admin_can('flash_sales.edit');
$canDelete  = admin_can('flash_sales.delete');
$hasFilters = $search !== '' || $state !== '' || $type !== '';

$pageTitle    = 'Flash Sales';
$pageSubtitle = $counts['all'] . ' sales · ' . $counts['live'] . ' inside their window';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Flash Sales'],
];
$pageActions = '';
if (admin_can('flash_sales.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('flash-sales/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Flash Sale</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($counts['live'] > 1): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong><?= (int) $counts['live'] ?> flash sales overlap.</strong>
            The storefront serves the active sale ending soonest, so the others are priced as if they were off.
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php
        $tabs = [
            ''          => ['All', $counts['all']],
            'live'      => ['Live', $counts['live']],
            'scheduled' => ['Scheduled', $counts['scheduled']],
            'expired'   => ['Expired', $counts['expired']],
        ];
        ?>
        <?php foreach ($tabs as $key => [$label, $count]): ?>
            <a class="ad-tab <?= $state === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['state' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('flash-sales/')) ?>">
        <?php if ($state !== ''): ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="flashSearch">Search flash sales</label>
            <input class="sik-input" type="search" id="flashSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name or subtitle&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="flashTypeFilter">Discount type</label>
        <select class="sik-select" id="flashTypeFilter" name="type" data-auto-submit>
            <?= admin_options(['percentage' => 'Percentage off', 'fixed' => 'Fixed amount off'], $type, 'Any discount') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('flash-sales/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($sales === []): ?>
            <?= $hasFilters
                ? admin_empty('No flash sales match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No flash sales yet',
                    'A flash sale drops selected products to a sale price for a short window.',
                    admin_can('flash_sales.create') ? 'Add Flash Sale' : null,
                    admin_can('flash_sales.create') ? admin_url('flash-sales/create.php') : null,
                    'zap'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Sale', 'name', $sort, $dir) ?></th>
                            <th class="ad-table__num">Products</th>
                            <th><?= admin_sort_header('Discount', 'discount', $sort, $dir) ?></th>
                            <th>Units sold</th>
                            <th><?= admin_sort_header('Window', 'ends', $sort, $dir) ?></th>
                            <th>State</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales as $sale): ?>
                            <?php
                            $rowId    = (int) $sale['id'];
                            $rowState = marketing_state($sale['start_time'], $sale['end_time'], (string) $sale['status']);
                            $products = (int) $sale['product_count'];
                            $capped   = (int) $sale['capped'];
                            $discount = $sale['discount_type'] === 'fixed'
                                ? money((float) $sale['discount_value']) . ' off'
                                : rtrim(rtrim(number_format((float) $sale['discount_value'], 2), '0'), '.') . '% off';
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">
                                        <?php if ($canEdit): ?>
                                            <a href="<?= e(admin_url('flash-sales/edit.php?id=' . $rowId)) ?>"><?= e($sale['name']) ?></a>
                                        <?php else: ?>
                                            <?= e($sale['name']) ?>
                                        <?php endif; ?>
                                        <?php if ($rowId === $runningId): ?>
                                            <span class="sik-status sik-status--green">On the storefront</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ad-cellflex__meta">
                                        <?= $sale['subtitle'] !== null && $sale['subtitle'] !== ''
                                            ? e(str_limit((string) $sale['subtitle'], 48))
                                            : '<em>No subtitle</em>' ?>
                                    </span>
                                </td>
                                <td class="ad-table__num">
                                    <?= $products > 0
                                        ? number_format($products)
                                        : '<span class="sik-status sik-status--amber">Empty</span>' ?>
                                </td>
                                <td><strong><?= e($discount) ?></strong></td>
                                <td>
                                    <?= marketing_usage_bar((int) $sale['sold'], $capped > 0 ? $capped : null, 'sold') ?>
                                </td>
                                <td class="ad-muted" style="font-size:12px;white-space:nowrap">
                                    <?= e(marketing_window_text($sale['start_time'], $sale['end_time'])) ?>
                                </td>
                                <td><?= marketing_state_badge($rowState) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($sale['name']) ?>"
                                           href="<?= e(admin_url('flash-sales/edit.php?id=' . $rowId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('flash-sales/delete.php'),
                                            $rowId,
                                            'Delete "' . $sale['name'] . '"? '
                                                . ($products > 0 ? $products . ' product price(s) and their sold counters go with it. ' : '')
                                                . 'This cannot be undone.'
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
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, admin_url('flash-sales/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
