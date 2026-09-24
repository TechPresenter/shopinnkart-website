<?php
/**
 * ShopInnKart Admin - SEO health.
 *
 * A report, not a score out of 100. Every row here is a count of records that
 * are genuinely missing something a search engine reads, with a link to the
 * list that fixes them. Nothing is weighted or invented: an "SEO score" that
 * nobody can trace back to a record is worse than no score, because it invites
 * work on whatever moves the number rather than whatever helps.
 *
 * It lives beside the other Settings > SEO pages and reuses their layout.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once INCLUDES_PATH . '/content-functions.php';
require_once INCLUDES_PATH . '/sitemap-functions.php';

/**
 * How many rows in $table fail $condition, and how many there are in total.
 *
 * @return array{missing:int,total:int}
 */
function seo_health_count(string $table, string $condition, string $scope = '1'): array
{
    try {
        return [
            'missing' => (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM `$table` WHERE ($scope) AND ($condition)"
            ),
            'total' => (int) Database::fetchColumn("SELECT COUNT(*) FROM `$table` WHERE ($scope)"),
        ];
    } catch (Throwable $e) {
        return ['missing' => 0, 'total' => 0];
    }
}

/** Only published records matter: a draft is not in the index to begin with. */
$published = "`status` = 'active'";

$blank = static fn (string $col): string => "(`$col` IS NULL OR TRIM(`$col`) = '')";

$entities = [
    'Products'   => ['table' => 'products',    'scope' => $published, 'url' => 'products/'],
    'Categories' => ['table' => 'categories',  'scope' => $published, 'url' => 'categories/'],
    'Brands'     => ['table' => 'brands',      'scope' => $published, 'url' => 'brands/'],
    'Blog posts' => ['table' => 'blog_posts',  'scope' => "`status` = 'published'", 'url' => 'blog/'],
    'CMS pages'  => ['table' => 'pages',       'scope' => $published, 'url' => 'pages/'],
];

$rows = [];
foreach ($entities as $label => $meta) {
    $title = seo_health_count($meta['table'], $blank('meta_title'), $meta['scope']);
    $desc  = seo_health_count($meta['table'], $blank('meta_description'), $meta['scope']);
    $rows[$label] = [
        'url'            => $meta['url'],
        'total'          => $title['total'],
        'no_title'       => $title['missing'],
        'no_description' => $desc['missing'],
        'noindex'        => seo_health_count($meta['table'], "`robots` LIKE '%noindex%'", $meta['scope'])['missing'],
    ];
}

// Images without alt text. product_images is the only table that stores one.
$imagesTotal = (int) Database::fetchColumn('SELECT COUNT(*) FROM `product_images`');
$imagesNoAlt = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `product_images` WHERE `alt_text` IS NULL OR TRIM(`alt_text`) = ''"
);

// Duplicate meta titles are the single most common real problem: two pages
// competing for the same query, usually because a template filled both in.
$dupeTitles = Database::fetchAll(
    "SELECT `meta_title` AS t, COUNT(*) AS n FROM `products`
     WHERE `meta_title` IS NOT NULL AND TRIM(`meta_title`) <> '' AND `status` = 'active'
     GROUP BY `meta_title` HAVING n > 1 ORDER BY n DESC LIMIT 10"
);

$redirects = [
    'total'    => (int) Database::fetchColumn('SELECT COUNT(*) FROM `redirects`'),
    'active'   => (int) Database::fetchColumn("SELECT COUNT(*) FROM `redirects` WHERE `status` = 'active'"),
    'unused'   => (int) Database::fetchColumn('SELECT COUNT(*) FROM `redirects` WHERE `hits` = 0'),
    'looping'  => (int) Database::fetchColumn('SELECT COUNT(*) FROM `redirects` WHERE `source_path` = `target_path`'),
];

/**
 * Fetch one of the store's OWN addresses, briefly, and say whether it answered.
 *
 * This page used to call `@file_get_contents(SITE_URL . '/sitemap.xml')` with
 * no stream context. Without one, PHP waits `default_socket_timeout` - 60
 * seconds on a stock install - for the first byte, and it does it twice, once
 * per URL. Worse, SITE_URL is *this* server: on any single-worker setup (the
 * PHP built-in server, php-fpm with one child) the request cannot be served
 * while the worker is still rendering the page that asks for it, so the two
 * fetches always time out and the page answered in 121s with an HTTP 500.
 *
 * A short timeout is the fix, not a longer one. These are local addresses; a
 * store that cannot answer its own robots.txt in half a second has a problem
 * this page should REPORT, not sit and wait for.
 *
 * `reached` is the fact that matters and is kept separate from `status`:
 * "we could not look" and "we looked and it is wrong" are different answers
 * and the checklist prints them differently.
 *
 * Deliberately still a whole function rather than a call into
 * includes/sitemap-functions.php: the regression suites lift this function out
 * of the shipped source and run it on its own, so that the guarantee is tested
 * against what is served rather than against a copy.
 *
 * @return array{reached:bool,status:int,body:string}
 */
