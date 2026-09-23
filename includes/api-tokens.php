<?php
/**
 * ShopInnKart - Per-device API tokens.
 *
 * A mobile app cannot carry a PHP session cookie around for weeks, so it signs
 * in once per device and keeps a token. This is that token, built the way
 * remember_tokens already are:
 *
 *   sikt_<selector>_<secret>
 *
 * The selector finds the row; only a SHA-256 of the secret is stored, so a
 * dump of `api_tokens` hands an attacker nothing they can sign in with. Each
 * row is one device: it carries a name, a platform, an expiry, the address it
 * was issued to and when it was last used, and it can be revoked on its own or
 * with every other token on the account.
 *
 * This is ADDITIVE. The website's session flow is untouched: a token is only
 * ever looked at when the request carries an Authorization: Bearer header, and
 * the session it borrows for that request is discarded before the response
 * leaves - a bearer request never leaves a signed-in browser session behind.
 */

declare(strict_types=1);

const API_TOKEN_PREFIX = 'sikt_';

// ===========================================================================
//  Settings
// ===========================================================================

function api_tokens_enabled(): bool
{
    return setting_bool('sec_api_tokens_enabled', true);
}

/**
 * Admin accounts are off by default. A stolen customer token can place an
 * order; a stolen admin token can empty the catalogue, so the owner has to
 * switch that on deliberately.
 */
function api_tokens_admin_enabled(): bool
{
    return setting_bool('sec_api_tokens_admin', false);
}

function api_token_lifetime_days(): int
{
    return max(1, min(365, setting_int('sec_api_token_days', 30)));
}

function api_token_max_per_account(): int
{
    return max(1, min(100, setting_int('sec_api_token_max', 10)));
}

function api_token_platforms(): array
{
    return ['android' => 'Android', 'ios' => 'iOS', 'web' => 'Web app', 'other' => 'Other'];
}

// ===========================================================================
//  Issuing
// ===========================================================================

/**
 * Mint a token for one device.
 *
 * @return array{token:string, id:int, expires_at:string, device_name:string, platform:string}
 */
