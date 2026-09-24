<?php
/**
 * ShopInnKart Admin - Read and answer a contact message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.view');

/** The template the reply would be sent with, if the store has configured one. */
const CONTACT_REPLY_TEMPLATE = 'contact_reply';

$id = input_int('id');
$message = $id > 0
    ? Database::fetch('SELECT * FROM `contact_messages` WHERE `id` = :id', ['id' => $id])
    : null;

if ($message === null) {
    flash('error', 'That message no longer exists.');
    redirect(admin_url('messages/'));
}

$canReply = admin_can('customers.edit');

// A reply is only mailed when a template exists for it. notify() would return
// false on its own, but the admin needs to be told either way.
$replyTemplate = Database::fetch(
    "SELECT `id` FROM `notification_templates`
     WHERE `template_key` = :key AND `channel` = 'email' AND `status` = 'active' LIMIT 1",
    ['key' => CONTACT_REPLY_TEMPLATE]
);
$canEmailReply = $replyTemplate !== null;

$errors = [];

if (is_post()) {
    csrf_require();

    if (!$canReply) {
        flash('error', 'You do not have permission to answer messages.');
        redirect(admin_url('messages/view.php?id=' . $id));
    }

    $action = (string) input('action', 'reply');

    if ($action === 'status') {
        $newStatus = (string) input('status', '');
        if (!in_array($newStatus, ['new', 'read', 'replied', 'closed'], true)) {
            flash('error', 'That status is not recognised.');
            redirect(admin_url('messages/view.php?id=' . $id));
        }

        Database::update('contact_messages', ['status' => $newStatus], '`id` = :id', ['id' => $id]);

        log_activity('contact_message.status', 'contact_message', $id,
            'Set message from ' . $message['email'] . ' to ' . $newStatus);
        admin_after_write();

        flash('success', 'Message marked as ' . $newStatus . '.');
        redirect(admin_url('messages/view.php?id=' . $id));
    }

    $reply = trim((string) ($_POST['admin_reply'] ?? ''));

    $v = new Validator(['admin_reply' => $reply], ['admin_reply' => 'Reply']);
    $v->required('admin_reply')->max('admin_reply', 4000);

    if ($v->fails()) {
        $errors = $v->errors();
        $message['admin_reply'] = $reply;
    } else {
        Database::update('contact_messages', [
            'admin_reply' => $reply,
            'status'      => 'replied',
        ], '`id` = :id', ['id' => $id]);

        $queued = false;
        if ($canEmailReply) {
            $queued = notify(CONTACT_REPLY_TEMPLATE, (string) $message['email'], [
                'customer_name' => (string) $message['name'],
                'customer_email' => (string) $message['email'],
                'subject'       => (string) ($message['subject'] ?? 'your message'),
                // The shipped template quotes the enquiry back as {{message}};
                // {{original}} is kept for any template edited before that.
                'message'       => (string) $message['message'],
                'original'      => (string) $message['message'],
                'reply'         => $reply,
            ], 'contact_message', $id, 'email', [
                'email_type'     => 'contact_reply',
                'recipient_name' => (string) $message['name'],
                // A second reply to the same enquiry is a deliberate act.
                'force'          => true,
            ]);
        }

        log_activity('contact_message.replied', 'contact_message', $id,
            'Replied to ' . $message['email'] . ($queued ? ' (email queued)' : ' (saved, no email sent)'));
        admin_after_write();

        flash('success', $queued
            ? 'Reply saved and queued for delivery to ' . $message['email'] . '.'
            : 'Reply saved. No "' . CONTACT_REPLY_TEMPLATE . '" email template is active, so nothing was sent.');
        redirect(admin_url('messages/view.php?id=' . $id));
    }
}

// Opening the message is what marks it read. Done after the POST branch so a
// failed reply does not leave the row half-updated.
if ($message['status'] === 'new' && $canReply) {
    Database::update('contact_messages', ['status' => 'read'], '`id` = :id', ['id' => $id]);
    $message['status'] = 'read';

    log_activity('contact_message.read', 'contact_message', $id, 'Opened the message from ' . $message['email']);
    // The sidebar "new messages" badge is cached for a minute; bust it now so
    // the count does not keep advertising a message the admin is looking at.
    admin_after_write();
}

$pageTitle    = 'Message from ' . $message['name'];
$pageSubtitle = format_datetime($message['created_at']) . ' · ' . time_ago($message['created_at']);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Messages',  'url' => admin_url('messages/')],
    ['label' => str_limit((string) ($message['subject'] ?: $message['name']), 45)],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('messages/')) . '">'
    . icon('arrow-left', 'w-4 h-4') . ' Back to inbox</a>';

