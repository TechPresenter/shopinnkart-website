<?php
/**
 * ShopInnKart - Checkout.
 *
 * checkout.js posts this form to /api/checkout/process.php, but the same
 * markup is a plain HTML form: posting it back here runs create_order()
 * server-side and redirects, so checkout still completes without JavaScript.
 * Either way create_order() owns pricing, stock and the transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';

$user = current_user();

if (!setting_bool('guest_checkout', true) && $user === null) {
    $_SESSION['_intended'] = url('checkout.php');
    flash('info', 'Please sign in to complete your order.');
    redirect(url('login.php'));
}

if (cart_is_empty()) {
    flash('info', 'Your cart is empty. Add something before checking out.');
    redirect(url('cart.php'));
}

/** Saved addresses are re-read here so a posted id can never point elsewhere. */
$addresses = $user === null ? [] : Database::fetchAll(
    'SELECT * FROM `user_addresses` WHERE `user_id` = :uid ORDER BY `is_default` DESC, `id` DESC',
    ['uid' => (int) $user['id']]
);

// ---------------------------------------------------------------------------
// No-JS submission
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    $input = $_POST;
    unset($input[CSRF_TOKEN_NAME]);

    // A saved address only fills the gap when nothing was typed into the new
    // address form - the typed values always win, and the row is re-read from
    // the database rather than trusted from the payload.
    $addressId = input_int('address_id');
    if ($addressId > 0 && trim((string) ($input['shipping_address'] ?? '')) === '' && $user !== null) {
        $saved = Database::fetch(
            'SELECT * FROM `user_addresses` WHERE `id` = :id AND `user_id` = :uid LIMIT 1',
            ['id' => $addressId, 'uid' => (int) $user['id']]
        );
        if ($saved !== null) {
            $input['shipping_name']     = $saved['full_name'];
            $input['shipping_phone']    = $saved['phone'];
            $input['shipping_address']  = $saved['address_line1'];
            $input['shipping_address2'] = $saved['address_line2'];
            $input['shipping_landmark'] = $saved['landmark'];
            $input['shipping_city']     = $saved['city'];
            $input['shipping_state']    = $saved['state'];
            $input['shipping_pincode']  = $saved['pincode'];
            $input['shipping_country']  = $saved['country'];
        }
    }

    $result = create_order($input);

    if ($result['ok']) {
        $orderNumber = (string) $result['order']['order_number'];
        remember_placed_order($orderNumber);
        redirect(url('order-success.php?order=' . rawurlencode($orderNumber)));
    }

    flash('error', $result['message']);
    flash_errors($result['errors']);
    flash_old($input);
    redirect(url('checkout.php'));
}

$errors = errors_pull();
$old    = $_SESSION['_old'] ?? [];
old_clear();

/** Repopulate a field after a failed submit, falling back to a default. */
$value = static function (string $key, $default = '') use ($old): string {
    $stored = $old[$key] ?? null;
    return (string) ($stored !== null && $stored !== '' ? $stored : $default);
};

$items  = cart_items();
$totals = cart_totals($items);

$shippingMethods = Database::fetchAll(
    "SELECT * FROM `shipping_methods` WHERE `status` = 'active' ORDER BY `sort_order`, `id`"
);
$paymentMethods = PaymentGatewayFactory::available();

$shippingCodes   = array_column($shippingMethods, 'code');
$defaultShipping = $value('shipping_method', in_array(SHIPPING_STANDARD, $shippingCodes, true)
    ? SHIPPING_STANDARD
    : (string) ($shippingCodes[0] ?? SHIPPING_STANDARD));
$defaultPayment  = $value('payment_method', $paymentMethods === [] ? '' : (string) $paymentMethods[0]['code']);
$billingSame     = $old === [] ? true : !empty($old['billing_same']);

