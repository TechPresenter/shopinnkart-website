<?php
/**
 * ShopInnKart - Primary navigation with mega menus.
 * Rendered inside includes/header.php. Expects $mainMenu from build_menu('main').
 */

declare(strict_types=1);

/** @var array $mainMenu */
$mainMenu = $mainMenu ?? build_menu('main');

/**
 * Drop items this visitor should not see before deciding what fits.
 *
 * This is the desktop surface — CSS reveals it from 1024px up — so that, and not
 * the User-Agent, is what "Desktop only" is measured against. It filters the
 * whole tree, because a child of a mega panel carries the same two rules as the
 * item that opens it and only the top level used to be checked.
 */
$navVisible = menu_surface_items($mainMenu, 'desktop');

/**
 * How many top-level items sit inline before the rest fold into "More".
 *
 * This was 6 when the nav shared a row with the logo, the search field and the
 * action cluster and had roughly 620px to work with. It now has a full-width
 * row of its own, so one more real destination reaches the shopper instead of
 * hiding behind a dropdown.
 *
 * 7 is a measured ceiling, not a guess: at the 1024px breakpoint the container
 * is narrowest and this menu's 7 items need ~911px of the ~961px available. 8
 * overflowed by 78px there and put a horizontal scrollbar on the whole page, so
 * raise this only against a measurement at 1024px, not on the look of a wide
 * monitor.
 */
// Six, not seven: the container is 1280px and the row also carries a solid
// browse button at one end and the deals pill at the other. The seventh item
// ran under the pill. Anything past this goes into the More list, which is
// what it is for.
const NAV_INLINE_LIMIT = 6;

$navInline   = array_slice($navVisible, 0, NAV_INLINE_LIMIT);
$navOverflow = array_slice($navVisible, NAV_INLINE_LIMIT);

// Every panel needs a stable id so its trigger can point at it with
// aria-controls — that association is what tells a screen reader the two belong
// together, and header.js uses it to find the panel it owns.
$navSeq = 0;

/**
 * Which side each panel opens from.
 *
 * A panel is one narrow column now, anchored to its own trigger rather than
 * stretched across the header, so a trigger near the end of the row would push
 * its panel past the viewport edge. Positioning is pure CSS — nothing measures
 * or repositions the panel at runtime — so the side is decided here, from where
 * the trigger sits in the row.
 *
 * The offsets are estimated rather than measured, the same way NAV_INLINE_LIMIT
 * above was reasoned about: an uppercase 13px semibold label runs ~9px per
 * character and everything else in the item is fixed chrome. Checked against the
 * live bar at 1280, "Home" estimates 80px against 80 measured and "Smartphones"
 * 161 against 160, and the estimate only has to be right to within ~150px: for
 * every trigger in the middle of the row *both* sides fit, so only the ones near
 * an edge depend on it at all.
 *
 * Counting by item index instead looks simpler and is wrong — on a four-item
 * menu it flips the third item, whose panel then hangs off the left of the
 * screen.
 */
$navPanelW = 368;   // the widest a panel gets: --sik-mega-w at 1440 and up.
$navRowW   = 976;   // container width at the 1024 breakpoint, the tightest case.

$navOffset = 0.0;
$navFlip   = [];
foreach ($navInline as $navIdx => $navMeasure) {
    // 18ch is where .sik-nav__text ellipsizes, so a longer label buys no width.
    $navChars = min(18, mb_strlen((string) $navMeasure['label']));
    $navWidth = 9.0 * $navChars + 16                                    // label + inline padding
        + (menu_item_has_glyph($navMeasure, 'desktop') ? 24 : 0)
        + (!empty($navMeasure['children']) ? 22 : 0)                    // the caret
        + (!empty($navMeasure['badge']) ? 8 + 7 * mb_strlen((string) $navMeasure['badge']) : 0)
        + 4;                                                            // --sp-1 list gap

    // Open from the left by default. Flip only when the panel would run past the
    // row's right edge *and* there is room for it on the left; if neither side
    // fits, left-aligned at least keeps it reachable.
    $navFlip[$navIdx] = ($navOffset + $navPanelW > $navRowW)
        && ($navOffset + $navWidth >= $navPanelW);
    $navOffset += $navWidth;
}

/**
 * Does any item in this list draw a glyph on the desktop surface?
 *
 * The leading glyph slot is reserved for a whole list or for none of it: if
 * only some rows have an icon the labels have to stay on one alignment, and if
 * no row has one the list should not carry an empty 16px gutter.
 */
