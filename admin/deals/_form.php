<?php
/**
 * ShopInnKart Admin - Deal form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $deal     field values (stored row, or defaults overlaid with the submit)
 *   $errors   field => message
 *   $isEdit   bool
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a
// host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** @var array $deal @var array $errors @var bool $isEdit */
$isEdit = $isEdit ?? false;
$errors = $errors ?? [];
$dealId = (int) ($deal['id'] ?? 0);

$attached = $deal['products'] ?? [];

// The headline product is a one-row picker; the deal list is a many-row one.
$headlineRows = [];
$headlineId   = (int) ($deal['product_id'] ?? 0);
if ($headlineId > 0) {
    foreach (marketing_product_rows([$headlineId]) as $productId => $product) {
        $headlineRows[] = [
            'id'    => $productId,
            'name'  => $product['name'],
            'image' => $product['main_image'],
            'meta'  => trim((string) $product['sku']) . ' · ' . money(marketing_product_price($product)),
        ];
    }
}

$productRows = [];
foreach (marketing_product_rows(array_keys($attached)) as $productId => $product) {
    $productRows[] = [
        'id'     => $productId,
        'name'   => $product['name'],
        'image'  => $product['main_image'],
        'meta'   => trim((string) $product['sku']) . ' · ' . money(marketing_product_price($product))
            . ' · ' . (int) $product['stock'] . ' in stock',
        'values' => ['deal_price' => (string) ($attached[$productId]['deal_price'] ?? '')],
    ];
}

$state = marketing_state(
    marketing_dt_save((string) ($deal['start_time'] ?? '')),
    marketing_dt_save((string) ($deal['end_time'] ?? '')),
    (string) ($deal['status'] ?? 'active')
);
?>

