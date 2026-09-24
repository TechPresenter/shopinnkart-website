<?php
/**
 * ShopInnKart - Analytics: the metric layer (phase B3).
 *
 * Every number an analytics screen shows comes from a function in this file,
 * and every function in this file reads a ROLLUP table. Nothing here scans a
 * raw an_sessions or an_pageviews row, which is what keeps a dashboard on a
 * shared host at a few milliseconds instead of a few seconds, and keeps it
 * there when the store is a year older.
 *
 * Three exceptions, each deliberate and each documented at its function:
 * analytics_cart_abandonment() reads `carts` for what is in a basket RIGHT
 * NOW, analytics_top_searches() reads `search_logs` because search terms are
 * not copied into a table that is kept for ever, and analytics_quality()
 * reads the cursors.
 *
 * WHAT EVERY CALLER MUST PASS ON
 * ------------------------------
 * The owner chose the anonymous mode, and the honest consequences travel with
 * the data instead of being left for a screen to remember:
 *
 *   - `visitors` is APPROXIMATE. The visitor key is a daily-salted hash of an
 *     anonymised address and the user agent, so a family or an office behind
 *     one router is one visitor, and the same person is a new visitor every
 *     morning. Summing daily visitors over a week is therefore a count of
 *     visitor-days, and it is also exactly what a distinct count over the raw
 *     rows would give, because the key genuinely does not survive the night.
 *   - NEW VS RETURNING is unknown for most sessions; `known_type_pct` says
 *     for what share it is known at all.
 *   - COUNTRY, REGION AND CITY are 'Unknown' until the owner turns on a geo
 *     source. They are not missing data, they were never collected.
 *   - Anything ATTRIBUTED to a visit undercounts: a shopper whose beacon was
 *     blocked still buys. `orders` and `revenue` are the store's books;
 *     `attr_orders` and `attr_revenue` are the part analytics could tie to a
 *     visit, and attribution_rate is the gap.
 *
 * analytics_quality() returns all of this as text, so a screen can print it
 * rather than invent its own wording.
 */

declare(strict_types=1);

require_once __DIR__ . '/rollup.php';

/** Date-range presets every analytics screen offers. */
const AN_RANGES = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    '7d'        => 'Last 7 days',
    '28d'       => 'Last 28 days',
    '30d'       => 'Last 30 days',
    '90d'       => 'Last 90 days',
    'mtd'       => 'This month',
    'lastmonth' => 'Last month',
    'ytd'       => 'This year',
];

/** dim id in an_daily_dim => the name a caller uses. */
const AN_DIMS = [
    'medium'   => 1,
    'campaign' => 2,
    'referrer' => 3,
    'browser'  => 4,
    'os'       => 5,
    'city'     => 6,
    'landing'  => 7,
    'exit'     => 8,
];

/** Dimensions that live in the cube instead, with the column that holds them. */
const AN_CUBE_DIMS = [
    'channel'      => 'channel',
    'source'       => 'source_id',
    'device'       => 'device',
    'country'      => 'country',
    'region'       => 'region',
    'visitor_type' => 'visitor_type',
];

// ===========================================================================
//  Ranges
// ===========================================================================

/**
 * Turn a preset (or a pair of dates) into a range every function here takes.
 *
 * @return array{from:string, to:string, days:int, preset:string, label:string, filters:array}
 */
