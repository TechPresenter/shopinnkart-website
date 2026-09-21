<?php
/**
 * ShopInnKart - Floating action buttons.
 *
 * One row in `floating_buttons` = one button in the floating stack. The six
 * shipped rows (WhatsApp, Call, Live Chat, Support, Custom, Back to top) are
 * ordinary rows with no privileges: an admin can retitle, recolour, reorder,
 * move, hide or delete any of them, and add more.
 *
 * Why a table and not a JSON blob in `settings`:
 *   - every button has its own sort order, position, device audience and
 *     colour, which is a row, not a scalar;
 *   - `banners`, `popups` and `menu_items` already have exactly this shape, so
 *     the admin list, the enable switch and the delete form are the project's
 *     existing components rather than new ones;
 *   - visibility_allows() takes a row and is reused verbatim;
 *   - a blob cannot be sorted or filtered in SQL and would need its own
 *     hand-written array validator.
 *
 * Nothing about a button is hard-coded here. Even the WhatsApp number and the
 * phone number fall back to Settings > General rather than being literals.
 */

declare(strict_types=1);

// floating_button_target() resolves a link button through nav_link_url(), which
// lives in menu-functions.php. This file never said so, and got away with it
// only because its one caller was includes/footer.php, which requires
// menu-functions.php twelve lines earlier. Any other caller fataled on "Call to
// undefined function nav_link_url()" the moment a link-type row reached the
// switch - which is what admin/floating/index.php does on every load, because
// it resolves every row's target to flag the ones that point nowhere.
// A file states its own dependencies; it does not borrow its caller's.
require_once __DIR__ . '/menu-functions.php';

// ===========================================================================
//  OPTION VOCABULARIES  (shared by the storefront renderer and the admin form)
// ===========================================================================

/** What clicking the button does. */
function floating_action_types(): array
{
    return [
        'whatsapp'   => 'Open WhatsApp chat',
        'call'       => 'Start a phone call',
        'link'       => 'Open a link (page, mailto: or tel:)',
        'scroll_top' => 'Scroll back to the top',
    ];
}

/** Which corner the stack this button belongs to is pinned to. */
function floating_positions(): array
{
    return [
        'bottom-right' => 'Bottom right',
        'bottom-left'  => 'Bottom left',
        'middle-right' => 'Middle right',
        'middle-left'  => 'Middle left',
    ];
}

function floating_sizes(): array
{
    return ['sm' => 'Small (38px)', 'md' => 'Medium (46px)', 'lg' => 'Large (56px)'];
}

function floating_animations(): array
{
    return [
        'none'   => 'None',
        'pulse'  => 'Pulse ring',
        'bounce' => 'Bounce',
        'float'  => 'Gentle float',
    ];
}

/**
 * Device audiences. The full six values visibility_allows() understands, so a
 * tablet is never excluded from both "desktop" and "mobile".
 */
function floating_device_options(): array
{
    return [
        'all'            => 'All devices',
        'desktop'        => 'Desktop only',
        'tablet'         => 'Tablet only',
        'mobile'         => 'Mobile only',
        'desktop_tablet' => 'Desktop + tablet',
        'tablet_mobile'  => 'Tablet + mobile',
    ];
}

function floating_auth_options(): array
{
    return ['all' => 'Everyone', 'guest' => 'Signed-out visitors', 'user' => 'Signed-in customers'];
}

// ===========================================================================
//  READ
// ===========================================================================

/** Every row, in render order. Used by the admin screen. */
function floating_buttons_all(): array
{
    return Database::fetchAll(
        'SELECT * FROM `floating_buttons` ORDER BY `position` ASC, `sort_order` ASC, `id` ASC'
    );
}

/**
 * The rows that should render for this visitor: enabled, allowed on this
 * device and for this auth state, and actually pointing somewhere.
 */
function floating_buttons_live(): array
{
    if (!setting_bool('floating_buttons_enabled', true)) {
        return [];
    }

    $rows = cache_remember('floating.active', 300, static function (): array {
        return Database::fetchAll(
            "SELECT * FROM `floating_buttons`
             WHERE `status` = 'active'
             ORDER BY `position` ASC, `sort_order` ASC, `id` ASC"
        );
    });

    $live = [];
    foreach ($rows as $row) {
        if (!visibility_allows($row)) {
            continue;
        }
        // A button with nothing behind it is not a button. Resolving here means
        // an admin who enables "Call Us" before filling in a phone number gets
        // no control rather than a dead one.
        $target = floating_button_target($row);
        if ($target === null) {
            continue;
        }
        $row['_target'] = $target;
        $live[] = $row;
    }

    return $live;
}

/**
 * Where one button points, or null when it points nowhere.
 *
 * @return array{tag:string, href:string, target:string}|null
 */
