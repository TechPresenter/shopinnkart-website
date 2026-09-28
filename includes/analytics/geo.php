<?php
/**
 * ShopInnKart - Analytics geo lookup.
 *
 * Called ONCE per session, at the moment the session row is created.
 *
 * WHAT IS STORED, AND WHAT IS NOT
 * -------------------------------
 * The address is an argument to this file and a local variable inside it. It is
 * never returned, never logged, never cached and never written to any table.
 * What CAN be stored is a two-letter country code, and - only when the owner
 * has asked for city precision - a city name and an ISO 3166-2 region for
 * India. Nothing else about the address survives this file, which is why the
 * store's own counting still needs no consent banner after geo is switched on.
 *
 * ONE SETTING CHOOSES THE SOURCE
 * ------------------------------
 *   analytics_geo_source = off | cloudflare | geolite2
 *
 *   off         Every lookup returns nulls and every screen reads "Unknown".
 *               Honest, rather than guessing a country from Accept-Language,
 *               which is a language preference and not a location (half of
 *               India browses in en-US).
 *
 *   cloudflare  CF-IPCountry, read ONLY when the machine that actually
 *               connected is inside Cloudflare's published ranges. CF-IPCountry
 *               is just a header: anyone can send one, and without the range
 *               check a visitor could label their own traffic, which is worse
 *               than no data. Do not weaken that check.
 *
 *   geolite2    A MaxMind GeoLite2 database the owner supplies. Drop
 *               GeoLite2-City.mmdb (or -Country.mmdb) into storage/geo/ and the
 *               maxmind-db reader into vendor/maxmind-db/. Nothing leaves the
 *               server: the lookup is a read of a local file.
 *
 * A second setting, analytics_geo_precision, decides how much of a result is
 * kept. It is enforced in ONE place, an_geo_narrow(), so no source can widen it
 * and a source added later cannot forget it. It defaults to country, because a
 * city inferred from an address is a guess with a wide error bar, and an owner
 * who does not know that reads the numbers as if they were facts.
 *
 * REGION is kept for India only (IN-MH, IN-KA...). A worldwide region dimension
 * multiplies the rollup's cardinality for rows nobody here reads.
 *
 * an_geo_status() reports what is happening on THIS request, and Settings >
 * Analytics shows it. an_geo_note() is the one-sentence version for a report
 * footnote, and it deliberately does NOT depend on the current request.
 */

declare(strict_types=1);

/** Where an owner-supplied MaxMind database would live, under STORAGE_PATH. */
const AN_GEO_DIR = '/geo';

/** The .mmdb names looked for, in order. City beats country. */
const AN_GEO_FILES = ['GeoLite2-City.mmdb', 'GeoLite2-Country.mmdb'];

/** Valid analytics_geo_source values. Anything else is read as 'off'. */
const AN_GEO_SOURCES = ['off', 'cloudflare', 'geolite2'];

/** Valid analytics_geo_precision values. Anything else is read as 'country'. */
const AN_GEO_PRECISIONS = ['country', 'city'];

/**
 * Where the maxmind-db reader may have been unpacked, relative to ROOT_PATH.
 * The first is what Composer would produce, the second what unzipping the
 * library's own repository produces. There is no autoloader in this project,
 * so the classes are required by hand - see an_geo_load_reader().
 */
const AN_GEO_READER_DIRS = ['/vendor/maxmind-db/reader/src', '/vendor/maxmind-db/src', '/vendor/maxmind-db'];

/** GeoLite2 is rebuilt weekly; past this many days a copy is worth replacing. */
const AN_GEO_STALE_DAYS = 90;

/**
 * A public address used to prove a .mmdb actually resolves something.
 *
 * Only ever passed to the local file - no request is made to it - and the
 * answer is shown on the settings screen and stored nowhere.
 */
const AN_GEO_PROBE_IP = '8.8.8.8';

