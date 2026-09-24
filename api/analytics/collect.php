<?php
/**
 * ShopInnKart - First-party analytics collector.
 *
 * The only endpoint assets/js/analytics.js talks to. It answers 204 to
 * everything - a real browser, a replayed beacon, a crawler, a forged token -
 * because a collector that reports which check you failed is a collector that
 * tells a script how to pass. Refusals are counted in `an_state` instead, so
 * the owner can see them on the settings screen and we can see whether a real
 * browser is being turned away by mistake.
 *
 * WHY THIS FILE DOES NOT LOAD includes/init.php
 * ---------------------------------------------
 * init.php starts a session. A session means a session-file lock, and a
 * beacon that fires while the page it came from is still loading would queue
 * behind that page's own lock - tracking would slow down the thing it is
 * measuring. It also means a `carts` row is minted for every beacon (the
 * measured cause of 99.4% of that table being empty), a mailer, a cart, an
 * order library and a security-header pass, none of which a counter needs.
 *
 * So this is a deliberate lean bootstrap: config, the database, the shared
 * helpers, the consent layer, the analytics library. Nothing on this path may
 * require init.php - see SIK_LEAN_BOOTSTRAP in includes/consent.php.
 *
 * WHAT ARRIVES
 * ------------
 * navigator.sendBeacon(url, URLSearchParams) sends an ordinary
 * application/x-www-form-urlencoded POST, so the payload is in $_POST. Fields:
 *
 *   t   signed page token (required, always)
 *   k   pv | hb | end | ev
 *   pv  u path, r referrer, w viewport width, tp maxTouchPoints, m mobile hint,
 *       p platform hint, s sequence (bfcache re-show)
 *   hb  q seq, e engaged ms (absolute for this pageview), d engaged delta,
 *       s max scroll %
 *   end as hb, plus lcp / inp / cls / fcp / ttfb
 *   ev  n event name (client-reportable names only), lb label
 *
 * IF A FULL-PAGE CACHE IS EVER TURNED ON (LiteSpeed): a cached page serves one
 * visitor's token to everybody, and the nonce dedupe would collapse all of
 * their page views into one row. The fix is a GET token endpoint, rate-limited
 * per IP; it is documented here and deliberately not built, because nothing in
 * this stack caches HTML today.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
//  Lean bootstrap
// ---------------------------------------------------------------------------
define('SIK_LEAN_BOOTSTRAP', true);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once INCLUDES_PATH . '/error-handler.php';
ErrorHandler::register();
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once INCLUDES_PATH . '/functions.php';
// app_key() / app_key_derive() live here. The file only defines functions;
// PHPMailer itself is loaded on demand and is not touched by this path.
require_once INCLUDES_PATH . '/mailer.php';
require_once INCLUDES_PATH . '/rate-limit.php';
require_once INCLUDES_PATH . '/consent.php';
require_once INCLUDES_PATH . '/analytics/collect.php';
require_once INCLUDES_PATH . '/analytics/session.php';
require_once INCLUDES_PATH . '/analytics/geo.php';

// A warning printed into a 204 is not a response, and this endpoint has no
// reader to show it to. Everything of interest goes to the log instead.
ini_set('display_errors', '0');

$started = hrtime(true);

/** The only answer this endpoint ever gives. */
function an_done(): void
{
    if (!headers_sent()) {
        http_response_code(204);
        header('Cache-Control: no-store');
        header('Content-Length: 0');
    }
    exit;
}

/**
 * Refuse, count it, and answer 204 anyway.
 *
 * The rate limiter is on THIS path only, so good traffic never touches it and
 * a real page view costs no limiter query.
 *
 * WHAT THE CAP DOES AND DOES NOT BUY. Past 600 refusals a minute from one
 * address it stops the `an_state` counter write - but rate_limit_attempt()
 * itself is an upsert plus a read on every call, capped or not. So a forged
 * beacon costs 2 statements under the cap and 1 write above it, never zero.
 * Measured: 300 forged beacons from one address wrote no analytics row and
 * left the store serving normally; the cost is a `rate_limits` row being
 * incremented, which is one indexed upsert. If a real flood ever makes that
 * matter, the answer is to sample the limiter rather than to remove it -
 * removing it puts the an_state write back on every forged beacon instead.
 */
