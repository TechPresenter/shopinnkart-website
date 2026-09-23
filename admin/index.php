<?php
/**
 * ShopInnKart Admin - Entry point.
 * Sends the visitor to the dashboard or the login screen.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

if (!admin_is_logged_in() && !admin_gate_passed()) {
    admin_gate_deny();   // hidden login address in use: /admin/ is "not found"
}

redirect(admin_is_logged_in() ? admin_url('dashboard.php') : admin_url('login.php'));
