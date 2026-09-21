<?php
/**
 * ShopInnKart Admin - Customer list filters.
 *
 * index.php and export.php both need the exact same WHERE clause and ORDER BY,
 * otherwise the downloaded CSV quietly disagrees with the screen the admin was
 * looking at. Building both from here keeps them honest.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/**
 * Order states that count towards lifetime spend.
 *
 * This used to be the inverse — "not cancelled, returned or refunded" — which
 * quietly counted pending orders the customer has not paid for, so a customer's
 * total spend disagreed with the dashboard and the reports on the same data.
 * REVENUE_ORDER_STATUSES_SQL is the single definition all three now share.
 */
const CUSTOMER_SPEND_ORDER_STATUSES = REVENUE_ORDER_STATUSES_SQL;

/** sort key => the SQL expression it maps to. Nothing outside this reaches ORDER BY. */
const CUSTOMER_SORT_COLUMNS = [
    'name'       => 'u.`first_name`',
    'email'      => 'u.`email`',
    'created_at' => 'u.`created_at`',
    'orders'     => 'orders_count',
    'spend'      => 'lifetime_spend',
    'last_order' => 'last_order_at',
];

const CUSTOMER_STATUSES = ['active', 'inactive', 'blocked'];

/**
 * The SELECT + FROM for a customer row with its lifetime figures.
 *
 * Only `orders` is joined, and the count is DISTINCT on the order id, so a
 * customer with several orders produces one row and one correct sum rather
 * than a fan-out multiplied by every joined child table.
 */
function customer_list_select(): string
{
    return 'SELECT u.`id`, u.`first_name`, u.`last_name`, u.`email`, u.`phone`, u.`gender`,
                   u.`date_of_birth`, u.`status`, u.`created_at`, u.`last_login_at`,
                   COUNT(DISTINCT o.`id`) AS orders_count,
                   COALESCE(SUM(CASE WHEN o.`status` IN (' . CUSTOMER_SPEND_ORDER_STATUSES . ')
                                     THEN o.`total_amount` ELSE 0 END), 0) AS lifetime_spend,
                   MAX(o.`created_at`) AS last_order_at
            FROM `users` u
            LEFT JOIN `orders` o ON o.`user_id` = u.`id`';
}

/** Wrap a search term for LIKE, neutralising the wildcards a user can type. */
function customer_like(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

/**
 * Read the list filters out of the query string.
 *
 * @return array{
 *     where:string, params:array, order_by:string,
 *     q:string, status:string, has:string, from:string, to:string, sort:string, dir:string,
 *     active:bool
 * }
 */
function customer_list_filters(): array
{
    $q      = trim((string) ($_GET['q'] ?? ''));
    $status = admin_filter('status', CUSTOMER_STATUSES);
    $has    = admin_filter('has', ['yes', 'no']);

    // Accept only real dates; anything else is treated as "not set".
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $from = ($from !== '' && strtotime($from) !== false) ? date('Y-m-d', (int) strtotime($from)) : '';
    $to   = ($to !== '' && strtotime($to) !== false) ? date('Y-m-d', (int) strtotime($to)) : '';
    // Entered backwards - swap rather than silently return nothing.
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $sort = admin_safe_sort((string) ($_GET['sort'] ?? ''), array_keys(CUSTOMER_SORT_COLUMNS), 'created_at');
    $dir  = admin_safe_dir((string) ($_GET['dir'] ?? 'desc'));

    $where  = ['1'];
    $params = [];

    if ($q !== '') {
        $where[] = "(u.`first_name` LIKE :q1 OR u.`last_name` LIKE :q2 OR u.`email` LIKE :q3
                    OR u.`phone` LIKE :q4 OR CONCAT_WS(' ', u.`first_name`, u.`last_name`) LIKE :q5)";
        $like = customer_like($q);
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
    }

    if ($status !== '') {
        $where[] = 'u.`status` = :status';
        $params['status'] = $status;
    }

    if ($from !== '') {
        $where[] = 'u.`created_at` >= :from';
        $params['from'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        // Half-open upper bound so the last day is included whatever its time.
        $where[] = 'u.`created_at` < :to';
        $params['to'] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    }

    // "Has ordered" is an EXISTS rather than a HAVING on the aggregate, so the
    // COUNT(*) used for pagination can run against `users` alone.
    if ($has === 'yes') {
        $where[] = 'EXISTS (SELECT 1 FROM `orders` eo WHERE eo.`user_id` = u.`id`)';
    } elseif ($has === 'no') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM `orders` eo WHERE eo.`user_id` = u.`id`)';
    }

    return [
        'where'    => implode(' AND ', $where),
        'params'   => $params,
        'order_by' => CUSTOMER_SORT_COLUMNS[$sort] . ' ' . $dir . ', u.`id` DESC',
        'q'        => $q,
        'status'   => $status,
        'has'      => $has,
        'from'     => $from,
        'to'       => $to,
        'sort'     => $sort,
        'dir'      => strtolower($dir),
        'active'   => $q !== '' || $status !== '' || $has !== '' || $from !== '' || $to !== '',
    ];
}

/** Full name for display, tolerant of a missing last name. */
function customer_full_name(array $row): string
{
    return trim((string) $row['first_name'] . ' ' . (string) ($row['last_name'] ?? ''));
}
