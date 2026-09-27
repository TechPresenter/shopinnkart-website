<?php
/**
 * ShopInnKart Admin - Analytics workbench shell.
 *
 * Four screens share one range picker and one tab strip. Every number they
 * print comes out of includes/analytics/metrics.php; this file adds NO way of
 * counting anything. It owns exactly three things:
 *
 *   1. the range state - which period is selected, how it survives a reload,
 *      and the equally long period before it that every comparison uses;
 *   2. the shared chrome - tabs, the range bar, breadcrumbs;
 *   3. the honesty helpers - an_admin_rate() refuses to print a confident
 *      percentage over a tiny base, an_admin_why() says where a figure came
 *      from, and analytics_admin_notes() prints what analytics_quality()
 *      already worked out.
 *
 * WHY THE MINIMUM-BASE RULE IS HERE AND NOT IN METRICS.PHP
 * -------------------------------------------------------
 * analytics_quality() answers "what must the reader be told about how this was
 * collected". It does not answer "is this particular percentage standing on
 * enough visits to mean anything", because that is a property of one figure on
 * one screen rather than of the data set. So the rule lives here, at the point
 * of printing, and it is a rule about DISPLAY only: the number is still
 * computed by metrics.php, it is simply not shown as a confident percentage
 * until its denominator can carry one.
 *
 * ON THE LAZY ROLLUP. Every metric function calls analytics_rollup_lazy(),
 * which may total up to three missing days on the first request of a
 * fifteen-minute window. That is the metric layer's own design, not something
 * these screens add, and it is the reason a store with no cron still has
 * yesterday's figures. Settings > Analytics deliberately does NOT trigger it,
 * because that is the screen you use to diagnose the rollup itself.
 */

declare(strict_types=1);

// Include-only: the parent page has already run authentication and the
// permission check. The .htaccess rule refuses /_*.php outright; this is the
// backstop for a host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once INCLUDES_PATH . '/analytics/metrics.php';

/** The tab strip, in the order the questions get asked. */
const ANALYTICS_SCREENS = [
    'index'       => ['label' => 'Overview',         'file' => 'index.php'],
    'acquisition' => ['label' => 'Acquisition',      'file' => 'acquisition.php'],
    'content'     => ['label' => 'Pages & products', 'file' => 'content.php'],
    'performance' => ['label' => 'Speed',            'file' => 'performance.php'],
];

/** Below this many visits a percentage is noise, so it is not printed as one. */
const ANALYTICS_MIN_BASE = 30;

/** The filters analytics_clean_filters() accepts, as this section names them. */
const ANALYTICS_FILTER_KEYS = ['channel' => 'channel', 'device' => 'device', 'vtype' => 'visitor_type'];

// ===========================================================================
//  Range state
// ===========================================================================

/**
 * The selected period, the one before it, and the query string that reproduces
 * both.
 *
 * The range lives in the URL, so a reload, a bookmark and a link pasted to
 * somebody else all show the same period. It is ALSO remembered in the
 * session, which is what makes it survive moving between these four tabs and
 * coming back tomorrow - arriving with no parameters at all picks up where the
 * operator left off instead of silently resetting to the default.
 *
 * Only the range is remembered. A filter deliberately is not, because a
 * filtered dashboard that looks like an unfiltered one is how an owner
 * concludes the store has lost nine tenths of its traffic.
 *
 * @return array{range:array, prev:array, preset:string, query:array, filters:array, filtered:bool}
 */
