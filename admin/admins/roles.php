<?php
/**
 * ShopInnKart Admin - Roles and the permission matrix.
 *
 * A role stores a JSON array of "module.action" strings. The grid below is
 * built from PERMISSION_MODULES, so a module added to constants.php appears
 * here without anyone touching this file.
 *
 * The Super Admin role is the exception: its list is the wildcard ["*"] and
 * stays that way, because it is what admin_can() short-circuits on.
 *
 * "Grant only what you hold" used to be checked against the SUBMITTED list
 * only, which left the role being edited unguarded: a lower admin could open a
 * role full of permissions they lacked, post a smaller list plus
 * status=inactive, and lock out everyone holding it. So the stored role has to
 * be within reach too, on save and on delete, and the editor is not offered at
 * all for a role that outranks the viewer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('admins.view');

require_once __DIR__ . '/_helpers.php';

/** Column order of the matrix, widened by whatever the registry declares. */
$actionColumns = admins_action_columns();
$allModules    = admins_permission_modules();

$rolesUrl = admin_url('admins/roles.php');

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------
$errors = [];
$op     = (string) input('op', '');

if (is_post() && $op === 'delete') {
    admin_require_action('admins.delete');   // POST + CSRF + permission

    $roleId = input_int('id');
    $role   = $roleId > 0
        ? Database::fetch('SELECT `id`, `name`, `is_system` FROM `admin_roles` WHERE `id` = :id', ['id' => $roleId])
        : null;

    if ($role === null) {
        flash('error', 'That role no longer exists.');
        redirect($rolesUrl);
    }

    // Deleting a role you could not have created is the same escalation as
    // editing one, read backwards.
    if (!admin_can_manage_role($roleId)) {
        admin_deny_back(
            '"' . $role['name'] . '" grants permissions you do not hold yourself, so you cannot delete it.',
            $rolesUrl,
            ['role_id' => $roleId, 'beyond' => admin_permissions_beyond(admin_role_permissions($roleId))]
        );
    }

    if ((int) $role['is_system'] === 1) {
        flash('error', '"' . $role['name'] . '" is a built-in role and cannot be deleted. You can deactivate it instead.');
        redirect($rolesUrl);
    }

    // fk_admin_role is ON DELETE RESTRICT, so this would fail at the database
    // anyway — refusing here lets the message say why.
    $inUse = Database::count('admins', '`role_id` = :id', ['id' => $roleId]);
    if ($inUse > 0) {
        flash('error', '"' . $role['name'] . '" is still assigned to ' . $inUse . ' admin user'
            . ($inUse === 1 ? '' : 's') . '. Move them to another role first.');
        redirect($rolesUrl);
    }

    Database::delete('admin_roles', '`id` = :id', ['id' => $roleId]);

    log_activity('role.deleted', 'admin_role', $roleId, 'Deleted role "' . $role['name'] . '"');
    security_event('rbac.role_changed', 'high',
        ['action' => 'deleted', 'role_id' => $roleId, 'role' => (string) $role['name']],
        (int) $admin['id'], 'admin');
    admin_after_write();

    flash('success', 'Role "' . $role['name'] . '" deleted.');
    redirect($rolesUrl);
}

