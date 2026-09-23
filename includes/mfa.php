<?php
/**
 * ShopInnKart - Two-factor authentication.
 *
 * One engine for both sides of the house. An admin and a customer differ only
 * in which table their row lives in, which settings decide whether they are
 * required to use it, and whether an emailed code is offered as a fallback -
 * so everything below takes a $userType of 'admin' or 'customer' and the rest
 * is shared.
 *
 * The shape of a sign-in with a second factor:
 *
 *   password correct  ->  mfa_sign_in()  ->  'complete'   login now
 *                                        ->  'challenge'  half-authenticated
 *                                        ->  'enrol'      half-authenticated
 *
 * "Half-authenticated" here is not a weaker session: it is NO session. The
 * principal key ($_SESSION['admin'] / ['user']) is never written until the
 * second factor is satisfied, so a half-authenticated browser is signed out as
 * far as every admin_require() and require_login() in the codebase is
 * concerned. All it carries is $_SESSION['_mfa_pending'], which names the
 * account, expires in five minutes and is worth nothing anywhere but the 2FA
 * screen. That is deliberately stronger than a flag on a live session, which
 * only stops the code that remembers to check it.
 *
 * Factors, in the order they are offered:
 *   TOTP          an authenticator app - see includes/totp.php
 *   backup code   ten single-use codes, shown once, stored hashed
 *   email OTP     customers only: six digits, ten minutes, single use
 *
 * SMS is NOT a factor here and the UI says so rather than showing a dead
 * option: the store has no SMS provider configured, and India additionally
 * requires DLT-registered sender IDs and templates before a single message can
 * be sent. Adding one is a purchase the owner has to make, not a checkbox.
 */

declare(strict_types=1);

/** Cookie holding the "trust this device" token. One per audience: see mfa_device_cookie(). */
const MFA_DEVICE_COOKIE_ADMIN    = 'sik_td_a';
const MFA_DEVICE_COOKIE_CUSTOMER = 'sik_td_c';

/** How long a half-authenticated browser has to finish the second step. */
const MFA_PENDING_TTL = 300;

/** Wrong codes allowed before the pending challenge is thrown away. */
const MFA_PENDING_TRIES = 5;

/** Emailed codes: six digits, ten minutes, five guesses. */
const MFA_OTP_TTL      = 600;
const MFA_OTP_ATTEMPTS = 5;
const MFA_OTP_COOLDOWN = 60;

// ===========================================================================
//  POLICY
//
//  Who MUST use a second factor. Note there is no "switch 2FA off for
//  everybody" setting: an admin who wants to protect their own account is
//  never stopped from doing so, and a policy that could silently disarm an
//  enrolled account would be a downgrade attack with a checkbox.
// ===========================================================================

/** optional | required_super | required_all */
function mfa_policy(): string
{
    $policy = (string) setting('sec_2fa_policy', 'optional');

    return in_array($policy, ['optional', 'required_super', 'required_all'], true) ? $policy : 'optional';
}

/** May customers protect their account with a second factor at all? */
function mfa_customers_enabled(): bool
{
    return setting_bool('sec_2fa_customers', true);
}

/** Is the emailed six-digit fallback offered to customers? */
function mfa_email_otp_enabled(): bool
{
    return setting_bool('sec_2fa_email_otp', true);
}

/** May a browser be trusted for a while after passing the second factor? */
function mfa_trust_enabled(): bool
{
    return setting_bool('sec_2fa_trust_enabled', true) && mfa_trust_days() > 0;
}

function mfa_trust_days(): int
{
    return max(0, min(365, setting_int('sec_2fa_trust_days', 30)));
}

/** Does this role carry its own "2FA required" flag? */
function mfa_role_requires(int $roleId): bool
{
    if ($roleId <= 0) {
        return false;
    }

    try {
        return (int) Database::fetchColumn(
            'SELECT `require_2fa` FROM `admin_roles` WHERE `id` = :id',
            ['id' => $roleId]
        ) === 1;
    } catch (Throwable $e) {
        // Column missing (migration not run): requiring nothing is the safe
        // reading, because the alternative locks the panel.
        return false;
    }
}

/**
 * Must this account use a second factor?
 *
 * Deliberately says "no" when secrets cannot be stored at all. Without a
 * readable application key the enrolment screen cannot save a secret, so
 * insisting on enrolment would mean nobody can ever sign in again - the one
 * failure mode that cannot be recovered from through the browser. It is
 * recorded as critical instead, and enrolled accounts are still challenged.
 */
function mfa_required(string $userType, array $row): bool
{
    if ($userType !== 'admin') {
        return false;   // customers are never forced; theirs is opt-in
    }

    $policy = mfa_policy();
    $isSuper = in_array('*', array_values(array_filter(
        json_decode_safe((string) ($row['role_permissions'] ?? '[]'), []),
        'is_string'
    )), true);

    $required = $policy === 'required_all'
        || ($policy === 'required_super' && $isSuper)
        || mfa_role_requires((int) ($row['role_id'] ?? 0));

    if ($required && !app_key_available()) {
        security_event('mfa.requirement_suspended', 'critical', [
            'reason' => 'no application key, so a new secret cannot be stored',
        ], (int) ($row['id'] ?? 0), 'admin');
        return false;
    }

    return $required;
}

/**
 * An admins row with its role permissions, which mfa_required() needs.
 * admin_user() already returns one; attempt_admin_login() does not.
 */
function mfa_admin_row(int $adminId): ?array
{
    return Database::fetch(
        'SELECT a.*, r.`slug` AS role_slug, r.`name` AS role_name, r.`permissions` AS role_permissions,
                r.`require_2fa` AS role_require_2fa
           FROM `admins` a
           INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
          WHERE a.`id` = :id LIMIT 1',
        ['id' => $adminId]
    );
}

