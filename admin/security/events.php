<?php
/**
 * ShopInnKart Admin - The security log.
 *
 * Everything the app wrote down because it looked like an attack: refused
 * sign-ins, throttle trips, CSRF failures, permission denials, webhook
 * signature failures, two-step failures, token reuse, installer probes and
 * directory scans. activity_logs answers "who changed what"; this answers
 * "who tried".
 *
 * Three things this screen is careful about:
 *
 *  1. MASKING. The stored context was masked when it was written, and it is
 *     masked again on the way out (security_event_context_display()), because
 *     this page has a CSV export and a row written before the masker existed
 *     must not be the thing that puts a token in a spreadsheet.
 *  2. VOLUME. The table is the busiest one in the security build. Every filter
 *     runs through one builder shared with the export, so the list, the counts
 *     and the CSV can never disagree about what "this week, high only" means.
 *  3. THE CRON. The detectors are meant to run from bin/security-monitor.php.
 *     A store that never added the cron line would otherwise have a log nobody
 *     reads, so opening this page sweeps inline when cron is plainly overdue.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('security.view');

$selfUrl = admin_url('security/events.php');
$canEdit = admin_can('security.edit');

// ---------------------------------------------------------------------------
// Filters, read once and reused by the list, the counts and the export
// ---------------------------------------------------------------------------
$filters = [
    'type'     => trim((string) ($_GET['type'] ?? '')),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'ip'       => trim((string) ($_GET['ip'] ?? '')),
    'account'  => trim((string) ($_GET['account'] ?? '')),
    'from'     => trim((string) ($_GET['from'] ?? '')),
    'to'       => trim((string) ($_GET['to'] ?? '')),
    'q'        => trim((string) ($_GET['q'] ?? '')),
];
$filter = security_events_filter($filters);

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if (is_post()) {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $action = (string) input('action', '');

    if ($action === 'block_ip') {
        // Blocking straight from an event: the address comes from the row the
        // admin clicked, never from a free-text box, so a mistyped octet
        // cannot become a rule against somebody else.
        $result = security_ip_rule_add([
            'cidr'       => (string) input('ip', ''),
            'kind'       => 'block',
            'reason'     => trim((string) input('reason', '')) !== ''
                ? (string) input('reason', '')
                : 'Blocked from the security log: ' . (string) input('event_type', 'a security event'),
            'expires_at' => (string) input('expires_at', ''),
            'admin_id'   => admin_id(),
        ]);

        if (!$result['ok']) {
            flash('error', $result['error']);
        } else {
            log_activity('security.ip_blocked', 'ip_rule', $result['id'],
                'Blocked ' . $result['cidr'] . ' from the security log');
            flash('success', $result['cidr'] . ' is blocked. It gets a plain 403 before any page runs.');
        }
        redirect((string) input('back', $selfUrl));
    }

    if ($action === 'prune') {
        $days    = setting_int('sec_events_retention_days', 90);
        $removed = security_events_prune();
        log_activity('security.events_pruned', 'settings', null,
            'Pruned the security log (' . $removed . ' rows older than ' . $days . ' days)');
        flash($removed > 0 ? 'success' : 'info', $removed > 0
            ? number_format($removed) . ' event(s) older than ' . $days . ' days removed.'
            : 'Nothing was older than ' . $days . ' days.');
        redirect($selfUrl);
    }

    if ($action === 'sweep') {
        $sweep = security_monitor_sweep();
        if ($sweep['skipped'] !== '') {
            flash('info', $sweep['skipped']);
        } else {
            flash(count($sweep['trips']) > 0 ? 'error' : 'success',
                count($sweep['trips']) > 0
                    ? count($sweep['trips']) . ' detector(s) tripped. They are in the list below, type "monitor.".'
                    : 'All ' . $sweep['checked'] . ' detectors ran. Nothing crossed a threshold.');
        }
        redirect($selfUrl);
    }

    flash('error', 'Unknown action.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
// CSV export - same filter, before any output
// ---------------------------------------------------------------------------
if ((string) ($_GET['export'] ?? '') === 'csv') {
    $rows = Database::fetchAll(
        "SELECT * FROM `security_events` WHERE {$filter['sql']} ORDER BY `id` DESC LIMIT 10000",
        $filter['params']
    );

    log_activity('security.events_exported', 'settings', null,
        'Exported ' . count($rows) . ' security events');
    security_event('logs.security_exported', 'medium', ['rows' => count($rows)] + $filters, admin_id(), 'admin');

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="security-events-' . date('Y-m-d-His') . '.csv"');
        header('Cache-Control: private, no-store');
    }

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'when', 'type', 'severity', 'account', 'address', 'path', 'user agent', 'detail']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (int) $row['id'],
            (string) $row['created_at'],
            (string) $row['type'],
            (string) $row['severity'],
            $row['user_id'] === null ? '' : ((string) ($row['user_type'] ?? 'account') . '#' . (int) $row['user_id']),
            (string) ($row['ip_address'] ?? ''),
            (string) ($row['path'] ?? ''),
            (string) ($row['user_agent'] ?? ''),
            // Masked on the way out, exactly as the screen shows it: the
            // export must never be the loose copy.
            json_encode(security_event_context_display($row['context']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// The cron safety net
// ---------------------------------------------------------------------------
$sweptInline = false;
if (security_monitor_overdue()) {
    try {
        security_monitor_sweep();
        $sweptInline = true;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Inline security sweep failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$total      = (int) Database::fetchColumn("SELECT COUNT(*) FROM `security_events` WHERE {$filter['sql']}", $filter['params']);
$pagination = paginate($total, ADMIN_PER_PAGE, max(1, (int) ($_GET['page'] ?? 1)));
$limit      = (int) $pagination['per_page'];
$offset     = (int) $pagination['offset'];

$events = Database::fetchAll(
    "SELECT * FROM `security_events` WHERE {$filter['sql']} ORDER BY `id` DESC LIMIT {$limit} OFFSET {$offset}",
    $filter['params']
);

$types = Database::fetchColumnAll(
    "SELECT DISTINCT `type` FROM `security_events`
      WHERE `created_at` >= (NOW() - INTERVAL 90 DAY) ORDER BY `type` ASC"
);

$day = static fn (string $severitySql): int => (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `security_events`
      WHERE `created_at` >= (NOW() - INTERVAL 24 HOUR) AND {$severitySql}"
);
$seriousToday = $day("`severity` IN ('high','critical')");
$allToday     = $day('1');

$openAlerts = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `security_alerts` WHERE `created_at` >= (NOW() - INTERVAL 24 HOUR)'
);
$blockRules = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `ip_rules` WHERE `kind` = 'block' AND (`expires_at` IS NULL OR `expires_at` > NOW())"
);

$retention = setting_int('sec_events_retention_days', 90);
$lastSweep = (string) setting('sec_monitor_last_run', '');

/** Rules, keyed, so a monitor.* row can show the advice that goes with it. */
$ruleAdvice = [];
foreach (security_monitor_rules() as $rule) {
    $ruleAdvice['monitor.' . $rule['key']] = (string) $rule['advice'];
}

