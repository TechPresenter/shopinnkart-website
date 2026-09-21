<?php
/**
 * POST /api/auth/reset-password.php
 * Consume a reset token, set the new password and sign the customer in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('reset_password', 8, 900);

$validator = new Validator(request_all(), [
    'email'                 => 'Email address',
    'password'              => 'New password',
    'password_confirmation' => 'Confirm password',
]);

$validator->required('token')
    ->required('email')->email('email')
    ->required('password')->password('password')
    ->required('password_confirmation')
    ->matches('password_confirmation', 'password', 'Passwords do not match.');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$clean = $validator->validated(['token', 'email', 'password']);
$expired = 'This reset link has expired or has already been used. Please request a new one.';

$reset = find_password_reset((string) $clean['token'], 'customer');
if ($reset === null) {
    json_error($expired, [], 410);
}

// The link carries both halves: a token alone must not be replayable against
// a different account if it ever leaks from a shared inbox or a referrer log.
if (!hash_equals(mb_strtolower((string) $reset['email']), mb_strtolower((string) $clean['email']))) {
    json_error($expired, [], 410);
}

$user = Database::fetch('SELECT * FROM `users` WHERE `email` = :email LIMIT 1', ['email' => $reset['email']]);
if ($user === null || $user['status'] !== 'active') {
    json_error('This account is no longer active. Please contact support.', [], 403);
}

Database::update('users', [
    'password'      => password_hash((string) $clean['password'], PASSWORD_DEFAULT),
    // A successful reset clears any lockout left behind by the failed attempts
    // that sent the customer here in the first place.
    'failed_logins' => 0,
    'locked_until'  => null,
], '`id` = :id', ['id' => (int) $user['id']]);

consume_password_reset((int) $reset['id']);

login_user($user);

json_success('Your password has been updated.', ['redirect' => url('account.php')]);