/**
 * Cloudflare's published edge ranges, embedded.
 *
 * Fetching https://www.cloudflare.com/ips-v4 at runtime would put a network
 * call in front of a page view and hand the decision to whoever can answer
 * that request. The list changes rarely; an_geo_status() reports how many are
 * embedded so a stale copy is visible rather than silent.
 */
const AN_CLOUDFLARE_V4 = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
];
const AN_CLOUDFLARE_V6 = [
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

// ===========================================================================
//  The two settings
// ===========================================================================

/**
 * off | cloudflare | geolite2.
 *
 * A store configured before this setting existed said the same thing with
 * analytics_trusted_proxy, so that value is still honoured when the new key has
 * never been written - otherwise an upgrade would silently stop recording.
 */
function an_geo_source(): string
{
    $source = trim((string) setting('analytics_geo_source', ''));

    if ($source === '') {
        $source = (string) setting('analytics_trusted_proxy', 'none') === 'cloudflare' ? 'cloudflare' : 'off';
    }

    return in_array($source, AN_GEO_SOURCES, true) ? $source : 'off';
}

/** country | city. Country is the default, and the narrower answer. */
function an_geo_precision(): string
{
    $precision = trim((string) setting('analytics_geo_precision', 'country'));

    return in_array($precision, AN_GEO_PRECISIONS, true) ? $precision : 'country';
}

// ===========================================================================
//  The lookup
// ===========================================================================

/**
 * Country / region / city for an address, or nulls when we cannot know.
 *
 * The address is used and dropped. Nothing here returns it, and the caller
 * (an_session_create) writes only the three values below.
 *
 * @return array{country:?string, region:?string, city:?string}
 */
function an_geo_lookup(string $ip): array
{
    $unknown = ['country' => null, 'region' => null, 'city' => null];

    $source = an_geo_source();
    if ($source === 'off' || $ip === '') {
        return $unknown;
    }

    $found = $source === 'cloudflare' ? an_geo_from_cloudflare() : an_geo_from_maxmind($ip);

    return an_geo_narrow($found ?? $unknown);
}

/**
 * The owner's precision, enforced in one place.
 *
 * Every source passes through here, so "country only" cannot be widened by a
 * source that happens to know more, and a source added later cannot forget the
 * setting exists.
 */
function an_geo_narrow(array $geo): array
{
    $result = [
        'country' => $geo['country'] ?? null,
        'region'  => $geo['region'] ?? null,
        'city'    => $geo['city'] ?? null,
    ];

    if (an_geo_precision() === 'country') {
        $result['region'] = null;
        $result['city']   = null;
    }

    return $result;
}

/**
 * The Cloudflare headers, but only from Cloudflare.
 *
 * Returns null when the owner has not chosen Cloudflare, when the connecting
 * address is not one of Cloudflare's, or when the header is absent - all of
 * which mean "nothing to record", not "the visitor is nowhere".
 */
function an_geo_from_cloudflare(): ?array
{
    if (an_geo_source() !== 'cloudflare') {
        return null;
    }

    if (!an_geo_peer_is_cloudflare()) {
        return null;
    }

    $country = an_geo_cf_country();
    if ($country === null) {
        return null;
    }
    // XX = unknown to Cloudflare, T1 = Tor exit. Both are "we do not know".
    if ($country === 'XX' || $country === 'T1') {
        return ['country' => null, 'region' => null, 'city' => null];
    }

    // These two arrive only when the "Add visitor location headers" managed
    // transform is switched on, which is plan-dependent - hence the guards.
    // an_geo_narrow() drops them again unless the owner asked for city.
    $region = strtoupper(trim((string) ($_SERVER['HTTP_CF_REGION_CODE'] ?? '')));
    $region = ($country === 'IN' && preg_match('/^[A-Z0-9]{1,3}$/', $region) === 1) ? $country . '-' . $region : null;

    $city = trim((string) ($_SERVER['HTTP_CF_IPCITY'] ?? ''));
    $city = $city !== '' ? mb_substr($city, 0, 80) : null;

    return ['country' => $country, 'region' => $region, 'city' => $city];
}

/** Did this request's connection really come from a Cloudflare edge? */
function an_geo_peer_is_cloudflare(): bool
{
    return an_ip_in_any(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        array_merge(AN_CLOUDFLARE_V4, AN_CLOUDFLARE_V6)
    );
}

/** The CF-IPCountry code on this request, or null when absent or malformed. */
function an_geo_cf_country(): ?string
{
    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));

    return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
}

