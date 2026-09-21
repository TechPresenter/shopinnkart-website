<?php
/**
 * ShopInnKart Admin - Edit a blog post.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('blog.edit');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$id = input_int('id');
$post = $id > 0
    ? Database::fetch('SELECT * FROM `blog_posts` WHERE `id` = :id', ['id' => $id])
    : null;

if ($post === null) {
    flash('error', 'That post no longer exists.');
    redirect(admin_url('blog/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $seo       = seo_editor_input();
    $submitted = [
        'category_id'  => input_int('category_id', 0),
        'title'        => (string) input('title', ''),
        'slug'         => (string) input('slug', ''),
        'excerpt'      => trim((string) ($_POST['excerpt'] ?? '')),
        'content'      => sanitize_html((string) ($_POST['content'] ?? '')),
        'author_name'  => (string) input('author_name', ''),
        'is_featured'  => input_bool('is_featured') ? 1 : 0,
        'status'       => (string) input('status', 'draft'),
        'published_at' => (string) input('published_at', ''),
        ...$seo,
    ];
    $post = array_merge($post, $submitted);

    $v = new Validator($submitted, ['title' => 'Post title', 'category_id' => 'Category']);
    $v->required('title')->max('title', 255)
      ->max('slug', 280)
      ->max('excerpt', 500)
      ->max('author_name', 150)
      ->max('meta_title', 255)
      ->in('status', ['draft', 'published'])
      ->date('published_at');
    if ($submitted['category_id'] > 0) {
        $v->exists('category_id', 'blog_categories');
    }

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $slug = unique_slug(
            'blog_posts',
            slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['title']),
            $id
        );

        $image = $post['featured_image'];
        if ((string) input('remove_featured_image', '0') === '1' && empty($_FILES['featured_image']['name'])) {
            delete_upload($image);
            $image = null;
        }
        $image = admin_handle_image('featured_image', 'blog', $image);

        // A published post always carries a date, otherwise it sorts to the
        // bottom of the blog listing and never shows a byline date.
        $publishedAt = $submitted['published_at'] !== '' && strtotime($submitted['published_at']) !== false
            ? date('Y-m-d H:i:s', (int) strtotime($submitted['published_at']))
            : null;
        if ($publishedAt === null && $submitted['status'] === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        Database::update('blog_posts', [
            'category_id'    => $submitted['category_id'] > 0 ? $submitted['category_id'] : null,
            'title'          => $submitted['title'],
            'slug'           => $slug,
            'excerpt'        => $submitted['excerpt'] !== '' ? $submitted['excerpt'] : null,
            'content'        => $submitted['content'] !== '' ? $submitted['content'] : null,
            'featured_image' => $image,
            'author_name'    => $submitted['author_name'] !== '' ? $submitted['author_name'] : (string) $admin['name'],
            'is_featured'    => $submitted['is_featured'],
            'status'         => $submitted['status'],
            'published_at'   => $publishedAt,
            ...$seo,
        ], '`id` = :id', ['id' => $id]);

        log_activity('blog_post.updated', 'blog_post', $id, 'Updated post "' . $submitted['title'] . '"');
        admin_after_write();

        flash('success', 'Post "' . $submitted['title'] . '" saved.');
        redirect(admin_url('blog/edit.php?id=' . $id));
    }
}

$categoryOptions = Database::fetchPairs(
    "SELECT `id`, `name` FROM `blog_categories` WHERE `status` = 'active' ORDER BY `sort_order`, `name`"
);
// An inactive category already on the post must stay selectable, otherwise
// saving anything else would silently move the post to Uncategorised.
if (!empty($post['category_id']) && !isset($categoryOptions[(int) $post['category_id']])) {
    $orphan = Database::fetchColumn('SELECT `name` FROM `blog_categories` WHERE `id` = :id',
        ['id' => (int) $post['category_id']]);
    if ($orphan !== null) {
        $categoryOptions[(int) $post['category_id']] = $orphan . ' (inactive)';
    }
}

$isEdit       = true;
$pageTitle    = 'Edit Post';
$pageSubtitle = '/blog/' . $post['slug'] . ' · ' . number_format((int) $post['views']) . ' views';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Blog',      'url' => admin_url('blog/')],
    ['label' => str_limit((string) $post['title'], 50)],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('blog/view.php?id=' . $id)) . '">'
    . icon('eye', 'w-4 h-4') . ' Preview</a>'
    . '<a class="ad-btn" href="' . e(blog_url((string) $post['slug'])) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

if (admin_can('blog.delete')) {
    $confirm = 'Delete "' . $post['title'] . '"? This cannot be undone.';
    $pageActions .= '<form method="post" action="' . e(admin_url('blog/delete.php')) . '" class="ad-inline-form"'
        . ' onsubmit="return confirm(' . e_attr((string) json_encode($confirm)) . ')">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
