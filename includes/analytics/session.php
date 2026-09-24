<?php
/**
 * ShopInnKart - Analytics identity, sessions and the two dictionaries.
 *
 * WHO A VISITOR IS, WITHOUT KNOWING WHO THEY ARE
 * ----------------------------------------------
 * Every hit resolves to a `vkey`, 16 bytes, which is all the identity this
 * system has. There are two ways to get one:
 *
 *   anonymous (the owner's mode)   sha256(today's salt | anonymised IP | UA | host)
 *   consented (a=1 in sik_consent) sha256('v|' | the sik_vid cookie value)
 *
 * The anonymous key is deliberately weak as an identifier:
 *   - the IP is cut to /24 (or /48) BEFORE it is hashed, so the input space is
 *     small enough that the hash is not a stored IP in disguise;
 *   - the salt is random per day and only today's and yesterday's are kept, so
 *     yesterday's keys cannot be matched to today's even by us;
 *   - which is exactly why unique-visitor counts are approximate: two people
 *     on one office router with the same phone model are one key, and one
 *     person is a new key every day. Every screen that shows visitors has to
 *     say so. That honesty is the price of not tracking anyone.
 *
 * SESSIONS
 * --------
 * A session is a vkey seen inside a 30-minute window, GA's rule, with GA's
 * exception: arriving on a new UTM campaign starts a new session even if the
 * old one is still warm, or a campaign's landing page would be credited to
 * whatever brought the visitor here yesterday.
 */

declare(strict_types=1);

// Creating a session parses the UA, classifies the source and looks the
// visitor's country up, so this file owns those dependencies rather than
// trusting every caller to have loaded them first. (It did not, and a caller
// that had loaded only session.php fataled the moment a NEW session appeared -
// which is the one path a smoke test on a warm database never reaches.)
require_once __DIR__ . '/classify.php';
require_once __DIR__ . '/geo.php';

/** A session ends after 30 minutes of silence. */
const AN_SESSION_GAP_MINUTES = 30;

/** Engaged = GA4's rule: 10 seconds in the foreground, or a second page, or a purchase. */
const AN_ENGAGED_MS = 10000;

// ===========================================================================
//  The daily salt
// ===========================================================================

/**
 * Today's salt, 32 random bytes, created on first use.
 *
 * Kept for two days only. Deleting yesterday's tomorrow is what makes the
 * anonymous key un-linkable across days: without the salt, no amount of
 * stored data can be turned back into "the same person, on Tuesday".
 */
function an_salt(?string $day = null): string
{
    static $cache = [];

    $day = $day ?? date('Y-m-d');
    if (isset($cache[$day])) {
        return $cache[$day];
    }

    $salt = Database::fetchColumn('SELECT `salt` FROM `an_salts` WHERE `day` = :d', ['d' => $day]);

    if ($salt === null || $salt === false || $salt === '') {
        $fresh = random_bytes(32);
        // INSERT IGNORE, then read back: two requests can race for the first
        // hit of the day, and both must end up using the SAME salt or the day
        // starts with two keys for one visitor.
        Database::query(
            'INSERT IGNORE INTO `an_salts` (`day`, `salt`) VALUES (:d, :s)',
            ['d' => $day, 's' => $fresh]
        );
        $salt = Database::fetchColumn('SELECT `salt` FROM `an_salts` WHERE `day` = :d', ['d' => $day]);

        // Housekeeping, once a day at most: only today and yesterday survive.
        Database::query('DELETE FROM `an_salts` WHERE `day` < :cutoff', ['cutoff' => date('Y-m-d', strtotime($day . ' -1 day'))]);
    }

    return $cache[$day] = (string) $salt;
}

/**
 * The 16-byte visitor key for this hit.
 *
 * $vid is the sik_vid cookie when the visitor has consented to analytics; it
 * takes precedence because it is stable across days and IP changes, which is
 * the whole reason consent buys better numbers.
 */
function an_visitor_key(string $ip, string $ua, string $host, ?string $vid = null, ?string $day = null): string
{
    if ($vid !== null && $vid !== '') {
        return substr(hash('sha256', 'v|' . $vid, true), 0, 16);
    }

    $anonymised = an_anonymise_ip($ip);

    return substr(hash('sha256', an_salt($day) . '|' . $anonymised . '|' . $ua . '|' . $host, true), 0, 16);
}

// ===========================================================================
//  Dictionaries
// ===========================================================================

/**
 * The id of a path in `an_paths`, creating the row on first sight.
 *
 * One statement, not a SELECT-then-INSERT: LAST_INSERT_ID(id) hands back the
 * existing id on a duplicate, so a page that is already known costs one write
 * and no read, and two simultaneous first hits cannot create two rows.
 *
 * The stored path is the URL only. Labels ("Diya String Lights") are resolved
 * at read time from the entity id, never from document.title, which the
 * browser controls and which changes when the product is renamed.
 */
