<?php
/**
 * ShopInnKart - The watchtower.
 *
 * Three jobs that all hang off one idea: the app already WRITES down what
 * looked like an attack (includes/security-events.php). Nothing was reading it
 * back. This file reads it back, and adds the two things a log alone cannot
 * do - refuse an address at the door, and tell the owner without being asked.
 *
 *  1. IP RULES (block / allow). Applied from security_headers_send(), before
 *     the page does any work. Allow always beats block, every rule carries a
 *     reason and may carry an expiry, and an admin cannot create a rule that
 *     would shut the door on the address they are sitting at.
 *
 *  2. REQUEST PROBES. Two things that cannot be seen after the fact because
 *     nothing records them today: a request for /wp-login.php or /.env, and a
 *     burst of 404s that is somebody walking the directory tree. Both are
 *     COUNTED cheaply (one row in `rate_limits`, the same table the login
 *     throttles use) and only become a `security_events` row when the count
 *     crosses the line - otherwise a scanner hitting 10,000 URLs would write
 *     10,000 rows describing one scan.
 *
 *  3. CSP REPORTS + THE DETECTORS. Violation reports arrive grouped
 *     (see csp_report_record), and bin/security-monitor.php sweeps the event
 *     log on a cron for the patterns in security_monitor_rules().
 *
 * WHY THE DETECTORS RUN ON CRON AND NOT ON EVERY REQUEST
 * A detector is a GROUP BY over a window. Running ten of them on every page
 * view would put ten aggregates in front of every shopper to catch something
 * that, by definition, is still happening five minutes later. The sweep runs
 * from cron; admin/security/events.php also runs it inline when cron has
 * plainly not been set up, so a store that never added the cron line is
 * watched anyway - just later.
 *
 * Nothing in here may throw into a request it is describing: a monitor that
 * turns a page view into a 500 is worse than no monitor.
 */

declare(strict_types=1);

// ===========================================================================
//  1. IP rules
// ===========================================================================

/**
 * Where the mirrored copy of the rules lives.
 *
 * Same keying as site_url_policy_file(): one file per database AND install
 * path, because the scratch database and the live one share storage/ and must
 * never hand each other a block list.
 */
function security_ip_rules_file(): string
{
    if (!defined('STORAGE_PATH') || !defined('DB_NAME') || !defined('ROOT_PATH')) {
        return '';
    }
    $key = substr(sha1(DB_NAME . '|' . (defined('DB_HOST') ? DB_HOST : '') . '|' . ROOT_PATH), 0, 12);

    return STORAGE_PATH . '/cache/ip-rules-' . $key . '.json';
}

/**
 * The live rules, as ['gen' => int, 'rules' => [['id','kind','cidr','family','start','end','expires'], ...]].
 *
 * Read from a small JSON file rather than the database, because this runs on
 * every request: matching an address must not cost a query. The file is
 * rewritten by security_ip_rules_sync() whenever a rule changes, and rebuilt
 * from the database if it is missing or was written by an older generation.
 */
/**
 * The per-request memo for the rule list.
 *
 * Its own function rather than a static inside security_ip_rules(), because
 * security_ip_rules_sync() has to be able to replace it: adding a rule and
 * then asking whether it applies happens inside ONE request on the IP-rules
 * screen, and a memo only the reader could fill answered with the list from
 * before the write.
 */
function security_ip_rules_memo(?array $value = null): ?array
{
    static $memo = null;
    if ($value !== null) {
        $memo = $value;
    }
    return $memo;
}

function security_ip_rules(): array
{
    $memo = security_ip_rules_memo();
    if ($memo !== null) {
        return $memo;
    }

    $wanted = setting_int('sec_ip_rules_gen', 0);
    $file   = security_ip_rules_file();

    if ($file !== '' && is_file($file)) {
        $raw     = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded) && (int) ($decoded['gen'] ?? -1) === $wanted && isset($decoded['rules'])) {
            return (array) security_ip_rules_memo([
                'gen'   => $wanted,
                'rules' => is_array($decoded['rules']) ? $decoded['rules'] : [],
            ]);
        }
    }

    // Rebuilt WITHOUT bumping the generation: two storefront requests that
    // both find the file missing must not race each other into an endless
    // bump-and-rewrite. Only an actual rule change moves the generation on.
    return security_ip_rules_sync(false);
}

/**
 * Rebuild the mirrored file from the database and return what it now holds.
 *
 * @param bool $bump true after a real change - moves the generation on and
 *                   refreshes the counter the fast path reads
 */
