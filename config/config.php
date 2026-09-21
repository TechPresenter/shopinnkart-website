<?php
/**
 * ShopInnKart - Core Application Configuration
 * shopinnkart.com
 *
 * Environment driven. Never commit production credentials.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------
// 'development' shows detailed errors on screen. 'production' hides them.
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', APP_ENV === 'development');

// ---------------------------------------------------------------------------
// Database
//
// Precedence: environment variables > config/db.local.php (written by the
// installer) > the development defaults below. Keep db.local.php out of
// version control and out of the web root's reach (see .htaccess).
// ---------------------------------------------------------------------------
$localDb = [];
if (is_file(__DIR__ . '/db.local.php')) {
    $loaded = require __DIR__ . '/db.local.php';
    if (is_array($loaded)) {
        $localDb = $loaded;
    }
}

define('DB_HOST', getenv('DB_HOST') ?: ($localDb['host'] ?? 'localhost'));
define('DB_PORT', getenv('DB_PORT') ?: ($localDb['port'] ?? '3306'));
define('DB_NAME', getenv('DB_NAME') ?: ($localDb['name'] ?? 'shopinnkart'));
define('DB_USER', getenv('DB_USER') ?: ($localDb['user'] ?? 'root'));
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($localDb['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

unset($localDb, $loaded);

// ---------------------------------------------------------------------------
// Site
// ---------------------------------------------------------------------------
define('SITE_NAME', 'ShopInnKart');
define('SITE_TAGLINE', 'Shop Smart. Live Better.');
define('SITE_DOMAIN', 'shopinnkart.com');

/**
 * Base URL is auto-detected so the project runs from any folder
 * (e.g. http://localhost/ecomweb) without editing config.
 */
if (!defined('SITE_URL')) {
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        $scheme = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? SITE_DOMAIN;

    // Project root = the folder containing this config's parent directory.
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $basePath = '';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $basePath = rtrim(substr($appRoot, strlen($docRoot)), '/');
    }

    define('BASE_PATH', $basePath);
    define('SITE_URL', $scheme . '://' . $host . $basePath);
}

// ---------------------------------------------------------------------------
// Filesystem paths
// ---------------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');

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
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

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
