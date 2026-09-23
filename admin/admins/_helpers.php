<?php
/**
 * ShopInnKart Admin - Shared guards for the admin-user screens.
 *
 * The "last Super Admin" rule has to hold on the list, the edit form, the
 * delete endpoint and the role editor. A check that lives on only one of them
 * is not a check, so all four call the helpers here.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// "Nobody manages up" is enforced by the same guards the settings, customer
// and backup screens use, so all of them agree on who outranks whom.
require_once ADMIN_PATH . '/includes/rbac.php';

const ADMIN_ACCOUNT_STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

/**
 * Role ids whose permission list is ["*"].
 *
 * Super Admin is defined by the wildcard, not by the row id or the slug, so a
 * second unrestricted role created later is treated exactly the same way.
 */
function admins_super_role_ids(): array
{
    $ids = [];
    foreach (Database::fetchPairs('SELECT `id`, `permissions` FROM `admin_roles`') as $id => $json) {
        if (in_array('*', json_decode_safe((string) $json, []), true)) {
            $ids[] = (int) $id;
        }
    }
    return $ids;
}

function admins_role_is_super(int $roleId): bool
{
    return in_array($roleId, admins_super_role_ids(), true);
}

/**
 * Ids of admins who can actually sign in with unrestricted access right now:
 * active account, active role, wildcard permissions.
 */
function admins_active_super_ids(): array
{
    $roleIds = admins_super_role_ids();
    if ($roleIds === []) {
        return [];
    }

    [$placeholders, $params] = Database::inPlaceholders($roleIds, 'role');

    return array_map('intval', Database::fetchColumnAll(
        "SELECT a.`id`
         FROM `admins` a
         INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
         WHERE a.`status` = 'active' AND r.`status` = 'active'
           AND a.`role_id` IN ({$placeholders})",
        $params
    ));
}

/** True when removing this admin's access would leave the panel with no Super Admin. */
function admins_is_last_active_super(int $adminId): bool
{
    $supers = admins_active_super_ids();
    return count($supers) === 1 && in_array($adminId, $supers, true);
}

/**
 * Role picker options: id => name, inactive roles marked so they are not
 * chosen by accident.
 *
 * Only roles the operator could grant are offered. $keepId keeps the account's
 * current role in the list even when it outranks them, so the select still
 * shows what the account actually holds instead of silently reading as blank.
 */
function admins_role_options(?int $keepId = null): array
{
    $options = [];
    foreach (Database::fetchAll('SELECT `id`, `name`, `status` FROM `admin_roles` ORDER BY `name`') as $role) {
        $roleId = (int) $role['id'];
        if ($roleId !== $keepId && !admin_can_manage_role($roleId)) {
            continue;
        }
        $options[$roleId] = $role['status'] === 'active'
            ? (string) $role['name']
            : $role['name'] . ' (inactive)';
    }
    return $options;
}

/** Unique slug within admin_roles. unique_slug() does not cover this table. */
function admins_unique_role_slug(string $slug, ?int $ignoreId = null): string
{
    $base = $slug;
    $suffix = 1;

    while (true) {
        $sql = 'SELECT `id` FROM `admin_roles` WHERE `slug` = :slug';
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $ignoreId;
        }
        if (Database::fetchColumn($sql . ' LIMIT 1', $params) === null) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}

/**
 * module => actions for the role matrix, unioned with the admin_permissions
 * table.
 *
 * The constant alone used to decide the grid, so a permission a migration
 * inserted into the table (security.view and security.edit were the first two)
 * had no checkbox and was stripped from any role that somehow held it. Reading
 * both and keeping whichever is wider means a migration can add a permission
 * without also editing constants.php, and neither source can silently drop one.
 */
function admins_permission_modules(): array
{
    static $modules = null;
    if ($modules !== null) {
        return $modules;
    }

    $modules = PERMISSION_MODULES;

    try {
        foreach (Database::fetchAll('SELECT `module`, `action` FROM `admin_permissions`') as $row) {
            $module = (string) $row['module'];
            $action = (string) $row['action'];
            if ($module === '' || $action === '') {
                continue;
            }
            if (!isset($modules[$module])) {
                $modules[$module] = [];
            }
            if (!in_array($action, $modules[$module], true)) {
                $modules[$module][] = $action;
            }
        }
    } catch (Throwable $e) {
        // A missing or unreadable table falls back to the constant rather than
        // rendering a grid with no permissions at all.
    }

    // Familiar verbs first, anything else after, so the columns stay stable.
    foreach ($modules as $module => $actions) {
        $known = array_values(array_intersect(PERMISSION_ACTION_ORDER, $actions));
        $extra = array_values(array_diff($actions, PERMISSION_ACTION_ORDER));
        sort($extra);
        $modules[$module] = array_merge($known, $extra);
    }

    return $modules;
}

/** Column order of the role matrix: the verbs above, then every extra action in use. */
function admins_action_columns(): array
{
    $extra = [];
    foreach (admins_permission_modules() as $actions) {
        foreach ($actions as $action) {
            if (!in_array($action, PERMISSION_ACTION_ORDER, true)) {
                $extra[$action] = true;
            }
        }
    }
    $extra = array_keys($extra);
    sort($extra);

    return array_merge(PERMISSION_ACTION_ORDER, $extra);
}

/** Every "module.action" string the panel understands, flattened. */
function admins_all_permissions(): array
{
    $all = [];
    foreach (admins_permission_modules() as $module => $actions) {
        foreach ($actions as $action) {
            $all[] = $module . '.' . $action;
        }
    }
    return $all;
}

/** Human label for a permission module key. */
function admins_module_label(string $module): string
{
    return ucwords(str_replace('_', ' ', $module));
}
