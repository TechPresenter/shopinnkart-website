<?php
/**
 * ShopInnKart Admin - Report CSV export.
 *
 * One endpoint for all five reports: ?report=sales|products|orders|customers|marketing
 * plus the same range/from/to the screens use, so an export always covers
 * exactly the window the admin was looking at.
 *
 * Money is written as a plain number a spreadsheet can add up, and the larger
 * exports are streamed straight off the statement so they never have to fit in
 * memory at once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('reports.view');

require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/_filters.php';

$report     = admin_filter('report', array_keys(report_pages()), 'sales');
$filters    = report_filter_state();
$dateParams = ['from' => $filters['from_dt'], 'to' => $filters['to_dt']];
$revenueSql = report_revenue_sql();

$amount = static fn ($value): string => number_format((float) $value, 2, '.', '');
$stamp  = static fn ($value): string => $value === null ? '' : format_datetime($value, 'Y-m-d H:i');

switch ($report) {

    // -----------------------------------------------------------------------
    //  Sales: one row per chart bucket, matching sales.php exactly.
    // -----------------------------------------------------------------------
    case 'sales':
        $bucket = report_bucket_sql('o.`created_at`', $filters['group']);

        $money = Database::fetchAll(
            'SELECT ' . $bucket . ' AS bucket,
                    COUNT(*)                              AS orders,
                    COALESCE(SUM(o.`total_amount`), 0)    AS revenue,
                    COALESCE(SUM(o.`discount_amount`), 0) AS discount,
                    COALESCE(SUM(o.`shipping_amount`), 0) AS shipping,
                    COALESCE(SUM(o.`tax_amount`), 0)      AS tax
             FROM `orders` o
             WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
             GROUP BY bucket',
            $dateParams
        );

        $units = Database::fetchPairs(
            'SELECT ' . $bucket . ' AS bucket, COALESCE(SUM(oi.`quantity`), 0)
             FROM `order_items` oi
             INNER JOIN `orders` o ON o.`id` = oi.`order_id`
             WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
             GROUP BY bucket',
            $dateParams
        );

        $byBucket = [];
        foreach ($money as $row) {
            $byBucket[(string) $row['bucket']] = $row;
        }

        $headers = [
            'Period', 'Orders', 'Units', 'Revenue', 'Avg Order Value',
            'Discount Given', 'Shipping Collected', 'Tax Collected',
        ];

        $rows = [];
        // The series drives the loop so periods with no orders still export.
        foreach (report_fill_series([], $filters) as $point) {
            $row     = $byBucket[$point['key']] ?? [];
            $orders  = (int) ($row['orders'] ?? 0);
            $revenue = (float) ($row['revenue'] ?? 0);

            $rows[] = [
                $point['key'],
                $orders,
                (int) ($units[$point['key']] ?? 0),
                $amount($revenue),
                $amount($orders > 0 ? $revenue / $orders : 0),
                $amount($row['discount'] ?? 0),
                $amount($row['shipping'] ?? 0),
                $amount($row['tax'] ?? 0),
            ];
        }

        $filename = 'report-sales-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
        break;

    // -----------------------------------------------------------------------
    //  Products: every catalogue product that sold in the window.
    // -----------------------------------------------------------------------
    case 'products':
        $headers = [
            'SKU', 'Product', 'Category', 'Brand', 'Units', 'Orders', 'Revenue',
            'Avg Unit Price', 'Lifetime Views', 'Units per 100 Views', 'Stock', 'Status',
        ];

        $statement = Database::query(
            'SELECT p.`sku`, p.`name`, p.`views`, p.`stock`, p.`status`,
                    c.`name` AS category_name, b.`name` AS brand_name,
                    SUM(oi.`quantity`)     AS units,
                    SUM(oi.`subtotal`)     AS revenue,
                    COUNT(DISTINCT o.`id`) AS orders
             FROM `order_items` oi
             INNER JOIN `orders` o ON o.`id` = oi.`order_id`
             INNER JOIN `products` p ON p.`id` = oi.`product_id`
             LEFT JOIN `categories` c ON c.`id` = p.`category_id`
             LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
             WHERE ' . $revenueSql . ' AND o.`created_at` BETWEEN :from AND :to
             GROUP BY oi.`product_id`, p.`sku`, p.`name`, p.`views`, p.`stock`, p.`status`,
                      c.`name`, b.`name`
             ORDER BY revenue DESC, units DESC',
            $dateParams
        );

        $rows = (static function (PDOStatement $statement, callable $amount): Generator {
            while (($row = $statement->fetch()) !== false) {
                $units = max(1, (int) $row['units']);
                $views = max(1, (int) $row['views']);
                yield [
                    $row['sku'],
                    $row['name'],
                    $row['category_name'],
                    $row['brand_name'],
                    (int) $row['units'],
                    (int) $row['orders'],
                    $amount($row['revenue']),
                    $amount((float) $row['revenue'] / $units),
                    (int) $row['views'],
                    number_format(((int) $row['units'] / $views) * 100, 2, '.', ''),
                    (int) $row['stock'],
                    $row['status'],
                ];
            }
        })($statement, $amount);

        $filename = 'report-products-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
        break;

    // -----------------------------------------------------------------------
    //  Orders: one row per order with its fulfilment timings.
    // -----------------------------------------------------------------------
    case 'orders':
        $headers = [
            'Order Number', 'Placed At', 'Status', 'Counts As Revenue', 'Payment Method',
            'Payment Status', 'Customer', 'Email', 'City', 'State', 'Total',
            'Confirmed At', 'Shipped At', 'Delivered At', 'Cancelled At',
            'Hours To Confirm', 'Hours To Ship', 'Hours To Deliver', 'Hours Placed To Delivered',
            'Cancel Reason', 'Return Reason',
        ];

        $statement = Database::query(
            'SELECT o.`order_number`, o.`created_at`, o.`status`, o.`payment_method`, o.`payment_status`,
                    o.`customer_name`, o.`customer_email`, o.`shipping_city`, o.`shipping_state`,
                    o.`total_amount`, o.`confirmed_at`, o.`shipped_at`, o.`delivered_at`, o.`cancelled_at`,
                    o.`cancel_reason`, o.`return_reason`,
                    TIMESTAMPDIFF(MINUTE, o.`created_at`, o.`confirmed_at`)   AS m_confirm,
                    TIMESTAMPDIFF(MINUTE, o.`confirmed_at`, o.`shipped_at`)   AS m_ship,
                    TIMESTAMPDIFF(MINUTE, o.`shipped_at`, o.`delivered_at`)   AS m_deliver,
                    TIMESTAMPDIFF(MINUTE, o.`created_at`, o.`delivered_at`)   AS m_total
             FROM `orders` o
             WHERE o.`created_at` BETWEEN :from AND :to
             ORDER BY o.`created_at` DESC, o.`id` DESC',
            $dateParams
        );

        $rows = (static function (PDOStatement $statement, callable $amount, callable $stamp): Generator {
            $hours = static fn ($minutes): string => $minutes === null || (int) $minutes < 0
                ? ''
                : number_format((int) $minutes / 60, 2, '.', '');

            while (($row = $statement->fetch()) !== false) {
                yield [
                    $row['order_number'],
                    $stamp($row['created_at']),
                    ORDER_STATUSES[$row['status']] ?? $row['status'],
                    report_status_earns((string) $row['status']) ? 'Yes' : 'No',
                    $row['payment_method'],
                    PAYMENT_STATUSES[$row['payment_status']] ?? $row['payment_status'],
                    $row['customer_name'],
                    $row['customer_email'],
                    $row['shipping_city'],
                    $row['shipping_state'],
                    $amount($row['total_amount']),
                    $stamp($row['confirmed_at']),
                    $stamp($row['shipped_at']),
                    $stamp($row['delivered_at']),
                    $stamp($row['cancelled_at']),
                    $hours($row['m_confirm']),
                    $hours($row['m_ship']),
                    $hours($row['m_deliver']),
                    $hours($row['m_total']),
                    $row['cancel_reason'],
                    $row['return_reason'],
                ];
            }
        })($statement, $amount, $stamp);

        $filename = 'report-orders-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
        break;

    // -----------------------------------------------------------------------
    //  Customers: everyone who bought in the window, with lifetime context.
    // -----------------------------------------------------------------------
    case 'customers':
        $headers = [
            'Customer', 'Email', 'Phone', 'Account ID', 'Segment',
            'Orders In Range', 'Spend In Range', 'Lifetime Orders', 'Lifetime Spend',
            'Avg Order Value', 'First Order', 'Last Order', 'City', 'State',
        ];

        // GROUP_CONCAT with a newline separator gives the most recent address
        // without tripping over commas inside a city name.
        $statement = Database::query(
            'SELECT COALESCE(CAST(o.`user_id` AS CHAR), o.`customer_email`) AS ckey,
                    o.`user_id`,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.`customer_name`  ORDER BY o.`created_at` DESC SEPARATOR \'\n\'), \'\n\', 1) AS name,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.`customer_email` ORDER BY o.`created_at` DESC SEPARATOR \'\n\'), \'\n\', 1) AS email,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.`customer_phone` ORDER BY o.`created_at` DESC SEPARATOR \'\n\'), \'\n\', 1) AS phone,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.`shipping_city`  ORDER BY o.`created_at` DESC SEPARATOR \'\n\'), \'\n\', 1) AS city,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.`shipping_state` ORDER BY o.`created_at` DESC SEPARATOR \'\n\'), \'\n\', 1) AS state,
                    COUNT(*)                           AS lifetime_orders,
                    COALESCE(SUM(o.`total_amount`), 0) AS lifetime_spend,
                    MIN(o.`created_at`)                AS first_order,
                    MAX(o.`created_at`)                AS last_order,
                    COALESCE(SUM(CASE WHEN o.`created_at` BETWEEN :count_from AND :count_to
                                      THEN 1 ELSE 0 END), 0) AS period_orders,
                    COALESCE(SUM(CASE WHEN o.`created_at` BETWEEN :spend_from AND :spend_to
                                      THEN o.`total_amount` ELSE 0 END), 0) AS period_spend
             FROM `orders` o
             WHERE ' . $revenueSql . '
             GROUP BY ckey, o.`user_id`
             HAVING period_orders > 0
             ORDER BY lifetime_spend DESC',
            [
                'count_from' => $filters['from_dt'], 'count_to' => $filters['to_dt'],
                'spend_from' => $filters['from_dt'], 'spend_to' => $filters['to_dt'],
            ]
        );

        $rows = (static function (PDOStatement $statement, callable $amount, callable $stamp, string $rangeStart): Generator {
            while (($row = $statement->fetch()) !== false) {
                $orders = max(1, (int) $row['lifetime_orders']);
                yield [
                    $row['name'],
                    $row['email'],
                    $row['phone'],
                    $row['user_id'],
                    // First order inside the window means this was a first-time buyer.
                    $row['first_order'] >= $rangeStart ? 'New' : 'Returning',
                    (int) $row['period_orders'],
                    $amount($row['period_spend']),
                    (int) $row['lifetime_orders'],
                    $amount($row['lifetime_spend']),
                    $amount((float) $row['lifetime_spend'] / $orders),
                    $stamp($row['first_order']),
                    $stamp($row['last_order']),
                    $row['city'],
                    $row['state'],
                ];
            }
        })($statement, $amount, $stamp, $filters['from_dt']);

        $filename = 'report-customers-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
        break;

    // -----------------------------------------------------------------------
    //  Marketing: five different things, so one long table with a Section
    //  column rather than five files nobody can join back together.
    // -----------------------------------------------------------------------
    default:
        $headers = ['Section', 'Item', 'Detail', 'Count', 'Conversions', 'Discount Given', 'Revenue'];
        $rows    = [];

        foreach (report_coupon_performance($filters, 200) as $row) {
            $rows[] = [
                'Coupon',
                $row['code'],
                (COUPON_TYPES[$row['type']] ?? $row['type']) . ' · ' . $row['status'],
                (int) $row['redemptions'],
                '',
                $amount($row['discount_given']),
                $amount($row['revenue_influenced']),
            ];
        }

        foreach (report_campaign_performance('deal', $filters, 100) as $row) {
            $rows[] = [
                'Deal',
                $row['title'],
                format_date($row['start_time'], 'Y-m-d') . ' to ' . format_date($row['end_time'], 'Y-m-d'),
                $row['sales']['orders'],
                $row['sales']['units'],
                '',
                $amount($row['sales']['revenue']),
            ];
        }

        foreach (report_campaign_performance('flash', $filters, 100) as $row) {
            $rows[] = [
                'Flash sale',
                $row['title'],
                format_date($row['start_time'], 'Y-m-d') . ' to ' . format_date($row['end_time'], 'Y-m-d'),
                $row['sales']['orders'],
                $row['sales']['units'],
                '',
                $amount($row['sales']['revenue']),
            ];
        }

        // Popup counters are lifetime totals, which the Detail column states.
        foreach (Database::fetchAll(
            'SELECT `name`, `popup_type`, `status`, `impressions`, `conversions`
             FROM `popups` ORDER BY `impressions` DESC, `name` ASC'
        ) as $row) {
            $rows[] = [
                'Popup',
                $row['name'],
                'Lifetime counters · ' . $row['popup_type'] . ' · ' . $row['status'],
                (int) $row['impressions'],
                (int) $row['conversions'],
                '',
                '',
            ];
        }

        foreach (report_fill_series(
            Database::fetchPairs(
                'SELECT ' . report_bucket_sql('`created_at`', $filters['group']) . ' AS bucket, COUNT(*)
                 FROM `newsletter_subscribers`
                 WHERE `created_at` BETWEEN :from AND :to
                 GROUP BY bucket',
                $dateParams
            ),
            $filters
        ) as $point) {
            $rows[] = ['Newsletter signups', $point['key'], 'New subscribers', (int) $point['value'], '', '', ''];
        }

        foreach (report_search_terms($filters, 500) as $row) {
            $rows[] = [
                'Search term',
                $row['query'],
                (int) $row['best_results'] === 0 ? 'No results — unmet demand' : $row['best_results'] . ' results',
                (int) $row['searches'],
                (int) $row['sessions'],
                '',
                '',
            ];
        }

        $filename = 'report-marketing-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';
        break;
}

// Reports carry customer contact details and revenue, so every export is
// recorded in the audit trail.
log_activity(
    'report.exported',
    'report',
    null,
    'Exported the ' . $report . ' report for ' . $filters['from'] . ' to ' . $filters['to']
);

stream_csv($filename, $headers, $rows);
