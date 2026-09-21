<?php
/**
 * ShopInnKart Admin - Report module shared pieces.
 *
 * Five report screens and one CSV endpoint all have to agree on what counts as
 * revenue and how a date range is bucketed, otherwise two pages showing "the
 * same" number quietly disagree. Both decisions live here, once.
 */

declare(strict_types=1);

// Included by admin pages only — there is nothing to run on its own.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// ===========================================================================
//  Revenue definition
// ===========================================================================

/**
 * Statuses whose money is real. Kept as a module-local name because every
 * report already reads it, but the list itself now lives in
 * config/constants.php so the dashboard, the reports and the customer screens
 * cannot drift apart again.
 */
const REPORT_REVENUE_STATUSES = REVENUE_ORDER_STATUSES;

/**
 * The revenue condition for an orders alias.
 * The list is a hard-coded constant, never request data, so inlining it keeps
 * the placeholder budget free for the date range.
 */
function report_revenue_sql(string $alias = 'o'): string
{
    return $alias . ".`status` IN ('" . implode("','", REPORT_REVENUE_STATUSES) . "')";
}

/** True when this status contributes to revenue. Used to annotate status tables. */
function report_status_earns(string $status): bool
{
    return in_array($status, REPORT_REVENUE_STATUSES, true);
}

// ===========================================================================
//  Navigation
// ===========================================================================

/** The five report screens, in sidebar order. */
function report_pages(): array
{
    return [
        'sales'     => 'Sales',
        'products'  => 'Products',
        'orders'    => 'Orders',
        'customers' => 'Customers',
        'marketing' => 'Marketing',
    ];
}

/** Tab strip across the report screens, carrying the current date range along. */
function report_nav(string $current, array $filters): string
{
    $html = '<div class="ad-tabs">';
    foreach (report_pages() as $key => $label) {
        $url = admin_url('reports/' . $key . '.php') . '?' . http_build_query($filters['query']);
        $html .= '<a class="ad-tab' . ($key === $current ? ' is-active' : '') . '"'
            . ' href="' . e($url) . '">' . e($label) . '</a>';
    }
    return $html . '</div>';
}

// ===========================================================================
//  Time series
// ===========================================================================

/** Bucket expression matching the current grouping. */
function report_bucket_sql(string $column, string $group): string
{
    return $group === 'month' ? "DATE_FORMAT({$column}, '%Y-%m')" : "DATE({$column})";
}

/**
 * Turn "bucket => value" rows into a chart series.
 *
 * Buckets with no rows still have to appear, otherwise a quiet Tuesday looks
 * like it never happened, so the series is built from the range rather than
 * from whatever the query returned.
 */
function report_fill_series(array $rows, array $filters): array
{
    $series = [];

    if ($filters['group'] === 'month') {
        $cursor = (int) strtotime(date('Y-m-01', (int) strtotime($filters['from'])));
        $end    = (int) strtotime(date('Y-m-01', (int) strtotime($filters['to'])));
        while ($cursor <= $end) {
            $key = date('Y-m', $cursor);
            $series[] = ['key' => $key, 'label' => date('M y', $cursor), 'value' => (float) ($rows[$key] ?? 0)];
            $cursor = (int) strtotime('+1 month', $cursor);
        }
        return $series;
    }

    $cursor = (int) strtotime($filters['from']);
    $end    = (int) strtotime($filters['to']);
    while ($cursor <= $end) {
        $key = date('Y-m-d', $cursor);
        $series[] = ['key' => $key, 'label' => date('d M', $cursor), 'value' => (float) ($rows[$key] ?? 0)];
        $cursor = (int) strtotime('+1 day', $cursor);
    }
    return $series;
}

// ===========================================================================
//  Presentation helpers
// ===========================================================================

/**
 * Stat tile carrying a delta against the previous equivalent period.
 * admin_stat_card() escapes its sub-line, so the delta badge needs its own
 * tile — the markup is otherwise identical.
 */
