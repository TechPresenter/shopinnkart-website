<?php
/**
 * ShopInnKart Admin - Tax settings.
 *
 * Four switches decide a lot of money, so the screen shows the arithmetic
 * the cart actually runs (calculate_tax) against a sample price instead of
 * asking the admin to imagine it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

$spec = [
    'tax_enabled' => [
        'type' => 'bool', 'label' => 'Charge tax',
        'help' => 'Off removes the tax line from the cart, checkout and invoices entirely.',
    ],
    'tax_inclusive' => [
        'type' => 'bool', 'label' => 'Product prices already include tax',
        'help' => 'On extracts the tax from the price shown. Off adds it on top at checkout.',
    ],
    'default_tax_rate' => [
        'type' => 'number', 'label' => 'Default rate (%)', 'required' => true,
        'min_value' => 0, 'max_value' => 100, 'step' => '0.01',
        'help' => 'Used for any product that does not set its own tax_rate.',
    ],
    'tax_label' => [
        'type' => 'text', 'label' => 'Tax label', 'required' => true, 'max' => 30,
        'help' => 'Printed next to the tax line, e.g. GST or VAT.',
    ],
];

if (is_post()) {
    settings_handle_save('tax', 'tax', $spec, settings_group('tax'));
}

$stored = settings_group('tax');
$errors = errors_pull();
$values = settings_values($spec, $stored);

// ---------------------------------------------------------------------------
// Worked example, using the same arithmetic as calculate_tax().
// ---------------------------------------------------------------------------
$sample = (float) ($_GET['sample'] ?? 0);
if ($sample <= 0 || $sample > 10000000) {
    $sample = 24999.0;
}

$enabled   = ($values['tax_enabled'] ?? '0') === '1';
$inclusive = ($values['tax_inclusive'] ?? '0') === '1';
$rate      = is_numeric($values['default_tax_rate'] ?? null) ? (float) $values['default_tax_rate'] : 0.0;
$taxLabel  = (string) ($values['tax_label'] ?? 'Tax');

if (!$enabled || $rate <= 0) {
    $net = $sample;
    $tax = 0.0;
} elseif ($inclusive) {
    $net = $sample * 100 / (100 + $rate);
    $tax = $sample - $net;
} else {
    $net = $sample;
    $tax = $sample * ($rate / 100);
}
$grand = $net + $tax;

// How many products override the default, so the example is honest about scope.
$rateSpread = Database::fetchAll(
    'SELECT `tax_rate`, COUNT(*) AS products
     FROM `products`
     GROUP BY `tax_rate`
     ORDER BY `tax_rate`'
);
$totalProducts = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products`');

$pageTitle    = 'Tax Settings';
$pageSubtitle = 'How tax is calculated, labelled and shown on every price.';
$breadcrumbs  = settings_breadcrumbs('tax');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('tax') ?>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" data-guard-unsaved>
        <?= csrf_field() ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Tax rules</div>
                    <div class="ad-card__sub">
                        A product's own tax rate always wins; the default below covers the rest.
                    </div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:16px">
                <?= settings_field('tax_enabled', $spec, $values, $errors) ?>
                <?= settings_field('tax_inclusive', $spec, $values, $errors) ?>

                <div class="ad-row ad-row--2">
                    <?= settings_field('default_tax_rate', $spec, $values, $errors) ?>
                    <?= settings_field('tax_label', $spec, $values, $errors) ?>
                </div>

                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        With inclusive pricing the number on the product card is what the shopper pays;
                        the tax is separated out on the invoice. With exclusive pricing the tax is added
                        at checkout, so the cart total is higher than the sum of the listed prices.
                    </div>
                </div>
            </div>
            <?= settings_save_bar('Changes apply to new carts immediately.') ?>
        </div>

        <?php if ($rateSpread !== []): ?>
            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Rates in use across the catalogue</div>
                        <div class="ad-card__sub"><?= number_format($totalProducts) ?> product(s) in total.</div>
                    </div>
                </div>
                <div class="ad-card__body ad-card__body--flush">
                    <div class="ad-tablewrap">
                        <table class="ad-table" style="min-width:0">
                            <thead>
                                <tr>
                                    <th>Product tax rate</th>
                                    <th class="ad-table__num">Products</th>
                                    <th class="ad-table__num">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rateSpread as $bucket): ?>
                                    <tr>
                                        <td>
                                            <?= e(rtrim(rtrim(number_format((float) $bucket['tax_rate'], 2), '0'), '.')) ?>%
                                            <?php if ((float) $bucket['tax_rate'] === $rate): ?>
                                                <span class="sik-status sik-status--blue">Matches the default</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-table__num"><?= number_format((int) $bucket['products']) ?></td>
                                        <td class="ad-table__num">
                                            <?= $totalProducts > 0
                                                ? number_format(((int) $bucket['products'] / $totalProducts) * 100, 1)
                                                : '0.0' ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Worked example</div>
                    <div class="ad-card__sub">Saved settings, applied to one line at the default rate.</div>
                </div>
            </div>

            <form class="ad-filters" method="get" action="<?= e(settings_url('tax')) ?>">
                <div class="ad-field" style="flex:1;min-width:150px;margin:0">
                    <label class="sik-label" for="taxSample">Listed price</label>
                    <input class="sik-input" type="number" id="taxSample" name="sample" min="1" step="0.01"
                           value="<?= e(number_format($sample, 2, '.', '')) ?>">
                </div>
                <button type="submit" class="ad-btn ad-btn--sm" style="align-self:flex-end">Recalculate</button>
            </form>

            <div class="ad-card__body">
                <?php if (!$enabled): ?>
                    <div class="sik-alert sik-alert--warning" style="margin-bottom:14px">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>Tax is switched off, so nothing is added or extracted.</div>
                    </div>
                <?php endif; ?>

                <div class="ad-tablewrap">
                    <table class="ad-table" style="min-width:0">
                        <tbody>
                            <tr>
                                <td>Listed price</td>
                                <td class="ad-table__num"><strong><?= e(money($sample)) ?></strong></td>
                            </tr>
                            <tr>
                                <td>
                                    Taxable value
                                    <div class="ad-cellflex__meta">
                                        <?= $enabled && $inclusive
                                            ? 'price × 100 ÷ (100 + rate)'
                                            : 'the listed price' ?>
                                    </div>
                                </td>
                                <td class="ad-table__num"><?= e(money($net)) ?></td>
                            </tr>
                            <tr>
                                <td>
                                    <?= e($taxLabel) ?> @ <?= e(rtrim(rtrim(number_format($rate, 2), '0'), '.')) ?>%
                                    <div class="ad-cellflex__meta">
                                        <?php if (!$enabled): ?>
                                            not charged
                                        <?php elseif ($inclusive): ?>
                                            already inside the listed price
                                        <?php else: ?>
                                            added at checkout
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="ad-table__num"><?= e(money($tax)) ?></td>
                            </tr>
                            <tr>
                                <td><strong>Customer pays</strong></td>
                                <td class="ad-table__num">
                                    <strong style="font-size:15px"><?= e(money($grand)) ?></strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="ad-muted" style="font-size:12.5px;margin-top:14px">
                    <?php if ($enabled && $inclusive): ?>
                        Inclusive: the cart total stays <?= e(money($sample)) ?> and
                        <?= e(money($tax)) ?> of it is reported as <?= e($taxLabel) ?>.
                    <?php elseif ($enabled): ?>
                        Exclusive: the shopper sees <?= e(money($sample)) ?> on the product card and
                        <?= e(money($grand)) ?> at checkout.
                    <?php else: ?>
                        No tax line appears anywhere while tax is switched off.
                    <?php endif; ?>
                </p>
            </div>
            <div class="ad-card__foot">
                <span class="ad-muted" style="font-size:12.5px;margin-right:auto">
                    Cart-level coupon discounts are spread proportionally across lines before tax is worked out.
                </span>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
