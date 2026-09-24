<?php
/**
 * ShopInnKart Admin - Shipping > Rate calculator.
 *
 * "What would it cost to send this box there?" - asked before an order exists,
 * for a customer on the phone, for a quote, or to see whether a lane is served
 * at all. No order id, no booking, nothing written.
 *
 * Choices worth knowing before changing this file:
 *
 *   - It is a GET form. Everything here is a question, so the answer is
 *     linkable and shareable, and the back button works. Nothing is posted
 *     because nothing is saved.
 *
 *   - Nothing is cached, at either end. A rate is a courier's answer about
 *     right now - fuel surcharges, wallet balance, a lane that stopped being
 *     serviceable this morning - and a cached one is a price we would quote a
 *     customer and then not get. security_no_store() says the same to the
 *     browser, which otherwise serves the back button a stale price.
 *
 *   - The scoring is the same shipping_score_quote() the booking screen and
 *     the unattended booker use, so a courier this screen calls best is the
 *     one the booking screen pre-selects. A second copy of the weighting here
 *     would drift within a week.
 *
 *   - Serviceability is read from whether the courier quoted, not from a
 *     second round of calls. Asking twice would double a real integration's
 *     API bill for an answer the rates call has already given; a courier that
 *     did not quote says why in its own words below the table.
 *
 * Permission: orders.view, the same as the booking screen. This screen shows
 * prices, not credentials, and the people who quote deliveries are the people
 * who pack them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';

// A price must never come back from a cache - see the docblock.
security_no_store();

/**
 * Read the parcel out of the query string.
 *
 * The bounds are the booking screen's, deliberately: a calculator that happily
 * quotes a 90 kg parcel would be quoting something that can never be booked.
 *
 * @return array{0:array, 1:array<string,string>, 2:array<string,string>}
 */
$readInput = static function (): array {
    $text = static function (string $key): string {
        $value = input($key, '');
        return is_string($value) ? trim($value) : '';
    };

    $raw = [];
    foreach (['origin_pin', 'destination_pin', 'weight_grams', 'length_cm', 'width_cm', 'height_cm',
              'payment', 'declared_value'] as $key) {
        $raw[$key] = $text($key);
    }

    $values = [
        'origin_pin'      => '',
        'destination_pin' => '',
        'weight_grams'    => 500,
        'length_cm'       => null,
        'width_cm'        => null,
        'height_cm'       => null,
        'payment'         => $raw['payment'] === 'cod' ? 'cod' : 'prepaid',
        'declared_value'  => 0.0,
    ];
    $errors = [];

    foreach (['origin_pin' => 'Pickup PIN code', 'destination_pin' => 'Delivery PIN code'] as $key => $label) {
        if ($raw[$key] === '') {
            continue;
        }
        if (preg_match('/^[1-9][0-9]{5}$/', $raw[$key]) !== 1) {
            $errors[$key] = $label . ' must be six digits, and cannot start with a zero.';
            continue;
        }
        $values[$key] = $raw[$key];
    }

    if ($raw['weight_grams'] !== '') {
        $grams = ctype_digit($raw['weight_grams']) ? (int) $raw['weight_grams'] : 0;
        if ($grams < 50 || $grams > 50000) {
            $errors['weight_grams'] = 'Weight must be whole grams, between 50 and 50,000.';
        } else {
            $values['weight_grams'] = $grams;
        }
    }

    // Dimensions come as a set: a courier bills the larger of dead and
    // volumetric weight, and two sides out of three cannot be volumetric.
    $given = 0;
    foreach (['length_cm' => 'Length', 'width_cm' => 'Width', 'height_cm' => 'Height'] as $key => $label) {
        if ($raw[$key] === '') {
            continue;
        }
        $given++;
        if (!is_numeric($raw[$key]) || (float) $raw[$key] < 1 || (float) $raw[$key] > 300) {
            $errors[$key] = $label . ' must be between 1 and 300 cm.';
            continue;
        }
        $values[$key] = round((float) $raw[$key], 1);
    }
    if ($given > 0 && $given < 3) {
        $errors['dimensions'] = 'Give all three dimensions, or leave all three blank.';
    }

    if ($raw['declared_value'] !== '') {
        if (!is_numeric($raw['declared_value']) || (float) $raw['declared_value'] < 0
            || (float) $raw['declared_value'] > 10000000) {
            $errors['declared_value'] = 'Declared value must be a number between 0 and 1,00,00,000.';
        } else {
            $values['declared_value'] = round((float) $raw['declared_value'], 2);
        }
    }

    return [$values, $errors, $raw];
};

