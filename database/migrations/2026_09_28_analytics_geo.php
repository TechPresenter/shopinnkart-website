<?php
/**
 * ShopInnKart - Migration: the location-analytics controls.
 *
 * includes/analytics/geo.php has been able to place a visit since phase B2, from
 * either Cloudflare's CF-IPCountry header or a MaxMind GeoLite2 file. It was
 * gated on a setting no screen ever wrote (analytics_trusted_proxy), so on every
 * installed store the answer was "off" and the only way to change it was an
 * UPDATE on the settings table by hand.
 *
 * This creates the two keys Settings > Analytics now owns and writes:
 *
 *   analytics_geo_source     off | cloudflare | geolite2
 *   analytics_geo_precision  country | city
 *
 * WHY analytics_trusted_proxy IS NOT REUSED
 * -----------------------------------------
 * It named a proxy, and the choice is no longer only about a proxy: "look the
 * address up in a file on this server" is not a trusted-proxy question. A store
 * that had set the old key to `cloudflare` keeps working - the new value is
 * seeded from it below, and an_geo_source() also falls back to it while the new
 * key is unwritten - so nothing stops recording across an upgrade. The old row
 * is left alone rather than deleted: it is an owner's value, and after this
 * migration it is inert.
 *
 * WHY THE DEFAULT IS COUNTRY, NOT CITY
 * ------------------------------------
 * A city inferred from an address is a guess with a wide error bar. An owner who
 * has not been told that reads city rows as facts, so the narrower answer is the
 * default and the screen says what the wider one costs.
 *
 * IT ALSO CREATES storage/geo/
 * ----------------------------
 * With the same .htaccess the rest of storage/ carries, so a .mmdb dropped in
 * there is never served over HTTP, and a README saying which file goes in it -
 * "put the file here" is only useful advice when "here" exists.
 *
 * No table is created: an_sessions.country, .region and .city already exist, and
 * so do the rollup's country and region columns.
 *
 * Idempotent. Never DROPs, never overwrites a value an owner has set.
 *
 *     php database/migrations/2026_09_28_analytics_geo.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once INCLUDES_PATH . '/analytics/geo.php';

/** The group these keys are filed under: the screen that writes them. */
const ANALYTICS_GEO_GROUP = 'analytics';

/** key => [shipped value, storage type]. Written only when the key is absent. */
const ANALYTICS_GEO_SETTINGS = [
    'analytics_geo_source'    => ['off', 'select'],
    'analytics_geo_precision' => ['country', 'select'],
];

/** The .htaccess every server-side storage folder carries. */
const ANALYTICS_GEO_HTACCESS = <<<'TXT'
# ShopInnKart - this directory holds a geolocation database, not web content.
# Nothing in here should ever be requested directly over HTTP.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
TXT;

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_analytics_geo_run(): array
{
    $applied = [];
    $errors  = [];

    $run = static function (string $label, callable $fn) use (&$applied, &$errors): void {
        try {
            $line = $fn();
            if ($line !== null && $line !== false) {
                $applied[] = is_string($line) ? $line : $label;
            }
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    // The value the old key was carrying, if any. Read once, before anything is
    // written, so the seeding below cannot read its own work.
    $legacy = Database::fetchColumn(
        'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :k',
        ['k' => 'analytics_trusted_proxy']
    );

    foreach (ANALYTICS_GEO_SETTINGS as $key => [$value, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $type, $legacy): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }

            // An owner already on Cloudflare keeps their location data.
            if ($key === 'analytics_geo_source' && (string) $legacy === 'cloudflare') {
                $value = 'cloudflare';
            }

            setting_save($key, $value, ANALYTICS_GEO_GROUP, $type);

            return '+ setting ' . $key . ' = ' . $value;
        });
    }

    if ($legacy !== null) {
        $applied[] = '= analytics_trusted_proxy is still stored (' . (string) $legacy
                   . ') and is no longer read once the key above exists';
    }

    $run('+ ' . AN_GEO_DIR . ' folder', static function (): ?string {
        $dir = an_geo_directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('could not create ' . $dir);
        }

        $lines = [];

        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            if (file_put_contents($htaccess, ANALYTICS_GEO_HTACCESS . "\n") === false) {
                throw new RuntimeException('could not write ' . $htaccess);
            }
            $lines[] = '.htaccess';
        }

        $readme = $dir . '/README.txt';
        if (!is_file($readme)) {
            $text = "Put a MaxMind GeoLite2 database in this folder to record where visits\n"
                  . "come from. The analytics collector looks for these names, in this order:\n\n"
                  . '    ' . implode("\n    ", AN_GEO_FILES) . "\n\n"
                  . "It also needs the maxmind-db reader, unpacked so that this file exists:\n\n"
                  . '    ' . ROOT_PATH . AN_GEO_READER_DIRS[0] . "/MaxMind/Db/Reader.php\n\n"
                  . "Then set Settings > Analytics > Visitor location to MaxMind GeoLite2.\n"
                  . "That screen reports whether the file is found, readable and current.\n\n"
                  . "Nothing in this folder is served over HTTP (see .htaccess), and no\n"
                  . "visitor address is ever written to it: the lookup happens in memory and\n"
                  . "only the country - and the city, if you ask for it - is stored.\n\n"
                  . "GeoLite2 is created by MaxMind, available from https://www.maxmind.com.\n";
            if (file_put_contents($readme, $text) === false) {
                throw new RuntimeException('could not write ' . $readme);
            }
            $lines[] = 'README.txt';
        }

        if (!is_dir($dir)) {
            return null;
        }

        return $lines === []
            ? null
            : '+ ' . $dir . ' (' . implode(', ', $lines) . ')';
    });

    $run('= settings cache', static function (): ?string {
        settings_cache_generation(true);
        if (function_exists('cache_bust')) {
            cache_bust();
        }
        return null;
    });

    // No new permission. Reading this screen needs settings.view and changing it
    // needs settings.edit, and both already exist.

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/_cli.php';
    migration_refuse_arguments($argv);

    $result = migration_analytics_geo_run();
    echo "Location analytics migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  (nothing to do - already applied)\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