function analytics_range(string $preset = '28d', ?string $from = null, ?string $to = null, array $filters = []): array
{
    $today = date('Y-m-d');

    switch ($preset) {
        case 'today':
            $from = $to = $today;
            break;
        case 'yesterday':
            $from = $to = date('Y-m-d', strtotime('-1 day'));
            break;
        case '7d':
        case '28d':
        case '30d':
        case '90d':
            // Ending YESTERDAY, not today. A part-day at the end of a
            // comparison makes every trend look like a collapse at 9 a.m.
            $n    = (int) rtrim($preset, 'd');
            $to   = date('Y-m-d', strtotime('-1 day'));
            $from = date('Y-m-d', strtotime('-' . $n . ' days'));
            break;
        case 'mtd':
            $from = date('Y-m-01');
            $to   = $today;
            break;
        case 'lastmonth':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'ytd':
            $from = date('Y-01-01');
            $to   = $today;
            break;
        case 'custom':
        default:
            $from = $from !== null && $from !== '' ? date('Y-m-d', strtotime($from)) : date('Y-m-d', strtotime('-28 days'));
            $to   = $to !== null && $to !== '' ? date('Y-m-d', strtotime($to)) : $today;
            $preset = 'custom';
            break;
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [
        'from'    => $from,
        'to'      => $to,
        'days'    => (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1,
        'preset'  => $preset,
        'label'   => AN_RANGES[$preset] ?? (format_date($from) . ' - ' . format_date($to)),
        'filters' => analytics_clean_filters($filters),
    ];
}

/** The equally long period immediately before this one, for "vs previous". */
function analytics_previous_range(array $range): array
{
    $days = max(1, (int) $range['days']);

    return [
        'from'    => date('Y-m-d', strtotime($range['from'] . ' -' . $days . ' days')),
        'to'      => date('Y-m-d', strtotime($range['from'] . ' -1 day')),
        'days'    => $days,
        'preset'  => 'custom',
        'label'   => 'Previous ' . $days . ' day' . ($days === 1 ? '' : 's'),
        'filters' => $range['filters'] ?? [],
    ];
}

/** Only the filters the cube can actually apply; anything else is dropped. */
function analytics_clean_filters(array $filters): array
{
    $clean = [];

    foreach (['channel', 'device', 'visitor_type'] as $key) {
        if (isset($filters[$key]) && $filters[$key] !== '') {
            $clean[$key] = (int) $filters[$key];
        }
    }
    if (isset($filters['source_id']) && $filters['source_id'] !== '') {
        $clean['source_id'] = (int) $filters['source_id'];
    }
    foreach (['country', 'region'] as $key) {
        if (isset($filters[$key]) && $filters[$key] !== '') {
            $clean[$key] = mb_substr((string) $filters[$key], 0, 8);
        }
    }

    return $clean;
}

/** WHERE fragment + params for a cube read. */
function an_cube_where(array $range, string $alias = ''): array
{
    $a   = $alias === '' ? '' : $alias . '.';
    $sql = $a . '`day` BETWEEN :from AND :to';
    $params = ['from' => $range['from'], 'to' => $range['to']];

    foreach (($range['filters'] ?? []) as $key => $value) {
        $sql .= ' AND ' . $a . '`' . $key . '` = :f_' . $key;
        $params['f_' . $key] = $value;
    }

    return [$sql, $params];
}

// ===========================================================================
//  The headline numbers
// ===========================================================================

/**
 * Sessions, visitors, engagement, the funnel and the money for a range.
 *
 * Unfiltered it reads an_daily_totals - one row per day, so a 90-day summary
 * is a 90-row range scan of a primary key. Filtered it reads the cube, and
 * says so: `visitors` cannot be recovered from a filtered cube, because the
 * cube's own visitor counts are distinct within a row and adding them
 * double-counts anyone who arrived twice by different routes. The screen
 * shows a dash rather than a number nobody can defend.
 *
 * @return array<string, mixed>
 */
function analytics_summary(array $range): array
{
    analytics_rollup_lazy();

    $filtered = !empty($range['filters']);

    if ($filtered) {
        [$where, $params] = an_cube_where($range);
        $row = Database::fetch(
            'SELECT COALESCE(SUM(`sessions`), 0)          AS sessions,
                    0                                     AS visitors,
                    COALESCE(SUM(`new_visitors`), 0)      AS new_visitors,
                    COALESCE(SUM(CASE WHEN `visitor_type` > 0 THEN `sessions` ELSE 0 END), 0) AS known_type,
                    COALESCE(SUM(`pageviews`), 0)         AS pageviews,
                    COALESCE(SUM(`engaged_sessions`), 0)  AS engaged_sessions,
                    COALESCE(SUM(`engaged_ms`), 0)        AS engaged_ms,
                    COALESCE(SUM(`view_sessions`), 0)     AS view_sessions,
                    COALESCE(SUM(`cart_sessions`), 0)     AS cart_sessions,
                    COALESCE(SUM(`checkout_sessions`), 0) AS checkout_sessions,
                    COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                    COALESCE(SUM(`orders`), 0)            AS attr_orders,
                    COALESCE(SUM(`revenue`), 0)           AS attr_revenue,
                    0 AS orders, 0 AS revenue, 0 AS units
               FROM `an_daily_traffic` WHERE ' . $where,
            $params
        ) ?? [];
    } else {
        $row = Database::fetch(
            'SELECT COALESCE(SUM(`sessions`), 0)          AS sessions,
                    COALESCE(SUM(`visitors`), 0)          AS visitors,
                    COALESCE(SUM(`new_visitors`), 0)      AS new_visitors,
                    COALESCE(SUM(`known_type`), 0)        AS known_type,
                    COALESCE(SUM(`pageviews`), 0)         AS pageviews,
                    COALESCE(SUM(`engaged_sessions`), 0)  AS engaged_sessions,
                    COALESCE(SUM(`engaged_ms`), 0)        AS engaged_ms,
                    COALESCE(SUM(`view_sessions`), 0)     AS view_sessions,
                    COALESCE(SUM(`cart_sessions`), 0)     AS cart_sessions,
                    COALESCE(SUM(`checkout_sessions`), 0) AS checkout_sessions,
                    COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                    COALESCE(SUM(`attr_orders`), 0)       AS attr_orders,
                    COALESCE(SUM(`attr_revenue`), 0)      AS attr_revenue,
                    COALESCE(SUM(`orders`), 0)            AS orders,
                    COALESCE(SUM(`revenue`), 0)           AS revenue,
                    COALESCE(SUM(`units`), 0)             AS units
               FROM `an_daily_totals` WHERE `day` BETWEEN :from AND :to',
            ['from' => $range['from'], 'to' => $range['to']]
        ) ?? [];
    }

    $sessions  = (int) ($row['sessions'] ?? 0);
    $engaged   = (int) ($row['engaged_sessions'] ?? 0);
    $purchases = (int) ($row['purchase_sessions'] ?? 0);
    $orders    = (int) ($row['orders'] ?? 0);
    $revenue   = round((float) ($row['revenue'] ?? 0), 2);
    $attrOrders = (int) ($row['attr_orders'] ?? 0);

    return [
        'sessions'          => $sessions,
        // Visitor-days, not people. See the file header.
        'visitors'          => (int) ($row['visitors'] ?? 0),
        'visitors_exact'    => !$filtered,
        'new_visitors'      => (int) ($row['new_visitors'] ?? 0),
        'known_type'        => (int) ($row['known_type'] ?? 0),
        'known_type_pct'    => an_pct((int) ($row['known_type'] ?? 0), $sessions),
        'pageviews'         => (int) ($row['pageviews'] ?? 0),
        'pages_per_session' => $sessions > 0 ? round((int) ($row['pageviews'] ?? 0) / $sessions, 2) : 0.0,
        'engaged_sessions'  => $engaged,
        'engagement_rate'   => an_pct($engaged, $sessions),
        'bounces'           => max(0, $sessions - $engaged),
        // GA4's definition, and the only one that matches the engagement rate
        // beside it: a bounce is a visit that was never engaged.
        'bounce_rate'       => an_pct(max(0, $sessions - $engaged), $sessions),
        'engaged_ms'        => (int) ($row['engaged_ms'] ?? 0),
        'avg_engagement_s'  => $sessions > 0 ? round(((int) ($row['engaged_ms'] ?? 0) / 1000) / $sessions, 1) : 0.0,
        'view_sessions'     => (int) ($row['view_sessions'] ?? 0),
        'cart_sessions'     => (int) ($row['cart_sessions'] ?? 0),
        'checkout_sessions' => (int) ($row['checkout_sessions'] ?? 0),
        'purchase_sessions' => $purchases,
        'conversion_rate'   => an_pct($purchases, $sessions),
        'attr_orders'       => $attrOrders,
        'attr_revenue'      => round((float) ($row['attr_revenue'] ?? 0), 2),
        // The store's own books. Absent on a filtered read, because an order
        // has no channel of its own - only the visit that analytics managed
        // to tie to it does.
        'orders'            => $orders,
        'revenue'           => $revenue,
        'units'             => (int) ($row['units'] ?? 0),
        'aov'               => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
        'attribution_rate'  => an_pct($attrOrders, $orders),
        'books_available'   => !$filtered,
    ];
}

/**
 * One row per day for the charts, with missing days filled in as zeroes.
 *
 * A day with no traffic has no rollup row - an absent row and a row of
 * zeroes say the same thing and not writing it keeps the table the size of
 * the days the store was open. A CHART cannot have that gap, though: a line
 * that skips Sunday draws Saturday straight into Monday and invents a trend.
 *
 * @return array<int, array<string, mixed>>
 */
function analytics_series(array $range): array
{
    analytics_rollup_lazy();

    if (!empty($range['filters'])) {
        [$where, $params] = an_cube_where($range);
        $rows = Database::fetchAll(
            'SELECT `day`,
                    COALESCE(SUM(`sessions`), 0)         AS sessions,
                    0                                    AS visitors,
                    COALESCE(SUM(`pageviews`), 0)        AS pageviews,
                    COALESCE(SUM(`engaged_sessions`), 0) AS engaged_sessions,
                    COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                    COALESCE(SUM(`orders`), 0)           AS orders,
                    COALESCE(SUM(`revenue`), 0)          AS revenue,
                    0 AS units
               FROM `an_daily_traffic` WHERE ' . $where . ' GROUP BY `day` ORDER BY `day`',
            $params
        );
    } else {
        $rows = Database::fetchAll(
            'SELECT `day`, `sessions`, `visitors`, `pageviews`, `engaged_sessions`,
                    `purchase_sessions`, `orders`, `revenue`, `units`
               FROM `an_daily_totals`
              WHERE `day` BETWEEN :from AND :to
              ORDER BY `day`',
            ['from' => $range['from'], 'to' => $range['to']]
        );
    }

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[(string) $r['day']] = $r;
    }

    $series = [];
    foreach (an_rollup_days_between($range['from'], $range['to']) as $day) {
        $r = $byDay[$day] ?? [];
        $series[] = [
            'day'               => $day,
            'sessions'          => (int) ($r['sessions'] ?? 0),
            'visitors'          => (int) ($r['visitors'] ?? 0),
            'pageviews'         => (int) ($r['pageviews'] ?? 0),
            'engaged_sessions'  => (int) ($r['engaged_sessions'] ?? 0),
            'purchase_sessions' => (int) ($r['purchase_sessions'] ?? 0),
            'orders'            => (int) ($r['orders'] ?? 0),
            'revenue'           => round((float) ($r['revenue'] ?? 0), 2),
            'units'             => (int) ($r['units'] ?? 0),
        ];
    }

    return $series;
}

// ===========================================================================
//  Breakdowns
// ===========================================================================

/**
 * Traffic split by one dimension: channel, source, device, country, region,
 * new-vs-returning, medium, campaign, referrer, browser, OS, city, landing
 * page or exit page.
 *
 * Rows come back already labelled, already sorted by sessions, and already
 * carrying their share of the range - so a screen prints them and does no
 * arithmetic of its own.
 *
 * @return array<int, array<string, mixed>>
 */
function analytics_breakdown(string $dim, array $range, int $limit = 10): array
{
    analytics_rollup_lazy();

    $limit = max(1, min(200, $limit));

    if (isset(AN_CUBE_DIMS[$dim])) {
        $column = AN_CUBE_DIMS[$dim];
        [$where, $params] = an_cube_where($range);

        $rows = Database::fetchAll(
            'SELECT `' . $column . '` AS k,
                    COALESCE(SUM(`sessions`), 0)          AS sessions,
                    COALESCE(SUM(`pageviews`), 0)         AS pageviews,
                    COALESCE(SUM(`engaged_sessions`), 0)  AS engaged_sessions,
                    COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                    COALESCE(SUM(`orders`), 0)            AS orders,
                    COALESCE(SUM(`revenue`), 0)           AS revenue
               FROM `an_daily_traffic`
              WHERE ' . $where . '
              GROUP BY `' . $column . '`
              ORDER BY sessions DESC
              LIMIT ' . ($limit + 1),
            $params
        );
    } elseif (isset(AN_DIMS[$dim])) {
        $rows = Database::fetchAll(
            'SELECT `val` AS k,
                    COALESCE(SUM(`sessions`), 0)          AS sessions,
                    COALESCE(SUM(`pageviews`), 0)         AS pageviews,
                    COALESCE(SUM(`engaged_sessions`), 0)  AS engaged_sessions,
                    COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                    0                                     AS orders,
                    COALESCE(SUM(`revenue`), 0)           AS revenue
               FROM `an_daily_dim`
              WHERE `dim` = :dim AND `day` BETWEEN :from AND :to
              GROUP BY `val`
              ORDER BY sessions DESC
              LIMIT ' . ($limit + 1),
            ['dim' => AN_DIMS[$dim], 'from' => $range['from'], 'to' => $range['to']]
        );
    } else {
        return [];
    }

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['sessions'];
    }

    $out = [];
    foreach (array_slice($rows, 0, $limit) as $r) {
        $sessions = (int) $r['sessions'];
        $out[] = [
            'key'               => (string) $r['k'],
            'label'             => an_dim_label($dim, (string) $r['k']),
            'sessions'          => $sessions,
            'pageviews'         => (int) $r['pageviews'],
            'engaged_sessions'  => (int) $r['engaged_sessions'],
            'engagement_rate'   => an_pct((int) $r['engaged_sessions'], $sessions),
            'purchase_sessions' => (int) $r['purchase_sessions'],
            'conversion_rate'   => an_pct((int) $r['purchase_sessions'], $sessions),
            'orders'            => (int) $r['orders'],
            'revenue'           => round((float) $r['revenue'], 2),
            'share'             => an_pct($sessions, $total),
        ];
    }

    return $out;
}

