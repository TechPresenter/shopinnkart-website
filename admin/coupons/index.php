<?php
/**
 * ShopInnKart Admin - Coupons list.
 *
 * The "state" column is computed, not stored: a coupon is only really live
 * when it is active, inside its window and under its redemption cap. The same
 * three rules drive the filter, so the tab count and the badge always agree.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('coupons.view');

require_once ADMIN_PATH . '/includes/marketing.php';

$search = trim((string) ($_GET['q'] ?? ''));
$type   = admin_filter('type', array_keys(COUPON_TYPES));
$status = admin_filter('status', [STATUS_ACTIVE, STATUS_INACTIVE]);
$state  = admin_filter('state', array_keys(marketing_state_options()));
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'code'    => 'c.`code`',
    'value'   => 'c.`value`',
    'minimum' => 'c.`minimum_order`',
    'used'    => 'c.`used_count`',
    'ends'    => 'c.`end_date`',
    'created' => 'c.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'created');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(c.`code` LIKE :q_code OR c.`description` LIKE :q_desc)';
    $params['q_code'] = $params['q_desc'] = '%' . $search . '%';
}
if ($type !== '') {
    $where[] = 'c.`type` = :type';
    $params['type'] = $type;
}
if ($status !== '') {
    $where[] = 'c.`status` = :status';
    $params['status'] = $status;
}
if ($state !== '') {
    $where[] = '(' . marketing_state_where($state, 'c', 'start_date', 'end_date') . ')';
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `coupons` c WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$coupons = Database::fetchAll(
    "SELECT c.`id`, c.`code`, c.`description`, c.`type`, c.`value`, c.`minimum_order`, c.`maximum_discount`,
            c.`start_date`, c.`end_date`, c.`usage_limit`, c.`per_user_limit`, c.`used_count`, c.`status`,
            c.`created_at`,
            (SELECT COUNT(*) FROM `coupon_restrictions` r WHERE r.`coupon_id` = c.`id`) AS restriction_count,
            (SELECT COALESCE(SUM(u.`discount`), 0) FROM `coupon_usage` u WHERE u.`coupon_id` = c.`id`) AS discount_given
     FROM `coupons` c
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, c.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'       => Database::count('coupons'),
    'live'      => Database::count('coupons', marketing_state_where('live', 'coupons', 'start_date', 'end_date')),
    'scheduled' => Database::count('coupons', marketing_state_where('scheduled', 'coupons', 'start_date', 'end_date')),
    'expired'   => Database::count('coupons', marketing_state_where('expired', 'coupons', 'start_date', 'end_date')),
];

$redeemed = (int) Database::fetchColumn('SELECT COUNT(*) FROM `coupon_usage`');
$given    = (float) Database::fetchColumn('SELECT COALESCE(SUM(`discount`), 0) FROM `coupon_usage`');

$canEdit    = admin_can('coupons.edit');
$canDelete  = admin_can('coupons.delete');
$hasFilters = $search !== '' || $type !== '' || $status !== '' || $state !== '';

$pageTitle    = 'Coupons';
$pageSubtitle = $counts['all'] . ' coupons · ' . $counts['live'] . ' live right now';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Coupons'],
];
$pageActions = '';
if (admin_can('coupons.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('coupons/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Coupon</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Live coupons', number_format($counts['live']), 'percent', 'green',
        $counts['scheduled'] . ' scheduled to start') ?>
    <?= admin_stat_card('Expired', number_format($counts['expired']), 'clock', 'amber',
        'Past their end date') ?>
    <?= admin_stat_card('Redemptions', number_format($redeemed), 'tag', 'blue',
        'All coupons, all time') ?>
    <?= admin_stat_card('Discount given', money($given), 'wallet', 'violet',
        'Total taken off orders') ?>
</div>

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

    <form class="ad-filters" method="get" action="<?= e(admin_url('coupons/')) ?>">
        <?php if ($state !== ''): ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="couponSearch">Search coupons</label>
            <input class="sik-input" type="search" id="couponSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by code or description&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="couponTypeFilter">Discount type</label>
        <select class="sik-select" id="couponTypeFilter" name="type" data-auto-submit>
            <?= admin_options(COUPON_TYPES, $type, 'All types') ?>
        </select>

        <label class="sik-sr" for="couponStatusFilter">Status</label>
        <select class="sik-select" id="couponStatusFilter" name="status" data-auto-submit>
            <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $status, 'Any status') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('coupons/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($coupons === []): ?>
            <?= $hasFilters
                ? admin_empty('No coupons match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No coupons yet',
                    'Coupon codes are applied at the cart. Create one to run your first promotion.',
                    admin_can('coupons.create') ? 'Add Coupon' : null,
                    admin_can('coupons.create') ? admin_url('coupons/create.php') : null,
                    'percent'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Code', 'code', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Discount', 'value', $sort, $dir) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Min order', 'minimum', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Usage', 'used', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Window', 'ends', $sort, $dir) ?></th>
                            <th>State</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coupons as $coupon): ?>
                            <?php
                            $couponId   = (int) $coupon['id'];
                            $usageLimit = $coupon['usage_limit'] !== null ? (int) $coupon['usage_limit'] : null;
                            $used       = (int) $coupon['used_count'];
                            $rowState   = marketing_state($coupon['start_date'], $coupon['end_date'], (string) $coupon['status']);

                            // A coupon at its cap is finished even if the dates say otherwise.
                            if ($rowState['key'] === 'live' && $usageLimit !== null && $used >= $usageLimit) {
                                $rowState = ['key' => 'used_up', 'label' => 'Fully redeemed', 'tone' => 'orange'];
                            }

                            $discountText = match ((string) $coupon['type']) {
                                COUPON_TYPE_PERCENTAGE => rtrim(rtrim(number_format((float) $coupon['value'], 2), '0'), '.') . '% off',
                                COUPON_TYPE_FIXED      => money((float) $coupon['value']) . ' off',
                                default                => 'Free shipping',
                            };
                            $restrictionCount = (int) $coupon['restriction_count'];
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name ad-mono" style="display:block;font-size:13px">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('coupons/edit.php?id=' . $couponId)) ?>">
                                                        <?= e($coupon['code']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($coupon['code']) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <?= $coupon['description'] !== null && $coupon['description'] !== ''
                                                    ? e(str_limit((string) $coupon['description'], 46))
                                                    : '<em>No description</em>' ?>
                                            </span>
                                        </span>
                                        <button type="button" class="ad-btn ad-btn--icon"
                                                data-copy="<?= e_attr($coupon['code']) ?>"
                                                title="Copy code" aria-label="Copy <?= e_attr($coupon['code']) ?>">
                                            <?= icon('copy', 'w-4 h-4') ?>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= e($discountText) ?></strong>
                                    <?php if ($coupon['maximum_discount'] !== null && (float) $coupon['maximum_discount'] > 0): ?>
                                        <span class="ad-cellflex__meta" style="display:block">
                                            max <?= e(money((float) $coupon['maximum_discount'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($restrictionCount > 0): ?>
                                        <span class="sik-status sik-status--indigo" style="margin-top:4px">
                                            <?= $restrictionCount ?> restriction<?= $restrictionCount === 1 ? '' : 's' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num">
                                    <?= (float) $coupon['minimum_order'] > 0
                                        ? e(money((float) $coupon['minimum_order']))
                                        : '<span class="ad-muted">&mdash;</span>' ?>
                                </td>
                                <td><?= marketing_usage_bar($used, $usageLimit, 'redeemed') ?></td>
                                <td class="ad-muted" style="font-size:12px;white-space:nowrap">
                                    <?= e(marketing_window_text($coupon['start_date'], $coupon['end_date'])) ?>
                                </td>
                                <td><?= marketing_state_badge($rowState) ?></td>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Redemptions"
                                       aria-label="Redemptions for <?= e_attr($coupon['code']) ?>"
                                       href="<?= e(admin_url('coupons/usage.php?id=' . $couponId)) ?>">
                                        <?= icon('users', 'w-4 h-4') ?>
                                    </a>
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($coupon['code']) ?>"
                                           href="<?= e(admin_url('coupons/edit.php?id=' . $couponId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('coupons/delete.php'),
                                            $couponId,
                                            $used > 0
                                                ? 'Delete "' . $coupon['code'] . '"? Its ' . $used
                                                    . ' redemption record(s) will be removed from the marketing report too.'
                                                : 'Delete "' . $coupon['code'] . '"? This cannot be undone.'
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
            <?= admin_pagination($pagination, admin_url('coupons/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
