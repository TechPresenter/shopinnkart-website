<?php
/**
 * ShopInnKart - Wishlist.
 *
 * Guests get a session-backed list and signed-in customers get a stored one;
 * cart-functions.php hides the difference, so this page treats both the same.
 * The bulk "move to cart" runs server-side so it works without JavaScript.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

// Admin > Settings > Widgets can switch the wishlist off, or restrict it to
// signed-in customers. Refused before the POST handler as well as before the
// page body: the bulk "move all to cart" is a write and must not run either.
$wishlistRefusal = feature_refusal('wishlist');

if ($wishlistRefusal !== null) {
    // A switched-off feature is a route that no longer exists on this store, so
    // the status goes out before a byte of the page. "Sign in first" is a
    // working page for the wrong visitor, so that case stays a 200.
    if (!wishlist_enabled()) {
        http_response_code(404);
    }

    seo_set([
        'title'     => 'Wishlist unavailable',
        'robots'    => 'noindex, nofollow',
        'canonical' => url('wishlist.php'),
    ]);

    require INCLUDES_PATH . '/header.php';
    saved_list_unavailable('wishlist');
    require INCLUDES_PATH . '/footer.php';
    exit;
}

if (is_post()) {
    csrf_require();

    if (input('action') === 'move_all') {
        $moved = 0;
        $skipped = 0;

        // Stock and price are re-read by cart_add(); nothing is taken from the form.
        foreach (wishlist_products() as $wishItem) {
            // A product with a real choice of variants cannot be moved in bulk:
            // cart_add() would fall back to the default one, which is a guess
            // nobody asked for. Those stay saved and get moved from their own
            // product page.
            if (empty($wishItem['in_stock']) || variant_choice_required($wishItem)) {
                $skipped++;
                continue;
            }
            $result = cart_add((int) $wishItem['id'], null, 1);
            if ($result['ok']) {
                wishlist_remove((int) $wishItem['id']);
                $moved++;
            } else {
                $skipped++;
            }
        }

        if ($moved > 0) {
            flash('success', $moved . ' item' . ($moved === 1 ? '' : 's') . ' moved to your cart.'
                . ($skipped > 0 ? ' ' . $skipped . ' could not be moved and stayed on your wishlist.' : ''));
        } else {
            flash('warning', 'Nothing could be moved - none of these items are in stock right now.');
        }
    }

    redirect(url('wishlist.php'));
}

seo_set([
    'title'       => 'My Wishlist',
    'description' => 'Everything you have saved for later, with live prices and stock.',
    'robots'      => 'noindex, follow',
    'canonical'   => url('wishlist.php'),
]);

$saved      = wishlist_products();
$inStockNow = array_values(array_filter($saved, static fn ($p) => !empty($p['in_stock'])));

// What the bulk button can honestly promise: in stock AND no variant to choose.
$movable = array_values(array_filter(
    $inStockNow,
    static fn (array $p): bool => !variant_choice_required($p)
));

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container sik-section sik-section--sm" data-wishlist-page>

    <?= breadcrumbs([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'My Wishlist', 'url' => url('wishlist.php')],
    ]) ?>

    <div class="sik-heading sik-heading--row" style="margin-top:var(--sp-5)">
        <div>
            <h1 class="sik-heading__title">MY <span class="sik-heading__accent">WISHLIST</span></h1>
            <p class="sik-heading__sub">
                <?php if ($saved === []): ?>
                    Save the things you are still thinking about and they will wait for you here.
                <?php else: ?>
                    <?= count($saved) ?> saved item<?= count($saved) === 1 ? '' : 's' ?>,
                    <?= count($inStockNow) ?> in stock right now.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($movable !== []): ?>
            <form method="post" action="<?= e(url('wishlist.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_all">
                <button type="submit" class="sik-btn sik-btn--primary sik-btn--sm">
                    <?= icon('cart', 'w-4 h-4') ?>
                    <span class="sik-btn__label">
                        Move <?= count($movable) ?> item<?= count($movable) === 1 ? '' : 's' ?> to cart
                    </span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!is_logged_in() && $saved !== []): ?>
        <div class="sik-alert sik-alert--info" style="margin-bottom:var(--sp-5)">
            <?= icon('info', 'w-5 h-5') ?>
            <span>
                This wishlist lives in your browser session.
                <a href="<?= e(url('login.php')) ?>" style="font-weight:700;text-decoration:underline">Sign in</a>
                and we will save it to your account so it follows you to any device.
            </span>
        </div>
    <?php endif; ?>

    <?php if ($saved === []): ?>
        <div class="sik-empty">
            <img src="<?= e(asset('images/placeholders/empty-wishlist.svg')) ?>" alt="" width="180" height="140">
            <h2 class="sik-empty__title">Your wishlist is empty</h2>
            <p class="sik-empty__text">
                Tap the heart on any product to park it here. Prices and stock stay live, so you will
                know the moment something you want drops.
            </p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('shop.php')) ?>">
                    <?= icon('grid', 'w-4 h-4') ?> Start shopping
                </a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('deals.php')) ?>">See today&rsquo;s deals</a>
            </div>
        </div>
    <?php else: ?>
        <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:3;--cols-mobile:2">
            <?php foreach ($saved as $savedItem): ?>
                <?php
                $savedId = (int) $savedItem['id'];
                $isOut   = empty($savedItem['in_stock']);
                $tone    = [STOCK_IN => 'green', STOCK_LOW => 'amber', STOCK_OUT => 'red'][$savedItem['stock_state']] ?? 'gray';
                ?>
                <article class="sik-card <?= $isOut ? 'is-out' : '' ?>" data-wishlist-row="<?= $savedId ?>">
                    <a class="sik-card__media" href="<?= e($savedItem['url']) ?>" aria-label="<?= e($savedItem['name']) ?>">
                        <img class="sik-card__img sik-card__img--main" src="<?= e($savedItem['image_url']) ?>"
                             alt="<?= e($savedItem['name']) ?>" width="400" height="400" loading="lazy" decoding="async">
                    </a>

                    <?php if (!empty($savedItem['badges'])): ?>
                        <div class="sik-card__badges">
                            <?php foreach ($savedItem['badges'] as $badge): ?>
                                <span class="sik-badge sik-badge--<?= e_attr($badge['tone']) ?>"><?= e($badge['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="sik-card__tools">
                        <button type="button" class="sik-iconbtn"
                                data-wishlist-remove data-product-id="<?= $savedId ?>"
                                aria-label="Remove <?= e($savedItem['name']) ?> from wishlist">
                            <?= icon('trash', 'w-4 h-4') ?>
                        </button>
                        <?php // A saved card is a product card, so its compare control is the
                              // same shared one and follows the same product-card switch. ?>
                        <?= compare_shows_on('card') ? compare_button($savedId, (string) $savedItem['name']) : '' ?>
                    </div>

                    <div class="sik-card__body">
                        <?php if (!empty($savedItem['brand_name'])): ?>
                            <span class="sik-card__brand"><?= e($savedItem['brand_name']) ?></span>
                        <?php endif; ?>

                        <h2 class="sik-card__name">
                            <a href="<?= e($savedItem['url']) ?>"><?= e($savedItem['name']) ?></a>
                        </h2>

                        <?php if ((int) $savedItem['rating_count'] > 0): ?>
                            <div class="sik-card__rating">
                                <?= rating_stars((float) $savedItem['rating_avg']) ?>
                                <span>(<?= (int) $savedItem['rating_count'] ?>)</span>
                            </div>
                        <?php endif; ?>

                        <div class="sik-card__price">
                            <span class="sik-price"><?= e($savedItem['price_display']) ?></span>
                            <?php if (!empty($savedItem['on_sale'])): ?>
                                <span class="sik-price--mrp"><?= e($savedItem['mrp_display']) ?></span>
                                <span class="sik-price--off"><?= (int) $savedItem['discount'] ?>% off</span>
                            <?php endif; ?>
                        </div>

                        <span class="sik-status sik-status--<?= e($tone) ?>" style="align-self:flex-start">
                            <?= e($savedItem['stock_label']) ?>
                        </span>

                        <div class="sik-card__foot">
                            <?php // Same component as every other add-to-cart; only the mode differs. ?>
                            <?= add_to_cart_button($savedItem, ['mode' => 'wishlist']) ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div style="text-align:center;margin-top:var(--sp-7)">
            <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">
                <?= icon('arrow-left', 'w-4 h-4') ?> Continue shopping
            </a>
        </div>
    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
