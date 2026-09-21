<?php
/**
 * ShopInnKart - Single blog post (/blog/<slug>).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

$slug = trim((string) input('slug', ''));
$post = $slug === '' ? null : blog_post($slug);

if ($post === null) {
    require __DIR__ . '/404.php';
    exit;
}

blog_record_view((int) $post['id']);

$postUrl     = blog_url((string) $post['slug']);
$cover       = content_image($post['featured_image'] ?? null);
$readMinutes = blog_reading_time($post['content'] ?? '');
$published   = (string) ($post['published_at'] ?: $post['created_at']);
$related     = blog_related_posts($post, 3);

seo_set([
    'canonical' => $postUrl,
    'og_type'   => 'article',
]);
seo_from_entity($post, [
    'title'       => (string) $post['title'],
    'description' => str_limit((string) $post['excerpt'], 200, ''),
    'og_image'    => $cover !== null ? (string) $post['featured_image'] : '',
]);
seo_add_schema(seo_article_schema($post));
seo_add_schema(seo_breadcrumb_schema(array_values(array_filter([
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Blog', 'url' => url('blog')],
    !empty($post['category_name'])
        ? ['label' => (string) $post['category_name'], 'url' => url('blog') . '?category=' . rawurlencode((string) $post['category_slug'])]
        : null,
    ['label' => (string) $post['title'], 'url' => $postUrl],
]))));

$shareText  = rawurlencode((string) $post['title']);
$shareUrl   = rawurlencode($postUrl);
$shareLinks = [
    ['icon' => 'whatsapp',  'label' => 'Share on WhatsApp', 'href' => 'https://wa.me/?text=' . $shareText . '%20' . $shareUrl],
    ['icon' => 'twitter',   'label' => 'Share on X',        'href' => 'https://twitter.com/intent/tweet?url=' . $shareUrl . '&text=' . $shareText],
    ['icon' => 'facebook',  'label' => 'Share on Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . $shareUrl],
    ['icon' => 'linkedin',  'label' => 'Share on LinkedIn', 'href' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $shareUrl],
];

require INCLUDES_PATH . '/header.php';
?>

<div class="sik-container" style="padding-top:var(--sp-4)">
    <?= breadcrumbs(array_values(array_filter([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'Blog', 'url' => url('blog')],
        !empty($post['category_name'])
            ? ['label' => (string) $post['category_name'], 'url' => url('blog.php') . '?category=' . rawurlencode((string) $post['category_slug'])]
            : null,
        ['label' => (string) $post['title']],
    ]))) ?>
</div>

<div class="sik-container sik-section sik-section--sm">
    <article style="max-width:820px;margin-inline:auto">

        <header>
            <?php if (!empty($post['category_name'])): ?>
                <a class="sik-badge sik-badge--soft"
                   href="<?= e(url('blog.php') . '?category=' . rawurlencode((string) $post['category_slug'])) ?>">
                    <?= e($post['category_name']) ?>
                </a>
            <?php endif; ?>

            <h1 style="font-size:clamp(23px,4.2vw,34px);font-weight:800;line-height:1.22;margin-top:var(--sp-3)">
                <?= e($post['title']) ?>
            </h1>

            <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2) var(--sp-4);align-items:center;margin-top:var(--sp-4);
                        font-size:12.5px;color:var(--sik-muted)">
                <?php if (!empty($post['author_name'])): ?>
                    <span style="display:inline-flex;align-items:center;gap:var(--sp-2)">
                        <?= icon('user', 'w-4 h-4') ?><?= e($post['author_name']) ?>
                    </span>
                <?php endif; ?>
                <span style="display:inline-flex;align-items:center;gap:var(--sp-2)">
                    <?= icon('calendar', 'w-4 h-4') ?>
                    <time datetime="<?= e(date('Y-m-d', (int) strtotime($published))) ?>"><?= e(format_date($published)) ?></time>
                </span>
                <span style="display:inline-flex;align-items:center;gap:var(--sp-2)">
                    <?= icon('clock', 'w-4 h-4') ?><?= (int) $readMinutes ?> min read
                </span>
                <span style="display:inline-flex;align-items:center;gap:var(--sp-2)">
                    <?= icon('eye', 'w-4 h-4') ?><?= e(number_format((int) $post['views'])) ?> views
                </span>
            </div>
        </header>

        <?php if ($cover !== null): ?>
            <?php /* Explicit intrinsic size so the 16/9 box is reserved before the file lands. */ ?>
            <img src="<?= e($cover) ?>" alt="<?= e($post['title']) ?>" width="1200" height="675" fetchpriority="high"
                 style="width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;border-radius:var(--sik-radius);
                        margin-top:var(--sp-6);background:var(--sik-soft)">
        <?php endif; ?>

        <?php if (!empty($post['excerpt'])): ?>
            <p style="font-size:16px;line-height:1.7;color:var(--sik-text);font-weight:500;margin-top:var(--sp-6);
                      padding-left:var(--sp-4);border-left:3px solid var(--sik-primary)">
                <?= e($post['excerpt']) ?>
            </p>
        <?php endif; ?>

        <?php $body = content_prose($post['content'] ?? null); ?>
        <?php if ($body !== ''): ?>
            <div class="sik-prose" style="margin-top:var(--sp-6)"><?= $body ?></div>
        <?php else: ?>
            <div class="sik-empty sik-empty--sm">
                <?= icon('edit', 'w-12 h-12') ?>
                <h2 class="sik-empty__title">This article has no body yet</h2>
                <p class="sik-empty__text">The post is published but its content is empty. Add the copy in the
                    admin blog manager.</p>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('blog.php')) ?>">Back to all articles</a>
            </div>
        <?php endif; ?>

        <div style="display:flex;flex-wrap:wrap;gap:var(--sp-3);align-items:center;margin-top:var(--sp-7);padding-top:var(--sp-6);
                    border-top:1px solid var(--sik-border)">
            <span style="font-size:13px;font-weight:700">Share this article</span>
            <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2)">
                <button type="button" class="sik-iconbtn" data-share="<?= e($postUrl) ?>"
                        data-share-title="<?= e($post['title']) ?>" aria-label="Share this article">
                    <?= icon('external', 'w-4 h-4') ?>
                </button>
                <?php foreach ($shareLinks as $link): ?>
                    <a class="sik-iconbtn" href="<?= e($link['href']) ?>" target="_blank" rel="noopener noreferrer"
                       aria-label="<?= e($link['label']) ?>"><?= icon($link['icon'], 'w-4 h-4') ?></a>
                <?php endforeach; ?>
                <button type="button" class="sik-iconbtn" data-copy="<?= e($postUrl) ?>" aria-label="Copy link">
                    <?= icon('copy', 'w-4 h-4') ?>
                </button>
            </div>
        </div>
    </article>
</div>

<section class="sik-section sik-section--sm sik-section--soft">
    <div class="sik-container">
        <div class="sik-heading sik-heading--row">
            <h2 class="sik-heading__title">
                MORE FROM <span class="sik-heading__accent"><?= e($post['category_name'] ?: 'THE BLOG') ?></span>
            </h2>
            <a class="sik-viewall" href="<?= e(url('blog.php')) ?>">All articles <?= icon('arrow-right', 'w-4 h-4') ?></a>
        </div>

        <?php if ($related === []): ?>
            <div class="sik-empty sik-empty--sm">
                <?= icon('edit', 'w-12 h-12') ?>
                <h3 class="sik-empty__title">No other articles here yet</h3>
                <p class="sik-empty__text">This is the only published post in this topic so far.</p>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('blog.php')) ?>">Browse all articles</a>
            </div>
        <?php else: ?>
            <div class="sik-grid" style="--cols-desktop:3;--cols-tablet:2;--cols-mobile:1">
                <?php foreach ($related as $item): ?>
                    <?= blog_card($item) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
