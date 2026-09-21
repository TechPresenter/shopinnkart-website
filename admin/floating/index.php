<?php
/**
 * ShopInnKart Admin - Floating buttons.
 *
 * The list screen for `floating_buttons`. save.php, toggle.php and delete.php
 * already existed and already redirected here; this is the page they were
 * written against, and the "Open the builder" link on Settings → Widgets has
 * been pointing at it all along.
 *
 * Everything the form offers comes from the vocabularies in
 * includes/floating-functions.php - the same lists the storefront renderer and
 * save.php's validator use - so an option can never appear here that the
 * validator would reject, and a new action type shows up in all three places at
 * once.
 *
 * One form per row inside a <details>, the same shape the Footer Builder uses.
 * A rejected save comes back through the flash bag tagged `__form`, and the
 * matching row reopens with its errors.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.view');

require_once INCLUDES_PATH . '/floating-functions.php';

$rows = floating_buttons_all();

// The save endpoints bounce a failed submission back through the flash bag;
// __form says which of the many forms on this page it belongs to. The bag is
// copied out and cleared up front because the closures below run during
// rendering, long after old_clear() would have emptied the session copy.
$errors = errors_pull();
$oldBag = $_SESSION['_old'] ?? [];
old_clear();

$errorForm = $errors !== [] ? (string) ($oldBag['__form'] ?? '') : '';

$field = static function (string $form, string $key, $default) use ($errorForm, $oldBag) {
    return $errorForm === $form && array_key_exists($key, $oldBag) ? $oldBag[$key] : $default;
};
$errorIn = static function (string $form, string $key) use ($errorForm, $errors): string {
    return $errorForm === $form ? (string) ($errors[$key] ?? '') : '';
};

$openId    = strpos($errorForm, 'button:') === 0 ? (int) substr($errorForm, 7) : -1;
$addOpen   = $errorForm === 'button:0';
$canEdit   = admin_can('homepage.edit');
$activeNow = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'active'));

// What would actually render right now, for a desktop visitor. floating_buttons_live()
// applies the master switch, the device and auth rules and the "points nowhere"
// test, so comparing it against the active count is the honest readout: a row can
// be switched on and still draw nothing.
$liveNow    = count(floating_buttons_live());
$masterOn   = setting_bool('floating_buttons_enabled', true);
$storePhone = trim((string) setting('store_phone', ''));
$storeWa    = trim((string) setting('store_whatsapp', ''));

/** Renders the shared button form. $form is 'button:<id>'; $row is null when adding. */
$renderForm = static function (string $form, ?array $row) use ($field, $errorIn, $canEdit): void {
    $id  = (int) ($row['id'] ?? 0);
    $get = static fn (string $key, $default = '') => $field($form, $key, $row[$key] ?? $default);
    $err = static fn (string $key): string => $errorIn($form, $key);
    ?>
    <form method="post" action="<?= e(admin_url('floating/save.php')) ?>" class="ad-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="ad-grid ad-grid--2">
            <div class="ad-field">
                <label class="sik-label" for="fb_label_<?= $id ?>">Label <span class="req">*</span></label>
                <input class="sik-input<?= $err('label') !== '' ? ' is-invalid' : '' ?>" type="text"
                       id="fb_label_<?= $id ?>" name="label" maxlength="60" required
                       value="<?= e_attr((string) $get('label')) ?>">
                <?php if ($err('label') !== ''): ?>
                    <span class="sik-error"><?= e($err('label')) ?></span>
                <?php else: ?>
                    <span class="sik-help">Used as the accessible name, and as the visible pill when
                        &ldquo;Show the label&rdquo; is on.</span>
                <?php endif; ?>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="fb_tooltip_<?= $id ?>">Tooltip</label>
                <input class="sik-input<?= $err('tooltip') !== '' ? ' is-invalid' : '' ?>" type="text"
                       id="fb_tooltip_<?= $id ?>" name="tooltip" maxlength="120"
                       value="<?= e_attr((string) $get('tooltip')) ?>">
                <?php if ($err('tooltip') !== ''): ?><span class="sik-error"><?= e($err('tooltip')) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="ad-grid ad-grid--2">
            <div class="ad-field">
                <label class="sik-label" for="fb_action_<?= $id ?>">What it does</label>
                <select class="sik-select" id="fb_action_<?= $id ?>" name="action_type" data-fb-action>
                    <?= admin_options(floating_action_types(), (string) $get('action_type', 'link')) ?>
                </select>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="fb_icon_<?= $id ?>">Icon</label>
                <select class="sik-select<?= $err('icon') !== '' ? ' is-invalid' : '' ?>"
                        id="fb_icon_<?= $id ?>" name="icon">
                    <?php $current = (string) $get('icon', 'sparkle'); ?>
                    <?php foreach (icon_groups() as $group => $names): ?>
                        <optgroup label="<?= e_attr($group) ?>">
                            <?php foreach ($names as $name): ?>
                                <option value="<?= e_attr($name) ?>" <?= $current === $name ? 'selected' : '' ?>>
                                    <?= e($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('icon') !== ''): ?><span class="sik-error"><?= e($err('icon')) ?></span><?php endif; ?>
            </div>
        </div>

        <?php // One target block per action type. Only the selected one is shown,
              // and [hidden] is scoped in this screen's own <style> so it beats
              // .ad-field's display. ?>
        <div class="ad-field" data-fb-target="whatsapp" <?= $get('action_type', 'link') === 'whatsapp' ? '' : 'hidden' ?>>
            <label class="sik-label" for="fb_wa_<?= $id ?>">WhatsApp number</label>
            <input class="sik-input<?= $err('whatsapp_number') !== '' ? ' is-invalid' : '' ?>" type="tel"
                   id="fb_wa_<?= $id ?>" name="whatsapp_number" maxlength="30"
                   value="<?= e_attr((string) $get('whatsapp_number')) ?>">
            <span class="sik-help">Blank borrows the store WhatsApp number from Settings &rarr; General.</span>
            <input class="sik-input" type="text" name="whatsapp_message" maxlength="255"
                   style="margin-top:8px" placeholder="Prefilled message (optional)"
                   value="<?= e_attr((string) $get('whatsapp_message')) ?>">
        </div>

        <div class="ad-field" data-fb-target="call" <?= $get('action_type', 'link') === 'call' ? '' : 'hidden' ?>>
            <label class="sik-label" for="fb_phone_<?= $id ?>">Phone number</label>
            <input class="sik-input<?= $err('phone') !== '' ? ' is-invalid' : '' ?>" type="tel"
                   id="fb_phone_<?= $id ?>" name="phone" maxlength="30"
                   value="<?= e_attr((string) $get('phone')) ?>">
            <span class="sik-help">Blank borrows the store phone number from Settings &rarr; General.</span>
        </div>

        <div class="ad-field" data-fb-target="link" <?= $get('action_type', 'link') === 'link' ? '' : 'hidden' ?>>
            <label class="sik-label" for="fb_url_<?= $id ?>">URL</label>
            <input class="sik-input<?= $err('url') !== '' ? ' is-invalid' : '' ?>" type="text"
                   id="fb_url_<?= $id ?>" name="url" maxlength="255"
                   value="<?= e_attr((string) $get('url')) ?>"
                   placeholder="contact.php, https://…, mailto:… or tel:…">
            <?php if ($err('url') !== ''): ?><span class="sik-error"><?= e($err('url')) ?></span><?php endif; ?>
            <label class="ad-switch" style="margin-top:8px">
                <input type="checkbox" name="open_new_tab" value="1"
                       <?= (int) $get('open_new_tab', 0) === 1 ? 'checked' : '' ?>>
                <span class="ad-switch__track"></span>
                <span>Open in a new tab</span>
            </label>
        </div>

        <div class="ad-field" data-fb-target="scroll_top" <?= $get('action_type', 'link') === 'scroll_top' ? '' : 'hidden' ?>>
            <span class="sik-help">Scroll-to-top needs no target. It appears once the visitor has scrolled.</span>
        </div>

        <div class="ad-grid ad-grid--3">
            <div class="ad-field">
                <label class="sik-label" for="fb_pos_<?= $id ?>">Position</label>
                <select class="sik-select" id="fb_pos_<?= $id ?>" name="position">
                    <?= admin_options(floating_positions(), (string) $get('position', 'bottom-right')) ?>
                </select>
            </div>
            <div class="ad-field">
                <label class="sik-label" for="fb_size_<?= $id ?>">Size</label>
                <select class="sik-select" id="fb_size_<?= $id ?>" name="size">
                    <?= admin_options(floating_sizes(), (string) $get('size', 'md')) ?>
                </select>
            </div>
            <div class="ad-field">
                <label class="sik-label" for="fb_anim_<?= $id ?>">Animation</label>
                <select class="sik-select" id="fb_anim_<?= $id ?>" name="animation">
                    <?= admin_options(floating_animations(), (string) $get('animation', 'none')) ?>
                </select>
            </div>
        </div>

        <div class="ad-grid ad-grid--3">
            <div class="ad-field">
                <label class="sik-label" for="fb_dev_<?= $id ?>">Devices</label>
                <select class="sik-select" id="fb_dev_<?= $id ?>" name="device_visibility">
                    <?= admin_options(floating_device_options(), (string) $get('device_visibility', 'all')) ?>
                </select>
            </div>
            <div class="ad-field">
                <label class="sik-label" for="fb_auth_<?= $id ?>">Audience</label>
                <select class="sik-select" id="fb_auth_<?= $id ?>" name="auth_visibility">
                    <?= admin_options(floating_auth_options(), (string) $get('auth_visibility', 'all')) ?>
                </select>
            </div>
            <div class="ad-field">
                <label class="sik-label" for="fb_sort_<?= $id ?>">Order</label>
                <input class="sik-input<?= $err('sort_order') !== '' ? ' is-invalid' : '' ?>" type="number"
                       id="fb_sort_<?= $id ?>" name="sort_order" min="0" max="9999"
                       value="<?= e_attr((string) $get('sort_order', 0)) ?>">
                <?php if ($err('sort_order') !== ''): ?><span class="sik-error"><?= e($err('sort_order')) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="ad-grid ad-grid--2">
            <div class="ad-field">
                <label class="sik-label" for="fb_bg_<?= $id ?>">Background colour</label>
                <div class="ad-colorfield">
                    <input type="color" value="<?= e_attr(preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $get('bg_color')) === 1 ? (string) $get('bg_color') : '#ffffff') ?>"
                           data-color-picker="fb_bg_<?= $id ?>" aria-label="Background colour picker">
                    <input class="sik-input<?= $err('bg_color') !== '' ? ' is-invalid' : '' ?>" type="text"
                           id="fb_bg_<?= $id ?>" name="bg_color" maxlength="7" spellcheck="false"
                           placeholder="Theme default" value="<?= e_attr((string) $get('bg_color')) ?>">
                </div>
                <?php if ($err('bg_color') !== ''): ?><span class="sik-error"><?= e($err('bg_color')) ?></span><?php endif; ?>
            </div>
            <div class="ad-field">
                <label class="sik-label" for="fb_fg_<?= $id ?>">Icon colour</label>
                <div class="ad-colorfield">
                    <input type="color" value="<?= e_attr(preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $get('text_color')) === 1 ? (string) $get('text_color') : '#ffffff') ?>"
                           data-color-picker="fb_fg_<?= $id ?>" aria-label="Icon colour picker">
                    <input class="sik-input<?= $err('text_color') !== '' ? ' is-invalid' : '' ?>" type="text"
                           id="fb_fg_<?= $id ?>" name="text_color" maxlength="7" spellcheck="false"
                           placeholder="Theme default" value="<?= e_attr((string) $get('text_color')) ?>">
                </div>
                <?php if ($err('text_color') !== ''): ?><span class="sik-error"><?= e($err('text_color')) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="ad-grid ad-grid--2">
            <div class="ad-field">
                <label class="ad-switch">
                    <input type="checkbox" name="show_label" value="1"
                           <?= (int) $get('show_label', 0) === 1 ? 'checked' : '' ?>>
                    <span class="ad-switch__track"></span>
                    <span>Show the label beside the icon</span>
                </label>
            </div>
            <div class="ad-field">
                <label class="ad-switch">
                    <input type="checkbox" name="status" value="active"
                           <?= (string) $get('status', 'active') === 'active' ? 'checked' : '' ?>>
                    <span class="ad-switch__track"></span>
                    <span>Enabled</span>
                </label>
            </div>
        </div>

        <div class="ad-card__foot">
            <?php if ($id > 0): ?>
                <span class="ad-muted" style="margin-right:auto;font-size:12px">
                    Key <code><?= e((string) ($row['button_key'] ?? '')) ?></code>
                </span>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= icon('check', 'w-4 h-4') ?> <?= $id > 0 ? 'Save button' : 'Add button' ?>
                </button>
            <?php endif; ?>
        </div>
    </form>
    <?php
};