function security_ip_rules_sync(bool $bump = true): array
{
    $rules = [];

    try {
        $rows = Database::fetchAll(
            'SELECT `id`, `kind`, `cidr`, `family`, HEX(`ip_start`) AS `s`, HEX(`ip_end`) AS `e`,
                    UNIX_TIMESTAMP(`expires_at`) AS `expires`
               FROM `ip_rules`
              WHERE `expires_at` IS NULL OR `expires_at` > NOW()
              ORDER BY `kind` = \'allow\' DESC, `id` ASC'
        );
        foreach ($rows as $row) {
            $rules[] = [
                'id'      => (int) $row['id'],
                'kind'    => (string) $row['kind'],
                'cidr'    => (string) $row['cidr'],
                'family'  => (int) $row['family'],
                'start'   => strtolower((string) $row['s']),
                'end'     => strtolower((string) $row['e']),
                'expires' => $row['expires'] === null ? null : (int) $row['expires'],
            ];
        }
    } catch (Throwable $e) {
        // A missing table (migration not run yet) means "no rules", never a
        // 500 on the storefront.
        // Memoised as well, so a missing table costs one failed query per
        // request rather than one per address that gets looked up.
        ErrorHandler::log('warning', 'IP rules unavailable: ' . $e->getMessage());
        return (array) security_ip_rules_memo(['gen' => setting_int('sec_ip_rules_gen', 0), 'rules' => []]);
    }

    $gen = setting_int('sec_ip_rules_gen', 0) + ($bump ? 1 : 0);
    if ($bump) {
        try {
            setting_save('sec_ip_rules_gen', (string) $gen, 'security', 'number');
            setting_save('sec_ip_rules_count', (string) count($rules), 'security', 'number');
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'IP rule counters not saved: ' . $e->getMessage());
        }
    }

    $file = security_ip_rules_file();
    if ($file !== '') {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Best effort. An unwritable cache folder costs one query per request
        // (the rebuild above), not correctness.
        @file_put_contents($file, json_encode(['gen' => $gen, 'rules' => $rules], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return (array) security_ip_rules_memo(['gen' => $gen, 'rules' => $rules]);
}

/**
 * Turn "203.0.113.4" or "2001:db8::/32" into the range it covers.
 *
 * @return array{cidr:string,family:int,start:string,end:string,bits:int}|null
 */
function security_ip_rule_parse(string $input): ?array
{
    $input = strtolower(trim($input));
    if ($input === '') {
        return null;
    }
    $input = trim($input, '[]');

    [$address, $bits] = array_pad(explode('/', $input, 2), 2, null);
    $address = trim((string) $address, '[]');

    // A prefix that is not a number is a TYPO, not a zero.
    //
    // "1.2.3.4/abc" used to come out of (int) as 0, i.e. 0.0.0.0/0 - every
    // address there is. A block rule that wide is caught downstream (the /8
    // floor, and the "that covers you" guard), but an ALLOW has neither, and
    // allow beats block: one mistyped character silently switched the whole
    // block list off. Refusing it puts the typo in front of the admin instead.
    if ($bits !== null) {
        $bits = trim((string) $bits);
        if ($bits !== '' && ctype_digit($bits) === false) {
            return null;
        }
    }

    $binary = security_ip_binary($address);
    if ($binary === false) {
        return null;
    }

    $width   = strlen($binary) * 8;          // 32 for IPv4, 128 for IPv6
    $family  = $width === 32 ? 4 : 6;
    $bits    = $bits === null || $bits === '' ? $width : (int) $bits;
    if ($bits < 0 || $bits > $width) {
        return null;
    }

    // Mask the address down to the start of its range, then flood the host
    // bits to get the end. Done byte by byte so IPv4 and IPv6 share one path.
    $start = $binary;
    $end   = $binary;
    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;

    for ($i = $whole; $i < strlen($binary); $i++) {
        if ($i === $whole && $rest > 0) {
            $mask     = chr((0xFF << (8 - $rest)) & 0xFF);
            $start[$i] = $binary[$i] & $mask;
            $end[$i]   = chr(ord($binary[$i] & $mask) | (~ord($mask) & 0xFF));
            continue;
        }
        $start[$i] = "\0";
        $end[$i]   = "\xFF";
    }

    return [
        'cidr'   => $bits === $width ? inet_ntop($start) : inet_ntop($start) . '/' . $bits,
        'family' => $family,
        'start'  => bin2hex($start),
        'end'    => bin2hex($end),
        'bits'   => $bits,
    ];
}

/**
 * One address, as bytes, with the IPv4-mapped IPv6 form folded back to IPv4.
 *
 * ::ffff:203.0.113.9 and 203.0.113.9 are the SAME machine, and a dual-stack
 * listener decides on its own which of the two ends up in REMOTE_ADDR - as
 * does a proxy writing X-Forwarded-For, where the mapped form passes
 * FILTER_VALIDATE_IP and comes straight back out of resolve_client_ip().
 * Without this fold, a block on 203.0.113.9 was a 16-byte-vs-4-byte width
 * mismatch against the mapped form and simply did not match: the rule looked
 * applied on the screen and the blocked address kept browsing. Measured
 * before the fix: in_range('::ffff:203.0.113.9', parse('203.0.113.9')) = false.
 *
 * @return string|false the packed address, or false when it is not one
 */
function security_ip_binary(string $address)
{
    $binary = @inet_pton(trim($address, '[]'));
    if ($binary === false) {
        return false;
    }

    // ::ffff:a.b.c.d - eighty zero bits, sixteen one bits, then the v4 address.
    if (strlen($binary) === 16 && strncmp($binary, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF", 12) === 0) {
        return substr($binary, 12);
    }

    return $binary;
}

/**
 * Does this address fall inside a parsed range?
 *
 * Called once per rule for every request the store serves, so the conversion
 * of the address is memoised and the comparison is done on the hex the rules
 * already carry - no hex2bin per rule, no re-parse of the same address forty
 * times. Measured against 202 rules before that: 428 us per request, roughly
 * 2 us a rule, nearly all of it inet_pton/hex2bin/bin2hex churn.
 */
function security_ip_in_range(string $ip, array $range): bool
{
    static $memo = [];

    if (!array_key_exists($ip, $memo)) {
        if (count($memo) > 64) {
            $memo = [];   // a long-lived CLI sweep must not grow this forever
        }
        $binary      = security_ip_binary($ip);
        $memo[$ip]   = $binary === false ? '' : bin2hex($binary);
    }
    $hex = $memo[$ip];
    if ($hex === '') {
        return false;
    }

    // An IPv4 address can never sit inside an IPv6 range, and a comparison
    // across two different widths would compare nonsense. Both sides are
    // lower-case hex of the same length, so strcmp is the byte order.
    $start = (string) $range['start'];
    if (strlen($hex) !== strlen($start)) {
        return false;
    }

    return strcmp($hex, $start) >= 0 && strcmp($hex, (string) $range['end']) <= 0;
}

/**
 * The rule that decides this address, or null when nothing matches.
 *
 * ALLOW WINS. The office address the owner listed must keep working even if a
 * clumsy /16 block later covers it, because the alternative - a block rule
 * that quietly outranks the allow list - is how a shop locks out its own
 * warehouse at 2am and nobody can explain why.
 */
function security_ip_rule_match(string $ip): ?array
{
    if ($ip === '') {
        return null;
    }
    $now   = time();
    $block = null;

    foreach (security_ip_rules()['rules'] as $rule) {
        if ($rule['expires'] !== null && $rule['expires'] <= $now) {
            continue;   // the file can outlive an expiry by up to one change
        }
        if (!security_ip_in_range($ip, $rule)) {
            continue;
        }
        if ($rule['kind'] === 'allow') {
            return $rule;
        }
        $block = $block ?? $rule;
    }

    return $block;
}

/** Would this CIDR shut the door on the address the admin is sitting at? */
function security_ip_rule_covers_me(string $cidr): bool
{
    $range = security_ip_rule_parse($cidr);
    if ($range === null) {
        return false;
    }

    return security_ip_in_range(client_ip(), $range);
}

/**
 * Create a rule.
 *
 * @param array{cidr:string,kind:string,reason:string,expires_at:?string,admin_id:?int} $data
 * @return array{ok:bool,error:string,id:int,cidr:string}
 */
function security_ip_rule_add(array $data): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'error' => $message, 'id' => 0, 'cidr' => ''];

    $kind  = (string) ($data['kind'] ?? 'block') === 'allow' ? 'allow' : 'block';
    $range = security_ip_rule_parse((string) ($data['cidr'] ?? ''));
    if ($range === null) {
        return $fail('That is not an address or a range. Use 203.0.113.4 or 203.0.113.0/24.');
    }

    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        return $fail('Say why. A rule nobody can explain in six months is a rule nobody dares delete.');
    }

    // /8 for IPv4 is 16 million addresses; /32 for IPv6 is a whole ISP.
    // Nothing wider than that is a "this one attacker" rule - and on the allow
    // side nothing wider than that is a rule at all, because allow beats
    // block: an allow of 0.0.0.0/0 switches the entire block list off and the
    // screen still reads as if the blocks were in force.
    $floor = $range['family'] === 4 ? 8 : 32;

    if ($kind === 'block') {
        // Two guards, in this order. The first is the one that matters: an
        // admin who blocks their own office cannot undo it, because undoing it
        // needs this screen, which needs this address.
        if (client_ip() === '') {
            // No request, no address, and security_ip_rule_covers_me() has
            // nothing to compare - it answers "no" and the guard below waves
            // the rule through. Proved by driving this from the CLI: a block
            // on 127.0.0.0/8 was accepted and the next homepage request was a
            // 403, including the admin screen needed to undo it. The three
            // callers are all admin screens, so a caller with no address is a
            // script, and a script must say which address it is protecting.
            return $fail('This rule is being added without a request behind it, so there is no way to check that it'
                . ' does not shut the door on whoever is adding it. Add it from Admin > Security > IP rules.');
        }
        if (security_ip_rule_covers_me($range['cidr'])) {
            return $fail('That range covers the address you are connecting from (' . client_ip()
                . '). Blocking it would lock you out of this screen.');
        }
        if ($range['bits'] < $floor) {
            return $fail('That range is too broad (/' . $range['bits'] . '). Blocking whole networks belongs at the'
                . ' firewall or the CDN, not here - use /' . $floor . ' or narrower.');
        }
    } elseif ($range['bits'] < $floor) {
        return $fail('That range is too broad (/' . $range['bits'] . '). An allow rule outranks every block rule,'
            . ' so one this wide would quietly switch the whole block list off - use /' . $floor . ' or narrower.');
    }

    $expires = null;
    $rawExpiry = trim((string) ($data['expires_at'] ?? ''));
    if ($rawExpiry !== '') {
        $stamp = strtotime($rawExpiry);
        if ($stamp === false) {
            return $fail('That expiry date could not be read.');
        }
        if ($stamp <= time()) {
            return $fail('That expiry is already in the past.');
        }
        $expires = date('Y-m-d H:i:s', $stamp);
    }

    try {
        if (Database::exists('ip_rules', '`kind` = :k AND `cidr` = :c', ['k' => $kind, 'c' => $range['cidr']])) {
            return $fail('There is already a ' . $kind . ' rule for ' . $range['cidr'] . '.');
        }

        $id = Database::insert('ip_rules', [
            'kind'       => $kind,
            'cidr'       => $range['cidr'],
            'family'     => $range['family'],
            // The driver has no binary placeholder here, so the range is
            // written as the hex the parser produced and unhexed by MySQL.
            'ip_start'   => hex2bin($range['start']),
            'ip_end'     => hex2bin($range['end']),
            'reason'     => mb_substr($reason, 0, 190),
            'expires_at' => $expires,
            'created_by' => isset($data['admin_id']) ? (int) $data['admin_id'] : null,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'IP rule not saved: ' . $e->getMessage());
        return $fail('The rule could not be saved: ' . $e->getMessage());
    }

    security_ip_rules_sync();

    security_event('ip_rule.added', $kind === 'allow' ? 'medium' : 'high', [
        'kind'    => $kind,
        'cidr'    => $range['cidr'],
        'reason'  => $reason,
        'expires' => $expires,
    ], $data['admin_id'] ?? null, 'admin');

    return ['ok' => true, 'error' => '', 'id' => $id, 'cidr' => $range['cidr']];
}

/** Remove a rule. Returns the row that was removed, or null. */
function security_ip_rule_delete(int $id, ?int $adminId = null): ?array
{
    try {
        $row = Database::fetch('SELECT * FROM `ip_rules` WHERE `id` = :id LIMIT 1', ['id' => $id]);
        if ($row === null) {
            return null;
        }
        Database::delete('ip_rules', '`id` = :id', ['id' => $id]);
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'IP rule not deleted: ' . $e->getMessage());
        return null;
    }

    security_ip_rules_sync();
    security_event('ip_rule.removed', 'medium', [
        'kind' => (string) $row['kind'],
        'cidr' => (string) $row['cidr'],
    ], $adminId, 'admin');

    return $row;
}

