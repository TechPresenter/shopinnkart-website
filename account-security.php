<?php
/**
 * ShopInnKart - Two-step sign in (customer account).
 *
 * Optional for shoppers, never forced: a store that locks a customer out of
 * their own order history loses the order, not the attacker. Two ways to do it:
 *
 *   an authenticator app  - a six-digit code, offline, the stronger one
 *   an emailed code       - for somebody who has no app and never will
 *
 * A plain form post throughout, like the sign-in screens: switching security
 * on must not depend on JavaScript.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();

if (!mfa_customers_enabled()) {
    flash('info', 'Two-step sign in is not available on this store yet.');
    redirect(url('account.php'));
}

$back = url('account-security.php');

if (is_post()) {
    csrf_require();
    $action = (string) input('action', '');

    // Re-read: $user was fetched before this POST and may be a request old.
    $fresh = Database::fetch('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => (int) $user['id']]) ?? $user;

    /**
     * Switching a second factor ON asks for the password, exactly as switching
     * it off does.
     *
     * Without it, a borrowed phone or a stolen session cookie can enrol ITS
     * OWN authenticator on the account - and then the real owner's password
     * stops being enough. A customer cannot recover from that on their own:
     * a password reset moves auth_version, which ends sessions and trusted
     * browsers, but it does not remove a TOTP secret. So the cheap check goes
     * in front of the enrolment, not only in front of the removal.
     */
    $reauthed = static function (array $fresh): bool {
        $password = (string) ($_POST['password'] ?? '');

        if (!rate_limit_attempt('mfa.enrol_reauth.customer', (string) $fresh['id'], 10, 900)) {
            return false;
        }
        if ($password === '' || !password_verify_app($password, (string) $fresh['password'])) {
            security_event('auth.reauth_failed', 'medium',
                ['reason' => 'wrong password while switching 2FA on'], (int) $fresh['id'], 'customer');
            return false;
        }

        rate_limit_clear('mfa.enrol_reauth.customer', (string) $fresh['id']);
        return true;
    };

    if ($action === 'enrol') {
        if (!$reauthed($fresh)) {
            flash_errors(['password' => 'Enter your password to switch two-step sign in on.']);
            flash('error', 'Two-step sign in was not switched on.');
            redirect($back);
        }

        $result = mfa_enrol_complete('customer', $fresh, (string) input('code', ''));

        if (!$result['ok']) {
            flash_errors(['code' => (string) $result['error']]);
            flash('error', 'Two-step sign in was not switched on.');
            redirect($back);
        }

        $_SESSION['_mfa_new_codes'] = ['type' => 'customer', 'codes' => $result['codes']];
        flash('success', 'Two-step sign in is on. Save your backup codes - they are shown once.');
        redirect($back);
    }

    if ($action === 'enrol_email') {
        // No shared secret, so nothing to verify up front - but the address is
        // proved before it becomes a factor, or a typo in the email field
        // would lock the account out at the next sign-in.
        if (!mfa_email_otp_enabled()) {
            flash('error', 'Codes by email are not available on this store.');
            redirect($back);
        }

        if (!$reauthed($fresh)) {
            flash_errors(['password' => 'Enter your password to switch two-step sign in on.']);
            flash('error', 'Two-step sign in was not switched on.');
            redirect($back);
        }

        if (!mfa_otp_verify('customer', (int) $fresh['id'], (string) input('code', ''), 'enrol_email')) {
            flash_errors(['code' => 'That code is not right, or it has expired. Ask for another.']);
            redirect($back);
        }

        mfa_email_enable($fresh);
        flash('success', 'Two-step sign in by email is on.');
        redirect($back);
    }

    if ($action === 'send_enrol_code') {
        $result = mfa_otp_send('customer', $fresh, 'enrol_email');
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'We have emailed you a code. Enter it below to finish.'
            : (string) $result['error']);
        redirect($back);
    }

    if ($action === 'disable') {
        $password = (string) ($_POST['password'] ?? '');

        if (!rate_limit_attempt('mfa.disable.customer', (string) $fresh['id'], 10, 900)) {
            flash('error', 'Too many attempts. Please wait a few minutes.');
            redirect($back);
        }
        if ($password === '' || !password_verify_app($password, (string) $fresh['password'])) {
            flash_errors(['password' => 'That is not your password.']);
            security_event('auth.reauth_failed', 'medium',
                ['reason' => 'wrong password while switching 2FA off'], (int) $fresh['id'], 'customer');
            redirect($back);
        }

        rate_limit_clear('mfa.disable.customer', (string) $fresh['id']);
        mfa_disable('customer', $fresh, 'self');
        flash('success', 'Two-step sign in is off. Your password is all that guards the account now.');
        redirect($back);
    }

    if ($action === 'codes') {
        if (!mfa_totp_enabled($fresh)) {
            flash('error', 'Backup codes go with an authenticator app. Set one up first.');
            redirect($back);
        }
        $password = (string) ($_POST['password'] ?? '');
        if (!rate_limit_attempt('mfa.codes.customer', (string) $fresh['id'], 10, 900)
            || $password === '' || !password_verify_app($password, (string) $fresh['password'])) {
            flash_errors(['password' => 'That is not your password.']);
            redirect($back);
        }

        $_SESSION['_mfa_new_codes'] = ['type' => 'customer', 'codes' => mfa_backup_generate('customer', (int) $fresh['id'])];
        mfa_notify('customer', $fresh, 'New two-step backup codes were issued');
        flash('success', 'Ten new backup codes. The old ones no longer work.');
        redirect($back);
    }

    if ($action === 'revoke_device') {
        mfa_device_revoke('customer', (int) $fresh['id'], (int) input('device_id', 0));
        flash('success', 'That browser will be asked for a code next time.');
        redirect($back);
    }

    if ($action === 'revoke_devices') {
        mfa_devices_revoke_all('customer', (int) $fresh['id']);
        flash('success', 'Every trusted browser has to prove it again.');
        redirect($back);
    }

    flash('error', 'Unknown action.');
    redirect($back);
}