$pageTitle    = 'Floating Buttons';
$pageSubtitle = 'The helpers that float over the storefront: WhatsApp, call, scroll-to-top and custom links.';
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Appearance', 'url' => admin_url('appearance/')],
    ['label' => 'Floating Buttons'],
];

$pageActions = '<a class="ad-btn" href="' . e(url()) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View storefront</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<style>
    /* [hidden] must beat .ad-field's own display rule, or every target block
       renders at once. Scoped to this screen. */
    .ad-field[hidden] { display: none; }

    .fb-row { border: 1px solid var(--ad-border); border-radius: 10px; margin-bottom: 10px; background: var(--ad-surface); }
    .fb-row__head {
        display: flex; align-items: center; gap: 12px; padding: 12px 14px;
        cursor: pointer; list-style: none;
    }
    .fb-row__head::-webkit-details-marker { display: none; }
    .fb-row__dot {
        width: 34px; height: 34px; border-radius: 999px; flex: none;
        display: grid; place-items: center; color: #fff;
    }
    .fb-row__name { font-weight: 650; font-size: 14px; }
    .fb-row__meta { font-size: 11.5px; color: var(--ad-muted); }
    .fb-row__body { padding: 4px 14px 14px; border-top: 1px solid var(--ad-border); }
    .fb-row[open] .fb-row__caret { transform: rotate(180deg); }
    .fb-row__caret { transition: transform var(--dur-fast, .15s) var(--ease-out, ease); margin-left: auto; }
