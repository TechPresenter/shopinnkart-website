<?php
/**
 * ShopInnKart - Migration: analytics & privacy (phase B1).
 *
 * B1 is the consent layer only, so this migration creates no tables: the
 * an_* tracking tables arrive with the collector in B2. What it does create is
 * the settings the consent layer reads, and the permission trio the analytics
 * screens will need, so that B2 and B3 find them already present.
 *
 * Two of the keys are new to the UI rather than new to the code:
 *   - google_tag_manager_id was read by includes/footer.php from the first
 *     build and had no field anywhere in the admin, so the only way to set it
 *     was an UPDATE on the table;
 *   - clarity_project_id is new, and is gated by the same marketing category.
 *
 * analytics_mode installs as `anonymous`, which is the owner's answer to §17
 * Q1: cookieless counting, IP anonymised, DNT/GPC honoured. That mode puts no
 * identifier on the device, so it adds no category to the consent banner - the
 * banner exists for the third-party tags. Nothing reads the mode yet; the
 * collector that will is B2.
 *
 * Idempotent. Never DROPs. Existing values are never overwritten, so re-running
 * it on a store where the owner has already changed a setting is a no-op.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_analytics.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_analytics_run(): array
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

    /**
     * key => [value, group, type]. The value is the SHIPPED default and is
     * written only when the key is absent.
     */
    $settings = [
        // --- our own analytics ------------------------------------------
        'analytics_mode'        => ['anonymous', 'analytics', 'select'],
        'analytics_honor_dnt'   => ['1',         'analytics', 'boolean'],

        // --- the consent banner -----------------------------------------
        // Blank means "use the wording in includes/consent.php", which is the
        // wording the Cookie Policy was rewritten to match. An owner who
        // overrides it here owns keeping the two in step.
        'consent_banner_title'  => ['', 'analytics', 'text'],
        'consent_banner_text'   => ['', 'analytics', 'textarea'],

        // --- third-party tag ids the consent layer gates -----------------
        // google_analytics_id and meta_pixel_id already exist in the `seo`
        // group and stay owned by Settings > SEO; only the two that had no
        // home are created here.
        'google_tag_manager_id' => ['', 'analytics', 'text'],
        'clarity_project_id'    => ['', 'analytics', 'text'],
    ];

    foreach ($settings as $key => [$value, $group, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $group, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, $group, $type);
            return '+ setting ' . $key . ($value !== '' ? ' = ' . $value : '');
        });
    }

    $run('+ analytics permissions', static function (): ?string {
        // Registered here rather than in B2 so that a role can be prepared
        // before the screens exist. Super Admin holds "*" and needs none of
        // them; every other role gets them in Admin > System > Roles.
        $permissions = [
            ['view',     'View Analytics',                 920],
            ['export',   'Export Analytics Data',          921],
            ['settings', 'Change Analytics & Privacy Settings', 922],
        ];

        $added = 0;
        foreach ($permissions as [$action, $label, $sort]) {
            if (Database::exists('admin_permissions', '`module` = :m AND `action` = :a', ['m' => 'analytics', 'a' => $action])) {
                continue;
            }
            Database::insert('admin_permissions', [
                'module' => 'analytics', 'action' => $action, 'label' => $label, 'sort_order' => $sort,
            ]);
            $added++;
        }

        return $added > 0 ? '+ analytics permissions (' . $added . ')' : null;
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_analytics_run();
    echo "Analytics migration (B1: consent layer)\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  nothing to do\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