if (admin_can('customers.delete')) {
    $confirm = 'Delete the message from "' . $message['name'] . '"? This cannot be undone.';
    $pageActions .= '<form method="post" action="' . e(admin_url('messages/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:16px">

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= e($message['subject'] ?: 'No subject') ?></div>
                    <div class="ad-card__sub">
                        From <?= e($message['name']) ?> &lt;<?= e($message['email']) ?>&gt;
                    </div>
                </div>
                <?= admin_state_badge((string) $message['status']) ?>
            </div>
            <div class="ad-card__body">
                <p style="margin:0;white-space:pre-line;line-height:1.75"><?= e($message['message']) ?></p>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Reply</div>
                    <div class="ad-card__sub">Saving marks the conversation as replied.</div>
                </div>
            </div>

            <?php if ($canReply): ?>
                <?php if (!$canEmailReply): ?>
                    <div class="ad-card__body" style="padding-bottom:0">
                        <div class="sik-alert sik-alert--warning" style="margin:0">
                            <?= icon('alert', 'w-5 h-5') ?>
                            <div>
                                No active <code class="ad-mono"><?= e(CONTACT_REPLY_TEMPLATE) ?></code> email template
                                exists, so this reply is recorded here but not emailed.
                                Add the template under Settings &rarr; Email to turn delivery on.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(admin_url('messages/view.php?id=' . $id)) ?>" data-guard-unsaved>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply">
                    <div class="ad-card__body">
                        <div class="ad-field">
                            <label class="sik-label" for="messageReply">Your reply <span class="req">*</span></label>
                            <textarea class="sik-textarea<?= isset($errors['admin_reply']) ? ' is-invalid' : '' ?>"
                                      id="messageReply" name="admin_reply" rows="9" maxlength="4000" required
                                      style="min-height:220px"
                                      placeholder="Answer <?= e_attr($message['name']) ?> here."><?= e($message['admin_reply'] ?? '') ?></textarea>
                            <?php if (isset($errors['admin_reply'])): ?>
                                <span class="sik-error"><?= e($errors['admin_reply']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    Plain text, up to 4000 characters. The saved reply stays on the record.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ad-card__foot">
                        <a class="ad-btn" href="mailto:<?= e_attr($message['email']) ?>?subject=<?= e_attr('Re: ' . ($message['subject'] ?: 'Your message')) ?>">
                            <?= icon('mail', 'w-4 h-4') ?> Open in mail client
                        </a>
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?>
                            <?= $canEmailReply ? 'Send Reply' : 'Save Reply' ?>
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="ad-card__body">
                    <?php if (!empty($message['admin_reply'])): ?>
                        <p style="margin:0;white-space:pre-line"><?= e($message['admin_reply']) ?></p>
                    <?php else: ?>
                        <p class="ad-muted" style="margin:0">No reply has been recorded for this message.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;gap:16px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Sender</div></div>
            <div class="ad-card__body" style="display:grid;gap:10px;font-size:13.5px">
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Name</span>
                    <span><?= e($message['name']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Email</span>
                    <a href="mailto:<?= e_attr($message['email']) ?>"><?= e($message['email']) ?></a>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Phone</span>
                    <span>
                        <?php if (!empty($message['phone'])): ?>
                            <a href="tel:<?= e_attr($message['phone']) ?>"><?= e($message['phone']) ?></a>
                        <?php else: ?>
                            <span class="ad-muted">&mdash;</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Received</span>
                    <span><?= e(format_datetime($message['created_at'])) ?></span>
                </div>
                <?php if (!empty($message['ip_address'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px">
                        <span class="ad-muted">IP address</span>
                        <span class="ad-mono"><?= e($message['ip_address']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canReply): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Status</div></div>
                <form method="post" action="<?= e(admin_url('messages/view.php?id=' . $id)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <div class="ad-card__body">
                        <div class="ad-field">
                            <label class="sik-label" for="messageStatus">Conversation status</label>
                            <select class="sik-select" id="messageStatus" name="status">
                                <?= admin_options([
                                    'new'     => 'New',
                                    'read'    => 'Read',
                                    'replied' => 'Replied',
                                    'closed'  => 'Closed',
                                ], $message['status']) ?>
                            </select>
                            <span class="sik-help">Close a conversation once it needs no further action.</span>
                        </div>
                    </div>
                    <div class="ad-card__foot">
                        <button type="submit" class="ad-btn ad-btn--block">
                            <?= icon('check', 'w-4 h-4') ?> Update Status
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
