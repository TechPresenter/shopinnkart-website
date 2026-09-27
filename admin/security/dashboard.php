<?php
/**
 * ShopInnKart Admin - Security overview.
 *
 * Security > Settings is twenty cards and the longest screen in this admin. It
 * is the right place to CHANGE something and the wrong place to find out
 * whether anything is wrong: the log, the IP rules, the devices, the privacy
 * queue, the monitor's ten detectors, the CSP reports, the backups and the
 * retention sweep are seven more screens, none of which says "this store is
 * fine" or "this store is not".
 *
 * This screen answers that one question and nothing else.
 *
 * ---------------------------------------------------------------------------
 * Four rules it is built to
 * ---------------------------------------------------------------------------
 *  1. READ-ONLY, WITH LINKS. Nothing here writes, sweeps or probes. Every line
 *     ends at the card or screen that fixes it, so a control is never
 *     duplicated away from the place that owns it. A POST is bounced.
 *  2. RANKED BY CONSEQUENCE, NOT BY CATEGORY. The order is `rank`, assigned by
 *     what happens if the line is ignored. An admin still signing in with the
 *     password printed in a public README outranks a late retention sweep,
 *     always - and they are one table, not an "Accounts" section and a
 *     "Housekeeping" section that let the reader stop after the first.
 *  3. A QUIET STORE LOOKS QUIET. Only failing checks are printed. The ones that
 *     passed are counted in one line and listed inside a <details>, because a
 *     screen that always looks alarming is one nobody opens twice.
 *  4. SEVERITY IS NOT INVENTED. Event and alert rows carry the severity the app
 *     recorded (security_events.severity, security_alerts.severity) and it is
 *     printed as stored. The state checks below have no stored severity, so
 *     each declares its own beside its rank, in the same vocabulary, where the
 *     next reader can see it and argue with it.
 *
 * Read-only is also why the detectors are NOT swept here even when they are
 * overdue - security/events.php does that, and says so. A screen whose job is
 * to report the state must not be the thing that changes it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once INCLUDES_PATH . '/deployment-checks.php';   // deployment_seeded_logins()
require_once INCLUDES_PATH . '/retention.php';           // retention_last_run()

$admin = admin_require('security.view');

$selfUrl     = admin_url('security/dashboard.php');
$settingsUrl = admin_url('security/settings.php');
$eventsUrl   = admin_url('security/events.php');
$devicesUrl  = admin_url('security/devices.php');

// Nothing on this screen posts. A POST is a stale tab or a probe, and bouncing
// it is cheaper than leaving behind a surface that looks like it accepts one.
if (is_post()) {
    redirect($selfUrl);
}

/**
 * A link into the security log.
 *
 * Built by hand rather than with url_with(), which merges $_GET - that would
 * carry this screen's `days` onto a screen where `days` means nothing.
 */
$logLink = static function (array $params) use ($eventsUrl): string {
    $params = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');

    return $params === [] ? $eventsUrl : $eventsUrl . '?' . http_build_query($params);
};

// ---------------------------------------------------------------------------
// The range, and the buckets the chart draws
//
// Bucket count is capped at twelve on purpose: admin.js's chart module drops
// the printed values and then the labels as columns get thin, and a reader who
// has to hover thirty bars to find out what happened on Tuesday is being shown
// a texture rather than a number.
// ---------------------------------------------------------------------------
$ranges = [
    1  => ['label' => '24 hours', 'buckets' => 12, 'secs' => 7200,   'fmt' => 'H:i', 'per' => 'per 2 hours'],
    7  => ['label' => '7 days',   'buckets' => 7,  'secs' => 86400,  'fmt' => 'd M', 'per' => 'per day'],
    30 => ['label' => '30 days',  'buckets' => 10, 'secs' => 259200, 'fmt' => 'd M', 'per' => 'per 3 days'],
];

$days = (int) ($_GET['days'] ?? 7);
if (!isset($ranges[$days])) {
    $days = 7;
}
$range       = $ranges[$days];
$bucketSecs  = (int) $range['secs'];      // whitelisted above, so safe to inline in SQL
$bucketCount = (int) $range['buckets'];
$windowSecs  = $bucketCount * $bucketSecs;
$now         = time();

// The window is aligned to the bucket, not to "now minus seven days".
// Unaligned, the last daily column starts mid-afternoon and is labelled with
// yesterday's date while holding mostly today's events - a column whose label
// is wrong is worse than no column.
//
// Both alignments are measured from LOCAL midnight, never from the Unix epoch.
// Aligning the two-hour buckets with intdiv(time(), 7200) snaps them to UTC, and
// on this store's +05:30 clock every label came out at half past: 17:30, 19:30,
// 21:30. The bars were right and the axis was unreadable.
$midnight    = (int) strtotime('today');
$windowStart = $bucketSecs >= 86400
    ? $midnight - ($windowSecs - 86400)
    : $midnight + ((intdiv($now - $midnight, $bucketSecs) + 1) * $bucketSecs) - $windowSecs;

