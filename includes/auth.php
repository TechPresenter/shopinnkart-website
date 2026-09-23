<?php
/**
 * ShopInnKart - Authentication & authorisation.
 *
 * Customers and admins use separate session keys so being signed in to the
 * storefront never grants anything in /admin.
 */

declare(strict_types=1);

// The second factor. Both files only declare functions, so pulling them in
// here - rather than adding two more lines to init.php - costs nothing and
// means every entry point that has authentication also has 2FA.
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/mfa.php';

/**
 * Timing-equalising hash, used when the account does not exist so that the
 * response takes as long as a real verification. Argon2id where it is
 * available, because that is what real rows are hashed with.
 */
function auth_dummy_hash(): string
{
    static $hash = null;
    if ($hash === null) {
        $hash = password_app_algo() === PASSWORD_BCRYPT
            ? '$2y$12$usesomesillystringfore7A1fVVRK/WVjQ1w7e8sQ0ArGPZLwzy'
            : '$argon2id$v=19$m=19456,t=2,p=1$ZG9lc25vdGV4aXN0MDAw$uBVTWQyLkPAvhavL1kM3TCkYm1JGGRPEQbwBkCeEuRk';
    }
    return $hash;
}

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

    $session = is_array($_SESSION[USER_SESSION_KEY] ?? null) ? $_SESSION[USER_SESSION_KEY] : [];
    $id = $session['id'] ?? null;
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

    // Superseded by a password change elsewhere, or simply too old.
    if (!auth_session_still_valid('customer', $session, $row)) {
        unset($_SESSION[USER_SESSION_KEY]);
        auth_remember_forget_cookie();
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
 *
 * @param string $mfaMethod How the second factor was proved on this sign-in:
 *                          totp, backup, email, device - or 'none' when the
 *                          account has none. Recorded in the session so a
 *                          screen can tell "fully authenticated" from "did not
 *                          need to be"; see auth_mfa_satisfied().
 */
function login_user(array $user, bool $remember = false, string $mfaMethod = 'none'): void
{
    $guestSession = session_key();

    // Whoever used this browser before is not this customer.
    auth_forget_order_claims();

    session_regenerate_id(true);
    $_SESSION[USER_SESSION_KEY] = [
        'id'         => (int) $user['id'],
        'email'      => $user['email'],
        'name'       => trim($user['first_name'] . ' ' . (string) $user['last_name']),
        'logged_at'  => time(),
        'seen_at'    => time(),
        // Stamped into the session so a password change anywhere ends every
        // OTHER session of this account - see auth_session_still_valid().
        'auth_version' => auth_row_version($user),
        'mfa'          => ['method' => $mfaMethod, 'at' => time()],
    ];
    $_SESSION['_regenerated_at'] = time();

    // The half-finished sign-in is over, whichever way it ended.
    mfa_pending_clear();

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

    // "Keep me signed in" is a separate, rotated, hashed token - never a
    // longer-lived session cookie. The session itself dies with the browser.
    if ($remember && setting_bool('sec_remember_enabled', true)) {
        auth_remember_issue((int) $user['id']);
    } else {
        auth_remember_forget_cookie();
    }
}

/**
 * End this session. With $everywhere, end every other session of the account
 * too by moving its auth_version on (stolen cookies and remembered devices
 * included).
 */
function logout_user(bool $everywhere = false): void
{
    $userId = (int) ($_SESSION[USER_SESSION_KEY]['id'] ?? 0);

    if ($everywhere && $userId > 0) {
        auth_bump_version('customer', $userId);
        security_event('auth.signed_out_everywhere', 'info', [], $userId, 'customer');
    } elseif ($userId > 0) {
        auth_remember_forget_current($userId);
    }

    auth_remember_forget_cookie();
    unset($_SESSION[USER_SESSION_KEY]);
    auth_forget_order_claims();
    mfa_pending_clear();
    session_regenerate_id(true);
}

/**
 * Drop the customer-scoped claims that are not the account itself.
 *
 * session_regenerate_id() carries the session DATA across, so unsetting the
 * user key on sign-out left `_recent_orders` - the "this browser placed that
 * order" claim guest checkout runs on - sitting in the session. The next
 * person to use the machine, signed in as someone else or not signed in at
 * all, could then open the previous customer's confirmation page, invoice and
 * invoice PDF: their name, address, phone, email and everything they bought.
 *
 * Called from BOTH ends - sign-out and sign-in - because either one means the
 * browser has changed hands as far as we can tell.
 */
function auth_forget_order_claims(): void
{
    unset($_SESSION['_recent_orders'], $_SESSION['_last_order']);
}

/**
 * Verify customer credentials.
 *
 * Every refusal reads the same - wrong password, unknown address, blocked
 * account alike - because the old wording told a stranger which addresses are
 * registered here. The real reason goes to login_history and security_events.
 *
 * Throttling is atomic and lives in `rate_limits` (per IP, per IP+account, per
 * account); there is no lockout flag on the account any more, because that one
 * could be aimed at any known address to keep its owner out. See
 * auth_throttle_gate() and auth_attack_delay().
 *
 * @return array{ok:bool, user:?array, error:?string, throttled?:bool}
 */
function attempt_login(string $email, string $password): array
{
    $email   = mb_strtolower(trim($email));
    $generic = 'Incorrect email or password.';

    $throttle = auth_throttle_gate('customer', $email);
    if ($throttle !== null) {
        return ['ok' => false, 'user' => null, 'error' => $throttle, 'throttled' => true];
    }

    $user = Database::fetch('SELECT * FROM `users` WHERE `email` = :email LIMIT 1', ['email' => $email]);

    if ($user === null) {
        // The same shape as a real refusal, in both halves: the same growing
        // delay once this address is being guessed at, then the same time
        // spent hashing. Delaying only the addresses that exist is what told a
        // stranger which ones do, which is the enumeration signal V7 removed.
        auth_attack_delay('customer', $email, null);
        password_verify($password, auth_dummy_hash());
        auth_throttle_fail('customer', $email, null);
        log_login('customer', null, $email, false, 'unknown_email');
        return ['ok' => false, 'user' => null, 'error' => $generic];
    }

    auth_attack_delay('customer', $email, (int) $user['id']);

    if ($user['status'] !== 'active') {
        // Spend the hash this branch would otherwise skip. Refusing a blocked
        // account before the verify answered in a third of the time an unknown
        // address takes, so "fast" meant "there is an account here" - the same
        // enumeration signal V7 took out of the wording and A3 took out of the
        // delay, handed back by the branch that does the least work. The
        // result is deliberately discarded; the account is refused either way.
        password_verify(
            $password,
            ((string) $user['password']) !== '' ? (string) $user['password'] : auth_dummy_hash()
        );
        auth_throttle_fail('customer', $email, $user);
        log_login('customer', (int) $user['id'], $email, false, 'inactive');
        return ['ok' => false, 'user' => null, 'error' => $generic];
    }

    if (!password_verify_app($password, (string) $user['password'])) {
        auth_throttle_fail('customer', $email, $user);
        log_login('customer', (int) $user['id'], $email, false, 'bad_password');

        return ['ok' => false, 'user' => null, 'error' => $generic];
    }

    // Upgrade a hash made by an older algorithm or a lower cost.
    if (password_needs_upgrade((string) $user['password'])) {
        Database::update(
            'users',
            ['password' => password_hash_app($password)],
            '`id` = :id',
            ['id' => (int) $user['id']]
        );
    }

    auth_throttle_success('customer', $email);

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

    if (!is_string($intended)) {
        return url($fallback);
    }

    // require_login() stores a REQUEST_URI, but checkout.php stores
    // url('checkout.php') - an absolute URL on this very origin. Unwind that
    // one case to the path it names, exactly and only when the origin is ours;
    // every other absolute URL is somebody else's site and is refused below.
    $parts  = parse_url(SITE_URL);
    $origin = isset($parts['scheme'], $parts['host'])
        ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
        : '';
    if ($origin !== '' && strncmp($intended, $origin . '/', strlen($origin) + 1) === 0) {
        $intended = substr($intended, strlen($origin));
    }

    // Refusing a leading "//" was not enough - see auth_safe_intended_path().
    return auth_safe_intended_path($intended, BASE_PATH) ?? url($fallback);
}

/**
 * A stored "where they were going" path, or null when it is not one.
 *
 * The value was taken from $_SERVER['REQUEST_URI'] on an earlier request -
 * which is the visitor's to write - and it goes back out in a Location
 * header, so it gets the same treatment admin_safe_return() gives a
 * request-supplied return target, for the same reasons. V32: refusing only a
 * leading "//" left several ways out of the site open. Browsers that read a
 * backslash as a path separator resolve "/\evil.example" off-site; "%2e%2e"
 * walks out of $within once the server has decoded it; and a CR or LF makes
 * header() throw after the caller has already done its work.
 *
 * @param string $within Path prefix the target must sit under: BASE_PATH on
 *                       the storefront, the admin's own path in /admin.
 */
function auth_safe_intended_path(string $candidate, string $within = ''): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    // chr(92) and POSIX classes rather than escapes, matching the admin twin:
    // these files have been written through a heredoc more than once and a
    // heredoc eats the escape. A real link never carries a raw space either -
    // http_build_query() encodes it.
    if (strpos($candidate, chr(92)) !== false
        || preg_match('/[[:cntrl:][:space:]]/', $candidate) === 1) {
        return null;
    }

    // Scheme-relative and absolute URLs both leave this origin, and a
    // traversal segment disqualifies the whole target however it resolves.
    if ($candidate[0] !== '/' || strncmp($candidate, '//', 2) === 0 || strpos($candidate, '..') !== false) {
        return null;
    }

    $prefix = rtrim($within, '/') . '/';
    $path   = (string) parse_url($candidate, PHP_URL_PATH);
    if ($path === '' || strncmp($path, $prefix, strlen($prefix)) !== 0) {
        return null;
    }

    // The same traversal, percent-encoded: a browser decodes "%2e%2e" before
    // it resolves the path, and a server may read %2f or %5c as a separator.
    // Every hop may undo one layer, so each layer is judged. Only the PATH -
    // a query string may legitimately carry an encoded slash.
    for ($layer = 0; $layer < 4; $layer++) {
        if (stripos($path, '%2e') !== false || stripos($path, '%2f') !== false || stripos($path, '%5c') !== false
            || strpos($path, '..') !== false || strpos($path, '//') !== false || strpos($path, chr(92)) !== false
            || preg_match('/[[:cntrl:]]/', $path) === 1) {
            return null;
        }
        $decoded = rawurldecode($path);
        if ($decoded === $path) {
            break;
        }
        $path = $decoded;
    }

    return $candidate;
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

    $session = is_array($_SESSION[ADMIN_SESSION_KEY] ?? null) ? $_SESSION[ADMIN_SESSION_KEY] : [];
    $id = $session['id'] ?? null;
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

    // Password changed (by them or by another admin), signed out everywhere,
    // idle too long, or open for longer than an admin session may live.
    if (!auth_session_still_valid('admin', $session, $row)) {
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

/**
 * Establish an admin session.
 *
 * @param string $mfaMethod totp, backup, device - or 'none' when the account
 *                          has no second factor. See login_user().
 */
function login_admin(array $admin, string $mfaMethod = 'none'): void
{
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id'        => (int) $admin['id'],
        'email'     => $admin['email'],
        'name'      => $admin['name'],
        'logged_at' => time(),
        'seen_at'   => time(),
        // Admins never get "remember me": the session ends with the browser,
        // idles out in half an hour and cannot outlive the working day.
        'auth_version' => auth_row_version($admin),
        'mfa'          => ['method' => $mfaMethod, 'at' => time()],
    ];
    $_SESSION['_regenerated_at'] = time();

    mfa_pending_clear();

    Database::update('admins', [
        'last_login_at' => date('Y-m-d H:i:s'),
        'last_login_ip' => client_ip(),
        'failed_logins' => 0,
        'locked_until'  => null,
    ], '`id` = :id', ['id' => (int) $admin['id']]);

    log_login('admin', (int) $admin['id'], $admin['email'], true);
}

function logout_admin(bool $everywhere = false): void
{
    $admin = admin_user();
    if ($admin !== null) {
        log_activity('admin.logout', 'admin', (int) $admin['id'], $admin['name'] . ' signed out');
        if ($everywhere) {
            auth_bump_version('admin', (int) $admin['id']);
            security_event('auth.signed_out_everywhere', 'info', [], (int) $admin['id'], 'admin');
        }
    }
    unset($_SESSION[ADMIN_SESSION_KEY]);
    mfa_pending_clear();
    session_regenerate_id(true);
}

/**
 * Verify admin credentials (email or username).
 *
 * Same rules as the customer side, tighter numbers: one wording for every
 * refusal (the old one confirmed which usernames exist), atomic per-IP and
 * per-IP+account throttles instead of a lock that anybody could trigger on the
 * owner's account, and a delay - never a refusal - when the account itself is
 * being guessed at from somewhere new.
 *
 * @return array{ok:bool, admin:?array, error:?string, throttled?:bool}
 */
function attempt_admin_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    $generic    = 'Invalid credentials.';

    $throttle = auth_throttle_gate('admin', $identifier);
    if ($throttle !== null) {
        return ['ok' => false, 'admin' => null, 'error' => $throttle, 'throttled' => true];
    }

    $admin = Database::fetch(
        'SELECT * FROM `admins` WHERE `email` = :id OR `username` = :id2 LIMIT 1',
        ['id' => $identifier, 'id2' => $identifier]
    );

    if ($admin === null) {
        // Delay first, hash second - exactly as the branch below does, so an
        // unknown username cannot be told from a real one by the clock.
        auth_attack_delay('admin', $identifier, null);
        password_verify($password, auth_dummy_hash());
        auth_throttle_fail('admin', $identifier, null);
        log_login('admin', null, $identifier, false, 'unknown_account');
        return ['ok' => false, 'admin' => null, 'error' => $generic];
    }

    auth_attack_delay('admin', $identifier, (int) $admin['id']);

    if ($admin['status'] !== 'active') {
        // Same reason as the customer twin: a branch that skips the hash
        // answers fast, and "fast" is an answer about whether the account
        // exists. Discarded result.
        password_verify(
            $password,
            ((string) $admin['password']) !== '' ? (string) $admin['password'] : auth_dummy_hash()
        );
        auth_throttle_fail('admin', $identifier, $admin);
        log_login('admin', (int) $admin['id'], $identifier, false, 'inactive');
        return ['ok' => false, 'admin' => null, 'error' => $generic];
    }

    if (!password_verify_app($password, (string) $admin['password'])) {
        auth_throttle_fail('admin', $identifier, $admin);
        log_login('admin', (int) $admin['id'], $identifier, false, 'bad_password');

        return ['ok' => false, 'admin' => null, 'error' => $generic];
    }

    if (password_needs_upgrade((string) $admin['password'])) {
        Database::update(
            'admins',
            ['password' => password_hash_app($password)],
            '`id` = :id',
            ['id' => (int) $admin['id']]
        );
    }

    auth_throttle_success('admin', $identifier);

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

/**
 * Burn a reset token, atomically.
 *
 * The old pair of statements (find, then update by id) let two submissions of
 * the same leaked token both pass the SELECT and both set a password. Claiming
 * the row in one conditional UPDATE means exactly one submission can win, and
 * the caller only changes the password when it did.
 *
 * @return bool true when THIS call consumed the token.
 */
function claim_password_reset(int $resetId): bool
{
    $statement = Database::query(
        'UPDATE `password_resets` SET `used_at` = NOW()
          WHERE `id` = :id AND `used_at` IS NULL AND `expires_at` > NOW()',
        ['id' => $resetId]
    );

    return $statement->rowCount() === 1;
}

/** @deprecated Use claim_password_reset(), which cannot be raced. */
function consume_password_reset(int $resetId): void
{
    claim_password_reset($resetId);
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

// ===========================================================================
//  PASSWORD HASHING AND POLICY
//
//  Argon2id where the build has it (PHP 8.2 on this host and on Hostinger
//  do), bcrypt otherwise. Two reasons beyond fashion:
//
//   * bcrypt silently ignores everything past 72 bytes, so a long passphrase
//     is weaker than the customer thinks - "aaa...a(72)X" and "aaa...a(72)Y"
//     are the same password to it. Argon2id has no such limit.
//   * the cost is tunable in memory as well as time, which is what makes GPU
//     cracking expensive.
//
//  Existing bcrypt rows keep working: password_verify_app() reads both, and a
//  correct sign-in quietly re-hashes with the current algorithm.
// ===========================================================================

/** The algorithm new hashes are made with. */
function password_app_algo(): string
{
    return defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)
        ? PASSWORD_ARGON2ID
        : PASSWORD_BCRYPT;
}

/** Cost settings for the current algorithm. */
function password_app_options(): array
{
    // 19 MiB / 2 passes is the OWASP baseline and stays inside the 128 MB
    // memory_limit shared hosting gives PHP.
    return password_app_algo() === PASSWORD_BCRYPT
        ? ['cost' => 12]
        : ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
}

function password_hash_app(string $password): string
{
    $algo = password_app_algo();

    // Under bcrypt the extra bytes are a lie either way; cutting them here
    // means the stored hash matches what verification will compare.
    if ($algo === PASSWORD_BCRYPT) {
        $password = substr($password, 0, 72);
    }

    return password_hash($password, $algo, password_app_options());
}

/** Verify against a hash made by any algorithm this project has ever used. */
function password_verify_app(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    if (strncmp($hash, '$2y$', 4) === 0 || strncmp($hash, '$2a$', 4) === 0 || strncmp($hash, '$2b$', 4) === 0) {
        return password_verify(substr($password, 0, 72), $hash);
    }

    return password_verify($password, $hash);
}

/** Was this hash made with something weaker than we use now? */
function password_needs_upgrade(string $hash): bool
{
    return password_needs_rehash($hash, password_app_algo(), password_app_options());
}

/** The minimum length for this kind of account. */
function password_min_length(string $userType = 'customer'): int
{
    $key = $userType === 'admin' ? 'sec_password_min_admin' : 'sec_password_min';
    $min = setting_int($key, $userType === 'admin' ? 12 : PASSWORD_MIN_LENGTH);

    // The setting can raise the floor, never lower it.
    return max(PASSWORD_MIN_LENGTH, min(64, $min));
}

/**
 * The bundled "never use this" list - the passwords every credential-stuffing
 * list starts with. Small on purpose (a few hundred lines, not 10 MB): it is
 * read on password changes only, and shared hosting has no memory to spare.
 */
function password_blocklist(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    $list = [];
    $file = INCLUDES_PATH . '/data/common-passwords.txt';
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $list[$line] = true;
            }
        }
    }

    return $list;
}

