<?php
/**
 * ShopInnKart - Migration: the Integrations screen.
 *
 * Brief item 4. The ids for Google Analytics and the Meta Pixel were scattered
 * across Settings > SEO and Settings > Analytics, and Meta CAPI, Google Ads
 * conversions and a custom webhook had nowhere to live at all. This creates the
 * keys the new screen owns.
 *
 * WHAT IT DOES NOT DO, DELIBERATELY
 * ---------------------------------
 * It does not move google_analytics_id, meta_pixel_id, google_site_verification
 * (Settings > SEO) or clarity_project_id / google_tag_manager_id (Settings >
 * Analytics). Those screens still declare those keys in their own field spec, so
 * moving the VALUE here without also deleting the field there would leave two
 * screens writing one key - and then the id depends on which page was saved
 * last. Ownership stays where the writer is; Settings > Integrations shows those
 * five read-only and links to their owner.
 *
 * THE THREE VERIFICATION KEYS ARE A DIFFERENT CASE
 * ------------------------------------------------
 * bing_site_verification, pinterest_site_verification and yandex_site_verification
 * were already READ by includes/seo.php and written by nothing: no admin screen
 * anywhere had a field for them, so the only way to set one was an UPDATE on the
 * table. They had no owner to conflict with, so Integrations becomes it.
 *
 * Their rows already exist in the `seo` group on installed stores. setting_save()
 * fixes a row's group at insert and never moves it, so this migration re-groups
 * those three by hand - otherwise settings_group('integrations') would not see
 * them. Nothing reads them by group (includes/seo.php and Settings > SEO Health
 * both read them by key), so the move is safe.
 *
 * Idempotent. Never DROPs, never overwrites a value an owner has set.
 *
 *     php database/migrations/2026_09_27_integrations.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

/** The group every key this screen owns is filed under. */
const INTEGRATIONS_GROUP = 'integrations';

/**
 * key => [shipped value, storage type]. The value is written only when the key
 * is absent, so re-running this on a configured store is a no-op.
 */
const INTEGRATIONS_SETTINGS = [
    // --- webmaster verification, newly given a home ---------------------
    'bing_site_verification'      => ['', 'text'],
    'pinterest_site_verification' => ['', 'text'],
    'yandex_site_verification'    => ['', 'text'],

    // --- Google Ads. Gated by includes/consent.php like every other tag --
    'google_ads_conversion_id'    => ['', 'text'],

    // --- Meta Conversions API. Stored, NOT sent: no sender is built, and
    //     the screen says so rather than implying events are flowing. -----
    'meta_capi_dataset_id'        => ['', 'text'],
    'meta_capi_token'             => ['', 'text'],
    'meta_capi_test_code'         => ['', 'text'],

    // --- custom webhook. Also stored and not sent. -----------------------
    'integration_webhook_url'     => ['', 'text'],
    'integration_webhook_secret'  => ['', 'text'],
];

/** The keys that already existed in another group and belong to this screen. */
const INTEGRATIONS_REGROUP = [
    'bing_site_verification',
    'pinterest_site_verification',
    'yandex_site_verification',
];

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_integrations_run(): array
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

    foreach (INTEGRATIONS_SETTINGS as $key => [$value, $type]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $type): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            setting_save($key, $value, INTEGRATIONS_GROUP, $type);
            return '+ setting ' . $key;
        });
    }

    foreach (INTEGRATIONS_REGROUP as $key) {
        $run('~ regroup ' . $key, static function () use ($key): ?string {
            $current = Database::fetchColumn(
                'SELECT `setting_group` FROM `settings` WHERE `setting_key` = :k',
                ['k' => $key]
            );
            if ($current === null || $current === INTEGRATIONS_GROUP) {
                return null;
            }
            Database::update(
                'settings',
                ['setting_group' => INTEGRATIONS_GROUP],
                '`setting_key` = :k',
                ['k' => $key]
            );
            return '~ regroup ' . $key . ': ' . $current . ' -> ' . INTEGRATIONS_GROUP;
        });
    }

    $run('= settings cache', static function (): ?string {
        settings_cache_generation(true);
        if (function_exists('cache_bust')) {
            cache_bust();
        }
        return null;
    });

    // No new permissions. Reading needs settings.view and changing needs
    // settings.edit, both of which already exist; nothing on this screen takes
    // operator-authored code, so settings.scripts is not required either.

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/_cli.php';
    migration_refuse_arguments($argv);

    $result = migration_integrations_run();
    echo "Integrations migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ! ' . $line . "\n";
    }
    if ($result['applied'] === [] && $result['errors'] === []) {
        echo "  (nothing to do - already applied)\n";
    }
    exit($result['errors'] === [] ? 0 : 1);
}
