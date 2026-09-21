<?php
/**
 * ShopInnKart Admin - Combos list.
 *
 * "Needs attention" is why this screen exists. A combo that is switched on but
 * not combo_is_sellable() - fewer than two live components, no sets in stock,
 * or priced at or above what the items cost separately - is skipped by
 * combo_list(), so the storefront quietly stops showing it and nothing else in
 * the admin ever says so.
 *
 * That set has no WHERE clause: sellability depends on live component stock and
 * on pricing that is computed rather than stored (combo-functions.php:176-185).
 * So it is built once, before the query, and then read three times - by the
 * warning banner, by the tab count, and, when the filter is on, as a
 * `c`.`id` IN (...) predicate. One source, three readers, which is why the
 * banner, the tab and the rows can never disagree. Post-filtering the
 * paginated page in PHP instead would make LIMIT/OFFSET lie: three rows on
 * page 1, eleven on page 2.
 *
 * Permissions are coupons.* on purpose. PERMISSION_MODULES has no `combos`
 * key (config/constants.php:150-172) and admin/admins/roles.php builds its
 * checkboxes straight from that list, so a combos.view permission could never
 * be granted to any role - the sidebar entry uses coupons.view too
 * (admin/includes/functions.php:59).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('coupons.view');

require_once ADMIN_PATH . '/includes/marketing.php';

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', [STATUS_ACTIVE, STATUS_INACTIVE, STATUS_DRAFT]);

// The schedule states come from the shared list minus "inactive":
// marketing_state_where('inactive', ...) matches `status` = 'inactive' only,
// and the combos enum has three values, so that tab would silently drop every
// draft. The status select below knows all three.
$stateOptions = marketing_state_options();
unset($stateOptions['inactive']);
// "attention" is this screen's own state rather than a schedule one; see the
// header note for why it cannot be a marketing_state_where() fragment.
$stateOptions['attention'] = 'Needs attention';

$state = admin_filter('state', array_keys($stateOptions));
$page  = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'    => 'c.`name`',
    'price'   => 'c.`price`',
    'ends'    => 'c.`end_date`',
    'created' => 'c.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'created');
$dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$dirSql = admin_safe_dir($dir);

/*
 * The one pass that decides "needs attention", over EVERY active combo rather
 * than the current page - an operator on page 2 has to see the same number as
 * one on page 1. It costs one combo_items() query per active combo; that is
 * fine at this catalogue's size, and anything that grows it should cache a
 * sellability flag from combo_recalculate() rather than widen this loop.
 */
$attentionIds = [];
foreach (Database::fetchAll("SELECT c.* FROM `combos` c WHERE c.`status` = 'active'") as $activeCombo) {
    if (!combo_is_sellable($activeCombo, combo_items((int) $activeCombo['id']))) {
        $attentionIds[] = (int) $activeCombo['id'];
    }
}

$where  = ['1'];
$params = [];

if ($search !== '') {
    // Each occurrence needs its own placeholder: with emulated prepares off,
    // PDO binds a named marker exactly once.
    $where[] = '(c.`name` LIKE :q_name OR c.`subtitle` LIKE :q_sub)';
    $params['q_name'] = $params['q_sub'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'c.`status` = :status';
    $params['status'] = $status;
}
if ($state === 'attention') {
    // Fed back into SQL rather than filtered out afterwards, so COUNT(*),
    // LIMIT and OFFSET all describe the same set of rows.
    if ($attentionIds === []) {
        $where[] = '0';
    } else {
        [$attentionSql, $attentionParams] = Database::inPlaceholders($attentionIds, 'att');
        $where[] = 'c.`id` IN (' . $attentionSql . ')';
        $params += $attentionParams;
    }
} elseif ($state !== '') {
    $where[] = '(' . marketing_state_where($state, 'c', 'start_date', 'end_date') . ')';
}
$whereSql = implode(' AND ', $where);

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `combos` c WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

// c.* rather than a column list: combo_decorate() reprices the row, so it needs
// every pricing, stock and badge column present on it.
$rows = Database::fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM `cart_items` ci WHERE ci.`combo_id` = c.`id`) AS cart_lines
     FROM `combos` c
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, c.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$combos = [];
foreach ($rows as $row) {
    // combo_items() is one SELECT per combo, and combo_decorate() would run it
    // again for the pricing and a third time for the availability if it were
    // not handed the result. Fetch once, thread it through.
    $combos[] = combo_decorate($row, combo_items((int) $row['id']));
}

