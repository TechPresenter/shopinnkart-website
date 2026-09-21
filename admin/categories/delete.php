<?php
/**
 * ShopInnKart Admin - Delete a category.
 *
 * The foreign keys would quietly orphan children and unassign products
 * (both are ON DELETE SET NULL), so this refuses instead and says exactly
 * what is in the way.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('categories.delete');

$id = input_int('id');
$category = $id > 0
    ? Database::fetch('SELECT `id`, `name`, `image`, `banner` FROM `categories` WHERE `id` = :id', ['id' => $id])
    : null;

if ($category === null) {
    flash('error', 'That category no longer exists.');
    redirect(admin_url('categories/'));
}

$childCount   = Database::count('categories', '`parent_id` = :id', ['id' => $id]);
$productCount = Database::count('products', '`category_id` = :id', ['id' => $id]);

if ($childCount > 0 || $productCount > 0) {
    $blockers = [];
    if ($childCount > 0) {
        $blockers[] = $childCount . ' subcategor' . ($childCount === 1 ? 'y' : 'ies');
    }
    if ($productCount > 0) {
        $blockers[] = $productCount . ' product' . ($productCount === 1 ? '' : 's');
    }

    flash('error', '"' . $category['name'] . '" still has ' . implode(' and ', $blockers)
        . '. Move or remove them first, then delete the category.');
    redirect(admin_url('categories/'));
}

delete_upload($category['image']);
delete_upload($category['banner']);
Database::delete('categories', '`id` = :id', ['id' => $id]);

log_activity('category.deleted', 'category', $id, 'Deleted category "' . $category['name'] . '"');
admin_after_write();

flash('success', 'Category "' . $category['name'] . '" deleted.');
redirect(admin_url('categories/'));