$hasFilter = implode('', $filters) !== '';

$pageTitle    = 'Security Log';
$pageSubtitle = number_format($allToday) . ' event' . ($allToday === 1 ? '' : 's') . ' in the last 24 hours';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security', 'url' => admin_url('security/settings.php')],
    ['label' => 'Log'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Serious events (24h)', number_format($seriousToday), 'alert',
        $seriousToday > 0 ? 'red' : 'green', 'High and critical only') ?>
    <?= admin_stat_card('All events (24h)', number_format($allToday), 'activity', 'blue',
        'Most of these are ordinary noise') ?>
    <?= admin_stat_card('Detector alerts (24h)', number_format($openAlerts), 'shield',
        $openAlerts > 0 ? 'amber' : 'gray', 'A threshold was crossed') ?>
    <?= admin_stat_card('Blocked addresses', number_format($blockRules), 'lock',
        $blockRules > 0 ? 'blue' : 'gray', 'Live block rules', admin_url('security/ip-rules.php')) ?>
</div>

<?php if ($sweptInline): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('clock', 'w-5 h-5') ?>
        <div>
            <strong>The detectors had not run for a while, so they were run just now.</strong>
            They are meant to run from cron, which is far quicker to notice something:
            <code class="ad-mono">*/5 * * * * <?= e(PHP_BINARY) ?> <?= e(ROOT_PATH) ?>/bin/security-monitor.php --quiet</code>
        </div>
    </div>
