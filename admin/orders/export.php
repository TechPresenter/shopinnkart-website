<?php
/**
 * ShopInnKart Admin - Order CSV export.
 *
 * Reads exactly the same filters as the list screen, so "export" always means
 * "what I am looking at". Two shapes:
 *   (default)     one row per order
 *   ?mode=items   one row per order line, for stock and vendor reconciliation
 *
 * Rows are streamed straight off the statement so a large export never has to
 * fit in memory, and money is written as a plain number a spreadsheet can add up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once __DIR__ . '/_filters.php';

$filters = order_filter_state();
$mode    = admin_filter('mode', ['orders', 'items'], 'orders');

$amount = static fn ($value): string => number_format((float) $value, 2, '.', '');

if ($mode === 'items') {
    $headers = [
        'Order Number', 'Order Date', 'Order Status', 'Payment Status', 'Customer', 'Email',
        'Product', 'Variant', 'SKU', 'MRP', 'Unit Price', 'Quantity',
        'Tax Rate %', 'Tax Amount', 'Line Subtotal', 'Line Total',
    ];

    $statement = Database::query(
        'SELECT o.`order_number`, o.`created_at`, o.`status`, o.`payment_status`,
                o.`customer_name`, o.`customer_email`,
                oi.`product_name`, oi.`variant_name`, oi.`product_sku`, oi.`mrp`, oi.`price`,
                oi.`quantity`, oi.`tax_rate`, oi.`tax_amount`, oi.`subtotal`, oi.`total`
         FROM `order_items` oi
         INNER JOIN `orders` o ON o.`id` = oi.`order_id`
         WHERE ' . $filters['where'] . '
         ORDER BY ' . $filters['order_by'] . ', oi.`id` ASC',
        $filters['params']
    );

    $rows = (static function (PDOStatement $statement, callable $amount): Generator {
        while (($row = $statement->fetch()) !== false) {
            yield [
                $row['order_number'],
                format_datetime($row['created_at'], 'Y-m-d H:i'),
                ORDER_STATUSES[$row['status']] ?? $row['status'],
                PAYMENT_STATUSES[$row['payment_status']] ?? $row['payment_status'],
                $row['customer_name'],
                $row['customer_email'],
                $row['product_name'],
                $row['variant_name'],
                $row['product_sku'],
                $amount($row['mrp']),
                $amount($row['price']),
                (int) $row['quantity'],
                $amount($row['tax_rate']),
                $amount($row['tax_amount']),
                $amount($row['subtotal']),
                $amount($row['total']),
            ];
        }
    })($statement, $amount);

    $filename = 'order-items-' . date('Ymd-His') . '.csv';
} else {
    $headers = [
        'Order Number', 'Order Date', 'Status', 'Payment Status', 'Payment Method',
        'Customer', 'Email', 'Phone', 'Lines', 'Units',
        'Subtotal', 'Discount', 'Coupon', 'Shipping', 'Tax', 'Total',
        'Shipping Method', 'City', 'State', 'PIN Code',
        'Courier', 'Tracking Number', 'Estimated Delivery',
    ];

    $statement = Database::query(
        'SELECT o.`order_number`, o.`created_at`, o.`status`, o.`payment_status`, o.`payment_method`,
                o.`customer_name`, o.`customer_email`, o.`customer_phone`,
                o.`subtotal`, o.`discount_amount`, o.`coupon_code`, o.`shipping_amount`,
                o.`tax_amount`, o.`total_amount`, o.`shipping_method`,
                o.`shipping_city`, o.`shipping_state`, o.`shipping_pincode`,
                o.`courier_name`, o.`tracking_number`, o.`estimated_delivery`,
                (SELECT COUNT(*) FROM `order_items` oi WHERE oi.`order_id` = o.`id`) AS line_count,
                (SELECT COALESCE(SUM(oi2.`quantity`), 0) FROM `order_items` oi2 WHERE oi2.`order_id` = o.`id`) AS unit_count
         FROM `orders` o
         WHERE ' . $filters['where'] . '
         ORDER BY ' . $filters['order_by'],
        $filters['params']
    );

    $methodNames = $filters['payment_methods'];

    $rows = (static function (PDOStatement $statement, callable $amount, array $methodNames): Generator {
        while (($row = $statement->fetch()) !== false) {
            yield [
                $row['order_number'],
                format_datetime($row['created_at'], 'Y-m-d H:i'),
                ORDER_STATUSES[$row['status']] ?? $row['status'],
                PAYMENT_STATUSES[$row['payment_status']] ?? $row['payment_status'],
                $methodNames[$row['payment_method']] ?? $row['payment_method'],
                $row['customer_name'],
                $row['customer_email'],
                $row['customer_phone'],
                (int) $row['line_count'],
                (int) $row['unit_count'],
                $amount($row['subtotal']),
                $amount($row['discount_amount']),
                $row['coupon_code'],
                $amount($row['shipping_amount']),
                $amount($row['tax_amount']),
                $amount($row['total_amount']),
                $row['shipping_method'],
                $row['shipping_city'],
                $row['shipping_state'],
                $row['shipping_pincode'],
                $row['courier_name'],
                $row['tracking_number'],
                $row['estimated_delivery'],
            ];
        }
    })($statement, $amount, $methodNames);

    $filename = 'orders-' . date('Ymd-His') . '.csv';
}

// Exporting customer contact details is worth an audit trail of its own.
log_activity('order.exported', 'order', null,
    'Exported the ' . $mode . ' view'
    . ($filters['status'] !== '' ? ' (status: ' . $filters['status'] . ')' : '')
    . ($filters['q'] !== '' ? ' (search: ' . $filters['q'] . ')' : ''));

stream_csv($filename, $headers, $rows);