function an_refuse(string $reason): void
{
    if (rate_limit_attempt('an.collect', client_ip(), 600, 60)) {
        analytics_reject($reason);
    }
    an_done();
}

try {
    // ---- 1. Shape ---------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        an_refuse('method');
    }

    // A beacon is under 1 KB. Anything larger is not one of ours.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) {
        an_refuse('body');
    }

    // ---- 2. Same-origin ---------------------------------------------------
    // Sec-Fetch-Site is sent by every browser that can run this script. When
    // it is absent (an old browser, or a script pretending to be one) the
    // token check below is what stands.
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin') {
        an_refuse('site');
    }

    // ---- 3. The page token ------------------------------------------------
    $token = analytics_verify_token((string) ($_POST['t'] ?? ''));
    if ($token === null) {
        an_refuse('token');
    }

    // ---- 4. Bots ----------------------------------------------------------
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (an_is_bot($ua)) {
        an_refuse('bot');
    }

    // ---- 5. Is this store counting at all? --------------------------------
    // Re-asked here, not assumed from the token: the owner may have switched
    // analytics off, or this visitor may have sent a GPC header, since the
    // page that minted it was rendered.
    if (analytics_mode() === 'off') {
        an_refuse('mode');
    }
    if (consent_signal_active()) {
        an_refuse('dnt');
    }
    if (!analytics_consent_ok()) {
        an_refuse('consent');
    }
    if (analytics_ip_excluded(client_ip())) {
        an_refuse('excluded');
    }

    $kind = (string) ($_POST['k'] ?? '');
    if (!in_array($kind, ['pv', 'hb', 'end', 'ev'], true)) {
        an_refuse('kind');
    }

    // ---- 6. Who is this? --------------------------------------------------
    // The consented identity comes from a cookie the SERVER set; the anonymous
    // one is recomputed from scratch every time and stored nowhere.
    $consent   = consent_stored();
    $consented = $consent !== null && $consent['analytics'];
    $vid       = $consented ? (string) ($_COOKIE[AN_VID_COOKIE] ?? '') : '';

    if ($consented && $vid === '') {
        // First consented hit: mint the durable id. 13 months, HttpOnly, so no
        // script - ours or anyone's - can read or forge it.
        $vid = bin2hex(random_bytes(16));
        setcookie(AN_VID_COOKIE, $vid, [
            'expires'  => time() + 34164000,
            'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
            'secure'   => session_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $context = [
        'vkey'      => an_visitor_key(client_ip(), $ua, analytics_collect_host(), $vid !== '' ? $vid : null),
        'ip'        => client_ip(),
        'ua'        => $ua,
        'host'      => analytics_collect_host(),
        'consented' => $consented,
        'user_id'   => (int) $token['user_id'],
        'hints'     => [
            'mobile'   => isset($_POST['m']) ? ((string) $_POST['m'] === '1') : null,
            'platform' => an_clean((string) ($_POST['p'] ?? ''), 30),
            'touch'    => (int) ($_POST['tp'] ?? 0),
        ],
    ];

    switch ($kind) {
        case 'pv':
            an_handle_pageview($token, $context);
            break;
        case 'hb':
        case 'end':
            an_handle_engagement($token, $kind === 'end');
            break;
        case 'ev':
            an_handle_event($token);
            break;
    }
} catch (Throwable $e) {
    // Nothing a visitor does should be able to see this, and nothing here is
    // worth failing a page over.
    ErrorHandler::log('warning', 'analytics collector: ' . $e->getMessage(), $e->getFile(), $e->getLine());
}

// Timing goes to the log only when the owner (or a test bench) asks for it in
// the settings, never on a field the caller controls - an endpoint whose log
// verbosity a stranger can turn on is a disk-filling endpoint.
if (setting_bool('analytics_debug_timing', false)) {
    ErrorHandler::log('debug', sprintf('an.collect %s in %.2f ms', (string) ($_POST['k'] ?? '?'), (hrtime(true) - $started) / 1e6));
}

an_done();

// ===========================================================================
//  Handlers
// ===========================================================================

/**
 * A page view.
 *
 * The path comes from the browser and is re-cleaned here; the page TYPE and
 * entity id come from inside the signature, so a script cannot claim its hits
 * happened on a product page.
 */
function an_handle_pageview(array $token, array $context): void
{
    $seq = max(0, min(255, (int) ($_POST['s'] ?? 0)));
    if ($seq >= AN_CAP_PV) {
        an_refuse('cap_pv');
    }

    $isSearch = (int) $token['page_type'] === 5;
    $path     = an_normalise_path((string) ($_POST['u'] ?? '/'));

    // The search term is the page on a search results page, and only there.
    if ($isSearch) {
        $raw   = (string) ($_POST['u'] ?? '');
        $query = strpos($raw, '?') === false ? '' : substr($raw, strpos($raw, '?') + 1);
        $kept  = an_allowed_query($query, true);
        $path  = explode('?', $path, 2)[0] . ($kept === '' ? '' : '?' . $kept);
    }

    $pathId = an_path_id($path, (int) $token['page_type'], (int) $token['entity_id']);

    parse_str(strpos($path, '?') === false ? '' : substr($path, strpos($path, '?') + 1), $query);

    $session = an_session_for_hit($context, [
        'path'      => $path,
        'path_id'   => $pathId,
        'page_type' => (int) $token['page_type'],
        'entity_id' => (int) $token['entity_id'],
        'query'     => is_array($query) ? $query : [],
        // Only the host. A full referrer can carry another site's query string,
        // which is their visitor's data, not ours to store.
        'ref_host'  => an_referrer_host((string) ($_POST['r'] ?? '')),
    ]);

    // INSERT IGNORE: the nonce is unique per rendered page, so a beacon that
    // arrives twice (a retry, a double sendBeacon on a flaky connection)
    // changes nothing at all.
    $inserted = Database::query(
        'INSERT IGNORE INTO `an_pageviews`
            (`nonce`, `seq`, `session_id`, `created_at`, `path_id`, `page_type`, `entity_id`, `hits`)
         VALUES (:n, :q, :s, NOW(), :p, :t, :e, 1)',
        [
            'n' => $token['nonce'], 'q' => $seq, 's' => $session['id'], 'p' => $pathId,
            't' => (int) $token['page_type'],
            'e' => (int) $token['entity_id'] > 0 ? (int) $token['entity_id'] : null,
        ]
    )->rowCount();

    if ($inserted === 0) {
        // Duplicate nonce+seq: this page view is already counted. The session
        // counters must not move a second time either.
        //
        // AND NEITHER MAY A SESSION ROW SURVIVE. an_session_for_hit() runs
        // above this insert because a page view needs a session id, so a
        // replay that lands INSIDE the 30-minute window harmlessly finds the
        // original session - but one that lands outside it finds nothing and
        // CREATES a session, which the ignored insert then leaves behind with
        // no page view on it. A token is good for 24 hours, so replaying one
        // genuine beacon every 31 minutes minted about 46 phantom sessions,
        // each of them a bounce in every report. Measured, not theorised:
        // five out-of-window replays produced six sessions and one page view.
        //
        // `pageviews` = 0 in the WHERE is the safety catch: it can only ever
        // remove the empty row this request just made, never one that a
        // concurrent beacon has already attached itself to.
        //
        // One residue, stated rather than hidden: for a CONSENTED visitor the
        // session creation above also bumped an_visitors.sessions, and that
        // increment is not rolled back here. It is dormant in the owner's
        // anonymous mode, where an_visitors is never written at all.
        // A plain return, not an_refuse(): a duplicate beacon is usually one of
        // OURS - sendBeacon retried on a flaky connection - and charging that
        // a limiter upsert plus a counter write would put two writes on the
        // normal retry path to diagnose something that is not a problem.
        if (!empty($session['created'])) {
            Database::query(
                'DELETE FROM `an_sessions` WHERE `id` = :id AND `pageviews` = 0',
                ['id' => $session['id']]
            );
        }

        return;
    }

    // Funnel bit 1 = "saw a product". The other three bits are set by the
    // server-side events, which is why cart and purchase numbers survive an
    // ad blocker and this one does not claim to.
    $viewBit = (int) $token['page_type'] === 3 ? 1 : 0;

    // Assignments are evaluated left to right, so `pageviews` is already
    // incremented when is_engaged reads it - which is what we want: two
    // pageviews is an engaged session by GA's definition.
    Database::query(
        'UPDATE `an_sessions`
            SET `pageviews` = `pageviews` + 1,
                `is_engaged` = (`pageviews` >= 2 OR `engaged_ms` >= ' . AN_ENGAGED_MS . '),
                `current_path_id` = :p,
                `funnel` = `funnel` | :b,
                `last_seen_at` = NOW()
          WHERE `id` = :id',
        ['p' => $pathId, 'b' => $viewBit, 'id' => $session['id']]
    );
}

/**
 * A heartbeat or the end-of-page beacon.
 *
 * Two writes, no reads: the pageview row is found by its nonce, and the
 * session is reached through it with a joined UPDATE. GREATEST() means a
 * beacon that arrives out of order (they do) can only ever raise a number.
 */
function an_handle_engagement(array $token, bool $isEnd): void
{
    $seq      = max(0, min(255, (int) ($_POST['q'] ?? 0)));
    $engaged  = max(0, min(3600000, (int) ($_POST['e'] ?? 0)));     // absolute, this pageview
    $delta    = max(0, min(300000, (int) ($_POST['d'] ?? 0)));      // since the last beacon
    $scroll   = max(0, min(100, (int) ($_POST['s'] ?? 0)));

    $updated = Database::query(
        'UPDATE `an_pageviews`
            SET `engaged_ms` = GREATEST(`engaged_ms`, :e),
                `scroll_pct` = GREATEST(COALESCE(`scroll_pct`, 0), :s),
                `hits` = `hits` + 1
          WHERE `nonce` = :n AND `seq` = :q AND `hits` < ' . AN_CAP_HITS,
        ['e' => $engaged, 's' => $scroll, 'n' => $token['nonce'], 'q' => $seq]
    )->rowCount();

    if ($updated === 0) {
        // Either the pv never landed (so there is nothing to attach this to)
        // or this token has spent its budget.
        an_refuse('target');
    }

    // Single-table UPDATE with the session reached through a SUBQUERY, not a
    // JOIN - and that is the whole point, not a style choice. MySQL evaluates
    // the assignments of a single-table UPDATE left to right, so `engaged_ms`
    // is already the new value when `is_engaged` reads it; for a MULTI-table
    // UPDATE the order is explicitly undefined, and on MariaDB 10.4 it runs
    // the other way. The joined version of this statement therefore judged
    // engagement on the engaged time from BEFORE the heartbeat, so a visitor
    // who read one page for ten seconds - the exact case GA4 calls engaged -
    // was filed as a bounce until their second page view. Caught by
    // "10 s of attention is an engaged session" in test_analytics_b2.php.
    Database::query(
        'UPDATE `an_sessions`
            SET `engaged_ms` = `engaged_ms` + :d,
                `is_engaged` = (`pageviews` >= 2 OR `engaged_ms` >= ' . AN_ENGAGED_MS . '),
                `last_seen_at` = NOW()
          WHERE `id` = (SELECT `session_id` FROM `an_pageviews` WHERE `nonce` = :n AND `seq` = :q)',
        ['d' => $delta, 'n' => $token['nonce'], 'q' => $seq]
    );

    if ($isEnd) {
        an_record_vitals($token, $seq);
    }
}

/**
 * Core Web Vitals, once per page, on the end beacon.
 *
 * Stored with the page type and device so the report can say "LCP is fine on
 * desktop and terrible on the product page on mobile", which is the only form
 * of this number anybody can act on. Safari reports none of them, which is why
 * B5's screen shows sample counts per browser next to the percentile.
 */
function an_record_vitals(array $token, int $seq): void
{
    $metrics = [
        'lcp_ms'    => an_metric('lcp', 120000),
        'inp_ms'    => an_metric('inp', 120000),
        'cls_x1000' => an_metric('cls', 65535),
        'fcp_ms'    => an_metric('fcp', 120000),
        'ttfb_ms'   => an_metric('ttfb', 120000),
    ];

    if (count(array_filter($metrics, static fn ($v) => $v !== null)) === 0) {
        return;
    }

    $row = Database::fetch(
        'SELECT p.`session_id`, p.`page_type`, s.`device`, s.`browser`
           FROM `an_pageviews` p JOIN `an_sessions` s ON s.`id` = p.`session_id`
          WHERE p.`nonce` = :n AND p.`seq` = :q LIMIT 1',
        ['n' => $token['nonce'], 'q' => $seq]
    );

    if ($row === null) {
        return;
    }

    Database::insert('an_vitals', array_merge([
        'created_at' => date('Y-m-d H:i:s'),
        'session_id' => (int) $row['session_id'],
        'page_type'  => (int) $row['page_type'],
        'device'     => (int) $row['device'],
        'browser'    => (int) $row['browser'],
    ], $metrics));
}

/** One vitals number from the beacon, or null when the browser did not report it. */
function an_metric(string $field, int $max): ?int
{
    if (!isset($_POST[$field]) || $_POST[$field] === '') {
        return null;
    }

    $value = (int) round((float) $_POST[$field]);

    return $value < 0 ? null : min($value, $max);
}

/**
 * A client-reported event.
 *
 * The name must be on AN_CLIENT_EVENTS. Nothing here can carry a price, a
 * quantity or an order: those are written by the server (see
 * includes/analytics/events.php), which is what makes them trustworthy.
 */
function an_handle_event(array $token): void
{
    $name = an_clean((string) ($_POST['n'] ?? ''), 30);
    if (!in_array($name, AN_CLIENT_EVENTS, true)) {
        an_refuse('event_name');
    }

    $seq = max(0, min(255, (int) ($_POST['q'] ?? 0)));

    $updated = Database::query(
        'UPDATE `an_pageviews` SET `hits` = `hits` + 1
          WHERE `nonce` = :n AND `seq` = :q AND `hits` < ' . AN_CAP_HITS,
        ['n' => $token['nonce'], 'q' => $seq]
    )->rowCount();

    if ($updated === 0) {
        an_refuse('target');
    }

    $sessionId = Database::fetchColumn(
        'SELECT `session_id` FROM `an_pageviews` WHERE `nonce` = :n AND `seq` = :q LIMIT 1',
        ['n' => $token['nonce'], 'q' => $seq]
    );

    Database::insert('an_events', [
        'session_id' => $sessionId === null ? null : (int) $sessionId,
        'created_at' => date('Y-m-d H:i:s'),
        'name'       => an_event_id($name),
        'label'      => an_clean((string) ($_POST['lb'] ?? ''), 100) ?: null,
    ]);
}

// ===========================================================================
//  Small helpers
// ===========================================================================

/** Our own host, for the visitor key and the "is this referrer ours" test. */
function analytics_collect_host(): string
{
    static $host = null;

    if ($host === null) {
        $host = strtolower((string) (parse_url(SITE_URL, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '')));
    }

    return $host;
}

/** The host of a referrer, and nothing else of it. */
function an_referrer_host(string $referrer): string
{
    if ($referrer === '' || strlen($referrer) > 512) {
        return '';
    }

    // analytics.js sends the host already; accept a full URL too, because a
    // future caller might not.
    $host = strpos($referrer, '/') === false ? $referrer : (string) (parse_url($referrer, PHP_URL_HOST) ?: '');

    return preg_match('/^[a-z0-9.\-]{1,190}$/i', $host) === 1 ? strtolower($host) : '';
}

/** Printable ASCII only, trimmed to length. Nothing from the browser is trusted raw. */
function an_clean(string $value, int $max): string
{
    $value = preg_replace('~[^\x20-\x7E]~', '', trim($value)) ?? '';

    return substr($value, 0, $max);
}
