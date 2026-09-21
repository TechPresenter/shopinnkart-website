<?php
/**
 * ShopInnKart - Authentication & authorisation.
 *
 * Customers and admins use separate session keys so being signed in to the
 * storefront never grants anything in /admin.
 */

declare(strict_types=1);

// ===========================================================================
//  CUSTOMER AUTHENTICATION
// ===========================================================================

/** The signed-in customer row, or null. */
function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    $id = $_SESSION[USER_SESSION_KEY]['id'] ?? null;
    if (!$id) {
        return $user = null;
    }

    $row = Database::fetch(
        'SELECT * FROM `users` WHERE `id` = :id AND `status` = :status LIMIT 1',
        ['id' => (int) $id, 'status' => 'active']
    );

    // Account was deleted, blocked or deactivated mid-session.
    if ($row === null) {
        unset($_SESSION[USER_SESSION_KEY]);
        return $user = null;
    }

    return $user = $row;
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user === null ? null : (int) $user['id'];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Display name for the header. */
function current_user_name(): string
{
    $user = current_user();
    if ($user === null) {
        return '';
    }
    return trim($user['first_name'] . ' ' . (string) $user['last_name']);
}

/**
 * Establish a customer session. Regenerates the session id and merges any
 * guest cart / compare list into the account.
 */
function login_user(array $user, bool $remember = false): void
{
    $guestSession = session_key();

    session_regenerate_id(true);
    $_SESSION[USER_SESSION_KEY] = [
        'id'         => (int) $user['id'],
        'email'      => $user['email'],
        'name'       => trim($user['first_name'] . ' ' . (string) $user['last_name']),
        'logged_at'  => time(),
    ];
    $_SESSION['_regenerated_at'] = time();

    Database::update('users', [
        'last_login_at' => date('Y-m-d H:i:s'),
        'last_login_ip' => client_ip(),
        'failed_logins' => 0,
        'locked_until'  => null,
    ], '`id` = :id', ['id' => (int) $user['id']]);

    // Carry the anonymous cart and comparison list over to the account.
    if ($guestSession !== '') {
        cart_merge_guest_into_user($guestSession, (int) $user['id']);
        compare_merge_guest_into_user($guestSession, (int) $user['id']);
    }

    log_login('customer', (int) $user['id'], $user['email'], true);

    if ($remember) {
        // Extend this session's cookie rather than issuing a separate
        // long-lived token, which keeps the credential surface small.
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function logout_user(): void
{
    unset($_SESSION[USER_SESSION_KEY]);
    session_regenerate_id(true);
}

/**
 * Verify credentials with lockout after repeated failures.
 *
 * @return array{ok:bool, user:?array, error:?string}
 */
function attempt_login(string $email, string $password): array
{
    $user = Database::fetch('SELECT * FROM `users` WHERE `email` = :email LIMIT 1', ['email' => $email]);

    if ($user === null) {
        // Spend roughly the same time as a real verify so the response
        // does not reveal whether the address exists.
        password_verify($password, '$2y$10$usesomesillystringfor.invalidhashvaluehere000000000000000');
        log_login('customer', null, $email, false, 'unknown_email');
        return ['ok' => false, 'user' => null, 'error' => 'Incorrect email or password.'];
    }

    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        $minutes = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
        log_login('customer', (int) $user['id'], $email, false, 'locked');
        return ['ok' => false, 'user' => null, 'error' => "Too many failed attempts. Try again in {$minutes} minute(s)."];
    }

    if ($user['status'] !== 'active') {
        log_login('customer', (int) $user['id'], $email, false, 'inactive');
        return ['ok' => false, 'user' => null, 'error' => 'This account is not active. Please contact support.'];
    }

    if (!password_verify($password, $user['password'])) {
        $failed = (int) $user['failed_logins'] + 1;
        $update = ['failed_logins' => $failed];
        if ($failed >= MAX_LOGIN_ATTEMPTS) {
            $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MINUTES * 60));
            $update['failed_logins'] = 0;
        }
        Database::update('users', $update, '`id` = :id', ['id' => (int) $user['id']]);
        log_login('customer', (int) $user['id'], $email, false, 'bad_password');

        return ['ok' => false, 'user' => null, 'error' => 'Incorrect email or password.'];
    }

    // Upgrade the stored hash if PHP's default cost has moved on.
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        Database::update(
            'users',
            ['password' => password_hash($password, PASSWORD_DEFAULT)],
            '`id` = :id',
            ['id' => (int) $user['id']]
        );
    }

    return ['ok' => true, 'user' => $user, 'error' => null];
}

/** Gate a storefront page behind a customer login. */
function require_login(string $redirectTo = 'login.php'): array
{
    $user = current_user();
    if ($user === null) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? url('account.php');
        flash('info', 'Please sign in to continue.');
        redirect(url($redirectTo));
    }
    return $user;
}

/** Where to send the customer after signing in. */
function intended_url(string $fallback = 'account.php'): string
{
    $intended = $_SESSION['_intended'] ?? null;
    unset($_SESSION['_intended']);

    if (is_string($intended) && $intended !== '' && strpos($intended, '//') !== 0) {
        return $intended;
    }
    return url($fallback);
}

