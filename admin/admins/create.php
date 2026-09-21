<?php
/**
 * ShopInnKart Admin - Create an admin user.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('admins.create');

require_once __DIR__ . '/_helpers.php';

$account = [
    'id'       => 0,
    'name'     => '',
    'username' => '',
    'email'    => '',
    'phone'    => '',
    'avatar'   => null,
    'role_id'  => '',
    'status'   => 'active',
];
$errors = [];

$roleOptions    = admins_role_options();
$canAssignSuper = admin_is_super();

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
    $account  = array_merge($account, $submitted);

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
      ->unique('username', 'admins', 'username')
      ->required('email')->email('email')->max('email', 190)
      ->unique('email', 'admins', 'email')
      ->phone('phone')
      ->required('role_id')->integer('role_id')->exists('role_id', 'admin_roles')
      ->required('status')->in('status', array_keys(ADMIN_ACCOUNT_STATUSES))
      ->required('password')->password('password')
      ->matches('password_confirmation', 'password', 'The two passwords do not match.');

    // Handing out the wildcard role is an escalation, so it stays with the
    // people who already hold it.
    if ($submitted['role_id'] > 0 && !$canAssignSuper && admins_role_is_super($submitted['role_id'])) {
        $v->rule('role_id', false, 'Only a Super Admin can grant a role with unrestricted access.');
    }

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $avatar = admin_handle_image('avatar', 'admins');
        $phone  = normalize_phone($submitted['phone']);

        $newId = Database::insert('admins', [
            'role_id'  => $submitted['role_id'],
            'name'     => $submitted['name'],
            'username' => $submitted['username'],
            'email'    => $submitted['email'],
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'phone'    => $phone,
            'avatar'   => $avatar,
            'status'   => $submitted['status'],
        ]);

        $roleName = (string) Database::fetchColumn(
            'SELECT `name` FROM `admin_roles` WHERE `id` = :id',
            ['id' => $submitted['role_id']]
        );

        log_activity(
            'admin.created',
            'admin',
            $newId,
            'Created admin "' . $submitted['name'] . '" (@' . $submitted['username'] . ') as ' . $roleName
        );
        admin_after_write();

        flash('success', 'Admin "' . $submitted['name'] . '" created. Share the password securely and ask them to change it.');
        redirect(admin_url('admins/edit.php?id=' . $newId));
    }
}

$isEdit      = false;
$isSelf      = false;
$isLastSuper = false;
$formAction  = admin_url('admins/create.php');

$pageTitle    = 'Add Admin';
$pageSubtitle = 'A new sign-in for this panel. The role decides what they can reach.';
$breadcrumbs  = [
    ['label' => 'Dashboard',   'url' => admin_url('dashboard.php')],
    ['label' => 'Admin Users', 'url' => admin_url('admins/')],
    ['label' => 'Add Admin'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