function an_path_id(string $path, int $pageType, ?int $entityId): int
{
    $path = an_normalise_path($path);
    $hash = substr(hash('sha256', $path, true), 0, 8);

    // Read first. A store has a few hundred URLs and serves them over and
    // over, so all but the first hit on a page finds the row already there -
    // and an indexed SELECT costs 0.6 ms where the upsert below costs 9 ms,
    // because every write on this stack is a commit and a disk flush. That
    // difference is most of the collector's response time.
    $known = Database::fetchColumn('SELECT `id` FROM `an_paths` WHERE `hash` = :h', ['h' => $hash]);
    if ($known !== null && $known !== false) {
        return (int) $known;
    }

    // First sight of this URL. ON DUPLICATE KEY, not a plain INSERT, because
    // two first hits can race and both must end up with the same id rather
    // than one of them failing on the unique key.
    Database::query(
        'INSERT INTO `an_paths` (`hash`, `path`, `page_type`, `entity_id`, `first_seen`)
         VALUES (:h, :p, :t, :e, CURDATE())
         ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
        ['h' => $hash, 'p' => $path, 't' => $pageType, 'e' => $entityId > 0 ? $entityId : null]
    );

    return (int) Database::connect()->lastInsertId();
}

/** The id of a traffic source in `an_sources`, creating it on first sight. */
function an_source_id(string $name, int $channel): int
{
    $name = mb_substr(trim($name), 0, 100);
    if ($name === '') {
        return 0;
    }

    // Read first, for the same reason as an_path_id(): a store has a handful
    // of traffic sources and meets a new one perhaps once a week.
    $known = Database::fetchColumn('SELECT `id` FROM `an_sources` WHERE `name` = :n', ['n' => $name]);
    if ($known !== null && $known !== false) {
        return (int) $known;
    }

    Database::query(
        'INSERT INTO `an_sources` (`name`, `channel`) VALUES (:n, :c)
         ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
        ['n' => $name, 'c' => $channel]
    );

    return (int) Database::connect()->lastInsertId();
}

/**
 * A path safe to store: our own path, no host, no fragment, printable only.
 *
 * The browser sends this, so it is re-checked here even though analytics.js
 * already stripped it. The query allowlist is applied again for the same
 * reason - a token from a real page view is enough to POST any string, and
 * "?token=<live password reset token>" must not be able to land in a table the
 * reports later print.
 */
function an_normalise_path(string $raw): string
{
    $raw = str_replace(["\r", "\n", "\t"], '', trim($raw));

    // Anything absolute is reduced to its path; a foreign host is dropped.
    if (strpos($raw, '//') === 0 || preg_match('~^https?://~i', $raw) === 1) {
        $parts = @parse_url($raw);
        $raw   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    $raw = explode('#', $raw, 2)[0];
    [$path, $query] = array_pad(explode('?', $raw, 2), 2, '');

    $path = '/' . ltrim($path, '/');
    $path = preg_replace('~[^\x20-\x7E]~', '', $path) ?? '/';
    $path = str_replace(['"', "'", '<', '>', '\\'], '', $path);
    $path = mb_substr($path, 0, 400);

    $kept = an_allowed_query($query);

    return $kept === '' ? $path : $path . '?' . $kept;
}

/**
 * The only query parameters that may be stored, rebuilt in a fixed order.
 *
 * utm_* and `page` are kept as sent. A click id is kept as a bare flag with no
 * value, because the value IS the advertising identifier. `q` is kept only for
 * the search page, where the term is the page. Everything else - tokens,
 * emails, order numbers, session ids - is dropped.
 */
function an_allowed_query(string $query, bool $isSearch = false): string
{
    if ($query === '') {
        return '';
    }

    parse_str($query, $params);
    $kept = [];

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
        if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
            $kept[$key] = mb_substr($params[$key], 0, 100);
        }
    }

    foreach (array_keys(AN_CLICK_IDS) as $key) {
        if (!empty($params[$key])) {
            $kept[$key] = '1';      // presence, never the id itself
        }
    }

    if (isset($params['page']) && preg_match('/^\d{1,4}$/', (string) $params['page']) === 1) {
        $kept['page'] = (string) $params['page'];
    }

    if ($isSearch && isset($params['q']) && is_string($params['q']) && $params['q'] !== '') {
        $kept['q'] = mb_substr($params['q'], 0, 100);
    }

    return $kept === [] ? '' : http_build_query($kept);
}

// ===========================================================================
//  Sessions
// ===========================================================================

/**
 * The live session for this vkey, or null.
 *
 * Index ix_vkey_seen (vkey, last_seen_at) makes this a range scan of one
 * visitor's recent rows - 0.1 ms at the measured upper bound.
 */
function an_session_find(string $vkey): ?array
{
    return Database::fetch(
        'SELECT `id`, `campaign`, `source_id`, `pageviews`, `engaged_ms`, `funnel`, `user_id`
           FROM `an_sessions`
          WHERE `vkey` = :k AND `last_seen_at` >= NOW() - INTERVAL ' . AN_SESSION_GAP_MINUTES . ' MINUTE
          ORDER BY `id` DESC LIMIT 1',
        ['k' => $vkey]
    );
}

