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
 *   sec_csp_report_uri optional EXTERNAL collector URL; empty = our own
 *   sec_csp_collect    '1' (default) collect violations at api/security/csp-report.php
 *   sec_csp_script_mode'unsafe-inline' (default) | 'nonce'
 */

declare(strict_types=1);

// The watchtower: IP rules and the request probes, both applied from
// security_headers_send() below so they run before a page does any work.
require_once __DIR__ . '/security-monitor.php';

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

/**
 * Which set of script sources a page is entitled to: 'admin' or 'storefront'.
 *
 * A DIFFERENT question from security_response_context(), on purpose. That one
 * answers "how must this response be cached and referred to", and for that
 * admin/login.php is an auth page first - no-store, no-referrer, exactly like
 * the shop's own sign-in. But the answer it gives for script-src was then the
 * storefront's, which handed the back-office sign-in page four third-party
 * origins (GTM, GA, Facebook) that it has never loaded and never will.
 * Measured on the running site: admin/login.php fetches no external script and
 * names no external host at all. So the two questions are asked separately.
 *
 * @param string|null $script null = this request
 */
function security_csp_script_scope(?string $script = null): string
{
    $script = strtolower(str_replace('\\', '/', $script ?? (string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    return strpos($script, '/admin/') !== false ? 'admin' : 'storefront';
}

/**
 * This request's script nonce.
 *
 * One value per request, generated on first use. A page that wants its inline
 * <script> to survive nonce mode prints csp_nonce_attr() on the tag; a page
 * that does not is exactly what security_csp_inline_audit() counts, and why
 * the admin card refuses to switch nonce mode on while that count is not zero.
 */
function security_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce !== null) {
        return $nonce;
    }

    try {
        // base64url: the CSP grammar accepts base64, and '+' / '/' inside an
        // HTML attribute is one escaping bug away from a broken page.
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    } catch (Throwable $e) {
        // No entropy source means no nonce, which means the policy must not
        // claim to have one - security_csp_script_mode() falls back below.
        $nonce = '';
    }

    return $nonce;
}

/**
 * How inline <script> is allowed today: 'unsafe-inline' or 'nonce'.
 *
 * Never 'nonce' unless a nonce could actually be generated: a nonce directive
 * with an empty value blocks every inline script on the site, which is the one
 * failure mode a security header must not have.
 */
function security_csp_script_mode(): string
{
    return (string) setting('sec_csp_script_mode', 'unsafe-inline') === 'nonce'
        && security_csp_nonce() !== ''
            ? 'nonce'
            : 'unsafe-inline';
}

/** `nonce="..."` for an inline <script>, or '' when the site is not in nonce mode. */
function csp_nonce_attr(): string
{
    if (security_csp_script_mode() !== 'nonce') {
        return '';
    }

    return ' nonce="' . e_attr(security_csp_nonce()) . '"';
}

/**
 * Where violation reports go.
 *
 * An external collector when the owner configured one, otherwise this site's
 * own endpoint - which is the point: the policy shipped in report-only mode
 * with nowhere to report to, so "report only" meant "tell the visitor's
 * console and nobody else".
 */
function security_csp_report_target(): string
{
    $external = trim((string) setting('sec_csp_report_uri', ''));
    if ($external !== '' && filter_var($external, FILTER_VALIDATE_URL) !== false) {
        return $external;
    }

    return setting_bool('sec_csp_collect', true) ? csp_report_endpoint() : '';
}

/**
 * The Content-Security-Policy this site can actually run under today.
 *
 * @param string|null $script the SCRIPT_NAME to judge the script scope by;
 *                            null = this request. Only consulted for an 'auth'
 *                            page, which can sit on either side of the house.
 */
function security_csp_policy(bool $isHttps, string $context, ?string $script = null): string
{
    // The storefront and the admin both carry hand-written inline <script>
    // blocks and inline style attributes (theme colours, widget config, the
    // festive gradients). 'unsafe-inline' is still the default because those
    // blocks live in files four other areas of the app own; security_csp_nonce()
    // is the way out, and security_csp_inline_audit() measures how far off it
    // is rather than guessing. A nonce that half the page ignores would only
    // look good.
    $inlineScripts = security_csp_script_mode() === 'nonce'
        ? "'nonce-" . security_csp_nonce() . "'"
        : "'unsafe-inline'";

    $directives = [
        'default-src'     => ["'self'"],
        'base-uri'        => ["'self'"],
        'object-src'      => ["'none'"],
        'frame-ancestors' => ["'self'"],
        'form-action'     => ["'self'"],
        'script-src'      => ["'self'", $inlineScripts, 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://connect.facebook.net'],
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

    // The back office, including its sign-in page - which reports itself as an
    // 'auth' context for caching but is still an admin page for script sources.
    if ($context === 'admin'
        || ($context === 'auth' && security_csp_script_scope($script) === 'admin')) {
        // No third-party tags in the back office, so the policy can be tighter.
        $directives['script-src'] = ["'self'", $inlineScripts];
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

    $report = security_csp_report_target();
    if ($report !== '') {
        // Both spellings on purpose. report-uri is deprecated but is the only
        // one Safari and older Chrome understand; report-to is the only one
        // the newest Chrome still acts on. A policy that sends one of them
        // reports from half the visitors.
        $parts[] = 'report-uri ' . $report;
        $parts[] = 'report-to csp-endpoint';
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

    // Headers already out (an early echo, a warning printed by PHP) means the
    // header work below is pointless - but the door still has to be shut, so
    // this skips the headers rather than returning from the function.
    if (!headers_sent()) {
        security_headers_apply($server, $isHttps, $wants);
    }

    // ---- The door ---------------------------------------------------------
    // Last, so that a refused address still leaves with nosniff, no-store and
    // the rest - and first in terms of the page, because nothing below init.php
    // has run yet. A blocked request exits inside here.
    security_monitor_guard();
}

/**
 * Every header this app is responsible for. Split out of
 * security_headers_send() only so the door below it always gets its turn.
 */
function security_headers_apply(array $server, bool $isHttps, bool $wants): void
{
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

        // The named group the policy's `report-to` points at. It has to be an
        // absolute URL here (the header grammar has no relative form), and it
        // is built from the request's own origin rather than the canonical
        // one: a staging domain must report to itself, not to production.
        $report = security_csp_report_target();
        if ($report !== '') {
            $absolute = preg_match('~^https?://~i', $report) === 1
                ? $report
                : ($isHttps ? 'https://' : 'http://') . (string) ($server['HTTP_HOST'] ?? '') . $report;
            if (filter_var($absolute, FILTER_VALIDATE_URL) !== false) {
                header('Reporting-Endpoints: csp-endpoint="' . $absolute . '"');
            }
        }

        header(($mode === 'enforce' ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . $policy);
    }
}

// ---------------------------------------------------------------------------
//  Is nonce mode reachable? - measured, not guessed
// ---------------------------------------------------------------------------

/**
 * The page types worth asking about, and what each one proves.
 *
 * Deliberately fetched over HTTP rather than reasoned about from the source:
 * the source says what a template contains, the response says what the browser
 * is actually handed after every include, widget and injected tag has run.
 *
 * @return array<string,string> label => path relative to SITE_URL
 */
function security_csp_audit_pages(): array
{
    return [
        'Home'            => '/',
        'Category'        => '/shop.php',
        'Product'         => '/product.php',
        'Cart'            => '/cart.php',
        'Sign in'         => '/login.php',
        'Register'        => '/register.php',
        'Contact'         => '/contact.php',
        'Not found (404)' => '/this-page-does-not-exist',
        'Admin sign in'   => '/admin/login.php',
    ];
}

/**
 * Count what a nonce-based policy would break, page type by page type.
 *
 * Three separate problems, and they are NOT interchangeable:
 *   - inline <script> without a nonce: fixable with one attribute
 *   - inline event handlers (onclick=...): a nonce cannot help these at all,
 *     they need 'unsafe-hashes' or the handler moved into a file
 *   - href="javascript:...": same, and worse
 *
 * Inline style= attributes are counted too but do not block script nonce mode,
 * because style-src is a separate directive and stays on 'unsafe-inline'.
 *
 * @return array{pages:array<int,array>, totals:array<string,int>, errors:string[]}
 */
function security_csp_inline_audit(?array $pages = null): array
{
    $pages  = $pages ?? security_csp_audit_pages();
    $result = [];
    $errors = [];
    $totals = ['inline' => 0, 'nonced' => 0, 'handlers' => 0, 'js_href' => 0, 'style_attr' => 0];

    foreach ($pages as $label => $path) {
        $url     = rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
        // The signed self-check mark: one of the pages below is deliberately a
        // 404, which is what the scan detector counts. Without this, measuring
        // the site would slowly accuse the server of scanning itself.
        $headers = array_values(array_filter([
            'User-Agent: ShopInnKart-CSP-Audit',
            security_selfcheck_header(),
        ]));

        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 8,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'header'          => $headers,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        unset($http_response_header);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || ($http_response_header ?? []) === []) {
            $errors[] = $label . ': no response from ' . $url;
            continue;
        }

        // Every opening <script> tag that has no src attribute.
        preg_match_all('~<script\b(?![^>]*\bsrc\s*=)[^>]*>~i', $body, $tags);
        $inline = count($tags[0]);
        $nonced = 0;
        foreach ($tags[0] as $tag) {
            if (stripos($tag, 'nonce') !== false) {
                $nonced++;
            }
        }

        $handlers = preg_match_all('~\son(?:click|change|submit|input|load|error|keyup|keydown|focus|blur|mouseover|mouseout|toggle)\s*=~i', $body);
        $jsHref   = preg_match_all('~\b(?:href|action|src)\s*=\s*["\']\s*javascript:~i', $body);
        $styles   = preg_match_all('~\sstyle\s*=\s*["\']~i', $body);

        $row = [
            'label'      => (string) $label,
            'path'       => $path,
            'inline'     => $inline,
            'nonced'     => $nonced,
            'unnonced'   => max(0, $inline - $nonced),
            'handlers'   => (int) $handlers,
            'js_href'    => (int) $jsHref,
            'style_attr' => (int) $styles,
        ];
        $result[] = $row;

        $totals['inline']     += $row['inline'];
        $totals['nonced']     += $row['nonced'];
        $totals['handlers']   += $row['handlers'];
        $totals['js_href']    += $row['js_href'];
        $totals['style_attr'] += $row['style_attr'];
    }

    $totals['unnonced'] = max(0, $totals['inline'] - $totals['nonced']);

    return ['pages' => $result, 'totals' => $totals, 'errors' => $errors];
}

/**
 * Which FILES still print an inline <script> without a nonce.
 *
 * The audit above says how bad it is; this says where to go. One bounded pass
 * over the project's own PHP - vendor, storage and uploads are somebody else's
 * code or not code at all.
 *
 * @return array<string,int> repo-relative path => un-nonced inline script tags
 */
function security_csp_inline_sources(): array
{
    $found = [];
    $root  = str_replace('\\', '/', ROOT_PATH);

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    $name = $file->getFilename();
                    if ($file->isDir()) {
                        return !in_array($name, ['vendor', 'storage', 'node_modules', '.git', 'uploads', 'backups'], true);
                    }
                    return strtolower($file->getExtension()) === 'php';
                }
            )
        );
    } catch (Throwable $e) {
        return [];
    }

    foreach ($iterator as $file) {
        $source = @file_get_contents($file->getPathname());
        if (!is_string($source) || stripos($source, '<script') === false) {
            continue;
        }
        $count = 0;
        foreach (security_csp_script_tags($source) as $tag) {
            if (stripos($tag, 'nonce') === false) {
                $count++;
            }
        }
        if ($count > 0) {
            $found[ltrim(str_replace($root, '', str_replace('\\', '/', $file->getPathname())), '/')] = $count;
        }
    }

    arsort($found);

    return $found;
}