function report_stat_card(
    string $label,
    string $value,
    string $iconName,
    string $tone,
    float $current,
    float $previous,
    string $sub = 'vs previous period'
): string {
    return '<div class="ad-stat">'
        . '<span class="ad-stat__icon ad-stat__icon--' . e_attr($tone) . '">' . icon($iconName, 'w-5 h-5') . '</span>'
        . '<span class="ad-stat__body">'
        . '<span class="ad-stat__value">' . e($value) . '</span>'
        . '<span class="ad-stat__label">' . e($label) . '</span>'
        . '<span class="ad-stat__sub">' . admin_delta_badge(admin_delta($current, $previous)) . ' ' . e($sub) . '</span>'
        . '</span></div>';
}

/** Share of a whole, guarded against a zero denominator. */
function report_percent(float $part, float $whole): float
{
    return $whole > 0 ? ($part / $whole) * 100 : 0.0;
}

/** "12.4%" for a ratio already expressed as a percentage. */
function report_percent_text(float $percent): string
{
    return number_format($percent, 1) . '%';
}

/** Minutes as something a human reads at a glance. */
function report_duration(?float $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        return '—';
    }
    if ($minutes < 90) {
        return round($minutes) . ' min';
    }
    $hours = $minutes / 60;
    if ($hours < 48) {
        return number_format($hours, 1) . ' hrs';
    }
    return number_format($hours / 24, 1) . ' days';
}

// ===========================================================================
//  Lookups shared by several reports
// ===========================================================================

/** payment_method code => display name. Orders store the code. */
function report_payment_method_names(): array
{
    return Database::fetchPairs('SELECT `code`, `name` FROM `payment_methods` ORDER BY `sort_order`, `id`');
}

/** shipping_method code => display name. */
function report_shipping_method_names(): array
{
    return Database::fetchPairs('SELECT `code`, `name` FROM `shipping_methods` ORDER BY `sort_order`, `id`');
}

// ===========================================================================
//  Shared aggregates (screens and the CSV export both read these)
// ===========================================================================

/**
 * The seven headline sales numbers for a window.
 * Called twice per sales report — once for the range, once for the previous
 * equivalent range — so the deltas cannot drift from the tiles.
 */
function report_sales_totals(string $fromDt, string $toDt): array
{
    $row = Database::fetch(
        'SELECT COUNT(*)                              AS order_count,
                COALESCE(SUM(o.`total_amount`), 0)    AS revenue,
                COALESCE(SUM(o.`discount_amount`), 0) AS discount,
                COALESCE(SUM(o.`shipping_amount`), 0) AS shipping,
                COALESCE(SUM(o.`tax_amount`), 0)      AS tax
         FROM `orders` o
         WHERE ' . report_revenue_sql() . ' AND o.`created_at` BETWEEN :from AND :to',
        ['from' => $fromDt, 'to' => $toDt]
    ) ?? [];

    $units = (int) Database::fetchColumn(
        'SELECT COALESCE(SUM(oi.`quantity`), 0)
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE ' . report_revenue_sql() . ' AND o.`created_at` BETWEEN :from AND :to',
        ['from' => $fromDt, 'to' => $toDt]
    );

    $orders  = (int) ($row['order_count'] ?? 0);
    $revenue = (float) ($row['revenue'] ?? 0);

    return [
        'orders'   => $orders,
        'revenue'  => $revenue,
        'aov'      => $orders > 0 ? $revenue / $orders : 0.0,
        'units'    => $units,
        'discount' => (float) ($row['discount'] ?? 0),
        'shipping' => (float) ($row['shipping'] ?? 0),
        'tax'      => (float) ($row['tax'] ?? 0),
    ];
}

/**
 * Split the window's buyers into first-timers and repeat buyers.
 *
 * Guests have no user_id, so a customer is identified by their account when
 * there is one and by their email otherwise — the same person checking out as
 * a guest twice still counts once.
 */
