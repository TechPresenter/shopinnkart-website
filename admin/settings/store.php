<?php
/**
 * ShopInnKart Admin - Store & order settings.
 *
 * Currency, locale and catalogue behaviour live in the "store" group; the
 * order numbering and post-purchase windows live in the "order" group. Both
 * are edited here because an admin thinks of them as one screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

$timezones = [];
foreach (timezone_identifiers_list() as $identifier) {
    $timezones[$identifier] = $identifier;
}

$spec = [
    // ---- store group ------------------------------------------------------
    'currency_code' => [
        'type' => 'text', 'label' => 'Currency code', 'required' => true, 'max' => 10,
        'help' => 'ISO 4217, e.g. INR.',
    ],
    'currency_symbol' => [
        'type' => 'text', 'label' => 'Currency symbol', 'required' => true, 'max' => 10,
    ],
    'currency_position' => [
        'type' => 'select', 'label' => 'Symbol position', 'required' => true,
        'options' => ['before' => 'Before the amount (₹1,299)', 'after' => 'After the amount (1,299₹)'],
    ],
    'number_grouping' => [
        'type' => 'select', 'label' => 'Number grouping', 'required' => true,
        'options' => ['indian' => 'Indian (1,24,999)', 'western' => 'Western (124,999)'],
    ],
    'timezone' => [
        'type' => 'select', 'label' => 'Timezone', 'required' => true, 'options' => $timezones,
        'help' => 'Every timestamp in the admin and on invoices is rendered in this zone.',
    ],
    'products_per_page' => [
        'type' => 'number', 'label' => 'Products per page', 'required' => true,
        'min_value' => 4, 'max_value' => 96,
        'help' => 'Grid size on the shop, category and brand pages.',
    ],
    'low_stock_threshold' => [
        'type' => 'number', 'label' => 'Default low stock threshold', 'required' => true,
        'min_value' => 0, 'max_value' => 9999,
        'help' => 'Used when a product does not set its own. Drives the "Only n left" badge.',
    ],
    'max_compare_items' => [
        'type' => 'number', 'label' => 'Max compare items', 'required' => true,
        // 2-6, because that is exactly what compare_max() will honour. The field
        // used to accept 8 and the storefront silently rendered 6 - two values an
        // operator could pick that changed nothing. Same range on the Widgets tab,
        // which edits this same row.
        'min_value' => 2, 'max_value' => 6,
        'help' => 'How many products fit side by side on /compare. Also on the Storefront Widgets '
            . 'tab — the same setting, not a second one.',
    ],
    'guest_checkout' => [
        'type' => 'bool', 'label' => 'Allow guest checkout',
        'help' => 'Off forces shoppers to sign in before they can place an order.',
    ],
    'reviews_require_purchase' => [
        'type' => 'bool', 'label' => 'Only verified buyers can review',
    ],
    'reviews_auto_approve' => [
        'type' => 'bool', 'label' => 'Auto-approve reviews',
        'help' => 'On publishes new reviews immediately, skipping the moderation queue.',
    ],
    'multivendor_enabled' => [
        'type' => 'bool', 'label' => 'Multi-vendor',
        'help' => 'Exposes the vendor tables so products can be owned by a seller.',
    ],
    'multilanguage_enabled' => [
        'type' => 'bool', 'label' => 'Multi-language',
        'help' => 'Enables the language switcher for the languages marked active.',
    ],
    'multicurrency_enabled' => [
        'type' => 'bool', 'label' => 'Multi-currency',
        'help' => 'Enables the currency switcher for the currencies marked active.',
    ],

    // ---- order group ------------------------------------------------------
    'order_prefix' => [
        'type' => 'text', 'label' => 'Order number prefix', 'required' => true, 'max' => 10,
        'group' => 'order',
        'help' => 'Order numbers are prefix + date + six random characters, so they cannot be counted or guessed.',
    ],
    'invoice_prefix' => [
        'type' => 'text', 'label' => 'Invoice prefix', 'required' => true, 'max' => 10,
        'group' => 'order',
    ],
    'min_order_amount' => [
        'type' => 'number', 'label' => 'Minimum order amount', 'required' => true,
        'min_value' => 0, 'max_value' => 1000000, 'step' => '0.01', 'group' => 'order',
        'help' => 'Checkout refuses carts below this subtotal. 0 disables the rule.',
    ],
    'cancel_window_hours' => [
        'type' => 'number', 'label' => 'Customer cancellation window (hours)', 'required' => true,
        'min_value' => 0, 'max_value' => 720, 'group' => 'order',
        'help' => 'How long after placing an order a customer may still cancel it themselves.',
    ],
    'return_window_days' => [
        'type' => 'number', 'label' => 'Return window (days)', 'required' => true,
        'min_value' => 0, 'max_value' => 365, 'group' => 'order',
        'help' => 'Counted from delivery.',
    ],
    'auto_confirm_cod' => [
        'type' => 'bool', 'label' => 'Auto-confirm COD orders', 'group' => 'order',
        'help' => 'Moves a new COD order straight from Pending to Confirmed.',
    ],
];

$stored = settings_group('store') + settings_group('order');

if (is_post()) {
    settings_handle_save('store', 'store', $spec, $stored);
}

$errors = errors_pull();
$values = settings_values($spec, $stored);

$pageTitle    = 'Store Settings';
$pageSubtitle = 'Currency, locale, catalogue behaviour and order rules.';
$breadcrumbs  = settings_breadcrumbs('store');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('store') ?>

<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--2">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Currency &amp; locale</div>
                    <div class="ad-card__sub">
                        Every price on the site is formatted from these three values, so
                        <?= e(money(124999)) ?> reflects the current choice.
                    </div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('currency_code', $spec, $values, $errors) ?>
                    <?= settings_field('currency_symbol', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('currency_position', $spec, $values, $errors) ?>
                    <?= settings_field('number_grouping', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('timezone', $spec, $values, $errors) ?>
                <p class="ad-muted" style="font-size:12.5px">
                    Store time right now: <strong><?= e(format_datetime(date('Y-m-d H:i:s'))) ?></strong>
                </p>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Catalogue</div>
                    <div class="ad-card__sub">How products are listed, compared and reviewed.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('products_per_page', $spec, $values, $errors) ?>
                    <?= settings_field('low_stock_threshold', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('max_compare_items', $spec, $values, $errors) ?>
                <fieldset class="ad-fieldset" style="display:grid;gap:12px">
                    <legend>Reviews &amp; checkout</legend>
                    <?= settings_field('guest_checkout', $spec, $values, $errors) ?>
                    <?= settings_field('reviews_require_purchase', $spec, $values, $errors) ?>
                    <?= settings_field('reviews_auto_approve', $spec, $values, $errors) ?>
                </fieldset>
            </div>
        </div>
    </div>

    <div class="ad-grid ad-grid--2">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Orders</div>
                    <div class="ad-card__sub">
                        Order numbers look like
                        <span class="ad-mono"><?= e(generate_order_number()) ?></span>
                    </div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('order_prefix', $spec, $values, $errors) ?>
                    <?= settings_field('invoice_prefix', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('min_order_amount', $spec, $values, $errors) ?>
                <div class="ad-row ad-row--2">
                    <?= settings_field('cancel_window_hours', $spec, $values, $errors) ?>
                    <?= settings_field('return_window_days', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('auto_confirm_cod', $spec, $values, $errors) ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Platform modules</div>
                    <div class="ad-card__sub">Switches the storefront reads before showing vendor, language or currency controls.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:14px">
                <?= settings_field('multivendor_enabled', $spec, $values, $errors) ?>
                <?= settings_field('multilanguage_enabled', $spec, $values, $errors) ?>
                <?= settings_field('multicurrency_enabled', $spec, $values, $errors) ?>

                <p class="ad-muted" style="font-size:12.5px">
                    Active languages: <?= (int) Database::count('languages', "`status` = 'active'") ?>
                    &middot; active currencies: <?= (int) Database::count('currencies', "`status` = 'active'") ?>
                    &middot; approved vendors: <?= (int) Database::count('vendors', "`status` = 'approved'") ?>
                </p>
            </div>
            <?= settings_save_bar() ?>
        </div>
    </div>
</form>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