function analytics_admin_state(): array
{
    $presets = array_keys(AN_RANGES);
    $preset  = (string) ($_GET['r'] ?? '');
    $from    = trim((string) ($_GET['from'] ?? ''));
    $to      = trim((string) ($_GET['to'] ?? ''));

    if ($preset === 'custom' && ($from !== '' || $to !== '')) {
        $remember = ['r' => 'custom', 'from' => $from, 'to' => $to];
    } elseif (in_array($preset, $presets, true)) {
        $remember = ['r' => $preset];
        $from = $to = '';
    } else {
        // Nothing usable in the URL: the last choice, then the default.
        $saved    = $_SESSION['an_admin_range'] ?? null;
        $remember = is_array($saved) ? $saved : ['r' => '28d'];
        $preset   = (string) ($remember['r'] ?? '28d');
        $from     = (string) ($remember['from'] ?? '');
        $to       = (string) ($remember['to'] ?? '');

        if ($preset !== 'custom' && !in_array($preset, $presets, true)) {
            $preset   = '28d';
            $remember = ['r' => '28d'];
            $from = $to = '';
        }
    }

    $_SESSION['an_admin_range'] = $remember;

    $filters = [];
    foreach (ANALYTICS_FILTER_KEYS as $param => $key) {
        $value = trim((string) ($_GET[$param] ?? ''));
        if ($value !== '' && ctype_digit($value)) {
            $filters[$key] = (int) $value;
        }
    }

    $range = analytics_range($preset, $from !== '' ? $from : null, $to !== '' ? $to : null, $filters);

    // The cleaned set, not the requested one: a filter metrics.php dropped must
    // not go on colouring this screen's "filtered" wording.
    $clean = $range['filters'];

    $query = $remember;
    foreach (ANALYTICS_FILTER_KEYS as $param => $key) {
        if (isset($clean[$key])) {
            $query[$param] = (string) $clean[$key];
        }
    }

    return [
        'range'    => $range,
        'prev'     => analytics_previous_range($range),
        'preset'   => $range['preset'],
        'query'    => $query,
        'filters'  => $clean,
        'filtered' => $clean !== [],
    ];
}

/** A URL inside this section that keeps the current range. */
function analytics_admin_url(string $screen = 'index', array $state = [], array $extra = []): string
{
    $file  = ANALYTICS_SCREENS[$screen]['file'] ?? 'index.php';
    $query = array_merge((array) ($state['query'] ?? []), $extra);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return admin_url('analytics/' . $file) . ($query === [] ? '' : '?' . http_build_query($query));
}

/** Breadcrumbs every screen in this section shares. */
function analytics_admin_breadcrumbs(string $label, array $state = []): array
{
    return [
        ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
        ['label' => 'Analytics', 'url' => analytics_admin_url('index', $state)],
        ['label' => $label],
    ];
}

/** "Last 28 days - 30 Aug to 26 Sep", for the page subtitle. */
function analytics_admin_subtitle(array $state): string
{
    $range = $state['range'];
    $label = $range['preset'] === 'custom' ? 'Custom' : (AN_RANGES[$range['preset']] ?? 'Custom');

    return $label . ' - ' . format_date($range['from']) . ' to ' . format_date($range['to'])
        . ($state['filtered'] ? ' - filtered' : '');
}

// ===========================================================================
//  Chrome
// ===========================================================================

/** The tab strip. */
function analytics_admin_tabs(string $current, array $state): string
{
    $out = '<div class="ad-tabs">';

    foreach (ANALYTICS_SCREENS as $key => $screen) {
        $out .= '<a class="ad-tab' . ($key === $current ? ' is-active' : '') . '"'
            . ' href="' . e(analytics_admin_url($key, $state)) . '">' . e($screen['label']) . '</a>';
    }

    // Linked rather than tabbed: it writes settings, these four only read.
    if (admin_can('settings.view')) {
        $out .= '<a class="ad-tab" href="' . e(admin_url('settings/analytics.php')) . '">Settings '
            . icon('external', 'w-3.5 h-3.5') . '</a>';
    }

    return $out . '</div>';
}

/**
 * The range picker and the traffic filters, as a GET form.
 *
 * A GET form and not a POST: the state it produces IS the URL, which is what
 * makes a reload, a bookmark and the browser's back button all behave.
 */
