<?php
/**
 * ShopInnKart - Migration: admin-cluster security fixes.
 *
 * Schema and data this cluster's guards depend on:
 *
 *   - the four capability permissions the role editor now offers, so a role
 *     can be given security monitoring, script editing, backups or customer
 *     credentials without being handed Super Admin;
 *   - error_logs.resolved_at / resolved_by, so "mark resolved" can stop being
 *     a DELETE that erases the evidence of a failed attack;
 *   - the two security notification templates, so an account holder is told
 *     when someone changes their credentials;
 *   - encryption of payment_methods.config secrets that older code wrote in
 *     plaintext.
 *
 * Idempotent. Never DROPs. Safe to run on a live database: it adds columns and
 * rows, and the only existing data it rewrites is the gateway secrets, which
 * it re-reads through the same function the gateway uses.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_security_admin.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_security_admin_run(): array
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
    //  1. The capability permissions
    // -----------------------------------------------------------------------
    $run('+ capability permissions', static function (): ?string {
        $wanted = [
            ['settings', 'scripts', 'Edit Storefront Custom CSS / JavaScript', 910],
            ['system', 'backup', 'Create & Download Database Backups', 911],
            ['customers', 'credentials', 'Set a Customer Password or Email', 912],
            // Re-stated so an install that skipped 2026_09_22 still lands here.
            ['security', 'view', 'View Security Dashboard & Settings', 900],
            ['security', 'edit', 'Change Security Settings', 901],
        ];

        $added = 0;
        foreach ($wanted as [$module, $action, $label, $sort]) {
            if (Database::exists('admin_permissions', '`module` = :m AND `action` = :a', ['m' => $module, 'a' => $action])) {
                continue;
            }
            Database::insert('admin_permissions', [
                'module' => $module, 'action' => $action, 'label' => $label, 'sort_order' => $sort,
            ]);
            $added++;
        }

        return $added > 0 ? '+ capability permissions (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    //  2. Error log: resolvable instead of deletable
    // -----------------------------------------------------------------------
    $run('+ error_logs.resolved_at', static fn (): ?string => mig_add_column(
        'error_logs',
        'resolved_at',
        "DATETIME NULL DEFAULT NULL COMMENT 'When an admin marked this handled. The row is kept either way.'"
    ));

    $run('+ error_logs.resolved_by', static fn (): ?string => mig_add_column(
        'error_logs',
        'resolved_by',
        "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Admin name, plain text so it survives the account being deleted'"
    ));

    $run('+ index error_logs.resolved', static fn (): ?string => mig_add_index(
        'error_logs',
        'idx_error_logs_resolved',
        'INDEX `idx_error_logs_resolved` (`resolved_at`, `created_at`)'
    ));

    // -----------------------------------------------------------------------
    //  3. "Someone changed your account" templates
    // -----------------------------------------------------------------------
    $run('+ security notification templates', static function (): ?string {
        if (!mig_table_exists('notification_templates')) {
            return null;
        }

        $templates = [
            [
                'template_key'   => 'security_admin_alert',
                'name'           => 'Admin account changed',
                'description'    => 'Sent to an admin when another admin changes their password, email, role, status or lockout.',
                'recipient_type' => 'admin',
                'subject'        => 'Your {{store_name}} admin account was changed',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{admin_name}},</p>'
                    . '<p style="margin:0 0 16px 0">A change was just made to your admin account on {{store_name}}:</p>'
                    . '<p style="margin:0 0 16px 0;padding:12px 14px;background:#FEF3C7;border-radius:8px">'
                    . '<strong>{{change}}</strong></p>'
                    . '<p style="margin:0 0 16px 0">Made by <strong>{{actor_name}}</strong> on {{event_time}} from {{ip_address}}.</p>'
                    . '<p style="margin:0 0 16px 0">If this was expected, nothing to do. '
                    . '<strong>If it was not, tell a Super Admin immediately</strong> &mdash; somebody may have '
                    . 'taken over an admin session.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{admin_name}},\n\nA change was just made to your admin account on {{store_name}}:\n\n"
                    . "  {{change}}\n\nMade by {{actor_name}} on {{event_time}} from {{ip_address}}.\n\n"
                    . "If this was expected, nothing to do. If it was not, tell a Super Admin immediately.\n\n-- {{store_name}}",
                'variables'      => '{{admin_name}}, {{change}}, {{actor_name}}, {{event_time}}, {{ip_address}}, {{store_name}}',
            ],
            [
                'template_key'   => 'security_account_changed',
                'name'           => 'Account credentials changed by support',
                'description'    => 'Sent to a customer when an admin changes their password or sign-in email. Goes to the address on file BEFORE the change.',
                'recipient_type' => 'customer',
                'subject'        => 'Your {{store_name}} account details were changed',
                'body'           => '<p style="margin:0 0 16px 0">Hi {{customer_name}},</p>'
                    . '<p style="margin:0 0 16px 0">We are writing because {{change}}, on {{event_time}}.</p>'
                    . '<p style="margin:0 0 16px 0">If you asked our team for this, no action is needed. '
                    . '<strong>If you did not, contact us straight away</strong> and do not use any link '
                    . 'someone else sent you.</p>'
                    . '<p style="margin:0">&mdash; {{store_name}}</p>',
                'body_text'      => "Hi {{customer_name}},\n\nWe are writing because {{change}}, on {{event_time}}.\n\n"
                    . "If you asked our team for this, no action is needed. If you did not, contact us straight away.\n\n-- {{store_name}}",
                'variables'      => '{{customer_name}}, {{change}}, {{actor_name}}, {{event_time}}, {{store_name}}',
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

        return $added > 0 ? '+ security notification templates (' . $added . ')' : null;
    });

    // -----------------------------------------------------------------------
    //  4. Encrypt gateway secrets that older code stored in plaintext
    //
    //  gateway_config_encrypt() leaves an "enc:v1:" value alone, so a second
    //  run is a no-op and an install that is already encrypted is untouched.
    // -----------------------------------------------------------------------
    $run('~ gateway secrets encrypted', static function (): ?string {
        if (!mig_table_exists('payment_methods')) {
            return null;
        }

        $changed = 0;
        foreach (Database::fetchAll('SELECT `id`, `code`, `config` FROM `payment_methods`') as $method) {
            $raw = trim((string) ($method['config'] ?? ''));
            if ($raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;   // hand-written JSON we cannot safely rewrite
            }

            $encrypted = gateway_config_encrypt($decoded);
            if ($encrypted === $decoded) {
                continue;   // nothing was in plaintext
            }

            Database::update(
                'payment_methods',
                ['config' => (string) json_encode($encrypted, JSON_UNESCAPED_SLASHES)],
                '`id` = :id',
                ['id' => (int) $method['id']]
            );
            $changed++;
        }

        return $changed > 0 ? '~ gateway secrets encrypted (' . $changed . ' method(s))' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_security_admin_run();
    echo "Admin security migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