$since     = date('Y-m-d H:i:s', $windowStart);
$prevSince = date('Y-m-d H:i:s', $windowStart - $windowSecs);
$sinceDate = date('Y-m-d', $windowStart);

// ---------------------------------------------------------------------------
// WHAT HAS ACTUALLY HAPPENED
//
// One pass over the range and the range before it, so "the trend" is the same
// rows the totals came from and the two can never disagree. The repeated values
// get their own placeholder names: PDO runs with EMULATE_PREPARES off, where a
// named parameter may appear exactly once in a statement.
// ---------------------------------------------------------------------------
$totals = Database::fetch(
    "SELECT SUM(`created_at` >= :s1)                                       AS `cur_all`,
            SUM(`created_at` >= :s2 AND `severity` IN ('high','critical'))  AS `cur_bad`,
            SUM(`created_at` <  :s3)                                       AS `prev_all`,
            SUM(`created_at` <  :s4 AND `severity` IN ('high','critical'))  AS `prev_bad`
       FROM `security_events`
      WHERE `created_at` >= :p",
    ['s1' => $since, 's2' => $since, 's3' => $since, 's4' => $since, 'p' => $prevSince]
) ?? [];

$curAll  = (int) ($totals['cur_all'] ?? 0);
$curBad  = (int) ($totals['cur_bad'] ?? 0);
$prevAll = (int) ($totals['prev_all'] ?? 0);
$prevBad = (int) ($totals['prev_bad'] ?? 0);

$byType = Database::fetchAll(
    "SELECT `type`,
            SUM(`created_at` >= :s1) AS `cur`,
            SUM(`created_at` <  :s2) AS `prev`,
            MAX(CASE WHEN `created_at` >= :s3
                     THEN FIELD(`severity`, 'info','low','medium','high','critical')
                     ELSE 0 END)     AS `worst`
       FROM `security_events`
      WHERE `created_at` >= :p
      GROUP BY `type`",
    ['s1' => $since, 's2' => $since, 's3' => $since, 'p' => $prevSince]
);

/** Plain names for the kinds of trouble this screen is asked about by name. */
$typeLabels = [
    'auth.login_failed'            => 'Refused sign-ins',
    'auth.login_throttled'         => 'Sign-ins the throttle refused',
    'auth.login_flood'             => 'Sign-in floods',
    'auth.account_under_attack'    => 'An account under sustained guessing',
    'auth.reauth_failed'           => 'Failed re-authentication',
    'auth.password_change_failed'  => 'Failed password changes',
    'csrf.failed'                  => 'Forms posted from somewhere else',
    'rbac.denied'                  => 'Permission denials',
    'webhook.payment_needs_review' => 'Payment callbacks held for review',
    'webhook.payment_throttled'    => 'Payment callbacks throttled',
    'api.token_reuse_after_revoke' => 'A revoked app token came back',
    'auth.remember_token_reuse'    => 'A stale remembered-device token came back',
    'mfa.device_token_reuse'       => 'A stale trusted-device token came back',
    'mfa.failed'                   => 'Two-step codes refused',
    'mfa.verify_throttled'         => 'Two-step checks throttled',
    'mfa.otp_throttled'            => 'Emailed codes throttled',
    'api.token_unknown'            => 'Unknown app tokens presented',
    'api.token_bad_secret'         => 'App tokens with the wrong secret',
    'probe.scanning'               => 'Directory scanning',
    'probe.notfound'               => 'Missing pages asked for',
    'probe.installer'              => 'Installer probes',
    'platform.installer_probe'     => 'Installer probes',
    'bot.honeypot'                 => 'Bots caught by the honeypot',
    'bot.flood'                    => 'Bot floods',
    'coupon.guess_burst'           => 'Coupon codes being guessed',
];

$severityByRank = [1 => 'info', 2 => 'low', 3 => 'medium', 4 => 'high', 5 => 'critical'];

// Two lists, and the split is the difference between an attack and an errand.
//
// Measured on 21,000 seeded events: ranking the table by severity alone put
// mfa.reset_by_cli, payment.credentials_changed, settings.scripts_changed,
// ip_rule.added and backup.downloaded in the top twelve and pushed 1,146
// refused two-step codes and every refused sign-in off the end. Those types are
// recorded high or critical because an admin doing them is worth a record, not
// because anybody attacked anything - Activity Log owns them.
//
// So the table is the types this screen was asked about by name, and everything
// else the app called serious goes in a <details> under it. The safety net still
// exists - the curated list can never be the reason something serious is missing
// - but it cannot bury the signal either.
//
// monitor.* rows are left out of both: they ARE the alerts card, and counting a
// detector's own trip as a ninth kind of event says the same thing twice.
$happened = [];
$alsoSerious = [];
foreach ($byType as $row) {
    $type  = (string) $row['type'];
    $cur   = (int) $row['cur'];
    $worst = (int) $row['worst'];

    if ($cur < 1 || str_starts_with($type, 'monitor.')) {
        continue;
    }

    $entry = [
        'type'  => $type,
        'label' => $typeLabels[$type] ?? $type,
        'named' => isset($typeLabels[$type]),
        'cur'   => $cur,
        'prev'  => (int) $row['prev'],
        'worst' => $severityByRank[$worst] ?? 'info',
        'rank'  => $worst,
    ];

    if ($entry['named']) {
        $happened[] = $entry;
    } elseif ($worst >= 4) {
        $alsoSerious[] = $entry;
    }
}