/**
 * Why this password cannot be used, or null when it can.
 *
 * @param string $context Free text the password must not simply repeat -
 *                        the email address, the customer's name, the store.
 */
function password_policy_error(string $password, string $userType = 'customer', string $context = ''): ?string
{
    $min = password_min_length($userType);

    if ($password === '') {
        return null;   // required() reports an empty field
    }
    if (mb_strlen($password) < $min) {
        return 'Password must be at least ' . $min . ' characters.';
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return 'Password must be ' . PASSWORD_MAX_LENGTH . ' characters or fewer.';
    }
    // A long passphrase does not need a digit in it; a short password does.
    if (mb_strlen($password) < PASSWORD_PASSPHRASE_LENGTH
        && (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1)) {
        return 'Password must include at least one letter and one number, or be '
            . PASSWORD_PASSPHRASE_LENGTH . ' characters or longer.';
    }

    $lower = strtolower($password);
    $blocked = password_blocklist();
    // Checked with the trailing digits people add to get past a rule, so
    // "password1" and "password123" fail with "password".
    $stripped = rtrim($lower, '0123456789!@#$');
    if (isset($blocked[$lower]) || ($stripped !== '' && strlen($stripped) >= 4 && isset($blocked[$stripped]))) {
        return 'That password appears on every attacker\'s first-guess list. Please choose another.';
    }

    foreach (password_policy_terms($context) as $term) {
        if (strlen($term) >= 4 && strpos($lower, $term) !== false) {
            return 'Password must not contain your email address, your name or the store name.';
        }
    }

    return null;
}