/**
 * The human label for one breakdown key.
 *
 * Ids rather than names are what the rollups store for browser, OS and the
 * two path dimensions, so the label is resolved here, at read time. That is
 * also why renaming a product or moving a URL renames it in every historical
 * report instead of leaving the old name frozen in a table.
 */
function an_dim_label(string $dim, string $key): string
{
    switch ($dim) {
        case 'channel':
            return an_label('channel', (int) $key);
        case 'device':
            return (int) $key === AN_DEVICE_ALL ? 'All devices' : ucfirst(an_label('device', (int) $key));
        case 'browser':
            return an_label('browser', (int) $key);
        case 'os':
            return an_label('os', (int) $key);
        case 'visitor_type':
            return [0 => 'Unknown', 1 => 'New', 2 => 'Returning'][(int) $key] ?? 'Unknown';
        case 'country':
        case 'region':
        case 'city':
            // Not missing: never collected. No geo source is configured.
            return $key === '' ? 'Unknown' : $key;
        case 'source':
            return an_source_name((int) $key);
        case 'landing':
        case 'exit':
            return an_path_label((int) $key);
        default:
            return $key === '' ? '(none)' : $key;
    }
}

/** an_sources id => name, looked up once per request. */
function an_source_name(int $id): string
{
    static $names = null;

    if ($names === null) {
        $names = Database::fetchPairs('SELECT `id`, `name` FROM `an_sources`');
    }

    return $id === 0 ? '(direct)' : (string) ($names[$id] ?? '(unknown)');
}

