<?php
/**
 * ShopInnKart Admin - Banner form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $banner   field values (stored row, or defaults overlaid with the submit)
 *   $errors   field => message
 *   $isEdit   bool
 */

declare(strict_types=1);

/** @var array $banner @var array $errors @var bool $isEdit */
$isEdit   = $isEdit ?? false;
$errors   = $errors ?? [];
$bannerId = (int) ($banner['id'] ?? 0);

$background = (string) ($banner['bg_color'] ?? '');
$foreground = (string) ($banner['text_color'] ?? '');

$state = marketing_state(
    marketing_dt_save((string) ($banner['start_date'] ?? '')),
    marketing_dt_save((string) ($banner['end_date'] ?? '')),
    (string) ($banner['status'] ?? 'active')
);
?>
<style>
    /* Scoped to this form: a rough stand-in for the storefront block so the
       colours and copy can be judged without publishing first. */
    .ad-bannerprev {
        border-radius: 12px;
        padding: 20px;
        min-height: 150px;
        display: grid;
        gap: 8px;
        align-content: center;
        background: #0F2143;
        color: #fff;
        overflow: hidden;
    }
    .ad-bannerprev__badge {
        justify-self: start;
        font-size: 10.5px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 4px 9px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .18);
    }
    .ad-bannerprev__badge:empty, .ad-bannerprev__sub:empty, .ad-bannerprev__cta:empty { display: none; }
    .ad-bannerprev__title { font-size: 21px; font-weight: 800; line-height: 1.2; }
    .ad-bannerprev__title em { font-style: normal; color: var(--ad-primary); }
    .ad-bannerprev__sub { font-size: 13px; opacity: .85; }
    .ad-bannerprev__cta {
        justify-self: start;
        font-size: 12.5px;
        font-weight: 700;
        padding: 8px 14px;
        border-radius: 8px;
        background: var(--ad-primary);
        color: #fff;
    }
</style>

