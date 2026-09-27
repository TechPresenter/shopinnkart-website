<?php
/**
 * ShopInnKart Admin - Analytics: pages, products and searches.
 *
 * What visitors actually looked at, and what they asked for and did not get.
 *
 * Three tables, each already sorted and already labelled by metrics.php:
 * analytics_top_pages(), analytics_top_products() and analytics_top_searches().
 * The product table's sort is the one thing this screen passes through, because
 * analytics_top_products() takes it as an argument - "most viewed" and "most
 * sold" are different questions and the answer to one is not the answer to the
 * other.
 *
 * The searches table is the one report here that does NOT come from a rollup.
 * analytics_top_searches() reads search_logs on purpose: a search term is free
 * text a shopper typed and it has its own retention window on Security >
 * Settings, so copying it into a table kept for ever would quietly cancel that
 * window. This report is therefore only as deep as that window allows, and it
 * says so rather than pretending to be complete.
 *
 * READ-ONLY: no POST handler, nothing written.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('analytics.view');

require_once __DIR__ . '/_shared.php';

$state = analytics_admin_state();
$range = $state['range'];

/** The sorts analytics_top_products() accepts, with the label for each. */
const ANALYTICS_PRODUCT_SORTS = [
    'views'     => 'Most viewed',
    'cart_adds' => 'Most added to cart',
    'units'     => 'Most units sold',
    'revenue'   => 'Most revenue',
];

$sort = admin_filter('sort', array_keys(ANALYTICS_PRODUCT_SORTS), 'views');

$summary  = analytics_summary($range);
$previous = analytics_summary($state['prev']);
$quality  = analytics_quality($range);

$emptyState = analytics_admin_empty($summary, $quality, $state);
$hasData    = $emptyState === '';

if ($hasData) {
    $pages    = analytics_top_pages($range, 30);
    $products = analytics_top_products($range, 30, $sort);
    $searches = analytics_top_searches($range, 30);
    $events   = analytics_events($range);
}

$pageTitle    = 'Analytics - Pages & products';
$pageSubtitle = analytics_admin_subtitle($state);
$breadcrumbs  = analytics_admin_breadcrumbs('Pages & products', $state);

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card" style="margin-bottom:18px">
    <?= analytics_admin_tabs('content', $state) ?>
    <?= analytics_admin_range_bar('content', $state) ?>
</div>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= an_admin_tile('Pages seen', number_format($summary['pageviews']), 'eye', 'violet',
        (float) $summary['pageviews'], (float) $previous['pageviews'],
        'vs ' . number_format($previous['pageviews']) . ' before') ?>
    <?= admin_stat_card('Pages per visit', $summary['sessions'] > 0
        ? number_format($summary['pages_per_session'], 2) : '—', 'list', 'blue',
        'Across ' . number_format($summary['sessions']) . ' visits') ?>
    <?= admin_stat_card('Saw a product', an_admin_rate_text(
        an_pct($summary['view_sessions'], $summary['sessions']), $summary['sessions']), 'package', 'primary',
        number_format($summary['view_sessions']) . ' visits') ?>
    <?= admin_stat_card('Left on the first page',
        an_admin_rate_text($summary['bounce_rate'], $summary['sessions']), 'external', 'amber',
        number_format($summary['bounces']) . ' visits never engaged') ?>
</div>

<?php if (!$hasData): ?>

    <?= admin_card('', $emptyState, ['flush' => true]) ?>

