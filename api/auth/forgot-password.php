<?php
/**
 * POST /api/auth/forgot-password.php
 * Email a reset link. The response is identical for every address so the
 * form cannot be used to discover which emails have accounts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();

// Deliberately tight: this endpoint sends mail on someone else's behalf. The
// counter is per client address now - it used to be per session, so dropping
// the cookie bought a fresh allowance and the form was an uncapped mail bomb
// aimed at any address the sender chose.
api_rate_limit('forgot_password', 5, 3600);

$validator = new Validator(request_all(), ['email' => 'Email address']);
$validator->required('email')->email('email');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$email = mb_strtolower((string) $validator->value('email'));

// The same ceiling on the receiving end, so a sender coming from many
// addresses still cannot fill one inbox. Silent: telling the sender they hit
// the limit would say the address is registered here.
$mayEmail = rate_limit_attempt('forgot.email', $email, 3, 3600);

$user = Database::fetch(
    'SELECT `id`, `email`, `first_name`, `status` FROM `users` WHERE `email` = :email LIMIT 1',
    ['email' => $email]
);

// Blocked and deactivated accounts get nothing - they cannot sign in anyway.
if ($mayEmail && $user !== null && $user['status'] === 'active') {
    $token = create_password_reset((string) $user['email'], 'customer');

    // canonical_url(), never url(): a reset link must not be able to follow
    // the Host header this request happened to arrive with.
    $resetUrl = canonical_url('reset-password.php')
        . '?token=' . urlencode($token)
        . '&email=' . urlencode((string) $user['email']);

    notify_password_reset((string) $user['email'], (string) $user['first_name'], $resetUrl);
    security_event('auth.password_reset_requested', 'info', [], (int) $user['id'], 'customer');
}

json_success('If that email is registered with us, a password reset link is on its way. The link is valid for 1 hour.');
