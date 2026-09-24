<?php
/**
 * ShopInnKart - Analytics: daily rollups, retention and the purge (phase B3).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A dashboard that reads raw rows is a dashboard that gets slower every week
 * it works. Measured on this schema, "sessions, page views, engagement and
 * revenue for the last 28 days" over raw an_sessions/an_pageviews is hundreds
 * of milliseconds at a modest volume and seconds at a busy one, because it is
 * a scan of every row a shopper ever produced. The same question against
 * `an_daily_totals` is a 28-row range scan of a primary key, and it costs the
 * same on the last day of the year as on the first.
 *
 * So every number a screen shows comes from a rollup, and the rollups are
 * written here.
 *
 * THE FOUR RULES THIS CODE IS BUILT AROUND
 * ----------------------------------------
 *  1. A DAY IS RECOMPUTED, NEVER ADDED TO. analytics_rollup() deletes that
 *     day's rollup rows and writes them again from the raw rows. That is the
 *     whole of the idempotence story: running it twice cannot double a
 *     number, because the second run does not know or care that the first one
 *     happened. Incremental counters would have needed a cursor per table,
 *     and a cursor that is ever wrong is a number that is silently wrong
 *     forever.
 *
 *  2. EVERYTHING IS KEYED BY THE VISIT'S DAY, not by the row's own timestamp.
 *     A visit that starts at 23:50 and ends at 00:10 is one visit, on the day
 *     it started; its second page view, its purchase and its bounce all
 *     belong to that day. Keying page views by their own timestamp would
 *     split one visit across two days and make "entries" and "exits" - which
 *     are properties of a visit, not of a page view - impossible to count.
 *     The one exception is an event with no session (an ad blocker ate the
 *     beacon but the shopper still added to a cart), which has nothing but
 *     its own timestamp to go on.
 *
 *  3. A DAY WHOSE RAW ROWS ARE GONE IS FROZEN. The purge records how far it
 *     has deleted, and analytics_rollup() refuses any day at or before that
 *     mark. Without this rule the first run after a purge would cheerfully
 *     recompute last quarter as zero and write it.
 *
 *  4. THE STORE'S BOOKS AND ANALYTICS' GUESS ARE DIFFERENT COLUMNS.
 *     `orders`, `revenue` and `units` come from the orders table, using the
 *     same REVENUE_ORDER_STATUSES the reports and the dashboard use.
 *     `attr_orders` and `attr_revenue` are the part analytics could tie to a
 *     visit, which is always less. Printing the second under the word
 *     "revenue" is how a dashboard ends up disagreeing with the orders
 *     screen, and the owner is right to believe the orders screen.
 *
 * WHAT MAKES A DAY DIRTY
 * ----------------------
 * Late data is the normal case, not the exception: a heartbeat lands after
 * midnight, an order is confirmed on Tuesday for a visit on Sunday, a
 * cancellation reverses a sale a fortnight later. Three mechanisms cover it:
 *
 *   - a watermark of the highest an_events id seen, so an event inserted
 *     late - even one back-dated into a day that was rolled up a week ago -
 *     makes that day dirty on the next run;
 *   - the session's own last_seen_at against the last run's start time, so a
 *     session touched after its day was rolled up is picked up;
 *   - a trailing commerce window (45 days by default) over which units and
 *     revenue are re-derived from `orders` on every run, so a confirmation,
 *     a cancellation or a refund flows back into the day it belongs to.
 *
 * @see bin/analytics-rollup.php  the cron entry point
 * @see includes/analytics/metrics.php  everything that reads what this writes
 */

declare(strict_types=1);

require_once __DIR__ . '/classify.php';
require_once __DIR__ . '/collect.php';

/**
 * The device bucket order-derived numbers are written to.
 *
 * Views and cart adds know their device because the visit did. A SALE does
 * not: the orders table has no device, and the visit that could have supplied
 * one is deleted after the retention window - so re-deriving an old day would
 * quietly move its units from "mobile" to "other" and rewrite history. 255 is
 * outside AN_DEVICES on purpose: it reads as "all devices", it can never
 * collide with a real one, and it makes the honest statement the screens
 * repeat - units sold are not split by device.
 */
const AN_DEVICE_ALL = 255;

/** Rollup rows are written in chunks of this many. */
const AN_ROLLUP_CHUNK = 400;

/** Bucket width per vitals metric id, used for the p75. See an_rollup_vitals(). */
const AN_VITALS_BUCKET = [1 => 50, 2 => 10, 3 => 5, 4 => 50, 5 => 50];

/** How many days a single on-demand (no cron) rollup will catch up in one page load. */
const AN_LAZY_MAX_DAYS = 3;

/** At most one on-demand rollup attempt per this many seconds. */
const AN_LAZY_THROTTLE = 900;

/** Rows deleted per statement by the purge, and how many statements per table per run. */
const AN_PURGE_BATCH   = 2000;
const AN_PURGE_BATCHES = 25;

// ===========================================================================
//  One day
// ===========================================================================

/**
 * Recompute every rollup row for one day, from the raw rows.
 *
 * One pass per table, each one a GROUP BY that the database does and PHP only
 * merges - so what crosses into PHP is aggregate rows (hundreds), never raw
 * rows (hundreds of thousands).
 *
 * Idempotent by construction: the day's rows are deleted first, so calling
 * this twice writes identical rows the second time.
 *
 * @param  string $day Y-m-d
 * @return array{day:string, rows:int, ms:float, skipped:?string}
 */
function analytics_rollup(string $day): array
{
    $day   = date('Y-m-d', strtotime($day));
    $start = hrtime(true);

    // Rule 3. A day whose raw rows have been purged must keep the numbers it
    // already has; recomputing it from nothing would write zeroes over them.
    $purged = analytics_state('purge.through', '');
    if ($purged !== '' && $day <= $purged) {
        return ['day' => $day, 'rows' => 0, 'ms' => 0.0, 'skipped' => 'raw rows purged'];
    }

    $rows = 0;

    // The whole day in one transaction: a screen reading while this runs sees
    // the old numbers or the new ones, never a day that is half deleted.
    Database::transaction(static function () use ($day, &$rows): void {
        // ONE streamed pass over the day's visits feeds three tables. The
        // first version of this ran a grouped query per dimension - eleven
        // scans of the same rows, each re-materialising the same two derived
        // tables - and measured 78 ms of query time per day where this
        // measures 13. What crosses into PHP is still bounded by the number
        // of GROUPS, not the number of visits, because the accumulators are
        // written as each row arrives and the row is then dropped.
        $acc = an_rollup_scan_sessions($day);

        $rows += an_rollup_write_traffic($day, $acc['cube']);
        $rows += an_rollup_write_dim($day, $acc['dims']);
        $rows += an_rollup_write_page($day, $acc['pages']);
        $rows += an_rollup_product($day);
        $rows += an_rollup_event($day);
        $rows += an_rollup_vitals($day);
        $rows += an_rollup_totals($day);
    });

    return [
        'day'     => $day,
        'rows'    => $rows,
        'ms'      => round((hrtime(true) - $start) / 1e6, 1),
        'skipped' => null,
    ];
}

/**
 * ONE streamed pass over the day's visits, feeding three tables at once.
 *
 * WHY STREAMED. The obvious shape - a GROUP BY per table and per dimension -
 * runs eleven queries over the same rows and re-materialises the same two
 * derived tables in each of them; measured on a 420-visit day that was 78 ms
 * of query time against 13 ms for this. The obvious alternative - fetchAll()
 * and group in PHP - holds every visit of the day in memory at once, which is
 * fine at 420 a day and is not fine at fifty thousand.
 *
 * So the rows are read one at a time and dropped as soon as they have been
 * added to the accumulators. Memory is bounded by the number of GROUPS - a
 * few hundred cube rows, a few hundred campaigns, one entry per page - and
 * not by the traffic.
 *
 * `visitors` cannot be accumulated this way: a distinct count needs to
 * remember every key it has seen, which is the one thing that would scale
 * with traffic. It is the one figure left to the database, as a second
 * grouped query with no joins.
 *
 * @return array{cube:array, dims:array, pages:array}
 */
