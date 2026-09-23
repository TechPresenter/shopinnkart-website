<?php
/**
 * ShopInnKart - Core Application Configuration
 * shopinnkart.com
 *
 * Environment driven. Never commit production credentials.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Local overrides: config/db.local.php (written by the installer, git-ignored,
// blocked from the web by .htaccess). Environment variables still win.
// ---------------------------------------------------------------------------
$localDb = [];
if (is_file(__DIR__ . '/db.local.php')) {
    $loaded = require __DIR__ . '/db.local.php';
    if (is_array($loaded)) {
        $localDb = $loaded;
    }
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------
// 'development' shows detailed errors on screen (and the default admin login
// hint). 'production' hides them. Production is the default, so a server that
// is missing its settings fails closed instead of printing stack traces and
// server paths to visitors; a developer machine opts in with
// 'env' => 'development' in db.local.php or APP_ENV=development.
define('APP_ENV', getenv('APP_ENV') ?: (($localDb['env'] ?? '') === 'development' ? 'development' : 'production'));
define('APP_DEBUG', APP_ENV === 'development');

// ---------------------------------------------------------------------------
// Database
//
// Precedence: environment variables > config/db.local.php > the development
// defaults below.
// ---------------------------------------------------------------------------

define('DB_HOST', getenv('DB_HOST') ?: ($localDb['host'] ?? 'localhost'));
define('DB_PORT', getenv('DB_PORT') ?: ($localDb['port'] ?? '3306'));
define('DB_NAME', getenv('DB_NAME') ?: ($localDb['name'] ?? 'shopinnkart'));
define('DB_USER', getenv('DB_USER') ?: ($localDb['user'] ?? 'root'));
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($localDb['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

// Deployment-fixed site address, if the host has one. Set it on production:
// 'url' => 'https://shopinnkart.com' in config/db.local.php, or APP_URL in the
// environment. Anything set here is never overridden by the request.
$localSiteUrl = (string) (getenv('APP_URL') ?: (getenv('SITE_URL') ?: ($localDb['url'] ?? '')));

unset($localDb, $loaded);

// ---------------------------------------------------------------------------
// Filesystem paths
//
// Defined before the site URL: the Host allow-list keeps a small mirror of its
// database settings under storage/, because this file runs before the database.
// ---------------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');

// ---------------------------------------------------------------------------
// Site
// ---------------------------------------------------------------------------
define('SITE_NAME', 'ShopInnKart');
define('SITE_TAGLINE', 'Shop Smart. Live Better.');
define('SITE_DOMAIN', 'shopinnkart.com');

/**
 * Base URL.
 *
 * Still auto-detected so the project runs from any folder (e.g.
 * http://localhost/ecomweb) without editing config - but only from a Host we
 * recognise. A stranger's "Host: evil.example" used to end up inside the
 * password-reset link of a genuine store email; now an unknown Host falls back
 * to the canonical address instead of deciding one.
 *
 * See includes/request-trust.php for the allow-list and the resolution order.
 */
require_once ROOT_PATH . '/includes/request-trust.php';

if (!defined('SITE_URL')) {
    $siteUrl = site_url_resolve([
        'configured' => $localSiteUrl,
        'server'     => $_SERVER,
        'app_root'   => dirname(__DIR__),
        'domain'     => SITE_DOMAIN,
        'dev'        => APP_DEBUG,
    ]);

    define('BASE_PATH', $siteUrl['base_path']);
    define('SITE_URL', $siteUrl['url']);
    // The address emailed links are built from, '' when none is configured.
    define('SITE_URL_CANONICAL', $siteUrl['canonical']);
    // false = this request's Host header is not one of ours.
    define('SITE_URL_HOST_OK', $siteUrl['host_ok']);

    unset($siteUrl);
}
unset($localSiteUrl);

// ---------------------------------------------------------------------------
// Public URLs
// ---------------------------------------------------------------------------
define('ASSET_URL', SITE_URL . '/assets');
define('UPLOAD_URL', SITE_URL . '/uploads');
define('ADMIN_URL', SITE_URL . '/admin');
define('API_URL', SITE_URL . '/api');

// ---------------------------------------------------------------------------
// Locale / currency
// ---------------------------------------------------------------------------
define('CURRENCY', 'INR');
define('CURRENCY_SYMBOL', '₹');
define('TIMEZONE', 'Asia/Kolkata');
define('LOCALE', 'en_IN');

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
define('SESSION_NAME', 'SIK_SESSION');
define('SESSION_LIFETIME', 60 * 60 * 24 * 7); // 7 days
define('ADMIN_SESSION_KEY', 'sik_admin');
define('USER_SESSION_KEY', 'sik_user');

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_HEADER_NAME', 'X-CSRF-Token');
// Floor for the password policy. The live minimum is the sec_password_min
// setting (Admin > Security), which can only be raised above this, never below.
define('PASSWORD_MIN_LENGTH', 10);
// A passphrase this long is accepted without the letter+digit rule.
define('PASSWORD_PASSPHRASE_LENGTH', 16);
// bcrypt silently ignores everything past 72 bytes, and hashing a megabyte of
// input is a free CPU sink, so every password form stops well before both.
define('PASSWORD_MAX_LENGTH', 128);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
// Cookie that carries a "keep me signed in" token. Deliberately NOT the
// session cookie: see auth_remember_issue() in includes/auth.php.
define('REMEMBER_COOKIE_NAME', 'SIK_REMEMBER');

// ---------------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------------
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg']);
define('ALLOWED_IMAGE_MIMES', [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
]);

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------
define('PRODUCTS_PER_PAGE', 12);
define('ADMIN_PER_PAGE', 20);
define('MAX_COMPARE_ITEMS', 4);

// ---------------------------------------------------------------------------
// Order numbering
// ---------------------------------------------------------------------------
define('ORDER_PREFIX', 'SIK');
define('INVOICE_PREFIX', 'INV');

// ---------------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------------
date_default_timezone_set(TIMEZONE);
mb_internal_encoding('UTF-8');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php-error.log');