$byConsequence = static fn (array $a, array $b): int => [$b['rank'], $b['cur']] <=> [$a['rank'], $a['cur']];
usort($happened, $byConsequence);
usort($alsoSerious, $byConsequence);
$happenedShown = array_slice($happened, 0, 12);

// The chart. Gaps are filled here, so a quiet hour is an empty column rather
// than a missing one and the x axis stays a real time axis.
$bucketHits = Database::fetchPairs(
    "SELECT FLOOR(TIMESTAMPDIFF(SECOND, :s1, `created_at`) / {$bucketSecs}) AS `b`, COUNT(*) AS `c`
       FROM `security_events`
      WHERE `created_at` >= :s2
      GROUP BY `b`",
    ['s1' => $since, 's2' => $since]
);

$chart = [];
for ($i = 0; $i < $bucketCount; $i++) {
    $chart[] = [
        'label' => date((string) $range['fmt'], $windowStart + ($i * $bucketSecs)),
        'value' => (int) ($bucketHits[$i] ?? ($bucketHits[(string) $i] ?? 0)),
    ];
}

// ---------------------------------------------------------------------------
// WHAT THE MONITOR HAS RAISED
// ---------------------------------------------------------------------------
$alerts = Database::fetchAll(
    'SELECT * FROM `security_alerts` WHERE `created_at` >= :s ORDER BY `id` DESC LIMIT 20',
    ['s' => $since]
);
$alertTotal = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `security_alerts` WHERE `created_at` >= :s',
    ['s' => $since]
);

$rulesByKey = [];
foreach (security_monitor_rules() as $rule) {
    $rulesByKey[(string) $rule['key']] = $rule;
}

// Every type the log actually holds, so a prefix filter can be checked rather
// than assumed. Only fetched when there is an alert to link.
$knownTypes = [];
if ($alerts !== []) {
    $knownTypes = Database::fetchColumnAll(
        'SELECT DISTINCT `type` FROM `security_events`
          WHERE `created_at` >= (NOW() - INTERVAL 180 DAY)'
    );
}

/**
 * The events BEHIND an alert, not the alert's own monitor.* row.
 *
 * The subject and the cooldown window are what the detector actually counted,
 * so they are what the link always carries. A TYPE is added only when it cannot
 * mislead:
 *
 *   - one type: use it;
 *   - several under one prefix, AND the log holds nothing else under that prefix:
 *     use `prefix.`, which the log's filter reads as LIKE 'prefix.%'.
 *
 * That second condition is not pedantry. The lockout detector watches
 * auth.login_throttled and auth.login_flood, and `auth.` looked like the
 * obvious filter - but it also matches auth.login_failed, auth.password_changed
 * and nine others, so on this database it opened 20,000 rows and called them
 * "the events behind it". webhook.* passes the test; auth.* does not. A rule
 * that fails it, or one watching three unrelated families (token reuse watches
 * api.*, auth.* and mfa.*), links by subject and date and names its types in
 * the link's tooltip instead of guessing one of them.
 */
$alertLink = static function (array $alert) use ($rulesByKey, $logLink, $knownTypes): string {
    $rule   = $rulesByKey[(string) $alert['rule_key']] ?? null;
    $start  = (int) $alert['bucket_start'];
    $window = max(60, (int) $alert['window_secs']);
    $params = [
        'from' => date('Y-m-d', $start - $window),
        'to'   => date('Y-m-d', $start + $window),
    ];

    $subject = (string) $alert['subject'];
    $scope   = (string) $alert['scope'];
    if ($scope === 'ip' && filter_var($subject, FILTER_VALIDATE_IP) !== false) {
        $params['ip'] = $subject;
    } elseif ($scope === 'account' && preg_match('/^(admin|customer|api)#\d+$/', $subject) === 1) {
        $params['account'] = $subject;
    }

    $types = $rule !== null ? array_values(array_map('strval', (array) $rule['types'])) : [];

    if (count($types) === 1) {
        $params['type'] = $types[0];
    } elseif ($types !== []) {
        $prefixes = array_unique(array_map(
            static fn (string $t): string => (string) strstr($t, '.', true),
            $types
        ));
        $prefix = count($prefixes) === 1 ? (string) reset($prefixes) : '';

        if ($prefix !== '') {
            $strays = array_filter(
                $knownTypes,
                static fn ($t): bool => str_starts_with((string) $t, $prefix . '.')
                    && !in_array((string) $t, $types, true)
            );
            if ($strays === []) {
                $params['type'] = $prefix . '.';
            }
        }
    }

    return $logLink($params);
};

/** What the link will show, for its tooltip - the rule's types, named. */
$alertTypes = static function (array $alert) use ($rulesByKey): string {
    $rule = $rulesByKey[(string) $alert['rule_key']] ?? null;

    return $rule === null ? '' : implode(', ', array_map('strval', (array) $rule['types']));
};

