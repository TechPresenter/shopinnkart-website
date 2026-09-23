<?php
/**
 * ShopInnKart Admin - Privilege hierarchy, step-up re-authentication and the
 * notices that go with them.
 *
 * The rule every screen here enforces is "nobody manages up": an admin may
 * only act on an account, a role or a setting whose power is a subset of their
 * own. Without it, one permission (admins.edit) is the whole panel, because the
 * holder can simply reset a Super Admin's password and sign in as them.
 *
 * It lives in its own file because five unrelated screens need the same answer
 * - admin users, roles, customers, the theme's script boxes and the database
 * backup - and a guard that is re-typed per screen is a guard that is missed on
 * the sixth.
 *
 * Every refusal and every successful privilege change is written to
 * security_events, which the UI cannot clear.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Permissions that only a Super Admin may hand out, whoever holds them.
 *
 * Each one is a route back to everything else: security.edit hides the login
 * and switches protections off, settings.scripts runs JavaScript on the
 * admin's own origin, system.backup reads every password hash, and
 * customers.credentials takes over shopper accounts. Delegating them has to be
 * a deliberate act by the owner, not a side effect of holding admins.edit.
 */
const ADMIN_SUPER_ONLY_PERMISSIONS = [
    'security.edit',
    'settings.scripts',
    'system.backup',
    'customers.credentials',
];

/** Settings whose value is executed in the browser, so they are not ordinary text. */
const ADMIN_SCRIPT_SETTING_KEYS = ['custom_js', 'custom_css'];

/** How long a fresh re-authentication is accepted for, in seconds. */
const ADMIN_REAUTH_WINDOW = 900;

// ===========================================================================
//  Who outranks whom
// ===========================================================================

/** The permission list stored on a role, decoded. ['*'] means unrestricted. */
function admin_role_permissions(int $roleId): array
{
    static $cache = [];

    if (!isset($cache[$roleId])) {
        $json = Database::fetchColumn('SELECT `permissions` FROM `admin_roles` WHERE `id` = :id', ['id' => $roleId]);
        $cache[$roleId] = array_values(array_filter(json_decode_safe((string) $json, []), 'is_string'));
    }

    return $cache[$roleId];
}

/**
 * May the signed-in admin hand out this exact set of permissions?
 *
 * A Super Admin may hand out anything. Anyone else may only pass on what they
 * already hold, and never the wildcard or the four permissions above - which
 * is what stops the role editor being a one-click escalation.
 *
 * @return string[] the permissions that are out of reach ([] means "yes")
 */
function admin_permissions_beyond(array $permissions): array
{
    if (admin_is_super()) {
        return [];
    }

    $beyond = [];
    foreach ($permissions as $permission) {
        if (!is_string($permission)) {
            continue;
        }
        if ($permission === '*'
            || in_array($permission, ADMIN_SUPER_ONLY_PERMISSIONS, true)
            || !admin_can($permission)) {
            $beyond[] = $permission;
        }
    }

    return array_values(array_unique($beyond));
}

/** May the signed-in admin assign, edit or delete this role? */
function admin_can_manage_role(int $roleId): bool
{
    return admin_permissions_beyond(admin_role_permissions($roleId)) === [];
}

/**
 * May the signed-in admin edit, reset, unlock, demote, deactivate or delete
 * this account?
 *
 * Yes when they are a Super Admin, or when everything the target's role grants
 * is something the actor holds too. Equal rank is allowed on purpose: two
 * admins on the same role are peers and already have each other's reach.
 *
 * @param array|int $target an admins row (needs role_id) or an admin id
 */
function admin_can_manage_admin($target): bool
{
    if (admin_is_super()) {
        return true;
    }

    $roleId = is_array($target)
        ? (int) ($target['role_id'] ?? 0)
        : (int) Database::fetchColumn('SELECT `role_id` FROM `admins` WHERE `id` = :id', ['id' => (int) $target]);

    return $roleId > 0 && admin_can_manage_role($roleId);
}

/** Super Admin, or the holder of the dedicated permission. */
function admin_can_edit_scripts(): bool
{
    return admin_is_super() || admin_can('settings.scripts');
}

// ===========================================================================
//  Refusals
// ===========================================================================

/**
 * Record a refusal and stop the request with the 403 screen.
 *
 * The event is written before anything is rendered, so a refusal is on record
 * even if the render itself fails. Never returns.
 */
function admin_deny(string $reason, array $context = []): void
{
    $admin = admin_user();

    security_event('rbac.denied', 'high', ['reason' => $reason] + $context,
        $admin !== null ? (int) $admin['id'] : null, 'admin');

    flash('error', $reason);
    admin_forbidden();
}