function analytics_admin_range_bar(string $screen, array $state, bool $withFilters = true): string
{
    $range  = $state['range'];
    $custom = $range['preset'] === 'custom';
    $action = admin_url('analytics/' . (ANALYTICS_SCREENS[$screen]['file'] ?? 'index.php'));

    $out = '<form class="ad-filters" method="get" action="' . e($action) . '">'
        . '<label class="sik-label" for="anRange" style="margin:0">Period</label>'
        . '<select class="sik-select" id="anRange" name="r">'
        . admin_options(AN_RANGES + ['custom' => 'Custom range'], $range['preset'])
        . '</select>'
        . '<input class="sik-input" type="date" name="from" aria-label="From date"'
        . ' value="' . e_attr($custom ? $range['from'] : '') . '">'
        . '<input class="sik-input" type="date" name="to" aria-label="To date"'
        . ' value="' . e_attr($custom ? $range['to'] : '') . '">';

    if ($withFilters) {
        $channels = ['' => 'All channels'];
        foreach (AN_CHANNEL_IDS as $name => $id) {
            $channels[(string) $id] = $name;
        }

        $devices = ['' => 'All devices'];
        foreach (AN_DEVICES as $id => $name) {
            $devices[(string) $id] = ucfirst($name);
        }

        $out .= '<select class="sik-select" name="channel" aria-label="Channel">'
            . admin_options($channels, (string) ($state['filters']['channel'] ?? '')) . '</select>'
            . '<select class="sik-select" name="device" aria-label="Device">'
            . admin_options($devices, (string) ($state['filters']['device'] ?? '')) . '</select>';
    }

    $out .= '<button type="submit" class="ad-btn ad-btn--primary ad-btn--sm">Apply</button>';

    if ($state['filtered']) {
        $keep = ['r' => $range['preset']];
        if ($custom) {
            $keep['from'] = $range['from'];
            $keep['to']   = $range['to'];
        }
        $out .= '<a class="ad-btn ad-btn--sm" href="'
            . e(analytics_admin_url($screen, ['query' => $keep])) . '">Clear filters</a>';
    }

    return $out . '</form>';
}

// ===========================================================================
//  Honesty
// ===========================================================================

/**
 * A stat tile carrying its change against the previous period.
 *
 * admin_stat_card() has no slot for a delta, and Reports solves that with
 * report_stat_card() in admin/reports/_shared.php. That file is not this
 * section's to require - it boots a whole report shell - so the same two
 * helpers it composes, admin_delta() and admin_delta_badge(), are composed
 * here instead. Same markup, same classes, same look in both themes; nothing
 * new is styled.
 */
function an_admin_tile(
    string $label,
    string $value,
    string $iconName,
    string $tone,
    float $current,
    float $previous,
    string $sub
): string {
    return '<div class="ad-stat">'
        . '<span class="ad-stat__icon ad-stat__icon--' . e_attr($tone) . '">' . icon($iconName, 'w-5 h-5') . '</span>'
        . '<span class="ad-stat__body">'
        . '<span class="ad-stat__value">' . e($value) . '</span>'
        . '<span class="ad-stat__label">' . e($label) . '</span>'
        . '<span class="ad-stat__sub">' . admin_delta_badge(admin_delta($current, $previous)) . ' ' . e($sub) . '</span>'
        . '</span></div>';
}

/**
 * A round (i) saying where a figure came from.
 *
 * Attribution is not a nice-to-have on an analytics screen: an operator who
 * cannot find out what a number counted cannot tell a real fall in traffic
 * from a tracker that stopped reporting. The text names the source in the
 * store's own terms, and it sits in a popover rather than on the page, so it
 * costs the reader nothing until asked.
 */
function an_admin_why(string $text, string $field = ''): string
{
    return admin_help($text, ['field' => $field !== '' ? $field : 'this figure']);
}

/**
 * A percentage, or a dash when its denominator is too small to carry one.
 *
 * 1 purchase in 4 visits is 25%, and printing 25% invites an owner to plan
 * around a figure that one more visit would move to 20%. The base is named in
 * the tooltip either way, so nothing is merely withheld.
 */
