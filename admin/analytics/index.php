<?php
/**
 * ShopInnKart Admin - Analytics overview.
 *
 * The screen that reads includes/analytics/metrics.php. Collection has been
 * running, the rollups have been running, and until this page existed the owner
 * could not see a single figure either produced - the only caller of the metric
 * layer was the settings screen, which shows its health and none of its output.
 *
 * It answers six questions, in the order an owner asks them:
 *
 *   1. how many people came, and is that more or less than before
 *   2. where did they come from
 *   3. what did they look at
 *   4. did they buy
 *   5. what did they search for and not find
 *   6. is the site fast
 *
 * NO NEW ARITHMETIC. Every number here is returned by a metrics.php function.
 * The two exceptions are both display decisions and both documented where they
 * happen: analytics_admin_points() sums daily rows into weeks so a long range
 * is a chart rather than a texture, and an_admin_rate() declines to print a
 * percentage whose denominator is under thirty visits.
 *
 * READ-ONLY. There is no POST handler and no CSRF field because nothing on this
 * screen writes anything; Settings > Analytics owns every switch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('analytics.view');

require_once __DIR__ . '/_shared.php';

$state = analytics_admin_state();
$range = $state['range'];
$prev  = $state['prev'];

$summary  = analytics_summary($range);
$previous = analytics_summary($prev);
$quality  = analytics_quality($range);

$emptyState = analytics_admin_empty($summary, $quality, $state);
$hasData    = $emptyState === '';

// Nothing below the headline is worth a query when the range is empty: a card
// full of dashes says less than one honest empty state.
if ($hasData) {
    $series   = analytics_series($range);
    $chart    = analytics_admin_points($series, 'sessions');
    $channels = analytics_breakdown('channel', $range, 6);
    $devices  = analytics_breakdown('device', $range, 4);
    $pages    = analytics_top_pages($range, 5);
    $products = analytics_top_products($range, 5, 'views');
    $funnel   = analytics_funnel($range);
    $carts    = analytics_cart_abandonment($range);
    $searches = analytics_top_searches($range, 6);
    $vitals   = analytics_vitals($range);
}

$pageTitle    = 'Analytics';
$pageSubtitle = analytics_admin_subtitle($state);
$breadcrumbs  = analytics_admin_breadcrumbs('Overview', $state);

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= analytics_admin_tabs('index', $state) ?>
    <?= analytics_admin_range_bar('index', $state) ?>
</div>

<?php if (!$quality['counting']): ?>
    <div class="sik-alert sik-alert--warning" style="margin-bottom:18px">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>Counting is off. These are old figures.</div>
    </div>
<?php endif; ?>

<?php // ===================== 1. How many came, vs before ===================== ?>
<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?php
    $visitorTile = $summary['visitors_exact']
        ? admin_stat_card('Visitors', an_admin_num($summary['visitors']), 'users', 'primary',
            'vs ' . an_admin_num($previous['visitors']) . ' before')
        : admin_stat_card('Visitors', '—', 'users', 'navy', 'Not countable with a filter on');
    ?>
    <?= $visitorTile ?>
    <?= an_admin_tile('Visits', number_format($summary['sessions']), 'activity', 'blue',
        (float) $summary['sessions'], (float) $previous['sessions'],
        'vs ' . number_format($previous['sessions']) . ' before') ?>
    <?= an_admin_tile('Pages seen', number_format($summary['pageviews']), 'eye', 'violet',
        (float) $summary['pageviews'], (float) $previous['pageviews'],
        number_format($summary['pages_per_session'], 2) . ' per visit') ?>
    <?php if ($summary['books_available']): ?>
        <?= an_admin_tile('Revenue', money($summary['revenue']), 'wallet', 'green',
            $summary['revenue'], $previous['revenue'],
            number_format($summary['orders']) . ' orders in your books') ?>
    <?php else: ?>
        <?= admin_stat_card('Attributed revenue', money($summary['attr_revenue']), 'wallet', 'amber',
            'Only visits this filter matched') ?>
    <?php endif; ?>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Engaged visits', an_admin_rate_text($summary['engagement_rate'], $summary['sessions']),
        'target', 'green', number_format($summary['engaged_sessions']) . ' of '
        . number_format($summary['sessions'])) ?>
    <?= admin_stat_card('Avg time on site', $summary['sessions'] > 0
        ? number_format($summary['avg_engagement_s'], 1) . 's' : '—', 'clock', 'blue',
        'Engaged time per visit') ?>
    <?= admin_stat_card('Bought something', an_admin_rate_text($summary['conversion_rate'], $summary['sessions']),
        'cart', 'primary', number_format($summary['purchase_sessions']) . ' visits ended in a sale') ?>
    <?php // The thirty-visit floor is deliberately NOT applied to this one: the
          // base is ORDERS, the sub-line below prints both the numerator and the
          // denominator, and a store with nine orders still needs to know that
          // only four of them were tracked. The floor exists to stop a
          // percentage standing alone over a base nobody can see. ?>
    <?= admin_stat_card('Tracked to a visit', $summary['books_available']
        ? an_admin_rate_text($summary['attribution_rate'], $summary['orders'], 1) : '—',
        'shield-check', 'navy', $summary['books_available']
            ? number_format($summary['attr_orders']) . ' of ' . number_format($summary['orders']) . ' orders'
            : 'Hidden while a filter is on') ?>
</div>

<?php if (!$hasData): ?>

    <?= admin_card('', $emptyState, ['flush' => true]) ?>

    <div class="ad-card" style="margin-top:18px">
        <div class="ad-card__body">
            <?= analytics_admin_notes($quality, $state) ?>
        </div>
    </div>

<?php else: ?>

    <?php // ====================== The trend, and before ====================== ?>
    <?= admin_card_open('Visits ' . analytics_admin_bucket_label($chart['bucket']), [
        'sub' => 'This period against the ' . $prev['days'] . ' days before it.',
        'actions' => an_admin_why('Each bar is the sum of an_daily_totals.sessions for the days it covers, '
            . 'from analytics_series(). A visit is one browsing session.', 'the visits chart'),
    ]) ?>
        <?= admin_bar_chart($chart['points'], '', 200) ?>
        <div class="ad-cluster" style="margin-top:14px;gap:22px;flex-wrap:wrap">
            <div><span class="ad-text-xs ad-muted">This period</span><br>
                <strong class="ad-strong"><?= e(number_format($summary['sessions'])) ?> visits</strong></div>
            <div><span class="ad-text-xs ad-muted"><?= e($prev['label']) ?></span><br>
                <strong class="ad-strong"><?= e(number_format($previous['sessions'])) ?> visits</strong></div>
            <div><span class="ad-text-xs ad-muted">Change</span><br>
                <?= admin_delta_badge(admin_delta((float) $summary['sessions'], (float) $previous['sessions'])) ?></div>
        </div>
    <?= admin_card_close('<span class="ad-text-xs ad-muted">' . e(analytics_admin_freshness($quality)) . '</span>') ?>

    <div class="ad-grid ad-grid--2" style="margin-top:18px">

        <?php // ==================== 2. Where they came from ==================== ?>
        <?= admin_card_open('Where visits came from', [
            'sub' => 'Top channels for this period.',
            'actions' => an_admin_why('analytics_breakdown() over the channel column of an_daily_traffic. '
                . 'The channel is worked out from the referrer and any campaign tags on arrival.', 'channels'),
        ]) ?>
            <?php if ($channels === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No channel recorded.</p>
            <?php else: ?>
                <?php // The bar is drawn against the BIGGEST channel, so the
                      // top one fills the row; the percentage beside it is of
                      // the whole period. See an_admin_share() for why the
                      // breakdown's own `share` is not used. ?>
                <?php $widest = (float) $channels[0]['sessions']; ?>
                <?php foreach ($channels as $row): ?>
                    <?= admin_progress_row(
                        $row['label'],
                        (float) $row['sessions'],
                        $widest,
                        number_format($row['sessions']) . ' visits - '
                            . number_format(an_pct($row['sessions'], $summary['sessions']), 1) . '% of all',
                        analytics_admin_url('acquisition', $state)
                    ) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?= admin_card_close('<a class="ad-btn ad-btn--sm" href="'
            . e(analytics_admin_url('acquisition', $state)) . '">Every source</a>') ?>

        <?= admin_card_open('Devices', [
            'sub' => 'How visitors reached the shop.',
            'actions' => an_admin_why('analytics_breakdown() over the device column of an_daily_traffic, '
                . 'classified from the user agent at collection time.', 'devices'),
        ]) ?>
            <?php if ($devices === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No device recorded.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Device</th>
                            <th scope="col" class="ad-table__num">Visits</th>
                            <th scope="col" class="ad-table__num">Of all visits</th>
                            <th scope="col" class="ad-table__num">Bought</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($devices as $row): ?>
                            <tr>
                                <td><?= e($row['label']) ?></td>
                                <td class="ad-table__num"><?= e(number_format($row['sessions'])) ?></td>
                                <td class="ad-table__num"><?= an_admin_share($row['sessions'], $summary['sessions']) ?></td>
                                <td class="ad-table__num">
                                    <?= an_admin_rate($row['conversion_rate'], $row['sessions']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close() ?>
    </div>

    <div class="ad-grid ad-grid--2" style="margin-top:18px">

        <?php // ====================== 3. What they looked at ==================== ?>
        <?= admin_card_open('Most-seen pages', [
            'sub' => 'Top five by page views.',
            'actions' => an_admin_why('analytics_top_pages() over an_daily_page. A bounce rate is measured '
                . 'against visits that STARTED on the page, not against all its views.', 'top pages'),
        ]) ?>
            <?php if ($pages === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No page views recorded.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Page</th>
                            <th scope="col" class="ad-table__num">Views</th>
                            <th scope="col" class="ad-table__num">Left here</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($pages as $row): ?>
                            <tr>
                                <td><span class="ad-mono"><?= admin_trunc($row['path'], 48, true) ?></span></td>
                                <td class="ad-table__num"><?= e(number_format($row['views'])) ?></td>
                                <td class="ad-table__num"><?= an_admin_rate($row['exit_rate'], $row['views'], 'views') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close('<a class="ad-btn ad-btn--sm" href="'
            . e(analytics_admin_url('content', $state)) . '">Every page</a>') ?>

        <?= admin_card_open('Most-seen products', [
            'sub' => 'Top five by product views.',
            'actions' => an_admin_why('analytics_top_products() over an_daily_product. Views come from the '
                . 'browser and cart adds from the server, so a blocked tracker flatters the cart rate.',
                'top products'),
        ]) ?>
            <?php if ($products === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No product views recorded.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Product</th>
                            <th scope="col" class="ad-table__num">Views</th>
                            <th scope="col" class="ad-table__num">To cart</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($products as $row): ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td class="ad-table__num"><?= e(number_format($row['views'])) ?></td>
                                <td class="ad-table__num"><?= an_admin_rate($row['cart_rate'], $row['views'], 'views') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close('<a class="ad-btn ad-btn--sm" href="'
            . e(analytics_admin_url('content', $state)) . '">Every product</a>') ?>
    </div>

    <?php // ========================== 4. Did they buy ========================= ?>
    <div class="ad-grid ad-grid--2" style="margin-top:18px">
        <?= admin_card_open('From looking to paying', [
            'sub' => 'Each step counts visits, not clicks.',
            'actions' => an_admin_why('analytics_funnel(). The first step needs the visitor\'s browser to '
                . 'report a product view; the other three are written by the server as they happen, '
                . 'so a blocked tracker makes this funnel look better than it is.', 'the funnel'),
        ]) ?>
            <?php $funnelTop = max(1, (int) $funnel['steps'][0]['sessions']); ?>
            <?php foreach ($funnel['steps'] as $i => $step): ?>
                <?= admin_progress_row(
                    $step['label'],
                    (float) $step['sessions'],
                    (float) $funnelTop,
                    number_format($step['sessions']) . ($i === 0 ? '' : ' - kept '
                        . number_format($step['of_prev'], 1) . '%')
                ) ?>
            <?php endforeach; ?>
            <?php if ($summary['books_available'] && $funnel['orders'] > $funnel['attr_orders']): ?>
                <p class="ad-text-xs ad-muted" style="margin:12px 0 0">
                    <?= e(number_format($funnel['orders'] - $funnel['attr_orders'])) ?> more orders were placed
                    with no visit attached.
                </p>
            <?php endif; ?>
        <?= admin_card_close() ?>

        <?= admin_card_open('Carts left behind', [
            'sub' => 'Added something, never paid.',
            'actions' => an_admin_why('analytics_cart_abandonment(). The rates are historical, from the daily '
                . 'totals; the live basket counts are read from the carts table as it stands right now.',
                'cart abandonment'),
        ]) ?>
            <?= admin_kv([
                ['Abandoned after adding', an_admin_rate($carts['rate'], $carts['cart_sessions'], 'visits with a cart'),
                    ['hint' => number_format($carts['abandoned']) . ' of ' . number_format($carts['cart_sessions'])
                        . ' visits']],
                ['Abandoned at checkout', an_admin_rate($carts['checkout_rate'], $carts['checkout_sessions'],
                    'visits that reached checkout')],
                ['Baskets holding stock now', '<strong class="ad-strong">' . e(number_format($carts['live_carts']))
                    . '</strong>', ['hint' => number_format($carts['live_units']) . ' items, '
                        . number_format($carts['live_stale_carts']) . ' untouched for a day']],
            ]) ?>
        <?= admin_card_close() ?>
    </div>

    <div class="ad-grid ad-grid--2" style="margin-top:18px">

        <?php // ============ 5. What they searched for and did not find ========= ?>
        <?= admin_card_open('Searches', [
            'sub' => 'What shoppers typed, and what came back empty.',
            'actions' => an_admin_why('analytics_top_searches() reads search_logs, not a rollup, so it is only '
                . 'as deep as the search-log retention window on Security > Settings allows.', 'searches'),
        ]) ?>
            <?php // The search log has no channel or device on it, so a filtered
                  // range cannot narrow this table. Saying so beats letting the
                  // operator read it as filtered when it is not. ?>
            <?php if ($state['filtered']): ?>
                <p class="ad-text-xs ad-muted" style="margin:0 0 10px">Not narrowed by your filter.</p>
            <?php endif; ?>
            <?php if ($searches === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">Nobody searched in this period.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Term</th>
                            <th scope="col" class="ad-table__num">Searches</th>
                            <th scope="col" class="ad-table__num">Found nothing</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($searches as $row): ?>
                            <?php $none = (int) $row['no_results']; ?>
                            <tr>
                                <td><?= e((string) $row['term']) ?></td>
                                <td class="ad-table__num"><?= e(number_format((int) $row['searches'])) ?></td>
                                <td class="ad-table__num">
                                    <?php if ($none > 0): ?>
                                        <strong class="ad-strong"><?= e(number_format($none)) ?></strong>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close() ?>

        <?php // ========================= 6. Is it fast ======================== ?>
        <?= admin_card_open('Speed, as visitors felt it', [
            'sub' => 'Core Web Vitals from real visits.',
            'actions' => an_admin_why('analytics_vitals() over an_daily_vitals. The good/poor counts are exact '
                . 'for any range; the typical figure is a sample-weighted average of daily 75th '
                . 'percentiles, because a week\'s percentile cannot be recovered from seven daily ones.',
                'web vitals'),
        ]) ?>
            <?php if ($vitals === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No speed samples in this period.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Metric</th>
                            <th scope="col" class="ad-table__num">Typical</th>
                            <th scope="col" class="ad-table__num">Good</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($vitals as $vital): ?>
                            <tr>
                                <td><?= e($vital['name']) ?></td>
                                <td class="ad-table__num">
                                    <?= e($vital['unit'] === 'score'
                                        ? number_format($vital['p75_typical'] / 1000, 3)
                                        : number_format($vital['p75_typical']) . ' ms') ?>
                                </td>
                                <td class="ad-table__num"><?= an_admin_rate($vital['good_pct'], $vital['samples'], 'samples') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close('<a class="ad-btn ad-btn--sm" href="'
            . e(analytics_admin_url('performance', $state)) . '">Speed detail</a>') ?>
    </div>

    <div class="ad-card" style="margin-top:18px">
        <div class="ad-card__body">
            <?= analytics_admin_notes($quality, $state) ?>
        </div>
    </div>

<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
