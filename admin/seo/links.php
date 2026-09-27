<?php
/**
 * ShopInnKart Admin - Internal linking.
 *
 * Which pages link to which, across the whole store. Before this, internal
 * linking was one line on the per-record SEO checklist ("this page links to
 * other pages") - a yes/no about the record you happened to have open, which
 * cannot answer the only questions that matter:
 *
 *   ORPHANS       nothing links to this published record at all
 *   THIN          this page or post links nowhere, so it sells nothing
 *   BROKEN        a link somebody wrote points at a slug that is gone
 *   WHAT LINKS HERE   for one record, every page that points at it
 *
 * The report is built by seo_link_graph() and cached; this screen only renders
 * it. See the INTERNAL LINKING section of includes/seo.php for what is counted,
 * what is not, and which template was read before a link was credited.
 *
 * Nothing on this screen edits anybody's copy. The thin list SUGGESTS records a
 * post could link to and stops there - an admin screen that rewrites prose is
 * an admin screen nobody trusts twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once __DIR__ . '/_layout.php';

/** The four questions, in the order they are worth asking. */
$views = [
    'orphans' => 'Orphans',
    'thin'    => 'Links nowhere',
    'broken'  => 'Broken links',
    'record'  => 'What links here',
];

$view = (string) input('view', 'orphans');
$view = isset($views[$view]) ? $view : 'orphans';
$page = max(1, (int) ($_GET['page'] ?? 1));

$viewUrl = static function (string $which, array $extra = []): string {
    return seo_admin_url('links') . '?' . http_build_query(['view' => $which] + $extra);
};

if (is_post()) {
    // Rebuilding recomputes a report and writes one cache entry. It changes no
    // store data, so it is gated on the same permission as reading the screen
    // rather than on settings.edit - but it is still a POST behind CSRF,
    // because a GET that does work is a GET something else can trigger.
    admin_require_action('settings.view');

    if ((string) input('action', '') === 'rebuild') {
        $rebuilt = seo_link_graph(true);
        log_activity('seo.links_rebuilt', 'seo', null,
            'Rebuilt the internal-link report: ' . (int) $rebuilt['counts']['records'] . ' records');
        flash('success', 'Rebuilt ' . number_format((int) $rebuilt['counts']['records'])
            . ' records in ' . $rebuilt['ms'] . ' ms.');
    }

    redirect($viewUrl($view));
}

$graph  = seo_link_graph();
$nodes  = (array) $graph['nodes'];
$counts = (array) $graph['counts'];

// --- shared row helpers ----------------------------------------------------

/** The admin editor for one node. */
$editUrl = static function (string $key) use ($nodes): ?string {
    $node = $nodes[$key] ?? null;
    $path = $node === null ? '' : (string) (SEO_ENTITY_TYPES[$node['type']]['admin'] ?? '');

    return $path === '' ? null : admin_url($path . '?id=' . (int) $node['id']);
};

/** The public URL of one node, or null when it has no usable slug. */
$publicUrl = static function (string $key) use ($nodes): ?string {
    $node = $nodes[$key] ?? null;
    $url  = $node === null ? '' : seo_entity_url((string) $node['type'], (string) $node['slug']);

    return $url === '' ? null : $url;
};

/** The screen that fixes a link's source: a record editor, or a builder. */
$sourceUrl = static function (array $row) use ($nodes): ?string {
    $fix = (string) ($row['fix'] ?? '');
    if ($fix === '') {
        return null;
    }
    $from = $row['from'] ?? null;

    return $from !== null && isset($nodes[$from])
        ? admin_url($fix . '?id=' . (int) $nodes[$from]['id'])
        : admin_url($fix);
};

// Every long value below goes through admin_trunc(), which RETURNS ESCAPED
// HTML (it has to, because it wraps a shortened value in a span carrying the
// full text as a tooltip). So it is printed without e() - wrapping it showed
// the operator the span source instead of the value.

