<?php
/**
 * ShopInnKart - What we are willing to believe about the request.
 *
 * Two things arrive with every request that the application used to trust
 * outright, and both decide security-relevant behaviour:
 *
 *  1. The Host header, which SITE_URL was built from. A request carrying
 *     "Host: evil.example" therefore produced a genuine store email whose
 *     password-reset link pointed at the attacker. Absolute links are now
 *     built from a canonical base, and a Host we do not recognise is never
 *     allowed to decide one.
 *
 *  2. X-Forwarded-For / CF-Connecting-IP, which say who the client "really"
 *     is. Behind Cloudflare or a load balancer REMOTE_ADDR is the edge, so
 *     every visitor shares one rate-limit bucket and every log line records
 *     the CDN. Believe the forwarded header only when the machine that
 *     actually connected is a proxy the owner listed.
 *
 * Base URL resolution order:
 *   1. APP_URL / SITE_URL in the environment, or 'url' in config/db.local.php
 *   2. sec_canonical_url (Admin > Security > Settings), mirrored into a small
 *      cache file because config/config.php runs before the database exists
 *   3. the request's own Host, but only when it is on the allow-list
 *   4. https://SITE_DOMAIN - the safe fallback for anything else
 *
 * Loaded by config/config.php (before the database) and again by init.php, so
 * everything here must work with nothing but $_SERVER and the filesystem.
 */

declare(strict_types=1);

// ===========================================================================
//  Canonical site URL
// ===========================================================================

/**
 * Normalise "shop.example/path" or "https://shop.example/path/" to a base URL
 * with no trailing slash, or null when it cannot be used as one.
 */
function site_url_normalise(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('~^https?://~i', $url) !== 1) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    // Hostnames and bracketed IPv6 literals only: this value ends up in the
    // Location header and in emailed links.
    if (preg_match('/^(?:[a-z0-9]([a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$|^\[[0-9a-f:]+\]$/i', $host) !== 1) {
        return null;
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if (strpos($path, '//') !== false) {
        $path = '';
    }

    return $scheme . '://' . $host . $port . $path;
}

/** The host (with port, without scheme) of a base URL. */
function site_url_host_of(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    return strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
}

/** Split a comma / newline separated host list into a clean array. */
function site_url_parse_hosts(string $list): array
{
    $hosts = preg_split('/[\s,;]+/', strtolower(trim($list))) ?: [];
    $clean = [];
    foreach ($hosts as $host) {
        $host = trim($host);
        if ($host === '') {
            continue;
        }
        // Tolerate a pasted URL.
        if (strpos($host, '/') !== false || strpos($host, ':') !== false) {
            $normalised = site_url_normalise($host);
            $host = $normalised === null ? '' : site_url_host_of($normalised);
        }
        if ($host !== '' && preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host) === 1) {
            $clean[] = $host;
        }
    }
    return array_values(array_unique($clean));
}

/**
 * Hosts that only ever mean "a developer's machine". Accepted as themselves in
 * development so the project still runs from localhost, a LAN address or a
 * .test domain without any configuration; never accepted in production.
 */
function site_url_dev_host(string $host): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

    if ($host === 'localhost' || $host === '::1' || $host === '[::1]' || substr($host, -10) === '.localhost') {
        return true;
    }
    // .test/.local/.internal only. Not .example or .invalid: those are just as
    // registerable in a Host header as anything else, and treating them as
    // "ours" would hand an attacker the allow-list.
    if (preg_match('/\.(test|local|localdomain|internal)$/', $host) === 1) {
        return true;
    }
    if (strpos($host, '.') === false) {
        return true;   // a bare machine name, e.g. http://devbox/
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    return false;
}

/** Is this Host header one of ours? */
function site_url_host_allowed(string $host, ?string $canonical, string $domain, array $policy, bool $dev): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-f:]+\](:\d{1,5})?$/i', $host) !== 1) {
        return false;
    }
    $bare = preg_replace('/:\d+$/', '', $host) ?? $host;

    $allowed = [];
    foreach ([$canonical === null ? '' : site_url_host_of($canonical), $domain] as $candidate) {
        $candidate = strtolower(trim((string) $candidate));
        if ($candidate === '') {
            continue;
        }
        $apex = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $candidate) ?? $candidate) ?? $candidate;
        $allowed[] = $candidate;
        $allowed[] = $apex;
        $allowed[] = 'www.' . $apex;
    }
    foreach ($policy['hosts'] ?? [] as $extra) {
        $allowed[] = strtolower((string) $extra);
    }

    if (in_array($host, $allowed, true) || in_array($bare, $allowed, true)) {
        return true;
    }

    return $dev && site_url_dev_host($bare);
}