// ===========================================================================
//  ENROLMENT STATE
// ===========================================================================

function mfa_table(string $userType): string
{
    return $userType === 'admin' ? 'admins' : 'users';
}

/** Has this account got an authenticator app set up? */
function mfa_totp_enabled(array $row): bool
{
    return ($row['totp_enabled_at'] ?? null) !== null
        && (string) ($row['totp_secret'] ?? '') !== '';
}

/**
 * Which second factors this account can actually use, strongest first.
 * [] means the account has no second factor at all.
 */
function mfa_methods(string $userType, array $row): array
{
    $methods = [];

    if (mfa_totp_enabled($row)) {
        $methods[] = 'totp';
        if (mfa_backup_remaining($userType, (int) $row['id']) > 0) {
            $methods[] = 'backup';
        }
    }

    // The emailed code is a customer fallback only. Admins do not get it: the
    // admin mailbox is very often the same inbox the password reset goes to,
    // which would make "two factors" one factor wearing a hat.
    if ($userType === 'customer'
        && mfa_customers_enabled()
        && mfa_email_otp_enabled()
        && (string) ($row['mfa_method'] ?? 'off') !== 'off') {
        $methods[] = 'email';
    }

    return $methods;
}

/** Is a second factor active on this account? */
function mfa_active(string $userType, array $row): bool
{
    return mfa_methods($userType, $row) !== [];
}

/** The stored secret, decrypted, or '' when there is none or it is unreadable. */
function mfa_secret(array $row): string
{
    $stored = (string) ($row['totp_secret'] ?? '');
    if ($stored === '') {
        return '';
    }

    $secret = secret_decrypt($stored);
    if ($secret === '' || !totp_secret_valid($secret)) {
        security_event('mfa.secret_unreadable', 'critical', [
            'reason' => 'the stored secret could not be decrypted with the current application key',
        ], isset($row['id']) ? (int) $row['id'] : null);
        return '';
    }

    return $secret;
}

/** The label an authenticator app shows for this account. */
function mfa_issuer(): string
{
    $name = trim((string) setting('store_name', SITE_NAME));
    return $name !== '' ? $name : 'ShopInnKart';
}

// ===========================================================================
//  ENROLMENT
//
//  A secret is generated, held in the SESSION only, and written to the account
//  the moment the person proves they can read a code from it. Writing it first
//  and verifying later is how accounts end up "protected" by a secret that
//  never made it into anybody's phone.
// ===========================================================================

function mfa_enrol_key(string $userType): string
{
    return '_mfa_enrol_' . ($userType === 'admin' ? 'a' : 'c');
}

/**
 * Start (or resume) an enrolment and return the secret to display.
 *
 * Resuming matters: the screen is re-rendered on every wrong code, and a new
 * secret each time would mean the QR the person just scanned is already stale.
 */
function mfa_enrol_begin(string $userType, int $accountId): string
{
    $key     = mfa_enrol_key($userType);
    $pending = $_SESSION[$key] ?? null;

    if (is_array($pending)
        && (int) ($pending['id'] ?? 0) === $accountId
        && totp_secret_valid((string) ($pending['secret'] ?? ''))
        && (int) ($pending['started'] ?? 0) > time() - 1800) {
        return (string) $pending['secret'];
    }

    $secret = totp_secret_new();
    $_SESSION[$key] = ['id' => $accountId, 'secret' => $secret, 'started' => time()];

    return $secret;
}

function mfa_enrol_secret(string $userType, int $accountId): ?string
{
    $pending = $_SESSION[mfa_enrol_key($userType)] ?? null;

    return is_array($pending)
        && (int) ($pending['id'] ?? 0) === $accountId
        && totp_secret_valid((string) ($pending['secret'] ?? ''))
        ? (string) $pending['secret']
        : null;
}

function mfa_enrol_forget(string $userType): void
{
    unset($_SESSION[mfa_enrol_key($userType)]);
}

/**
 * Finish enrolment: the code has to come from the secret we just offered.
 *
 * @return array{ok:bool, error:?string, codes:string[]}  codes are shown ONCE
 */
function mfa_enrol_complete(string $userType, array $row, string $code): array
{
    $accountId = (int) $row['id'];
    $secret    = mfa_enrol_secret($userType, $accountId);

    if ($secret === null) {
        return ['ok' => false, 'error' => 'That setup has expired. Start again with a fresh QR code.', 'codes' => []];
    }

    // The enrolment box is as guessable as the sign-in box, so it is counted
    // the same way. Per account, not per session: dropping the cookie must not
    // hand out a fresh budget.
    if (!rate_limit_attempt('mfa.enrol.' . $userType, (string) $accountId, 10, 900)) {
        security_event('mfa.enrol_throttled', 'medium', ['user_type' => $userType], $accountId, $userType);
        return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.', 'codes' => []];
    }

    if (totp_verify($secret, $code) === null) {
        security_event('mfa.enrol_failed', 'low', ['user_type' => $userType], $accountId, $userType);
        return ['ok' => false, 'error' => 'That code did not match. Check your phone\'s clock and try the next one.', 'codes' => []];
    }

    $stored = secret_encrypt($secret);
    if ($stored === '') {
        // secret_encrypt() fails closed when there is no application key. It
        // must not be papered over: storing the secret in the clear is worse
        // than refusing, and storing '' would leave a half-enrolled account.
        security_event('mfa.enrol_failed', 'critical', [
            'user_type' => $userType,
            'reason'    => 'no application key, so the secret cannot be stored safely',
        ], $accountId, $userType);
        return [
            'ok'    => false,
            'error' => 'Two-step sign-in cannot be switched on until the application key is readable. '
                . 'Ask whoever manages the server to check that config/ is writable.',
            'codes' => [],
        ];
    }

    $fields = [
        'totp_secret'     => $stored,
        'totp_enabled_at' => date('Y-m-d H:i:s'),
        'totp_last_step'  => totp_step(),
    ];
    if ($userType === 'customer') {
        $fields['mfa_method'] = 'totp';
    }

    Database::update(mfa_table($userType), $fields, '`id` = :id', ['id' => $accountId]);
    mfa_enrol_forget($userType);

    $codes = mfa_backup_generate($userType, $accountId);

    security_event('mfa.enabled', 'medium', ['user_type' => $userType, 'method' => 'totp'], $accountId, $userType);
    mfa_notify($userType, $row, 'Two-step sign-in was switched on');

    return ['ok' => true, 'error' => null, 'codes' => $codes];
}