function floating_button_target(array $row): ?array
{
    switch ((string) $row['action_type']) {
        case 'whatsapp':
            // The row's own number wins; empty falls back to the store number,
            // so the shipped button works the moment Settings > General has one.
            $number = trim((string) ($row['whatsapp_number'] ?? ''));
            if ($number === '') {
                $number = trim((string) setting('store_whatsapp', ''));
            }
            $digits = preg_replace('/\D/', '', $number);
            if ($digits === '' || $digits === null) {
                return null;
            }
            $message = trim((string) ($row['whatsapp_message'] ?? ''));
            return [
                'tag'    => 'a',
                'href'   => 'https://wa.me/' . $digits . ($message !== '' ? '?text=' . rawurlencode($message) : ''),
                'target' => '_blank',
            ];

        case 'call':
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone === '') {
                $phone = trim((string) setting('store_phone', ''));
            }
            // tel: takes digits and an optional leading +, nothing else - the
            // same normalisation the footer's contact row uses.
            $dial = preg_replace('/[^\d+]/', '', $phone);
            if ($dial === '' || $dial === null) {
                return null;
            }
            return ['tag' => 'a', 'href' => 'tel:' . $dial, 'target' => ''];

        case 'link':
            $raw = trim((string) ($row['url'] ?? ''));
            if ($raw === '' || $raw === '#') {
                return null;
            }
            // Same resolver the Menu Builder and the Footer Builder use, so a
            // mailto:, tel: or //cdn link typed into any of the three behaves
            // identically.
            return [
                'tag'    => 'a',
                'href'   => nav_link_url($raw),
                'target' => (int) $row['open_new_tab'] === 1 ? '_blank' : '',
            ];

        case 'scroll_top':
            return ['tag' => 'button', 'href' => '', 'target' => ''];
    }

    return null;
}

// ===========================================================================
//  RENDER
// ===========================================================================

/** Inline style for one button: only the colours an admin actually set. */
function floating_button_style(array $row): string
{
    $style = '';
    if (!empty($row['bg_color'])) {
        $style .= 'background:' . $row['bg_color'] . ';';
    }
    if (!empty($row['text_color'])) {
        $style .= 'color:' . $row['text_color'] . ';';
    }
    return $style;
}

/** One button. Returned as a string so the admin preview can reuse it. */
function floating_button_html(array $row): string
{
    $target = $row['_target'] ?? floating_button_target($row);
    if ($target === null) {
        return '';
    }

    $label   = (string) $row['label'];
    $tooltip = trim((string) ($row['tooltip'] ?? ''));
    $tooltip = $tooltip !== '' ? $tooltip : $label;
    $glyph   = (string) $row['icon'];
    if (!icon_exists($glyph)) {
        $glyph = 'sparkle';
    }

    $classes = ['sik-float__btn', 'sik-float__btn--' . (string) $row['size']];
    if (($row['animation'] ?? 'none') !== 'none') {
        $classes[] = 'sik-float__btn--' . (string) $row['animation'];
    }
    if ((int) $row['show_label'] === 1) {
        $classes[] = 'sik-float__btn--labelled';
    }

    // A back-to-top control is hidden until the page has scrolled, so it starts
    // out of the tab order too - a keyboard user should not tab onto a button
    // they cannot see. app.js flips both together.
    $isTop = (string) $row['action_type'] === 'scroll_top';
    if ($isTop) {
        $classes[] = 'sik-float__btn--top';
    }

    $style = floating_button_style($row);

    $attrs = ' class="' . e_attr(implode(' ', $classes)) . '"'
        . ($style !== '' ? ' style="' . e_attr($style) . '"' : '')
        . ' data-float-tip="' . e_attr($tooltip) . '"'
        . ' aria-label="' . e_attr($label) . '"';

    if ($isTop) {
        return '<button type="button"' . $attrs . ' data-back-to-top hidden>'
            . icon($glyph, '')
            . '<span class="sik-float__label">' . e($label) . '</span>'
            . '</button>';
    }

    $rel = $target['target'] === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';

    return '<a href="' . e($target['href']) . '"' . $attrs . $rel . '>'
        . icon($glyph, '')
        . '<span class="sik-float__label">' . e($label) . '</span>'
        . '</a>';
}

/**
 * Every floating stack for this page, one wrapper per position.
 *
 * The bottom stacks clear the mobile bottom bar and the compare bar through
 * --sik-bottomnav-space / --sik-comparebar-h in app.css, which is why this
 * function has no geometry of its own.
 */
function render_floating_stacks(): void
{
    $buttons = floating_buttons_live();
    if ($buttons === []) {
        return;
    }

    $stacks = [];
    foreach ($buttons as $row) {
        $stacks[(string) $row['position']][] = $row;
    }

    foreach (floating_positions() as $position => $positionLabel) {
        if (empty($stacks[$position])) {
            continue;
        }
        echo '<div class="sik-float sik-float--' . e_attr($position) . '">';
        foreach ($stacks[$position] as $row) {
            echo floating_button_html($row);
        }
        echo '</div>';
    }
}
