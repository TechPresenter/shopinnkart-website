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
    redirect(admin_url('dashboard.php'));
}

csrf_require();

logout_admin();

flash('success', 'You have been signed out.');
redirect(admin_url('login.php'));
