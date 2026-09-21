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
 * Permission: orders.*, because shipping is order fulfilment. Inventing a
 * `shipping.view` key would resolve to nobody until every role was edited,
 * hiding the screen from the owner too.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-functions.php';

$canEdit   = admin_can('orders.edit');
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
