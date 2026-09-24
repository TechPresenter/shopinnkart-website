<?php
/**
 * ShopInnKart Admin - Menu writes.
 *
 * Three intents share one endpoint because they all write the same menu:
 *   intent=menu   create or rename the menus row for a location
 *   intent=move   swap an item with the sibling above or below it
 *   intent=item   create or update a menu item (the default)
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once __DIR__ . '/_meta.php';

$intent   = (string) input('intent', 'item');
$posted   = (string) input('location', 'main');
$location = isset(menu_locations()[$posted]) ? $posted : 'main';
$listUrl  = admin_url('menus/?location=' . urlencode($location));

// ===========================================================================
//  The menus row itself
// ===========================================================================
if ($intent === 'menu') {
    $name   = trim((string) input('name', ''));
    $status = (string) input('status', 'active');

    if ($name === '' || mb_strlen($name) > 100) {
        flash('error', 'The menu name is required and must be 100 characters or fewer.');
        redirect($listUrl);
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $menu = Database::fetch('SELECT `id`, `name` FROM `menus` WHERE `location` = :loc LIMIT 1', ['loc' => $location]);

    if ($menu === null) {
        $menuId = Database::insert('menus', ['name' => $name, 'location' => $location, 'status' => $status]);
        log_activity('menu.created', 'menu', $menuId, 'Created the "' . $name . '" menu (' . $location . ')');
        flash('success', 'Menu created. Add its first item below.');
    } else {
        $menuId = (int) $menu['id'];
        Database::update('menus', ['name' => $name, 'status' => $status], '`id` = :id', ['id' => $menuId]);
        log_activity('menu.updated', 'menu', $menuId, 'Updated the "' . $name . '" menu (' . $location . ')');
        flash('success', 'Menu saved.');
    }

    admin_after_write();
    redirect($listUrl);
}

// ===========================================================================
//  Reorder within a sibling group
// ===========================================================================
if ($intent === 'move') {
    $id   = input_int('id');
    $move = strtolower(trim((string) input('move', '')));

    $item = $id > 0
        ? Database::fetch('SELECT `id`, `menu_id`, `parent_id`, `label` FROM `menu_items` WHERE `id` = :id', ['id' => $id])
        : null;

    if ($item === null || !in_array($move, ['up', 'down'], true)) {
        flash('error', 'That menu item no longer exists.');
        redirect($listUrl);
    }

    // The arrow buttons only post the item, so the zone to return to comes
    // from the item's own menu rather than from the request.
    $itemLocation = (string) Database::fetchColumn(
        'SELECT `location` FROM `menus` WHERE `id` = :id',
        ['id' => (int) $item['menu_id']]
    );
    if (isset(menu_locations()[$itemLocation])) {
        $location = $itemLocation;
        $listUrl = admin_url('menus/?location=' . urlencode($location));
    }

    $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];
    $siblings = Database::fetchColumnAll(
        'SELECT `id` FROM `menu_items`
         WHERE `menu_id` = :menu AND `parent_id` ' . ($parentId === null ? 'IS NULL' : '= :parent') . '
         ORDER BY `sort_order` ASC, `id` ASC',
        $parentId === null
            ? ['menu' => (int) $item['menu_id']]
            : ['menu' => (int) $item['menu_id'], 'parent' => $parentId]
    );
    $siblings = array_map('intval', $siblings);

    $position = array_search($id, $siblings, true);
    $target = $position === false ? false : $position + ($move === 'up' ? -1 : 1);

    if ($target === false || $target < 0 || $target >= count($siblings)) {
        flash('warning', '"' . $item['label'] . '" is already ' . ($move === 'up' ? 'first' : 'last') . ' in its group.');
        redirect($listUrl);
    }

    // Renumber the whole group after the swap: seeded rows often share a
    // sort_order, and swapping two identical numbers would move nothing.
    [$siblings[$position], $siblings[$target]] = [$siblings[$target], $siblings[$position]];

    Database::transaction(static function () use ($siblings): void {
        foreach ($siblings as $index => $siblingId) {
            Database::update('menu_items', ['sort_order' => $index + 1], '`id` = :id', ['id' => $siblingId]);
        }
    });

    log_activity('menu_item.reordered', 'menu_item', $id, 'Moved "' . $item['label'] . '" ' . $move);
    admin_after_write();

    flash('success', 'Menu order updated.');
    redirect($listUrl);
}

// ===========================================================================
//  Create / update a menu item
// ===========================================================================
$menu = Database::fetch('SELECT `id` FROM `menus` WHERE `location` = :loc LIMIT 1', ['loc' => $location]);
if ($menu === null) {
    flash('error', 'Create the menu before adding items to it.');
    redirect($listUrl);
}
$menuId = (int) $menu['id'];

$id = input_int('id');
$existing = $id > 0
    ? Database::fetch('SELECT * FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu', ['id' => $id, 'menu' => $menuId])
    : null;

if ($id > 0 && $existing === null) {
    flash('error', 'That menu item no longer exists in this menu.');
    redirect($listUrl);
}

$linkType    = (string) input('link_type', 'custom');
$parentRaw   = trim((string) input('parent_id', ''));
$productIds  = array_values(array_filter(array_map('intval', input_array('product_ids'))));

// ---------------------------------------------------------------------------
//  The icon: a set key, an uploaded file, or nothing
//
//  One column holds both kinds, told apart by the `upload:` marker — a second
//  column would mean every reader having to decide which of the two wins, and
//  they would disagree. Resolved before validation because a bad upload has to
//  be reported like any other field error rather than silently dropped.
// ---------------------------------------------------------------------------
$iconChoice    = (string) input('icon', '');
$currentIcon   = (string) ($existing['icon'] ?? '');
$iconWasUpload = icon_is_upload($currentIcon);

$iconError  = null;   // reported through the validator, like any other field
$iconFresh  = null;   // a file written this request, removed again if the save fails
$iconStale  = null;   // the previous upload, removed only once the save lands
$iconValue  = null;

if (!empty($_FILES['icon_file']['name'])) {
    // A custom glyph is only ever a small flat mark. Constraining the types
    // here rather than accepting everything upload_image() does keeps a 5MB
    // photograph out of a 16px slot.
    $iconExt = strtolower(pathinfo((string) $_FILES['icon_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($iconExt, ['svg', 'png', 'webp'], true)) {
        $iconError = 'A custom icon must be an SVG, PNG or WebP file.';
    } else {
        $upload = upload_image($_FILES['icon_file'], 'menu-icons');
        if (!$upload['ok']) {
            $iconError = $upload['error'] ?? 'That icon could not be uploaded.';
        } else {
            // upload_image() has already rewritten an SVG from svg_sanitize()'s
            // allowlist, so what is on disk cannot carry script. It is still
            // drawn through <img> at render time — see menu_glyph().
            $iconFresh = $upload['path'];
            $iconValue = 'upload:' . $iconFresh;
        }
    }
}

if ($iconValue === null) {
    // __upload__ is the radio for "the custom icon this item already has". It
    // is the only value that is not either a set key or the empty string, and
    // it never reaches the database.
    $iconValue = ($iconChoice === '__upload__' && $iconWasUpload) ? $currentIcon
        : ($iconChoice === '__upload__' ? '' : $iconChoice);
}

// Whatever the item is moving TO, a custom icon it is moving off takes its file
// with it — but not until the row has actually been written. Deleting on the
// way in loses the picture when validation then rejects the label.
if ($iconWasUpload && $iconValue !== $currentIcon) {
    $iconStale = icon_upload_path($currentIcon);
}

$data = [
    'label'             => (string) input('label', ''),
    'link_type'         => $linkType,
    'reference_id'      => null,
    'url'               => null,
    'icon'              => $iconValue,
    'icon_visibility'   => (string) input('icon_visibility', 'all'),
    'badge'             => (string) input('badge', ''),
    'badge_color'       => (string) input('badge_color', ''),
    'badge_style'       => (string) input('badge_style', 'solid'),
    'badge_text_color'  => (string) input('badge_text_color', ''),
    'badge_position'    => (string) input('badge_position', 'after'),
    'badge_animation'   => (string) input('badge_animation', 'none'),
    'is_mega'           => input_bool('is_mega') ? 1 : 0,
    'mega_columns'      => input_int('mega_columns', 4),
    'mega_image_url'    => (string) input('mega_image_url', ''),
    'mega_products'     => implode(',', $productIds),
    'mega_promo'        => input_bool('mega_promo') ? 1 : 0,
    'open_new_tab'      => input_bool('open_new_tab') ? 1 : 0,
    'device_visibility' => (string) input('device_visibility', 'all'),
    'auth_visibility'   => (string) input('auth_visibility', 'all'),
    'parent_id'         => $parentRaw === '' ? null : (int) $parentRaw,
    'sort_order'        => input_int('sort_order', 0),
    'status'            => (string) input('status', 'active'),
];

// The target field depends on the link type, so only one of them is read.
$referenceRaw = trim((string) input('reference_id', ''));
$routeUrl     = (string) input('route_url', '');
$customUrl    = (string) input('url', '');

if (in_array($linkType, menu_reference_types(), true)) {
    $data['reference_id'] = $referenceRaw === '' ? null : (int) $referenceRaw;
} elseif ($linkType === 'route') {
    $data['url'] = $routeUrl;
} else {
    $data['url'] = $customUrl;
}

$v = new Validator(array_merge($data, ['route_url' => $routeUrl]), [
    'label'            => 'Label',
    'reference_id'     => 'Reference',
    'route_url'        => 'Store page',
    'url'              => 'URL',
    'sort_order'       => 'Sort order',
    'mega_columns'     => 'Mega columns',
    'icon_visibility'  => 'Icon visibility',
    'badge_style'      => 'Badge style',
    'badge_position'   => 'Badge position',
    'badge_animation'  => 'Badge animation',
    'badge_text_color' => 'Badge text colour',
    'mega_promo'       => 'Promo tile',
]);

$v->required('label')->max('label', 120)
  ->in('link_type', array_keys(menu_link_types()))
  ->in('device_visibility', array_keys(menu_device_visibility()))
  ->in('auth_visibility', array_keys(menu_auth_visibility()))
  ->in('icon_visibility', array_keys(menu_icon_visibility()))
  ->in('badge_style', array_keys(menu_badge_styles()))
  ->in('badge_position', array_keys(menu_badge_positions()))
  ->in('badge_animation', array_keys(menu_badge_animations()))
  ->in('status', ['active', 'inactive'])
  ->max('badge', 30)
  ->max('badge_color', 20)
  ->max('badge_text_color', 20)
  ->max('mega_image_url', 255)
  ->between('mega_columns', 1, 6)
  ->between('sort_order', 0, 9999)
  ->rule(
      'icon',
      $iconError === null
        && ($data['icon'] === '' || icon_exists($data['icon']) || icon_is_upload($data['icon'])),
      $iconError ?? 'Pick an icon from the list, or upload one.'
  );

// Both colours land in a style attribute, so keep them to a colour literal.
foreach (['badge_color' => 'Badge colour', 'badge_text_color' => 'Text colour'] as $field => $name) {
    if ($data[$field] !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20})$/', $data[$field]) !== 1) {
        $v->rule($field, false, $name . ': use a hex colour like #ED1857, or a CSS colour name.');
    }
}

// A promo tile with no picture behind it renders nothing, so the switch would
// read as broken. Say so at the point it is turned on instead.
if ($data['mega_promo'] === 1
    && empty($_FILES['mega_image']['name'])
    && trim((string) ($existing['mega_image'] ?? '')) === '') {
    $v->rule('mega_promo', false, 'Upload a promo image before switching the promo tile on.');
}

if (in_array($linkType, menu_reference_types(), true)) {
    $lists = menu_reference_lists();
    $v->rule(
        'reference_id',
        $data['reference_id'] !== null && isset($lists[$linkType][$data['reference_id']]),
        'Choose which ' . $linkType . ' this item links to.'
    );
} elseif ($linkType === 'route') {
    $v->rule('route_url', isset(menu_routes()[$routeUrl]), 'Choose a store page.');
} else {
    $v->required('url')->max('url', 255);
}

// An item cannot sit inside itself or inside one of its own descendants.
if ($data['parent_id'] !== null) {
    $parent = Database::fetch(
        'SELECT `id` FROM `menu_items` WHERE `id` = :id AND `menu_id` = :menu',
        ['id' => $data['parent_id'], 'menu' => $menuId]
    );
    if ($parent === null) {
        $v->rule('parent_id', false, 'That parent item is not in this menu.');
    } elseif ($id > 0) {
        $childrenOf = menu_children_map($menuId);
        $blocked = array_merge([$id], menu_descendant_ids($childrenOf, $id));
        $v->rule('parent_id', !in_array($data['parent_id'], $blocked, true),
            'An item cannot be moved inside itself or one of its own children.');
    }
}

if ($v->fails()) {
    // Nothing was written, so the icon that was uploaded a moment ago belongs
    // to no row. Removing it here is what keeps /uploads/menu-icons in step
    // with the column: a rejected save leaves the item exactly as it was.
    if ($iconFresh !== null) {
        delete_upload($iconFresh);
    }

    flash_old($_POST);
    flash_errors($v->errors());
    flash('error', 'Please correct the highlighted fields.');
    redirect(admin_url('menus/?location=' . urlencode($location) . '&item=' . ($id > 0 ? $id : 'new')) . '#menuForm');
}

// ---------------------------------------------------------------------------
//  Persist
// ---------------------------------------------------------------------------
foreach (['icon', 'badge', 'badge_color', 'badge_text_color', 'mega_image_url', 'mega_products', 'url'] as $field) {
    if ($data[$field] !== null && trim((string) $data[$field]) === '') {
        $data[$field] = null;
    }
}
$data['mega_products'] = $data['mega_products'] !== null ? mb_substr((string) $data['mega_products'], 0, 255) : null;

$currentImage = $existing['mega_image'] ?? null;
if (input_bool('remove_mega_image')) {
    delete_upload($currentImage);
    $currentImage = null;
    // The tile has nothing left to draw, so the switch goes with the picture
    // rather than being left on, pointing at a file that is gone.
    $data['mega_promo'] = 0;
}
$data['mega_image'] = admin_handle_image('mega_image', 'menus', $currentImage);

if ($existing === null) {
    $data['menu_id'] = $menuId;

    // A brand new item goes to the end of its group unless a position was typed.
    if ($data['sort_order'] === 0) {
        $data['sort_order'] = 1 + (int) Database::fetchColumn(
            'SELECT COALESCE(MAX(`sort_order`), 0) FROM `menu_items`
             WHERE `menu_id` = :menu AND `parent_id` ' . ($data['parent_id'] === null ? 'IS NULL' : '= :parent'),
            $data['parent_id'] === null
                ? ['menu' => $menuId]
                : ['menu' => $menuId, 'parent' => $data['parent_id']]
        );
    }

    $id = Database::insert('menu_items', $data);
    log_activity('menu_item.created', 'menu_item', $id, 'Added "' . $data['label'] . '" to the ' . $location . ' menu');
    $message = 'Menu item "' . $data['label'] . '" added.';
} else {
    Database::update('menu_items', $data, '`id` = :id', ['id' => $id]);
    log_activity('menu_item.updated', 'menu_item', $id, 'Updated "' . $data['label'] . '" in the ' . $location . ' menu');
    $message = 'Menu item "' . $data['label'] . '" saved.';
}

// The row is written, so the icon file it no longer points at is now genuinely
// unreferenced. Only here — see the note where $iconStale is set.
if ($iconStale !== null && $iconStale !== '') {
    delete_upload($iconStale);
}

admin_after_write();

flash('success', $message);
redirect($listUrl);
