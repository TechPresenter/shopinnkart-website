<?php
/**
 * ShopInnKart Admin - SEO overview.
 *
 * Coverage, per entity type, and the three queues. Every number here is a
 * COUNT over records that are actually published, with a link to the list that
 * fixes them - nothing is weighted, and there is no score out of 100, for the
 * same reason the per-record checklist has none: a figure nobody can trace
 * back to a row invites work on the figure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once __DIR__ . '/_layout.php';

/**
 * How many published records of this type are missing something.
 *
 * @return array{total:int,no_title:int,no_description:int,no_keywords:int,noindex:int,with_code:int}
 */
function seo_overview_row(string $type, array $spec): array
{
    $blank = static fn (string $column): string => "(`$column` IS NULL OR TRIM(`$column`) = '')";

    $count = static function (string $condition) use ($spec): int {
        try {
            return (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `' . $spec['table'] . '` WHERE (' . $spec['live'] . ') AND (' . $condition . ')'
            );
        } catch (Throwable $e) {
            return 0;
        }
    };

    try {
        $total = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `' . $spec['table'] . '` WHERE ' . $spec['live']
        );
    } catch (Throwable $e) {
        $total = 0;
    }

    // Keywords and the code flag live in the side table, so they are counted
    // there - a LEFT JOIN rather than a second pass, because "no row at all"
    // and "a row with a blank field" are the same answer to the operator.
    try {
        $noKeywords = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `' . $spec['table'] . '` e
               LEFT JOIN `seo_entity_meta` m ON m.`entity_type` = :t AND m.`entity_id` = e.`id`
              WHERE (' . str_replace('`status`', 'e.`status`', $spec['live']) . ')
                AND (m.`meta_keywords` IS NULL OR TRIM(m.`meta_keywords`) = "")',
            ['t' => $type]
        );
        $withCode = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `seo_entity_meta`
              WHERE `entity_type` = :t
                AND COALESCE(NULLIF(TRIM(`custom_head`), ""), NULLIF(TRIM(`custom_body`), ""),
                             NULLIF(TRIM(`custom_css`), ""), NULLIF(TRIM(`custom_js`), "")) IS NOT NULL',
            ['t' => $type]
        );
    } catch (Throwable $e) {
        $noKeywords = 0;
        $withCode   = 0;
    }

    return [
        'total'          => $total,
        'no_title'       => $count($blank('meta_title')),
        'no_description' => $count($blank('meta_description')),
        'no_keywords'    => $noKeywords,
        'noindex'        => $count("`robots` LIKE '%noindex%'"),
        'with_code'      => $withCode,
    ];
}

$rows = [];
foreach (SEO_ENTITY_TYPES as $type => $spec) {
    $rows[$type] = seo_overview_row($type, $spec) + ['label' => $spec['label'], 'admin' => $spec['admin']];
}