if (is_post() && $op === 'save') {
    $roleId = input_int('id');
    $isNew  = $roleId <= 0;

    admin_require_action($isNew ? 'admins.create' : 'admins.edit');   // POST + CSRF + permission

    $current = $isNew
        ? null
        : Database::fetch('SELECT * FROM `admin_roles` WHERE `id` = :id', ['id' => $roleId]);

    if (!$isNew && $current === null) {
        flash('error', 'That role no longer exists.');
        redirect($rolesUrl);
    }

    // The role as it stands has to be within reach before a single field of it
    // is touched - otherwise "submit a smaller list" is a way to strip powers
    // you were never able to grant.
    if (!$isNew && !admin_can_manage_role($roleId)) {
        admin_deny_back(
            '"' . $current['name'] . '" grants permissions you do not hold yourself, so you cannot change it. '
                . 'Ask an admin with at least that access.',
            $rolesUrl,
            ['role_id' => $roleId, 'beyond' => admin_permissions_beyond(admin_role_permissions($roleId))]
        );
    }

    $submitted = [
        'name'        => (string) input('name', ''),
        'slug'        => (string) input('slug', ''),
        'description' => (string) input('description', ''),
        'status'      => (string) input('status', 'active'),
    ];

    $isSuperRole = !$isNew && in_array('*', json_decode_safe((string) $current['permissions'], []), true);

    // Only permissions the panel actually understands are stored.
    $permissions = array_values(array_intersect(input_array('permissions'), admins_all_permissions()));

    $v = new Validator($submitted, ['name' => 'Role name', 'status' => 'Status']);
    $v->required('name')->max('name', 100)
      ->max('slug', 100)
      ->max('description', 255)
      ->required('status')->in('status', ['active', 'inactive']);

    if (!$isSuperRole && $permissions === []) {
        $v->rule('permissions', false, 'Pick at least one permission — a role with none cannot open a single screen.');
    }

    // An operator cannot grant what they do not hold themselves, and the four
    // permissions in ADMIN_SUPER_ONLY_PERMISSIONS stay with the owner however
    // they are held; otherwise the roles editor is a one-click escalation.
    $beyond = admin_permissions_beyond($permissions);
    if ($beyond !== []) {
        $v->rule('permissions', false, 'You can only grant permissions you hold yourself, and '
            . implode(', ', ADMIN_SUPER_ONLY_PERMISSIONS) . ' are Super Admin only. Remove: '
            . implode(', ', array_slice($beyond, 0, 6)) . (count($beyond) > 6 ? '…' : '') . '.');
    }

    // Rewriting who can do what is a privilege change, so it carries the same
    // step-up as resetting a password.
    if (!admin_reauth_ok()) {
        $v->rule('reauth_password', false, admin_reauth_error('change a role'));
    }

    // Deactivating the role that carries the wildcard blocks every Super Admin
    // at admin_user(), which checks the role status as well as the account.
    if ($isSuperRole && $submitted['status'] !== 'active') {
        $stillSuper = array_diff(admins_active_super_ids(), array_map('intval', Database::fetchColumnAll(
            'SELECT `id` FROM `admins` WHERE `role_id` = :id',
            ['id' => $roleId]
        )));
        if ($stillSuper === []) {
            $v->rule('status', false, 'Deactivating this role would leave no Super Admin able to sign in.');
        }
    }

    if ($v->fails()) {
        $errors = $v->errors();
        flash_errors($errors);
        flash_old(array_merge($submitted, ['permissions' => $permissions]));
        flash('error', 'Nothing was saved. Please correct the highlighted fields.');
        redirect($isNew ? $rolesUrl . '?new=1' : $rolesUrl . '?id=' . $roleId);
    }

    $slugSource = $submitted['slug'] !== '' ? $submitted['slug'] : $submitted['name'];
    $data = [
        'name'        => $submitted['name'],
        'slug'        => admins_unique_role_slug(slugify($slugSource), $isNew ? null : $roleId),
        'description' => $submitted['description'] !== '' ? $submitted['description'] : null,
        'status'      => $submitted['status'],
        // The wildcard is preserved verbatim; the grid never round-trips it.
        'permissions' => json_encode($isSuperRole ? ['*'] : $permissions),
    ];

    if ($isNew) {
        $roleId = Database::insert('admin_roles', $data + ['is_system' => 0]);
        log_activity('role.created', 'admin_role', $roleId,
            'Created role "' . $data['name'] . '" with ' . count($permissions) . ' permission(s)');
        flash('success', 'Role "' . $data['name'] . '" created.');
    } else {
        Database::update('admin_roles', $data, '`id` = :id', ['id' => $roleId]);
        log_activity('role.updated', 'admin_role', $roleId,
            'Updated role "' . $data['name'] . '"'
            . ($isSuperRole ? ' (permissions locked to unrestricted)' : ' — ' . count($permissions) . ' permission(s)'));
        flash('success', 'Role "' . $data['name'] . '" updated.');
    }

    // Who can do what just moved. The permission lists go in verbatim so the
    // trail shows the shape of the change, not only that one happened.
    security_event('rbac.role_changed', 'high', [
        'action'      => $isNew ? 'created' : 'updated',
        'role_id'     => $roleId,
        'role'        => $data['name'],
        'status'      => $data['status'],
        'before'      => $isNew ? [] : json_decode_safe((string) $current['permissions'], []),
        'after'       => $isSuperRole ? ['*'] : $permissions,
    ], (int) $admin['id'], 'admin');

    admin_after_write();
    redirect($rolesUrl . '?id=' . $roleId);
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------
$roles      = Database::fetchAll('SELECT * FROM `admin_roles` ORDER BY `is_system` DESC, `name` ASC');
$roleUsage  = Database::fetchPairs('SELECT `role_id`, COUNT(*) FROM `admins` GROUP BY `role_id`');
$canCreate  = admin_can('admins.create');
$canEditRow = admin_can('admins.edit');
$canDelete  = admin_can('admins.delete');

$editId  = (int) ($_GET['id'] ?? 0);
$wantNew = isset($_GET['new']) && $canCreate;

$editing = null;
if ($editId > 0 && $canEditRow) {
    foreach ($roles as $role) {
        if ((int) $role['id'] === $editId) {
            $editing = $role;
            break;
        }
    }
    if ($editing === null) {
        flash('error', 'That role no longer exists.');
        redirect($rolesUrl);
    }
    // The save refuses it anyway; not rendering the form is what stops an
    // operator filling one in and only then being told no.
    if (!admin_can_manage_role($editId)) {
        flash('error', '"' . $editing['name'] . '" grants permissions you do not hold yourself, '
            . 'so it is read-only for you.');
        redirect($rolesUrl);
    }
} elseif ($wantNew) {
    $editing = [
        'id'          => 0,
        'name'        => '',
        'slug'        => '',
        'description' => '',
        'permissions' => '[]',
        'is_system'   => 0,
        'status'      => 'active',
    ];
}

$errors = errors_pull();

// A rejected submission is repopulated from the old bag; the bag is copied out
// first because old_clear() drops it wholesale.
$old = $_SESSION['_old'] ?? [];
old_clear();

$formValue = static function (string $key, $default = '') use ($old) {
    return $old[$key] ?? $default;
};

$isNewForm    = $editing !== null && (int) $editing['id'] === 0;
$editingPerms = $editing !== null ? json_decode_safe((string) $editing['permissions'], []) : [];
$isSuperEdit  = in_array('*', $editingPerms, true);
$checkedPerms = isset($old['permissions']) && is_array($old['permissions'])
    ? array_values(array_filter($old['permissions'], 'is_string'))
    : $editingPerms;

$pageTitle    = 'Roles & Permissions';
$pageSubtitle = count($roles) . ' role(s) · ' . count(admins_active_super_ids()) . ' active Super Admin(s)';
$breadcrumbs  = [
    ['label' => 'Dashboard',   'url' => admin_url('dashboard.php')],
    ['label' => 'Admin Users', 'url' => admin_url('admins/')],
    ['label' => 'Roles'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('admins/')) . '">' . icon('users', 'w-4 h-4') . ' Admin Users</a>';
if ($canCreate && $editing === null) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e($rolesUrl . '?new=1') . '">'
        . icon('plus', 'w-4 h-4') . ' New Role</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Roles</div>
            <div class="ad-card__sub">Every admin user holds exactly one role, and the role holds every permission.</div>
        </div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($roles === []): ?>
            <?= admin_empty(
                'No roles defined',
                'Without a role no admin user can be created, because the account needs one to sign in.',
                $canCreate ? 'New Role' : null,
                $canCreate ? $rolesUrl . '?new=1' : null,
                'shield'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Permissions</th>
                            <th class="ad-table__num">Admins</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                            <?php
                            $roleId     = (int) $role['id'];
                            $perms      = json_decode_safe((string) $role['permissions'], []);
                            $isWildcard = in_array('*', $perms, true);
                            $inUse      = (int) ($roleUsage[$roleId] ?? 0);
                            $isSystem   = (int) $role['is_system'] === 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex__name">
                                        <?php if ($canEditRow): ?>
                                            <a href="<?= e($rolesUrl . '?id=' . $roleId) ?>"><?= e($role['name']) ?></a>
                                        <?php else: ?>
                                            <?= e($role['name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ad-cellflex__meta ad-mono">/<?= e($role['slug']) ?></div>
                                    <?php if (!empty($role['description'])): ?>
                                        <div class="ad-cellflex__meta"><?= e(str_limit((string) $role['description'], 90)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isWildcard): ?>
                                        <span class="sik-status sik-status--violet">Unrestricted (*)</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--gray">
                                            <?= count($perms) ?> of <?= count(admins_all_permissions()) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num">
                                    <?php if ($inUse > 0 && admin_can('admins.view')): ?>
                                        <a href="<?= e(admin_url('admins/?role=' . $roleId)) ?>"><?= number_format($inUse) ?></a>
                                    <?php else: ?>
                                        <?= number_format($inUse) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $isSystem
                                        ? '<span class="sik-status sik-status--blue">Built-in</span>'
                                        : '<span class="ad-muted">Custom</span>' ?>
                                </td>
                                <td><?= admin_state_badge((string) $role['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEditRow): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($role['name']) ?>"
                                           href="<?= e($rolesUrl . '?id=' . $roleId) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete && !$isSystem && $inUse === 0): ?>
                                        <form method="post" action="<?= e($rolesUrl) ?>" class="ad-inline-form"
                                              <?= admin_confirm_form_attrs(
                                                  'Delete the role "' . $role['name'] . '"? This cannot be undone.',
                                                  ['title' => 'Delete this role?', 'label' => 'Delete role']
                                              ) ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="delete">
                                            <input type="hidden" name="id" value="<?= $roleId ?>">
                                            <button type="submit" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                                    title="Delete" aria-label="Delete <?= e_attr($role['name']) ?>">
                                                <?= icon('trash', 'w-4 h-4') ?>
                                            </button>
                                        </form>
                                    <?php elseif ($canDelete): ?>
                                        <span class="ad-muted" style="font-size:11.5px"
                                              title="<?= e_attr($isSystem
                                                  ? 'Built-in roles cannot be deleted.'
                                                  : 'Still assigned to ' . $inUse . ' admin user(s).') ?>">
                                            <?= $isSystem ? 'Built-in' : 'In use' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($editing !== null): ?>
<form class="ad-form" method="post" action="<?= e($rolesUrl) ?>" data-guard-unsaved>
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title"><?= $isNewForm ? 'New role' : 'Edit role: ' . e($editing['name']) ?></div>
                <div class="ad-card__sub">
                    <?= $isNewForm
                        ? 'Name it after the job, then tick only what that job needs.'
                        : 'Changes apply the next time each admin loads a page.' ?>
                </div>
            </div>
            <a class="ad-btn ad-btn--sm" href="<?= e($rolesUrl) ?>">Close</a>
        </div>

        <div class="ad-card__body">
            <?php if ($isSuperEdit): ?>
                <div class="sik-alert sik-alert--warning">
                    <?= icon('shield', 'w-5 h-5') ?>
                    <div>
                        This role holds the wildcard permission <code>*</code>, which grants everything
                        including future modules. The matrix below is read-only for this role &mdash; the
                        wildcard is saved back unchanged no matter what is submitted.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($errors['permissions'])): ?>
                <div class="sik-alert sik-alert--error">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div><?= e($errors['permissions']) ?></div>
                </div>
            <?php endif; ?>

            <div class="ad-row ad-row--3">
                <div class="ad-field">
                    <label class="sik-label" for="roleName">Role name <span class="req">*</span></label>
                    <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                           id="roleName" name="name" maxlength="100" required
                           data-slug-source="#roleSlug"
                           value="<?= e($formValue('name', $editing['name'])) ?>" placeholder="e.g. Warehouse Lead">
                    <?php if (isset($errors['name'])): ?>
                        <span class="sik-error"><?= e($errors['name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="roleSlug">Slug</label>
                    <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                           id="roleSlug" name="slug" maxlength="100" data-slugify
                           value="<?= e($formValue('slug', $editing['slug'] ?? '')) ?>" placeholder="warehouse-lead">
                    <?php if (isset($errors['slug'])): ?>
                        <span class="sik-error"><?= e($errors['slug']) ?></span>
                    <?php else: ?>
                        <span class="sik-help">Leave blank to build it from the name.</span>
                    <?php endif; ?>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="roleStatus">Status <span class="req">*</span></label>
                    <select class="sik-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>"
                            id="roleStatus" name="status" required>
                        <?= admin_options(
                            ['active' => 'Active', 'inactive' => 'Inactive'],
                            (string) $formValue('status', $editing['status'])
                        ) ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <span class="sik-error"><?= e($errors['status']) ?></span>
                    <?php else: ?>
                        <span class="sik-help">An inactive role stops everyone holding it from signing in.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="roleDescription">Description</label>
                <input class="sik-input<?= isset($errors['description']) ? ' is-invalid' : '' ?>" type="text"
                       id="roleDescription" name="description" maxlength="255"
                       value="<?= e($formValue('description', $editing['description'] ?? '')) ?>"
                       placeholder="One line explaining who this role is for.">
                <?php if (isset($errors['description'])): ?>
                    <span class="sik-error"><?= e($errors['description']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card__head" style="border-top:1px solid var(--ad-border)">
            <div>
                <div class="ad-card__title">Permission matrix</div>
                <div class="ad-card__sub">
                    <?= count($checkedPerms) ?> selected ·
                    <?= count(admins_all_permissions()) ?> available across <?= count($allModules) ?> modules
                </div>
            </div>
            <?php if (!$isSuperEdit): ?>
                <label class="ad-switch" style="font-size:12.5px">
                    <input type="checkbox" data-check-all aria-label="Select every permission">
                    <span class="ad-switch__track"></span>
                    <span>Select all</span>
                </label>
            <?php endif; ?>
        </div>

        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <?php foreach ($actionColumns as $action): ?>
                                <th style="text-align:center"><?= e(ucfirst($action)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allModules as $module => $actions): ?>
                            <tr>
                                <td>
                                    <span class="ad-cellflex__name"><?= e(admins_module_label($module)) ?></span>
                                    <span class="ad-cellflex__meta ad-mono"><?= e($module) ?></span>
                                </td>
                                <?php foreach ($actionColumns as $action): ?>
                                    <td style="text-align:center">
                                        <?php if (!in_array($action, $actions, true)): ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php else: ?>
                                            <?php
                                            $key = $module . '.' . $action;
                                            // Out of the operator's own reach: shown so the matrix stays
                                            // readable, but not tickable - the save refuses it too.
                                            $locked = $isSuperEdit || admin_permissions_beyond([$key]) !== [];
                                            ?>
                                            <label class="sik-sr" for="perm_<?= e_attr($key) ?>">
                                                <?= e($key) ?>
                                            </label>
                                            <input type="checkbox" id="perm_<?= e_attr($key) ?>"
                                                   name="permissions[]" value="<?= e_attr($key) ?>"
                                                   <?= $locked ? 'disabled' : 'data-check-row' ?>
                                                   <?= $isSuperEdit || in_array($key, $checkedPerms, true) ? 'checked' : '' ?>
                                                   <?= $locked && !$isSuperEdit
                                                       ? 'title="Only an admin who holds this permission can grant it."' : '' ?>>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
            <?= admin_reauth_field('change who can do what', (string) ($errors['reauth_password'] ?? '')) ?>
        </div>

        <div class="ad-card__foot">
            <span class="ad-muted" style="margin-right:auto;font-size:12.5px">
                Ticking every box is not the same as the wildcard: only <code>*</code> covers modules added later.
            </span>
            <a class="ad-btn" href="<?= e($rolesUrl) ?>">Cancel</a>
            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('check', 'w-4 h-4') ?> <?= $isNewForm ? 'Create Role' : 'Save Role' ?>
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
