<?php
/**
 * POST /api/notifications/read.php - mark one notification, or all of them, read.
 *
 * Params: id (one notification) or all=true (the whole feed)
 * Returns: { id, all, marked, unread }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
// Opening the bell marks rows one by one, so the ceiling is generous.
api_rate_limit('notification_read', 120, 300);

$userId = (int) $user['id'];

if (request_bool('all')) {
    $marked = mark_all_notifications_read($userId);

    json_success($marked > 0 ? 'All notifications marked as read.' : 'You are already up to date.', [
        'id'     => null,
        'all'    => true,
        'marked' => $marked,
        'unread' => unread_notification_count($userId),
    ]);
}

$validator = new Validator(request_all(), ['id' => 'Notification']);
$validator->required('id')->integer('id');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

// mark_notification_read() scopes its UPDATE to this user_id, so someone else's
// id simply matches nothing. It also matches nothing when the row is already
// read, which makes a false result ambiguous - answering 200 either way keeps
// the call idempotent and refuses to confirm whether a foreign id exists.
$marked = mark_notification_read(request_int('id'), $userId);

json_success('OK', [
    'id'     => request_int('id'),
    'all'    => false,
    'marked' => $marked,
    'unread' => unread_notification_count($userId),
]);
