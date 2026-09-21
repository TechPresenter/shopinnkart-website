<?php
/**
 * ShopInnKart Admin - Edit an admin user.
 *
 * The password column is never read into this page; it can only be replaced.
 * Two refusals are enforced here as well as in delete.php, because demoting
 * the last Super Admin locks everyone out just as thoroughly as deleting them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('admins.edit');

require_once __DIR__ . '/_helpers.php';

$targetId = input_int('id');

if ($targetId <= 0) {
    flash('error', 'No admin user was selected.');
    redirect(admin_url('admins/'));
}

$stored = Database::fetch(
    'SELECT `id`, `role_id`, `name`, `username`, `email`, `phone`, `avatar`, `status`,
            `locked_until`, `last_login_at`, `last_login_ip`, `created_at`
     FROM `admins` WHERE `id` = :id LIMIT 1',
    ['id' => $targetId]
);

if ($stored === null) {
    flash('error', 'That admin user no longer exists.');
    redirect(admin_url('admins/'));
}

$account        = $stored;
$errors         = [];
$roleOptions    = admins_role_options();
$canAssignSuper = admin_is_super();
$isSelf         = $targetId === (int) $admin['id'];
$isLastSuper    = admins_is_last_active_super($targetId);
$formAction     = admin_url('admins/edit.php?id=' . $targetId);

if (is_post()) {
    csrf_require();

    $submitted = [
        'name'     => (string) input('name', ''),
        'username' => mb_strtolower((string) input('username', '')),
        'email'    => mb_strtolower((string) input('email', '')),
        'phone'    => (string) input('phone', ''),
        'role_id'  => input_int('role_id'),
        'status'   => (string) input('status', 'active'),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $account  = array_merge($stored, $submitted);

    $v = new Validator(
        array_merge($submitted, [
            'password'              => $password,
            'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
        ]),
        [
            'name'     => 'Full name',
            'username' => 'Username',
            'email'    => 'Email address',
            'phone'    => 'Phone number',
            'role_id'  => 'Role',
            'status'   => 'Status',
        ]
    );

    $v->required('name')->max('name', 150)
      ->required('username')->max('username', 60)
      ->regex('username', '/^[A-Za-z0-9._-]{3,60}$/',
          'Username must be 3–60 characters using only letters, numbers, dot, dash or underscore.')
      ->unique('username', 'admins', 'username', $targetId)
      ->required('email')->email('email')->max('email', 190)
      ->unique('email', 'admins', 'email', $targetId)
      ->phone('phone')
      ->required('role_id')->integer('role_id')->exists('role_id', 'admin_roles')
      ->required('status')->in('status', array_keys(ADMIN_ACCOUNT_STATUSES));

    // Blank means "keep the current password", so it is only checked when filled.
    if ($password !== '') {
        $v->password('password')
          ->matches('password_confirmation', 'password', 'The two passwords do not match.');
    }

    $newRoleId  = $submitted['role_id'];
    $newStatus  = $submitted['status'];
    $roleChange = $newRoleId !== (int) $stored['role_id'];

    if ($newRoleId > 0 && $roleChange && !$canAssignSuper && admins_role_is_super($newRoleId)) {
        $v->rule('role_id', false, 'Only a Super Admin can grant a role with unrestricted access.');
    }

    // Signing yourself out of your own panel is never a save worth accepting.
    if ($isSelf && $newStatus !== 'active') {
        $v->rule('status', false, 'You cannot deactivate the account you are signed in with. Ask another Super Admin to do it.');
    }

    // The last Super Admin may still edit their name or password, but not the
    // two fields that would leave the panel with nobody able to administer it.
    if ($isLastSuper) {
        if ($newStatus !== 'active') {
            $v->rule('status', false, 'This is the last Super Admin who can sign in. Promote a second one before deactivating this account.');
        }
        if (!admins_role_is_super($newRoleId)) {
            $v->rule('role_id', false, 'This is the last Super Admin who can sign in. Give another account the Super Admin role first, then change this one.');
        }
    }

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Nothing was saved. Please correct the highlighted fields.');
    } else {
        if (input_bool('remove_avatar')) {
            delete_upload($stored['avatar']);
            $avatar = null;
        } else {
            $avatar = admin_handle_image('avatar', 'admins', $stored['avatar']);
        }

        $data = [
            'role_id'  => $newRoleId,
            'name'     => $submitted['name'],
            'username' => $submitted['username'],
            'email'    => $submitted['email'],
            'phone'    => normalize_phone($submitted['phone']),
            'avatar'   => $avatar,
            'status'   => $newStatus,
        ];

        if ($password !== '') {
            // A new password with a live lockout still cannot be used, so the
            // counter is cleared at the same time.
            $data['password']      = password_hash($password, PASSWORD_DEFAULT);
            $data['failed_logins'] = 0;
            $data['locked_until']  = null;
        } elseif (input_bool('unlock')) {
            $data['failed_logins'] = 0;
            $data['locked_until']  = null;
        }

        Database::update('admins', $data, '`id` = :id', ['id' => $targetId]);

        $notes = [];
        if ($roleChange) {
            $notes[] = 'role changed to ' . (string) Database::fetchColumn(
                'SELECT `name` FROM `admin_roles` WHERE `id` = :id',
                ['id' => $newRoleId]
            );
        }
        if ($newStatus !== $stored['status']) {
            $notes[] = 'status set to ' . $newStatus;
        }
        if ($password !== '') {
            $notes[] = 'password replaced';
        }

        log_activity(
            'admin.updated',
            'admin',
            $targetId,
            'Updated admin "' . $submitted['name'] . '"' . ($notes !== [] ? ' — ' . implode(', ', $notes) : '')
        );
        admin_after_write();

        flash('success', 'Admin "' . $submitted['name'] . '" updated.');
        redirect(admin_url('admins/'));
    }
}

$isEdit = true;

$pageTitle    = 'Edit ' . (string) $stored['name'];
$pageSubtitle = '@' . $stored['username'] . ' · admin #' . $targetId
    . ' · joined ' . format_date($stored['created_at'], 'd M Y');
$breadcrumbs  = [
    ['label' => 'Dashboard',   'url' => admin_url('dashboard.php')],
    ['label' => 'Admin Users', 'url' => admin_url('admins/')],
    ['label' => (string) $stored['name']],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('admins/')) . '">'
    . icon('arrow-left', 'w-4 h-4') . ' Back to list</a>';

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