/** an_paths id => path, looked up once per request. */
function an_path_label(int $id): string
{
    static $paths = null;

    if ($paths === null) {
        $paths = Database::fetchPairs('SELECT `id`, `path` FROM `an_paths`');
    }

    return $id === 0 ? '(not recorded)' : (string) ($paths[$id] ?? '(deleted page)');
}

// ===========================================================================
//  Pages and products
// ===========================================================================

/**
 * The most-viewed pages, with entries, exits, bounce rate, attention and
 * scroll depth.
 *
 * Two queries on purpose: aggregate first, then look up the URLs of the
 * winners. Joining an_paths into the aggregate would drag the join across
 * every row of the range to label twenty.
 *
 * @return array<int, array<string, mixed>>
 */
function analytics_top_pages(array $range, int $limit = 20): array
{
    analytics_rollup_lazy();

    $limit = max(1, min(200, $limit));

    return an_cached('pages', $range, [$limit], static fn(): array => an_top_pages_query($range, $limit));
}

/** @see analytics_top_pages() */
function an_top_pages_query(array $range, int $limit): array
{
    $rows = Database::fetchAll(
        'SELECT `path_id`,
                COALESCE(SUM(`views`), 0)         AS views,
                COALESCE(SUM(`view_sessions`), 0) AS view_sessions,
                COALESCE(SUM(`entries`), 0)       AS entries,
                COALESCE(SUM(`exits`), 0)         AS exits,
                COALESCE(SUM(`bounces`), 0)       AS bounces,
                COALESCE(SUM(`engaged_ms`), 0)    AS engaged_ms,
                COALESCE(SUM(`scroll_sum`), 0)    AS scroll_sum,
                COALESCE(SUM(`scroll_n`), 0)      AS scroll_n
           FROM `an_daily_page`
          WHERE `day` BETWEEN :from AND :to
          GROUP BY `path_id`
          ORDER BY views DESC
          LIMIT ' . $limit,
        ['from' => $range['from'], 'to' => $range['to']]
    );

    if ($rows === []) {
        return [];
    }

    $ids  = array_map(static fn(array $r): int => (int) $r['path_id'], $rows);
    $meta = Database::fetchAll(
        'SELECT `id`, `path`, `page_type`, `entity_id` FROM `an_paths` WHERE `id` IN (' .
        implode(',', array_map('intval', $ids)) . ')'
    );
    $byId = [];
    foreach ($meta as $m) {
        $byId[(int) $m['id']] = $m;
    }

    $out = [];
    foreach ($rows as $r) {
        $id      = (int) $r['path_id'];
        $views   = (int) $r['views'];
        $entries = (int) $r['entries'];
        $out[] = [
            'path_id'      => $id,
            'path'         => (string) ($byId[$id]['path'] ?? '(deleted page)'),
            'page_type'    => an_label('page_type', (int) ($byId[$id]['page_type'] ?? 0)),
            'entity_id'    => (int) ($byId[$id]['entity_id'] ?? 0),
            'views'        => $views,
            'view_sessions' => (int) $r['view_sessions'],
            'entries'      => $entries,
            'exits'        => (int) $r['exits'],
            // Bounce rate is over ENTRIES, not views: a page can only bounce a
            // visit that started on it.
            'bounce_rate'  => an_pct((int) $r['bounces'], $entries),
            'exit_rate'    => an_pct((int) $r['exits'], $views),
            'avg_time_s'   => $views > 0 ? round(((int) $r['engaged_ms'] / 1000) / $views, 1) : 0.0,
            // Null, not zero: "nobody's scroll was measured" and "nobody
            // scrolled" are different answers.
            'avg_scroll'   => (int) $r['scroll_n'] > 0
                ? (int) round((int) $r['scroll_sum'] / (int) $r['scroll_n'])
                : null,
        ];
    }

    return $out;
}

