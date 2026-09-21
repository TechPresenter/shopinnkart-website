<?php
/**
 * ShopInnKart Admin - Login history.
 *
 * Every attempt is here, successful or not, for admins and customers alike.
 * The failed rows are the point of the screen: repeated failures against one
 * identifier or from one IP are what a break-in attempt looks like.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('logs.view');

require_once __DIR__ . '/_shared.php';

$selfUrl = admin_url('logs/login-history.php');

if (is_post() && input('op', '') === 'clear') {
    logs_handle_clear('login_history', $selfUrl);
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
const LOGIN_USER_TYPES = ['admin' => 'Admin', 'customer' => 'Customer'];
const LOGIN_STATUSES   = ['success' => 'Successful', 'failed' => 'Failed'];

$search   = trim((string) ($_GET['q'] ?? ''));
$userType = admin_filter('user_type', array_keys(LOGIN_USER_TYPES));
$status   = admin_filter('status', array_keys(LOGIN_STATUSES));
$range    = logs_date_filter();

$where  = ['1'];
$params = [];

if ($search !== '') {
    $where[] = '(`identifier` LIKE :q_id OR `ip_address` LIKE :q_ip OR `reason` LIKE :q_reason)';
    $params['q_id'] = $params['q_ip'] = $params['q_reason'] = '%' . $search . '%';
}
if ($userType !== '') {
    $where[] = '`user_type` = :user_type';
    $params['user_type'] = $userType;
}
if ($status !== '') {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
if ($range !== null) {
    $where[] = '`created_at` >= :from AND `created_at` <= :to';
    $params['from'] = $range['from'] . ' 00:00:00';
    $params['to']   = $range['to'] . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `login_history` WHERE {$whereSql}", $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$entries = Database::fetchAll(
    "SELECT `id`, `user_type`, `user_id`, `identifier`, `status`, `reason`,
            `ip_address`, `user_agent`, `created_at`
     FROM `login_history`
     WHERE {$whereSql}
     ORDER BY `id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$logTotal     = Database::count('login_history');
$failedTotal  = Database::count('login_history', "`status` = 'failed'");
$failed24h    = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `login_history` WHERE `status` = 'failed' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
);
$failedAdmin24h = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `login_history`
     WHERE `status` = 'failed' AND `user_type` = 'admin' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
);

// The IPs worth looking at: most failures in the last 7 days.
$topFailingIps = Database::fetchAll(
    "SELECT `ip_address`, COUNT(*) AS attempts
     FROM `login_history`
     WHERE `status` = 'failed' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)
       AND `ip_address` IS NOT NULL AND `ip_address` <> ''
     GROUP BY `ip_address`
     ORDER BY attempts DESC
     LIMIT 5"
);

$canDelete  = admin_can('logs.delete');
$hasFilters = $search !== '' || $userType !== '' || $status !== '' || $range !== null;

$pageTitle    = 'Login History';
$pageSubtitle = number_format($logTotal) . ' attempts · ' . number_format($failedTotal) . ' failed';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Login History'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Attempts logged', number_format($logTotal), 'clock', 'blue', 'Admins and customers') ?>
    <?= admin_stat_card('Failed (24h)', number_format($failed24h), 'alert', $failed24h > 0 ? 'amber' : 'green',
        'Across both account types',
        $failed24h > 0 ? url_with(['status' => 'failed', 'range' => 'today', 'page' => null]) : null) ?>
    <?= admin_stat_card('Failed admin logins (24h)', number_format($failedAdmin24h), 'shield',
        $failedAdmin24h > 0 ? 'red' : 'green',
        $failedAdmin24h > 0 ? 'Worth a look' : 'Nothing unusual',
        $failedAdmin24h > 0 ? url_with(['status' => 'failed', 'user_type' => 'admin', 'range' => 'today', 'page' => null]) : null) ?>
    <?= admin_stat_card('Failed all time', number_format($failedTotal), 'lock', 'navy',
        'Lockout kicks in after ' . MAX_LOGIN_ATTEMPTS . ' tries') ?>
</div>

<?php if ($topFailingIps !== []): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Most failed attempts by IP &mdash; last 7 days</div>
                <div class="ad-card__sub">Click an address to see every attempt it made.</div>
            </div>
        </div>
        <div class="ad-card__body" style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($topFailingIps as $ipRow): ?>
                <a class="ad-btn ad-btn--sm"
                   href="<?= e(url_with(['q' => (string) $ipRow['ip_address'], 'status' => 'failed', 'page' => null])) ?>">
                    <span class="ad-mono"><?= e($ipRow['ip_address']) ?></span>
                    <span class="ad-tab__count"><?= number_format((int) $ipRow['attempts']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All attempts'] + LOGIN_STATUSES as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="loginSearch">Search login history</label>
            <input class="sik-input" type="search" id="loginSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Email, username, IP or reason&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="loginUserType">Account type</label>
        <select class="sik-select" id="loginUserType" name="user_type" data-auto-submit>
            <?= admin_options(LOGIN_USER_TYPES, $userType, 'Admins and customers') ?>
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
                : admin_empty('No sign-in attempts recorded', 'Every attempt against the storefront and this panel is logged here.', null, null, 'lock') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Result</th>
                            <th>Type</th>
                            <th>Identifier</th>
                            <th>Reason</th>
                            <th>IP</th>
                            <th>Device</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $failed  = $entry['status'] === 'failed';
                            $isAdmin = $entry['user_type'] === 'admin';
                            $userId  = (int) ($entry['user_id'] ?? 0);
                            ?>
                            <tr>
                                <td style="white-space:nowrap">
                                    <div><?= e(format_datetime($entry['created_at'], 'd M Y, g:i A')) ?></div>
                                    <div class="ad-cellflex__meta"><?= e(time_ago($entry['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $failed ? 'red' : 'green' ?>">
                                        <?= $failed ? 'Failed' : 'Success' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $isAdmin ? 'violet' : 'gray' ?>">
                                        <?= e(LOGIN_USER_TYPES[$entry['user_type']] ?? (string) $entry['user_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $profileUrl = null;
                                    if ($userId > 0 && $isAdmin && admin_can('admins.edit')) {
                                        $profileUrl = admin_url('admins/edit.php?id=' . $userId);
                                    } elseif ($userId > 0 && !$isAdmin && admin_can('customers.view')) {
                                        $profileUrl = admin_url('customers/view.php?id=' . $userId);
                                    }
                                    ?>
                                    <?php if ($profileUrl !== null): ?>
                                        <a href="<?= e($profileUrl) ?>"><?= e($entry['identifier']) ?></a>
                                    <?php else: ?>
                                        <?= e($entry['identifier']) ?>
                                    <?php endif; ?>
                                    <?php if ($userId === 0): ?>
                                        <div class="ad-cellflex__meta">No matching account</div>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-mono"><?= e($entry['reason'] ?: '—') ?></td>
                                <td class="ad-mono"><?= e($entry['ip_address'] ?: '—') ?></td>
                                <td class="ad-cellflex__meta" style="max-width:260px">
                                    <?= e(str_limit((string) ($entry['user_agent'] ?? ''), 70) ?: '—') ?>
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
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canDelete): ?>
    <?= logs_clear_card('login_history', $selfUrl, $logTotal) ?>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
