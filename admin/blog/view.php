<?php
/**
 * ShopInnKart Admin - Blog post preview.
 *
 * Read-only. It exists so an admin can check a draft without publishing it,
 * since /blog/<slug> only serves published posts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('blog.view');

$id = input_int('id');
$post = $id > 0
    ? Database::fetch(
        'SELECT p.*, c.`name` AS category_name, c.`slug` AS category_slug, a.`name` AS admin_name
         FROM `blog_posts` p
         LEFT JOIN `blog_categories` c ON c.`id` = p.`category_id`
         LEFT JOIN `admins` a ON a.`id` = p.`admin_id`
         WHERE p.`id` = :id',
        ['id' => $id]
    )
    : null;

if ($post === null) {
    flash('error', 'That post no longer exists.');
    redirect(admin_url('blog/'));
}

$pageTitle    = 'Preview Post';
$pageSubtitle = '/blog/' . $post['slug'];
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Blog',      'url' => admin_url('blog/')],
    ['label' => str_limit((string) $post['title'], 50)],
];

$pageActions = '';
if (admin_can('blog.edit')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('blog/edit.php?id=' . $id)) . '">'
        . icon('edit', 'w-4 h-4') . ' Edit</a>';
}
$pageActions .= '<a class="ad-btn" href="' . e(blog_url((string) $post['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($post['status'] !== 'published'): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>This post is a draft. The storefront returns a 404 for it until the status is set to Published.</div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <div class="ad-card" style="margin:0">
        <div class="ad-card__body">
            <?php if (!empty($post['featured_image'])): ?>
                <img src="<?= e(img_url($post['featured_image'])) ?>" alt=""
                     style="width:100%;max-height:340px;object-fit:cover;border-radius:12px;margin-bottom:18px">
            <?php endif; ?>

            <h1 style="font-size:26px;font-weight:700;letter-spacing:-.4px;margin:0 0 10px">
                <?= e($post['title']) ?>
            </h1>

            <div class="ad-cellflex__meta" style="margin-bottom:18px">
                <?= e($post['author_name'] ?: 'Unknown author') ?>
                <?php if ($post['category_name'] !== null): ?>
                    &middot; <?= e($post['category_name']) ?>
                <?php endif; ?>
                <?php if (!empty($post['published_at'])): ?>
                    &middot; <?= e(format_datetime($post['published_at'])) ?>
                <?php endif; ?>
                &middot; <?= number_format((int) $post['views']) ?> views
            </div>

            <?php if (!empty($post['excerpt'])): ?>
                <p style="font-size:15px;color:var(--ad-muted);border-left:3px solid var(--ad-primary);
                          padding-left:12px;margin:0 0 18px">
                    <?= e($post['excerpt']) ?>
                </p>
            <?php endif; ?>

            <?php if (trim((string) $post['content']) !== ''): ?>
                <div class="sik-prose">
                    <?php
                    // Re-sanitise at render time, exactly as the storefront does in
                    // content_prose(). A stored row is not proof it was ever cleaned:
                    // seed data, an import, a direct DB write or a row written before
                    // the sanitiser was tightened all reach this column without
                    // passing through create.php/edit.php. This is the admin origin
                    // with a privileged session and a live CSRF token in the page, so
                    // it is the worst possible place to trust the database.
                    echo sanitize_html((string) $post['content']);
                    ?>
                </div>
            <?php else: ?>
                <p class="ad-muted">This post has no content yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;gap:16px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Details</div></div>
            <div class="ad-card__body" style="display:grid;gap:12px;font-size:13.5px">
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Status</span>
                    <?= admin_state_badge((string) $post['status']) ?>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Featured</span>
                    <span><?= (int) $post['is_featured'] === 1 ? 'Yes' : 'No' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Category</span>
                    <span><?= e($post['category_name'] ?? 'Uncategorised') ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Slug</span>
                    <span class="ad-mono"><?= e($post['slug']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Published</span>
                    <span><?= !empty($post['published_at']) ? e(format_datetime($post['published_at'])) : 'Not scheduled' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Created</span>
                    <span><?= e(format_datetime($post['created_at'])) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <span class="ad-muted">Last edited</span>
                    <span><?= e(time_ago($post['updated_at'])) ?></span>
                </div>
                <?php if (!empty($post['admin_name'])): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px">
                        <span class="ad-muted">Written by</span>
                        <span><?= e($post['admin_name']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head"><div class="ad-card__title">Search engine listing</div></div>
            <div class="ad-card__body" style="display:grid;gap:8px;font-size:13.5px">
                <div>
                    <div class="ad-muted" style="font-size:12px">Meta title</div>
                    <div><?= e($post['meta_title'] ?: $post['title']) ?></div>
                </div>
                <?php $metaDescription = (string) ($post['meta_description'] ?: str_limit((string) $post['excerpt'], 155)); ?>
                <div>
                    <div class="ad-muted" style="font-size:12px">Meta description</div>
                    <div><?= $metaDescription !== '' ? e($metaDescription) : '<span class="ad-muted">&mdash;</span>' ?></div>
                </div>
                <div>
                    <div class="ad-muted" style="font-size:12px">URL</div>
                    <div class="ad-mono"><?= e(blog_url((string) $post['slug'])) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
