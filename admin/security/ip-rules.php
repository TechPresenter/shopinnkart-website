<?php
/**
 * ShopInnKart Admin - Block and allow lists.
 *
 * The bluntest control in the building, so it is also the most carefully
 * fenced:
 *
 *  - ALLOW WINS. The office address stays reachable even if a later, clumsier
 *    /16 block covers it. A block that silently outranks an allow is how a
 *    shop locks out its own warehouse at 2am with nobody able to say why.
 *  - YOU CANNOT BLOCK YOURSELF. Undoing a rule needs this screen, and this
 *    screen needs the address the rule just refused. The check is on the RANGE,
 *    not the typed address, so "203.0.113.0/24" is caught as well as the exact
 *    address.
 *  - EVERY RULE CARRIES A REASON, and may carry an expiry. A rule nobody can
 *    explain in six months is a rule nobody dares delete, and a permanent
 *    block on a mobile address is a customer the shop lost for good when the
 *    address moved on to somebody else.
 *
 * Applied from security_headers_send() - see security_monitor_guard() - so a
 * blocked request never reaches a page, a query or a session. Matching reads a
 * small mirrored file rather than the database, and a store with no rules at
 * all pays one array lookup per request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('security.view');

$selfUrl = admin_url('security/ip-rules.php');
$canEdit = admin_can('security.edit');

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if (is_post()) {
    admin_require_action('security.edit');

    $action = (string) input('action', '');

    if ($action === 'add') {
        $result = security_ip_rule_add([
            'cidr'       => (string) input('cidr', ''),
            'kind'       => (string) input('kind', 'block'),
            'reason'     => (string) input('reason', ''),
            'expires_at' => (string) input('expires_at', ''),
            'admin_id'   => admin_id(),
        ]);

        if (!$result['ok']) {
            flash_errors(['cidr' => $result['error']]);
            flash_old($_POST);
            flash('error', 'The rule was not added.');
        } else {
            log_activity('security.ip_rule_added', 'ip_rule', $result['id'],
                ucfirst((string) input('kind', 'block')) . ' rule added for ' . $result['cidr']);
            flash('success', $result['cidr'] . ' added to the '
                . ((string) input('kind', 'block') === 'allow' ? 'allow' : 'block') . ' list.');
        }
        redirect($selfUrl);
    }

    if ($action === 'delete') {
        $row = security_ip_rule_delete((int) input('id', 0), admin_id());
        if ($row === null) {
            flash('error', 'That rule no longer exists.');
        } else {
            log_activity('security.ip_rule_removed', 'ip_rule', (int) $row['id'],
                'Removed the ' . $row['kind'] . ' rule for ' . $row['cidr']);
            flash('success', $row['cidr'] . ' is no longer on the ' . $row['kind'] . ' list.');
        }
        redirect($selfUrl);
    }

    if ($action === 'resync') {
        // The escape hatch for a cache file that went stale (a restored
        // database, a copied storage folder). Rebuilds it from the table.
        $synced = security_ip_rules_sync();
        flash('success', count($synced['rules']) . ' live rule(s) reloaded from the database.');
        redirect($selfUrl);
    }

    flash('error', 'Unknown action.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$rules = Database::fetchAll(
    'SELECT r.*, a.`name` AS `admin_name`
       FROM `ip_rules` r
       LEFT JOIN `admins` a ON a.`id` = r.`created_by`
      ORDER BY r.`kind` = \'allow\' DESC, r.`id` DESC'
);

$live    = 0;
$expired = 0;
foreach ($rules as $row) {
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
        $expired++;
    } else {
        $live++;
    }
}

$allowCount = 0;
$blockCount = 0;
foreach ($rules as $row) {
    if ((string) $row['kind'] === 'allow') {
        $allowCount++;
    } else {
        $blockCount++;
    }
}

$myIp     = client_ip();
$myRule   = security_ip_rule_match($myIp);
$errors   = errors_pull();
$cached   = security_ip_rules();

// The addresses the log says are worth looking at, so the owner does not have
// to copy one out of another screen by hand.
$suggestions = Database::fetchAll(
    "SELECT `ip_address`, COUNT(*) AS `c`, MAX(`created_at`) AS `seen`
       FROM `security_events`
      WHERE `created_at` >= (NOW() - INTERVAL 7 DAY)
        AND `severity` IN ('high','critical')
        AND `ip_address` IS NOT NULL AND `ip_address` <> ''
      GROUP BY `ip_address`
      ORDER BY `c` DESC
      LIMIT 8"
);

$pageTitle    = 'IP Rules';
$pageSubtitle = $blockCount . ' block, ' . $allowCount . ' allow';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security', 'url' => admin_url('security/settings.php')],
    ['label' => 'IP Rules'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="sik-alert sik-alert--info">
    <?= icon('info', 'w-5 h-5') ?>
    <div>
        You are connecting from <code class="ad-mono"><?= e($myIp !== '' ? $myIp : 'an unknown address') ?></code>.
        <?php if ($myRule !== null && $myRule['kind'] === 'allow'): ?>
            It is on the allow list (<code><?= e((string) $myRule['cidr']) ?></code>), so no block rule can ever shut you out.
        <?php else: ?>
            Nothing here can block it - a rule that covers your own address is refused when you try to save it.
        <?php endif; ?>
        <?php if (!$canEdit): ?>
            You have read-only access; ask a Super Admin for <code>security.edit</code> to change these lists.
        <?php endif; ?>
    </div>
</div>

<?php if ($cached['rules'] === [] && $live > 0): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>The rules are in the table but not in the cache the site reads.</strong>
            That usually means <code>storage/cache</code> is not writable. Nothing is being blocked right now.
            <?php if ($canEdit): ?>Use <em>Reload from the database</em> below.<?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Rules</h2>
                    <div class="ad-card__sub">
                        <?= (int) $live ?> live<?= $expired > 0 ? ', ' . (int) $expired . ' expired and no longer applied' : '' ?>.
                        Allow rules are checked first.
                    </div>
                </div>
            </div>

            <div class="ad-card__body ad-card__body--flush">
                <?php if ($rules === []): ?>
                    <?= admin_empty('No rules yet',
                        'Nothing is blocked and nothing is exempt. Add a block from here or straight from a row in the security log.',
                        'Open the security log', admin_url('security/events.php'), 'lock') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Address / range</th>
                                    <th>List</th>
                                    <th>Why</th>
                                    <th>Expires</th>
                                    <th>Used</th>
                                    <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $row): ?>
                                    <?php
                                    $isExpired = $row['expires_at'] !== null
                                        && strtotime((string) $row['expires_at']) <= time();
                                    ?>
                                    <tr>
                                        <td class="ad-mono" style="white-space:nowrap"><?= e((string) $row['cidr']) ?>
                                            <div class="ad-cellflex__meta">IPv<?= (int) $row['family'] ?></div>
                                        </td>
                                        <td>
                                            <?php if ($isExpired): ?>
                                                <span class="sik-status sik-status--gray">Expired</span>
                                            <?php elseif ((string) $row['kind'] === 'allow'): ?>
                                                <span class="sik-status sik-status--green">Allow</span>
                                            <?php else: ?>
                                                <span class="sik-status sik-status--red">Block</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width:300px">
                                            <?= e((string) $row['reason']) ?>
                                            <div class="ad-cellflex__meta">
                                                <?= e((string) ($row['admin_name'] ?? 'system')) ?>
                                                · <?= e(time_ago($row['created_at'])) ?>
                                            </div>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <?= $row['expires_at'] === null
                                                ? '<span class="ad-muted">Never</span>'
                                                : e(format_datetime($row['expires_at'], 'd M Y, H:i')) ?>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <?= number_format((int) $row['hits']) ?>
                                            <?php if ($row['last_hit_at'] !== null): ?>
                                                <div class="ad-cellflex__meta"><?= e(time_ago($row['last_hit_at'])) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canEdit): ?>
                                            <td>
                                                <form method="post" style="display:inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <button type="submit" class="ad-btn ad-btn--sm"
                                                            <?= admin_confirm_attrs(
                                                                'Traffic from that range stops being treated specially.',
                                                                [
                                                                    'title' => 'Remove the rule for ' . (string) $row['cidr'] . '?',
                                                                    'label' => 'Remove rule',
                                                                    'tone'  => 'danger',
                                                                ]
                                                            ) ?>>
                                                        Remove
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

            <?php if ($canEdit): ?>
                <div class="ad-card__foot">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resync">
                        <button type="submit" class="ad-btn ad-btn--sm">
                            <?= icon('refresh', 'w-4 h-4') ?> Reload from the database
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">What this does and does not do</h2>
                </div>
            </div>
            <div class="ad-card__body" style="font-size:13px;display:grid;gap:8px">
                <p>A blocked address gets a plain <code>403</code> before any page, query or session runs.
                   That is cheap, but it is still PHP answering - it does <strong>not</strong> save bandwidth and it
                   will not survive a real flood. Anything at that scale belongs at the firewall or in front of the
                   site (Cloudflare), not here.</p>
                <p>Addresses are read through <code>client_ip()</code>, so behind a CDN the rule applies to the
                   visitor and not to the edge - but only when the proxy is listed in
                   <a href="<?= e(admin_url('security/settings.php')) ?>#trusted-proxies">Trusted proxies</a>.
                   Without that, every visitor looks like the CDN and a block would take the whole shop down.</p>
                <p>Mobile and home addresses move between people. Prefer an expiry over a permanent block for
                   anything that is not a server.</p>
            </div>
        </div>
    </div>

    <div style="display:grid;gap:18px;align-content:start">
        <?php if ($canEdit): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div><h2 class="ad-card__title">Add a rule</h2></div>
                </div>
                <div class="ad-card__body">
                    <form method="post" class="ad-form" style="display:grid;gap:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">

                        <div class="ad-field">
                            <label class="sik-label" for="cidr">Address or range</label>
                            <input class="sik-input<?= isset($errors['cidr']) ? ' is-invalid' : '' ?>"
                                   type="text" id="cidr" name="cidr" required
                                   value="<?= e_attr((string) old('cidr', '')) ?>"
                                   placeholder="203.0.113.4 or 203.0.113.0/24">
                            <?php if (isset($errors['cidr'])): ?>
                                <span class="sik-error"><?= e($errors['cidr']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">IPv4 or IPv6. A single address, or a range in CIDR form.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="kind">List</label>
                            <select class="sik-select" id="kind" name="kind">
                                <option value="block">Block - refuse every request</option>
                                <option value="allow">Allow - never refuse, whatever else says</option>
                            </select>
                            <span class="sik-help">
                                Put your office and warehouse on the allow list first. Then a mistake in a block
                                rule cannot reach you.
                            </span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="reason">Why</label>
                            <input class="sik-input" type="text" id="reason" name="reason" required maxlength="190"
                                   value="<?= e_attr((string) old('reason', '')) ?>"
                                   placeholder="Password spraying, 12 Sept">
                            <span class="sik-help">Required. In six months this is the only thing that will explain the rule.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="expires_at">Expires (optional)</label>
                            <input class="sik-input" type="datetime-local" id="expires_at" name="expires_at"
                                   value="<?= e_attr((string) old('expires_at', '')) ?>">
                            <span class="sik-help">Leave empty for a permanent rule.</span>
                        </div>

                        <button type="submit" class="ad-btn ad-btn--primary"><?= icon('lock', 'w-4 h-4') ?> Add rule</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($suggestions !== []): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <h2 class="ad-card__title">Worth a look</h2>
                        <div class="ad-card__sub">Addresses behind high or critical events in the last 7 days.</div>
                    </div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:10px;font-size:13px">
                    <?php foreach ($suggestions as $row): ?>
                        <?php $ip = (string) $row['ip_address']; ?>
                        <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                            <span>
                                <a class="ad-mono" href="<?= e(admin_url('security/events.php?ip=' . urlencode($ip))) ?>"><?= e($ip) ?></a>
                                <span class="ad-muted">· <?= (int) $row['c'] ?> event(s), last <?= e(time_ago($row['seen'])) ?></span>
                            </span>
                            <?php if ($canEdit && $ip !== $myIp): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="kind" value="block">
                                    <input type="hidden" name="cidr" value="<?= e_attr($ip) ?>">
                                    <input type="hidden" name="reason" value="High-severity events in the security log">
                                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                            <?= admin_confirm_attrs(
                                                'Every request from it gets a 403 before any page runs.',
                                                [
                                                    'title' => 'Block ' . $ip . '?',
                                                    'label' => 'Block this address',
                                                    'tone'  => 'danger',
                                                ]
                                            ) ?>>Block</button>
                                </form>
                            <?php elseif ($ip === $myIp): ?>
                                <span class="ad-muted">This is you</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php old_clear(); ?>
<?php require ADMIN_PATH . '/includes/footer.php'; ?>