<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Headline</div></div>
                <div class="ad-card__body">
                    <div class="ad-field">
                        <label class="sik-label" for="dealTitle">Title <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" type="text"
                               id="dealTitle" name="title" maxlength="200" required
                               value="<?= e($deal['title'] ?? '') ?>" placeholder="Deal of the Day">
                        <?php if (isset($errors['title'])): ?>
                            <span class="sik-error"><?= e($errors['title']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="dealSubtitle">Subtitle</label>
                        <input class="sik-input<?= isset($errors['subtitle']) ? ' is-invalid' : '' ?>" type="text"
                               id="dealSubtitle" name="subtitle" maxlength="255"
                               value="<?= e($deal['subtitle'] ?? '') ?>"
                               placeholder="Ends at midnight — while stocks last">
                        <?php if (isset($errors['subtitle'])): ?>
                            <span class="sik-error"><?= e($errors['subtitle']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="dealDescription">Description</label>
                        <textarea class="sik-textarea" id="dealDescription" name="description" rows="4"
                                  placeholder="Shown under the title on the deal block."><?= e($deal['description'] ?? '') ?></textarea>
                        <span class="sik-help">Basic HTML is allowed; scripts and event handlers are stripped on save.</span>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="dealButtonText">Button text</label>
                            <input class="sik-input<?= isset($errors['button_text']) ? ' is-invalid' : '' ?>" type="text"
                                   id="dealButtonText" name="button_text" maxlength="60"
                                   value="<?= e($deal['button_text'] ?? 'Shop The Deal') ?>" placeholder="Shop The Deal">
                            <?php if (isset($errors['button_text'])): ?>
                                <span class="sik-error"><?= e($errors['button_text']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="dealButtonUrl">Button link</label>
                            <input class="sik-input<?= isset($errors['button_url']) ? ' is-invalid' : '' ?>" type="text"
                                   id="dealButtonUrl" name="button_url" maxlength="255"
                                   value="<?= e($deal['button_url'] ?? '') ?>" placeholder="shop.php?deal=1">
                            <?php if (isset($errors['button_url'])): ?>
                                <span class="sik-error"><?= e($errors['button_url']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">
                                    The homepage deal block adds the headline product to the cart; this link is for
                                    custom deal landing pages. Relative to the store root.
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Headline product</div>
                    <div class="ad-card__sub">The single product the homepage deal block features.</div>
                </div>
                <div class="ad-card__body">
                    <?= marketing_product_picker([
                        'name'   => 'product_id',
                        'single' => true,
                        'rows'   => $headlineRows,
                        'empty'  => 'No headline product — the homepage deal block stays hidden.',
                        'help'   => 'Picking a new product replaces the current one.',
                    ]) ?>
                    <?php if (isset($errors['product_id'])): ?>
                        <span class="sik-error"><?= e($errors['product_id']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Discount</div>
                    <div class="ad-card__sub">Applies to every product in this deal unless a row sets its own price.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <div class="ad-field">
                            <label class="sik-label" for="dealDiscountType">Type</label>
                            <select class="sik-select" id="dealDiscountType" name="discount_type">
                                <?= admin_options(deal_discount_types(), $deal['discount_type'] ?? 'percentage') ?>
                            </select>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="dealDiscountValue">Value <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['discount_value']) ? ' is-invalid' : '' ?>" type="number"
                                   id="dealDiscountValue" name="discount_value" step="0.01" min="0" required
                                   value="<?= e($deal['discount_value'] ?? '') ?>" placeholder="25">
                            <?php if (isset($errors['discount_value'])): ?>
                                <span class="sik-error"><?= e($errors['discount_value']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="dealStockLimit">Stock limit</label>
                            <input class="sik-input<?= isset($errors['stock_limit']) ? ' is-invalid' : '' ?>" type="number"
                                   id="dealStockLimit" name="stock_limit" step="1" min="1"
                                   value="<?= e($deal['stock_limit'] ?? '') ?>" placeholder="Unlimited">
                            <?php if (isset($errors['stock_limit'])): ?>
                                <span class="sik-error"><?= e($errors['stock_limit']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Units across the whole deal. It stops pricing once reached.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Products in this deal</div>
                    <div class="ad-card__sub">Leave a deal price blank to use the discount above.</div>
                </div>
                <div class="ad-card__body">
                    <?= marketing_product_picker([
                        'name'   => 'product_ids',
                        'rows'   => $productRows,
                        'fields' => [
                            ['name' => 'deal_price', 'label' => 'Deal price', 'placeholder' => 'Auto', 'step' => '0.01', 'width' => '120px'],
                        ],
                        'empty'  => 'No products attached — only the headline product will be discounted.',
                        'help'   => 'These products get the deal price everywhere on the storefront while the deal runs.',
                    ]) ?>
                    <?php if (isset($errors['product_ids'])): ?>
                        <span class="sik-error"><?= e($errors['product_ids']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($errors['deal_price'])): ?>
                        <span class="sik-error"><?= e($errors['deal_price']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Schedule</div>
                    <div><?= marketing_state_badge($state) ?></div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="dealStart">Starts <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['start_time']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="dealStart" name="start_time" required
                               value="<?= e($deal['start_time'] ?? '') ?>">
                        <?php if (isset($errors['start_time'])): ?>
                            <span class="sik-error"><?= e($errors['start_time']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="dealEnd">Ends <span class="req">*</span></label>
                        <input class="sik-input<?= isset($errors['end_time']) ? ' is-invalid' : '' ?>"
                               type="datetime-local" id="dealEnd" name="end_time" required
                               value="<?= e($deal['end_time'] ?? '') ?>">
                        <?php if (isset($errors['end_time'])): ?>
                            <span class="sik-error"><?= e($errors['end_time']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="dealStatus">Status</label>
                        <select class="sik-select" id="dealStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $deal['status'] ?? 'active'
                            ) ?>
                        </select>
                        <span class="sik-help">Only one deal shows on the homepage: the active one ending soonest.</span>
                    </div>

                    <?php if ($isEdit): ?>
                        <div>
                            <div class="sik-label" style="margin-bottom:6px">Units sold on this deal</div>
                            <?= marketing_usage_bar(
                                (int) ($deal['stock_sold'] ?? 0),
                                isset($deal['stock_limit']) && $deal['stock_limit'] !== '' ? (int) $deal['stock_limit'] : null,
                                'sold'
                            ) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('deals/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Deal' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
