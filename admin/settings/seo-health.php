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

<div class="ad-grid ad-grid--4" style="margin-bottom:20px">
    <div class="ad-stat">
        <span class="ad-stat__label">Records missing meta</span>
        <strong class="ad-stat__value"><?= (int) $totalMissing ?></strong>
        <span class="ad-stat__hint">title or description blank</span>
    </div>
    <div class="ad-stat">
        <span class="ad-stat__label">Images without alt text</span>
        <strong class="ad-stat__value"><?= (int) $imagesNoAlt ?></strong>
        <span class="ad-stat__hint">of <?= (int) $imagesTotal ?> product images</span>
    </div>
    <div class="ad-stat">
        <span class="ad-stat__label">Sitemap</span>
        <strong class="ad-stat__value"><?= $sitemapOk ? (int) $sitemapUrls : '—' ?></strong>
        <span class="ad-stat__hint"><?= $sitemapOk ? 'URLs listed' : 'not reachable' ?></span>
    </div>
    <div class="ad-stat">
        <span class="ad-stat__label">Redirects</span>
        <strong class="ad-stat__value"><?= (int) $redirects['active'] ?></strong>
        <span class="ad-stat__hint">active of <?= (int) $redirects['total'] ?></span>
    </div>
</div>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head"><h2 class="ad-card__title">Metadata coverage</h2></div>
    <div class="ad-table-scroll">
        <table class="ad-table">
            <thead>
                <tr>
                    <th>Content type</th>
                    <th class="ad-num">Published</th>
                    <th class="ad-num">No meta title</th>
                    <th class="ad-num">No description</th>
                    <th class="ad-num">Noindex</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $label => $row): ?>
                    <tr>
                        <td><strong><?= e($label) ?></strong></td>
                        <td class="ad-num"><?= (int) $row['total'] ?></td>
                        <td class="ad-num">
                            <?= (int) $row['no_title'] ?>
                        </td>
                        <td class="ad-num<?= $row['no_description'] > 0 ? '' : '' ?>">
                            <?= (int) $row['no_description'] ?>
                        </td>
                        <td class="ad-num"><?= (int) $row['noindex'] ?></td>
                        <td class="ad-num">
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
