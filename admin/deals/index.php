<?php
/**
 * ShopInnKart Admin - Deals list.
 *
 * The live/scheduled/expired badge is computed from start_time and end_time,
 * so a deal that has quietly aged out is obvious without opening it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('deals.view');

require_once ADMIN_PATH . '/includes/marketing.php';

$search = trim((string) ($_GET['q'] ?? ''));
$state  = admin_filter('state', array_keys(marketing_state_options()));
$type   = admin_filter('type', ['percentage', 'fixed']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'title'    => 'd.`title`',
    'discount' => 'd.`discount_value`',
    'starts'   => 'd.`start_time`',
    'ends'     => 'd.`end_time`',
    'sold'     => 'd.`stock_sold`',
    'created'  => 'd.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'starts');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(d.`title` LIKE :q_title OR d.`subtitle` LIKE :q_sub)';
    $params['q_title'] = $params['q_sub'] = '%' . $search . '%';
}
if ($type !== '') {
    $where[] = 'd.`discount_type` = :type';
    $params['type'] = $type;
}
if ($state !== '') {
    $where[] = '(' . marketing_state_where($state, 'd', 'start_time', 'end_time') . ')';
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `deals` d WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$deals = Database::fetchAll(
    "SELECT d.`id`, d.`title`, d.`subtitle`, d.`discount_type`, d.`discount_value`, d.`stock_limit`,
            d.`stock_sold`, d.`start_time`, d.`end_time`, d.`status`, d.`product_id`,
            p.`name` AS product_name, p.`main_image` AS product_image, p.`slug` AS product_slug,
            (SELECT COUNT(*) FROM `deal_products` dp WHERE dp.`deal_id` = d.`id`) AS product_count
     FROM `deals` d
     LEFT JOIN `products` p ON p.`id` = d.`product_id`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, d.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'       => Database::count('deals'),
    'live'      => Database::count('deals', marketing_state_where('live', 'deals', 'start_time', 'end_time')),
    'scheduled' => Database::count('deals', marketing_state_where('scheduled', 'deals', 'start_time', 'end_time')),
    'expired'   => Database::count('deals', marketing_state_where('expired', 'deals', 'start_time', 'end_time')),
];

$canEdit    = admin_can('deals.edit');
$canDelete  = admin_can('deals.delete');
$hasFilters = $search !== '' || $state !== '' || $type !== '';

$pageTitle    = 'Deals';
$pageSubtitle = $counts['all'] . ' deals · ' . $counts['live'] . ' running right now';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Deals'],
];
$pageActions = '';
if (admin_can('deals.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('deals/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Deal</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

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

    <form class="ad-filters" method="get" action="<?= e(admin_url('deals/')) ?>">
        <?php if ($state !== ''): ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="dealSearch">Search deals</label>
            <input class="sik-input" type="search" id="dealSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by title or subtitle&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="dealTypeFilter">Discount type</label>
        <select class="sik-select" id="dealTypeFilter" name="type" data-auto-submit>
            <?= admin_options(['percentage' => 'Percentage off', 'fixed' => 'Fixed amount off'], $type, 'Any discount') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('deals/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($deals === []): ?>
            <?= $hasFilters
                ? admin_empty('No deals match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No deals yet',
                    'A deal drives the homepage countdown block and reprices the products attached to it.',
                    admin_can('deals.create') ? 'Add Deal' : null,
                    admin_can('deals.create') ? admin_url('deals/create.php') : null,
                    'fire'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Deal', 'title', $sort, $dir) ?></th>
                            <th>Headline product</th>
                            <th><?= admin_sort_header('Discount', 'discount', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Sold', 'sold', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Window', 'ends', $sort, $dir) ?></th>
                            <th>State</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deals as $deal): ?>
                            <?php
                            $rowId    = (int) $deal['id'];
                            $rowState = marketing_state($deal['start_time'], $deal['end_time'], (string) $deal['status']);
                            $products = (int) $deal['product_count'];
                            $discount = $deal['discount_type'] === 'fixed'
                                ? money((float) $deal['discount_value']) . ' off'
                                : rtrim(rtrim(number_format((float) $deal['discount_value'], 2), '0'), '.') . '% off';
                            ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name" style="display:block">
                                        <?php if ($canEdit): ?>
                                            <a href="<?= e(admin_url('deals/edit.php?id=' . $rowId)) ?>"><?= e($deal['title']) ?></a>
                                        <?php else: ?>
                                            <?= e($deal['title']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ad-cellflex__meta">
                                        <?= $deal['subtitle'] !== null && $deal['subtitle'] !== ''
                                            ? e(str_limit((string) $deal['subtitle'], 44))
                                            : '<em>No subtitle</em>' ?>
                                        &middot; <?= $products ?> product<?= $products === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($deal['product_name'] !== null): ?>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($deal['product_image'])) ?>"
                                                 alt="" width="34" height="34" loading="lazy">
                                            <span class="ad-cellflex__name" style="font-size:12.5px">
                                                <?= e(str_limit((string) $deal['product_name'], 34)) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--amber">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= e($discount) ?></strong></td>
                                <td>
                                    <?= marketing_usage_bar(
                                        (int) $deal['stock_sold'],
                                        $deal['stock_limit'] !== null ? (int) $deal['stock_limit'] : null,
                                        'sold'
                                    ) ?>
                                </td>
                                <td class="ad-muted" style="font-size:12px;white-space:nowrap">
                                    <?= e(marketing_window_text($deal['start_time'], $deal['end_time'])) ?>
                                </td>
                                <td><?= marketing_state_badge($rowState) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($deal['product_slug'] !== null): ?>
                                        <a class="ad-btn ad-btn--icon" title="View product on store"
                                           aria-label="View <?= e_attr((string) $deal['product_name']) ?> on store"
                                           href="<?= e(product_url((string) $deal['product_slug'])) ?>"
                                           target="_blank" rel="noopener">
                                            <?= icon('external', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($deal['title']) ?>"
                                           href="<?= e(admin_url('deals/edit.php?id=' . $rowId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('deals/delete.php'),
                                            $rowId,
                                            'Delete "' . $deal['title'] . '"? '
                                                . ($products > 0 ? $products . ' attached product(s) will lose this price. ' : '')
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
            <?= admin_pagination($pagination, admin_url('deals/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