function an_rollup_scan_sessions(string $day): array
{
    $cube  = [];
    $dims  = [];
    $pages = [];

    /** Start an accumulator row the first time its key appears. */
    $blank = ['sessions' => 0, 'visitors' => 0, 'new_visitors' => 0, 'pageviews' => 0,
        'engaged_sessions' => 0, 'engaged_ms' => 0, 'view_sessions' => 0, 'cart_sessions' => 0,
        'checkout_sessions' => 0, 'purchase_sessions' => 0, 'orders' => 0, 'revenue' => 0.0];

    $statement = Database::query(
        'SELECT s.`channel`, s.`source_id`, s.`device`, s.`visitor_type`,
                COALESCE(s.`country`, \'\') AS country, COALESCE(s.`region`, \'\') AS region,
                s.`is_engaged`, s.`engaged_ms`, s.`funnel`,
                LOWER(s.`medium`) AS medium, LOWER(s.`campaign`) AS campaign, s.`city`,
                s.`browser`, s.`os`, s.`landing_path_id`, s.`current_path_id`,
                src.`name` AS source_name,
                COALESCE(pv.`n`, 0)        AS pageviews,
                COALESCE(po.`orders`, 0)   AS orders,
                COALESCE(po.`revenue`, 0)  AS revenue
           FROM `an_sessions` s
           LEFT JOIN `an_sources` src ON src.`id` = s.`source_id`
           ' . an_rollup_pv_join($day) . '
           ' . an_rollup_order_join($day) . '
          WHERE s.`day` = :d',
        ['d' => $day, 'd1' => $day, 'd2' => $day]
    );

    while (($row = $statement->fetch()) !== false) {
        $engaged   = (int) $row['is_engaged'];
        $funnel    = (int) $row['funnel'];
        $pvCount   = (int) $row['pageviews'];
        $revenue   = (float) $row['revenue'];
        $purchased = ($funnel & 8) > 0 ? 1 : 0;

        // ---- the cube -------------------------------------------------
        $key = $row['channel'] . '|' . $row['source_id'] . '|' . $row['device'] . '|'
             . $row['country'] . '|' . $row['region'] . '|' . $row['visitor_type'];

        if (!isset($cube[$key])) {
            $cube[$key] = $blank + ['_dims' => [(int) $row['channel'], (int) $row['source_id'],
                (int) $row['device'], (string) $row['country'], (string) $row['region'],
                (int) $row['visitor_type']]];
        }

        $cube[$key]['sessions']++;
        $cube[$key]['new_visitors']      += (int) $row['visitor_type'] === 1 ? 1 : 0;
        $cube[$key]['pageviews']         += $pvCount;
        $cube[$key]['engaged_sessions']  += $engaged;
        $cube[$key]['engaged_ms']        += (int) $row['engaged_ms'];
        $cube[$key]['view_sessions']     += ($funnel & 1) > 0 ? 1 : 0;
        $cube[$key]['cart_sessions']     += ($funnel & 2) > 0 ? 1 : 0;
        $cube[$key]['checkout_sessions'] += ($funnel & 4) > 0 ? 1 : 0;
        $cube[$key]['purchase_sessions'] += $purchased;
        $cube[$key]['orders']            += (int) $row['orders'];
        $cube[$key]['revenue']           += $revenue;

        // ---- the long-tail dimensions ---------------------------------
        // Browser, OS and the two paths are stored as their numeric ids, not
        // their names: an id is append-only and means the same thing for
        // ever, while a name that is later corrected would split one line of
        // every historical report into two. The label is resolved at read
        // time, which is also why renaming a product renames it in every
        // report going back rather than leaving the old name frozen here.
        //
        // DIM 9, SEARCH TERMS, IS DELIBERATELY ABSENT. A search term is free
        // text a shopper typed; it lives in `search_logs` with a retention
        // window of its own, and copying it into a table kept for ever would
        // quietly cancel that window. analytics_top_searches() reads
        // search_logs directly, so the report is exactly as deep as the log
        // is allowed to be.
        $values = [
            1 => (string) ($row['medium'] ?? ''),
            2 => (string) ($row['campaign'] ?? ''),
            3 => (string) ($row['source_name'] ?? ''),
            4 => (string) $row['browser'],
            5 => (string) $row['os'],
            6 => (string) ($row['city'] ?? ''),
            7 => (string) $row['landing_path_id'],
            8 => (string) $row['current_path_id'],
        ];

        foreach ($values as $dim => $value) {
            // An empty medium, campaign, referrer or city is "there wasn't
            // one", which is not a row. An id of 0 IS an answer - "unknown
            // browser", "no landing page recorded" - and is kept, so the
            // shares on a screen still add to 100.
            if ($value === '' && in_array($dim, [1, 2, 3, 6], true)) {
                continue;
            }

            $value = mb_substr($value, 0, 191);

            if (!isset($dims[$dim][$value])) {
                $dims[$dim][$value] = ['sessions' => 0, 'pageviews' => 0, 'engaged_sessions' => 0,
                    'purchase_sessions' => 0, 'revenue' => 0.0];
            }

            $dims[$dim][$value]['sessions']++;
            $dims[$dim][$value]['pageviews']         += $pvCount;
            $dims[$dim][$value]['engaged_sessions']  += $engaged;
            $dims[$dim][$value]['purchase_sessions'] += $purchased;
            $dims[$dim][$value]['revenue']           += $revenue;
        }

        // ---- entries, exits and bounces -------------------------------
        // All three belong to the VISIT, not to a page view: a bounce is
        // "arrived here and was never engaged", which only the session knows.
        $device  = (int) $row['device'];
        $landing = (int) $row['landing_path_id'];
        $exit    = (int) $row['current_path_id'];

        if ($landing > 0) {
            $k = $landing . '|' . $device;
            $pages[$k] = $pages[$k] ?? an_rollup_blank_page($landing, $device);
            $pages[$k]['entries']++;
            $pages[$k]['bounces'] += $engaged === 0 ? 1 : 0;
        }

        if ($exit > 0) {
            $k = $exit . '|' . $device;
            $pages[$k] = $pages[$k] ?? an_rollup_blank_page($exit, $device);
            $pages[$k]['exits']++;
        }
    }

    $statement->closeCursor();

    // The one figure a streaming accumulator cannot produce without
    // remembering every visitor of the day. No joins, so it is a plain
    // grouped read of one index range.
    foreach (Database::fetchAll(
        'SELECT s.`channel`, s.`source_id`, s.`device`,
                COALESCE(s.`country`, \'\') AS country, COALESCE(s.`region`, \'\') AS region,
                s.`visitor_type`, COUNT(DISTINCT s.`vkey`) AS visitors
           FROM `an_sessions` s
          WHERE s.`day` = :d
          GROUP BY s.`channel`, s.`source_id`, s.`device`, country, region, s.`visitor_type`',
        ['d' => $day]
    ) as $row) {
        $key = $row['channel'] . '|' . $row['source_id'] . '|' . $row['device'] . '|'
             . $row['country'] . '|' . $row['region'] . '|' . $row['visitor_type'];

        if (isset($cube[$key])) {
            $cube[$key]['visitors'] = (int) $row['visitors'];
        }
    }

    return ['cube' => $cube, 'dims' => $dims, 'pages' => $pages];
}

function an_rollup_blank_page(int $pathId, int $device): array
{
    return ['path_id' => $pathId, 'device' => $device, 'views' => 0, 'view_sessions' => 0,
        'entries' => 0, 'exits' => 0, 'bounces' => 0, 'engaged_ms' => 0, 'scroll_sum' => 0, 'scroll_n' => 0];
}

/**
 * The filterable cube: one row per (channel, source, device, country, region,
 * new-vs-returning) for the day.
 *
 * `visitors` is distinct WITHIN each row. One person who arrives from search
 * and returns from an email is 1 in each of two rows, so adding the rows up
 * says 2. The day's true figure is computed once, for the whole day, in
 * an_rollup_totals().
 */
function an_rollup_write_traffic(string $day, array $cube): int
{
    Database::delete('an_daily_traffic', '`day` = :d', ['d' => $day]);

    $insert = [];
    foreach ($cube as $row) {
        [$channel, $sourceId, $device, $country, $region, $visitorType] = $row['_dims'];
        $insert[] = [$day, $channel, $sourceId, $device, $country, $region, $visitorType,
            $row['sessions'], $row['visitors'], $row['new_visitors'], $row['pageviews'],
            $row['engaged_sessions'], $row['engaged_ms'],
            $row['view_sessions'], $row['cart_sessions'], $row['checkout_sessions'],
            $row['purchase_sessions'], $row['orders'], round($row['revenue'], 2)];
    }

    return an_rollup_insert(
        'an_daily_traffic',
        ['day', 'channel', 'source_id', 'device', 'country', 'region', 'visitor_type',
            'sessions', 'visitors', 'new_visitors', 'pageviews', 'engaged_sessions', 'engaged_ms',
            'view_sessions', 'cart_sessions', 'checkout_sessions', 'purchase_sessions', 'orders', 'revenue'],
        $insert
    );
}

/**
 * Page views per session, as a derived table.
 *
 * Joined rather than read from an_sessions.pageviews so that the cube, the
 * page table and the day's total are all counting the same rows. The counter
 * on the session is incremented by a second statement after the page view is
 * inserted; if that statement ever loses a deadlock the counter drifts, and a
 * dashboard where the totals disagree with the page list by one is a
 * dashboard nobody can debug.
 */
function an_rollup_pv_join(string $day): string
{
    return 'LEFT JOIN (SELECT p.`session_id`, COUNT(*) AS `n`
                         FROM `an_pageviews` p
                         JOIN `an_sessions` s2 ON s2.`id` = p.`session_id` AND s2.`day` = :d1
                        GROUP BY p.`session_id`) pv ON pv.`session_id` = s.`id`';
}

/**
 * Attributed orders and revenue per session, as a derived table.
 *
 * Taken from the purchase EVENTS joined back to `orders` rather than from
 * an_sessions.revenue, for two reasons. A visit can place more than one order
 * and the session row keeps only the first id. And the money must obey the
 * store's own definition of revenue - a cancelled order is not revenue on the
 * orders screen, so it must not be revenue here either, and this way a
 * cancellation on Friday corrects Sunday's report when the commerce window
 * next sweeps over it.
 */
function an_rollup_order_join(string $day): string
{
    return 'LEFT JOIN (SELECT e.`session_id`, COUNT(*) AS `orders`,
                              COALESCE(SUM(o.`total_amount`), 0) AS `revenue`
                         FROM `an_events` e
                         JOIN `an_sessions` s3 ON s3.`id` = e.`session_id` AND s3.`day` = :d2
                         JOIN `orders` o ON o.`id` = e.`order_id`
                              AND o.`status` IN (' . REVENUE_ORDER_STATUSES_SQL . ')
                        WHERE e.`name` = ' . AN_EVENT_IDS['purchase'] . '
                        GROUP BY e.`session_id`) po ON po.`session_id` = s.`id`';
}

