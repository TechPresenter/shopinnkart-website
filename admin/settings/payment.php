<?php
/**
 * ShopInnKart Admin - Payment methods.
 *
 * This screen edits the payment_methods table, not the settings table. A row
 * only becomes a usable checkout option when PaymentGatewayFactory can build
 * a gateway for its code, so the list says plainly which rows are backed by
 * working code and the save refuses to activate the ones that are not.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

/** True when a gateway class is registered for this code. */
function payment_is_implemented(string $code): bool
{
    return PaymentGatewayFactory::make($code) !== null;
}

/** Orders already placed with a method code - history we must not orphan. */
function payment_order_count(string $code): int
{
    return (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `orders` WHERE `payment_method` = :c',
        ['c' => $code]
    );
}

$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Delete
// ---------------------------------------------------------------------------
if (is_post() && $action === 'delete') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $method = Database::fetch('SELECT * FROM `payment_methods` WHERE `id` = :id', ['id' => $id]);

    if ($method === null) {
        flash('error', 'That payment method no longer exists.');
        redirect(settings_url('payment'));
    }

    $used = payment_order_count((string) $method['code']);
    if ($used > 0) {
        flash('error', $method['name'] . ' is attached to ' . $used . ' order(s). Set it to inactive instead of deleting it.');
        redirect(settings_url('payment'));
    }

    delete_upload($method['logo']);
    Database::delete('payment_methods', '`id` = :id', ['id' => $id]);

    log_activity('payment_method.deleted', 'payment_method', $id, 'Deleted payment method "' . $method['name'] . '"');
    admin_after_write();

    flash('success', $method['name'] . ' deleted.');
    redirect(settings_url('payment'));
}

