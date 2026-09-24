<?php
/**
 * ShopInnKart Admin - My security.
 *
 * The one admin screen that needs no permission beyond being signed in. Until
 * now an admin who was not a Super Admin had nowhere to change their own
 * password, let alone manage a second factor: the only password field in the
 * panel lived on admins/edit.php behind admins.edit, so the people most likely
 * to be sharing a password had no way to stop.
 *
 * Everything here acts on the signed-in admin's own account and nobody else's.
 * The sensitive actions ask for the account's own password again
 * (admin_reauth_ok), because a borrowed laptop or a stolen session cookie does
 * not know it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once ADMIN_PATH . '/includes/rbac.php';

$admin = admin_require();

$back = admin_url('account/index.php');

if (is_post()) {
    csrf_require();
    $action = (string) input('action', '');

    // ---------------------------------------------------------------------
    // Change my own password
    // ---------------------------------------------------------------------
    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors  = [];

        // Counted per admin, so this box cannot be used as a password oracle.
        if (!rate_limit_attempt('admin.self_password', (string) $admin['id'], 10, 900)) {
            $errors['current_password'] = 'Too many attempts. Please wait a few minutes.';
        } elseif ($current === '' || !password_verify_app($current, (string) $admin['password'])) {
            $errors['current_password'] = 'That is not your current password.';
            security_event('auth.reauth_failed', 'medium',
                ['reason' => 'wrong current password on own security page'], (int) $admin['id'], 'admin');
        }

        $policy = password_policy_error($new, 'admin', (string) $admin['email'] . ' ' . (string) $admin['name']);
        if ($new === '') {
            $errors['password'] = 'Choose a new password.';
        } elseif ($policy !== null) {
            $errors['password'] = $policy;
        } elseif ($new === $current) {
            $errors['password'] = 'That is the password you already have.';
        }
        if ($confirm !== $new) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            flash_errors($errors);
            flash('error', 'Your password was not changed.');
            redirect($back . '#password');
        }

        Database::update('admins', ['password' => password_hash_app($new)], '`id` = :id', ['id' => (int) $admin['id']]);

        // Bumps auth_version (every OTHER session of this account ends), keeps
        // this one, regenerates the id, forgives the counters, emails a notice.
        auth_after_password_change('admin', $admin, 'changed');
        rate_limit_clear('admin.self_password', (string) $admin['id']);
        log_activity('admin.password_changed', 'admin', (int) $admin['id'], 'Changed their own password');

        flash('success', 'Password changed. Your other sessions have been signed out.');
        redirect($back);
    }

    // ---------------------------------------------------------------------
    // Two-step sign in
    // ---------------------------------------------------------------------
    if ($action === 'enrol') {
        // Switching it ON asks for the password too, for the same reason
        // switching it off does. A borrowed laptop or a stolen session cookie
        // could otherwise enrol ITS OWN authenticator on somebody else's
        // account: the owner's password would stop being enough from the next
        // sign-in, and the way back in would be another Super Admin or the
        // command line. The forced-enrolment screen at sign-in
        // (admin/login-2fa.php) does not ask, and should not - the password
        // was typed one screen earlier.
        if (!admin_reauth_ok()) {
            flash_errors(['reauth_password' => admin_reauth_error('switch two-step sign in on')]);
            flash('error', 'Two-step sign in was not switched on.');
            redirect($back . '#two-factor');
        }

        $result = mfa_enrol_complete('admin', $admin, (string) input('code', ''));

        if (!$result['ok']) {
            flash_errors(['code' => (string) $result['error']]);
            flash('error', 'Two-step sign in was not switched on.');
            redirect($back . '#two-factor');
        }

        log_activity('admin.2fa_enabled', 'admin', (int) $admin['id'], 'Switched on two-step sign in');
        $_SESSION['_mfa_new_codes'] = ['type' => 'admin', 'codes' => $result['codes']];
        flash('success', 'Two-step sign in is on. Save the backup codes below - they are shown once.');
        redirect($back . '#backup-codes');
    }

    if ($action === 'disable') {
        // A role that requires 2FA is not something the holder can opt out of;
        // otherwise the requirement would be a suggestion.
        if (mfa_required('admin', $admin)) {
            flash('error', 'Your role requires two-step sign in, so it cannot be switched off. '
                . 'Ask a Super Admin to change the requirement first.');
            redirect($back . '#two-factor');
        }

        if (!admin_reauth_ok()) {
            flash_errors(['reauth_password' => admin_reauth_error('switch two-step sign in off')]);
            redirect($back . '#two-factor');
        }

        // The password alone is not enough: an attacker who has the password is
        // exactly who the second factor is keeping out, so removing it takes
        // the factor as well.
        $secret = mfa_secret($admin);
        $code   = (string) input('code', '');
        $proved = ($secret !== '' && totp_verify($secret, $code, 1, (int) ($admin['totp_last_step'] ?? 0)) !== null)
            || mfa_backup_consume('admin', (int) $admin['id'], $code);

        if (!$proved) {
            flash_errors(['code' => 'Enter a current code from your app, or one of your backup codes.']);
            flash('error', 'Two-step sign in is still on.');
            redirect($back . '#two-factor');
        }

        mfa_disable('admin', $admin, 'self');
        log_activity('admin.2fa_disabled', 'admin', (int) $admin['id'], 'Switched off their own two-step sign in');
        flash('success', 'Two-step sign in is off.');
        redirect($back . '#two-factor');
    }

    if ($action === 'codes') {
        if (!mfa_totp_enabled($admin)) {
            flash('error', 'Set up two-step sign in first.');
            redirect($back . '#two-factor');
        }
        if (!admin_reauth_ok()) {
            flash_errors(['reauth_password' => admin_reauth_error('issue new backup codes')]);
            redirect($back . '#backup-codes');
        }

        $_SESSION['_mfa_new_codes'] = ['type' => 'admin', 'codes' => mfa_backup_generate('admin', (int) $admin['id'])];
        log_activity('admin.2fa_codes', 'admin', (int) $admin['id'], 'Issued new backup codes');
        mfa_notify('admin', $admin, 'New two-step backup codes were issued');
        flash('success', 'Ten new backup codes. The old ones no longer work.');
        redirect($back . '#backup-codes');
    }

    // ---------------------------------------------------------------------
    // Trusted browsers
    // ---------------------------------------------------------------------
    if ($action === 'revoke_device') {
        $ok = mfa_device_revoke('admin', (int) $admin['id'], (int) input('device_id', 0));
        flash($ok ? 'success' : 'error', $ok
            ? 'That browser will be asked for a code next time.'
            : 'That browser is not on your list any more.');
        redirect($back . '#devices');
    }

    if ($action === 'revoke_devices') {
        mfa_devices_revoke_all('admin', (int) $admin['id']);
        log_activity('admin.2fa_devices_revoked', 'admin', (int) $admin['id'], 'Revoked every trusted browser');
        flash('success', 'Every trusted browser now has to prove the second factor again.');
        redirect($back . '#devices');
    }

    flash('error', 'Unknown action.');
    redirect($back);
}

$errors = errors_pull();

// Backup codes handed over exactly once: read out of the session and gone.
$newCodes = [];
if (is_array($_SESSION['_mfa_new_codes'] ?? null)
    && ($_SESSION['_mfa_new_codes']['type'] ?? '') === 'admin') {
    $newCodes = (array) $_SESSION['_mfa_new_codes']['codes'];
    unset($_SESSION['_mfa_new_codes']);
}

$enabled   = mfa_totp_enabled($admin);
$required  = mfa_required('admin', $admin);
$remaining = mfa_backup_remaining('admin', (int) $admin['id']);
$devices   = mfa_devices('admin', (int) $admin['id']);

// Enrolment material, generated only when it will actually be shown.
$secret = '';
$qr     = '';
if (!$enabled) {
    $secret = mfa_enrol_begin('admin', (int) $admin['id']);
    $qr     = totp_qr_svg(totp_uri($secret, (string) $admin['email'], mfa_issuer()));
}

$history = Database::fetchAll(
    'SELECT * FROM `login_history`
      WHERE `user_type` = :t AND `user_id` = :u
      ORDER BY `id` DESC LIMIT 10',
    ['t' => 'admin', 'u' => (int) $admin['id']]
);

$pageTitle    = 'My Security';
$pageSubtitle = 'Your password, your second factor and the browsers you have trusted.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'My Security'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:18px;align-content:start">

        <?php if ($newCodes !== []): ?>
            <div class="ad-card" style="margin:0;border:2px solid var(--ad-primary)" id="backup-codes">
                <div class="ad-card__head">
                    <div>
                        <h2 class="ad-card__title">Your ten backup codes</h2>
                        <div class="ad-card__sub">
                            Shown once, now. Print them or put them in a password manager &mdash;
                            not on the phone that holds the authenticator app.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
                        <?php foreach ($newCodes as $code): ?>
                            <code class="ad-mono" style="font-size:15px;letter-spacing:1px;padding:8px 10px;
                                  background:var(--ad-surface-2,#f3f4f6);border-radius:8px;text-align:center">
                                <?= e($code) ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" class="ad-btn ad-btn--sm"
                                data-copy="<?= e_attr(implode("\n", $newCodes)) ?>">
                            <?= icon('copy', 'w-4 h-4') ?> Copy all
                        </button>
                        <button type="button" class="ad-btn ad-btn--sm" onclick="window.print()">Print</button>
                    </div>
                    <p style="font-size:12.5px;color:var(--ad-muted);margin-top:12px">
                        Each code works once. Reloading this page will not show them again.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= two-step sign in ================= -->
        <div class="ad-card" style="margin:0" id="two-factor">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Two-step sign in</h2>
                    <div class="ad-card__sub">
                        A six-digit code from an app on your phone, on top of your password.
                        It is what stops a stolen password being enough.
                    </div>
                </div>
                <?= $enabled
                    ? '<span class="sik-status sik-status--green">On</span>'
                    : ($required
                        ? '<span class="sik-status sik-status--red">Required</span>'
                        : '<span class="sik-status sik-status--amber">Off</span>') ?>
            </div>

            <div class="ad-card__body" style="display:grid;gap:16px">
                <?php if ($enabled): ?>
                    <div style="font-size:13.5px">
                        Switched on <strong><?= e(format_datetime((string) $admin['totp_enabled_at'])) ?></strong>.
                        <?= (int) $remaining ?> of your 10 backup codes are unused.
                    </div>

                    <?php if ($remaining <= 2): ?>
                        <div class="sik-alert sik-alert--warning">
                            <?= icon('alert', 'w-5 h-5') ?>
                            <div>You are nearly out of backup codes. Issue a fresh set below.</div>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="ad-form" style="display:grid;gap:12px;max-width:420px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="codes">
                        <?= admin_reauth_field('issue new backup codes', (string) ($errors['reauth_password'] ?? '')) ?>
                        <div>
                            <button type="submit" class="ad-btn"
                                    <?= admin_confirm_attrs('The codes you have now stop working the moment the new ones are issued.', ['title' => 'Issue new backup codes?', 'label' => 'Issue new codes', 'tone' => 'warning']) ?>>
                                <?= icon('refresh', 'w-4 h-4') ?> Issue ten new backup codes
                            </button>
                        </div>
                    </form>

                    <details>
                        <summary style="cursor:pointer;font-weight:600;font-size:13.5px">Switch two-step sign in off</summary>
                        <?php if ($required): ?>
                            <p style="font-size:13px;margin-top:10px;color:var(--ad-muted)">
                                Your role requires it, so it cannot be switched off here.
                                A Super Admin has to change the requirement first.
                            </p>
                        <?php else: ?>
                            <form method="post" class="ad-form" style="display:grid;gap:12px;margin-top:12px;max-width:420px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="disable">
                                <?= admin_reauth_field('switch two-step sign in off', (string) ($errors['reauth_password'] ?? '')) ?>
                                <div class="ad-field">
                                    <label class="sik-label" for="disableCode">A current code, or a backup code</label>
                                    <input class="sik-input ad-mono<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                                           id="disableCode" name="code" type="text" autocomplete="one-time-code"
                                           maxlength="13" placeholder="000000">
                                    <?php if (isset($errors['code'])): ?>
                                        <span class="sik-error"><?= e($errors['code']) ?></span>
                                    <?php else: ?>
                                        <span class="sik-help">
                                            Asked for on purpose: somebody who only has your password must not be
                                            able to take the second factor off.
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button type="submit" class="ad-btn ad-btn--danger"
                                            <?= admin_confirm_attrs('Your account will be protected by its password alone.', ['title' => 'Switch off two-step sign in?', 'label' => 'Switch it off', 'tone' => 'danger']) ?>>
                                        Switch it off
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </details>

                <?php else: ?>
                    <?php if ($required): ?>
                        <div class="sik-alert sik-alert--warning">
                            <?= icon('alert', 'w-5 h-5') ?>
                            <div>Your role requires two-step sign in. You will be asked to set it up
                                 the next time you sign in, so you may as well do it now.</div>
                        </div>
                    <?php endif; ?>

                    <div style="display:grid;gap:16px;grid-template-columns:minmax(0,1fr);align-items:start">
                        <ol style="font-size:13.5px;display:grid;gap:12px;margin:0;padding-left:20px">
                            <li>Install Google Authenticator, Microsoft Authenticator, Authy or 1Password.</li>
                            <li>
                                Scan this, or type the key in by hand:
                                <?php if ($qr !== ''): ?>
                                    <div style="margin:10px 0;display:inline-block;padding:10px;background:#fff;border-radius:10px">
                                        <?= $qr /* locally drawn SVG - nothing leaves the server */ ?>
                                    </div>
                                <?php endif; ?>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px">
                                    <code class="ad-mono" style="flex:1 1 220px;font-size:14px;letter-spacing:1px;
                                          word-break:break-all;padding:8px 10px;background:var(--ad-surface-2,#f3f4f6);border-radius:8px">
                                        <?= e(totp_secret_grouped($secret)) ?>
                                    </code>
                                    <button type="button" class="ad-btn ad-btn--sm" data-copy="<?= e_attr($secret) ?>">
                                        <?= icon('copy', 'w-4 h-4') ?> Copy
                                    </button>
                                </div>
                                <span class="sik-help">
                                    This key is the secret. It is drawn on this server and never sent anywhere else.
                                </span>
                            </li>
                            <li>Enter the six digits the app shows.</li>
                        </ol>

                        <form method="post" class="ad-form" style="display:grid;gap:12px;max-width:340px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="enrol">
                            <div class="ad-field">
                                <label class="sik-label" for="enrolCode">Code from your app</label>
                                <input class="sik-input ad-mono<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                                       id="enrolCode" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                                       maxlength="7" autocomplete="one-time-code" placeholder="000000"
                                       style="font-size:19px;letter-spacing:5px;text-align:center">
                                <?php if (isset($errors['code'])): ?>
                                    <span class="sik-error"><?= e($errors['code']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?= admin_reauth_field('switch two-step sign in on', (string) ($errors['reauth_password'] ?? '')) ?>
                            <div>
                                <button type="submit" class="ad-btn ad-btn--primary">
                                    <?= icon('lock', 'w-4 h-4') ?> Turn on two-step sign in
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <p style="font-size:12.5px;color:var(--ad-muted);margin:0">
                    <strong>Text messages are not offered.</strong> This store has no SMS provider, and in
                    India sending one needs a DLT-registered sender ID and template. An authenticator app
                    is free, works offline and cannot be SIM-swapped.
                </p>
            </div>
        </div>

        <!-- ================= trusted browsers ================= -->
        <div class="ad-card" style="margin:0" id="devices">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Trusted browsers</h2>
                    <div class="ad-card__sub">
                        Browsers that proved the second factor and may skip it for
                        <?= (int) mfa_trust_days() ?> days. Revoke any you do not recognise.
                    </div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if ($devices === []): ?>
                    <p class="ad-muted" style="font-size:13.5px;margin:0">
                        No trusted browsers. Every sign-in asks for a code.
                    </p>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr><th>Browser</th><th>Last used</th><th>Trusted until</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($devices as $device): ?>
                                <tr>
                                    <td>
                                        <?= e((string) ($device['label'] ?? 'Unknown')) ?>
                                        <?php if (mfa_device_is_current('admin', $device)): ?>
                                            <span class="sik-status sik-status--green">This one</span>
                                        <?php endif; ?>
                                        <div class="ad-muted" style="font-size:12px"><?= e((string) $device['ip_address']) ?></div>
                                    </td>
                                    <td><?= e($device['last_used_at'] ? format_datetime((string) $device['last_used_at']) : '-') ?></td>
                                    <td><?= e(format_datetime((string) $device['expires_at'])) ?></td>
                                    <td style="text-align:right">
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke_device">
                                            <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="post" style="margin-top:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revoke_devices">
                        <button type="submit" class="ad-btn ad-btn--danger ad-btn--sm"
                                <?= admin_confirm_attrs('Every trusted browser, including this one, is asked for a code next time.', ['title' => 'Forget every trusted browser?', 'label' => 'Forget them all', 'tone' => 'warning']) ?>>
                            Revoke all
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="display:grid;gap:18px;align-content:start">

        <!-- ================= password ================= -->
        <div class="ad-card" style="margin:0" id="password">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Your password</h2>
                    <div class="ad-card__sub">At least <?= (int) password_min_length('admin') ?> characters,
                        and not one you use anywhere else.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <form method="post" class="ad-form" style="display:grid;gap:12px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="ad-field">
                        <label class="sik-label" for="currentPassword">Current password <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                               id="currentPassword" name="current_password" type="password"
                               autocomplete="current-password" required>
                        <?php if (isset($errors['current_password'])): ?>
                            <span class="sik-error"><?= e($errors['current_password']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="newPassword">New password <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                               id="newPassword" name="password" type="password"
                               autocomplete="new-password" minlength="<?= (int) password_min_length('admin') ?>" required>
                        <?php if (isset($errors['password'])): ?>
                            <span class="sik-error"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="confirmPassword">Confirm new password <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>"
                               id="confirmPassword" name="password_confirmation" type="password"
                               autocomplete="new-password" required>
                        <?php if (isset($errors['password_confirmation'])): ?>
                            <span class="sik-error"><?= e($errors['password_confirmation']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <button type="submit" class="ad-btn ad-btn--primary">Change password</button>
                    </div>
                    <p style="font-size:12.5px;color:var(--ad-muted);margin:0">
                        Your other sessions are signed out; this one stays open.
                    </p>
                </form>
            </div>
        </div>

        <!-- ================= recent sign-ins ================= -->
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Your recent sign-ins</h2>
                    <div class="ad-card__sub">Anything here you do not recognise is worth acting on.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if ($history === []): ?>
                    <p class="ad-muted" style="font-size:13.5px;margin:0">Nothing recorded yet.</p>
                <?php else: ?>
                    <div style="display:grid;gap:10px;font-size:13px">
                        <?php foreach ($history as $row): ?>
                            <div style="display:flex;gap:10px;align-items:baseline;justify-content:space-between">
                                <span>
                                    <?= $row['status'] === 'success'
                                        ? '<span class="sik-status sik-status--green">In</span>'
                                        : '<span class="sik-status sik-status--red">Refused</span>' ?>
                                    <span class="ad-mono" style="font-size:12px"><?= e((string) $row['ip_address']) ?></span>
                                </span>
                                <span class="ad-muted" style="font-size:12px">
                                    <?= e(format_datetime((string) $row['created_at'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
