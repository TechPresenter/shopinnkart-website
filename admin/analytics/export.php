<?php
/**
 * ShopInnKart Admin - Analytics CSV export.
 *
 * One endpoint for every table the four analytics screens show, told what to
 * write by ?what=. It reads the SAME metrics.php functions the screens read,
 * with the same range, the same filters and the same per-screen controls, so a
 * figure in the file and the figure on the screen are one query with one
 * result. Nothing here counts anything: there is no SQL in this file at all,
 * which is the only way to guarantee the CSV and the dashboard cannot drift.
 * The day they can disagree is the day neither is trusted.
 *
 * WHAT ?what= ACCEPTS
 *   index | acquisition | content | performance   one screen's tables, in the
 *                                                order that screen shows them
 *   all                                           every table
 *   <table key>                                   one table on its own
 * The catalogue and the bundles live in _shared.php, because the menu that
 * builds these links and this file that reads them must agree about them.
 *
 * FOUR THINGS THIS FILE IS CAREFUL ABOUT
 *
 * 1. THE FILTERS. analytics_admin_state() is the screens' own range and filter
 *    reader, so the export cannot interpret ?r= or ?device= differently from
 *    the page the button was on. The two controls that live on a single screen
 *    - content.php's product sort and performance.php's vitals split - are not
 *    part of that state, so analytics_admin_extras() carries them, and the
 *    header block prints whichever were applied. An export that quietly
 *    dropped one would look exactly like the screen and not be it.
 *
 * 2. THE THIRTY-VISIT FLOOR. an_admin_rate() prints a dash on screen when a
 *    percentage stands on fewer than ANALYTICS_MIN_BASE visits, which is right
 *    for a dashboard and wrong for a file somebody is about to analyse. So the
 *    CSV keeps the figure, carries the numerator and the denominator as their
 *    own columns so it can be recomputed, and adds a column saying the screen
 *    withheld it. Hiding a number in a spreadsheet helps nobody; not saying it
 *    was hidden on screen is how two people compare notes and disagree.
 *
 * 3. A ZERO THAT MEANS "NOT MEASURABLE" IS LEFT BLANK. With a filter on,
 *    analytics_summary() and analytics_series() return 0 for visitors and units
 *    because a filtered cube cannot answer those without double counting - the
 *    screens print a dash. A 0 in a CSV is a measurement. Those cells are
 *    written empty and the table's note line says why.
 *
 * 4. IT STREAMS. The rows are a Generator handed to stream_csv(), so one table
 *    at a time is in memory and the whole document never is. Measured on ten
 *    full tables, twenty-three of them cost 156 KB more than one; buffered into
 *    an array first it was 1.5 MB. Each metric call is bounded by metrics.php's
 *    own ceilings (ANALYTICS_EXPORT_ROWS), which the export asks for in full
 *    rather than the five or fifteen rows the card had space for.
 *
 *    Two things this does NOT buy, so nobody optimises against a false belief.
 *    stream_csv() does not flush(), so the bytes still reach the browser in one
 *    go - the saving is memory, not time to first byte. And the high-water mark
 *    of the whole request is set by metrics.php materialising its own result
 *    array, which this file must not duplicate and cannot avoid; what streaming
 *    prevents is holding twenty-three of those at once.
 *
 * PERMISSION. analytics.view is enough to read a dashboard. This endpoint hands
 * over every page URL and every search term a shopper typed as a file that
 * leaves the building, so it also requires analytics.export - checked here, not
 * inherited - and records the download in the activity log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('analytics.view');

require_once ADMIN_PATH . '/includes/rbac.php';
require_once __DIR__ . '/_shared.php';

// ---------------------------------------------------------------------------
//  The second permission
//
//  admin_deny() records an rbac.denied security event before it renders the
//  403, so an attempt to pull the search log out of a view-only role is on file
//  rather than merely refused.
// ---------------------------------------------------------------------------
if (!admin_can('analytics.export')) {
    admin_deny(
        'Downloading analytics needs the analytics.export permission, not just analytics.view.',
        ['needs' => 'analytics.export', 'what' => (string) ($_GET['what'] ?? '')]
    );
}

// ---------------------------------------------------------------------------
//  What to write
// ---------------------------------------------------------------------------
$what      = trim((string) ($_GET['what'] ?? 'index'));
$selection = analytics_export_selection($what);

if ($selection['tables'] === []) {
    // A ?what= nobody offers is a hand-edited or stale link, not a filter being
    // dropped. Refusing and saying so beats sending a different file than the
    // one that was asked for.
    flash('error', 'That export does not exist. Use the Export button on an analytics screen.');
    redirect(admin_url('analytics/index.php'));
}

$state   = analytics_admin_state();
$range   = $state['range'];
$extras  = analytics_admin_extras();
$tables  = $selection['tables'];
$quality = analytics_quality($range);

// ===========================================================================
//  Cell formatting
//
//  A spreadsheet has to be able to add these up, so money and rates are plain
//  numbers with a dot and no grouping - the rupee sign and the Indian grouping
//  belong on a screen, not in a column somebody is about to SUM().
// ===========================================================================

/** Money as a number, never a formatted string. */
function an_x_money($value): string
{
    return number_format((float) $value, 2, '.', '');
}