/**
 * Products by views, cart adds, units sold and revenue.
 *
 * Units and revenue are summed across every device row including
 * AN_DEVICE_ALL, which is where the order-derived numbers live - a sale has
 * no device, so it is never split by one. Revenue is
 * SUM(order_items.subtotal), exactly as Reports > Products defines it.
 *
 * @param string $sort views|cart_adds|units|revenue
 * @return array<int, array<string, mixed>>
 */
function analytics_top_products(array $range, int $limit = 20, string $sort = 'views'): array
{
    analytics_rollup_lazy();

    $limit = max(1, min(200, $limit));

    return an_cached('products', $range, [$limit, $sort],
        static fn(): array => an_top_products_query($range, $limit, $sort));
}

/** @see analytics_top_products() */
function an_top_products_query(array $range, int $limit, string $sort): array
{
    $sortBy = [
        'views'     => 'views',
        'cart_adds' => 'cart_adds',
        'units'     => 'units_sold',
        'revenue'   => 'revenue',
    ][$sort] ?? 'views';

    $rows = Database::fetchAll(
        'SELECT `product_id`,
                COALESCE(SUM(`views`), 0)         AS views,
                COALESCE(SUM(`view_sessions`), 0) AS view_sessions,
                COALESCE(SUM(`cart_adds`), 0)     AS cart_adds,
                COALESCE(SUM(`cart_qty`), 0)      AS cart_qty,
                COALESCE(SUM(`wishlist_adds`), 0) AS wishlist_adds,
                COALESCE(SUM(`units_sold`), 0)    AS units_sold,
                COALESCE(SUM(`revenue`), 0)       AS revenue
           FROM `an_daily_product`
          WHERE `day` BETWEEN :from AND :to
          GROUP BY `product_id`
          ORDER BY ' . $sortBy . ' DESC
          LIMIT ' . $limit,
        ['from' => $range['from'], 'to' => $range['to']]
    );

    if ($rows === []) {
        return [];
    }

    $ids   = implode(',', array_map(static fn(array $r): int => (int) $r['product_id'], $rows));
    // The NAME is never stored in an analytics table, so a renamed product is
    // renamed in every historical report rather than frozen under its old one.
    $names = Database::fetchPairs('SELECT `id`, `name` FROM `products` WHERE `id` IN (' . $ids . ')');
    $slugs = Database::fetchPairs('SELECT `id`, `slug` FROM `products` WHERE `id` IN (' . $ids . ')');

    $out = [];
    foreach ($rows as $r) {
        $id    = (int) $r['product_id'];
        $views = (int) $r['views'];
        $out[] = [
            'product_id'    => $id,
            'name'          => (string) ($names[$id] ?? 'Deleted product #' . $id),
            'slug'          => (string) ($slugs[$id] ?? ''),
            'deleted'       => !isset($names[$id]),
            'views'         => $views,
            'view_sessions' => (int) $r['view_sessions'],
            'cart_adds'     => (int) $r['cart_adds'],
            'cart_qty'      => (int) $r['cart_qty'],
            'wishlist_adds' => (int) $r['wishlist_adds'],
            'units'         => (int) $r['units_sold'],
            'revenue'       => round((float) $r['revenue'], 2),
            // How often a view became a cart add. Views come from the browser
            // and cart adds from the server, so an ad blocker makes this look
            // better than it is, never worse.
            'cart_rate'     => an_pct((int) $r['cart_adds'], $views),
        ];
    }

    return $out;
}

