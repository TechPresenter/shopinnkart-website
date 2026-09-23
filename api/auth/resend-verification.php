<?php
/**
 * POST /api/auth/resend-verification.php
 *
 * Sends a fresh confirmation link to the address on the signed-in account.
 *
 * Requiring a session is what removes the user-enumeration surface: there is
 * no email input to probe, so unlike /api/auth/forgot-password.php this one
 * can answer specifically instead of being forced into neutral wording.
 *
 * The address is read from the database row, never from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('resend_verification', 5, 3600);

$user = api_require_login();

if (!setting_bool('email_verification_enabled', true)) {
    json_error('Email confirmation is switched off for this store.', [], 400);
}

if (user_email_verified($user)) {
    json_success('Your email address is already confirmed.', ['verified' => true]);
}

// A second limiter on the account itself, so the same person cannot bypass the
// per-address cap by signing in from somewhere else. In the shared table, not
// the session: a session-backed counter is reset by dropping the cookie.
if (!rate_limit_attempt('verify.resend', (string) $user['id'], 3, 3600)) {
    json_error('We have already sent a few confirmation emails. Please check your inbox and spam folder, then try again in a few minutes.', [], 429);
}

if (!send_email_verification($user)) {
    json_error('We could not send the confirmation email just now. Please try again shortly.', [], 500);
}

// Deliver it while the customer is still on the page rather than waiting for
// the background drain.
process_notification_queue(2);

json_success(
    'A confirmation link is on its way to ' . mask_email((string) $user['email']) . '.',
    ['verified' => false]
);
