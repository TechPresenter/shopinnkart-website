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

// Deliberately tight: this endpoint sends mail on someone else's behalf.
api_rate_limit('forgot_password', 3, 900);

$validator = new Validator(request_all(), ['email' => 'Email address']);
$validator->required('email')->email('email');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$email = mb_strtolower((string) $validator->value('email'));

$user = Database::fetch(
    'SELECT `id`, `email`, `first_name`, `status` FROM `users` WHERE `email` = :email LIMIT 1',
    ['email' => $email]
);

// Blocked and deactivated accounts get nothing - they cannot sign in anyway.
if ($user !== null && $user['status'] === 'active') {
    $token = create_password_reset((string) $user['email'], 'customer');

    $resetUrl = url('reset-password.php')
        . '?token=' . urlencode($token)
        . '&email=' . urlencode((string) $user['email']);

    notify_password_reset((string) $user['email'], (string) $user['first_name'], $resetUrl);
}

json_success('If that email is registered with us, a password reset link is on its way. The link is valid for 1 hour.');
