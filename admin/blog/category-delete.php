<?php
/**
 * ShopInnKart Admin - Delete a blog category.
 *
 * fk_post_category is ON DELETE SET NULL, so posts survive the delete as
 * uncategorised rather than disappearing with the bucket.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('blog.delete');

$id = input_int('id');
$category = $id > 0
    ? Database::fetch('SELECT `id`, `name` FROM `blog_categories` WHERE `id` = :id', ['id' => $id])
    : null;

if ($category === null) {
    flash('error', 'That category no longer exists.');
    redirect(admin_url('blog/categories.php'));
}

$postCount = Database::count('blog_posts', '`category_id` = :id', ['id' => $id]);

Database::delete('blog_categories', '`id` = :id', ['id' => $id]);

log_activity('blog_category.deleted', 'blog_category', $id,
    'Deleted blog category "' . $category['name'] . '"'
        . ($postCount > 0 ? ' — ' . $postCount . ' post(s) left uncategorised' : ''));
admin_after_write();

flash('success', $postCount > 0
    ? 'Category "' . $category['name'] . '" deleted. ' . $postCount . ' post'
        . ($postCount === 1 ? ' is' : 's are') . ' now uncategorised.'
    : 'Category "' . $category['name'] . '" deleted.');
redirect(admin_url('blog/categories.php'));
