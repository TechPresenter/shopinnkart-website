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
//
// functions.php and request-trust.php come first because the cookie flags are
// decided from settings (sec_force_https) and from whether the proxy in front
// of us is one the owner listed. Both only declare functions, and the later
// require_once in section 6 is then a no-op.
// ---------------------------------------------------------------------------
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/request-trust.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        // 0 = the cookie dies with the browser. It used to be seven days for
        // everybody, remembered or not, admins included: closing the window on
        // a shared machine left a live session behind for a week. "Keep me
        // signed in" is a separate rotated token now (auth_remember_issue()).
        'lifetime' => 0,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'domain'   => '',
        'secure'   => session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // The server-side copy has to outlive the longest session we allow, or an
    // idle customer would be logged out by garbage collection instead.
    ini_set('session.gc_maxlifetime', (string) max(3600, 60 * max(60, (int) setting_int('sec_session_idle_customer', 240))));
    session_start();
}

// The rotation itself is in section 6c: it has to carry a guest's basket to
// the new id, and cart-functions.php is not loaded yet at this point.
if (!isset($_SESSION['_regenerated_at'])) {
    $_SESSION['_regenerated_at'] = time();
}

// ---------------------------------------------------------------------------
// 5. Security headers
//     Sent from includes/security-headers.php at the end of section 6: the
//     policy reads admin settings (HTTPS mode, CSP mode), so it has to wait
//     until functions.php has defined setting(). Nothing is output before then.
// ---------------------------------------------------------------------------

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
require_once INCLUDES_PATH . '/admin-gate.php';
require_once INCLUDES_PATH . '/rate-limit.php';
require_once INCLUDES_PATH . '/security-events.php';
require_once INCLUDES_PATH . '/security-headers.php';

security_headers_send();

// ---------------------------------------------------------------------------
// 6b. Request trust: the Host header, and who this visitor is
// ---------------------------------------------------------------------------

// Keep the mirrored copy of the Host settings in step (config/config.php reads
// it before the database exists, so it cannot read the settings itself).
site_url_policy_sync();

if (PHP_SAPI !== 'cli') {
    // A Host header we do not recognise can no longer decide an emailed link
    // (see includes/request-trust.php). Refusing the request outright is the
    // owner's choice, because a misconfigured allow-list would otherwise take
    // the whole store down.
    if (!request_host_trusted()) {
        security_event('platform.unknown_host', 'medium', [
            'host' => mb_substr((string) ($_SERVER['HTTP_HOST'] ?? ''), 0, 100),
        ]);

        if (setting_bool('sec_host_block', false)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo "Bad Request\n";
            exit;
        }
    }

    // "Keep me signed in": a valid token restores the session the browser no
    // longer has. Rotates the token; does nothing when there is no cookie.
    auth_remember_restore();
}

// ---------------------------------------------------------------------------
// 6c. Session id rotation
//
// Rotated every 30 minutes to blunt fixation. A guest's cart is keyed on the
// session id, so the new id has to be handed the cart the old one was holding:
// rotating without that emptied a browsing shopper's basket every half hour
// and left the filled row behind as an orphan. Nothing has been echoed yet,
// so the new cookie can still go out.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && time() - (int) ($_SESSION['_regenerated_at'] ?? time()) > 1800) {
    $previousSessionId = session_id();
    session_regenerate_id(true);
    cart_session_rehome((string) $previousSessionId, (string) session_id());
    $_SESSION['_regenerated_at'] = time();
}

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
    // The hidden admin login address is served by 404.php, not from /admin/,
    // so it needs its own pass or a signed-out admin could never get back in
    // to switch maintenance off.
    $isAdminGate = admin_gate_request_matches();

    if (!$isAdminArea && !$isInstaller && !$isAdminGate && !admin_is_logged_in()) {
        http_response_code(503);
        header('Retry-After: 3600');
        $message = setting('maintenance_message', 'We will be back shortly.');
        require ROOT_PATH . '/maintenance.php';
        exit;
    }
}
