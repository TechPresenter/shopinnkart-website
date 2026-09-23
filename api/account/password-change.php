<?php
/**
 * POST /api/account/password-change.php
 * Change the signed-in customer's password.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
$user = api_require_login();

// Guessing the current password is a credential attack even from inside a
// live session, so cap the attempts.
api_rate_limit('password_change', 8, 900);

$validator = new Validator(request_all(), [
    'current_password'      => 'Current password',
    'password'              => 'New password',
    'password_confirmation' => 'Confirm password',
]);

$validator->required('current_password')
    ->required('password')->password('password', null, 'customer', (string) $user['email'])
    ->required('password_confirmation')
    ->matches('password_confirmation', 'password', 'Passwords do not match.');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$clean = $validator->validated(['current_password', 'password']);

if (!password_verify_app((string) $clean['current_password'], (string) $user['password'])) {
    security_event('auth.password_change_failed', 'medium', [], (int) $user['id'], 'customer');
    json_validation_error(['current_password' => 'That is not your current password.']);
}

if (hash_equals((string) $clean['current_password'], (string) $clean['password'])) {
    json_validation_error(['password' => 'Choose a password different from your current one.']);
}

Database::update('users', [
    'password'      => password_hash_app((string) $clean['password']),
    'failed_logins' => 0,
    'locked_until'  => null,
], '`id` = :id', ['id' => (int) $user['id']]);

// New credential, new generation: every OTHER session of this account and
// every remembered device is over, which is the point of changing a password
// you think somebody else has. Regenerating this session id alone (what used
// to happen here) left the intruder's own session untouched for a week.
auth_after_password_change('customer', $user, 'changed');

json_success('Your password has been changed. Any other devices have been signed out.');
