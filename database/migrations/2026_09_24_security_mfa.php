<?php
/**
 * ShopInnKart - Migration: two-factor authentication.
 *
 * Adds what includes/mfa.php and includes/totp.php need, and nothing else:
 *   - totp_secret / totp_enabled_at / totp_last_step on `admins` and `users`
 *     (the secret is stored secret_encrypt'd; totp_last_step is the replay
 *     guard that stops one code signing somebody in twice).
 *   - users.mfa_method: off | totp | email, because a customer may use the
 *     emailed code on its own.
 *   - admin_roles.require_2fa: the per-role requirement.
 *   - auth_backup_codes, auth_trusted_devices, auth_otps.
 *   - the sec_2fa_* settings behind Admin > Security > Settings.
 *   - one notification template, login_otp, for the emailed six-digit code.
 *
 * Idempotent. Never DROPs. Safe on a live database: it adds columns, three
 * tables and configuration rows, changes no existing data, and every default
 * is the behaviour the code already assumes - installing it forces nobody to
 * do anything and locks nobody out.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_security_mfa.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';
require_once __DIR__ . '/2026_09_23_security_auth.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_mfa_run(): array
{
    $applied = [];
    $errors  = [];

    $run = static function (string $label, callable $fn) use (&$applied, &$errors): void {
        try {
            $line = $fn();
            if ($line !== null && $line !== false) {
                $applied[] = is_string($line) ? $line : $label;
            }
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    };

    // -----------------------------------------------------------------------
    // The shared secret, on both kinds of account
    // -----------------------------------------------------------------------
    foreach (['admins', 'users'] as $table) {
        $run('+ ' . $table . '.totp_secret', static fn (): ?string => mig_add_column(
            $table,
            'totp_secret',
            "VARCHAR(255) NULL COMMENT 'Encrypted TOTP shared secret (secret_encrypt); NULL = not enrolled' AFTER `auth_version`"
        ));
        $run('+ ' . $table . '.totp_enabled_at', static fn (): ?string => mig_add_column(
            $table,
            'totp_enabled_at',
            "DATETIME NULL COMMENT 'When the authenticator app was confirmed working' AFTER `totp_secret`"
        ));
        $run('+ ' . $table . '.totp_last_step', static fn (): ?string => mig_add_column(
            $table,
            'totp_last_step',
            "BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Highest TOTP step already spent - replay guard' AFTER `totp_enabled_at`"
        ));
    }

    // Customers may use the emailed code without an authenticator app.
    $run('+ users.mfa_method', static fn (): ?string => mig_add_column(
        'users',
        'mfa_method',
        "VARCHAR(10) NOT NULL DEFAULT 'off' COMMENT 'off | totp | email' AFTER `totp_last_step`"
    ));

    // -----------------------------------------------------------------------
    // The per-role requirement
    // -----------------------------------------------------------------------
    $run('+ admin_roles.require_2fa', static fn (): ?string => mig_add_column(
        'admin_roles',
        'require_2fa',
        "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Admins on this role must use a second factor' AFTER `permissions`"
    ));

    // -----------------------------------------------------------------------
    // Backup codes: ten per account, hashed, single use
    // -----------------------------------------------------------------------
    $run('+ table auth_backup_codes', static function (): ?string {
        if (mig_table_exists('auth_backup_codes')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `auth_backup_codes` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_type`  VARCHAR(10) NOT NULL COMMENT 'admin | customer',
                `user_id`    INT UNSIGNED NOT NULL,
                `code_hash`  CHAR(64) NOT NULL COMMENT 'Keyed SHA-256; the code itself is shown once and never stored',
                `used_at`    DATETIME NULL,
                `used_ip`    VARCHAR(45) NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_backup_owner` (`user_type`, `user_id`, `used_at`),
                KEY `idx_backup_hash` (`code_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table auth_backup_codes';
    });

    // -----------------------------------------------------------------------
    // Browsers that have proved the second factor recently
    // -----------------------------------------------------------------------
    $run('+ table auth_trusted_devices', static function (): ?string {
        if (mig_table_exists('auth_trusted_devices')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `auth_trusted_devices` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_type`      VARCHAR(10) NOT NULL COMMENT 'admin | customer',
                `user_id`        INT UNSIGNED NOT NULL,
                `selector`       CHAR(18) NOT NULL COMMENT 'Lookup half of the cookie - not a secret',
                `validator_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret half',
                `auth_version`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Generation the trust was granted for',
                `label`          VARCHAR(100) NULL COMMENT 'Chrome on Windows - shown in the revoke list',
                `ip_address`     VARCHAR(45) NULL,
                `user_agent`     VARCHAR(255) NULL,
                `expires_at`     DATETIME NOT NULL,
                `last_used_at`   DATETIME NULL,
                `revoked_at`     DATETIME NULL,
                `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_trusted_selector` (`selector`),
                KEY `idx_trusted_owner` (`user_type`, `user_id`, `revoked_at`),
                KEY `idx_trusted_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table auth_trusted_devices';
    });

    // -----------------------------------------------------------------------
    // Emailed one-time codes
    // -----------------------------------------------------------------------
    $run('+ table auth_otps', static function (): ?string {
        if (mig_table_exists('auth_otps')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `auth_otps` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_type`   VARCHAR(10) NOT NULL COMMENT 'admin | customer',
                `user_id`     INT UNSIGNED NOT NULL,
                `purpose`     VARCHAR(20) NOT NULL DEFAULT 'login_2fa',
                `channel`     VARCHAR(10) NOT NULL DEFAULT 'email' COMMENT 'email only - the store has no SMS provider',
                `code_hash`   CHAR(64) NOT NULL COMMENT 'Keyed SHA-256 of row id + code',
                `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `expires_at`  DATETIME NOT NULL,
                `consumed_at` DATETIME NULL,
                `ip_address`  VARCHAR(45) NULL,
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_otp_owner` (`user_type`, `user_id`, `purpose`, `consumed_at`),
                KEY `idx_otp_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table auth_otps';
    });

    // -----------------------------------------------------------------------
    // Settings. 'optional' means nobody is forced and anybody may enrol, which
    // is exactly how the store behaved before this migration.
    // -----------------------------------------------------------------------
    $settings = [
        ['sec_2fa_policy', 'optional', 'text'],
        ['sec_2fa_customers', '1', 'boolean'],
        ['sec_2fa_email_otp', '1', 'boolean'],
        ['sec_2fa_trust_enabled', '1', 'boolean'],
        ['sec_2fa_trust_days', '30', 'number'],
    ];

    $run('+ 2FA settings', static function () use ($settings): ?string {
        $added = 0;
        foreach ($settings as [$key, $value, $type]) {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                continue;
            }
            setting_save($key, $value, 'security', $type);
            $added++;
        }
        return $added > 0 ? '+ 2FA settings (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    // The emailed code
    // -----------------------------------------------------------------------
    $run('+ template login_otp', static fn (): ?string => migration_security_auth_template(
        'login_otp',
        'Sign-in Code',
        'The six-digit code used as the second step of signing in when the customer has chosen email.',
        'Your {{store_name}} sign-in code',
        '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
        . '<p style="margin:0 0 8px 0">Use this code to finish signing in:</p>'
        . '<p style="margin:0 0 16px 0;font-size:30px;letter-spacing:8px;font-weight:700;'
        . 'font-family:Consolas,Menlo,monospace">{{otp_code}}</p>'
        . '<p style="margin:0 0 16px 0;font-size:14px">It expires in {{minutes}} minutes and can be used once.</p>'
        . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280"><strong>If you were not signing in</strong>, '
        . 'somebody has your password. Change it now &mdash; the code alone does not let them in.</p>'
        . '<p style="margin:16px 0 0 0;font-size:12px;color:#6b7280">Requested from {{request_ip}} at {{when}}. '
        . 'We will never ask you for this code by phone, chat or email.</p>'
        . '<p style="margin:24px 0 0 0">Warm regards,<br><strong>Team {{store_name}}</strong></p>',
        "Hi {{customer_name}},\n\nUse this code to finish signing in: {{otp_code}}\n\n"
        . "It expires in {{minutes}} minutes and can be used once.\n\n"
        . "If you were not signing in, somebody has your password. Change it now - the code alone does not let them in.\n\n"
        . "Requested from {{request_ip}} at {{when}}. We will never ask you for this code by phone, chat or email.\n\n"
        . "Warm regards,\nTeam {{store_name}}",
        '{{customer_name}}, {{customer_email}}, {{otp_code}}, {{minutes}}, {{request_ip}}, {{when}}, {{store_name}}'
    ));

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_mfa_run();
    echo "Security migration (two-factor authentication)\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