/**
 * The response a blocked address gets.
 *
 * Deliberately plain text and deliberately vague: a scanner learns nothing
 * from it, and a human who has been blocked by mistake gets something they can
 * quote to the shop owner (the time) without being told the rule.
 */
function security_ip_rule_refuse(array $rule): void
{
    // Counted at most once a minute per rule: the conditional UPDATE writes
    // nothing on the 59 following requests of a scanner's burst, so a blocked
    // flood costs one row-miss each rather than a row-write each.
    $counted = 0;
    try {
        $counted = Database::query(
            'UPDATE `ip_rules` SET `hits` = `hits` + 1, `last_hit_at` = NOW()
              WHERE `id` = :id AND (`last_hit_at` IS NULL OR `last_hit_at` < (NOW() - INTERVAL 60 SECOND))',
            ['id' => (int) $rule['id']]
        )->rowCount();
    } catch (Throwable $e) {
        // Never let bookkeeping stop the refusal itself.
    }

    if ($counted > 0) {
        security_event('ip_rule.blocked', 'low', ['rule_id' => (int) $rule['id'], 'cidr' => (string) $rule['cidr']]);
    }

    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Connection: close');
    }
    echo "Forbidden\n";
    echo "If you believe this is a mistake, contact the store and quote this time: " . gmdate('Y-m-d H:i:s') . " UTC\n";
    exit;
}

// ===========================================================================
//  2. Request probes
// ===========================================================================

/**
 * Paths that only ever mean "somebody is looking for a way in".
 *
 * Every entry is something this shop does not have and never will, so a
 * request for one cannot be a customer with an old bookmark. Matched against
 * the PATH only - a query string containing ".env" is a search term, not a
 * probe, and matching it would turn the site's own search box into an alarm.
 */
function security_probe_patterns(): array
{
    return [
        'wordpress'  => '~/(wp-login\.php|wp-admin|wp-content|wp-includes|xmlrpc\.php|wlwmanifest\.xml)~i',
        'secrets'    => '~/(\.env(\.|$)|\.git/|\.svn/|\.aws/|\.ssh/|\.htpasswd$|id_rsa$)~i',
        // Anchored to a whole path segment: an unanchored "pma" would have
        // turned a category called /pmarket into an intruder.
        'db-tools'   => '~/(phpmyadmin|pma|myadmin|dbadmin|sqlitemanager|adminer(?:-[\w.]+)?\.php)(?:/|$)~i',
        'installers' => '~/(install(er)?(\.php)?$|setup(\.php)?$|upgrade\.php$|_installation)~i',
        'webshell'   => '~/(shell|alfa|wso|c99|r57|cmd|eval-stdin|filemanager)\.php$~i',
        'vendor'     => '~/(vendor/phpunit|vendor/composer|composer\.(json|lock)$|\.well-known/pki-validation/\w+\.php)~i',
        'configs'    => '~/(config(uration)?\.(bak|old|orig|save|txt)$|web\.config$|\.DS_Store$|backup\.(sql|zip|tar\.gz)$)~i',
        'cgi'        => '~/cgi-bin/~i',
    ];
}

/**
 * The mark the app's own HTTP self-checks carry.
 *
 * Two of the security cards find out the truth by fetching the live site:
 * security_exposure_probe() asks for /.env, /.git/HEAD, /.git/config and
 * /composer.json, and security_csp_inline_audit() asks for a page that does
 * not exist. Those are precisely the shapes security_probe_patterns() calls an
 * intruder: four probe-shaped paths per run, counted in a one-hour window
 * against a default threshold of five. Run the exposure check twice in an
 * hour - or leave bin/security-selftest.php on a cron, which is the whole
 * point of it - and the site emails the owner that somebody is probing the
 * site, naming the site's own address. An alarm that goes off when you test
 * the alarm is an alarm people stop reading, which is the one thing this
 * build must not ship.
 *
 * A User-Agent string would have silenced it, and would also have been a free
 * pass any scanner could copy out of this file. This is an HMAC under a key
 * derived from the application key - which never leaves the server - and it
 * goes stale in five minutes. It silences our own probe and nobody else's, and
 * it grants nothing: the worst a forged one could do is not raise an alarm.
 *
 * @return string '<unix time>.<hmac>', or '' when there is no key to sign with
 */
function security_selfcheck_token(): string
{
    if (!function_exists('app_key_derive')) {
        return '';
    }

    $key = app_key_derive('selfcheck-probe');
    if ($key === '') {
        return '';
    }

    $ts = (string) time();

    return $ts . '.' . hash_hmac('sha256', $ts, $key);
}

/** The request header line for a stream context; '' when it cannot be signed. */
function security_selfcheck_header(): string
{
    $token = security_selfcheck_token();

    return $token === '' ? '' : 'X-ShopInnKart-Selfcheck: ' . $token;
}

/**
 * Is THIS request one of our own self-checks?
 *
 * Costs one array lookup on an ordinary request - the hashing only happens
 * once something has already presented a header of the right shape.
 */
function security_request_is_selfcheck(int $maxAgeSeconds = 300): bool
{
    $sent = (string) ($_SERVER['HTTP_X_SHOPINNKART_SELFCHECK'] ?? '');
    if ($sent === '' || strpos($sent, '.') === false) {
        return false;
    }

    [$ts, $mac] = explode('.', $sent, 2);
    // The timestamp is checked BEFORE the key is derived: a flood of junk
    // headers must not be able to make every request do an HKDF.
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > $maxAgeSeconds) {
        return false;
    }
    if (!function_exists('app_key_derive')) {
        return false;
    }

    $key = app_key_derive('selfcheck-probe');
    if ($key === '') {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $ts, $key), $mac);
}

/**
 * Count one suspicious request and raise an event only when the count crosses
 * the threshold.
 *
 * @return bool true when this call is the one that tripped the line
 */