/** The long-tail dimensions, accumulated by an_rollup_scan_sessions(). */
function an_rollup_write_dim(string $day, array $dims): int
{
    Database::delete('an_daily_dim', '`day` = :d', ['d' => $day]);

    $insert = [];
    foreach ($dims as $dim => $values) {
        foreach ($values as $value => $row) {
            $insert[] = [$dim, $day, (string) $value, $row['sessions'], $row['pageviews'],
                $row['engaged_sessions'], $row['purchase_sessions'], round($row['revenue'], 2)];
        }
    }

    return an_rollup_insert(
        'an_daily_dim',
        ['dim', 'day', 'val', 'sessions', 'pageviews', 'engaged_sessions', 'purchase_sessions', 'revenue'],
        $insert
    );
}

/**
 * Per page: views, entries, exits, bounces, attention and scroll depth.
 *
 * Entries, exits and bounces arrive from the session pass; views, attention
 * and scroll depth come from the page views themselves, which is the one
 * grouping the session rows cannot produce.
 */
function an_rollup_write_page(string $day, array $pages): int
{
    Database::delete('an_daily_page', '`day` = :d', ['d' => $day]);

    foreach (Database::fetchAll(
        'SELECT p.`path_id`, s.`device`,
                COUNT(*)                              AS views,
                COUNT(DISTINCT p.`session_id`)        AS view_sessions,
                COALESCE(SUM(p.`engaged_ms`), 0)      AS engaged_ms,
                COALESCE(SUM(p.`scroll_pct`), 0)      AS scroll_sum,
                COALESCE(SUM(p.`scroll_pct` IS NOT NULL), 0) AS scroll_n
           FROM `an_pageviews` p
           JOIN `an_sessions` s ON s.`id` = p.`session_id`
          WHERE s.`day` = :d
          GROUP BY p.`path_id`, s.`device`',
        ['d' => $day]
    ) as $r) {
        $key = $r['path_id'] . '|' . $r['device'];
        $pages[$key] = $pages[$key] ?? an_rollup_blank_page((int) $r['path_id'], (int) $r['device']);
        $pages[$key]['views']         = (int) $r['views'];
        $pages[$key]['view_sessions'] = (int) $r['view_sessions'];
        $pages[$key]['engaged_ms']    = (int) $r['engaged_ms'];
        $pages[$key]['scroll_sum']    = (int) $r['scroll_sum'];
        $pages[$key]['scroll_n']      = (int) $r['scroll_n'];
    }

    $insert = [];
    foreach ($pages as $row) {
        $insert[] = [$day, $row['path_id'], $row['device'], $row['views'], $row['view_sessions'],
            $row['entries'], $row['exits'], $row['bounces'], $row['engaged_ms'],
            $row['scroll_sum'], $row['scroll_n']];
    }

    return an_rollup_insert(
        'an_daily_page',
        ['day', 'path_id', 'device', 'views', 'view_sessions', 'entries', 'exits', 'bounces',
            'engaged_ms', 'scroll_sum', 'scroll_n'],
        $insert
    );
}

/**
 * Per product: views and cart adds from the visit, units and revenue from the
 * orders table.
 *
 * The two halves are written to different device buckets on purpose - see
 * AN_DEVICE_ALL. Revenue is SUM(order_items.subtotal), which is what
 * Reports > Products means by product revenue; using a different column here
 * would produce two "top products by revenue" lists that disagree.
 */