<?php else: ?>

    <?= admin_card_open('Pages', [
        'sub' => 'Every page with a view in this period, most seen first.',
        'actions' => an_admin_why('analytics_top_pages() over an_daily_page, up to thirty rows. Bounce rate is '
            . 'measured against visits that STARTED on the page; exit rate against all of its views. '
            . 'Scroll depth is blank where no visitor\'s scroll was measured, which is not the same '
            . 'as nobody scrolling.', 'the page table'),
    ]) ?>
        <?php if ($pages === []): ?>
            <p class="ad-muted ad-text-sm" style="margin:0">No page views recorded.</p>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table ad-table--stack">
                    <thead><tr>
                        <th scope="col">Page</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="ad-table__num">Views</th>
                        <th scope="col" class="ad-table__num">Visits</th>
                        <th scope="col" class="ad-table__num">Started here</th>
                        <th scope="col" class="ad-table__num">Bounced</th>
                        <th scope="col" class="ad-table__num">Left here</th>
                        <th scope="col" class="ad-table__num">Time</th>
                        <th scope="col" class="ad-table__num">Scroll</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pages as $row): ?>
                        <tr>
                            <td><span class="ad-mono"><?= admin_trunc($row['path'], 60, true) ?></span></td>
                            <td><?= e($row['page_type']) ?></td>
                            <td class="ad-table__num"><?= e(number_format($row['views'])) ?></td>
                            <td class="ad-table__num"><?= e(number_format($row['view_sessions'])) ?></td>
                            <td class="ad-table__num"><?= e(number_format($row['entries'])) ?></td>
                            <td class="ad-table__num">
                                <?= an_admin_rate($row['bounce_rate'], $row['entries'], 'visits started here') ?>
                            </td>
                            <td class="ad-table__num"><?= an_admin_rate($row['exit_rate'], $row['views'], 'views') ?></td>
                            <td class="ad-table__num"><?= e(number_format($row['avg_time_s'], 1)) ?>s</td>
                            <td class="ad-table__num">
                                <?php if ($row['avg_scroll'] === null): ?>
                                    <span class="ad-muted"<?= admin_tip('No visitor\'s scroll depth was measured here.') ?>>&mdash;</span>
                                <?php else: ?>
                                    <?= e((string) $row['avg_scroll']) ?>%
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?= admin_card_close(count($pages) >= 30
        ? '<span class="ad-text-xs ad-muted">Top 30 shown.</span>' : '') ?>

    <?php
    $sortLinks = '';
    foreach (ANALYTICS_PRODUCT_SORTS as $key => $label) {
        $sortLinks .= '<a class="ad-btn ad-btn--sm' . ($key === $sort ? ' ad-btn--primary' : '') . '" href="'
            . e(analytics_admin_url('content', $state, ['sort' => $key])) . '">' . e($label) . '</a>';
    }
    ?>
    <div style="margin-top:18px">
        <?= admin_card_open('Products', [
            'sub' => 'Views, cart adds and sales for each product.',
            'actions' => '<div class="ad-btngroup">' . $sortLinks . '</div>',
        ]) ?>
            <?php if ($products === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No product views or sales recorded.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Product</th>
                            <th scope="col" class="ad-table__num">Views</th>
                            <th scope="col" class="ad-table__num">To cart</th>
                            <th scope="col" class="ad-table__num">Cart rate</th>
                            <th scope="col" class="ad-table__num">Wishlisted</th>
                            <th scope="col" class="ad-table__num">Units sold</th>
                            <th scope="col" class="ad-table__num">Revenue</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($products as $row): ?>
                            <tr>
                                <td>
                                    <?php if ($row['deleted'] || !admin_can('products.edit')): ?>
                                        <?= admin_trunc($row['name'], 60) ?>
                                    <?php else: ?>
                                        <a href="<?= e(admin_url('products/edit.php?id=' . (int) $row['product_id'])) ?>"><?= admin_trunc($row['name'], 60) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= e(number_format($row['views'])) ?></td>
                                <td class="ad-table__num"><?= e(number_format($row['cart_adds'])) ?></td>
                                <td class="ad-table__num"><?= an_admin_rate($row['cart_rate'], $row['views'], 'views') ?></td>
                                <td class="ad-table__num"><?= e(number_format($row['wishlist_adds'])) ?></td>
                                <td class="ad-table__num"><?= e(number_format($row['units'])) ?></td>
                                <td class="ad-table__num"><?= e(money($row['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close('<details class="ad-text-sm"><summary>Where these product figures come from</summary>'
            . '<ul><li>' . e('Views and cart adds are analytics_top_products() over an_daily_product. '
                . 'A view is reported by the browser; a cart add is written by the server, so an ad '
                . 'blocker makes the cart rate look better than it is, never worse.') . '</li>'
            . '<li>' . e('Units and revenue are derived from your order items, the same definition '
                . 'Reports > Products uses. A cancelled or refunded order is excluded there and here.')
            . '</li><li>' . e('A product renamed after the sale shows its current name: analytics '
                . 'never stores the name, only the id.') . '</li></ul></details>') ?>
    </div>

    <div class="ad-grid ad-grid--2" style="margin-top:18px">
        <?= admin_card_open('Searches', [
            'sub' => 'What shoppers typed, and what came back empty.',
            'actions' => an_admin_why('analytics_top_searches() reads search_logs directly, not a rollup, so it '
                . 'goes back only as far as the search-log retention window on Security > Settings. '
                . 'A term with searches but no results is a product gap or a spelling the catalogue '
                . 'does not match.', 'searches'),
        ]) ?>
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
                            <?php
                            $none    = (int) $row['no_results'];
                            $total   = (int) $row['searches'];
                            $allNone = $none > 0 && $none === $total;
                            ?>
                            <tr>
                                <td><?= admin_trunc((string) $row['term'], 48) ?></td>
                                <td class="ad-table__num"><?= e(number_format($total)) ?></td>
                                <td class="ad-table__num">
                                    <?php if ($none === 0): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php else: ?>
                                        <strong class="ad-strong"<?= $allNone
                                            ? admin_tip('Every search for this term came back empty.') : '' ?>>
                                            <?= e(number_format($none)) ?></strong>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close(count($searches) >= 30
            ? '<span class="ad-text-xs ad-muted">Top 30 shown.</span>' : '') ?>

        <?= admin_card_open('Everything else visitors did', [
            'sub' => 'Every recorded event in this period.',
            'actions' => an_admin_why('analytics_events() over an_daily_event. Visits is lower than events '
                . 'whenever somebody did the same thing twice, and lower again by the events that had '
                . 'no visit to belong to. Anything involving money is written by the server, never '
                . 'reported by the browser.', 'events'),
        ]) ?>
            <?php if ($events === []): ?>
                <p class="ad-muted ad-text-sm" style="margin:0">No events recorded.</p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table ad-table--stack">
                        <thead><tr>
                            <th scope="col">Event</th>
                            <th scope="col" class="ad-table__num">Times</th>
                            <th scope="col" class="ad-table__num">Visits</th>
                            <th scope="col" class="ad-table__num">Value</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= e(str_replace('_', ' ', $event['name'])) ?></td>
                                <td class="ad-table__num"><?= e(number_format($event['events'])) ?></td>
                                <td class="ad-table__num"><?= e(number_format($event['sessions'])) ?></td>
                                <td class="ad-table__num">
                                    <?= $event['value'] > 0
                                        ? e(money($event['value']))
                                        : '<span class="ad-muted">&mdash;</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close() ?>
    </div>

    <div class="ad-card" style="margin-top:18px">
        <div class="ad-card__body">
            <?= analytics_admin_notes($quality, $state) ?>
        </div>
    </div>

<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
