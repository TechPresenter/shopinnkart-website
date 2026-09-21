<?php
/**
 * ShopInnKart - Blog listing.
 *
 * Supports ?category=<slug> and ?q=<term>. The featured post is pulled out of
 * the grid on every page so it never appears twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

$term         = trim((string) input('q', ''));
$categorySlug = trim((string) input('category', ''));
$currentPage  = max(1, input_int('page', 1));

$categories = blog_categories();
$counts     = blog_category_counts();

$activeCategory = null;
foreach ($categories as $category) {
    if ($category['slug'] === $categorySlug) {
        $activeCategory = $category;
        break;
    }
}
if ($activeCategory === null) {
    $categorySlug = '';
}

// The hero is only rendered on page one, but it is excluded from the grid on
// every page so the pagination maths stays consistent.
$hero = ($term === '' && $categorySlug === '') ? blog_featured_post() : null;

$listing    = blog_posts([
    'category'   => $categorySlug,
    'q'          => $term,
    'page'       => $currentPage,
    'per_page'   => 9,
    'exclude_id' => $hero['id'] ?? null,
]);
$posts      = $listing['items'];
$pagination = $listing['pagination'];

$canonicalParams = [];
if ($categorySlug !== '') { $canonicalParams['category'] = $categorySlug; }
if ($term !== '')         { $canonicalParams['q'] = $term; }
if ($pagination['current'] > 1) { $canonicalParams['page'] = $pagination['current']; }

seo_from_page('blog');
seo_set([
    'canonical' => url('blog') . ($canonicalParams === [] ? '' : '?' . http_build_query($canonicalParams)),
]);
if ($activeCategory !== null) {
    seo_set([
        'title'       => $activeCategory['name'] . ' Articles',
        'description' => (string) ($activeCategory['description'] ?: 'Articles filed under ' . $activeCategory['name'] . '.'),
    ]);
}
seo_add_schema(seo_breadcrumb_schema(array_values(array_filter([
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Blog', 'url' => url('blog')],
    $activeCategory !== null ? ['label' => $activeCategory['name'], 'url' => url('blog') . '?category=' . rawurlencode($categorySlug)] : null,
]))));

require INCLUDES_PATH . '/header.php';

echo cms_page_banner([
    'title'        => $activeCategory !== null ? $activeCategory['name'] : 'Tech Guides & Advice',
    'banner_image' => null,
    'updated_at'   => null,
], [
    'subtitle' => $activeCategory !== null
        ? (string) $activeCategory['description']
        : 'Buying guides, long-term reviews and setup walkthroughs from the team that sells the gear.',
    'updated'  => false,
    'trail'    => array_values(array_filter([
        ['label' => 'Home', 'url' => url()],
        $activeCategory !== null ? ['label' => 'Blog', 'url' => url('blog')] : ['label' => 'Blog'],
        $activeCategory !== null ? ['label' => (string) $activeCategory['name']] : null,
    ])),
]);
?>

<div class="sik-container sik-section sik-section--sm">

    <?php if ($hero !== null && $pagination['current'] === 1): ?>
        <?php
        $heroImage = content_image($hero['featured_image'] ?? null) ?? img_url(null);
        $heroUrl   = blog_url((string) $hero['slug']);
        ?>
        <article class="sik-card" style="margin-bottom:var(--sp-7)" data-anim="fade-up">
            <div style="display:grid;gap:0" class="lg:grid-cols-2">
                <a href="<?= e($heroUrl) ?>" aria-label="<?= e($hero['title']) ?>"
                   style="display:block;background:var(--sik-soft);aspect-ratio:16/9;overflow:hidden">
                    <img src="<?= e($heroImage) ?>" alt="<?= e($hero['title']) ?>" width="960" height="540"
                         style="width:100%;height:100%;object-fit:cover" loading="lazy" decoding="async">
                </a>
                <div style="padding:var(--sp-6);display:flex;flex-direction:column;justify-content:center">
                    <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2);align-items:center;margin-bottom:var(--sp-3)">
                        <span class="sik-badge sik-badge--orange">Featured</span>
                        <?php if (!empty($hero['category_name'])): ?>
                            <a class="sik-badge sik-badge--soft"
                               href="<?= e(url('blog.php') . '?category=' . rawurlencode((string) $hero['category_slug'])) ?>">
                                <?= e($hero['category_name']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <h2 style="font-size:clamp(19px,2.4vw,25px);font-weight:800;line-height:1.3">
                        <a href="<?= e($heroUrl) ?>"><?= e($hero['title']) ?></a>
                    </h2>
                    <p style="font-size:14px;color:var(--sik-muted);line-height:1.7;margin-top:var(--sp-3)">
                        <?= e(str_limit((string) $hero['excerpt'], 210)) ?>
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:var(--sp-3);align-items:center;margin-top:var(--sp-4);
                                font-size:12px;color:var(--sik-muted)">
                        <?php if (!empty($hero['author_name'])): ?>
                            <span><?= e($hero['author_name']) ?></span><span aria-hidden="true">&middot;</span>
                        <?php endif; ?>
                        <span><?= e(format_date($hero['published_at'] ?: $hero['created_at'])) ?></span>
                        <span aria-hidden="true">&middot;</span>
                        <span><?= blog_reading_time($hero['content'] ?? '') ?> min read</span>
                    </div>
                    <div style="margin-top:var(--sp-5)">
                        <a class="sik-btn sik-btn--primary" href="<?= e($heroUrl) ?>">
                            Read the guide <?= icon('arrow-right', 'w-4 h-4') ?>
                        </a>
                    </div>
                </div>
            </div>
        </article>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:var(--sp-4);align-items:center;justify-content:space-between;margin-bottom:var(--sp-5)">
        <?php if ($categories !== []): ?>
            <div class="sik-pills">
                <a class="sik-pill<?= $categorySlug === '' ? ' is-active' : '' ?>"
                   href="<?= e(url_with(['category' => null, 'page' => null])) ?>">All posts</a>
                <?php foreach ($categories as $category): ?>
                    <?php $count = (int) ($counts[$category['id']] ?? 0); ?>
                    <a class="sik-pill<?= $categorySlug === $category['slug'] ? ' is-active' : '' ?>"
                       href="<?= e(url_with(['category' => $category['slug'], 'page' => null])) ?>">
                        <?= e($category['name']) ?><?= $count > 0 ? ' (' . $count . ')' : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="get" action="<?= e(url('blog.php')) ?>" role="search" style="flex:1;min-width:230px;max-width:340px">
            <?php if ($categorySlug !== ''): ?>
                <input type="hidden" name="category" value="<?= e($categorySlug) ?>">
            <?php endif; ?>
            <div class="sik-search__field">
                <label class="sik-sr" for="blogSearch">Search articles</label>
                <input class="sik-search__input" id="blogSearch" type="search" name="q"
                       placeholder="Search articles&hellip;" autocomplete="off" value="<?= e($term) ?>">
                <button class="sik-search__submit" type="submit" aria-label="Search articles">
                    <?= icon('search', 'w-4 h-4') ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($term !== '' || $categorySlug !== ''): ?>
        <p style="font-size:13px;color:var(--sik-muted);margin-bottom:var(--sp-5)">
            <?= (int) $pagination['total'] ?> article<?= (int) $pagination['total'] === 1 ? '' : 's' ?>
            <?php if ($term !== ''): ?> matching &ldquo;<?= e($term) ?>&rdquo;<?php endif; ?>
            <?php if ($activeCategory !== null): ?> in <?= e($activeCategory['name']) ?><?php endif; ?>
            &middot; <a href="<?= e(url('blog.php')) ?>" style="color:var(--sik-primary-ink)">Clear filters</a>
        </p>
    <?php endif; ?>

    <?php if ($posts === []): ?>
        <div class="sik-empty">
            <?= icon('edit', 'w-12 h-12') ?>
            <h2 class="sik-empty__title">
                <?= $term !== '' || $categorySlug !== '' ? 'No articles match that' : 'No articles published yet' ?>
            </h2>
            <p class="sik-empty__text">
                <?php if ($term !== '' || $categorySlug !== ''): ?>
                    Try a different search or browse every article instead.
                <?php else: ?>
                    Publish a post from the admin blog manager and it will show up here.
                <?php endif; ?>
            </p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <?php if ($term !== '' || $categorySlug !== ''): ?>
                    <a class="sik-btn sik-btn--primary" href="<?= e(url('blog.php')) ?>">Browse all articles</a>
                <?php else: ?>
                    <a class="sik-btn sik-btn--primary" href="<?= e(url('shop.php')) ?>">Browse the store</a>
                    <a class="sik-btn sik-btn--outline" href="<?= e(admin_url('blog/')) ?>">Manage Blog</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="sik-grid" style="--cols-desktop:3;--cols-tablet:2;--cols-mobile:1">
            <?php foreach ($posts as $post): ?>
                <?= blog_card($post) ?>
            <?php endforeach; ?>
        </div>
        <?= content_pager($pagination) ?>
    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