function an_rollup_product(string $day): int
{
    Database::delete('an_daily_product', '`day` = :d', ['d' => $day]);

    /** @var array<string, array<string,float|int>> $acc keyed "productId|device" */
    $acc = [];
    $at  = static function (array &$acc, int $productId, int $device): string {
        $key = $productId . '|' . $device;
        if (!isset($acc[$key])) {
            $acc[$key] = ['product_id' => $productId, 'device' => $device, 'views' => 0,
                'view_sessions' => 0, 'cart_adds' => 0, 'cart_qty' => 0, 'wishlist_adds' => 0,
                'units_sold' => 0, 'revenue' => 0.0];
        }
        return $key;
    };

    // Product page views. entity_id comes from inside the signed page token,
    // so the browser cannot claim a view of a product it never opened.
    foreach (Database::fetchAll(
        'SELECT p.`entity_id` AS product_id, s.`device`,
                COUNT(*) AS views, COUNT(DISTINCT p.`session_id`) AS view_sessions
           FROM `an_pageviews` p
           JOIN `an_sessions` s ON s.`id` = p.`session_id`
          WHERE s.`day` = :d AND p.`page_type` = 3 AND p.`entity_id` > 0
          GROUP BY p.`entity_id`, s.`device`',
        ['d' => $day]
    ) as $r) {
        $key = $at($acc, (int) $r['product_id'], (int) $r['device']);
        $acc[$key]['views']         = (int) $r['views'];
        $acc[$key]['view_sessions'] = (int) $r['view_sessions'];
    }

    // Cart and wishlist adds. Two queries because an event either belongs to a
    // visit (grouped by that visit's day and device) or belongs to nobody -
    // an ad blocker ate the page beacon while the add itself was a server-side
    // POST that certainly happened. The second kind still counts for the
    // product; it has only its own timestamp, and no device.
    $addSql = 'SUM(e.`name` = ' . AN_EVENT_IDS['add_to_cart'] . ') AS cart_adds,
               COALESCE(SUM(CASE WHEN e.`name` = ' . AN_EVENT_IDS['add_to_cart'] . ' THEN e.`qty` END), 0) AS cart_qty,
               SUM(e.`name` = ' . AN_EVENT_IDS['add_to_wishlist'] . ') AS wishlist_adds';
    $addIn  = AN_EVENT_IDS['add_to_cart'] . ', ' . AN_EVENT_IDS['add_to_wishlist'];

    foreach (Database::fetchAll(
        'SELECT e.`product_id`, s.`device`, ' . $addSql . '
           FROM `an_events` e
           JOIN `an_sessions` s ON s.`id` = e.`session_id` AND s.`day` = :d
          WHERE e.`name` IN (' . $addIn . ') AND e.`product_id` > 0
          GROUP BY e.`product_id`, s.`device`',
        ['d' => $day]
    ) as $r) {
        $key = $at($acc, (int) $r['product_id'], (int) $r['device']);
        $acc[$key]['cart_adds']     += (int) $r['cart_adds'];
        $acc[$key]['cart_qty']      += (int) $r['cart_qty'];
        $acc[$key]['wishlist_adds'] += (int) $r['wishlist_adds'];
    }

    foreach (Database::fetchAll(
        'SELECT e.`product_id`, ' . $addSql . '
           FROM `an_events` e
          WHERE e.`session_id` IS NULL AND e.`name` IN (' . $addIn . ') AND e.`product_id` > 0
                AND e.`created_at` >= :d0 AND e.`created_at` < :d1
          GROUP BY e.`product_id`',
        ['d0' => $day . ' 00:00:00', 'd1' => an_rollup_next_day($day) . ' 00:00:00']
    ) as $r) {
        $key = $at($acc, (int) $r['product_id'], 0);
        $acc[$key]['cart_adds']     += (int) $r['cart_adds'];
        $acc[$key]['cart_qty']      += (int) $r['cart_qty'];
        $acc[$key]['wishlist_adds'] += (int) $r['wishlist_adds'];
    }

    foreach (an_rollup_product_sales($day) as $r) {
        $key = $at($acc, (int) $r['product_id'], AN_DEVICE_ALL);
        $acc[$key]['units_sold'] = (int) $r['units'];
        $acc[$key]['revenue']    = round((float) $r['revenue'], 2);
    }

    $insert = [];
    foreach ($acc as $row) {
        $insert[] = [$day, $row['product_id'], $row['device'], $row['views'], $row['view_sessions'],
            $row['cart_adds'], $row['cart_qty'], $row['wishlist_adds'], $row['units_sold'], $row['revenue']];
    }

    return an_rollup_insert(
        'an_daily_product',
        ['day', 'product_id', 'device', 'views', 'view_sessions', 'cart_adds', 'cart_qty',
            'wishlist_adds', 'units_sold', 'revenue'],
        $insert
    );
}

/** Units and revenue per product for a day, straight from the orders table. */
function an_rollup_product_sales(string $day): array
{
    return Database::fetchAll(
        'SELECT oi.`product_id`,
                COALESCE(SUM(oi.`quantity`), 0) AS units,
                COALESCE(SUM(oi.`subtotal`), 0) AS revenue
           FROM `order_items` oi
           JOIN `orders` o ON o.`id` = oi.`order_id`
          WHERE o.`status` IN (' . REVENUE_ORDER_STATUSES_SQL . ')
                AND o.`created_at` >= :d0 AND o.`created_at` < :d1
                AND oi.`product_id` > 0
          GROUP BY oi.`product_id`',
        ['d0' => $day . ' 00:00:00', 'd1' => an_rollup_next_day($day) . ' 00:00:00']
    );
}

/**
 * Events by name. `sessions` counts distinct visits, `events` counts
 * occurrences - "three people added to a cart" and "one person added three
 * times" are different numbers and a funnel that confuses them is wrong in
 * the direction that flatters the store.
 */
function an_rollup_event(string $day): int
{
    Database::delete('an_daily_event', '`day` = :d', ['d' => $day]);

    $acc = [];

    foreach (Database::fetchAll(
        'SELECT e.`name`, COUNT(*) AS events, COUNT(DISTINCT e.`session_id`) AS sessions,
                COALESCE(SUM(e.`qty`), 0) AS qty, COALESCE(SUM(e.`value`), 0) AS value
           FROM `an_events` e
           JOIN `an_sessions` s ON s.`id` = e.`session_id` AND s.`day` = :d
          GROUP BY e.`name`',
        ['d' => $day]
    ) as $r) {
        $acc[(int) $r['name']] = [(int) $r['events'], (int) $r['sessions'], (int) $r['qty'], (float) $r['value']];
    }

    // Unattributed events: counted in `events`, never in `sessions`, because
    // they have no visit to be distinct within.
    foreach (Database::fetchAll(
        'SELECT e.`name`, COUNT(*) AS events, COALESCE(SUM(e.`qty`), 0) AS qty,
                COALESCE(SUM(e.`value`), 0) AS value
           FROM `an_events` e
          WHERE e.`session_id` IS NULL AND e.`created_at` >= :d0 AND e.`created_at` < :d1
          GROUP BY e.`name`',
        ['d0' => $day . ' 00:00:00', 'd1' => an_rollup_next_day($day) . ' 00:00:00']
    ) as $r) {
        $name = (int) $r['name'];
        $acc[$name] = $acc[$name] ?? [0, 0, 0, 0.0];
        $acc[$name][0] += (int) $r['events'];
        $acc[$name][2] += (int) $r['qty'];
        $acc[$name][3] += (float) $r['value'];
    }

    $insert = [];
    foreach ($acc as $name => [$events, $sessions, $qty, $value]) {
        $insert[] = [$day, $name, $events, $sessions, $qty, round($value, 2)];
    }

    return an_rollup_insert('an_daily_event', ['day', 'name', 'events', 'sessions', 'qty', 'value'], $insert);
}

/**
 * Core Web Vitals: sample counts, the good/needs-work/poor split, and p75.
 *
 * The split is counted exactly in SQL. The p75 is computed here from a
 * histogram, which is why the query groups by a bucket: pulling every sample
 * into PHP to sort them would mean loading a day's raw vitals into memory,
 * and MariaDB 10.4 and MySQL 8 disagree about percentile functions - a report
 * that changes meaning when the host upgrades is worse than a loop.
 *
 * The bucket makes p75 conservative to within one bucket width (50 ms for
 * LCP, FCP and TTFB, 10 ms for INP, 0.005 for CLS). Nobody quotes a Web Vital
 * to the millisecond; the thresholds all fall on bucket edges, so the
 * good/poor counts are exact regardless.
 */