function report_new_vs_returning(string $fromDt, string $toDt): array
{
    $revenue = report_revenue_sql('o');
    $key     = 'COALESCE(CAST(o.`user_id` AS CHAR), o.`customer_email`)';

    $row = Database::fetch(
        'SELECT COALESCE(SUM(x.`is_new`), 0)                       AS new_customers,
                COALESCE(SUM(1 - x.`is_new`), 0)                   AS returning_customers,
                COALESCE(SUM(x.`is_new` * x.`orders`), 0)          AS new_orders,
                COALESCE(SUM((1 - x.`is_new`) * x.`orders`), 0)    AS returning_orders,
                COALESCE(SUM(x.`is_new` * x.`revenue`), 0)         AS new_revenue,
                COALESCE(SUM((1 - x.`is_new`) * x.`revenue`), 0)   AS returning_revenue
         FROM (
            SELECT CASE WHEN f.`first_at` >= :first_from THEN 1 ELSE 0 END AS is_new,
                   r.`orders`, r.`revenue`
            FROM (
                SELECT ' . $key . ' AS ckey, MIN(o.`created_at`) AS first_at
                FROM `orders` o
                WHERE ' . $revenue . '
                GROUP BY ckey
            ) f
            INNER JOIN (
                SELECT ' . $key . ' AS ckey,
                       COUNT(*) AS `orders`,
                       COALESCE(SUM(o.`total_amount`), 0) AS revenue
                FROM `orders` o
                WHERE ' . $revenue . ' AND o.`created_at` BETWEEN :from AND :to
                GROUP BY ckey
            ) r ON r.`ckey` = f.`ckey`
         ) x',
        ['first_from' => $fromDt, 'from' => $fromDt, 'to' => $toDt]
    ) ?? [];

    return [
        'new_customers'       => (int) ($row['new_customers'] ?? 0),
        'returning_customers' => (int) ($row['returning_customers'] ?? 0),
        'new_orders'          => (int) ($row['new_orders'] ?? 0),
        'returning_orders'    => (int) ($row['returning_orders'] ?? 0),
        'new_revenue'         => (float) ($row['new_revenue'] ?? 0),
        'returning_revenue'   => (float) ($row['returning_revenue'] ?? 0),
    ];
}

/**
 * Lifetime loyalty numbers. These are deliberately not date-filtered: a
 * lifetime value that only looks at 30 days is not a lifetime value.
 */
function report_lifetime_stats(): array
{
    $row = Database::fetch(
        'SELECT COUNT(*) AS customers,
                COALESCE(SUM(CASE WHEN x.`orders` >= 2 THEN 1 ELSE 0 END), 0) AS repeat_customers,
                COALESCE(SUM(x.`spend`), 0) AS total_spend,
                COALESCE(SUM(x.`orders`), 0) AS total_orders
         FROM (
            SELECT COALESCE(CAST(o.`user_id` AS CHAR), o.`customer_email`) AS ckey,
                   COUNT(*) AS `orders`,
                   COALESCE(SUM(o.`total_amount`), 0) AS spend
            FROM `orders` o
            WHERE ' . report_revenue_sql() . '
            GROUP BY ckey
         ) x'
    ) ?? [];

    $customers = (int) ($row['customers'] ?? 0);
    $spend     = (float) ($row['total_spend'] ?? 0);
    $orders    = (int) ($row['total_orders'] ?? 0);

    return [
        'customers'        => $customers,
        'repeat_customers' => (int) ($row['repeat_customers'] ?? 0),
        'total_spend'      => $spend,
        'total_orders'     => $orders,
        'repeat_rate'      => report_percent((float) ($row['repeat_customers'] ?? 0), (float) $customers),
        'avg_ltv'          => $customers > 0 ? $spend / $customers : 0.0,
        'avg_orders'       => $customers > 0 ? $orders / $customers : 0.0,
    ];
}

