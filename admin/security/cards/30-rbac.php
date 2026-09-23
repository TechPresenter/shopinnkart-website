<?php
/**
 * Security card: who holds the dangerous permissions.
 *
 * Read-only on purpose. Roles are edited in Admin > Admin Users > Roles; what
 * is missing there is the view from the other end - not "what can this role
 * do" but "who can do this", which is the question asked after an incident and
 * the one nobody can answer by clicking through nine role pages.
 *
 * Every permission listed here is a route back to everything else, so a row
 * with more names on it than expected is the finding.
 */

declare(strict_types=1);

return [
    'key'    => 'rbac-summary',
    'order'  => 30,
    'column' => 'main',

    'actions' => [],

    'render' => static function (array $errors, bool $canEdit): void {
        /** permission => why it matters. */
        $watched = [
            'security.edit'         => 'Can hide or unhide the admin login and change these settings',
            'security.view'         => 'Can read the security screens',
            'admins.create'         => 'Can create new admin sign-ins',
            'admins.edit'           => 'Can reset passwords and change roles',
            'admins.delete'         => 'Can delete admin accounts',
            'settings.scripts'      => 'Can run JavaScript on every storefront page',
            'system.backup'         => 'Can download the whole database',
            'customers.credentials' => 'Can set a shopper\'s password or email',
            'logs.delete'           => 'Can prune the activity and error logs',
            'settings.edit'         => 'Can change store settings',
        ];

        // One pass over the roles, so the table below is built in memory
        // rather than with a query per permission.
        $roles = Database::fetchAll(
            'SELECT r.`id`, r.`name`, r.`status`, r.`permissions`,
                    (SELECT COUNT(*) FROM `admins` a
                      WHERE a.`role_id` = r.`id` AND a.`status` = \'active\') AS active_admins
             FROM `admin_roles` r ORDER BY r.`name`'
        );

        $admins = Database::fetchAll(
            'SELECT a.`id`, a.`name`, a.`username`, a.`role_id`
             FROM `admins` a
             WHERE a.`status` = \'active\'
             ORDER BY a.`name`'
        );

        $adminsByRole = [];
        foreach ($admins as $row) {
            $adminsByRole[(int) $row['role_id']][] = $row;
        }

        $holders = [];
        $superRoleIds = [];
        foreach ($roles as $role) {
            $roleId = (int) $role['id'];
            $perms  = json_decode_safe((string) $role['permissions'], []);
            $isWild = in_array('*', $perms, true);
            if ($isWild) {
                $superRoleIds[] = $roleId;
            }

            foreach (array_keys($watched) as $permission) {
                if (!$isWild && !in_array($permission, $perms, true)) {
                    continue;
                }
                $holders[$permission][] = [
                    'role'     => (string) $role['name'],
                    'wildcard' => $isWild,
                    'inactive' => $role['status'] !== 'active',
                    'admins'   => $adminsByRole[$roleId] ?? [],
                ];
            }
        }

        $superCount = 0;
        foreach ($superRoleIds as $roleId) {
            $superCount += count($adminsByRole[$roleId] ?? []);
        }
        ?>
        <div class="ad-card" style="margin:0" id="rbac-summary">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Who holds the keys</h2>
                    <div class="ad-card__sub">
                        The permissions that can be used to reach everything else, and every active
                        account that currently has them. Edit them in
                        <a href="<?= e(admin_url('admins/roles.php')) ?>">Admin Users &rsaquo; Roles</a>.
                    </div>
                </div>
                <?= $superCount === 1
                    ? '<span class="sik-status sik-status--amber">1 Super Admin</span>'
                    : '<span class="sik-status sik-status--violet">' . (int) $superCount . ' Super Admins</span>' ?>
            </div>

            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Permission</th>
                                <th>Roles</th>
                                <th>Active admins</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($watched as $permission => $why): ?>
                                <?php $rows = $holders[$permission] ?? []; ?>
                                <tr>
                                    <td>
                                        <span class="ad-cellflex__name ad-mono"><?= e($permission) ?></span>
                                        <span class="ad-cellflex__meta"><?= e($why) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($rows === []): ?>
                                            <span class="sik-status sik-status--green">Nobody</span>
                                        <?php else: ?>
                                            <?php foreach ($rows as $row): ?>
                                                <div style="margin-bottom:4px">
                                                    <?= e($row['role']) ?>
                                                    <?php if ($row['wildcard']): ?>
                                                        <span class="sik-status sik-status--violet">via *</span>
                                                    <?php endif; ?>
                                                    <?php if ($row['inactive']): ?>
                                                        <span class="ad-muted">(role inactive)</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $names = [];
                                        foreach ($rows as $row) {
                                            foreach ($row['admins'] as $person) {
                                                $names[(int) $person['id']] = (string) $person['name'];
                                            }
                                        }
                                        ?>
                                        <?php if ($names === []): ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php else: ?>
                                            <?php foreach ($names as $adminId => $name): ?>
                                                <div style="margin-bottom:4px">
                                                    <?php if (admin_can('admins.view')): ?>
                                                        <a href="<?= e(admin_url('admins/edit.php?id=' . (int) $adminId)) ?>"><?= e($name) ?></a>
                                                    <?php else: ?>
                                                        <?= e($name) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="ad-card__foot">
                <span class="ad-muted" style="font-size:12.5px">
                    <?php if ($superCount <= 1): ?>
                        Only one Super Admin can sign in. If that account is lost, nobody can manage roles,
                        settings or admin users &mdash; promote a second one.
                    <?php else: ?>
                        Every account above can be used to reach the rest of the panel. Fewer names on these
                        rows is the safer shape; an admin who no longer needs one should lose it.
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    },
];
