<?php
/**
 * ShopInnKart Admin - Email settings & templates.
 *
 * The sender identity and SMTP credentials live in the settings table; the
 * message bodies live in notification_templates. The test button and the
 * queue drainer are here too, because "did that actually send?" is the only
 * question an admin has on this screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

$spec = [
    'mail_from_name' => [
        'type' => 'text', 'label' => 'From name', 'required' => true, 'max' => 100,
    ],
    'mail_from_email' => [
        'type' => 'email', 'label' => 'From address', 'required' => true, 'max' => 190,
        'help' => 'Must be a mailbox your server is allowed to send as, or mail lands in spam.',
    ],
    'mail_reply_to' => [
        'type' => 'email', 'label' => 'Reply-To address', 'max' => 190,
        'help' => 'Where customer replies land. Falls back to the store support address.',
    ],
    'admin_notify_email' => [
        'type' => 'text', 'label' => 'Admin notification address(es)', 'required' => true, 'max' => 500,
        'help' => 'Gets new-order, failed-payment, cancellation, return and enquiry alerts. Separate several addresses with commas.',
    ],
    'mail_driver' => [
        'type' => 'select', 'label' => 'Transport', 'required' => true,
        // PHP mail() is gone: it gave no delivery signal, no auth and no way to
        // attach the invoice PDF. Installs still set to 'mail' fall back to SMTP.
        'options' => [
            'smtp'     => 'SMTP (recommended)',
            'log'      => 'Log to file — nothing is delivered (development)',
            'disabled' => 'Disabled — queue but never send',
        ],
    ],
    'smtp_host' => [
        'type' => 'text', 'label' => 'SMTP host', 'max' => 190,
        'placeholder' => 'smtp.yourhost.com',
    ],
    'smtp_port' => [
        'type' => 'number', 'label' => 'SMTP port', 'min_value' => 1, 'max_value' => 65535,
    ],
    'smtp_user' => [
        'type' => 'text', 'label' => 'SMTP username', 'max' => 190,
    ],
    'smtp_pass' => [
        'type' => 'password', 'label' => 'SMTP password',
        'help' => 'Encrypted before it is stored and never rendered back into this page. Leave blank to keep the current one.',
    ],
    'smtp_encryption' => [
        'type' => 'select', 'label' => 'Encryption',
        'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (not recommended)'],
    ],
    'smtp_auth' => [
        'type' => 'bool', 'label' => 'Authenticate',
        'help' => 'Turn off only for a relay that accepts your server by IP.',
    ],
    'smtp_timeout' => [
        'type' => 'number', 'label' => 'Timeout (seconds)', 'min_value' => 5, 'max_value' => 120,
    ],
    'smtp_allow_self_signed' => [
        'type' => 'bool', 'label' => 'Accept self-signed certificates',
        'help' => 'For local relays such as Mailhog. Never enable this in production.',
    ],
    'email_queue_auto_drain' => [
        'type' => 'bool', 'label' => 'Send from page views',
        'help' => 'A fallback for installs with no cron. Turn this off once bin/send-queued-emails.php is scheduled.',
    ],
    'email_log_retention_days' => [
        'type' => 'number', 'label' => 'Keep email log for (days)', 'min_value' => 7, 'max_value' => 3650,
    ],
];

$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Sender settings
// ---------------------------------------------------------------------------
if (is_post() && ($action === '' || $action === 'settings')) {
    // settings_handle_save() writes what it is given, so the password is
    // encrypted here on its way through. A blank field means "unchanged" and
    // is dropped by the save loop before it reaches the database.
    if (isset($_POST['smtp_pass']) && trim((string) $_POST['smtp_pass']) !== '') {
        $_POST['smtp_pass'] = secret_encrypt(trim((string) $_POST['smtp_pass']));
    }

    settings_handle_save('email', 'email', $spec, settings_group('email'));
}

// ---------------------------------------------------------------------------
//  Verify the SMTP credentials without sending anything
// ---------------------------------------------------------------------------
if (is_post() && $action === 'verify') {
    admin_require_action('settings.edit');

    $check = mailer_verify_connection();

    log_activity('email.smtp_verified', 'settings', null,
        'SMTP connection check: ' . ($check['ok'] ? 'succeeded' : 'failed'));

    if ($check['ok']) {
        flash('success', 'Connected and authenticated successfully.'
            . ($check['detail'] !== null ? ' Server said: ' . $check['detail'] : ''));
    } else {
        flash('error', 'Connection check failed: ' . (string) $check['error']
            . ($check['detail'] !== null ? ' (' . $check['detail'] . ')' : ''));
    }
    redirect(settings_url('email'));
}

// ---------------------------------------------------------------------------
//  Template save
// ---------------------------------------------------------------------------
if (is_post() && $action === 'template_save') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $template = Database::fetch('SELECT * FROM `notification_templates` WHERE `id` = :id', ['id' => $id]);

    if ($template === null) {
        flash('error', 'That template no longer exists.');
        redirect(settings_url('email'));
    }

    $subject = trim((string) input('subject', ''));
    $body = trim((string) input('body', ''));
    $status = in_array((string) input('status', 'active'), ['active', 'inactive'], true)
        ? (string) input('status', 'active') : 'inactive';

    $problem = '';
    if ($template['channel'] === 'email' && $subject === '') {
        $problem = 'An email template needs a subject line.';
    } elseif (mb_strlen($subject) > 255) {
        $problem = 'Subject must not exceed 255 characters.';
    } elseif ($body === '') {
        $problem = 'The message body cannot be empty.';
    }

    if ($problem !== '') {
        flash('error', $problem);
        flash_old($_POST);
        redirect(settings_url('email') . '?template=' . $id);
    }

    $cleanBody = sanitize_html($body);
    $bodyText = trim((string) input('body_text', ''));

    Database::update('notification_templates', [
        'subject' => $subject === '' ? null : $subject,
        // Admin-authored HTML: keep the formatting tags, drop scripts and handlers.
        'body'    => $cleanBody,
        // A blank plain-text part is derived from the HTML rather than left
        // empty, so every message still goes out as a proper multipart.
        'body_text' => $bodyText !== '' ? $bodyText : html_to_text($cleanBody),
        'attach_invoice' => input_bool('attach_invoice') ? 1 : 0,
        'status'  => $status,
    ], '`id` = :id', ['id' => $id]);

    log_activity('notification_template.updated', 'notification_template', $id,
        'Updated the "' . $template['name'] . '" template');
    admin_after_write();

    flash('success', $template['name'] . ' template saved.');
    redirect(settings_url('email') . '?template=' . $id);
}

// ---------------------------------------------------------------------------
//  Restore a system template to its shipped default
// ---------------------------------------------------------------------------
if (is_post() && $action === 'template_reset') {
    admin_require_action('settings.edit');

    require_once ROOT_PATH . '/database/seeds/email-templates.php';

    $id = input_int('id');
    $template = Database::fetch('SELECT * FROM `notification_templates` WHERE `id` = :id', ['id' => $id]);

    if ($template === null) {
        flash('error', 'That template no longer exists.');
        redirect(settings_url('email'));
    }

    if (reset_email_template((string) $template['template_key'])) {
        log_activity('notification_template.reset', 'notification_template', $id,
            'Reset the "' . $template['name'] . '" template to its default');
        admin_after_write();
        flash('success', $template['name'] . ' restored to the shipped default.');
    } else {
        flash('error', 'That template has no shipped default to restore.');
    }

    redirect(settings_url('email') . '?template=' . $id);
}

// ---------------------------------------------------------------------------
//  Send one template to a test address, rendered with sample data
// ---------------------------------------------------------------------------
if (is_post() && $action === 'template_test') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $to = trim((string) input('test_email', ''));
    $template = Database::fetch('SELECT * FROM `notification_templates` WHERE `id` = :id', ['id' => $id]);

    if ($template === null) {
        flash('error', 'That template no longer exists.');
        redirect(settings_url('email'));
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address to send the test to.');
        redirect(settings_url('email') . '?template=' . $id);
    }
    if (!mail_rate_limit_hit('test_email_' . (int) $admin['id'], 5, 60)) {
        flash('error', 'Too many test emails. Please wait a minute before trying again.');
        redirect(settings_url('email') . '?template=' . $id);
    }

    $vars = email_template_sample_vars();
    $result = send_email_detailed(
        $to,
        '[TEST] ' . render_template((string) $template['subject'], $vars),
        render_template((string) $template['body'], $vars),
        [],
        ['text' => render_template((string) ($template['body_text'] ?? ''), template_text_vars($vars))]
    );

    log_activity('email.template_test', 'notification_template', $id,
        'Sent a test of "' . $template['name'] . '" to ' . $to);

    if ($result['ok']) {
        flash('success', 'Test of "' . $template['name'] . '" sent to ' . $to . '.'
            . ($result['transport'] === 'log' ? ' Transport is "log" — written to storage/logs/mail/, not delivered.' : ''));
    } else {
        flash('error', 'Send failed: ' . (string) $result['error']);
    }

    redirect(settings_url('email') . '?template=' . $id);
}

/**
 * Realistic placeholder values for previews and template tests.
 * Built from a real order when one exists so the preview shows the store's own
 * formatting rather than invented numbers.
 */
