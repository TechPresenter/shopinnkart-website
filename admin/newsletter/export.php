<?php
/**
 * ShopInnKart Admin - Newsletter CSV export.
 *
 * Exports whatever the list screen is currently showing, without paging.
 * Rows are streamed straight off the statement so a large list does not have
 * to fit in memory first.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

admin_require('newsletter.view');

require_once __DIR__ . '/_filters.php';

$filters = newsletter_list_filters();

$statement = Database::query(
    'SELECT `id`, `email`, `name`, `source`, `ip_address`, `status`, `created_at`, `updated_at`
     FROM `newsletter_subscribers`
     WHERE ' . $filters['where'] . '
     ORDER BY ' . $filters['order_by'],
    $filters['params']
);

log_activity('newsletter.exported', 'newsletter_subscriber', null,
    'Exported the newsletter list to CSV'
        . ($filters['active'] ? ' (filtered)' : ''));

$rows = static function (PDOStatement $statement): Generator {
    foreach ($statement as $row) {
        yield [
            $row['id'],
            $row['email'],
            $row['name'],
            $row['source'],
            $row['status'],
            $row['ip_address'],
            format_datetime($row['created_at'], 'Y-m-d H:i'),
            format_datetime($row['updated_at'], 'Y-m-d H:i'),
        ];
    }
};

stream_csv(
    'newsletter-' . date('Y-m-d-His') . '.csv',
    ['ID', 'Email', 'Name', 'Source', 'Status', 'IP Address', 'Subscribed', 'Last Change'],
    $rows($statement)
);
