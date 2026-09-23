<?php
/**
 * POST /api/auth/token.php
 *
 * Sign a device in and hand back a long-lived API token. This is the mobile
 * app's front door; the website keeps using sessions exactly as before.
 *
 *   { "email": "...", "password": "...", "device_name": "Pixel 8",
 *     "platform": "android", "code": "123456" }
 *
 * Deliberately NOT behind api_require_csrf(): a CSRF token is a defence for
 * credentials the browser attaches by itself, and there are none here - the
 * caller has to know the password. A cross-site page could post to this
 * endpoint, but it can neither supply the password nor read the answer. Every
 * other protection is in place instead: the same throttles, delays and
 * enumeration-safe wording as the website login (attempt_login()), a ceiling
 * on issuance per address and per account, and the bot guard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);

if (!api_tokens_enabled()) {
    json_error('App sign-in is switched off for this store.', [], 403, 'disabled');
}

// Per client address first, then per account below: a credential-stuffing run
// changes the address it comes from far more easily than the address it aims at.
api_rate_limit('auth_token', 10, 600);

$input    = request_all();
$identity = mb_strtolower(trim((string) request_input('email', '')));
$password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
$wantsAdmin = strtolower((string) request_input('account_type', 'customer')) === 'admin';

$v = new Validator($input, [
    'email'       => 'Email address',
    'password'    => 'Password',
    'device_name' => 'Device name',
]);
$v->required('email')->max('email', 190)
  ->required('password')
  ->max('device_name', 100);

// An admin signs in with an email or a username, so the email rule only fits
// the customer side.
if (!$wantsAdmin) {
    $v->email('email');
}

if ($v->fails()) {
    json_validation_error($v->errors());
}

// Adaptive bot check. An app signing one device in once never sees it; a
// script working through a password list meets it after a few refusals.
bot_guard_api('login', ['key' => $identity, 'min_seconds' => 0]);

// A second ceiling on the account being aimed at, so a distributed run cannot
// mint tokens for one address from a thousand different machines.
if (!rate_limit_attempt('auth.token_account', $identity, 15, 3600)) {
    security_event('api.token_issue_throttled', 'medium', ['identity' => $identity]);
    if (!headers_sent()) {
        header('Retry-After: ' . rate_limit_retry_after(3600));
    }
    json_error('Too many sign-in attempts for this account. Please try again later.', [], 429, 'rate_limit');
}

if ($wantsAdmin && !api_tokens_admin_enabled()) {
    // Same wording as a wrong password: whether admin tokens are allowed here
    // is not something an unauthenticated caller needs to learn.
    security_event('api.token_admin_disabled', 'medium', ['identity' => $identity]);
    json_error('Invalid credentials.', [], 401, 'auth');
}

$userType = $wantsAdmin ? 'admin' : 'customer';
$attempt  = $wantsAdmin
    ? attempt_admin_login((string) request_input('email', ''), $password)
    : attempt_login($identity, $password);

if (!$attempt['ok']) {
    // Feeds the adaptive challenge: repeated refusals from this address are
    // what makes the next attempt meet a CAPTCHA.
    bot_note_failure('login', $identity);
    json_error((string) $attempt['error'], [], ($attempt['throttled'] ?? false) ? 429 : 401, 'auth');
}

$account = $wantsAdmin ? $attempt['admin'] : $attempt['user'];
$userId  = (int) $account['id'];

// mfa_required() decides "required for Super Admins" by reading the role's
// permissions off the row it is handed, and attempt_admin_login() returns the
// bare `admins` row - no role columns on it. Handed that, the check quietly
// read every admin as not-a-Super-Admin, so under the required_super policy an
// unenrolled Super Admin could mint an app token with the password alone. The
// website's sign-in already re-reads the row with its role for exactly this
// reason (admin/login.php); this is the same re-read.
if ($wantsAdmin && function_exists('mfa_admin_row')) {
    $account = mfa_admin_row($userId) ?? $account;
}

/**
 * Second factor.
 *
 * A correct password is only the first half on an account that has 2FA, and a
 * token endpoint that skipped it would be the way around it. The checks are
 * the same ones the website's challenge screen runs (includes/mfa.php), driven
 * from a pending record built here rather than parked in a session - an app
 * posts both halves in one request.
 *
 * function_exists() only because this endpoint must not fatal on an install
 * where the 2FA module has not been deployed; when it is there, it is used.
 */
if (function_exists('mfa_methods')) {
    $methods = mfa_methods($userType, $account);

    if ($methods === [] && function_exists('mfa_required') && mfa_required($userType, $account)) {
        // Required but not set up. The enrolment screen is on the website, and
        // it is the only place a secret should ever be handed out.
        security_event('api.token_2fa_enrolment_required', 'medium', [], $userId, $userType);
        json_error('Set up two-factor authentication on the website before signing in from the app.',
            [], 403, 'two_factor');
    }

    if ($methods !== []) {
        $code   = (string) request_input('code', '');
        $method = strtolower((string) request_input('method', 'totp'));
        $method = in_array($method, ['totp', 'backup', 'email'], true) ? $method : 'totp';

        if ($code === '') {
            json_error('Enter the 6-digit code from your authenticator app.', [], 401, 'two_factor');
        }

        $verify = mfa_challenge_verify([
            'type'     => $userType,
            'id'       => $userId,
            'version'  => auth_row_version($account),
            'stage'    => 'verify',
            'methods'  => $methods,
            'remember' => false,
            'expires'  => time() + MFA_PENDING_TTL,
            'tries'    => 0,
            'started'  => time(),
        ], $method, $code);

        // mfa_challenge_verify() counts a failure into the session's pending
        // record. There is no browser behind this request to carry one, so it
        // is dropped rather than left as a half-formed challenge.
        mfa_pending_clear();

        if (!$verify['ok']) {
            json_error((string) ($verify['error'] ?? 'That code did not match.'), [], 401, 'two_factor');
        }
    }
}

bot_note_success('login', $identity);
rate_limit_clear('auth.token_account', $identity);

$issued = api_token_issue($userType, $userId, [
    'device_name' => (string) request_input('device_name', ''),
    'platform'    => (string) request_input('platform', ''),
]);

log_login($userType, $userId, (string) $account['email'], true, 'api_token');

json_success('Signed in.', [
    'token'      => $issued['token'],
    'token_type' => 'Bearer',
    'expires_at' => $issued['expires_at'],
    'device'     => [
        'id'       => $issued['id'],
        'name'     => $issued['device_name'],
        'platform' => $issued['platform'],
    ],
    'account' => [
        'id'         => $userId,
        'type'       => $userType,
        'name'       => $wantsAdmin
            ? (string) $account['name']
            : trim($account['first_name'] . ' ' . (string) ($account['last_name'] ?? '')),
        'email'      => (string) $account['email'],
    ],
], 201);