$navListHasIcons = static function (array $navItems): bool {
    foreach ($navItems as $navRow) {
        if (menu_item_has_glyph($navRow, 'desktop')) {
            return true;
        }
    }
    return false;
};

/**
 * One row inside a plain dropdown or the "More" panel.
 *
 * A menu item has no artwork of its own, so the leading glyph is the icon the
 * admin picked — resolved through menu_item_glyph(), which is also what decides
 * whether that icon is switched on for this surface at all. The span is still
 * emitted for an item with no glyph so its label lines up with the rows that
 * have one. The mega panel draws its rows from includes/mega-panel.php with the
 * identical contract.
 */
$navRenderLink = static function (array $navLink, string $navClass = 'sik-mega__link', bool $navSlot = false): void {
    $navGlyph = menu_item_glyph($navLink, 'desktop', 'sik-mega__glyph-svg');
    $navChip  = menu_badge_chip($navLink);
    ?>
    <a class="<?= e_attr($navClass) ?>" href="<?= e($navLink['href']) ?>"
       <?= !empty($navLink['open_new_tab']) ? 'target="_blank" rel="noopener"' : '' ?>>
        <?php if ($navSlot || $navGlyph !== ''): ?>
            <span class="sik-mega__glyph" aria-hidden="true"><?= $navGlyph ?></span>
        <?php endif; ?>
        <?= menu_badge_first($navLink) ? $navChip : '' ?>
        <span class="sik-mega__link-text"><?= e($navLink['label']) ?></span>
        <?= menu_badge_first($navLink) ? '' : $navChip ?>
    </a>
    <?php
};
?>
<?php
// The first item that opens a full panel becomes the row's anchor button -
// the solid "browse the catalogue" control a shopper expects at the left of
// a marketplace navigation. It is still an ordinary menu item underneath, so
// Admin > Menu Builder decides what it says and what the panel holds.
$navBrowseTaken = false;
?>
<nav class="sik-nav" aria-label="Primary">
    <ul class="sik-nav__list">
        <?php foreach ($navInline as $navIndex => $navItem): ?>
            <?php
            $navHasChildren = !empty($navItem['children']);
            $navIsMega   = $navHasChildren && (int) $navItem['is_mega'] === 1;
            $navIsActive = menu_item_is_active($navItem);
            $navPanelId  = 'sikNavPanel' . (++$navSeq);
            $navEnd      = !empty($navFlip[$navIndex]) ? ' sik-mega--end' : '';
            // Any item with a panel qualifies, not just a full mega one: most
            // stores build their departments as a plain dropdown, and the row
            // still needs its anchor button.
            $navBrowse   = $navHasChildren && !$navBrowseTaken;
            $navBrowseTaken = $navBrowseTaken || $navBrowse;
            ?>
            <?php // --mega marks which items open a full panel rather than a plain
                  // dropdown. It carries no positioning any more — see app.css. ?>
            <li class="sik-nav__item<?= $navIsMega ? ' sik-nav__item--mega' : '' ?>"
                <?= $navHasChildren ? 'data-nav-item' : '' ?>>
                <a href="<?= e($navItem['href']) ?>"
                   class="sik-nav__link<?= $navIsActive ? ' is-current' : '' ?><?= $navBrowse ? ' sik-nav__link--browse' : '' ?>"
                   <?php // The underline is the only "you are here" cue on screen;
                         // assistive tech needs the same fact stated, not drawn. ?>
                   <?= $navIsActive ? 'aria-current="page"' : '' ?>
                   <?= $navItem['open_new_tab'] ? 'target="_blank" rel="noopener"' : '' ?>
                   <?= $navHasChildren ? 'aria-expanded="false" aria-controls="' . e_attr($navPanelId) . '"' : '' ?>>
                    <?php // The browse button leads with a menu glyph whatever the item
                          // itself carries, because that is the shape a shopper reads as
                          // "everything is in here". ?>
                    <?= $navBrowse ? icon('menu', 'sik-nav__icon') : menu_item_glyph($navItem, 'desktop', 'sik-nav__icon') ?>
                    <?php // The chip is built by the same resolver the panels and
                          // the drawer use, so a badge styled once looks the same
                          // everywhere it appears. .sik-nav__badge is the bar's
                          // own chip class — smaller, and lifted onto the label's
                          // cap height — so it is passed as the base class rather
                          // than layered on top of .sik-badge. ?>
                    <?= menu_badge_first($navItem) ? menu_badge_chip($navItem, 'sik-nav__badge') : '' ?>
                    <span class="sik-nav__text"><?= e($navItem['label']) ?></span>
                    <?= menu_badge_first($navItem) ? '' : menu_badge_chip($navItem, 'sik-nav__badge') ?>
                    <?php if ($navHasChildren): ?>
                        <span class="sik-nav__caret" aria-hidden="true"><?= icon('chevron-down', 'sik-nav__caret-svg') ?></span>
                    <?php endif; ?>
                </a>

                <?php if ($navIsMega): ?>
                    <?php
                    // The panel itself is includes/mega-panel.php, so Admin >
                    // Menu Builder > Preview panel can render the real thing
                    // rather than a picture of it. This file keeps only what is
                    // about the BAR: which side the panel opens from, and the id
                    // the trigger above points at.
                    $megaItem     = $navItem;
                    $megaPanelId  = $navPanelId;
                    $megaEndClass = $navEnd;
                    $megaExtra    = '';
                    require __DIR__ . '/mega-panel.php';
                    unset($megaItem, $megaPanelId);
                    ?>

                <?php elseif ($navHasChildren): ?>
                    <div class="sik-mega sik-mega--drop<?= $navEnd ?>" id="<?= e_attr($navPanelId) ?>" data-mega>
                        <ul class="sik-mega__list">
                            <?php $navDropSlot = $navListHasIcons($navItem['children']); ?>
                            <?php foreach ($navItem['children'] as $navChild): ?>
                                <li><?php $navRenderLink($navChild, 'sik-mega__link', $navDropSlot); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>

        <?php if ($navOverflow !== []): ?>
            <?php $navPanelId = 'sikNavPanel' . (++$navSeq); ?>
            <li class="sik-nav__item" data-nav-item>
                <button type="button" class="sik-nav__link sik-nav__link--more"
                        aria-expanded="false" aria-controls="<?= e_attr($navPanelId) ?>">
                    <span class="sik-nav__text">More</span>
                    <span class="sik-nav__caret" aria-hidden="true"><?= icon('chevron-down', 'sik-nav__caret-svg') ?></span>
                </button>

                <div class="sik-mega sik-mega--drop sik-mega--end" id="<?= e_attr($navPanelId) ?>" data-mega>
                    <?php
                    // One list, one group per overflow item — not a list each, or
                    // the icon gutter would be decided per group and the labels
                    // would sit on three different alignments.
                    $navDropSlot = $navListHasIcons($navOverflow);
                    ?>
                    <ul class="sik-mega__list">
                        <?php foreach ($navOverflow as $navItem): ?>
                            <li class="sik-mega__group">
                                <?php $navRenderLink($navItem, 'sik-mega__link sik-mega__link--parent', $navDropSlot); ?>
                                <?php if (!empty($navItem['children'])): ?>
                                    <ul class="sik-mega__sub">
                                        <?php // Still capped: "More" is a compact list of the
                                              // destinations that did not fit the bar, not a
                                              // second mega menu. Each entry links to its own
                                              // page where the full list lives. ?>
                                        <?php foreach (array_slice($navItem['children'], 0, 6) as $navChild): ?>
                                            <li><?php $navRenderLink($navChild, 'sik-mega__link sik-mega__link--sub', false); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>
        <?php endif; ?>
    </ul>

    <?php // The deals shortcut, at the far end of the row. It is only offered
          // while something is genuinely discounted - a store with nothing on
          // sale pointing at a deals page is the fastest way to lose a
          // shopper's trust in every other claim on the page. ?>
    <?php if (store_has_deals()): ?>
        <a class="sik-nav__deal" href="<?= e(url('deals.php')) ?>">
            <?= icon('percent', 'sik-nav__deal-icon') ?>
            <span>Today's deals</span>
        </a>
    <?php endif; ?>
</nav>
<?php
// navbar.php is included at the file scope of whichever page is rendering, so
// every local here is prefixed `$nav` and released afterwards. A plain name
// like $item or $product would silently overwrite the page's own variable.
unset(
    $navItem, $navChild, $navRow, $navHasChildren,
    $navIsMega, $navIsActive, $navIndex, $navEnd,
    $navDropSlot, $navGlyph, $navChip,
    $navVisible, $navInline, $navOverflow, $navSeq, $navPanelId,
    $navRenderLink, $navListHasIcons, $navBrowse, $navBrowseTaken,
    $navPanelW, $navRowW, $navOffset, $navFlip, $navIdx, $navMeasure,
    $navChars, $navWidth
);
