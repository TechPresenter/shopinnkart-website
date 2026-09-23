<?php
/**
 * ShopInnKart Admin - Sign in.
 *
 * Standalone layout: this page must render before any authentication exists,
 * so it does not use includes/header.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
// admin_intended_url() lives here. Without it a successful sign-in fatals on
// the redirect — the session was established, so the operator saw a 500 and
// then found themselves logged in anyway. The file only declares functions,
// so including it before authentication exists is safe.
require_once __DIR__ . '/includes/auth.php';

// Already signed in? Go straight through.
if (admin_is_logged_in()) {
    redirect(admin_url('dashboard.php'));
}

// With a hidden login address configured, this screen only exists for a browser
// that came in through it - GET and POST alike, so the form cannot be
// brute-forced by anyone who merely guessed /admin/login.php.
if (!admin_gate_passed()) {
    admin_gate_deny();
}

// A password was already accepted and the second factor is outstanding. Sending
// them back to the password form would look like the password was wrong.
if (mfa_pending('admin') !== null) {
    redirect(admin_url('login-2fa.php'));
}

$errors = [];
$identifier = '';

if (is_post()) {
    csrf_require();

    $identifier = trim((string) input('identifier', ''));
    $password = (string) input('password', '');

    $validator = new Validator(
        ['identifier' => $identifier, 'password' => $password],
        ['identifier' => 'Email or username']
    );
    $validator->required('identifier')->required('password');

    if ($validator->fails()) {
        $errors = $validator->errors();
    } else {
        // Throttling (per IP, per IP+account, atomic) lives inside
        // attempt_admin_login, so the form and any other caller share it.
        $attempt = attempt_admin_login($identifier, $password);

        if ($attempt['ok']) {
            // The password is only the first half. mfa_sign_in() decides
            // whether there is a second one, and if so parks the browser in a
            // half-authenticated state - no admin session is written until
            // admin/login-2fa.php is satisfied. A row WITH its role
            // permissions is needed, because the requirement can come from the
            // role; attempt_admin_login() returns the bare admins row.
            $row  = mfa_admin_row((int) $attempt['admin']['id']) ?? $attempt['admin'];
            $gate = mfa_sign_in('admin', $row);

            if ($gate['stage'] !== 'complete') {
                redirect($gate['url']);
            }

            login_admin($row, $gate['method']);
            log_activity('admin.login', 'admin', (int) $row['id'], $row['name'] . ' signed in');
            // An admin signing in is worth a line in the security log too:
            // activity_logs record what admins did, this records who got in.
            security_event('auth.admin_login', 'info', ['mfa' => $gate['method']], (int) $row['id'], 'admin');
            flash('success', 'Welcome back, ' . $row['name'] . '.');
            redirect(admin_intended_url());
        }

        $errors['password'] = $attempt['error'] ?? 'Invalid credentials.';
    }
}

$storeName = (string) setting('store_name', SITE_NAME);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Sign In &middot; <?= e($storeName) ?></title>
    <?= brand_favicon_links() ?>
    <link rel="stylesheet" href="<?= e(asset('css/tailwind.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="ad-login">

    <div class="ad-login__card">
        <a href="<?= e(url()) ?>">
            <img class="ad-login__logo"
                 src="<?= e(brand_logo_src()) ?>"
                 alt="<?= e($storeName) ?>" width="200" height="42">
        </a>

        <h1 style="font-size:19px;text-align:center;margin-bottom:5px">Admin Sign In</h1>
        <p style="text-align:center;color:var(--ad-muted);font-size:13.5px;margin-bottom:24px">
            Sign in to manage your store.
        </p>

        <?php if (isset($errors['password']) && !isset($errors['identifier'])): ?>
            <div class="sik-alert sik-alert--error">
                <?= icon('alert', 'w-5 h-5') ?>
                <div><?= e($errors['password']) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(admin_url('login.php')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="sik-field">
                <label class="sik-label" for="identifier">Email or Username</label>
                <input class="sik-input <?= isset($errors['identifier']) ? 'is-invalid' : '' ?>"
                       type="text" id="identifier" name="identifier"
                       value="<?= e($identifier) ?>" autocomplete="username"
                       autofocus required>
                <?php if (isset($errors['identifier'])): ?>
                    <span class="sik-error"><?= e($errors['identifier']) ?></span>
                <?php endif; ?>
            </div>

            <div class="sik-field">
                <label class="sik-label" for="password">Password</label>
                <div style="position:relative">
                    <input class="sik-input <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           type="password" id="password" name="password"
                           autocomplete="current-password" required style="padding-right:44px">
                    <button type="button" class="ad-iconbtn" data-toggle-password="#password"
                            style="position:absolute;right:3px;top:50%;transform:translateY(-50%);width:36px;height:36px"
                            aria-label="Show password">
                        <?= icon('eye', 'w-4 h-4') ?>
                    </button>
                </div>
            </div>

            <button type="submit" class="ad-btn ad-btn--primary ad-btn--block" style="margin-top:6px">
                <?= icon('lock', 'w-4 h-4') ?> Sign In
            </button>
        </form>

        <p style="text-align:center;font-size:12.5px;color:var(--ad-muted);margin-top:22px">
            <a href="<?= e(url()) ?>" style="color:var(--ad-primary);font-weight:600">&larr; Back to storefront</a>
        </p>
    </div>

    <script>
        // Minimal inline handler so the toggle works without loading app.js here.
        document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.querySelector(button.dataset.togglePassword);
                if (!input) return;
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            });
        });
    </script>
</body>
</html>