// ===========================================================================
//  The funnel
// ===========================================================================

/**
 * Product view -> add to cart -> checkout -> purchase, counted in VISITS.
 *
 * Visits, not events, because that is the only version of the question an
 * owner can act on: "of the people who put something in a basket, how many
 * paid". The event counts are returned alongside for the screens that want
 * them, and so is the store's real order count - the gap between `purchases`
 * (attributed) and `orders` (the books) is the share of buyers analytics
 * never saw, and B4 is expected to show it rather than quietly present the
 * smaller number.
 *
 * @return array<string, mixed>
 */
function analytics_funnel(array $range): array
{
    $summary = analytics_summary($range);
    $events  = analytics_events($range);

    $steps = [
        ['key' => 'view',     'label' => 'Viewed a product', 'sessions' => $summary['view_sessions']],
        ['key' => 'cart',     'label' => 'Added to cart',    'sessions' => $summary['cart_sessions']],
        ['key' => 'checkout', 'label' => 'Started checkout', 'sessions' => $summary['checkout_sessions']],
        ['key' => 'purchase', 'label' => 'Purchased',        'sessions' => $summary['purchase_sessions']],
    ];

    $first = max(1, $steps[0]['sessions']);
    $prev  = null;

    foreach ($steps as $i => $step) {
        $steps[$i]['of_first']  = an_pct($step['sessions'], $first);
        $steps[$i]['of_prev']   = $prev === null ? 100.0 : an_pct($step['sessions'], max(1, $prev));
        $steps[$i]['drop']      = $prev === null ? 0 : max(0, $prev - $step['sessions']);
        $steps[$i]['drop_rate'] = $prev === null ? 0.0 : an_pct(max(0, $prev - $step['sessions']), max(1, $prev));
        $prev = $step['sessions'];
    }

    return [
        'steps'            => $steps,
        'sessions'         => $summary['sessions'],
        'orders'           => $summary['orders'],
        'attr_orders'      => $summary['attr_orders'],
        'attribution_rate' => $summary['attribution_rate'],
        'revenue'          => $summary['revenue'],
        'units'            => $summary['units'],
        'events'           => [
            'add_to_cart'    => $events['add_to_cart']['events'] ?? 0,
            'begin_checkout' => $events['begin_checkout']['events'] ?? 0,
            'purchase'       => $events['purchase']['events'] ?? 0,
        ],
        // A product view is the one step that depends on the browser's beacon
        // getting through; every other step is written by the server when the
        // thing actually happened. So the top of this funnel is the only part
        // an ad blocker can shrink, which makes the funnel look BETTER than
        // it is rather than worse.
        'note' => 'Steps count visits. The first step needs the visitor\'s browser to report the view; '
                . 'the rest are recorded by the server when they happen.',
    ];
}

/**
 * Cart abandonment, two different questions with two different answers.
 *
 *   `rate`       - of the visits that added something, the share that never
 *                  bought, over the range. From the rollups, historical.
 *   `live_carts` - baskets holding something RIGHT NOW. From the `carts`
 *                  table, because a rollup cannot answer "what is sitting in
 *                  a basket at this moment", and because since empty carts
 *                  stopped being created by a page view, a cart row with
 *                  items in it is a real intention rather than an artefact.
 *
 * @return array<string, mixed>
 */
function analytics_cart_abandonment(array $range): array
{
    $summary = analytics_summary($range);

    $carts = Database::fetch(
        'SELECT COUNT(DISTINCT c.`id`) AS carts,
                COALESCE(SUM(ci.`quantity`), 0) AS units
           FROM `carts` c
           JOIN `cart_items` ci ON ci.`cart_id` = c.`id`'
    ) ?? [];

    $stale = (int) Database::fetchColumn(
        'SELECT COUNT(DISTINCT c.`id`)
           FROM `carts` c
           JOIN `cart_items` ci ON ci.`cart_id` = c.`id`
          WHERE c.`updated_at` < NOW() - INTERVAL 1 DAY'
    );

    $cart     = $summary['cart_sessions'];
    $purchase = $summary['purchase_sessions'];
    $checkout = $summary['checkout_sessions'];

    return [
        'cart_sessions'     => $cart,
        'checkout_sessions' => $checkout,
        'purchase_sessions' => $purchase,
        'abandoned'         => max(0, $cart - $purchase),
        'rate'              => an_pct(max(0, $cart - $purchase), $cart),
        // Of the people who reached checkout - a much smaller and much more
        // alarming number when it is high, because they had already decided.
        'checkout_rate'     => an_pct(max(0, $checkout - $purchase), $checkout),
        'live_carts'        => (int) ($carts['carts'] ?? 0),
        'live_units'        => (int) ($carts['units'] ?? 0),
        'live_stale_carts'  => $stale,
    ];
}