// ---------------------------------------------------------------------------
// WHAT IS NOT SWITCHED ON, AND WHAT IS EXPIRING
//
// Every fact below is read from the thing itself, never from a checklist an
// operator ticked. Each read that can legitimately fail on an older database is
// wrapped, because a dashboard that 500s tells you nothing at all.
// ---------------------------------------------------------------------------
$seeded       = deployment_seeded_logins();
$seededAdmins = count($seeded['admins']);
$keyOk        = app_key_available();
$installer    = security_installer_status();
$gateSlug     = admin_gate_slug();

$twoFactorPolicy = mfa_policy();
$rolesRequiring  = 0;
try {
    $rolesRequiring = (int) Database::fetchColumn('SELECT COUNT(*) FROM `admin_roles` WHERE `require_2fa` = 1');
} catch (Throwable $e) {
    // Older database: report what is known rather than failing the screen.
}
$twoFactorRequired = $twoFactorPolicy !== 'optional' || $rolesRequiring > 0;

$cspMode      = (string) setting('sec_csp_mode', 'report-only');
$cspEnforcing = $cspMode === 'enforce';

// HTTPS is a question about THIS host, not only about the setting: on localhost
// and on a LAN address there is no certificate to redirect to, so the honest
// answer is "not applicable" rather than a red row the operator cannot clear.
$httpsMode = (string) setting('sec_force_https', 'auto');
$host      = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$hostLocal = security_host_is_local(trim($host, '[]'));
$httpsNow  = security_request_is_https();

$backupLatest = null;
try {
    $backupLatest = Database::fetchColumn('SELECT `created_at` FROM `backups` ORDER BY `id` DESC LIMIT 1');
} catch (Throwable $e) {
    // System > Backup owns that table; never fail here.
}
$backupHours = max(1, setting_int('sec_backup_alert_hours', 48));
$backupAt    = $backupLatest === null ? null : (int) strtotime((string) $backupLatest);
$backupStale = $backupAt !== null && $backupAt < $now - ($backupHours * 3600);

$monitorOn      = setting_bool('sec_monitor_enabled', true);
$monitorLastRun = trim((string) setting('sec_monitor_last_run', ''));
$monitorOverdue = $monitorOn && security_monitor_overdue();

$retentionRun = retention_last_run();
$retentionAt  = is_array($retentionRun) && isset($retentionRun['at'])
    ? (int) strtotime((string) $retentionRun['at'])
    : null;
$retentionOld = $retentionAt !== null && $retentionAt < $now - (30 * 86400);

$cspOpen     = 0;
$cspBlocking = 0;
try {
    $cspRow = Database::fetch(
        "SELECT COUNT(*) AS `open`, SUM(`disposition` = 'enforce') AS `blocking`
           FROM `csp_reports`
          WHERE `origin` = 'first-party' AND `status` = 'new'"
    );
    $cspOpen     = (int) ($cspRow['open'] ?? 0);
    $cspBlocking = (int) ($cspRow['blocking'] ?? 0);
} catch (Throwable $e) {
    // The CSP report table arrived with the monitor migration.
}

$privacyLate = 0;
try {
    $privacyLate = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `privacy_requests`
          WHERE `status` IN ('pending','ready') AND `due_at` IS NOT NULL AND `due_at` < NOW()"
    );
} catch (Throwable $e) {
    // ditto
}

