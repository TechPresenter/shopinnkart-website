<?php
/**
 * POST /api/auth/register.php
 * Create a customer account, sign them straight in and queue the welcome mail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['POST']);
api_require_csrf();
api_rate_limit('register', 5, 900);

$input = request_all();

$validator = new Validator($input, [
    'first_name'            => 'First name',
    'last_name'             => 'Last name',
    'email'                 => 'Email address',
    'phone'                 => 'Mobile number',
    'password'              => 'Password',
    'password_confirmation' => 'Confirm password',
]);

$validator->required('first_name')->max('first_name', 100)
    ->max('last_name', 100)
    ->required('email')->email('email')->max('email', 190)
    ->unique('email', 'users', 'email', null, 'An account already exists with this email. Try signing in instead.')
    ->required('phone')->phone('phone')
    ->required('password')->password('password')
    ->required('password_confirmation')
    ->matches('password_confirmation', 'password', 'Passwords do not match.')
    ->rule('accepts_terms', request_bool('accepts_terms'), 'Please accept the Terms & Conditions to continue.');

if ($validator->fails()) {
    json_validation_error($validator->errors());
}

$clean = $validator->validated(['first_name', 'last_name', 'email', 'phone', 'password']);
$email = mb_strtolower((string) $clean['email']);
$lastName = trim((string) ($clean['last_name'] ?? ''));

try {
    $userId = Database::insert('users', [
        'first_name' => (string) $clean['first_name'],
        'last_name'  => $lastName === '' ? null : $lastName,
        'email'      => $email,
        'phone'      => normalize_phone((string) $clean['phone']),
        'password'   => password_hash((string) $clean['password'], PASSWORD_DEFAULT),
        'status'     => 'active',
    ]);
} catch (PDOException $e) {
    // Two submissions racing for the same address land here despite the
    // unique() check above.
    if ($e->getCode() === '23000') {
        json_validation_error(['email' => 'An account already exists with this email. Try signing in instead.']);
    }
    throw $e;
}

$user = Database::fetch('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => $userId]);
if ($user === null) {
    json_error('We could not finish creating your account. Please try again.', [], 500);
}

login_user($user);
notify_welcome($user);

// Confirmation link. Both registration paths must issue one: register.php
// carries data-ajax-form="auth/register.php", so with JavaScript enabled the
// browser posts here and the page's own PHP never runs. Wiring only one file
// means verification silently never happens for most real signups.
try {
    send_email_verification($user);
} catch (Throwable $e) {
    ErrorHandler::log('warning', 'Verification email failed at registration: ' . $e->getMessage());
}

/**
 * Add the address to the mailing list, reviving a previous unsubscribe.
 * Never fatal: a failed subscription must not cost us a completed signup.
 */
$subscribe = static function (string $email, string $name): void {
    try {
        $existing = Database::fetch(
            'SELECT `id`, `status` FROM `newsletter_subscribers` WHERE `email` = :email LIMIT 1',
            ['email' => $email]
        );

        if ($existing === null) {
            Database::insert('newsletter_subscribers', [
                'email'      => $email,
                'name'       => $name === '' ? null : mb_substr($name, 0, 150),
                'source'     => 'register',
                'ip_address' => client_ip(),
                'status'     => 'active',
            ]);
        } elseif ($existing['status'] !== 'active') {
            Database::update('newsletter_subscribers', ['status' => 'active'], '`id` = :id', ['id' => (int) $existing['id']]);
        } else {
            return; // Already subscribed - no second welcome mail.
        }

        notify_newsletter_welcome($email);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Newsletter opt-in at registration failed: ' . $e->getMessage());
    }
};

// Marketing consent is opt-in: subscribe only when the box was actually ticked.
if (request_bool('newsletter')) {
    $subscribe($email, trim($clean['first_name'] . ' ' . $lastName));
}

json_success('Welcome to ' . setting('store_name', SITE_NAME) . ', ' . $clean['first_name'] . '!', [
    'redirect' => intended_url(),
]);