/** Record a refusal, flash it and send the admin back - for a save that is only partly out of reach. */
function admin_deny_back(string $reason, string $redirectUrl, array $context = []): void
{
    $admin = admin_user();

    security_event('rbac.denied', 'high', ['reason' => $reason] + $context,
        $admin !== null ? (int) $admin['id'] : null, 'admin');

    flash('error', $reason);
    redirect($redirectUrl);
}

// ===========================================================================
//  Step-up re-authentication
// ===========================================================================

/**
 * Did this request carry the actor's own password?
 *
 * Asked again for the handful of actions that hand over an account or the
 * database: a borrowed session, an unlocked laptop or an XSS payload riding
 * the admin's cookies does not know the password.
 *
 * Rate limited per admin so the box cannot be used as a password oracle, and
 * cleared on success so ordinary work is never blocked.
 */
function admin_reauth_ok(string $field = 'reauth_password'): bool
{
    $admin = admin_user();
    if ($admin === null) {
        return false;
    }

    $adminId  = (int) $admin['id'];
    $password = (string) ($_POST[$field] ?? '');

    if ($password === '') {
        return false;
    }

    if (!rate_limit_attempt('admin.reauth', (string) $adminId, 10, ADMIN_REAUTH_WINDOW)) {
        security_event('auth.reauth_failed', 'critical',
            ['reason' => 'too many re-authentication attempts'], $adminId, 'admin');
        return false;
    }

    // _app() so a long password is truncated the same way the login screen
    // truncates it under bcrypt - otherwise a 90-character password would sign
    // in but never re-authenticate.
    if (!password_verify_app($password, (string) $admin['password'])) {
        security_event('auth.reauth_failed', 'high', ['reason' => 'wrong password'], $adminId, 'admin');
        return false;
    }

    rate_limit_clear('admin.reauth', (string) $adminId);

    return true;
}

/** The message shown when a sensitive change arrives without the password. */
function admin_reauth_error(string $what): string
{
    return 'Confirm with your own password to ' . $what . '.';
}

/** The password box every sensitive form ends with. */
function admin_reauth_field(string $what, string $error = ''): string
{
    return '<div class="ad-field">'
        . '<label class="sik-label" for="reauthPassword">Your password <span class="req">*</span></label>'
        . '<input class="sik-input' . ($error !== '' ? ' is-invalid' : '') . '" type="password"'
        . ' id="reauthPassword" name="reauth_password" autocomplete="current-password">'
        . ($error !== ''
            ? '<span class="sik-error">' . e($error) . '</span>'
            : '<span class="sik-help">Required to ' . e($what) . '. It is your own password, not theirs.</span>')
        . '</div>';
}

// ===========================================================================
//  Telling the account holder
// ===========================================================================

/**
 * Warn an admin that someone changed their account.
 *
 * Silent credential changes are what turn a compromised admin session into a
 * lasting takeover, so the person whose account moved always hears about it
 * even when the change was legitimate.
 */
function admin_notify_account_change(array $account, string $what): void
{
    $actor = admin_user();

    notify(
        'security_admin_alert',
        (string) ($account['email'] ?? ''),
        [
            'admin_name'  => (string) ($account['name'] ?? 'there'),
            'change'      => $what,
            'actor_name'  => (string) ($actor['name'] ?? 'System'),
            'event_time'  => format_datetime(date('Y-m-d H:i:s'), 'd M Y, g:i A'),
            'ip_address'  => client_ip(),
        ],
        'admin',
        (int) ($account['id'] ?? 0),
        'email',
        [
            'recipient_type' => 'admin',
            'recipient_name' => (string) ($account['name'] ?? ''),
            // Two different changes minutes apart are two different warnings.
            'force'          => true,
        ]
    );
}

/** The same warning for a shopper, sent to the address on file BEFORE the change. */
function admin_notify_customer_change(array $customer, string $what): void
{
    $actor = admin_user();

    notify(
        'security_account_changed',
        (string) ($customer['email'] ?? ''),
        [
            'customer_name' => trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? '')),
            'change'        => $what,
            'actor_name'    => (string) ($actor['name'] ?? 'Support'),
            'event_time'    => format_datetime(date('Y-m-d H:i:s'), 'd M Y, g:i A'),
        ],
        'user',
        (int) ($customer['id'] ?? 0),
        'email',
        [
            'recipient_name' => trim((string) ($customer['first_name'] ?? '')),
            'user_id'        => (int) ($customer['id'] ?? 0),
            'force'          => true,
        ]
    );
}