/** Best sellers in the window, ranked by units or by revenue. */
function report_top_products(string $fromDt, string $toDt, string $rankBy = 'revenue', int $limit = 10): array
{
    // Column name comes from this branch, never from the request.
    $order = $rankBy === 'units' ? 'units DESC, revenue DESC' : 'revenue DESC, units DESC';
    $limit = max(1, min(200, $limit));

    return Database::fetchAll(
        'SELECT oi.`product_id` AS id, p.`name`, p.`slug`, p.`sku`, p.`main_image`, p.`stock`, p.`views`,
                SUM(oi.`quantity`)         AS units,
                SUM(oi.`subtotal`)         AS revenue,
                COUNT(DISTINCT o.`id`)     AS orders
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         INNER JOIN `products` p ON p.`id` = oi.`product_id`
         WHERE ' . report_revenue_sql() . ' AND o.`created_at` BETWEEN :from AND :to
         GROUP BY oi.`product_id`, p.`name`, p.`slug`, p.`sku`, p.`main_image`, p.`stock`, p.`views`
         ORDER BY ' . $order . '
         LIMIT ' . $limit,
        ['from' => $fromDt, 'to' => $toDt]
    );
}

/**
 * Units and revenue per product for the window, keyed by product id.
 * Products with no sales are absent — callers LEFT JOIN or COALESCE.
 */
function report_units_sold_by_product(string $fromDt, string $toDt): array
{
    $rows = Database::fetchAll(
        'SELECT oi.`product_id`, SUM(oi.`quantity`) AS units, SUM(oi.`subtotal`) AS revenue
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE ' . report_revenue_sql() . ' AND o.`created_at` BETWEEN :from AND :to
           AND oi.`product_id` IS NOT NULL
         GROUP BY oi.`product_id`',
        ['from' => $fromDt, 'to' => $toDt]
    );

    $byProduct = [];
    foreach ($rows as $row) {
        $byProduct[(int) $row['product_id']] = [
            'units'   => (int) $row['units'],
            'revenue' => (float) $row['revenue'],
        ];
    }
    return $byProduct;
}

/**
 * Sales of a fixed product set inside a window.
 * Deals and flash sales each own their own product list and their own live
 * window, so their performance is measured one campaign at a time.
 */
function report_product_set_sales(array $productIds, string $fromDt, string $toDt): array
{
    $zero = ['orders' => 0, 'units' => 0, 'revenue' => 0.0];

    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if ($productIds === [] || strtotime($fromDt) > strtotime($toDt)) {
        return $zero;
    }

    [$placeholders, $idParams] = Database::inPlaceholders($productIds, 'pid');

    $row = Database::fetch(
        'SELECT COUNT(DISTINCT o.`id`)               AS orders,
                COALESCE(SUM(oi.`quantity`), 0)      AS units,
                COALESCE(SUM(oi.`subtotal`), 0)      AS revenue
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE ' . report_revenue_sql() . '
           AND o.`created_at` BETWEEN :from AND :to
           AND oi.`product_id` IN (' . $placeholders . ')',
        array_merge(['from' => $fromDt, 'to' => $toDt], $idParams)
    ) ?? [];

    return [
        'orders'  => (int) ($row['orders'] ?? 0),
        'units'   => (int) ($row['units'] ?? 0),
        'revenue' => (float) ($row['revenue'] ?? 0),
    ];
}

/**
 * Campaign performance for deals or flash sales overlapping the window.
 * A campaign is only credited for orders placed while it was actually live,
 * so the measured window is the overlap of the campaign and the report range.
 */
