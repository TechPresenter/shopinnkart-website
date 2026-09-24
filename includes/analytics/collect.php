<?php
/**
 * ShopInnKart - First-party analytics: the decision layer and the page token.
 *
 * This file is loaded by TWO very different callers, and that is deliberate:
 *
 *   1. includes/footer.php, on every storefront page, to ask "is this page
 *      view counted at all?" and, if so, to mint the signed page token that
 *      the beacon will present. That path must be cheap: it costs no query
 *      beyond the settings the page already read.
 *
 *   2. api/analytics/collect.php, the collector, which re-asks every one of
 *      those questions from the beacon's point of view before it writes a row.
 *
 * One file, one set of answers. A tracking system whose client and server
 * disagree about who is tracked produces numbers nobody can defend.
 *
 * THE PAGE TOKEN, AND WHAT IT IS FOR
 * ----------------------------------
 *     base64url(ver | ts | user_id | page_type | entity | nonce) . '.' . hmac16
 *
 * It is not a security boundary around anything valuable - it guards a counter
 * - but it does three jobs no cheaper mechanism does:
 *
 *   - it proves the hit came from a page THIS server rendered, so a stranger's
 *     form POST from another site cannot invent traffic (they would have to
 *     fetch a real page first, which is a page view anyway);
 *   - it carries the logged-in user id and the page's identity, so the
 *     collector needs no session - no session lock contention with the page it
 *     is measuring, and no empty `carts` row per beacon;
 *   - its nonce is the pageview's primary key, so a duplicated beacon is an
 *     INSERT IGNORE that changes nothing rather than a second page view.
 *
 * The page type and entity id are inside the signature on purpose: the browser
 * may say where it is, but it may not say WHAT it is, or a script could report
 * ten thousand views of one product page.
 */

declare(strict_types=1);

require_once __DIR__ . '/classify.php';

// The consent layer answers "may this visitor be counted at all". The
// storefront footer has it loaded already; an API endpoint firing a commerce
// event (api/cart/add.php) does not, and the collector loads it itself.
if (!function_exists('consent_allows')) {
    require_once INCLUDES_PATH . '/consent.php';
}

/** Token version. Bump to invalidate every outstanding token at once. */
const AN_TOKEN_VERSION = 1;

/** A token is good for 24 hours - long enough for a tab left open overnight. */
const AN_TOKEN_TTL = 86400;

/** Per-token caps. A real page cannot exceed these; a script trying to will. */
const AN_CAP_PV     = 5;    // bfcache re-shows
const AN_CAP_HB     = 30;   // 30 minutes of heartbeats
const AN_CAP_END    = 3;
const AN_CAP_EVENTS = 20;

/** How often the client sends a heartbeat, in seconds. */
const AN_HEARTBEAT = 60;

/**
 * One pageview's whole beacon budget: the pv itself, its heartbeats, its end
 * beacon and any client events fired from it. The table counts these in one
 * `hits` column, so they share one ceiling instead of three - which bounds the
 * writes a single token can cause just as tightly, and needs no extra columns.
 */
const AN_CAP_HITS = 1 + AN_CAP_HB + AN_CAP_END + AN_CAP_EVENTS;

/**
 * The cookie that identifies a CONSENTED visitor across days.
 *
 * Server-set (HttpOnly, 13 months) because Safari's ITP caps a script-written
 * cookie at 7 days, which would silently turn consented visitors back into
 * anonymous ones after a week. It exists only when the visitor said yes; in
 * the owner's anonymous mode it is never set at all.
 */
const AN_VID_COOKIE = 'sik_vid';

// ===========================================================================
//  Is anything counted?
// ===========================================================================

/** off | anonymous | consent_only | notice. Anything unknown means off. */
function analytics_mode(): string
{
    $mode = (string) setting('analytics_mode', 'off');

    return in_array($mode, ['off', 'anonymous', 'consent_only', 'notice'], true) ? $mode : 'off';
}

/**
 * Does the visitor's consent state allow first-party counting in this mode?
 *
 *   anonymous     - nothing is stored on the device and no IP is kept, so
 *                   there is nothing to consent to. Counted.
 *   consent_only  - counted only after an explicit yes.
 *   notice        - counted unless the visitor has explicitly said no.
 */
function analytics_consent_ok(): bool
{
    switch (analytics_mode()) {
        case 'consent_only':
            return consent_allows('analytics');
        case 'notice':
            $stored = consent_stored();
            return $stored === null || $stored['analytics'];
        case 'anonymous':
            return true;
        default:
            return false;
    }
}

/**
 * The master answer, used at render time and again in the collector.
 *
 * Order matters only for cost: the free checks come first so a switched-off
 * store does no work at all.
 *
 * @param string $ua the UA to judge; defaults to this request's
 */
function analytics_tracking_allowed(string $ua = null): bool
{
    if (analytics_mode() === 'off') {
        return false;
    }

    // A signal the owner has chosen to honour outranks everything stored.
    if (consent_signal_active()) {
        return false;
    }

    // The shop and the back office share one session. The owner walking their
    // own storefront is not the store's traffic.
    if (function_exists('admin_user') && admin_user() !== null) {
        return false;
    }

    if (!analytics_consent_ok()) {
        return false;
    }

    $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (an_is_bot($ua)) {
        return false;
    }

    return !analytics_ip_excluded(client_ip());
}