// ===========================================================================
//  ADMIN AUTHENTICATION
// ===========================================================================

/** The signed-in admin row (with role name + permissions), or null. */
function admin_user(): ?array
{
    static $admin = false;

    if ($admin !== false) {
        return $admin;
    }

    $id = $_SESSION[ADMIN_SESSION_KEY]['id'] ?? null;
    if (!$id) {
        return $admin = null;
    }

    $row = Database::fetch(
        'SELECT a.*, r.`name` AS role_name, r.`slug` AS role_slug, r.`permissions` AS role_permissions
         FROM `admins` a
         INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
         WHERE a.`id` = :id AND a.`status` = :status AND r.`status` = :rstatus
         LIMIT 1',
        ['id' => (int) $id, 'status' => 'active', 'rstatus' => 'active']
    );

    if ($row === null) {
        unset($_SESSION[ADMIN_SESSION_KEY]);
        return $admin = null;
    }

    return $admin = $row;
}

function admin_is_logged_in(): bool
{
    return admin_user() !== null;
}

function admin_id(): ?int
{
    $admin = admin_user();
    return $admin === null ? null : (int) $admin['id'];
}

/** Permission list for the signed-in admin. ['*'] means super admin. */
function admin_permissions(): array
{
    $admin = admin_user();
    if ($admin === null) {
        return [];
    }
    $permissions = json_decode_safe($admin['role_permissions'] ?? '[]', []);
    return array_values(array_filter($permissions, 'is_string'));
}

/**
 * Can the current admin perform "module.action"?
 * Passing just "module" checks for any permission in that module.
 */
function admin_can(string $permission): bool
{
    $permissions = admin_permissions();
    if ($permissions === []) {
        return false;
    }
    if (in_array('*', $permissions, true)) {
        return true;
    }
    if (in_array($permission, $permissions, true)) {
        return true;
    }

    // "products" matches any of products.view / products.edit / ...
    if (strpos($permission, '.') === false) {
        $prefix = $permission . '.';
        foreach ($permissions as $granted) {
            if (strpos($granted, $prefix) === 0) {
                return true;
            }
        }
    }
    return false;
}

/** True when the admin holds every listed permission. */
function admin_can_all(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (!admin_can($permission)) {
            return false;
        }
    }
    return true;
}

function admin_is_super(): bool
{
    return in_array('*', admin_permissions(), true);
}

/** Establish an admin session. */
function login_admin(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id'        => (int) $admin['id'],
        'email'     => $admin['email'],
        'name'      => $admin['name'],
        'logged_at' => time(),
    ];
    $_SESSION['_regenerated_at'] = time();

    Database::update('admins', [
        'last_login_at' => date('Y-m-d H:i:s'),
        'last_login_ip' => client_ip(),
        'failed_logins' => 0,
        'locked_until'  => null,
    ], '`id` = :id', ['id' => (int) $admin['id']]);

    log_login('admin', (int) $admin['id'], $admin['email'], true);
}

function logout_admin(): void
{
    $admin = admin_user();
    if ($admin !== null) {
        log_activity('admin.logout', 'admin', (int) $admin['id'], $admin['name'] . ' signed out');
    }
    unset($_SESSION[ADMIN_SESSION_KEY]);
    session_regenerate_id(true);
}

/**
 * Verify admin credentials (email or username) with lockout.
 *
 * @return array{ok:bool, admin:?array, error:?string}
 */
function attempt_admin_login(string $identifier, string $password): array
{
    $admin = Database::fetch(
        'SELECT * FROM `admins` WHERE `email` = :id OR `username` = :id2 LIMIT 1',
        ['id' => $identifier, 'id2' => $identifier]
    );

    if ($admin === null) {
        password_verify($password, '$2y$10$usesomesillystringfor.invalidhashvaluehere000000000000000');
        log_login('admin', null, $identifier, false, 'unknown_account');
        return ['ok' => false, 'admin' => null, 'error' => 'Invalid credentials.'];
    }

    if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
        $minutes = max(1, (int) ceil((strtotime((string) $admin['locked_until']) - time()) / 60));
        log_login('admin', (int) $admin['id'], $identifier, false, 'locked');
        return ['ok' => false, 'admin' => null, 'error' => "Account locked. Try again in {$minutes} minute(s)."];
    }

    if ($admin['status'] !== 'active') {
        log_login('admin', (int) $admin['id'], $identifier, false, 'inactive');
        return ['ok' => false, 'admin' => null, 'error' => 'This admin account is disabled.'];
    }

    if (!password_verify($password, $admin['password'])) {
        $failed = (int) $admin['failed_logins'] + 1;
        $update = ['failed_logins' => $failed];
        if ($failed >= MAX_LOGIN_ATTEMPTS) {
            $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MINUTES * 60));
            $update['failed_logins'] = 0;
        }
        Database::update('admins', $update, '`id` = :id', ['id' => (int) $admin['id']]);
        log_login('admin', (int) $admin['id'], $identifier, false, 'bad_password');

        return ['ok' => false, 'admin' => null, 'error' => 'Invalid credentials.'];
    }

    if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
        Database::update(
            'admins',
            ['password' => password_hash($password, PASSWORD_DEFAULT)],
            '`id` = :id',
            ['id' => (int) $admin['id']]
        );
    }

    return ['ok' => true, 'admin' => $admin, 'error' => null];
}