function seo_health_fetch(string $url, float $timeout): array
{
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,   // connect AND read, both
            'follow_location' => 1,
            'max_redirects'   => 3,
            // A 404 body is still an answer: without this, file_get_contents
            // returns false on any 4xx/5xx and we could not tell it apart
            // from silence.
            'ignore_errors'   => true,
            'header'          => "User-Agent: ShopInnKart SEO health check\r\nConnection: close\r\n",
        ],
    ]);

    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) {
        return ['reached' => false, 'status' => 0, 'body' => ''];
    }

    $status = 0;
    foreach ($http_response_header as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
            $status = (int) $m[1];   // last one wins, so a redirect chain reports where it landed
        }
    }

    return ['reached' => true, 'status' => $status, 'body' => $body];
}

// Technical checks. Each one is answered by looking, not by assuming - but
// never by waiting. Half a second each, and if the FIRST one gets no answer at
// all the second is not attempted: the store's own address is not responding,
// so the only thing a second half-second buys is a slower page saying the same
// thing. Worst case for the whole section is therefore ONE timeout, which
// measures 0.63s for the whole page against 120.16s and an HTTP 500 before.
const SEO_HEALTH_FETCH_TIMEOUT = 0.5;

$sitemap = seo_health_fetch(SITE_URL . '/sitemap.xml', SEO_HEALTH_FETCH_TIMEOUT);
$sitemapUrls = $sitemap['reached'] ? substr_count($sitemap['body'], '<url>') : 0;
$sitemapOk   = $sitemap['reached'] && $sitemap['status'] < 400 && $sitemapUrls > 0;

$robots = $sitemap['reached']
    ? seo_health_fetch(SITE_URL . '/robots.txt', SEO_HEALTH_FETCH_TIMEOUT)
    : ['reached' => false, 'status' => 0, 'body' => ''];
$robotsOk = $robots['reached'] && $robots['status'] < 400 && str_contains($robots['body'], 'Sitemap:');

// True only when the address answered. Used to say "could not fetch" instead
// of accusing the store of a missing sitemap we never actually looked for.
$sitemapReached = $sitemap['reached'];
$robotsReached  = $robots['reached'];

// What the fetch above cannot answer when it gets no answer at all. The index
// is built here, in process, so "does it parse" and "how many URLs does it
// lead to" are known even on a single-worker server that cannot serve itself.
//
// Counted, not built. Each section's total is one indexed COUNT, where
// building every section file would load every product, page and image row -
// which measured 3.5s on this page and is not what a summary is for. Admin >
// SEO > Sitemap builds and parses each file, because that is its job.
$builtUrls  = 0;
$builtFiles = 0;
foreach (array_keys(sitemap_sections()) as $sitemapSection) {
    if (!sitemap_section_enabled($sitemapSection)) {
        continue;
    }
    $builtUrls  += sitemap_section_count($sitemapSection);
    $builtFiles += sitemap_section_pages($sitemapSection);
}

$indexFact       = sitemap_health_parse('index', sitemap_index_url(), (string) sitemap_index_xml(), 'sitemap');
$sitemapProblems = $indexFact['problems'];
$builtOk         = $builtUrls > 0 && $indexFact['parses'] && $sitemapProblems === [];
$robotsAllowsSitemap = robots_allows(sitemap_index_url());

$integrations = [
    'Google Analytics'      => trim((string) setting('google_analytics_id', '')),
    'Google Tag Manager'    => trim((string) setting('google_tag_manager_id', '')),
    'Google verification'   => trim((string) setting('google_site_verification', '')),
    'Bing verification'     => trim((string) setting('bing_site_verification', '')),
    'Meta Pixel'            => trim((string) setting('meta_pixel_id', '')),
];

$totalMissing = 0;
foreach ($rows as $row) {
    $totalMissing += $row['no_title'] + $row['no_description'];
}

$pageTitle = 'SEO health';
// The screens this report links into. They are not in the settings tab strip -
// they are their own section under /admin/seo - so the way in is here, beside
// the numbers that send an operator to them. This page counts the problems;
// those pages are where they get fixed.
$pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('seo/')) . '">'
    . icon('list', 'w-4 h-4') . ' SEO workbench</a>'
    . '<a class="ad-btn" href="' . e(admin_url('seo/sitemap.php')) . '">'
    . icon('globe', 'w-4 h-4') . ' Sitemap &amp; robots</a>'
    . '<a class="ad-btn" href="' . e(admin_url('seo/schema.php')) . '">'
    . icon('code', 'w-4 h-4') . ' Schema</a>';
