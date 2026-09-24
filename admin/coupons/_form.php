<?php
/**
 * ShopInnKart Admin - Coupon form body.
 *
 * Shared by create.php and edit.php. Both set:
 *   $coupon   field values (stored row, or defaults overlaid with the submit)
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

/** @var array $coupon @var array $errors @var bool $isEdit */
$isEdit   = $isEdit ?? false;
$errors   = $errors ?? [];
$couponId = (int) ($coupon['id'] ?? 0);

$restrictions = $coupon['restrictions'] ?? coupon_restriction_defaults();
$type         = (string) ($coupon['type'] ?? COUPON_TYPE_PERCENTAGE);
$isPercentage = $type === COUPON_TYPE_PERCENTAGE;
$isShipping   = $type === COUPON_TYPE_FREE_SHIPPING;

$categoryChoices = marketing_category_choices();
$brandChoices    = admin_lookup('brands', 'name', '1');

// Attached products are rendered from the live product rows so the picker can
// show the name and price rather than a bare id.
$pickedProducts = [];
foreach (marketing_product_rows($restrictions['products']) as $productId => $product) {
    $pickedProducts[] = [
        'id'    => $productId,
        'name'  => $product['name'],
        'image' => $product['main_image'],
        'meta'  => trim((string) $product['sku']) . ' · ' . money(marketing_product_price($product)),
    ];
}

$currencySymbol = (string) setting('currency_symbol', CURRENCY_SYMBOL);
?>