</style>

<?php if (!$masterOn): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            The master switch for floating buttons is <strong>off</strong>, so none of the rows below reach
            the storefront no matter how they are configured.
            <a href="<?= e(admin_url('settings/widgets.php')) ?>">Turn it on in Settings &rarr; Widgets</a>.
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--3" style="margin-bottom:16px">
    <?= admin_stat_card('Buttons', (string) count($rows), 'sparkle', 'primary') ?>
    <?= admin_stat_card('Enabled', (string) $activeNow, 'check-circle', $activeNow > 0 ? 'green' : 'navy') ?>
    <?= admin_stat_card('Rendering now', (string) $liveNow, 'eye', $liveNow > 0 ? 'green' : 'amber',
        'Desktop visitor, after device, audience and target checks') ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <h2 class="ad-card__title">Buttons</h2>
            <p class="ad-card__sub">Grouped by the corner they are pinned to, then by order.</p>
        </div>
    </div>
    <div class="ad-card__body">
        <?php if ($rows === []): ?>
            <?= admin_empty('No floating buttons yet',
                'Add one below. A WhatsApp or call button with no number borrows the store number.',
                null, null, 'sparkle') ?>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
            <?php
            $rowId   = (int) $row['id'];
            $target  = floating_button_target($row);
            $bg      = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($row['bg_color'] ?? '')) === 1
                ? (string) $row['bg_color'] : 'var(--ad-primary)';
            ?>
            <details class="fb-row" <?= $openId === $rowId ? 'open' : '' ?>>
                <summary class="fb-row__head">
                    <span class="fb-row__dot" style="background:<?= e_attr($bg) ?>">
                        <?= icon((string) $row['icon'], 'w-4 h-4') ?>
                    </span>
                    <span style="min-width:0">
                        <span class="fb-row__name"><?= e((string) $row['label']) ?></span>
                        <br>
                        <span class="fb-row__meta">
                            <?= e(floating_action_types()[$row['action_type']] ?? (string) $row['action_type']) ?>
                            &middot; <?= e(floating_positions()[$row['position']] ?? (string) $row['position']) ?>
                            &middot; <?= e(floating_device_options()[$row['device_visibility']] ?? '') ?>
                            <?php if ($target === null): ?>
                                &middot; <strong style="color:#B45309">points nowhere &mdash; not rendered</strong>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($canEdit): ?>
                        <?php // The list switch every sibling screen has. floating/toggle.php shipped
                              // for it and admin.js already drives [data-toggle-endpoint], but this
                              // page never rendered one - so the endpoint had no caller and enabling a
                              // button meant opening its form. Same component and same contract as
                              // Marketing > Popups; the row form's own Enabled box still writes the
                              // same column, so this is a second control, not a second source of
                              // truth. data-fb-stop keeps the click off the <summary> that wraps it. ?>
                        <label class="ad-switch" data-fb-stop
                               title="Enable or disable this floating button">
                            <input type="checkbox"
                                   data-toggle-endpoint="<?= e(admin_url('floating/toggle.php')) ?>"
                                   data-id="<?= $rowId ?>" data-field="status"
                                   <?= $row['status'] === 'active' ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span class="sik-sr">Enable <?= e((string) $row['label']) ?></span>
                        </label>
                    <?php else: ?>
                        <?= admin_state_badge((string) $row['status']) ?>
                    <?php endif; ?>
                    <span class="fb-row__caret"><?= icon('chevron-down', 'w-4 h-4') ?></span>
                </summary>
                <div class="fb-row__body">
                    <?php $renderForm('button:' . $rowId, $row); ?>
                    <?php if ($canEdit): ?>
                        <div style="display:flex;justify-content:flex-end;padding-top:8px">
                            <?= admin_delete_form(admin_url('floating/delete.php'), $rowId,
                                'Delete the floating button "' . (string) $row['label'] . '"? This cannot be undone.',
                                'Delete button') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($canEdit): ?>