/**
 * Create a session row: the one place UA parsing, geo and source
 * classification happen. Everything else in the collector is counters.
 *
 * @param array $ctx vkey, ip, ua, host, hints, consented, user_id
 * @param array $landing path, page_type, entity_id, query (array), ref_host
 */
function an_session_create(array $ctx, array $landing): int
{
    $parsed = an_parse_ua((string) $ctx['ua'], (array) ($ctx['hints'] ?? []));
    $source = an_classify_source(
        (array) ($landing['query'] ?? []),
        (string) ($landing['ref_host'] ?? ''),
        (string) $ctx['host'],
        (string) $ctx['ua']
    );
    $geo = an_geo_lookup((string) ($ctx['ip'] ?? ''));

    // The caller has usually resolved the path already (it is this hit's own
    // path as well as the landing one); repeating the upsert would be a second
    // write for the same row.
    $pathId   = (int) ($landing['path_id'] ?? 0)
        ?: an_path_id((string) $landing['path'], (int) $landing['page_type'], (int) ($landing['entity_id'] ?? 0));
    $sourceId = an_source_id((string) $source['source'], an_channel_id($source['channel']));

    return Database::insert('an_sessions', [
        'vkey'            => $ctx['vkey'],
        'consented'       => !empty($ctx['consented']) ? 1 : 0,
        'user_id'         => ($ctx['user_id'] ?? 0) > 0 ? (int) $ctx['user_id'] : null,
        'visitor_type'    => an_visitor_type($ctx),
        'day'             => date('Y-m-d'),
        'started_at'      => date('Y-m-d H:i:s'),
        'last_seen_at'    => date('Y-m-d H:i:s'),
        'pageviews'       => 0,
        'landing_path_id' => $pathId,
        'current_path_id' => $pathId,
        'channel'         => an_channel_id($source['channel']),
        'source_id'       => $sourceId,
        'medium'          => $source['medium'] !== null ? mb_substr($source['medium'], 0, 50) : null,
        'campaign'        => $source['campaign'] !== null ? mb_substr($source['campaign'], 0, 100) : null,
        'click_id'        => an_click_id($source['click_id']),
        'country'         => $geo['country'],
        'region'          => $geo['region'],
        'city'            => $geo['city'],
        'device'          => an_device_id($parsed['device']),
        'browser'         => an_browser_id($parsed['browser']),
        'os'              => an_os_id($parsed['os']),
    ]);
}

/**
 * 0 unknown, 1 new, 2 returning.
 *
 * Honest by construction: an anonymous visitor is `unknown`, because a key
 * that is regenerated every day cannot tell a first visit from a fiftieth. A
 * consented visitor is known from an_visitors, and a signed-in customer is
 * known from their own earlier sessions. The screens say which share of the
 * data is known rather than presenting a guess as a fact.
 */
function an_visitor_type(array $ctx): int
{
    if (!empty($ctx['consented'])) {
        return Database::exists('an_visitors', '`vkey` = :k', ['k' => $ctx['vkey']]) ? 2 : 1;
    }

    $userId = (int) ($ctx['user_id'] ?? 0);
    if ($userId > 0) {
        return Database::exists('an_sessions', '`user_id` = :u', ['u' => $userId]) ? 2 : 1;
    }

    return 0;
}

/**
 * Remember a consented visitor across sessions. Consented only: this is the
 * one table that holds a key which survives the day, and it exists only
 * because the visitor said yes.
 */
function an_visitor_touch(string $vkey, ?int $userId): void
{
    Database::query(
        'INSERT INTO `an_visitors` (`vkey`, `first_seen`, `last_seen`, `sessions`, `user_id`)
         VALUES (:k, CURDATE(), CURDATE(), 1, :u)
         ON DUPLICATE KEY UPDATE `last_seen` = CURDATE(), `sessions` = `sessions` + 1,
                                 `user_id` = COALESCE(:u2, `user_id`)',
        ['k' => $vkey, 'u' => $userId, 'u2' => $userId]
    );
}

/**
 * Find the session this hit belongs to, or start one.
 *
 * The "new campaign starts a new session" rule lives here: a visitor who comes
 * back through a different ad in the same half hour is a new session, so the
 * campaign that brought them keeps the credit for what happens next.
 *
 * @return array{id:int, created:bool}
 */
function an_session_for_hit(array $ctx, array $landing): array
{
    $existing = an_session_find((string) $ctx['vkey']);

    if ($existing !== null) {
        $campaign = isset($landing['query']['utm_campaign'])
            ? mb_substr(strtolower(trim((string) $landing['query']['utm_campaign'])), 0, 100)
            : null;

        $sameCampaign = $campaign === null
            || $campaign === strtolower((string) ($existing['campaign'] ?? ''));

        if ($sameCampaign) {
            return ['id' => (int) $existing['id'], 'created' => false];
        }
    }

    $id = an_session_create($ctx, $landing);

    if (!empty($ctx['consented'])) {
        an_visitor_touch((string) $ctx['vkey'], ($ctx['user_id'] ?? 0) > 0 ? (int) $ctx['user_id'] : null);
    }

    return ['id' => $id, 'created' => true];
}
