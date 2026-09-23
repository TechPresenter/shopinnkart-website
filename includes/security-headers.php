<?php
/**
 * ShopInnKart - Response security headers, in one place.
 *
 * Every dynamic response gets its headers from here rather than from a mix of
 * .htaccess and bootstrap. Two reasons:
 *
 *  1. Apache's `Header always set` ADDS to what PHP sent, so the same policy
 *     went out twice and the stricter of the two was not necessarily the one
 *     the browser honoured. Worse, `Header set Cache-Control` on *.php
 *     REPLACED the "no-store" that json_response() and the account pages send,
 *     so JSON carrying a cart or an address could be written to disk cache.
 *  2. A shared host may ignore .htaccess entirely (LiteSpeed with headers off,
 *     nginx in front). A policy that only exists in .htaccess is a policy the
 *     site cannot rely on.
 *
 * .htaccess keeps only what PHP never touches: static files and the uploaded
 * SVG sandbox.
 *
 * Settings (group "security"):
 *   sec_force_https    'auto' (default) | '1' always | '0' never
 *   sec_hsts_max_age   seconds, default 300. Raise to 31536000 once happy.
 *   sec_hsts_subdomains'0' (default) | '1'  - only after mail/cpanel subdomains are on TLS
 *   sec_csp_mode       'report-only' (default) | 'enforce' | 'off'
 *   sec_csp_report_uri optional collector URL
 */

declare(strict_types=1);

/**
 * Should this request be on HTTPS?
 *
 * 'auto' deliberately does NOT redirect a plain-HTTP request: on a host where
 * TLS is not finished yet that would take the whole shop down. It means "treat
 * HTTPS as the real address once we are on it", which is enough to send HSTS
 * and to let the browser do the upgrading from then on. Switch the setting to
 * '1' to redirect as well.
 *
 * @param array|null $server null = this request; an array is for tests
 */
function security_force_https_wanted(?array $server = null): bool
{
    $explicit = $server !== null;
    $server   = $server ?? $_SERVER;
    $mode     = (string) setting('sec_force_https', 'auto');

    if ($mode === '0') {
        return false;
    }

    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    $host = trim($host, '[]');

    if (security_host_is_local($host)) {
        return false;   // localhost and the LAN have no certificate, ever
    }

    if ($mode === '1') {
        return true;
    }

    return security_request_is_https($explicit ? $server : null);
}

/** True for loopback, .local/.test names and RFC1918 addresses. */
function security_host_is_local(string $host): bool
{
    if ($host === '' || $host === 'localhost' || $host === '::1') {
        return true;
    }
    if (preg_match('/\.(local|localhost|test|internal|example)$/', $host) === 1) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE fail for private+reserved, which
        // is exactly the set that cannot hold a public certificate.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    return false;
}

/**
 * HTTPS, including behind a terminating proxy or load balancer.
 *
 * For the real request this defers to request_is_https() in request-trust.php,
 * which only believes X-Forwarded-Proto when it came from a proxy the owner
 * listed - otherwise any visitor could claim HTTPS by sending the header, and
 * HSTS would go out over plain HTTP. The array form is for tests and for code
 * asking about a hypothetical request.
 *
 * @param array|null $server null = this request
 */