/** The words a password must not simply repeat. */
function password_policy_terms(string $context): array
{
    $terms = [strtolower(SITE_NAME)];
    $store = strtolower(trim((string) setting('store_name', '')));
    if ($store !== '') {
        $terms[] = $store;
    }

    foreach (preg_split('/[\s@._\-+]+/', strtolower($context)) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $terms[] = $part;
        }
    }

    return array_values(array_unique(array_filter($terms, static fn (string $t): bool => strlen($t) >= 4)));
}

// ===========================================================================
//  SESSION VALIDITY
//
//  Three questions on every request, for customers and admins alike:
//  is this session still the account's current generation, has it been idle
//  too long, and has it simply been open for too long?
// ===========================================================================

/** The account's session generation. 1 for a row from before the migration. */
function auth_row_version(array $row): int
{
    return isset($row['auth_version']) ? max(1, (int) $row['auth_version']) : 1;
}


/**
 * Move an account to a new session generation, ending every session that is
 * not holding the new number - the point of the exercise after a password
 * change, a reset, an admin-set password or "sign out everywhere".
 *
 * The acting session is re-stamped by the caller (auth_refresh_session()), so
 * the person who pressed the button stays signed in.
 */
function auth_bump_version(string $userType, int $id): int
{
    $table = $userType === 'admin' ? 'admins' : 'users';

    try {
        Database::query(
            'UPDATE `' . $table . '` SET `auth_version` = `auth_version` + 1 WHERE `id` = :id',
            ['id' => $id]
        );
        $version = (int) Database::fetchColumn(
            'SELECT `auth_version` FROM `' . $table . '` WHERE `id` = :id',
            ['id' => $id]
        );
    } catch (Throwable $e) {
        // Column missing (migration not run yet): nothing to bump, and the
        // caller must not fail because of it.
        ErrorHandler::log('warning', 'auth_version bump failed for ' . $userType . ' ' . $id . ': ' . $e->getMessage());
        return 1;
    }

    // Remembered devices are credentials of their own: they go too.
    if ($userType === 'customer') {
        auth_remember_forget_user($id);
    }

    // So are browsers that were trusted to skip the second factor. The trust
    // is already stamped with the old generation, so it would stop working
    // anyway; revoking it keeps the "your devices" list honest rather than
    // showing browsers that are silently dead.
    mfa_devices_revoke_all($userType, $id);

    return max(1, $version);
}

