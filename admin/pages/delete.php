<?php
/**
 * ShopInnKart Admin - Delete a CMS page.
 *
 * System pages are refused: about.php, contact.php, the policy pages and faq.php
 * are fixed routes that look their copy up by slug, so removing the row would
 * leave those URLs rendering an empty shell.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('pages.delete');

$id = input_int('id');
$cmsPage = $id > 0
    ? Database::fetch('SELECT `id`, `title`, `slug`, `is_system`, `banner_image` FROM `pages` WHERE `id` = :id', ['id' => $id])
    : null;

if ($cmsPage === null) {
    flash('error', 'That page no longer exists.');
    redirect(admin_url('pages/'));
}

if ((int) $cmsPage['is_system'] === 1) {
    flash('error', '"' . $cmsPage['title'] . '" is a system page. The storefront route /page/'
        . $cmsPage['slug'] . ' reads its content from this row, so it cannot be deleted. '
        . 'Set it to Inactive instead if you want it off the site.');
    redirect(admin_url('pages/edit.php?id=' . $id));
}

delete_upload($cmsPage['banner_image']);
Database::delete('pages', '`id` = :id', ['id' => $id]);
// The record is gone, so its extended SEO row has nothing to describe.
seo_entity_meta_delete('page', $id);


log_activity('page.deleted', 'page', $id, 'Deleted page "' . $cmsPage['title'] . '" (/page/' . $cmsPage['slug'] . ')');
admin_after_write();

flash('success', 'Page "' . $cmsPage['title'] . '" deleted.');
redirect(admin_url('pages/'));
