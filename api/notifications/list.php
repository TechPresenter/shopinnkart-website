<?php
/**
 * GET /api/notifications/list.php - the customer's own notification feed.
 *
 * Params:  filter ('' | 'unread' | a notification_types() key), limit (1-100), offset
 * Returns: { items, html, unread, total, count, has_more, filter, limit, offset }
 *
 * Two surfaces read this and they need different things, so it answers both in
 * one payload rather than splitting into two near-identical endpoints:
 *
 *   - the header bell paints `html`, which keeps the row template and the whole
 *     icon set from having to exist a second time in JavaScript;
 *   - notifications.php pages through `items` and needs the real counts.
 *
 * `unread` is deliberately the global count, never the filtered one - it drives
 * the badge, which means "unread in total" regardless of what is on screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';
require_once INCLUDES_PATH . '/header-actions.php';

api_require_method(['GET']);
$user   = api_require_login();
$userId = (int) $user['id'];

$limit  = max(1, min(100, input_int('limit', 20)));
$offset = max(0, input_int('offset', 0));
$filter = trim((string) input('filter', ''));

// Anything the caller invents falls back to the whole feed rather than erroring,
// matching how orders/list.php treats an unknown status.
if ($filter !== '' && $filter !== 'unread' && !array_key_exists($filter, notification_types())) {
    $filter = '';
}

$rows  = user_notifications($userId, $limit, $offset, $filter);
$total = notification_total($userId, $filter);

json_success('OK', [
    'items'    => $rows,
    'html'     => notification_list_html($rows),
    'unread'   => unread_notification_count($userId),
    'total'    => $total,
    'count'    => count($rows),
    'has_more' => ($offset + count($rows)) < $total,
    'filter'   => $filter,
    'limit'    => $limit,
    'offset'   => $offset,
]);