/** Keep the acting session alive across an auth_version bump. */
function auth_refresh_session(string $userType, int $version): void
{
    $key = $userType === 'admin' ? ADMIN_SESSION_KEY : USER_SESSION_KEY;
    if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
        $_SESSION[$key]['auth_version'] = $version;
        $_SESSION[$key]['logged_at']    = time();
        $_SESSION[$key]['seen_at']      = time();
    }
}

/** [idle seconds, absolute seconds] for this kind of session. 0 = no limit. */
function auth_session_timeouts(string $userType): array
{
    if ($userType === 'admin') {
        return [
            max(0, min(1440, setting_int('sec_session_idle_admin', 30))) * 60,
            max(0, min(168, setting_int('sec_session_absolute_admin', 12))) * 3600,
        ];
    }

    return [
        max(0, min(43200, setting_int('sec_session_idle_customer', 240))) * 60,
        max(0, min(365, setting_int('sec_session_absolute_customer', 30))) * 86400,
    ];
}

/** Is the session in $session still allowed to speak for the row in $row? */
function auth_session_still_valid(string $userType, array $session, array $row): bool
{
    $key     = $userType === 'admin' ? ADMIN_SESSION_KEY : USER_SESSION_KEY;
    $now     = time();
    $version = auth_row_version($row);

    $stored = $session['auth_version'] ?? null;

    // Sessions opened before this code deployed carry no stamp at all. Refusing
    // them signed out every admin and every customer the moment the files
    // landed, so one is adopted instead: stamped with the generation it is
    // already on and checked normally from the next request. Adoption is not a
    // way past anything - it is allowed only while the account is still on its
    // first generation (any bump means a password change or a "sign out
    // everywhere" that this session is exactly what was meant to end), only
    // when the session carries the sign-in time the timeouts are judged by,
    // and the idle and absolute checks below still run on it in this same call.
    if (!is_int($stored)) {
        $startedAt = $session['logged_at'] ?? null;
        if ($version !== 1 || !is_int($startedAt) || $startedAt <= 0) {
            security_event('auth.session_superseded', 'low',
                ['user_type' => $userType, 'reason' => 'unstamped'], (int) $row['id'], $userType);
            return false;
        }

        $stored = $version;
        if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
            $_SESSION[$key]['auth_version'] = $version;
        }
        security_event('auth.session_adopted', 'info', ['user_type' => $userType], (int) $row['id'], $userType);
    }

    if ($stored !== $version) {
        // The password changed under this session, or somebody signed out
        // everywhere - either way, "sign in again" is the safe answer.
        security_event('auth.session_superseded', 'low', ['user_type' => $userType], (int) $row['id'], $userType);
        return false;
    }

    [$idle, $absolute] = auth_session_timeouts($userType);

    // Both timeouts are measured from the sign-in time, so a session that does
    // not carry one cannot be judged - and "cannot be judged" must never read
    // as "passed", which is what defaulting these to now() quietly meant.
    $started = $session['logged_at'] ?? null;
    if (!is_int($started) || $started <= 0) {
        security_event('auth.session_superseded', 'low',
            ['user_type' => $userType, 'reason' => 'untimed'], (int) $row['id'], $userType);
        return false;
    }
    $seen = $session['seen_at'] ?? null;
    $seen = is_int($seen) && $seen > 0 ? $seen : $started;

    if ($idle > 0 && $now - $seen > $idle) {
        security_event('auth.session_idle_timeout', 'info',
            ['user_type' => $userType, 'idle_seconds' => $now - $seen], (int) $row['id'], $userType);
        return false;
    }
    if ($absolute > 0 && $now - $started > $absolute) {
        security_event('auth.session_expired', 'info',
            ['user_type' => $userType, 'age_seconds' => $now - $started], (int) $row['id'], $userType);
        return false;
    }

    // One write a minute at most: this runs on every request.
    if ($now - $seen >= 60 && isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
        $_SESSION[$key]['seen_at'] = $now;
    }

    return true;
}

