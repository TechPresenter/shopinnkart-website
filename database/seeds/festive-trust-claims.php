<?php
/**
 * ShopInnKart - Make the trust claims true.
 *
 * The trust_features rows are seeded marketing copy from the electronics store,
 * and they render on EVERY page (the strip in the footer's top band, the hero
 * row on the homepage). Cross-checked against the store's own settings, six of
 * the ten were false:
 *
 *   "30 Day Returns" / "30 day no-questions returns"
 *        -> return_window_days = 7
 *   "Secure Payment - UPI, cards, netbanking and EMI"
 *        -> PaymentGatewayFactory::available() = Cash on Delivery, only
 *   "Secure Payments - 256-bit encrypted checkout"
 *        -> there is no online checkout to encrypt
 *   "24/7 Support" / "Talk to a real person, any time"
 *        -> business_hours = "Mon - Sat: 9:00 AM to 8:00 PM IST"
 *   "Easy EMI - No-cost EMI from Rs999 per month"
 *        -> no EMI gateway, and every product costs Rs149-Rs399, so the monthly
 *           instalment exceeded the price of the entire catalogue
 *   "100% Genuine - Official India warranty on every item"
 *        -> electronics framing; nothing in the store carries a manufacturer
 *           India warranty
 *
 * A shop that promises a 30-day return window and honours 7 is not a design
 * problem. Under the Consumer Protection Act 2019 a misleading advertisement is
 * actionable, and the CCPA can act on it, so this corrects the copy to what the
 * store actually does.
 *
 * Two rules were followed when rewriting:
 *   1. Every remaining claim is DERIVED from a setting, so it is checkable.
 *   2. A claim that cannot be substantiated is DEACTIVATED, not reworded into
 *      something vaguer. Inventing a softer promise is still inventing one.
 *
 * NOTE: this writes static text. If the operator later changes the return
 * window or enables an online gateway, these rows must be updated too - re-run
 * this script and it will recompute them from the settings.
 *
 *     php database/seeds/festive-trust-claims.php          apply
 *     php database/seeds/festive-trust-claims.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/**
 * Build the true claim set from the store's own configuration.
 *
 * Returns id => [title, subtitle, status].
 */
function festive_trust_plan(): array
{
    $returnDays   = (int) setting('return_window_days', 7);
    $returnsOn    = (string) setting('returns_enabled', '1') === '1';
    $freeShipOn   = (string) setting('free_shipping_enabled', '1') === '1';
    $freeShipFrom = (int) setting('free_shipping_threshold', 999);
    $hours        = trim((string) setting('business_hours', ''));

    $gateways = array_column(PaymentGatewayFactory::available(), 'code');
    $codOnly  = $gateways === ['cod'];

    $plan = [];

    // --- hero row -------------------------------------------------------
    // "100% genuine, brand sealed stock" is an electronics warranty claim.
    // Nothing here is brand sealed in that sense, so it goes.
    $plan[1] = ['Handpicked Lighting', 'Festive and decorative lights, chosen and checked', STATUS_ACTIVE];

    $plan[2] = $codOnly
        ? ['Cash on Delivery', 'Pay when your order reaches you', STATUS_ACTIVE]
        : ['Secure Payments', 'Encrypted checkout on every order', STATUS_ACTIVE];

    $plan[3] = $returnsOn
        ? ['Easy Returns', $returnDays . ' day return window on every order', STATUS_ACTIVE]
        : ['Easy Returns', '', STATUS_INACTIVE];

    $plan[4] = ['Customer Support', $hours !== '' ? $hours : 'Call or email our support team', STATUS_ACTIVE];

    // --- footer strip ---------------------------------------------------
    $plan[5] = $freeShipOn
        ? ['Free Delivery', 'On orders above ' . money($freeShipFrom), STATUS_ACTIVE]
        : ['Fast Delivery', 'Dispatched within one working day', STATUS_ACTIVE];

    $plan[6] = $returnsOn
        ? [$returnDays . ' Day Returns', 'Raise a request from your orders page', STATUS_ACTIVE]
        : ['Returns', '', STATUS_INACTIVE];

    $plan[7] = $codOnly
        ? ['Cash on Delivery', 'Pay the courier when your order arrives', STATUS_ACTIVE]
        : ['Secure Payment', 'Encrypted checkout', STATUS_ACTIVE];

    $plan[8] = ['Customer Support', $hours !== '' ? $hours : 'Call or email our support team', STATUS_ACTIVE];

    // Cannot be substantiated -> off, rather than softened into a vaguer promise.
    $plan[9]  = ['100% Genuine', 'Official India warranty on every item', STATUS_INACTIVE];
    $plan[10] = ['Easy EMI', 'No-cost EMI from ₹999 per month', STATUS_INACTIVE];

    return $plan;
}

function festive_trust_run(bool $dryRun = false): array
{
    $report = ['changed' => [], 'deactivated' => [], 'missing' => [], 'unchanged' => 0];

    foreach (festive_trust_plan() as $id => [$title, $subtitle, $status]) {
        $row = Database::fetch(
            'SELECT `id`, `title`, `subtitle`, `status` FROM `trust_features` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );

        if (!$row) {
            $report['missing'][] = $id;
            continue;
        }

        $same = (string) $row['title'] === $title
             && (string) $row['subtitle'] === $subtitle
             && (string) $row['status'] === $status;

        if ($same) {
            $report['unchanged']++;
            continue;
        }

        $entry = [
            'id'   => $id,
            'from' => $row['title'] . ' — ' . $row['subtitle'] . ' [' . $row['status'] . ']',
            'to'   => $title . ' — ' . $subtitle . ' [' . $status . ']',
        ];

        if ($status === STATUS_INACTIVE) {
            $report['deactivated'][] = $entry;
        } else {
            $report['changed'][] = $entry;
        }

        if (!$dryRun) {
            Database::update('trust_features', [
                'title'    => $title,
                'subtitle' => $subtitle,
                'status'   => $status,
            ], '`id` = :id', ['id' => $id]);
        }
    }

    if (!$dryRun) {
        if (function_exists('admin_after_write')) {
            admin_after_write();
        } elseif (function_exists('cache_bust')) {
            cache_bust();
        }
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_trust_run(in_array('--dry', $argv, true));

    foreach ($result['changed'] as $c) {
        printf('  corrected  #%d%s               %s%s            -> %s%s', $c['id'], PHP_EOL, $c['from'], PHP_EOL, $c['to'], PHP_EOL);
    }
    foreach ($result['deactivated'] as $c) {
        printf('  DISABLED   #%d  %s  (cannot be substantiated)%s', $c['id'], $c['from'], PHP_EOL);
    }
    foreach ($result['missing'] as $id) {
        printf('  missing    #%d%s', $id, PHP_EOL);
    }
    printf('%sCorrected: %d   Disabled: %d   Already true: %d%s',
        PHP_EOL, count($result['changed']), count($result['deactivated']), $result['unchanged'], PHP_EOL);
    exit(0);
}