/**
 * The opening inline <script> tags a PHP file really PRINTS.
 *
 * Matching the raw source counts this file's own doc comments and the very
 * regex below, and then the "where to go next" list leads with a file that
 * prints no script at all. So the source is tokenised first and comments are
 * dropped: what is left is the HTML the file emits and the strings it echoes,
 * which is what actually reaches a browser.
 *
 * @return string[] the matched opening tags
 */
function security_csp_script_tags(string $source): array
{
    $code = $source;

    try {
        $kept = [];
        foreach (@token_get_all($source) as $token) {
            if (is_string($token)) {
                $kept[] = $token;
                continue;
            }
            // Comments are prose about scripts, never scripts. Everything else
            // - inline HTML, echoed strings, heredocs - can carry a real tag.
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            // And a regex literal that LOOKS FOR script tags is not one either
            // - which is how this very function used to report its own source
            // as the worst offender in the codebase. Only the ~ and # delimiters
            // the house style uses; a string starting with / is a path.
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                && preg_match('{^([\'"])([~#])(?s:.*)\2[imsxuUADSXJn]*\1$}', $token[1]) === 1) {
                continue;
            }
            $kept[] = $token[1];
        }
        $code = implode('', $kept);
    } catch (Throwable $e) {
        // A file this PHP cannot tokenise falls back to the raw scan:
        // over-reporting one file beats silently missing it.
    }

    preg_match_all('~<script\b(?![^>]*\bsrc\s*=)[^>]*>~i', $code, $tags);

    return $tags[0];
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
        // Four of the paths in security_exposure_paths() - /.env, /.git/HEAD,
        // /.git/config and /composer.json - match security_probe_patterns()
        // word for word, and the probe counter's window is an hour. Run the
        // check twice in that hour, or leave the cron self-test on, and the
        // site accuses itself of probing itself. Unmarked, this check WAS the
        // thing it was checking for.
        $headers = array_values(array_filter([
            'User-Agent: ShopInnKart-SelfTest',
            security_selfcheck_header(),
        ]));

        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 6,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'header'          => $headers,
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