$defaultAddressId = 0;
foreach ($addresses as $address) {
    if ((int) $address['is_default'] === 1) {
        $defaultAddressId = (int) $address['id'];
        break;
    }
}
if ($defaultAddressId === 0 && $addresses !== []) {
    $defaultAddressId = (int) $addresses[0]['id'];
}
$selectedAddressId = (int) ($old['address_id'] ?? $defaultAddressId);

seo_from_page('checkout');
seo_set([
    'title'     => 'Secure Checkout',
    'robots'    => 'noindex, nofollow',
    'canonical' => url('checkout.php'),
]);

require INCLUDES_PATH . '/header.php';

$fieldError = static function (string $name) use ($errors): string {
    $message = error_for($errors, $name);
    return $message ? '<span class="sik-error">' . e($message) . '</span>' : '';
};
$invalid = static fn (string $name): string => error_for($errors, $name) ? ' is-invalid' : '';
?>

<div class="sik-container sik-section sik-section--sm sik-checkoutpage">

    <header class="sik-checkoutpage__head">
        <h1 class="sik-pagehead__title">Checkout</h1>
        <ol class="sik-steps" aria-label="Checkout progress">
            <li><a class="sik-step is-done" href="<?= e(url('cart.php')) ?>">
                <span class="sik-step__num"><?= icon('check', 'w-3.5 h-3.5') ?></span> Cart
            </a></li>
            <li class="sik-step__line" aria-hidden="true"></li>
            <li><span class="sik-step is-active" aria-current="step"><span class="sik-step__num">2</span> Details &amp; payment</span></li>
            <li class="sik-step__line" aria-hidden="true"></li>
            <li><span class="sik-step"><span class="sik-step__num">3</span> Confirmation</span></li>
        </ol>
    </header>

    <?php if ($errors !== []): ?>
        <div class="sik-alert sik-alert--error" role="alert">
            <?= icon('alert', 'w-4 h-4') ?>
            <span>Some details need another look. The fields are marked below.</span>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('checkout.php')) ?>" id="sikCheckoutForm" novalidate>
        <?= csrf_field() ?>

        <div class="sik-checkout">
            <div class="sik-checkout__main">

                <!-- ======================= Contact ========================== -->
                <section class="sik-panel" aria-labelledby="sikCoContact">
                    <div class="sik-panel__head">
                        <h2 class="sik-panel__title" id="sikCoContact">Contact</h2>
                        <?php if ($user === null): ?>
                            <a class="sik-summarycard__link" href="<?= e(url('login.php')) ?>">Sign in</a>
                        <?php endif; ?>
                    </div>
                    <div class="sik-panel__body">
                        <div class="sik-formgrid">
                            <label class="sik-field">
                                <span class="sik-label">Full name <span class="req">*</span></span>
                                <input type="text" class="sik-input<?= $invalid('customer_name') ?>"
                                       name="customer_name" autocomplete="name" maxlength="150" required
                                       value="<?= e($value('customer_name', $user === null ? '' : trim($user['first_name'] . ' ' . (string) $user['last_name']))) ?>">
                                <?= $fieldError('customer_name') ?>
                            </label>

                            <label class="sik-field">
                                <span class="sik-label">Mobile number <span class="req">*</span></span>
                                <input type="tel" class="sik-input<?= $invalid('customer_phone') ?>"
                                       name="customer_phone" autocomplete="tel" inputmode="numeric" maxlength="15" required
                                       value="<?= e($value('customer_phone', $user === null ? '' : (string) $user['phone'])) ?>">
                                <span class="sik-help">For delivery updates only.</span>
                                <?= $fieldError('customer_phone') ?>
                            </label>

                            <label class="sik-field sik-formgrid__full">
                                <span class="sik-label">Email <span class="req">*</span></span>
                                <input type="email" class="sik-input<?= $invalid('customer_email') ?>"
                                       name="customer_email" autocomplete="email" maxlength="190" required
                                       value="<?= e($value('customer_email', $user === null ? '' : (string) $user['email'])) ?>">
                                <span class="sik-help">Your confirmation and invoice are sent here.</span>
                                <?= $fieldError('customer_email') ?>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- ==================== Delivery address ==================== -->
                <section class="sik-panel" aria-labelledby="sikCoAddress">
                    <div class="sik-panel__head">
                        <h2 class="sik-panel__title" id="sikCoAddress">Delivery address</h2>
                        <?php if ($addresses !== []): ?>
                            <a class="sik-summarycard__link" href="#sikNewAddress" data-new-address>Use a new address</a>
                        <?php endif; ?>
                    </div>
                    <div class="sik-panel__body">
                        <?php if ($addresses !== []): ?>
                            <div class="sik-addresscards">
                                <?php foreach ($addresses as $address): ?>
                                    <?php $addressId = (int) $address['id']; ?>
                                    <label class="sik-addresscard<?= $addressId === $selectedAddressId ? ' is-selected' : '' ?>"
                                           data-address-card
                                           data-name="<?= e_attr($address['full_name']) ?>"
                                           data-phone="<?= e_attr($address['phone']) ?>"
                                           data-address="<?= e_attr($address['address_line1']) ?>"
                                           data-address2="<?= e_attr((string) $address['address_line2']) ?>"
                                           data-landmark="<?= e_attr((string) $address['landmark']) ?>"
                                           data-city="<?= e_attr($address['city']) ?>"
                                           data-state="<?= e_attr($address['state']) ?>"
                                           data-pincode="<?= e_attr($address['pincode']) ?>"
                                           data-country="<?= e_attr($address['country']) ?>">
                                        <input type="radio" name="address_id" value="<?= $addressId ?>"
                                               <?= $addressId === $selectedAddressId ? 'checked' : '' ?>>
                                        <span class="sik-addresscard__body">
                                            <span class="sik-addresscard__name">
                                                <?= e($address['full_name']) ?>
                                                <?php if (!empty($address['label'])): ?>
                                                    <span class="sik-tag"><?= e($address['label']) ?></span>
                                                <?php endif; ?>
                                                <?php if ((int) $address['is_default'] === 1): ?>
                                                    <span class="sik-tag sik-tag--success">Default</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="sik-addresscard__text">
                                                <?= e($address['address_line1']) ?><?php if (!empty($address['address_line2'])): ?>, <?= e($address['address_line2']) ?><?php endif; ?><br>
                                                <?php if (!empty($address['landmark'])): ?>Near <?= e($address['landmark']) ?><br><?php endif; ?>
                                                <?= e($address['city']) ?>, <?= e($address['state']) ?> <?= e($address['pincode']) ?><br>
                                                <?= e($address['phone']) ?>
                                            </span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="sik-sectionlabel">Or deliver somewhere new</p>
                        <?php endif; ?>

                        <div id="sikNewAddress">
                            <?php if ($addresses !== []): ?>
                                <label class="sik-check" style="margin-bottom:var(--sp-4)">
                                    <input type="radio" name="address_id" value="0" <?= $selectedAddressId === 0 ? 'checked' : '' ?>>
                                    <span>Deliver to the address below</span>
                                </label>
                            <?php endif; ?>

                            <div class="sik-formgrid">
                                <label class="sik-field">
                                    <span class="sik-label">Recipient name</span>
                                    <input type="text" class="sik-input" name="shipping_name" maxlength="150"
                                           autocomplete="shipping name" value="<?= e($value('shipping_name')) ?>">
                                    <span class="sik-help">Leave blank to use your contact name.</span>
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">Delivery phone</span>
                                    <input type="tel" class="sik-input" name="shipping_phone" maxlength="15"
                                           inputmode="numeric" autocomplete="shipping tel" value="<?= e($value('shipping_phone')) ?>">
                                </label>

                                <label class="sik-field sik-formgrid__full">
                                    <span class="sik-label">Flat, house no., building, street <span class="req">*</span></span>
                                    <input type="text" class="sik-input<?= $invalid('shipping_address') ?>"
                                           name="shipping_address" maxlength="255" autocomplete="shipping address-line1"
                                           value="<?= e($value('shipping_address')) ?>">
                                    <?= $fieldError('shipping_address') ?>
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">Area, colony, sector</span>
                                    <input type="text" class="sik-input" name="shipping_address2" maxlength="255"
                                           autocomplete="shipping address-line2" value="<?= e($value('shipping_address2')) ?>">
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">Landmark</span>
                                    <input type="text" class="sik-input" name="shipping_landmark" maxlength="150"
                                           value="<?= e($value('shipping_landmark')) ?>">
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">PIN code <span class="req">*</span></span>
                                    <input type="text" class="sik-input<?= $invalid('shipping_pincode') ?>"
                                           name="shipping_pincode" maxlength="6" inputmode="numeric"
                                           autocomplete="shipping postal-code" pattern="[1-9][0-9]{5}"
                                           value="<?= e($value('shipping_pincode')) ?>">
                                    <span class="sik-help" data-delivery-eta hidden></span>
                                    <?= $fieldError('shipping_pincode') ?>
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">City <span class="req">*</span></span>
                                    <input type="text" class="sik-input<?= $invalid('shipping_city') ?>"
                                           name="shipping_city" maxlength="100" autocomplete="shipping address-level2"
                                           value="<?= e($value('shipping_city')) ?>">
                                    <?= $fieldError('shipping_city') ?>
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">State <span class="req">*</span></span>
                                    <select class="sik-select<?= $invalid('shipping_state') ?>"
                                            name="shipping_state" autocomplete="shipping address-level1">
                                        <option value="">Select a state</option>
                                        <?php foreach (INDIAN_STATES as $state): ?>
                                            <option value="<?= e($state) ?>" <?= $value('shipping_state') === $state ? 'selected' : '' ?>><?= e($state) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= $fieldError('shipping_state') ?>
                                </label>

                                <label class="sik-field">
                                    <span class="sik-label">Country</span>
                                    <input type="text" class="sik-input" name="shipping_country" value="India" readonly>
                                    <span class="sik-help">We deliver within India.</span>
                                </label>
                            </div>

                            <?php if ($user !== null): ?>
                                <label class="sik-check" style="margin-top:var(--sp-4)">
                                    <input type="checkbox" name="save_address" value="1" checked>
                                    <span>Save this address for next time</span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <hr class="sik-checkout__divider">

                        <label class="sik-check" id="sikBillingToggle">
                            <input type="checkbox" id="sikBillingSame" name="billing_same" value="1" <?= $billingSame ? 'checked' : '' ?>>
                            <span>Billing address is the same as delivery</span>
                        </label>

                        <div id="sikBillingFields" style="margin-top:var(--sp-5)">
                            <div class="sik-formgrid">
                                <label class="sik-field">
                                    <span class="sik-label">Billing name</span>
                                    <input type="text" class="sik-input" name="billing_name" maxlength="150" value="<?= e($value('billing_name')) ?>">
                                </label>
                                <label class="sik-field">
                                    <span class="sik-label">Billing phone</span>
                                    <input type="tel" class="sik-input" name="billing_phone" maxlength="15" inputmode="numeric" value="<?= e($value('billing_phone')) ?>">
                                </label>
                                <label class="sik-field sik-formgrid__full">
                                    <span class="sik-label">Billing address</span>
                                    <input type="text" class="sik-input" name="billing_address" maxlength="255" value="<?= e($value('billing_address')) ?>">
                                </label>
                                <label class="sik-field">
                                    <span class="sik-label">Billing city</span>
                                    <input type="text" class="sik-input" name="billing_city" maxlength="100" value="<?= e($value('billing_city')) ?>">
                                </label>
                                <label class="sik-field">
                                    <span class="sik-label">Billing state</span>
                                    <select class="sik-select" name="billing_state">
                                        <option value="">Select a state</option>
                                        <?php foreach (INDIAN_STATES as $state): ?>
                                            <option value="<?= e($state) ?>" <?= $value('billing_state') === $state ? 'selected' : '' ?>><?= e($state) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="sik-field">
                                    <span class="sik-label">Billing PIN code</span>
                                    <input type="text" class="sik-input" name="billing_pincode" maxlength="6" inputmode="numeric" value="<?= e($value('billing_pincode')) ?>">
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ===================== Delivery method ==================== -->
                <section class="sik-panel" aria-labelledby="sikCoDelivery">
                    <div class="sik-panel__head">
                        <h2 class="sik-panel__title" id="sikCoDelivery">Delivery</h2>
                    </div>
                    <div class="sik-panel__body">
                        <?php if ($shippingMethods === []): ?>
                            <div class="sik-empty sik-empty--sm">
                                <?= icon('truck', 'w-12 h-12') ?>
                                <p class="sik-empty__title">No delivery method is set up</p>
                                <p class="sik-empty__text">Contact us and we will arrange delivery for this order.</p>
                                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('contact.php')) ?>">Contact us</a>
                            </div>
                        <?php else: ?>
                            <div class="sik-options">
                                <?php foreach ($shippingMethods as $method): ?>
                                    <?php
                                    $quote = calculate_shipping(
                                        (float) $totals['subtotal'] - (float) $totals['discount'],
                                        (string) $method['code']
                                    );
                                    $isChecked = (string) $method['code'] === $defaultShipping;
                                    ?>
                                    <label class="sik-paymethod<?= $isChecked ? ' is-selected' : '' ?>">
                                        <input type="radio" name="shipping_method" value="<?= e_attr($method['code']) ?>" <?= $isChecked ? 'checked' : '' ?>>
                                        <span class="sik-paymethod__body">
                                            <span class="sik-paymethod__row">
                                                <span class="sik-paymethod__name"><?= e($method['name']) ?></span>
                                                <span class="sik-paymethod__price<?= $quote['is_free'] ? ' is-free' : '' ?>">
                                                    <?= $quote['is_free'] ? 'Free' : e(money($quote['amount'])) ?>
                                                </span>
                                            </span>
                                            <span class="sik-paymethod__desc">
                                                <?= e($quote['eta']) ?><?php if (!empty($method['description'])): ?> &middot; <?= e($method['description']) ?><?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ====================== Payment ========================== -->
                <section class="sik-panel" aria-labelledby="sikCoPayment">
                    <div class="sik-panel__head">
                        <h2 class="sik-panel__title" id="sikCoPayment">Payment</h2>
                    </div>
                    <div class="sik-panel__body">
                        <?php if ($paymentMethods === []): ?>
                            <div class="sik-empty sik-empty--sm">
                                <?= icon('credit-card', 'w-12 h-12') ?>
                                <p class="sik-empty__title">No payment method is available right now</p>
                                <p class="sik-empty__text">We cannot take this order online at the moment. Get in touch and we will complete it with you.</p>
                                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('contact.php')) ?>">Contact us</a>
                            </div>
                        <?php else: ?>
                            <div class="sik-options">
                                <?php foreach ($paymentMethods as $method): ?>
                                    <?php $isChecked = (string) $method['code'] === $defaultPayment; ?>
                                    <label class="sik-paymethod<?= $isChecked ? ' is-selected' : '' ?>" data-paymethod>
                                        <input type="radio" name="payment_method" value="<?= e_attr($method['code']) ?>" <?= $isChecked ? 'checked' : '' ?>>
                                        <span class="sik-paymethod__body">
                                            <span class="sik-paymethod__row">
                                                <span class="sik-paymethod__name">
                                                    <?= e($method['name']) ?>
                                                    <?php if ((float) $method['discount_percent'] > 0): ?>
                                                        <span class="sik-tag sik-tag--success">
                                                            <?= e(rtrim(rtrim(number_format((float) $method['discount_percent'], 2, '.', ''), '0'), '.')) ?>% off
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                                <?php if ((float) $method['extra_charge'] > 0): ?>
                                                    <span class="sik-paymethod__desc">+ <?= e(money((float) $method['extra_charge'])) ?> handling</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (!empty($method['description'])): ?>
                                                <span class="sik-paymethod__desc"><?= e($method['description']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $fieldError('payment_method') ?>
                        <?php endif; ?>

                        <details class="sik-checkout__note"<?= $value('customer_note') !== '' ? ' open' : '' ?>>
                            <summary><?= icon('plus', 'w-4 h-4') ?> Add a delivery note</summary>
                            <label class="sik-field">
                                <span class="sik-sr">Order note</span>
                                <textarea class="sik-textarea" name="customer_note" maxlength="500" rows="3"
                                          placeholder="Gate code, a landmark, a good time to deliver&hellip;"><?= e($value('customer_note')) ?></textarea>
                                <span class="sik-help">We pass this to the courier where we can.</span>
                            </label>
                        </details>
                    </div>
                </section>
            </div>

            <!-- ========================= Summary ========================== -->
            <aside class="sik-checkout__aside">
                <div class="sik-summarycard" data-order-summary>
                    <div class="sik-summarycard__head">
                        <h2 class="sik-summarycard__title">Order summary</h2>
                        <a class="sik-summarycard__link" href="<?= e(url('cart.php')) ?>">Edit</a>
                    </div>

                    <div class="sik-summaryitems">
                        <?php foreach ($items as $item): ?>
                            <div class="sik-summaryitem">
                                <span class="sik-summaryitem__media">
                                    <img src="<?= e($item['image_url']) ?>" alt="" width="56" height="56" loading="lazy">
                                    <span class="sik-summaryitem__qty" aria-label="Quantity <?= (int) $item['quantity'] ?>"><?= (int) $item['quantity'] ?></span>
                                </span>
                                <span class="sik-summaryitem__name">
                                    <span><?= e($item['name']) ?></span>
                                    <?php if (!empty($item['variant_name'])): ?>
                                        <span class="sik-summaryitem__variant"><?= e($item['variant_name']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="sik-summaryitem__price"><?= e($item['subtotal_display']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    /* One coupon applies at a time, so this has two states: the
                       applied row with a way off it, or the entry field. Both post
                       to cart.php, which already owns apply and remove - checkout's
                       own POST places the order, so it is not a target for this.
                       The markup mirrors the cart's coupon block exactly, so the
                       styles and the cart.js hooks apply unchanged. */
                    $checkoutCoupon = $totals['coupon_code'] ?? null;
                    ?>
                    <div class="sik-couponbox" data-checkout-coupon>
                        <form method="post" action="<?= e(url('cart.php')) ?>" class="sik-coupon__on"
                              data-coupon-on<?= $checkoutCoupon === null ? ' hidden' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_coupon">
                            <input type="hidden" name="return" value="checkout">
                            <span class="min-w-0">
                                <strong>
                                    <?= icon('check-circle', 'w-4 h-4') ?>
                                    <span data-coupon-code><?= e((string) $checkoutCoupon) ?></span> applied
                                </strong>
                                <span class="sik-coupon__saving">
                                    You save <span data-coupon-saving><?= e($totals['display']['discount']) ?></span> on this order
                                </span>
                            </span>
                            <button type="submit" class="sik-btn sik-btn--ghost sik-btn--sm" data-coupon-remove>
                                <span class="sik-btn__label">Remove</span>
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('cart.php')) ?>" class="sik-couponform"
                              data-coupon-form<?= $checkoutCoupon !== null ? ' hidden' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="apply_coupon">
                            <input type="hidden" name="return" value="checkout">
                            <label class="sik-sr" for="sikCheckoutCoupon">Coupon code</label>
                            <input type="text" class="sik-input" id="sikCheckoutCoupon" name="code"
                                   placeholder="Coupon code" autocomplete="off" maxlength="60" required>
                            <button type="submit" class="sik-btn sik-btn--navy">
                                <span class="sik-btn__label">Apply</span>
                            </button>
                        </form>

                        <p class="sik-couponbox__msg" role="status" aria-live="polite" data-coupon-msg hidden></p>
                    </div>

                    <div class="sik-summary">
                        <div class="sik-summary__row">
                            <span>Subtotal</span>
                            <span data-total="subtotal"><?= e($totals['display']['subtotal']) ?></span>
                        </div>
                        <div class="sik-summary__row" data-total-row="discount"<?= (float) $totals['discount'] > 0 ? '' : ' hidden' ?>>
                            <span>
                                Discount
                                <?php if ($totals['coupon_code'] !== null): ?>
                                    <span class="sik-tag"><?= e((string) $totals['coupon_code']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span style="color:var(--sik-success-ink)" data-total="discount">- <?= e($totals['display']['discount']) ?></span>
                        </div>
                        <div class="sik-summary__row">
                            <span>Delivery</span>
                            <span data-total="shipping"><?= e($totals['display']['shipping']) ?></span>
                        </div>
                        <div class="sik-summary__row" data-total-row="payment_charge" hidden>
                            <span>Payment handling fee</span>
                            <span data-total="payment_charge"><?= e(money(0)) ?></span>
                        </div>
                        <div class="sik-summary__row" data-total-row="payment_discount" hidden>
                            <span>Payment discount</span>
                            <span style="color:var(--sik-success-ink)" data-total="payment_discount">- <?= e(money(0)) ?></span>
                        </div>
                        <div class="sik-summary__row" data-total-row="tax"<?= (float) $totals['tax'] > 0 ? '' : ' hidden' ?>>
                            <span><?= $totals['tax_inclusive'] ? 'Includes ' . e((string) $totals['tax_label']) : e((string) $totals['tax_label']) ?></span>
                            <span data-total="tax"><?= e($totals['display']['tax']) ?></span>
                        </div>
                        <div class="sik-summary__row sik-summary__row--total">
                            <span>Total</span>
                            <span data-total="total"><?= e($totals['display']['total']) ?></span>
                        </div>
                        <div class="sik-summary__row sik-summary__row--save" data-total-row="saving"<?= (float) $totals['total_saving'] > 0 ? '' : ' hidden' ?>>
                            <span>You save</span>
                            <span data-total="saving"><?= e($totals['display']['saving']) ?></span>
                        </div>
                    </div>

                    <div class="sik-summarycard__cta">
                        <?php if ($paymentMethods !== []): ?>
                            <button type="submit" class="sik-btn sik-btn--primary sik-btn--lg sik-btn--block sik-summarycard__cta--wide" data-place-order>
                                <span class="sik-btn__label">Place order</span>
                            </button>
                        <?php endif; ?>
                        <p class="sik-summarycard__note">
                            By placing this order you agree to our
                            <a href="<?= e(page_url('terms-conditions')) ?>">Terms</a> and
                            <a href="<?= e(page_url('privacy-policy')) ?>">Privacy Policy</a>.
                        </p>
                    </div>
                </div>
            </aside>
        </div>

        <?php if ($paymentMethods !== []): ?>
            <?php // Phones: the total and the button stay on screen however long the form is. ?>
            <div class="sik-paybar">
                <span class="sik-paybar__total">
                    <span class="sik-paybar__label">Total</span>
                    <span class="sik-paybar__amount" data-total="total"><?= e($totals['display']['total']) ?></span>
                </span>
                <button type="submit" class="sik-btn sik-btn--primary sik-btn--lg" data-place-order>
                    <span class="sik-btn__label">Place order</span>
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