// ===========================================================================
//  "KEEP ME SIGNED IN"
//
//  A separate credential, not a longer-lived session cookie. The cookie holds
//  selector:validator; the database holds the selector and a SHA-256 of the
//  validator, so a leaked table hands out nothing. Every use rotates the pair,
//  and a validator that does not match a known selector means the cookie was
//  copied: every token for that account is dropped on the spot.
//
//  Admins never get one.
// ===========================================================================

function auth_remember_days(): int
{
    return max(1, min(365, setting_int('sec_remember_days', 30)));
}

function auth_remember_cookie_params(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'domain'   => '',
        'secure'   => session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Issue a fresh remember-me token for this browser. */
function auth_remember_issue(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        $selector  = bin2hex(random_bytes(9));    // 18 chars, only an index key
        $validator = bin2hex(random_bytes(32));   // the actual secret

        Database::insert('remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'auth_version'   => auth_user_version($userId),
            'expires_at'     => date('Y-m-d H:i:s', time() + auth_remember_days() * 86400),
            'ip_address'     => client_ip(),
            'user_agent'     => user_agent(),
        ]);

        $cookie = $selector . ':' . $validator;
        if (!headers_sent()) {
            setcookie(REMEMBER_COOKIE_NAME, $cookie,
                auth_remember_cookie_params(time() + auth_remember_days() * 86400));
        }
        $_COOKIE[REMEMBER_COOKIE_NAME] = $cookie;

        // Opportunistic housekeeping; the table is tiny.
        if (random_int(1, 50) === 1) {
            Database::query('DELETE FROM `remember_tokens` WHERE `expires_at` < NOW() LIMIT 500');
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Remember-me token not issued: ' . $e->getMessage());
    }
}