function an_rollup_vitals(string $day): int
{
    Database::delete('an_daily_vitals', '`day` = :d', ['d' => $day]);

    $insert = [];

    foreach (AN_VITALS as $metric => [$name, $good, $poor]) {
        $column = $name === 'cls' ? 'cls_x1000' : $name . '_ms';
        $width  = AN_VITALS_BUCKET[$metric];

        $rows = Database::fetchAll(
            'SELECT v.`device`, v.`page_type`,
                    FLOOR((v.`' . $column . '` - 1) / ' . $width . ') AS bucket,
                    COUNT(*) AS n,
                    COALESCE(SUM(v.`' . $column . '` <= ' . $good . '), 0) AS good,
                    COALESCE(SUM(v.`' . $column . '` > ' . $good . ' AND v.`' . $column . '` <= ' . $poor . '), 0) AS ni,
                    COALESCE(SUM(v.`' . $column . '` > ' . $poor . '), 0) AS poor
               FROM `an_vitals` v
               JOIN `an_sessions` s ON s.`id` = v.`session_id` AND s.`day` = :d
              WHERE v.`' . $column . '` IS NOT NULL
              GROUP BY v.`device`, v.`page_type`, bucket
              ORDER BY v.`device`, v.`page_type`, bucket',
            ['d' => $day]
        );

        /** @var array<string, array{device:int,page_type:int,n:int,good:int,ni:int,poor:int,buckets:array<int,int>}> $groups */
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['device'] . '|' . $r['page_type'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['device' => (int) $r['device'], 'page_type' => (int) $r['page_type'],
                    'n' => 0, 'good' => 0, 'ni' => 0, 'poor' => 0, 'buckets' => []];
            }
            $groups[$key]['n']    += (int) $r['n'];
            $groups[$key]['good'] += (int) $r['good'];
            $groups[$key]['ni']   += (int) $r['ni'];
            $groups[$key]['poor'] += (int) $r['poor'];
            $groups[$key]['buckets'][(int) $r['bucket']] = (int) $r['n'];
        }

        foreach ($groups as $g) {
            $insert[] = [$day, $metric, $g['device'], $g['page_type'], $g['n'], $g['good'], $g['ni'], $g['poor'],
                an_rollup_p75($g['buckets'], $g['n'], $width)];
        }
    }

    return an_rollup_insert(
        'an_daily_vitals',
        ['day', 'metric', 'device', 'page_type', 'samples', 'good', 'ni', 'poor', 'p75'],
        $insert
    );
}

/**
 * The 75th percentile from a histogram: the top of the bucket the 75th
 * percentile sample falls in. Never optimistic - it rounds up, not to nearest.
 *
 * @param array<int,int> $buckets bucket index => sample count
 */
function an_rollup_p75(array $buckets, int $samples, int $width): int
{
    if ($samples <= 0) {
        return 0;
    }

    ksort($buckets);
    $target = (int) ceil($samples * 0.75);
    $seen   = 0;

    foreach ($buckets as $bucket => $count) {
        $seen += $count;
        if ($seen >= $target) {
            return max(0, (int) (($bucket + 1) * $width));
        }
    }

    return max(0, (int) ((array_key_last($buckets) + 1) * $width));
}

/**
 * The day in one row: what every screen reads first.
 *
 * `visitors` is computed here and only here, because a distinct count for a
 * day cannot be assembled out of the cube's per-row distinct counts. In the
 * owner's anonymous mode this number is APPROXIMATE by construction - the key
 * is a daily-salted hash of an anonymised address and the user agent, so an
 * office behind one router is one visitor and one person is a new visitor
 * every morning. Every screen has to say so; the number itself is the best
 * this store can honestly produce.
 */
function an_rollup_totals(string $day): int
{
    Database::delete('an_daily_totals', '`day` = :d', ['d' => $day]);

    // Summed from the cube, which was just rewritten from the same raw rows,
    // so the headline can never disagree with the breakdown beneath it.
    $cube = Database::fetch(
        'SELECT COALESCE(SUM(`sessions`), 0)          AS sessions,
                COALESCE(SUM(`new_visitors`), 0)      AS new_visitors,
                COALESCE(SUM(`pageviews`), 0)         AS pageviews,
                COALESCE(SUM(`engaged_sessions`), 0)  AS engaged_sessions,
                COALESCE(SUM(`engaged_ms`), 0)        AS engaged_ms,
                COALESCE(SUM(`view_sessions`), 0)     AS view_sessions,
                COALESCE(SUM(`cart_sessions`), 0)     AS cart_sessions,
                COALESCE(SUM(`checkout_sessions`), 0) AS checkout_sessions,
                COALESCE(SUM(`purchase_sessions`), 0) AS purchase_sessions,
                COALESCE(SUM(`orders`), 0)            AS attr_orders,
                COALESCE(SUM(`revenue`), 0)           AS attr_revenue,
                COALESCE(SUM(CASE WHEN `visitor_type` > 0 THEN `sessions` ELSE 0 END), 0) AS known_type
           FROM `an_daily_traffic` WHERE `day` = :d',
        ['d' => $day]
    ) ?? [];

    $sessions = (int) ($cube['sessions'] ?? 0);
    $visitors = $sessions === 0 ? 0 : (int) Database::fetchColumn(
        'SELECT COUNT(DISTINCT `vkey`) FROM `an_sessions` WHERE `day` = :d',
        ['d' => $day]
    );

    $sales = an_rollup_day_sales($day);

    // A day with no traffic AND no orders gets no row at all: an absent row
    // and a row of zeroes mean the same thing to every reader, and not
    // writing it keeps the table the size of the days the store was open.
    if ($sessions === 0 && $sales['orders'] === 0) {
        return 0;
    }

    Database::insert('an_daily_totals', [
        'day'               => $day,
        'sessions'          => $sessions,
        'visitors'          => $visitors,
        'new_visitors'      => (int) ($cube['new_visitors'] ?? 0),
        'known_type'        => (int) ($cube['known_type'] ?? 0),
        'pageviews'         => (int) ($cube['pageviews'] ?? 0),
        'engaged_sessions'  => (int) ($cube['engaged_sessions'] ?? 0),
        'engaged_ms'        => (int) ($cube['engaged_ms'] ?? 0),
        'bounces'           => max(0, $sessions - (int) ($cube['engaged_sessions'] ?? 0)),
        'view_sessions'     => (int) ($cube['view_sessions'] ?? 0),
        'cart_sessions'     => (int) ($cube['cart_sessions'] ?? 0),
        'checkout_sessions' => (int) ($cube['checkout_sessions'] ?? 0),
        'purchase_sessions' => (int) ($cube['purchase_sessions'] ?? 0),
        'attr_orders'       => (int) ($cube['attr_orders'] ?? 0),
        'attr_revenue'      => round((float) ($cube['attr_revenue'] ?? 0), 2),
        'orders'            => $sales['orders'],
        'revenue'           => $sales['revenue'],
        'units'             => $sales['units'],
        'rolled_at'         => date('Y-m-d H:i:s'),
    ]);

    return 1;
}

/**
 * The store's own figures for a day: orders taken, money earned, units sold.
 *
 * Same revenue statuses as Reports and the dashboard (config/constants.php),
 * so the analytics screens cannot drift from the orders screen. "Sales" means
 * UNITS here, as the owner asked; revenue is its own number.
 *
 * @return array{orders:int, revenue:float, units:int}
 */
function an_rollup_day_sales(string $day): array
{
    $row = Database::fetch(
        'SELECT COUNT(*) AS orders, COALESCE(SUM(o.`total_amount`), 0) AS revenue
           FROM `orders` o
          WHERE o.`status` IN (' . REVENUE_ORDER_STATUSES_SQL . ')
                AND o.`created_at` >= :d0 AND o.`created_at` < :d1',
        ['d0' => $day . ' 00:00:00', 'd1' => an_rollup_next_day($day) . ' 00:00:00']
    ) ?? [];

    $units = (int) Database::fetchColumn(
        'SELECT COALESCE(SUM(oi.`quantity`), 0)
           FROM `order_items` oi
           JOIN `orders` o ON o.`id` = oi.`order_id`
          WHERE o.`status` IN (' . REVENUE_ORDER_STATUSES_SQL . ')
                AND o.`created_at` >= :d0 AND o.`created_at` < :d1',
        ['d0' => $day . ' 00:00:00', 'd1' => an_rollup_next_day($day) . ' 00:00:00']
    );

    return [
        'orders'  => (int) ($row['orders'] ?? 0),
        'revenue' => round((float) ($row['revenue'] ?? 0), 2),
        'units'   => $units,
    ];
}

