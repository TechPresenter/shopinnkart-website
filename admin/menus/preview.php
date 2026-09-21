<?php
/**
 * ShopInnKart Admin - Live preview of a menu row.
 *
 * Answers the Menu Builder's Preview card with the markup the storefront would
 * render for the values currently in the form. Nothing is read from or written
 * to the database: the posted fields are assembled into the same shape
 * build_menu() produces and handed to the same two renderers the header uses.
 *
 * It is a round trip rather than a JavaScript re-implementation on purpose.
 * menu_badge_chip() darkens an admin's colour until 11px text in it clears
 * 4.5:1, and a second copy of that calculation in the browser is a second copy
 * that can disagree with the first — the preview would then be confidently
 * showing a chip the store does not draw. One renderer, one answer.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once __DIR__ . '/_meta.php';

/**
 * Only the presentation fields are read. The preview never resolves a link, so
 * link_type / reference_id / url are irrelevant to it, and an id is not accepted
 * at all — there is nothing here that could load one row and preview another.
 */
$icon = (string) request_input('icon', '');
if ($icon === '__upload__') {
    // The "keep the custom icon" radio. The path itself rides along in the
    // hidden mirror field so the preview can draw it without a lookup.
    $icon = (string) request_input('icon_current', '');
}

$item = [
    'label'            => trim((string) request_input('label', '')),
    'icon'             => $icon,
    'icon_visibility'  => (string) request_input('icon_visibility', 'all'),
    'badge'            => (string) request_input('badge', ''),
    'badge_color'      => (string) request_input('badge_color', ''),
    'badge_style'      => (string) request_input('badge_style', 'solid'),
    'badge_text_color' => (string) request_input('badge_text_color', ''),
    'badge_position'   => (string) request_input('badge_position', 'after'),
    'badge_animation'  => (string) request_input('badge_animation', 'none'),
];

// A row with no label still has to preview as something, or the card empties
// out the moment an admin clears the field to retype it.
$label = $item['label'] !== '' ? $item['label'] : 'Menu item';

// Anything outside the enums is normalised rather than rejected: this endpoint
// draws a picture, and refusing to draw one is a worse answer than drawing the
// default. item-save.php is where a bad value is actually stopped.
foreach ([
    'icon_visibility' => menu_icon_visibility(),
    'badge_style'     => menu_badge_styles(),
    'badge_position'  => menu_badge_positions(),
    'badge_animation' => menu_badge_animations(),
] as $field => $allowed) {
    if (!isset($allowed[$item[$field]])) {
        $item[$field] = (string) array_key_first($allowed);
    }
}

$chipBefore = menu_badge_first($item);

/**
 * The two rows are the two SURFACES, not two sizes of the same one.
 *
 * That is the whole reason the card is worth having: `icon_visibility` and the
 * device rules answer differently on each, and previewing both on the desktop
 * surface would show an icon set to "mobile only" as simply missing, twice.
 * So the bar is rendered as navbar.php renders it and the drawer row as
 * footer.php does — the same classes, the same resolvers, the same surface
 * argument.
 */
$navChip  = menu_badge_chip($item, 'sik-nav__badge');
$navGlyph = menu_item_glyph($item, 'desktop', 'sik-nav__icon');
$nav = '<span data-preview-glyph>' . $navGlyph . '</span>'
    . ($chipBefore ? $navChip : '')
    . '<span class="sik-nav__text" data-preview-label>' . e($label) . '</span>'
    . ($chipBefore ? '' : $navChip);

$drawerChip = menu_badge_chip(
    $item,
    'sik-nav__badge sik-nav__badge--drawer' . ($chipBefore ? ' sik-nav__badge--lead' : '')
);
$drawerGlyph = menu_item_glyph($item, 'mobile', 'w-4 h-4');
$drawer = ($drawerGlyph === '' ? '<span data-preview-glyph></span>'
        : '<span class="sik-mmenu__glyph" aria-hidden="true" data-preview-glyph>' . $drawerGlyph . '</span>')
    . '<span data-preview-label>'
    . ($chipBefore ? $drawerChip : '')
    . e($label)
    . ($chipBefore ? '' : $drawerChip)
    . '</span>'
    . icon('chevron-right', 'w-4 h-4');

/**
 * What the icon settings are doing, in words.
 *
 * "Hidden" and "no icon chosen" look identical in a preview, so the picture
 * alone cannot tell an admin which of the two they are looking at.
 */
$note = '';
if ($item['icon'] === '') {
    $note = 'No icon selected — the label sits on its own.';
} elseif (!icon_value_renderable($item['icon'])) {
    $note = 'That icon cannot be drawn: it is not in the set, or its uploaded file is missing.';
} else {
    $note = [
        'all'     => 'The icon shows in the header bar, its panels and the mobile drawer.',
        'desktop' => 'The icon shows on desktop only — the mobile drawer draws the label alone.',
        'mobile'  => 'The icon shows in the mobile drawer only — the desktop bar draws the label alone.',
        'none'    => 'The icon is switched off. It stays saved, but nothing on the store draws it.',
    ][$item['icon_visibility']];
}

json_success('', ['nav' => $nav, 'drawer' => $drawer, 'note' => $note]);
