<?php
/**
 * ShopInnKart Admin - Analytics: where visits came from.
 *
 * The Overview shows the top six channels. This screen shows every dimension
 * analytics_breakdown() can split traffic by, one table each, so the question
 * "which campaign, which referrer, which browser" has an answer rather than a
 * roadmap.
 *
 * ONE function does all of it. AN_DIMS and AN_CUBE_DIMS in metrics.php already
 * name every dimension the rollups hold, so the tables below are a loop over
 * that list, not thirteen hand-written queries. A dimension added to the
 * rollup tomorrow appears here with no edit.
 *
 * GEO IS NOT MISSING, IT WAS NEVER COLLECTED. Country, region and city read
 * "Unknown" for every row until a location source is configured, and
 * analytics_quality() says so. They are still listed rather than hidden,
 * because an owner looking for them needs to find the answer, not the absence.
 *
 * READ-ONLY: no POST handler, nothing written.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('analytics.view');

require_once __DIR__ . '/_shared.php';

$state = analytics_admin_state();
$range = $state['range'];

$summary  = analytics_summary($range);
$previous = analytics_summary($state['prev']);
$quality  = analytics_quality($range);

$emptyState = analytics_admin_empty($summary, $quality, $state);
$hasData    = $emptyState === '';

/**
 * The dimensions, grouped the way an operator thinks about them.
 *
 * Each entry is [dimension name for analytics_breakdown(), card title, the
 * card's one-line subtitle, the attribution text]. The dimension names are
 * exactly the keys of AN_CUBE_DIMS and AN_DIMS.
 */
const ANALYTICS_ACQ_GROUPS = [
    'Who sent them' => [
        ['channel',  'Channels',  'Grouped from the referrer and campaign tags.',
            'an_daily_traffic.channel. The channel is decided once, on arrival, by an_classify_source().'],
        ['source',   'Sources',   'The site or campaign that sent the visit.',
            'an_daily_traffic.source_id, named from the an_sources table.'],
        ['medium',   'Mediums',   'The utm_medium on arrival, if there was one.',
            'an_daily_dim, dimension 1. Only visits carrying a utm_medium appear.'],
        ['campaign', 'Campaigns', 'The utm_campaign on arrival, if there was one.',
            'an_daily_dim, dimension 2. Only visits carrying a utm_campaign appear.'],
        ['referrer', 'Referrers', 'The site the link was on.',
            'an_daily_dim, dimension 3. The host only; the full URL is never stored.'],
    ],
    'What they used' => [
        ['device',       'Devices',       'Classified from the user agent.',
            'an_daily_traffic.device, set at collection time from the user agent.'],
        ['browser',      'Browsers',      'Classified from the user agent.',
            'an_daily_dim, dimension 4. Stored as an id and named at read time.'],
        ['os',           'Systems',       'Classified from the user agent.',
            'an_daily_dim, dimension 5. Stored as an id and named at read time.'],
        ['visitor_type', 'New or returning', 'Unknown for most visits, by design.',
            'an_daily_traffic.visitor_type. Telling a returning visitor apart needs an identifier '
            . 'this store deliberately does not set, so most rows read Unknown.'],
    ],
    'Where they arrived and left' => [
        ['landing', 'First pages', 'The page a visit started on.',
            'an_daily_dim, dimension 7, resolved against an_paths.'],
        ['exit',    'Last pages',  'The page a visit ended on.',
            'an_daily_dim, dimension 8, resolved against an_paths.'],
    ],
    'Where they were' => [
        ['country', 'Countries', 'Unknown until a location source is set up.',
            'an_daily_traffic.country. No location source is configured, so no location was collected.'],
        ['region',  'Regions',   'Unknown until a location source is set up.',
            'an_daily_traffic.region. No location source is configured, so no location was collected.'],
        ['city',    'Cities',    'Unknown until a location source is set up.',
            'an_daily_dim, dimension 6. No location source is configured, so no location was collected.'],
    ],
];