/**
 * MaxMind GeoLite2, when the owner has chosen it and supplied both the database
 * and the reader. Returns null when any of those is missing, so the caller
 * records "Unknown" instead of failing a page view.
 */
function an_geo_from_maxmind(string $ip): ?array
{
    if (an_geo_source() !== 'geolite2') {
        return null;
    }

    $reader = an_geo_reader();
    if ($reader === null) {
        return null;
    }

    try {
        $record = $reader->get($ip);
    } catch (Throwable $e) {
        // Deliberately not logged: the message can contain the address, and
        // this file does not put an address anywhere it would persist.
        return null;
    }

    return is_array($record)
        ? an_geo_from_record($record)
        : ['country' => null, 'region' => null, 'city' => null];
}

/** One GeoLite2 record, reduced to the three values that may be stored. */
function an_geo_from_record(array $record): array
{
    $country = strtoupper((string) ($record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? ''));
    $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

    $region = null;
    if ($country === 'IN' && !empty($record['subdivisions'][0]['iso_code'])) {
        $region = $country . '-' . strtoupper((string) $record['subdivisions'][0]['iso_code']);
    }

    $city = (string) ($record['city']['names']['en'] ?? '');
    $city = $city !== '' ? mb_substr($city, 0, 80) : null;

    return ['country' => $country, 'region' => $region, 'city' => $city];
}

// ===========================================================================
//  The GeoLite2 file and its reader
// ===========================================================================

