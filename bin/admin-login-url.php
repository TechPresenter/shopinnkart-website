<?php
/**
 * ShopInnKart - Recover the hidden admin login address (CLI only).
 *
 *   php bin/admin-login-url.php            print the secret login address
 *   php bin/admin-login-url.php --disable  make the login public again (/admin/)
 *
 * The way back in for an owner who lost the bookmark. It needs shell access to
 * the server, which is the point: anyone with that already owns the store.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/init.php';

if (in_array('--disable', $argv, true)) {
    setting_save('admin_login_slug', '', 'security', 'text');
    cache_bust();
    log_activity('security.admin_login_address', 'settings', null, 'Made the admin login public again (from the command line)');
    echo "The admin login is public again: " . admin_url('login.php') . PHP_EOL;
    exit(0);
}

$slug = admin_gate_slug();
if ($slug === '') {
    echo "No hidden address is set. The admin login is at " . admin_url('login.php') . PHP_EOL;
    exit(0);
}

// From the command line the host is unknown, so SITE_URL falls back to the
// configured domain. Print the path too, for when that is not the real one.
echo "Admin login address: " . admin_gate_url() . PHP_EOL;
echo "Path on your domain: /" . ltrim(BASE_PATH . '/' . $slug, '/') . PHP_EOL;
