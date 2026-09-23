<?php
/**
 * ShopInnKart - Migration: authentication hardening (security audit, auth cluster).
 *
 * Adds what the new auth code needs and nothing else:
 *   - users.auth_version / admins.auth_version: the session generation. A
 *     password change, a reset, an admin-set password or "sign out everywhere"
 *     moves it on, and every session still holding the old number is over.
 *   - remember_tokens: "keep me signed in" as its own rotated, hashed
 *     credential instead of a seven-day session cookie.
 *   - the sec_* settings behind Admin > Security > Settings (canonical URL and
 *     host allow-list, session timeouts, login throttles, password policy,
 *     trusted proxies).
 *   - two notification templates: the password-changed notice and the
 *     "somebody is guessing at your password" alert that replaced the account
 *     lockout.
 *
 * Idempotent. Never DROPs. Safe to run on a live database: it adds columns,
 * one table and configuration rows, and changes no existing data.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_security_auth.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_auth_run(): array
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
    // Session generation counters
    // -----------------------------------------------------------------------
    $run('+ users.auth_version', static fn (): ?string => mig_add_column(
        'users',
        'auth_version',
        "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Session generation; every other session dies when this moves' AFTER `password`"
    ));

    $run('+ admins.auth_version', static fn (): ?string => mig_add_column(
        'admins',
        'auth_version',
        "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Session generation; every other session dies when this moves' AFTER `password`"
    ));

    // -----------------------------------------------------------------------
    // "Keep me signed in"
    // -----------------------------------------------------------------------
    $run('+ table remember_tokens', static function (): ?string {
        if (mig_table_exists('remember_tokens')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `remember_tokens` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`        INT UNSIGNED NOT NULL,
                `selector`       CHAR(18) NOT NULL COMMENT 'Lookup half of the cookie - not a secret',
                `validator_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret half; the secret itself is never stored',
                `auth_version`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Generation the token was issued for',
                `expires_at`     DATETIME NOT NULL,
                `ip_address`     VARCHAR(45) NULL,
                `user_agent`     VARCHAR(255) NULL,
                `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_remember_selector` (`selector`),
                KEY `idx_remember_user` (`user_id`),
                KEY `idx_remember_expiry` (`expires_at`),
                CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table remember_tokens';
    });

    // -----------------------------------------------------------------------
    // Settings. Every default here is the behaviour the code already assumes,
    // so writing them changes nothing until the owner edits one.
    // -----------------------------------------------------------------------
    $settings = [
        // Canonical address and Host allow-list (V1, V52)
        ['sec_canonical_url', '', 'text'],
        ['sec_host_allowlist', '', 'text'],
        ['sec_host_block', '0', 'boolean'],
        // Sessions (V6, V54)
        ['sec_session_idle_admin', '30', 'number'],
        ['sec_session_absolute_admin', '12', 'number'],
        ['sec_session_idle_customer', '240', 'number'],
        ['sec_session_absolute_customer', '30', 'number'],
        ['sec_remember_enabled', '1', 'boolean'],
        ['sec_remember_days', '30', 'number'],
        // Login throttles (V3, V4, V8, V67, V68)
        ['sec_login_ip_max', '20', 'number'],
        ['sec_login_ip_daily', '100', 'number'],
        ['sec_login_account_max', '5', 'number'],
        ['sec_login_global_max', '400', 'number'],
        // Password policy (V11)
        ['sec_password_min', '10', 'number'],
        ['sec_password_min_admin', '12', 'number'],
        // Trusted proxies (V72)
        ['sec_trusted_proxies', '', 'text'],
    ];

    $run('+ security settings', static function () use ($settings): ?string {
        $added = 0;
        foreach ($settings as [$key, $value, $type]) {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                continue;
            }
            setting_save($key, $value, 'security', $type);
            $added++;
        }
        return $added > 0 ? '+ security settings (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    // Account-security emails
    // -----------------------------------------------------------------------
    $run('+ template password_changed', static fn (): ?string => migration_security_auth_template(
        'password_changed',
        'Password Changed',
        'Tells the account owner that their password was changed, so a takeover is noticed.',
        'Your {{store_name}} password was changed',
        '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
        . '<p style="margin:0 0 16px 0">The password on your <strong>{{store_name}}</strong> account '
        . '(<strong>{{customer_email}}</strong>) was {{change_type}} on {{when}}.</p>'
        . '<p style="margin:0 0 16px 0;font-size:14px">Everything else was signed out at the same time, so you will '
        . 'be asked to sign in again on your other devices.</p>'
        . '<p style="margin:16px 0 0 0;font-size:14px"><strong>If this was not you</strong>, reset your password now '
        . 'and then contact us:<br><span style="color:#6b7280;font-size:12px;word-break:break-all">{{reset_url}}</span></p>'
        . '<p style="margin:24px 0 0 0">Warm regards,<br><strong>Team {{store_name}}</strong></p>',
        "Hi {{customer_name}},\n\nThe password on your {{store_name}} account ({{customer_email}}) was {{change_type}} on {{when}}.\n\n"
        . "Everything else was signed out at the same time, so you will be asked to sign in again on your other devices.\n\n"
        . "If this was not you, reset your password now and then contact us:\n{{reset_url}}\n\nWarm regards,\nTeam {{store_name}}",
        '{{customer_name}}, {{customer_email}}, {{change_type}}, {{when}}, {{request_ip}}, {{reset_url}}, {{support_url}}, {{store_name}}'
    ));

    $run('+ template login_blocked', static fn (): ?string => migration_security_auth_template(
        'login_blocked',
        'Sign-in Attempts Blocked',
        'Warns the account owner that somebody is guessing at their password. Replaces the old account lockout.',
        'Unusual sign-in attempts on your {{store_name}} account',
        '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
        . '<p style="margin:0 0 16px 0">We blocked repeated sign-in attempts on your <strong>{{store_name}}</strong> '
        . 'account (<strong>{{customer_email}}</strong>) around {{when}}. Your account is <strong>not</strong> locked '
        . '&mdash; you can still sign in normally.</p>'
        . '<p style="margin:0 0 16px 0;font-size:14px">If that was you and you have forgotten your password, set a new '
        . 'one here:<br><span style="color:#6b7280;font-size:12px;word-break:break-all">{{reset_url}}</span></p>'
        . '<p style="margin:16px 0 0 0;font-size:13px;color:#6b7280">If it was not you, there is nothing you need to do, '
        . 'but a password you do not use anywhere else is the best protection.</p>'
        . '<p style="margin:24px 0 0 0">Warm regards,<br><strong>Team {{store_name}}</strong></p>',
        "Hi {{customer_name}},\n\nWe blocked repeated sign-in attempts on your {{store_name}} account ({{customer_email}}) around {{when}}. "
        . "Your account is NOT locked - you can still sign in normally.\n\nIf that was you and you have forgotten your password, set a new one here:\n{{reset_url}}\n\n"
        . "If it was not you, there is nothing you need to do, but a password you do not use anywhere else is the best protection.\n\nWarm regards,\nTeam {{store_name}}",
        '{{customer_name}}, {{customer_email}}, {{attempts}}, {{when}}, {{request_ip}}, {{reset_url}}, {{store_name}}'
    ));

    return ['applied' => $applied, 'errors' => $errors];
}

/** Insert one notification template if the store does not already have it. */
function migration_security_auth_template(
    string $key,
    string $name,
    string $description,
    string $subject,
    string $body,
    string $bodyText,
    string $variables
): ?string {
    if (Database::exists('notification_templates', '`template_key` = :k AND `channel` = :c', ['k' => $key, 'c' => 'email'])) {
        return null;
    }

    Database::insert('notification_templates', [
        'template_key'   => $key,
        'name'           => $name,
        'description'    => $description,
        'channel'        => 'email',
        'recipient_type' => 'customer',
        'subject'        => $subject,
        'body'           => $body,
        'body_text'      => $bodyText,
        'variables'      => $variables,
        'status'         => 'active',
        'is_system'      => 1,
    ]);

    return '+ template ' . $key;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_auth_run();
    echo "Security migration (auth)\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
