<?php
/**
 * ShopInnKart Admin - Delete a blog post.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('blog.delete');

$id = input_int('id');
$post = $id > 0
    ? Database::fetch('SELECT `id`, `title`, `featured_image` FROM `blog_posts` WHERE `id` = :id', ['id' => $id])
    : null;

if ($post === null) {
    flash('error', 'That post no longer exists.');
    redirect(admin_url('blog/'));
}

// The row owns its featured image, so the file goes with it.
delete_upload($post['featured_image']);
Database::delete('blog_posts', '`id` = :id', ['id' => $id]);

log_activity('blog_post.deleted', 'blog_post', $id, 'Deleted post "' . $post['title'] . '"');
admin_after_write();

flash('success', 'Post "' . $post['title'] . '" deleted.');
redirect(admin_url('blog/'));
