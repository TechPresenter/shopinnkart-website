<?php
/**
 * ShopInnKart - One mega panel.
 *
 * Extracted out of navbar.php so the Menu Builder's "Preview panel" screen can
 * show the panel the storefront actually renders instead of a drawing of it. It
 * is the same file, the same classes and the same stylesheet in both places, so
 * a preview that looks right cannot be a preview of something else.
 *
 * Expects, at include time:
 *   $megaItem     the top-level menu item, with 'children' already filtered
 *                 by menu_surface_items() for the desktop surface
 *   $megaPanelId  the id its trigger points at with aria-controls
 *   $megaEndClass ' sik-mega--end' when the panel opens from the right, or ''
 *   $megaExtra    extra classes for the wrapper (the preview pins it open)
 *
 * Every local is prefixed `$mega` because this is included at the file scope of
 * whichever template is rendering, exactly like navbar.php itself.
 */

declare(strict_types=1);

/** @var array $megaItem */
$megaEndClass = $megaEndClass ?? '';
$megaExtra    = $megaExtra ?? '';
$megaPanelId  = $megaPanelId ?? 'sikMegaPanel';

/**
 * Two shapes come out of the Menu Builder: children that have children of their
 * own, and a flat list. Both render as ONE vertical column — a child with its
 * own children becomes a titled group with its subcategories beneath it, a flat
 * list is simply the panel's rows. `mega_columns` is deliberately not read:
 * there is no second column to put anything in at any width.
 *
 * The rows are already the Mega Panel Builder's answer to "which children, in
 * what order": build_menu() drops anything set to Inactive and sorts by
 * sort_order, and menu_surface_items() has applied the device and audience
 * rules. There is no second notion of "hidden in the panel" to keep in step.
 */
$megaChildren = $megaItem['children'] ?? [];

$megaNested = false;
foreach ($megaChildren as $megaChild) {
    if (!empty($megaChild['children'])) {
        $megaNested = true;
        break;
    }
}

/**
 * Does any item in this list carry a glyph on this surface?
 *
 * The leading glyph slot is reserved for a whole list or for none of it: if only
 * some rows have an icon the labels have to stay on one alignment, and if no row
 * has one the list should not carry an empty 16px gutter. Asked of
 * menu_item_glyph(), not of the `icon` column, so a row whose icon is switched
 * off for this surface does not reserve a gutter nothing will fill.
 */
$megaListHasIcons = static function (array $megaRows): bool {
    foreach ($megaRows as $megaRow) {
        if (menu_item_has_glyph($megaRow, 'desktop')) {
            return true;
        }
    }
    return false;
};

/**
 * One row inside the panel: glyph, label, badge.
 *
 * The badge sits before or after the label as `badge_position` asks. Both
 * orders are laid out by the same flex row, so "before" is a DOM order change
 * and nothing else — no second stylesheet rule, and the reading order a screen
 * reader announces matches what is on screen either way.
 */
$megaRenderLink = static function (array $megaLink, string $megaClass = 'sik-mega__link', bool $megaSlot = false): void {
    $megaGlyph = menu_item_glyph($megaLink, 'desktop', 'sik-mega__glyph-svg');
    $megaChip  = menu_badge_chip($megaLink);
    ?>
    <a class="<?= e_attr($megaClass) ?>" href="<?= e($megaLink['href']) ?>"
       <?= !empty($megaLink['open_new_tab']) ? 'target="_blank" rel="noopener"' : '' ?>>
        <?php if ($megaSlot || $megaGlyph !== ''): ?>
            <span class="sik-mega__glyph" aria-hidden="true"><?= $megaGlyph ?></span>
        <?php endif; ?>
        <?= menu_badge_first($megaLink) ? $megaChip : '' ?>
        <span class="sik-mega__link-text"><?= e($megaLink['label']) ?></span>
        <?= menu_badge_first($megaLink) ? '' : $megaChip ?>
    </a>
    <?php
};

