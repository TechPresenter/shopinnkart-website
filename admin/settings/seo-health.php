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

// Technical checks. Each one is answered by looking, not by assuming.
$sitemapUrls = 0;
$sitemapOk   = false;
try {
    $xml = @file_get_contents(SITE_URL . '/sitemap.xml');
    $sitemapUrls = $xml ? substr_count($xml, '<url>') : 0;
    $sitemapOk   = $sitemapUrls > 0;
} catch (Throwable $e) {
    $sitemapOk = false;
}

$robotsOk = false;
try {
    $robotsBody = @file_get_contents(SITE_URL . '/robots.txt');
    $robotsOk = is_string($robotsBody) && str_contains($robotsBody, 'Sitemap:');
} catch (Throwable $e) {
    $robotsOk = false;
}

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
        $sitemapOk ? (string) (int) $sitemapUrls : "\u{2014}",
        'globe',
        $sitemapOk ? 'blue' : 'red',
        $sitemapOk ? 'URLs listed' : 'not reachable'
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
                <li class="<?= $sitemapOk ? 'is-ok' : 'is-bad' ?>">
                    sitemap.xml <?= $sitemapOk ? 'reachable, ' . (int) $sitemapUrls . ' URLs' : 'not reachable' ?>
                </li>
                <li class="<?= $robotsOk ? 'is-ok' : 'is-bad' ?>">
                    robots.txt <?= $robotsOk ? 'served and names the sitemap' : 'missing its Sitemap line' ?>
                </li>
                <li class="<?= $redirects['looping'] === 0 ? 'is-ok' : 'is-bad' ?>">
                    <?= $redirects['looping'] === 0
                        ? 'No redirect points at itself'
                        : (int) $redirects['looping'] . ' redirect(s) point at themselves' ?>
                </li>
                <li class="<?= $imagesNoAlt === 0 ? 'is-ok' : 'is-todo' ?>">
                    <?= $imagesNoAlt === 0
                        ? 'Every product image has alt text'
                        : (int) $imagesNoAlt . ' product image(s) have no alt text' ?>
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
                        <li><?= e(str_limit((string) $dupe['t'], 70)) ?> &mdash; <?= (int) $dupe['n'] ?> products</li>
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