function security_probe_count(string $bucket, string $type, int $threshold, int $window, array $context): bool
{
    if ($threshold < 1) {
        return false;
    }
    $ip = client_ip();
    if ($ip === '') {
        return false;
    }

    try {
        // One atomic upsert in `rate_limits`, the same table the sign-in
        // throttles use - no new hot table, and the housekeeping is already
        // written.
        $count = rate_limit_count($bucket, $ip, $window, true);
        if ($count < $threshold) {
            return false;
        }

        // Have we already shouted about this address in this window?
        //
        // Asked of the event log itself rather than of a second rate-limit
        // bucket, and the difference is not tidiness. rate_limit_count() FAILS
        // OPEN - a hiccup makes it answer 0, which is the right answer for a
        // throttle ("let the customer in") and exactly the wrong one for a
        // "have we shouted yet" gate ("shout again"). Measured: roughly one
        // scan in twenty wrote a second event it should not have. The log is
        // the fact we actually care about, it cannot drift from itself, and
        // (`ip_address`, `created_at`) is indexed - so this costs one lookup,
        // and only on the rare request that has already crossed the line.
        $alreadySaid = Database::exists(
            'security_events',
            '`type` = :t AND `ip_address` = :ip AND `created_at` >= :since',
            ['t' => $type, 'ip' => $ip, 'since' => date('Y-m-d H:i:s', time() - $window)]
        );
        if ($alreadySaid) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    security_event($type, 'medium', $context + ['count' => (int) $count, 'window_seconds' => $window]);

    return true;
}

/**
 * Is this path a static asset?
 *
 * A missing stylesheet or image is this site's own bug, and one broken page
 * full of them would trip the scan alarm on the shop's own visitors. Named
 * rather than inline so the exclusion can be tested on its own.
 */
function security_probe_is_asset(string $path): bool
{
    return preg_match('~\.(css|js|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|mp4|webm)$~i', $path) === 1;
}

/**
 * Watch this request for the two things nothing else records.
 *
 * Called from security_headers_send() (probes, which are visible from the
 * path) and from a shutdown hook (missing pages, which are only visible once
 * the response code is known).
 */
function security_monitor_watch_request(): void
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path === '') {
        return;
    }

    // The site checking itself is not the site being attacked. See
    // security_selfcheck_token() for why this is signed rather than sniffed.
    if (security_request_is_selfcheck()) {
        return;
    }

    foreach (security_probe_patterns() as $family => $pattern) {
        if (preg_match($pattern, $path) === 1) {
            security_probe_count(
                'probe.installer',
                'probe.installer',
                max(1, setting_int('sec_alert_probe', 5)),
                3600,
                ['family' => $family, 'path' => mb_substr($path, 0, 200)]
            );
            return;   // one family is enough; a probe is a probe
        }
    }

    // 404s are only knowable at the end of the response.
    register_shutdown_function(static function () use ($path): void {
        try {
            if (http_response_code() !== 404) {
                return;
            }
            if (security_probe_is_asset($path)) {
                return;
            }
            security_probe_count(
                'probe.notfound',
                'probe.scanning',
                max(1, setting_int('sec_alert_404', 40)),
                900,
                ['path' => mb_substr($path, 0, 200)]
            );
        } catch (Throwable $e) {
            // Shutdown is the worst possible place to throw.
        }
    });
}

/**
 * Record a CSRF failure.
 *
 * Lives here rather than in csrf.php so that the two call sites (the form path
 * and the API path) share one definition, and so that a store with the monitor
 * switched off writes nothing at all. Cheap on purpose: a stale tab is the
 * ordinary cause, so this must never cost more than one insert.
 */
function security_csrf_failed(string $where): void
{
    try {
        // A ceiling per address per window, because this is the one event an
        // ANONYMOUS caller can write at will: a POST to any public form with
        // no token at all is refused, and used to write a row every time.
        // Measured on the running site: forty curl POSTs to contact.php, no
        // session and no token, wrote forty rows in five seconds, and nothing
        // in the retention prune is size-based - it only drops rows by age, so
        // the flood would sit there for ninety days.
        //
        // The detector only needs to SEE the threshold crossed, not to hold
        // every proof of it, so logging stops a little way above the number it
        // watches for. The counter is the same atomic upsert the throttles use
        // and it fails open, which for a log is the right direction.
        $ip = client_ip();
        if ($ip !== '') {
            $cap = max(5, setting_int('sec_alert_csrf', 15)) + 5;
            if (rate_limit_count('csrf.failed.log', $ip, 900, true) > $cap) {
                return;
            }
        }

        security_event('csrf.failed', 'low', [
            'where'  => $where,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            // Whether the browser even had a token tells apart "expired" from
            // "never had one", which is the difference between a stale tab and
            // a cross-site post.
            'had_session_token' => isset($_SESSION[CSRF_TOKEN_NAME]) && $_SESSION[CSRF_TOKEN_NAME] !== '',
        ], function_exists('admin_id') ? admin_id() : null, function_exists('admin_id') && admin_id() !== null ? 'admin' : null);
    } catch (Throwable $e) {
        // A refused POST must stay refused even if the log is unavailable.
    }
}

/**
 * The one entry point init.php reaches, through security_headers_send().
 *
 * Order matters: allow/block first (a blocked address should not get to run
 * the probe patterns), then the request watch.
 */
function security_monitor_guard(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    // The fast gate. setting() has the whole settings table in a per-request
    // array by now, so a store with no rules pays one array lookup - no file
    // read, no query, no address parsing.
    if (setting_int('sec_ip_rules_count', 0) > 0) {
        $rule = security_ip_rule_match(client_ip());
        if ($rule !== null && $rule['kind'] === 'block') {
            security_ip_rule_refuse($rule);
        }
    }

    if (setting_bool('sec_monitor_enabled', true)) {
        security_monitor_watch_request();
    }
}

// ===========================================================================
//  3. CSP violation reports
// ===========================================================================

/** Our own collector, the default target of report-uri. */
function csp_report_endpoint(): string
{
    return rtrim(BASE_PATH, '/') . '/api/security/csp-report.php';
}

/**
 * Normalise a blocked URI down to something worth grouping on.
 *
 * A browser sends the whole URL, query string and all, so one blocked tracker
 * produces a new "fact" per page view. Everything after the path is dropped,
 * and the CSP keywords (inline, eval, data) are kept as themselves.
 */
function csp_normalise_blocked_uri(string $uri): string
{
    $uri = trim($uri);
    if ($uri === '') {
        return 'inline';
    }
    $lower = strtolower($uri);
    foreach (['inline', 'eval', 'data', 'blob', 'filesystem', 'wasm-eval', 'self'] as $keyword) {
        if ($lower === $keyword || $lower === $keyword . ':') {
            return $keyword;
        }
    }

    $parts = @parse_url($uri);
    if (!is_array($parts) || empty($parts['host'])) {
        return mb_substr($uri, 0, 180);
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $path   = (string) ($parts['path'] ?? '');
    // Cache-busting names (main.a91f3c.js) are the same file every time.
    $path   = (string) preg_replace('/\.[0-9a-f]{8,}\./i', '.*.', $path);

    return mb_substr($scheme . '://' . strtolower((string) $parts['host']) . $path, 0, 200);
}

/**
 * Is this OUR page refusing OUR OWN code?
 *
 * The distinction the whole enforce/revert decision rests on. A blocked
 * chrome-extension:// URL means one visitor has an extension; a blocked
 * 'inline' or a blocked script from this very origin means the shop just
 * stopped working for everybody.
 */
function csp_report_origin(string $blockedUri): string
{
    if (in_array($blockedUri, ['inline', 'eval', 'wasm-eval'], true)) {
        return 'first-party';
    }
    $host = (string) @parse_url($blockedUri, PHP_URL_HOST);
    if ($host === '') {
        return 'third-party';
    }
    $ours = strtolower((string) @parse_url(canonical_base_url(), PHP_URL_HOST));
    if ($ours !== '' && strtolower($host) === $ours) {
        return 'first-party';
    }
    // The request's own host counts too, because a staging domain reporting
    // about itself is still the site refusing itself.
    $here = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));

    return $here !== '' && strtolower($host) === $here ? 'first-party' : 'third-party';
}

/**
 * Store one violation, grouped.
 *
 * @param array $report the browser's report body (legacy or Reporting API shape)
 * @return array{stored:bool, first_party:bool, fingerprint:string}
 */