/** A percentage exactly as metrics.php computed it, to one decimal. */
function an_x_rate($value): string
{
    return number_format((float) $value, 1, '.', '');
}

/** A count, or an empty cell when the figure was never measurable. */
function an_x_int(?int $value): string
{
    return $value === null ? '' : (string) $value;
}

/**
 * Did the screen print this rate, or a dash?
 *
 * The same test an_admin_rate() applies, including the base-of-zero case: the
 * screen shows a dash for that too, so the column says so.
 */
function an_x_suppressed(int $base): string
{
    return $base >= ANALYTICS_MIN_BASE ? 'no' : 'yes';
}

/** One label/value row of the header block. */
function an_x_meta(string $label, string $value): array
{
    return [$label, $value];
}

// ===========================================================================
//  The header block
//
//  So that this file, found on a desktop in six months, still says what it is:
//  the store, the selection, the period, every filter, the generation time, and
//  metrics.php's own caveats about how the numbers were collected - printed
//  verbatim, never paraphrased into something weaker. They come from
//  analytics_quality(), which is also what the screens print, so when the store
//  configures a location source the note in this file changes with it.
// ===========================================================================
function an_x_preamble(array $ctx): Generator
{
    $state   = $ctx['state'];
    $range   = $ctx['range'];
    $extras  = $ctx['extras'];
    $tables  = $ctx['tables'];
    $quality = $ctx['quality'];

    yield ['ShopInnKart analytics export'];
    yield an_x_meta('Store', (string) setting('store_name', SITE_NAME));
    yield an_x_meta('Exported by', (string) ($ctx['admin']['name'] ?? 'an administrator'));
    yield an_x_meta('Generated', date('Y-m-d H:i:s') . ' ' . date_default_timezone_get());
    yield an_x_meta('Contents', $ctx['label']);
    yield an_x_meta('Tables in this file', (string) count($tables));

    yield [];
    yield ['Period'];
    yield an_x_meta('Preset', $range['preset'] === 'custom' ? 'Custom range' : (AN_RANGES[$range['preset']] ?? 'Custom'));
    yield an_x_meta('From', $range['from']);
    yield an_x_meta('To', $range['to']);
    yield an_x_meta('Days', (string) $range['days']);

    yield [];
    yield ['Filters'];

    if ($state['filters'] === []) {
        yield an_x_meta('Traffic filter', 'None - every visit in the period');
    } else {
        // The CLEANED filters, which is what actually reached the queries: a
        // filter metrics.php dropped must not be listed here as applied.
        foreach ($state['filters'] as $key => $value) {
            // analytics_clean_filters() calls the source filter source_id;
            // an_dim_label() calls that dimension source.
            $dim = (string) $key === 'source_id' ? 'source' : (string) $key;
            yield [
                'Traffic filter',
                str_replace('_', ' ', (string) $key),
                an_dim_label($dim, (string) $value),
            ];
        }
        yield an_x_meta('Note', 'With a traffic filter on, visitors, units and the order book cannot be '
            . 'split without double counting, so those cells are left empty rather than written as zero.');
    }

    if (in_array('products', $tables, true)) {
        yield an_x_meta('Products sorted by', $extras['sort']);
    }

    if (in_array('vitals', $tables, true)) {
        yield an_x_meta('Vitals device', $extras['vdev'] === null
            ? 'All devices' : ucfirst(an_label('device', $extras['vdev'])));
        yield an_x_meta('Vitals page type', $extras['ptype'] === null
            ? 'All page types' : (AN_PAGE_TYPES[$extras['ptype']] ?? (string) $extras['ptype']));
    }

    if (in_array('searches', $tables, true)) {
        yield an_x_meta('Note', 'The Searches table is not narrowed by a traffic filter: the search log '
            . 'carries no channel or device.');
    }

    yield [];
    yield ['How to read the columns'];
    yield an_x_meta('Money', 'A plain number, ' . (string) setting('currency_symbol', CURRENCY_SYMBOL)
        . ' (' . (string) setting('currency_code', CURRENCY) . '), two decimals, no grouping.');
    yield an_x_meta('Rates', 'A percentage to one decimal, as the screen computes it.');
    yield an_x_meta('On-screen rate floor', 'The screens print a dash instead of a percentage standing on '
        . 'fewer than ' . ANALYTICS_MIN_BASE . ' visits. This file keeps the figure, carries its numerator '
        . 'and denominator as columns so it can be recomputed, and flags it in a '
        . '"suppressed_on_screen" column.');
    yield an_x_meta('Empty cell', 'Not measurable, which is not the same as zero. The table note says why.');
    yield an_x_meta('Rows per table', 'Up to ' . ANALYTICS_EXPORT_ROWS['breakdown'] . ' for a breakdown, '
        . ANALYTICS_EXPORT_ROWS['pages'] . ' pages, ' . ANALYTICS_EXPORT_ROWS['products'] . ' products, '
        . ANALYTICS_EXPORT_ROWS['searches'] . ' searches - more than the cards had room for.');

    yield [];
    yield ['How these numbers are counted'];
    foreach ($quality['notes'] as $note) {
        yield an_x_meta('Note', (string) $note);
    }
    yield an_x_meta('Totalled through', analytics_admin_freshness($quality));
}