/**
 * Take the second factor off an account.
 *
 * Everything goes at once - secret, backup codes and trusted devices - because
 * "2FA is off but these four browsers are still trusted" is a state nobody
 * would expect and an attacker would enjoy.
 */
function mfa_disable(string $userType, array $row, string $how = 'self'): void
{
    $accountId = (int) $row['id'];

    $fields = ['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => 0];
    if ($userType === 'customer') {
        $fields['mfa_method'] = 'off';
    }

    Database::update(mfa_table($userType), $fields, '`id` = :id', ['id' => $accountId]);
    Database::delete('auth_backup_codes', '`user_type` = :t AND `user_id` = :u', ['t' => $userType, 'u' => $accountId]);
    mfa_devices_revoke_all($userType, $accountId);
    mfa_enrol_forget($userType);

    security_event('mfa.disabled', 'high', ['user_type' => $userType, 'how' => $how], $accountId, $userType);
    mfa_notify($userType, $row, $how === 'admin'
        ? 'Two-step sign-in was reset by another administrator'
        : 'Two-step sign-in was switched off');
}

/**
 * Turn on the emailed code as a customer's only second factor.
 * No shared secret to store, so no key requirement and no QR.
 */
function mfa_email_enable(array $user): bool
{
    if (!mfa_customers_enabled() || !mfa_email_otp_enabled()) {
        return false;
    }

    Database::update('users', ['mfa_method' => 'email'], '`id` = :id', ['id' => (int) $user['id']]);
    security_event('mfa.enabled', 'medium', ['user_type' => 'customer', 'method' => 'email'], (int) $user['id'], 'customer');
    mfa_notify('customer', $user, 'Two-step sign-in by email was switched on');

    return true;
}

// ===========================================================================
//  BACKUP CODES
//
//  Ten codes, shown once, never again. Stored as a keyed SHA-256 rather than
//  password_hash(): a backup code is 50 bits of random_bytes(), not a chosen
//  password, so there is nothing for a slow hash to defend against - and ten
//  argon2id verifications per sign-in attempt would be a denial-of-service
//  lever on the one screen an attacker can reach without credentials. The key
//  comes from the application key, so a leaked table is not a list of codes.
// ===========================================================================

/** Confusable characters left out on purpose: no O/0, I/1, L, S/5, U/V. */
const MFA_BACKUP_ALPHABET = '23456789ABCDEFGHJKMNPQRTWXYZ';

function mfa_backup_hash(string $code): string
{
    return hash_hmac('sha256', mfa_backup_normalise($code), app_key_derive('mfa-backup-codes'));
}

/** What the person types is matched however they space or case it. */
function mfa_backup_normalise(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function mfa_backup_format(string $code): string
{
    return substr($code, 0, 5) . '-' . substr($code, 5);
}

/**
 * Issue a fresh set of ten and invalidate every older one.
 *
 * @return string[] the plain codes, which exist only in this return value
 */
function mfa_backup_generate(string $userType, int $accountId): array
{
    Database::delete('auth_backup_codes', '`user_type` = :t AND `user_id` = :u', ['t' => $userType, 'u' => $accountId]);

    $codes = [];
    $max   = strlen(MFA_BACKUP_ALPHABET) - 1;

    for ($i = 0; $i < 10; $i++) {
        $code = '';
        for ($c = 0; $c < 10; $c++) {
            $code .= MFA_BACKUP_ALPHABET[random_int(0, $max)];
        }
        $codes[] = $code;

        Database::insert('auth_backup_codes', [
            'user_type' => $userType,
            'user_id'   => $accountId,
            'code_hash' => mfa_backup_hash($code),
        ]);
    }

    security_event('mfa.backup_codes_issued', 'medium', ['user_type' => $userType, 'count' => count($codes)],
        $accountId, $userType);

    return array_map('mfa_backup_format', $codes);
}

function mfa_backup_remaining(string $userType, int $accountId): int
{
    try {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `auth_backup_codes`
              WHERE `user_type` = :t AND `user_id` = :u AND `used_at` IS NULL',
            ['t' => $userType, 'u' => $accountId]
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Spend one backup code.
 *
 * Claimed in a single conditional UPDATE, so two windows submitting the same
 * code cannot both be let in - the same reason claim_password_reset() exists.
 */
function mfa_backup_consume(string $userType, int $accountId, string $code): bool
{
    $normalised = mfa_backup_normalise($code);
    if (strlen($normalised) !== 10) {
        return false;
    }

    $statement = Database::query(
        'UPDATE `auth_backup_codes` SET `used_at` = NOW(), `used_ip` = :ip
          WHERE `user_type` = :t AND `user_id` = :u AND `code_hash` = :h AND `used_at` IS NULL
          LIMIT 1',
        ['ip' => client_ip(), 't' => $userType, 'u' => $accountId, 'h' => mfa_backup_hash($normalised)]
    );

    if ($statement->rowCount() !== 1) {
        return false;
    }

    $left = mfa_backup_remaining($userType, $accountId);

    // A spent backup code is a signal, not a routine event: either the phone
    // is gone or somebody else is using the codes.
    security_event('mfa.backup_code_used', $left === 0 ? 'high' : 'medium',
        ['user_type' => $userType, 'remaining' => $left], $accountId, $userType);

    return true;
}

// ===========================================================================
//  EMAILED ONE-TIME CODES  (customers)
//
//  Mail on this installation is QUEUED, not sent - the store's SMTP host is
//  not configured here - so everything below is asserted against
//  notification_queue. The code itself is never written to the queue row in
//  the clear beyond the message body the customer is meant to read, and only
//  its hash is stored.
// ===========================================================================

function mfa_otp_hash(string $code, int $otpId): string
{
    // The row id is mixed in so two accounts holding the same six digits do
    // not share a hash, and a stolen hash cannot be replayed against another row.
    return hash_hmac('sha256', $otpId . '|' . preg_replace('/\D/', '', $code), app_key_derive('mfa-email-otp'));
}

/**
 * Send a fresh six-digit code, subject to a cooldown and hourly ceilings.
 *
 * @return array{ok:bool, error:?string, wait:int}
 */
function mfa_otp_send(string $userType, array $row, string $purpose = 'login_2fa'): array
{
    $accountId = (int) $row['id'];
    $email     = (string) ($row['email'] ?? '');

    if ($email === '') {
        return ['ok' => false, 'error' => 'There is no email address on this account.', 'wait' => 0];
    }

    // One a minute, five an hour per account, and a ceiling per address so the
    // send button cannot be used to post mail at somebody.
    if (!rate_limit_attempt('mfa.otp.cooldown', $userType . '|' . $accountId, 1, MFA_OTP_COOLDOWN)) {
        return ['ok' => false, 'error' => 'A code was just sent. Please wait a moment before asking for another.',
            'wait' => rate_limit_retry_after(MFA_OTP_COOLDOWN)];
    }
    if (!rate_limit_attempt('mfa.otp.account', $userType . '|' . $accountId, 5, 3600)
        || !rate_limit_attempt('mfa.otp.ip', client_ip(), 15, 3600)) {
        security_event('mfa.otp_throttled', 'medium', ['user_type' => $userType, 'purpose' => $purpose],
            $accountId, $userType);
        return ['ok' => false, 'error' => 'Too many codes requested. Please try again later.', 'wait' => 900];
    }

    // A new code voids the previous one, or "resend" would leave several live
    // at once and multiply the guessing budget.
    Database::query(
        'UPDATE `auth_otps` SET `consumed_at` = NOW()
          WHERE `user_type` = :t AND `user_id` = :u AND `purpose` = :p AND `consumed_at` IS NULL',
        ['t' => $userType, 'u' => $accountId, 'p' => $purpose]
    );

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $otpId = Database::insert('auth_otps', [
        'user_type'  => $userType,
        'user_id'    => $accountId,
        'purpose'    => $purpose,
        'channel'    => 'email',
        'code_hash'  => '',
        'expires_at' => date('Y-m-d H:i:s', time() + MFA_OTP_TTL),
        'ip_address' => client_ip(),
    ]);

    // The hash binds the code to this row, so it can only be written once the
    // row has an id.
    Database::update('auth_otps', ['code_hash' => mfa_otp_hash($code, (int) $otpId)], '`id` = :id', ['id' => (int) $otpId]);

    $name = trim((string) ($row['first_name'] ?? $row['name'] ?? ''));

    notify('login_otp', $email, [
        'customer_name'  => $name !== '' ? $name : 'there',
        'customer_email' => $email,
        'otp_code'       => $code,
        'minutes'        => (string) (MFA_OTP_TTL / 60),
        'request_ip'     => client_ip(),
        'when'           => format_datetime(date('Y-m-d H:i:s')),
    ], 'user', $accountId, 'email', [
        'email_type'     => 'login_otp',
        'recipient_name' => $name,
        'recipient_type' => $userType === 'admin' ? 'admin' : 'customer',
        // Account-security mail ignores the customer's notification preferences
        // and is never deduplicated: two sign-ins are two codes.
        'force'          => true,
    ]);

    security_event('mfa.otp_sent', 'info', ['user_type' => $userType, 'purpose' => $purpose], $accountId, $userType);

    return ['ok' => true, 'error' => null, 'wait' => MFA_OTP_COOLDOWN];
}

/**
 * Check a submitted emailed code.
 *
 * The attempt counter lives on the row, so it survives a new session, a new
 * cookie and a parallel window.
 */
function mfa_otp_verify(string $userType, int $accountId, string $code, string $purpose = 'login_2fa'): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }

    $row = Database::fetch(
        'SELECT * FROM `auth_otps`
          WHERE `user_type` = :t AND `user_id` = :u AND `purpose` = :p AND `consumed_at` IS NULL
          ORDER BY `id` DESC LIMIT 1',
        ['t' => $userType, 'u' => $accountId, 'p' => $purpose]
    );

    if ($row === null) {
        return false;
    }

    if (strtotime((string) $row['expires_at']) <= time() || (int) $row['attempts'] >= MFA_OTP_ATTEMPTS) {
        Database::update('auth_otps', ['consumed_at' => date('Y-m-d H:i:s')], '`id` = :id', ['id' => (int) $row['id']]);
        return false;
    }

    // Counted before the comparison, so a crash between the two cannot hand
    // out a free guess.
    Database::query('UPDATE `auth_otps` SET `attempts` = `attempts` + 1 WHERE `id` = :id', ['id' => (int) $row['id']]);

    if (!hash_equals((string) $row['code_hash'], mfa_otp_hash($code, (int) $row['id']))) {
        return false;
    }

    // Claimed in one conditional UPDATE: two windows cannot both spend it.
    $claimed = Database::query(
        'UPDATE `auth_otps` SET `consumed_at` = NOW() WHERE `id` = :id AND `consumed_at` IS NULL',
        ['id' => (int) $row['id']]
    );

    return $claimed->rowCount() === 1;
}

// ===========================================================================
//  TRUSTED DEVICES
//
//  After the second factor has been proved on a browser, that browser may be
//  trusted for sec_2fa_trust_days. Same construction as "keep me signed in":
//  selector:validator in the cookie, only the hash in the table, bound to the
//  account's auth_version so a password change drops every trust at once.
//
//  This is what "the second factor survives remember me" means in practice: a
//  remembered browser still has to prove the factor ONCE, and only then does
//  the remember-me cookie start signing in silently again.
// ===========================================================================

function mfa_device_cookie(string $userType): string
{
    return $userType === 'admin' ? MFA_DEVICE_COOKIE_ADMIN : MFA_DEVICE_COOKIE_CUSTOMER;
}

function mfa_device_cookie_params(string $userType, int $expires): array
{
    $base = BASE_PATH === '' ? '' : BASE_PATH;

    return [
        'expires'  => $expires,
        // The admin token is scoped to /admin/ so the storefront never carries
        // it - the same reasoning as the hidden-login-address cookie.
        'path'     => $userType === 'admin' ? $base . '/admin/' : ($base === '' ? '/' : $base . '/'),
        'domain'   => '',
        'secure'   => session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** A short, honest description of the browser, for the "your devices" list. */
function mfa_device_label(): string
{
    $ua = user_agent();

    $browser = 'Browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Safari' => 'Safari', 'Firefox' => 'Firefox'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $browser = $name;
            break;
        }
    }

    $platform = 'device';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
              'Mac OS X' => 'Mac', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $platform = $name;
            break;
        }
    }

    return mb_substr($browser . ' on ' . $platform, 0, 100);
}

