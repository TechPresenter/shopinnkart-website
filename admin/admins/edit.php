<?php
/**
 * ShopInnKart Admin - Edit an admin user.
 *
 * The password column is never read into this page; it can only be replaced.
 * Two refusals are enforced here as well as in delete.php, because demoting
 * the last Super Admin locks everyone out just as thoroughly as deleting them.
 *
 * Nobody manages up. admins.edit used to be the whole panel: its holder could
 * open a Super Admin, set a password, and sign back in with "*". So the target
 * has to be somebody this actor already outranks (admin_can_manage_admin()),
 * the role offered has to be one they could grant, and the four changes that
 * hand over an account - password, email, role, status, unlock - each need the
 * actor's own password and tell the account holder afterwards.
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

// The hierarchy check runs on GET as well as POST: opening the form for an
// account you cannot manage would still show its email and lockout state, and
// a hidden form is not an authorisation check.
if (!admin_can_manage_admin($stored)) {
    admin_deny(
        'You cannot manage "' . $stored['name'] . '". That account\'s role holds permissions yours does not, '
            . 'so only an admin with at least their access can change it.',
        ['target_admin_id' => $targetId, 'target_role_id' => (int) $stored['role_id']]
    );
}

$account        = $stored;
$errors         = [];
$roleOptions    = admins_role_options((int) $stored['role_id']);
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

    // Blank means "keep the current password", so it is only checked when
    // filled. The account type decides the minimum: an admin password is held
    // to sec_password_min_admin (12) rather than the shopper floor, because
    // this hash is the one that opens the whole panel.
    if ($password !== '') {
        $v->password('password', null, 'admin', $submitted['email'])
          ->matches('password_confirmation', 'password', 'The two passwords do not match.');
    }

    $newRoleId   = $submitted['role_id'];
    $newStatus   = $submitted['status'];
    $roleChange  = $newRoleId !== (int) $stored['role_id'];
    $emailChange = $submitted['email'] !== mb_strtolower((string) $stored['email']);
    $unlock      = input_bool('unlock');

    if ($newRoleId > 0 && $roleChange && !$canAssignSuper && admins_role_is_super($newRoleId)) {
        $v->rule('role_id', false, 'Only a Super Admin can grant a role with unrestricted access.');
    }

    // You cannot promote anyone - yourself included - into a role you could
    // not have built. Without this, "assign an existing role" walks straight
    // past the role editor's own "grant only what you hold" rule.
    if ($newRoleId > 0 && $roleChange && !admin_can_manage_role($newRoleId)) {
        $beyond = admin_permissions_beyond(admin_role_permissions($newRoleId));
        $v->rule('role_id', false, 'That role grants permissions you do not hold yourself ('
            . implode(', ', array_slice($beyond, 0, 4)) . (count($beyond) > 4 ? '…' : '')
            . '), so you cannot assign it.');
    }

    // Changing your own role is how a self-promotion looks from the inside,
    // and there is never a good reason to need it: ask another admin.
    if ($isSelf && $roleChange) {
        $v->rule('role_id', false, 'You cannot change your own role. Ask another admin with at least your access to do it.');
    }

    // Signing yourself out of your own panel is never a save worth accepting.
    if ($isSelf && $newStatus !== 'active') {
        $v->rule('status', false, 'You cannot deactivate the account you are signed in with. Ask another Super Admin to do it.');
    }

    // Step-up: taking over an account needs the actor's own password, not just
    // their cookie. Editing a name or a phone number does not.
    $sensitive = [];
    if ($password !== '')                        { $sensitive[] = 'set a new password'; }
    if ($emailChange)                            { $sensitive[] = 'change the email address'; }
    if ($roleChange)                             { $sensitive[] = 'change the role'; }
    if ($newStatus !== (string) $stored['status']) { $sensitive[] = 'change the account status'; }
    if ($unlock)                                 { $sensitive[] = 'clear the lockout'; }

    if ($sensitive !== [] && !admin_reauth_ok()) {
        $v->rule('reauth_password', false, admin_reauth_error(implode(', ', $sensitive)));
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
            $data['password']      = password_hash_app($password);
            $data['failed_logins'] = 0;
            $data['locked_until']  = null;
        } elseif ($unlock) {
            $data['failed_logins'] = 0;
            $data['locked_until']  = null;
        }

        Database::update('admins', $data, '`id` = :id', ['id' => $targetId]);

        // Writing a new hash is only half of a password reset. The sessions
        // the account already has open keep working - which is exactly the
        // intruder an admin is trying to evict when they reset a colleague's
        // password. Moving the account to a new session generation ends every
        // session that is not holding the new number.
        //
        // auth_after_password_change() also re-stamps the ACTING session with
        // that number, which is right when an admin changes their own password
        // and wrong when they reset somebody else's - it would sign the actor
        // out on their next request. So the actor's own stamp is put back.
        if ($password !== '') {
            $actorStamp = $_SESSION[ADMIN_SESSION_KEY]['auth_version'] ?? null;
            // "changed" only when they are changing their own; otherwise the
            // notice that lands in the target's inbox would claim the change
            // came from their own account, which is the one thing it did not.
            auth_after_password_change('admin', $stored, $isSelf ? 'changed' : 'admin');
            if (!$isSelf && is_int($actorStamp) && isset($_SESSION[ADMIN_SESSION_KEY])) {
                $_SESSION[ADMIN_SESSION_KEY]['auth_version'] = $actorStamp;
            }

            security_event('auth.sessions_revoked', 'high', [
                'reason'          => $isSelf ? 'own password changed' : 'password set by another admin',
                'target_admin_id' => $targetId,
                'target_username' => (string) $stored['username'],
                'by_admin_id'     => (int) $admin['id'],
            ], $targetId, 'admin');
        }

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

        // Anything on the sensitive list changed who can sign in as this
        // account, so it goes to the append-only trail and to the account
        // holder - a takeover that nobody is told about is the dangerous kind.
        if ($sensitive !== []) {
            $context = [
                'target_admin_id' => $targetId,
                'target_username' => (string) $stored['username'],
                'changes'         => $sensitive,
            ];
            if ($password !== '' || $emailChange) {
                security_event('admin.credentials_changed', 'high', $context, (int) $admin['id'], 'admin');
            }
            if ($roleChange || $newStatus !== (string) $stored['status']) {
                security_event('rbac.role_changed', 'high', $context, (int) $admin['id'], 'admin');
            }
            if ($unlock && $password === '') {
                security_event('admin.unlocked', 'medium', $context, (int) $admin['id'], 'admin');
            }
            admin_notify_account_change(
                ['id' => $targetId, 'name' => $submitted['name'], 'email' => (string) $stored['email']],
                implode(', ', $sensitive)
            );
            // A changed address is told as well, so a redirected account is
            // visible from both sides.
            if ($emailChange) {
                admin_notify_account_change(
                    ['id' => $targetId, 'name' => $submitted['name'], 'email' => $submitted['email']],
                    'your sign-in email address was changed to this one'
                );
            }
        }

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