/** The account's current session generation, read fresh. */
function auth_user_version(int $userId): int
{
    try {
        return max(1, (int) Database::fetchColumn(
            'SELECT `auth_version` FROM `users` WHERE `id` = :id',
            ['id' => $userId]
        ));
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * Sign the visitor back in from a remember-me cookie, if they have a valid one
 * and no live session. Called once per request from init.php.
 */
function auth_remember_restore(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION[USER_SESSION_KEY]['id'])) {
        return;
    }
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || substr_count($cookie, ':') !== 1) {
        return;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (preg_match('/^[a-f0-9]{18}$/', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/', $validator) !== 1) {
        auth_remember_forget_cookie();
        return;
    }

    try {
        $row = Database::fetch('SELECT * FROM `remember_tokens` WHERE `selector` = :s LIMIT 1', ['s' => $selector]);

        if ($row === null) {
            auth_remember_forget_cookie();
            return;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Right selector, wrong secret: somebody is replaying a copy.
            auth_remember_forget_user((int) $row['user_id']);
            auth_remember_forget_cookie();
            security_event('auth.remember_token_reuse', 'high', ['selector' => $selector], (int) $row['user_id'], 'customer');
            return;
        }

        if (strtotime((string) $row['expires_at']) <= time()) {
            Database::delete('remember_tokens', '`id` = :id', ['id' => (int) $row['id']]);
            auth_remember_forget_cookie();
            return;
        }

        $user = Database::fetch(
            'SELECT * FROM `users` WHERE `id` = :id AND `status` = :status LIMIT 1',
            ['id' => (int) $row['user_id'], 'status' => 'active']
        );

        // A password change since the token was issued invalidates it.
        if ($user === null || auth_row_version($user) !== max(1, (int) ($row['auth_version'] ?? 1))) {
            auth_remember_forget_user((int) $row['user_id']);
            auth_remember_forget_cookie();
            return;
        }

        // "Keep me signed in" is ONE factor - a cookie. On an account with a
        // second factor it may only complete a sign-in on a browser that has
        // already proved that factor and is still inside its trust window.
        //
        // Nothing is consumed on the way out: the token is left intact so the
        // customer can finish signing in normally, at which point the full
        // challenge runs. Burning it here would punish the account holder for
        // having 2FA switched on, and setting a pending challenge from a
        // background request would ambush them on an unrelated page.
        $method = 'none';
        if (mfa_active('customer', $user)) {
            if (!mfa_device_trusted('customer', (int) $user['id'])) {
                security_event('mfa.remember_needs_factor', 'info', [], (int) $user['id'], 'customer');
                return;
            }
            $method = 'device';
        }

        // One use, one token: the old row goes before the new one is issued.
        Database::delete('remember_tokens', '`id` = :id', ['id' => (int) $row['id']]);
        login_user($user, true, $method);
        security_event('auth.remember_login', 'info', ['mfa' => $method], (int) $user['id'], 'customer');
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Remember-me restore failed: ' . $e->getMessage());
    }
}

/** Drop the token this browser holds (sign out on this device only). */
function auth_remember_forget_current(int $userId): void
{
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return;
    }
    [$selector] = explode(':', $cookie, 2);
    if (preg_match('/^[a-f0-9]{18}$/', $selector) !== 1) {
        return;
    }

    try {
        Database::delete('remember_tokens', '`selector` = :s AND `user_id` = :u', ['s' => $selector, 'u' => $userId]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Remember-me cleanup failed: ' . $e->getMessage());
    }
}

/** Drop every remembered device for an account. */
function auth_remember_forget_user(int $userId): void
{
    try {
        Database::delete('remember_tokens', '`user_id` = :id', ['id' => $userId]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Remember-me purge failed: ' . $e->getMessage());
    }
}

function auth_remember_forget_cookie(): void
{
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE_NAME, '', auth_remember_cookie_params(time() - 86400));
    }
}

// ===========================================================================
//  LOGIN THROTTLING
//
//  Everything is counted in `rate_limits`, which is atomic (one INSERT ... ON
//  DUPLICATE KEY UPDATE) and shared across sessions and processes. That is the
//  point: the old counter was read, held through a 100 ms password_verify and
//  written back as an absolute value, so fifty parallel guesses counted as one.
//
//  Four scopes, deliberately different in kind:
//    login.ip        every attempt from one address    - refuses
//    login.pair      one address guessing one account  - refuses
//    login.account   one account from anywhere         - delays, never
//                    refuses: refusing here is exactly the switch that let
//                    anybody lock any customer (or the owner) out on demand
//    login.global    the whole site                    - records only
//
//  The account-scoped two key on what the identifier RESOLVES to, so every
//  spelling of one account counts once (auth_throttle_identity), and they are
//  the only two a successful sign-in forgives. The per-address ceilings are
//  never cleared: clearing them made one valid account of the guesser's own a
//  reset button for the budget that stops password spraying.
// ===========================================================================

const AUTH_THROTTLE_MESSAGE = 'Too many sign-in attempts from this device. Please wait a few minutes and try again.';

/** The throttle numbers, all overridable in Admin > Security > Settings. */
function auth_throttle_limits(): array
{
    return [
        'window'  => 900,
        'ip'      => max(3, min(500, setting_int('sec_login_ip_max', 20))),
        'ip_day'  => max(10, min(5000, setting_int('sec_login_ip_daily', 100))),
        'account' => max(3, min(100, setting_int('sec_login_account_max', 5))),
        'global'  => max(0, min(100000, setting_int('sec_login_global_max', 400))),
    ];
}