/** Remember that this browser has proved the second factor. */
function mfa_device_trust(string $userType, int $accountId): void
{
    if (!mfa_trust_enabled() || $accountId <= 0) {
        return;
    }

    try {
        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + mfa_trust_days() * 86400;

        Database::insert('auth_trusted_devices', [
            'user_type'      => $userType,
            'user_id'        => $accountId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'auth_version'   => mfa_account_version($userType, $accountId),
            'label'          => mfa_device_label(),
            'ip_address'     => client_ip(),
            'user_agent'     => user_agent(),
            'expires_at'     => date('Y-m-d H:i:s', $expires),
            'last_used_at'   => date('Y-m-d H:i:s'),
        ]);

        $cookie = $selector . ':' . $validator;
        if (!headers_sent()) {
            setcookie(mfa_device_cookie($userType), $cookie, mfa_device_cookie_params($userType, $expires));
        }
        $_COOKIE[mfa_device_cookie($userType)] = $cookie;

        security_event('mfa.device_trusted', 'medium',
            ['user_type' => $userType, 'days' => mfa_trust_days()], $accountId, $userType);
    } catch (Throwable $e) {
        // A device that could not be remembered simply asks for a code again.
        ErrorHandler::log('warning', 'Trusted device not recorded: ' . $e->getMessage());
    }
}