/** live / expiring within a week / past their date but never revoked. */
$credentialAges = static function (string $table): array {
    try {
        $row = Database::fetch(
            "SELECT SUM(`revoked_at` IS NULL AND `expires_at` >  NOW())  AS `live`,
                    SUM(`revoked_at` IS NULL AND `expires_at` >  NOW()
                        AND `expires_at` <= NOW() + INTERVAL 7 DAY)      AS `soon`,
                    SUM(`revoked_at` IS NULL AND `expires_at` <= NOW())  AS `stale`
               FROM `{$table}`"
        );

        return [
            'live'  => (int) ($row['live'] ?? 0),
            'soon'  => (int) ($row['soon'] ?? 0),
            'stale' => (int) ($row['stale'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['live' => 0, 'soon' => 0, 'stale' => 0];
    }
};
$tokens  = $credentialAges('api_tokens');
$trusted = $credentialAges('auth_trusted_devices');

// ---------------------------------------------------------------------------
// The checks, in one list, ranked by consequence
//
// `rank` is the whole point of this array: 1 is "somebody may be inside the
// panel already", 19 is "housekeeping is late". It is deliberately not grouped
// by subject, because grouping is what lets a reader stop after the first
// heading and miss the one line that mattered.
// ---------------------------------------------------------------------------
$adminsFixUrl = admin_can('admins.edit') ? admin_url('admins/') : $settingsUrl . '#installer';

$checks = [
    [
        'rank' => 1, 'severity' => 'critical',
        'state' => $seeded['error'] !== null ? 'unknown' : ($seededAdmins > 0 ? 'bad' : 'ok'),
        'bad'   => $seededAdmins === 1
            ? 'An admin still signs in with the password printed in the public README.'
            : $seededAdmins . ' admins still sign in with passwords printed in the public README.',
        'ok'      => 'No admin uses a README password.',
        'unknown' => 'Could not check the seeded passwords.',
        'url'     => $adminsFixUrl, 'action' => 'Change it now',
    ],
    [
        'rank' => 2, 'severity' => 'critical',
        'state' => $keyOk ? 'ok' : 'bad',
        'bad'   => 'This copy cannot store a secret, so a saved password will not stick.',
        'ok'    => 'The application key is readable.',
        'url'   => $settingsUrl . '#installer', 'action' => 'Deployment',
    ],
    [
        'rank' => 3, 'severity' => 'high',
        'state' => ($installer['present'] && !$installer['disarmed']) ? 'bad' : 'ok',
        'bad'   => 'The web installer is still live in the document root.',
        'ok'    => $installer['present'] ? 'install.php is present but refuses to run.' : 'install.php is gone.',
        'url'   => $settingsUrl . '#installer', 'action' => 'Deployment',
    ],
    [
        'rank' => 4, 'severity' => 'high',
        'state' => $monitorOn ? 'ok' : 'bad',
        'bad'   => 'Nothing is watching: all ten detectors are switched off.',
        'ok'    => 'The ten detectors are on.',
        'url'   => $settingsUrl . '#monitor', 'action' => 'Monitoring',
    ],
    [
        'rank' => 5, 'severity' => 'high',
        'state' => $backupLatest === null ? 'bad' : 'ok',
        'bad'   => 'No backup has ever been taken of this store.',
        'ok'    => 'A backup exists, taken ' . ($backupLatest !== null ? time_ago($backupLatest) : '') . '.',
        'url'   => $settingsUrl . '#backups', 'action' => 'Backups',
    ],
    [
        'rank' => 6, 'severity' => 'high',
        'state' => $hostLocal
            ? 'unknown'
            : (($httpsMode === '0' || ($httpsMode === 'auto' && !$httpsNow)) ? 'bad' : 'ok'),
        'bad' => $httpsMode === '0'
            ? 'HTTPS is switched off, so a sign-in can cross the network in the clear.'
            : 'HTTPS is not being forced, so a visitor can stay on http.',
        'ok'      => 'HTTPS is enforced.',
        'unknown' => 'HTTPS: not applicable on a local address.',
        'url'     => $settingsUrl . '#https', 'action' => 'HTTPS',
    ],
    [
        'rank' => 7, 'severity' => 'high',
        'state' => $twoFactorRequired ? 'ok' : 'bad',
        'bad'   => 'Two-step sign in is required of nobody; a password is the only lock.',
        'ok'    => $twoFactorPolicy === 'required_all'
            ? 'Two-step sign in is required of every admin.'
            : 'Two-step sign in is required of some admins.',
        'url' => $settingsUrl . '#two-factor', 'action' => 'Two-step sign in',
    ],
    [
        'rank' => 8, 'severity' => 'high',
        'state' => $cspBlocking > 0 ? 'bad' : 'ok',
        'bad'   => 'The content policy is blocking this store\'s own scripts right now.',
        'ok'    => 'Nothing of ours is being blocked.',
        'url'   => $settingsUrl . '#csp-reports', 'action' => 'CSP reports',
    ],
    [
        'rank' => 9, 'severity' => 'medium',
        'state' => ($seeded['error'] === null && (int) $seeded['customers'] > 0) ? 'bad' : 'ok',
        'bad'   => (int) $seeded['customers'] === 1
            ? 'One demonstration customer account still uses the seeded password.'
            : (int) $seeded['customers'] . ' demonstration customer accounts still use the seeded password.',
        'ok'  => 'No customer uses a seeded password.',
        'url' => $settingsUrl . '#installer', 'action' => 'Deployment',
    ],
    [
        'rank' => 10, 'severity' => 'medium',
        'state' => $backupStale ? 'bad' : 'ok',
        'bad'   => 'The last backup was ' . ($backupLatest !== null ? time_ago($backupLatest) : 'never') . '.',
        'ok'    => 'The backup is recent.',
        'url'   => $settingsUrl . '#backups', 'action' => 'Backups',
    ],
    [
        'rank' => 11, 'severity' => 'medium',
        'state' => $monitorOverdue ? 'bad' : 'ok',
        'bad'   => 'The detectors are overdue - nothing is running them from cron.',
        'ok'    => $monitorLastRun !== '' ? 'Last swept ' . time_ago($monitorLastRun) . '.' : 'A sweep is due shortly.',
        'url'   => $settingsUrl . '#monitor', 'action' => 'Monitoring',
    ],
    [
        'rank' => 12, 'severity' => 'medium',
        'state' => $cspEnforcing ? 'ok' : 'bad',
        'bad'   => 'The content policy only reports; nothing is actually blocked.',
        'ok'    => 'The content policy is enforcing.',
        'url'   => $settingsUrl . '#headers-csp', 'action' => 'Headers & CSP',
    ],
    [
        'rank' => 13, 'severity' => 'medium',
        'state' => $privacyLate > 0 ? 'bad' : 'ok',
        'bad'   => $privacyLate === 1
            ? 'A privacy request is past the date the policy promises.'
            : $privacyLate . ' privacy requests are past the date the policy promises.',
        'ok'  => 'No privacy request is overdue.',
        'url' => admin_url('security/privacy.php'), 'action' => 'Privacy requests',
    ],
    [
        'rank' => 14, 'severity' => 'medium',
        'state' => ($cspOpen > 0 && $cspBlocking < 1) ? 'bad' : 'ok',
        'bad'   => $cspOpen === 1
            ? 'One unreviewed report about this store\'s own code.'
            : $cspOpen . ' unreviewed reports about this store\'s own code.',
        'ok'  => 'No CSP report is waiting.',
        'url' => $settingsUrl . '#csp-reports', 'action' => 'CSP reports',
    ],
    [
        'rank' => 15, 'severity' => 'low',
        'state' => $gateSlug === '' ? 'bad' : 'ok',
        'bad'   => 'The admin login sits at its public address.',
        'ok'    => 'The admin login is behind a secret address.',
        'url'   => $settingsUrl . '#admin-login-address', 'action' => 'Login address',
    ],
    [
        'rank' => 16, 'severity' => 'low',
        'state' => $installer['present'] ? 'bad' : 'ok',
        'bad'   => 'install.php is still on the server. Delete it.',
        'ok'    => 'No installer file is left behind.',
        'url'   => $settingsUrl . '#installer', 'action' => 'Deployment',
    ],
    [
        'rank' => 17, 'severity' => 'low',
        'state' => ($retentionAt === null || $retentionOld) ? 'bad' : 'ok',
        'bad'   => $retentionAt === null
            ? 'The retention sweep has never run, so nothing old is being deleted.'
            : 'The retention sweep last ran ' . time_ago(date('Y-m-d H:i:s', $retentionAt)) . '.',
        'ok'  => 'The retention sweep is current.',
        'url' => $settingsUrl . '#retention', 'action' => 'Retention',
    ],
    [
        'rank' => 18, 'severity' => 'low',
        'state' => $tokens['stale'] > 0 ? 'bad' : 'ok',
        'bad'   => $tokens['stale'] === 1
            ? 'An app token has expired but was never revoked.'
            : $tokens['stale'] . ' app tokens have expired but were never revoked.',
        'ok'  => 'No app token is left expired.',
        'url' => $devicesUrl, 'action' => 'Devices & tokens',
    ],
    [
        'rank' => 19, 'severity' => 'low',
        'state' => $trusted['stale'] > 0 ? 'bad' : 'ok',
        'bad'   => $trusted['stale'] === 1
            ? 'A trusted device is past its date but not cleared.'
            : $trusted['stale'] . ' trusted devices are past their date but not cleared.',
        'ok'  => 'No trusted device is left expired.',
        'url' => $devicesUrl, 'action' => 'Devices & tokens',
    ],
];

usort($checks, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

$open  = array_values(array_filter($checks, static fn (array $c): bool => $c['state'] === 'bad'));
$clear = array_values(array_filter($checks, static fn (array $c): bool => $c['state'] !== 'bad'));

$severityWeight = ['critical' => 5, 'high' => 4, 'medium' => 3, 'low' => 2, 'info' => 1];
$worstOpen      = '';
foreach ($open as $c) {
    if ($worstOpen === '' || ($severityWeight[(string) $c['severity']] ?? 0) > ($severityWeight[$worstOpen] ?? 0)) {
        $worstOpen = (string) $c['severity'];
    }
}

$verdictTone = $open === []
    ? 'green'
    : (in_array($worstOpen, ['critical', 'high'], true) ? 'red' : 'amber');

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$pageTitle    = 'Security Overview';
$pageSubtitle = $open === []
    ? 'Nothing needs attention.'
    : count($open) . (count($open) === 1 ? ' thing needs' : ' things need')
        . ' attention. The worst is ' . $worstOpen . '.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Security', 'url' => $settingsUrl],
    ['label' => 'Overview'],
];

$pageActions = '';
foreach ($ranges as $key => $meta) {
    $pageActions .= '<a class="ad-btn ad-btn--sm' . ($key === $days ? ' ad-btn--primary' : '') . '"'
        . ' href="' . e($selfUrl . '?days=' . $key) . '"'
        . ($key === $days ? ' aria-current="true"' : '') . '>' . e((string) $meta['label']) . '</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card(
        'Needs attention',
        $open === [] ? 'None' : (string) count($open),
        $open === [] ? 'shield-check' : 'alert',
        $verdictTone,
        $open === [] ? 'Every check passed' : 'Worst is ' . $worstOpen
    ) ?>
    <?php /* No link: this counts high AND critical, and the log's severity
             filter takes one value, so any link here would be a number that
             disagrees with the page it opens. The table below is the drill-in. */ ?>
    <?= admin_stat_card(
        'Serious events',
        number_format($curBad),
        'activity',
        $curBad > 0 ? 'red' : 'green',
        number_format($prevBad) . ' the ' . $range['label'] . ' before'
    ) ?>
    <?= admin_stat_card(
        'All events',
        number_format($curAll),
        'shield',
        'blue',
        number_format($prevAll) . ' the ' . $range['label'] . ' before',
        $logLink(['from' => $sinceDate])
    ) ?>
    <?= admin_stat_card(
        'Detector alerts',
        number_format($alertTotal),
        'bell',
        $alertTotal > 0 ? 'amber' : 'gray',
        $monitorLastRun !== '' ? 'Swept ' . time_ago($monitorLastRun) : 'Never swept'
    ) ?>
</div>

<?php /* 1. Needs attention - the ranked answer, and nothing else above it. */ ?>
<?= admin_card_open($open === [] ? 'Nothing needs attention' : 'Needs attention', [
    'id'    => 'sd-attention',
    'sub'   => $open === []
        ? 'Read from the settings themselves, not from a checklist.'
        : 'Worst first. Each row links to the control that fixes it.',
    'flush' => $open !== [],
]) ?>
    <?php if ($open === []): ?>
        <div class="sik-alert sik-alert--success" style="margin:0">
            <?= icon('check-circle', 'w-5 h-5') ?>
            <div>Nothing is switched off, overdue or expired.</div>
        </div>
    <?php else: ?>
        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>What is wrong</th>
                        <th>Fix it on</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($open as $c): ?>
                        <tr>
                            <td class="ad-nowrap">
                                <span class="sik-status sik-status--<?= e_attr(security_severity_tone((string) $c['severity'])) ?>">
                                    <?= e(ucfirst((string) $c['severity'])) ?>
                                </span>
                            </td>
                            <td><?= e((string) $c['bad']) ?></td>
                            <td class="ad-nowrap">
                                <a class="ad-btn ad-btn--sm" href="<?= e((string) $c['url']) ?>"><?= e((string) $c['action']) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?= admin_card_close(
    '<details><summary style="cursor:pointer">' . count($clear) . ' check'
    . (count($clear) === 1 ? '' : 's') . ' passed</summary>'
    . '<ul class="ad-checklist" style="margin-top:10px">'
    . implode('', array_map(static function (array $c): string {
        return '<li class="' . ($c['state'] === 'ok' ? 'is-ok' : 'is-unknown') . '">'
            . e((string) ($c['state'] === 'ok' ? $c['ok'] : ($c['unknown'] ?? $c['ok'])))
            . '</li>';
    }, $clear))
    . '</ul></details>'
) ?>

<div class="ad-grid ad-grid--sidebar" style="margin-top:18px">
    <div style="display:grid;gap:18px;align-content:start">

        <?php /* 2. What has actually happened, over the range, with the trend. */ ?>
        <?= admin_card_open('What has happened', [
            'id'      => 'sd-happened',
            'sub'     => 'Every recorded event, ' . $range['per'],
            'actions' => '<a class="ad-btn ad-btn--sm" href="' . e($eventsUrl) . '">Security log</a>',
        ]) ?>
            <?= admin_bar_chart($chart, '', 170) ?>

            <?php if ($happenedShown === []): ?>
                <p style="margin:14px 0 0">
                    <strong>Nothing looked like an attack in the last <?= e((string) $range['label']) ?>.</strong>
                </p>
            <?php else: ?>
                <div class="ad-tablewrap" style="margin-top:14px">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>What</th>
                                <th>Worst</th>
                                <th class="ad-num">Last <?= e((string) $range['label']) ?></th>
                                <th class="ad-num">The <?= e((string) $range['label']) ?> before</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($happenedShown as $h): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e($logLink(['type' => $h['type'], 'from' => $sinceDate])) ?>">
                                            <?= e((string) $h['label']) ?>
                                        </a>
                                        <?php if ($h['named']): ?>
                                            <div class="ad-cellflex__meta ad-mono"><?= e((string) $h['type']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ad-nowrap">
                                        <span class="sik-status sik-status--<?= e_attr(security_severity_tone((string) $h['worst'])) ?>">
                                            <?= e(ucfirst((string) $h['worst'])) ?>
                                        </span>
                                    </td>
                                    <td class="ad-num"><strong><?= e(number_format((int) $h['cur'])) ?></strong></td>
                                    <td class="ad-num"><?= e(number_format((int) $h['prev'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($happened) > count($happenedShown)): ?>
                    <p style="margin:12px 0 0">
                        <a href="<?= e($logLink(['from' => $sinceDate])) ?>">
                            <?= (int) (count($happened) - count($happenedShown)) ?> more kinds in the log
                        </a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        <?= admin_card_close($alsoSerious === [] ? '' :
            '<details><summary style="cursor:pointer">'
            . count($alsoSerious) . ' other kind' . (count($alsoSerious) === 1 ? '' : 's')
            . ' the app recorded as serious</summary>'
            . '<ul class="ad-checklist" style="margin-top:10px">'
            . implode('', array_map(static function (array $h) use ($logLink, $sinceDate): string {
                return '<li class="is-todo"><a class="ad-mono" href="'
                    . e($logLink(['type' => $h['type'], 'from' => $sinceDate])) . '">'
                    . e((string) $h['type']) . '</a> &middot; '
                    . e(ucfirst((string) $h['worst'])) . ' &middot; '
                    . e(number_format((int) $h['cur'])) . '</li>';
            }, array_slice($alsoSerious, 0, 20)))
            . '</ul></details>'
        ) ?>

        <?php /* 3. What the monitor raised, each linking to the events behind it. */ ?>
        <?= admin_card_open('Alerts the monitor raised', [
            'id'    => 'sd-alerts',
            'sub'   => 'A detector crossed its threshold. Newest first.',
            'flush' => $alerts !== [],
        ]) ?>
            <?php if ($alerts === []): ?>
                <p style="margin:0">
                    <strong>No detector has tripped in the last <?= e((string) $range['label']) ?>.</strong>
                </p>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Severity</th>
                                <th>What tripped</th>
                                <th>Behind it</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): ?>
                                <?php
                                $rule   = $rulesByKey[(string) $alert['rule_key']] ?? null;
                                $advice = $rule !== null ? (string) $rule['advice'] : '';
                                ?>
                                <tr>
                                    <td class="ad-nowrap">
                                        <?= e(format_datetime($alert['created_at'], 'd M, H:i')) ?>
                                        <div class="ad-cellflex__meta"><?= e(time_ago($alert['created_at'])) ?></div>
                                    </td>
                                    <td class="ad-nowrap">
                                        <span class="sik-status sik-status--<?= e_attr(security_severity_tone((string) $alert['severity'])) ?>">
                                            <?= e(ucfirst((string) $alert['severity'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e((string) $alert['summary']) ?>
                                        <?php if ($advice !== ''): ?>
                                            <details style="margin-top:6px">
                                                <summary style="cursor:pointer">What to do</summary>
                                                <div class="sik-alert sik-alert--warning" style="margin:8px 0 0">
                                                    <div><?= e($advice) ?></div>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ad-nowrap">
                                        <?php $types = $alertTypes($alert); ?>
                                        <a class="ad-btn ad-btn--sm" href="<?= e($alertLink($alert)) ?>"
                                           <?= $types !== '' ? 'title="' . e_attr('Look for: ' . $types) . '"' : '' ?>>Events</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?= admin_card_close(
            $alertTotal > count($alerts)
                ? '<a href="' . e($logLink(['type' => 'monitor.', 'from' => $sinceDate])) . '">'
                    . (int) ($alertTotal - count($alerts)) . ' older alerts in the log</a>'
                : ''
        ) ?>
    </div>

    <div style="display:grid;gap:18px;align-content:start">

        <?php /* 4. What is expiring or stale: dates, not accusations. */ ?>
        <?= admin_card_open('Expiring and stale', [
            'id'  => 'sd-expiring',
            'sub' => 'Dates, not faults. Anything overdue is listed above.',
        ]) ?>
            <?= admin_kv([
                [
                    'App tokens',
                    '<a href="' . e($devicesUrl) . '">' . e(number_format($tokens['live']) . ' live') . '</a>',
                    ['hint' => $tokens['soon'] > 0
                        ? $tokens['soon'] . ' expire within a week'
                        : 'None expire within a week'],
                ],
                [
                    'Trusted devices',
                    '<a href="' . e($devicesUrl) . '">' . e(number_format($trusted['live']) . ' live') . '</a>',
                    ['hint' => $trusted['soon'] > 0
                        ? $trusted['soon'] . ' expire within a week'
                        : 'None expire within a week'],
                ],
                [
                    'Retention sweep',
                    $retentionAt === null
                        ? '<span class="sik-status sik-status--amber">Never run</span>'
                        : e(time_ago(date('Y-m-d H:i:s', $retentionAt))),
                    ['hint' => 'Deletes logs past their keep-for date'],
                ],
                [
                    'Last backup',
                    $backupLatest === null
                        ? '<span class="sik-status sik-status--red">None ever</span>'
                        : e(time_ago((string) $backupLatest)),
                    ['hint' => 'Counts as stale after ' . $backupHours . ' hours'],
                ],
                [
                    'Security log kept',
                    e(setting_int('sec_events_retention_days', 90) . ' days'),
                    ['hint' => 'Older events are pruned'],
                ],
            ]) ?>
        <?= admin_card_close('<a class="ad-btn ad-btn--sm" href="' . e($settingsUrl) . '">Security settings</a>') ?>

        <?= admin_card_open('The screens behind this one', ['id' => 'sd-links', 'level' => 3]) ?>
            <ul class="ad-checklist">
                <li class="is-ok"><a href="<?= e($eventsUrl) ?>">Security log</a></li>
                <li class="is-ok"><a href="<?= e(admin_url('security/ip-rules.php')) ?>">IP rules</a></li>
                <li class="is-ok"><a href="<?= e($devicesUrl) ?>">Devices &amp; tokens</a></li>
                <li class="is-ok"><a href="<?= e(admin_url('security/privacy.php')) ?>">Privacy requests</a></li>
                <li class="is-ok"><a href="<?= e($settingsUrl) ?>">Security settings</a></li>
            </ul>
        <?= admin_card_close() ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