function security_request_is_https(?array $server = null): bool
{
    if ($server === null) {
        if (function_exists('request_is_https')) {
            return request_is_https();
        }
        $server = $_SERVER;
    }

    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    if ((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
        return true;
    }

    return (int) ($server['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Which kind of page this is. The answer decides caching and the referrer
 * policy; everything else is the same site-wide.
 *
 * @return string admin | api | auth | account | public
 */
function security_response_context(?string $script = null): string
{
    $script = strtolower(str_replace('\\', '/', $script ?? (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $base   = basename($script);

    // Auth first: admin/login.php is an auth page before it is an admin page,
    // and it is the one that must never end up in a referrer or a cache.
    static $authPages = [
        'login.php', 'register.php', 'logout.php',
        'forgot-password.php', 'reset-password.php', 'verify-email.php',
        'resend-verification.php', 'set-password.php',
    ];
    if (in_array($base, $authPages, true)) {
        return 'auth';
    }
    if (strpos($script, '/api/') !== false) {
        return 'api';
    }
    if (strpos($script, '/admin/') !== false) {
        return 'admin';
    }

    static $privatePages = [
        'account.php', 'checkout.php', 'cart.php', 'wishlist.php',
        'order-details.php', 'order-confirmation.php', 'track-order.php',
        'invoice.php', 'returns.php',
    ];
    if (in_array($base, $privatePages, true) || strpos($script, '/account/') !== false) {
        return 'account';
    }

    return 'public';
}

/** The Content-Security-Policy this site can actually run under today. */
function security_csp_policy(bool $isHttps, string $context): string
{
    // The storefront and the admin both carry hand-written inline <script>
    // blocks and inline style attributes (theme colours, widget config, the
    // festive gradients). Until those are moved out, 'unsafe-inline' is the
    // honest policy - a nonce that half the page ignores would only look good.
    $directives = [
        'default-src'     => ["'self'"],
        'base-uri'        => ["'self'"],
        'object-src'      => ["'none'"],
        'frame-ancestors' => ["'self'"],
        'form-action'     => ["'self'"],
        'script-src'      => ["'self'", "'unsafe-inline'", 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://connect.facebook.net'],
        'style-src'       => ["'self'", "'unsafe-inline'"],
        'img-src'         => ["'self'", 'data:', 'blob:', 'https:'],
        'font-src'        => ["'self'", 'data:'],
        'media-src'       => ["'self'", 'data:', 'https:'],
        'worker-src'      => ["'self'", 'blob:'],
        'manifest-src'    => ["'self'"],
        'connect-src'     => ["'self'", 'https://www.google-analytics.com', 'https://region1.google-analytics.com', 'https://connect.facebook.net'],
        // Product videos and the GTM debug frame. Nothing else may frame in.
        'frame-src'       => ["'self'", 'https://www.googletagmanager.com', 'https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
    ];

    if ($context === 'admin') {
        // No third-party tags in the back office, so the policy can be tighter.
        $directives['script-src'] = ["'self'", "'unsafe-inline'"];
        $directives['connect-src'] = ["'self'"];
        $directives['frame-src'] = ["'self'"];
    }

    // The configured CAPTCHA provider, if there is one. Its widget is a script
    // and an iframe from the vendor's own domain: leave them out and the
    // browser blocks the very check the visitor has to pass to get in. Nothing
    // is added while no provider is configured.
    if (function_exists('bot_protection_csp_sources')) {
        foreach (bot_protection_csp_sources() as $directive => $sources) {
            if (!isset($directives[$directive])) {
                continue;
            }
            foreach ((array) $sources as $source) {
                if (!in_array($source, $directives[$directive], true)) {
                    $directives[$directive][] = $source;
                }
            }
        }
    }

    $parts = [];
    foreach ($directives as $name => $values) {
        $parts[] = $name . ' ' . implode(' ', $values);
    }
    if ($isHttps) {
        // Only on HTTPS: on plain HTTP it would upgrade the page's own assets
        // to a scheme the server is not answering on.
        $parts[] = 'upgrade-insecure-requests';
    }

    $report = trim((string) setting('sec_csp_report_uri', ''));
    if ($report !== '' && filter_var($report, FILTER_VALIDATE_URL) !== false) {
        $parts[] = 'report-uri ' . $report;
    }

    return implode('; ', $parts);
}

/**
 * Mark the current response as private and uncacheable.
 * Exposed because a page can decide mid-request that it is holding personal
 * data (an order lookup by number, say) after the headers have been chosen.
 */
function security_no_store(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Developer surfaces (the /api explorer) are for a developer, not for the
 * internet: they publish the full endpoint list with its guards and limits.
 * Visible in development, or to a signed-in admin who may see security pages.
 */
function security_dev_tools_visible(): bool
{
    if (APP_DEBUG) {
        return true;
    }

    return function_exists('admin_is_logged_in') && admin_is_logged_in()
        && function_exists('admin_can') && admin_can('security.view');
}

/**
 * Send every response header the app is responsible for, and redirect to
 * HTTPS when the operator has asked for that. Called once from init.php.
 */
function security_headers_send(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $server  = $_SERVER;
    $isHttps = security_request_is_https();
    $wants   = security_force_https_wanted();

    // ---- HTTPS redirect ---------------------------------------------------
    // Only in the explicit '1' mode; 'auto' never redirects (see the function).
    if (!$isHttps && $wants && (string) setting('sec_force_https', 'auto') === '1' && !headers_sent()) {
        $host = (string) ($server['HTTP_HOST'] ?? '');
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) === 1) {
            $target = 'https://' . $host . ($server['REQUEST_URI'] ?? '/');
            security_event('platform.https_redirect', 'info', ['host' => $host]);
            header('Location: ' . $target, true, 301);
            exit;
        }
    }

    if (headers_sent()) {
        return;
    }

    $context = security_response_context();

    // ---- Transport --------------------------------------------------------
    // HSTS is a promise the browser remembers for max-age seconds; a wrong one
    // cannot be taken back. So it goes out only over a working HTTPS request,
    // starts at 5 minutes and is raised from the security card once the
    // operator has seen the site work on TLS. No preload here, ever - that is
    // a one-way door and belongs on hstspreload.org, deliberately.
    if ($isHttps && $wants) {
        $maxAge = max(0, (int) setting('sec_hsts_max_age', 300));
        if ($maxAge > 0) {
            header('Strict-Transport-Security: max-age=' . $maxAge
                . ((string) setting('sec_hsts_subdomains', '0') === '1' ? '; includeSubDomains' : ''));
        }
    }

    // ---- Content handling -------------------------------------------------
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // Keeps a cross-origin popup from reaching back through window.opener.
    // allow-popups rather than plain same-origin because a hosted payment page
    // opened in a popup still needs to postMessage its result home.
    header('Cross-Origin-Opener-Policy: ' . ($context === 'admin' ? 'same-origin' : 'same-origin-allow-popups'));
    header('X-Permitted-Cross-Domain-Policies: none');
    header_remove('X-Powered-By');

    // ---- Referrer ---------------------------------------------------------
    // A password-reset or verification URL carries its token in the query
    // string. Any outbound request from that page - an image, a font, a tag -
    // would otherwise hand the whole URL to a third party in the Referer.
    header('Referrer-Policy: ' . ($context === 'auth' ? 'no-referrer' : 'strict-origin-when-cross-origin'));

    // ---- Feature permissions ----------------------------------------------
    // microphone=(self) on purpose: assets/js/voice-search.js is the site's own
    // feature and a blanket microphone=() switched it off.
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(), payment=(self), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()');

    // ---- Caching ----------------------------------------------------------
    if (in_array($context, ['admin', 'api', 'auth', 'account'], true)) {
        security_no_store();
    }

    // ---- CSP --------------------------------------------------------------
    $mode = (string) setting('sec_csp_mode', 'report-only');
    if ($mode === 'enforce' || $mode === 'report-only') {
        $policy = security_csp_policy($isHttps, $context);
        header(($mode === 'enforce' ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . $policy);
    }
}

// ---------------------------------------------------------------------------
//  Self-checks shown on the security cards
// ---------------------------------------------------------------------------

/**
 * Is the web installer still sitting in the document root?
 *
 * @return array{present:bool, disarmed:bool, lock:bool, path:string}
 */
function security_installer_status(): array
{
    $path = ROOT_PATH . '/install.php';
    $lock = is_file(ROOT_PATH . '/storage/installed.lock');

    return [
        'present'  => is_file($path),
        // install.php refuses to do anything once the app is configured, so an
        // installer that is still there is untidy rather than an open door.
        'disarmed' => function_exists('install_already_installed') ? install_already_installed() : $lock,
        'lock'     => $lock,
        'path'     => $path,
    ];
}

/**
 * The list of paths that must never be downloadable. Used by the security card
 * and by bin/security-selftest.php.
 *
 * @return string[]
 */
function security_exposure_paths(): array
{
    return [
        '.git/HEAD',
        '.git/config',
        '.env',
        'composer.json',
        'config/db.local.php',
        'config/app.key.php',
        'database/schema.sql',
        'database/export-for-hosting.php',
        'includes/functions.php',
        'admin/includes/auth.php',
        'bin/send-queued-emails.php',
        'storage/installed.lock',
        'vendor/autoload.php',
    ];
}

/**
 * Ask the live site for each path and report what came back.
 *
 * It really goes over HTTP, because that is the only thing that proves the
 * host honours .htaccess - reading the file from disk proves nothing. Short
 * timeouts and a handful of URLs, so the card stays usable.
 *
 * @return array{checked:int, exposed:array<int,array{path:string,status:int,bytes:int}>, errors:string[]}
 */
function security_exposure_probe(?array $paths = null): array
{
    $paths   = $paths ?? security_exposure_paths();
    $exposed = [];
    $errors  = [];
    $checked = 0;

    foreach ($paths as $path) {
        $url = rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 6,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'header'          => ['User-Agent: ShopInnKart-SelfTest'],
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        // $http_response_header is function-scoped and survives the loop, so a
        // failed request would otherwise be read as the previous one's result.
        unset($http_response_header);
        $body = @file_get_contents($url, false, $context);
        $raw  = $http_response_header ?? [];
        if ($raw === []) {
            $errors[] = $path . ': no response';
            continue;
        }

        $status = 0;
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $checked++;
        if ($status === 200) {
            $exposed[] = ['path' => $path, 'status' => $status, 'bytes' => strlen((string) $body)];
        }
    }

    return ['checked' => $checked, 'exposed' => $exposed, 'errors' => $errors];
}
