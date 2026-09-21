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
    ->required('password')->password('password')
    ->required('password_confirmation')
    ->matches('password_confirmation', 'password', 'Passwords do not match.');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$clean = $validator->validated(['current_password', 'password']);

if (!password_verify((string) $clean['current_password'], (string) $user['password'])) {
    json_validation_error(['current_password' => 'That is not your current password.']);
}

if (hash_equals((string) $clean['current_password'], (string) $clean['password'])) {
    json_validation_error(['password' => 'Choose a password different from your current one.']);
}

Database::update('users', [
    'password'      => password_hash((string) $clean['password'], PASSWORD_DEFAULT),
    'failed_logins' => 0,
    'locked_until'  => null,
], '`id` = :id', ['id' => (int) $user['id']]);

// New credential, new session id - anything that captured the old one is dead.
session_regenerate_id(true);
$_SESSION['_regenerated_at'] = time();

json_success('Your password has been changed.');
