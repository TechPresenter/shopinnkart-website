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

/** Role picker options: id => name, inactive roles marked so they are not chosen by accident. */
function admins_role_options(): array
{
    $options = [];
    foreach (Database::fetchAll('SELECT `id`, `name`, `status` FROM `admin_roles` ORDER BY `name`') as $role) {
        $options[(int) $role['id']] = $role['status'] === 'active'
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

/** Every "module.action" string the panel understands, flattened. */
function admins_all_permissions(): array
{
    $all = [];
    foreach (PERMISSION_MODULES as $module => $actions) {
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
