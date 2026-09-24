<?php
/**
 * ShopInnKart - build the sitemaps ahead of time.
 *
 * The sitemaps are generated on demand and cached, which is right for a
 * catalogue of this size: the first crawler of the hour pays for the build and
 * everybody else reads the cache. On a large catalogue that first request is
 * the slow one, and a crawler is exactly who should not be made to wait - so
 * this job builds every file and leaves it in the cache, from cron:
 *
 *     15 3 * * *  /usr/bin/php /home/user/public_html/bin/sitemap-build.php
 *
 * With --write=DIR it also writes each file to disk, for an operator who would
 * rather serve static XML than run PHP for a crawler. Nothing else reads those
 * files; they are a deployment convenience, not a second source of truth.
 *
 *     php bin/sitemap-build.php [--write=DIR] [--quiet]
 *
 * Exits 0 when every file built and parsed, 1 when any did not.
 *
 * It never fetches anything over HTTP. In CLI there is no Host header, so
 * SITE_URL falls back to the configured canonical address - which on a
 * developer's machine is the PRODUCTION domain. A job that "checked" the
 * sitemap from here would be checking the live site from a laptop.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';
require_once INCLUDES_PATH . '/sitemap-functions.php';

$options = getopt('', ['write::', 'quiet']);
$quiet   = array_key_exists('quiet', $options);
$writeTo = isset($options['write']) && is_string($options['write']) && $options['write'] !== ''
    ? rtrim($options['write'], '/\\')
    : '';

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

if ($writeTo !== '' && !is_dir($writeTo) && !@mkdir($writeTo, 0775, true) && !is_dir($writeTo)) {
    fwrite(STDERR, "Cannot create {$writeTo}\n");
    exit(1);
}

// A stale cache would be handed straight back instead of rebuilt.
Cache::flush();

$started = microtime(true);
$failed  = 0;
$urls    = 0;

/** Build one file, report it, and optionally drop it on disk. */
$build = static function (string $name, ?string $xml, string $childTag)
    use ($say, $writeTo, &$failed, &$urls): void {

    if ($xml === null || $xml === '') {
        $say(sprintf('  %-28s %s', $name, 'EMPTY (section off or nothing to list)'));
        return;
    }

    $fact = sitemap_health_parse($name, $name, $xml, $childTag);
    $urls += (int) $fact['urls'];

    if (!$fact['parses'] || $fact['problems'] !== []) {
        $failed++;
    }

    $say(sprintf(
        '  %-28s %6d %s  %7.1f KB  %s',
        $name,
        (int) $fact['urls'],
        $childTag === 'sitemap' ? 'files' : 'URLs ',
        $fact['bytes'] / 1024,
        $fact['problems'] === [] ? 'ok' : implode(' | ', $fact['problems'])
    ));

    if ($writeTo !== '') {
        $file = $writeTo . '/' . $name;
        if (@file_put_contents($file, $xml) === false) {
            $failed++;
            $say('      could not write ' . $file);
        }
    }
};

$say('Sitemap build (' . date('Y-m-d H:i:s') . ')');
$say('  base ' . SITE_URL);

$build('sitemap.xml', sitemap_cached('index'), 'sitemap');

foreach (array_keys(sitemap_sections()) as $key) {
    if (!sitemap_section_enabled($key)) {
        $say(sprintf('  %-28s %s', 'sitemap-' . $key . '.xml', 'switched off'));
        continue;
    }

    $pages = sitemap_section_pages($key);
    for ($page = 1; $page <= $pages; $page++) {
        $build('sitemap-' . $key . '-' . $page . '.xml', sitemap_cached($key, $page), 'url');
    }
}

if ($writeTo !== '') {
    @file_put_contents($writeTo . '/robots.txt', robots_txt_body());
    $say('  robots.txt written');
}

$say(sprintf('  %d URLs in %.2fs%s', $urls, microtime(true) - $started,
    $failed > 0 ? ' - ' . $failed . ' file(s) with problems' : ''));

exit($failed > 0 ? 1 : 0);
