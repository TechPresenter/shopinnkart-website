<?php
/**
 * ShopInnKart - Migration: shipping hub fixes found in review.
 *
 *   shipments.courier_id           the aggregator sub-courier the admin chose.
 *                                  Without it a refused AWB retried later went
 *                                  to the aggregator's default courier instead.
 *   shipping_providers.webhook_slug
 *                                  an opaque id for the webhook URL. Shiprocket
 *                                  refuses a webhook URL containing
 *                                  "shiprocket", "sr" or "kr", so the provider
 *                                  code cannot be in it; a random slug also makes
 *                                  the URL unguessable.
 *   the Shiprocket provider row    the driver ships with the code, but nothing
 *                                  created its row, so a database built from
 *                                  schema.sql plus migrations could not
 *                                  configure it.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_22_shipping_fixes.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';
require_once __DIR__ . '/2026_09_21_shipping_hub.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_shipping_fixes_run(): array
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

    // The hub's own tables first, so this runs on a database that skipped it.
    $hub = migration_shipping_hub_run();
    $applied = array_merge($applied, $hub['applied']);
    $errors  = array_merge($errors, $hub['errors']);

    $run('+ shipments.courier_id', static function (): ?string {
        return mig_add_column(
            'shipments',
            'courier_id',
            "VARCHAR(40) NULL COMMENT 'Aggregator sub-courier chosen at booking; reused when the AWB is retried' AFTER `courier_name`"
        ) ? '+ shipments.courier_id' : null;
    });

    $run('+ shipping_providers.webhook_slug', static function (): ?string {
        return mig_add_column(
            'shipping_providers',
            'webhook_slug',
            "VARCHAR(40) NULL COMMENT 'Opaque id in the webhook URL' AFTER `webhook_enabled`"
        ) ? '+ shipping_providers.webhook_slug' : null;
    });

    $run('+ index shipping_providers.webhook_slug', static function (): ?string {
        return mig_add_index(
            'shipping_providers',
            'uq_shipping_provider_webhook_slug',
            "UNIQUE KEY `uq_shipping_provider_webhook_slug` (`webhook_slug`)"
        ) ? '+ index shipping_providers.webhook_slug' : null;
    });

    $run('+ Shiprocket provider', static function (): ?string {
        if (Database::exists('shipping_providers', '`code` = :c', ['c' => 'shiprocket'])) {
            return null;
        }
        Database::insert('shipping_providers', [
            'code'         => 'shiprocket',
            'name'         => 'Shiprocket',
            'status'       => 'inactive',
            'mode'         => 'test',
            'supports_cod' => 1,
            'sort_order'   => 10,
            'last_status'  => 'unknown',
        ]);
        return '+ Shiprocket provider';
    });

    $run('+ webhook slugs', static function (): ?string {
        $rows = Database::fetchAll("SELECT `id` FROM `shipping_providers` WHERE `webhook_slug` IS NULL OR `webhook_slug` = ''");
        foreach ($rows as $row) {
            Database::update('shipping_providers', ['webhook_slug' => bin2hex(random_bytes(12))],
                '`id` = :id', ['id' => (int) $row['id']]);
        }
        return $rows === [] ? null : '+ webhook slugs for ' . count($rows) . ' provider(s)';
    });

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_shipping_fixes_run();
    echo "Shipping fixes migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
