<?php
/**
 * POST /api/auth/logout.php
 * End the customer session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

logout_user();

// Answer the same way whether or not a session was live, so a stale tab
// posting twice still gets sent home instead of an error.
json_success('You have been signed out.', ['redirect' => url()]);
