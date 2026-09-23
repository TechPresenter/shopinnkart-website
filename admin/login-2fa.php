<?php
/**
 * ShopInnKart Admin - Second step of signing in.
 *
 * Reached only from admin/login.php, and only after a correct password. The
 * browser arriving here is NOT signed in: $_SESSION holds a five-minute
 * _mfa_pending record naming the account and nothing else, so every other
 * admin page still treats it as a stranger. See includes/mfa.php.
 *
 * Two stages share the screen:
 *   verify - the account has a second factor; prove it
 *   enrol  - the account's role requires one and it has none; set it up now
 *
 * The enrol stage must never be a dead end. It is reachable without a session,
 * it shows the secret as text beside the QR code (a server with no camera in
 * the room still has to be able to set this up), and it hands over ten backup
 * codes before it lets go. That, plus "a Super Admin cannot require 2FA of
 * anybody until they are using it themselves", is what stops the requirement
 * locking the whole panel - see admin/security/cards/50-two-factor.php.
 *
 * Standalone layout for the same reason admin/login.php has one: this page
 * renders before any authentication exists.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (admin_is_logged_in()) {
    redirect(admin_url('dashboard.php'));
}

// The hidden login address covers this screen too: without it, /admin/ answers
// like any missing page, and that has to include the second step.
if (!admin_gate_passed()) {
    admin_gate_deny();
}

$pending = mfa_pending('admin');
if ($pending === null) {
    flash('error', 'That sign-in timed out. Please start again.');
    redirect(admin_url('login.php'));
}

$admin = mfa_pending_account($pending);
if ($admin === null) {
    // Deactivated, deleted, or the password changed between the two halves.
    mfa_pending_clear();
    flash('error', 'That sign-in is no longer valid. Please start again.');
    redirect(admin_url('login.php'));
}

$stage   = (string) $pending['stage'];
$methods = (array) $pending['methods'];
$error   = '';
$notice  = '';
$method  = in_array((string) input('method', ''), ['totp', 'backup'], true)
    ? (string) input('method', '')
    : 'totp';

/** Everything that happens once the second factor is satisfied. */
$completeSignIn = static function (array $admin, string $usedMethod): void {
    if (input_bool('trust_device') && mfa_trust_enabled()) {
        mfa_device_trust('admin', (int) $admin['id']);
    }

    login_admin($admin, $usedMethod);
    log_activity('admin.login', 'admin', (int) $admin['id'], $admin['name'] . ' signed in');
    security_event('auth.admin_login', 'info', ['mfa' => $usedMethod], (int) $admin['id'], 'admin');
    flash('success', 'Welcome back, ' . $admin['name'] . '.');
    redirect(admin_intended_url());
};

if (is_post()) {
    csrf_require();
    $action = (string) input('action', 'verify');

    if ($action === 'cancel') {
        mfa_pending_clear();
        mfa_enrol_forget('admin');
        redirect(admin_url('login.php'));
    }

    if ($action === 'enrol' && $stage === 'enrol') {
        $result = mfa_enrol_complete('admin', $admin, (string) input('code', ''));

        if ($result['ok']) {
            // Shown once, on the next screen, and then gone from the session.
            $_SESSION['_mfa_new_codes'] = ['type' => 'admin', 'codes' => $result['codes']];
            // A PATH, not a URL: admin_intended_url() passes it through
            // auth_safe_intended_path(), which refuses anything that is not a
            // path on this origin - an absolute URL would silently fall back
            // to the dashboard and the backup codes would never be seen.
            $_SESSION['_admin_intended'] = (string) parse_url(admin_url('account/index.php'), PHP_URL_PATH);
            $completeSignIn($admin, 'totp');
        }

        $error = (string) $result['error'];
    } elseif ($action === 'verify' && $stage === 'verify') {
        $method = in_array((string) input('method', ''), $methods, true) ? (string) input('method', '') : 'totp';
        $result = mfa_challenge_verify($pending, $method, (string) input('code', ''));

        if ($result['ok']) {
            $completeSignIn($admin, $result['method']);
        }

        if ($result['cancelled']) {
            flash('error', (string) $result['error']);
            redirect(admin_url('login.php'));
        }

        $error = (string) $result['error'];
        // tries was incremented inside the verifier.
        $pending = mfa_pending('admin') ?? $pending;
    }
}

// Enrolment material. mfa_enrol_begin() holds the secret in the session, so a
// wrong code re-renders the SAME QR rather than invalidating the one that was
// just scanned.
$secret = '';
$uri    = '';
$qr     = '';
if ($stage === 'enrol') {
    $secret = mfa_enrol_begin('admin', (int) $admin['id']);
    $uri    = totp_uri($secret, (string) $admin['email'], mfa_issuer());
    $qr     = totp_qr_svg($uri);
}

