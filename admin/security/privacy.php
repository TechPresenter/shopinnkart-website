<?php
/**
 * ShopInnKart Admin - Privacy requests.
 *
 * The queue behind "download my data" and "delete my account". Most requests
 * arrive from the account page and are confirmed by the customer from their
 * own inbox; this screen is for the ones that arrive by email or over the
 * phone, for the deadline, and for the record of what was done and when.
 *
 * It sits behind security.view to read and security.edit to act. That is
 * deliberately narrow: running one of these reveals everything the store
 * holds about a person, or anonymises them for good, and neither is a
 * support-desk action. security.edit is Super-Admin-only (see
 * ADMIN_SUPER_ONLY_PERMISSIONS), so delegating it is the owner's explicit
 * choice rather than a side effect of holding customers.edit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('security.view');

require_once ADMIN_PATH . '/includes/rbac.php';
require_once INCLUDES_PATH . '/privacy.php';

$selfUrl = admin_url('security/privacy.php');
$canEdit = admin_can('security.edit');

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------
$op = (string) input('op', '');

if (is_post() && $op === 'raise') {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $type  = (string) input('type', 'export');
    $who   = trim((string) input('customer', ''));
    $user  = null;

    if ($who !== '') {
        $user = ctype_digit($who)
            ? Database::fetch('SELECT `id` FROM `users` WHERE `id` = :id', ['id' => (int) $who])
            : Database::fetch('SELECT `id` FROM `users` WHERE `email` = :e', ['e' => $who]);
    }

    if ($user === null) {
        flash('error', 'No customer found for "' . e($who) . '". Use their sign-in email address or their id.');
        redirect($selfUrl);
    }

    $result = privacy_request_create([
        'user_id'    => (int) $user['id'],
        'type'       => isset(PRIVACY_TYPES[$type]) ? $type : 'export',
        'by'         => 'admin',
        'admin_id'   => (int) $admin['id'],
        'admin_name' => (string) $admin['name'],
        'reason'     => (string) input('reason', ''),
    ]);
    admin_after_write();

    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? 'Request logged. Use "Run it" when you are ready - nothing has happened to the account yet.'
        : $result['error']);

    redirect($selfUrl);
}

if (is_post() && $op === 'run') {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $request = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => input_int('id')]);
    if ($request === null) {
        flash('error', 'That request no longer exists.');
        redirect($selfUrl);
    }

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('act on a privacy request for someone else'));
        redirect($selfUrl);
    }

    // Deleting an account on someone else's behalf is the one action here
    // with no undo, so it takes a typed word as well as the password.
    if ((string) $request['type'] === 'delete'
        && strtoupper(trim((string) input('confirm_phrase', ''))) !== 'DELETE') {
        flash('error', 'Type DELETE in the confirmation box to anonymise an account. Nothing was changed.');
        redirect($selfUrl);
    }

    $result = privacy_fulfil($request, [
        'actor'      => 'admin',
        'admin_id'   => (int) $admin['id'],
        'admin_name' => (string) $admin['name'],
    ]);
    admin_after_write();

    flash($result['ok'] ? 'success' : 'error', $result['ok']
        ? ((string) $request['type'] === 'delete'
            ? 'The account has been anonymised. The customer was emailed at the address that was on file.'
            : 'The export is built. Download it below - it is deleted automatically after '
              . privacy_bundle_days() . ' days.')
        : $result['error']);

    redirect($selfUrl);
}

if (is_post() && $op === 'reject') {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $reason = trim((string) input('reason', ''));
    if ($reason === '') {
        flash('error', 'Say why the request is being closed - it goes on the record.');
        redirect($selfUrl);
    }

    $done = privacy_reject(input_int('id'), $reason, ['actor' => 'admin']);
    admin_after_write();

    flash($done ? 'success' : 'error', $done
        ? 'Closed. Tell the customer yourself - nothing is emailed for a refusal.'
        : 'That request could not be closed.');

    redirect($selfUrl);
}

if (is_post() && $op === 'download') {
    admin_require_action('security.edit');   // POST + CSRF + permission

    $request = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => input_int('id')]);
    if ($request === null || (string) $request['file_name'] === '') {
        flash('error', 'There is no file on that request.');
        redirect($selfUrl);
    }

    if (!admin_reauth_ok()) {
        flash('error', admin_reauth_error('download a customer\'s data export'));
        redirect($selfUrl);
    }

    try {
        $stream = privacy_bundle_stream($request);
        $first  = $stream->current();
    } catch (Throwable $e) {
        flash('error', 'That file could not be read: ' . $e->getMessage());
        redirect($selfUrl);
    }

    Database::update('privacy_requests', [
        'downloads'     => (int) $request['downloads'] + 1,
        'downloaded_at' => date('Y-m-d H:i:s'),
    ], '`id` = :id', ['id' => (int) $request['id']]);

    log_activity('privacy.downloaded', 'privacy_request', (int) $request['id'],
        'Downloaded the data export for ' . mask_email((string) $request['email']));
    security_event('privacy.export_downloaded', 'critical', [
        'request_id' => (int) $request['id'],
        'by'         => 'admin',
        'email'      => mask_email((string) $request['email']),
    ], (int) $request['user_id'], 'customer');
    admin_after_write();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $name = preg_replace('/\.enc$/', '', (string) $request['file_name']);
    header('Content-Type: ' . (str_ends_with((string) $name, '.zip') ? 'application/zip' : 'application/json'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    echo $first;
    $stream->next();
    while ($stream->valid()) {
        echo $stream->current();
        flush();
        $stream->next();
    }
    exit;
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------
$status = admin_filter('status', ['pending', 'ready', 'completed', 'rejected', 'cancelled', 'expired', 'failed']);
$type   = admin_filter('type', array_keys(PRIVACY_TYPES));

$where  = '1';
$params = [];
if ($status !== '') {
    $where .= ' AND `status` = :st';
    $params['st'] = $status;
}
if ($type !== '') {
    $where .= ' AND `type` = :ty';
    $params['ty'] = $type;
}

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = Database::count('privacy_requests', $where, $params);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$requests = Database::fetchAll(
    "SELECT * FROM `privacy_requests` WHERE {$where} ORDER BY `id` DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

$open    = privacy_open_count();
$overdue = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `privacy_requests`
      WHERE `status` IN ('pending', 'ready') AND `due_at` IS NOT NULL AND `due_at` < NOW()"
);
$done30 = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `privacy_requests`
      WHERE `status` = 'completed' AND `completed_at` > (NOW() - INTERVAL 30 DAY)"
);

$pageTitle    = 'Privacy Requests';
$pageSubtitle = $open > 0
    ? number_format($open) . ' waiting' . ($overdue > 0 ? ', ' . number_format($overdue) . ' past the deadline' : '')
    : 'Nothing waiting.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security',  'url' => admin_url('security/settings.php')],
    ['label' => 'Privacy Requests'],
];
$pageActions  = '<a class="ad-btn" href="' . e(admin_url('security/settings.php#privacy')) . '">'
    . icon('settings', 'w-4 h-4') . ' Settings</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if (!$canEdit): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            You have read-only access. Acting on a request &mdash; building an export or anonymising an
            account &mdash; needs the <code>security.edit</code> permission, which only a Super Admin
            can hand out.
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Open', number_format($open), 'clock', $open > 0 ? 'amber' : 'green',
        'awaiting confirmation or download') ?>
    <?= admin_stat_card('Past the deadline', number_format($overdue), 'alert', $overdue > 0 ? 'red' : 'green',
        PRIVACY_DUE_DAYS . ' days from the ask') ?>
    <?= admin_stat_card('Done in 30 days', number_format($done30), 'check', 'blue', 'exports and deletions') ?>
    <?= admin_stat_card('Self-service', privacy_enabled() ? 'On' : 'Off', 'user',
        privacy_enabled() ? 'green' : 'amber',
        privacy_enabled() ? 'customers can run their own' : 'every request comes through here') ?>
</div>

<div class="sik-alert sik-alert--warning">
    <?= icon('alert', 'w-5 h-5') ?>
    <div>
        <strong>Deletion is anonymisation, and it cannot be undone.</strong>
        The account becomes a tombstone and every trace that points at a person is removed or
        detached. What survives is the money: orders as numbers, and tax invoices exactly as they
        were issued &mdash; including the name and address printed on them. Say that to the customer
        before you run it, not after; the confirmation email says it too.
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Requests</div>
            <div class="ad-card__sub">Newest first.</div>
        </div>
        <form method="get" action="<?= e($selfUrl) ?>" style="display:flex;gap:8px;flex-wrap:wrap">
            <select class="sik-input sik-input--sm" name="status" onchange="this.form.submit()">
                <option value="">Any status</option>
                <?php foreach (['pending', 'ready', 'completed', 'rejected', 'cancelled', 'expired', 'failed'] as $value): ?>
                    <option value="<?= e_attr($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                        <?= e(privacy_status_meta($value)[0]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="sik-input sik-input--sm" name="type" onchange="this.form.submit()">
                <option value="">Both kinds</option>
                <?php foreach (PRIVACY_TYPES as $value => $label): ?>
                    <option value="<?= e_attr($value) ?>" <?= $type === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="ad-btn ad-btn--sm">Filter</button></noscript>
        </form>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($requests === []): ?>
            <?= admin_empty(
                'No requests',
                'Nothing has been asked for yet. When a customer uses Data & Privacy in their account, it lands here.',
                null,
                null,
                'shield'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Asked for</th>
                            <th>Status</th>
                            <th>Due</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $row): ?>
                            <?php
                            $rowId              = (int) $row['id'];
                            [$label, $tone]     = privacy_status_meta((string) $row['status']);
                            $isOpen             = in_array((string) $row['status'], ['pending', 'ready'], true);
                            $late               = $isOpen && $row['due_at'] !== null
                                                  && strtotime((string) $row['due_at']) < time();
                            $summary            = json_decode_safe((string) ($row['summary'] ?? ''), []);
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600"><?= e((string) ($row['name'] ?: 'Customer #' . $row['user_id'])) ?></div>
                                    <div class="ad-cellflex__meta ad-mono" style="word-break:break-all">
                                        <?= e((string) $row['email']) ?>
                                    </div>
                                    <?php if (!empty($row['reason'])): ?>
                                        <div class="ad-cellflex__meta"><?= e((string) $row['reason']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['error'])): ?>
                                        <div class="ad-cellflex__meta" style="color:#DC2626"><?= e((string) $row['error']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <div><?= e(PRIVACY_TYPES[(string) $row['type']] ?? (string) $row['type']) ?></div>
                                    <div class="ad-cellflex__meta">
                                        <?= e(time_ago((string) $row['created_at'])) ?>
                                        &middot; by <?= e((string) $row['requested_by']) ?>
                                        <?php if (!empty($row['admin_name'])): ?>
                                            (<?= e((string) $row['admin_name']) ?>)
                                        <?php endif; ?>
                                    </div>
                                    <?php if ((int) ($summary['file_bytes'] ?? 0) > 0): ?>
                                        <div class="ad-cellflex__meta">
                                            <?= e(format_bytes((int) $summary['file_bytes'])) ?>
                                            <?= e((string) ($summary['format'] ?? '')) ?>
                                            <?php if ((int) $row['downloads'] > 0): ?>
                                                &middot; downloaded <?= (int) $row['downloads'] ?>&times;
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="sik-status sik-status--<?= e_attr($tone) ?>"><?= e($label) ?></span></td>
                                <td style="white-space:nowrap">
                                    <?php if ($row['due_at'] !== null && $isOpen): ?>
                                        <span style="<?= $late ? 'color:#DC2626;font-weight:600' : '' ?>">
                                            <?= e(format_datetime((string) $row['due_at'], 'd M Y')) ?>
                                        </span>
                                    <?php elseif ($row['completed_at'] !== null): ?>
                                        <span class="ad-muted"><?= e(format_datetime((string) $row['completed_at'], 'd M Y')) ?></span>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit && $isOpen): ?>
                                        <?php
                                        /* Two very different actions behind one button.
                                           Anonymising is irreversible and is the brief's
                                           named "deleting a customer" case, so it asks for
                                           the address to be typed; building an export only
                                           makes a file and needs no such ceremony. */
                                        $isErase = (string) $row['type'] === 'delete';
                                        $privacyConfirm = $isErase
                                            ? admin_confirm_form_attrs(
                                                'Their personal data is overwritten in place. This cannot be undone.',
                                                [
                                                    'title'   => 'Anonymise ' . $row['email'] . '?',
                                                    'label'   => 'Anonymise',
                                                    'require' => (string) $row['email'],
                                                ]
                                            )
                                            : admin_confirm_form_attrs(
                                                'A copy of everything held about ' . $row['email'] . ' is written to a file you can send them.',
                                                [
                                                    'title' => 'Build the data export?',
                                                    'label' => 'Build export',
                                                    'tone'  => 'warning',
                                                ]
                                            );
                                        ?>
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                              style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"
                                              <?= $privacyConfirm ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="run">
                                            <input type="hidden" name="id" value="<?= $rowId ?>">
                                            <?php if ((string) $row['type'] === 'delete'): ?>
                                                <label class="sik-sr" for="ph<?= $rowId ?>">Type DELETE</label>
                                                <input class="sik-input sik-input--sm" type="text" id="ph<?= $rowId ?>"
                                                       name="confirm_phrase" placeholder="DELETE" autocomplete="off"
                                                       style="max-width:96px" required>
                                            <?php endif; ?>
                                            <label class="sik-sr" for="pw<?= $rowId ?>">Your password</label>
                                            <input class="sik-input sik-input--sm" type="password" id="pw<?= $rowId ?>"
                                                   name="reauth_password" autocomplete="current-password"
                                                   placeholder="Your password" style="max-width:150px" required>
                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">Run it</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canEdit && (string) $row['status'] === 'ready' && (string) $row['file_name'] !== ''): ?>
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                              style="display:flex;gap:6px;align-items:center">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="download">
                                            <input type="hidden" name="id" value="<?= $rowId ?>">
                                            <label class="sik-sr" for="dl<?= $rowId ?>">Your password</label>
                                            <input class="sik-input sik-input--sm" type="password" id="dl<?= $rowId ?>"
                                                   name="reauth_password" autocomplete="current-password"
                                                   placeholder="Your password" style="max-width:150px" required>
                                            <button type="submit" class="ad-btn ad-btn--icon" title="Download the bundle"
                                                    aria-label="Download the export for <?= e_attr((string) $row['email']) ?>">
                                                <?= icon('download', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canEdit && $isOpen): ?>
                                        <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form"
                                              style="display:flex;gap:6px;align-items:center">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="reject">
                                            <input type="hidden" name="id" value="<?= $rowId ?>">
                                            <label class="sik-sr" for="rj<?= $rowId ?>">Why</label>
                                            <input class="sik-input sik-input--sm" type="text" id="rj<?= $rowId ?>"
                                                   name="reason" placeholder="Why it is being closed"
                                                   style="max-width:190px" required>
                                            <button type="submit" class="ad-btn ad-btn--sm">Close</button>
                                        </form>
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
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Log a request that came in another way</div>
                <div class="ad-card__sub">
                    For a request that arrived by email or over the phone. Nothing happens to the
                    account until you press Run it.
                </div>
            </div>
        </div>
        <form method="post" action="<?= e($selfUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="raise">

            <div class="ad-card__body" style="display:grid;gap:12px">
                <div class="ad-field">
                    <label class="sik-label" for="customer">Customer <span class="req">*</span></label>
                    <input class="sik-input" type="text" id="customer" name="customer" required
                           style="max-width:360px" placeholder="their sign-in email, or their customer id">
                    <span class="sik-help">
                        Check it is really them before you log it. An export handed to the wrong person
                        is the breach this feature exists to prevent.
                    </span>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="type">What they asked for</label>
                    <select class="sik-input" id="type" name="type" style="max-width:260px">
                        <?php foreach (PRIVACY_TYPES as $value => $label): ?>
                            <option value="<?= e_attr($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="reason">Note</label>
                    <input class="sik-input" type="text" id="reason" name="reason" maxlength="255"
                           placeholder="e.g. Emailed support on 12 Sep, identity checked against last order">
                    <span class="sik-help">Goes on the record. Write what you checked, not just that you did.</span>
                </div>
            </div>

            <div class="ad-card__foot">
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('plus', 'w-4 h-4') ?> Log the request
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