/** Type pill for a node, using the state tones the rest of the admin uses. */
$typePill = static function (string $key) use ($nodes): string {
    $type = (string) ($nodes[$key]['type'] ?? '');

    return '<span class="sik-status sik-status--gray">'
        . e((string) (SEO_ENTITY_TYPES[$type]['label'] ?? $type)) . '</span>';
};

// --- the lists this view needs --------------------------------------------

$problems = (array) $graph['problems'];
$dead     = 0;
$soft     = 0;
foreach ($problems as $problem) {
    if ($problem['reason'] === 'gone' || $problem['reason'] === 'case') {
        $dead++;
    } else {
        $soft++;
    }
}

$record = null;
$matches = [];
$search  = trim((string) input('q', ''));

if ($view === 'record') {
    $wanted = (string) input('rec', '');
    if (isset($nodes[$wanted])) {
        $record = $wanted;
    } elseif ($search !== '') {
        $needle = strtolower($search);
        foreach ($nodes as $key => $node) {
            if (str_contains(strtolower((string) $node['label']), $needle)
                || str_contains(strtolower((string) $node['slug']), $needle)) {
                $matches[] = $key;
            }
            if (count($matches) >= 30) {
                break;
            }
        }
    }
}

$rows = match ($view) {
    'orphans' => (array) $graph['orphans'],
    'thin'    => (array) $graph['thin'],
    'broken'  => $problems,
    default   => [],
};

$pagination = paginate(count($rows), ADMIN_PER_PAGE, $page);
$shown      = array_slice($rows, (int) $pagination['offset'], (int) $pagination['per_page']);

$subtitles = [
    'orphans' => 'Published records nothing on the site links to',
    'thin'    => 'Pages and posts whose own copy links nowhere',
    'broken'  => 'Links that are written down and lead nowhere',
    'record'  => 'Every link that points at one record',
];

$pageTitle    = 'Internal links';
// Plain text, not entities: header.php and admin_stat_card() both run their
// copy through e(), so an &middot; here reaches the operator as "&middot;".
$pageSubtitle = number_format(count((array) $graph['orphans'])) . ' orphaned, '
    . number_format($dead) . ' broken';
$breadcrumbs  = seo_admin_breadcrumbs('Internal links');

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <?= seo_admin_tabs('links') ?>

    <div class="ad-card__body">
        <div class="ad-grid ad-grid--4">
            <?= admin_stat_card('Orphans', number_format(count((array) $graph['orphans'])), 'target',
                count((array) $graph['orphans']) > 0 ? 'amber' : 'green',
                'published, nothing links to them', $viewUrl('orphans')) ?>

            <?= admin_stat_card('Links nowhere', number_format(count((array) $graph['thin'])), 'file-text',
                count((array) $graph['thin']) > 0 ? 'amber' : 'green',
                'pages and posts only', $viewUrl('thin')) ?>

            <?php /* The sub has to agree with the number above it: "nothing points at a
                     gap" under a 3 is the screen contradicting itself. */ ?>
            <?= admin_stat_card('Broken links', number_format($dead), 'alert',
                $dead > 0 ? 'red' : 'green',
                match (true) {
                    $soft > 0 => number_format($soft) . ' more need a look',
                    $dead > 0 => 'in copy, menus or the footer',
                    default   => 'nothing written points at a gap',
                },
                $viewUrl('broken')) ?>

            <?= admin_stat_card('Links read', number_format((int) $counts['links'] + (int) $counts['placements']),
                'link', 'primary',
                number_format((int) $counts['links']) . ' in copy, '
                    . number_format((int) $counts['placements']) . ' placed') ?>
        </div>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-minw0">
            <h2 class="ad-card__title"><?= e($views[$view]) ?></h2>
            <p class="ad-card__sub"><?= e($subtitles[$view]) ?></p>
        </div>
        <div class="ad-card__actions">
            <form method="post" action="<?= e($viewUrl($view)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rebuild">
                <button type="submit" class="ad-btn ad-btn--sm">
                    <?= icon('refresh', 'w-4 h-4') ?> Rebuild
                </button>
            </form>
        </div>
    </div>

    <?= (static function (array $views, string $view, callable $viewUrl, array $graph, int $dead): string {
        $counts = ['orphans' => count((array) $graph['orphans']), 'thin' => count((array) $graph['thin']),
                   'broken' => $dead, 'record' => null];
        $out = '<div class="ad-tabs">';
        foreach ($views as $key => $label) {
            $out .= '<a class="ad-tab' . ($key === $view ? ' is-active' : '') . '" href="'
                . e($viewUrl($key)) . '">' . e($label)
                . ($counts[$key] !== null ? ' <span class="ad-tab__count">' . number_format($counts[$key]) . '</span>' : '')
                . '</a>';
        }
        return $out . '</div>';
    })($views, $view, $viewUrl, $graph, $dead) ?>