$breakdowns = [];
if ($hasData) {
    foreach (ANALYTICS_ACQ_GROUPS as $group => $dims) {
        foreach ($dims as [$dim, $title, $sub, $why]) {
            $breakdowns[$dim] = analytics_breakdown($dim, $range, 15);
        }
    }
}

$pageTitle    = 'Analytics - Acquisition';
$pageSubtitle = analytics_admin_subtitle($state);
$breadcrumbs  = analytics_admin_breadcrumbs('Acquisition', $state);

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= analytics_admin_tabs('acquisition', $state) ?>
    <?= analytics_admin_range_bar('acquisition', $state) ?>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= an_admin_tile('Visits', number_format($summary['sessions']), 'activity', 'blue',
        (float) $summary['sessions'], (float) $previous['sessions'],
        'vs ' . number_format($previous['sessions']) . ' before') ?>
    <?= admin_stat_card('Engaged', an_admin_rate_text($summary['engagement_rate'], $summary['sessions']),
        'target', 'green', number_format($summary['engaged_sessions']) . ' visits') ?>
    <?= admin_stat_card('Bought something', an_admin_rate_text($summary['conversion_rate'], $summary['sessions']),
        'cart', 'primary', number_format($summary['purchase_sessions']) . ' visits') ?>
    <?= admin_stat_card('Known new or returning',
        an_admin_rate_text($summary['known_type_pct'], $summary['sessions']), 'users', 'navy',
        'Of all visits in this period') ?>
</div>

<?php if (!$hasData): ?>

    <?= admin_card('', $emptyState, ['flush' => true]) ?>

<?php else: ?>

    <?php // One card per group, each dimension a titled block inside it.
          // <h3 class="ad-card__title"> is exactly what admin_card_open()
          // emits for a level-3 card, so the sub-heading reuses the card
          // heading role rather than inventing a size of its own. ?>
    <?php foreach (ANALYTICS_ACQ_GROUPS as $group => $dims): ?>
        <div style="margin-bottom:18px">
            <?= admin_card_open($group, ['sub' => 'Visits split by each of these, most visits first.']) ?>
                <div class="ad-grid ad-grid--2">
                    <?php foreach ($dims as [$dim, $title, $sub, $why]): ?>
                        <?php $rows = $breakdowns[$dim] ?? []; ?>
                        <div class="ad-minw0">
                            <div class="ad-split" style="margin-bottom:8px">
                                <h3 class="ad-card__title"><?= e($title) ?></h3>
                                <?= an_admin_why($why, $title) ?>
                            </div>
                            <p class="ad-card__sub" style="margin:0 0 10px"><?= e($sub) ?></p>

                            <?php if ($rows === []): ?>
                                <p class="ad-muted ad-text-sm" style="margin:0">Nothing recorded.</p>
                            <?php else: ?>
                                <div class="ad-tablewrap">
                                    <table class="ad-table ad-table--stack">
                                        <thead><tr>
                                            <th scope="col"><?= e($title) ?></th>
                                            <th scope="col" class="ad-table__num">Visits</th>
                                            <th scope="col" class="ad-table__num">Of all visits</th>
                                            <th scope="col" class="ad-table__num">Engaged</th>
                                            <th scope="col" class="ad-table__num">Bought</th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td><?= admin_trunc($row['label'], 42, true) ?></td>
                                                <td class="ad-table__num"><?= e(number_format($row['sessions'])) ?></td>
                                                <td class="ad-table__num"><?= an_admin_share($row['sessions'], $summary['sessions']) ?></td>
                                                <td class="ad-table__num">
                                                    <?= an_admin_rate($row['engagement_rate'], $row['sessions']) ?>
                                                </td>
                                                <td class="ad-table__num">
                                                    <?= an_admin_rate($row['conversion_rate'], $row['sessions']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($rows) >= 15): ?>
                                    <p class="ad-text-xs ad-muted" style="margin:8px 0 0">Top 15 shown.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?= admin_card_close() ?>
        </div>
    <?php endforeach; ?>

    <div class="ad-card" style="margin-top:18px">
        <div class="ad-card__body">
            <?= analytics_admin_notes($quality, $state) ?>
        </div>
    </div>

<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