$errors = errors_pull();

// Shown exactly once.
$newCodes = [];
if (is_array($_SESSION['_mfa_new_codes'] ?? null)
    && ($_SESSION['_mfa_new_codes']['type'] ?? '') === 'customer') {
    $newCodes = (array) $_SESSION['_mfa_new_codes']['codes'];
    unset($_SESSION['_mfa_new_codes']);
}

$mfaMethod = (string) ($user['mfa_method'] ?? 'off');
$totpOn    = mfa_totp_enabled($user);
$emailOn   = $mfaMethod === 'email';
$anyOn     = $totpOn || $emailOn;
$remaining = mfa_backup_remaining('customer', (int) $user['id']);
$devices   = mfa_devices('customer', (int) $user['id']);

$secret = '';
$qr     = '';
if (!$totpOn) {
    $secret = mfa_enrol_begin('customer', (int) $user['id']);
    $qr     = totp_qr_svg(totp_uri($secret, (string) $user['email'], mfa_issuer()), 4);
}

seo_set(['title' => 'Two-Step Sign In', 'robots' => 'noindex, nofollow']);

require INCLUDES_PATH . '/header.php';

account_layout_open('account-security', [
    'title'    => 'Two-Step Sign In',
    'subtitle' => 'A second check at sign-in, so a stolen password is not enough on its own.',
]);
?>

