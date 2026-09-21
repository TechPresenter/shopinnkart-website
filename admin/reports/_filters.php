<?php
/**
 * ShopInnKart Admin - Report date-range filter.
 *
 * Every report screen and the CSV endpoint read their window through
 * report_filter_state(), so a link from one report to another keeps the range,
 * and an export always covers exactly what the screen showed.
 */

declare(strict_types=1);

// Included by admin pages only — there is nothing to run on its own.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Presets offered by the range picker. Every key is one admin_date_range() understands. */
const REPORT_DATE_PRESETS = [
    'today'      => 'Today',
    'yesterday'  => 'Yesterday',
    'last_7'     => 'Last 7 days',
    'last_30'    => 'Last 30 days',
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'this_year'  => 'This year',
    'custom'     => 'Custom range',
];

/** Ranges longer than this switch the charts from daily to monthly buckets. */
const REPORT_DAILY_MAX_DAYS = 62;

/**
 * Resolve the requested window plus the equivalent window immediately before
 * it, which is what every delta on the sales report compares against.
 */
function report_filter_state(): array
{
    $preset = admin_filter('range', array_keys(REPORT_DATE_PRESETS), 'last_30');

    [$from, $to] = admin_date_range(
        $preset,
        isset($_GET['from']) ? (string) $_GET['from'] : null,
        isset($_GET['to']) ? (string) $_GET['to'] : null
    );

    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;

    // The previous period is the same number of days ending the day before
    // this one starts, so "last 7 days" is compared against the 7 before it.
    $prevTo   = date('Y-m-d', (int) strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', (int) strtotime($prevTo . ' -' . ($days - 1) . ' days'));

    $query = ['range' => $preset];
    if ($preset === 'custom') {
        $query['from'] = $from;
        $query['to']   = $to;
    }

    return [
        'range'        => $preset,
        'from'         => $from,
        'to'           => $to,
        'from_dt'      => $from . ' 00:00:00',
        'to_dt'        => $to . ' 23:59:59',
        'prev_from'    => $prevFrom,
        'prev_to'      => $prevTo,
        'prev_from_dt' => $prevFrom . ' 00:00:00',
        'prev_to_dt'   => $prevTo . ' 23:59:59',
        'days'         => $days,
        'group'        => $days > REPORT_DAILY_MAX_DAYS ? 'month' : 'day',
        'label'        => $from === $to
            ? format_date($from, 'd M Y')
            : format_date($from, 'd M Y') . ' – ' . format_date($to, 'd M Y'),
        'prev_label'   => $prevFrom === $prevTo
            ? format_date($prevFrom, 'd M Y')
            : format_date($prevFrom, 'd M Y') . ' – ' . format_date($prevTo, 'd M Y'),
        'query'        => $query,
        'is_default'   => $preset === 'last_30',
    ];
}

/** Page subtitle every report shares: the window and how it is bucketed. */
function report_range_subtitle(array $filters): string
{
    return $filters['label'] . '  ·  ' . $filters['days'] . ' day' . ($filters['days'] === 1 ? '' : 's')
        . ', grouped by ' . $filters['group'] . '  ·  compared against ' . $filters['prev_label'];
}

/**
 * The shared filter bar: range presets, a custom from/to that reveals itself,
 * and the Export CSV button for whichever report is calling.
 */
function report_filter_bar(string $report, array $filters): string
{
    $action     = admin_url('reports/' . $report . '.php');
    $exportUrl  = admin_url('reports/export.php') . '?'
        . http_build_query(array_merge(['report' => $report], $filters['query']));
    $customFrom = (string) ($_GET['from'] ?? $filters['from']);
    $customTo   = (string) ($_GET['to'] ?? $filters['to']);

    $html = '<form class="ad-filters" method="get" action="' . e($action) . '">'
        . '<label class="sik-sr" for="reportRange">Date range</label>'
        . '<select class="sik-select" id="reportRange" name="range" data-range-preset>'
        . admin_options(REPORT_DATE_PRESETS, $filters['range'])
        . '</select>'

        . '<span data-range-custom style="display:flex;gap:8px;align-items:center"'
        . ($filters['range'] === 'custom' ? '' : ' hidden') . '>'
        . '<label class="sik-sr" for="reportFrom">From date</label>'
        . '<input class="sik-input" type="date" id="reportFrom" name="from" value="' . e($customFrom) . '">'
        . '<label class="sik-sr" for="reportTo">To date</label>'
        . '<input class="sik-input" type="date" id="reportTo" name="to" value="' . e($customTo) . '">'
        . '</span>'

        . '<button type="submit" class="ad-btn ad-btn--sm">' . icon('filter', 'w-4 h-4') . ' Apply</button>';

    if (!$filters['is_default']) {
        $html .= '<a class="ad-btn ad-btn--sm" href="' . e($action) . '">Reset</a>';
    }

    $html .= '<span style="flex:1"></span>'
        . '<span class="ad-muted" style="font-size:12.5px">' . e($filters['label']) . '</span>'
        . '<a class="ad-btn ad-btn--sm ad-btn--primary" href="' . e($exportUrl) . '">'
        . icon('download', 'w-4 h-4') . ' Export CSV</a>'
        . '</form>';

    return $html;
}