/**
 * Re-derive ONLY the numbers that come from the orders table, for one day.
 *
 * This is the half of a day that can be corrected after its raw rows are
 * gone: `orders` is never purged. An order confirmed, cancelled or refunded
 * days after it was placed changes the day it was placed on, and this is what
 * carries the correction back there without touching a single traffic figure.
 *
 * @return bool true when something changed
 */
function analytics_rollup_commerce(string $day): bool
{
    $day   = date('Y-m-d', strtotime($day));
    $sales = an_rollup_day_sales($day);

    $before = Database::fetch(
        'SELECT `orders`, `revenue`, `units` FROM `an_daily_totals` WHERE `day` = :d',
        ['d' => $day]
    );

    $changed = $before === null
        ? ($sales['orders'] > 0)
        : ((int) $before['orders'] !== $sales['orders']
            || abs((float) $before['revenue'] - $sales['revenue']) > 0.001
            || (int) $before['units'] !== $sales['units']);

    if (!$changed) {
        return false;
    }

    Database::transaction(static function () use ($day, $sales): void {
        if (Database::exists('an_daily_totals', '`day` = :d', ['d' => $day])) {
            Database::update('an_daily_totals', [
                'orders'    => $sales['orders'],
                'revenue'   => $sales['revenue'],
                'units'     => $sales['units'],
                'rolled_at' => date('Y-m-d H:i:s'),
            ], '`day` = :d', ['d' => $day]);
        } elseif ($sales['orders'] > 0) {
            // A day with orders but no recorded traffic is a real thing - the
            // store was up, the counter was off or every beacon was blocked.
            // It gets a row so the money is not missing from the chart.
            Database::insert('an_daily_totals', [
                'day'       => $day,
                'orders'    => $sales['orders'],
                'revenue'   => $sales['revenue'],
                'units'     => $sales['units'],
                'rolled_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Product sales live in their own device bucket, so rewriting them
        // cannot disturb the view and cart-add rows beside them.
        Database::delete('an_daily_product', '`day` = :d AND `device` = :dev',
            ['d' => $day, 'dev' => AN_DEVICE_ALL]);

        $insert = [];
        foreach (an_rollup_product_sales($day) as $r) {
            $insert[] = [$day, (int) $r['product_id'], AN_DEVICE_ALL, 0, 0, 0, 0, 0,
                (int) $r['units'], round((float) $r['revenue'], 2)];
        }

        an_rollup_insert(
            'an_daily_product',
            ['day', 'product_id', 'device', 'views', 'view_sessions', 'cart_adds', 'cart_qty',
                'wishlist_adds', 'units_sold', 'revenue'],
            $insert
        );
    });

    return true;
}

// ===========================================================================
//  A run
// ===========================================================================

/**
 * Roll up everything that needs it.
 *
 * @param array $options days (cap on how many to roll), commerce (bool),
 *                       rebuild (Y-m-d: recompute from this day on regardless
 *                       of watermarks), day (one day only), quiet
 * @return array{days:string[], rows:int, commerce:int, through:string, ms:float, skipped:array, errors:array}
 */
function analytics_rollup_run(array $options = []): array
{
    $startedAt  = date('Y-m-d H:i:s');
    $start      = hrtime(true);
    $maxDays    = max(1, (int) ($options['days'] ?? 400));
    $today      = date('Y-m-d');
    $yesterday  = date('Y-m-d', strtotime('-1 day'));

    $result = ['days' => [], 'rows' => 0, 'commerce' => 0, 'through' => analytics_state('rollup.through', ''),
        'ms' => 0.0, 'skipped' => [], 'errors' => []];

    // The watermarks are read BEFORE any work, so a row inserted while this
    // run is going is caught by the NEXT run rather than silently skipped.
    $eventMax   = (int) Database::fetchColumn('SELECT COALESCE(MAX(`id`), 0) FROM `an_events`');
    $sessionMax = (int) Database::fetchColumn('SELECT COALESCE(MAX(`id`), 0) FROM `an_sessions`');

    if (!empty($options['day'])) {
        $candidates = [date('Y-m-d', strtotime((string) $options['day']))];
    } elseif (!empty($options['rebuild'])) {
        $candidates = an_rollup_days_between(date('Y-m-d', strtotime((string) $options['rebuild'])), $today);
    } else {
        $candidates = analytics_rollup_dirty_days();
    }

    $truncated = count($candidates) > $maxDays;
    $candidates = array_slice($candidates, 0, $maxDays);

    foreach ($candidates as $day) {
        try {
            $one = analytics_rollup($day);
            if ($one['skipped'] !== null) {
                $result['skipped'][$day] = $one['skipped'];
                continue;
            }
            $result['days'][] = $day;
            $result['rows']  += $one['rows'];
        } catch (Throwable $e) {
            $result['errors'][$day] = $e->getMessage();
            ErrorHandler::log('error', 'analytics rollup failed for ' . $day . ': ' . $e->getMessage());
        }
    }

    // The trailing commerce window. Cheap - one grouped read of `orders` per
    // day, and a write only when something actually moved - which is why it
    // can run on every pass instead of being scheduled separately.
    if (($options['commerce'] ?? true) && empty($options['day'])) {
        $window = an_rollup_commerce_window();
        foreach (an_rollup_days_between(date('Y-m-d', strtotime('-' . $window . ' days')), $today) as $day) {
            if (in_array($day, $result['days'], true)) {
                continue;   // just recomputed in full
            }
            try {
                if (analytics_rollup_commerce($day)) {
                    $result['commerce']++;
                }
            } catch (Throwable $e) {
                $result['errors'][$day] = $e->getMessage();
            }
        }
    }

    // How far the cursor may move. Today is never "through": it is still
    // happening, and a run at noon would otherwise mark it done.
    //
    // A REBUILD is the trap here. "Rebuild the last 7 days" on a store that
    // has never rolled up at all would otherwise mark everything before those
    // seven days as complete, and those days would never be totalled - the
    // cursor says they are done, so nothing looks at them again. So a rebuild
    // only advances the cursor when it starts inside or next to the range
    // already covered.
    $rebuildContiguous = empty($options['rebuild'])
        || ($result['through'] !== ''
            && date('Y-m-d', strtotime((string) $options['rebuild'])) <= date('Y-m-d', strtotime($result['through'] . ' +1 day')));

    if ($result['errors'] === [] && empty($options['day']) && $rebuildContiguous) {
        if (!$truncated) {
            $result['through'] = $yesterday;
        } elseif ($result['days'] !== []) {
            // Caught up as far as the last day we finished, minus one, so the
            // day we stopped in the middle of is examined again next time.
            $last = (string) end($result['days']);
            $stop = date('Y-m-d', strtotime($last . ' -1 day'));
            $result['through'] = max($result['through'], min($stop, $yesterday));
        }

        if ($result['through'] !== '') {
            analytics_state_set('rollup.through', $result['through']);
        }
        analytics_state_set('rollup.ran_at', $startedAt);
        analytics_state_set('rollup.ev_max', (string) $eventMax);
        analytics_state_set('rollup.se_max', (string) $sessionMax);
    }

    $result['ms'] = round((hrtime(true) - $start) / 1e6, 1);

    if (empty($options['day'])) {
        setting_save('analytics_rollup_last_run', json_encode([
            'at'       => $startedAt,
            'days'     => count($result['days']),
            'rows'     => $result['rows'],
            'commerce' => $result['commerce'],
            'ms'       => $result['ms'],
            'through'  => $result['through'],
            'errors'   => count($result['errors']),
            'by'       => PHP_SAPI === 'cli' ? 'cron' : 'on demand',
        ], JSON_UNESCAPED_SLASHES), 'analytics', 'text');
    }

    return $result;
}

/**
 * Which days need recomputing, oldest first.
 *
 * Three sources, unioned:
 *   - every day from the cursor to today that has not been rolled up yet,
 *     which is what a first run and a store that was quiet for a week need;
 *   - the day of any SESSION created or touched since the last run started,
 *     which covers a heartbeat after midnight and a purchase on a visit that
 *     began before the run;
 *   - the day of any EVENT whose id is above the last run's watermark, which
 *     is the only thing that catches an event written into a day that was
 *     already rolled up - a back-dated import, or an event on a session that
 *     the funnel bits did not touch.
 *
 * @return string[]
 */
function analytics_rollup_dirty_days(): array
{
    $today   = date('Y-m-d');
    $through = analytics_state('rollup.through', '');
    $since   = analytics_state('rollup.ran_at', '');
    $eventMax = (int) analytics_state('rollup.ev_max', '0');
    $sessionMax = (int) analytics_state('rollup.se_max', '0');

    $days = [];

    // 1. Never rolled up.
    if ($through === '') {
        $first = Database::fetchColumn('SELECT MIN(`day`) FROM `an_sessions`');
        if ($first === null) {
            $first = Database::fetchColumn(
                'SELECT MIN(DATE(`created_at`)) FROM `orders` WHERE `status` IN (' . REVENUE_ORDER_STATUSES_SQL . ')'
            );
        }
        if ($first !== null && $first !== false) {
            foreach (an_rollup_days_between((string) $first, $today) as $d) {
                $days[$d] = true;
            }
        }
    } else {
        foreach (an_rollup_days_between(date('Y-m-d', strtotime($through . ' +1 day')), $today) as $d) {
            $days[$d] = true;
        }
    }

    // 2. Sessions created or touched since the last run began.
    $params = ['id' => $sessionMax];
    $where  = 's.`id` > :id';
    if ($since !== '') {
        $where .= ' OR s.`last_seen_at` >= :since';
        $params['since'] = $since;
    }
    foreach (Database::fetchColumnAll(
        'SELECT DISTINCT s.`day` FROM `an_sessions` s WHERE ' . $where,
        $params
    ) as $d) {
        $days[(string) $d] = true;
    }

    // 3. Events inserted since the last run, at whatever day they belong to.
    foreach (Database::fetchColumnAll(
        'SELECT DISTINCT COALESCE(s.`day`, DATE(e.`created_at`)) AS d
           FROM `an_events` e
           LEFT JOIN `an_sessions` s ON s.`id` = e.`session_id`
          WHERE e.`id` > :id',
        ['id' => $eventMax]
    ) as $d) {
        $days[(string) $d] = true;
    }

    $list = array_keys($days);
    sort($list);

    // Nothing in the future: a clock skew on a shared host should not create
    // a rollup row for tomorrow.
    return array_values(array_filter($list, static fn(string $d): bool => $d <= $today));
}

/**
 * The on-demand rollup, for a store with no cron.
 *
 * Called by the metric layer before a screen reads. It is bounded in three
 * ways, because this runs inside somebody's page load: at most one attempt
 * every fifteen minutes, at most three days of catching up, and never at all
 * if another rollup already holds the lock. A store with cron never reaches
 * the work - the cursor is already at yesterday, and the check costs one
 * indexed read.
 */
function analytics_rollup_lazy(): void
{
    // Once per request, whatever a screen calls. Every metric function calls
    // this so that no screen can forget to, which means a dashboard drawing
    // eight panels would otherwise ask eight times.
    static $done = false;

    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!setting_bool('analytics_rollup_on_demand', true) || !analytics_tables_ready()) {
            return;
        }

        $through = analytics_state('rollup.through', '');
        if ($through !== '' && $through >= date('Y-m-d', strtotime('-1 day'))) {
            return;     // already up to date
        }

        $last = analytics_state('rollup.lazy_at', '');
        if ($last !== '' && (time() - strtotime($last)) < AN_LAZY_THROTTLE) {
            return;     // somebody just tried
        }

        analytics_state_set('rollup.lazy_at', date('Y-m-d H:i:s'));

        if (!analytics_rollup_lock()) {
            return;     // a cron run is doing it properly
        }

        try {
            analytics_rollup_run(['days' => AN_LAZY_MAX_DAYS, 'commerce' => true]);
        } finally {
            analytics_rollup_unlock();
        }
    } catch (Throwable $e) {
        // A report that cannot roll up still shows the numbers it has.
        ErrorHandler::log('warning', 'on-demand analytics rollup failed: ' . $e->getMessage());
    }
}

/**
 * The advisory lock. Two rollups at once would each delete the other's rows
 * between the DELETE and the INSERT of the same day, and the survivor would
 * be a day that is missing half its traffic.
 *
 * GET_LOCK is held by the CONNECTION, so it is released by the unlock below
 * and again by the connection closing - a crashed run never leaves it stuck.
 */
function analytics_rollup_lock(int $waitSeconds = 0): bool
{
    return (int) Database::fetchColumn("SELECT GET_LOCK('sik_an_rollup', :w)", ['w' => $waitSeconds]) === 1;
}

function analytics_rollup_unlock(): void
{
    try {
        Database::query("SELECT RELEASE_LOCK('sik_an_rollup')");
    } catch (Throwable $e) {
        // Closing the connection releases it anyway.
    }
}

// ===========================================================================
//  Retention
// ===========================================================================

/** Days of raw rows to keep. The owner's setting, clamped to the allowed range. */
function analytics_retention_days(): int
{
    return max(30, min(180, setting_int('analytics_retention_days', 60)));
}

/** How far back units and revenue are re-derived from `orders` on every run. */
function an_rollup_commerce_window(): int
{
    return max(1, min(180, setting_int('analytics_commerce_window_days', 45)));
}

/**
 * Delete raw rows past the retention window.
 *
 * TWO SAFETY RULES, both of which have to hold or the store loses history:
 *
 *   1. Nothing is deleted for a day that has not been rolled up. The daily
 *      totals are the permanent record; deleting the raw rows first would
 *      leave a hole no run could ever fill.
 *   2. How far it got is written to `purge.through`, and analytics_rollup()
 *      refuses any day at or before it. Without that, the next run would
 *      recompute the emptied days as zero and overwrite the very numbers this
 *      job was preserving.
 *
 * Deletes run in batches, because one unbounded DELETE over a few hundred
 * thousand rows holds a lock long enough to stall a checkout. A run takes
 * what it can and says so; the next one takes the rest.
 *
 * @param array $options dry_run (bool), days (override), max_batches (int)
 * @return array{cutoff:string, through:string, deleted:array<string,int>, capped:string[], dry:bool, ms:float}
 */
function analytics_purge(array $options = []): array
{
    $start      = hrtime(true);
    $dry        = !empty($options['dry_run']);
    $days       = isset($options['days']) ? max(1, (int) $options['days']) : analytics_retention_days();
    $maxBatches = max(1, min(1000, (int) ($options['max_batches'] ?? AN_PURGE_BATCHES)));

    // The oldest day that may stay. Everything strictly before it goes.
    $cutoff  = date('Y-m-d', strtotime('-' . $days . ' days'));
    $rolled  = analytics_state('rollup.through', '');

    // Rule 1: never past the rollup.
    $lastDeletable = date('Y-m-d', strtotime($cutoff . ' -1 day'));
    if ($rolled === '' || $rolled < $lastDeletable) {
        $lastDeletable = $rolled;
    }

    $result = ['cutoff' => $cutoff, 'through' => $lastDeletable, 'deleted' => [], 'capped' => [],
        'dry' => $dry, 'ms' => 0.0];

    if ($lastDeletable === '') {
        // Nothing has ever been rolled up, so nothing may be deleted yet.
        $result['ms'] = round((hrtime(true) - $start) / 1e6, 1);
        return $result;
    }

    // A page view or an event belonging to a visit that crossed midnight has
    // its own timestamp on the NEXT day, so the row cutoff is the day after
    // the last deletable session day. Such a row outlives its session by a
    // day and is invisible to every report in the meantime, because every
    // report reaches page views through their session.
    $rowCutoff = date('Y-m-d', strtotime($lastDeletable . ' +1 day')) . ' 00:00:00';

    $targets = [
        // Children first: deleting sessions first would orphan these, and an
        // interrupted run would leave rows nothing can ever find again.
        'an_pageviews' => ['`created_at` < :c', ['c' => $rowCutoff]],
        'an_events'    => ['`created_at` < :c', ['c' => $rowCutoff]],
        'an_vitals'    => ['`created_at` < :c', ['c' => $rowCutoff]],
        'an_sessions'  => ['`day` <= :c', ['c' => $lastDeletable]],
        // The only table holding a key that survives a day, and only for
        // visitors who consented. Same window.
        'an_visitors'  => ['`last_seen` < :c', ['c' => $cutoff]],
        // Two rows in normal operation; this is the sweep for a store that
        // stopped getting traffic with an old salt still on the table. An old
        // salt is what would make yesterday's visitor keys linkable, so
        // deleting it is a privacy property, not tidiness.
        'an_salts'     => ['`day` < :c', ['c' => date('Y-m-d', strtotime('-1 day'))]],
    ];

    foreach ($targets as $table => [$where, $params]) {
        if ($dry) {
            $result['deleted'][$table] = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where,
                $params
            );
            continue;
        }

        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $affected = Database::query(
                'DELETE FROM `' . $table . '` WHERE ' . $where . ' LIMIT ' . AN_PURGE_BATCH,
                $params
            )->rowCount();

            $deleted += $affected;

            if ($affected < AN_PURGE_BATCH) {
                break;
            }
            if ($batch === $maxBatches - 1) {
                $result['capped'][] = $table;
            }
        }

        $result['deleted'][$table] = $deleted;
    }

    if (!$dry) {
        // Rule 2. Only once the sessions are really gone: a capped run still
        // deleted everything BEFORE its cap, and the cursor is what stops the
        // rollup writing zeroes over the days it emptied.
        if (!in_array('an_sessions', $result['capped'], true)) {
            analytics_state_set('purge.through', $lastDeletable);
        }
        analytics_state_set('purge.ran_at', date('Y-m-d H:i:s'));
    }

    $result['ms'] = round((hrtime(true) - $start) / 1e6, 1);

    return $result;
}