$storeName = (string) setting('store_name', SITE_NAME);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $stage === 'enrol' ? 'Set Up Two-Step Sign In' : 'Two-Step Sign In' ?> &middot; <?= e($storeName) ?></title>
    <?= brand_favicon_links() ?>
    <link rel="stylesheet" href="<?= e(asset('css/tailwind.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="ad-login">

    <div class="ad-login__card" style="max-width:<?= $stage === 'enrol' ? '520' : '420' ?>px">
        <img class="ad-login__logo" src="<?= e(brand_logo_src()) ?>"
             alt="<?= e($storeName) ?>" width="200" height="42">

        <?php if ($stage === 'enrol'): ?>

            <h1 style="font-size:19px;text-align:center;margin-bottom:5px">Set up two-step sign in</h1>
            <p style="text-align:center;color:var(--ad-muted);font-size:13.5px;margin-bottom:20px">
                Your role requires it, so this is the last step before you can carry on.
            </p>

            <?php if ($error !== ''): ?>
                <div class="sik-alert sik-alert--error">
                    <?= icon('alert', 'w-5 h-5') ?><div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <ol style="font-size:13.5px;display:grid;gap:14px;margin:0 0 18px 0;padding-left:20px">
                <li>
                    Install an authenticator app &mdash; Google Authenticator, Microsoft Authenticator,
                    Authy or 1Password all work.
                </li>
                <li>
                    Add this account to it:
                    <?php if ($qr !== ''): ?>
                        <div style="margin:10px 0;display:flex;justify-content:center">
                            <div style="padding:10px;background:#fff;border-radius:10px;display:inline-block">
                                <?= $qr /* locally generated SVG, no user input in it */ ?>
                            </div>
                        </div>
                        <div style="text-align:center;font-size:12.5px;color:var(--ad-muted);margin-bottom:8px">
                            Cannot scan? Type this key in instead:
                        </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <code class="ad-mono" id="mfaSecret"
                              style="flex:1 1 220px;font-size:14px;letter-spacing:1px;word-break:break-all;
                                     padding:8px 10px;background:var(--ad-surface-2, #f3f4f6);border-radius:8px">
                            <?= e(totp_secret_grouped($secret)) ?>
                        </code>
                        <button type="button" class="ad-btn ad-btn--sm" data-copy="<?= e_attr($secret) ?>">
                            <?= icon('copy', 'w-4 h-4') ?> Copy
                        </button>
                    </div>
                </li>
                <li>Enter the six digits it shows.</li>
            </ol>

            <form method="post" action="<?= e(admin_url('login-2fa.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enrol">

                <div class="sik-field">
                    <label class="sik-label" for="code">Code from your app</label>
                    <input class="sik-input ad-mono<?= $error !== '' ? ' is-invalid' : '' ?>"
                           type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*"
                           maxlength="7" autocomplete="one-time-code" autofocus required
                           style="font-size:20px;letter-spacing:6px;text-align:center" placeholder="000000">
                </div>

                <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" style="margin-top:6px">
                    <?= icon('lock', 'w-4 h-4') ?> Turn it on and sign in
                </button>
            </form>

            <p style="font-size:12.5px;color:var(--ad-muted);margin-top:16px">
                You will get ten single-use backup codes on the next screen. Save them somewhere
                that is not your phone &mdash; they are how you get back in if you lose it.
            </p>

        <?php else: ?>

            <h1 style="font-size:19px;text-align:center;margin-bottom:5px">One more step</h1>
            <p style="text-align:center;color:var(--ad-muted);font-size:13.5px;margin-bottom:22px">
                Signing in as <strong><?= e((string) $admin['email']) ?></strong>.
            </p>

            <?php if ($error !== ''): ?>
                <div class="sik-alert sik-alert--error">
                    <?= icon('alert', 'w-5 h-5') ?><div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(admin_url('login-2fa.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="method" value="<?= e_attr($method) ?>">

                <div class="sik-field">
                    <label class="sik-label" for="code">
                        <?= $method === 'backup' ? 'Backup code' : 'Code from your authenticator app' ?>
                    </label>
                    <input class="sik-input ad-mono<?= $error !== '' ? ' is-invalid' : '' ?>"
                           type="text" id="code" name="code"
                           <?= $method === 'backup' ? 'maxlength="13"' : 'inputmode="numeric" pattern="[0-9]*" maxlength="7"' ?>
                           autocomplete="one-time-code" autofocus required
                           style="font-size:<?= $method === 'backup' ? '17' : '20' ?>px;letter-spacing:<?= $method === 'backup' ? '2' : '6' ?>px;text-align:center"
                           placeholder="<?= $method === 'backup' ? 'XXXXX-XXXXX' : '000000' ?>">
                    <span class="sik-help">
                        <?= $method === 'backup'
                            ? 'One of the ten codes you saved when you set this up. Each works once.'
                            : 'The six digits change every 30 seconds.' ?>
                    </span>
                </div>

                <?php if (mfa_trust_enabled()): ?>
                    <label class="sik-check" style="margin-bottom:14px">
                        <input type="checkbox" name="trust_device" value="1">
                        <span>Trust this browser for <?= (int) mfa_trust_days() ?> days</span>
                    </label>
                <?php endif; ?>

                <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                    <?= icon('lock', 'w-4 h-4') ?> Verify and sign in
                </button>
            </form>

            <div style="display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-top:18px;font-size:12.5px">
                <?php if (in_array('backup', $methods, true)): ?>
                    <a href="<?= e(admin_url('login-2fa.php?method=' . ($method === 'backup' ? 'totp' : 'backup'))) ?>"
                       style="color:var(--ad-primary);font-weight:600">
                        <?= $method === 'backup' ? 'Use my authenticator app' : 'Use a backup code instead' ?>
                    </a>
                <?php endif; ?>
                <form method="post" action="<?= e(admin_url('login-2fa.php')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="ad-linkbtn"
                            style="background:none;border:0;padding:0;color:var(--ad-muted);cursor:pointer;font-size:12.5px">
                        Cancel
                    </button>
                </form>
            </div>

            <p style="font-size:12px;color:var(--ad-muted);margin-top:18px;text-align:center">
                Lost your phone and your backup codes? Another Super Admin can reset this for you
                from Security Settings.
            </p>

        <?php endif; ?>
    </div>

    <script>
        // Same two-line copy handler admin.js provides, inlined because that
        // bundle is not loaded on the sign-in screens.
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
                    var was = button.innerHTML;
                    button.textContent = 'Copied';
                    setTimeout(function () { button.innerHTML = was; }, 1500);
                });
            });
        });
    </script>
</body>
</html>
