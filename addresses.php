<?php
/**
 * ShopInnKart - Saved delivery addresses.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();
$userId = (int) $user['id'];

$addresses = Database::fetchAll(
    'SELECT * FROM `user_addresses` WHERE `user_id` = :uid ORDER BY `is_default` DESC, `id` DESC',
    ['uid' => $userId]
);

$addressTypes = ['home' => 'Home', 'work' => 'Work', 'other' => 'Other'];

seo_set([
    'title'  => 'My Addresses',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('addresses', [
    'subtitle' => $addresses === []
        ? 'Save an address once and checkout takes seconds.'
        : count($addresses) . ' saved address' . (count($addresses) === 1 ? '' : 'es') . '.',
    'action'   => '<button type="button" class="sik-btn sik-btn--primary sik-btn--sm" data-address-new>'
        . icon('plus', 'w-4 h-4') . ' <span class="sik-btn__label">Add address</span></button>',
]);
?>

<?php if ($addresses === []): ?>
    <div class="sik-panel">
        <div class="sik-panel__body">
            <div class="sik-empty" style="color:var(--sik-muted)">
                <?= icon('location', 'w-16 h-16') ?>
                <p class="sik-empty__title" style="color:var(--sik-text)">No addresses saved yet</p>
                <p class="sik-empty__text">Add a delivery address so we know where to send your orders.</p>
                <button type="button" class="sik-btn sik-btn--primary" data-address-new>
                    <?= icon('plus', 'w-4 h-4') ?> <span class="sik-btn__label">Add your first address</span>
                </button>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2" id="sikAddressGrid">
        <?php foreach ($addresses as $address): ?>
            <?php $addressId = (int) $address['id']; ?>
            <div class="sik-addresscard"
                 data-address-row="<?= $addressId ?>"
                 data-id="<?= $addressId ?>"
                 data-label="<?= e_attr($address['label']) ?>"
                 data-type="<?= e_attr($address['address_type']) ?>"
                 data-name="<?= e_attr($address['full_name']) ?>"
                 data-phone="<?= e_attr($address['phone']) ?>"
                 data-address="<?= e_attr($address['address_line1']) ?>"
                 data-address2="<?= e_attr((string) $address['address_line2']) ?>"
                 data-landmark="<?= e_attr((string) $address['landmark']) ?>"
                 data-city="<?= e_attr($address['city']) ?>"
                 data-state="<?= e_attr($address['state']) ?>"
                 data-pincode="<?= e_attr($address['pincode']) ?>"
                 data-country="<?= e_attr($address['country']) ?>"
                 data-default="<?= (int) $address['is_default'] ?>"
                 style="cursor:default">

                <div style="display:flex;gap:var(--sp-2);align-items:center;flex-wrap:wrap;margin-bottom:var(--sp-2)">
                    <span class="sik-badge sik-badge--navy"><?= e($address['label']) ?></span>
                    <?php if ((int) $address['is_default'] === 1): ?>
                        <span class="sik-badge sik-badge--green">Default</span>
                    <?php endif; ?>
                </div>

                <strong style="display:block;font-size:14px"><?= e($address['full_name']) ?></strong>
                <div style="font-size:13px;color:var(--sik-muted);line-height:1.65;margin-top:var(--sp-1)">
                    <?php foreach (account_address_lines($address) as $line): ?>
                        <?= e($line) ?><br>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:13px;margin-top:var(--sp-2)"><?= icon('phone', 'w-3.5 h-3.5') ?> <?= e($address['phone']) ?></div>

                <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2);margin-top:var(--sp-4)">
                    <button type="button" class="sik-btn sik-btn--outline sik-btn--sm" data-address-edit="<?= $addressId ?>">
                        <?= icon('edit', 'w-4 h-4') ?> <span class="sik-btn__label">Edit</span>
                    </button>
                    <?php if ((int) $address['is_default'] !== 1): ?>
                        <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm" data-set-default-address="<?= $addressId ?>">
                            <?= icon('check', 'w-4 h-4') ?> <span class="sik-btn__label">Set default</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm" data-delete-address="<?= $addressId ?>"
                            style="color:var(--sik-danger)">
                        <?= icon('trash', 'w-4 h-4') ?> <span class="sik-btn__label">Delete</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="sik-modal" id="sikAddressModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sikAddressModalTitle">
    <div class="sik-modal__backdrop"></div>
    <div class="sik-modal__panel sik-modal__panel--md">
        <button type="button" class="sik-modal__close" data-close-modal aria-label="Close">
            <?= icon('close', 'w-4 h-4') ?>
        </button>

        <div style="padding:var(--sp-6) var(--sp-6) var(--sp-6)">
            <h2 id="sikAddressModalTitle" style="font-size:18px;margin-bottom:var(--sp-5)">Add a new address</h2>

            <form id="sikAddressForm" data-ajax-form="account/address-save.php" data-reload-on-success="true" novalidate>
                <input type="hidden" name="address_id" value="">

                <div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2;gap:0 var(--sp-4)">
                    <div class="sik-field">
                        <label class="sik-label" for="addrName">Full name <span class="req">*</span></label>
                        <input class="sik-input" id="addrName" name="full_name" type="text" maxlength="150"
                               autocomplete="name" required>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="addrPhone">Mobile number <span class="req">*</span></label>
                        <input class="sik-input" id="addrPhone" name="phone" type="tel" inputmode="numeric"
                               maxlength="15" autocomplete="tel" required placeholder="10-digit mobile number">
                    </div>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="addrLine1">Flat, house no., building, street <span class="req">*</span></label>
                    <input class="sik-input" id="addrLine1" name="address_line1" type="text" maxlength="255"
                           autocomplete="address-line1" required>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="addrLine2">Area, colony (optional)</label>
                    <input class="sik-input" id="addrLine2" name="address_line2" type="text" maxlength="255"
                           autocomplete="address-line2">
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="addrLandmark">Landmark (optional)</label>
                    <input class="sik-input" id="addrLandmark" name="landmark" type="text" maxlength="150"
                           placeholder="e.g. opposite the metro station">
                </div>

                <div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:2;gap:0 var(--sp-4)">
                    <div class="sik-field">
                        <label class="sik-label" for="addrPincode">PIN code <span class="req">*</span></label>
                        <input class="sik-input" id="addrPincode" name="pincode" type="text" inputmode="numeric"
                               maxlength="6" pattern="[1-9][0-9]{5}" autocomplete="postal-code" required>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="addrCity">City <span class="req">*</span></label>
                        <input class="sik-input" id="addrCity" name="city" type="text" maxlength="100"
                               autocomplete="address-level2" required>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="addrState">State <span class="req">*</span></label>
                        <select class="sik-select" id="addrState" name="state" autocomplete="address-level1" required>
                            <option value="">Select a state</option>
                            <?php foreach (INDIAN_STATES as $state): ?>
                                <option value="<?= e($state) ?>"><?= e($state) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="addrType">Address type</label>
                        <select class="sik-select" id="addrType" name="address_type">
                            <?php foreach ($addressTypes as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="addrLabel">Label</label>
                    <input class="sik-input" id="addrLabel" name="label" type="text" maxlength="50"
                           placeholder="Home" value="Home">
                    <span class="sik-help">Shown on the address card, e.g. Home, Office, Mum&rsquo;s place.</span>
                </div>

                <label class="sik-check" style="margin-bottom:var(--sp-5)">
                    <input type="checkbox" name="is_default" value="1" id="addrDefault">
                    <span>Make this my default delivery address</span>
                </label>

                <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
                    <button type="submit" class="sik-btn sik-btn--primary">
                        <span class="sik-btn__label">Save address</span>
                    </button>
                    <button type="button" class="sik-btn sik-btn--outline" data-close-modal>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* checkout.js only wires address cards inside the checkout form, so the modal
   on this page drives itself. All values are read back out of the DOM. */
