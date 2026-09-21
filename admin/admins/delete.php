<?php
/**
 * ShopInnKart Admin - Delete an admin user.
 *
 * Two accounts are refused outright: your own, and the last Super Admin who
 * can still sign in. Both refusals are decided here rather than by the list
 * screen hiding a button, because a hidden button is not a permission check.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('admins.delete');   // POST + CSRF + permission

require_once __DIR__ . '/_helpers.php';

$targetId = input_int('id');

if ($targetId <= 0) {
    flash('error', 'No admin user was selected.');
    redirect(admin_url('admins/'));
}

// Refusal 1: deleting yourself would end your own session mid-request and
// there is no undo path back into the panel.
if ($targetId === (int) $admin['id']) {
    flash('error', 'You cannot delete the account you are signed in with. Ask another Super Admin to remove it for you.');
    redirect(admin_url('admins/'));
}

$account = Database::fetch(
    'SELECT a.`id`, a.`name`, a.`username`, a.`avatar`, a.`role_id`, r.`name` AS role_name
     FROM `admins` a
     INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
     WHERE a.`id` = :id LIMIT 1',
    ['id' => $targetId]
);

if ($account === null) {
    flash('error', 'That admin user no longer exists.');
    redirect(admin_url('admins/'));
}

// Refusal 2: the wildcard role is the only way back into settings, roles and
// admin users. Removing the last holder locks the panel for everyone.
if (admins_is_last_active_super($targetId)) {
    flash(
        'error',
        '"' . $account['name'] . '" is the last Super Admin who can sign in. '
            . 'Give another active account the Super Admin role first — deleting this one would leave '
            . 'nobody able to manage roles, settings or admin users.'
    );
    redirect(admin_url('admins/'));
}

delete_upload($account['avatar']);

// activity_logs keeps admin_name as plain text with no foreign key, so the
// audit trail this person left behind survives the delete.
Database::delete('admins', '`id` = :id', ['id' => $targetId]);

log_activity(
    'admin.deleted',
    'admin',
    $targetId,
    'Deleted admin "' . $account['name'] . '" (@' . $account['username'] . ', ' . $account['role_name'] . ')'
);
admin_after_write();

flash('success', 'Admin "' . $account['name'] . '" deleted. Their entries in the activity log were kept.');
redirect(admin_url('admins/'));
