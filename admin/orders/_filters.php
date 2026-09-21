<?php
/**
 * ShopInnKart Admin - Order list filter state.
 *
 * index.php and export.php must agree exactly on what "the current list" is,
 * otherwise an export silently returns a different set of rows than the screen
 * that produced it. The WHERE clause is therefore built once, here.
 */

declare(strict_types=1);

// Included by admin pages only — there is nothing to run on its own.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** Statuses that count towards revenue. Cancelled/returned/refunded never do. */
const ORDER_REVENUE_STATUS_SQL = "'confirmed','processing','packed','shipped','out_for_delivery','delivered'";

/** Sortable columns mapped to their qualified SQL name — the ORDER BY allowlist. */
const ORDER_SORT_COLUMNS = [
    'order_number' => 'o.`order_number`',
    'created_at'   => 'o.`created_at`',
    'total_amount' => 'o.`total_amount`',
    'status'       => 'o.`status`',
];

/** Date presets offered above the list. "all" skips the date condition entirely. */
const ORDER_DATE_PRESETS = [
    'all'        => 'All time',
    'today'      => 'Today',
    'yesterday'  => 'Yesterday',
    'last_7'     => 'Last 7 days',
    'last_30'    => 'Last 30 days',
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'this_year'  => 'This year',
    'custom'     => 'Custom range',
];

/**
 * Read every list filter from the query string and compile it into SQL.
 *
 * Returns the raw values (for repopulating the form) plus:
 *   where / params        - the full condition, including the status tab
 *   base_where / base_params - the same minus status, for the per-tab counts
 *   order_by              - a column from the allowlist above, never user input
 */
function order_filter_state(): array
{
    $methods = Database::fetchPairs('SELECT `code`, `name` FROM `payment_methods` ORDER BY `sort_order`, `id`');

    $q             = trim((string) ($_GET['q'] ?? ''));
    $status        = admin_filter('status', array_keys(ORDER_STATUSES));
    $paymentStatus = admin_filter('payment_status', array_keys(PAYMENT_STATUSES));
    $paymentMethod = admin_filter('payment_method', array_keys($methods));
    $preset        = admin_filter('range', array_keys(ORDER_DATE_PRESETS), 'all');

    $from = null;
    $to   = null;
    if ($preset !== 'all') {
        [$from, $to] = admin_date_range(
            $preset,
            isset($_GET['from']) ? (string) $_GET['from'] : null,
            isset($_GET['to']) ? (string) $_GET['to'] : null
        );
    }

    $minTotal = is_numeric($_GET['min_total'] ?? null) ? (float) $_GET['min_total'] : null;
    $maxTotal = is_numeric($_GET['max_total'] ?? null) ? (float) $_GET['max_total'] : null;
    // A backwards range matches nothing, which reads as a broken page.
    if ($minTotal !== null && $maxTotal !== null && $minTotal > $maxTotal) {
        [$minTotal, $maxTotal] = [$maxTotal, $minTotal];
    }

    $where  = [];
    $params = [];

    if ($q !== '') {
        // Escape the LIKE wildcards so a search for "50%" means what it says.
        $needle = '%' . addcslashes($q, '%_\\') . '%';
        // Native prepared statements cannot reuse one named placeholder, so
        // each column gets its own copy of the same value.
        $where[] = '(o.`order_number` LIKE :q_number OR o.`customer_name` LIKE :q_name'
            . ' OR o.`customer_email` LIKE :q_email OR o.`customer_phone` LIKE :q_phone)';
        $params['q_number'] = $needle;
        $params['q_name']   = $needle;
        $params['q_email']  = $needle;
        $params['q_phone']  = $needle;
    }
    if ($paymentStatus !== '') {
        $where[] = 'o.`payment_status` = :payment_status';
        $params['payment_status'] = $paymentStatus;
    }
    if ($paymentMethod !== '') {
        $where[] = 'o.`payment_method` = :payment_method';
        $params['payment_method'] = $paymentMethod;
    }
    if ($from !== null && $to !== null) {
        $where[] = 'o.`created_at` BETWEEN :date_from AND :date_to';
        $params['date_from'] = $from . ' 00:00:00';
        $params['date_to']   = $to . ' 23:59:59';
    }
    if ($minTotal !== null) {
        $where[] = 'o.`total_amount` >= :min_total';
        $params['min_total'] = $minTotal;
    }
    if ($maxTotal !== null) {
        $where[] = 'o.`total_amount` <= :max_total';
        $params['max_total'] = $maxTotal;
    }

    // The tabs show a count for every status, so they reuse this filter set
    // with the status condition left out.
    $baseWhere  = $where === [] ? '1' : implode(' AND ', $where);
    $baseParams = $params;

    if ($status !== '') {
        $where[] = 'o.`status` = :status';
        $params['status'] = $status;
    }

    $sort = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys(ORDER_SORT_COLUMNS), 'created_at');
    // admin_sort_header() compares against a lowercase direction, so keep the
    // display value lowercase and let admin_safe_dir() produce the SQL keyword.
    $dir = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    return [
        'q'               => $q,
        'status'          => $status,
        'payment_status'  => $paymentStatus,
        'payment_method'  => $paymentMethod,
        'range'           => $preset,
        'from'            => $from,
        'to'              => $to,
        'min_total'       => $minTotal,
        'max_total'       => $maxTotal,
        'sort'            => $sort,
        'dir'             => $dir,
        // `id` breaks ties so paging never shows the same row twice.
        'order_by'        => ORDER_SORT_COLUMNS[$sort] . ' ' . admin_safe_dir($dir) . ', o.`id` DESC',
        'where'           => $where === [] ? '1' : implode(' AND ', $where),
        'params'          => $params,
        'base_where'      => $baseWhere,
        'base_params'     => $baseParams,
        'payment_methods' => $methods,
        'is_filtered'     => $q !== '' || $status !== '' || $paymentStatus !== '' || $paymentMethod !== ''
            || $preset !== 'all' || $minTotal !== null || $maxTotal !== null,
    ];
}

/** Headline numbers for whatever the current filter selects. */
function order_filter_summary(array $filters): array
{
    $row = Database::fetch(
        'SELECT COUNT(*) AS order_count,
                COALESCE(SUM(CASE WHEN o.`status` IN (' . ORDER_REVENUE_STATUS_SQL . ')
                                  THEN o.`total_amount` ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN o.`status` IN (' . ORDER_REVENUE_STATUS_SQL . ')
                                  THEN 1 ELSE 0 END), 0) AS revenue_orders
         FROM `orders` o WHERE ' . $filters['where'],
        $filters['params']
    ) ?? [];

    $units = (int) Database::fetchColumn(
        'SELECT COALESCE(SUM(oi.`quantity`), 0)
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE ' . $filters['where'],
        $filters['params']
    );

    $orderCount   = (int) ($row['order_count'] ?? 0);
    $revenue      = (float) ($row['revenue'] ?? 0);
    $revenueCount = (int) ($row['revenue_orders'] ?? 0);

    return [
        'orders'  => $orderCount,
        'revenue' => $revenue,
        'aov'     => $revenueCount > 0 ? $revenue / $revenueCount : 0.0,
        'units'   => $units,
    ];
}