/**
 * Count this attempt and say whether it may proceed to password_verify().
 *
 * @return string|null An error message when the attempt is refused.
 */
function auth_throttle_gate(string $userType, string $identifier): ?string
{
    $limits = auth_throttle_limits();
    $ip     = client_ip();

    if (!rate_limit_attempt('login.ip.' . $userType, $ip, $limits['ip'], $limits['window'])
        || !rate_limit_attempt('login.ip.day.' . $userType, $ip, $limits['ip_day'], 86400)) {
        security_event('auth.login_throttled', 'medium', ['user_type' => $userType, 'scope' => 'ip']);
        log_login($userType, null, $identifier, false, 'ip_throttled');
        return AUTH_THROTTLE_MESSAGE;
    }

    // Resolved rather than as typed, so every spelling of one account shares
    // one budget - see auth_throttle_identity(). Looked up only after the
    // per-device ceilings have let the attempt through.
    $id = auth_throttle_identity($userType, $identifier);

    if ($id !== ''
        && !rate_limit_attempt('login.pair.' . $userType, $ip . '|' . $id, $limits['account'], $limits['window'])) {
        security_event('auth.login_throttled', 'medium', ['user_type' => $userType, 'scope' => 'ip_account']);
        log_login($userType, null, $identifier, false, 'pair_throttled');
        return AUTH_THROTTLE_MESSAGE;
    }

    return null;
}

/**
 * Slow down guessing at one identifier without ever refusing its owner.
 *
 * A device that has signed in to this account before is waved through, so the
 * customer whose address somebody else is guessing at is not inconvenienced at
 * all; anybody else pays a growing delay.
 *
 * Called on BOTH branches of a sign-in, with $accountId null when the address
 * does not exist. It used to run only after the account had been found, so a
 * registered address visibly stalled where an unregistered one answered at
 * once - the same enumeration signal V7 took out of the error wording, handed
 * back by the clock. The counter is keyed on the identifier, not on the row,
 * so an address that exists nowhere still has one of its own to be judged by.
 */
function auth_attack_delay(string $userType, string $identifier, ?int $accountId = null): void
{
    $limits = auth_throttle_limits();
    $fails  = rate_limit_count(
        'login.account.' . $userType,
        auth_throttle_identity($userType, $identifier),
        $limits['window'],
        false
    );

    if ($fails < $limits['account']) {
        return;
    }
    if ($accountId !== null && $accountId > 0 && auth_known_device($userType, $accountId, client_ip())) {
        return;
    }

    usleep((int) min(2000000, 250000 * (((int) $fails - $limits['account']) + 1)));
}

/**
 * The key the (device, account) and account-wide counters count against.
 *
 * They used to count the string the visitor submitted. On the admin side the
 * lookup accepts the email OR the username, so one account had two budgets -
 * five guesses under each spelling rather than five in total - and anything
 * the database collation treats as equal (case, trailing space) was a budget
 * again. Resolving the identifier to the account id first puts every spelling
 * of one account on one counter.
 *
 * ONLY the counter key is resolved here. The credential check still runs
 * against the identifier as submitted, and an identifier that resolves to
 * nothing keeps a counter of its own - its lower-cased self - so guessing at
 * addresses that do not exist is counted, and delayed, exactly the same way.
 */
function auth_throttle_identity(string $userType, string $identifier): string
{
    $id = mb_strtolower(trim($identifier));
    if ($id === '') {
        return '';
    }

    // One lookup per identifier per request: the gate, the delay and the
    // failure counter all ask for the same key.
    static $resolved = [];
    $memo = $userType . '|' . $id;
    if (isset($resolved[$memo])) {
        return $resolved[$memo];
    }

    try {
        $accountId = $userType === 'admin'
            ? Database::fetchColumn(
                'SELECT `id` FROM `admins` WHERE `email` = :id OR `username` = :id2 LIMIT 1',
                ['id' => $id, 'id2' => $id]
            )
            : Database::fetchColumn(
                'SELECT `id` FROM `users` WHERE `email` = :id LIMIT 1',
                ['id' => $id]
            );
    } catch (Throwable $e) {
        // A limiter that cannot resolve the account still has to count.
        ErrorHandler::log('warning', 'auth_throttle_identity failed: ' . $e->getMessage());
        $accountId = null;
    }

    return $resolved[$memo] = $accountId === null ? $id : '#' . (int) $accountId;
}

/** Has this address signed in to this account successfully before? */
function auth_known_device(string $userType, int $accountId, string $ip): bool
{
    if ($accountId <= 0 || $ip === '') {
        return false;
    }

    try {
        return Database::exists(
            'login_history',
            "`user_type` = :t AND `user_id` = :u AND `status` = 'success' AND `ip_address` = :ip
             AND `created_at` > (NOW() - INTERVAL 90 DAY)",
            ['t' => $userType, 'u' => $accountId, 'ip' => $ip]
        );
    } catch (Throwable $e) {
        return false;
    }
}

