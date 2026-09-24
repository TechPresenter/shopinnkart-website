<?php
/**
 * ShopInnKart - Migration: backups, privacy rights and retention.
 *
 * Three related things the audit asked for, all storage-level:
 *
 *   - `backups` grows the columns a backup needs to be trustworthy: a
 *     checksum (so "is this file still good?" can be answered without a key),
 *     how it is protected, what it contained, whether it was taken by hand or
 *     by cron, and when it was last verified.
 *   - `privacy_requests`: one row per export or deletion a customer (or an
 *     admin on their behalf) asked for, with the hashed confirmation token,
 *     the 30-day deadline and what came of it.
 *   - the settings behind Admin > Security > Settings for the backup folder,
 *     retention count and passphrase, the self-service privacy switch, and a
 *     retention window per log table.
 *
 * Idempotent. Never DROPs. Safe on a live database: one new table, new
 * columns with defaults, configuration rows and email templates.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_24_security_privacy.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

// The migration reads the header of every existing dump and guards the storage
// folders, so it needs the same code the backup screen uses rather than a
// second opinion about the file format.
require_once INCLUDES_PATH . '/backup.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_privacy_run(): array
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
    //  1. What a backup record has to say for itself
    // -----------------------------------------------------------------------
    $columns = [
        'checksum'     => "CHAR(64) NULL COMMENT 'SHA-256 of the file as written; checking it needs no key'",
        'protection'   => "VARCHAR(20) NOT NULL DEFAULT 'app-key' COMMENT 'app-key | passphrase | plaintext'",
        'tables_count' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        'rows_count'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'source'       => "VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | scheduled'",
        'verified_at'  => 'DATETIME NULL',
        'verify_error' => 'VARCHAR(255) NULL',
    ];

    foreach ($columns as $column => $definition) {
        $run('+ backups.' . $column, static function () use ($column, $definition): ?string {
            return mig_add_column('backups', $column, $definition);
        });
    }

    // Existing rows were taken before checksums existed. Leaving `protection`
    // at its default would claim they are app-key encrypted, which the older
    // plaintext dumps are not, so it is read off the file itself.
    $run('~ backups: protection read from the files on disk', static function (): ?string {
        if (!mig_table_exists('backups') || !mig_column_exists('backups', 'protection')) {
            return null;
        }

        $dir     = STORAGE_PATH . '/backups';
        $changed = 0;
        foreach (Database::fetchAll('SELECT `id`, `filename` FROM `backups` WHERE `checksum` IS NULL') as $row) {
            $path = $dir . '/' . basename((string) $row['filename']);
            if (!is_file($path)) {
                continue;
            }
            Database::update('backups', [
                'protection' => BackupCipher::protection($path),
                'checksum'   => backup_checksum($path),
            ], '`id` = :id', ['id' => (int) $row['id']]);
            $changed++;
        }

        return $changed > 0 ? '~ backups: ' . $changed . ' existing file(s) checksummed' : null;
    });

    // -----------------------------------------------------------------------
    //  2. Privacy requests
    // -----------------------------------------------------------------------
    $run('+ table privacy_requests', static function (): ?string {
        if (mig_table_exists('privacy_requests')) {
            return null;
        }

        Database::query(
            "CREATE TABLE `privacy_requests` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`          INT UNSIGNED NOT NULL,
                `email`            VARCHAR(190) NOT NULL COMMENT 'The address at the time of asking; the account may be anonymised later',
                `name`             VARCHAR(150) NULL,
                `type`             ENUM('export','delete') NOT NULL,
                `status`           ENUM('pending','ready','completed','rejected','cancelled','expired','failed')
                                   NOT NULL DEFAULT 'pending',
                `token_hash`       CHAR(64) NULL COMMENT 'HMAC of the emailed confirmation token; cleared once spent',
                `token_expires_at` DATETIME NULL,
                `due_at`           DATETIME NULL COMMENT 'The 30 days the policy promises',
                `requested_by`     ENUM('customer','admin') NOT NULL DEFAULT 'customer',
                `admin_id`         INT UNSIGNED NULL,
                `admin_name`       VARCHAR(150) NULL,
                `reason`           VARCHAR(255) NULL,
                `request_ip`       VARCHAR(45) NULL,
                `file_name`        VARCHAR(190) NULL COMMENT 'Encrypted export bundle in storage/privacy',
                `file_bytes`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `file_checksum`    CHAR(64) NULL,
                `downloads`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `downloaded_at`    DATETIME NULL,
                `expires_at`       DATETIME NULL COMMENT 'When the bundle is deleted unread',
                `completed_at`     DATETIME NULL,
                `summary`          TEXT NULL,
                `error`            VARCHAR(500) NULL,
                `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_privacy_token` (`token_hash`),
                KEY `idx_privacy_user` (`user_id`, `type`, `status`),
                KEY `idx_privacy_open` (`status`, `due_at`),
                KEY `idx_privacy_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return '+ table privacy_requests';
    });

    // -----------------------------------------------------------------------
    //  3. Settings
    //
    //  Every one is created with the value that keeps today's behaviour, or
    //  the value the audit recommended where there was no behaviour at all.
    // -----------------------------------------------------------------------
    $settings = [
        // Backups
        ['sec_backup_path',       '',  'text',    'absolute folder outside the web root; blank = storage/backups'],
        ['sec_backup_keep',       '7', 'number',  'dumps kept on disk; 0 = keep every one'],
        ['sec_backup_passphrase', '',  'text',    'secret_encrypt()ed; blank = protect dumps with the application key'],
        ['sec_backup_alert_hours', '48', 'number', 'hours before "no recent backup" turns red'],

        // Privacy
        ['sec_privacy_self_service', '1', 'boolean', 'may a customer run their own export and deletion'],
        ['sec_privacy_bundle_days',  '7', 'number',  'days an export bundle waits to be collected'],

        // Retention. 180 days is the floor the audit set for anything that is
        // evidence; the rest are longer only where the record is still useful.
        ['sec_retention_security_events',    '365', 'number', 'days'],
        ['sec_retention_login_history',      '365', 'number', 'days'],
        ['sec_retention_activity_logs',      '730', 'number', 'days'],
        ['sec_retention_error_logs',         '180', 'number', 'days'],
        ['sec_retention_search_logs',        '180', 'number', 'days'],
        ['sec_retention_notification_queue', '180', 'number', 'days (sent and failed rows only)'],
        ['sec_retention_rate_limits',        '7',   'number', 'days'],
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
    //  4. The four emails a privacy request sends
    //
    //  Every one of them repeats privacy_erase_plan() rather than restating
    //  it in prose: the page, the email and the code that runs must say the
    //  same thing, and prose written twice diverges.
    // -----------------------------------------------------------------------
    $run('+ privacy notification templates', static function (): ?string {
        if (!mig_table_exists('notification_templates')) {
            return null;
        }

        $templates = [
            [
                'template_key'   => 'privacy_export_requested',
                'name'           => 'Data export - confirm',
                'description'    => 'Sent when a customer asks for a copy of their data. Carries the confirmation link.',
                'recipient_type' => 'customer',
                'subject'        => 'Confirm your data request on {{store_name}}',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
                    . '<p style="margin:0 0 16px 0">Someone asked for a copy of everything {{store_name}} '
                    . 'holds about you. If that was you, confirm it here:</p>'
                    . '<p style="margin:0 0 16px 0"><a href="{{confirm_url}}" style="background:#111827;color:#fff;'
                    . 'padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block">'
                    . 'Build my data file</a></p>'
                    . '<p style="margin:0 0 16px 0">The link works for {{expires_in}} and once only. '
                    . '<strong>If it was not you, do nothing</strong> &mdash; nothing is built and nothing is sent '
                    . 'until that link is clicked while you are signed in.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{customer_name}},\n\nSomeone asked for a copy of everything {{store_name}} holds about you.\n"
                    . "If that was you, confirm here:\n\n  {{confirm_url}}\n\n"
                    . "The link works for {{expires_in}} and once only. If it was not you, do nothing.\n\n-- {{store_name}}",
                'variables'      => '{{customer_name}}, {{confirm_url}}, {{expires_in}}, {{store_name}}',
            ],
            [
                'template_key'   => 'privacy_export_ready',
                'name'           => 'Data export - ready',
                'description'    => 'Sent when the export bundle has been built and is waiting to be downloaded.',
                'recipient_type' => 'customer',
                'subject'        => 'Your {{store_name}} data is ready to download',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
                    . '<p style="margin:0 0 16px 0">Your file is ready ({{file_size}}). Sign in and open '
                    . '<a href="{{download_url}}">Data &amp; Privacy</a> in your account to download it.</p>'
                    . '<p style="margin:0 0 16px 0">It waits until <strong>{{expires_on}}</strong> and is then deleted '
                    . 'from our server. We do not email the file itself &mdash; it holds every address and order '
                    . 'you have ever had, and email is not a safe place to put that.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{customer_name}},\n\nYour file is ready ({{file_size}}). Sign in and open "
                    . "Data & Privacy in your account to download it:\n\n  {{download_url}}\n\n"
                    . "It waits until {{expires_on}} and is then deleted. We do not email the file itself.\n\n-- {{store_name}}",
                'variables'      => '{{customer_name}}, {{download_url}}, {{expires_on}}, {{file_size}}, {{store_name}}',
            ],
            [
                'template_key'   => 'privacy_delete_requested',
                'name'           => 'Account deletion - confirm',
                'description'    => 'Sent when a customer asks to delete their account. Carries the confirmation link and says what survives.',
                'recipient_type' => 'customer',
                'subject'        => 'Confirm you want your {{store_name}} account deleted',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
                    . '<p style="margin:0 0 16px 0">Someone asked us to delete your {{store_name}} account. '
                    . '<strong>This cannot be undone.</strong></p>'
                    . '<p style="margin:0 0 8px 0">Some things have to stay, and we would rather tell you now '
                    . 'than after:</p>'
                    . '{{kept_list_html}}'
                    . '<p style="margin:0 0 16px 0">Everything else about you is removed or stripped of anything '
                    . 'that points at a person.</p>'
                    . '<p style="margin:0 0 16px 0"><a href="{{confirm_url}}" style="background:#B91C1C;color:#fff;'
                    . 'padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block">'
                    . 'Delete my account</a></p>'
                    . '<p style="margin:0 0 16px 0">The link works for {{expires_in}}. '
                    . '<strong>If it was not you, do nothing</strong> and tell us &mdash; nothing happens until '
                    . 'that link is clicked while you are signed in.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{customer_name}},\n\nSomeone asked us to delete your {{store_name}} account. "
                    . "This cannot be undone.\n\nSome things have to stay:\n\n{{kept_list}}\n\n"
                    . "Everything else about you is removed or stripped of anything that points at a person.\n\n"
                    . "Confirm here:\n\n  {{confirm_url}}\n\nThe link works for {{expires_in}}. "
                    . "If it was not you, do nothing and tell us.\n\n-- {{store_name}}",
                'variables'      => '{{customer_name}}, {{confirm_url}}, {{expires_in}}, {{kept_list_html}}, {{kept_list}}, {{store_name}}',
            ],
            [
                'template_key'   => 'privacy_delete_done',
                'name'           => 'Account deletion - done',
                'description'    => 'Sent to the address on file BEFORE anonymisation, so the last message reaches a real inbox.',
                'recipient_type' => 'customer',
                'subject'        => 'Your {{store_name}} account has been deleted',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
                    . '<p style="margin:0 0 16px 0">Your account is closed and everything that pointed at you '
                    . 'has been removed or stripped. You cannot sign in again, and this is the last message '
                    . 'we will send to this address.</p>'
                    . '<p style="margin:0 0 8px 0">What we still hold, and why:</p>'
                    . '{{kept_list_html}}'
                    . '<p style="margin:0 0 16px 0">Keep this email if you ever need to show what was done and when.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{customer_name}},\n\nYour account is closed and everything that pointed at you "
                    . "has been removed or stripped. You cannot sign in again, and this is the last message we will "
                    . "send to this address.\n\nWhat we still hold, and why:\n\n{{kept_list}}\n\n"
                    . "Keep this email if you ever need to show what was done and when.\n\n-- {{store_name}}",
                'variables'      => '{{customer_name}}, {{kept_list_html}}, {{kept_list}}, {{store_name}}',
            ],
            [
                'template_key'   => 'backup_failed_admin',
                'name'           => 'Scheduled backup failed',
                'description'    => 'Sent to the store address when bin/backup.php could not finish a run.',
                'recipient_type' => 'admin',
                'subject'        => '[{{store_name}}] The scheduled backup failed',
                'body'           => '<p style="margin:0 0 16px 0">The backup job on {{store_name}} did not finish '
                    . 'at {{event_time}}:</p>'
                    . '<p style="margin:0 0 16px 0;padding:12px 14px;background:#FEE2E2;border-radius:8px">'
                    . '<strong>{{error}}</strong></p>'
                    . '<p style="margin:0 0 16px 0">The last backup that did work was taken {{last_backup}}. '
                    . 'Until this is fixed there is nothing to restore from.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "The backup job on {{store_name}} did not finish at {{event_time}}:\n\n  {{error}}\n\n"
                    . "The last backup that did work was taken {{last_backup}}. Until this is fixed there is "
                    . "nothing to restore from.\n\n-- {{store_name}}",
                'variables'      => '{{error}}, {{event_time}}, {{last_backup}}, {{store_name}}',
            ],
        ];

        $added = 0;
        foreach ($templates as $template) {
            if (Database::exists('notification_templates', '`template_key` = :k', ['k' => $template['template_key']])) {
                continue;
            }
            Database::insert('notification_templates', $template + [
                'channel'        => 'email',
                'status'         => 'active',
                'is_system'      => 1,
                'attach_invoice' => 0,
            ]);
            $added++;
        }

        return $added > 0 ? '+ privacy notification templates (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    //  5. The folders, guarded on the way in
    //
    //  Created here rather than on first use, so a host that will not allow a
    //  folder to be written says so during the migration instead of the first
    //  time somebody needs a backup.
    // -----------------------------------------------------------------------
    $run('+ storage folders guarded', static function (): ?string {
        $made = [];
        foreach ([STORAGE_PATH . '/backups', STORAGE_PATH . '/privacy'] as $dir) {
            $existed = is_dir($dir);
            backup_dir_guard($dir);
            if (!$existed) {
                $made[] = basename($dir);
            }
        }

        return $made === [] ? null : '+ storage/' . implode(', storage/', $made);
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_privacy_run();
    echo "Backups, privacy and retention migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
