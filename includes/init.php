<?php
/**
 * ShopInnKart - Application Bootstrap
 *
 * Every frontend page, API endpoint and admin screen starts here.
 * Order matters: config -> error handling -> database -> session -> helpers.
 */

declare(strict_types=1);

if (defined('SIK_BOOTSTRAPPED')) {
    return;
}
define('SIK_BOOTSTRAPPED', true);

// ---------------------------------------------------------------------------
// 1. Configuration
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/constants.php';

// Make sure writable runtime folders exist before anything tries to log.
foreach ([LOG_PATH, STORAGE_PATH . '/cache'] as $runtimeDir) {
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0775, true);
    }
}

// ---------------------------------------------------------------------------
// 2. Error & exception handling
// ---------------------------------------------------------------------------
require_once INCLUDES_PATH . '/error-handler.php';
ErrorHandler::register();

// ---------------------------------------------------------------------------
// 3. Database
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/config/database.php';

// ---------------------------------------------------------------------------
// 4. Session
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    session_start();
}

// Rotate the session id periodically to blunt fixation attacks.
if (!isset($_SESSION['_regenerated_at'])) {
    $_SESSION['_regenerated_at'] = time();
} elseif (time() - (int) $_SESSION['_regenerated_at'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_regenerated_at'] = time();
}

// ---------------------------------------------------------------------------
// 5. Security headers (skipped for CLI and already-sent responses)
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header_remove('X-Powered-By');
}

// ---------------------------------------------------------------------------
// 6. Helper libraries
// ---------------------------------------------------------------------------
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/brand.php';
require_once INCLUDES_PATH . '/csrf.php';
require_once INCLUDES_PATH . '/validation.php';
require_once INCLUDES_PATH . '/response.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/cache.php';
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/cart-functions.php';
require_once INCLUDES_PATH . '/order-functions.php';
require_once INCLUDES_PATH . '/product-functions.php';
require_once INCLUDES_PATH . '/combo-functions.php';
require_once INCLUDES_PATH . '/mailer.php';
require_once INCLUDES_PATH . '/notifications.php';
require_once INCLUDES_PATH . '/invoice-functions.php';
// Only defines functions; TCPDF itself is loaded lazily on first render.
require_once INCLUDES_PATH . '/invoice-pdf.php';
require_once INCLUDES_PATH . '/payment-functions.php';
require_once INCLUDES_PATH . '/webhook-functions.php';
require_once INCLUDES_PATH . '/returns-functions.php';
require_once INCLUDES_PATH . '/account-functions.php';
require_once INCLUDES_PATH . '/search-bar.php';

// ---------------------------------------------------------------------------
// 7. Runtime locale from admin settings
// ---------------------------------------------------------------------------
$configuredTimezone = setting('timezone', TIMEZONE);
if ($configuredTimezone && in_array($configuredTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($configuredTimezone);
}

// ---------------------------------------------------------------------------
// 8. Maintenance mode (admin area and the installer stay reachable)
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && setting('maintenance_mode', '0') === '1') {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isAdminArea = strpos($script, '/admin/') !== false;
    $isInstaller = strpos($script, '/install.php') !== false;

    if (!$isAdminArea && !$isInstaller && !admin_is_logged_in()) {
        http_response_code(503);
        header('Retry-After: 3600');
        $message = setting('maintenance_message', 'We will be back shortly.');
        require ROOT_PATH . '/maintenance.php';
        exit;
    }
}
