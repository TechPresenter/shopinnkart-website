<?php
/**
 * ShopInnKart - Analytics geo lookup.
 *
 * Called ONCE per session, at the moment the session row is created. The IP is
 * an argument here and a local variable inside; it is never returned, never
 * logged and never stored. What is stored is at most a country code, an ISO
 * 3166-2 region and a city name.
 *
 * THE OWNER'S ANSWER TODAY (spec Q5, Q6): no Cloudflare and no GeoLite2 file.
 * So every lookup returns nulls and every screen shows "Unknown" - honestly,
 * rather than guessing a country from Accept-Language, which is a language
 * preference and not a location (half of India browses in en-US).
 *
 * Both upgrades are one setting away, and both are written here already:
 *
 *   1. Cloudflare. Set `analytics_trusted_proxy` to `cloudflare`. The header is
 *      then read ONLY when the machine that actually connected is inside
 *      Cloudflare's published ranges, because CF-IPCountry is just a header:
 *      anyone can send one. Without that check a visitor could label their own
 *      traffic, which is worse than no data.
 *   2. MaxMind GeoLite2. Drop GeoLite2-City.mmdb (or -Country.mmdb) into
 *      storage/geo/ - already denied to the web by its .htaccess - and vendor
 *      maxmind-db/reader under vendor/maxmind-db/. an_geo_status() reports
 *      what it finds so the settings screen can say "file found, built on X"
 *      instead of the owner wondering why the map is still empty.
 *
 * REGION is kept for India only (IN-MH, IN-KA...). A worldwide region
 * dimension multiplies the rollup's cardinality for rows nobody here reads.
 */

declare(strict_types=1);

/** Where an owner-supplied MaxMind database would live. */
const AN_GEO_DIR = '/geo';

/**
 * Cloudflare's published edge ranges, embedded.
 *
 * Fetching https://www.cloudflare.com/ips-v4 at runtime would put a network
 * call in front of a page view and hand the decision to whoever can answer
 * that request. The list changes rarely; an_geo_status() shows it so a stale
 * entry is visible rather than silent.
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

/**
 * Country / region / city for an IP, or nulls when we cannot know.
 *
 * @return array{country:?string, region:?string, city:?string}
 */
function an_geo_lookup(string $ip): array
{
    $unknown = ['country' => null, 'region' => null, 'city' => null];

    if ($ip === '') {
        return $unknown;
    }

    $fromCloudflare = an_geo_from_cloudflare();
    if ($fromCloudflare !== null) {
        return $fromCloudflare;
    }

    $fromMaxMind = an_geo_from_maxmind($ip);
    if ($fromMaxMind !== null) {
        return $fromMaxMind;
    }

    return $unknown;
}

/**
 * The Cloudflare headers, but only from Cloudflare.
 *
 * Returns null when the owner has not said they are behind Cloudflare, when
 * the connecting address is not one of Cloudflare's, or when the header is
 * absent - all of which mean "ask the next source", not "unknown".
 */
function an_geo_from_cloudflare(): ?array
{
    if ((string) setting('analytics_trusted_proxy', 'none') !== 'cloudflare') {
        return null;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!an_ip_in_any($remote, array_merge(AN_CLOUDFLARE_V4, AN_CLOUDFLARE_V6))) {
        return null;
    }

    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
        return null;
    }
    // XX = unknown to Cloudflare, T1 = Tor exit. Both are "we do not know".
    if ($country === 'XX' || $country === 'T1') {
        return ['country' => null, 'region' => null, 'city' => null];
    }

    // These two arrive only when the "Add visitor location headers" managed
    // transform is switched on, which is plan-dependent - hence the guards.
    $region = strtoupper(trim((string) ($_SERVER['HTTP_CF_REGION_CODE'] ?? '')));
    $region = ($country === 'IN' && preg_match('/^[A-Z0-9]{1,3}$/', $region) === 1) ? $country . '-' . $region : null;

    $city = trim((string) ($_SERVER['HTTP_CF_IPCITY'] ?? ''));
    $city = $city !== '' ? mb_substr($city, 0, 80) : null;

    return ['country' => $country, 'region' => $region, 'city' => $city];
}

/**
 * MaxMind GeoLite2, when the owner has supplied both the database and the
 * reader. Returns null when either is missing, so the caller falls through to
 * "Unknown" instead of failing.
 */
function an_geo_from_maxmind(string $ip): ?array
{
    static $reader = false;   // false = not tried yet, null = unavailable

    if ($reader === false) {
        $reader = null;
        $file   = an_geo_database_file();

        if ($file !== '' && class_exists('MaxMind\\Db\\Reader')) {
            try {
                $readerClass = 'MaxMind\\Db\\Reader';
                $reader = new $readerClass($file);
            } catch (Throwable $e) {
                // A corrupt or half-uploaded .mmdb must not take page views
                // down with it; it just means no geo.
                ErrorHandler::log('warning', 'GeoLite2 database unreadable: ' . $e->getMessage());
                $reader = null;
            }
        }
    }

    if ($reader === null) {
        return null;
    }

    try {
        $record = $reader->get($ip);
    } catch (Throwable $e) {
        return null;
    }

    if (!is_array($record)) {
        return ['country' => null, 'region' => null, 'city' => null];
    }

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

/** The owner-supplied .mmdb, or '' when there is none. City beats country. */
function an_geo_database_file(): string
{
    foreach (['GeoLite2-City.mmdb', 'GeoLite2-Country.mmdb'] as $name) {
        $path = STORAGE_PATH . AN_GEO_DIR . '/' . $name;
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

/**
 * What the settings screen shows: where geo would come from right now, and why
 * it is not coming from anywhere else.
 *
 * @return array{source:string, detail:string, file:string, built:?int, attribution:string}
 */
function an_geo_status(): array
{
    $proxy = (string) setting('analytics_trusted_proxy', 'none');
    $cfSeen = isset($_SERVER['HTTP_CF_IPCOUNTRY']);

    if ($proxy === 'cloudflare') {
        return [
            'source' => 'cloudflare',
            'detail' => $cfSeen
                ? 'Reading CF-IPCountry from Cloudflare.'
                : 'Set to Cloudflare, but this request carried no CF-IPCountry header.',
            'file'   => '',
            'built'  => null,
            'attribution' => '',
        ];
    }

    $file = an_geo_database_file();
    if ($file !== '') {
        $hasReader = class_exists('MaxMind\\Db\\Reader');
        return [
            'source' => $hasReader ? 'geolite2' : 'geolite2-no-reader',
            'detail' => $hasReader
                ? 'Using ' . basename($file) . '.'
                : basename($file) . ' is present, but vendor/maxmind-db/ is not installed, so it cannot be read.',
            'file'   => basename($file),
            'built'  => (int) filemtime($file),
            'attribution' => 'This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com.',
        ];
    }

    return [
        'source' => 'none',
        'detail' => 'No geo source configured, so country, state and city are recorded as Unknown.',
        'file'   => '',
        'built'  => null,
        'attribution' => '',
    ];
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