<details class="ad-card" style="margin-top:16px" <?= $addOpen ? 'open' : '' ?>>
    <summary class="ad-card__head" style="cursor:pointer;list-style:none">
        <div>
            <h2 class="ad-card__title">Add a floating button</h2>
            <p class="ad-card__sub">
                <?php if ($storeWa === '' && $storePhone === ''): ?>
                    No store WhatsApp or phone number is set, so a WhatsApp or call button needs its own.
                <?php else: ?>
                    A WhatsApp or call button left blank borrows the store number.
                <?php endif; ?>
            </p>
        </div>
    </summary>
    <div class="ad-card__body">
        <?php $renderForm('button:0', null); ?>
    </div>
</details>
<?php endif; ?>

<script>
(function () {
    'use strict';

    /* Show only the target block the selected action actually uses. The blocks
       ship marked [hidden] from the server, so this is progressive: with
       JavaScript off the correct one is still the one on screen. */
    document.querySelectorAll('[data-fb-action]').forEach(function (select) {
        var form = select.closest('form');
        if (!form) return;

        function sync() {
            form.querySelectorAll('[data-fb-target]').forEach(function (block) {
                block.hidden = block.dataset.fbTarget !== select.value;
            });
        }

        select.addEventListener('change', sync);
        sync();
    });

    /* The enable switch lives inside the <summary>, so a click on it would also
       open or close the row. Swallow it there and drive the checkbox by hand -
       admin.js listens for 'change', which a programmatic .checked flip does not
       fire, so the event is dispatched explicitly. */
    document.querySelectorAll('[data-fb-stop]').forEach(function (label) {
        label.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var box = label.querySelector('input[type="checkbox"]');
            if (!box) return;
            box.checked = !box.checked;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
})();
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
