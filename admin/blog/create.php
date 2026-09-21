<?php
/**
 * ShopInnKart Admin - Write a blog post.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('blog.create');

// The SEO card renders the shared editor; seo_editor_input() reads the nine
// fields it posts, already trimmed, null-when-blank and validated.
require_once ADMIN_PATH . '/includes/seo-editor.php';

$post = [
    'category_id'      => '',
    'title'            => '',
    'slug'             => '',
    'excerpt'          => '',
    'content'          => '',
    'featured_image'   => null,
    'author_name'      => (string) $admin['name'],
    'is_featured'      => 0,
    'status'           => 'draft',
    'published_at'     => null,
    'meta_title'       => '',
    'meta_description' => '',
];
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
        $slug  = unique_slug('blog_posts', slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['title']));
        $image = admin_handle_image('featured_image', 'blog');

        // A published post always carries a date, otherwise it sorts to the
        // bottom of the blog listing and never shows a byline date.
        $publishedAt = $submitted['published_at'] !== '' && strtotime($submitted['published_at']) !== false
            ? date('Y-m-d H:i:s', (int) strtotime($submitted['published_at']))
            : null;
        if ($publishedAt === null && $submitted['status'] === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $id = Database::insert('blog_posts', [
            'category_id'    => $submitted['category_id'] > 0 ? $submitted['category_id'] : null,
            'admin_id'       => (int) $admin['id'],
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
        ]);

        log_activity('blog_post.created', 'blog_post', $id, 'Created post "' . $submitted['title'] . '"');
        admin_after_write();

        flash('success', 'Post "' . $submitted['title'] . '" created.');
        redirect(admin_url('blog/edit.php?id=' . $id));
    }
}

$categoryOptions = Database::fetchPairs(
    "SELECT `id`, `name` FROM `blog_categories` WHERE `status` = 'active' ORDER BY `sort_order`, `name`"
);

$isEdit       = false;
$pageTitle    = 'Write Post';
$pageSubtitle = 'Posts are published at /blog/<slug>.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Blog',      'url' => admin_url('blog/')],
    ['label' => 'Write Post'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