function an_admin_rate(float $percent, int $base, string $what = 'visits', int $min = ANALYTICS_MIN_BASE): string
{
    if ($base <= 0) {
        return '<span class="ad-muted"' . admin_tip('Nothing to divide by yet.') . '>&mdash;</span>';
    }

    if ($base < $min) {
        return '<span class="ad-muted"' . admin_tip(number_format($base) . ' ' . $what
            . ' is too few to quote a rate; ' . $min . ' is the floor this screen uses.') . '>&mdash;</span>';
    }

    return '<span' . admin_tip('Out of ' . number_format($base) . ' ' . $what . '.') . '>'
        . e(number_format($percent, 1)) . '%</span>';
}

/** The same rule for a stat tile, which takes plain text rather than markup. */
function an_admin_rate_text(float $percent, int $base, int $min = ANALYTICS_MIN_BASE): string
{
    return $base >= $min ? number_format($percent, 1) . '%' : '—';
}

/** A count, or a dash when the reading cannot be defended. */
function an_admin_num(?int $value): string
{
    return $value === null ? '—' : number_format($value);
}

/**
 * One breakdown row's share of ALL visits in the period.
 *
 * NOT analytics_breakdown()'s own `share`, and the difference is not cosmetic.
 * That function fetches LIMIT+1 rows, sums the visits of those rows, and
 * divides each row by that sum - so when a dimension has more distinct values
 * than the limit, the denominator is not the period's traffic and every share
 * is overstated. Measured on 30 campaigns of 100 visits each with a limit of 5:
 * it reports 16.7% per row where the truth is 3.3%, and the five rows add up to
 * 83.5% of a period they are 16.7% of. That is a defect in
 * includes/analytics/metrics.php, which is not this section's file to change;
 * it is reported rather than patched around silently.
 *
 * So the share is taken here against analytics_summary()['sessions'] - the same
 * function's own figure for the period, through the same function's own
 * percentage helper. Nothing is counted a second way; a different, defensible
 * denominator is chosen, and the column is labelled for what it is.
 *
 * Dimensions held in an_daily_dim (medium, campaign, referrer, browser, OS,
 * city, landing, exit) have no row for a visit that carried no such value, so
 * their rows will not add to 100%. "Of all visits" is true either way, which is
 * exactly why it is the wording.
 */
function an_admin_share(int $sessions, int $totalSessions): string
{
    if ($totalSessions <= 0) {
        return '<span class="ad-muted">&mdash;</span>';
    }

    return '<span' . admin_tip('Of ' . number_format($totalSessions) . ' visits in this period.') . '>'
        . e(number_format(an_pct($sessions, $totalSessions), 1)) . '%</span>';
}

/**
 * What analytics_quality() says, as a <details> at the foot of a card.
 *
 * The notes are metrics.php's own wording and are printed verbatim: this
 * screen must not paraphrase a caveat into something weaker. They go in a
 * <details> because they are the same paragraphs on every visit - depth the
 * reader opens once, not a wall between them and the numbers.
 */
function analytics_admin_notes(array $quality, array $state): string
{
    $out = '<details class="ad-text-sm"><summary>How these numbers are counted</summary><ul>';

    foreach ($quality['notes'] as $note) {
        $out .= '<li>' . e($note) . '</li>';
    }

    if ($state['filtered']) {
        $out .= '<li>' . e('A filter is applied, so visitors and the order book are hidden: neither can be '
            . 'split by channel or device without double counting.') . '</li>';
    }

    $out .= '<li>' . e('Percentages are not printed over fewer than ' . ANALYTICS_MIN_BASE
        . ' visits; those read as a dash whose tooltip gives the count.') . '</li>';

    $out .= '<li>' . e('These four screens only read. Settings > Analytics owns the collector, '
        . 'the retention window and the rollup.') . '</li>';

    return $out . '</ul></details>';
}