/** Record a failed attempt in every scope, and alert the owner if warranted. */
function auth_throttle_fail(string $userType, string $identifier, ?array $account): void
{
    $limits = auth_throttle_limits();
    $id     = mb_strtolower(trim($identifier));

    security_event('auth.login_failed', 'low', [
        'user_type'  => $userType,
        'identifier' => mask_email($id),
    ], $account === null ? null : (int) $account['id'], $userType);

    // The event above shows the address; the counter below is keyed on the
    // account it resolves to, so both spellings of one admin count together.
    $fails = rate_limit_count(
        'login.account.' . $userType,
        auth_throttle_identity($userType, $identifier),
        $limits['window'],
        true
    );

    if ($account !== null && $fails >= $limits['account']) {
        auth_notify_account_attack($userType, $account, (int) $fails);
    }

    // Site-wide signal. Recorded, never enforced: a global refusal would be a
    // way for one attacker to stop every customer signing in.
    if ($limits['global'] > 0
        && !rate_limit_attempt('login.global', 'all', $limits['global'], $limits['window'])) {
        security_event('auth.login_flood', 'high', ['limit' => $limits['global'], 'window' => $limits['window']]);
    }

    // The old counter column still feeds the customer and admin list screens.
    if ($account !== null) {
        try {
            $table = $userType === 'admin' ? 'admins' : 'users';
            Database::query(
                'UPDATE `' . $table . '` SET `failed_logins` = LEAST(`failed_logins` + 1, 60000) WHERE `id` = :id',
                ['id' => (int) $account['id']]
            );
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'failed_logins update failed: ' . $e->getMessage());
        }
    }
}

/**
 * A successful sign-in forgives the counters that belong to THAT account.
 *
 * Never the per-device ceilings. Clearing login.ip / login.ip.day on success
 * meant anyone holding one valid account of their own could spray a password
 * across every other account from the same address and reset the budget with
 * a sign-in of their own whenever it ran low - which is precisely what the
 * per-IP ceiling exists to stop. They decay with their own window instead;
 * an owner locked out by a shared office address is let back in from
 * Admin > Security > Sign-in throttles > "Clear the counters now".
 */
function auth_throttle_success(string $userType, string $identifier): void
{
    $key = auth_throttle_identity($userType, $identifier);
    if ($key === '') {
        return;
    }

    rate_limit_clear('login.pair.' . $userType, client_ip() . '|' . $key);
    rate_limit_clear('login.account.' . $userType, $key);
}

/**
 * Tell the account's owner that somebody is guessing at their password.
 *
 * At most one message an hour per account. This is what replaces the old hard
 * lock: the owner hears about it, and can still sign in.
 */
function auth_notify_account_attack(string $userType, array $account, int $failures): void
{
    $email = (string) ($account['email'] ?? '');
    if ($email === '' || !rate_limit_attempt('login.alert.' . $userType, $email, 1, 3600)) {
        return;
    }

    security_event('auth.account_under_attack', 'high', [
        'user_type' => $userType,
        'failures'  => $failures,
    ], (int) $account['id'], $userType);

    $name = trim((string) ($account['first_name'] ?? $account['name'] ?? ''));

    try {
        notify('login_blocked', $email, [
            'customer_name'  => $name !== '' ? $name : 'there',
            'customer_email' => $email,
            'attempts'       => (string) $failures,
            'request_ip'     => client_ip(),
            'when'           => format_datetime(date('Y-m-d H:i:s')),
            'reset_url'      => canonical_url('forgot-password.php'),
        ], null, null, 'email', [
            'email_type'     => 'login_blocked',
            'recipient_name' => $name,
            'recipient_type' => $userType === 'admin' ? 'admin' : 'customer',
            'force'          => true,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Login alert not queued: ' . $e->getMessage());
    }
}

/**
 * "Your password was changed" - sent after every password change and reset, so
 * that a takeover is noticed by the person it happened to.
 */
function auth_notify_password_changed(array $user, string $how = 'changed'): void
{
    $email = (string) ($user['email'] ?? '');
    if ($email === '') {
        return;
    }
    $name = trim((string) ($user['first_name'] ?? $user['name'] ?? ''));

    // Reads into "The password on your account was ___ on <date>." There are
    // three ways a password changes here and the notice has to name the right
    // one: an administrator setting it for somebody is not "from your
    // account", and telling the owner it was would hide the very thing this
    // message exists to surface - a change they did not make themselves.
    $changeType = [
        'reset' => 'reset using a link we emailed you',
        'admin' => 'changed for you by our support team',
    ][$how] ?? 'changed from your account';

    try {
        notify('password_changed', $email, [
            'customer_name'  => $name !== '' ? $name : 'there',
            'customer_email' => $email,
            'change_type'    => $changeType,
            'when'           => format_datetime(date('Y-m-d H:i:s')),
            'request_ip'     => client_ip(),
            'reset_url'      => canonical_url('forgot-password.php'),
            'support_url'    => canonical_url('contact.php'),
        ], 'user', isset($user['id']) ? (int) $user['id'] : null, 'email', [
            'email_type'     => 'password_changed',
            'recipient_name' => $name,
            'recipient_type' => isset($user['role_id']) ? 'admin' : 'customer',
            'force'          => true,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Password-changed notice not queued: ' . $e->getMessage());
    }
}

/**
 * Everything a password change has to do besides writing the hash: a new
 * generation (every other session and remembered device dies), the acting
 * session kept alive, the counters forgiven, the owner told.
 */
function auth_after_password_change(string $userType, array $account, string $how = 'changed'): void
{
    $id = (int) ($account['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    $version = auth_bump_version($userType, $id);
    auth_refresh_session($userType, $version);

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();
    }

    // Same keys the counters were written under - see auth_throttle_identity().
    $key = auth_throttle_identity($userType, (string) ($account['email'] ?? ''));
    if ($key !== '') {
        rate_limit_clear('login.account.' . $userType, $key);
        rate_limit_clear('login.pair.' . $userType, client_ip() . '|' . $key);
    }

    security_event('auth.password_changed', 'medium', ['user_type' => $userType, 'how' => $how], $id, $userType);
    auth_notify_password_changed($account, $how);
}