(function () {
    var modal = document.getElementById('sikAddressModal');
    var form = document.getElementById('sikAddressForm');
    var heading = document.getElementById('sikAddressModalTitle');
    if (!modal || !form) return;

    var FIELDS = {
        full_name: 'name',
        phone: 'phone',
        address_line1: 'address',
        address_line2: 'address2',
        landmark: 'landmark',
        city: 'city',
        state: 'state',
        pincode: 'pincode',
        label: 'label',
        address_type: 'type'
    };

    function open(card) {
        if (window.SIK && SIK.account) SIK.account.clearErrors(form);
        form.reset();

        var editing = !!card;
        heading.textContent = editing ? 'Edit address' : 'Add a new address';
        form.elements.address_id.value = editing ? (card.dataset.id || '') : '';

        // reset() already restored the "add" defaults, so only editing needs filling.
        if (editing) {
            Object.keys(FIELDS).forEach(function (name) {
                var field = form.elements[name];
                if (field) field.value = card.dataset[FIELDS[name]] || '';
            });
        }

        var isDefault = form.elements.is_default;
        if (isDefault) {
            isDefault.checked = editing && card.dataset.default === '1';
            // Un-ticking would leave the account with no default, so promote
            // another address instead of clearing this one.
            isDefault.disabled = isDefault.checked;
        }

        SIK.openModal('sikAddressModal');
        setTimeout(function () { form.elements.full_name.focus(); }, 120);
    }

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('[data-address-new]');
        if (addButton) {
            event.preventDefault();
            open(null);
            return;
        }

        var editButton = event.target.closest('[data-address-edit]');
        if (editButton) {
            event.preventDefault();
            open(editButton.closest('[data-address-row]'));
        }
    });
})();
</script>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
