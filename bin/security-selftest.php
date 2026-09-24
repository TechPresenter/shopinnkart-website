<?php
/**
 * ShopInnKart - platform security self-test.
 *
 * Answers, from the server itself, the questions that only the live host can
 * answer: does it honour .htaccess, is the installer still there, is HTTPS on,
 * can secrets be stored. The same checks the security cards show, in a form
 * that can be run over SSH right after a deploy or from cron.
 *
 * Run:  php bin/security-selftest.php
 *       php bin/security-selftest.php --quiet    (only problems)
 *
 * Exit code 0 = clean, 1 = something needs attention.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/init.php';

$quiet    = in_array('--quiet', $argv, true);
$problems = [];
$notes    = [];

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

$say('ShopInnKart security self-test');
$say('Site: ' . SITE_URL);
$say(str_repeat('-', 60));

// --- 1. Files the web should refuse ----------------------------------------
$probe = security_exposure_probe();
if ($probe['checked'] === 0) {
    $problems[] = 'The site could not reach itself over HTTP at ' . SITE_URL . ' - the exposed-file check did not run.';
} elseif ($probe['exposed'] !== []) {
    foreach ($probe['exposed'] as $row) {
        $problems[] = 'DOWNLOADABLE: ' . $row['path'] . ' (' . $row['bytes'] . ' bytes)';
    }
} else {
    $say('  ok    ' . $probe['checked'] . ' sensitive paths all refused over HTTP');
}
foreach ($probe['errors'] as $line) {
    $notes[] = 'not reachable: ' . $line;
}

// --- 2. The installer -------------------------------------------------------
$installer = security_installer_status();
if ($installer['present']) {
    $problems[] = 'install.php is still on the server. It refuses to run, but delete it.';
} else {
    $say('  ok    install.php has been deleted');
}

// --- 3. The password the store shipped with ---------------------------------
// Deliberately a problem rather than a note. The project's README is in a
// public repository and prints the seeded administrator's password, and the
// same row is in the dump an owner imports - so until it is changed, reading
// the repository is enough to sign in here. Hiding the login address does not
// help against somebody holding the key.
require_once INCLUDES_PATH . '/deployment-checks.php';
$seeded = deployment_seeded_logins();
$seededMessage = deployment_seeded_logins_message($seeded);
if ($seededMessage !== null) {
    $problems[] = $seededMessage;
} else {
    $say('  ok    no account still uses a password from the README');
}

// --- 4. Somewhere to keep secrets ------------------------------------------
if (!app_key_available()) {
    $problems[] = 'No application key: config/ is not writable and SIK_APP_KEY is unset, so SMTP and courier secrets cannot be stored.';
} else {
    $say('  ok    application key is available');
}

// --- 5. HTTPS ---------------------------------------------------------------
$mode = (string) setting('sec_force_https', 'auto');
$host = (string) parse_url(SITE_URL, PHP_URL_HOST);
if (security_host_is_local($host)) {
    $say('  --    HTTPS not applicable on ' . $host);
} elseif (strpos(SITE_URL, 'https://') !== 0) {
    $problems[] = 'SITE_URL is not https. Set up a certificate, then set the HTTPS mode on Admin > Security > Settings.';
} elseif ($mode === '0') {
    $problems[] = 'HTTPS enforcement is switched off while the site runs on https - no HSTS is being sent.';
} else {
    $say('  ok    HTTPS mode: ' . ($mode === '1' ? 'always redirect' : 'automatic')
        . ', HSTS ' . ((int) setting('sec_hsts_max_age', 300) > 0 ? setting('sec_hsts_max_age', 300) . 's' : 'off'));
}

// --- 6. Content Security Policy ---------------------------------------------
$csp = (string) setting('sec_csp_mode', 'report-only');
if ($csp === 'off') {
    $problems[] = 'The Content Security Policy is switched off.';
} elseif ($csp === 'report-only') {
    $notes[] = 'The CSP is in report-only mode - it warns but does not block. Switch it to enforce once the site has been clicked through.';
} else {
    $say('  ok    Content Security Policy is enforced');
}

// --- 7. Secrets left in the mail queue --------------------------------------
try {
    $stale = (int) Database::fetchColumn(
        // "token=[redacted]" still matches "%token=%", so an already-scrubbed
        // row would otherwise be reported forever.
        "SELECT COUNT(*) FROM `notification_queue`
         WHERE `template_key` IN ('password_reset', 'email_verify')
           AND `created_at` < :cutoff
           AND (
                (`body` LIKE '%token=%' AND `body` NOT LIKE '%token=[redacted]%')
             OR (`body_text` LIKE '%token=%' AND `body_text` NOT LIKE '%token=[redacted]%')
           )",
        ['cutoff' => date('Y-m-d H:i:s', time() - 86400)]
    );
    if ($stale > 0) {
        $problems[] = $stale . ' queued mail row(s) still hold expired reset links. Run the queue, or call notification_prune_secrets().';
    } else {
        $say('  ok    no working tokens left in notification_queue');
    }
} catch (Throwable $e) {
    $notes[] = 'Could not check notification_queue: ' . $e->getMessage();
}

// --- Result -----------------------------------------------------------------
echo "\n";
foreach ($notes as $line) {
    echo '  note  ' . $line . "\n";
}
foreach ($problems as $line) {
    echo '  FIX   ' . $line . "\n";
}

printf("\n%d problem(s), %d note(s)\n", count($problems), count($notes));
exit($problems === [] ? 0 : 1);