function csp_report_record(array $report): array
{
    $directive = mb_substr((string) (
        $report['effective-directive']
        ?? $report['effectiveDirective']
        ?? $report['violated-directive']
        ?? $report['violatedDirective']
        ?? 'unknown'
    ), 0, 40);
    // "script-src-elem 'self'" -> "script-src-elem"
    $directive = (string) (strtok($directive, ' ') ?: 'unknown');

    $blocked = csp_normalise_blocked_uri((string) (
        $report['blocked-uri'] ?? $report['blockedURL'] ?? $report['blockedURI'] ?? ''
    ));

    $documentUri = (string) ($report['document-uri'] ?? $report['documentURL'] ?? '');
    $documentPath = (string) (@parse_url($documentUri, PHP_URL_PATH) ?: '/');
    $context = security_response_context($documentPath);

    $enforcing   = (string) setting('sec_csp_mode', 'report-only') === 'enforce';
    $disposition = strtolower((string) ($report['disposition'] ?? ''));
    if ($disposition !== 'enforce' && $disposition !== 'report') {
        // Older browsers omit it on the legacy report-uri body. We only ever
        // send ONE policy header, so the current mode is the honest answer.
        $disposition = $enforcing ? 'enforce' : 'report';
    } elseif ($disposition === 'enforce' && !$enforcing) {
        // A claim we KNOW is false. The header this site sent was
        // Content-Security-Policy-Report-Only, so no browser can honestly say
        // it enforced anything - and 'enforce' is the field the automatic
        // revert counts on, which makes believing it a way to stock the table
        // with revert fuel before the owner has even pressed Enforce.
        $disposition = 'report';
    }

    $origin      = csp_report_origin($blocked);
    $fingerprint = sha1($directive . '|' . $blocked . '|' . $context);

    $sample = trim((string) ($report['script-sample'] ?? $report['sample'] ?? ''));

    try {
        $updated = Database::query(
            'UPDATE `csp_reports`
                SET `hits` = `hits` + 1, `last_seen` = NOW(), `disposition` = :d
              WHERE `fingerprint` = :f',
            ['f' => $fingerprint, 'd' => $disposition]
        )->rowCount();

        if ($updated === 0) {
            // The flood guard. Past the cap we still count the groups we know
            // about (the UPDATE above) but stop learning new ones, so one
            // misbehaving extension cycling random URLs cannot grow the table.
            //
            // Counted PER ORIGIN, not as one pool. Everything in a report is
            // the caller's to choose - directive, blocked URI and, through
            // document-uri, the page kind - so the fingerprint is entirely
            // attacker-chosen and an open endpoint can mint distinct groups at
            // will. With one shared cap, junk third-party rows fill the table
            // and the site's OWN violations - the only rows the Enforce
            // decision is made from - can no longer be learned at all. So
            // first-party keeps a reserved quarter of the cap that third-party
            // noise cannot reach.
            $cap      = max(20, setting_int('sec_csp_group_cap', 400));
            $firstCap = max(5, intdiv($cap, 4));
            $mine     = $origin === 'first-party';
            $used     = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `csp_reports` WHERE `origin` = :o',
                ['o' => $origin]
            );
            $limit = $mine ? $firstCap : $cap - $firstCap;
            if ($used >= $limit || Database::count('csp_reports') >= $cap) {
                return ['stored' => false, 'first_party' => $mine, 'fingerprint' => $fingerprint];
            }

            Database::insert('csp_reports', [
                'fingerprint'   => $fingerprint,
                'directive'     => $directive,
                'blocked_uri'   => $blocked,
                'page_context'  => $context,
                'document_path' => mb_substr($documentPath, 0, 255),
                'sample'        => $sample === '' ? null : mb_substr($sample, 0, 255),
                'origin'        => $origin,
                'disposition'   => $disposition,
                'first_seen'    => date('Y-m-d H:i:s'),
                'last_seen'     => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (PDOException $e) {
        // 23000 = two reports of the same new violation arrived at once. The
        // other one won; nothing is lost.
        if ($e->getCode() !== '23000') {
            ErrorHandler::log('warning', 'CSP report not stored: ' . $e->getMessage());
            return ['stored' => false, 'first_party' => $origin === 'first-party', 'fingerprint' => $fingerprint];
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'CSP report not stored: ' . $e->getMessage());
        return ['stored' => false, 'first_party' => $origin === 'first-party', 'fingerprint' => $fingerprint];
    }

    return ['stored' => true, 'first_party' => $origin === 'first-party', 'fingerprint' => $fingerprint];
}

/**
 * Switch the policy to Enforce and start the probation clock.
 *
 * @return array{ok:bool, error:string}
 */
function csp_enforce_start(?int $adminId = null): array
{
    setting_save('sec_csp_mode', 'enforce', 'security', 'text');
    setting_save('sec_csp_enforced_at', date('Y-m-d H:i:s'), 'security', 'text');
    setting_save('sec_csp_revert_note', '', 'security', 'text');

    security_event('platform.csp_mode_changed', 'medium', ['mode' => 'enforce', 'probation' => true], $adminId, 'admin');

    return ['ok' => true, 'error' => ''];
}

/** Put the policy back on Report only. $why !== '' means it was automatic. */
function csp_enforce_stop(string $why = '', ?int $adminId = null): void
{
    setting_save('sec_csp_mode', 'report-only', 'security', 'text');
    setting_save('sec_csp_enforced_at', '', 'security', 'text');
    setting_save('sec_csp_revert_note', mb_substr($why, 0, 190), 'security', 'text');

    security_event('platform.csp_mode_changed', $why === '' ? 'medium' : 'high', [
        'mode'      => 'report-only',
        'automatic' => $why !== '',
        'reason'    => $why,
    ], $adminId, 'admin');
}

/**
 * The automatic revert.
 *
 * Runs from the report endpoint, which is the only place that learns the site
 * is refusing itself. Two conditions, both required:
 *
 *   - the policy is being ENFORCED and we are inside the probation window
 *     (an old violation from last month must not undo today's decision), and
 *   - enough DISTINCT first-party violations have been blocked since the
 *     switch. Distinct, not total, because one broken page reloading fifty
 *     times is one bug, while five different blocked scripts is the policy
 *     being wrong about this site.
 *
 * @return bool true when this call reverted the policy
 */
function csp_enforce_autorevert_check(): bool
{
    if ((string) setting('sec_csp_mode', 'report-only') !== 'enforce') {
        return false;
    }

    $since = (string) setting('sec_csp_enforced_at', '');
    if ($since === '') {
        return false;
    }
    $startedAt = strtotime($since);
    $probation = max(1, setting_int('sec_csp_probation_hours', 24)) * 3600;
    if ($startedAt === false || time() > $startedAt + $probation) {
        return false;
    }

    $limit = max(1, setting_int('sec_csp_selfblock_limit', 5));

    try {
        $distinct = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `csp_reports`
              WHERE `origin` = 'first-party' AND `disposition` = 'enforce'
                AND `status` = 'new' AND `last_seen` >= :since",
            ['since' => $since]
        );
    } catch (Throwable $e) {
        return false;
    }

    if ($distinct < $limit) {
        return false;
    }

    // ----------------------------------------------------------------------
    // Corroboration: how many DIFFERENT addresses have reported since Enforce?
    //
    // Everything in a violation report is the caller's to choose, and the
    // endpoint takes no session and no CSRF because a browser's policy engine
    // would not send either. Measured on the running site: five anonymous
    // POSTs, eighteen seconds apart, with blocked-uri "inline" and five
    // different document-uri values, turned the policy off. Nothing had been
    // blocked; the reports were invented. And because only an admin can press
    // Enforce again, those five POSTs win every time.
    //
    // What an attacker cannot invent is a second source address: the reports
    // arrive over TCP. A policy that is genuinely refusing the site's own
    // scripts is refusing them for EVERY visitor, so real breakage is reported
    // from many addresses within seconds; one address shouting on its own is
    // not evidence of anything. The count comes from the `rate_limits` row the
    // collector already wrote before parsing the body, so this costs one
    // indexed COUNT on the rare request that has already crossed the line -
    // no new table, no extra write.
    //
    // (Behind a trusted proxy the address is the forwarded one, which is the
    // same address the rest of the app limits on; a store that trusts a proxy
    // it should not has a bigger problem than this.)
    $wantReporters = max(1, setting_int('sec_csp_revert_reporters', 2));
    $reporters     = $wantReporters;
    if ($wantReporters > 1) {
        try {
            // window_start is the floor of the limiter's hour, so step back one
            // window to catch the hour Enforce was switched on inside.
            $reporters = (int) Database::fetchColumn(
                'SELECT COUNT(DISTINCT `key_hash`) FROM `rate_limits`
                  WHERE `bucket` = :b AND `window_start` >= :from',
                ['b' => 'csp.report', 'from' => $startedAt - 3600]
            );
        } catch (Throwable $e) {
            // Fail CLOSED here, unlike the limiter itself: not reverting leaves
            // the owner's own decision standing, which is the safe direction.
            return false;
        }
    }

    if ($reporters < $wantReporters) {
        // Once an hour, not once a report: the caller here is an open endpoint,
        // and a row per POST would let the same flood that cannot turn the
        // policy off grow the event log instead.
        $saidAlready = false;
        try {
            $saidAlready = Database::exists(
                'security_events',
                '`type` = :t AND `created_at` >= (NOW() - INTERVAL 1 HOUR)',
                ['t' => 'platform.csp_revert_held']
            );
        } catch (Throwable $e) {
            $saidAlready = true;   // never let bookkeeping become the noise
        }
        if ($saidAlready) {
            return false;
        }

        security_event('platform.csp_revert_held', 'medium', [
            'distinct_violations' => $distinct,
            'reporters'           => $reporters,
            'reporters_needed'    => $wantReporters,
            'why'                 => 'Enough first-party violations to revert, but they all came from too few'
                . ' addresses to be the site breaking for everybody.',
        ]);

        return false;
    }

    $why = 'The site was refusing its own scripts: ' . $distinct . ' different first-party violations were blocked'
        . ' within ' . max(1, setting_int('sec_csp_probation_hours', 24)) . ' hours of switching to Enforce'
        . ', reported from ' . $reporters . ' different addresses.';
    csp_enforce_stop($why);

    try {
        notify_admins('security_alert', [
            'alert_title'     => 'The Content Security Policy was switched back automatically',
            'alert_subject'   => 'Content Security Policy',
            'alert_observed'  => (string) $distinct . ' blocked first-party resources',
            'alert_threshold' => (string) $limit,
            'alert_window'    => 'since Enforce was switched on',
            'alert_time'      => format_datetime(date('Y-m-d H:i:s')),
            'alert_advice'    => $why . ' The policy is back on Report only and the shop is working again.'
                . ' Open the CSP card, allow what should be allowed, and try Enforce again.',
            'alert_url'       => canonical_url('admin/security/settings.php#csp-reports'),
        ], 'security_alert', null);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'CSP revert email not queued: ' . $e->getMessage());
    }

    return true;
}

// ===========================================================================
//  4. Suspicious-activity detection
// ===========================================================================

/**
 * The detectors, and WHY each number is where it is.
 *
 * The rule for every threshold: it must sit above what the honest busiest hour
 * of this store can produce, and below what the attack it names looks like.
 * Where the app already enforces a limit of its own, the detector is set ABOVE
 * that limit on purpose - crossing it means the attacker was refused and kept
 * going, which no customer ever does.
 *
 * @return array<int, array<string, mixed>>
 */
function security_monitor_rules(): array
{
    return [
        [
            'key'       => 'login_burst_ip',
            'title'     => 'Failed sign-ins from one address',
            'scope'     => 'ip',
            'types'     => ['auth.login_failed'],
            'window'    => 900,
            'threshold' => max(1, setting_int('sec_alert_login_ip', 30)),
            'severity'  => 'high',
            // The app refuses this address at 20 failures in 15 minutes
            // (auth_throttle_limits()['ip']). Passing 30 means it was refused
            // and carried on. A customer who has forgotten their password
            // produces 3-6 and then uses the reset link.
            'advice'    => 'Check the address on the security log. If it is not your office or warehouse, block it'
                . ' from the event - the sign-in throttle already refused it and it kept going.',
        ],
        [
            'key'       => 'login_burst_account',
            'title'     => 'One account under sustained guessing',
            'scope'     => 'account',
            'types'     => ['auth.login_failed', 'auth.account_under_attack'],
            'window'    => 900,
            'threshold' => max(1, setting_int('sec_alert_login_account', 12)),
            'severity'  => 'high',
            // The per-account ceiling is 5 in 15 minutes. Reaching 12 means
            // the guessing is spread over several addresses, which is exactly
            // the case the per-address rule above cannot see.
            'advice'    => 'The attempts are spread across addresses, so the per-address throttle will not stop them.'
                . ' Tell the account holder, and turn two-step sign in on for that account.',
        ],
        [
            'key'       => 'lockouts',
            'title'     => 'The sign-in throttle is refusing a lot of people',
            'scope'     => 'global',
            'types'     => ['auth.login_throttled', 'auth.login_flood'],
            'window'    => 900,
            'threshold' => max(1, setting_int('sec_alert_lockouts', 10)),
            'severity'  => 'medium',
            // This store signs in a handful of people an hour. Ten refusals in
            // a quarter of an hour is either a spray across many accounts or
            // the throttle set too tight - and the owner needs to know which.
            'advice'    => 'Either somebody is spraying passwords across many accounts, or the limits are too tight'
                . ' for a shared office address. The security log shows which, by address.',
        ],
        [
            'key'       => 'csrf_failures',
            'title'     => 'Forms being posted from somewhere else',
            'scope'     => 'ip',
            'types'     => ['csrf.failed'],
            'window'    => 900,
            'threshold' => max(1, setting_int('sec_alert_csrf', 15)),
            'severity'  => 'medium',
            // One stale tab is one failure. Fifteen from one address in 15
            // minutes is a script posting without ever loading a page.
            'advice'    => 'A handful of these is people leaving tabs open overnight. This many from one address is a'
                . ' script posting to forms it never loaded.',
        ],
        [
            'key'       => 'permission_denials',
            'title'     => 'An admin keeps trying what their role forbids',
            'scope'     => 'account',
            'types'     => ['rbac.denied'],
            'window'    => 3600,
            'threshold' => max(1, setting_int('sec_alert_rbac', 5)),
            'severity'  => 'high',
            // The menu does not show what an admin may not open, so an honest
            // admin cannot reach this by clicking. Five in an hour is either a
            // stolen session or an admin probing the edges of their role.
            'advice'    => 'The admin menu hides what a role cannot open, so this is not accidental clicking.'
                . ' Either the session has been taken, or that admin is testing the edges of their role.',
        ],
        [
            'key'       => 'webhook_signatures',
            'title'     => 'Payment callbacks with a bad signature',
            'scope'     => 'global',
            'types'     => ['webhook.payment_throttled', 'webhook.payment_needs_review'],
            'window'    => 3600,
            'threshold' => max(1, setting_int('sec_alert_webhook', 3)),
            'severity'  => 'critical',
            // A correctly configured gateway never fails a signature. Three in
            // an hour is either forged callbacks or a secret that was rotated
            // on one side only - and both need the owner today.
            'advice'    => 'A real gateway never sends a bad signature. Either somebody is forging payment callbacks,'
                . ' or the webhook secret was changed at the gateway and not here. Check the payment settings first.',
        ],
        [
            'key'       => 'mfa_failures',
            'title'     => 'Two-step codes being guessed',
            'scope'     => 'account',
            'types'     => ['mfa.failed', 'mfa.verify_throttled', 'mfa.otp_throttled'],
            'window'    => 900,
            'threshold' => max(1, setting_int('sec_alert_mfa', 8)),
            'severity'  => 'high',
            // Somebody who already has the password is now working on the
            // second factor. A real person mistypes a 6-digit code once or
            // twice, then opens the app properly.
            'advice'    => 'Whoever is doing this already got past the password. Change that password now, then'
                . ' sign the account out of every device.',
        ],
        [
            'key'       => 'token_reuse',
            'title'     => 'A revoked or stale token was presented again',
            'scope'     => 'global',
            'types'     => ['api.token_reuse_after_revoke', 'auth.remember_token_reuse', 'mfa.device_token_reuse'],
            'window'    => 3600,
            'threshold' => max(1, setting_int('sec_alert_token_reuse', 1)),
            'severity'  => 'critical',
            // One is already too many. A token that was revoked and comes back
            // is either a copy somebody kept, or a device that has not noticed
            // - and the owner cannot tell which without being told it happened.
            'advice'    => 'A token that was taken away has come back. That is either a copy somebody kept, or an app'
                . ' that has not noticed yet. Devices & Tokens shows which account it belongs to.',
        ],
        [
            'key'       => 'probes',
            'title'     => 'Somebody is looking for an installer or a control panel',
            'scope'     => 'ip',
            'types'     => ['probe.installer'],
            'window'    => 3600,
            'threshold' => 1,   // the counter already applied sec_alert_probe
            'severity'  => 'medium',
            'advice'    => 'Background noise on any public site. Worth blocking only if it keeps up, and worth acting'
                . ' on at once if the security self-test says any of those paths actually answered.',
        ],
        [
            'key'       => 'scanning',
            'title'     => 'A directory scan is running against the site',
            'scope'     => 'ip',
            'types'     => ['probe.scanning'],
            'window'    => 3600,
            'threshold' => 1,   // the counter already applied sec_alert_404
            'severity'  => 'medium',
            'advice'    => 'One address is walking the site asking for pages that do not exist. Blocking it is safe:'
                . ' nothing it has asked for is anything a customer needs.',
        ],
    ];
}

/**
 * Run every detector once.
 *
 * @param  bool  $notify queue the owner an email for each new trip
 * @return array{trips:array<int,array>, checked:int, skipped:string}
 */
function security_monitor_sweep(bool $notify = true): array
{
    if (!setting_bool('sec_monitor_enabled', true)) {
        return ['trips' => [], 'checked' => 0, 'skipped' => 'The monitor is switched off in Security Settings.'];
    }

    $trips    = [];
    $checked  = 0;
    $now      = time();
    $cooldown = max(60, setting_int('sec_monitor_cooldown', 3600));

    foreach (security_monitor_rules() as $rule) {
        $checked++;
        try {
            foreach (security_monitor_rule_hits($rule, $now) as $hit) {
                $trip = security_monitor_trip($rule, $hit, $cooldown, $now, $notify);
                if ($trip !== null) {
                    $trips[] = $trip;
                }
            }
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'Detector ' . $rule['key'] . ' failed: ' . $e->getMessage());
        }
    }

    setting_save('sec_monitor_last_run', date('Y-m-d H:i:s', $now), 'security', 'text');

    return ['trips' => $trips, 'checked' => $checked, 'skipped' => ''];
}

