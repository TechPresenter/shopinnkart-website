<?php
/**
 * ShopInnKart Admin - Admin users list.
 *
 * The password column is never selected here. Nothing on this screen, in any
 * branch, can put a hash in front of a browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('admins.view');

require_once __DIR__ . '/_helpers.php';

$search = trim((string) ($_GET['q'] ?? ''));
$status = admin_filter('status', array_keys(ADMIN_ACCOUNT_STATUSES));
$roleId = (int) ($_GET['role'] ?? 0);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// ORDER BY is assembled from this map only.
$sortMap = [
    'name'       => 'a.`name`',
    'username'   => 'a.`username`',
    'email'      => 'a.`email`',
    'role'       => 'r.`name`',
    'status'     => 'a.`status`',
    'last_login' => 'a.`last_login_at`',
    'created'    => 'a.`created_at`',
];
$sort   = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys($sortMap), 'name');
$dir    = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$dirSql = admin_safe_dir($dir);

$where  = ['1'];
$params = [];

if ($search !== '') {
    // With emulated prepares off a named marker binds exactly once, so each
    // occurrence gets its own placeholder.
    $where[] = '(a.`name` LIKE :q_name OR a.`username` LIKE :q_user OR a.`email` LIKE :q_mail OR a.`phone` LIKE :q_phone)';
    $params['q_name'] = $params['q_user'] = $params['q_mail'] = $params['q_phone'] = '%' . $search . '%';
}
if ($status !== '') {
    $where[] = 'a.`status` = :status';
    $params['status'] = $status;
}
if ($roleId > 0) {
    $where[] = 'a.`role_id` = :role';
    $params['role'] = $roleId;
}
$whereSql = implode(' AND ', $where);

$total = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `admins` a INNER JOIN `admin_roles` r ON r.`id` = a.`role_id` WHERE {$whereSql}",
    $params
);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so cast them here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$admins = Database::fetchAll(
    "SELECT a.`id`, a.`name`, a.`username`, a.`email`, a.`phone`, a.`avatar`, a.`status`,
            a.`role_id`, a.`locked_until`, a.`last_login_at`, a.`last_login_ip`, a.`created_at`,
            r.`name` AS role_name, r.`status` AS role_status
     FROM `admins` a
     INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
     WHERE {$whereSql}
     ORDER BY {$sortMap[$sort]} {$dirSql}, a.`name` ASC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = [
    'all'      => Database::count('admins'),
    'active'   => Database::count('admins', "`status` = 'active'"),
    'inactive' => Database::count('admins', "`status` = 'inactive'"),
];

$superRoleIds  = admins_super_role_ids();
$activeSupers  = admins_active_super_ids();
$roleOptions   = admins_role_options();
$currentId     = (int) $admin['id'];
$canEdit       = admin_can('admins.edit');
$canDelete     = admin_can('admins.delete');
$hasFilters    = $search !== '' || $status !== '' || $roleId > 0;

$pageTitle    = 'Admin Users';
$pageSubtitle = $counts['all'] . ' account(s) · ' . count($activeSupers) . ' active Super Admin(s)';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Admin Users'],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('admins/roles.php')) . '">'
    . icon('shield', 'w-4 h-4') . ' Roles</a>';
if (admin_can('admins.create')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('admins/create.php')) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Admin</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if (count($activeSupers) === 1): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('shield', 'w-5 h-5') ?>
        <div>
            Only one Super Admin can sign in right now. That account cannot be deleted, deactivated
            or demoted until a second one exists &mdash; losing it would lock everyone out of this panel.
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
            <a class="ad-tab <?= $status === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?>
                <span class="ad-tab__count"><?= (int) ($counts[$key === '' ? 'all' : $key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e(admin_url('admins/')) ?>">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>

        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="adminSearch">Search admin users</label>
            <input class="sik-input" type="search" id="adminSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Name, username, email or phone&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="adminRoleFilter">Role filter</label>
        <select class="sik-select" id="adminRoleFilter" name="role" data-auto-submit>
            <?= admin_options($roleOptions, $roleId > 0 ? (string) $roleId : '', 'All roles') ?>
        </select>

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilters): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('admins/')) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($admins === []): ?>
            <?= $hasFilters
                ? admin_empty('No admin users match', 'Try a different search term or clear the filters.', null, null, 'search')
                : admin_empty(
                    'No admin users yet',
                    'Every person who signs into this panel needs an account and a role.',
                    admin_can('admins.create') ? 'Add Admin' : null,
                    admin_can('admins.create') ? admin_url('admins/create.php') : null,
                    'users'
                ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th><?= admin_sort_header('Admin', 'name', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Email', 'email', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Role', 'role', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Status', 'status', $sort, $dir) ?></th>
                            <th><?= admin_sort_header('Last login', 'last_login', $sort, $dir) ?></th>
                            <th>Last IP</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $row): ?>
                            <?php
                            $rowId    = (int) $row['id'];
                            $isSelf   = $rowId === $currentId;
                            $isSuper  = in_array((int) $row['role_id'], $superRoleIds, true);
                            $isLast   = count($activeSupers) === 1 && in_array($rowId, $activeSupers, true);
                            $isLocked = !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time();
                            $editUrl  = admin_url('admins/edit.php?id=' . $rowId);
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <?php if (!empty($row['avatar'])): ?>
                                            <img class="ad-thumb" src="<?= e(img_url($row['avatar'])) ?>"
                                                 alt="" width="38" height="38" loading="lazy">
                                        <?php else: ?>
                                            <span class="ad-avatar"><?= e(initials((string) $row['name'])) ?></span>
                                        <?php endif; ?>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e($editUrl) ?>"><?= e($row['name']) ?></a>
                                                <?php else: ?>
                                                    <?= e($row['name']) ?>
                                                <?php endif; ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="sik-status sik-status--blue">You</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta ad-mono">@<?= e($row['username']) ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div><?= e($row['email']) ?></div>
                                    <?php if (!empty($row['phone'])): ?>
                                        <div class="ad-cellflex__meta"><?= e($row['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= $isSuper ? 'violet' : 'gray' ?>">
                                        <?= e($row['role_name']) ?>
                                    </span>
                                    <?php if ($row['role_status'] !== 'active'): ?>
                                        <div class="ad-cellflex__meta">Role is inactive &mdash; cannot sign in</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= admin_state_badge((string) $row['status']) ?>
                                    <?php if ($isLocked): ?>
                                        <div class="ad-cellflex__meta">
                                            Locked until <?= e(format_datetime($row['locked_until'], 'd M, g:i A')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['last_login_at'])): ?>
                                        <div><?= e(format_datetime($row['last_login_at'], 'd M Y, g:i A')) ?></div>
                                        <div class="ad-cellflex__meta"><?= e(time_ago($row['last_login_at'])) ?></div>
                                    <?php else: ?>
                                        <span class="ad-muted">Never signed in</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-mono"><?= $row['last_login_ip'] !== null && $row['last_login_ip'] !== ''
                                    ? e($row['last_login_ip'])
                                    : '<span class="ad-muted">&mdash;</span>' ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($row['name']) ?>" href="<?= e($editUrl) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete && !$isSelf && !$isLast): ?>
                                        <?= admin_delete_form(
                                            admin_url('admins/delete.php'),
                                            $rowId,
                                            'Delete the admin account "' . $row['name'] . '"? Their activity log entries stay, but they lose access immediately.'
                                        ) ?>
                                    <?php elseif ($canDelete): ?>
                                        <span class="ad-muted" style="font-size:11.5px"
                                              title="<?= e_attr($isSelf
                                                  ? 'You cannot delete the account you are signed in with.'
                                                  : 'This is the last Super Admin who can sign in.') ?>">
                                            <?= $isSelf ? 'Your account' : 'Last Super Admin' ?>
                                        </span>
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
            <?= admin_pagination($pagination, admin_url('admins/')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