// ===========================================================================
//  One table per function
//
//  Each returns ['label', 'note', 'columns', rows] and each row list is
//  bounded, so the Generator below holds one table at a time.
// ===========================================================================

/** Headline totals - the stat tiles on Overview, Acquisition and Pages. */
function an_x_summary(array $range): array
{
    $s     = analytics_summary($range);
    $books = (bool) $s['books_available'];
    $exact = (bool) $s['visitors_exact'];
    $vis   = (int) $s['sessions'];
    $flag  = an_x_suppressed($vis);

    $rows = [
        ['visits', (string) $s['sessions'], '', '', ''],
        ['visitors', $exact ? (string) $s['visitors'] : '', '', '',
            $exact ? '' : 'not measurable with a filter on'],
        ['new_visitors', $exact ? (string) $s['new_visitors'] : '', '', '', ''],
        ['pageviews', (string) $s['pageviews'], '', '', ''],
        ['pages_per_visit', number_format((float) $s['pages_per_session'], 2, '.', ''),
            (string) $s['pageviews'], (string) $s['sessions'], ''],
        ['engaged_visits', (string) $s['engaged_sessions'], '', '', ''],
        ['engagement_rate_pct', an_x_rate($s['engagement_rate']),
            (string) $s['engaged_sessions'], (string) $s['sessions'], $flag],
        ['bounces', (string) $s['bounces'], '', '', ''],
        ['bounce_rate_pct', an_x_rate($s['bounce_rate']),
            (string) $s['bounces'], (string) $s['sessions'], $flag],
        ['avg_engaged_seconds_per_visit', number_format((float) $s['avg_engagement_s'], 1, '.', ''),
            (string) round((int) $s['engaged_ms'] / 1000), (string) $s['sessions'], ''],
        ['product_view_visits', (string) $s['view_sessions'], '', '', ''],
        ['cart_visits', (string) $s['cart_sessions'], '', '', ''],
        ['checkout_visits', (string) $s['checkout_sessions'], '', '', ''],
        ['purchase_visits', (string) $s['purchase_sessions'], '', '', ''],
        ['conversion_rate_pct', an_x_rate($s['conversion_rate']),
            (string) $s['purchase_sessions'], (string) $s['sessions'], $flag],
        ['known_visitor_type_visits', (string) $s['known_type'], '', '', ''],
        ['known_visitor_type_pct', an_x_rate($s['known_type_pct']),
            (string) $s['known_type'], (string) $s['sessions'], $flag],
        // The books. Absent under a filter, because an order has no channel of
        // its own - only the visit that was tied to it does.
        ['orders_in_your_books', $books ? (string) $s['orders'] : '', '', '',
            $books ? '' : 'not measurable with a filter on'],
        ['revenue_in_your_books', $books ? an_x_money($s['revenue']) : '', '', '',
            $books ? '' : 'not measurable with a filter on'],
        ['units_in_your_books', $books ? (string) $s['units'] : '', '', '',
            $books ? '' : 'not measurable with a filter on'],
        ['average_order_value', $books ? an_x_money($s['aov']) : '',
            $books ? an_x_money($s['revenue']) : '', $books ? (string) $s['orders'] : '', ''],
        ['attributed_orders', (string) $s['attr_orders'], '', '', ''],
        ['attributed_revenue', an_x_money($s['attr_revenue']), '', '', ''],
        // No floor on screen either: the tile prints both sides of it, and a
        // store with nine orders still needs to know four were tracked.
        ['attribution_rate_pct', $books ? an_x_rate($s['attribution_rate']) : '',
            $books ? (string) $s['attr_orders'] : '', $books ? (string) $s['orders'] : '',
            $books ? 'no - this rate has no floor on screen' : ''],
    ];

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['summary'],
        'note'    => 'analytics_summary(). Visitors are visitor-days, not people: somebody who came on '
            . 'Monday and Thursday counts twice.',
        'columns' => ['metric', 'value', 'numerator', 'denominator', 'suppressed_on_screen'],
        'rows'    => $rows,
    ];
}