<form class="ad-form" method="post" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:16px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Coupon</div></div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="couponCode">Code <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['code']) ? ' is-invalid' : '' ?>" type="text"
                                   id="couponCode" name="code" maxlength="60" required
                                   style="text-transform:uppercase" autocomplete="off" spellcheck="false"
                                   value="<?= e($coupon['code'] ?? '') ?>" placeholder="NEWYEAR20">
                            <?php if (isset($errors['code'])): ?>
                                <span class="sik-error"><?= e($errors['code']) ?></span>
                            <?php else: ?>
                                <?php // Stored upper-cased; the lookup at the cart is case-insensitive. ?>
                                <span class="sik-help">Customers may type it in any case.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="couponType">Discount type <span class="req">*</span></label>
                            <select class="sik-select" id="couponType" name="type">
                                <?= admin_options(COUPON_TYPES, $type) ?>
                            </select>
                            <?php if (isset($errors['type'])): ?>
                                <span class="sik-error"><?= e($errors['type']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="couponDescription">Description</label>
                        <input class="sik-input<?= isset($errors['description']) ? ' is-invalid' : '' ?>" type="text"
                               id="couponDescription" name="description" maxlength="255"
                               value="<?= e($coupon['description'] ?? '') ?>"
                               placeholder="20% off your first order, up to ₹500">
                        <?php if (isset($errors['description'])): ?>
                            <span class="sik-error"><?= e($errors['description']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Shown on the cart beside the applied coupon.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Amount</div>
                    <div class="ad-card__sub">Free-shipping coupons carry no amount.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <div class="ad-field" id="couponValueField" <?= $isShipping ? 'style="display:none"' : '' ?>>
                            <label class="sik-label" for="couponValue">
                                Value <span class="req">*</span>
                                <span class="ad-muted" id="couponValueUnit">(<?= e($isPercentage ? '%' : $currencySymbol) ?>)</span>
                            </label>
                            <input class="sik-input<?= isset($errors['value']) ? ' is-invalid' : '' ?>" type="number"
                                   id="couponValue" name="value" step="0.01" min="0"
                                   value="<?= e($coupon['value'] ?? '') ?>" placeholder="20">
                            <?php if (isset($errors['value'])): ?>
                                <span class="sik-error"><?= e($errors['value']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="couponMinimum">Minimum order</label>
                            <input class="sik-input<?= isset($errors['minimum_order']) ? ' is-invalid' : '' ?>" type="number"
                                   id="couponMinimum" name="minimum_order" step="0.01" min="0"
                                   value="<?= e($coupon['minimum_order'] ?? '0') ?>" placeholder="0">
                            <?php if (isset($errors['minimum_order'])): ?>
                                <span class="sik-error"><?= e($errors['minimum_order']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">Cart subtotal, before discount. 0 = no minimum.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field" id="couponCapField" <?= $isPercentage ? '' : 'style="display:none"' ?>>
                            <label class="sik-label" for="couponCap">Maximum discount</label>
                            <input class="sik-input<?= isset($errors['maximum_discount']) ? ' is-invalid' : '' ?>" type="number"
                                   id="couponCap" name="maximum_discount" step="0.01" min="0"
                                   value="<?= e($coupon['maximum_discount'] ?? '') ?>" placeholder="No cap">
                            <?php if (isset($errors['maximum_discount'])): ?>
                                <span class="sik-error"><?= e($errors['maximum_discount']) ?></span>
                            <?php else: ?>
                                <?php // The label "Maximum discount" already says it caps the percentage. ?>
                                <span class="sik-help">Blank = uncapped.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Schedule &amp; limits</div>
                    <div class="ad-card__sub">Blank leaves that end of the window open.</div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="couponStart">Starts</label>
                            <input class="sik-input<?= isset($errors['start_date']) ? ' is-invalid' : '' ?>"
                                   type="datetime-local" id="couponStart" name="start_date"
                                   value="<?= e($coupon['start_date'] ?? '') ?>">
                            <?php if (isset($errors['start_date'])): ?>
                                <span class="sik-error"><?= e($errors['start_date']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="couponEnd">Ends</label>
                            <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                                   type="datetime-local" id="couponEnd" name="end_date"
                                   value="<?= e($coupon['end_date'] ?? '') ?>">
                            <?php if (isset($errors['end_date'])): ?>
                                <span class="sik-error"><?= e($errors['end_date']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="couponUsageLimit">Total redemptions</label>
                            <input class="sik-input<?= isset($errors['usage_limit']) ? ' is-invalid' : '' ?>" type="number"
                                   id="couponUsageLimit" name="usage_limit" step="1" min="1"
                                   value="<?= e($coupon['usage_limit'] ?? '') ?>" placeholder="Unlimited">
                            <?php if (isset($errors['usage_limit'])): ?>
                                <span class="sik-error"><?= e($errors['usage_limit']) ?></span>
                            <?php else: ?>
                                <?php // "Total redemptions" already means across all customers. ?>
                                <span class="sik-help">Blank = unlimited.</span>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="couponPerUser">Per customer <span class="req">*</span></label>
                            <input class="sik-input<?= isset($errors['per_user_limit']) ? ' is-invalid' : '' ?>" type="number"
                                   id="couponPerUser" name="per_user_limit" step="1" min="0" max="999" required
                                   value="<?= e($coupon['per_user_limit'] ?? '1') ?>">
                            <?php if (isset($errors['per_user_limit'])): ?>
                                <span class="sik-error"><?= e($errors['per_user_limit']) ?></span>
                            <?php else: ?>
                                <span class="sik-help">0 = no per-customer cap. Guests are only limited by the total.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            /* Everything a coupon needs to be ADVERTISED rather than merely
               accepted. public_offers() reads these four columns, and it applies
               the same catalogue rules validate_coupon() applies at checkout, so
               an offer shown to a shopper cannot be refused in the basket. */
            $offerPlacements = array_filter(array_map('trim', explode(',', (string) ($coupon['offer_placements'] ?? ''))));
            /* One short line each, and none at all where the label already says it:
               "Homepage" and "Cart and checkout" described themselves. The header
               strip's countdown, the scratch panel and the product-page filtering are
               the three the label cannot tell you. */
            $placementList = [
                'header'  => ['Announcement strip', 'Above the header, with a countdown.'],
                'home'    => ['Homepage', ''],
                'scratch' => ['Scratch card', 'Hidden until a shopper scratches it.'],
                'product' => ['Product pages', 'Only on products it can apply to.'],
                'cart'    => ['Cart and checkout', ''],
            ];
            ?>
            <div class="ad-card" style="margin:0 0 16px">
                <div class="ad-card__head">
                    <div class="ad-card__title">Show as an offer</div>
                    <div class="ad-card__sub">Off by default. The code works whether or not it is advertised.</div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:16px">

                    <div>
                        <label class="ad-switch">
                            <input type="checkbox" name="offer_visible" value="1"
                                   <?= (int) ($coupon['offer_visible'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>Advertise this coupon on the storefront</span>
                        </label>
                        <?php // The first half restated the switch. What the switch cannot say is
                              // that public_offers() still filters on expiry, redemptions and
                              // status, so this can be on and the offer still never appear. ?>
                        <div class="sik-help" style="margin-top:5px">
                            An expired, fully-redeemed or inactive coupon is never shown, whatever this says.
                        </div>
                    </div>

                    <div>
                        <label class="sik-label" for="offerTitle">Headline</label>
                        <input class="sik-input" type="text" id="offerTitle" name="offer_title" maxlength="120"
                               value="<?= e((string) ($coupon['offer_title'] ?? '')) ?>"
                               placeholder="Left empty: built from the discount, e.g. &quot;10% off on orders over &#8377;599&quot;">
                        <?php // A hand-written headline that disagrees with the discount is worse
                              // than the plain generated one, hence "unless it is wrong". ?>
                        <div class="sik-help" style="margin-top:5px">
                            Leave blank unless the generated wording is wrong.
                        </div>
                    </div>

                    <div>
                        <span class="sik-label">Where it appears</span>
                        <div style="display:grid;gap:10px;margin-top:6px">
                            <?php foreach ($placementList as $placementKey => $placementMeta): ?>
                                <label class="ad-check">
                                    <input type="checkbox" name="offer_placements[]" value="<?= e_attr($placementKey) ?>"
                                           <?= in_array($placementKey, $offerPlacements, true) ? 'checked' : '' ?>>
                                    <span>
                                        <strong><?= e($placementMeta[0]) ?></strong>
                                        <?php if ($placementMeta[1] !== ''): ?>
                                            <span class="sik-help" style="display:block"><?= e($placementMeta[1]) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <label class="sik-label" for="offerSort">Order</label>
                        <input class="sik-input" type="number" id="offerSort" name="offer_sort" min="0" max="999"
                               style="max-width:140px"
                               value="<?= (int) ($coupon['offer_sort'] ?? 0) ?>">
                        <div class="sik-help" style="margin-top:5px">
                            Lower shows first; the strip carries only the first.
                        </div>
                    </div>
                </div>
            </div>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Restrictions</div>
                    <div class="ad-card__sub">Nothing selected = the coupon applies to the whole catalogue.</div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:16px">

                    <div>
                        <label class="ad-switch">
                            <input type="checkbox" name="restrict_first_order" value="1"
                                   <?= (int) $restrictions['first_order'] === 1 ? 'checked' : '' ?>>
                            <span class="ad-switch__track"></span>
                            <span>First order only</span>
                        </label>
                        <?php // A guest can never satisfy this: with no account there is no
                              // order history to check, so validate_coupon() refuses outright. ?>
                        <div class="sik-help" style="margin-top:5px">
                            Signed-in customers with no previous orders.
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label">Eligible products</label>
                        <?= marketing_product_picker([
                            'name'   => 'restrict_products',
                            'rows'   => $pickedProducts,
                            'empty'  => 'No product restriction — every product qualifies.',
                            'help'   => 'Only the matching lines in the cart are discounted.',
                        ]) ?>
                        <?php if (isset($errors['restrict_products'])): ?>
                            <span class="sik-error"><?= e($errors['restrict_products']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ad-row ad-row--2">
                        <div class="ad-field">
                            <label class="sik-label" for="couponCategories">Eligible categories</label>
                            <select class="sik-select" id="couponCategories" name="restrict_categories[]"
                                    multiple size="7" style="height:auto;padding:8px">
                                <?php foreach ($categoryChoices as $categoryId => $label): ?>
                                    <option value="<?= (int) $categoryId ?>"
                                        <?= in_array((int) $categoryId, $restrictions['categories'], true) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['restrict_categories'])): ?>
                                <span class="sik-error"><?= e($errors['restrict_categories']) ?></span>
                            <?php // The multi-select's own affordance teaches ctrl/cmd-click; the
                                  // line said nothing the widget does not. ?>
                            <?php endif; ?>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="couponBrands">Eligible brands</label>
                            <select class="sik-select" id="couponBrands" name="restrict_brands[]"
                                    multiple size="7" style="height:auto;padding:8px">
                                <?php foreach ($brandChoices as $brandId => $label): ?>
                                    <option value="<?= (int) $brandId ?>"
                                        <?= in_array((int) $brandId, $restrictions['brands'], true) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['restrict_brands'])): ?>
                                <span class="sik-error"><?= e($errors['restrict_brands']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="sik-label" for="couponUsers">Restrict to specific customers</label>
                        <textarea class="sik-textarea<?= isset($errors['restrict_users']) ? ' is-invalid' : '' ?>"
                                  id="couponUsers" name="restrict_users" rows="3" spellcheck="false"
                                  placeholder="one@example.com&#10;two@example.com"><?= e($restrictions['user_emails']) ?></textarea>
                        <?php if (isset($errors['restrict_users'])): ?>
                            <span class="sik-error"><?= e($errors['restrict_users']) ?></span>
                        <?php else: ?>
                            <span class="sik-help">One registered email per line.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">Availability</div></div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="ad-field">
                        <label class="sik-label" for="couponStatus">Status</label>
                        <select class="sik-select" id="couponStatus" name="status">
                            <?= admin_options(
                                ['active' => 'Active', 'inactive' => 'Inactive'],
                                $coupon['status'] ?? 'active'
                            ) ?>
                        </select>
                        <span class="sik-help">Inactive codes are rejected even inside their window.</span>
                    </div>

                    <?php if ($isEdit): ?>
                        <div>
                            <div class="sik-label" style="margin-bottom:6px">Redemptions</div>
                            <?= marketing_usage_bar(
                                (int) ($coupon['used_count'] ?? 0),
                                isset($coupon['usage_limit']) && $coupon['usage_limit'] !== '' ? (int) $coupon['usage_limit'] : null,
                                'redeemed'
                            ) ?>
                            <?php if (admin_can('coupons.view')): ?>
                                <a class="ad-btn ad-btn--sm ad-btn--block" style="margin-top:10px"
                                   href="<?= e(admin_url('coupons/usage.php?id=' . $couponId)) ?>">
                                    <?= icon('users', 'w-4 h-4') ?> Who redeemed it
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ad-card__foot">
                    <a class="ad-btn" href="<?= e(admin_url('coupons/')) ?>">Cancel</a>
                    <button type="submit" class="ad-btn ad-btn--primary">
                        <?= icon('check', 'w-4 h-4') ?>
                        <?= $isEdit ? 'Save Changes' : 'Create Coupon' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // The amount fields only make sense for some discount types, so the form
    // shows exactly the ones the chosen type will actually use.
    document.addEventListener('DOMContentLoaded', function () {
        var type = document.getElementById('couponType');
        var valueField = document.getElementById('couponValueField');
        var capField = document.getElementById('couponCapField');
        var unit = document.getElementById('couponValueUnit');
        if (!type) return;

        var symbol = <?= e_json($currencySymbol) ?>;

        function sync() {
            var isPercent = type.value === 'percentage';
            valueField.style.display = type.value === 'free_shipping' ? 'none' : '';
            capField.style.display = isPercent ? '' : 'none';
            unit.textContent = '(' + (isPercent ? '%' : symbol) + ')';
        }

        type.addEventListener('change', sync);
        sync();
    });
</script>