/**
 * What one detector sees in its window.
 *
 * @return array<int, array{subject:string, label:string, count:int}>
 */
function security_monitor_rule_hits(array $rule, int $now): array
{
    $since  = date('Y-m-d H:i:s', $now - (int) $rule['window']);
    $types  = (array) $rule['types'];
    $params = ['since' => $since];
    $names  = [];
    foreach ($types as $i => $type) {
        $names[]            = ':t' . $i;
        $params['t' . $i]   = $type;
    }
    $typeSql = implode(', ', $names);

    if ($rule['scope'] === 'global') {
        $count = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `security_events` WHERE `type` IN ({$typeSql}) AND `created_at` >= :since",
            $params
        );
        return $count >= (int) $rule['threshold']
            ? [['subject' => 'store', 'label' => 'across the whole store', 'count' => $count]]
            : [];
    }

    // Why the grouped scopes filter in SQL and count what they had to leave
    // out
    //
    // These used to take the twenty loudest subjects and THEN drop the ones
    // under the threshold, which handed an attacker a way to be ignored:
    // thirteen failed sign-ins each against twenty-five throwaway customer
    // accounts fill all twenty slots, and the account actually being broken
    // into - sitting at exactly the threshold - falls off the end and raises
    // nothing at all. Measured: with 25 noise accounts at 13, the real admin
    // account at 12 was BURIED, no alert, no email.
    //
    // Three changes. The threshold moves into HAVING, so a slot is never spent
    // on a subject that was never going to alert. The cap rises to fifty, and
    // whatever is still over the line beyond that is counted and reported as
    // one extra "and more" trip, so a wide spray is louder than a narrow one
    // instead of silencing it. And an admin account sorts ahead of any number
    // of customer accounts, because the back office is what the spray is
    // usually cover for.
    $cap = 50;

    if ($rule['scope'] === 'ip') {
        $params['th'] = (int) $rule['threshold'];
        $rows = Database::fetchAll(
            "SELECT `ip_address` AS `subject`, COUNT(*) AS `c`
               FROM `security_events`
              WHERE `type` IN ({$typeSql}) AND `created_at` >= :since
                AND `ip_address` IS NOT NULL AND `ip_address` <> ''
              GROUP BY `ip_address`
             HAVING `c` >= :th
              ORDER BY `c` DESC
              LIMIT " . ($cap + 1),
            $params
        );

        return security_monitor_cap_hits(
            array_map(
                static fn (array $row): array => [
                    'subject' => (string) $row['subject'],
                    'label'   => (string) $row['subject'],
                    'count'   => (int) $row['c'],
                ],
                $rows
            ),
            $cap,
            'address',
            'addresses'
        );
    }

    // scope: account
    $params['th'] = (int) $rule['threshold'];
    $rows = Database::fetchAll(
        "SELECT `user_type`, `user_id`, COUNT(*) AS `c`
           FROM `security_events`
          WHERE `type` IN ({$typeSql}) AND `created_at` >= :since AND `user_id` IS NOT NULL
          GROUP BY `user_type`, `user_id`
         HAVING `c` >= :th
          ORDER BY `user_type` = 'admin' DESC, `c` DESC
          LIMIT " . ($cap + 1),
        $params
    );

    return security_monitor_cap_hits(
        array_map(
            static fn (array $row): array => [
                'subject' => (string) ($row['user_type'] ?? 'account') . '#' . (int) $row['user_id'],
                // The id, never the email: this string ends up in an email and
                // in a table an admin can export.
                'label'   => ((string) $row['user_type'] === 'admin' ? 'admin account #' : 'customer account #')
                    . (int) $row['user_id'],
                'count'   => (int) $row['c'],
            ],
            $rows
        ),
        $cap,
        'account',
        'accounts'
    );
}

