<?php
/**
 * ShopInnKart - Migration: bot protection and per-device API tokens.
 *
 * Adds what includes/bot-protection.php and includes/api-tokens.php need:
 *   - `api_tokens`: one row per device a mobile app has signed in on. The
 *     token itself is never stored - only a selector (to find the row) and a
 *     SHA-256 of the secret half (to verify it), the same split
 *     remember_tokens already uses.
 *   - the sec_bot_* settings behind Admin > Security > Settings (which CAPTCHA
 *     provider, its keys, and how hard the no-provider fallback leans).
 *   - the sec_api_* settings (whether tokens may be issued at all, for how
 *     long, and how many per account).
 *
 * Idempotent. Never DROPs. Safe on a live database: one new table and
 * configuration rows, no existing data touched.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_security_bots.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_bots_run(): array
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
    // Per-device API tokens
    // -----------------------------------------------------------------------
    $run('+ table api_tokens', static function (): ?string {
        if (mig_table_exists('api_tokens')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `api_tokens` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_type`      VARCHAR(20) NOT NULL DEFAULT 'customer' COMMENT 'customer | admin',
                `user_id`        INT UNSIGNED NOT NULL,
                `selector`       CHAR(16) NOT NULL COMMENT 'Public half - finds the row without a lookup on a secret',
                `token_hash`     CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret half; the token itself is never stored',
                `auth_version`   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Stamped at issue; a password change moves the account on and retires the token',
                `device_name`    VARCHAR(100) NOT NULL DEFAULT 'Mobile device',
                `platform`       VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'android | ios | web | other',
                `expires_at`     DATETIME NOT NULL,
                `last_used_at`   DATETIME NULL,
                `last_ip`        VARCHAR(45) NULL,
                `created_ip`     VARCHAR(45) NULL,
                `user_agent`     VARCHAR(255) NULL,
                `revoked_at`     DATETIME NULL,
                `revoked_reason` VARCHAR(60) NULL,
                `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_api_tokens_selector` (`selector`),
                KEY `idx_api_tokens_account` (`user_type`, `user_id`, `revoked_at`),
                KEY `idx_api_tokens_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table api_tokens';
    });

    // -----------------------------------------------------------------------
    // Settings. Every one is created with the value that keeps today's
    // behaviour: no CAPTCHA provider, the built-in fallback on, and tokens
    // issuable for customers only.
    // -----------------------------------------------------------------------
    $settings = [
        // Bot protection
        ['sec_bot_mode',          'adaptive', 'text',    'off | adaptive | always'],
        ['sec_bot_provider',      '',         'text',    'empty = built-in fallback only'],
        ['sec_bot_site_key',      '',         'text',    'public half, printed in the page'],
        ['sec_bot_secret',        '',         'text',    'encrypted at rest by secret_encrypt()'],
        ['sec_bot_score',         '0.5',      'text',    'reCAPTCHA v3 score floor'],
        ['sec_bot_timeout',       '4',        'number',  'seconds to wait for the provider'],
        ['sec_bot_min_seconds',   '3',        'number',  'a human cannot fill a form faster than this'],
        ['sec_bot_failures',      '3',        'number',  'failed attempts before a challenge appears'],
        // 0, not a number: bot_burst() reads 0 as "use the per-form defaults"
        // (8 for a contact form, 20 for a sign-in), which is what the card
        // promises the owner. Seeding a number here would silently flatten
        // every form to that one value and leave the per-form table dead.
        ['sec_bot_burst',         '0',        'number',  '0 = the per-form defaults; any other value overrides them'],
        ['sec_bot_fail_open',     '1',        'boolean', 'let people through when the provider is down'],
        // API tokens
        ['sec_api_tokens_enabled', '1',  'boolean', 'may /api/auth/token.php issue tokens at all'],
        ['sec_api_tokens_admin',   '0',  'boolean', 'may admin accounts hold API tokens'],
        ['sec_api_token_days',     '30', 'number',  'token lifetime in days'],
        ['sec_api_token_max',      '10', 'number',  'active tokens per account'],
    ];

    foreach ($settings as [$key, $value, $type, $why]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, 'security', $type);
            return '+ setting ' . $key;
        });
    }

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_bots_run();
    echo "Bot protection & API tokens migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