$counts = [
    'all'       => Database::count('combos'),
    'live'      => Database::count('combos', marketing_state_where('live', 'combos', 'start_date', 'end_date')),
    'scheduled' => Database::count('combos', marketing_state_where('scheduled', 'combos', 'start_date', 'end_date')),
    'expired'   => Database::count('combos', marketing_state_where('expired', 'combos', 'start_date', 'end_date')),
    'attention' => count($attentionIds),
];

$drafts = Database::count('combos', '`status` = :status', ['status' => STATUS_DRAFT]);

$canEdit    = admin_can('coupons.edit');
$canDelete  = admin_can('coupons.delete');
$hasFilters = $search !== '' || $status !== '' || $state !== '';

$pageTitle    = 'Combo Offers';
$pageSubtitle = $counts['all'] . ' combos · ' . $counts['live'] . ' inside their window';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Combos'],
];
$pageActions = '';
if (admin_can('coupons.create')) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('combos/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Combo</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($counts['attention'] > 0): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong><?= (int) $counts['attention'] ?> active combo<?= $counts['attention'] === 1 ? ' is' : 's are' ?> not sellable.</strong>
            The storefront skips them, so each one is switched on and invisible at the same time.
            The Needs attention tab lists them with the reason.
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Live combos', number_format($counts['live']), 'gift', 'green',
        $counts['scheduled'] . ' scheduled to start') ?>
    <?= admin_stat_card('Needs attention', number_format($counts['attention']), 'alert', 'red',
        'Switched on but not sellable') ?>
    <?= admin_stat_card('Expired', number_format($counts['expired']), 'clock', 'amber',
        'Past their end date') ?>
    <?= admin_stat_card('Drafts', number_format($drafts), 'edit', 'navy',
        'Never published') ?>
</div>

