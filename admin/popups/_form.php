<?php
/**
 * ShopInnKart Admin - Popup form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $popup   array of field values (existing row, or defaults + submitted input)
 *   $errors  field => message
 *   $isEdit  bool
 *
 * The preview panel is a rough client-side rebuild of what includes/footer.php
 * renders on the storefront. It is deliberately plain JS: the whole point is
 * that an admin can see the effect of a colour or a position without saving.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** @var array $popup @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];

$mode        = (string) ($popup['display_mode'] ?? 'popup');
$typeKey     = (string) ($popup['popup_type'] ?? 'promo');
$triggerKey  = (string) ($popup['trigger_type'] ?? 'timed');
$selectedPages = popup_pages_list((string) ($popup['display_pages'] ?? 'all'));
$allPages    = $selectedPages === [];

$productId = (int) ($popup['product_id'] ?? 0);
$product   = $productId > 0
    ? Database::fetch('SELECT `id`, `name`, `sku`, `main_image` FROM `products` WHERE `id` = :id', ['id' => $productId])
    : null;

// Only coupons a visitor could actually redeem are worth offering here; a
// free-text box stays available for codes handled elsewhere.
$coupons = Database::fetchAll(
    "SELECT `code`, `type`, `value` FROM `coupons`
     WHERE `status` = 'active' AND (`end_date` IS NULL OR `end_date` >= NOW())
     ORDER BY `code`"
);
$couponOptions = [];
foreach ($coupons as $coupon) {
    $code = (string) $coupon['code'];
    $couponOptions[$code] = match ((string) $coupon['type']) {
        'percentage'    => $code . ' — ' . rtrim(rtrim(number_format((float) $coupon['value'], 2, '.', ''), '0'), '.') . '% off',
        'fixed'         => $code . ' — ' . money((float) $coupon['value']) . ' off',
        'free_shipping' => $code . ' — free shipping',
        default         => $code,
    };
}

$triggerFields = [];
foreach (array_keys(popup_trigger_options()) as $key) {
    $triggerFields[$key] = popup_trigger_field($key);
}

$imageUrl  = !empty($popup['image']) ? img_url((string) $popup['image']) : '';
$mobileUrl = !empty($popup['mobile_image']) ? img_url((string) $popup['mobile_image']) : '';

$previewConfig = [
    'triggers'   => $triggerFields,
    'frequency'  => popup_frequency_options(),
    'pages'      => popup_page_options(),
    'devices'    => popup_device_options(),
    'auth'       => popup_auth_options(),
    'types'      => popup_type_options(),
    'image'      => $imageUrl,
    'mobile'     => $mobileUrl,
    'product'    => $product !== null
        ? ['name' => (string) $product['name'], 'image' => img_url($product['main_image'])]
        : null,
    'fallback'   => img_url(null),
];
?>
<style>
/* Scoped to this screen: the preview stage has no equivalent in admin.css. */
.pop-stage { position: relative; height: 236px; border: 1px solid var(--ad-border); border-radius: 10px; background: #fff; overflow: hidden; }
.pop-stage__page { position: absolute; inset: 0; padding: 14px; display: grid; gap: 8px; align-content: start; }
.pop-stage__bar { height: 9px; border-radius: 4px; background: #E8ECF3; }
.pop-stage__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 4px; }
.pop-stage__tile { height: 44px; border-radius: 6px; background: #F1F4F9; }
.pop-stage__dim { position: absolute; inset: 0; background: rgba(16, 35, 61, .42); }
.pop-card { position: absolute; border-radius: 10px; overflow: hidden; background: #fff; color: #10233D;
            box-shadow: 0 12px 30px rgba(15, 33, 67, .26); font-size: 9px; line-height: 1.4; }
.pop-card--center       { top: 50%; left: 50%; transform: translate(-50%, -50%); }
.pop-card--top          { top: 14px; left: 50%; transform: translateX(-50%); }
.pop-card--bottom       { bottom: 14px; left: 50%; transform: translateX(-50%); }
.pop-card--top-right    { top: 14px; right: 14px; }
.pop-card--bottom-left  { bottom: 14px; left: 14px; }
.pop-card--bottom-right { bottom: 14px; right: 14px; }
.pop-card--left         { top: 50%; left: 14px; transform: translateY(-50%); }
.pop-card--right        { top: 50%; right: 14px; transform: translateY(-50%); }
.pop-card--sm { width: 46%; } .pop-card--md { width: 62%; } .pop-card--lg { width: 80%; }
.pop-card--popin { width: 66%; }
.pop-card__close { position: absolute; top: 5px; right: 6px; width: 13px; height: 13px; border-radius: 50%;
                   background: rgba(16, 35, 61, .1); display: flex; align-items: center; justify-content: center;
                   font-size: 9px; line-height: 1; }
.pop-card__img { display: block; width: 100%; height: 54px; object-fit: cover; }
.pop-card__body { padding: 11px 12px 12px; text-align: center; }
.pop-card--popin .pop-card__body { padding: 9px 10px; text-align: left; display: flex; gap: 8px; align-items: flex-start; }
.pop-card--popin .pop-card__thumb { width: 26px; height: 26px; object-fit: contain; flex: none; border-radius: 5px; }
.pop-card__title { font-size: 11px; font-weight: 800; margin-bottom: 2px; }
.pop-card__sub { opacity: .75; }
.pop-card__content { margin-top: 5px; opacity: .8; }
.pop-card__content * { font-size: inherit !important; margin: 0 0 3px; }
.pop-card__coupon { margin-top: 7px; border: 1px dashed currentColor; border-radius: 6px; padding: 6px 8px;
                    font-weight: 800; letter-spacing: .08em; font-size: 11px; }
.pop-card__btn { display: inline-block; margin-top: 8px; padding: 4px 12px; border-radius: 999px;
                 background: var(--ad-primary); color: #fff; font-weight: 700; }
.pop-card__field { display: flex; margin-top: 8px; border: 1px solid rgba(16, 35, 61, .18); border-radius: 999px; overflow: hidden; background: #fff; }
.pop-card__field span:first-child { flex: 1; padding: 4px 9px; color: #8A94A6; text-align: left; }
.pop-card__field span:last-child { padding: 4px 11px; background: var(--ad-primary); color: #fff; font-weight: 700; }
.pop-card__video { margin-top: 7px; height: 44px; border-radius: 6px; background: #10233D; color: #fff;
                   display: flex; align-items: center; justify-content: center; gap: 5px; }
.pop-card__product { margin-top: 7px; display: flex; gap: 7px; align-items: center; text-align: left;
                     border: 1px solid rgba(16, 35, 61, .12); border-radius: 7px; padding: 5px 6px; }
.pop-card__product img { width: 24px; height: 24px; object-fit: contain; flex: none; }
.pop-picker { position: relative; }
.pop-picker__results { display: grid; gap: 2px; margin-top: 6px; max-height: 220px; overflow-y: auto; }
.pop-picker__results:empty { display: none; }
.pop-chosen { display: flex; align-items: center; gap: 10px; padding: 8px; margin-top: 8px;
              border: 1px solid var(--ad-border); border-radius: 9px; background: var(--ad-bg); }
/* [hidden] loses to the display rules above, so it is restated here. */
.pop-chosen[hidden], .ad-field[hidden] { display: none; }
@media (min-width: 1024px) { .pop-sticky { position: sticky; top: 78px; } }
</style>

<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved id="popupForm">
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <!-- ============================ Basics ============================ -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Basics</div></div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="popupName">Internal name <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                               id="popupName" name="name" maxlength="150" required
                               value="<?= e($popup['name'] ?? '') ?>" placeholder="e.g. Welcome newsletter coupon">
                        <?php if (isset($errors['name'])): ?>
                            <span class="sik-error"><?= e($errors['name']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Only ever shown in the admin. Visitors see the title below.</span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupMode">Display mode</label>
                            <select class="sik-select" id="popupMode" name="display_mode" data-preview>
                                <?= admin_options(
                                    ['popup' => 'Popup — centred modal over the page', 'popin' => 'Pop-in — small corner card'],
                                    $mode
                                ) ?>
                            </select>
                            <span class="sik-help">A pop-in never dims the page, so it interrupts far less.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupType">Type</label>
                            <select class="sik-select<?= isset($errors['popup_type']) ? ' is-invalid' : '' ?>"
                                    id="popupType" name="popup_type" data-preview>
                                <?= admin_options(popup_type_options(), $typeKey) ?>
                            </select>
                            <?php if (isset($errors['popup_type'])): ?>
                                <span class="sik-error"><?= e($errors['popup_type']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Decides which block the storefront renders inside the frame.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Content =========================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Content</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupTitle">Title</label>
                            <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                                   id="popupTitle" name="title" maxlength="200" data-preview
                                   value="<?= e($popup['title'] ?? '') ?>" placeholder="Get 10% off your first order">
                            <?php if (isset($errors['title'])): ?>
                                <span class="sik-error"><?= e($errors['title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupSubtitle">Subtitle</label>
                            <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                                   id="popupSubtitle" name="subtitle" maxlength="255" data-preview
                                   value="<?= e($popup['subtitle'] ?? '') ?>" placeholder="Join the list and we will send the code">
                            <?php if (isset($errors['subtitle'])): ?>
                                <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="popupContent">Body</label>
                        <textarea class="sik-textarea" id="popupContent" name="content" rows="5" data-preview
                                  placeholder="Optional supporting copy. Basic HTML is allowed."><?= e($popup['content'] ?? '') ?></textarea>
                        <span class="sik-help">Scripts, event handlers and javascript: links are stripped on save.</span>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupButtonText">Button text</label>
                            <input class="sik-input<?= isset($errors['button_text']) ? ' is-invalid' : '' ?>" type="text"
                                   id="popupButtonText" name="button_text" maxlength="60" data-preview
                                   value="<?= e($popup['button_text'] ?? '') ?>" placeholder="Shop the sale">
                            <?php if (isset($errors['button_text'])): ?>
                                <span class="sik-error"><?= e($errors['button_text']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Leave blank to show no button.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupButtonUrl">Button link</label>
                            <input class="sik-input<?= isset($errors['button_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="popupButtonUrl" name="button_url" maxlength="255"
                                   value="<?= e($popup['button_url'] ?? '') ?>" placeholder="shop.php?deal=1">
                            <?php if (isset($errors['button_url'])): ?>
                                <span class="sik-error"><?= e($errors['button_url']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">A path such as <code>shop.php</code> is resolved against the store URL.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================= Media ============================ -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Media</div>
                    <div class="ad-card__sub">Up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?> per image</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label">Main image</label>
                            <div class="ad-drop" data-drop="#popupImagePreview">
                                <input type="file" name="image" accept="image/*" id="popupImageInput">
                                <?= icon('upload', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Click or drop the image here</div>
                            </div>
                            <div class="ad-preview" id="popupImagePreview">
                                <?php if ($imageUrl !== ''): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e($imageUrl) ?>" alt="Current popup image">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#popupRemoveImage" aria-label="Remove image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_image" id="popupRemoveImage" value="0">
                        </div>

                        <div class="ad-field">
                            <label class="sik-label">Mobile image</label>
                            <div class="ad-drop" data-drop="#popupMobilePreview">
                                <input type="file" name="mobile_image" accept="image/*" id="popupMobileInput">
                                <?= icon('smartphone', 'w-6 h-6') ?>
                                <div style="font-size:13px;margin-top:6px">Optional portrait crop</div>
                            </div>
                            <div class="ad-preview" id="popupMobilePreview">
                                <?php if ($mobileUrl !== ''): ?>
                                    <div class="ad-preview__item">
                                        <img src="<?= e($mobileUrl) ?>" alt="Current mobile image">
                                        <button type="button" class="ad-preview__remove"
                                                data-remove-image="#popupRemoveMobile" aria-label="Remove mobile image">&times;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="remove_mobile_image" id="popupRemoveMobile" value="0">
                            <span class="sik-help">Falls back to the main image when empty.</span>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="popupVideoUrl">Video URL</label>
                        <input class="sik-input<?= isset($errors['video_url']) ? ' is-invalid' : '' ?>" type="url"
                               id="popupVideoUrl" name="video_url" maxlength="255" data-preview
                               value="<?= e($popup['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=…">
                        <?php if (isset($errors['video_url'])): ?>
                            <span class="sik-error"><?= e($errors['video_url']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Used by the <strong>Video</strong> type. Include the protocol.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================= Offer ============================ -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Offer</div>
                    <div class="ad-card__sub">What the popup actually hands over</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupCouponPick">Pick an active coupon</label>
                            <select class="sik-select" id="popupCouponPick">
                                <?= admin_options($couponOptions, '', '— Type a code instead —') ?>
                            </select>
                            <span class="sik-help">
                                <?= $couponOptions === []
                                    ? 'No active coupons right now — type a code below.'
                                    : count($couponOptions) . ' coupon(s) currently redeemable.' ?>
                            </span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupCouponCode">Coupon code</label>
                            <input class="sik-input<?= isset($errors['coupon_code']) ? ' is-invalid' : '' ?>" type="text"
                                   id="popupCouponCode" name="coupon_code" maxlength="60" data-preview
                                   style="text-transform:uppercase"
                                   value="<?= e($popup['coupon_code'] ?? '') ?>" placeholder="WELCOME10">
                            <?php if (isset($errors['coupon_code'])): ?>
                                <span class="sik-error"><?= e($errors['coupon_code']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Shown as a copy-to-clipboard block. Blank hides it.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field pop-picker">
                        <label class="sik-label" for="popupProductSearch">Featured product</label>
                        <input class="sik-input" type="search" id="popupProductSearch"
                               placeholder="Search the catalogue by name or SKU&hellip;" autocomplete="off">
                        <div class="pop-picker__results" id="popupProductResults"></div>

                        <input type="hidden" name="product_id" id="popupProductId" value="<?= $productId ?: '' ?>">

                        <div class="pop-chosen" id="popupProductChosen" <?= $product === null ? 'hidden' : '' ?>>
                            <img class="ad-thumb" id="popupProductThumb" alt=""
                                 src="<?= e($product !== null ? img_url($product['main_image']) : img_url(null)) ?>">
                            <span style="flex:1;min-width:0">
                                <span class="ad-cellflex__name" style="display:block" id="popupProductName">
                                    <?= e($product['name'] ?? '') ?>
                                </span>
                                <span class="ad-cellflex__meta" id="popupProductMeta">
                                    <?= e($product !== null ? 'SKU ' . (string) $product['sku'] : '') ?>
                                </span>
                            </span>
                            <button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost"
                                    id="popupProductClear" title="Remove product" aria-label="Remove featured product">
                                <?= icon('close', 'w-4 h-4') ?>
                            </button>
                        </div>

                        <?php if (isset($errors['product_id'])): ?>
                            <span class="sik-error"><?= e($errors['product_id']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Used by the <strong>Product spotlight</strong> type.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- =========================== Appearance ========================= -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Appearance</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupPosition">Position</label>
                            <select class="sik-select" id="popupPosition" name="position" data-preview>
                                <?= admin_options(popup_position_options(), $popup['position'] ?? 'center') ?>
                            </select>
                            <span class="sik-help">A pop-in set to Center falls back to the bottom-left corner.</span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupSize">Size</label>
                            <select class="sik-select" id="popupSize" name="size" data-preview>
                                <?= admin_options(popup_size_options(), $popup['size'] ?? 'md') ?>
                            </select>
                            <span class="sik-help">Applies to popups; pop-ins are always compact.</span>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupBgColor">Background colour</label>
                            <div class="ad-colorfield">
                                <input type="color" id="popupBgSwatch" value="<?= e(($popup['bg_color'] ?? '') ?: '#FFFFFF') ?>"
                                       aria-label="Pick a background colour">
                                <input class="sik-input<?= isset($errors['bg_color']) ? ' is-invalid' : '' ?>" type="text"
                                       id="popupBgColor" name="bg_color" maxlength="20" data-preview
                                       value="<?= e($popup['bg_color'] ?? '') ?>" placeholder="#FFFFFF">
                            </div>
                            <?php if (isset($errors['bg_color'])): ?>
                                <span class="sik-error"><?= e($errors['bg_color']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Blank keeps the storefront default.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupTextColor">Text colour</label>
                            <div class="ad-colorfield">
                                <input type="color" id="popupTextSwatch" value="<?= e(($popup['text_color'] ?? '') ?: '#10233D') ?>"
                                       aria-label="Pick a text colour">
                                <input class="sik-input<?= isset($errors['text_color']) ? ' is-invalid' : '' ?>" type="text"
                                       id="popupTextColor" name="text_color" maxlength="20" data-preview
                                       value="<?= e($popup['text_color'] ?? '') ?>" placeholder="#10233D">
                            </div>
                            <?php if (isset($errors['text_color'])): ?>
                                <span class="sik-error"><?= e($errors['text_color']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Blank keeps the storefront default.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <label class="ad-switch">
                        <input type="checkbox" name="show_close" value="1" data-preview
                               <?= (int) ($popup['show_close'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="ad-switch__track"></span>
                        <span>Show a close button</span>
                    </label>
                    <span class="sik-help" style="display:block;margin-top:6px">
                        Without one the visitor can only dismiss a popup with Escape or the backdrop.
                    </span>
                </div>
            </div>

            <!-- =========================== Behaviour ========================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Behaviour</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="popupTrigger">Trigger</label>
                            <select class="sik-select" id="popupTrigger" name="trigger_type" data-preview>
                                <?= admin_options(popup_trigger_options(), $triggerKey) ?>
                            </select>
                        </div>

                        <div class="ad-field" id="popupTriggerValueField">
                            <label class="sik-label" for="popupTriggerValue" id="popupTriggerValueLabel">
                                <?= e(popup_trigger_field($triggerKey)['label']) ?>
                            </label>
                            <input class="sik-input<?= isset($errors['trigger_value']) ? ' is-invalid' : '' ?>" type="number"
                                   id="popupTriggerValue" name="trigger_value" step="1" data-preview
                                   min="<?= (int) popup_trigger_field($triggerKey)['min'] ?>"
                                   max="<?= (int) popup_trigger_field($triggerKey)['max'] ?>"
                                   value="<?= (int) ($popup['trigger_value'] ?? 5) ?>">
                            <?php if (isset($errors['trigger_value'])): ?>
                                <span class="sik-error"><?= e($errors['trigger_value']) ?></span>
                            <?php else: ?>
                                <span class="sik-help" id="popupTriggerValueHelp"><?= e(popup_trigger_field($triggerKey)['help']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupFrequency">Frequency</label>
                            <select class="sik-select" id="popupFrequency" name="frequency" data-preview>
                                <?= admin_options(popup_frequency_options(), $popup['frequency'] ?? 'session') ?>
                            </select>
                            <span class="sik-help">How often one visitor may see it.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================== Targeting ========================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Targeting</div></div>
                <div class="ad-card__body">
                    <fieldset class="ad-fieldset">
                        <legend>Pages</legend>

                        <label class="sik-check" style="margin-bottom:8px">
                            <input type="checkbox" name="pages_all" value="1" id="popupPagesAll" data-preview
                                   <?= $allPages ? 'checked' : '' ?>>
                            <span><strong>All pages</strong></span>
                        </label>

                        <?php // Forty-one boxes in one flat grid is a wall, so they are drawn
                              // in the five groups popup_page_groups() defines. The wrapper id
                              // stays put: syncPages() below still reaches every box through it
                              // with one querySelectorAll. ?>
                        <div id="popupPageList" style="display:grid;gap:14px">
                            <?php foreach (popup_page_groups() as $groupLabel => $groupPages): ?>
                                <div>
                                    <span class="ad-muted" style="display:block;font-size:11.5px;font-weight:600;
                                          letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px">
                                        <?= e($groupLabel) ?>
                                    </span>
                                    <div style="display:grid;gap:7px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
                                        <?php foreach ($groupPages as $key => $label): ?>
                                            <?php // (string) is load-bearing, not tidiness. '404' is a real route
                                                  // key and PHP stores a decimal-string array key as an integer, so
                                                  // $key arrives here as int 404 while $selectedPages holds the
                                                  // strings popup_pages_list() split out of the CSV. A strict
                                                  // in_array() therefore drew the 404 box unchecked on a popup that
                                                  // really did target it, and the next save - which posts only the
                                                  // checked boxes - dropped the target silently. See the note on
                                                  // loose comparison above popup_page_options() in _shared.php. ?>
                                            <label class="sik-check">
                                                <input type="checkbox" name="display_pages[]" value="<?= e_attr((string) $key) ?>" data-preview
                                                       <?= in_array((string) $key, $selectedPages, true) ? 'checked' : '' ?>
                                                       <?= $allPages ? 'disabled' : '' ?>>
                                                <span><?= e($label) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (isset($errors['display_pages'])): ?>
                            <span class="sik-error"><?= e($errors['display_pages']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">
                                Route keys match the storefront script name — Home is index.php, and a blog
                                article is blog-post.php, which is why "Blog article" is separate from "Blog index".
                            </span>
                        <?php endif; ?>
                    </fieldset>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupDevice">Devices</label>
                            <select class="sik-select" id="popupDevice" name="device_visibility" data-preview>
                                <?= admin_options(popup_device_options(), $popup['device_visibility'] ?? 'all') ?>
                            </select>
                            <span class="sik-help">
                                Decided from the User-Agent, which sorts a visitor into exactly one of
                                desktop, tablet or mobile — an iPad is a tablet, so "Desktop only" and
                                "Mobile only" both exclude it.
                            </span>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupAuth">Audience</label>
                            <select class="sik-select" id="popupAuth" name="auth_visibility" data-preview>
                                <?= admin_options(popup_auth_options(), $popup['auth_visibility'] ?? 'all') ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Schedule ========================== -->
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Schedule</div>
                    <div class="ad-card__sub">Leave both blank to run until you disable it</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="popupStart">Starts</label>
                            <input class="sik-input<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                                   type="datetime-local" id="popupStart" name="start_date"
                                   value="<?= e(popup_datetime_input($popup['start_date'] ?? null)) ?>">
                            <?php if (isset($errors['start_date'])): ?>
                                <span class="sik-error"><?= e($errors['start_date']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="popupEnd">Ends</label>
                            <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                                   type="datetime-local" id="popupEnd" name="end_date"
                                   value="<?= e(popup_datetime_input($popup['end_date'] ?? null)) ?>">
                            <?php if (isset($errors['end_date'])): ?>
                                <span class="sik-error"><?= e($errors['end_date']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================== Sidebar ============================= -->
        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Publish</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="popupStatus">Status</label>
                        <select class="sik-select" id="popupStatus" name="status">
                            <?= admin_options(['active' => 'Enabled', 'inactive' => 'Disabled'], $popup['status'] ?? 'active') ?>
                        </select>
                        <span class="sik-help">Disabled popups never reach the storefront.</span>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="popupSortOrder">Sort order</label>
                        <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>" type="number"
                               id="popupSortOrder" name="sort_order" min="-9999" max="9999" step="1"
                               value="<?= (int) ($popup['sort_order'] ?? 0) ?>">
                        <?php if (isset($errors['sort_order'])): ?>
                            <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Lower numbers are evaluated first when several match a page.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('popups/?mode=' . urlencode($mode))) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Popup' ?>
                    </button>
                </div>
            </div>

            <div class="ad-card pop-sticky" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Live preview</div>
                        <div class="ad-card__sub">Approximate — updates as you type</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="pop-stage" id="popPreviewStage">
                        <div class="pop-stage__page" aria-hidden="true">
                            <div class="pop-stage__bar" style="width:52%"></div>
                            <div class="pop-stage__bar" style="width:88%;height:6px"></div>
                            <div class="pop-stage__grid">
                                <div class="pop-stage__tile"></div>
                                <div class="pop-stage__tile"></div>
                                <div class="pop-stage__tile"></div>
                            </div>
                            <div class="pop-stage__bar" style="width:70%;height:6px"></div>
                        </div>
                        <div class="pop-stage__dim" id="popPreviewDim" hidden></div>
                        <div class="pop-card" id="popPreviewCard"></div>
                    </div>

                    <p class="sik-help" id="popPreviewSummary" style="margin-top:12px"></p>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
window.SIK_POPUP_PREVIEW = <?= e_json($previewConfig) ?>;
</script>
<script>
(function () {
    'use strict';

    // Deferred scripts (app.js, admin.js) have already run by DOMContentLoaded,
    // so SIK is available to the product picker from here on.
    document.addEventListener('DOMContentLoaded', function () {
        var cfg  = window.SIK_POPUP_PREVIEW || {};
        var form = document.getElementById('popupForm');
        var card = document.getElementById('popPreviewCard');
        var dim  = document.getElementById('popPreviewDim');
        var note = document.getElementById('popPreviewSummary');
        if (!form || !card) { return; }

        var field = function (name) { return form.querySelector('[name="' + name + '"]'); };
        var value = function (name) { var el = field(name); return el ? el.value.trim() : ''; };
        var checked = function (name) { var el = field(name); return !!(el && el.checked); };

        /* --------------------------------------------------------------
           Trigger value: one column, three different meanings.
           -------------------------------------------------------------- */
        var triggerSelect = document.getElementById('popupTrigger');
        var triggerField  = document.getElementById('popupTriggerValueField');
        var triggerInput  = document.getElementById('popupTriggerValue');
        var triggerLabel  = document.getElementById('popupTriggerValueLabel');
        var triggerHelp   = document.getElementById('popupTriggerValueHelp');

        function syncTrigger() {
            var spec = (cfg.triggers || {})[triggerSelect.value];
            if (!spec) { return; }

            triggerField.hidden = !spec.show;
            triggerLabel.textContent = spec.label;
            if (triggerHelp) { triggerHelp.textContent = spec.help; }
            triggerInput.min = spec.min;
            triggerInput.max = spec.max;
            // A hidden field must not block submission with a range error.
            triggerInput.disabled = !spec.show;
        }

        function triggerSummary() {
            var type = triggerSelect.value;
            var n = parseInt(triggerInput.value, 10);
            if (isNaN(n)) { n = 0; }

            if (type === 'exit') { return 'on exit intent'; }
            if (type === 'scroll') { return 'at ' + n + '% scroll'; }
            if (type === 'cart_value') { return 'when the cart reaches ' + (window.SIK ? SIK.formatCurrency(n) : n); }
            if (type === 'timed' && n > 0) { return n + 's after load'; }
            return 'immediately on load';
        }

        /* --------------------------------------------------------------
           "All pages" owns the individual page boxes.
           -------------------------------------------------------------- */
        var pagesAll = document.getElementById('popupPagesAll');
        var pageList = document.getElementById('popupPageList');

        function syncPages() {
            var boxes = pageList.querySelectorAll('input[type="checkbox"]');
            for (var i = 0; i < boxes.length; i++) { boxes[i].disabled = pagesAll.checked; }
            pageList.style.opacity = pagesAll.checked ? '.5' : '1';
        }

        function pagesSummary() {
            if (pagesAll.checked) { return 'every page'; }
            var labels = [];
            var boxes = pageList.querySelectorAll('input[type="checkbox"]:checked');
            for (var i = 0; i < boxes.length; i++) {
                labels.push((cfg.pages || {})[boxes[i].value] || boxes[i].value);
            }
            return labels.length ? labels.join(', ') : 'no page yet';
        }

        /* --------------------------------------------------------------
           Colour swatches drive the text inputs, which stay authoritative
           because they can also be emptied.
           -------------------------------------------------------------- */
        function bindSwatch(swatchId, inputId) {
            var swatch = document.getElementById(swatchId);
            var input  = document.getElementById(inputId);
            if (!swatch || !input) { return; }

            swatch.addEventListener('input', function () {
                input.value = swatch.value.toUpperCase();
                render();
            });
            input.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(input.value.trim())) { swatch.value = input.value.trim(); }
                render();
            });
        }

        /* --------------------------------------------------------------
           Images: a freshly chosen file wins over the saved one.
           -------------------------------------------------------------- */
        var pickedImage = '';

        function bindImage(inputId, removeId) {
            var input = document.getElementById(inputId);
            var flag  = document.getElementById(removeId);
            if (input) {
                input.addEventListener('change', function () {
                    if (input.files && input.files[0]) {
                        pickedImage = URL.createObjectURL(input.files[0]);
                        if (flag) { flag.value = '0'; }
                    }
                    render();
                });
            }
            if (flag) {
                // The shared [data-remove-image] handler sets the flag; watch the
                // click so the preview drops the image at the same moment.
                var button = document.querySelector('[data-remove-image="#' + removeId + '"]');
                if (button) { button.addEventListener('click', function () { setTimeout(render, 0); }); }
            }
        }

        function currentImage() {
            if (pickedImage) { return pickedImage; }
            var flag = document.getElementById('popupRemoveImage');
            if (flag && flag.value === '1') { return ''; }
            return cfg.image || '';
        }

        /* --------------------------------------------------------------
           Body copy is admin HTML, so it is scrubbed before it reaches
           the preview the same way sanitize_html() scrubs it on save.
           -------------------------------------------------------------- */
        function safeHtml(raw) {
            var doc = new DOMParser().parseFromString('<div>' + raw + '</div>', 'text/html');
            var root = doc.body.firstElementChild;
            if (!root) { return ''; }

            var banned = root.querySelectorAll('script,style,iframe,object,embed,link,meta,form');
            for (var i = 0; i < banned.length; i++) { banned[i].remove(); }

            var all = root.querySelectorAll('*');
            for (var j = 0; j < all.length; j++) {
                var attrs = Array.prototype.slice.call(all[j].attributes);
                for (var k = 0; k < attrs.length; k++) {
                    var name = attrs[k].name.toLowerCase();
                    var val = attrs[k].value.replace(/\s+/g, '').toLowerCase();
                    if (name.indexOf('on') === 0 || (val.indexOf('javascript:') === 0)) {
                        all[j].removeAttribute(attrs[k].name);
                    }
                }
            }
            return root.innerHTML;
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /* --------------------------------------------------------------
           Render
           -------------------------------------------------------------- */
        function render() {
            var mode = value('display_mode');
            var type = value('popup_type');
            var isPopin = mode === 'popin';
            var position = value('position');
            var size = value('size');

            // The storefront pushes a centred pop-in into the bottom-left corner.
            if (isPopin && position === 'center') { position = 'bottom-left'; }

            card.className = 'pop-card pop-card--' + position + (isPopin ? ' pop-card--popin' : ' pop-card--' + size);
            dim.hidden = isPopin;

            var bg = value('bg_color');
            var fg = value('text_color');
            card.style.background = /^#[0-9a-fA-F]{6}$/.test(bg) ? bg : '';
            card.style.color = /^#[0-9a-fA-F]{6}$/.test(fg) ? fg : '';

            var image = currentImage();
            var title = value('title');
            var subtitle = value('subtitle');
            var body = value('content');
            var coupon = value('coupon_code').toUpperCase();
            var buttonText = value('button_text');
            var videoUrl = value('video_url');
            var productId = value('product_id');

            var html = '';
            if (checked('show_close')) { html += '<span class="pop-card__close">&times;</span>'; }

            if (image && !isPopin && type !== 'video') {
                html += '<img class="pop-card__img" src="' + escapeHtml(image) + '" alt="">';
            }

            html += '<div class="pop-card__body">';

            if (image && isPopin) {
                html += '<img class="pop-card__thumb" src="' + escapeHtml(image) + '" alt="">';
            }
            html += '<div style="flex:1;min-width:0">';

            html += '<div class="pop-card__title">' + escapeHtml(title || 'Untitled ' + (isPopin ? 'pop-in' : 'popup')) + '</div>';
            if (subtitle) { html += '<div class="pop-card__sub">' + escapeHtml(subtitle) + '</div>'; }

            if (body && !isPopin) {
                html += '<div class="pop-card__content">' + safeHtml(body) + '</div>';
            }

            if (type === 'video' && videoUrl) {
                html += '<div class="pop-card__video">&#9658; ' + escapeHtml(videoUrl.replace(/^https?:\/\//, '').slice(0, 26)) + '</div>';
            }

            if (type === 'product' && productId) {
                var chosenName = document.getElementById('popupProductName').textContent.trim();
                var chosenImg = document.getElementById('popupProductThumb').getAttribute('src') || cfg.fallback;
                html += '<div class="pop-card__product">'
                    + '<img src="' + escapeHtml(chosenImg) + '" alt="">'
                    + '<span>' + escapeHtml(chosenName || 'Selected product') + '</span></div>';
            }

            if (type === 'newsletter' && !isPopin) {
                html += '<div class="pop-card__field"><span>your@email.com</span><span>Subscribe</span></div>';
            }

            if (coupon) { html += '<div class="pop-card__coupon">' + escapeHtml(coupon) + '</div>'; }
            if (buttonText) { html += '<span class="pop-card__btn">' + escapeHtml(buttonText) + '</span>'; }

            html += '</div></div>';
            card.innerHTML = html;

            if (note) {
                note.textContent = 'Shows ' + triggerSummary()
                    + ', ' + ((cfg.frequency || {})[value('frequency')] || '').toLowerCase()
                    + ', on ' + pagesSummary()
                    + ' — ' + ((cfg.devices || {})[value('device_visibility')] || 'All devices').toLowerCase()
                    + ', ' + ((cfg.auth || {})[value('auth_visibility')] || 'everyone').toLowerCase() + '.';
            }
        }

        /* --------------------------------------------------------------
           Coupon picker
           -------------------------------------------------------------- */
        var couponPick = document.getElementById('popupCouponPick');
        var couponCode = document.getElementById('popupCouponCode');
        if (couponPick && couponCode) {
            couponPick.addEventListener('change', function () {
                if (couponPick.value) { couponCode.value = couponPick.value; render(); }
            });
        }

        /* --------------------------------------------------------------
           Product picker — single select, so it does not reuse the shared
           [data-product-search] multi-picker in admin.js.
           -------------------------------------------------------------- */
        var search  = document.getElementById('popupProductSearch');
        var results = document.getElementById('popupProductResults');
        var hidden  = document.getElementById('popupProductId');
        var chosen  = document.getElementById('popupProductChosen');

        function setProduct(id, name, sku, image) {
            hidden.value = id;
            document.getElementById('popupProductName').textContent = name;
            document.getElementById('popupProductMeta').textContent = sku ? 'SKU ' + sku : '';
            document.getElementById('popupProductThumb').src = image || cfg.fallback;
            chosen.hidden = false;
            results.innerHTML = '';
            search.value = '';
            render();
        }

        if (search && window.SIK) {
            var lookup = SIK.debounce(async function () {
                var term = search.value.trim();
                if (term.length < 2) { results.innerHTML = ''; return; }

                var response = await SIK.get('products/search.php', { q: term, limit: 8 });
                if (!response.success) { return; }

                var items = (response.data && response.data.products) || [];
                if (!items.length) {
                    results.innerHTML = '<div class="ad-dropdown__item ad-muted">No products found</div>';
                    return;
                }

                results.innerHTML = items.map(function (p) {
                    return '<button type="button" class="ad-dropdown__item" data-pick="' + p.id + '"'
                        + ' data-name="' + SIK.escapeHtml(p.name) + '"'
                        + ' data-sku="' + SIK.escapeHtml(p.sku || '') + '"'
                        + ' data-image="' + SIK.escapeHtml(p.image_url) + '">'
                        + '<img src="' + SIK.escapeHtml(p.image_url) + '" alt="" width="28" height="28" style="border-radius:5px">'
                        + '<span style="flex:1;min-width:0"><span style="display:block;font-weight:600">' + SIK.escapeHtml(p.name) + '</span>'
                        + '<span style="font-size:11.5px;color:var(--ad-muted)">' + SIK.escapeHtml(p.sku || '') + ' · ' + SIK.escapeHtml(p.price_display) + '</span></span>'
                        + '</button>';
                }).join('');
            }, 300);

            search.addEventListener('input', lookup);

            results.addEventListener('click', function (event) {
                var button = event.target.closest('[data-pick]');
                if (!button) { return; }
                event.preventDefault();
                setProduct(button.dataset.pick, button.dataset.name, button.dataset.sku, button.dataset.image);
            });

            // Enter inside the picker would otherwise submit the whole form.
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); }
            });
        }

        var clear = document.getElementById('popupProductClear');
        if (clear) {
            clear.addEventListener('click', function () {
                hidden.value = '';
                chosen.hidden = true;
                render();
            });
        }

        /* --------------------------------------------------------------
           Wire up
           -------------------------------------------------------------- */
        triggerSelect.addEventListener('change', function () { syncTrigger(); render(); });
        pagesAll.addEventListener('change', function () { syncPages(); render(); });

        form.addEventListener('input', function (event) {
            if (event.target.hasAttribute('data-preview')) { render(); }
        });
        form.addEventListener('change', function (event) {
            if (event.target.hasAttribute('data-preview')) { render(); }
        });

        bindSwatch('popupBgSwatch', 'popupBgColor');
        bindSwatch('popupTextSwatch', 'popupTextColor');
        bindImage('popupImageInput', 'popupRemoveImage');
        bindImage('popupMobileInput', 'popupRemoveMobile');

        syncTrigger();
        syncPages();
        render();
    });
})();
</script>
