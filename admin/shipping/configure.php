<?php
/**
 * ShopInnKart Admin - Configure one courier integration.
 *
 * Credentials, pickup address, mode and webhook, plus a connection test that
 * proves the account works before an order depends on it.
 *
 * The credential fields are declared by the DRIVER, not listed here, so adding
 * a courier that wants five fields where another wants two needs no edit to
 * this file.
 *
 * A stored secret is never rendered back into the HTML. The field shows a
 * placeholder when one exists and an empty submission means "keep it", the way
 * the settings screens already handle passwords - a secret echoed into a form
 * is a secret in the browser cache, the page source and any screenshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-functions.php';

$code     = (string) input('code', '');
$provider = $code === '' ? null : shipping_provider($code);

if ($provider === null || !ShippingProviderFactory::implemented($code)) {
    flash('error', 'That courier integration is not installed.');
    redirect(admin_url('shipping/'));
}

$driver  = ShippingProviderFactory::make($provider);
$fields  = $driver->credentialFields();
$stored  = shipping_credentials($provider);
$errors  = [];

if (is_post()) {
    admin_require_action('orders.edit');   // POST + CSRF + permission

    $v = new Validator($_POST, [
        'name'           => 'Display name',
        'pickup_pincode' => 'Pickup PIN code',
        'pickup_phone'   => 'Pickup phone',
        'gst_number'     => 'GSTIN',
    ]);
    $v->required('name')->max('name', 120);

    if (trim((string) input('pickup_pincode', '')) !== '') {
        $v->pincode('pickup_pincode');
    }
    if (trim((string) input('pickup_phone', '')) !== '') {
        $v->phone('pickup_phone');
    }
    if (trim((string) input('gst_number', '')) !== '') {
        $v->max('gst_number', 20);
    }

    // A required credential is only required once the integration is switched
    // on. Demanding it while the row is still inactive would stop an admin
    // saving a half-filled form and coming back to it.
    $wantsActive = input_bool('status');
    $submitted   = [];
    foreach ($fields as $key => $meta) {
        $value = trim((string) input('cred_' . $key, ''));
        if ($value === '' && isset($stored[$key])) {
            $submitted[$key] = $stored[$key];   // blank means keep
            continue;
        }
        if ($value === '' && $wantsActive && !empty($meta['required'])) {
            $errors[$key] = ($meta['label'] ?? $key) . ' is required to activate this courier.';
            continue;
        }
        if ($value !== '') {
            $submitted[$key] = $value;
        }
    }

    $errors = array_merge($errors, $v->errors());

    if ($errors !== []) {
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $columns = [
            'name'            => (string) input('name', ''),
            'status'          => $wantsActive ? 'active' : 'inactive',
            'mode'            => input('mode', 'test') === 'live' ? 'live' : 'test',
            'is_default'      => input_bool('is_default') ? 1 : 0,
            'supports_cod'    => input_bool('supports_cod') ? 1 : 0,
            'supports_return' => input_bool('supports_return') ? 1 : 0,
            'webhook_enabled' => input_bool('webhook_enabled') ? 1 : 0,
            'pickup_name'     => (string) input('pickup_name', ''),
            'pickup_phone'    => (string) input('pickup_phone', ''),
            'pickup_email'    => (string) input('pickup_email', ''),
            'pickup_address'  => (string) input('pickup_address', ''),
            'pickup_city'     => (string) input('pickup_city', ''),
            'pickup_state'    => (string) input('pickup_state', ''),
            'pickup_pincode'  => (string) input('pickup_pincode', ''),
            'gst_number'      => (string) input('gst_number', ''),
        ];

        // A new webhook secret only when one was typed; blank keeps the old.
        $newSecret = trim((string) input('webhook_secret', ''));
        if ($newSecret !== '') {
            $columns['webhook_secret'] = secret_encrypt($newSecret);
        }

        Database::transaction(static function () use ($columns, $provider, $submitted): void {
            // Only one default, or booking has to guess.
            if ((int) $columns['is_default'] === 1) {
                Database::query('UPDATE `shipping_providers` SET `is_default` = 0 WHERE `id` <> :id',
                    ['id' => (int) $provider['id']]);
            }
            Database::update('shipping_providers', $columns, '`id` = :id', ['id' => (int) $provider['id']]);
            shipping_store_credentials((int) $provider['id'], $submitted);
        });

        log_activity('shipping_provider.updated', 'shipping_provider', (int) $provider['id'],
            'Updated the ' . $columns['name'] . ' shipping integration');
        admin_after_write();

        flash('success', $columns['name'] . ' saved.');
        redirect(admin_url('shipping/configure.php?code=' . urlencode($code)));
    }
}

$pageTitle    = (string) $provider['name'];
$pageSubtitle = 'Credentials, pickup address and connection health.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping',  'url' => admin_url('shipping/')],
    ['label' => 'Configure'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-container">
    <form method="post" class="ad-form">
        <?= csrf_field() ?>

        <div class="ad-grid" style="grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start">
            <div>
                <!-- Credentials ------------------------------------------ -->
                <div class="ad-card" style="margin:0 0 16px">
                    <div class="ad-card__head">
                        <div>
                            <h2 class="ad-card__title">Account</h2>
                            <div class="ad-card__sub">Stored encrypted with the application key. Never shown again once saved.</div>
                        </div>
                    </div>
                    <div class="ad-card__body">
                        <div class="ad-field">
                            <label class="sik-label" for="shipName">Display name</label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                                   id="shipName" name="name" type="text" maxlength="120"
                                   value="<?= e((string) $provider['name']) ?>" required>
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($fields as $key => $meta): ?>
                            <?php $has = isset($stored[$key]); ?>
                            <div class="ad-field">
                                <label class="sik-label" for="cred_<?= e_attr($key) ?>">
                                    <?= e((string) ($meta['label'] ?? $key)) ?>
                                    <?php if (!empty($meta['required'])): ?><span style="color:var(--ad-primary)">*</span><?php endif; ?>
                                </label>
                                <input class="sik-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>"
                                       id="cred_<?= e_attr($key) ?>" name="cred_<?= e_attr($key) ?>"
                                       type="<?= ($meta['type'] ?? 'text') === 'password' ? 'password' : 'text' ?>"
                                       autocomplete="off"
                                       placeholder="<?= $has ? 'Saved — leave blank to keep it' : '' ?>">
                                <?php if (isset($errors[$key])): ?>
                                    <span class="sik-error"><?= e($errors[$key]) ?></span>
                                <?php elseif (!empty($meta['help'])): ?>
                                    <span class="sik-help"><?= e((string) $meta['help']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Pickup ----------------------------------------------- -->
                <div class="ad-card" style="margin:0 0 16px">
                    <div class="ad-card__head">
                        <div>
                            <h2 class="ad-card__title">Pickup address</h2>
                            <div class="ad-card__sub">Where the courier collects, and the GSTIN printed on the label.</div>
                        </div>
                    </div>
                    <div class="ad-card__body">
                        <div class="ad-grid ad-grid--2">
                            <div class="ad-field">
                                <label class="sik-label" for="pickupName">Contact name</label>
                                <input class="sik-input" id="pickupName" name="pickup_name" type="text" maxlength="150"
                                       value="<?= e((string) $provider['pickup_name']) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="pickupPhone">Phone</label>
                                <input class="sik-input<?= isset($errors['pickup_phone']) ? ' is-invalid' : '' ?>"
                                       id="pickupPhone" name="pickup_phone" type="tel" maxlength="20"
                                       value="<?= e((string) $provider['pickup_phone']) ?>">
                                <?php if (isset($errors['pickup_phone'])): ?>
                                    <span class="sik-error"><?= e($errors['pickup_phone']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="pickupAddress">Address</label>
                            <input class="sik-input" id="pickupAddress" name="pickup_address" type="text" maxlength="255"
                                   value="<?= e((string) $provider['pickup_address']) ?>">
                        </div>

                        <div class="ad-grid ad-grid--3">
                            <div class="ad-field">
                                <label class="sik-label" for="pickupCity">City</label>
                                <input class="sik-input" id="pickupCity" name="pickup_city" type="text" maxlength="100"
                                       value="<?= e((string) $provider['pickup_city']) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="pickupState">State</label>
                                <input class="sik-input" id="pickupState" name="pickup_state" type="text" maxlength="100"
                                       value="<?= e((string) $provider['pickup_state']) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="pickupPin">PIN code</label>
                                <input class="sik-input<?= isset($errors['pickup_pincode']) ? ' is-invalid' : '' ?>"
                                       id="pickupPin" name="pickup_pincode" type="text" inputmode="numeric" maxlength="10"
                                       value="<?= e((string) $provider['pickup_pincode']) ?>">
                                <?php if (isset($errors['pickup_pincode'])): ?>
                                    <span class="sik-error"><?= e($errors['pickup_pincode']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ad-grid ad-grid--2">
                            <div class="ad-field">
                                <label class="sik-label" for="pickupEmail">Email</label>
                                <input class="sik-input" id="pickupEmail" name="pickup_email" type="email" maxlength="190"
                                       value="<?= e((string) $provider['pickup_email']) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="gstNumber">GSTIN</label>
                                <input class="sik-input" id="gstNumber" name="gst_number" type="text" maxlength="20"
                                       value="<?= e((string) $provider['gst_number']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Webhook ---------------------------------------------- -->
                <div class="ad-card" style="margin:0">
                    <div class="ad-card__head">
                        <div>
                            <h2 class="ad-card__title">Webhook</h2>
                            <div class="ad-card__sub">Where the courier pushes status changes.</div>
                        </div>
                    </div>
                    <div class="ad-card__body">
                        <div class="ad-field">
                            <label class="sik-label">Endpoint to give the courier</label>
                            <input class="sik-input ad-mono" type="text" readonly
                                   value="<?= e(url('api/shipping/webhook.php?provider=' . urlencode($code))) ?>">
                            <span class="sik-help">Read-only. Paste this into the courier's dashboard.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="webhookSecret">Signing secret</label>
                            <input class="sik-input" id="webhookSecret" name="webhook_secret" type="password"
                                   autocomplete="off"
                                   placeholder="<?= !empty($provider['webhook_secret']) ? 'Saved — leave blank to keep it' : '' ?>">
                            <span class="sik-help">Used to verify that an inbound call really came from the courier.</span>
                        </div>

                        <label class="ad-switch">
                            <input type="checkbox" name="webhook_enabled" value="1"
                                   <?= (int) $provider['webhook_enabled'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Accept webhooks from this courier</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Aside -------------------------------------------------- -->
            <div>
                <div class="ad-card" style="margin:0 0 16px">
                    <div class="ad-card__head"><div><h2 class="ad-card__title">Status</h2></div></div>
                    <div class="ad-card__body">
                        <label class="ad-switch" style="margin-bottom:12px">
                            <input type="checkbox" name="status" value="1"
                                   <?= $provider['status'] === 'active' ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Active</span>
                        </label>

                        <div class="ad-field">
                            <label class="sik-label" for="shipMode">Mode</label>
                            <select class="sik-select" id="shipMode" name="mode">
                                <option value="test" <?= $provider['mode'] === 'test' ? 'selected' : '' ?>>Test</option>
                                <option value="live" <?= $provider['mode'] === 'live' ? 'selected' : '' ?>>Live</option>
                            </select>
                            <span class="sik-help">Live mode books real consignments and is billed.</span>
                        </div>

                        <label class="ad-switch" style="margin-bottom:10px">
                            <input type="checkbox" name="is_default" value="1"
                                   <?= (int) $provider['is_default'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Default courier</span>
                        </label>

                        <label class="ad-switch" style="margin-bottom:10px">
                            <input type="checkbox" name="supports_cod" value="1"
                                   <?= (int) $provider['supports_cod'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Supports COD</span>
                        </label>

                        <label class="ad-switch">
                            <input type="checkbox" name="supports_return" value="1"
                                   <?= (int) $provider['supports_return'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Supports returns / RTO</span>
                        </label>
                    </div>
                </div>

                <div class="ad-card" style="margin:0">
                    <div class="ad-card__head"><div><h2 class="ad-card__title">Connection</h2></div></div>
                    <div class="ad-card__body">
                        <?php
                        $health = (string) $provider['last_status'];
                        $tone   = $health === 'ok' ? 'sik-status--green'
                                : ($health === 'failed' ? 'sik-status--red' : 'sik-status--gray');
                        ?>
                        <p style="margin:0 0 10px">
                            <span class="sik-status <?= $tone ?>">
                                <?= e($health === 'ok' ? 'Connected' : ($health === 'failed' ? 'Failing' : 'Not tested')) ?>
                            </span>
                        </p>
                        <?php if (!empty($provider['last_message'])): ?>
                            <p class="ad-muted" style="margin:0 0 10px;font-size:12.5px"><?= e((string) $provider['last_message']) ?></p>
                        <?php endif; ?>

                        <?php // Save first: the test runs against what is stored, not what is typed. ?>
                        <button type="button" class="ad-btn" style="width:100%"
                                data-test-connection
                                data-url="<?= e_attr(admin_url('shipping/test.php')) ?>"
                                data-code="<?= e_attr($code) ?>">
                            <?= icon('refresh', 'w-4 h-4') ?> Test connection
                        </button>
                        <span class="sik-help" style="display:block;margin-top:8px">
                            Tests the saved credentials. Save any change first.
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card__foot" style="margin-top:16px">
            <a class="ad-btn" href="<?= e(admin_url('shipping/')) ?>">Cancel</a>
            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save</button>
        </div>
    </form>
</div>

<script>
(function () {
    var button = document.querySelector('[data-test-connection]');
    if (!button) { return; }

    button.addEventListener('click', async function () {
        button.disabled = true;
        var original = button.innerHTML;
        button.textContent = 'Testing…';

        var result = await SIK.post(button.dataset.url, { code: button.dataset.code });

        button.disabled = false;
        button.innerHTML = original;
        SIK.toast(result.message || (result.success ? 'Connected' : 'Failed'),
                  result.success ? 'success' : 'error');

        // The badge above is rendered server-side, so reload to show the new
        // health rather than painting a second, possibly disagreeing, copy.
        if (result.success) { setTimeout(function () { window.location.reload(); }, 900); }
    });
}());
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
