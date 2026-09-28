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
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Sign In &middot; <?= e($storeName) ?></title>
    <?= brand_favicon_links() ?>
    <?php
    /* The same two preloads admin/includes/header.php prints, for the same
       reason: app.css declares Plus Jakarta Sans with font-display: optional,
       which means an uncached font is not used AT ALL for that page load
       rather than swapped in late. This page builds its own <head>, so it was
       the one screen in the admin still rendering in the fallback.

       asset() is deliberately not used - it appends ?v=<filemtime>, and
       app.css asks for these files with no query, so a preload of a different
       URL is a second download rather than a head start. crossorigin is
       required even same-origin: a font is fetched in CORS mode, and a
       preload without it is discarded and fetched twice. */
    foreach (['plus-jakarta-sans-latin.woff2', 'plus-jakarta-sans-latin-ext.woff2'] as $adFontFile): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="<?= e(ASSET_URL . '/fonts/' . $adFontFile) ?>">
    <?php endforeach; ?>
    <?php // utilities.css, not tailwind.css: see admin/includes/header.php. ?>
    <link rel="stylesheet" href="<?= e(asset('css/utilities.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <?php
    /* The same two INPUT tokens the signed-in layout prints. Without them the
       sign-in page fell back to admin.css's defaults while every page behind
       it used the operator's colours - so the store's own admin colour only
       appeared AFTER you were through the door. */
    ?>
    <style>
        :root {
            --ad-primary: <?= e(setting('admin_primary', '#D8402A')) ?>;
            --ad-sidebar: <?= e(setting('admin_sidebar_bg', '#4A1206')) ?>;
        }
    </style>
</head>
<body class="ad-login">

<main>
    <div class="ad-login__card ad-login__card--split">

        <?php /* Decoration, and it says so: the panel is display:none below
                 900px and everything inside it is hidden from assistive
                 technology, so nothing here is a thing anybody needs. The
                 store sells decorative lighting, which is why the one moving
                 element on the page is a string of lights rather than a
                 spinner. */ ?>
        <aside class="ad-login__brand" aria-hidden="true">
            <div>
                <h2><?= e($storeName) ?></h2>
                <p>Everything behind the shop front.</p>
                <ul class="ad-login__points">
                    <li>Orders, shipments and returns</li>
                    <li>Catalogue, stock and pricing</li>
                    <li>Customers, offers and content</li>
                </ul>
            </div>
            <ul class="ad-login__lights">
                <li></li><li></li><li></li><li></li><li></li><li></li>
            </ul>
        </aside>

        <div class="ad-login__panel">
            <div class="ad-login__form">
                <a href="<?= e(url()) ?>">
                    <img class="ad-login__logo"
                         src="<?= e(brand_logo_src()) ?>"
                         alt="<?= e($storeName) ?>" width="200" height="42">
                </a>

                <h1 class="ad-login__title">Admin Sign In</h1>
                <p class="ad-login__sub">Sign in to manage your store.</p>

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

                <p class="ad-login__foot">
                    <a href="<?= e(url()) ?>" style="color:var(--ad-primary);font-weight:600">&larr; Back to storefront</a>
                </p>
            </div>
        </div>
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
</main>
</body>
</html>