function email_template_sample_vars(): array
{
    $order = Database::fetch('SELECT * FROM `orders` ORDER BY `id` DESC LIMIT 1');

    $base = $order !== null
        ? order_notification_vars($order)
        : [
            'customer_name' => 'Sample Customer',
            'order_number'  => 'SIK' . date('Ymd') . '0001',
            'order_total'   => money(2499),
            'order_date'    => format_date(date('Y-m-d')),
        ];

    return array_merge(notification_default_vars(), $base, notification_welcome_coupon(), [
        'login_url'    => url('login.php'),
        'shop_url'     => url('shop.php'),
        'account_url'  => url('account.php'),
        'reset_url'    => url('reset-password.php?token=sample-token'),
        'expiry'       => '1 hour',
        'expiry_minutes' => '60',
        'request_ip'   => (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'subscriber_email' => 'sample@example.com',
        'unsubscribe_url'  => newsletter_unsubscribe_url('sample@example.com'),
        'product_name'  => 'Sample Product',
        'product_url'   => url('shop.php'),
        'product_price' => money(1299),
        'product_sku'   => 'SIK-SAMPLE-1',
        'rating'        => '5 out of 5',
        'stock_quantity' => '3',
        'threshold'     => (string) setting_int('low_stock_threshold', 5),
        'subject'       => 'Sample enquiry subject',
        'message'       => 'This is what a customer message looks like in the template.',
        'reply'         => 'And this is the reply an admin typed.',
        'signup_date'   => format_datetime(date('Y-m-d H:i:s')),
        'customer_url'  => admin_url('customers/'),
        'failure_reason' => 'Your bank declined the transaction.',
        'retry_url'     => url('cart.php'),
        'refund_amount' => money(2499),
        'refund_eta'    => '5-7 working days',
        'return_note'   => 'Our courier will collect the item within two working days.',
    ]);
}

// ---------------------------------------------------------------------------
//  Test email
// ---------------------------------------------------------------------------
if (is_post() && $action === 'test') {
    admin_require_action('settings.edit');

    $to = trim((string) input('test_email', ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address to send the test to.');
        redirect(settings_url('email'));
    }

    // Five test sends a minute is plenty and stops this being used to spray
    // mail at a third party from someone else's server.
    if (!mail_rate_limit_hit('test_email_' . (int) $admin['id'], 5, 60)) {
        flash('error', 'Too many test emails. Please wait a minute before trying again.');
        redirect(settings_url('email'));
    }

    $storeName = (string) setting('store_name', SITE_NAME);
    $config = mail_config();

    // Attach the newest invoice when there is one, so the test proves the
    // attachment path works too rather than only the transport.
    $attachments = [];
    $latestInvoice = Database::fetch('SELECT * FROM `invoices` ORDER BY `id` DESC LIMIT 1');
    if ($latestInvoice !== null) {
        $pdf = invoice_render_pdf($latestInvoice);
        if ($pdf['ok'] && $pdf['path'] !== null) {
            $attachments[] = ['path' => $pdf['path'], 'name' => $pdf['filename']];
        }
    }

    $result = send_email_detailed(
        $to,
        'Test email from ' . $storeName,
        '<p>This is a test message sent from <strong>' . e($storeName) . '</strong> admin at '
        . e(format_datetime(date('Y-m-d H:i:s'))) . '.</p>'
        . '<p>If you are reading it, the from address, the SMTP transport and the email layout are all working.</p>'
        . ($attachments !== [] ? '<p>A sample invoice PDF is attached, which also confirms attachments work.</p>' : '')
        . '<p style="color:#6b7280;font-size:13px">Transport: ' . e(strtoupper($config['transport']))
        . ($config['host'] !== '' ? ' via ' . e($config['host']) . ':' . (int) $config['port'] : '')
        . '<br>Sent by ' . e((string) $admin['name']) . '.</p>',
        $attachments
    );

    log_activity('email.test_sent', 'settings', null,
        'Sent a test email to ' . $to . ' (' . ($result['ok'] ? 'accepted' : 'rejected') . ')');

    if ($result['ok']) {
        flash('success', 'Test email accepted for ' . $to . '.'
            . ($result['transport'] === 'log'
                ? ' Transport is "log", so it was written to storage/logs/mail/ and NOT delivered.'
                : ' Check the inbox and the spam folder.')
            . ($result['smtp'] !== null ? ' Server said: ' . $result['smtp'] : ''));
    } else {
        // The old message guessed at the cause; PHPMailer tells us the truth.
        flash('error', 'Send failed: ' . (string) $result['error']);
    }
    redirect(settings_url('email'));
}

// ---------------------------------------------------------------------------
//  Drain the queue
// ---------------------------------------------------------------------------
if (is_post() && $action === 'process_queue') {
    admin_require_action('settings.edit');

    $result = process_notification_queue(50);

    log_activity('notification_queue.processed', 'notification_queue', null,
        'Processed the notification queue: ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
    admin_after_write();

    flash(
        $result['failed'] > 0 && $result['sent'] === 0 ? 'error' : 'success',
        'Queue run finished: ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed.'
    );
    redirect(settings_url('email'));
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$stored = settings_group('email');
$errors = errors_pull();

// Grab the template editor's rejected input before settings_values() clears the bag.
$oldTemplate = settings_has_old()
    ? ['subject' => old('subject', null), 'body' => old('body', null), 'status' => old('status', null)]
    : [];

$values = settings_values($spec, $stored);

$hasStoredPassword = trim((string) ($stored['smtp_pass'] ?? '')) !== '';

$templates = Database::fetchAll(
    'SELECT * FROM `notification_templates` ORDER BY `channel`, `template_key`'
);

$editing = null;
$templateParam = (string) ($_GET['template'] ?? '');
if ($templateParam !== '' && ctype_digit($templateParam)) {
    $editing = Database::fetch('SELECT * FROM `notification_templates` WHERE `id` = :id', ['id' => (int) $templateParam]);
    if ($editing === null) {
        flash('error', 'That template no longer exists.');
        redirect(settings_url('email'));
    }
}

$queueCounts = Database::fetchPairs('SELECT `status`, COUNT(*) FROM `notification_queue` GROUP BY `status`');
$lastSent = Database::fetchColumn("SELECT MAX(`sent_at`) FROM `notification_queue` WHERE `status` = 'sent'");

$canEdit = admin_can('settings.edit');

$pageTitle    = 'Email Settings';
$pageSubtitle = 'Who mail comes from, how it is sent, and what each message says.';
$breadcrumbs  = settings_breadcrumbs('email');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('email') ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Templates', (string) count($templates), 'mail', 'navy',
        Database::count('notification_templates', "`status` = 'active'") . ' active') ?>
    <?= admin_stat_card('Queued', number_format((int) ($queueCounts['pending'] ?? 0)), 'clock', 'amber',
        'Waiting to be sent') ?>
    <?= admin_stat_card('Sent', number_format((int) ($queueCounts['sent'] ?? 0)), 'check-circle', 'green',
        $lastSent ? 'Last: ' . format_datetime((string) $lastSent) : 'Nothing sent yet') ?>
    <?= admin_stat_card('Failed', number_format((int) ($queueCounts['failed'] ?? 0)), 'alert', 'red',
        'Gave up after 3 attempts') ?>
</div>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:18px">

        <!-- ============================ Sender ============================ -->
        <form class="ad-form" method="post" action="<?= e(settings_url('email')) ?>" data-guard-unsaved>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Sender</div>
                        <div class="ad-card__sub">Every notification is sent with this identity.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <?= settings_field('mail_from_name', $spec, $values, $errors) ?>
                        <?= settings_field('mail_from_email', $spec, $values, $errors) ?>
                    </div>
                    <?= settings_field('admin_notify_email', $spec, $values, $errors) ?>
                    <p class="ad-muted" style="font-size:12.5px">
                        Replies go to the support address on the General screen
                        (<?= e((string) setting('store_email', '')) ?>).
                    </p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Transport</div>
                        <div class="ad-card__sub">Where the message is handed off.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?php
                    $mailConfig = mail_config();
                    $overridden = array_keys(array_filter(
                        $mailConfig['source'],
                        static fn (string $source): bool => $source !== 'admin settings'
                    ));
                    ?>

                    <div class="sik-alert sik-alert--info" style="margin-bottom:16px">
                        <?= icon('info', 'w-5 h-5') ?>
                        <div>
                            Messages go out over <strong>SMTP via PHPMailer</strong>. PHP's <code>mail()</code>
                            is no longer used — it reported no delivery errors and could not carry the invoice PDF.
                            Credentials are read in this order:
                            <strong>environment variables</strong> &rarr; <code>config/mail.local.php</code> &rarr;
                            the fields below.
                        </div>
                    </div>

                    <?php if ($overridden !== []): ?>
                        <div class="sik-alert sik-alert--warning" style="margin-bottom:16px">
                            <?= icon('alert', 'w-5 h-5') ?>
                            <div>
                                <strong>Some values below are being overridden.</strong>
                                <?php foreach ($overridden as $field): ?>
                                    <br><code><?= e($field) ?></code> comes from <?= e($mailConfig['source'][$field]) ?>.
                                <?php endforeach; ?>
                                <br>Editing the matching field here will have no effect until that source is removed.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?= settings_field('mail_driver', $spec, $values, $errors) ?>

                    <div class="ad-row ad-row--2">
                        <?= settings_field('smtp_host', $spec, $values, $errors) ?>
                        <?= settings_field('smtp_port', $spec, $values, $errors) ?>
                    </div>
                    <div class="ad-row ad-row--2">
                        <?= settings_field('smtp_user', $spec, $values, $errors) ?>
                        <?= settings_field('smtp_encryption', $spec, $values, $errors) ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="set_smtp_pass">SMTP password</label>
                        <input class="sik-input<?= isset($errors['smtp_pass']) ? ' is-invalid' : '' ?>"
                               type="password" id="set_smtp_pass" name="smtp_pass"
                               autocomplete="new-password" placeholder="<?= $hasStoredPassword ? '••••••••  (leave blank to keep)' : 'Not set' ?>">
                        <?php if (isset($errors['smtp_pass'])): ?>
                            <span class="sik-error"><?= e($errors['smtp_pass']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                <?= $hasStoredPassword
                                    ? 'A password is saved. It is never rendered back into this page — leave the field blank to keep it, or type a new one to replace it.'
                                    : 'No password saved yet.' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-row ad-row--2">
                        <?= settings_field('smtp_timeout', $spec, $values, $errors) ?>
                        <?= settings_field('email_log_retention_days', $spec, $values, $errors) ?>
                    </div>
                    <?= settings_field('smtp_auth', $spec, $values, $errors) ?>
                    <?= settings_field('smtp_allow_self_signed', $spec, $values, $errors) ?>
                    <?= settings_field('email_queue_auto_drain', $spec, $values, $errors) ?>

                    <p class="ad-muted" style="font-size:12.5px;margin-top:12px">
                        For reliable delivery schedule the worker instead of relying on page views:<br>
                        <code class="ad-mono">php <?= e(ROOT_PATH) ?>/bin/send-queued-emails.php</code> every minute.
                    </p>
                </div>
                <?= settings_save_bar() ?>
            </div>
        </form>

        <!-- ==================== Connection check ==================== -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Connection check</div>
                    <div class="ad-card__sub">Opens an SMTP session and authenticates without sending a message.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <form method="post" action="<?= e(settings_url('email')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <button type="submit" class="ad-btn">
                        <?= icon('check-circle', 'w-4 h-4') ?> Verify SMTP credentials
                    </button>
                </form>
            </div>
        </div>

        <!-- =========================== Templates =========================== -->
        <?php if ($editing !== null): ?>
            <?php
            $oldSubject = $oldTemplate['subject'] ?? null;
            $oldBody    = $oldTemplate['body'] ?? null;
            $oldStatus  = $oldTemplate['status'] ?? null;

            $placeholders = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $editing['variables'])
            )));
            ?>
            <form class="ad-form" method="post" action="<?= e(settings_url('email')) ?>" data-guard-unsaved>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="template_save">
                <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

                <div class="ad-card" style="margin:0">
                    <div class="ad-card__head">
                        <div>
                            <div class="ad-card__title">Editing: <?= e((string) $editing['name']) ?></div>
                            <div class="ad-card__sub">
                                <span class="ad-mono"><?= e((string) $editing['template_key']) ?></span>
                                &middot; <?= e(ucfirst((string) $editing['channel'])) ?> channel
                            </div>
                        </div>
                        <a class="ad-btn ad-btn--sm" href="<?= e(settings_url('email')) ?>">Close</a>
                    </div>

                    <div class="ad-card__body">
                        <?php if ($placeholders !== []): ?>
                            <div class="ad-field">
                                <span class="sik-label">Available placeholders</span>
                                <div style="display:flex;flex-wrap:wrap;gap:6px">
                                    <?php foreach ($placeholders as $placeholder): ?>
                                        <button type="button" class="ad-btn ad-btn--sm ad-mono"
                                                data-copy="<?= e_attr($placeholder) ?>"
                                                title="Copy <?= e_attr($placeholder) ?>">
                                            <?= e($placeholder) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <span class="sik-help">
                                    Click to copy. Anything else in double braces is left in the message as written.
                                    Every template can also use {{store_name}}, {{store_url}}, {{store_email}},
                                    {{store_phone}}, {{store_address}} and {{year}}.
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="ad-field">
                            <label class="sik-label" for="tplSubject">
                                Subject <?= $editing['channel'] === 'email' ? '<span class="req">*</span>' : '' ?>
                            </label>
                            <input class="sik-input" type="text" id="tplSubject" name="subject" maxlength="255"
                                   <?= $editing['channel'] === 'email' ? 'required' : '' ?>
                                   value="<?= e((string) ($oldSubject ?? $editing['subject'])) ?>">
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="tplBody">Body <span class="req">*</span></label>
                            <textarea class="sik-textarea" id="tplBody" name="body" rows="16" required
                                      spellcheck="false"
                                      style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;min-height:340px"><?= e((string) ($oldBody ?? $editing['body'])) ?></textarea>
                            <span class="sik-help">
                                HTML fragment. It is wrapped in the branded email shell on send, so there is no need
                                for &lt;html&gt; or &lt;body&gt; tags. Scripts and event handlers are stripped when saved.
                            </span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="tplBodyText">Plain-text version</label>
                            <textarea class="sik-textarea" id="tplBodyText" name="body_text" rows="8"
                                      spellcheck="false"
                                      style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"><?= e((string) ($editing['body_text'] ?? '')) ?></textarea>
                            <span class="sik-help">
                                The fallback part for clients that do not render HTML, and a real factor in
                                spam scoring. Leave blank and it is derived from the HTML automatically.
                            </span>
                        </div>

                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="tplStatus">Status</label>
                                <select class="sik-select" id="tplStatus" name="status">
                                    <?= admin_options(
                                        ['active' => 'Active', 'inactive' => 'Inactive'],
                                        (string) ($oldStatus ?? $editing['status'])
                                    ) ?>
                                </select>
                                <span class="sik-help">An inactive template silently skips that notification.</span>
                            </div>

                            <div class="ad-field">
                                <span class="sik-label">Recipient</span>
                                <p style="margin:6px 0 0 0">
                                    <span class="sik-badge sik-badge--soft">
                                        <?= e(ucfirst((string) ($editing['recipient_type'] ?? 'customer'))) ?>
                                    </span>
                                </p>
                                <span class="sik-help">
                                    <?= (string) ($editing['recipient_type'] ?? 'customer') === 'admin'
                                        ? 'Goes to the admin notification addresses.'
                                        : 'Goes to the customer this event belongs to.' ?>
                                </span>
                            </div>
                        </div>

                        <div class="ad-field">
                            <label class="sik-switch">
                                <input type="checkbox" name="attach_invoice" value="1"
                                       <?= (int) ($editing['attach_invoice'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>Attach the PDF invoice</span>
                            </label>
                            <span class="sik-help">
                                Only meaningful for order emails. The attachment is skipped silently when the
                                order has no invoice, so the message still goes out.
                            </span>
                        </div>
                    </div>

                    <div class="ad-card__foot">
                        <a class="ad-btn" href="<?= e(settings_url('email')) ?>">Cancel</a>
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save Template
                        </button>
                    </div>
                </div>
            </form>

            <!-- ============== Per-template test send / reset ============== -->
            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Try it</div>
                        <div class="ad-card__sub">
                            Sends "<?= e((string) $editing['name']) ?>" filled in with sample data from your newest order.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <form class="ad-form" method="post" action="<?= e(settings_url('email')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="template_test">
                        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="tplTestEmail">Send a test to</label>
                                <input class="sik-input" type="email" id="tplTestEmail" name="test_email"
                                       value="<?= e((string) $admin['email']) ?>" required>
                            </div>
                            <div class="ad-field" style="align-self:end">
                                <button type="submit" class="ad-btn ad-btn--primary">
                                    <?= icon('send', 'w-4 h-4') ?> Send test
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if ((int) ($editing['is_system'] ?? 0) === 1): ?>
                        <hr class="sik-divider">
                        <form method="post" action="<?= e(settings_url('email')) ?>"
                              onsubmit="return confirm('Replace your edits to this template with the shipped default?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="template_reset">
                            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
                            <button type="submit" class="ad-btn ad-btn--danger-ghost ad-btn--sm">
                                <?= icon('refresh', 'w-4 h-4') ?> Reset to default
                            </button>
                            <span class="ad-muted" style="font-size:12.5px;margin-left:8px">
                                Restores the subject, body and plain-text version this template ships with.
                            </span>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Notification templates</div>
                    <div class="ad-card__sub">One row per event. The key is what the application calls.</div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($templates === []): ?>
                    <?= admin_empty(
                        'No templates',
                        'Without templates the store sends nothing at all — orders, password resets and welcome mails are all driven from this table.',
                        null, null, 'mail'
                    ) ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Template</th>
                                    <th>Key</th>
                                    <th>Channel</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th class="ad-table__actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                    <tr>
                                        <td class="ad-cellflex__name"><?= e((string) $template['name']) ?></td>
                                        <td class="ad-mono"><?= e((string) $template['template_key']) ?></td>
                                        <td><?= e(ucfirst((string) $template['channel'])) ?></td>
                                        <td class="ad-muted"><?= e(str_limit((string) $template['subject'], 55)) ?></td>
                                        <td><?= admin_state_badge((string) $template['status']) ?></td>
                                        <td class="ad-table__actions">
                                            <?php if ($canEdit): ?>
                                                <a class="ad-btn ad-btn--sm"
                                                   href="<?= e(settings_url('email') . '?template=' . (int) $template['id']) ?>">
                                                    <?= icon('edit', 'w-4 h-4') ?> Edit
                                                </a>
                                            <?php else: ?>
                                                <span class="ad-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================== Sidebar ============================= -->
    <div style="display:grid;gap:18px;align-content:start">
        <?php if ($canEdit): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Send a test email</div>
                </div>
                <form class="ad-card__body" method="post" action="<?= e(settings_url('email')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test">

                    <div class="ad-field">
                        <label class="sik-label" for="testEmail">Send to</label>
                        <input class="sik-input" type="email" id="testEmail" name="test_email" required
                               value="<?= e((string) ($admin['email'] ?? '')) ?>">
                        <span class="sik-help">
                            Uses the saved sender settings and the real email layout, so it proves the whole path.
                        </span>
                    </div>

                    <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                        <?= icon('mail', 'w-4 h-4') ?> Send test email
                    </button>
                </form>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Notification queue</div>
                        <div class="ad-card__sub">Nothing is sent inline; everything is queued and drained.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?php if ((int) ($queueCounts['pending'] ?? 0) === 0): ?>
                        <p class="ad-muted" style="font-size:13px">The queue is empty.</p>
                    <?php else: ?>
                        <p style="font-size:13px;margin-bottom:12px">
                            <strong><?= number_format((int) $queueCounts['pending']) ?></strong>
                            message(s) waiting. Each is retried up to three times before it is marked failed.
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?= e(settings_url('email')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="process_queue">
                        <button type="submit" class="ad-btn ad-btn--block"
                                <?= (int) ($queueCounts['pending'] ?? 0) === 0 ? 'disabled' : '' ?>>
                            <?= icon('refresh', 'w-4 h-4') ?> Send up to 50 now
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
