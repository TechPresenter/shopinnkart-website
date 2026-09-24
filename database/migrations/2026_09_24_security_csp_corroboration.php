<?php
/**
 * ShopInnKart - Migration: corroboration before the CSP switches itself off.
 *
 * One setting, sec_csp_revert_reporters.
 *
 * WHY
 * The Content-Security-Policy report collector takes no session and no CSRF
 * token, because the caller is the browser's own policy engine and it would
 * not send either. That makes every field in a report the CALLER's to choose:
 * the directive, the blocked URI (and "inline" is what marks a violation as
 * first-party) and, through document-uri, the page kind. Measured on the
 * running site before this change: five anonymous POSTs, no cookie, no token,
 * took the policy from Enforce back to Report only. Nothing had actually been
 * blocked - the reports were invented - and since only an admin can press
 * Enforce again, the same five POSTs win every time the owner tries.
 *
 * What a caller cannot invent is a second source address. A policy that is
 * genuinely refusing the site's own scripts refuses them for EVERY visitor, so
 * real breakage is reported from many addresses within seconds. This setting
 * is how many different addresses must have reported before the automatic
 * revert is allowed to act. 1 restores the old, forgeable behaviour.
 *
 * Idempotent. Never DROPs. One configuration row; no existing data is touched.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_security_csp_corroboration.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_csp_corroboration_run(): array
{
    $applied = [];
    $errors  = [];

    try {
        $exists = Database::fetchColumn(
            'SELECT `id` FROM `settings` WHERE `setting_key` = :k',
            ['k' => 'sec_csp_revert_reporters']
        );

        if (!$exists) {
            Database::insert('settings', [
                'setting_group' => 'security',
                'setting_key'   => 'sec_csp_revert_reporters',
                'setting_value' => '2',
                'setting_type'  => 'number',
                'label'         => 'Addresses needed before the CSP reverts itself',
            ]);
            $applied[] = '+ setting sec_csp_revert_reporters = 2';
        }
    } catch (Throwable $e) {
        $errors[] = 'sec_csp_revert_reporters: ' . $e->getMessage();
    }

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = migration_security_csp_corroboration_run();
    foreach ($result['applied'] as $line) {
        echo '  ', $line, "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ', $line, "\n";
    }
    echo count($result['applied']), " change(s), ", count($result['errors']), " error(s)\n";
    exit($result['errors'] === [] ? 0 : 1);
}