/** Where the mirrored host policy lives - one file per database + install. */
function site_url_policy_file(): string
{
    if (!defined('STORAGE_PATH') || !defined('DB_NAME') || !defined('ROOT_PATH')) {
        return '';
    }
    // Keyed by database and install path: a scratch database on the same code
    // must never hand its canonical URL to the live site (they share storage/).
    $key = substr(sha1(DB_NAME . '|' . (defined('DB_HOST') ? DB_HOST : '') . '|' . ROOT_PATH), 0, 12);

    return STORAGE_PATH . '/cache/host-policy-' . $key . '.json';
}

/**
 * The mirrored copy of the Host settings, readable before the database is up.
 * Empty (and harmless) when the file has never been written.
 */
function site_url_policy(): array
{
    static $policy = null;
    if ($policy !== null) {
        return $policy;
    }

    $policy = ['canonical' => '', 'hosts' => [], 'proxy' => false, 'block' => false];

    $file = site_url_policy_file();
    if ($file !== '' && is_file($file)) {
        $raw = @file_get_contents($file);
        $loaded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($loaded)) {
            $policy['canonical'] = is_string($loaded['canonical'] ?? null) ? $loaded['canonical'] : '';
            $policy['hosts']     = is_array($loaded['hosts'] ?? null) ? array_values(array_filter($loaded['hosts'], 'is_string')) : [];
            $policy['proxy']     = !empty($loaded['proxy']);
            $policy['block']     = !empty($loaded['block']);
        }
    }

    return $policy;
}

/**
 * Refresh the mirror from the database. Called once per request from init.php
 * after the settings are available; writes only when something changed.
 */