// ===========================================================================
//  Events, vitals, searches
// ===========================================================================

/**
 * Every event in the range, keyed by its name.
 *
 * @return array<string, array{name:string, events:int, sessions:int, qty:int, value:float}>
 */
function analytics_events(array $range): array
{
    analytics_rollup_lazy();

    $rows = Database::fetchAll(
        'SELECT `name`,
                COALESCE(SUM(`events`), 0)   AS events,
                COALESCE(SUM(`sessions`), 0) AS sessions,
                COALESCE(SUM(`qty`), 0)      AS qty,
                COALESCE(SUM(`value`), 0)    AS value
           FROM `an_daily_event`
          WHERE `day` BETWEEN :from AND :to
          GROUP BY `name`
          ORDER BY events DESC',
        ['from' => $range['from'], 'to' => $range['to']]
    );

    $out = [];
    foreach ($rows as $r) {
        $name = an_label('event', (int) $r['name']);
        $out[$name] = [
            'name'     => $name,
            'events'   => (int) $r['events'],
            // Distinct VISITS. Lower than `events` whenever somebody did the
            // same thing twice, and lower again by the events that had no
            // visit to belong to.
            'sessions' => (int) $r['sessions'],
            'qty'      => (int) $r['qty'],
            'value'    => round((float) $r['value'], 2),
        ];
    }

    return $out;
}

/**
 * Core Web Vitals for the range.
 *
 * The good / needs-work / poor counts are EXACT for any range, because they
 * are counts and counts add up. The p75 is not: a percentile of a week is not
 * the average of seven daily percentiles, and the samples it would need are
 * deleted with the raw rows. So `p75_typical` is labelled for what it is - a
 * sample-weighted average of the daily figures - and `p75_worst` names the
 * worst single day, which is the one worth opening.
 *
 * For one day, `p75_typical` IS that day's p75.
 *
 * @return array<string, mixed>
 */
function analytics_vitals(array $range, ?int $device = null, ?int $pageType = null): array
{
    analytics_rollup_lazy();

    $where  = '`day` BETWEEN :from AND :to';
    $params = ['from' => $range['from'], 'to' => $range['to']];

    if ($device !== null) {
        $where .= ' AND `device` = :dev';
        $params['dev'] = $device;
    }
    if ($pageType !== null) {
        $where .= ' AND `page_type` = :pt';
        $params['pt'] = $pageType;
    }

    $rows = Database::fetchAll(
        'SELECT `metric`,
                COALESCE(SUM(`samples`), 0) AS samples,
                COALESCE(SUM(`good`), 0)    AS good,
                COALESCE(SUM(`ni`), 0)      AS ni,
                COALESCE(SUM(`poor`), 0)    AS poor,
                COALESCE(SUM(`p75` * `samples`), 0) AS p75_weighted,
                COALESCE(MAX(`p75`), 0)     AS p75_worst
           FROM `an_daily_vitals`
          WHERE ' . $where . '
          GROUP BY `metric`',
        $params
    );

    $out = [];
    foreach ($rows as $r) {
        $metric  = (int) $r['metric'];
        $samples = (int) $r['samples'];
        $name    = AN_VITALS[$metric][0] ?? ('metric' . $metric);
        $out[$name] = [
            'metric'      => $metric,
            'name'        => strtoupper($name),
            'samples'     => $samples,
            'good'        => (int) $r['good'],
            'ni'          => (int) $r['ni'],
            'poor'        => (int) $r['poor'],
            'good_pct'    => an_pct((int) $r['good'], $samples),
            'poor_pct'    => an_pct((int) $r['poor'], $samples),
            'p75_typical' => $samples > 0 ? (int) round((int) $r['p75_weighted'] / $samples) : 0,
            'p75_worst'   => (int) $r['p75_worst'],
            'good_under'  => AN_VITALS[$metric][1] ?? 0,
            'poor_over'   => AN_VITALS[$metric][2] ?? 0,
            'unit'        => $name === 'cls' ? 'score' : 'ms',
            'exact'       => $range['days'] <= 1,
        ];
    }

    return $out;
}

/**
 * What shoppers searched for.
 *
 * READS search_logs, not a rollup, and that is the point. A search term is
 * free text a shopper typed; it has its own retention window in Admin >
 * Security > Settings, and copying it into a table that is kept for ever
 * would quietly cancel that window. So this report is only as deep as the
 * search log is allowed to be, which is the honest depth.
 *
 * @return array<int, array{term:string, searches:int, no_results:int}>
 */
function analytics_top_searches(array $range, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    return Database::fetchAll(
        'SELECT `query` AS term, COUNT(*) AS searches,
                COALESCE(SUM(`results_count` = 0), 0) AS no_results
           FROM `search_logs`
          WHERE `created_at` >= :from AND `created_at` < :to
          GROUP BY `query`
          ORDER BY searches DESC
          LIMIT ' . $limit,
        ['from' => $range['from'] . ' 00:00:00', 'to' => an_rollup_next_day($range['to']) . ' 00:00:00']
    );
}

// ===========================================================================
//  Honesty
// ===========================================================================

/**
 * What a screen must tell the reader about these numbers.
 *
 * Returned as data rather than left to each screen to remember, because the
 * caveats are properties of how the data was collected and every screen that
 * shows the data inherits them. A dashboard that prints "1,284 visitors"
 * without saying the count is approximate has made a claim the store cannot
 * support.
 *
 * @return array{mode:string, counting:bool, notes:string[], fresh_through:?string,
 *               stale_days:int, partial_days:string[], geo:bool, raw_from:?string}
 */
