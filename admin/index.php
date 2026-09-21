<?php
/**
 * ShopInnKart Admin - Entry point.
 * Sends the visitor to the dashboard or the login screen.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

redirect(admin_is_logged_in() ? admin_url('dashboard.php') : admin_url('login.php'));
