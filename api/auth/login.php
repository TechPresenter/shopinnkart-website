<?php
/**
 * POST /api/auth/login.php
 * Sign a customer in and hand back where to go next.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
// A coarse ceiling on this endpoint only. The real brake is inside
// attempt_login(): per IP, per IP+account, atomic, and shared with the
// no-JavaScript form so the two paths cannot be used to double the quota.
api_rate_limit('login', 30, 300);

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
$identity    = mb_strtolower((string) $credentials['email']);

// Nothing for an ordinary customer to answer. Only once this address has run
// up several refusals does a challenge appear - that is what "after N
// failures" means, and the counter is fed by bot_note_failure() below.
bot_guard_api('login', ['key' => $identity, 'min_seconds' => 0]);

$attempt = attempt_login($identity, (string) $credentials['password']);

if (!$attempt['ok']) {
    bot_note_failure('login', $identity);
    // No field-level errors: flagging "email" or "password" separately would
    // tell an attacker which half was wrong, and whether the address exists.
    json_error((string) $attempt['error'], [], 401);
}

bot_note_success('login', $identity);

$user     = $attempt['user'];
$remember = request_bool('remember');

// The password is only the first half. On an account with a second factor
// mfa_sign_in() writes a five-minute pending record and NO session, and the
// browser is sent to the challenge screen - which is a plain form, so
// finishing a sign-in never depends on this endpoint or on JavaScript.
$gate = mfa_sign_in('customer', $user, ['remember' => $remember]);

if ($gate['stage'] !== 'complete') {
    json_success(
        $gate['stage'] === 'enrol'
            ? 'One more step before you can carry on.'
            : 'Almost there - we need one more check.',
        ['redirect' => $gate['url'], 'mfa_required' => true]
    );
}

login_user($user, $remember, $gate['method']);

// current_user() memoised "logged out" earlier in this request, so greet from
// the row we just verified rather than re-reading the session.
json_success('Welcome back, ' . trim((string) $user['first_name']) . '.', [
    'redirect' => intended_url(),
]);