<?php if ($newCodes !== []): ?>
    <div class="sik-panel" style="margin-bottom:var(--sp-5);border:2px solid var(--sik-primary,#f4511e)">
        <div class="sik-panel__head">
            <h2 class="sik-panel__title">Your ten backup codes</h2>
        </div>
        <div class="sik-panel__body">
            <p style="font-size:14px;margin-bottom:var(--sp-3)">
                Shown once, now. Save them somewhere that is not the phone holding your
                authenticator app &mdash; they are how you get back in if you lose it.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:var(--sp-2)">
                <?php foreach ($newCodes as $code): ?>
                    <code style="font-size:15px;letter-spacing:1px;padding:8px 10px;text-align:center;
                          background:var(--sik-surface-2,#f3f4f6);border-radius:8px"><?= e($code) ?></code>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="sik-panel" style="margin-bottom:var(--sp-5)">
    <div class="sik-panel__head" style="display:flex;justify-content:space-between;gap:var(--sp-3);align-items:center">
        <h2 class="sik-panel__title">Two-step sign in</h2>
        <span class="sik-status <?= $anyOn ? 'sik-status--green' : 'sik-status--amber' ?>">
            <?= $anyOn ? 'On' : 'Off' ?>
        </span>
    </div>
    <div class="sik-panel__body">

        <?php if ($totpOn): ?>

            <p style="font-size:14px">
                Your authenticator app has been set up since
                <strong><?= e(format_datetime((string) $user['totp_enabled_at'])) ?></strong>.
                <?= (int) $remaining ?> of your 10 backup codes are unused.
            </p>

            <?php if ($remaining <= 2): ?>
                <div class="sik-alert sik-alert--warning" style="margin:var(--sp-3) 0">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div>You are nearly out of backup codes. Get a fresh set below.</div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e($back) ?>" style="display:grid;gap:var(--sp-3);max-width:380px;margin-top:var(--sp-4)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="codes">
                <div class="sik-field">
                    <label class="sik-label" for="codesPassword">Your password</label>
                    <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           id="codesPassword" name="password" type="password" autocomplete="current-password" required>
                    <?php if (isset($errors['password'])): ?>
                        <span class="sik-error"><?= e($errors['password']) ?></span>
                    <?php endif; ?>
                </div>
                <div><button type="submit" class="sik-btn sik-btn--outline">Get ten new backup codes</button></div>
            </form>

        <?php elseif ($emailOn): ?>

            <p style="font-size:14px">
                We email you a six-digit code each time you sign in. It lasts ten minutes
                and can be used once.
            </p>
            <p style="font-size:13.5px;color:var(--sik-muted,#6b7280);margin-top:var(--sp-2)">
                An authenticator app is stronger &mdash; it works offline and cannot be read by
                somebody who gets into your email. Switch this off below and set the app up instead
                whenever you like.
            </p>

        <?php else: ?>

            <p style="font-size:14px;margin-bottom:var(--sp-4)">
                Turn this on and signing in takes one extra step: your password, then a short code.
                Somebody who steals your password still cannot get in.
            </p>

            <h3 style="font-size:15px;margin-bottom:var(--sp-2)">With an authenticator app <span class="sik-status sik-status--green">recommended</span></h3>
            <ol style="font-size:14px;display:grid;gap:var(--sp-3);padding-left:20px;margin-bottom:var(--sp-4)">
                <li>Install Google Authenticator, Microsoft Authenticator or Authy.</li>
                <li>
                    Scan this, or type the key in:
                    <?php if ($qr !== ''): ?>
                        <div style="margin:var(--sp-2) 0;display:inline-block;padding:10px;background:#fff;border-radius:10px">
                            <?= $qr /* drawn on this server; the key is never sent anywhere */ ?>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:var(--sp-2)">
                        <code style="font-size:14px;letter-spacing:1px;word-break:break-all;padding:8px 10px;
                              background:var(--sik-surface-2,#f3f4f6);border-radius:8px;display:inline-block">
                            <?= e(totp_secret_grouped($secret)) ?>
                        </code>
                    </div>
                </li>
                <li>Enter the six digits it shows.</li>
            </ol>

            <form method="post" action="<?= e($back) ?>" style="display:grid;gap:var(--sp-3);max-width:320px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enrol">
                <div class="sik-field">
                    <label class="sik-label" for="enrolCode">Code from your app</label>
                    <input class="sik-input<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                           id="enrolCode" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                           maxlength="7" autocomplete="one-time-code" placeholder="000000" required
                           style="font-size:19px;letter-spacing:5px;text-align:center">
                    <?php if (isset($errors['code'])): ?>
                        <span class="sik-error"><?= e($errors['code']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="sik-field">
                    <label class="sik-label" for="enrolPassword">Your password</label>
                    <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           id="enrolPassword" name="password" type="password"
                           autocomplete="current-password" required>
                    <?php if (isset($errors['password'])): ?>
                        <span class="sik-error"><?= e($errors['password']) ?></span>
                    <?php else: ?>
                        <span class="sik-help">
                            Asked for so that somebody who walks up to an open browser cannot
                            put their own authenticator on your account.
                        </span>
                    <?php endif; ?>
                </div>
                <div><button type="submit" class="sik-btn sik-btn--primary">Turn on two-step sign in</button></div>
            </form>

            <?php if (mfa_email_otp_enabled()): ?>
                <div class="sik-divider" style="margin:var(--sp-5) 0"></div>
                <h3 style="font-size:15px;margin-bottom:var(--sp-2)">Or get the code by email</h3>
                <p style="font-size:13.5px;color:var(--sik-muted,#6b7280);margin-bottom:var(--sp-3)">
                    No app needed. It is a little weaker &mdash; anybody who gets into your email
                    gets the code too &mdash; but it is much better than a password on its own.
                </p>

                <form method="post" action="<?= e($back) ?>" style="margin-bottom:var(--sp-3)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_enrol_code">
                    <button type="submit" class="sik-btn sik-btn--outline">Email me a code to confirm</button>
                </form>

                <form method="post" action="<?= e($back) ?>" style="display:grid;gap:var(--sp-3);max-width:320px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enrol_email">
                    <div class="sik-field">
                        <label class="sik-label" for="emailCode">Code from that email</label>
                        <input class="sik-input" id="emailCode" name="code" type="text"
                               inputmode="numeric" pattern="[0-9]*" maxlength="7"
                               autocomplete="one-time-code" placeholder="000000"
                               style="font-size:19px;letter-spacing:5px;text-align:center">
                    </div>
                    <div class="sik-field">
                        <label class="sik-label" for="emailEnrolPassword">Your password</label>
                        <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                               id="emailEnrolPassword" name="password" type="password"
                               autocomplete="current-password" required>
                        <?php if (isset($errors['password'])): ?>
                            <span class="sik-error"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div><button type="submit" class="sik-btn sik-btn--outline">Turn on email codes</button></div>
                </form>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($anyOn): ?>
            <div class="sik-divider" style="margin:var(--sp-5) 0"></div>
            <details>
                <summary style="cursor:pointer;font-weight:600;font-size:14px">Switch two-step sign in off</summary>
                <form method="post" action="<?= e($back) ?>" style="display:grid;gap:var(--sp-3);max-width:380px;margin-top:var(--sp-3)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable">
                    <div class="sik-field">
                        <label class="sik-label" for="disablePassword">Your password</label>
                        <input class="sik-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                               id="disablePassword" name="password" type="password"
                               autocomplete="current-password" required>
                        <?php if (isset($errors['password'])): ?>
                            <span class="sik-error"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div><button type="submit" class="sik-btn sik-btn--outline">Switch it off</button></div>
                </form>
            </details>
        <?php endif; ?>
    </div>
</div>

<?php if ($devices !== []): ?>
    <div class="sik-panel">
        <div class="sik-panel__head">
            <h2 class="sik-panel__title">Browsers you have trusted</h2>
        </div>
        <div class="sik-panel__body">
            <p style="font-size:13.5px;color:var(--sik-muted,#6b7280);margin-bottom:var(--sp-3)">
                These skip the code for <?= (int) mfa_trust_days() ?> days. Revoke any you do not recognise.
            </p>
            <div style="display:grid;gap:var(--sp-2)">
                <?php foreach ($devices as $device): ?>
                    <div style="display:flex;gap:var(--sp-3);align-items:center;justify-content:space-between;flex-wrap:wrap">
                        <div style="font-size:14px">
                            <strong><?= e((string) ($device['label'] ?? 'Unknown browser')) ?></strong>
                            <?php if (mfa_device_is_current('customer', $device)): ?>
                                <span class="sik-status sik-status--green">This one</span>
                            <?php endif; ?>
                            <div style="font-size:12px;color:var(--sik-muted,#6b7280)">
                                Trusted until <?= e(format_datetime((string) $device['expires_at'])) ?>
                            </div>
                        </div>
                        <form method="post" action="<?= e($back) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="revoke_device">
                            <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                            <button type="submit" class="sik-btn sik-btn--outline sik-btn--sm">Revoke</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?= e($back) ?>" style="margin-top:var(--sp-4)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_devices">
                <button type="submit" class="sik-btn sik-btn--outline sik-btn--sm">Revoke all</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