/**
 * Trim a detector's subject list to the cap, and say so out loud.
 *
 * One row over the cap was fetched on purpose: its presence is how we know the
 * list was cut, without a second COUNT query. A cut list becomes one extra
 * trip rather than a silent truncation - "and at least 12 more accounts" is an
 * alert in itself, and it is the shape a password spray has.
 *
 * @param array<int, array{subject:string,label:string,count:int}> $hits
 * @param string $one  what one more subject is called ("account")
 * @param string $many what several are called ("accounts")
 * @return array<int, array{subject:string,label:string,count:int}>
 */
function security_monitor_cap_hits(array $hits, int $cap, string $one, string $many): array
{
    if (count($hits) <= $cap) {
        return $hits;
    }

    $kept    = array_slice($hits, 0, $cap);
    $dropped = count($hits) - $cap;

    $kept[] = [
        'subject' => 'spread',
        'label'   => 'at least ' . $dropped . ' further ' . ($dropped === 1 ? $one : $many)
            . ' over the line as well',
        // The count is what the quietest kept subject had: the point of this
        // trip is the SPREAD, and claiming a total nobody measured would be a
        // number the owner could not check.
        'count'   => (int) ($kept[$cap - 1]['count'] ?? 0),
    ];

    return $kept;
}

/**
 * Record one trip, once per cooldown window.
 *
 * The UNIQUE key on (rule, subject, bucket) is what stops the crying wolf: a
 * burst that lasts two hours raises two alerts, not one every time cron runs.
 *
 * @return array|null the trip, or null when this window already alerted
 */