<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Content</div>
                    <div class="ad-card__sub">Every field is optional, but a banner needs a title or an image.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="bannerPosition">Position <span class="req">*</span></label>
                            <select class="sik-select" id="bannerPosition" name="position">
                                <?= admin_options(banner_positions(), $banner['position'] ?? 'hero') ?>
                            </select>
                            <?php if (isset($errors['position'])): ?>
                                <span class="sik-error"><?= e($errors['position']) ?></span>
                            <?php else: ?>
                                <span class="sik-help" id="bannerPositionNote"></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="bannerBadge">Badge</label>
                            <input class="sik-input<?= isset($errors['badge']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerBadge" name="badge" maxlength="100" data-preview-source="badge"
                                   value="<?= e($banner['badge'] ?? '') ?>" placeholder="Limited time">
                            <?php if (isset($errors['badge'])): ?>
                                <span class="sik-error"><?= e($errors['badge']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="bannerTitle">Title</label>
                            <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerTitle" name="title" maxlength="200" data-preview-source="title"
                                   value="<?= e($banner['title'] ?? '') ?>" placeholder="Big savings on">
                            <?php if (isset($errors['title'])): ?>
                                <span class="sik-error"><?= e($errors['title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="bannerAccent">Accent line</label>
                            <input class="sik-input<?= isset($errors['title_accent']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerAccent" name="title_accent" maxlength="200" data-preview-source="title_accent"
                                   value="<?= e($banner['title_accent'] ?? '') ?>" placeholder="Gaming Laptops">
                            <?php if (isset($errors['title_accent'])): ?>
                                <span class="sik-error"><?= e($errors['title_accent']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Rendered in the accent colour after the title.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerSubtitle">Subtitle</label>
                        <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                               id="bannerSubtitle" name="subtitle" maxlength="255" data-preview-source="subtitle"
                               value="<?= e($banner['subtitle'] ?? '') ?>" placeholder="Up to 40% off this week">
                        <?php if (isset($errors['subtitle'])): ?>
                            <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerDescription">Description</label>
                        <textarea class="sik-textarea" id="bannerDescription" name="description" rows="3"
                                  placeholder="One short paragraph under the heading."><?= e($banner['description'] ?? '') ?></textarea>
                        <span class="sik-help">Plain text — the storefront escapes it.</span>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Artwork</div>
                    <div class="ad-card__sub">JPG, PNG, WEBP, GIF or SVG up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?></div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label">Desktop image</label>
                            <div class="ad-drop" data-drop="#bannerDesktopPreview">
                                <input type="file" name="desktop_image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop a wide image here</div>
                            </div>
                            <div class="ad-preview" id="bannerDesktopPreview">
                                <?php if (!empty($banner['desktop_image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($banner['desktop_image'])) ?>" alt="Current desktop artwork">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#bannerRemoveDesktop"
                                                aria-label="Remove desktop image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_desktop_image" id="bannerRemoveDesktop" value="0">
                        </div>

                        <div class="ad-field">
                            <label class="sik-label">Mobile image</label>
                            <div class="ad-drop" data-drop="#bannerMobilePreview">
                                <input type="file" name="mobile_image" accept="image/*">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Optional taller crop for phones</div>
                            </div>
                            <div class="ad-preview" id="bannerMobilePreview">
                                <?php if (!empty($banner['mobile_image'])): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e(img_url($banner['mobile_image'])) ?>" alt="Current mobile artwork">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#bannerRemoveMobile"
                                                aria-label="Remove mobile image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_mobile_image" id="bannerRemoveMobile" value="0">
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Buttons</div>
                    <div class="ad-card__sub">Links are relative to the store root, e.g. <code>shop.php?sort=discount</code>.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="bannerButtonText">Primary button</label>
                            <input class="sik-input<?= isset($errors['button_text']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerButtonText" name="button_text" maxlength="60" data-preview-source="button_text"
                                   value="<?= e($banner['button_text'] ?? '') ?>" placeholder="Shop Now">
                            <?php if (isset($errors['button_text'])): ?>
                                <span class="sik-error"><?= e($errors['button_text']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="bannerButtonUrl">Primary link</label>
                            <input class="sik-input<?= isset($errors['button_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerButtonUrl" name="button_url" maxlength="255"
                                   value="<?= e($banner['button_url'] ?? '') ?>" placeholder="shop.php">
                            <?php if (isset($errors['button_url'])): ?>
                                <span class="sik-error"><?= e($errors['button_url']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="bannerButton2Text">Second button</label>
                            <input class="sik-input<?= isset($errors['button2_text']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerButton2Text" name="button2_text" maxlength="60"
                                   value="<?= e($banner['button2_text'] ?? '') ?>" placeholder="View Offers">
                            <?php if (isset($errors['button2_text'])): ?>
                                <span class="sik-error"><?= e($errors['button2_text']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="bannerButton2Url">Second link</label>
                            <input class="sik-input<?= isset($errors['button2_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerButton2Url" name="button2_url" maxlength="255"
                                   value="<?= e($banner['button2_url'] ?? '') ?>" placeholder="page/offers">
                            <?php if (isset($errors['button2_url'])): ?>
                                <span class="sik-error"><?= e($errors['button2_url']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Preview</div></div>
                <div class="ad-card__body">
                    <div class="ad-bannerprev" id="bannerPreview">
                        <span class="ad-bannerprev__badge" data-preview="badge"><?= e($banner['badge'] ?? '') ?></span>
                        <div class="ad-bannerprev__title">
                            <span data-preview="title"><?= e($banner['title'] ?? '') ?></span>
                            <em data-preview="title_accent"><?= e($banner['title_accent'] ?? '') ?></em>
                        </div>
                        <div class="ad-bannerprev__sub" data-preview="subtitle"><?= e($banner['subtitle'] ?? '') ?></div>
                        <span class="ad-bannerprev__cta" data-preview="button_text"><?= e($banner['button_text'] ?? '') ?></span>
                    </div>
                    <span class="sik-help" style="display:block;margin-top:8px">
                        Colours and copy only — the storefront lays each position out differently.
                    </span>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Colours</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="bannerBg">Background</label>
                        <div class="ad-colorfield">
                            <input type="color" value="<?= e($background !== '' ? $background : '#0F2143') ?>"
                                   data-color-for="#bannerBg" aria-label="Pick a background colour">
                            <input class="sik-input<?= isset($errors['bg_color']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerBg" name="bg_color" maxlength="20"
                                   value="<?= e($background) ?>" placeholder="#0F2143">
                        </div>
                        <?php if (isset($errors['bg_color'])): ?>
                            <span class="sik-error"><?= e($errors['bg_color']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Blank keeps the theme default.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerText">Text</label>
                        <div class="ad-colorfield">
                            <input type="color" value="<?= e($foreground !== '' ? $foreground : '#FFFFFF') ?>"
                                   data-color-for="#bannerText" aria-label="Pick a text colour">
                            <input class="sik-input<?= isset($errors['text_color']) ? ' is-invalid' : '' ?>" type="text"
                                   id="bannerText" name="text_color" maxlength="20"
                                   value="<?= e($foreground) ?>" placeholder="#FFFFFF">
                        </div>
                        <?php if (isset($errors['text_color'])): ?>
                            <span class="sik-error"><?= e($errors['text_color']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Visibility</div>
                    <div><?= marketing_state_badge($state) ?></div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="bannerStatus">Status</label>
                        <select class="sik-select" id="bannerStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $banner['status'] ?? 'active'
                            ) ?>
                        </select>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerSort">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="bannerSort" name="sort_order" min="0" max="9999" step="1"
                               value="<?= (int) ($banner['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers come first within the position.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerStart">Starts</label>
                        <input class="sik-input<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="bannerStart" name="start_date"
                               value="<?= e($banner['start_date'] ?? '') ?>">
                        <?php if (isset($errors['start_date'])): ?>
                            <span class="sik-error"><?= e($errors['start_date']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="bannerEnd">Ends</label>
                        <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="bannerEnd" name="end_date"
                               value="<?= e($banner['end_date'] ?? '') ?>">
                        <?php if (isset($errors['end_date'])): ?>
                            <span class="sik-error"><?= e($errors['end_date']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Leave both blank to show it until you turn it off.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('banners/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Banner' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var preview = document.getElementById('bannerPreview');
        var notes = <?= e_json(banner_position_notes()) ?>;

        // Copy text fields into the preview as they are typed.
        document.querySelectorAll('[data-preview-source]').forEach(function (input) {
            var target = preview ? preview.querySelector('[data-preview="' + input.dataset.previewSource + '"]') : null;
            if (!target) return;
            input.addEventListener('input', function () { target.textContent = input.value; });
        });

        // The colour swatch and the hex box drive each other.
        document.querySelectorAll('[data-color-for]').forEach(function (swatch) {
            var field = document.querySelector(swatch.dataset.colorFor);
            if (!field) return;

            swatch.addEventListener('input', function () {
                field.value = swatch.value.toUpperCase();
                field.dispatchEvent(new Event('input', { bubbles: true }));
            });
            field.addEventListener('input', function () {
                if (/^#[0-9A-Fa-f]{6}$/.test(field.value)) swatch.value = field.value;
                paint();
            });
        });

        function paint() {
            if (!preview) return;
            var background = document.getElementById('bannerBg');
            var text = document.getElementById('bannerText');
            preview.style.background = background && background.value ? background.value : '#0F2143';
            preview.style.color = text && text.value ? text.value : '#FFFFFF';
        }

        var position = document.getElementById('bannerPosition');
        var note = document.getElementById('bannerPositionNote');
        if (position && note) {
            var describe = function () { note.textContent = notes[position.value] || ''; };
            position.addEventListener('change', describe);
            describe();
        }

        paint();
    });
</script>