<?php endif; ?>

<?php if (!$canEdit): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>You have read-only access. Ask a Super Admin for <code>security.edit</code> to block an address from here.</div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <form class="ad-filters" method="get" action="<?= e($selfUrl) ?>" style="flex-wrap:wrap;gap:10px">
        <div class="ad-search">
            <?= icon('search', 'w-4 h-4') ?>
            <label class="sik-sr" for="eventSearch">Search the security log</label>
            <input class="sik-input" type="search" id="eventSearch" name="q"
                   value="<?= e($filters['q']) ?>" placeholder="Path, type or detail&hellip;" autocomplete="off">
        </div>

        <label class="sik-sr" for="eventType">Type</label>
        <select class="sik-select" id="eventType" name="type" style="max-width:220px">
            <option value="">Every type</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= e_attr((string) $type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>>
                    <?= e((string) $type) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="sik-sr" for="eventSeverity">Severity</label>
        <select class="sik-select" id="eventSeverity" name="severity" style="max-width:150px">
            <option value="">Any severity</option>
            <?php foreach (security_event_severity_order() as $severity): ?>
                <option value="<?= e_attr($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>>
                    <?= e(ucfirst($severity)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="sik-sr" for="eventIp">Address</label>
        <input class="sik-input" type="text" id="eventIp" name="ip" style="max-width:170px"
               value="<?= e($filters['ip']) ?>" placeholder="Address" autocomplete="off">

        <label class="sik-sr" for="eventAccount">Account</label>
        <input class="sik-input" type="text" id="eventAccount" name="account" style="max-width:150px"
               value="<?= e($filters['account']) ?>" placeholder="admin#3" autocomplete="off">

        <label class="sik-sr" for="eventFrom">From</label>
        <input class="sik-input" type="date" id="eventFrom" name="from" value="<?= e_attr($filters['from']) ?>">
        <label class="sik-sr" for="eventTo">To</label>
        <input class="sik-input" type="date" id="eventTo" name="to" value="<?= e_attr($filters['to']) ?>">

        <button type="submit" class="ad-btn ad-btn--sm"><?= icon('filter', 'w-4 h-4') ?> Filter</button>
        <?php if ($hasFilter): ?>
            <a class="ad-btn ad-btn--sm" href="<?= e($selfUrl) ?>">Reset</a>
        <?php endif; ?>
        <a class="ad-btn ad-btn--sm" href="<?= e(url_with(['export' => 'csv', 'page' => null], $selfUrl)) ?>">
            <?= icon('download', 'w-4 h-4') ?> Export CSV
        </a>
    </form>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($events === []): ?>
            <?= admin_empty(
                $hasFilter ? 'Nothing matches those filters' : 'Nothing has looked like an attack',
                $hasFilter
                    ? 'Widen the dates or clear the filters.'
                    : 'Refused sign-ins, throttle trips and permission denials all land here as they happen.',
                $hasFilter ? 'Clear filters' : null,
                $hasFilter ? $selfUrl : null,
                'shield'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>What</th>
                            <th>Account</th>
                            <th>Address</th>
                            <th>Detail</th>
                            <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $row): ?>
                            <?php
                            $severity = (string) $row['severity'];
                            $context  = security_event_context_display($row['context']);
                            $ip       = (string) ($row['ip_address'] ?? '');
                            $advice   = $ruleAdvice[(string) $row['type']] ?? '';
                            ?>
                            <tr>
                                <td style="white-space:nowrap">
                                    <?= e(format_datetime($row['created_at'], 'd M, H:i')) ?>
                                    <div class="ad-cellflex__meta"><?= e(time_ago($row['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= e_attr(security_severity_tone($severity)) ?>">
                                        <?= e(ucfirst($severity)) ?>
                                    </span>
                                    <div class="ad-mono" style="font-size:12px;margin-top:4px"><?= e((string) $row['type']) ?></div>
                                </td>
                                <td style="white-space:nowrap">
                                    <?php if ($row['user_id'] === null): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php else: ?>
                                        <a href="<?= e(url_with(['account' => (string) ($row['user_type'] ?? 'account') . '#' . (int) $row['user_id'], 'page' => null], $selfUrl)) ?>">
                                            <?= e((string) ($row['user_type'] ?? 'account')) ?>#<?= (int) $row['user_id'] ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-mono" style="white-space:nowrap">
                                    <?php if ($ip === ''): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php else: ?>
                                        <a href="<?= e(url_with(['ip' => $ip, 'page' => null], $selfUrl)) ?>"><?= e($ip) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:420px">
                                    <details>
                                        <summary style="cursor:pointer">
                                            <?= e(str_limit((string) ($row['path'] ?? '') ?: 'no path', 48)) ?>
                                        </summary>
                                        <div style="display:grid;gap:6px;margin-top:8px;font-size:12px">
                                            <?php if ($advice !== ''): ?>
                                                <div class="sik-alert sik-alert--warning" style="margin:0">
                                                    <div><?= e($advice) ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <div><strong>Path</strong>
                                                <span class="ad-mono"><?= e((string) ($row['path'] ?? '') ?: '—') ?></span></div>
                                            <div><strong>Browser</strong>
                                                <span class="ad-mono"><?= e(str_limit((string) ($row['user_agent'] ?? ''), 160) ?: '—') ?></span></div>
                                            <?php if ($context !== []): ?>
                                                <pre class="ad-mono" style="white-space:pre-wrap;word-break:break-word;font-size:11px;margin:0"><?=
                                                    e((string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                                                ?></pre>
                                            <?php endif; ?>
                                            <div class="ad-muted">
                                                Passwords, tokens and codes are replaced with <code>[masked]</code> when the
                                                event is written and again when it is shown, so nothing here and nothing in
                                                the CSV export carries a credential.
                                            </div>
                                        </div>
                                    </details>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td style="white-space:nowrap">
                                        <?php if ($ip !== '' && $ip !== client_ip()): ?>
                                            <form method="post" style="display:inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="block_ip">
                                                <input type="hidden" name="ip" value="<?= e_attr($ip) ?>">
                                                <input type="hidden" name="event_type" value="<?= e_attr((string) $row['type']) ?>">
                                                <input type="hidden" name="back" value="<?= e_attr(url_with([], $selfUrl)) ?>">
                                                <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                                        <?= admin_confirm_attrs(
                                                            'Every request from it gets a 403 before any page runs.',
                                                            [
                                                                'title' => 'Block ' . $ip . '?',
                                                                'label' => 'Block this address',
                                                                'tone'  => 'danger',
                                                            ]
                                                        ) ?>>
                                                    Block
                                                </button>
                                            </form>
                                        <?php elseif ($ip === client_ip() && $ip !== ''): ?>
                                            <span class="ad-muted" style="font-size:12px">This is you</span>
                                        <?php endif; ?>
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
                of <?= number_format((int) $pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <h2 class="ad-card__title">Housekeeping</h2>
            <div class="ad-card__sub">
                The detectors last swept <?= $lastSweep === '' ? 'never' : e(time_ago($lastSweep)) ?>.
                Events are kept for <?= $retention > 0 ? (int) $retention . ' days' : 'ever (no retention limit set)' ?>.
            </div>
        </div>
    </div>
    <div class="ad-card__body" style="display:grid;gap:12px;font-size:13px">
        <p>
            Thresholds, the retention window and whether a trip emails you all live in
            <a href="<?= e(admin_url('security/settings.php')) ?>#monitor">Security Settings</a>.
            Block and allow lists are on <a href="<?= e(admin_url('security/ip-rules.php')) ?>">IP Rules</a>.
        </p>
        <?php if ($canEdit): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sweep">
                    <button type="submit" class="ad-btn ad-btn--sm"><?= icon('refresh', 'w-4 h-4') ?> Run the detectors now</button>
                </form>
                <?php if ($retention > 0): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="prune">
                        <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                <?= admin_confirm_attrs(
                                    'Every security event older than ' . (int) $retention . ' days is deleted. This cannot be undone.',
                                    [
                                        'title' => 'Prune the security log?',
                                        'label' => 'Prune the log',
                                        'tone'  => 'danger',
                                    ]
                                ) ?>>
                            <?= icon('trash', 'w-4 h-4') ?> Prune older than <?= (int) $retention ?> days
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