// ===========================================================================
//  PASSWORD RESET
// ===========================================================================

/**
 * Issue a reset token. Only the hash is stored, so a database leak does not
 * hand out working reset links.
 *
 * @return string The plain token to embed in the emailed link.
 */
function create_password_reset(string $email, string $userType = 'customer'): string
{
    // Invalidate any outstanding tokens for this address.
    Database::query(
        'UPDATE `password_resets` SET `used_at` = NOW()
         WHERE `email` = :email AND `user_type` = :type AND `used_at` IS NULL',
        ['email' => $email, 'type' => $userType]
    );

    $token = random_token(32);
    Database::insert('password_resets', [
        'email'      => $email,
        'token_hash' => hash('sha256', $token),
        'user_type'  => $userType,
        'expires_at' => date('Y-m-d H:i:s', time() + 3600),
    ]);

    return $token;
}

/** Resolve a plain reset token to its pending row, or null. */
function find_password_reset(string $token, string $userType = 'customer'): ?array
{
    return Database::fetch(
        'SELECT * FROM `password_resets`
         WHERE `token_hash` = :hash AND `user_type` = :type
           AND `used_at` IS NULL AND `expires_at` > NOW()
         ORDER BY `id` DESC LIMIT 1',
        ['hash' => hash('sha256', $token), 'type' => $userType]
    );
}

function consume_password_reset(int $resetId): void
{
    Database::update('password_resets', ['used_at' => date('Y-m-d H:i:s')], '`id` = :id', ['id' => $resetId]);
}

// ===========================================================================
//  EMAIL VERIFICATION
//
//  Same shape as the password-reset trio above, for the same reasons: only the
//  hash is stored, outstanding tokens are invalidated when a new one is issued,
//  and expiry plus single-use are enforced in the SQL rather than in PHP.
//
//  A verification link proves possession of an inbox. It is deliberately NOT
//  an authentication credential — nothing here signs anybody in.
// ===========================================================================

/**
 * Issue a verification token for a user's current address.
 *
 * @return string The plain token to embed in the emailed link.
 */
function create_email_verification(string $email, int $userId): string
{
    // Otherwise a customer who clicks "resend" five times leaves five live
    // tokens sitting in five copies of the message.
    Database::query(
        'UPDATE `email_verifications` SET `used_at` = NOW()
         WHERE `user_id` = :uid AND `used_at` IS NULL',
        ['uid' => $userId]
    );

    $token = random_token(32);
    $hours = max(1, min(720, setting_int('email_verify_expiry_hours', 48)));

    Database::insert('email_verifications', [
        'user_id'    => $userId,
        'email'      => $email,
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + ($hours * 3600)),
    ]);

    return $token;
}

/** Resolve a plain verification token to its pending row, or null. */
function find_email_verification(string $token): ?array
{
    if (trim($token) === '') {
        return null;
    }

    return Database::fetch(
        'SELECT * FROM `email_verifications`
         WHERE `token_hash` = :hash AND `used_at` IS NULL AND `expires_at` > NOW()
         ORDER BY `id` DESC LIMIT 1',
        ['hash' => hash('sha256', $token)]
    );
}

/** Burn the token and stamp the account as verified. */
function consume_email_verification(array $record): bool
{
    $userId = (int) $record['user_id'];

    try {
        Database::transaction(static function () use ($record, $userId): void {
            Database::update(
                'email_verifications',
                ['used_at' => date('Y-m-d H:i:s')],
                '`id` = :id',
                ['id' => (int) $record['id']]
            );

            // Only stamp it if it is not already set, so a second click does
            // not rewrite the original verification date.
            Database::query(
                'UPDATE `users` SET `email_verified_at` = NOW()
                 WHERE `id` = :id AND `email_verified_at` IS NULL',
                ['id' => $userId]
            );
        });
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Email verification failed for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }

    return true;
}

/** Has this account proved its address? */
function user_email_verified(?array $user): bool
{
    if ($user === null) {
        return false;
    }

    // A row fetched without the column is treated as verified rather than
    // nagging a customer on the strength of a missing SELECT.
    if (!array_key_exists('email_verified_at', $user)) {
        return true;
    }

    return $user['email_verified_at'] !== null && $user['email_verified_at'] !== '';
}

/**
 * Should the storefront nag this visitor to verify?
 * Verification is not enforced at login — see notify_email_verification() for
 * why blocking would simply push customers into guest checkout.
 */
function should_prompt_email_verification(): bool
{
    if (!setting_bool('email_verification_enabled', true)) {
        return false;
    }

    $user = current_user();

    return $user !== null && !user_email_verified($user);
}