/**
 * The owner's own office, warehouse or VPN, from Settings.
 *
 * Comma or newline separated, CIDR or bare address. Empty is the normal case
 * and costs one trim.
 */
function analytics_ip_excluded(string $ip): bool
{
    $list = trim((string) setting('analytics_exclude_ips', ''));
    if ($list === '' || $ip === '') {
        return false;
    }

    require_once __DIR__ . '/geo.php';

    $ranges = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return an_ip_in_any($ip, $ranges);
}

// ===========================================================================
//  The page token
// ===========================================================================

/** The HMAC key: derived, so it is not the same secret that encrypts SMTP passwords. */
function analytics_token_key(): string
{
    static $key = null;

    if ($key === null) {
        $key = function_exists('app_key_derive') ? app_key_derive('analytics-token') : '';
    }

    return $key;
}

/**
 * Mint a token for the page being rendered.
 *
 * Returns '' when there is no application key, which is a broken install
 * (config/ not writable); tracking then stays off rather than accepting
 * unsigned hits.
 */
function analytics_page_token(int $pageType, int $entityId, ?int $userId): string
{
    $key = analytics_token_key();
    if ($key === '') {
        return '';
    }

    $payload = pack(
        'CNNCN',
        AN_TOKEN_VERSION,
        time(),
        max(0, (int) $userId),
        max(0, min(255, $pageType)),
        max(0, $entityId)
    ) . random_bytes(8);

    return analytics_b64($payload) . '.' . substr(hash_hmac('sha256', $payload, $key), 0, 16);
}

/**
 * Check a token and unpack it.
 *
 * @return array{ver:int, ts:int, user_id:int, page_type:int, entity_id:int, nonce:string}|null
 */
function analytics_verify_token(string $token): ?array
{
    $key = analytics_token_key();
    if ($key === '' || strlen($token) > 120 || strpos($token, '.') === false) {
        return null;
    }

    [$encoded, $mac] = explode('.', $token, 2);

    $payload = analytics_b64_decode($encoded);
    if ($payload === '' || strlen($payload) !== 22) {
        return null;
    }

    // hash_equals, not ===: a timing oracle on a counter token is not much of
    // a prize, but the habit costs nothing and the next copy of this code
    // might guard something that matters.
    if (!hash_equals(substr(hash_hmac('sha256', $payload, $key), 0, 16), $mac)) {
        return null;
    }

    $parts = unpack('Cver/Nts/Nuid/Cpt/Nentity', substr($payload, 0, 14));
    if (!is_array($parts) || (int) $parts['ver'] !== AN_TOKEN_VERSION) {
        return null;
    }

    $ts  = (int) $parts['ts'];
    $now = time();
    // 300 s of slack forwards for a clock that runs fast; a token from the
    // future by more than that was not minted here.
    if ($ts > $now + 300 || $ts < $now - AN_TOKEN_TTL) {
        return null;
    }

    return [
        'ver'       => (int) $parts['ver'],
        'ts'        => $ts,
        'user_id'   => (int) $parts['uid'],
        'page_type' => (int) $parts['pt'],
        'entity_id' => (int) $parts['entity'],
        'nonce'     => substr($payload, 14, 8),
    ];
}

function analytics_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function analytics_b64_decode(string $encoded): string
{
    if (preg_match('~^[A-Za-z0-9_-]{1,64}$~', $encoded) !== 1) {
        return '';
    }

    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

    return $decoded === false ? '' : $decoded;
}

// ===========================================================================
//  What page is this?
// ===========================================================================

/**
 * Page type and entity id for the page being rendered.
 *
 * The map is by script name because that is what the storefront routes to
 * (/product/<slug> is product.php). The entity comes from the variable the
 * page already has at global scope, or from an explicit analytics_page() call
 * - a page that wants to be counted as something else says so rather than
 * having this file guess.
 *
 * @return array{page_type:int, entity_id:int}
 */