// The three queues, as counts.
try {
    $open404 = (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE `status` = 'open'");
    $hits404 = (int) Database::fetchColumn("SELECT COALESCE(SUM(`hits`), 0) FROM `seo_404_log` WHERE `status` = 'open'");
} catch (Throwable $e) {
    $open404 = 0;
    $hits404 = 0;
}

$noAltCount  = seo_images_missing_alt_count();
$injected    = seo_injected_code_inventory(500);
$redirects   = (int) Database::fetchColumn("SELECT COUNT(*) FROM `redirects` WHERE `status` = 'active'");

// Duplicate meta titles across a type are the one problem worth calling out on
// an overview: two published records competing for the same search, which is
// almost always a template that filled both in.
$duplicates = [];
foreach (SEO_ENTITY_TYPES as $type => $spec) {
    try {
        foreach (Database::fetchAll(
            'SELECT `meta_title` AS t, COUNT(*) AS n FROM `' . $spec['table'] . '`
              WHERE (' . $spec['live'] . ') AND `meta_title` IS NOT NULL AND TRIM(`meta_title`) <> ""
              GROUP BY `meta_title` HAVING n > 1 ORDER BY n DESC LIMIT 5'
        ) as $duplicate) {
            $duplicates[] = [
                'type'  => $spec['label'],
                'title' => (string) $duplicate['t'],
                'count' => (int) $duplicate['n'],
                'admin' => $spec['admin'],
            ];
        }
    } catch (Throwable $e) {
        // a table that is not there yet contributes nothing
    }
}
usort($duplicates, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

$pageTitle    = 'SEO';
$pageSubtitle = 'Per-record SEO, broken links, image alt text and anything injected into the storefront';
$breadcrumbs  = seo_admin_breadcrumbs('Overview');

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <?= seo_admin_tabs('index') ?>

    <div class="ad-card__body">
        <div class="ad-row ad-row--3" style="gap:12px">
            <a class="ad-card" style="margin:0;padding:16px;text-decoration:none"
               href="<?= e(seo_admin_url('404s')) ?>">
                <div class="ad-card__title"><?= number_format($open404) ?></div>
                <div class="ad-muted">
                    broken link<?= $open404 === 1 ? '' : 's' ?> waiting
                    <?php if ($hits404 > 0): ?>
                        &middot; <?= number_format($hits404) ?> visit<?= $hits404 === 1 ? '' : 's' ?> lost
                    <?php endif; ?>
                </div>
            </a>

            <a class="ad-card" style="margin:0;padding:16px;text-decoration:none"
               href="<?= e(seo_admin_url('images')) ?>">
                <div class="ad-card__title"><?= number_format($noAltCount) ?></div>
                <div class="ad-muted">
                    image<?= $noAltCount === 1 ? '' : 's' ?> with no alt text
                </div>
            </a>

            <a class="ad-card" style="margin:0;padding:16px;text-decoration:none"
               href="<?= e(seo_admin_url('scripts')) ?>">
                <div class="ad-card__title"><?= number_format(count($injected)) ?></div>
                <div class="ad-muted">
                    record<?= count($injected) === 1 ? '' : 's' ?> injecting custom code
                </div>
            </a>
        </div>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">Coverage by record type</div>
        <?php /* Drafts are excluded on purpose: a draft is not in anybody's index, so counting it
                 as a gap would invent work that does not need doing. */ ?>
        <div class="ad-card__sub">
            Published records only &middot; <?= number_format($redirects) ?>
            active redirect<?= $redirects === 1 ? '' : 's' ?>
        </div>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Published</th>
                        <th>No meta title</th>
                        <th>No description</th>
                        <th>No keywords</th>
                        <th>Set to noindex</th>
                        <th>Injecting code</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $type => $row): ?>
                        <tr>
                            <td><strong><?= e($row['label']) ?></strong></td>
                            <td><?= number_format($row['total']) ?></td>
                            <td<?= $row['no_title'] > 0 ? ' class="ad-warn"' : '' ?>>
                                <?= number_format($row['no_title']) ?>
                            </td>
                            <td<?= $row['no_description'] > 0 ? ' class="ad-warn"' : '' ?>>
                                <?= number_format($row['no_description']) ?>
                            </td>
                            <td class="ad-muted"><?= number_format($row['no_keywords']) ?></td>
                            <td><?= number_format($row['noindex']) ?></td>
                            <td><?= number_format($row['with_code']) ?></td>
                            <td>
                                <a class="ad-btn ad-btn--sm"
                                   href="<?= e(admin_url(dirname($row['admin']) . '/')) ?>">Open list</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ad-card__foot">
        <span class="ad-muted">
            Keywords are counted, not flagged: Google has ignored that tag since 2009.
        </span>
    </div>
</div>

<?php if ($duplicates !== []): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">Meta titles used more than once</div>
            <div class="ad-card__sub">
                Two pages competing for one search &mdash; usually a template.
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Type</th><th>Title</th><th>Records</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($duplicates, 0, 15) as $duplicate): ?>
                            <tr>
                                <td><?= e($duplicate['type']) ?></td>
                                <td><?= e($duplicate['title']) ?></td>
                                <td><?= (int) $duplicate['count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