[$parcel, $errors, $raw] = $readInput();

$isCod = $parcel['payment'] === 'cod';
$asked = $raw['destination_pin'] !== '';

// The quote itself. Only when something was asked AND the form is sound: a
// courier call on input we already know is wrong is a call for nothing.
$quote  = null;
$scored = null;
if ($asked && $errors === []) {
    $quote = shipping_rate_quote([
        'origin_pin'      => $parcel['origin_pin'],
        'destination_pin' => $parcel['destination_pin'],
        'weight_grams'    => $parcel['weight_grams'],
        'length_cm'       => $parcel['length_cm'],
        'width_cm'        => $parcel['width_cm'],
        'height_cm'       => $parcel['height_cm'],
        'is_cod'          => $isCod,
        'cod_amount'      => $isCod ? $parcel['declared_value'] : 0,
        'declared_value'  => $parcel['declared_value'],
    ]);
    $scored = shipping_score_quote($quote);
}

// What the origin box shows when the admin has not typed one: where parcels
// actually leave from. Asked of the quote when there is one, so the box and
// the prices below can never disagree.
$defaultOrigin = (string) ($quote['origin_pin'] ?? '');
if ($defaultOrigin === '') {
    $default       = shipping_default_provider();
    $defaultOrigin = (string) ($default['pickup_pincode'] ?? '') ?: (string) setting('store_pincode', '');
}

$weights   = $scored['weights'] ?? shipping_selection_weights();
$providers = (array) ($quote['providers'] ?? []);
$rates     = (array) ($scored['ranked'] ?? []);
$available = ShippingProviderFactory::available();

// Which row is the recommendation. Not "the first one": the first may be a
// courier that quotes but cannot book, and the recommendation is then the one
// below it.
$rateKey  = static fn (array $r): string => (string) ($r['provider'] ?? '') . '|'
    . (string) ($r['courier'] ?? '') . '|' . trim((string) ($r['courier_id'] ?? ''));
$bestKey  = ($scored['choice'] ?? null) !== null ? $rateKey($scored['choice']) : null;

$grams = static function (int $g): string {
    return $g >= 1000 ? rtrim(rtrim(number_format($g / 1000, 2), '0'), '.') . ' kg' : $g . ' g';
};
$pct = static fn (float $share): string => number_format($share * 100, 0) . '%';

