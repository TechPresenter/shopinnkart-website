<?php
/**
 * ShopInnKart Admin - Popup and pop-in list.
 *
 * Popups and pop-ins are the same table split by display_mode, so the two
 * tabs share every filter and only swap that one clause. Impressions and
 * conversions are read straight off the row — api/widgets/popup-track.php
 * increments them as visitors see and use each popup.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.view');

require_once __DIR__ . '/_shared.php';

$canEdit   = admin_can('banners.edit');
$canCreate = admin_can('banners.create');
$canDelete = admin_can('banners.delete');

$mode   = admin_filter('mode', array_keys(popup_display_modes()), 'popup');
$search = trim((string) ($_GET['q'] ?? ''));
$type   = admin_filter('type', array_keys(popup_type_options()));
$status = admin_filter('status', ['active', 'inactive']);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is built from this map only, never from the raw query string.
$sortMap = [
    'name'        => 'p.`name`',
    'sort_order'  => 'p.`sort_order`',
    'impressions' => 'p.`impressions`',
    'conversions' => 'p.`conversions`',
    'rate'        => '(p.`conversions` / NULLIF(p.`impressions`, 0))',
    'created'     => 'p.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'sort_order');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

// Everything except the mode clause, so the tab counts can reuse it.
$baseWhere  = ['1'];
$baseParams = [];

if ($search !== '') {
    // Escape the LIKE wildcards so a search for "50%" means what it says, and
    // give every column its own placeholder — with emulated prepares off PDO
    // binds a named marker exactly once.
    $needle = '%' . addcslashes($search, '%_\\') . '%';
    $baseWhere[] = '(p.`name` LIKE :q_name OR p.`title` LIKE :q_title'
        . ' OR p.`subtitle` LIKE :q_sub OR p.`coupon_code` LIKE :q_code)';
    $baseParams['q_name']  = $needle;
    $baseParams['q_title'] = $needle;
    $baseParams['q_sub']   = $needle;
    $baseParams['q_code']  = $needle;
}
if ($type !== '') {
    $baseWhere[] = 'p.`popup_type` = :type';
    $baseParams['type'] = $type;
}
if ($status !== '') {
    $baseWhere[] = 'p.`status` = :status';
    $baseParams['status'] = $status;
}
$baseWhereSql = implode(' AND ', $baseWhere);

$modeCounts = Database::fetchPairs(
    "SELECT p.`display_mode`, COUNT(*) FROM `popups` p WHERE {$baseWhereSql} GROUP BY p.`display_mode`",
    $baseParams
);

$where  = $baseWhereSql . ' AND p.`display_mode` = :mode';
$params = $baseParams + ['mode' => $mode];

$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `popups` p WHERE {$where}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$popups = Database::fetchAll(
    "SELECT p.`id`, p.`name`, p.`display_mode`, p.`popup_type`, p.`title`, p.`image`,
            p.`coupon_code`, p.`trigger_type`, p.`trigger_value`, p.`frequency`,
            p.`display_pages`, p.`device_visibility`, p.`auth_visibility`,
            p.`impressions`, p.`conversions`, p.`start_date`, p.`end_date`,
            p.`sort_order`, p.`status`
     FROM `popups` p
     WHERE {$where}
     ORDER BY {$sortMap[$sort]} {$dirSql}, p.`id` ASC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

// Headline numbers cover the whole table, not just the filtered page — an
// admin comparing popups against pop-ins needs one shared denominator.
$totals = Database::fetch(
    "SELECT COUNT(*) AS rows_total,
            SUM(CASE WHEN `status` = 'active' THEN 1 ELSE 0 END) AS active_total,
            COALESCE(SUM(`impressions`), 0) AS impressions,
            COALESCE(SUM(`conversions`), 0) AS conversions
     FROM `popups`"
) ?? ['rows_total' => 0, 'active_total' => 0, 'impressions' => 0, 'conversions' => 0];

$allImpressions = (int) $totals['impressions'];
$allConversions = (int) $totals['conversions'];
$overallRate    = popup_conversion_rate($allImpressions, $allConversions);

$deviceLabels = popup_device_options();
$authLabels   = popup_auth_options();
$typeLabels   = popup_type_options();
$freqLabels   = popup_frequency_options();

$hasFilters = $search !== '' || $type !== '' || $status !== '';

$pageTitle    = 'Popups & Pop-ins';
$pageSubtitle = (int) $totals['rows_total'] . ' total · ' . (int) $totals['active_total'] . ' enabled · '
    . number_format($allImpressions) . ' impressions';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Popups'],
];

$pageActions = '';
if ($canCreate) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('popups/create.php?mode=' . $mode)) . '">'
        . icon('plus', 'w-4 h-4') . ' New ' . e(popup_display_modes()[$mode]) . '</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Popups', number_format((int) ($modeCounts['popup'] ?? 0)), 'grid', 'primary',
        'Modal dialogs', admin_url('popups/?mode=popup')) ?>
    <?= admin_stat_card('Pop-ins', number_format((int) ($modeCounts['popin'] ?? 0)), 'bell', 'blue',
        'Corner cards', admin_url('popups/?mode=popin')) ?>
    <?= admin_stat_card('Impressions', number_format($allImpressions), 'eye', 'violet',
        'Times shown, all popups') ?>
    <?= admin_stat_card('Conversions', number_format($allConversions), 'trending', 'green',
        $overallRate . '% of impressions') ?>
</div>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (popup_display_modes() as $key => $label): ?>
            <a class="ad-tab <?= $mode === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['mode' => $key, 'page' => null])) ?>">
                <?= e($label) ?>s
                <span class="ad-tab__count"><?= (int) ($modeCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('popups/')) ?>">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="popupSearch">Search popups</label>
            <input class="sik-input" type="search" id="popupSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Search by name, title or coupon&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="popupType">Type filter</label>
        <select class="sik-select" id="popupType" name="type" data-auto-submit>
            <?= admin_options($typeLabels, $type, 'All types') ?>
        </select>

        <label class="sik-sr" for="popupStatus">Status filter</label>
        <select class="sik-select" id="popupStatus" name="status" data-auto-submit>
            <?= admin_options(['active' => 'Enabled', 'inactive' => 'Disabled'], $status, 'Any status') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('popups/?mode=' . $mode)) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($popups === []): ?>
            <?= $hasFilters
                ? admin_empty(
                    'Nothing matches those filters',
                    'Try a different search term, or clear the filters to see every ' . strtolower(popup_display_modes()[$mode]) . '.',
                    null,
                    null,
                    'search'
                )
                : admin_empty(
                    'No ' . strtolower(popup_display_modes()[$mode]) . 's yet',
                    $mode === 'popup'
                        ? 'A popup is a centred modal — good for newsletter signups, coupon reveals and launch announcements.'
                        : 'A pop-in is a small corner card — good for free-shipping nudges, stock alerts and cart reminders.',
                    $canCreate ? 'New ' . popup_display_modes()[$mode] : null,
                    $canCreate ? admin_url('popups/create.php?mode=' . $mode) : null,
                    $mode === 'popup' ? 'grid' : 'bell'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Name', 'name', $sort, $dir) ?></th>
                            <th>Trigger</th>
                            <th>Frequency</th>
                            <th>Pages</th>
                            <th>Visibility</th>
                            <th>Schedule</th>
                            <th class="ad-table__num"><?= admin_sort_header('Impr.', 'impressions', $sort, $dir) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Conv.', 'conversions', $sort, $dir) ?></th>
                            <th class="ad-table__num"><?= admin_sort_header('Rate', 'rate', $sort, $dir) ?></th>
                            <th>Enabled</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($popups as $popup): ?>
                            <?php
                            $popupId     = (int) $popup['id'];
                            $impressions = (int) $popup['impressions'];
                            $conversions = (int) $popup['conversions'];
                            $rate        = popup_conversion_rate($impressions, $conversions);
                            $schedule    = popup_schedule_state($popup['start_date'], $popup['end_date']);
                            $typeKey     = (string) $popup['popup_type'];
                            $editUrl     = admin_url('popups/edit.php?id=' . $popupId);
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url($popup['image'])) ?>"
                                             alt="" width="38" height="38" loading="lazy">
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e($editUrl) ?>"><?= e($popup['name']) ?></a>
                                                <?php else: ?>
                                                    <?= e($popup['name']) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <span class="sik-status sik-status--<?= e(popup_type_tone($typeKey)) ?>"
                                                      style="font-size:10.5px;padding:1px 7px">
                                                    <?= e($typeLabels[$typeKey] ?? $typeKey) ?>
                                                </span>
                                                <?php if (!empty($popup['title'])): ?>
                                                    &middot; <?= e(str_limit((string) $popup['title'], 34)) ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td><?= e(popup_trigger_summary((string) $popup['trigger_type'], (int) $popup['trigger_value'])) ?></td>
                                <td class="ad-muted"><?= e($freqLabels[(string) $popup['frequency']] ?? (string) $popup['frequency']) ?></td>
                                <td class="ad-muted"><?= e(popup_pages_label((string) $popup['display_pages'])) ?></td>
                                <td class="ad-muted">
                                    <?= e($deviceLabels[(string) $popup['device_visibility']] ?? 'All devices') ?><br>
                                    <span style="font-size:11.5px"><?= e($authLabels[(string) $popup['auth_visibility']] ?? 'Everyone') ?></span>
                                </td>
                                <td><span class="sik-status sik-status--<?= e($schedule['tone']) ?>"><?= e($schedule['label']) ?></span></td>
                                <td class="ad-table__num"><?= number_format($impressions) ?></td>
                                <td class="ad-table__num"><?= number_format($conversions) ?></td>
                                <td class="ad-table__num">
                                    <?php if ($impressions === 0): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php else: ?>
                                        <strong><?= e(number_format($rate, 1)) ?>%</strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <label class="ad-switch" title="Enable or disable this <?= e(strtolower(popup_display_modes()[$popup['display_mode']] ?? 'popup')) ?>">
                                            <input type="checkbox"
                                                   data-toggle-endpoint="<?= e(admin_url('popups/toggle.php')) ?>"
                                                   data-id="<?= $popupId ?>" data-field="status"
                                                   <?= $popup['status'] === 'active' ? 'checked' : '' ?>>
                                            <span class="ad-switch__track"></span>
                                            <span class="sik-sr">Enable <?= e($popup['name']) ?></span>
                                        </label>
                                    <?php else: ?>
                                        <?= admin_state_badge((string) $popup['status']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" href="<?= e($editUrl) ?>"
                                           title="Edit" aria-label="Edit <?= e_attr($popup['name']) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                        <?php if ($impressions > 0 || $conversions > 0): ?>
                                            <form method="post" action="<?= e(admin_url('popups/reset-stats.php')) ?>"
                                                  class="ad-inline-form"
                                                  <?= admin_confirm_form_attrs(
                                                      'The impression and conversion numbers for "' . $popup['name'] . '" cannot be recovered.',
                                                      ['title' => 'Reset the counters?', 'label' => 'Reset counters']
                                                  ) ?>>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $popupId ?>">
                                                <button type="submit" class="ad-btn ad-btn--icon"
                                                        title="Reset stats" aria-label="Reset stats for <?= e_attr($popup['name']) ?>">
                                                    <?= icon('refresh', 'w-4 h-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?= admin_delete_form(
                                            admin_url('popups/delete.php'),
                                            $popupId,
                                            'Delete "' . $popup['name'] . '"? Its stats and images go with it. This cannot be undone.'
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
            <?= admin_pagination($pagination, admin_url('popups/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