/** Freshness, as one short line for a card footer. */
function analytics_admin_freshness(array $quality): string
{
    if ($quality['fresh_through'] === null) {
        return 'Nothing totalled yet.';
    }

    $line = 'Totalled through ' . format_date($quality['fresh_through']) . '.';

    if ($quality['partial_days'] !== []) {
        $line .= ' ' . count($quality['partial_days']) . ' later day(s) still counting.';
    }

    return $line;
}

/**
 * The state a new store is actually in: switched on, nothing collected yet.
 *
 * A dashboard of zeroes with no explanation reads as broken, and the owner's
 * next move is a support ticket about working software. So an empty range says
 * which of the four reasons it is - off, on but silent, on with data outside
 * this period, or filtered down to nothing - and what to do about that one.
 *
 * Returns '' when there is data, so a caller can simply print it.
 */
function analytics_admin_empty(array $summary, array $quality, array $state): string
{
    if ($summary['sessions'] > 0 || $summary['pageviews'] > 0) {
        return '';
    }

    $settings = admin_can('settings.view') ? admin_url('settings/analytics.php') : null;

    if (!$quality['counting']) {
        return admin_empty(
            'Counting is switched off',
            'Nothing is being recorded. Turn it on in Settings > Analytics and this fills in from tomorrow.',
            $settings !== null ? 'Open Analytics settings' : null,
            $settings,
            'activity'
        );
    }

    if ($state['filtered']) {
        return admin_empty(
            'No visits match this filter',
            'This period may have traffic, but none through the channel or device you picked.',
            null,
            null,
            'funnel'
        );
    }

    if ($quality['raw_from'] !== null) {
        return admin_empty(
            'Visits recorded, none in this period',
            'The oldest visit still held is ' . format_date($quality['raw_from']) . '. Widen the period.',
            null,
            null,
            'calendar'
        );
    }

    return admin_empty(
        'Nothing counted yet',
        'The collector is on and has seen no visits. Open the shop in another browser, then reload.',
        $settings !== null ? 'Check the collector' : null,
        $settings,
        'activity'
    );
}

// ===========================================================================
//  Series shaping
// ===========================================================================

/**
 * analytics_series() rows as chart points, grouped so the bars stay readable.
 *
 * A 365-day range is 365 bars in a 700px card, which is a texture rather than
 * a chart. Long ranges are therefore SUMMED into weeks or months - the same
 * daily rows added up, not a second query - and the bucket is named in the card
 * so nobody reads a weekly bar as a daily one.
 *
 * @param  array  $series analytics_series() output
 * @param  string $metric a key of those rows
 * @return array{points:array<int,array{label:string,value:float}>, bucket:string}
 */
function analytics_admin_points(array $series, string $metric): array
{
    $count = count($series);
    $step  = $count > 190 ? 'month' : ($count > 45 ? 'week' : 'day');

    if ($step === 'day') {
        $points = [];
        foreach ($series as $row) {
            $points[] = [
                'label' => date($count > 14 ? 'j M' : 'D j', (int) strtotime((string) $row['day'])),
                'value' => (float) ($row[$metric] ?? 0),
            ];
        }
        return ['points' => $points, 'bucket' => 'day'];
    }

    $buckets = [];
    foreach ($series as $row) {
        $time  = (int) strtotime((string) $row['day']);
        $key   = $step === 'month' ? date('Y-m', $time) : date('o-W', $time);
        // The week's own Monday, so a bar is labelled by a date that exists.
        $label = $step === 'month' ? date('M Y', $time) : date('j M', (int) strtotime('monday this week', $time));

        if (!isset($buckets[$key])) {
            $buckets[$key] = ['label' => $label, 'value' => 0.0];
        }
        $buckets[$key]['value'] += (float) ($row[$metric] ?? 0);
    }

    return ['points' => array_values($buckets), 'bucket' => $step];
}

/** "by week", for a chart card's title. */
function analytics_admin_bucket_label(string $bucket): string
{
    return ['day' => 'by day', 'week' => 'by week', 'month' => 'by month'][$bucket] ?? 'by day';
}
