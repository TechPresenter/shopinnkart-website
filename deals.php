<?php
/**
 * ShopInnKart - Deals.
 *
 * Deal of the Day and the running flash sale get the top of the page because
 * they expire; the standing discounts are the listing underneath.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';

/** Countdown block wired to the ticker in assets/js/app.js. */
function deals_countdown(int $seconds, string $expiredText, bool $dark = false): void
{
    if ($seconds <= 0) {
        return;
    }
    ?>
    <div class="sik-countdown <?= $dark ? 'sik-countdown--dark' : '' ?>" data-countdown="<?= $seconds ?>"
         data-expired-text="<?= e($expiredText) ?>">
        <?php foreach (['days' => 'Days', 'hours' => 'Hrs', 'minutes' => 'Min', 'seconds' => 'Sec'] as $unit => $label): ?>
            <span class="sik-countdown__unit">
                <b class="sik-countdown__num" data-cd="<?= e($unit) ?>">00</b>
                <span class="sik-countdown__label"><?= e($label) ?></span>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
}

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Deals'],
];

$deal = get_active_deal();
$dealProduct = ($deal !== null && !empty($deal['product_id'])) ? get_product((int) $deal['product_id']) : null;

$flashSale = get_active_flash_sale();
$flashProducts = $flashSale === null ? [] : query_products(['flash' => 1, 'per_page' => 8])['items'];

$listing = product_listing_query([
    // Anything marked down by at least 10% counts as a deal here.
    'base'         => ['discount' => 10],
    'default_sort' => 'discount',
    'title'        => 'Today’s Deals',
    'subtitle'     => 'Every product marked down 10% or more, refreshed as prices change.',
    'breadcrumbs'  => $crumbs,
    'clear_url'    => url('deals.php'),
    'toolbar_note' => 'discounted now',
    'empty'        => static function (): void {
        ?>
        <div class="sik-empty" style="grid-column:1/-1">
            <?= icon('percent', 'w-14 h-14') ?>
            <p class="sik-empty__title">No deals match these filters</p>
            <p class="sik-empty__text">
                Clear the filters to see everything on offer, or browse the full catalogue.
            </p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('deals.php')) ?>" data-filter-clear>Show all deals</a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">Browse all products</a>
            </div>
        </div>
        <?php
    },
]);

seo_from_page('deals');
seo_set(array_filter([
    'og_type' => 'website',
    'robots'  => $listing['robots'],
]));
seo_add_schema(seo_breadcrumb_schema($crumbs));
seo_add_schema(seo_item_list_schema($listing['items'], 'Today\'s Deals'));

require INCLUDES_PATH . '/header.php';
?>

<?php if ($deal !== null && $dealProduct !== null): ?>
    <?php
    $stockLimit = $deal['stock_limit'] !== null ? (int) $deal['stock_limit'] : null;
    $sold = (int) $deal['stock_sold'];
    $remaining = $stockLimit !== null ? max(0, $stockLimit - $sold) : (int) $dealProduct['stock'];
    $soldPercent = ($stockLimit !== null && $stockLimit > 0)
        ? min(100, (int) round(($sold / $stockLimit) * 100))
        : 0;
    ?>
    <section class="sik-section sik-section--sm">
        <div class="sik-container">
            <div class="sik-deal" data-anim="fade-up">
                <div class="sik-deal__grid">
                    <div>
                        <span class="sik-deal__eyebrow"><?= e($deal['subtitle'] ?: 'Deal of the Day') ?></span>
                        <h2 class="sik-deal__title"><?= e($deal['title']) ?></h2>

                        <a href="<?= e($dealProduct['url']) ?>" style="display:block;margin-top:var(--sp-4)">
                            <span style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--sik-muted)">
                                <?= e((string) ($dealProduct['brand_name'] ?? '')) ?>
                            </span>
                            <span style="display:block;font-size:18px;font-weight:700;margin-top:2px"><?= e($dealProduct['name']) ?></span>
                        </a>

                        <div class="sik-card__price" style="margin-top:var(--sp-3)">
                            <span class="sik-price" style="font-size:28px"><?= e($dealProduct['price_display']) ?></span>
                            <?php if (!empty($dealProduct['on_sale'])): ?>
                                <span class="sik-price--mrp" style="font-size:15px"><?= e($dealProduct['mrp_display']) ?></span>
                                <span class="sik-price--off" style="font-size:14px"><?= (int) $dealProduct['discount'] ?>% off</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($stockLimit !== null): ?>
                            <div style="margin-top:var(--sp-4);max-width:340px">
                                <div style="display:flex;justify-content:space-between;font-size:12.5px;font-weight:600;margin-bottom:var(--sp-2)">
                                    <span>Sold: <?= $sold ?></span>
                                    <span style="color:var(--sik-primary-ink)">Only <?= $remaining ?> left!</span>
                                </div>
                                <div class="sik-progress"><span style="width:<?= $soldPercent ?>%"></span></div>
                            </div>
                        <?php endif; ?>

                        <?php if (seconds_until($deal['end_time']) > 0): ?>
                            <div style="margin-top:var(--sp-5)">
                                <div style="font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--sik-muted);margin-bottom:var(--sp-2)">
                                    Hurry! Offer ends in
                                </div>
                                <?php deals_countdown(seconds_until($deal['end_time']), 'This deal has ended'); ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-6)">
                            <?= add_to_cart_button($dealProduct, [
                                'size'  => 'lg',
                                'block' => false,
                                'label' => !empty($deal['button_text']) ? (string) $deal['button_text'] : null,
                            ]) ?>
                            <a class="sik-btn sik-btn--outline sik-btn--lg" href="<?= e($dealProduct['url']) ?>">View Details</a>
                        </div>
                    </div>

                    <div class="sik-deal__media">
                        <a href="<?= e($dealProduct['url']) ?>">
                            <img src="<?= e($dealProduct['image_url']) ?>" alt="<?= e($dealProduct['name']) ?>"
                                 width="420" height="420" fetchpriority="high">
                        </a>
                        <?php if ((int) $dealProduct['discount'] > 0): ?>
                            <div class="sik-deal__off">
                                <b><?= (int) $dealProduct['discount'] ?>%</b><span>OFF</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($flashSale !== null && $flashProducts !== []): ?>
    <section class="sik-section sik-section--sm sik-flash">
        <div class="sik-container">
            <div class="sik-flash__head" data-anim="fade-up">
                <div>
                    <h2 class="sik-flash__title">
                        <?= icon('zap', '', true) ?><?= e($flashSale['name']) ?>
                    </h2>
                    <?php if (!empty($flashSale['subtitle'])): ?>
                        <p style="color:rgba(255,255,255,.7);font-size:14px;margin-top:var(--sp-2)"><?= e($flashSale['subtitle']) ?></p>
                    <?php endif; ?>
                </div>

                <?php if (seconds_until($flashSale['end_time']) > 0): ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap">
                        <span style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.7)">
                            Ends in
                        </span>
                        <?php deals_countdown(seconds_until($flashSale['end_time']), 'Sale ended', true); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:3;--cols-mobile:2">
                <?php foreach ($flashProducts as $product): ?>
                    <?= product_card($product) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