function report_campaign_performance(string $type, array $filters, int $limit = 15): array
{
    $limit = max(1, min(100, $limit));

    if ($type === 'flash') {
        $campaigns = Database::fetchAll(
            'SELECT `id`, `name` AS title, `discount_type`, `discount_value`, `start_time`, `end_time`, `status`
             FROM `flash_sales`
             WHERE `start_time` <= :to AND `end_time` >= :from
             ORDER BY `start_time` DESC
             LIMIT ' . $limit,
            ['from' => $filters['from_dt'], 'to' => $filters['to_dt']]
        );
    } else {
        $campaigns = Database::fetchAll(
            'SELECT `id`, `title`, `product_id`, `discount_type`, `discount_value`,
                    `stock_limit`, `stock_sold`, `start_time`, `end_time`, `status`
             FROM `deals`
             WHERE `start_time` <= :to AND `end_time` >= :from
             ORDER BY `start_time` DESC
             LIMIT ' . $limit,
            ['from' => $filters['from_dt'], 'to' => $filters['to_dt']]
        );
    }

    foreach ($campaigns as &$campaign) {
        $id = (int) $campaign['id'];

        if ($type === 'flash') {
            $productIds = Database::fetchColumnAll(
                'SELECT `product_id` FROM `flash_sale_products` WHERE `flash_sale_id` = :id',
                ['id' => $id]
            );
            $campaign['stock_sold'] = (int) Database::fetchColumn(
                'SELECT COALESCE(SUM(`stock_sold`), 0) FROM `flash_sale_products` WHERE `flash_sale_id` = :id',
                ['id' => $id]
            );
        } else {
            $productIds = Database::fetchColumnAll(
                'SELECT `product_id` FROM `deal_products` WHERE `deal_id` = :id',
                ['id' => $id]
            );
            if (!empty($campaign['product_id'])) {
                $productIds[] = (int) $campaign['product_id'];
            }
        }

        // Intersect the campaign window with the report range.
        $windowFrom = max($filters['from_dt'], (string) $campaign['start_time']);
        $windowTo   = min($filters['to_dt'], (string) $campaign['end_time']);

        $campaign['product_count'] = count(array_unique(array_map('intval', $productIds)));
        $campaign['window_from']   = $windowFrom;
        $campaign['window_to']     = $windowTo;
        $campaign['sales']         = report_product_set_sales($productIds, $windowFrom, $windowTo);
        $campaign['is_live']       = schedule_is_live($campaign['start_time'], $campaign['end_time'])
            && $campaign['status'] === STATUS_ACTIVE;
    }
    unset($campaign);

    return $campaigns;
}

/** Coupon performance for the window, including coupons nobody redeemed. */
function report_coupon_performance(array $filters, int $limit = 25): array
{
    $limit = max(1, min(200, $limit));

    return Database::fetchAll(
        'SELECT c.`id`, c.`code`, c.`type`, c.`value`, c.`status`, c.`used_count`, c.`usage_limit`,
                COUNT(o.`id`)                          AS redemptions,
                COALESCE(SUM(o.`discount_amount`), 0)  AS discount_given,
                COALESCE(SUM(o.`total_amount`), 0)     AS revenue_influenced
         FROM `coupons` c
         LEFT JOIN `orders` o
                ON o.`coupon_id` = c.`id`
               AND ' . report_revenue_sql() . '
               AND o.`created_at` BETWEEN :from AND :to
         GROUP BY c.`id`, c.`code`, c.`type`, c.`value`, c.`status`, c.`used_count`, c.`usage_limit`
         ORDER BY redemptions DESC, discount_given DESC, c.`code` ASC
         LIMIT ' . $limit,
        ['from' => $filters['from_dt'], 'to' => $filters['to_dt']]
    );
}

/** Search terms for the window. Zero-result terms are the actionable ones. */
function report_search_terms(array $filters, int $limit = 25, bool $zeroOnly = false): array
{
    $limit = max(1, min(500, $limit));
    $having = $zeroOnly ? 'HAVING MAX(s.`results_count`) = 0' : '';

    return Database::fetchAll(
        'SELECT s.`query`,
                COUNT(*)                    AS searches,
                MAX(s.`results_count`)      AS best_results,
                COUNT(DISTINCT s.`session_id`) AS sessions
         FROM `search_logs` s
         WHERE s.`created_at` BETWEEN :from AND :to
         GROUP BY s.`query`
         ' . $having . '
         ORDER BY searches DESC, s.`query` ASC
         LIMIT ' . $limit,
        ['from' => $filters['from_dt'], 'to' => $filters['to_dt']]
    );
}
