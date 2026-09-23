<?php
/**
 * ShopInnKart Admin - Sign out.
 *
 * POST + CSRF only. A GET would let any page on the internet end an admin's
 * session with a stray <img src="…/admin/logout.php">.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_post()) {
    // A stranger probing /admin/logout.php must not be bounced to a named admin
    // page when the login address is hidden.
    if (!admin_is_logged_in() && !admin_gate_passed()) {
        admin_gate_deny();
    }
    redirect(admin_url('dashboard.php'));
}

csrf_require();

logout_admin();

flash('success', 'You have been signed out.');
redirect(admin_url('login.php'));
