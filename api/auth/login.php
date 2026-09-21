<?php
/**
 * POST /api/auth/login.php
 * Sign a customer in and hand back where to go next.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('login', 8, 300);

$validator = new Validator(request_all(), [
    'email'    => 'Email address',
    'password' => 'Password',
]);

$validator->required('email')->email('email')
    ->required('password');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

// Already signed in (double submit, or a stale tab): nothing to do.
if (is_logged_in()) {
    json_success('You are already signed in.', ['redirect' => intended_url()]);
}

$credentials = $validator->validated(['email', 'password']);
$attempt = attempt_login((string) $credentials['email'], (string) $credentials['password']);

if (!$attempt['ok']) {
    // No field-level errors: flagging "email" or "password" separately would
    // tell an attacker which half was wrong, and whether the address exists.
    json_error((string) $attempt['error'], [], 401);
}

$user = $attempt['user'];
login_user($user, request_bool('remember'));

// current_user() memoised "logged out" earlier in this request, so greet from
// the row we just verified rather than re-reading the session.
json_success('Welcome back, ' . trim((string) $user['first_name']) . '.', [
    'redirect' => intended_url(),
]);
