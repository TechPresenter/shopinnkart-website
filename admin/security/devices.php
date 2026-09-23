<?php
/**
 * ShopInnKart Admin - Devices & API tokens.
 *
 * Every credential that is not a browser session in front of somebody right
 * now: the API tokens a mobile app holds, and the "keep me signed in" tokens
 * customers' browsers hold. Both can be taken away from here, one device at a
 * time or a whole account at once.
 *
 * What is deliberately NOT listed: live browser sessions. PHP keeps those in
 * its own session store, which has no account index to read, so this build
 * cannot show "Chrome on a laptop, last seen 10 minutes ago". "Sign out
 * everywhere" is the honest equivalent - it moves the account's auth_version
 * on, and every session and token stamped with the old one stops working on
 * its next request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once ADMIN_PATH . '/includes/rbac.php';   // admin_can_manage_admin()

$admin = admin_require('security.view');

$selfUrl = admin_url('security/devices.php');
$canEdit = admin_can('security.edit');

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if (is_post()) {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $action = (string) input('action', '');
    $id     = (int) input('id', 0);

    if ($action === 'revoke_token' && $id > 0) {
        $row = Database::fetch('SELECT * FROM `api_tokens` WHERE `id` = :id LIMIT 1', ['id' => $id]);
        if ($row === null) {
            flash('error', 'That token no longer exists.');
        } else {
            api_token_revoke($id, 'admin_revoked');
            log_activity('security.token_revoked', 'api_token', $id,
                'Revoked the app token "' . $row['device_name'] . '"');
            flash('success', 'That device has been signed out of the app.');
        }
        redirect($selfUrl);
    }

    if ($action === 'revoke_remember' && $id > 0) {
        $deleted = Database::delete('remember_tokens', '`id` = :id', ['id' => $id]);
        if ($deleted > 0) {
            security_event('auth.remember_revoked', 'info', ['token_id' => $id], admin_id(), 'admin');
            log_activity('security.remember_revoked', 'remember_token', $id, 'Removed a remembered device');
            flash('success', 'That remembered device will have to sign in again.');
        } else {
            flash('error', 'That remembered device no longer exists.');
        }
        redirect($selfUrl);
    }

    if ($action === 'revoke_account') {
        $userType = (string) input('user_type', 'customer') === 'admin' ? 'admin' : 'customer';
        $userId   = (int) input('user_id', 0);

        if ($userId <= 0) {
            flash('error', 'No account was given.');
            redirect($selfUrl);
        }

        // Nobody manages up. Without this, an admin holding security.edit could
        // sign a Super Admin out of everything - not a way INTO anything, but a
        // junior admin who can throw their seniors out of the panel at will is
        // the same rule the rest of the screens already refuse.
        if ($userType === 'admin' && !admin_can_manage_admin($userId)) {
            admin_deny_back('You cannot sign out an admin whose role grants more than your own.',
                $selfUrl, ['target' => $userId]);
        }

        $tokens = api_token_revoke_all($userType, $userId, 'admin_revoked_all');
        $remembered = $userType === 'customer'
            ? Database::delete('remember_tokens', '`user_id` = :u', ['u' => $userId])
            : 0;

        // The part that also ends the browser sessions: the account's session
        // generation moves on, so anything holding the old number is finished.
        auth_bump_version($userType, $userId);

        log_activity('security.signed_out_everywhere', $userType, $userId,
            'Signed the account out of every device (' . $tokens . ' app token(s), ' . $remembered . ' remembered device(s))');
        security_event('auth.admin_signed_out_account', 'high', [
            'target_type' => $userType,
            'target_id'   => $userId,
            'tokens'      => $tokens,
            'remembered'  => $remembered,
        ], admin_id(), 'admin');

        flash('success', 'Signed out everywhere: ' . $tokens . ' app token(s) and ' . $remembered
            . ' remembered device(s) revoked, and every open session on that account ends on its next request.');
        redirect($selfUrl);
    }

    flash('error', 'Unknown action.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// Filters and data
// ---------------------------------------------------------------------------
const DEVICE_VIEWS = ['active' => 'Active', 'revoked' => 'Revoked & expired', 'remembered' => 'Remembered browsers'];

$view   = (string) ($_GET['view'] ?? 'active');
$view   = array_key_exists($view, DEVICE_VIEWS) ? $view : 'active';
$search = trim((string) ($_GET['q'] ?? ''));

$tokens      = [];
$remembered  = [];
$pagination  = paginate(0, ADMIN_PER_PAGE, 1);

if ($view === 'remembered') {
    $where  = ['1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.`email` LIKE :q_email OR u.`first_name` LIKE :q_name OR t.`ip_address` LIKE :q_ip)';
        $params['q_email'] = $params['q_name'] = $params['q_ip'] = '%' . $search . '%';
    }
    $whereSql = implode(' AND ', $where);

    $total      = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `remember_tokens` t LEFT JOIN `users` u ON u.`id` = t.`user_id` WHERE {$whereSql}",
        $params
    );
    $pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));
    $limit      = (int) $pagination['per_page'];
    $offset     = (int) $pagination['offset'];

    $remembered = Database::fetchAll(
        "SELECT t.`id`, t.`user_id`, t.`expires_at`, t.`ip_address`, t.`user_agent`, t.`created_at`,
                u.`email`, u.`first_name`, u.`last_name`
           FROM `remember_tokens` t
           LEFT JOIN `users` u ON u.`id` = t.`user_id`
          WHERE {$whereSql}
          ORDER BY t.`id` DESC
          LIMIT {$limit} OFFSET {$offset}",
        $params
    );
} else {
    $where  = $view === 'active'
        ? ['t.`revoked_at` IS NULL AND t.`expires_at` > NOW()']
        : ['(t.`revoked_at` IS NOT NULL OR t.`expires_at` <= NOW())'];
    $params = [];
    if ($search !== '') {
        $where[] = '(t.`device_name` LIKE :q_device OR t.`last_ip` LIKE :q_ip OR u.`email` LIKE :q_email OR a.`email` LIKE :q_aemail)';
        $params['q_device'] = $params['q_ip'] = $params['q_email'] = $params['q_aemail'] = '%' . $search . '%';
    }
    $whereSql = implode(' AND ', $where);

    $joins = 'LEFT JOIN `users` u ON u.`id` = t.`user_id` AND t.`user_type` = \'customer\'
              LEFT JOIN `admins` a ON a.`id` = t.`user_id` AND t.`user_type` = \'admin\'';

    $total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `api_tokens` t {$joins} WHERE {$whereSql}", $params);
    $pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));
    $limit      = (int) $pagination['per_page'];
    $offset     = (int) $pagination['offset'];

    $tokens = Database::fetchAll(
        "SELECT t.*, u.`email` AS user_email, u.`first_name`, u.`last_name`, a.`email` AS admin_email, a.`name` AS admin_name
           FROM `api_tokens` t {$joins}
          WHERE {$whereSql}
          ORDER BY t.`id` DESC
          LIMIT {$limit} OFFSET {$offset}",
        $params
    );
}

$activeTokens = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `api_tokens` WHERE `revoked_at` IS NULL AND `expires_at` > NOW()'
);
$activeRemembered = (int) Database::fetchColumn('SELECT COUNT(*) FROM `remember_tokens` WHERE `expires_at` > NOW()');
$reuseWeek = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `security_events`
      WHERE `type` = 'api.token_reuse_after_revoke' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);

/** "Aarav Sharma (aarav@example.com)" for whichever account owns the row. */
$accountLabel = static function (array $row): string {
    if ((string) ($row['user_type'] ?? 'customer') === 'admin') {
        $name = (string) ($row['admin_name'] ?? '');
        $mail = (string) ($row['admin_email'] ?? '');
    } else {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $mail = (string) ($row['user_email'] ?? $row['email'] ?? '');
    }
    if ($mail === '') {
        return 'Account #' . (int) $row['user_id'] . ' (deleted)';
    }
    return ($name === '' ? $mail : $name . ' (' . $mail . ')');
};

