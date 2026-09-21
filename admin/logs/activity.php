<?php
/**
 * ShopInnKart Admin - Activity log.
 *
 * The audit trail written by log_activity() after every admin write. Rows keep
 * admin_name as plain text, so an entry still reads correctly after the
 * account behind it has been deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('logs.view');

require_once __DIR__ . '/_shared.php';

$selfUrl = admin_url('logs/activity.php');

if (is_post() && input('op', '') === 'clear') {
    logs_handle_clear('activity_logs', $selfUrl);
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$search   = trim((string) ($_GET['q'] ?? ''));
$adminId  = (int) ($_GET['admin_id'] ?? 0);
$range    = logs_date_filter();

// The action and entity pickers are built from what the log actually contains,
// so they never offer a value that returns nothing.
$actionList = Database::fetchColumnAll('SELECT DISTINCT `action` FROM `activity_logs` ORDER BY `action`');
$entityList = Database::fetchColumnAll(
    "SELECT DISTINCT `entity` FROM `activity_logs` WHERE `entity` IS NOT NULL AND `entity` <> '' ORDER BY `entity`"
);
$actionOptions = array_combine($actionList, $actionList) ?: [];
$entityOptions = array_combine($entityList, $entityList) ?: [];

$action = admin_filter('action', array_keys($actionOptions));
$entity = admin_filter('entity', array_keys($entityOptions));

$adminOptions = Database::fetchPairs(
    'SELECT `id`, `name` FROM `admins` ORDER BY `name`'
);

$where  = ['1'];
$params = [];

if ($search !== '') {
    $where[] = '(`description` LIKE :q_desc OR `admin_name` LIKE :q_admin OR `ip_address` LIKE :q_ip)';
    $params['q_desc'] = $params['q_admin'] = $params['q_ip'] = '%' . $search . '%';
}
if ($adminId > 0) {
    $where[] = '`admin_id` = :admin_id';
    $params['admin_id'] = $adminId;
}
if ($action !== '') {
    $where[] = '`action` = :action';
    $params['action'] = $action;
}
if ($entity !== '') {
    $where[] = '`entity` = :entity';
    $params['entity'] = $entity;
}
if ($range !== null) {
    $where[] = '`created_at` >= :from AND `created_at` <= :to';
    $params['from'] = $range['from'] . ' 00:00:00';
    $params['to']   = $range['to'] . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `activity_logs` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$entries = Database::fetchAll(
    "SELECT `id`, `admin_id`, `admin_name`, `action`, `entity`, `entity_id`,
            `description`, `ip_address`, `user_agent`, `created_at`
     FROM `activity_logs`
     WHERE {$whereSql}
     ORDER BY `id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$logTotal   = Database::count('activity_logs');
$today      = (int) Database::fetchColumn('SELECT COUNT(*) FROM `activity_logs` WHERE DATE(`created_at`) = CURDATE()');
$hasFilters = $search !== '' || $adminId > 0 || $action !== '' || $entity !== '' || $range !== null;

$pageTitle    = 'Activity Log';
$pageSubtitle = number_format($logTotal) . ' entries · ' . number_format($today) . ' today';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Activity Log'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Who changed what</div>
            <div class="ad-card__sub">
                Showing <?= number_format($total) ?> matching entr<?= $total === 1 ? 'y' : 'ies' ?>,
                newest first.
            </div>
        </div>
    </div>

    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>">
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="activitySearch">Search the activity log</label>
            <input class="sik-input" type="search" id="activitySearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Description, admin or IP&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="activityAdmin">Admin filter</label>
        <select class="sik-select" id="activityAdmin" name="admin_id" data-auto-submit>
            <?= admin_options($adminOptions, $adminId > 0 ? (string) $adminId : '', 'All admins') ?>
        </select>

        <label class="sik-sr" for="activityAction">Action filter</label>
        <select class="sik-select" id="activityAction" name="action" data-auto-submit>
            <?= admin_options($actionOptions, $action, 'All actions') ?>
        </select>

        <label class="sik-sr" for="activityEntity">Entity filter</label>
        <select class="sik-select" id="activityEntity" name="entity" data-auto-submit>
            <?= admin_options($entityOptions, $entity, 'All entities') ?>
        </select>

        <?= logs_range_controls($range) ?>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e($selfUrl) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($entries === []): ?>
            <?= $hasFilters
                ? admin_empty('Nothing matches those filters', 'Widen the date range or clear the filters to see more.', null, null, 'search')
                : admin_empty('The activity log is empty', 'Every admin write lands here — create or edit something and it will show up.', null, null, 'clock') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Description</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td style="white-space:nowrap">
                                    <div><?= e(format_datetime($entry['created_at'], 'd M Y, g:i A')) ?></div>
                                    <div class="ad-cellflex__meta"><?= e(time_ago($entry['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($entry['admin_id']) && admin_can('admins.edit')): ?>
                                        <a href="<?= e(admin_url('admins/edit.php?id=' . (int) $entry['admin_id'])) ?>">
                                            <?= e($entry['admin_name'] ?: 'Admin #' . (int) $entry['admin_id']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= e($entry['admin_name'] ?: 'System') ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="ad-mono"><?= e($entry['action']) ?></span></td>
                                <td>
                                    <?php if (!empty($entry['entity'])): ?>
                                        <span class="sik-status sik-status--gray"><?= e($entry['entity']) ?></span>
                                        <?php if (!empty($entry['entity_id'])): ?>
                                            <span class="ad-cellflex__meta">#<?= (int) $entry['entity_id'] ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e($entry['description'] ?: '—') ?>
                                    <?php if (!empty($entry['user_agent'])): ?>
                                        <div class="ad-cellflex__meta"><?= e(str_limit((string) $entry['user_agent'], 80)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-mono"><?= e($entry['ip_address'] ?: '—') ?></td>
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
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php if (admin_can('logs.delete')): ?>
    <?= logs_clear_card('activity_logs', $selfUrl, $logTotal) ?>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