function site_url_policy_sync(): void
{
    $current = [
        'canonical' => (string) (site_url_normalise((string) setting('sec_canonical_url', '')) ?? ''),
        'hosts'     => site_url_parse_hosts((string) setting('sec_host_allowlist', '')),
        'proxy'     => trim((string) setting('sec_trusted_proxies', '')) !== '',
        'block'     => setting_bool('sec_host_block', false),
    ];

    if ($current == site_url_policy()) {
        return;
    }

    $file = site_url_policy_file();
    if ($file === '') {
        return;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Best effort: an unwritable storage folder means links fall back to the
    // allow-list defaults, which is safe - never a fatal error.
    @file_put_contents($file, json_encode($current, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Decide BASE_PATH and SITE_URL for this request.
 *
 * @param array{configured:string,server:array,app_root:string,domain:string,dev:bool} $context
 * @return array{url:string, base_path:string, canonical:string, host_ok:bool}
 */
function site_url_resolve(array $context): array
{
    $server  = is_array($context['server'] ?? null) ? $context['server'] : [];
    $appRoot = str_replace('\\', '/', (string) ($context['app_root'] ?? ''));
    $domain  = (string) ($context['domain'] ?? '');
    $dev     = (bool) ($context['dev'] ?? false);
    $policy  = site_url_policy();

    // Where the app is installed under the web root, e.g. "/ecomweb".
    $docRoot  = str_replace('\\', '/', rtrim((string) ($server['DOCUMENT_ROOT'] ?? ''), '/'));
    $basePath = '';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $basePath = rtrim(substr($appRoot, strlen($docRoot)), '/');
    }

    // Explicit deployment configuration wins over anything in the request.
    $canonical = site_url_normalise((string) ($context['configured'] ?? ''))
        ?? site_url_normalise((string) $policy['canonical']);

    $host   = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
    $hostOk = $host !== '' && site_url_host_allowed($host, $canonical, $domain, $policy, $dev);

    // X-Forwarded-Proto is a header anybody can send, so it only counts when
    // the owner has told us there is a proxy in front.
    $scheme = 'http';
    if ((!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
        || ((string) ($server['SERVER_PORT'] ?? '') === '443'
            && (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? 'https') === 'https')
        || (!empty($policy['proxy']) && strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')) {
        $scheme = 'https';
    }

    if ($hostOk) {
        // Keep serving the host the visitor actually typed (apex vs www, or a
        // staging domain): rewriting it mid-session would post forms to another
        // origin and lose the session cookie. Emails use canonical_url().
        $url = $scheme . '://' . $host . $basePath;
    } elseif ($canonical !== null) {
        $url = $canonical;
        $basePath = rtrim((string) (parse_url($canonical, PHP_URL_PATH) ?: ''), '/');
    } else {
        $url = 'https://' . ($domain !== '' ? $domain : 'localhost') . $basePath;
    }

    return [
        'url'       => $url,
        'base_path' => $basePath,
        'canonical' => $canonical ?? '',
        'host_ok'   => $hostOk,
    ];
}

/**
 * The base every emailed or otherwise off-site link is built from.
 * Never the request's Host unless that Host is one of ours.
 */
function canonical_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    // Set from the environment / db.local.php / the mirrored setting at config
    // time; the live read below covers the request in which it was changed.
    $configured = defined('SITE_URL_CANONICAL') ? (string) SITE_URL_CANONICAL : '';
    if ($configured !== '') {
        return $base = $configured;
    }

    $live = function_exists('setting') ? site_url_normalise((string) setting('sec_canonical_url', '')) : null;

    return $base = $live ?? SITE_URL;
}

/** Absolute URL for a path, safe to put in an email. */
function canonical_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = rtrim(canonical_base_url(), '/');

    return $path === '' ? $base . '/' : $base . '/' . $path;
}

/** Did this request arrive with a Host header we recognise? */
function request_host_trusted(): bool
{
    return !defined('SITE_URL_HOST_OK') || SITE_URL_HOST_OK === true;
}

/** Is this request itself running over HTTPS? */
function request_is_https(): bool
{
    $server = $_SERVER;

    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // Forwarded protocol only counts behind a proxy the owner configured.
    if (function_exists('trusted_proxies') && trusted_proxies() !== []
        && is_trusted_proxy((string) ($server['REMOTE_ADDR'] ?? ''))) {
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (strpos((string) ($server['HTTP_CF_VISITOR'] ?? ''), '"https"') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Should cookies carry the Secure flag?
 *
 * Yes whenever this request is HTTPS, and yes whenever the owner has switched
 * "force HTTPS" on (sec_force_https, owned by the platform side of the audit)
 * - otherwise one plain-http request hands out a 7-day session cookie in
 * cleartext, admin sessions included.
 */
function session_cookie_secure(): bool
{
    if (request_is_https()) {
        return true;
    }
    if (!function_exists('setting')) {
        return false;
    }

    // sec_force_https is the platform side's switch: '1' always, '0' never,
    // 'auto' (the default) means "HTTPS is the real address once we are on it".
    $mode = (string) setting('sec_force_https', 'auto');
    if ($mode === '1') {
        return true;
    }
    if ($mode === '0') {
        return false;
    }

    // Auto: a canonical https:// address that the OWNER configured means the
    // site is meant to be served over TLS, so the cookie must not be allowed
    // to travel in the clear - except on a developer machine, which never has
    // a certificate. Deliberately not canonical_base_url(): that falls back to
    // https://SITE_DOMAIN for an unrecognised Host, and a stray Host header
    // must not decide cookie flags.
    $configured = defined('SITE_URL_CANONICAL') ? (string) SITE_URL_CANONICAL : '';
    if ($configured === '') {
        $configured = (string) (site_url_normalise((string) setting('sec_canonical_url', '')) ?? '');
    }

    return $configured !== '' && strpos($configured, 'https://') === 0
        && !site_url_dev_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
}

// ===========================================================================
//  Trusted proxies and the real client IP
// ===========================================================================

/**
 * Cloudflare's published edge ranges, bundled so the owner can switch the
 * preset on without copying 22 CIDRs by hand. Anything else (a load balancer,
 * a reverse proxy on the same host) is listed by the owner.
 */
function trusted_proxy_preset(string $name): array
{
    if ($name !== 'cloudflare') {
        return [];
    }

    return [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

/** The configured proxy ranges, with presets expanded. */
function trusted_proxies(): array
{
    static $ranges = null;
    if ($ranges !== null) {
        return $ranges;
    }

    $ranges = [];
    $raw = function_exists('setting') ? (string) setting('sec_trusted_proxies', '') : '';
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        if ($entry === 'cloudflare' || $entry === 'cf') {
            $ranges = array_merge($ranges, trusted_proxy_preset('cloudflare'));
            continue;
        }
        if (preg_match('~^[0-9a-f.:]+(/\d{1,3})?$~', $entry) === 1) {
            $ranges[] = $entry;
        }
    }

    return $ranges = array_values(array_unique($ranges));
}

/** Is $ip inside $cidr ("203.0.113.4", "203.0.113.0/24", "2400:cb00::/32")? */
function ip_in_cidr(string $ip, string $cidr): bool
{
    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }
    [$subnet, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);

    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton((string) $subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    $bits    = $bits === null ? $maxBits : (int) $bits;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;

    if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }

    $mask = chr((0xFF << (8 - $rest)) & 0xFF);

    return (($ipBin[$whole] & $mask) === ($subnetBin[$whole] & $mask));
}

function is_trusted_proxy(string $ip): bool
{
    foreach (trusted_proxies() as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * The real client address.
 *
 * Forwarded headers are read ONLY when the machine that connected to us is a
 * configured proxy; otherwise anybody could set X-Forwarded-For and escape
 * every IP rate limit. With a trusted peer we take Cloudflare's own header
 * first, then the right-most X-Forwarded-For hop that is not itself a proxy.
 */
function resolve_client_ip(string $remote): string
{
    $remote = trim($remote);
    if ($remote === '' || trusted_proxies() === [] || !is_trusted_proxy($remote)) {
        return $remote;
    }

    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
        return $cf;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($hops as $hop) {
            $hop = preg_replace('/^\[|\](:\d+)?$/', '', $hop) ?? $hop;
            $hop = preg_replace('/:\d+$/', '', $hop) ?? $hop;   // "1.2.3.4:5678"
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (is_trusted_proxy($hop)) {
                continue;   // another hop of our own chain
            }
            return $hop;
        }
    }

    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
        return $real;
    }

    return $remote;
}