$megaTopSlot  = $megaListHasIcons($megaChildren);
$megaHeadIcon = menu_item_glyph($megaItem, 'desktop', 'sik-mega__cover-svg');
$megaPromo    = mega_panel_promo($megaItem);
?>
<div class="sik-mega sik-mega--stack<?= $megaEndClass ?><?= $megaExtra !== '' ? ' ' . e_attr($megaExtra) : '' ?>"
     id="<?= e_attr($megaPanelId) ?>" data-mega>
    <?php
    // The panel's own header: it names the category the rows belong to and is
    // the way to the category page itself. The thumbnail is the picture the
    // admin uploaded against that category — menu items carry no artwork of
    // their own — and img_url() degrades a missing file to the placeholder
    // rather than a broken image.
    ?>
    <a class="sik-mega__head" href="<?= e($megaItem['href']) ?>">
        <?php if (!empty($megaItem['image'])): ?>
            <img class="sik-mega__cover" src="<?= e(img_url((string) $megaItem['image'])) ?>"
                 alt="" loading="lazy" width="36" height="36">
        <?php elseif ($megaHeadIcon !== ''): ?>
            <span class="sik-mega__cover" aria-hidden="true"><?= $megaHeadIcon ?></span>
        <?php endif; ?>
        <span class="sik-mega__head-body">
            <span class="sik-mega__eyebrow">Shop all</span>
            <span class="sik-mega__head-text"><?= e($megaItem['label']) ?></span>
        </span>
        <span class="sik-mega__head-go" aria-hidden="true"><?= icon('arrow-right', 'sik-mega__glyph-svg') ?></span>
    </a>

    <?php // Long lists scroll in here, never against the header or the promo. ?>
    <div class="sik-mega__scroll" data-mega-scroll>
        <ul class="sik-mega__list">
            <?php if ($megaNested): ?>
                <?php foreach ($megaChildren as $megaCol): ?>
                    <?php $megaSubSlot = $megaListHasIcons($megaCol['children'] ?? []); ?>
                    <li class="sik-mega__group">
                        <?php $megaRenderLink($megaCol, 'sik-mega__link sik-mega__link--parent', $megaTopSlot); ?>
                        <?php if (!empty($megaCol['children'])): ?>
                            <ul class="sik-mega__sub">
                                <?php foreach ($megaCol['children'] as $megaSub): ?>
                                    <li><?php $megaRenderLink($megaSub, 'sik-mega__link sik-mega__link--sub', $megaSubSlot); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($megaChildren as $megaChild): ?>
                    <li><?php $megaRenderLink($megaChild, 'sik-mega__link', $megaTopSlot); ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <?php if ($megaPromo !== null): ?>
        <?php
        /**
         * The promo tile. It sits OUTSIDE .sik-mega__scroll on purpose: a long
         * category list scrolls past it, and a banner that scrolls out of view
         * is a banner nobody sees. It is also the only part of the panel an
         * operator can put a picture in, so the tile carries no text of its own
         * — whatever the image says, it says.
         *
         * Behind mega_promo, so the two columns that have stored values since
         * before the single-column refactor stay dormant until they are asked
         * for.
         */
        ?>
        <a class="sik-mega__promo" href="<?= e($megaPromo['href']) ?>">
            <img class="sik-mega__promo-img" src="<?= e(img_url($megaPromo['image'])) ?>"
                 alt="<?= e_attr($megaPromo['label']) ?> offer" loading="lazy" width="280" height="150">
        </a>
    <?php endif; ?>
</div>
<?php
unset(
    $megaChildren, $megaChild, $megaCol, $megaSub, $megaRow, $megaNested,
    $megaTopSlot, $megaSubSlot, $megaHeadIcon, $megaGlyph, $megaPromo,
    $megaRenderLink, $megaListHasIcons, $megaExtra, $megaEndClass
);
