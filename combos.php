<?php
/**
 * ShopInnKart - Combo offers.
 *
 * The index of every bundle a shopper may buy right now. combo_list() decides
 * what that means - it drops a set that has lost a component, run out of stock,
 * or stopped being cheaper than the same items bought loose - so a card printed
 * here is always one that /api/cart/add-combo.php will accept.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Combo Offers'],
];

// A deliberately high ceiling: combo_list() defaults to 12 because its usual
// callers are homepage rails, and this is the page those rails link to.
$combos = combo_list(['limit' => 60]);

seo_from_page('combos');
seo_set([
    'title'       => seo_get('title', 'Combo Offers'),
    'description' => seo_get(
        'description',
        'Curated sets sold together for less than the same products cost bought separately.'
    ),
    'canonical'   => seo_get('canonical', url('combos')),
    'og_type'     => 'website',
]);
seo_add_schema(seo_breadcrumb_schema($crumbs));

// Built here rather than through seo_item_list_schema(), which addresses every
// entry with product_url(): a combo lives at /combo/<slug>, so borrowing that
// helper would hand search engines a list of /product/<combo-slug> 404s.
if ($combos !== []) {
    $listElements = [];
    foreach (array_values($combos) as $index => $listed) {
        $listElements[] = [
            '@type'    => 'ListItem',
            'position' => $index + 1,
            'url'      => $listed['url'],
            'name'     => (string) $listed['name'],
        ];
    }
    seo_add_schema([
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => 'Combo Offers',
        'numberOfItems'   => count($listElements),
        'itemListElement' => $listElements,
    ]);
}

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container sik-section sik-section--sm">

    <?= breadcrumbs($crumbs) ?>

    <header class="sik-listing__head">
        <div class="sik-listing__headrow">
            <div class="sik-listing__heading">
                <h1 class="sik-listing__title">Combo Offers</h1>
                <p class="sik-listing__sub">
                    Sets that cost less together. Every saving below is measured against what the same
                    products cost today bought one at a time &ndash; not against their MRP.
                </p>
            </div>
        </div>
    </header>

    <?php if ($combos === []): ?>
        <div class="sik-empty">
            <?= icon('gift', 'w-14 h-14') ?>
            <p class="sik-empty__title">No combos are running right now</p>
            <p class="sik-empty__text">
                A combo shows here while every product in it is in stock and the set still costs less
                than its parts. Check back soon, or browse the catalogue in the meantime.
            </p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('shop.php')) ?>">Browse all products</a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('deals.php')) ?>">See today&rsquo;s deals</a>
            </div>
        </div>
    <?php else: ?>
        <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:3;--cols-mobile:2">
            <?php foreach ($combos as $combo): ?>
                <article class="sik-card" data-anim="fade-up" data-combo-card="<?= (int) $combo['id'] ?>">
                    <?php // The name below is the card's one tab stop to the combo; the photo
                          // repeats that link for a pointer, so it stays out of the tab order. ?>
                    <a class="sik-card__media" href="<?= e($combo['url']) ?>" tabindex="-1" aria-hidden="true">
                        <img class="sik-card__img sik-card__img--main"
                             src="<?= e($combo['image_url']) ?>" alt="<?= e($combo['name']) ?>"
                             width="600" height="600" loading="lazy" decoding="async">
                    </a>

                    <?php if ($combo['badge'] !== null): ?>
                        <div class="sik-card__badges">
                            <span class="sik-badge sik-badge--<?= e_attr($combo['badge']['tone']) ?>">
                                <?= e($combo['badge']['label']) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="sik-card__body">
                        <span class="sik-card__brand">
                            <?= (int) $combo['item_count'] ?> product<?= (int) $combo['item_count'] === 1 ? '' : 's' ?>
                            &middot;
                            <?= (int) $combo['unit_count'] ?> item<?= (int) $combo['unit_count'] === 1 ? '' : 's' ?>
                        </span>

                        <h3 class="sik-card__name">
                            <a href="<?= e($combo['url']) ?>"><?= e($combo['name']) ?></a>
                        </h3>

                        <?php if (trim((string) $combo['subtitle']) !== ''): ?>
                            <p class="sik-caption"><?= e(str_limit((string) $combo['subtitle'], 90)) ?></p>
                        <?php endif; ?>

                        <?php // The struck-through figure is `regular`, never `mrp`: most of this
                              // catalogue is already discounted, so pricing a set against MRP
                              // advertises a saving the shopper could get anyway. ?>
                        <div class="sik-card__price">
                            <span class="sik-price"><?= e($combo['price_display']) ?></span>
                            <span class="sik-price--mrp"><?= e($combo['regular_display']) ?></span>
                            <?php // Hidden at 0 the way product.php hides its own: combo_is_sellable()
                                  // only guarantees saving > 0, so a few rupees off a four-figure set
                                  // rounds to 0% and would otherwise print "0% off" beside a real
                                  // struck-through price. The rupee line below still carries the saving. ?>
                            <?php if ((int) $combo['percent'] > 0): ?>
                                <span class="sik-price--off"><?= (int) $combo['percent'] ?>% off</span>
                            <?php endif; ?>
                        </div>

                        <?php // Not a restatement of the percentage above: this line carries the
                              // rupee figure and, more to the point, what it is measured against. ?>
                        <p class="sik-caption" style="color:var(--sik-success-ink);font-weight:600">
                            Save <?= e($combo['saving_display']) ?> vs buying separately
                        </p>

                        <?php if (!empty($combo['low_stock'])): ?>
                            <span class="sik-card__stock">
                                Only <?= (int) $combo['available'] ?> set<?= (int) $combo['available'] === 1 ? '' : 's' ?> left
                            </span>
                        <?php endif; ?>

                        <div class="sik-card__foot">
                            <a class="sik-btn sik-btn--outline sik-btn--block" href="<?= e($combo['url']) ?>">
                                <?= icon('gift', 'w-4 h-4') ?><span class="sik-btn__label">View combo</span>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