$pageTitle    = 'Devices & API Tokens';
$pageSubtitle = number_format($activeTokens) . ' app token' . ($activeTokens === 1 ? '' : 's')
    . ' · ' . number_format($activeRemembered) . ' remembered browser' . ($activeRemembered === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security', 'url' => admin_url('security/settings.php')],
    ['label' => 'Devices'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--3" style="margin-bottom:18px">
    <?= admin_stat_card('Active app tokens', number_format($activeTokens), 'phone', $activeTokens > 0 ? 'blue' : 'gray',
        'One per device signed in to the app') ?>
    <?= admin_stat_card('Remembered browsers', number_format($activeRemembered), 'clock',
        $activeRemembered > 0 ? 'blue' : 'gray', '"Keep me signed in" on the storefront') ?>
    <?= admin_stat_card('Revoked tokens still being used (7d)', number_format($reuseWeek), 'alert',
        $reuseWeek > 0 ? 'red' : 'green',
        $reuseWeek > 0 ? 'A copied token, or an app that has not noticed yet' : 'Nothing unusual') ?>
</div>

<?php if (!$canEdit): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>You have read-only access. Ask a Super Admin for <code>security.edit</code> to revoke anything here.</div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach (DEVICE_VIEWS as $key => $label): ?>
            <a class="ad-tab <?= $view === $key ? 'is-active' : '' ?>"
               href="<?= e(url_with(['view' => $key, 'page' => null], $selfUrl)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>">
        <input type="hidden" name="view" value="<?= e_attr($view) ?>">
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="deviceSearch">Search devices</label>
            <input class="sik-input" type="search" id="deviceSearch" name="q" data-filter-search
                   value="<?= e($search) ?>" placeholder="Device, email or IP&hellip;" autocomplete="off">
        </div>
        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($search !== ''): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e(url_with(['view' => $view], $selfUrl)) ?>">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($view === 'remembered'): ?>

            <?php if ($remembered === []): ?>
                <?= admin_empty('No remembered browsers',
                    'Nobody has ticked "keep me signed in", or every token has expired.', null, null, 'clock') ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Signed in from</th>
                                <th>Browser</th>
                                <th>Expires</th>
                                <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($remembered as $row): ?>
                                <tr>
                                    <td><?= e($accountLabel($row + ['user_type' => 'customer'])) ?></td>
                                    <td class="ad-mono"><?= e((string) ($row['ip_address'] ?: '—')) ?></td>
                                    <td class="ad-cellflex__meta" style="max-width:240px">
                                        <?= e(str_limit((string) ($row['user_agent'] ?? ''), 60) ?: '—') ?>
                                    </td>
                                    <td style="white-space:nowrap">
                                        <?= e(format_datetime($row['expires_at'], 'd M Y')) ?>
                                        <div class="ad-cellflex__meta"><?= e(time_ago($row['created_at'])) ?></div>
                                    </td>
                                    <?php if ($canEdit): ?>
                                        <td>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="revoke_remember">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                                        data-confirm="Forget this browser? The customer will have to sign in again.">
                                                    Forget
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($tokens === []): ?>

            <?= $view === 'active'
                ? admin_empty('No app tokens yet',
                    'Tokens appear here the first time a device signs in through /api/auth/token.php.', null, null, 'phone')
                : admin_empty('Nothing revoked or expired', 'Revoked and expired tokens are kept here for 30-90 days.', null, null, 'clock') ?>

        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Account</th>
                            <th>Last used</th>
                            <th>Expires</th>
                            <th>State</th>
                            <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tokens as $row): ?>
                            <?php
                            $isAdmin = (string) $row['user_type'] === 'admin';
                            $revoked = $row['revoked_at'] !== null;
                            $expired = strtotime((string) $row['expires_at']) <= time();
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600"><?= e((string) $row['device_name']) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= e(api_token_platforms()[$row['platform']] ?? (string) $row['platform']) ?>
                                        · issued <?= e(time_ago($row['created_at'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= e($accountLabel($row)) ?>
                                    <?php if ($isAdmin): ?>
                                        <span class="sik-status sik-status--violet">Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <?php if ($row['last_used_at'] === null): ?>
                                        <span class="ad-muted">Never</span>
                                    <?php else: ?>
                                        <?= e(time_ago($row['last_used_at'])) ?>
                                        <div class="ad-cellflex__meta ad-mono"><?= e((string) ($row['last_ip'] ?: '—')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap"><?= e(format_datetime($row['expires_at'], 'd M Y')) ?></td>
                                <td>
                                    <?php if ($revoked): ?>
                                        <span class="sik-status sik-status--red">Revoked</span>
                                        <div class="ad-cellflex__meta"><?= e((string) ($row['revoked_reason'] ?? '')) ?></div>
                                    <?php elseif ($expired): ?>
                                        <span class="sik-status sik-status--gray">Expired</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--green">Active</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td style="white-space:nowrap">
                                        <?php if (!$revoked): ?>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="revoke_token">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                                        data-confirm="Revoke this device's token? The app will be signed out at once.">
                                                    Revoke
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke_account">
                                            <input type="hidden" name="user_type" value="<?= e_attr((string) $row['user_type']) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm"
                                                    data-confirm="Sign this account out of every device - app tokens, remembered browsers and open sessions?">
                                                Sign out everywhere
                                            </button>
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
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">What this screen cannot show</div>
        </div>
    </div>
    <div class="ad-card__body" style="font-size:13px;display:grid;gap:8px">
        <p>Live browser sessions are not listed. PHP keeps them in its own session store, which has no index by
           account, so there is no honest way to draw "Chrome on a laptop, last seen 10 minutes ago" from this
           build. <strong>Sign out everywhere</strong> is the real equivalent: it moves the account's session
           generation on, and every open session and token stamped with the old one is refused from its next
           request.</p>
        <p>Session length itself is set in
           <a href="<?= e(admin_url('security/settings.php')) ?>#sessions">Security Settings</a>.</p>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