/** The daily series behind every chart. */
function an_x_series(array $range): array
{
    $filtered = !empty($range['filters']);

    $rows = [];
    foreach (analytics_series($range) as $r) {
        $rows[] = [
            (string) $r['day'],
            (string) $r['sessions'],
            // Zero here means "a filtered cube cannot answer this", not none.
            $filtered ? '' : (string) $r['visitors'],
            (string) $r['pageviews'],
            (string) $r['engaged_sessions'],
            (string) $r['purchase_sessions'],
            (string) $r['orders'],
            an_x_money($r['revenue']),
            $filtered ? '' : (string) $r['units'],
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['series'],
        'note'    => 'analytics_series(). A day the store saw no traffic is present as a row of zeroes.'
            . ($filtered
                ? ' A filter is on: visitors and units are left empty because a filtered cube cannot give '
                  . 'them, and orders are the ATTRIBUTED orders rather than your books.'
                : ' Orders and revenue are your books, not the tracker.'),
        'columns' => ['day', 'visits', 'visitors', 'pageviews', 'engaged_visits', 'purchase_visits',
                      'orders', 'revenue', 'units'],
        'rows'    => $rows,
    ];
}

/** One breakdown dimension, exactly as the screens table it. */
function an_x_breakdown(string $dim, array $range): array
{
    $rows      = analytics_breakdown($dim, $range, ANALYTICS_EXPORT_ROWS['breakdown']);
    $allVisits = (int) analytics_summary($range)['sessions'];

    $out = [];
    foreach ($rows as $r) {
        $visits = (int) $r['sessions'];
        $out[] = [
            (string) $r['key'],
            (string) $r['label'],
            (string) $visits,
            // The screens' own column: a share of every visit in the period,
            // through metrics.php's percentage helper. See an_admin_share().
            an_x_rate(an_pct($visits, $allVisits)),
            (string) $allVisits,
            // analytics_breakdown()'s own share, whose denominator is the
            // visits that carried a value for THIS dimension. Both are true
            // and they are different questions, so both are named.
            an_x_rate($r['share']),
            (string) $r['pageviews'],
            (string) $r['engaged_sessions'],
            an_x_rate($r['engagement_rate']),
            (string) $r['purchase_sessions'],
            an_x_rate($r['conversion_rate']),
            // null, not 0: an_daily_dim has no order count to give.
            an_x_int($r['orders'] === null ? null : (int) $r['orders']),
            an_x_money($r['revenue']),
            // Both rates on a breakdown row divide by this row's visits, so
            // one flag covers them.
            an_x_suppressed($visits),
        ];
    }

    $dimensional = isset(AN_DIMS[$dim]);

    return [
        'label'   => ANALYTICS_EXPORT_TABLES[$dim] ?? $dim,
        'note'    => 'analytics_breakdown(' . $dim . '). Sorted by visits, up to '
            . ANALYTICS_EXPORT_ROWS['breakdown'] . ' rows.'
            . ($dimensional
                ? ' A visit that carried no ' . $dim . ' has no row here, so the shares of all visits do '
                  . 'not add to 100%, and orders cannot be counted for this dimension at all.'
                : ''),
        'columns' => ['key', 'label', 'visits', 'share_of_all_visits_pct', 'all_visits_in_period',
                      'share_within_dimension_pct', 'pageviews', 'engaged_visits', 'engagement_rate_pct',
                      'purchase_visits', 'conversion_rate_pct', 'orders', 'revenue',
                      'rates_suppressed_on_screen'],
        'rows'    => $out,
    ];
}

/** Top pages. */
function an_x_pages(array $range): array
{
    $out = [];
    foreach (analytics_top_pages($range, ANALYTICS_EXPORT_ROWS['pages']) as $r) {
        $views   = (int) $r['views'];
        $entries = (int) $r['entries'];
        $out[] = [
            (string) $r['path'],
            (string) $r['page_type'],
            (string) $r['entity_id'],
            (string) $views,
            (string) $r['view_sessions'],
            (string) $entries,
            (string) $r['exits'],
            an_x_rate($r['bounce_rate']),
            (string) $entries,
            an_x_suppressed($entries),
            an_x_rate($r['exit_rate']),
            (string) $views,
            an_x_suppressed($views),
            number_format((float) $r['avg_time_s'], 1, '.', ''),
            // Null, not zero: "nobody's scroll was measured" and "nobody
            // scrolled" are different answers.
            an_x_int($r['avg_scroll'] === null ? null : (int) $r['avg_scroll']),
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['pages'],
        'note'    => 'analytics_top_pages(), sorted by views, up to ' . ANALYTICS_EXPORT_ROWS['pages']
            . ' rows. Bounce rate is over the visits that STARTED on the page, exit rate over all its '
            . 'views, which is why each carries its own base and its own flag. Not narrowed by a traffic '
            . 'filter: an_daily_page has no channel or device.',
        'columns' => ['path', 'page_type', 'entity_id', 'views', 'view_visits', 'entries', 'exits',
                      'bounce_rate_pct', 'bounce_rate_base_entries', 'bounce_rate_suppressed_on_screen',
                      'exit_rate_pct', 'exit_rate_base_views', 'exit_rate_suppressed_on_screen',
                      'avg_seconds_per_view', 'avg_scroll_pct'],
        'rows'    => $out,
    ];
}

/** Top products, in the order the screen was sorting them. */
function an_x_products(array $range, string $sort): array
{
    $out = [];
    foreach (analytics_top_products($range, ANALYTICS_EXPORT_ROWS['products'], $sort) as $r) {
        $views = (int) $r['views'];
        $out[] = [
            (string) $r['product_id'],
            (string) $r['name'],
            (string) $r['slug'],
            $r['deleted'] ? 'yes' : 'no',
            (string) $views,
            (string) $r['view_sessions'],
            (string) $r['cart_adds'],
            (string) $r['cart_qty'],
            (string) $r['wishlist_adds'],
            (string) $r['units'],
            an_x_money($r['revenue']),
            an_x_rate($r['cart_rate']),
            (string) $views,
            an_x_suppressed($views),
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['products'],
        'note'    => 'analytics_top_products(), sorted by ' . $sort . ', up to '
            . ANALYTICS_EXPORT_ROWS['products'] . ' rows. Views come from the browser and cart adds from '
            . 'the server, so a blocked tracker flatters the cart rate and never the reverse. Units and '
            . 'revenue are order-derived. Not narrowed by a traffic filter.',
        'columns' => ['product_id', 'product', 'slug', 'deleted', 'views', 'view_visits', 'cart_adds',
                      'cart_quantity', 'wishlist_adds', 'units_sold', 'revenue', 'cart_rate_pct',
                      'cart_rate_base_views', 'cart_rate_suppressed_on_screen'],
        'rows'    => $out,
    ];
}

/** What shoppers searched for. */
function an_x_searches(array $range): array
{
    $out = [];
    foreach (analytics_top_searches($range, ANALYTICS_EXPORT_ROWS['searches']) as $r) {
        $out[] = [
            (string) $r['term'],
            (string) (int) $r['searches'],
            (string) (int) $r['no_results'],
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['searches'],
        'note'    => 'analytics_top_searches() reads search_logs, not a rollup, so this table is only as '
            . 'deep as the search-log retention window on Security > Settings allows. It is NOT narrowed '
            . 'by a traffic filter, because a search has no channel or device of its own.',
        'columns' => ['term', 'searches', 'searches_with_no_results'],
        'rows'    => $out,
    ];
}

/** Every recorded event. */
function an_x_events(array $range): array
{
    $out = [];
    foreach (analytics_events($range) as $event) {
        $out[] = [
            (string) $event['name'],
            (string) $event['events'],
            (string) $event['sessions'],
            (string) $event['qty'],
            an_x_money($event['value']),
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['events'],
        'note'    => 'analytics_events(). Visits is lower than times whenever somebody did the same thing '
            . 'twice, and lower again by the events that had no visit to belong to. Anything involving '
            . 'money is written by the server, never reported by the browser.',
        'columns' => ['event', 'times', 'visits', 'quantity', 'value'],
        'rows'    => $out,
    ];
}

/** Core Web Vitals, with the screen's own device and page-type split. */
function an_x_vitals(array $range, ?int $device, ?int $pageType): array
{
    $out = [];
    foreach (analytics_vitals($range, $device, $pageType) as $v) {
        $samples = (int) $v['samples'];
        // CLS is stored as score x 1000. Written in the unit the `unit` column
        // names, which is the unit the screen shows.
        $value = static fn(int $raw): string => $v['unit'] === 'score'
            ? number_format($raw / 1000, 3, '.', '')
            : (string) $raw;

        $out[] = [
            (string) $v['name'],
            (string) $v['unit'],
            (string) $samples,
            (string) $v['good'],
            (string) $v['ni'],
            (string) $v['poor'],
            an_x_rate($v['good_pct']),
            an_x_rate($v['poor_pct']),
            an_x_suppressed($samples),
            $value((int) $v['p75_typical']),
            $value((int) $v['p75_worst']),
            $v['exact'] ? 'yes' : 'no',
            $value((int) $v['good_under']),
            $value((int) $v['poor_over']),
        ];
    }

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['vitals'],
        'note'    => 'analytics_vitals(). The good, needs-work and poor counts are EXACT for any range '
            . 'because counts add up. p75_typical is not: a week\'s 75th percentile cannot be recovered '
            . 'from seven daily ones, so it is a sample-weighted average of the daily figures, and '
            . 'p75_is_exact says whether the range is the single day where it is the real percentile. '
            . 'p75_worst is the worst single day.',
        'columns' => ['metric', 'unit', 'samples', 'good', 'needs_work', 'poor', 'good_pct', 'poor_pct',
                      'rates_suppressed_on_screen', 'p75_typical', 'p75_worst', 'p75_is_exact',
                      'good_under', 'poor_over'],
        'rows'    => $out,
    ];
}

/** Product view to purchase, in visits - plus the books beside it. */
function an_x_funnel(array $range): array
{
    $funnel = analytics_funnel($range);
    $books  = (bool) analytics_summary($range)['books_available'];

    $out = [];
    foreach ($funnel['steps'] as $step) {
        $out[] = [
            (string) $step['label'],
            (string) $step['sessions'],
            'visits',
            an_x_rate($step['of_first']),
            an_x_rate($step['of_prev']),
            (string) $step['drop'],
            an_x_rate($step['drop_rate']),
        ];
    }

    // The four steps count VISITS the tracker tied together; these two count
    // orders. Mixing them in one column without saying which is which is how
    // the gap between them stops being visible, so counts_what names it.
    //
    // Their step-percentage columns are left EMPTY rather than filled with the
    // attribution rate, which is not a share of a funnel step and would read as
    // one sitting in that column. The rate is recomputable from these two rows,
    // and the Headline totals table carries it with its own numerator and
    // denominator.
    if ($books) {
        $out[] = ['Orders in your books', (string) $funnel['orders'], 'orders', '', '', '', ''];
    }
    $out[] = ['Orders tied to a visit', (string) $funnel['attr_orders'], 'orders', '', '', '', ''];

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['funnel'],
        'note'    => 'analytics_funnel(). ' . $funnel['note'] . ' So a blocked tracker shrinks the FIRST '
            . 'step only, which makes the funnel look better than it is rather than worse. The last rows '
            . 'count orders, not visits: the gap between them is the share of buyers analytics never saw.'
            . ($books ? '' : ' Your books are hidden while a filter is on.'),
        'columns' => ['step', 'count', 'counts_what', 'of_first_step_pct', 'of_previous_step_pct',
                      'dropped_from_previous', 'drop_rate_pct'],
        'rows'    => $out,
    ];
}

/** Carts left behind, historical and live. */
function an_x_carts(array $range): array
{
    $c = analytics_cart_abandonment($range);

    return [
        'label'   => ANALYTICS_EXPORT_TABLES['carts'],
        'note'    => 'analytics_cart_abandonment(). The two rates are historical, from the daily totals '
            . 'for this period. The live basket counts are read from the carts table as it stands RIGHT '
            . 'NOW and have nothing to do with the period above.',
        'columns' => ['measure', 'value', 'numerator', 'denominator', 'suppressed_on_screen'],
        'rows'    => [
            ['cart_visits', (string) $c['cart_sessions'], '', '', ''],
            ['checkout_visits', (string) $c['checkout_sessions'], '', '', ''],
            ['purchase_visits', (string) $c['purchase_sessions'], '', '', ''],
            ['abandoned_after_adding', (string) $c['abandoned'], '', '', ''],
            ['abandoned_after_adding_pct', an_x_rate($c['rate']),
                (string) $c['abandoned'], (string) $c['cart_sessions'],
                an_x_suppressed((int) $c['cart_sessions'])],
            ['abandoned_at_checkout_pct', an_x_rate($c['checkout_rate']),
                (string) max(0, (int) $c['checkout_sessions'] - (int) $c['purchase_sessions']),
                (string) $c['checkout_sessions'], an_x_suppressed((int) $c['checkout_sessions'])],
            ['live_baskets_holding_stock_now', (string) $c['live_carts'], '', '', ''],
            ['live_items_in_those_baskets', (string) $c['live_units'], '', '', ''],
            ['live_baskets_untouched_for_a_day', (string) $c['live_stale_carts'], '', '', ''],
        ],
    ];
}

/**
 * One table by its key.
 *
 * A breakdown dimension is anything metrics.php knows as one, which is why
 * fifteen of the catalogue's entries need no case of their own here.
 */
function an_x_table(string $table, array $ctx): ?array
{
    $range = $ctx['range'];

    switch ($table) {
        case 'summary':  return an_x_summary($range);
        case 'series':   return an_x_series($range);
        case 'pages':    return an_x_pages($range);
        case 'products': return an_x_products($range, $ctx['extras']['sort']);
        case 'searches': return an_x_searches($range);
        case 'events':   return an_x_events($range);
        case 'vitals':   return an_x_vitals($range, $ctx['extras']['vdev'], $ctx['extras']['ptype']);
        case 'funnel':   return an_x_funnel($range);
        case 'carts':    return an_x_carts($range);
    }

    if (isset(AN_CUBE_DIMS[$table]) || isset(AN_DIMS[$table])) {
        return an_x_breakdown($table, $range);
    }

    return null;
}

// ===========================================================================
//  The document
//
//  A Generator, so stream_csv() writes each table as it is built and the whole
//  file is never assembled. Each table is labelled, noted and given its own
//  column header, because these tables have genuinely different shapes and
//  flattening them into one would lose most of the columns.
// ===========================================================================
$document = (static function (array $tables, array $ctx): Generator {
    yield from an_x_preamble($ctx);

    foreach ($tables as $key) {
        $table = an_x_table($key, $ctx);

        if ($table === null) {
            continue;
        }

        yield [];
        yield ['## ' . $table['label']];
        yield ['Source', $table['note']];
        yield $table['columns'];

        foreach ($table['rows'] as $row) {
            yield $row;
        }

        if ($table['rows'] === []) {
            yield ['(no rows in this period)'];
        }

        // The table has been written; let its rows go before building the next.
        unset($table);
    }
})($tables, [
    'state'   => $state,
    'range'   => $range,
    'extras'  => $extras,
    'tables'  => $tables,
    'quality' => $quality,
    'label'   => $selection['label'],
    'admin'   => $admin,
]);

// ---------------------------------------------------------------------------
//  The audit line
//
//  Written BEFORE the stream, because stream_csv() exits. Somebody carrying off
//  every page URL and every search term a shopper typed is worth a row in the
//  activity log, and naming the search table explicitly is what makes that row
//  answer the question it will be asked.
// ---------------------------------------------------------------------------
log_activity(
    'analytics.exported',
    'analytics',
    null,
    'Exported ' . $selection['label'] . ' (' . count($tables) . ' table(s)) for '
        . $range['from'] . ' to ' . $range['to']
        . ($state['filtered'] ? ', filtered by ' . implode(', ', array_keys($state['filters'])) : '')
        . (in_array('searches', $tables, true) ? ', including search terms' : '')
        . (in_array('pages', $tables, true) ? ', including page URLs' : '')
);

stream_csv(
    'analytics-' . $what . '-' . $range['from'] . '-to-' . $range['to'] . '.csv',
    // No column header here: the header BLOCK comes first, and each table
    // carries its own columns.
    [],
    $document
);
