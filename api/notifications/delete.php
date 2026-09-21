<?php
/**
 * POST /api/notifications/delete.php - remove one notification from the feed.
 *
 * Params: id
 * Returns: { id, unread, total }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();
api_rate_limit('notification_delete', 60, 300);

$userId = (int) $user['id'];

$validator = new Validator(request_all(), ['id' => 'Notification']);
$validator->required('id')->integer('id');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$id = request_int('id');

// delete_notification() scopes its DELETE to this user_id, so a false result
// means the row is not in this customer's feed - whether it never existed or
// belongs to someone else is not something the answer distinguishes.
if (!delete_notification($id, $userId)) {
    json_error('That notification is no longer in your feed.', [], 404);
}

json_success('Notification removed.', [
    'id' => $id,
    // Deleting an unread row lowers the badge, so send both counts back and let
    // the client refresh without a second round trip.
    'unread' => unread_notification_count($userId),
    'total'  => notification_total($userId),
]);
