<?php
/**
 * ShopInnKart - Second step of signing in (customers).
 *
 * Reached only from login.php or /api/auth/login.php, and only after a correct
 * password. The browser here is NOT signed in: the session holds a
 * five-minute _mfa_pending record naming the account and nothing else, so
 * require_login() still treats it as a stranger. See includes/mfa.php.
 *
 * Deliberately a plain form post, not the AJAX path the rest of the account
 * area uses: finishing a sign-in must not depend on JavaScript.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

if (is_logged_in()) {
    redirect(url('account.php'));
}

$pending = mfa_pending('customer');
if ($pending === null) {
    flash('info', 'That sign-in timed out. Please start again.');
    redirect(url('login.php'));
}

$user = mfa_pending_account($pending);
if ($user === null) {
    mfa_pending_clear();
    flash('error', 'That sign-in is no longer valid. Please start again.');
    redirect(url('login.php'));
}

$methods = (array) $pending['methods'];
$method  = (string) input('method', '');
if (!in_array($method, $methods, true)) {
    // Strongest first: the app, then a backup code, then the emailed code.
    $method = $methods[0] ?? 'totp';
}

$formError = '';
$notice    = '';
$sent      = !empty($_SESSION['_mfa_otp_sent']);

if (is_post()) {
    csrf_require();
    $action = (string) input('action', 'verify');
    $method = in_array((string) input('method', ''), $methods, true) ? (string) input('method', '') : $method;

    if ($action === 'cancel') {
        mfa_pending_clear();
        unset($_SESSION['_mfa_otp_sent']);
        redirect(url('login.php'));
    }

    if ($action === 'send_code') {
        if (!in_array('email', $methods, true)) {
            $formError = 'That option is not available on this account.';
        } else {
            $result = mfa_otp_send('customer', $user);
            if ($result['ok']) {
                $_SESSION['_mfa_otp_sent'] = time();
                $sent = true;
                $method = 'email';
                // Deliberately vague about the address: the screen is reached
                // with a correct password, but the address still is not echoed
                // back to whoever is holding it.
                $notice = 'We have sent a six-digit code to the email address on this account.';
            } else {
                $formError = (string) $result['error'];
            }
        }
    } elseif ($action === 'verify') {
        $result = mfa_challenge_verify($pending, $method, (string) input('code', ''));

        if ($result['ok']) {
            if (input_bool('trust_device') && mfa_trust_enabled()) {
                mfa_device_trust('customer', (int) $user['id']);
            }
            unset($_SESSION['_mfa_otp_sent']);

            // The "keep me signed in" box was ticked on the password screen and
            // carried through the challenge, so it is honoured here - but only
            // now that the second factor has actually been proved.
            login_user($user, !empty($pending['remember']), $result['method']);
            flash('success', 'Welcome back, ' . trim((string) $user['first_name']) . '.');
            redirect(intended_url());
        }

        if ($result['cancelled']) {
            unset($_SESSION['_mfa_otp_sent']);
            flash('error', (string) $result['error']);
            redirect(url('login.php'));
        }

        $formError = (string) $result['error'];
        $pending = mfa_pending('customer') ?? $pending;
    }
}

$storeName = (string) setting('store_name', SITE_NAME);

$labels = [
    'totp'   => 'Code from your authenticator app',
    'backup' => 'Backup code',
    'email'  => 'Code from your email',
];

auth_layout_start([
    'title'       => 'Two-Step Sign In',
    'description' => 'Confirm it is you.',
    'heading'     => 'One more step',
    'subheading'  => 'Your account asks for a second check before it opens.',
]);
?>

<?php if ($formError !== ''): ?>
    <?= auth_alert('error', $formError) ?>
<?php endif; ?>
<?php if ($notice !== ''): ?>
    <?= auth_alert('success', $notice) ?>
<?php endif; ?>

<?php if ($method === 'email' && !$sent): ?>

    <p style="font-size:14px;margin-bottom:var(--sp-4)">
        We will email a six-digit code to the address on your account. It lasts ten minutes
        and can be used once.
    </p>

    <form method="post" action="<?= e(url('login-2fa.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_code">
        <input type="hidden" name="method" value="email">
        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <span class="sik-btn__label">Email me a code</span>
        </button>
    </form>

<?php else: ?>

    <form method="post" action="<?= e(url('login-2fa.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="method" value="<?= e_attr($method) ?>">

        <div class="sik-field">
            <label class="sik-label" for="sikMfaCode"><?= e($labels[$method] ?? 'Code') ?></label>
            <input class="sik-input" type="text" id="sikMfaCode" name="code"
                   <?= $method === 'backup' ? 'maxlength="13"' : 'inputmode="numeric" pattern="[0-9]*" maxlength="7"' ?>
                   autocomplete="one-time-code" autofocus required
                   placeholder="<?= $method === 'backup' ? 'XXXXX-XXXXX' : '000000' ?>"
                   style="font-size:<?= $method === 'backup' ? '17' : '20' ?>px;letter-spacing:<?= $method === 'backup' ? '2' : '6' ?>px;text-align:center">
            <span class="sik-help">
                <?php if ($method === 'backup'): ?>
                    One of the ten codes you saved when you switched this on. Each works once.
                <?php elseif ($method === 'email'): ?>
                    Check your inbox, and the spam folder. The code expires in ten minutes.
                <?php else: ?>
                    The six digits change every 30 seconds.
                <?php endif; ?>
            </span>
        </div>

        <?php if (mfa_trust_enabled()): ?>
            <label class="sik-check" style="margin-bottom:var(--sp-5)">
                <input type="checkbox" name="trust_device" value="1">
                <span>Don't ask on this browser for <?= (int) mfa_trust_days() ?> days</span>
            </label>
        <?php endif; ?>

        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <span class="sik-btn__label">Verify and sign in</span>
        </button>
    </form>

<?php endif; ?>

<div style="display:grid;gap:var(--sp-2);margin-top:var(--sp-4);font-size:13px;text-align:center">
    <?php foreach ($methods as $option): ?>
        <?php if ($option !== $method): ?>
            <a href="<?= e(url('login-2fa.php?method=' . $option)) ?>">
                <?= $option === 'email' ? 'Email me a code instead'
                    : ($option === 'backup' ? 'Use a backup code instead' : 'Use my authenticator app instead') ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($method === 'email' && $sent): ?>
        <form method="post" action="<?= e(url('login-2fa.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <input type="hidden" name="method" value="email">
            <button type="submit" class="sik-linkbtn"
                    style="background:none;border:0;padding:0;color:var(--sik-primary,inherit);cursor:pointer;font-size:13px">
                Send another code
            </button>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login-2fa.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" style="background:none;border:0;padding:0;color:var(--sik-muted,#6b7280);cursor:pointer;font-size:13px">
            Cancel and start again
        </button>
    </form>
</div>

<?php
auth_layout_end();