<div class="ad-card">
    <div class="ad-tabs">
        <?php
        $tabs = [
            ''          => ['All', $counts['all']],
            'live'      => ['Live', $counts['live']],
            'scheduled' => ['Scheduled', $counts['scheduled']],
            'expired'   => ['Expired', $counts['expired']],
            'attention' => ['Needs attention', $counts['attention']],
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

    <form class="ad-filters" method="get" action="<?= e(admin_url('combos/')) ?>">
        <?php if ($state !== ''): ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
        <?php endif; ?>
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="comboSearch">Search combos</label>
            <input class="sik-input" type="search" id="comboSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name or subtitle&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="comboStatusFilter">Status</label>
        <select class="sik-select" id="comboStatusFilter" name="status" data-auto-submit>
            <?= admin_options(
                [STATUS_ACTIVE => 'Active', STATUS_INACTIVE => 'Inactive', STATUS_DRAFT => 'Draft'],
                $status,
                'Any status'
            ) ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('combos/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($combos === []): ?>
            <?= $hasFilters
                ? admin_empty('No combos match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No combos yet',
                    'A combo sells a set of products together for less than the same items cost one at a time.',
                    admin_can('coupons.create') ? 'Add Combo' : null,
                    admin_can('coupons.create') ? admin_url('combos/create.php') : null,
                    'gift'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Combo', 'name', $sort, $dir) ?></th>
                            <th class="ad-table__num">Products</th>
                            <th class="ad-table__num"><?= admin_sort_header('Price', 'price', $sort, $dir) ?></th>
                            <th class="ad-table__num">Available</th>
                            <th><?= admin_sort_header('Schedule', 'ends', $sort, $dir) ?></th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combos as $combo): ?>
                            <?php
                            $comboId   = (int) $combo['id'];
                            $name      = (string) $combo['name'];
                            $itemCount = (int) $combo['item_count'];
                            $unitCount = (int) $combo['unit_count'];
                            $available = (int) $combo['available'];
                            $cartLines = (int) $combo['cart_lines'];
                            $rowState  = marketing_state($combo['start_date'], $combo['end_date'], (string) $combo['status']);
                            $needsWork = in_array($comboId, $attentionIds, true);

                            // $attentionIds is combo_is_sellable()'s verdict and stays the only
                            // thing that decides membership; this only names which of its three
                            // refusals applies, so a row can never read "fine" while the tab
                            // counts it as broken.
                            $reason = '';
                            if ($needsWork) {
                                if ($itemCount < 2) {
                                    $reason = $itemCount === 1
                                        ? 'Only one live product left'
                                        : 'No live products left';
                                } elseif ($available < 1) {
                                    $reason = 'No sets in stock';
                                } else {
                                    $reason = 'Saves nothing vs buying separately';
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($combo['image'])) ?>" alt="" loading="lazy">
                                        <div style="min-width:0">
                                            <div class="ad-cellflex__name">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('combos/edit.php?id=' . $comboId)) ?>">
                                                        <?= e(str_limit($name, 56)) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e(str_limit($name, 56)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ad-cellflex__meta">
                                                <?= $combo['subtitle'] !== null && $combo['subtitle'] !== ''
                                                    ? e(str_limit((string) $combo['subtitle'], 44))
                                                    : '<em>No subtitle</em>' ?>
                                            </div>
                                            <?php if ($combo['badge'] !== null): ?>
                                                <?php // combo_badge() returns storefront tones: .sik-badge--soft exists, .sik-status--soft does not. ?>
                                                <span class="sik-badge sik-badge--<?= e_attr((string) $combo['badge']['tone']) ?>"
                                                      style="margin-top:4px">
                                                    <?= e((string) $combo['badge']['label']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($needsWork): ?>
                                                <span class="sik-status sik-status--red" style="margin-top:4px">
                                                    <?= e($reason) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td class="ad-table__num">
                                    <strong><?= $itemCount ?></strong>
                                    <div class="ad-cellflex__meta">
                                        <?= number_format($unitCount) ?> unit<?= $unitCount === 1 ? '' : 's' ?>
                                    </div>
                                </td>

                                <td class="ad-table__num">
                                    <?php
                                    // The struck number is `regular` - what the components cost TODAY bought
                                    // separately. Striking `mrp` would advertise a saving the shopper could get
                                    // anyway by adding the items one at a time (combo-functions.php:11-17).
                                    ?>
                                    <strong><?= e((string) $combo['price_display']) ?></strong>
                                    <div><span class="sik-price--mrp"><?= e((string) $combo['regular_display']) ?></span></div>
                                    <div class="ad-cellflex__meta">
                                        <?= (float) $combo['pricing']['saving'] > 0
                                            ? 'Saves ' . e((string) $combo['saving_display']) . ' &middot; ' . (int) $combo['percent'] . '%'
                                            : 'No saving' ?>
                                    </div>
                                </td>

                                <td class="ad-table__num">
                                    <?php if ($available < 1): ?>
                                        <span class="sik-status sik-status--red">No sets</span>
                                    <?php elseif (!empty($combo['low_stock'])): ?>
                                        <span class="sik-status sik-status--amber"><?= number_format($available) ?> left</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--green"><?= number_format($available) ?> sets</span>
                                    <?php endif; ?>
                                </td>

                                <td class="ad-muted" style="font-size:12px;white-space:nowrap">
                                    <?= e(marketing_window_text($combo['start_date'], $combo['end_date'])) ?>
                                    <?php
                                    // marketing_state() reports "Inactive" for anything that is not active, which
                                    // would print Inactive beside a Draft pill. The window badge only means
                                    // something while the combo is switched on.
                                    ?>
                                    <?php if ((string) $combo['status'] === STATUS_ACTIVE): ?>
                                        <div style="margin-top:4px"><?= marketing_state_badge($rowState) ?></div>
                                    <?php endif; ?>
                                </td>

                                <?php // admin_state_badge() knows 'draft'; marketing_state_badge() folds it into Inactive. ?>
                                <td><?= admin_state_badge((string) $combo['status']) ?></td>

                                <td class="ad-table__actions">
                                    <?php
                                    // combo.php refuses anything combo_find() will not return, and then anything
                                    // that is not combo_is_sellable() - the same two verdicts this row already
                                    // holds - so the link is only offered when following it lands on a page
                                    // rather than on the 404 a draft or a broken set would give.
                                    ?>
                                    <?php if ($rowState['key'] === 'live' && !$needsWork): ?>
                                        <a class="ad-btn ad-btn--icon" title="View on store"
                                           aria-label="View <?= e_attr($name) ?> on store"
                                           href="<?= e((string) $combo['url']) ?>"
                                           target="_blank" rel="noopener">
                                            <?= icon('external', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($name) ?>"
                                           href="<?= e(admin_url('combos/edit.php?id=' . $comboId)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('combos/delete.php'),
                                            $comboId,
                                            'Delete "' . $name . '"? '
                                                . ($cartLines > 0
                                                    ? $cartLines . ' basket line(s) still tagged to this combo will stop being a set. '
                                                    : '')
                                                . 'Its images are removed from disk too. This cannot be undone.'
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
            <?= admin_pagination($pagination, admin_url('combos/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
