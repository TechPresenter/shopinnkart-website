<?php
/**
 * ShopInnKart - Migration: platform security settings.
 *
 * Adds the switches that includes/security-headers.php reads, and marks an
 * already-installed site as installed so install.php can never run again.
 *
 * Defaults are deliberately the safe-but-quiet ones: HTTPS in 'auto' (send
 * HSTS once we are on TLS, but never redirect on a site whose certificate may
 * not be finished), HSTS at five minutes rather than a year, and the CSP in
 * report-only. The owner raises each one from Admin > Security > Settings once
 * they have seen the site work.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_security_platform.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/init.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_platform_run(): array
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

    $run('+ security header settings', static function (): ?string {
        $defaults = [
            // 'auto' | '1' always redirect | '0' never
            ['sec_force_https', 'auto', 'text'],
            // Seconds. Start small: an HSTS promise cannot be withdrawn from a
            // browser that has already cached it.
            ['sec_hsts_max_age', '300', 'number'],
            ['sec_hsts_subdomains', '0', 'boolean'],
            // 'report-only' | 'enforce' | 'off'
            ['sec_csp_mode', 'report-only', 'text'],
            ['sec_csp_report_uri', '', 'text'],
        ];

        $added = 0;
        foreach ($defaults as [$key, $value, $type]) {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                continue;
            }
            setting_save($key, $value, 'security', $type);
            $added++;
        }

        return $added > 0 ? '+ security header settings (' . $added . ')' : null;
    });

    $run('+ installer lock', static function (): ?string {
        $lock = STORAGE_PATH . '/installed.lock';
        if (is_file($lock)) {
            return null;
        }
        // Reaching a migration at all means the database is configured and
        // populated, which is the definition of "installed". The lock file was
        // only ever written by install.php step 4, so the documented deploy
        // (import the dump, hand-write db.local.php) left every production
        // site with an armed installer. install.php checks the database state
        // too now; this is the cheap belt to that pair of braces.
        if (!is_dir(STORAGE_PATH)) {
            return null;
        }
        $written = @file_put_contents($lock, json_encode([
            'installed_at' => date('c'),
            'php'          => PHP_VERSION,
            'note'         => 'Written by 2026_09_23_security_platform.php: this database already holds the schema.',
        ], JSON_PRETTY_PRINT));

        return $written === false ? null : '+ storage/installed.lock';
    });

    $run('~ scrub tokens left in notification_queue', static function (): ?string {
        if (!function_exists('notification_prune_secrets')) {
            return null;
        }
        // Existing rows still carry working password-reset links; the send-time
        // redaction only covers mail queued from now on.
        $scrubbed = notification_prune_secrets(3600);

        return $scrubbed > 0 ? '~ scrubbed ' . $scrubbed . ' queued mail body/bodies' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_platform_run();
    echo "Platform security migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
