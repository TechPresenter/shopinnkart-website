<?php
/**
 * ShopInnKart Admin - Attribute form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $attribute  array of field values
 *   $values     list of rows: id, value, slug, color_code, sort_order, in_use
 *   $errors     field => message
 *   $isEdit     bool
 *
 * The value rows post as parallel arrays (value_id[], value_name[], …) rather
 * than indexed names: removing a row in the middle then adding a new one can
 * reuse an index, and parallel arrays simply cannot collide.
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/** @var array $attribute @var array $values @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
$values = $values ?? [];
$type   = (string) ($attribute['type'] ?? 'select');
?>
<style>
    /* The default repeater grid is two columns; a value row needs four,
       or three when the colour column is not in play. */
    @media (min-width: 700px) {
        #attrValues .ad-repeater__row { grid-template-columns: 1.3fr 1.3fr 168px 90px auto; }
        #attrValues.is-nocolor .ad-repeater__row { grid-template-columns: 1.6fr 1.6fr 90px auto; }
    }
    #attrValues .ad-repeater__row .sik-label { font-size: 11.5px; margin-bottom: 4px; }
    #attrValues .ad-colorfield .sik-input { min-width: 0; }
</style>

<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Attribute</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="attrName">Name <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                                   id="attrName" name="name" maxlength="100" required
                                   data-slug-source="#attrSlug"
                                   value="<?= e($attribute['name'] ?? '') ?>" placeholder="e.g. Colour">
                            <?php if (isset($errors['name'])): ?>
                                <span class="sik-error"><?= e($errors['name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="attrSlug">Slug</label>
                            <input class="sik-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" type="text"
                                   id="attrSlug" name="slug" maxlength="120" data-slugify
                                   value="<?= e($attribute['slug'] ?? '') ?>" placeholder="colour">
                            <?php if (isset($errors['slug'])): ?>
                                <span class="sik-error"><?= e($errors['slug']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to build it from the name. Used in shop filter URLs.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="attrType">Type</label>
                        <select class="sik-select" id="attrType" name="type" data-attr-type>
                            <?= admin_options([
                                'select' => 'Dropdown — a list of values',
                                'color'  => 'Colour swatch — each value carries a hex code',
                                'text'   => 'Free text — a plain label',
                            ], $type) ?>
                        </select>
                        <?php if (isset($errors['type'])): ?>
                            <span class="sik-error"><?= e($errors['type']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Colour swatches render as circles on the product page and in filters.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Values</div>
                        <div class="ad-card__sub">These are the options a product variant can be built from.</div>
                    </div>
                    <button type="button" class="ad-btn ad-btn--sm" data-repeat-add="#attrValues">
                        <?= icon('plus', 'w-4 h-4') ?> Add value
                    </button>
                </div>
                <div class="ad-card__body">
                    <?php if (isset($errors['values'])): ?>
                        <div class="sik-alert sik-alert--error">
                            <?= icon('alert', 'w-5 h-5') ?>
                            <div><?= e($errors['values']) ?></div>
                        </div>
                    <?php endif; ?>

                    <div id="attrValues" class="<?= $type === 'color' ? '' : 'is-nocolor' ?>">
                        <?php foreach ($values as $index => $value): ?>
                            <?php $inUse = (int) ($value['in_use'] ?? 0); ?>
                            <div class="ad-repeater__row">
                                <input type="hidden" name="value_id[]" value="<?= (int) ($value['id'] ?? 0) ?>">

                                <label class="ad-field">
                                    <span class="sik-label">Value</span>
                                    <input class="sik-input" type="text" name="value_name[]" maxlength="150"
                                           value="<?= e($value['value'] ?? '') ?>" placeholder="e.g. Midnight Black">
                                </label>

                                <label class="ad-field">
                                    <span class="sik-label">Slug</span>
                                    <input class="sik-input" type="text" name="value_slug[]" maxlength="180"
                                           value="<?= e($value['slug'] ?? '') ?>" placeholder="auto from value">
                                </label>

                                <div class="ad-field" data-color-field <?= $type === 'color' ? '' : 'hidden' ?>>
                                    <span class="sik-label">Colour</span>
                                    <div class="ad-colorfield">
                                        <input type="color" data-color-picker aria-label="Pick a colour"
                                               value="<?= e_attr(
                                                   preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($value['color_code'] ?? '')) === 1
                                                       ? (string) $value['color_code']
                                                       : '#000000'
                                               ) ?>">
                                        <input class="sik-input" type="text" name="value_color[]" maxlength="20"
                                               value="<?= e($value['color_code'] ?? '') ?>" placeholder="#000000">
                                    </div>
                                </div>

                                <label class="ad-field">
                                    <span class="sik-label">Sort</span>
                                    <input class="sik-input" type="number" name="value_sort[]" min="0" max="9999" step="1"
                                           value="<?= (int) ($value['sort_order'] ?? $index) ?>">
                                </label>

                                <div class="ad-field">
                                    <?php if ($inUse > 0): ?>
                                        <span class="ad-muted" style="font-size:11.5px;display:block;max-width:120px"
                                              title="Used by <?= $inUse ?> product variant(s)">
                                            Used by <?= $inUse ?> variant<?= $inUse === 1 ? '' : 's' ?> — cannot be removed
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                                data-repeat-remove title="Remove value" aria-label="Remove value">
                                            <?= icon('trash', 'w-4 h-4') ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Inputs inside a <template> are inert, so the blank row never posts. -->
                        <template data-repeat-template>
                            <input type="hidden" name="value_id[]" value="0">

                            <label class="ad-field">
                                <span class="sik-label">Value</span>
                                <input class="sik-input" type="text" name="value_name[]" maxlength="150"
                                       placeholder="e.g. Midnight Black">
                            </label>

                            <label class="ad-field">
                                <span class="sik-label">Slug</span>
                                <input class="sik-input" type="text" name="value_slug[]" maxlength="180"
                                       placeholder="auto from value">
                            </label>

                            <div class="ad-field" data-color-field <?= $type === 'color' ? '' : 'hidden' ?>>
                                <span class="sik-label">Colour</span>
                                <div class="ad-colorfield">
                                    <input type="color" data-color-picker aria-label="Pick a colour" value="#000000">
                                    <input class="sik-input" type="text" name="value_color[]" maxlength="20"
                                           placeholder="#000000">
                                </div>
                            </div>

                            <label class="ad-field">
                                <span class="sik-label">Sort</span>
                                <input class="sik-input" type="number" name="value_sort[]" min="0" max="9999" step="1" value="0">
                            </label>

                            <div class="ad-field">
                                <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                        data-repeat-remove title="Remove value" aria-label="Remove value">
                                    <?= icon('trash', 'w-4 h-4') ?>
                                </button>
                            </div>
                        </template>
                    </div>

                    <?php if ($values === []): ?>
                        <p class="ad-muted" style="margin-top:4px">
                            No values yet. Add at least one so products can use this attribute.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Behaviour</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="attrStatus">Status</label>
                        <select class="sik-select" id="attrStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $attribute['status'] ?? 'active'
                            ) ?>
                        </select>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="attrSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="attrSortOrder" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($attribute['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Controls the order of filter blocks in the shop sidebar.</span>
                        <?php endif; ?>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="is_variant" value="1"
                               <?= (int) ($attribute['is_variant'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Can build product variants</span>
                    </label>

                    <label class="ad-switch">
                        <input type="checkbox" name="is_filter" value="1"
                               <?= (int) ($attribute['is_filter'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Show in shop sidebar filters</span>
                    </label>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('attributes/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Attribute' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.getElementById('attrValues');
        var typeSelect = document.querySelector('[data-attr-type]');
        if (!wrap || !typeSelect) return;

        // The colour column is only meaningful for a swatch attribute.
        function syncColourColumns() {
            var show = typeSelect.value === 'color';
            wrap.classList.toggle('is-nocolor', !show);
            wrap.querySelectorAll('[data-color-field]').forEach(function (field) {
                field.hidden = !show;
            });
            var template = wrap.querySelector('[data-repeat-template]');
            if (template) {
                var inTemplate = template.content.querySelector('[data-color-field]');
                if (inTemplate) { inTemplate.hidden = !show; }
            }
        }

        typeSelect.addEventListener('change', syncColourColumns);
        syncColourColumns();

        // The swatch picker writes into the hex field, which is the one that posts,
        // so an empty colour stays empty instead of silently becoming black.
        SIK.on('input', '[data-color-picker]', function () {
            var text = this.parentElement.querySelector('input[name="value_color[]"]');
            if (text) { text.value = this.value; }
        });

        SIK.on('input', 'input[name="value_color[]"]', function () {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                var picker = this.parentElement.querySelector('[data-color-picker]');
                if (picker) { picker.value = this.value; }
            }
        });
    });
</script>