<?php if ($view === 'orphans'): ?>

    <?php if ($shown === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= admin_empty(
                'Everything published is linked from somewhere',
                'No published record is left with nothing pointing at it.',
                null,
                null,
                'check-circle'
            ) ?>
        </div>
    <?php else: ?>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:110px">Type</th>
                            <th>Record</th>
                            <th>Links out</th>
                            <th style="width:210px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shown as $key): ?>
                            <?php $node = $nodes[$key]; ?>
                            <tr>
                                <td><?= $typePill($key) ?></td>
                                <td>
                                    <?php $edit = $editUrl($key); ?>
                                    <?php if ($edit !== null): ?>
                                        <a href="<?= e($edit) ?>"><?= e((string) $node['label']) ?></a>
                                    <?php else: ?>
                                        <?= e((string) $node['label']) ?>
                                    <?php endif; ?>
                                    <div class="ad-cellflex__meta">/<?= e((string) $node['slug']) ?></div>
                                </td>
                                <td><?= number_format((int) $node['out']) ?></td>
                                <td>
                                    <a class="ad-btn ad-btn--sm"
                                       href="<?= e($viewUrl('record', ['rec' => $key])) ?>">Links</a>
                                    <?php $public = $publicUrl($key); ?>
                                    <?php if ($public !== null): ?>
                                        <a class="ad-btn ad-btn--sm" href="<?= e($public) ?>"
                                           target="_blank" rel="noopener">
                                            <?= icon('external', 'w-3.5 h-3.5') ?> View
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($view === 'thin'): ?>

    <?php if ($shown === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= admin_empty(
                'Every page and post links somewhere',
                'No published page or post is left without a link in its copy.',
                null,
                null,
                'check-circle'
            ) ?>
        </div>
    <?php else: ?>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:110px">Type</th>
                            <th style="width:32%">Record</th>
                            <th>It could link to</th>
                            <th style="width:120px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shown as $key): ?>
                            <?php $node = $nodes[$key]; ?>
                            <tr>
                                <td><?= $typePill($key) ?></td>
                                <td>
                                    <?php $edit = $editUrl($key); ?>
                                    <?php if ($edit !== null): ?>
                                        <a href="<?= e($edit) ?>"><?= e((string) $node['label']) ?></a>
                                    <?php else: ?>
                                        <?= e((string) $node['label']) ?>
                                    <?php endif; ?>
                                    <div class="ad-cellflex__meta">/<?= e((string) $node['slug']) ?></div>
                                </td>
                                <td>
                                    <?php $ideas = seo_link_suggestions($key, $graph, 3); ?>
                                    <?php if ($ideas === []): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php else: ?>
                                        <?php foreach ($ideas as $idea): ?>
                                            <?php $ideaUrl = $publicUrl($idea); ?>
                                            <div>
                                                <?php if ($ideaUrl !== null): ?>
                                                    <a href="<?= e($ideaUrl) ?>" target="_blank" rel="noopener"><?=
                                                        admin_trunc((string) $nodes[$idea]['label'], 62) ?></a>
                                                <?php else: ?>
                                                    <?= admin_trunc((string) $nodes[$idea]['label'], 62) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($edit !== null): ?>
                                        <a class="ad-btn ad-btn--sm" href="<?= e($edit) ?>">Edit copy</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ad-card__foot">
            <span class="ad-muted">Suggestions come from shared words in the titles. Nothing is edited for you.</span>
        </div>
    <?php endif; ?>

<?php elseif ($view === 'broken'): ?>

    <?php if ($shown === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= admin_empty(
                'Every written link resolves',
                'No link in any body, menu, footer or homepage row points at a missing page.',
                null,
                null,
                'check-circle'
            ) ?>
        </div>
    <?php else: ?>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:26%">Written in</th>
                            <th>The link</th>
                            <th>Problem</th>
                            <th style="width:70px">Times</th>
                            <th style="width:90px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shown as $problem): ?>
                            <?php
                            [$tone, $what] = match ($problem['reason']) {
                                'gone'       => ['red',   'No such page'],
                                'case'       => ['red',   'Spelled differently'],
                                'draft'      => ['amber', 'Points at a draft'],
                                'redirected' => ['gray',  'Goes through a redirect'],
                                default      => ['gray',  'Needs a look'],
                            };
                            ?>
                            <tr>
                                <td>
                                    <?php $fix = $sourceUrl($problem); ?>
                                    <?php if ($fix !== null): ?>
                                        <a href="<?= e($fix) ?>"><?= admin_trunc((string) $problem['label'], 54) ?></a>
                                    <?php else: ?>
                                        <?= admin_trunc((string) $problem['label'], 54) ?>
                                    <?php endif; ?>
                                    <div class="ad-cellflex__meta">
                                        <?= e((string) (SEO_LINK_KINDS[$problem['kind']] ?? $problem['kind'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <code><?= admin_trunc((string) $problem['href'], 70, true) ?></code>
                                    <?php if ((string) $problem['anchor'] !== ''): ?>
                                        <div class="ad-cellflex__meta">
                                            &ldquo;<?= admin_trunc((string) $problem['anchor'], 52) ?>&rdquo;
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="sik-status sik-status--<?= e_attr($tone) ?>"><?= e($what) ?></span>
                                    <?php if ((string) $problem['target'] !== ''): ?>
                                        <div class="ad-cellflex__meta"><?=
                                            admin_trunc((string) $problem['target'], 54) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((int) $problem['count']) ?></td>
                                <td>
                                    <?php if ($fix !== null): ?>
                                        <a class="ad-btn ad-btn--sm" href="<?= e($fix) ?>">Open</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ad-card__foot">
            <span class="ad-muted">
                A dead link is fixed in the copy, or pointed somewhere real in
                <a href="<?= e(admin_url('settings/redirects.php')) ?>">Redirects</a>.
            </span>
        </div>
    <?php endif; ?>

<?php else: ?>

    <div class="ad-card__body">
        <?php /* Not .ad-search: that class absolutely positions the svg inside it,
                 which is right for a bare search box and wrong for one with a
                 submit button beside it. */ ?>
        <form method="get" action="<?= e(seo_admin_url('links')) ?>" role="search"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="view" value="record">
            <label class="sik-sr" for="q">Find a record by name or slug</label>
            <input class="sik-input" type="search" id="q" name="q" value="<?= e_attr($search) ?>"
                   style="max-width:340px" placeholder="Find a product, page, category&hellip;">
            <button type="submit" class="ad-btn ad-btn--sm"><?= icon('search', 'w-4 h-4') ?> Find</button>
        </form>
    </div>

    <?php if ($record === null): ?>
        <?php if ($search !== '' && $matches === []): ?>
            <div class="ad-card__body ad-card__body--flush">
                <?= admin_empty('Nothing matched', 'No published or draft record has that in its name or slug.',
                    null, null, 'search') ?>
            </div>
        <?php elseif ($matches !== []): ?>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th style="width:110px">Type</th>
                                <th>Record</th>
                                <th style="width:110px">Links in</th>
                                <th style="width:110px">Links out</th>
                                <th style="width:110px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $key): ?>
                                <tr>
                                    <td><?= $typePill($key) ?></td>
                                    <td>
                                        <a href="<?= e($viewUrl('record', ['rec' => $key])) ?>"><?=
                                            e((string) $nodes[$key]['label']) ?></a>
                                        <div class="ad-cellflex__meta">/<?= e((string) $nodes[$key]['slug']) ?></div>
                                    </td>
                                    <td><?= number_format((int) $nodes[$key]['in_total']) ?></td>
                                    <td><?= number_format((int) $nodes[$key]['out']) ?></td>
                                    <td>
                                        <a class="ad-btn ad-btn--sm"
                                           href="<?= e($viewUrl('record', ['rec' => $key])) ?>">Links</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="ad-card__body ad-card__body--flush">
                <?= admin_empty('Pick a record', 'Search above, or open one from the orphan list.',
                    null, null, 'link') ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $node    = $nodes[$record];
        $inbound = (array) $node['in'];
        $public  = $publicUrl($record);
        $edit    = $editUrl($record);
        ?>
        <div class="ad-card__body">
            <?= admin_kv([
                ['Record', ($edit !== null
                    ? '<a href="' . e($edit) . '">' . e((string) $node['label']) . '</a>'
                    : e((string) $node['label']))
                    . ' ' . $typePill($record)],
                ['Public URL', $public !== null
                    ? '<a href="' . e($public) . '" target="_blank" rel="noopener">' . e($public) . '</a>'
                    : '<span class="ad-muted">no usable slug</span>', ['mono' => true]],
                ['Published', $node['live']
                    ? '<span class="sik-status sik-status--green">Published</span>'
                    : '<span class="sik-status sik-status--amber">Not published</span>'],
                ['Links in', number_format((int) $node['in_total'])
                    . ((int) $node['in_more'] > 0
                        ? ' <span class="ad-muted">(' . number_format((int) $node['in_more']) . ' not listed)</span>'
                        : '')],
                ['Links out of its copy', number_format((int) $node['out'])
                    . ' <span class="ad-muted">&middot; ' . number_format((int) $node['out_entity'])
                    . ' reach a record</span>'],
            ]) ?>
        </div>

        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th colspan="4">What links here</th>
                        </tr>
                        <tr>
                            <th style="width:150px">Read from</th>
                            <th>Source</th>
                            <th>Linked words</th>
                            <th style="width:70px">Times</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($inbound === []): ?>
                            <tr>
                                <td colspan="4" class="ad-muted">Nothing links to this record.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inbound as $link): ?>
                                <tr>
                                    <td><?= e((string) (SEO_LINK_KINDS[$link['kind']] ?? $link['kind'])) ?></td>
                                    <td>
                                        <?php $fix = $sourceUrl($link); ?>
                                        <?php if ($fix !== null): ?>
                                            <a href="<?= e($fix) ?>"><?= admin_trunc((string) $link['label'], 64) ?></a>
                                        <?php else: ?>
                                            <?= admin_trunc((string) $link['label'], 64) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((string) $link['anchor'] !== ''): ?>
                                            &ldquo;<?= admin_trunc((string) $link['anchor'], 54) ?>&rdquo;
                                        <?php else: ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format((int) $link['count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php $outbound = seo_links_from((string) $node['type'], (int) $node['id']); ?>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th colspan="3">Where its own copy links</th>
                        </tr>
                        <tr>
                            <th style="width:150px">Resolves to</th>
                            <th>The link</th>
                            <th>Linked words</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($outbound === []): ?>
                            <tr>
                                <td colspan="3" class="ad-muted">Its copy contains no links.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($outbound as $link): ?>
                                <?php
                                [$tone, $what] = match ((string) $link['status']) {
                                    'ok'         => ['green', (string) $link['label'] !== '' ? 'A record' : 'A page'],
                                    'draft'      => ['amber', 'A draft'],
                                    'redirected' => ['gray',  'A redirect'],
                                    'gone'       => ['red',   'Nothing'],
                                    'case'       => ['red',   'Another spelling'],
                                    'external'   => ['blue',  'Another site'],
                                    default      => ['gray',  'Not resolved'],
                                };
                                ?>
                                <tr>
                                    <td>
                                        <span class="sik-status sik-status--<?= e_attr($tone) ?>"><?= e($what) ?></span>
                                    </td>
                                    <td>
                                        <code><?= admin_trunc((string) $link['href'], 74, true) ?></code>
                                        <?php if ((string) $link['label'] !== ''): ?>
                                            <div class="ad-cellflex__meta"><?=
                                                admin_trunc((string) $link['label'], 60) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((string) $link['anchor'] !== ''): ?>
                                            &ldquo;<?= admin_trunc((string) $link['anchor'], 46) ?>&rdquo;
                                        <?php else: ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <?php /* The bare path, not $viewUrl(): admin_pagination() rebuilds the whole
                     query string from $_GET, so handing it one that already carries
                     ?view= produces links.php?view=x?view=x&page=2. */ ?>
            <?= admin_pagination($pagination, seo_admin_url('links')) ?>
        </div>
    <?php endif; ?>

    <div class="ad-card__foot">
        <span class="ad-muted">
            Built <?= e(time_ago((int) $graph['built_at'])) ?>, in <?= e((string) $graph['ms']) ?> ms.
            <?php if (!setting_bool('cache_enabled', true)): ?>
                Caching is off, so it rebuilds on every load.
            <?php endif; ?>
            <?php if ((int) $graph['overflow'] > 0): ?>
                <?= number_format((int) $graph['overflow']) ?> further problems are not listed.
            <?php endif; ?>
        </span>

        <details>
            <summary>What this can and cannot see</summary>
            <p>
                Two kinds of link are counted. <strong>Body copy</strong> is read by parsing the
                <code>&lt;a href&gt;</code> in every product, category, brand, page, blog post and
                combo description, plus any hand-written HTML in a homepage row. <strong>Placements</strong>
                are links a template builds from something stored: a product&rsquo;s category and brand,
                a child category&rsquo;s parent, a combo&rsquo;s items, a post&rsquo;s blog category,
                menu items, footer links and the records pinned into a homepage row.
            </p>
            <p>
                What is <strong>not</strong> counted: anything a template invents while rendering.
                Pagination, search results, the &ldquo;you may also like&rdquo; widget, breadcrumbs past
                the parent chain, banners, popups and the sitemap. A listing page also links everything
                of its kind &mdash; <code>/shop</code> lists every product, <code>/combos</code> every
                combo &mdash; and that is not counted either, so a record in the orphan list is one that
                nothing <em>chooses</em> to link, not one no crawler can ever reach.
            </p>
            <p>
                A link counts as internal when it is relative or spelled out on a host this install
                recognises. A relative link is read from the site root, which is where the storefront
                resolves the ones stored in menus. The <code>{{page:slug}}</code> and
                <code>{{url:path}}</code> tokens policy copy is written with are expanded first, the
                same way the storefront expands them. Unpublished records link nothing: a draft page is
                not a link anybody can follow.
            </p>
            <p>
                <strong>Broken</strong> means a link whose path matches a real storefront route shape
                and whose record is gone. A path this screen cannot map to a route at all
                &mdash; <code>/admin/&hellip;</code>, an upload, something a plugin serves &mdash; is
                left alone rather than called broken. What visitors actually hit is on
                <a href="<?= e(seo_admin_url('404s')) ?>">Broken links</a>; this screen is the other
                half, the ones nobody has clicked yet.
            </p>
        </details>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
