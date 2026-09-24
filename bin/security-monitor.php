<?php
/**
 * ShopInnKart - the security sweep, for cron.
 *
 * Runs the suspicious-activity detectors over the events the app has written
 * since the last sweep, raises an alert for anything that crossed a threshold
 * (once per subject per cooldown window), prunes the log past its retention
 * setting, and keeps the mirrored IP-rule file honest.
 *
 * Shared hosting, so: no daemon, no queue worker, no Redis. One short cron
 * line, five minutes apart, is the whole design.
 *
 *     [*]/5 * * * * /usr/bin/php /home/user/public_html/bin/security-monitor.php --quiet
 *
 * (the [*] is a literal asterisk - it cannot be written inside this comment)
 *
 * Flags:
 *     --quiet     print only problems (what cron should use)
 *     --no-email  run the detectors without queueing anything
 *     --prune     prune the log even when nothing tripped
 *     --status    print what the monitor thinks and change nothing
 *
 * Exit code 0 = nothing serious, 1 = at least one high or critical alert.
 * Cron mails you the output on a non-zero exit, so a store with no SMTP still
 * finds out.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/init.php';

$quiet   = in_array('--quiet', $argv, true);
$noEmail = in_array('--no-email', $argv, true);
$prune   = in_array('--prune', $argv, true);
$status  = in_array('--status', $argv, true);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

$say('ShopInnKart security sweep - ' . date('Y-m-d H:i:s'));
$say(str_repeat('-', 66));

// ---------------------------------------------------------------------------
// --status: what the monitor thinks, without touching anything
// ---------------------------------------------------------------------------
if ($status) {
    $lastRun = (string) setting('sec_monitor_last_run', '');
    echo 'Enabled:        ' . (setting_bool('sec_monitor_enabled', true) ? 'yes' : 'no') . "\n";
    echo 'Emails alerts:  ' . (setting_bool('sec_monitor_email', true) ? 'yes' : 'no') . "\n";
    echo 'Last sweep:     ' . ($lastRun === '' ? 'never' : $lastRun) . "\n";
    echo 'Retention:      ' . setting_int('sec_events_retention_days', 90) . " days\n";
    echo 'CSP mode:       ' . (string) setting('sec_csp_mode', 'report-only') . "\n";
    echo 'Inline scripts: ' . security_csp_script_mode() . "\n";
    echo "\nDetectors:\n";
    foreach (security_monitor_rules() as $rule) {
        printf("  %-22s %5d in %-12s per %s\n",
            $rule['key'], (int) $rule['threshold'],
            security_monitor_window_label((int) $rule['window']),
            (string) $rule['scope']);
    }
    echo "\nIP rules:       " . setting_int('sec_ip_rules_count', 0) . " live\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 1. The detectors
// ---------------------------------------------------------------------------
$serious = 0;
$sweep   = security_monitor_sweep(!$noEmail);

if ($sweep['skipped'] !== '') {
    $say('  skip  ' . $sweep['skipped']);
} elseif ($sweep['trips'] === []) {
    $say('  ok    ' . $sweep['checked'] . ' detectors, nothing crossed a threshold');
} else {
    foreach ($sweep['trips'] as $trip) {
        // Printed even in --quiet: this is the whole reason the script exists.
        echo '  ALERT [' . strtoupper((string) $trip['severity']) . '] ' . $trip['summary']
            . ($trip['emailed'] ? ' (emailed)' : '') . "\n";
        if (in_array((string) $trip['severity'], ['high', 'critical'], true)) {
            $serious++;
        }
    }
}

// ---------------------------------------------------------------------------
// 2. The CSP probation
//
// The report endpoint reverts the moment it sees enough first-party blocks.
// This covers the other shape of the same failure: the site is so broken under
// the policy that no page runs far enough to SEND a report, so the inbox stays
// silent while the shop is down. If probation has run out with nothing
// reported at all, the policy is left enforced - silence at the end of a full
// probation window is the good outcome, and this only says so out loud.
// ---------------------------------------------------------------------------
if ((string) setting('sec_csp_mode', 'report-only') === 'enforce') {
    $since = (string) setting('sec_csp_enforced_at', '');
    $hours = max(1, setting_int('sec_csp_probation_hours', 24));
    if ($since !== '' && strtotime($since) !== false) {
        $left = (strtotime($since) + $hours * 3600) - time();
        $say($left > 0
            ? '  ..    CSP enforced, ' . max(1, intdiv($left, 60)) . ' minutes of probation left'
            : '  ok    CSP enforced and past probation');
    }
}

// ---------------------------------------------------------------------------
// 3. Housekeeping
// ---------------------------------------------------------------------------
// Rebuilt so an expired rule really stops applying, and so a copied storage
// folder cannot leave a stale block list in front of the site.
$rules = security_ip_rules_sync();
$say('  ok    ' . count($rules['rules']) . ' live IP rule(s) mirrored');

// Pruning is hourly rather than every five minutes: it is a DELETE over the
// busiest table in the security build, and there is nothing to gain from
// running it twelve times an hour.
if ($prune || (int) date('i') < 5) {
    $removed = security_events_prune();
    $say('  ok    retention: ' . $removed . ' old event(s) removed');
}

// The notification queue is somebody else's cron line. If it is not running,
// every alert above is sitting in a table nobody reads - worth saying.
try {
    $stuck = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `notification_queue`
          WHERE `status` = 'pending' AND `template_key` = 'security_alert'
            AND `created_at` < (NOW() - INTERVAL 30 MINUTE)"
    );
    if ($stuck > 0) {
        echo '  WARN  ' . $stuck . " security alert email(s) have been queued for over 30 minutes.\n";
        echo "        bin/send-queued-emails.php is not running, or SMTP is not configured.\n";
        $serious++;
    }
} catch (Throwable $e) {
    // The queue table belongs to the mail build; never fail this script on it.
}

$say(str_repeat('-', 66));
$say($serious > 0
    ? $serious . ' thing(s) need attention'
    : 'Nothing needs attention');

exit($serious > 0 ? 1 : 0);
