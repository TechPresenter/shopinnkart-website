<?php
/**
 * GET /api/notifications/count.php - unread total for the header bell badge.
 *
 * Returns: { unread }
 *
 * Deliberately one indexed COUNT(*) and nothing else: the header polls this,
 * so anything heavier here is paid for on every open tab. Use list.php when
 * the feed itself is needed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);
$user = api_require_login();

// No rate limit: polling is the intended use, and json_response() already sends
// no-store so a proxy can never hand back a stale badge.
json_success('OK', [
    'unread' => unread_notification_count((int) $user['id']),
]);
