<?php
/**
 * ShopInnKart - Rescale the offers to the catalogue that actually exists.
 *
 * The coupons and the welcome popup were written for the seeded electronics
 * store, where a cart ran to tens of thousands of rupees. The catalogue is now
 * festive lighting priced ₹149-₹399, and the entire eleven-product range adds
 * up to roughly ₹3,400 - so every offer on the site was unreachable:
 *
 *     SAVE500      ₹500 off over ₹4,999    <- the popup advertised this one
 *     WELCOME10    10% off over ₹2,000     ~6 items
 *     FIRST15      15% off over ₹1,999     ~6 items
 *     FESTIVE25    25% off over ₹9,999     more than the whole catalogue
 *     APPLE5       5%  off over ₹19,999    unreachable, and Apple is not sold
 *     AUDIO20      20% off over ₹2,999     unreachable, and audio is not sold
 *     LAPTOP2000   ₹2,000 off over ₹49,999 unreachable, and laptops are not sold
 *
 * A customer who entered the code the popup handed them got "minimum order not
 * met" every time.
 *
 * This retunes the general offers to the real price range and retires the three
 * that name departments the store no longer has. The amounts below are a
 * starting point chosen to be *reachable* (roughly two to four items), not a
 * margin decision - review them in Admin > Marketing > Coupons.
 *
 * Idempotent: re-running sets the same values and finds the retired codes gone.
 *
 *     php database/seeds/festive-offers.php          apply
 *     php database/seeds/festive-offers.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/**
 * code => [minimum_order, value, maximum_discount|null, description]
 *
 * Minimums land at ~2-4 items so the offer is winnable on a real basket.
 */
function festive_offer_plan(): array
{
    return [
        'WELCOME10' => [599.00,  10.00, 150.00, '10% off your first order over ₹599'],
        'FIRST15'   => [899.00,  15.00, 200.00, '15% off orders over ₹899'],
        'FESTIVE25' => [1499.00, 25.00, 400.00, '25% off festive orders over ₹1,499'],
        'SAVE500'   => [1299.00, 150.00, null,  '₹150 off orders over ₹1,299'],
        'FREESHIP'  => [499.00,  0.00,  null,   'Free delivery on orders over ₹499'],
    ];
}

/** Codes that name departments this store does not stock. */
function festive_retired_codes(): array
{
    return ['APPLE5', 'AUDIO20', 'LAPTOP2000'];
}

/** The welcome popup, rewritten to promise something that can be redeemed. */
function festive_popup_plan(): array
{
    return [
        'title'    => 'Get 10% off your first order',
        'subtitle' => 'Be first to hear when new festive collections land',
        'content'  => '<p>Join our list and we will email your code — 10% off any order over ₹599.</p>'
                    . '<ul><li>Early access to festive sales</li>'
                    . '<li>Price drop alerts on your wishlist</li>'
                    . '<li>No spam — two emails a week at most</li></ul>',

        // Three fields cleared, and each removes a block from the card.
        //
        // The popup was asking for three different conversions at once: enter
        // your email, copy a code, and go to the shop. It also printed the
        // coupon in a dashed box directly under a sentence promising to email
        // it — so the form had nothing left to offer. Seven stacked blocks is
        // what made the card outgrow the viewport and grow a scrollbar.
        //
        // The email field is the one action, so the code reveal and the CTA go.
        // (coupon_code was WELCOME500, which was not a real coupon anyway — the
        // code it handed out was rejected at checkout.)
        'coupon_code' => '',
        'button_text' => '',
        'button_url'  => '',
    ];
}

function festive_offers_run(bool $dryRun = false): array
{
    $report = ['retuned' => [], 'retired' => [], 'missing' => [], 'popup' => null];

    foreach (festive_offer_plan() as $code => [$min, $value, $maxDiscount, $description]) {
        $row = Database::fetch('SELECT `id`, `minimum_order`, `value` FROM `coupons` WHERE `code` = :c LIMIT 1', ['c' => $code]);
        if (!$row) {
            $report['missing'][] = $code;
            continue;
        }

        $report['retuned'][] = [
            'code' => $code,
            'from' => sprintf('value %s / min %s', $row['value'], $row['minimum_order']),
            'to'   => sprintf('value %.2f / min %.2f', $value, $min),
        ];

        if (!$dryRun) {
            Database::update('coupons', [
                'minimum_order'    => $min,
                'value'            => $value,
                'maximum_discount' => $maxDiscount,
                'description'      => $description,
            ], '`id` = :id', ['id' => (int) $row['id']]);
        }
    }

    foreach (festive_retired_codes() as $code) {
        $id = Database::fetchColumn('SELECT `id` FROM `coupons` WHERE `code` = :c LIMIT 1', ['c' => $code]);
        if (!$id) {
            continue;   // already retired - idempotent re-run
        }
        $report['retired'][] = $code;
        if (!$dryRun) {
            // Restrictions reference the coupon but carry no FK on some
            // installs, so clear them before the parent row.
            Database::delete('coupon_restrictions', '`coupon_id` = :id', ['id' => (int) $id]);
            Database::delete('coupons', '`id` = :id', ['id' => (int) $id]);
        }
    }

    $popup = Database::fetch("SELECT `id` FROM `popups` WHERE `popup_type` = 'newsletter' ORDER BY `id` LIMIT 1");
    if ($popup) {
        $plan = festive_popup_plan();
        $report['popup'] = $plan['title'];
        if (!$dryRun) {
            Database::update('popups', $plan, '`id` = :id', ['id' => (int) $popup['id']]);
        }
    }

    if (function_exists('admin_after_write')) {
        admin_after_write();
    } elseif (function_exists('cache_bust')) {
        cache_bust();
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_offers_run(in_array('--dry', $argv, true));

    foreach ($result['retuned'] as $r) {
        printf('  retuned  %-12s %s  ->  %s%s', $r['code'], $r['from'], $r['to'], PHP_EOL);
    }
    foreach ($result['retired'] as $code) {
        echo '  retired  ', $code, PHP_EOL;
    }
    foreach ($result['missing'] as $code) {
        echo '  missing  ', $code, ' (no such coupon)', PHP_EOL;
    }
    if ($result['popup'] !== null) {
        echo PHP_EOL, 'Popup now reads: ', $result['popup'], PHP_EOL;
    }
    exit(0);
}