$pageTitle    = 'Rate calculator';
$pageSubtitle = 'What every live courier would charge for one parcel, and which one wins on your weightings.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/shipments.php')],
    ['label' => 'Rate calculator'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<style>
    /* One column on a phone, four across once there is room for them. */
    .rc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px 16px; }
    .rc-why { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 4px; font-size: 13px; line-height: 1.6; }
    .rc-why li::before { content: '\2022'; margin-right: 7px; color: var(--ad-muted); }
    .rc-weights { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 0; font-size: 12.5px; }
    .rc-bar { display: block; height: 4px; border-radius: 3px; background: var(--ad-border); margin-top: 5px; overflow: hidden; }
    .rc-bar span { display: block; height: 100%; background: var(--ad-primary); }
    .rc-note { font-size: 12.5px; line-height: 1.55; }
    .rc-pick { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .04em;
               text-transform: uppercase; padding: 2px 7px; border-radius: 4px;
               background: var(--ad-primary); color: #fff; }
</style>

<div class="ad-container">

    <?php if ($available === []): ?>
        <div class="ad-card">
            <div class="ad-card__head"><div><h2 class="ad-card__title">No courier is live</h2></div></div>
            <div class="ad-card__body">
                <p style="margin:0 0 8px">
                    Nothing can be quoted until at least one courier integration is switched on and has a
                    pickup PIN code.
                </p>
                <?php if (admin_can('settings.view')): ?>
                    <a class="ad-btn ad-btn--primary" href="<?= e(admin_url('shipping/')) ?>">
                        <?= icon('settings', 'w-4 h-4') ?> Open shipping integrations
                    </a>
                <?php else: ?>
                    <p class="ad-muted" style="margin:0">Ask an admin with settings access to set one up.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================= 1. The parcel ================= -->
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <h2 class="ad-card__title">The parcel</h2>
                <div class="ad-card__sub">Nothing is saved. Dimensions change the price.</div>
            </div>
        </div>
        <form method="get" action="<?= e(admin_url('shipping/rates.php')) ?>">
            <div class="ad-card__body">
                <div class="rc-grid">
                    <div class="ad-field">
                        <label class="sik-label" for="rc_origin">Pickup PIN code</label>
                        <input class="sik-input<?= isset($errors['origin_pin']) ? ' is-invalid' : '' ?>"
                               id="rc_origin" name="origin_pin" type="text" inputmode="numeric"
                               pattern="[1-9][0-9]{5}" maxlength="6" autocomplete="off"
                               value="<?= e_attr($raw['origin_pin'] !== '' ? $raw['origin_pin'] : $defaultOrigin) ?>">
                        <?php if (isset($errors['origin_pin'])): ?>
                            <span class="sik-error"><?= e($errors['origin_pin']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">From the default integration. Change it to price another warehouse.</span>
                        <?php endif; ?>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="rc_dest">Delivery PIN code <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['destination_pin']) ? ' is-invalid' : '' ?>"
                               id="rc_dest" name="destination_pin" type="text" inputmode="numeric"
                               pattern="[1-9][0-9]{5}" maxlength="6" autocomplete="off" required
                               value="<?= e_attr($raw['destination_pin']) ?>">
                        <?php if (isset($errors['destination_pin'])): ?>
                            <span class="sik-error"><?= e($errors['destination_pin']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="rc_weight">Weight (g) <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['weight_grams']) ? ' is-invalid' : '' ?>"
                               id="rc_weight" name="weight_grams" type="number" inputmode="numeric"
                               step="1" min="50" max="50000" required
                               value="<?= e_attr($raw['weight_grams'] !== '' ? $raw['weight_grams'] : (string) $parcel['weight_grams']) ?>">
                        <?php if (isset($errors['weight_grams'])): ?>
                            <span class="sik-error"><?= e($errors['weight_grams']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="rc_payment">Payment</label>
                        <select class="sik-input" id="rc_payment" name="payment">
                            <option value="prepaid"<?= $isCod ? '' : ' selected' ?>>Prepaid</option>
                            <option value="cod"<?= $isCod ? ' selected' : '' ?>>Cash on delivery</option>
                        </select>
                        <span class="sik-help">COD adds the courier's collection fee, and some couriers refuse it.</span>
                    </div>
                </div>

                <div class="rc-grid" style="margin-top:14px">
                    <?php
                    $dims = [
                        'length_cm' => 'Length (cm)',
                        'width_cm'  => 'Width (cm)',
                        'height_cm' => 'Height (cm)',
                    ];
                    ?>
                    <?php foreach ($dims as $key => $label): ?>
                        <div class="ad-field">
                            <label class="sik-label" for="rc_<?= e_attr($key) ?>">
                                <?= e($label) ?> <span class="ad-muted">optional</span>
                            </label>
                            <input class="sik-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>"
                                   id="rc_<?= e_attr($key) ?>" name="<?= e_attr($key) ?>" type="number"
                                   inputmode="decimal" step="0.1" min="1" max="300"
                                   value="<?= e_attr($raw[$key]) ?>">
                            <?php if (isset($errors[$key])): ?>
                                <span class="sik-error"><?= e($errors[$key]) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="ad-field">
                        <label class="sik-label" for="rc_value">
                            <?= $isCod ? 'Amount to collect' : 'Declared value' ?>
                        </label>
                        <input class="sik-input<?= isset($errors['declared_value']) ? ' is-invalid' : '' ?>"
                               id="rc_value" name="declared_value" type="number" inputmode="decimal"
                               step="0.01" min="0" max="10000000"
                               value="<?= e_attr($raw['declared_value']) ?>">
                        <?php if (isset($errors['declared_value'])): ?>
                            <span class="sik-error"><?= e($errors['declared_value']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Sets the COD charge and the insurance the courier quotes.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($errors['dimensions'])): ?>
                    <span class="sik-error" style="display:block;margin-top:8px"><?= e($errors['dimensions']) ?></span>
                <?php endif; ?>
            </div>
            <div class="ad-card__foot" style="align-items:center">
                <?php // "Nothing is stored or cached" moved into the card subtitle; keeping
                      // both said the same thing twice, a screen apart. ?>
                <span class="sik-help" style="margin:0">Every courier is asked fresh.</span>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('refresh', 'w-4 h-4') ?> Get rates</button>
            </div>
        </form>
        <?php /* Why the box matters at all: a courier bills the larger of the weight
                 typed above and the volumetric weight of the box, so a light parcel in
                 a big carton is priced as a heavy one. Worth knowing once, not on
                 every visit. */ ?>
        <details class="ad-card__body" style="border-top:1px solid var(--ad-border)">
            <summary style="cursor:pointer;font-weight:600">Why the dimensions change the price</summary>
            <p class="ad-muted" style="margin:8px 0 0">
                Couriers bill the larger of the weight you type and the volumetric weight of
                the box. Leaving the dimensions blank prices the parcel on weight alone, which
                can quote lower than the courier will actually charge.
            </p>
        </details>
    </div>

    <?php if ($asked && $errors !== []): ?>
        <div class="ad-card"><div class="ad-card__body">
            <p style="margin:0" class="ad-muted">Correct the parcel above to see rates.</p>
        </div></div>
    <?php endif; ?>

    <?php if ($quote !== null): ?>

        <!-- ================= 2. The recommendation ================= -->
        <?php if (($scored['choice'] ?? null) !== null): ?>
            <?php $choice = $scored['choice']; ?>
            <div class="ad-card" style="border-left:3px solid var(--ad-primary)">
                <div class="ad-card__head">
                    <div>
                        <h2 class="ad-card__title">
                            <span class="rc-pick">Recommended</span>
                            <?= e((string) $choice['courier']) ?>
                            &middot; <?= e(money((float) $choice['cost'])) ?>
                        </h2>
                        <div class="ad-card__sub">
                            <?= e((string) ($choice['provider_name'] ?? $choice['provider'])) ?>
                            &middot; scores <?= e(number_format((float) $choice['score'], 1)) ?> out of 100
                            on your weightings
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <p style="margin:0 0 4px"><strong>Why this one</strong></p>
                    <ul class="rc-why">
                        <?php foreach ((array) $choice['why'] as $why): ?>
                            <li><?= e(ucfirst((string) $why)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="rc-weights ad-muted" style="margin-top:12px">
                        <span>Weightings in force:</span>
                        <span>cost <?= e($pct((float) $weights['cost'])) ?></span>
                        <span>speed <?= e($pct((float) $weights['speed'])) ?></span>
                        <span>performance <?= e($pct((float) $weights['reliability'])) ?></span>
                        <span>rating <?= e($pct((float) $weights['rating'])) ?></span>
                        <?php if (admin_can('settings.edit')): ?>
                            <a href="<?= e(admin_url('settings/shipping.php')) ?>">Change them</a>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= 3. Every rate ================= -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">
                        <?= count($rates) ?> rate<?= count($rates) === 1 ? '' : 's' ?>
                        for <?= e($grams((int) $parcel['weight_grams'])) ?>
                        <?php if ($parcel['length_cm'] !== null): ?>
                            in a <?= e((string) $parcel['length_cm']) ?>&times;<?= e((string) $parcel['width_cm']) ?>&times;<?= e((string) $parcel['height_cm']) ?> cm box
                        <?php endif; ?>
                    </h2>
                    <div class="ad-card__sub">
                        <?= e((string) ($quote['origin_pin'] ?: $defaultOrigin)) ?>
                        &rarr; <?= e((string) $parcel['destination_pin']) ?>
                        &middot; <?= $isCod ? 'cash on delivery' : 'prepaid' ?>
                        &middot; best score first
                    </div>
                </div>
            </div>

            <?php if ($rates === []): ?>
                <div class="ad-card__body">
                    <p style="margin:0 0 6px"><strong>No courier can take this parcel.</strong></p>
                    <p class="ad-muted" style="margin:0">Each one's own answer is below.</p>
                </div>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Courier</th>
                                <th>Provider</th>
                                <th class="ad-table__num">Cost</th>
                                <th class="ad-table__num">COD charge</th>
                                <th class="ad-table__num">ETA</th>
                                <th class="ad-table__num">Rating</th>
                                <th>Serviceable</th>
                                <th class="ad-table__num">Score</th>
                                <th>Why</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rates as $i => $rate): ?>
                                <?php
                                $eta    = $rate['eta_days'] ?? null;
                                $rating = $rate['rating'] ?? null;
                                $perf   = (array) $rate['performance'];
                                $isBest = $bestKey !== null && $rateKey($rate) === $bestKey;
                                ?>
                                <tr data-rate-row="<?= (int) $i ?>">
                                    <td>
                                        <span class="ad-cellflex__name" style="display:block">
                                            <?= e((string) ($rate['courier'] ?? '')) ?>
                                            <?php if ($isBest): ?><span class="rc-pick">Recommended</span><?php endif; ?>
                                        </span>
                                        <?php if ((string) ($rate['service'] ?? '') !== ''): ?>
                                            <span class="ad-cellflex__meta"><?= e((string) $rate['service']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($rate['provider_name'] ?? $rate['provider'])) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $rate['cost'])) ?></strong></td>
                                    <td class="ad-table__num">
                                        <?= (float) ($rate['cod_fee'] ?? 0) > 0 ? e(money((float) $rate['cod_fee'])) : '&mdash;' ?>
                                    </td>
                                    <td class="ad-table__num">
                                        <?= $eta === null ? '&mdash;' : e((int) $eta . ' day' . ((int) $eta === 1 ? '' : 's')) ?>
                                    </td>
                                    <td class="ad-table__num"><?= $rating === null ? '&mdash;' : e(number_format((float) $rating, 1)) ?></td>
                                    <td>
                                        <?php // A courier that quoted serves the lane - see the docblock. The
                                              // origin is worth naming: two integrations can ship the same
                                              // parcel out of two different warehouses. ?>
                                        <span class="sik-status sik-status--green">Yes</span>
                                        <span class="ad-cellflex__meta">from <?= e((string) ($rate['origin_pin'] ?? '')) ?></span>
                                    </td>
                                    <td class="ad-table__num" data-rate-score="<?= e_attr(number_format((float) $rate['score'], 2, '.', '')) ?>">
                                        <strong><?= e(number_format((float) $rate['score'], 1)) ?></strong>
                                        <span class="rc-bar"><span style="width:<?= e_attr((string) max(0, min(100, (float) $rate['score']))) ?>%"></span></span>
                                    </td>
                                    <td class="rc-note" data-rate-history="<?= e_attr((string) ($perf['scope'] ?? 'none')) ?>">
                                        <?php if (($rate['blocked'] ?? null) !== null): ?>
                                            <span class="sik-status sik-status--amber">Cannot book</span>
                                            <?= e((string) $rate['blocked']) ?><br>
                                        <?php endif; ?>
                                        <?= e((string) ($perf['note'] ?? '')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ================= 4. Who did not quote, and why ================= -->
            <?php
            $silent = array_filter($providers, static fn (array $p): bool => (int) $p['rates'] === 0);
            // Errors that no provider row above already accounts for: nothing
            // active at all, or an integration with no pickup PIN, which has no
            // "did not quote" row of its own to hang off.
            $loose = array_values(array_filter((array) ($quote['errors'] ?? []),
                static function (string $message) use ($silent): bool {
                    foreach ($silent as $info) {
                        $name = (string) $info['name'];
                        if ($name !== '' && strncmp($message, $name . ':', strlen($name) + 1) === 0) {
                            return false;
                        }
                    }
                    return true;
                }));
            ?>
            <?php if ($silent !== [] || $loose !== []): ?>
                <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                    <p class="sik-label" style="margin:0 0 8px">Couriers that did not quote this lane</p>
                    <ul class="rc-why ad-muted">
                        <?php foreach ($silent as $info): ?>
                            <li>
                                <strong><?= e((string) $info['name']) ?></strong>
                                <span class="sik-status sik-status--red">Not serviceable</span>
                                <?= e((string) $info['message']) ?>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($loose as $message): ?>
                            <li><?= e($message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