/**
 * Erase everything analytics has ever collected, raw and rolled up.
 *
 * Offered on the settings screen because "stop counting" and "forget what you
 * counted" are different requests and an owner who wants the second one
 * should not have to ask a developer. The dictionaries go too: a path table
 * is a list of every URL this store has served, which is not nothing.
 *
 * @return array<string,int> table => rows deleted
 */
function analytics_erase_all(): array
{
    $tables = ['an_daily_totals', 'an_daily_traffic', 'an_daily_dim', 'an_daily_page',
        'an_daily_product', 'an_daily_event', 'an_daily_vitals',
        'an_pageviews', 'an_events', 'an_vitals', 'an_sessions',
        'an_visitors', 'an_paths', 'an_sources', 'an_salts'];

    $deleted = [];
    foreach ($tables as $table) {
        // DELETE, not TRUNCATE: TRUNCATE is DDL, it commits implicitly and it
        // cannot be rolled back if the next statement fails.
        $deleted[$table] = Database::query('DELETE FROM `' . $table . '`')->rowCount();
    }

    // The cursors have to go too, or the next run believes it has already
    // rolled up days that no longer exist and skips them for ever.
    $deleted['an_state'] = Database::delete(
        'an_state',
        "`k` LIKE 'rollup.%' OR `k` LIKE 'purge.%' OR `k` LIKE 'rejects.%'"
    );

    setting_save('analytics_rollup_last_run', '', 'analytics', 'text');

    return $deleted;
}

