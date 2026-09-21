<?php
/**
 * ShopInnKart Admin - Delete a menu item.
 *
 * The fk_menuitem_parent constraint cascades, so deleting a parent takes its
 * whole subtree with it. The confirm text on the list says how many rows that
 * is; here we just count them for the audit entry.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once __DIR__ . '/_meta.php';

$id = input_int('id');
$item = $id > 0
    ? Database::fetch('SELECT * FROM `menu_items` WHERE `id` = :id', ['id' => $id])
    : null;

if ($item === null) {
    flash('error', 'That menu item no longer exists.');
    redirect(admin_url('menus/'));
}

$menuId   = (int) $item['menu_id'];
$location = (string) Database::fetchColumn('SELECT `location` FROM `menus` WHERE `id` = :id', ['id' => $menuId]);
$listUrl  = admin_url('menus/?location=' . urlencode(isset(menu_locations()[$location]) ? $location : 'main'));

// Collect the subtree before the delete so the uploaded files that go with it
// can be removed from disk too: the promo image, and — since the Icon Manager
// can put one there — a custom icon. `icon` holds a set key for most rows, so
// only the ones carrying the `upload:` marker name a file.
$childrenOf = menu_children_map($menuId);
$doomedIds  = array_merge([$id], menu_descendant_ids($childrenOf, $id));

[$placeholders, $params] = Database::inPlaceholders($doomedIds, 'd');
$files = Database::fetchAll(
    'SELECT `mega_image`, `icon` FROM `menu_items` WHERE `id` IN (' . $placeholders . ')',
    $params
);
foreach ($files as $row) {
    if (!empty($row['mega_image'])) {
        delete_upload((string) $row['mega_image']);
    }
    if (icon_is_upload($row['icon'])) {
        delete_upload(icon_upload_path((string) $row['icon']));
    }
}

Database::delete('menu_items', '`id` = :id', ['id' => $id]);

$childCount = count($doomedIds) - 1;
log_activity('menu_item.deleted', 'menu_item', $id,
    'Deleted "' . $item['label'] . '" from the ' . $location . ' menu'
    . ($childCount > 0 ? ' along with ' . $childCount . ' child item(s)' : ''));
admin_after_write();

flash('success', 'Menu item "' . $item['label'] . '" deleted'
    . ($childCount > 0 ? ' with ' . $childCount . ' child item(s).' : '.'));
redirect($listUrl);