function analytics_quality(array $range): array
{
    $status  = analytics_rollup_status();
    $mode    = analytics_mode();
    $through = $status['through'];

    $notes = [];

    if ($mode === 'off') {
        $notes[] = 'Counting is switched off, so nothing new is being recorded. What is shown here was '
                 . 'collected before it was switched off.';
    }

    if ($mode === 'anonymous') {
        $notes[] = 'Visitor counts are approximate. This store counts without a cookie, so a household or '
                 . 'an office behind one connection looks like one visitor, and the same person counts '
                 . 'again the next day.';
        $notes[] = 'New versus returning is unknown: identifying a returning visitor needs an identifier '
                 . 'this store deliberately does not set.';
    }

    if ($range['days'] > 1) {
        $notes[] = 'Visitors over more than one day is the sum of each day\'s visitors, so somebody who '
                 . 'came on Monday and Thursday counts twice.';
    }

    $notes[] = 'Country, region and city read "Unknown" - no location source is configured, so no location '
             . 'was ever collected.';

    $notes[] = 'Orders and revenue come from your orders, not from the tracker. The smaller "attributed" '
             . 'figures are the part that could be tied to a visit: a shopper whose browser blocked the '
             . 'tracker still buys.';

    // Days in the requested range that the rollup has not reached yet.
    $partial = [];
    $today   = date('Y-m-d');
    foreach (an_rollup_days_between($range['from'], min($range['to'], $today)) as $day) {
        if ($through === null || $day > $through) {
            $partial[] = $day;
        }
    }

    if ($partial !== []) {
        $notes[] = count($partial) === 1 && $partial[0] === $today
            ? 'Today is still being counted and will keep changing until midnight.'
            : 'The most recent ' . count($partial) . ' day(s) in this range are still being totalled.';
    }

    $notes[] = 'Visit-by-visit detail is kept for ' . $status['retention_days'] . ' days; the daily totals '
             . 'shown here are kept for good, so an older range still has its numbers even when the '
             . 'individual visits behind them have gone.';

    return [
        'mode'          => $mode,
        'counting'      => $mode !== 'off',
        'notes'         => $notes,
        'fresh_through' => $through,
        'stale_days'    => $status['lag_days'],
        'partial_days'  => $partial,
        'geo'           => false,
        'raw_from'      => $status['oldest_raw'],
    ];
}

/** A percentage to one decimal, with the "divide by nothing" case answered once. */
function an_pct(int $part, int $whole): float
{
    return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
}

// ===========================================================================
//  Caching the two heavy reads
// ===========================================================================

/**
 * A cache key that CANNOT go stale.
 *
 * Top pages and top products are the only two reads that scan a wide range
 * of rollup rows rather than one row per day - ninety days of a busy store is
 * around eighty thousand rows, and that measures in the low hundreds of
 * milliseconds however the query is written. They are also the two whose
 * answer does not change until the rollup runs again.
 *
 * So the key carries MAX(rolled_at) for the range. Every path that rewrites a
 * day - a full rollup, a late event, a cancelled order correcting last
 * week - stamps that day's rolled_at, so any change to any day in the range
 * produces a different key and the old entry is simply never read again.
 * A time-to-live alone would have meant a corrected figure staying wrong for
 * as long as the TTL, which is exactly the drift this phase exists to avoid.
 *
 * A range that includes a day still being counted is not cached at all.
 */
function an_cache_stamp(array $range): ?string
{
    $today = date('Y-m-d');

    if ($range['to'] >= $today) {
        return null;    // still moving
    }

    // rolled_at alone is a DATETIME, so two rollups inside the same second
    // would share a stamp. The row count and the totals are added for that
    // case: a day cannot be recomputed into different page or product rows
    // without also changing one of these, because they all come from the
    // same raw rows.
    $stamp = Database::fetchColumn(
        'SELECT CONCAT(COALESCE(MAX(`rolled_at`), \'-\'), \':\', COUNT(*), \':\',
                       COALESCE(SUM(`sessions`), 0), \':\', COALESCE(SUM(`pageviews`), 0), \':\',
                       COALESCE(SUM(`units`), 0), \':\', COALESCE(SUM(`orders`), 0))
           FROM `an_daily_totals` WHERE `day` BETWEEN :from AND :to',
        ['from' => $range['from'], 'to' => $range['to']]
    );

    if ($stamp === null || $stamp === false || strpos((string) $stamp, '-:0:') === 0) {
        return null;    // nothing rolled up for this range yet
    }

    return (string) $stamp;
}

/** Run $fn, or return the answer it gave for this exact version of this range. */
function an_cached(string $what, array $range, array $extra, callable $fn)
{
    $stamp = an_cache_stamp($range);

    if ($stamp === null) {
        return $fn();
    }

    $key = 'an_' . $what . '_' . md5($range['from'] . '|' . $range['to'] . '|' . $stamp . '|' . json_encode($extra));

    // Half an hour is plenty: the key changes the moment the data does, so
    // the lifetime is only about not keeping files for ranges nobody asks
    // for twice.
    return cache_remember($key, 1800, $fn);
}
