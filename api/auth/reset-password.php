<?php
/**
 * POST /api/auth/reset-password.php
 * Consume a reset token, set the new password and sign the customer in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
// Per client address, not per session: the old counter reset itself whenever
// the caller dropped the cookie.
api_rate_limit('reset_password', 15, 3600);

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

// Claim the token before writing the password, and only continue if this
// request is the one that claimed it: find-then-update let two submissions of
// the same leaked link both set a password, so "single use" was not true.
$claimed = false;
$password = (string) $clean['password'];

Database::transaction(static function () use ($reset, $user, $password, &$claimed): void {
    if (!claim_password_reset((int) $reset['id'])) {
        return;
    }
    $claimed = true;

    Database::update('users', [
        'password'      => password_hash_app($password),
        // A successful reset clears the counters left behind by the failed
        // attempts that sent the customer here in the first place.
        'failed_logins' => 0,
        'locked_until'  => null,
    ], '`id` = :id', ['id' => (int) $user['id']]);
});

if (!$claimed) {
    json_error($expired, [], 410);
}

// Ends every other session and remembered device of this account, and tells
// the owner by email that their password changed.
auth_after_password_change('customer', $user, 'reset');

// The copy reset-password.php parked when the emailed link was opened (V56)
// is spent with the token: it must not draw the form again afterwards.
unset($_SESSION['_reset_link']);

// Deliberately not signed in: an emailed link must not be worth a live
// session on its own.
json_success('Your password has been updated. Please sign in with it.', ['redirect' => url('login.php')]);
