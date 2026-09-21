<?php
/**
 * ShopInnKart Admin - Customer CSV export.
 *
 * Exports whatever the list screen is currently showing, without paging.
 * Rows are streamed straight off the statement so a large customer base does
 * not have to fit in memory first.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

admin_require('customers.view');

require_once __DIR__ . '/_filters.php';

$filters = customer_list_filters();

$statement = Database::query(
    customer_list_select()
    . ' WHERE ' . $filters['where']
    . ' GROUP BY u.`id`'
    . ' ORDER BY ' . $filters['order_by'],
    $filters['params']
);

log_activity('customer.exported', 'user', null, 'Exported the customer list to CSV');

$rows = static function (PDOStatement $statement): Generator {
    foreach ($statement as $row) {
        yield [
            $row['id'],
            $row['first_name'],
            $row['last_name'],
            $row['email'],
            $row['phone'],
            $row['status'],
            $row['gender'],
            $row['date_of_birth'] ? format_date($row['date_of_birth'], 'Y-m-d') : '',
            $row['orders_count'],
            // Unformatted so a spreadsheet treats it as a number.
            number_format((float) $row['lifetime_spend'], 2, '.', ''),
            $row['last_order_at'] ? format_datetime($row['last_order_at'], 'Y-m-d H:i') : '',
            format_datetime($row['created_at'], 'Y-m-d H:i'),
            $row['last_login_at'] ? format_datetime($row['last_login_at'], 'Y-m-d H:i') : '',
        ];
    }
};

stream_csv(
    'customers-' . date('Y-m-d-His') . '.csv',
    ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Status', 'Gender', 'Date of Birth',
     'Orders', 'Lifetime Spend', 'Last Order', 'Joined', 'Last Login'],
    $rows($statement)
);
