<?php
/**
 * ShopInnKart - Brand directory.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Brands'],
];

$brands = all_brands();
usort($brands, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

$featured = array_values(array_filter($brands, static fn (array $b): bool => (int) $b['is_featured'] === 1));

// Group by first character; anything not A-Z lands under #.
$groups = [];
foreach ($brands as $brand) {
    $letter = mb_strtoupper(mb_substr(trim((string) $brand['name']), 0, 1), 'UTF-8');
    if (!preg_match('/^[A-Z]$/', $letter)) {
        $letter = '#';
    }
    $groups[$letter][] = $brand;
}
ksort($groups);

$totalProducts = array_sum(array_map(static fn (array $b): int => (int) $b['product_count'], $brands));

seo_from_page('brands');
seo_set([
    'title'       => seo_get('title', 'All Brands'),
    'description' => seo_get('description', 'Browse every brand we stock, from flagship phone makers to audio specialists.'),
]);
seo_add_schema(seo_breadcrumb_schema($crumbs));

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container" style="padding-top:var(--sp-4)">
    <?= breadcrumbs($crumbs) ?>

    <header style="margin:var(--sp-4) 0 var(--sp-2)">
        <h1 style="font-size:clamp(20px,4vw,27px);font-weight:800;color:var(--sik-navy)">Shop by Brand</h1>
        <p style="color:var(--sik-muted);font-size:14px;margin-top:var(--sp-2)">
            <?= count($brands) ?> brands &middot; <?= (int) $totalProducts ?> products, all with official warranty.
        </p>
    </header>
</div>

<?php if ($brands === []): ?>
    <div class="sik-container sik-section">
        <div class="sik-empty">
            <?= icon('store', 'w-14 h-14') ?>
            <p class="sik-empty__title">No brands are published yet</p>
            <p class="sik-empty__text">Brands appear here as soon as they are added to the catalogue.</p>
            <a class="sik-btn sik-btn--primary" href="<?= e(url('shop.php')) ?>">Browse all products</a>
        </div>
    </div>
<?php else: ?>

    <?php if ($featured !== []): ?>
        <section class="sik-section sik-section--sm">
            <div class="sik-container">
                <div class="sik-heading sik-heading--row">
                    <div>
                        <h2 class="sik-heading__title">FEATURED <span class="sik-heading__accent">BRANDS</span></h2>
                    </div>
                    <a class="sik-viewall" href="#brandDirectory">
                        Full A&ndash;Z <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                    </a>
                </div>

                <div class="sik-grid" style="--cols-desktop:6;--cols-tablet:4;--cols-mobile:2">
                    <?php foreach ($featured as $brand): ?>
                        <a class="sik-cat" href="<?= e(brand_url((string) $brand['slug'])) ?>" data-anim="fade-up">
                            <span class="sik-cat__media" style="border-radius:var(--sik-radius)">
                                <img src="<?= e(img_url($brand['logo'])) ?>" alt="<?= e($brand['name']) ?>"
                                     width="120" height="60" loading="lazy"
                                     style="width:auto;max-width:100%;height:40px;object-fit:contain">
                            </span>
                            <span class="sik-cat__name"><?= e($brand['name']) ?></span>
                            <span class="sik-cat__count"><?= (int) $brand['product_count'] ?> products</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="sik-section sik-section--sm" id="brandDirectory">
        <div class="sik-container">
            <div class="sik-heading sik-heading--row">
                <div>
                    <h2 class="sik-heading__title">BRAND <span class="sik-heading__accent">DIRECTORY</span></h2>
                    <p class="sik-heading__sub">Jump straight to a letter.</p>
                </div>
            </div>

            <nav class="sik-pills" style="margin-bottom:var(--sp-6)" aria-label="Jump to letter">
                <?php foreach (array_keys($groups) as $letter): ?>
                    <a class="sik-pill" href="#brand-letter-<?= e($letter === '#' ? 'other' : $letter) ?>">
                        <?= e($letter) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($groups as $letter => $letterBrands): ?>
                <section id="brand-letter-<?= e($letter === '#' ? 'other' : $letter) ?>" style="margin-bottom:var(--sp-7)">
                    <h3 style="font-size:18px;font-weight:800;color:var(--sik-primary);border-bottom:1px solid var(--sik-border);padding-bottom:var(--sp-2);margin-bottom:var(--sp-4)">
                        <?= e($letter) ?>
                    </h3>
                    <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:3;--cols-mobile:1;gap:var(--sp-3)">
                        <?php foreach ($letterBrands as $brand): ?>
                            <a href="<?= e(brand_url((string) $brand['slug'])) ?>"
                               style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:#fff;border:1px solid var(--sik-border);border-radius:var(--sik-radius-sm)">
                                <img src="<?= e(img_url($brand['logo'])) ?>" alt="" width="60" height="30" loading="lazy"
                                     style="width:52px;height:28px;object-fit:contain;flex:none">
                                <span style="min-width:0">
                                    <span style="display:block;font-size:14px;font-weight:600;color:var(--sik-text)">
                                        <?= e($brand['name']) ?>
                                    </span>
                                    <span style="font-size:12px;color:var(--sik-muted)">
                                        <?= (int) $brand['product_count'] ?> products
                                    </span>
                                </span>
                                <span style="margin-left:auto;color:var(--sik-muted)"><?= icon('chevron-right', 'w-4 h-4') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
