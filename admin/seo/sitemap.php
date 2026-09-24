<?php
/**
 * ShopInnKart Admin - Sitemap, robots.txt and search engines.
 *
 * A report and a control panel in one. Everything it says about the sitemap is
 * measured, not assumed: each section file is BUILT here, in process, and
 * parsed, so "does it parse", "how many URLs", "is every URL absolute" and
 * "does robots.txt allow this file" are facts even on a server that cannot
 * fetch its own address.
 *
 * The network half - do those URLs actually answer 200, and is each one the
 * canonical the page claims - runs only when the operator asks for it, and is
 * bounded twice (per-fetch timeout and an overall budget). That is deliberate:
 * this page's predecessor hung for 121 seconds and died with a 500 because it
 * fetched its own sitemap with no timeout on a single-worker server.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once INCLUDES_PATH . '/content-functions.php';
require_once INCLUDES_PATH . '/sitemap-functions.php';

$spec = [
    'sitemap_include_products' => [
        'type' => 'bool', 'label' => 'List products', 'group' => 'seo',
    ],
    'sitemap_include_categories' => [
        'type' => 'bool', 'label' => 'List categories', 'group' => 'seo',
    ],
    'sitemap_include_brands' => [
        'type' => 'bool', 'label' => 'List brands', 'group' => 'seo',
    ],
    'sitemap_include_pages' => [
        'type' => 'bool', 'label' => 'List the homepage and CMS pages', 'group' => 'seo',
    ],
    'sitemap_include_blog' => [
        'type' => 'bool', 'label' => 'List blog posts', 'group' => 'seo',
    ],
    'sitemap_include_images' => [
        'type' => 'bool', 'label' => 'List product images', 'group' => 'seo',
        'help' => 'Tells Google which pictures belong to which product page.',
    ],
    // Off by default on purpose: the product page is still the right answer to a
    // search for that product, and dropping it from the sitemap loses the ranking
    // the moment stock runs out - which is the worst possible moment to lose it.
    'sitemap_exclude_oos' => [
        'type' => 'bool', 'label' => 'Leave out products that are out of stock', 'group' => 'seo',
        'help' => 'Off by default: removing a page loses its ranking.',
    ],
    'sitemap_urls_per_file' => [
        'type' => 'number', 'label' => 'URLs per file', 'group' => 'seo',
        'min_value' => 100, 'max_value' => SITEMAP_MAX_URLS,
        'help' => 'The format allows ' . number_format(SITEMAP_MAX_URLS) . ' at most.',
    ],
    'sitemap_cache_ttl' => [
        'type' => 'number', 'label' => 'Cache the built XML for (seconds)', 'group' => 'seo',
        'min_value' => 0, 'max_value' => 86400,
        'help' => '0 rebuilds every request. Any admin save clears it.',
    ],
];

$remote  = null;          // filled in only when the operator asks for a check
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require_action('settings.edit');       // POST + CSRF + permission

    $action = (string) input('action', '');

    if ($action === 'save') {
        settings_handle_save('seo', 'seo', $spec, settings_group('seo'), [
            'redirect' => admin_url('seo/sitemap.php'),
            'label'    => 'Sitemap',
        ]);                                       // never returns
    }

    if ($action === 'rebuild') {
        // The sitemap cache lives in the same store as every other storefront
        // cache, and there is no per-prefix clear, so this is the whole flush -
        // which is what admin_after_write() does after any content change too.
        admin_after_write();
        log_activity('seo.sitemap_rebuilt', 'settings', null, 'Cleared the sitemap cache');
        flash('success', 'Sitemap cache cleared. The next request rebuilds it.');
        redirect(admin_url('seo/sitemap.php'));
    }

    if ($action === 'submitted') {
        setting_save('sitemap_submitted_at', date('Y-m-d'), 'seo', 'text');
        admin_after_write();
        flash('success', 'Noted as submitted today.');
        redirect(admin_url('seo/sitemap.php'));
    }

    if ($action === 'verify_write') {
        $name   = (string) input('gsc_verification_file', '');
        $result = gsc_verification_write($name);

        if ($result['ok']) {
            setting_save('gsc_verification_file', strtolower(trim($name)), 'seo', 'text');
            security_event('seo.verification_file_written', 'medium',
                ['file' => strtolower(trim($name))], (int) $admin['id'], 'admin');
            admin_after_write();
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        redirect(admin_url('seo/sitemap.php'));
    }

    if ($action === 'verify_delete') {
        $current = (string) setting('gsc_verification_file', '');
        gsc_verification_delete($current);
        setting_save('gsc_verification_file', '', 'seo', 'text');
        security_event('seo.verification_file_removed', 'medium',
            ['file' => $current], (int) $admin['id'], 'admin');
        admin_after_write();
        flash('success', 'Verification file removed.');
        redirect(admin_url('seo/sitemap.php'));
    }

    if ($action === 'check') {
        // A sample, not the whole sitemap: one URL per section plus the index
        // and robots.txt. Checking 50,000 URLs from a web request is not a
        // health check, it is an outage.
        $targets = [sitemap_index_url(), robots_txt_url()];
        foreach (sitemap_health_local() as $fact) {
            foreach (array_slice((array) $fact['sample'], 0, 1) as $sample) {
                $targets[] = $sample;
            }
        }
        $remote = sitemap_health_remote(array_values(array_unique($targets)));
    }
}

$stored = settings_group('seo');
$values = settings_values($spec, $stored);
$errors = errors_pull();

// The measured half. Built in process, so it costs queries but never a socket.
$facts = sitemap_health_local();

$totalUrls = 0;
$totalFiles = 0;
$problemCount = 0;
foreach ($facts as $fact) {
    if ($fact['label'] === 'index') {
        continue;                      // the index counts files, not pages
    }
    $totalUrls += (int) $fact['urls'];
    $totalFiles++;
    $problemCount += count((array) $fact['problems']);
}

$verification = gsc_verification_status();
$robotsBody   = robots_txt_body();
$robotsAllowsSitemap = robots_allows(sitemap_index_url());

// The "is this URL crawlable?" box. Answered from the same rules robots.txt is
// printed from, so the answer cannot drift from the file.
$testPath   = trim((string) ($_GET['test'] ?? ''));
$testResult = $testPath === '' ? null : robots_allows($testPath);

$submittedAt = trim((string) setting('sitemap_submitted_at', ''));

$pageTitle    = 'Sitemap &amp; robots';
$pageSubtitle = number_format($totalUrls) . ' URLs in ' . $totalFiles . ' file' . ($totalFiles === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'SEO', 'url' => admin_url('settings/seo.php')],
    ['label' => 'Sitemap'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('seo/schema.php')) . '">'
    . icon('code', 'w-4 h-4') . ' Schema</a>'
    . '<a class="ad-btn" href="' . e(sitemap_index_url()) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' Open sitemap.xml</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:20px">
    <?= admin_stat_card('URLs listed', number_format($totalUrls), 'globe', $totalUrls > 0 ? 'blue' : 'amber',
        'across ' . $totalFiles . ' sitemap file' . ($totalFiles === 1 ? '' : 's')) ?>
    <?= admin_stat_card('Problems found', (string) $problemCount, 'alert', $problemCount === 0 ? 'green' : 'amber',
        $problemCount === 0 ? 'every file parses' : 'listed below') ?>
    <?= admin_stat_card('robots.txt', $robotsAllowsSitemap ? 'Allows' : 'Blocks', 'shield',
        $robotsAllowsSitemap ? 'green' : 'red',
        $robotsAllowsSitemap ? 'crawlers may read the sitemap' : 'a rule is blocking the sitemap') ?>
    <?= admin_stat_card('Submitted', $submittedAt !== '' ? e($submittedAt) : "\u{2014}", 'send',
        $submittedAt !== '' ? 'navy' : 'amber',
        $submittedAt !== '' ? 'noted by you' : 'not noted yet') ?>
</div>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head">
        <h2 class="ad-card__title">Sitemap files</h2>
        <?php if (admin_can('settings.edit')): ?>
            <form method="post" style="margin-left:auto">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rebuild">
                <button type="submit" class="ad-btn ad-btn--sm"><?= icon('refresh', 'w-4 h-4') ?> Rebuild now</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="ad-tablewrap">
        <table class="ad-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th class="ad-table__num">URLs</th>
                    <th class="ad-table__num">Size</th>
                    <th>Parses</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facts as $fact): ?>
                    <tr>
                        <td>
                            <strong><?= e((string) $fact['label']) ?></strong>
                            <div class="sik-help" style="word-break:break-all"><?= e((string) $fact['url']) ?></div>
                        </td>
                        <td class="ad-table__num"><?= number_format((int) $fact['urls']) ?></td>
                        <td class="ad-table__num"><?= e(number_format($fact['bytes'] / 1024, 1)) ?> KB</td>
                        <td>
                            <?= $fact['parses']
                                ? '<span class="sik-status sik-status--green">yes</span>'
                                : '<span class="sik-status sik-status--red">no</span>' ?>
                        </td>
                        <td>
                            <?php if ($fact['problems'] === []): ?>
                                <span class="ad-muted">&mdash;</span>
                            <?php else: ?>
                                <ul class="sik-error" style="margin:0;padding-left:18px">
                                    <?php foreach ($fact['problems'] as $problem): ?>
                                        <li><?= e($problem) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="ad-table__actions">
                            <a class="ad-btn ad-btn--sm" href="<?= e((string) $fact['url']) ?>"
                               target="_blank" rel="noopener">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ad-card__body">
        <p class="sik-help" style="margin:0 0 8px">
            Anything set to <code>noindex</code>, inactive or unpublished is left out.
        </p>
        <details>
            <summary>Why those pages are missing</summary>
            <p>
                A sitemap is the list of pages you want indexed. Listing one you have also told crawlers
                to skip is a contradiction, and Search Console reports it as an error against the file
                rather than ignoring it.
            </p>
        </details>
    </div>
</div>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head">
        <h2 class="ad-card__title">Do those URLs answer?</h2>
        <?php if (admin_can('settings.edit')): ?>
            <form method="post" style="margin-left:auto">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="check">
                <button type="submit" class="ad-btn ad-btn--sm"><?= icon('activity', 'w-4 h-4') ?> Check now</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="ad-card__body">
        <?php if ($remote === null): ?>
            <p class="sik-help" style="margin:0 0 8px">
                Not run &mdash; the only part of this page that uses the network.
            </p>
            <details>
                <summary>What it checks, and when it cannot</summary>
                <p>
                    It asks the store for the index, robots.txt and one URL from each section: half a
                    second per answer, <?= e((string) (int) SITEMAP_HEALTH_BUDGET) ?> seconds for the lot.
                    On a single-worker server &mdash; the local PHP one, or php-fpm with one child &mdash;
                    the store cannot answer itself while it is rendering this page, and the check reports
                    that it got no answer rather than pretending otherwise.
                </p>
            </details>
        <?php else: ?>
            <?php if ($remote['checked'] === []): ?>
                <p class="sik-error" style="margin:0">Nothing was checked.</p>
            <?php endif; ?>
            <ul class="ad-checklist">
                <?php foreach ($remote['checked'] as $row): ?>
                    <?php
                    $ok = $row['reached'] && $row['status'] >= 200 && $row['status'] < 400
                        && $row['canonical_ok'] && $row['allowed'];
                    $state = !$row['reached'] ? 'is-unknown' : ($ok ? 'is-ok' : 'is-bad');
                    ?>
                    <li class="<?= $state ?>">
                        <span style="word-break:break-all"><?= e((string) $row['url']) ?></span>
                        <?php if (!$row['reached']): ?>
                            <span class="ad-muted">&mdash; no answer within
                                <?= e((string) $row['seconds']) ?>s</span>
                        <?php else: ?>
                            <span class="ad-muted">&mdash; HTTP <?= (int) $row['status'] ?>,
                                <?= e((string) $row['seconds']) ?>s</span>
                            <?php if (!$row['allowed']): ?>
                                <span class="sik-error">robots.txt disallows it</span>
                            <?php endif; ?>
                            <?php if (!$row['canonical_ok']): ?>
                                <span class="sik-error">canonical points at
                                    <?= e((string) $row['canonical']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($remote['stopped'] !== ''): ?>
                <p class="sik-help" style="margin:8px 0 0">
                    Stopped early: <?= e((string) $remote['stopped']) ?>.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="ad-grid ad-grid--2" style="gap:20px;margin-bottom:20px">
    <form method="post" action="<?= e(admin_url('seo/sitemap.php')) ?>" class="ad-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="ad-card__head"><h2 class="ad-card__title">What goes in the sitemap</h2></div>
        <div class="ad-card__body">
            <?= settings_fields([
                'sitemap_include_products', 'sitemap_include_categories', 'sitemap_include_brands',
                'sitemap_include_pages', 'sitemap_include_blog', 'sitemap_include_images',
                'sitemap_exclude_oos', 'sitemap_urls_per_file', 'sitemap_cache_ttl',
            ], $spec, $values, $errors) ?>
        </div>
        <?= settings_save_bar('An empty section is left out of the index.') ?>
    </form>

    <div class="ad-card">
        <div class="ad-card__head"><h2 class="ad-card__title">Search engines</h2></div>
        <div class="ad-card__body">
            <?php /* No API key, and none is pretended. Google retired its old "ping" URL, so there is
                     nothing left to automate: the honest version is the address to paste and a link
                     straight to the right screen in each engine's own console. */ ?>
            <p class="sik-help" style="margin-top:0">
                No API key: you submit a sitemap once, signed in to your own account.
            </p>

            <div class="ad-field">
                <label class="sik-label" for="sm_url">Your sitemap address</label>
                <input class="sik-input ad-mono" type="text" id="sm_url" readonly
                       value="<?= e_attr(sitemap_index_url()) ?>"
                       onfocus="this.select()">
            </div>

            <ul class="ad-checklist">
                <?php foreach (sitemap_submission_links() as $engine => $link): ?>
                    <li class="is-todo">
                        <a href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer"><?= e($engine) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (admin_can('settings.edit')): ?>
                <form method="post" style="margin-top:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submitted">
                    <button type="submit" class="ad-btn ad-btn--sm">
                        <?= icon('check', 'w-4 h-4') ?> I have submitted it
                    </button>
                </form>
            <?php endif; ?>

            <hr style="margin:16px 0;border:0;border-top:1px solid var(--ad-border, #e5e7eb)">

            <h3 style="font-size:14px;margin:0 0 8px">Verifying ownership</h3>
            <ul class="ad-checklist">
                <li class="<?= $verification['meta'] !== '' ? 'is-ok' : 'is-todo' ?>">
                    Meta tag:
                    <?= $verification['meta'] !== ''
                        ? 'set, and printed in every page&rsquo;s head'
                        : 'not set' ?>
                    &mdash; <a href="<?= e(admin_url('settings/seo.php')) ?>">edit on Settings &rsaquo; SEO</a>
                </li>
                <li class="<?= $verification['present'] ? 'is-ok' : 'is-todo' ?>">
                    HTML file:
                    <?php if ($verification['present']): ?>
                        <a href="<?= e($verification['url']) ?>" target="_blank" rel="noopener">
                            <?= e($verification['file']) ?></a> is on the server
                    <?php elseif ($verification['file'] !== ''): ?>
                        <?= e($verification['file']) ?> is configured but missing from the site folder
                    <?php else: ?>
                        not used
                    <?php endif; ?>
                </li>
            </ul>

            <?php if (admin_can('settings.edit')): ?>
                <form method="post" style="margin-top:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_write">
                    <div class="ad-field">
                        <label class="sik-label" for="gsc_file">Google verification file</label>
                        <input class="sik-input" type="text" id="gsc_file" name="gsc_verification_file"
                               maxlength="60" spellcheck="false"
                               value="<?= e_attr($verification['file']) ?>"
                               placeholder="google1a2b3c4d5e6f7g.html">
                        <?php /* gsc_verification_write() only accepts a name of Google's own shape, and
                                 writes the exact contents Google looks for. */ ?>
                        <span class="sik-help">
                            Paste the filename Google gave you. We create it.
                        </span>
                    </div>
                    <button type="submit" class="ad-btn ad-btn--sm"><?= icon('upload', 'w-4 h-4') ?> Create file</button>
                </form>
                <?php if ($verification['file'] !== ''): ?>
                    <form method="post" style="margin-top:8px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify_delete">
                        <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">Remove file</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ad-card" style="margin-bottom:20px">
    <div class="ad-card__head">
        <h2 class="ad-card__title">robots.txt</h2>
        <a class="ad-btn ad-btn--sm" style="margin-left:auto" href="<?= e(robots_txt_url()) ?>"
           target="_blank" rel="noopener"><?= icon('external', 'w-4 h-4') ?> Open</a>
    </div>
    <div class="ad-card__body">
        <form method="get" class="ad-field" style="max-width:520px">
            <label class="sik-label" for="robots_test">Is a URL crawlable?</label>
            <div style="display:flex;gap:8px">
                <input class="sik-input" type="text" id="robots_test" name="test"
                       value="<?= e_attr($testPath) ?>" placeholder="/product/warm-white-fairy-lights">
                <button type="submit" class="ad-btn">Check</button>
            </div>
            <?php if ($testResult !== null): ?>
                <span class="<?= $testResult ? 'sik-help' : 'sik-error' ?>">
                    <?= $testResult
                        ? 'Allowed. A crawler may fetch it.'
                        : 'Disallowed by a rule in robots.txt.' ?>
                </span>
            <?php endif; ?>
        </form>

        <?php /* The disallow list is code, not a setting, on purpose: those paths are a property of how
                 the store is routed, and an admin who could edit them could de-index the whole catalogue
                 by accident. Only the operator's own extra rules are editable, and they are appended. */ ?>
        <p class="sik-help">
            This list is fixed. Your own extra rules are appended &mdash; edit them on
            <a href="<?= e(admin_url('settings/seo.php')) ?>">Settings &rsaquo; SEO</a>.
        </p>

        <pre class="ad-code" style="white-space:pre-wrap;max-height:320px;overflow:auto;font-size:12px"><?= e($robotsBody) ?></pre>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
