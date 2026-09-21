<?php
/**
 * ShopInnKart Admin - Save a mega panel's contents.
 *
 * One post for the whole panel: every row's position, its show/hide switch, its
 * icon and its badge. The alternative — a save per row — is what makes an
 * operator reorder eight rows in eight round trips, and it is the same three
 * columns either way.
 *
 * Only rows that are genuinely children of the posted item are touched. The
 * arrays are keyed by id and come from the client, so ownership is established
 * from the database first and every posted key is then checked against that
 * list; an id that is not in it is ignored rather than trusted.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once __DIR__ . '/_meta.php';

$itemId = input_int('item');
$panel  = $itemId > 0
    ? Database::fetch('SELECT `id`, `label`, `menu_id` FROM `menu_items` WHERE `id` = :id', ['id' => $itemId])
    : null;

if ($panel === null) {
    flash('error', 'That menu item no longer exists.');
    redirect(admin_url('menus/'));
}

$backUrl = admin_url('menus/panel.php?item=' . $itemId);

// The authority on which rows this request may write.
$children = Database::fetchAll(
    'SELECT `id`, `label`, `icon`, `status` FROM `menu_items` WHERE `parent_id` = :id',
    ['id' => $itemId]
);
if ($children === []) {
    flash('warning', 'That panel has no rows to save.');
    redirect($backUrl);
}

/**
 * A posted `name[<id>]` map, with its keys intact.
 *
 * input_array() is a *list* reader — it ends in array_values(), which is right
 * for `product_ids[]` and wrong here, where the key IS the row id. Reading the
 * superglobal directly rather than widening the shared helper: every other
 * caller of input_array() wants the renumbering it does.
 *
 * Keys are cast to int and values filtered to scalars, so a crafted
 * `sort[foo][bar]` cannot reach the loop below as an array.
 *
 * @return array<int, scalar>
 */
$postedMap = static function (string $key): array {
    $raw = $_POST[$key] ?? null;
    if (!is_array($raw)) {
        return [];
    }

    $map = [];
    foreach ($raw as $id => $value) {
        if (is_scalar($value) && (string) (int) $id === (string) $id && (int) $id > 0) {
            $map[(int) $id] = $value;
        }
    }
    return $map;
};

$sort        = $postedMap('sort');
$showFlags   = $postedMap('show');
$present     = $postedMap('present');
$icons       = $postedMap('icon');
$badges      = $postedMap('badge');
$badgeColors = $postedMap('badge_color');

$iconKeys = icon_names();
$changed  = 0;
$dropped  = [];   // icon files no longer pointed at, removed once the save lands

foreach ($children as $child) {
    $id = (int) $child['id'];

    // A row that was not on the screen is left exactly as it is. Without this a
    // panel edited in one tab would blank the rows added in another.
    if (!isset($present[$id])) {
        continue;
    }

    $update = [];

    // --- position ---------------------------------------------------------
    $position = isset($sort[$id]) ? (int) $sort[$id] : null;
    if ($position !== null && $position >= 0 && $position <= 9999) {
        $update['sort_order'] = $position;
    }

    // --- show / hide ------------------------------------------------------
    // Not a separate "hidden in the panel" flag: it is the row's own Status,
    // the same switch the item editor sets. A second one would immediately
    // start disagreeing with the first.
    $update['status'] = isset($showFlags[$id]) ? 'active' : 'inactive';

    // --- icon -------------------------------------------------------------
    $currentIcon = (string) ($child['icon'] ?? '');
    $postedIcon  = isset($icons[$id]) ? (string) $icons[$id] : null;

    if ($postedIcon !== null) {
        if ($postedIcon === '__upload__') {
            // "Keep the custom upload." Honoured only when there is actually one
            // to keep — a stale form must not be able to invent the marker.
            if (icon_is_upload($currentIcon)) {
                $postedIcon = $currentIcon;
            } else {
                $postedIcon = '';
            }
        } elseif ($postedIcon !== '' && !in_array($postedIcon, $iconKeys, true)) {
            // Not a key this build offers. Leave the row's icon alone rather
            // than clearing it on the strength of a value we do not recognise.
            $postedIcon = $currentIcon;
        }

        if ($postedIcon !== $currentIcon) {
            $update['icon'] = $postedIcon === '' ? null : $postedIcon;
            // Moving off a custom icon takes its file with it, but only after
            // the row is written — same contract as item-save.php.
            if (icon_is_upload($currentIcon)) {
                $dropped[] = icon_upload_path($currentIcon);
            }
        }
    }

    // --- badge ------------------------------------------------------------
    if (isset($badges[$id])) {
        $text = trim((string) $badges[$id]);
        $update['badge'] = $text === '' ? null : mb_substr($text, 0, 30);

        // The colour input is type="color", which always posts a value even for
        // a row with no badge. Storing it anyway would leave a colour behind
        // every cleared badge; the pair is written together or not at all.
        if ($update['badge'] === null) {
            $update['badge_color'] = null;
        } elseif (isset($badgeColors[$id])) {
            $color = menu_badge_color((string) $badgeColors[$id]);
            $update['badge_color'] = $color === '' ? null : $color;
        }
    }

    if ($update === []) {
        continue;
    }

    Database::update('menu_items', $update, '`id` = :id', ['id' => $id]);
    $changed++;
}

foreach ($dropped as $path) {
    if ($path !== '') {
        delete_upload($path);
    }
}

log_activity('menu_item.panel_saved', 'menu_item', $itemId,
    'Saved the "' . $panel['label'] . '" mega panel (' . $changed . ' row(s) written)');
admin_after_write();

flash('success', $changed === 0
    ? 'Nothing changed in the "' . $panel['label'] . '" panel.'
    : 'Panel saved. ' . $changed . ' row' . ($changed === 1 ? '' : 's') . ' updated.');
redirect($backUrl);
