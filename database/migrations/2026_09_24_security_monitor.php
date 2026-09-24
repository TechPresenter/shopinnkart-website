<?php
/**
 * ShopInnKart - Migration: CSP report inbox, suspicious-activity monitor, IP rules.
 *
 * Adds what includes/security-monitor.php and api/security/csp-report.php need:
 *
 *   - `csp_reports`: Content-Security-Policy violations, GROUPED. One row per
 *     (directive + blocked URI + page kind), with a hit counter - not one row
 *     per report. A single misbehaving browser extension fires the same
 *     violation on every page load, and a per-report table would be tens of
 *     thousands of rows describing one fact.
 *   - `ip_rules`: the block / allow lists. Addresses are kept twice: the CIDR
 *     as the owner typed it (so the screen can show it back) and the binary
 *     start/end of the range (so a match is a comparison, not a parse).
 *   - `security_alerts`: one row per TRIP of a detector, deduplicated on
 *     (rule, subject, window) so a burst that lasts an hour is one alert an
 *     hour and not one alert a second.
 *   - the sec_alert_* thresholds, the sec_csp_* collector settings and the
 *     retention window, all editable from Admin > Security.
 *   - the `security_alert` email template, so a trip can reach the owner
 *     through the ordinary notification queue.
 *
 * Idempotent. Never DROPs. Safe on a live database: three new tables, one
 * template row and configuration rows. No existing data is touched.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_security_monitor.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_monitor_run(): array
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
    // CSP violation reports, grouped
    // -----------------------------------------------------------------------
    $run('+ table csp_reports', static function (): ?string {
        if (mig_table_exists('csp_reports')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `csp_reports` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `fingerprint`   CHAR(40) NOT NULL COMMENT 'sha1(directive|blocked|page kind) - what makes two reports the same fact',
                `directive`     VARCHAR(40) NOT NULL COMMENT 'script-src-elem, style-src, ...',
                `blocked_uri`   VARCHAR(255) NOT NULL COMMENT 'Normalised to scheme://host/path, or inline / eval / data',
                `page_context`  VARCHAR(12) NOT NULL DEFAULT 'public' COMMENT 'public | account | auth | admin | api',
                `document_path` VARCHAR(255) NULL COMMENT 'Path of the page that reported, no query string',
                `sample`        VARCHAR(255) NULL COMMENT 'script-sample, when the browser sends one',
                `origin`        VARCHAR(12) NOT NULL DEFAULT 'third-party' COMMENT 'first-party = OUR page refusing OUR code',
                `disposition`   VARCHAR(10) NOT NULL DEFAULT 'report' COMMENT 'enforce = it was actually blocked',
                `status`        ENUM('new','acknowledged','allowed','ignored') NOT NULL DEFAULT 'new',
                `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
                `first_seen`    DATETIME NOT NULL,
                `last_seen`     DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_csp_fingerprint` (`fingerprint`),
                KEY `idx_csp_last_seen` (`last_seen`),
                KEY `idx_csp_origin` (`origin`, `disposition`, `last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table csp_reports';
    });

    // -----------------------------------------------------------------------
    // Block / allow lists
    // -----------------------------------------------------------------------
    $run('+ table ip_rules', static function (): ?string {
        if (mig_table_exists('ip_rules')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `ip_rules` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kind`        ENUM('block','allow') NOT NULL DEFAULT 'block',
                `cidr`        VARCHAR(49) NOT NULL COMMENT 'As the owner typed it: 203.0.113.4 or 203.0.113.0/24',
                `family`      TINYINT UNSIGNED NOT NULL DEFAULT 4 COMMENT '4 | 6',
                `ip_start`    VARBINARY(16) NOT NULL COMMENT 'inet_pton of the first address in the range',
                `ip_end`      VARBINARY(16) NOT NULL COMMENT 'inet_pton of the last address in the range',
                `reason`      VARCHAR(190) NOT NULL COMMENT 'Required: a rule nobody can explain later is a rule nobody dares delete',
                `expires_at`  DATETIME NULL COMMENT 'NULL = permanent',
                `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
                `last_hit_at` DATETIME NULL,
                `created_by`  INT UNSIGNED NULL COMMENT 'admins.id',
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ip_rules_cidr` (`kind`, `cidr`),
                KEY `idx_ip_rules_range` (`family`, `ip_start`, `ip_end`),
                KEY `idx_ip_rules_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table ip_rules';
    });

    // -----------------------------------------------------------------------
    // Detector trips
    // -----------------------------------------------------------------------
    $run('+ table security_alerts', static function (): ?string {
        if (mig_table_exists('security_alerts')) {
            return null;
        }
        Database::query(
            "CREATE TABLE `security_alerts` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rule_key`     VARCHAR(40) NOT NULL COMMENT 'login_burst_ip, token_reuse, ...',
                `scope`        VARCHAR(12) NOT NULL DEFAULT 'global' COMMENT 'ip | account | global',
                `subject`      VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'The address or account the trip is about',
                `bucket_start` INT UNSIGNED NOT NULL COMMENT 'Unix time of the cooldown window - the dedupe key',
                `observed`     INT UNSIGNED NOT NULL DEFAULT 0,
                `threshold`    INT UNSIGNED NOT NULL DEFAULT 0,
                `window_secs`  INT UNSIGNED NOT NULL DEFAULT 0,
                `severity`     ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'high',
                `summary`      VARCHAR(255) NOT NULL DEFAULT '',
                `notified_at`  DATETIME NULL,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_alert_window` (`rule_key`, `subject`, `bucket_start`),
                KEY `idx_alert_created` (`created_at`),
                KEY `idx_alert_rule` (`rule_key`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '+ table security_alerts';
    });

    // -----------------------------------------------------------------------
    // Settings.
    //
    // Every default keeps today's behaviour and is chosen so that a NORMAL day
    // on this store never reaches it - see security_monitor_rules() for the
    // reasoning behind each number. The monitor is on, but it can only raise
    // events and queue mail; it never blocks anybody by itself.
    // -----------------------------------------------------------------------
    $settings = [
        // --- CSP report collection ---------------------------------------
        ['sec_csp_collect',        '1',              'boolean', 'collect violations at our own endpoint'],
        ['sec_csp_script_mode',    'unsafe-inline',  'text',    "unsafe-inline | nonce - see security_csp_policy()"],
        ['sec_csp_report_max',     '60',             'number',  'reports accepted per address per hour'],
        ['sec_csp_group_cap',      '400',            'number',  'distinct violation groups before new ones are dropped'],
        ['sec_csp_enforced_at',    '',               'text',    'when Enforce was last switched on'],
        ['sec_csp_probation_hours','24',             'number',  'how long after switching to Enforce the auto-revert watches'],
        ['sec_csp_selfblock_limit','5',              'number',  'first-party blocks during probation that trigger the revert'],
        ['sec_csp_revert_note',    '',               'text',    'why the last automatic revert happened'],

        // --- The security log --------------------------------------------
        ['sec_events_retention_days', '90',  'number',  'days of security_events kept; 0 = keep everything'],

        // --- Suspicious-activity detection --------------------------------
        ['sec_monitor_enabled',   '1',    'boolean', 'run the detectors at all'],
        ['sec_monitor_email',     '1',    'boolean', 'queue the owner an email when a detector trips'],
        ['sec_monitor_cooldown',  '3600', 'number',  'seconds before the same subject can raise the same alert again'],
        ['sec_monitor_last_run',  '',     'text',    'when bin/security-monitor.php last swept'],

        ['sec_alert_login_ip',      '30', 'number', 'failed sign-ins from one address in 15 minutes'],
        ['sec_alert_login_account', '12', 'number', 'failed sign-ins against one account in 15 minutes'],
        ['sec_alert_lockouts',      '10', 'number', 'throttle refusals across the store in 15 minutes'],
        ['sec_alert_csrf',          '15', 'number', 'CSRF failures from one address in 15 minutes'],
        ['sec_alert_rbac',          '5',  'number', 'permission denials for one admin in an hour'],
        ['sec_alert_webhook',       '3',  'number', 'payment webhook signature failures in an hour'],
        ['sec_alert_mfa',           '8',  'number', 'two-step failures against one account in 15 minutes'],
        ['sec_alert_token_reuse',   '1',  'number', 'reuse of a revoked token - one is already too many'],
        ['sec_alert_probe',         '5',  'number', 'installer / admin-panel probes from one address in an hour'],
        ['sec_alert_404',           '40', 'number', 'missing pages requested by one address in 15 minutes'],

        // --- IP rules -----------------------------------------------------
        // The count is read from the settings cache on EVERY request, so that
        // a store with no rules pays nothing at all: no file read, no query.
        ['sec_ip_rules_count', '0', 'number', 'how many live rules exist - the fast "is this feature in use" gate'],
        ['sec_ip_rules_gen',   '0', 'number', 'bumped on every change so the mirrored cache file can be spotted as stale'],
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

    // -----------------------------------------------------------------------
    // The alert email.
    //
    // Plain, short and free of anything secret: it says what tripped, how
    // often and where to look, and nothing that would turn an intercepted
    // mailbox into a second breach. Deliberately NOT seeded with the store's
    // marketing layout - an alert that looks like a newsletter gets ignored.
    // -----------------------------------------------------------------------
    $run('+ template security_alert', static function (): ?string {
        if (Database::exists('notification_templates', '`template_key` = :k AND `channel` = :c',
            ['k' => 'security_alert', 'c' => 'email'])) {
            return null;
        }

        $body = <<<'HTML'
<p>Something on <strong>{{store_name}}</strong> looked like an attack.</p>
<table role="presentation" style="border-collapse:collapse;margin:16px 0">
  <tr><td style="padding:4px 12px 4px 0;color:#666">What</td><td style="padding:4px 0"><strong>{{alert_title}}</strong></td></tr>
  <tr><td style="padding:4px 12px 4px 0;color:#666">Where</td><td style="padding:4px 0">{{alert_subject}}</td></tr>
  <tr><td style="padding:4px 12px 4px 0;color:#666">How much</td><td style="padding:4px 0">{{alert_observed}} in {{alert_window}} (the limit is {{alert_threshold}})</td></tr>
  <tr><td style="padding:4px 12px 4px 0;color:#666">When</td><td style="padding:4px 0">{{alert_time}}</td></tr>
</table>
<p>{{alert_advice}}</p>
<p><a href="{{alert_url}}">Open the security log</a></p>
<p style="color:#666;font-size:13px">You are getting this because your address is set as the store notification address.
Thresholds and this email can be changed in Admin &rsaquo; Security &rsaquo; Settings.</p>
HTML;

        $text = "Something on {{store_name}} looked like an attack.\n\n"
            . "What:     {{alert_title}}\n"
            . "Where:    {{alert_subject}}\n"
            . "How much: {{alert_observed}} in {{alert_window}} (limit {{alert_threshold}})\n"
            . "When:     {{alert_time}}\n\n"
            . "{{alert_advice}}\n\n"
            . "Security log: {{alert_url}}\n";

        Database::insert('notification_templates', [
            'template_key'   => 'security_alert',
            'name'           => 'Security alert',
            'description'    => 'Sent to the store notification address when a security detector trips.',
            'channel'        => 'email',
            'recipient_type' => 'admin',
            'subject'        => '[{{store_name}}] Security alert: {{alert_title}}',
            'body'           => $body,
            'body_text'      => $text,
            'variables'      => json_encode([
                'store_name', 'alert_title', 'alert_subject', 'alert_observed',
                'alert_threshold', 'alert_window', 'alert_time', 'alert_advice', 'alert_url',
            ]),
            'status'         => 'active',
            'is_system'      => 1,
        ]);

        return '+ template security_alert';
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_monitor_run();
    echo "Security monitor migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