/** The owner-supplied .mmdb, or '' when there is none. */
function an_geo_database_file(): string
{
    foreach (AN_GEO_FILES as $name) {
        $path = an_geo_directory() . '/' . $name;
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

/** The directory an owner drops the .mmdb into. Denied to the web. */
function an_geo_directory(): string
{
    return STORAGE_PATH . AN_GEO_DIR;
}

/**
 * The open reader, or null - cached for the request.
 *
 * NOT gated on the source setting, so the settings screen can say "the file is
 * here and readable" before the owner switches it on. The gate is in
 * an_geo_from_maxmind(), where the lookup happens.
 */
function an_geo_reader(): ?object
{
    static $reader = false;   // false = not tried yet, null = unavailable

    if ($reader !== false) {
        return $reader;
    }
    $reader = null;

    $file = an_geo_database_file();
    if ($file === '') {
        an_geo_reader_error('No ' . AN_GEO_FILES[0] . ' or ' . AN_GEO_FILES[1] . ' in that folder.');
        return null;
    }

    if (!an_geo_load_reader()) {
        an_geo_reader_error('The MaxMind reader classes are not installed.');
        return null;
    }

    try {
        $readerClass = 'MaxMind\\Db\\Reader';
        $reader = new $readerClass($file);
        an_geo_reader_error('');
    } catch (Throwable $e) {
        // A corrupt or half-uploaded .mmdb must not take page views down with
        // it; it just means no geo. The message names the file, not an address.
        an_geo_reader_error($e->getMessage());
        if (class_exists('ErrorHandler')) {
            ErrorHandler::log('warning', 'GeoLite2 database unreadable: ' . $e->getMessage());
        }
        $reader = null;
    }

    return $reader;
}

/** Why an_geo_reader() returned null, for the settings screen. */
function an_geo_reader_error(?string $set = null): string
{
    static $error = '';

    if ($set !== null) {
        $error = $set;
    }

    return $error;
}

/** The directory that holds MaxMind/Db/Reader.php, or '' when none does. */
function an_geo_reader_dir(): string
{
    foreach (AN_GEO_READER_DIRS as $dir) {
        if (is_file(ROOT_PATH . $dir . '/MaxMind/Db/Reader.php')) {
            return ROOT_PATH . $dir;
        }
    }

    return '';
}

/**
 * Make MaxMind\Db\Reader available.
 *
 * This project has no Composer autoloader - PHPMailer and TCPDF are required by
 * hand too - so a reader dropped into vendor/ would otherwise never be found,
 * and the screen would go on saying "not installed" while the files sat there.
 */
function an_geo_load_reader(): bool
{
    if (class_exists('MaxMind\\Db\\Reader', false)) {
        return true;
    }

    $dir = an_geo_reader_dir();
    if ($dir === '') {
        return false;
    }

    // Order does not matter to PHP - nothing runs at include time - but this is
    // dependency order, which is the order a reader of this list expects.
    foreach ([
        'MaxMind/Db/Reader/InvalidDatabaseException.php',
        'MaxMind/Db/Reader/Util.php',
        'MaxMind/Db/Reader/Decoder.php',
        'MaxMind/Db/Reader/Metadata.php',
        'MaxMind/Db/Reader.php',
    ] as $relative) {
        $path = $dir . '/' . $relative;
        if (is_file($path)) {
            require_once $path;
        }
    }

    return class_exists('MaxMind\\Db\\Reader', false);
}

/**
 * When the .mmdb was BUILT, from its own metadata - not when it was copied
 * here. Null when the reader cannot tell us, and the screen then says which
 * date it is showing instead.
 */
function an_geo_build_epoch(): ?int
{
    $reader = an_geo_reader();
    if ($reader === null || !method_exists($reader, 'metadata')) {
        return null;
    }

    try {
        $meta  = $reader->metadata();
        $epoch = (int) ($meta->buildEpoch ?? 0);
    } catch (Throwable $e) {
        return null;
    }

    return $epoch > 0 ? $epoch : null;
}

/**
 * Prove the file resolves something, using a known public address.
 *
 * No request is made to that address - it is a key into a local file - and the
 * answer is displayed, never stored.
 */
function an_geo_probe(string $ip = AN_GEO_PROBE_IP): ?array
{
    $reader = an_geo_reader();
    if ($reader === null) {
        return null;
    }

    try {
        $record = $reader->get($ip);
    } catch (Throwable $e) {
        return null;
    }

    return is_array($record) ? an_geo_from_record($record) : null;
}

// ===========================================================================
//  What is happening right now
// ===========================================================================

/**
 * public | local | none for the address THIS request would be placed by.
 *
 * A classification, never the address itself: nothing in this file hands an
 * address back to a caller that might log or cache it.
 */
function an_geo_peer_scope(): string
{
    $ip = function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return 'none';
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        ? 'public'
        : 'local';
}

/** Human label for a source value. */
function an_geo_source_label(string $source): string
{
    return [
        'off'        => 'Off',
        'cloudflare' => 'Cloudflare',
        'geolite2'   => 'MaxMind GeoLite2',
    ][$source] ?? $source;
}

/**
 * What the settings screen shows: what is happening on THIS request, and why
 * it is not happening anywhere else.
 *
 * `live` means a location would be recorded for a visit exactly like this one.
 * `configured_ok` means the source can work at all, which is the request-
 * independent half - Cloudflare's header depends on the individual request, so
 * a report footnote must not be built from `live`.
 *
 * @return array{source:string, label:string, precision:string, live:bool,
 *               configured_ok:bool, detail:string, hint:string, file:string,
 *               built:?int, file_date:?int, reader:bool, reader_dir:string,
 *               reader_error:string, dir:string, dir_exists:bool,
 *               cf_peer:bool, cf_country:?string, ranges:int,
 *               peer_scope:string, proxy_configured:bool, stale_days:?int,
 *               attribution:string}
 */
function an_geo_status(): array
{
    $source = an_geo_source();

    $status = [
        'source'           => $source,
        'label'            => an_geo_source_label($source),
        'precision'        => an_geo_precision(),
        'live'             => false,
        'configured_ok'    => false,
        'detail'           => '',
        'hint'             => '',
        'file'             => '',
        'built'            => null,
        'file_date'        => null,
        'reader'           => an_geo_load_reader(),
        'reader_dir'       => an_geo_reader_dir(),
        'reader_error'     => '',
        'dir'              => an_geo_directory(),
        'dir_exists'       => is_dir(an_geo_directory()),
        'cf_peer'          => an_geo_peer_is_cloudflare(),
        'cf_country'       => an_geo_cf_country(),
        'ranges'           => count(AN_CLOUDFLARE_V4) + count(AN_CLOUDFLARE_V6),
        'peer_scope'       => an_geo_peer_scope(),
        'proxy_configured' => function_exists('trusted_proxies') && trusted_proxies() !== [],
        'stale_days'       => null,
        'attribution'      => '',
    ];

    if ($source === 'off') {
        $status['detail'] = 'Nothing is being recorded. Country, state and city read Unknown for every visit.';

        return $status;
    }

    if ($source === 'cloudflare') {
        // Cloudflare can always work; whether it worked on THIS request is a
        // separate question, and the two are reported separately on purpose.
        $status['configured_ok'] = true;

        if ($status['cf_peer'] && $status['cf_country'] !== null) {
            $status['live'] = true;
            $status['detail'] = in_array($status['cf_country'], ['XX', 'T1'], true)
                ? 'Cloudflare is being read, but it could not place this request (' . $status['cf_country']
                    . '), which is what it reports for Tor and for addresses it does not know.'
                : 'Working on this request: Cloudflare placed it in ' . $status['cf_country'] . '.';
        } elseif ($status['cf_peer']) {
            $status['detail'] = 'This request did arrive through Cloudflare, but carried no CF-IPCountry header.';
            $status['hint']   = 'Switch IP Geolocation on in Cloudflare, under Network.';
        } elseif ($status['cf_country'] !== null) {
            $status['detail'] = 'A CF-IPCountry header arrived on this request, but the connection did not come '
                              . 'from one of Cloudflare\'s published ranges, so it was ignored.';
            $status['hint']   = 'That check is what stops a visitor labelling their own traffic.';
        } else {
            $status['detail'] = 'Set to Cloudflare, but this request carried no CF-IPCountry header and did not '
                              . 'arrive through Cloudflare, so nothing would be recorded for a visit like it.';
        }

        return $status;
    }

    // ---- MaxMind GeoLite2 -------------------------------------------------
    $file = an_geo_database_file();
    $status['file'] = $file === '' ? '' : basename($file);

    if ($file === '') {
        $status['detail'] = 'No GeoLite2 file in that folder, so nothing is being recorded.';
        $status['hint']   = 'Put ' . AN_GEO_FILES[0] . ' there, then reload this page.';

        return $status;
    }

    $status['file_date'] = (int) filemtime($file) ?: null;

    if (!$status['reader']) {
        $status['detail'] = $status['file'] . ' is here, but the MaxMind reader is not installed, '
                          . 'so the file cannot be read and nothing is being recorded.';
        $status['hint']   = 'Unpack maxmind-db/reader into ' . ROOT_PATH . AN_GEO_READER_DIRS[0] . '.';

        return $status;
    }

    if (an_geo_reader() === null) {
        $status['reader_error'] = an_geo_reader_error();
        $status['detail'] = $status['file'] . ' could not be opened, so nothing is being recorded.';
        $status['hint']   = 'The file may be truncated - download it again.';

        return $status;
    }

    $status['built'] = an_geo_build_epoch();
    if ($status['built'] !== null) {
        $status['stale_days'] = (int) floor((time() - $status['built']) / 86400);
    }

    $probe = an_geo_probe();
    if ($probe === null || $probe['country'] === null) {
        // The reader opened it and it answers nothing for a known public
        // address: a stub, or a database of a different kind. Reporting that as
        // "working" is how an owner ends up wondering why the map is empty.
        $status['detail'] = $status['file'] . ' opened, but a known public address resolved to nothing, '
                          . 'so this copy is not usable.';
        $status['hint']   = 'Download ' . AN_GEO_FILES[0] . ' again from MaxMind.';

        return $status;
    }

    $status['configured_ok'] = true;
    $status['live']          = true;
    $status['attribution']   = 'This product includes GeoLite2 data created by MaxMind, '
                             . 'available from https://www.maxmind.com.';
    $status['detail'] = 'Working: ' . $status['file'] . ' resolved a known public address to '
                      . $probe['country'] . '.';

    if ($status['stale_days'] !== null && $status['stale_days'] > AN_GEO_STALE_DAYS) {
        $status['hint'] = 'This copy was built ' . $status['stale_days'] . ' days ago; GeoLite2 is rebuilt weekly.';
    } elseif ($status['peer_scope'] === 'local') {
        $status['hint'] = 'A visit from this machine still could not be placed: its address is a private one.';
    } elseif (!$status['proxy_configured']) {
        $status['hint'] = 'Behind a CDN, list its ranges under Security so the visitor is looked up, not the CDN.';
    }

    return $status;
}

/**
 * One sentence a report can print under a location table.
 *
 * Built from the CONFIGURED source, never from whether this particular request
 * carried a Cloudflare header - a report rendered on a laptop must not tell the
 * owner that their visitors have no location.
 */
function an_geo_note(bool $pointer = true): string
{
    $status = an_geo_status();
    // Settings > Analytics is where the answer is, which is worth saying in a
    // report and merely self-referential on that screen itself.
    $where = $pointer ? ' Settings > Analytics' : '';

    if ($status['source'] === 'off') {
        return 'Country, state and city read "Unknown": no location source is configured, so no location '
             . 'was ever collected.' . ($pointer ? $where . ' turns one on.' : '');
    }

    if (!$status['configured_ok']) {
        return 'Country, state and city read "Unknown": location is set to ' . $status['label']
             . ', but it is not working.' . ($pointer ? $where . ' says why.' : '');
    }

    $where = $status['source'] === 'cloudflare'
        ? 'from Cloudflare\'s own header, and only when the request really came through Cloudflare'
        : 'by looking the address up in your own GeoLite2 file';

    $grain = $status['precision'] === 'country'
        ? 'Only the country is recorded'
        : 'Country is recorded, with city - and state within India - as an estimate that is far more often '
            . 'right about the region than about the town';

    return $grain . ', ' . $where . '. Visits from before you switched this on have none, '
         . 'so they read "Unknown".';
}

// ===========================================================================
//  CIDR helpers
//
//  Also used by the collector's excluded-IP list, so they live here rather
//  than being written twice.
// ===========================================================================

/** Is $ip inside any of these CIDR blocks (or bare addresses)? */
function an_ip_in_any(string $ip, array $ranges): bool
{
    foreach ($ranges as $range) {
        if (an_ip_in_range($ip, (string) $range)) {
            return true;
        }
    }
    return false;
}

/** Is $ip inside one CIDR block? A bare address means /32 (or /128). */
function an_ip_in_range(string $ip, string $range): bool
{
    $range = trim($range);
    if ($ip === '' || $range === '') {
        return false;
    }

    $packedIp = @inet_pton($ip);
    if ($packedIp === false) {
        return false;
    }

    [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
    $packedSubnet = @inet_pton((string) $subnet);
    if ($packedSubnet === false || strlen($packedSubnet) !== strlen($packedIp)) {
        return false;   // comparing v4 with v6 is never a match
    }

    $maxBits = strlen($packedIp) * 8;
    $bits    = $bits === null ? $maxBits : (int) $bits;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($bits, 8);
    $extraBits  = $bits % 8;

    if ($wholeBytes > 0 && strncmp($packedIp, $packedSubnet, $wholeBytes) !== 0) {
        return false;
    }
    if ($extraBits === 0) {
        return true;
    }

    $mask = ~((1 << (8 - $extraBits)) - 1) & 0xFF;

    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
}
