<?php
/**
 * ShopInnKart - Account preferences.
 *
 * Every control on this page is generated from preference_schema(). Adding a
 * key there is all it takes for it to appear, validate and persist — nothing
 * about the field list is written twice here.
 *
 * Saving normally goes through /api/account/preferences.php without a reload.
 * The POST branch below is the same write for a browser with no JavaScript;
 * both call save_user_preferences(), which is the only thing that decides what
 * a legal value is.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();
$userId = (int) $user['id'];

if (is_post()) {
    csrf_require();

    $result = save_user_preferences(request_all(), $userId);

    if ($result['errors'] !== []) {
        flash_errors($result['errors']);
        flash('error', isset($result['errors']['_'])
            ? (string) $result['errors']['_']
            : 'Some choices could not be saved. Check the highlighted fields.');
    } else {
        flash('success', $result['saved'] > 0 ? 'Your preferences have been saved.' : 'No changes to save.');
    }

    redirect(url('preferences.php'));
}

$schema = preference_schema();
$values = user_preferences($userId);
$errors = errors_pull();

seo_set([
    'title'       => 'Preferences',
    'description' => 'Choose your language, currency, notifications and shopping defaults.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('preferences', [
    'subtitle' => 'Language, alerts and the small defaults that make browsing fit how you shop.',
]);
?>

<form class="sik-prefs" method="post" action="<?= e(url('preferences.php')) ?>"
      data-ajax-form="account/preferences.php" data-prefs-form novalidate>
    <?= csrf_field() ?>

    <?php foreach ($schema as $groupKey => $group): ?>
        <?php $groupId = 'prefGroup' . ucfirst(preg_replace('/[^a-z0-9]/i', '', (string) $groupKey)); ?>
        <section class="sik-panel sik-prefgroup" aria-labelledby="<?= e_attr($groupId) ?>">
            <div class="sik-panel__head sik-prefgroup__head">
                <span class="sik-prefgroup__icon" aria-hidden="true">
                    <?= icon((string) ($group['icon'] ?? 'settings'), 'w-5 h-5') ?>
                </span>
                <div class="sik-prefgroup__intro">
                    <h2 class="sik-panel__title" id="<?= e_attr($groupId) ?>"><?= e((string) $group['label']) ?></h2>
                    <?php if (!empty($group['hint'])): ?>
                        <p class="sik-prefgroup__hint"><?= e((string) $group['hint']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sik-panel__body sik-prefgroup__body">
                <?php foreach ($group['fields'] as $key => $field): ?>
                    <?php
                    $type    = (string) ($field['type'] ?? 'text');
                    $inputId = 'pref_' . preg_replace('/[^a-z0-9_]/i', '', (string) $key);
                    $value   = (string) ($values[$key] ?? ($field['default'] ?? ''));
                    $note    = trim((string) ($field['note'] ?? ''));
                    $error   = error_for($errors, (string) $key);
                    $describedBy = trim(($note !== '' ? $inputId . 'Note ' : '') . ($error !== '' ? $inputId . 'Err' : ''));
                    ?>

                    <?php if ($type === 'toggle'): ?>
                        <?php $isOn = in_array($value, ['1', 'true', 'yes', 'on'], true); ?>
                        <div class="sik-pref">
                            <span class="sik-pref__text">
                                <label class="sik-pref__label" for="<?= e_attr($inputId) ?>"><?= e((string) $field['label']) ?></label>
                                <?php if ($note !== ''): ?>
                                    <span class="sik-pref__note" id="<?= e_attr($inputId) ?>Note"><?= e($note) ?></span>
                                <?php endif; ?>
                                <?php if ($error !== ''): ?>
                                    <span class="sik-error" id="<?= e_attr($inputId) ?>Err"><?= e($error) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php // The hidden twin is what sends "off" — an unticked box submits nothing. ?>
                            <span class="sik-switch">
                                <input type="hidden" name="<?= e_attr((string) $key) ?>" value="0">
                                <input class="sik-switch__input" type="checkbox"
                                       id="<?= e_attr($inputId) ?>" name="<?= e_attr((string) $key) ?>" value="1"
                                       role="switch" <?= $isOn ? 'checked' : '' ?>
                                       <?= $describedBy !== '' ? 'aria-describedby="' . e_attr($describedBy) . '"' : '' ?>>
                                <span class="sik-switch__track" aria-hidden="true"><span class="sik-switch__thumb"></span></span>
                            </span>
                        </div>

                    <?php elseif ($type === 'select' && !empty($field['options'])): ?>
                        <div class="sik-pref sik-pref--stacked">
                            <label class="sik-label" for="<?= e_attr($inputId) ?>"><?= e((string) $field['label']) ?></label>
                            <select class="sik-select<?= $error !== '' ? ' is-invalid' : '' ?>"
                                    id="<?= e_attr($inputId) ?>" name="<?= e_attr((string) $key) ?>"
                                    <?= $describedBy !== '' ? 'aria-describedby="' . e_attr($describedBy) . '"' : '' ?>>
                                <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                    <option value="<?= e_attr((string) $optionValue) ?>"
                                        <?= (string) $optionValue === $value ? 'selected' : '' ?>>
                                        <?= e((string) $optionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($note !== ''): ?>
                                <span class="sik-help" id="<?= e_attr($inputId) ?>Note"><?= e($note) ?></span>
                            <?php endif; ?>
                            <?php if ($error !== ''): ?>
                                <span class="sik-error" id="<?= e_attr($inputId) ?>Err"><?= e($error) ?></span>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <?php // Any type the schema grows that this page has not met yet. ?>
                        <div class="sik-pref sik-pref--stacked">
                            <label class="sik-label" for="<?= e_attr($inputId) ?>"><?= e((string) $field['label']) ?></label>
                            <input class="sik-input<?= $error !== '' ? ' is-invalid' : '' ?>" type="text"
                                   id="<?= e_attr($inputId) ?>" name="<?= e_attr((string) $key) ?>"
                                   value="<?= e_attr($value) ?>" maxlength="255"
                                   <?= $describedBy !== '' ? 'aria-describedby="' . e_attr($describedBy) . '"' : '' ?>>
                            <?php if ($note !== ''): ?>
                                <span class="sik-help" id="<?= e_attr($inputId) ?>Note"><?= e($note) ?></span>
                            <?php endif; ?>
                            <?php if ($error !== ''): ?>
                                <span class="sik-error" id="<?= e_attr($inputId) ?>Err"><?= e($error) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="sik-prefs__save">
        <p class="sik-prefs__state" data-prefs-state role="status">Everything here is saved.</p>
        <button type="submit" class="sik-btn sik-btn--primary">
            <span class="sik-btn__label">Save preferences</span>
        </button>
    </div>
</form>

<div class="sik-alert sik-alert--info sik-prefs__foot">
    <?= icon('shield', 'w-5 h-5') ?>
    <span>
        Turning off a channel stops marketing on it. We will still email you about an order you
        have placed &mdash; a dispatch or delivery notice is part of the purchase, not marketing.
        <a href="<?= e(url('privacy-policy.php')) ?>">How we handle your data</a>.
    </span>
</div>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