/** The account's current session generation, read fresh. */
function mfa_account_version(string $userType, int $accountId): int
{
    try {
        return max(1, (int) Database::fetchColumn(
            'SELECT `auth_version` FROM `' . mfa_table($userType) . '` WHERE `id` = :id',
            ['id' => $accountId]
        ));
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * When the account row itself was created, as a timestamp - or null if it is
 * not there any more. Used to tell a trust apart from an inherited one.
 */
function mfa_account_born(string $userType, int $accountId): ?int
{
    try {
        $born = Database::fetchColumn(
            'SELECT `created_at` FROM `' . mfa_table($userType) . '` WHERE `id` = :id',
            ['id' => $accountId]
        );

        return $born === null || $born === false ? null : (int) strtotime((string) $born);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Has this browser already proved the second factor for this account, recently
 * enough to be taken at its word? Stamps last_used_at when it has.
 */
function mfa_device_trusted(string $userType, int $accountId): bool
{
    if (!mfa_trust_enabled()) {
        return false;
    }

    $cookie = (string) ($_COOKIE[mfa_device_cookie($userType)] ?? '');
    if ($cookie === '' || substr_count($cookie, ':') !== 1) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (preg_match('/^[a-f0-9]{18}$/', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/', $validator) !== 1) {
        return false;
    }

    try {
        $row = Database::fetch(
            'SELECT * FROM `auth_trusted_devices` WHERE `selector` = :s LIMIT 1',
            ['s' => $selector]
        );

        if ($row === null || (int) $row['user_id'] !== $accountId || (string) $row['user_type'] !== $userType) {
            return false;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Right selector, wrong secret: the cookie was copied. Every trust
            // on the account goes, exactly as a remember-me reuse does.
            mfa_devices_revoke_all($userType, $accountId);
            security_event('mfa.device_token_reuse', 'high', ['user_type' => $userType], $accountId, $userType);
            return false;
        }

        if ($row['revoked_at'] !== null
            || strtotime((string) $row['expires_at']) <= time()
            || (int) $row['auth_version'] !== mfa_account_version($userType, $accountId)) {
            return false;
        }

        // A trust is always recorded by signing in, so it can never predate the
        // account it belongs to. One that does is an inherited row: the account
        // it was written for was deleted and MariaDB handed its id to somebody
        // new when it recomputed AUTO_INCREMENT as MAX(id)+1 at restart. Taking
        // it at its word would let the old browser skip the new owner's second
        // factor. mfa_purge() sweeps these away; this refuses them meanwhile.
        $born = mfa_account_born($userType, $accountId);
        if ($born === null || strtotime((string) $row['created_at']) < $born) {
            security_event('mfa.device_orphaned', 'high', [
                'user_type' => $userType,
                'reason'    => 'the trusted browser predates the account it names',
            ], $accountId, $userType);
            return false;
        }

        Database::update('auth_trusted_devices', ['last_used_at' => date('Y-m-d H:i:s')],
            '`id` = :id', ['id' => (int) $row['id']]);

        return true;
    } catch (Throwable $e) {
        // A trust that cannot be read is a trust that is not granted.
        ErrorHandler::log('warning', 'Trusted-device check failed: ' . $e->getMessage());
        return false;
    }
}

/** The live trusted browsers on an account, newest first. */
function mfa_devices(string $userType, int $accountId): array
{
    try {
        return Database::fetchAll(
            'SELECT * FROM `auth_trusted_devices`
              WHERE `user_type` = :t AND `user_id` = :u AND `revoked_at` IS NULL AND `expires_at` > NOW()
              ORDER BY `last_used_at` DESC, `id` DESC',
            ['t' => $userType, 'u' => $accountId]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Is a given row the browser making this request? */
function mfa_device_is_current(string $userType, array $device): bool
{
    $cookie = (string) ($_COOKIE[mfa_device_cookie($userType)] ?? '');
    return $cookie !== '' && strncmp($cookie, (string) $device['selector'] . ':', 19) === 0;
}

function mfa_device_revoke(string $userType, int $accountId, int $deviceId): bool
{
    $statement = Database::query(
        'UPDATE `auth_trusted_devices` SET `revoked_at` = NOW()
          WHERE `id` = :id AND `user_type` = :t AND `user_id` = :u AND `revoked_at` IS NULL',
        ['id' => $deviceId, 't' => $userType, 'u' => $accountId]
    );

    if ($statement->rowCount() === 1) {
        security_event('mfa.device_revoked', 'medium', ['user_type' => $userType], $accountId, $userType);
        return true;
    }

    return false;
}

function mfa_devices_revoke_all(string $userType, int $accountId): void
{
    // Whether THIS browser's cookie is one of the ones being revoked. It often
    // is not: an admin resetting a customer's password revokes that customer's
    // devices, and dropping the admin's own cookie on the way past would log a
    // different account out of its trust for no reason.
    $mine = false;
    $cookie = (string) ($_COOKIE[mfa_device_cookie($userType)] ?? '');

    try {
        if ($cookie !== '' && strpos($cookie, ':') !== false) {
            [$selector] = explode(':', $cookie, 2);
            $mine = Database::exists(
                'auth_trusted_devices',
                '`selector` = :s AND `user_type` = :t AND `user_id` = :u',
                ['s' => $selector, 't' => $userType, 'u' => $accountId]
            );
        }

        Database::query(
            'UPDATE `auth_trusted_devices` SET `revoked_at` = NOW()
              WHERE `user_type` = :t AND `user_id` = :u AND `revoked_at` IS NULL',
            ['t' => $userType, 'u' => $accountId]
        );
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Trusted devices not revoked: ' . $e->getMessage());
    }

    if ($mine) {
        mfa_device_forget_cookie($userType);
    }
}

function mfa_device_forget_cookie(string $userType): void
{
    unset($_COOKIE[mfa_device_cookie($userType)]);
    if (!headers_sent()) {
        setcookie(mfa_device_cookie($userType), '', mfa_device_cookie_params($userType, time() - 86400));
    }
}

// ===========================================================================
//  THE SIGN-IN GATE
// ===========================================================================

/**
 * Decide what happens after a correct password.
 *
 * @param array $options remember: the customer ticked "keep me signed in"
 * @return array{stage:string, method:string, url:string}
 *         stage 'complete'  - sign them in now, passing method to login_*()
 *               'challenge' - a pending challenge is set; send them to url
 *               'enrol'     - same, but they must set 2FA up first
 */
function mfa_sign_in(string $userType, array $row, array $options = []): array
{
    $accountId = (int) $row['id'];
    $methods   = mfa_methods($userType, $row);
    $required  = mfa_required($userType, $row);
    $url       = $userType === 'admin' ? admin_url('login-2fa.php') : url('login-2fa.php');

    // Housekeeping rides along here; see mfa_purge_maybe() for why.
    mfa_purge_maybe();

    if ($methods === []) {
        if (!$required) {
            return ['stage' => 'complete', 'method' => 'none', 'url' => ''];
        }

        // Required but not enrolled: they get the setup screen and nothing
        // else. This is the branch that must never be a dead end, which is why
        // it is reachable straight from the sign-in form and why the setup
        // screen shows the secret as text as well as a QR code.
        mfa_pending_set($userType, $row, 'enrol', ['setup'], $options);
        security_event('mfa.enrolment_forced', 'medium', ['user_type' => $userType], $accountId, $userType);

        return ['stage' => 'enrol', 'method' => '', 'url' => $url];
    }

    // A browser that proved the factor within the trust window is taken at its
    // word - that is the whole point of "trust this device for 30 days".
    if (mfa_device_trusted($userType, $accountId)) {
        security_event('mfa.passed', 'info', ['user_type' => $userType, 'method' => 'device'], $accountId, $userType);
        return ['stage' => 'complete', 'method' => 'device', 'url' => ''];
    }

    mfa_pending_set($userType, $row, 'verify', $methods, $options);

    return ['stage' => 'challenge', 'method' => '', 'url' => $url];
}

/**
 * Park the account in a half-authenticated state.
 *
 * The session id is rotated first: the browser that arrives at the 2FA screen
 * must not be holding the id it had before the password was accepted.
 */
function mfa_pending_set(string $userType, array $row, string $stage, array $methods, array $options = []): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();
    }

    $_SESSION['_mfa_pending'] = [
        'type'     => $userType,
        'id'       => (int) $row['id'],
        'version'  => auth_row_version($row),
        'stage'    => $stage,
        'methods'  => array_values($methods),
        'remember' => !empty($options['remember']),
        'expires'  => time() + MFA_PENDING_TTL,
        'tries'    => 0,
        'started'  => time(),
    ];
}

/** The live pending challenge, or null. Expired ones are dropped here. */
function mfa_pending(?string $userType = null): ?array
{
    $pending = $_SESSION['_mfa_pending'] ?? null;
    if (!is_array($pending) || !isset($pending['type'], $pending['id'], $pending['expires'])) {
        return null;
    }

    if ((int) $pending['expires'] <= time()) {
        mfa_pending_clear();
        return null;
    }

    if ($userType !== null && $pending['type'] !== $userType) {
        return null;
    }

    return $pending;
}

function mfa_pending_clear(): void
{
    unset($_SESSION['_mfa_pending']);
}

/** The account row a pending challenge names, re-read and re-checked. */
function mfa_pending_account(array $pending): ?array
{
    $userType = (string) $pending['type'];

    $row = $userType === 'admin'
        ? mfa_admin_row((int) $pending['id'])
        : Database::fetch('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => (int) $pending['id']]);

    if ($row === null || (string) $row['status'] !== 'active') {
        return null;
    }

    // The password changed, or somebody signed out everywhere, between the two
    // halves of this sign-in. The half-finished attempt does not survive it.
    if (auth_row_version($row) !== (int) $pending['version']) {
        return null;
    }

    return $row;
}

/**
 * Check a submitted second factor against the pending challenge.
 *
 * @return array{ok:bool, method:string, error:?string, cancelled:bool}
 */
function mfa_challenge_verify(array $pending, string $method, string $code): array
{
    $userType  = (string) $pending['type'];
    $accountId = (int) $pending['id'];
    $fail      = static fn (string $message, bool $cancelled = false): array
        => ['ok' => false, 'method' => '', 'error' => $message, 'cancelled' => $cancelled];

    $row = mfa_pending_account($pending);
    if ($row === null) {
        mfa_pending_clear();
        return $fail('That sign-in is no longer valid. Please start again.', true);
    }

    // Two brakes, for two different attacks. The per-account counter survives
    // cookies, so cancelling and re-entering the password does not refill the
    // guessing budget; the per-challenge counter ends this attempt after five.
    if (!rate_limit_attempt('mfa.verify.' . $userType, (string) $accountId, 25, 900)
        || !rate_limit_attempt('mfa.verify.ip', client_ip(), 40, 900)) {
        mfa_pending_clear();
        security_event('mfa.verify_throttled', 'high', ['user_type' => $userType], $accountId, $userType);
        return $fail('Too many attempts. Please wait a few minutes and sign in again.', true);
    }

    $ok = false;
    $used = '';

    if ($method === 'backup') {
        $ok   = mfa_backup_consume($userType, $accountId, $code);
        $used = 'backup';
    } elseif ($method === 'email') {
        $ok   = in_array('email', (array) $pending['methods'], true)
            && mfa_otp_verify($userType, $accountId, $code);
        $used = 'email';
    } else {
        $secret = mfa_secret($row);
        $step   = $secret === '' ? null : totp_verify($secret, $code, 1, (int) ($row['totp_last_step'] ?? 0));
        if ($step !== null) {
            // Replay guard: this code, and every older one, is spent now.
            //
            // CLAIMED in one conditional UPDATE, for the same reason
            // mfa_backup_consume() and mfa_otp_verify() are. Reading
            // totp_last_step and then writing it is two statements, and two
            // windows submitting the same six digits in the same instant both
            // found it unspent - measured at three of ten concurrent
            // submissions getting in. The WHERE clause IS the claim: only the
            // request that actually moves the step forward is let through.
            $claimed = Database::query(
                'UPDATE `' . mfa_table($userType) . '` SET `totp_last_step` = :step
                  WHERE `id` = :id AND `totp_last_step` < :floor',
                ['step' => $step, 'id' => $accountId, 'floor' => $step]
            );
            $ok = $claimed->rowCount() === 1;
        }
        $used = 'totp';
    }

    if ($ok) {
        rate_limit_clear('mfa.verify.' . $userType, (string) $accountId);
        security_event('mfa.passed', 'info', ['user_type' => $userType, 'method' => $used], $accountId, $userType);
        return ['ok' => true, 'method' => $used, 'error' => null, 'cancelled' => false];
    }

    $tries = (int) ($pending['tries'] ?? 0) + 1;
    $_SESSION['_mfa_pending']['tries'] = $tries;

    security_event('mfa.failed', $tries >= MFA_PENDING_TRIES ? 'high' : 'low',
        ['user_type' => $userType, 'method' => $used, 'tries' => $tries], $accountId, $userType);
    log_login($userType, $accountId, (string) ($row['email'] ?? ''), false, 'mfa_' . $used . '_failed');

    if ($tries >= MFA_PENDING_TRIES) {
        mfa_pending_clear();
        // The per-IP login ceiling is told about it too, so somebody grinding
        // at the second step pays the same price as somebody grinding at the
        // first - otherwise the 2FA screen would be the cheap way in.
        auth_throttle_fail($userType, (string) ($row['email'] ?? ''), $row);
        return $fail('Too many incorrect codes. Please sign in again.', true);
    }

    // One wording for every kind of wrong code: which factor was wrong is not
    // a stranger's business, and "that backup code is already used" would
    // confirm which codes exist.
    return $fail('That code is not right. ' . (MFA_PENDING_TRIES - $tries) . ' attempt'
        . (MFA_PENDING_TRIES - $tries === 1 ? '' : 's') . ' left.');
}

// ===========================================================================
//  WHAT THE SESSION CARRIES
// ===========================================================================

/**
 * What a signed-in session records about its second factor:
 * ['method' => 'totp'|'backup'|'email'|'device'|'none', 'at' => timestamp].
 */
function auth_session_mfa(string $userType): ?array
{
    $key     = $userType === 'admin' ? ADMIN_SESSION_KEY : USER_SESSION_KEY;
    $session = is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [];

    return is_array($session['mfa'] ?? null) ? $session['mfa'] : null;
}

/**
 * Did this session actually prove a second factor (rather than simply not
 * needing one)? Used by the account screens to say so, and available to any
 * future step-up check.
 */
function auth_mfa_satisfied(string $userType): bool
{
    $mfa = auth_session_mfa($userType);

    return $mfa !== null && in_array((string) ($mfa['method'] ?? ''), ['totp', 'backup', 'email', 'device'], true);
}

// ===========================================================================
//  NOTICES
// ===========================================================================

/** Tell the account holder that their second factor changed. */
function mfa_notify(string $userType, array $row, string $what): void
{
    $email = (string) ($row['email'] ?? '');
    if ($email === '') {
        return;
    }

    try {
        if ($userType === 'admin') {
            notify('security_admin_alert', $email, [
                'admin_name' => (string) ($row['name'] ?? 'there'),
                'change'     => $what,
                'actor_name' => (string) (admin_user()['name'] ?? 'You'),
                'event_time' => format_datetime(date('Y-m-d H:i:s'), 'd M Y, g:i A'),
                'ip_address' => client_ip(),
            ], 'admin', (int) $row['id'], 'email', [
                'recipient_type' => 'admin',
                'recipient_name' => (string) ($row['name'] ?? ''),
                'force'          => true,
            ]);
            return;
        }

        notify('security_account_changed', $email, [
            'customer_name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'change'        => $what,
            'actor_name'    => 'You',
            'event_time'    => format_datetime(date('Y-m-d H:i:s'), 'd M Y, g:i A'),
        ], 'user', (int) $row['id'], 'email', [
            'recipient_name' => trim((string) ($row['first_name'] ?? '')),
            'user_id'        => (int) $row['id'],
            'force'          => true,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', '2FA notice not queued: ' . $e->getMessage());
    }
}

// ===========================================================================
//  HOUSEKEEPING
// ===========================================================================

/**
 * Drop expired one-time codes, dead device trusts, and anything left behind by
 * an account that no longer exists. Cheap, called rarely - see mfa_purge_maybe().
 *
 * The orphan half matters for more than tidiness. These three tables name their
 * owner as a (user_type, user_id) PAIR, because one table serves both `users`
 * and `admins`; a foreign key cannot be polymorphic, so nothing cascades when
 * an account row goes, and neither delete screen clears them by hand. MariaDB
 * recomputes AUTO_INCREMENT as MAX(id)+1 when it restarts, so the id of a
 * deleted account can be handed to the next account created - which would then
 * inherit the deleted one's trusted browsers and backup codes. Sweeping the
 * orphans closes that window; mfa_device_trusted() refuses the inherited row
 * outright, so the two together cover it even between sweeps.
 */
function mfa_purge(): void
{
    try {
        Database::query('DELETE FROM `auth_otps` WHERE `expires_at` < (NOW() - INTERVAL 1 DAY) LIMIT 500');
        Database::query('DELETE FROM `auth_trusted_devices`
                          WHERE `expires_at` < (NOW() - INTERVAL 7 DAY)
                             OR (`revoked_at` IS NOT NULL AND `revoked_at` < (NOW() - INTERVAL 30 DAY)) LIMIT 500');

        // One indexed pass per table per owner kind: the NOT EXISTS is a
        // primary-key lookup, and idx_*_owner already covers the outer scan.
        foreach (['auth_trusted_devices', 'auth_backup_codes', 'auth_otps'] as $table) {
            foreach (['customer' => 'users', 'admin' => 'admins'] as $userType => $ownerTable) {
                Database::query(
                    'DELETE FROM `' . $table . '`
                      WHERE `user_type` = :t
                        AND NOT EXISTS (SELECT 1 FROM `' . $ownerTable . '` o WHERE o.`id` = `' . $table . '`.`user_id`)
                      LIMIT 500',
                    ['t' => $userType]
                );
            }
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'MFA purge failed: ' . $e->getMessage());
    }
}

/**
 * Run the sweep on roughly one sign-in in fifty.
 *
 * The store has no daemon and shared hosting gives us one cron slot that is
 * already spoken for, so housekeeping rides along on a request instead - the
 * same trick the rate limiter uses. A sign-in is the right host for it: it is
 * infrequent, it is already doing database work, and it is the only path that
 * adds rows to these tables in the first place.
 */
function mfa_purge_maybe(): void
{
    try {
        if (random_int(1, 50) === 1) {
            mfa_purge();
        }
    } catch (Throwable $e) {
        // Housekeeping never stands between somebody and their account.
        ErrorHandler::log('warning', 'MFA purge hook failed: ' . $e->getMessage());
    }
}
