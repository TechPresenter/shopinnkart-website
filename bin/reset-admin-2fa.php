<?php
/**
 * ShopInnKart - Reset an admin's second factor (CLI only).
 *
 *   php bin/reset-admin-2fa.php                     list who is enrolled
 *   php bin/reset-admin-2fa.php owner@example.com   take 2FA off that account
 *
 * The last resort, for the case the browser cannot solve: a sole Super Admin
 * who has lost both the phone and the ten backup codes. Everything else -
 * backup codes, and another Super Admin pressing "Reset" in
 * Admin > Security > Settings - comes first.
 *
 * It needs shell access to the server, which is the point: anybody with that
 * already owns the store and could reset the password instead.
 *
 * The account keeps its password and its role. All this removes is the second
 * factor, the backup codes and the trusted browsers - and if the account's
 * role still requires 2FA, the next sign-in walks it through setting a new one
 * up. Every run is written to the security log and emailed to the account.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/init.php';

$identifier = trim((string) ($argv[1] ?? ''));

if ($identifier === '' || in_array($identifier, ['-h', '--help'], true)) {
    echo "Two-step sign in, by admin:\n\n";

    foreach (Database::fetchAll(
        'SELECT a.`email`, a.`name`, a.`status`, a.`totp_enabled_at`, r.`name` AS role_name, r.`require_2fa`
           FROM `admins` a INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
          ORDER BY a.`name`'
    ) as $row) {
        printf(
            "  %-34s %-18s %-10s %s%s\n",
            $row['email'],
            $row['role_name'],
            $row['status'],
            $row['totp_enabled_at'] !== null ? 'enrolled' : 'not enrolled',
            (int) $row['require_2fa'] === 1 ? ' (role requires it)' : ''
        );
    }

    echo "\nUsage: php bin/reset-admin-2fa.php <email or username>\n";
    exit(0);
}

$admin = Database::fetch(
    'SELECT a.*, r.`name` AS role_name, r.`permissions` AS role_permissions
       FROM `admins` a INNER JOIN `admin_roles` r ON r.`id` = a.`role_id`
      WHERE a.`email` = :id OR a.`username` = :id2 LIMIT 1',
    ['id' => $identifier, 'id2' => $identifier]
);

if ($admin === null) {
    fwrite(STDERR, "No admin found for \"" . $identifier . "\".\n");
    exit(1);
}

if (!mfa_totp_enabled($admin)) {
    echo $admin['email'] . " is not using two-step sign in; there is nothing to reset.\n";
    exit(0);
}

mfa_disable('admin', $admin, 'cli');

security_event('mfa.reset_by_cli', 'critical', [
    'admin' => mask_email((string) $admin['email']),
], (int) $admin['id'], 'admin');
log_activity('security.2fa_reset', 'admin', (int) $admin['id'],
    'Reset two-step sign in for ' . $admin['name'] . ' from the command line');

echo "Two-step sign in has been removed from " . $admin['email'] . ".\n";
echo "Their password is unchanged. Backup codes and trusted browsers are gone.\n";

if (mfa_required('admin', $admin)) {
    echo "Their role still requires it, so the next sign-in will walk them through setting it up again.\n";
}