// ===========================================================================
//  Status, for the settings screen
// ===========================================================================

/**
 * Everything the owner needs to answer "are my numbers up to date".
 *
 * @return array{ready:bool, through:?string, lag_days:int, last_run:?array, purge:?array,
 *               oldest_raw:?string, raw_rows:int, rollup_rows:int, retention_days:int}
 */
function analytics_rollup_status(): array
{
    $status = [
        'ready'          => analytics_tables_ready() && an_rollup_table_ready(),
        'through'        => null,
        'lag_days'       => 0,
        'last_run'       => null,
        'purge'          => null,
        'oldest_raw'     => null,
        'raw_rows'       => 0,
        'rollup_rows'    => 0,
        'retention_days' => analytics_retention_days(),
    ];

    if (!$status['ready']) {
        return $status;
    }

    $through = analytics_state('rollup.through', '');
    $status['through'] = $through === '' ? null : $through;
    $status['lag_days'] = $through === ''
        ? 0
        : max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime($through)) / 86400) - 1);

    $raw = setting('analytics_rollup_last_run', '');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $status['last_run'] = is_array($decoded) ? $decoded : null;
    }

    $purgeAt = analytics_state('purge.ran_at', '');
    if ($purgeAt !== '') {
        $status['purge'] = ['at' => $purgeAt, 'through' => analytics_state('purge.through', '')];
    }

    $oldest = Database::fetchColumn('SELECT MIN(`day`) FROM `an_sessions`');
    $status['oldest_raw'] = $oldest === null || $oldest === false ? null : (string) $oldest;

    // Two cheap counts, on this screen only. An owner deciding whether to
    // shorten the retention window needs to see what it is holding.
    $status['raw_rows'] = (int) Database::fetchColumn('SELECT COUNT(*) FROM `an_pageviews`')
        + (int) Database::fetchColumn('SELECT COUNT(*) FROM `an_sessions`')
        + (int) Database::fetchColumn('SELECT COUNT(*) FROM `an_events`');
    $status['rollup_rows'] = (int) Database::fetchColumn('SELECT COUNT(*) FROM `an_daily_totals`');

    return $status;
}

/** Do B3's own two tables exist? Cached per request. */
function an_rollup_table_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        try {
            Database::fetchColumn('SELECT 1 FROM `an_daily_totals` LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }

    return $ready;
}

// ===========================================================================
//  Small shared helpers
// ===========================================================================

/** Insert rollup rows in chunks. One statement per chunk, no upserts. */
function an_rollup_insert(string $table, array $columns, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $columnSql = '`' . implode('`, `', $columns) . '`';
    $written   = 0;

    foreach (array_chunk($rows, AN_ROLLUP_CHUNK) as $chunk) {
        $placeholders = [];
        $params       = [];
        $i            = 0;

        foreach ($chunk as $row) {
            $names = [];
            foreach ($row as $value) {
                $key = 'p' . $i++;
                $names[] = ':' . $key;
                $params[$key] = $value;
            }
            $placeholders[] = '(' . implode(', ', $names) . ')';
        }

        Database::query(
            'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES ' . implode(', ', $placeholders),
            $params
        );

        $written += count($chunk);
    }

    return $written;
}

/** Every date from $from to $to inclusive, as Y-m-d, capped at 800 days. */
function an_rollup_days_between(string $from, string $to): array
{
    $days = [];
    $at   = strtotime($from);
    $end  = strtotime($to);

    for ($i = 0; $at <= $end && $i < 800; $i++) {
        $days[] = date('Y-m-d', $at);
        $at = strtotime('+1 day', $at);
    }

    return $days;
}

function an_rollup_next_day(string $day): string
{
    return date('Y-m-d', strtotime($day . ' +1 day'));
}
