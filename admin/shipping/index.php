<?php
/**
 * ShopInnKart Admin - Shipping > Integrations.
 *
 * Lists every courier integration with the one thing an operator actually
 * needs to know: not whether it is switched on, but whether it works. A row
 * can be active and still be failing, so `last_status` is shown beside the
 * status rather than instead of it.
 *
 * A row whose code has no driver is shown as unavailable rather than offered.
 * Switching on an integration nobody wrote code for would mean discovering it
 * at booking time, with a customer waiting.
 *
 * Permission: settings.*, the keys payment gateway and SMTP credentials
 * already use. Booking and tracking stay on orders.* (they are fulfilment),
 * but this screen holds courier accounts: whoever can change them can point
 * bookings at another account or set the webhook secret to one they know and
 * post "delivered" for COD orders. The Order Manager and Manager roles hold
 * orders.edit and are described as having no system settings. An existing
 * key, not a new shipping.* one, so no role needs editing to keep access.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once INCLUDES_PATH . '/shipping-functions.php';

$canEdit   = admin_can('settings.edit');
$providers = shipping_providers_all();

// Counts for the stat row. Read from the rows already fetched rather than
// four more queries.
$active = 0;
$broken = 0;
foreach ($providers as $row) {
    if ($row['status'] === 'active') {
        $active++;
    }
    if ($row['last_status'] === 'failed') {
        $broken++;
    }
}

$shipmentCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM `shipments`');

// How a courier is being chosen right now. Shown here because this is the
// screen an operator opens when a parcel went somewhere they did not expect,
// and "which courier does it pick, and does it pick on its own?" is the first
// question - a question that was previously only answerable by reading the
// settings screen and the code.
require_once INCLUDES_PATH . '/shipping-service.php';
$selectWeights = shipping_selection_weights();
$autoShip      = shipping_autoship_enabled();
$autoCodMax    = shipping_autoship_cod_limit();
$autoMaxAge    = shipping_autoship_max_age_days();
$autoWaiting   = $autoShip ? count(shipping_autoship_candidates(200)) : 0;
$pctWeight     = static fn (float $share): string => number_format($share * 100, 0) . '%';

$pageTitle    = 'Shipping Integrations';
$pageSubtitle = 'Courier accounts, credentials and connection health.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Shipping'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-container">

    <?php if (ShippingProviderFactory::available() === []): ?>
        <div class="ad-card" style="margin-bottom:16px;border-left:3px solid var(--ad-primary)"><div class="ad-card__body">
            <strong>No courier is live yet.</strong>
            Configure one below and set it to Active. The
            <strong>Mock Courier</strong> runs the whole booking, tracking and
            return flow without an account, so the rest of the shipping screens
            can be used before a real integration exists.
        </div></div>
    <?php endif; ?>

    <div class="ad-grid ad-grid--4" style="margin-bottom:16px">
        <div class="ad-stat">
            <span class="ad-stat__icon ad-stat__icon--navy"><?= icon('package', 'w-5 h-5') ?></span>
            <div><div class="ad-stat__value"><?= count($providers) ?></div>
                 <div class="ad-stat__label">Integrations</div></div>
        </div>
        <div class="ad-stat">
            <span class="ad-stat__icon ad-stat__icon--green"><?= icon('check-circle', 'w-5 h-5') ?></span>
            <div><div class="ad-stat__value"><?= $active ?></div>
                 <div class="ad-stat__label">Active</div></div>
        </div>
        <div class="ad-stat">
            <span class="ad-stat__icon ad-stat__icon--red"><?= icon('alert', 'w-5 h-5') ?></span>
            <div><div class="ad-stat__value"><?= $broken ?></div>
                 <div class="ad-stat__label">Failing</div></div>
        </div>
        <div class="ad-stat">
            <span class="ad-stat__icon ad-stat__icon--amber"><?= icon('truck', 'w-5 h-5') ?></span>
            <div><div class="ad-stat__value"><?= $shipmentCount ?></div>
                 <div class="ad-stat__label">Shipments</div></div>
        </div>
    </div>

    <!-- ============ How a courier gets chosen ============ -->
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <h2 class="ad-card__title">Automatic courier selection</h2>
                <div class="ad-card__sub">
                    Every quote is scored on cost, promised days, the courier's own delivered-against-returned
                    record and its rating. The booking screen pre-selects the winner; an admin can still pick
                    any other rate in one click.
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="ad-btn ad-btn--primary ad-btn--sm" href="<?= e(admin_url('shipping/rates.php')) ?>">
                    <?= icon('truck', 'w-4 h-4') ?> Rate calculator
                </a>
                <?php /* The hub's three screens all belong on the hub's landing page: an
                        operator chasing a parcel that came back should not have to find
                        the desk through the shipments list. */ ?>
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('shipping/shipments.php')) ?>">
                    <?= icon('package', 'w-4 h-4') ?> Shipments
                </a>
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('shipping/returns.php')) ?>">
                    <?= icon('rotate', 'w-4 h-4') ?> Returns &amp; RTO
                </a>
            </div>
        </div>
        <div class="ad-card__body">
            <p style="margin:0 0 8px">
                Weighted
                <strong>cost <?= e($pctWeight((float) $selectWeights['cost'])) ?></strong>,
                <strong>speed <?= e($pctWeight((float) $selectWeights['speed'])) ?></strong>,
                <strong>performance <?= e($pctWeight((float) $selectWeights['reliability'])) ?></strong>,
                <strong>rating <?= e($pctWeight((float) $selectWeights['rating'])) ?></strong>
                over the last <?= (int) shipping_performance_window() ?> days, judging a courier only once it
                has <?= (int) shipping_performance_minimum() ?> settled parcels behind it.
            </p>
            <p style="margin:0">
                <?php if ($autoShip): ?>
                    <span class="sik-status sik-status--green">Automatic shipping is ON</span>
                    Confirmed orders book themselves with the recommended courier on each pass of the
                    shipment cron (<code>bin/refresh-shipments.php</code>) - not the moment they are
                    confirmed, so a booking follows within one cron interval.
                    <?= $autoCodMax > 0
                        ? 'COD above ' . e(money($autoCodMax)) . ' is left for a person.'
                        : 'There is no COD ceiling, so any cash-on-delivery order may go out unattended.' ?>
                    <?= $autoMaxAge > 0
                        ? 'Only orders placed in the last ' . (int) $autoMaxAge . ' days are swept; older ones wait for a person.'
                        : 'There is no age limit, so the sweep reaches the whole order history - including orders long since settled by hand.' ?>
                    <span class="ad-muted"><?= (int) $autoWaiting ?> order<?= $autoWaiting === 1 ? '' : 's' ?> waiting for the next cron pass.</span>
                <?php else: ?>
                    <span class="sik-status sik-status--gray">Automatic shipping is OFF</span>
                    Every booking is made by a person. The recommendation is advice, not an action.
                <?php endif; ?>
            </p>
        </div>
        <?php if (admin_can('settings.edit')): ?>
            <div class="ad-card__foot">
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('settings/shipping.php')) ?>">
                    <?= icon('settings', 'w-4 h-4') ?> Change the weightings
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <h2 class="ad-card__title">Courier integrations</h2>
                <div class="ad-card__sub">Each one is a driver. Adding a courier adds a class, not a schema change.</div>
            </div>
        </div>

        <?php if ($providers === []): ?>
            <?= admin_empty('No integrations yet', 'Run the shipping migration to seed the mock courier.', null, null, 'truck') ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Courier</th>
                            <th>Mode</th>
                            <th>COD</th>
                            <th>Health</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($providers as $row): ?>
                            <?php
                            $code        = (string) $row['code'];
                            $implemented = ShippingProviderFactory::implemented($code);
                            $isActive    = $row['status'] === 'active';
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <span class="ad-stat__icon ad-stat__icon--navy" style="width:34px;height:34px;flex:none">
                                            <?= icon('truck', 'w-4 h-4') ?>
                                        </span>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?= e((string) $row['name']) ?>
                                                <?php if ((int) $row['is_default'] === 1): ?>
                                                    <span class="sik-status sik-status--blue">Default</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <span class="ad-mono"><?= e($code) ?></span>
                                                <?php if (!$implemented): ?>
                                                    &middot; <span style="color:var(--ad-danger,#B91C1C)">no driver installed</span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span class="sik-status <?= $row['mode'] === 'live' ? 'sik-status--green' : 'sik-status--amber' ?>">
                                        <?= e(ucfirst((string) $row['mode'])) ?>
                                    </span>
                                </td>

                                <td class="ad-muted"><?= (int) $row['supports_cod'] === 1 ? 'Yes' : 'No' ?></td>

                                <td>
                                    <?php
                                    $health = (string) $row['last_status'];
                                    $tone   = $health === 'ok' ? 'sik-status--green'
                                            : ($health === 'failed' ? 'sik-status--red' : 'sik-status--gray');
                                    ?>
                                    <span class="sik-status <?= $tone ?>">
                                        <?= e($health === 'ok' ? 'Connected' : ($health === 'failed' ? 'Failing' : 'Not tested')) ?>
                                    </span>
                                    <?php if (!empty($row['last_checked_at'])): ?>
                                        <div class="ad-cellflex__meta"><?= e(format_date((string) $row['last_checked_at'])) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($canEdit && $implemented): ?>
                                        <label class="ad-switch" title="<?= $isActive ? 'Live' : 'Off' ?>">
                                            <span class="sik-sr">Enable <?= e((string) $row['name']) ?></span>
                                            <input type="checkbox"
                                                   data-toggle-endpoint="<?= e_attr(admin_url('shipping/toggle.php')) ?>"
                                                   data-id="<?= (int) $row['id'] ?>" data-field="status"
                                                   <?= $isActive ? 'checked' : '' ?>>
                                            <span class="ad-switch__track"></span>
                                        </label>
                                    <?php else: ?>
                                        <span class="ad-muted"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="ad-table__actions">
                                    <?php if ($implemented): ?>
                                        <a class="ad-btn ad-btn--sm"
                                           href="<?= e(admin_url('shipping/configure.php?code=' . urlencode($code))) ?>">
                                            <?= icon('settings', 'w-4 h-4') ?> Configure
                                        </a>
                                    <?php else: ?>
                                        <span class="ad-muted">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <h2 class="ad-card__title">Adding a courier</h2>
                <div class="ad-card__sub">What it takes to support one that is not listed.</div>
            </div>
        </div>
        <div class="ad-card__body">
            <p class="ad-muted" style="margin:0 0 10px">
                Write a class implementing <code>ShippingProviderInterface</code> in
                <code>includes/shipping/</code>, register it in
                <code>ShippingProviderFactory</code>, and insert a row in
                <code>shipping_providers</code> with the same code. Nothing in the order,
                payment, product or customer modules changes.
            </p>
            <p class="ad-muted" style="margin:0">
                Drivers installed:
                <?php foreach (ShippingProviderFactory::implementedCodes() as $implementedCode): ?>
                    <span class="ad-mono"><?= e($implementedCode) ?></span>
                <?php endforeach; ?>
            </p>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