require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('seo-health') ?>

<?php
/* The tiles are the shared helper's, not hand-built ones.
   -------------------------------------------------------------------------
   They used to be three bare spans - label, value and a `.ad-stat__hint` that
   is not a class admin.css defines - dropped straight into `.ad-stat`, which
   is a wrap-flex. So the three sat SIDE BY SIDE and then wrapped in whatever
   order the width allowed, and the hint line was unstyled body text. The
   helper emits the icon + `.ad-stat__body` pairing the tile's container
   queries are written against, which is what sizes the numeral to the tile
   rather than to the window. */
$tiles = [
    admin_stat_card(
        'Records missing meta',
        (string) (int) $totalMissing,
        'search',
        $totalMissing > 0 ? 'amber' : 'green',
        'title or description blank'
    ),
    admin_stat_card(
        'Images without alt text',
        (string) (int) $imagesNoAlt,
        'camera',
        $imagesNoAlt > 0 ? 'amber' : 'green',
        'of ' . (int) $imagesTotal . ' product images'
    ),
    admin_stat_card(
        'Sitemap',
        // The BUILT count, not the fetched one: it is the same number when the
        // store can answer itself and the true one when it cannot.
        $builtUrls > 0 ? number_format($builtUrls) : "\u{2014}",
        'globe',
        // navy, not red, when we never got an answer: a red tile is a verdict
        // on the store, and "we could not look" is not a verdict.
        $builtOk ? 'blue' : (!$sitemapReached ? 'navy' : 'red'),
        $builtUrls > 0
            ? 'URLs in ' . (int) $builtFiles . ' file' . ($builtFiles === 1 ? '' : 's')
            : 'lists no URLs',
        admin_url('seo/sitemap.php')
    ),
    admin_stat_card(
        'Redirects',
        (string) (int) $redirects['active'],
        'external',
        'navy',
        'active of ' . (int) $redirects['total'],
        admin_url('settings/redirects.php')
    ),
];
?>
<div class="ad-grid ad-grid--4" style="margin-bottom:20px">
    <?= implode("\n    ", $tiles) ?>
