<?php
/**
 * ShopInnKart - Migration: automatic courier selection and the rate calculator.
 *
 * Two things the shipping hub could not do: choose a courier on its own, and
 * say why it chose it.
 *
 *   shipments.selected_by       who picked this courier - an admin by hand, an
 *                               admin taking the recommendation, or the
 *                               unattended booker. Without it a consignment
 *                               cannot be told from one a machine made, which
 *                               is the first question asked when a parcel goes
 *                               to the wrong courier.
 *   shipments.selection_score   the score the winner had, 0-100.
 *   shipments.selection_reason  the same sentence the screen showed, stored as
 *                               it was at the time: the weights are settings
 *                               and the performance window moves, so the reason
 *                               cannot be recomputed later and still be true.
 *
 * The scoring weights are settings rather than constants because "cheapest" and
 * "fastest" are a business decision that changes with the season, and a store
 * running a sale wants speed weighted up for a fortnight without a deploy.
 *
 * Auto-booking ships OFF. It spends money and hands parcels to couriers with
 * nobody watching; it must be switched on deliberately.
 *
 * Idempotent. Never DROPs.
 *
 * Run from the project root:
 *     php database/migrations/2026_09_23_shipping_features.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/2026_08_13_invoice_email_system.php';
require_once __DIR__ . '/2026_09_21_shipping_hub.php';

/**
 * @return array{applied:string[], errors:string[]}
 */
function migration_shipping_features_run(): array
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
    $hub     = migration_shipping_hub_run();
    $applied = array_merge($applied, $hub['applied']);
    $errors  = array_merge($errors, $hub['errors']);

    // =======================================================================
    //  1. How this consignment's courier was chosen
    // =======================================================================
    $columns = [
        // Nullable on purpose: every shipment booked before this migration was
        // chosen by an admin through a screen that recorded nothing, and
        // back-filling them 'manual' would invent a fact.
        'selected_by' => "ENUM('manual','recommended','auto') NULL "
            . "COMMENT 'manual = admin overrode the recommendation, recommended = admin took it, auto = booked unattended' "
            . "AFTER `courier_id`",
        'selection_score' => "DECIMAL(5,2) NULL COMMENT 'The winning score out of 100, as it stood at booking' "
            . "AFTER `selected_by`",
        'selection_reason' => "VARCHAR(500) NULL COMMENT 'Why this courier, in the words the screen showed' "
            . "AFTER `selection_score`",
    ];
    foreach ($columns as $column => $definition) {
        $run('+ shipments.' . $column, static function () use ($column, $definition): ?string {
            return mig_add_column('shipments', $column, $definition) ? '+ shipments.' . $column : null;
        });
    }

    // The auto-book sweep asks for confirmed orders with no live consignment.
    // idx_shipment_order already covers the shipment side; this covers the
    // orders side, which otherwise scans every order in the table.
    $run('+ index orders.status/created_at', static function (): ?string {
        return mig_add_index(
            'orders',
            'idx_order_status_created',
            'KEY `idx_order_status_created` (`status`, `created_at`)'
        ) ? '+ index orders.status/created_at' : null;
    });

    // =======================================================================
    //  2. The settings the scoring and the unattended booker read
    // =======================================================================
    //  The four weights are relative, not percentages: they are normalised at
    //  read time, so 40/25/25/10 and 4/2.5/2.5/1 mean the same thing and an
    //  admin who sets them all to zero gets the defaults back rather than a
    //  division by zero.
    $settings = [
        // key, value, type, label, sort
        ['shipping_select_weight_cost',        '40', 'number',  'Selection weight: cost',                  10],
        ['shipping_select_weight_speed',       '25', 'number',  'Selection weight: delivery speed',        11],
        ['shipping_select_weight_reliability', '25', 'number',  'Selection weight: courier performance',   12],
        ['shipping_select_weight_rating',      '10', 'number',  'Selection weight: courier rating',        13],
        ['shipping_select_window_days',        '90', 'number',  'Performance window (days)',               14],
        ['shipping_select_min_shipments',      '20', 'number',  'Shipments needed before judging a courier', 15],
        ['shipping_auto_book_enabled',         '0',  'boolean', 'Ship confirmed orders automatically',      16],
        ['shipping_auto_book_cod_max',    '10000',   'number',  'Auto-ship COD ceiling',                    17],
        // The age floor on the unattended sweep. Seven days, not unlimited:
        // the switch ships off, so the first pass after it is turned on would
        // otherwise book couriers for the shop's whole tail of old confirmed
        // orders - see shipping_autoship_max_age_days().
        ['shipping_auto_book_max_age_days', '7',     'number',  'Auto-ship only orders newer than (days)',  18],
    ];
    foreach ($settings as [$key, $value, $type, $label, $sort]) {
        $run('+ setting ' . $key, static function () use ($key, $value, $type, $label, $sort): ?string {
            if (Database::exists('settings', '`setting_key` = :k', ['k' => $key])) {
                return null;
            }
            Database::insert('settings', [
                'setting_group' => 'shipping',
                'setting_key'   => $key,
                'setting_value' => $value,
                'setting_type'  => $type,
                'label'         => $label,
                'sort_order'    => $sort,
            ]);
            return '+ setting ' . $key;
        });
    }

    if ($applied !== []) {
        settings_cache_generation(true);
        cache_bust();
    }

    return ['applied' => $applied, 'errors' => $errors];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $result = migration_shipping_features_run();
    echo "Shipping features migration\n";
    foreach ($result['applied'] as $line) {
        echo '  ' . $line . "\n";
    }
    foreach ($result['errors'] as $line) {
        echo '  ERROR ' . $line . "\n";
    }
    printf("\n%d change(s), %d error(s)\n", count($result['applied']), count($result['errors']));
    exit($result['errors'] === [] ? 0 : 1);
}