function analytics_page_context(): array
{
    if (isset($GLOBALS['SIK_PAGE_CONTEXT']) && is_array($GLOBALS['SIK_PAGE_CONTEXT'])) {
        return $GLOBALS['SIK_PAGE_CONTEXT'];
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

    static $map = [
        'index.php'    => 1,
        'category.php' => 2,
        'brand.php'    => 14,
        'product.php'  => 3,
        'combo.php'    => 4,
        'search.php'   => 5,
        'cart.php'     => 6,
        'checkout.php' => 7,
        'account.php' => 8, 'orders.php' => 8, 'order-details.php' => 8, 'profile.php' => 8,
        'addresses.php' => 8, 'wishlist.php' => 8, 'notifications.php' => 8, 'preferences.php' => 8,
        'my-reviews.php' => 8, 'purchase-history.php' => 8, 'account-security.php' => 8,
        'change-password.php' => 8,
        'about.php' => 9, 'contact.php' => 9, 'faq.php' => 9, 'page.php' => 9, 'terms.php' => 9,
        'privacy-policy.php' => 9, 'refund-policy.php' => 9, 'shipping-policy.php' => 9,
        'return-policy.php' => 9, 'warranty.php' => 9,
        'blog.php' => 10, 'blog-post.php' => 10,
        'shop.php' => 11, 'deals.php' => 11, 'new-arrivals.php' => 11, 'best-sellers.php' => 11,
        'combos.php' => 11, 'brands.php' => 11,
        'order-success.php' => 12, 'track-order.php' => 12,
        'login.php' => 13, 'register.php' => 13, 'forgot-password.php' => 13, 'reset-password.php' => 13,
        'verify-email.php' => 13, 'login-2fa.php' => 13, 'newsletter-unsubscribe.php' => 13,
    ];

    $pageType = $map[$script] ?? 0;
    $entityId = 0;

    // The page's own variable, read only for the SCRIPT it belongs to - not
    // merely for the page type. blog.php and blog-post.php share a type, and
    // blog.php's listing loop leaves a $post in global scope: read by type,
    // the blog index reported itself as a view of whichever post happened to
    // be last on the page, and that post's view count in the reports was the
    // index page's traffic.
    switch ($script) {
        case 'category.php':
            $entityId = (int) ($GLOBALS['category']['id'] ?? 0);
            break;
        case 'brand.php':
            $entityId = (int) ($GLOBALS['brand']['id'] ?? 0);
            break;
        case 'product.php':
            $entityId = (int) ($GLOBALS['productId'] ?? ($GLOBALS['detail']['id'] ?? 0));
            break;
        case 'combo.php':
            $entityId = (int) ($GLOBALS['combo']['id'] ?? 0);
            break;
        case 'blog-post.php':
            $entityId = (int) ($GLOBALS['post']['id'] ?? 0);
            break;
    }

    return ['page_type' => $pageType, 'entity_id' => max(0, $entityId)];
}

/** A page declares itself explicitly. Call before including the footer. */
function analytics_page(int $pageType, int $entityId = 0): void
{
    $GLOBALS['SIK_PAGE_CONTEXT'] = ['page_type' => $pageType, 'entity_id' => max(0, $entityId)];
}

// ===========================================================================
//  What the footer prints
// ===========================================================================

/**
 * The `an` block of window.SIK_CONFIG, or null when this page is not counted.
 *
 * null is the signal that analytics.js is not loaded at all: a store with
 * tracking off ships no script, no config and no beacon.
 */
function analytics_js_config(): ?array
{
    if (PHP_SAPI === 'cli' || !analytics_tracking_allowed()) {
        return null;
    }

    $context = analytics_page_context();
    $userId  = function_exists('current_user_id') ? (int) (current_user_id() ?? 0) : 0;
    $token   = analytics_page_token($context['page_type'], $context['entity_id'], $userId);

    if ($token === '') {
        return null;
    }

    return [
        'u'  => api_url('analytics/collect.php'),
        't'  => $token,
        'pt' => $context['page_type'],
        'id' => $context['entity_id'],
        'hb' => AN_HEARTBEAT,
        // Core Web Vitals on every view: the volume is small enough that
        // sampling would leave a p75 computed from a handful of numbers.
        'v'  => 1,
    ];
}

// ===========================================================================
//  Rejection counters
// ===========================================================================

/**
 * Count a refusal in an_state.
 *
 * Every rejection still answers 204 - telling a script which check it failed
 * is telling it what to fix - so these counters are the only way the owner
 * ever sees that something was refused, and the only way we find out that a
 * real browser is being refused by mistake.
 */
function analytics_reject(string $reason): void
{
    try {
        Database::query(
            'INSERT INTO `an_state` (`k`, `v`, `updated_at`) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE `v` = CAST(`v` AS UNSIGNED) + 1, `updated_at` = NOW()',
            ['k' => mb_substr('rejects.' . $reason, 0, 40), 'v' => '1']
        );
    } catch (Throwable $e) {
        // A counter must never be the reason a beacon errors.
    }
}

/** Read a counter (or any an_state key). */
function analytics_state(string $key, string $default = ''): string
{
    try {
        $value = Database::fetchColumn('SELECT `v` FROM `an_state` WHERE `k` = :k', ['k' => $key]);
        return $value === null ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Write an an_state key. */
function analytics_state_set(string $key, string $value): void
{
    try {
        Database::query(
            'INSERT INTO `an_state` (`k`, `v`, `updated_at`) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE `v` = :v2, `updated_at` = NOW()',
            ['k' => mb_substr($key, 0, 40), 'v' => $value, 'v2' => $value]
        );
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'an_state write failed: ' . $e->getMessage());
    }
}

/** Do the analytics tables exist yet? Cached per request; used by the hooks. */
function analytics_tables_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        try {
            // A trivial read rather than SHOW TABLES: it is one index-free but
            // instantly-answered statement, and it works the same on a table
            // that exists and is empty.
            Database::fetchColumn('SELECT 1 FROM `an_sessions` LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }

    return $ready;
}