</div>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head"><h2 class="ad-card__title">Metadata coverage</h2></div>
    <?php /* .ad-tablewrap, not .ad-table-scroll: the wrapper the card view and
             the fit measurement in admin.js are keyed to. `.ad-table-scroll` -
             this page and settings/redirects were its only two users - is a
             bare `overflow-x: auto` with no `min-width: 0` and no part in
             either, so it would widen the page rather than scroll itself the
             moment such a table sat in a flex or grid track. `.ad-table__num` /
             `.ad-table__actions` are the real column classes too: `.ad-num` is
             defined in no stylesheet, so none of these figures were ever
             right-aligned. */ ?>
    <div class="ad-tablewrap">
        <table class="ad-table">
            <thead>
                <tr>
                    <th>Content type</th>
                    <th class="ad-table__num">Published</th>
                    <th class="ad-table__num">No meta title</th>
                    <th class="ad-table__num">No description</th>
                    <th class="ad-table__num">Noindex</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $label => $row): ?>
                    <tr>
                        <td><strong><?= e($label) ?></strong></td>
                        <td class="ad-table__num"><?= (int) $row['total'] ?></td>
                        <td class="ad-table__num"><?= (int) $row['no_title'] ?></td>
                        <td class="ad-table__num"><?= (int) $row['no_description'] ?></td>
                        <td class="ad-table__num"><?= (int) $row['noindex'] ?></td>
                        <td class="ad-table__actions">
                            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url($row['url'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="ad-card__foot">
        <p class="sik-help" style="margin:0">
            A blank meta title or description is not an error: the storefront falls back to the record
            name and summary. It only means a search engine is choosing your wording instead of you.
        </p>
    </div>
</div>

<div class="ad-grid ad-grid--2" style="gap:20px;margin-bottom:20px">
    <div class="ad-card">
        <div class="ad-card__head"><h2 class="ad-card__title">Technical checks</h2></div>
        <div class="ad-card__body">
            <ul class="ad-checklist">
                <?php /* Three states, not two. A check we could not RUN is
                         reported as unknown rather than as a failure: telling
                         an admin their sitemap is missing when we simply never
                         got an answer sends them hunting for a bug that is not
                         there. `is-unknown` is the "?" marker in admin.css. */ ?>
                <li class="<?= !$sitemapReached ? 'is-unknown' : ($sitemapOk ? 'is-ok' : 'is-bad') ?>">
                    <?php if (!$sitemapReached): ?>
                        sitemap.xml &mdash; could not fetch
                        <span class="ad-muted">(<?= e(SITE_URL) ?>/sitemap.xml did not answer within
                        <?= e(rtrim(rtrim(number_format(SEO_HEALTH_FETCH_TIMEOUT, 2), '0'), '.')) ?>s)</span>
                    <?php else: ?>
                        sitemap.xml <?= $sitemapOk ? 'reachable, ' . (int) $sitemapUrls . ' URLs' : 'reachable but lists no URLs' ?>
                    <?php endif; ?>
                </li>
                <?php /* Built here, so this line is answered whether or not the
                         fetch above got anything. */ ?>
                <li class="<?= $builtOk ? 'is-ok' : ($builtUrls > 0 ? 'is-todo' : 'is-bad') ?>">
                    sitemap.xml builds and parses &mdash;
                    <?= (int) $builtUrls ?> URLs in <?= (int) $builtFiles ?> file<?= $builtFiles === 1 ? '' : 's' ?>
                    <?php if ($sitemapProblems !== []): ?>
                        <span class="ad-muted">(<?= e($sitemapProblems[0]) ?><?= count($sitemapProblems) > 1
                            ? ', and ' . (count($sitemapProblems) - 1) . ' more' : '' ?>)</span>
                    <?php endif; ?>
                </li>
                <li class="<?= !$robotsReached ? 'is-unknown' : ($robotsOk ? 'is-ok' : 'is-bad') ?>">
                    <?php if (!$robotsReached): ?>
                        robots.txt &mdash; could not fetch
                        <span class="ad-muted"><?= $sitemapReached
                            ? '(' . e(SITE_URL) . '/robots.txt did not answer in time)'
                            : '(not attempted: the store&rsquo;s own address is not answering)' ?></span>
                    <?php else: ?>
                        robots.txt <?= $robotsOk ? 'served and names the sitemap' : 'missing its Sitemap line' ?>
                    <?php endif; ?>
                </li>
                <li class="<?= $robotsAllowsSitemap ? 'is-ok' : 'is-bad' ?>">
                    <?= $robotsAllowsSitemap
                        ? 'The crawl rules allow the sitemap to be read'
                        : 'A robots.txt rule blocks the sitemap, so no crawler will read it' ?>
                </li>
                <li class="<?= $redirects['looping'] === 0 ? 'is-ok' : 'is-bad' ?>">
                    <?= $redirects['looping'] === 0
                        ? 'No redirect points at itself'
                        : (int) $redirects['looping'] . ' redirect(s) point at themselves' ?>
                </li>
                <li class="<?= $imagesNoAlt === 0 ? 'is-ok' : 'is-todo' ?>">
                    <?php // A count with nowhere to go is a complaint; the desk is where it gets fixed. ?>
                    <?= $imagesNoAlt === 0
                        ? 'Every product image has alt text'
                        : (int) $imagesNoAlt . ' product image(s) have no alt text' ?>
                    <?php if ($imagesNoAlt > 0): ?>
                        &mdash; <a href="<?= e(admin_url('seo/images.php')) ?>">describe them</a>
                    <?php endif; ?>
                </li>
                <li class="<?= $dupeTitles === [] ? 'is-ok' : 'is-todo' ?>">
                    <?= $dupeTitles === []
                        ? 'No duplicate product meta titles'
                        : count($dupeTitles) . ' meta title(s) used by more than one product' ?>
                </li>
            </ul>

            <?php if ($dupeTitles !== []): ?>
                <ul class="sik-help" style="margin-top:12px">
                    <?php foreach ($dupeTitles as $dupe): ?>
                        <?php /* admin_trunc(): a duplicated meta title is the thing you have to
                                 go and change, so the part that was cut off is exactly the part
                                 you need. The full string is the title attribute. */ ?>
                        <li><?= admin_trunc((string) $dupe['t'], 70) ?> &mdash; <?= (int) $dupe['n'] ?> products</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-card__head"><h2 class="ad-card__title">Search engine integrations</h2></div>
        <div class="ad-card__body">
            <ul class="ad-checklist">
                <?php foreach ($integrations as $label => $value): ?>
                    <li class="<?= $value !== '' ? 'is-ok' : 'is-todo' ?>">
                        <?= e($label) ?>: <?= $value !== '' ? 'configured' : 'not configured' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="sik-help" style="margin-top:12px">
                Each of these is a credential from your own account. Nothing is filled in for you, and an
                unconfigured integration simply renders no tag.
            </p>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('settings/seo.php')) ?>" style="margin-top:8px">
                Configure
            </a>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