function security_monitor_trip(array $rule, array $hit, int $cooldown, int $now, bool $notify): ?array
{
    $bucket = intdiv($now, $cooldown) * $cooldown;

    $summary = $rule['title'] . ' - ' . $hit['count'] . ' in '
        . security_monitor_window_label((int) $rule['window']) . ' (' . $hit['label'] . ')';

    try {
        $id = Database::insert('security_alerts', [
            'rule_key'     => (string) $rule['key'],
            'scope'        => (string) $rule['scope'],
            'subject'      => mb_substr((string) $hit['subject'], 0, 190),
            'bucket_start' => $bucket,
            'observed'     => (int) $hit['count'],
            'threshold'    => (int) $rule['threshold'],
            'window_secs'  => (int) $rule['window'],
            'severity'     => (string) $rule['severity'],
            'summary'      => mb_substr($summary, 0, 255),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return null;   // already alerted for this subject in this window
        }
        throw $e;
    }

    security_event('monitor.' . $rule['key'], (string) $rule['severity'], [
        'subject'   => $hit['label'],
        'observed'  => (int) $hit['count'],
        'threshold' => (int) $rule['threshold'],
        'window'    => security_monitor_window_label((int) $rule['window']),
        'alert_id'  => $id,
    ]);

    $emailed = false;
    if ($notify && setting_bool('sec_monitor_email', true)) {
        try {
            notify_admins('security_alert', [
                'alert_title'     => (string) $rule['title'],
                'alert_subject'   => (string) $hit['label'],
                'alert_observed'  => (string) $hit['count'],
                'alert_threshold' => (string) $rule['threshold'],
                'alert_window'    => security_monitor_window_label((int) $rule['window']),
                'alert_time'      => format_datetime(date('Y-m-d H:i:s', $now)),
                'alert_advice'    => (string) $rule['advice'],
                'alert_url'       => canonical_url('admin/security/events.php?rule=' . urlencode((string) $rule['key'])),
            ], 'security_alert', $id);
            Database::update('security_alerts', ['notified_at' => date('Y-m-d H:i:s')], '`id` = :id', ['id' => $id]);
            $emailed = true;
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'Security alert email not queued: ' . $e->getMessage());
        }
    }

    return [
        'id'       => $id,
        'rule'     => (string) $rule['key'],
        'severity' => (string) $rule['severity'],
        'summary'  => $summary,
        'emailed'  => $emailed,
    ];
}

/** "15 minutes", "an hour" - for an email a shop owner reads on a phone. */
function security_monitor_window_label(int $seconds): string
{
    if ($seconds < 3600) {
        return max(1, intdiv($seconds, 60)) . ' minutes';
    }
    $hours = intdiv($seconds, 3600);

    return $hours === 1 ? 'an hour' : $hours . ' hours';
}

/**
 * Has the cron sweep plainly not been set up (or stopped)?
 * Used by the events screen to sweep inline rather than watch nothing.
 */
function security_monitor_overdue(int $graceSeconds = 1800): bool
{
    if (!setting_bool('sec_monitor_enabled', true)) {
        return false;
    }
    $last = (string) setting('sec_monitor_last_run', '');
    if ($last === '') {
        return true;
    }
    $stamp = strtotime($last);

    return $stamp === false || time() - $stamp > $graceSeconds;
}

// ===========================================================================
//  5. Reading the log back
// ===========================================================================

/** The severities, worst first, for filter menus. */
function security_event_severity_order(): array
{
    return ['critical', 'high', 'medium', 'low', 'info'];
}

/** Which CSS status pill a severity gets. */
function security_severity_tone(string $severity): string
{
    switch ($severity) {
        case 'critical':
        case 'high':
            return 'red';
        case 'medium':
            return 'amber';
        case 'low':
            return 'blue';
        default:
            return 'gray';
    }
}

/**
 * Build the WHERE clause for the events screen and its export from one filter
 * array, so the list, the counts and the CSV can never disagree.
 *
 * @return array{sql:string, params:array}
 */
function security_events_filter(array $filters): array
{
    $where  = ['1'];
    $params = [];

    $type = trim((string) ($filters['type'] ?? ''));
    if ($type !== '') {
        if (substr($type, -1) === '.') {
            $where[]          = '`type` LIKE :type';
            $params['type']   = $type . '%';
        } else {
            $where[]          = '`type` = :type';
            $params['type']   = $type;
        }
    }

    $severity = trim((string) ($filters['severity'] ?? ''));
    if ($severity !== '' && in_array($severity, SECURITY_EVENT_SEVERITIES, true)) {
        $where[]            = '`severity` = :severity';
        $params['severity'] = $severity;
    }

    $ip = trim((string) ($filters['ip'] ?? ''));
    if ($ip !== '') {
        $where[]      = '`ip_address` LIKE :ip';
        $params['ip'] = '%' . $ip . '%';
    }

    $account = trim((string) ($filters['account'] ?? ''));
    if ($account !== '') {
        // "admin#3" from an alert, or a bare id typed by hand.
        if (preg_match('/^(admin|customer|api)#(\d+)$/i', $account, $m) === 1) {
            $where[]              = '`user_type` = :utype AND `user_id` = :uid';
            $params['utype']      = strtolower($m[1]);
            $params['uid']        = (int) $m[2];
        } elseif (ctype_digit($account)) {
            $where[]       = '`user_id` = :uid';
            $params['uid'] = (int) $account;
        } else {
            $where[]         = '`user_type` = :utype';
            $params['utype'] = mb_substr($account, 0, 20);
        }
    }

    $from = trim((string) ($filters['from'] ?? ''));
    if ($from !== '' && strtotime($from) !== false) {
        $where[]        = '`created_at` >= :from';
        $params['from'] = date('Y-m-d 00:00:00', (int) strtotime($from));
    }

    $to = trim((string) ($filters['to'] ?? ''));
    if ($to !== '' && strtotime($to) !== false) {
        $where[]      = '`created_at` <= :to';
        $params['to'] = date('Y-m-d 23:59:59', (int) strtotime($to));
    }

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[]       = '(`path` LIKE :q OR `context` LIKE :q2 OR `type` LIKE :q3)';
        $params['q']   = '%' . $search . '%';
        $params['q2']  = '%' . $search . '%';
        $params['q3']  = '%' . $search . '%';
    }

    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

/**
 * A stored context, decoded and made safe to print.
 *
 * security_event_mask() already ran at write time, but it runs again here:
 * rows written before it existed, or by a caller that passed an odd key name,
 * must not print a secret onto an admin screen that can be exported to CSV.
 */
function security_event_context_display(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return ['(unreadable)' => mb_substr($json, 0, 300)];
    }

    $masked = security_event_mask($decoded);

    // A second pass for values that LOOK like a credential whatever the key
    // was called - a full email address, or a long opaque string.
    array_walk_recursive($masked, static function (&$value): void {
        if (!is_string($value)) {
            return;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $value = mask_email($value);
            return;
        }
        if (preg_match('/^[A-Za-z0-9_\-]{40,}$/', $value) === 1) {
            $value = mb_substr($value, 0, 6) . '...[' . mb_strlen($value) . ' chars]';
        }
    });

    return $masked;
}

/**
 * Delete events past the retention window.
 * Bounded so a first run on a large table cannot hold the database.
 *
 * @return int rows removed
 */
function security_events_prune(?int $days = null, int $maxRows = 20000): int
{
    $days = $days ?? setting_int('sec_events_retention_days', 90);
    if ($days <= 0) {
        return 0;   // 0 = keep everything, the owner's choice
    }

    $removed = 0;
    try {
        // In slices: one unbounded DELETE over a year of events locks the
        // table the whole site writes to.
        while ($removed < $maxRows) {
            $slice = Database::query(
                'DELETE FROM `security_events` WHERE `created_at` < (NOW() - INTERVAL :d DAY) LIMIT 1000',
                ['d' => $days]
            )->rowCount();
            $removed += $slice;
            if ($slice < 1000) {
                break;
            }
        }

        // The alerts and the CSP groups follow the same window - they are
        // summaries of events that no longer exist.
        Database::query('DELETE FROM `security_alerts` WHERE `created_at` < (NOW() - INTERVAL :d DAY)', ['d' => $days]);
        Database::query(
            "DELETE FROM `csp_reports` WHERE `last_seen` < (NOW() - INTERVAL :d DAY) AND `status` <> 'allowed'",
            ['d' => $days]
        );
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Security log pruning failed: ' . $e->getMessage());
    }

    if ($removed > 0) {
        security_event('logs.security_pruned', 'info', ['removed' => $removed, 'older_than_days' => $days]);
    }

    return $removed;
}