// ---------------------------------------------------------------------------
//  Create / update
// ---------------------------------------------------------------------------
if (is_post() && $action === 'save') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $existing = $id > 0
        ? Database::fetch('SELECT * FROM `payment_methods` WHERE `id` = :id', ['id' => $id])
        : null;

    if ($id > 0 && $existing === null) {
        flash('error', 'That payment method no longer exists.');
        redirect(settings_url('payment'));
    }

    // The code binds the row to a gateway class and to every order already
    // placed with it, so it is set once and then frozen.
    $code = $existing !== null
        ? (string) $existing['code']
        : mb_strtolower(trim((string) input('code', '')));

    $errors = [];

    if ($existing === null) {
        if ($code === '') {
            $errors['code'] = 'Code is required.';
        } elseif (preg_match('/^[a-z0-9_-]{2,40}$/', $code) !== 1) {
            $errors['code'] = 'Code may only contain lower-case letters, numbers, hyphens and underscores.';
        } elseif (Database::exists('payment_methods', '`code` = :c', ['c' => $code])) {
            $errors['code'] = 'Another payment method already uses that code.';
        }
    }

    $name = trim((string) input('name', ''));
    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors['name'] = 'Name must not exceed 100 characters.';
    }

    $status = (string) input('status', 'inactive');
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'inactive';
    }

    // The whole point of the implementation column: an active row with no
    // gateway behind it would show at checkout and then fail.
    if ($status === 'active' && !payment_is_implemented($code)) {
        $errors['status'] = 'There is no gateway implementation for "' . $code . '" yet, so it cannot be activated.';
    }

    $numbers = [];
    foreach (['extra_charge' => 0.0, 'discount_percent' => 0.0] as $field => $default) {
        $raw = trim((string) input($field, ''));
        if ($raw === '') {
            $numbers[$field] = $default;
        } elseif (!is_numeric($raw) || (float) $raw < 0) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be zero or more.';
        } else {
            $numbers[$field] = (float) $raw;
        }
    }
    if (isset($numbers['discount_percent']) && $numbers['discount_percent'] > 100) {
        $errors['discount_percent'] = 'Discount percent cannot exceed 100.';
    }

    $limits = [];
    foreach (['min_amount', 'max_amount'] as $field) {
        $raw = trim((string) input($field, ''));
        if ($raw === '') {
            $limits[$field] = null;
        } elseif (!is_numeric($raw) || (float) $raw < 0) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be zero or more, or blank for no limit.';
        } else {
            $limits[$field] = (float) $raw;
        }
    }
    if (!isset($errors['min_amount'], $errors['max_amount'])
        && $limits['min_amount'] !== null && $limits['max_amount'] !== null
        && $limits['max_amount'] < $limits['min_amount']) {
        $errors['max_amount'] = 'Maximum order value must be higher than the minimum.';
    }

    $config = trim((string) input('config', ''));
    if ($config !== '') {
        json_decode($config, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors['config'] = 'Config must be valid JSON: ' . json_last_error_msg() . '.';
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        flash_old($_POST);
        flash('error', 'Nothing was saved. Please fix the highlighted fields.');
        redirect(settings_url('payment') . ($id > 0 ? '?edit=' . $id : '?edit=new'));
    }

    $row = [
        'name'             => $name,
        'description'      => trim((string) input('description', '')) ?: null,
        'instructions'     => trim((string) input('instructions', '')) ?: null,
        'is_online'        => input_bool('is_online') ? 1 : 0,
        'extra_charge'     => $numbers['extra_charge'],
        'discount_percent' => $numbers['discount_percent'],
        'min_amount'       => $limits['min_amount'],
        'max_amount'       => $limits['max_amount'],
        'config'           => $config === '' ? null : $config,
        'sort_order'       => max(0, input_int('sort_order')),
        'status'           => $status,
    ];

    if ($existing !== null) {
        if (input_bool('remove_logo')) {
            delete_upload($existing['logo']);
            $row['logo'] = null;
        } else {
            $row['logo'] = admin_handle_image('logo', 'payments', $existing['logo']);
        }

        Database::update('payment_methods', $row, '`id` = :id', ['id' => $id]);
        log_activity('payment_method.updated', 'payment_method', $id, 'Updated payment method "' . $name . '"');
        flash('success', $name . ' updated.');
    } else {
        $row['code'] = $code;
        $row['logo'] = admin_handle_image('logo', 'payments', null);

        $id = Database::insert('payment_methods', $row);
        log_activity('payment_method.created', 'payment_method', $id, 'Created payment method "' . $name . '"');
        flash('success', $name . ' added.');
    }

    admin_after_write();
    redirect(settings_url('payment'));
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$methods = Database::fetchAll('SELECT * FROM `payment_methods` ORDER BY `sort_order`, `id`');

$editParam = (string) input('edit', '');
$editing = null;
if ($editParam === 'new') {
    $editing = [
        'id' => 0, 'code' => '', 'name' => '', 'description' => '', 'instructions' => '',
        'logo' => null, 'is_online' => 1, 'extra_charge' => '0.00', 'discount_percent' => '0.00',
        'min_amount' => '', 'max_amount' => '', 'config' => '', 'sort_order' => count($methods) + 1,
        'status' => 'inactive',
    ];
} elseif ($editParam !== '' && ctype_digit($editParam)) {
    $editing = Database::fetch('SELECT * FROM `payment_methods` WHERE `id` = :id', ['id' => (int) $editParam]);
    if ($editing === null) {
        flash('error', 'That payment method no longer exists.');
        redirect(settings_url('payment'));
    }
}

$errors = errors_pull();
$hasOld = settings_has_old();

/** Field value for the form: rejected input first, then the row. */
$formValue = static function (string $key, $default = '') use ($editing, $hasOld) {
    if ($hasOld) {
        $submitted = old($key, null);
        if ($submitted !== null) {
            return $submitted;
        }
        // Switches post nothing when off, so a bounce means "off".
        if (in_array($key, ['is_online'], true)) {
            return '0';
        }
    }
    return $editing[$key] ?? $default;
};

$liveCount = 0;
foreach ($methods as $method) {
    if (payment_is_implemented((string) $method['code'])) {
        $liveCount++;
    }
}
$activeCount = count(PaymentGatewayFactory::available());

$pageTitle    = 'Payment Methods';
$pageSubtitle = 'What a shopper can choose at checkout, and what each option costs them.';
$breadcrumbs  = settings_breadcrumbs('payment');
$pageActions  = admin_can('settings.edit')
    ? '<a class="ad-btn ad-btn--primary" href="' . e(settings_url('payment') . '?edit=new') . '">'
        . icon('plus', 'w-4 h-4') . ' Add Method</a>'
    : '';

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('payment') ?>

<div class="ad-grid ad-grid--3" style="margin-bottom:18px">
    <?= admin_stat_card('Configured methods', (string) count($methods), 'credit-card', 'navy',
        'Rows in the payment_methods table') ?>
    <?= admin_stat_card('With a working gateway', (string) $liveCount, 'shield', 'green',
        'Backed by a PaymentGatewayInterface class') ?>
    <?= admin_stat_card('Offered at checkout', (string) $activeCount, 'cart', 'primary',
        'Active and implemented') ?>
</div>

<div class="sik-alert sik-alert--info">
    <?= icon('info', 'w-5 h-5') ?>
    <div>
        Only <strong>Cash on Delivery</strong> has a gateway implementation today. The other rows are
        configuration placeholders: their credentials can be filled in now, but until a
        <code>PaymentGatewayInterface</code> class is registered for the code they cannot be activated,
        because checkout would offer an option it is unable to charge.
    </div>
</div>

<?php if ($editing !== null && admin_can('settings.edit')): ?>
    <?php $isNew = (int) ($editing['id'] ?? 0) === 0; ?>
    <form class="ad-form" method="post" enctype="multipart/form-data"
          action="<?= e(settings_url('payment')) ?>" data-guard-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= $isNew ? 'Add a payment method' : 'Edit ' . e((string) $editing['name']) ?></div>
                    <div class="ad-card__sub">
                        <?php if ($isNew): ?>
                            The code you pick must match a registered gateway class before this method can go live.
                        <?php elseif (payment_is_implemented((string) $editing['code'])): ?>
                            <span class="sik-status sik-status--green">Gateway implemented</span>
                            This method can be activated and charged.
                        <?php else: ?>
                            <span class="sik-status sik-status--amber">Awaiting integration</span>
                            Credentials can be stored, but the method stays unavailable at checkout.
                        <?php endif; ?>
                    </div>
                </div>
                <a class="ad-btn ad-btn--sm" href="<?= e(settings_url('payment')) ?>">Close</a>
            </div>

            <div class="ad-card__body">
                <div class="ad-row ad-row--3">
                    <div class="ad-field">
                        <label class="sik-label" for="pmCode">Code <span class="req">*</span></label>
                        <?php if ($isNew): ?>
                            <input class="sik-input<?= isset($errors['code']) ? ' is-invalid' : '' ?>" type="text"
                                   id="pmCode" name="code" maxlength="40" required spellcheck="false"
                                   value="<?= e((string) $formValue('code')) ?>" placeholder="razorpay">
                        <?php else: ?>
                            <input class="sik-input" type="text" id="pmCode" value="<?= e((string) $editing['code']) ?>"
                                   readonly disabled>
                        <?php endif; ?>
                        <?php if (isset($errors['code'])): ?>
                            <span class="sik-error"><?= e($errors['code']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                <?= $isNew
                                    ? 'Lower case, no spaces. This is what orders store and what the gateway factory looks up.'
                                    : 'Frozen after creation - existing orders reference it.' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="pmName">Name <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                               id="pmName" name="name" maxlength="100" required
                               value="<?= e((string) $formValue('name')) ?>" placeholder="Razorpay">
                        <?php if (isset($errors['name'])): ?>
                            <span class="sik-error"><?= e($errors['name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="pmSort">Sort order</label>
                        <input class="sik-input" type="number" id="pmSort" name="sort_order" min="0" max="9999"
                               value="<?= (int) $formValue('sort_order', 0) ?>">
                        <span class="sik-help">Lower numbers appear first at checkout.</span>
                    </div>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="pmDescription">Description</label>
                    <input class="sik-input" type="text" id="pmDescription" name="description" maxlength="255"
                           value="<?= e((string) $formValue('description')) ?>"
                           placeholder="One line shown next to the option at checkout.">
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="pmInstructions">Instructions</label>
                    <textarea class="sik-textarea" id="pmInstructions" name="instructions" rows="3"
                              placeholder="Shown after the shopper selects this method."><?= e((string) $formValue('instructions')) ?></textarea>
                </div>

                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <span class="sik-label">Logo</span>
                        <div class="ad-drop" data-drop="#pmLogoPreview">
                            <input type="file" name="logo" accept="image/*">
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">Click or drop the method logo</div>
                        </div>
                        <div class="ad-preview" id="pmLogoPreview">
                            <?php if (!empty($editing['logo'])): ?>
                                <div class="ad-preview__item">
                                    <img src="<?= e(img_url((string) $editing['logo'])) ?>" alt="Current logo">
                                    <button type="button" class="ad-preview__remove" data-remove-image="#pmRemoveLogo"
                                            aria-label="Remove logo">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="remove_logo" id="pmRemoveLogo" value="0">
                    </div>

                    <div style="display:grid;gap:14px;align-content:start">
                        <div class="ad-field">
                            <label class="sik-label" for="pmStatus">Status</label>
                            <select class="sik-select<?= isset($errors['status']) ? ' is-invalid' : '' ?>"
                                    id="pmStatus" name="status">
                                <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], (string) $formValue('status', 'inactive')) ?>
                            </select>
                            <?php if (isset($errors['status'])): ?>
                                <span class="sik-error"><?= e($errors['status']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Only a method with a gateway implementation can be set active.</span>
                            <?php endif; ?>
                        </div>

                        <label class="ad-switch">
                            <input type="checkbox" name="is_online" value="1"
                                   <?= (string) $formValue('is_online', '0') === '1' ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Online payment (collected before dispatch)</span>
                        </label>
                    </div>
                </div>

                <fieldset class="ad-fieldset">
                    <legend>Charges &amp; limits</legend>
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="pmExtra">Extra charge (<?= e((string) setting('currency_symbol', CURRENCY_SYMBOL)) ?>)</label>
                            <input class="sik-input<?= isset($errors['extra_charge']) ? ' is-invalid' : '' ?>" type="number"
                                   id="pmExtra" name="extra_charge" min="0" step="0.01"
                                   value="<?= e((string) $formValue('extra_charge', '0')) ?>">
                            <?php if (isset($errors['extra_charge'])): ?>
                                <span class="sik-error"><?= e($errors['extra_charge']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Added to the order total, e.g. a COD handling fee.</span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="pmDiscount">Discount (%)</label>
                            <input class="sik-input<?= isset($errors['discount_percent']) ? ' is-invalid' : '' ?>" type="number"
                                   id="pmDiscount" name="discount_percent" min="0" max="100" step="0.01"
                                   value="<?= e((string) $formValue('discount_percent', '0')) ?>">
                            <?php if (isset($errors['discount_percent'])): ?>
                                <span class="sik-error"><?= e($errors['discount_percent']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Prepaid incentive, applied to the subtotal.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="pmMin">Minimum order value</label>
                            <input class="sik-input<?= isset($errors['min_amount']) ? ' is-invalid' : '' ?>" type="number"
                                   id="pmMin" name="min_amount" min="0" step="0.01"
                                   value="<?= e((string) $formValue('min_amount')) ?>" placeholder="No minimum">
                            <?php if (isset($errors['min_amount'])): ?>
                                <span class="sik-error"><?= e($errors['min_amount']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ad-field">
                            <label class="sik-label" for="pmMax">Maximum order value</label>
                            <input class="sik-input<?= isset($errors['max_amount']) ? ' is-invalid' : '' ?>" type="number"
                                   id="pmMax" name="max_amount" min="0" step="0.01"
                                   value="<?= e((string) $formValue('max_amount')) ?>" placeholder="No maximum">
                            <?php if (isset($errors['max_amount'])): ?>
                                <span class="sik-error"><?= e($errors['max_amount']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>

                <div class="ad-field">
                    <label class="sik-label" for="pmConfig">Gateway config (JSON)</label>
                    <textarea class="sik-textarea<?= isset($errors['config']) ? ' is-invalid' : '' ?>"
                              id="pmConfig" name="config" rows="6" spellcheck="false"
                              style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                              placeholder='{"key_id":"","key_secret":"","mode":"test"}'><?= e((string) $formValue('config')) ?></textarea>
                    <?php if (isset($errors['config'])): ?>
                        <span class="sik-error"><?= e($errors['config']) ?></span>
                    <?php else: ?>
                        <span class="sik-help">
                            Keys and secrets for the gateway. Stored as-is and only read by the gateway class,
                            so leave it blank for offline methods.
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ad-card__foot">
                <a class="ad-btn" href="<?= e(settings_url('payment')) ?>">Cancel</a>
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('check', 'w-4 h-4') ?> <?= $isNew ? 'Add Method' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">All payment methods</div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($methods === []): ?>
            <?= admin_empty(
                'No payment methods yet',
                'Checkout needs at least one active method with a working gateway. Cash on Delivery is the one implemented today.',
                admin_can('settings.edit') ? 'Add Method' : null,
                admin_can('settings.edit') ? settings_url('payment') . '?edit=new' : null,
                'credit-card'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Code</th>
                            <th>Implementation</th>
                            <th>Type</th>
                            <th class="ad-table__num">Charge / discount</th>
                            <th class="ad-table__num">Order value</th>
                            <th class="ad-table__num">Orders</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($methods as $method): ?>
                            <?php
                            $code = (string) $method['code'];
                            $implemented = payment_is_implemented($code);
                            $orderCount = payment_order_count($code);
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <img class="ad-thumb" src="<?= e(img_url((string) $method['logo'])) ?>" alt="" loading="lazy">
                                        <div style="min-width:0">
                                            <div class="ad-cellflex__name"><?= e((string) $method['name']) ?></div>
                                            <div class="ad-cellflex__meta"><?= e(str_limit((string) $method['description'], 60)) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="ad-mono"><?= e($code) ?></td>
                                <td>
                                    <?php if ($implemented): ?>
                                        <span class="sik-status sik-status--green">Working gateway</span>
                                    <?php else: ?>
                                        <span class="sik-status sik-status--amber">Awaiting integration</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $method['is_online'] === 1 ? 'Online' : 'Offline' ?></td>
                                <td class="ad-table__num">
                                    <?php if ((float) $method['extra_charge'] > 0): ?>
                                        +<?= e(money((float) $method['extra_charge'])) ?>
                                    <?php endif; ?>
                                    <?php if ((float) $method['discount_percent'] > 0): ?>
                                        <span class="ad-muted">-<?= e(rtrim(rtrim(number_format((float) $method['discount_percent'], 2), '0'), '.')) ?>%</span>
                                    <?php endif; ?>
                                    <?php if ((float) $method['extra_charge'] <= 0 && (float) $method['discount_percent'] <= 0): ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num">
                                    <?php if ($method['min_amount'] === null && $method['max_amount'] === null): ?>
                                        <span class="ad-muted">Any</span>
                                    <?php else: ?>
                                        <?= e($method['min_amount'] === null ? 'Any' : money((float) $method['min_amount'])) ?>
                                        &ndash;
                                        <?= e($method['max_amount'] === null ? 'Any' : money((float) $method['max_amount'])) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= number_format($orderCount) ?></td>
                                <td><?= admin_state_badge((string) $method['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if (admin_can('settings.edit')): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit" aria-label="Edit"
                                           href="<?= e(settings_url('payment') . '?edit=' . (int) $method['id']) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                        <?php if ($orderCount === 0): ?>
                                            <?= admin_delete_form(
                                                settings_url('payment') . '?action=delete',
                                                (int) $method['id'],
                                                'Delete "' . $method['name'] . '"? This cannot be undone.'
                                            ) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="ad-card__foot">
        <span class="ad-muted" style="margin-right:auto;font-size:12.5px">
            <?php if (admin_can('settings.edit')): ?>
                A method with orders against it cannot be deleted &mdash; set it to inactive so the history stays readable.
            <?php else: ?>
                You have read-only access to settings. Ask a Super Admin for the
                <code>settings.edit</code> permission to change anything here.
            <?php endif; ?>
        </span>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
