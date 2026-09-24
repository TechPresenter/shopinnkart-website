<?php
/**
 * ShopInnKart Admin - Email delivery log.
 *
 * Reads notification_queue, which doubles as the log: a row is written when a
 * message is queued and updated in place with the outcome, the SMTP reply and
 * the attachment it carried. Keeping one table means the queue and the log can
 * never disagree about whether something was sent.
 *
 * Failures can be retried from here, individually or in bulk.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

$selfUrl = admin_url('settings/email-log.php');
$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if (is_post() && $action === 'retry') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $row = Database::fetch('SELECT * FROM `notification_queue` WHERE `id` = :id', ['id' => $id]);

    if ($row === null) {
        flash('error', 'That log entry no longer exists.');
        redirect($selfUrl);
    }

    // Resending is a deliberate act, so it bypasses the attempt ceiling.
    Database::update('notification_queue', [
        'status'   => 'pending',
        'attempts' => 0,
        'error'    => null,
    ], '`id` = :id', ['id' => $id]);

    $result = process_notification_queue(1);

    $after = Database::fetch('SELECT `status`, `error` FROM `notification_queue` WHERE `id` = :id', ['id' => $id]);

    log_activity('email.retried', 'notification_queue', $id, 'Retried the email to ' . mask_email((string) $row['recipient']));
    admin_after_write();

    if ($result['sent'] > 0) {
        flash('success', 'Resent to ' . $row['recipient'] . '.');
    } else {
        flash('error', 'Retry failed: ' . (string) ($after['error'] ?? 'unknown error') . '.');
    }
    redirect($selfUrl);
}

if (is_post() && $action === 'retry_all') {
    admin_require_action('settings.edit');

    $requeued = Database::query(
        "UPDATE `notification_queue` SET `status` = 'pending', `attempts` = 0, `error` = NULL
         WHERE `status` = 'failed'"
    )->rowCount();

    $result = process_notification_queue(50);

    log_activity('email.retried_all', 'notification_queue', null,
        'Requeued ' . $requeued . ' failed emails: ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
    admin_after_write();

    flash(
        $result['sent'] > 0 ? 'success' : 'warning',
        'Requeued ' . $requeued . ' message(s): ' . $result['sent'] . ' sent, ' . $result['failed'] . ' still failing.'
    );
    redirect($selfUrl);
}

if (is_post() && $action === 'prune') {
    admin_require_action('settings.edit');

    $days = max(7, setting_int('email_log_retention_days', 90));
    $deleted = Database::query(
        "DELETE FROM `notification_queue`
         WHERE `status` = 'sent' AND `created_at` < DATE_SUB(NOW(), INTERVAL :d DAY)",
        ['d' => $days]
    )->rowCount();

    log_activity('email.log_pruned', 'notification_queue', null, 'Pruned ' . $deleted . ' sent emails older than ' . $days . ' days');
    admin_after_write();

    flash('success', 'Removed ' . number_format($deleted) . ' sent entries older than ' . $days . ' days.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
//  Filters
// ---------------------------------------------------------------------------
$search    = trim((string) input('q', ''));
$status    = (string) input('status', '');
$type      = (string) input('type', '');
$recipient = (string) input('recipient_type', '');

$where = ['1 = 1'];
$params = [];

if ($search !== '') {
    $where[] = '(`recipient` LIKE :q OR `subject` LIKE :q OR `template_key` LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if (in_array($status, ['pending', 'sent', 'failed'], true)) {
    $where[] = '`status` = :status';
    $params['status'] = $status;
}
if ($type !== '') {
    $where[] = '`email_type` = :type';
    $params['type'] = $type;
}
if (in_array($recipient, ['customer', 'admin'], true)) {
    $where[] = '`recipient_type` = :rt';
    $params['rt'] = $recipient;
}

$whereSql = implode(' AND ', $where);

$total = (int) Database::fetchColumn('SELECT COUNT(*) FROM `notification_queue` WHERE ' . $whereSql, $params);

$page = max(1, (int) ($_GET['page'] ?? 1));
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$rows = Database::fetchAll(
    "SELECT q.*, o.`order_number`
     FROM `notification_queue` q
     LEFT JOIN `orders` o ON o.`id` = q.`order_id`
     WHERE {$whereSql}
     ORDER BY q.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$counts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `notification_queue` GROUP BY `status`');
$types = Database::fetchColumnAll(
    "SELECT DISTINCT `email_type` FROM `notification_queue` WHERE `email_type` IS NOT NULL AND `email_type` <> '' ORDER BY `email_type`"
);
$lastSent = Database::fetchColumn("SELECT MAX(`sent_at`) FROM `notification_queue` WHERE `status` = 'sent'");

$transport = mail_config();

$pageTitle = 'Email log';
$pageSubtitle = number_format($total) . ' message' . ($total === 1 ? '' : 's')
    . ' · transport: ' . strtoupper($transport['transport']);
$breadcrumbs = settings_breadcrumbs('email-log');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('email-log') ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Sent', number_format((int) ($counts['sent'] ?? 0)), 'check-circle', 'green',
        $lastSent ? 'Last: ' . format_datetime((string) $lastSent) : 'Nothing sent yet') ?>
    <?= admin_stat_card('Pending', number_format((int) ($counts['pending'] ?? 0)), 'clock', 'amber', 'Waiting for the worker') ?>
    <?= admin_stat_card('Failed', number_format((int) ($counts['failed'] ?? 0)), 'alert',
        (int) ($counts['failed'] ?? 0) > 0 ? 'red' : 'green', 'Gave up after 3 attempts') ?>
    <?= admin_stat_card('Transport', strtoupper($transport['transport']), 'send',
        $transport['transport'] === 'smtp' ? 'navy' : 'amber',
        $transport['transport'] === 'smtp'
            ? ($transport['host'] !== '' ? $transport['host'] : 'no host configured')
            : 'not delivering to real inboxes') ?>
</div>

<?php if ($transport['transport'] === 'log'): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>Mail transport is set to "Log to file".</strong>
            Messages are written to <code>storage/logs/mail/</code> as .eml files and are
            <em>not</em> delivered to anybody. Switch to SMTP on the
            <a href="<?= e(settings_url('email')) ?>">Email settings</a> screen before going live.
        </div>
    </div>
<?php elseif ($transport['transport'] === 'smtp' && $transport['host'] === ''): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>No SMTP host is configured.</strong>
            Nothing can be delivered. Set one on the
            <a href="<?= e(settings_url('email')) ?>">Email settings</a> screen.
        </div>
    </div>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Filter</div>
        </div>
    </div>
    <div class="ad-card__body">
        <form class="ad-form" method="get" action="<?= e($selfUrl) ?>">
            <div class="ad-row ad-row--2">
                <div class="ad-field">
                    <label class="sik-label" for="q">Search</label>
                    <input class="sik-input" type="search" id="q" name="q" value="<?= e($search) ?>"
                           placeholder="Recipient, subject or template">
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="status">Status</label>
                    <select class="sik-input" id="status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (['pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed'] as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="ad-row ad-row--2">
                <div class="ad-field">
                    <label class="sik-label" for="type">Email type</label>
                    <select class="sik-input" id="type" name="type">
                        <option value="">All types</option>
                        <?php foreach ($types as $value): ?>
                            <option value="<?= e((string) $value) ?>" <?= $type === (string) $value ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', (string) $value))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="recipient_type">Recipient</label>
                    <select class="sik-input" id="recipient_type" name="recipient_type">
                        <option value="">Everyone</option>
                        <option value="customer" <?= $recipient === 'customer' ? 'selected' : '' ?>>Customers</option>
                        <option value="admin" <?= $recipient === 'admin' ? 'selected' : '' ?>>Admins</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="ad-btn ad-btn--primary">Apply</button>
                <a class="ad-btn" href="<?= e($selfUrl) ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Messages</div>
            <div class="ad-card__sub">Newest first.</div>
        </div>
        <?php if (admin_can('settings.edit')): ?>
            <div class="ad-btngroup">
                <?php if ((int) ($counts['failed'] ?? 0) > 0): ?>
                    <form method="post" action="<?= e($selfUrl) ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retry_all">
                        <button type="submit" class="ad-btn ad-btn--sm">
                            <?= icon('refresh', 'w-4 h-4') ?> Retry all failed
                        </button>
                    </form>
                <?php endif; ?>
                <?php $emailLogDays = (int) max(7, setting_int('email_log_retention_days', 90)); ?>
                <form method="post" action="<?= e($selfUrl) ?>" style="display:inline"
                      <?= admin_confirm_form_attrs(
                          'Sent entries older than ' . $emailLogDays . ' days are deleted. Failed entries are kept.',
                          ['title' => 'Prune the email log?', 'label' => 'Delete old entries']
                      ) ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="prune">
                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger-ghost">
                        <?= icon('trash', 'w-4 h-4') ?> Prune old
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($rows === []): ?>
            <div class="sik-empty sik-empty--sm">
                <?= icon('mail', 'w-12 h-12') ?>
                <p class="sik-empty__title">No messages match</p>
                <p class="sik-empty__text">
                    <?= $total === 0 && $search === '' && $status === ''
                        ? 'Nothing has been queued yet. Place a test order or send a test email.'
                        : 'Try widening the filters.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Order</th>
                        <th>Attachment</th>
                        <th>Status</th>
                        <th>When</th>
                        <th class="ad-table__actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowStatus = (string) $row['status'];
                        $tone = ['sent' => 'green', 'pending' => 'amber', 'failed' => 'red'][$rowStatus] ?? 'gray';
                        ?>
                        <tr>
                            <td>
                                <div class="ad-cellflex__name"><?= e((string) $row['recipient']) ?></div>
                                <span class="sik-badge sik-badge--soft"><?= e((string) $row['recipient_type']) ?></span>
                            </td>
                            <td style="max-width:280px">
                                <div><?= e((string) ($row['subject'] ?? '—')) ?></div>
                                <?php if ((string) ($row['error'] ?? '') !== ''): ?>
                                    <div class="ad-muted" style="font-size:11.5px;color:#b91c1c">
                                        <?= e(mb_strimwidth((string) $row['error'], 0, 120, '…')) ?>
                                    </div>
                                <?php elseif ((string) ($row['smtp_response'] ?? '') !== ''): ?>
                                    <div class="ad-muted" style="font-size:11.5px">
                                        <?= e(mb_strimwidth((string) $row['smtp_response'], 0, 120, '…')) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="ad-mono" style="font-size:11.5px">
                                <?= e((string) ($row['email_type'] ?: $row['template_key'] ?: '—')) ?>
                            </td>
                            <td>
                                <?php if (!empty($row['order_number'])): ?>
                                    <a href="<?= e(admin_url('orders/view.php?id=' . (int) $row['order_id'])) ?>">
                                        <?= e((string) $row['order_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="ad-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((string) ($row['attachment_name'] ?? '') !== ''): ?>
                                    <span class="ad-mono" style="font-size:11.5px">
                                        <?= icon('paperclip', 'w-3 h-3') ?> <?= e((string) $row['attachment_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ad-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sik-status sik-status--<?= e($tone) ?>"><?= e(ucfirst($rowStatus)) ?></span>
                                <?php if ((int) $row['attempts'] > 1): ?>
                                    <div class="ad-muted" style="font-size:11px"><?= (int) $row['attempts'] ?> attempts</div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <?= e(format_datetime($row['sent_at'] ?: $row['created_at'])) ?>
                            </td>
                            <td class="ad-table__actions">
                                <?php if (admin_can('settings.edit') && $rowStatus !== 'pending'): ?>
                                    <form method="post" action="<?= e($selfUrl) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="retry">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="ad-btn ad-btn--sm"
                                                title="<?= $rowStatus === 'sent' ? 'Send this message again' : 'Retry sending' ?>">
                                            <?= icon('refresh', 'w-4 h-4') ?>
                                            <?= $rowStatus === 'sent' ? 'Resend' : 'Retry' ?>
                                        </button>
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
                of <?= number_format((int) $pagination['total']) ?>
            </span>
            <?= admin_pagination($pagination, $selfUrl) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
