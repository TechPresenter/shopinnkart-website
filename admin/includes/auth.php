<?php
/**
 * ShopInnKart Admin - Authentication gate.
 *
 * Every admin page requires this file FIRST, before producing any output:
 *
 *     require_once __DIR__ . '/../includes/auth.php';   // depth varies
 *     admin_require('products.view');
 *
 * Hiding a sidebar link is presentation. admin_require() is the actual
 * authorisation check, and it runs on every page and every action.
 */

declare(strict_types=1);

// Walk up to the project root regardless of how deep the admin page sits.
$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/init.php';

require_once ADMIN_PATH . '/includes/functions.php';

/**
 * Require an authenticated admin holding a permission.
 * Redirects to the login screen, or renders 403 when signed in without rights.
 *
 * @param string $permission "module.action", or "" to only require a login.
 */
function admin_require(string $permission = ''): array
{
    $admin = admin_user();

    if ($admin === null) {
        if (is_ajax()) {
            json_error('Admin authentication required.', [], 401);
        }
        $_SESSION['_admin_intended'] = $_SERVER['REQUEST_URI'] ?? admin_url('dashboard.php');
        redirect(admin_url('login.php'));
    }

    if ($permission !== '' && !admin_can($permission)) {
        if (is_ajax()) {
            json_error('You do not have permission to perform this action.', [], 403);
        }
        admin_forbidden($permission);
    }

    return $admin;
}

/** Render the admin 403 screen and stop. */
function admin_forbidden(string $permission = ''): void
{
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require ADMIN_PATH . '/includes/header.php';
    ?>
    <div class="ad-empty" style="padding-block:80px">
        <span class="ad-empty__icon"><?= icon('lock', 'w-9 h-9') ?></span>
        <h2 class="ad-empty__title">You don&rsquo;t have access to this section</h2>
        <p class="ad-empty__text">
            Your role (<strong><?= e((string) (admin_user()['role_name'] ?? 'Unknown')) ?></strong>)
            does not include the
            <?php if ($permission !== ''): ?><code><?= e($permission) ?></code><?php endif; ?>
            permission. Ask a Super Admin to grant it.
        </p>
        <a class="ad-btn ad-btn--primary" href="<?= e(admin_url('dashboard.php')) ?>">Back to Dashboard</a>
    </div>
    <?php
    require ADMIN_PATH . '/includes/footer.php';
    exit;
}

/**
 * Guard a destructive action: POST + CSRF + permission.
 * Call at the top of any delete/bulk endpoint.
 */
function admin_require_action(string $permission): array
{
    $admin = admin_require($permission);

    if (!is_post()) {
        if (is_ajax()) {
            json_error('This action requires a POST request.', [], 405);
        }
        flash('error', 'That action must be submitted from its form.');
        redirect_back(admin_url('dashboard.php'));
    }

    csrf_require();

    return $admin;
}

/** Where to send an admin after signing in. */
function admin_intended_url(string $fallback = 'dashboard.php'): string
{
    $intended = $_SESSION['_admin_intended'] ?? null;
    unset($_SESSION['_admin_intended']);

    if (is_string($intended) && $intended !== '' && strpos($intended, '//') !== 0
        && strpos($intended, 'login.php') === false) {
        return $intended;
    }
    return admin_url($fallback);
}