function api_token_issue(string $userType, int $userId, array $opts = []): array
{
    $userType = $userType === 'admin' ? 'admin' : 'customer';
    $selector = bin2hex(random_bytes(8));              // 16 chars, unique index
    $secret   = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $days    = max(1, min(365, (int) ($opts['days'] ?? api_token_lifetime_days())));
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);

    $account = api_token_account($userType, $userId);
    $version = $account === null ? 1 : (int) ($account['auth_version'] ?? 1);

    // One device, one token: signing in again on the same handset replaces the
    // old row instead of stacking up rows nobody will ever revoke.
    $deviceName = api_token_clean_device((string) ($opts['device_name'] ?? ''));
    $platform   = api_token_clean_platform((string) ($opts['platform'] ?? ''));

    $id = Database::insert('api_tokens', [
        'user_type'    => $userType,
        'user_id'      => $userId,
        'selector'     => $selector,
        'token_hash'   => hash('sha256', $secret),
        'auth_version' => $version,
        'device_name'  => $deviceName,
        'platform'     => $platform,
        'expires_at'   => $expires,
        'created_ip'   => client_ip(),
        'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    api_token_enforce_ceiling($userType, $userId, $id);
    api_token_prune();

    security_event('api.token_issued', 'info', [
        'device'   => $deviceName,
        'platform' => $platform,
        'expires'  => $expires,
    ], $userId, $userType);

    return [
        'token'       => API_TOKEN_PREFIX . $selector . '_' . $secret,
        'id'          => $id,
        'expires_at'  => $expires,
        'device_name' => $deviceName,
        'platform'    => $platform,
    ];
}

/**
 * Keep only the newest N tokens per account.
 *
 * Without it, an app that re-authenticates on every launch leaves a growing
 * list of live credentials the owner would have to revoke one by one.
 */
function api_token_enforce_ceiling(string $userType, int $userId, int $keepId): void
{
    $max = api_token_max_per_account();

    $stale = Database::fetchColumnAll(
        'SELECT `id` FROM `api_tokens`
          WHERE `user_type` = :t AND `user_id` = :u AND `revoked_at` IS NULL AND `id` <> :keep
          ORDER BY `id` DESC LIMIT 500 OFFSET ' . max(0, $max - 1),
        ['t' => $userType, 'u' => $userId, 'keep' => $keepId]
    );

    foreach ($stale as $id) {
        api_token_revoke((int) $id, 'device_limit');
    }
}

/** Housekeeping, on roughly one issue in twenty. Bounded so it never stalls. */
function api_token_prune(): void
{
    try {
        if (random_int(1, 20) !== 1) {
            return;
        }
        Database::query(
            'DELETE FROM `api_tokens`
              WHERE (`expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY))
                 OR (`revoked_at` IS NOT NULL AND `revoked_at` < DATE_SUB(NOW(), INTERVAL 90 DAY))
              LIMIT 200'
        );
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'api_token_prune failed: ' . $e->getMessage());
    }
}

function api_token_clean_device(string $name): string
{
    // Printed back in the admin device list, so it is stripped of anything that
    // is not a plain device label rather than trusted because it came from "our
    // app" - the request is a stranger's until proven otherwise.
    $name = trim(preg_replace('/[^\p{L}\p{N} .,_()\'-]+/u', ' ', $name) ?? '');
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    return $name === '' ? 'Mobile device' : mb_substr($name, 0, 100);
}

function api_token_clean_platform(string $platform): string
{
    $platform = strtolower(trim($platform));
    return isset(api_token_platforms()[$platform]) ? $platform : 'other';
}

// ===========================================================================
//  Authenticating
// ===========================================================================

/** The raw bearer credential this request carries, or ''. */
function api_token_bearer(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    // Apache CGI and LiteSpeed drop Authorization unless it is rescued; the
    // apache_request_headers() fallback is what makes this work on Hostinger.
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() ?: [] as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
        return $m[1];
    }

    // A second, header-safe name for hosts that strip Authorization entirely.
    $alt = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    return trim($alt);
}

/**
 * Verify this request's bearer token.
 *
 * Memoised: the answer cannot change within one request, and several guards
 * ask for it.
 *
 * @return array{token:array, account:array, user_type:string}|null
 */
function api_token_authenticate(): ?array
{
    static $result = false;
    if ($result !== false) {
        return $result;
    }

    $raw = api_token_bearer();
    if ($raw === '' || !api_tokens_enabled()) {
        return $result = null;
    }

    $parts = api_token_split($raw);
    if ($parts === null) {
        return $result = null;
    }
    [$selector, $secret] = $parts;

    try {
        $row = Database::fetch('SELECT * FROM `api_tokens` WHERE `selector` = :s LIMIT 1', ['s' => $selector]);
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'API token lookup failed: ' . $e->getMessage());
        return $result = null;
    }

    if ($row === null) {
        security_event('api.token_unknown', 'low', ['selector' => $selector]);
        return $result = null;
    }

    // The secret is checked before anything else is reported, so a stranger
    // holding only a selector learns nothing about the row behind it.
    if (!hash_equals((string) $row['token_hash'], hash('sha256', $secret))) {
        security_event('api.token_bad_secret', 'medium', [
            'selector' => $selector,
        ], (int) $row['user_id'], (string) $row['user_type']);
        return $result = null;
    }

    if ($row['revoked_at'] !== null) {
        // Somebody is still using a credential that was taken away. That is
        // either a stale app or a copy that outlived the revocation, and the
        // owner should be able to see which devices keep trying.
        security_event('api.token_reuse_after_revoke', 'high', [
            'token_id' => (int) $row['id'],
            'device'   => (string) $row['device_name'],
            'reason'   => (string) ($row['revoked_reason'] ?? ''),
        ], (int) $row['user_id'], (string) $row['user_type']);
        return $result = null;
    }

    if (strtotime((string) $row['expires_at']) <= time()) {
        security_event('api.token_expired', 'info', ['token_id' => (int) $row['id']],
            (int) $row['user_id'], (string) $row['user_type']);
        return $result = null;
    }

    $userType = (string) $row['user_type'] === 'admin' ? 'admin' : 'customer';

    if ($userType === 'admin' && !api_tokens_admin_enabled()) {
        security_event('api.token_admin_disabled', 'medium', ['token_id' => (int) $row['id']],
            (int) $row['user_id'], 'admin');
        return $result = null;
    }

    $account = api_token_account($userType, (int) $row['user_id']);
    if ($account === null) {
        api_token_revoke((int) $row['id'], 'account_gone');
        return $result = null;
    }

    // A password change, a reset or "sign out everywhere" moves auth_version
    // on. The website's sessions die with it; so must the app's tokens.
    if ((int) $row['auth_version'] !== (int) ($account['auth_version'] ?? 1)) {
        api_token_revoke((int) $row['id'], 'password_changed');
        security_event('api.token_stale_version', 'medium', ['token_id' => (int) $row['id']],
            (int) $row['user_id'], $userType);
        return $result = null;
    }

    api_token_touch($row);

    return $result = ['token' => $row, 'account' => $account, 'user_type' => $userType];
}

/** The verified token row for this request, or null. */
function api_token_current(): ?array
{
    $auth = api_token_authenticate();
    return $auth === null ? null : $auth['token'];
}

/** sikt_<selector>_<secret> -> [selector, secret], or null when malformed. */
function api_token_split(string $raw): ?array
{
    if (strncmp($raw, API_TOKEN_PREFIX, strlen(API_TOKEN_PREFIX)) !== 0) {
        return null;
    }
    $body = substr($raw, strlen(API_TOKEN_PREFIX));
    $at   = strpos($body, '_');
    if ($at === false) {
        return null;
    }

    $selector = substr($body, 0, $at);
    $secret   = substr($body, $at + 1);

    if (strlen($selector) !== 16 || ctype_xdigit($selector) === false || $secret === '') {
        return null;
    }
    return [$selector, $secret];
}

/** The live, usable account behind a token, or null. */
function api_token_account(string $userType, int $userId): ?array
{
    if ($userType === 'admin') {
        return Database::fetch(
            'SELECT a.*, r.`name` AS role_name, r.`slug` AS role_slug, r.`permissions` AS role_permissions
               FROM `admins` a
               INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
              WHERE a.`id` = :id AND a.`status` = :s AND r.`status` = :rs LIMIT 1',
            ['id' => $userId, 's' => 'active', 'rs' => 'active']
        );
    }

    return Database::fetch(
        'SELECT * FROM `users` WHERE `id` = :id AND `status` = :s LIMIT 1',
        ['id' => $userId, 's' => 'active']
    );
}

/** Record "last used", at most once a minute so a chatty app is not a write storm. */
function api_token_touch(array $row): void
{
    try {
        $last = $row['last_used_at'] === null ? 0 : (int) strtotime((string) $row['last_used_at']);
        if (time() - $last < 60) {
            return;
        }
        Database::update('api_tokens', [
            'last_used_at' => date('Y-m-d H:i:s'),
            'last_ip'      => client_ip(),
        ], '`id` = :id', ['id' => (int) $row['id']]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'api_token_touch failed: ' . $e->getMessage());
    }
}

// ===========================================================================
//  Revoking and listing
// ===========================================================================

function api_token_revoke(int $id, string $reason = 'revoked'): bool
{
    try {
        $changed = Database::update('api_tokens', [
            'revoked_at'     => date('Y-m-d H:i:s'),
            'revoked_reason' => mb_substr($reason, 0, 60),
        ], '`id` = :id AND `revoked_at` IS NULL', ['id' => $id]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'api_token_revoke failed: ' . $e->getMessage());
        return false;
    }

    if ($changed > 0) {
        security_event('api.token_revoked', 'info', ['token_id' => $id, 'reason' => $reason]);
    }
    return $changed > 0;
}

/** Revoke every live token on an account, optionally sparing the one in use. */
function api_token_revoke_all(string $userType, int $userId, string $reason = 'revoked_all', ?int $except = null): int
{
    $sql    = '`user_type` = :t AND `user_id` = :u AND `revoked_at` IS NULL';
    $params = ['t' => $userType, 'u' => $userId];
    if ($except !== null) {
        $sql .= ' AND `id` <> :keep';
        $params['keep'] = $except;
    }

    try {
        $changed = Database::update('api_tokens', [
            'revoked_at'     => date('Y-m-d H:i:s'),
            'revoked_reason' => mb_substr($reason, 0, 60),
        ], $sql, $params);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'api_token_revoke_all failed: ' . $e->getMessage());
        return 0;
    }

    if ($changed > 0) {
        security_event('api.tokens_revoked_all', 'medium', ['count' => $changed, 'reason' => $reason], $userId, $userType);
    }
    return $changed;
}

/** The devices on one account, newest first. */
function api_token_list(string $userType, int $userId, bool $activeOnly = true): array
{
    $where = '`user_type` = :t AND `user_id` = :u';
    if ($activeOnly) {
        $where .= ' AND `revoked_at` IS NULL AND `expires_at` > NOW()';
    }

    return Database::fetchAll(
        "SELECT `id`, `user_type`, `user_id`, `device_name`, `platform`, `created_at`, `created_ip`,
                `expires_at`, `last_used_at`, `last_ip`, `revoked_at`, `revoked_reason`
           FROM `api_tokens` WHERE {$where} ORDER BY `id` DESC LIMIT 100",
        ['t' => $userType, 'u' => $userId]
    );
}

// ===========================================================================
//  Bearer authentication for the existing API
// ===========================================================================

/**
 * Let a verified token stand in for a session, for this request only.
 *
 * The API is built on current_user() / admin_user(), which read the session.
 * Rather than teach every endpoint a second way to know who is calling, a
 * valid bearer token borrows the session for the length of the request and
 * hands it back at the end: api_token_release() clears the keys and calls
 * session_abort(), so nothing is written to the session store. A stolen
 * cookie can therefore never be a leftover of somebody's app traffic, and
 * revoking the token really does end access.
 *
 * Called by the api_require_* helpers in includes/response.php.
 */
function api_token_boot(): ?array
{
    static $booted = false;
    if ($booted) {
        return api_token_authenticate();
    }
    $booted = true;

    if (api_token_bearer() === '') {
        return null;
    }

    $auth = api_token_authenticate();
    if ($auth === null) {
        return null;
    }

    $account = $auth['account'];
    $stamp   = [
        'logged_at'    => time(),
        'seen_at'      => time(),
        'auth_version' => (int) ($account['auth_version'] ?? 1),
        // Marks the session as borrowed, so anything that inspects it can tell
        // this was a token and not somebody sitting at a browser.
        'via'          => 'api_token',
    ];

    if ($auth['user_type'] === 'admin') {
        $_SESSION[ADMIN_SESSION_KEY] = $stamp + [
            'id'    => (int) $account['id'],
            'email' => $account['email'],
            'name'  => $account['name'],
        ];
    } else {
        $_SESSION[USER_SESSION_KEY] = $stamp + [
            'id'    => (int) $account['id'],
            'email' => $account['email'],
            'name'  => trim($account['first_name'] . ' ' . (string) ($account['last_name'] ?? '')),
        ];
    }

    register_shutdown_function('api_token_release');

    return $auth;
}

/** Give the borrowed session back without writing it anywhere. */
function api_token_release(): void
{
    try {
        unset($_SESSION[USER_SESSION_KEY], $_SESSION[ADMIN_SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Discards every change this request made to the session, so the
            // token never leaves a signed-in session behind on disk.
            session_abort();
        }
    } catch (Throwable $e) {
        error_log('api_token_release: ' . $e->getMessage());
    }
}

/** True when this request was authenticated by a token rather than a cookie. */
function api_token_request(): bool
{
    return api_token_bearer() !== '' && api_token_authenticate() !== null;
}
