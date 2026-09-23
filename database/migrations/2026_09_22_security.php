<?php
/**
 * ShopInnKart - Migration: security module.
 *
 * Its own permission pair, so an admin who may change store settings is not
 * automatically the one who may switch off 2FA or change the login address.
 * Super Admin holds "*" and needs nothing; other roles get them explicitly in
 * Admin > System > Roles.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_22_security.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_run(): array
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

    $run('+ security permissions', static function (): ?string {
        $added = 0;
        foreach ([['view', 'View Security Dashboard & Settings', 900], ['edit', 'Change Security Settings', 901]] as [$action, $label, $sort]) {
            if (Database::exists('admin_permissions', '`module` = :m AND `action` = :a', ['m' => 'security', 'a' => $action])) {
                continue;
            }
            Database::insert('admin_permissions', [
                'module' => 'security', 'action' => $action, 'label' => $label, 'sort_order' => $sort,
            ]);
            $added++;
        }
        return $added > 0 ? '+ security permissions (' . $added . ')' : null;
    });

    $run('+ table rate_limits', static function (): ?string {
        if (mig_table_exists('rate_limits')) {
            // An older, unused shape may exist on some installs; only add what is missing.
            return null;
        }
        Database::query(
            "CREATE TABLE `rate_limits` (
                `bucket`       VARCHAR(40) NOT NULL COMMENT 'What is being limited, e.g. login.ip',
                `key_hash`     CHAR(40) NOT NULL COMMENT 'sha1 of the key (IP, account, ...) - never the raw value',
                `window_start` INT UNSIGNED NOT NULL COMMENT 'Unix time the fixed window began',
                `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
                `expires_at`   DATETIME NOT NULL,
                PRIMARY KEY (`bucket`, `key_hash`, `window_start`),
                KEY `idx_rate_limits_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table rate_limits';
    });

    $run('+ table security_events', static function (): ?string {
        if (mig_table_exists('security_events')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `security_events` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type`        VARCHAR(60) NOT NULL COMMENT 'auth.login_failed, rbac.role_changed, ...',
                `severity`    ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'info',
                `user_type`   VARCHAR(20) NULL COMMENT 'admin | customer | api',
                `user_id`     INT UNSIGNED NULL,
                `ip_address`  VARCHAR(45) NULL,
                `user_agent`  VARCHAR(255) NULL,
                `path`        VARCHAR(255) NULL,
                `context`     TEXT NULL COMMENT 'JSON, secrets masked',
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_security_events_type` (`type`, `created_at`),
                KEY `idx_security_events_ip` (`ip_address`, `created_at`),
                KEY `idx_security_events_severity` (`severity`, `created_at`),
                KEY `idx_security_events_user` (`user_type`, `user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table security_events';
    });

    $run('+ setting admin_login_slug', static function (): ?string {
        if (Database::exists('settings', '`setting_key` = :k', ['k' => 'admin_login_slug'])) {
            return null;
        }
        // Empty = the feature is off, which is how every existing install
        // behaves today. The owner switches it on from Security Settings.
        setting_save('admin_login_slug', '', 'security', 'text');
        return '+ setting admin_login_slug';
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_run();
    echo "Security migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
