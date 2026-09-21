<?php
/**
 * ShopInnKart - Header design contract.
 *
 * ONE place decides what the storefront header may be told to look like:
 *
 *   HEADER_MENU_STYLES  the five layout variants offered in the admin
 *   HEADER_DEFAULTS     every knob, and the value that reproduces the header
 *                       the store shipped with
 *   header_settings()   the validated, defaulted values for this request
 *   header_css_vars()   those values as CSS custom properties
 *   header_body_class() the classes that switch the structural variants
 *
 * The rule that makes this safe to extend: **every default here must render
 * the shipped header byte-for-byte.** An operator who changes nothing sees no
 * difference, so a new knob can never regress the storefront by existing.
 * That is why the fallbacks below are `var(--sik-…)` references rather than
 * literals — they resolve to whatever Admin > Settings > Theme already chose.
 *
 * Consumed by includes/header.php (prints the properties into the head, the
 * same mechanism the theme tokens already use) and by
 * admin/appearance/header.php (renders the form and the live preview).
 */

declare(strict_types=1);

/**
 * The five menu styles.
 *
 * These are structural variants, not colour presets: each one changes where
 * the navigation sits, whether the categories get a bar of their own, how the
 * search behaves and how the header is separated from the page. The scalar
 * knobs below (sizes, spacing, type, colour) then apply *on top of* whichever
 * style is selected, so the two systems never fight each other.
 *
 * `classic` is the shipped look and deliberately adds no CSS at all.
 */
const HEADER_MENU_STYLES = [
    'classic' => [
        'label' => 'Classic Navigation',
        'blurb' => 'The shipped layout. Logo, search and actions on one row, with a full-width category bar beneath it separated by a hairline.',
        'notes' => ['Category bar below the header', 'Inline search from 768px', 'Hairline separators, no shadow'],
    ],
    'mega' => [
        'label' => 'Modern Mega Menu',
        'blurb' => 'The category bar becomes a tinted band with the links centred in it, and the header floats over the page on a soft shadow instead of a rule.',
        'notes' => ['Tinted, centred category band', 'Soft shadow instead of a border', 'Roomier nav padding'],
    ],
    'minimal' => [
        'label' => 'Minimal Premium',
        'blurb' => 'No category bar at any width. Navigation moves entirely into the drawer and search into the overlay, leaving logo and actions on one quiet line.',
        'notes' => ['No category bar', 'Menu button at every width', 'Search opens as an overlay'],
    ],
    'category' => [
        'label' => 'Category-Focused Navigation',
        'blurb' => 'The category bar is a solid dark strip with hairline dividers between the links, so the departments read as the primary navigation of the store.',
        'notes' => ['Solid dark category strip', 'Dividers between departments', 'Compact top row'],
    ],
    'commerce' => [
        'label' => 'E-commerce Mega Navigation',
        'blurb' => 'Built for a wide catalogue: the search field takes every pixel the row can spare and the category bar is taller and heavier, with the actions set off by a divider.',
        'notes' => ['Full-width search field', 'Taller, bolder category bar', 'Actions separated by a rule'],
    ],
];

/**
 * Every header setting and the value that reproduces the shipped header.
 *
 * `type` mirrors the admin/settings/_layout.php field spec vocabulary so the
 * same validator and the same renderer are used here - there is no second
 * settings pipeline.
 */
const HEADER_DEFAULTS = [
    // --- menu style -------------------------------------------------------
    'menu_style'                => 'classic',

    // --- logo -------------------------------------------------------------
    'header_logo_height'        => '40',
    'header_show_tagline'       => '0',

    // --- height -----------------------------------------------------------
    'header_height'             => '76',
    'header_height_mobile'      => '60',

    // --- navigation & menu typography -------------------------------------
    'header_nav_enabled'        => '1',
    'menu_align'                => 'auto',
    'menu_spacing'              => '4',
    'menu_item_padding'         => '8',
    // 14/500/none, not 13/600/uppercase. Small caps set in semibold with wide
    // tracking is the heaviest way to set six short words: it reads as three
    // emphasis signals stacked on one another, and at 13px the letterforms are
    // below the body scale while shouting louder than it. 14px medium in
    // sentence case sits in the type scale, and the active item carries the
    // weight instead — one signal, where it means something.
    'menu_font_size'            => '14',
    'menu_font_weight'          => '500',
    'menu_text_transform'       => 'none',
    'menu_hover_effect'         => 'pill',

    // --- icons & actions --------------------------------------------------
    'header_icon_size'          => '20',
    'header_action_size'        => '44',
    'header_show_notifications' => '1',
    'header_show_wishlist'      => '1',
    'header_show_compare'       => '1',
    'header_show_cart'          => '1',
    'header_show_account'       => '1',
    'header_show_track'         => '1',

    // --- search -----------------------------------------------------------
    // A comma list that replaces the featured-category shortcuts under the
    // search field. Left empty the row builds itself from the catalogue, which
    // is the setting most stores should leave alone.
    'header_search_popular'     => '',
    'header_search_from'        => '768',
    'header_search_width'       => '560',
    'header_search_placeholder' => 'Search string lights, diyas, lamps…',
    'header_search_voice'       => '1',

    // --- mobile -----------------------------------------------------------
    'header_burger_position'    => 'left',

    // --- colours, borders, shadows, hover ---------------------------------
    'header_bg'                 => '',
    'header_border_width'       => '1',
    'header_border_color'       => '',
    'header_shadow'             => 'auto',
    'header_stuck_shadow'       => 'auto',
    'header_nav_bg'             => '',
    'menu_link_color'           => '',
    'menu_hover_color'          => '',
    'menu_hover_bg'             => '',
    'menu_active_color'         => '',
    'header_action_color'       => '',
    'header_action_hover_bg'    => '',
    'announce_bg'               => '',
    'announce_color'            => '',
];

/** Named box-shadow presets, so the select can never inject arbitrary CSS. */
const HEADER_SHADOWS = [
    'none' => 'none',
    'soft' => 'var(--sik-shadow-soft)',
    'card' => 'var(--sik-shadow-card)',
    'lift' => 'var(--sik-shadow-lift)',
];

/**
 * Allowed values for every select, mirrored by the admin form's options.
 *
 * `auto` means "let the chosen menu style decide". It is the default wherever
 * it appears, and it is the mechanism that keeps the two systems from
 * fighting: an operator who leaves a field on Auto gets the style's treatment,
 * and an operator who picks a value overrides the style everywhere.
 */
const HEADER_ENUMS = [
    'menu_align'             => ['auto', 'start', 'center', 'end'],
    'menu_font_weight'       => ['400', '500', '600', '700', '800'],
    'menu_text_transform'    => ['none', 'uppercase', 'capitalize'],
    'menu_hover_effect'      => ['pill', 'underline', 'color'],
    'header_search_from'     => ['768', '1024', 'never'],
    'header_burger_position' => ['left', 'right'],
    'header_shadow'          => ['auto', 'none', 'soft', 'card', 'lift'],
    'header_stuck_shadow'    => ['auto', 'none', 'soft', 'card', 'lift'],
];

/** Inclusive bounds for every numeric knob, enforced on read as well as save. */
const HEADER_RANGES = [
    'header_logo_height'  => [16, 72],
    'header_height'       => [56, 140],
    'header_height_mobile'=> [48, 110],
    'menu_spacing'        => [0, 32],
    'menu_item_padding'   => [2, 28],
    'menu_font_size'      => [10, 20],
    'header_icon_size'    => [14, 30],
    // 44px is the WCAG 2.5.5 target size. The floor is the guideline, not a
    // preference, so the admin cannot shrink a control below it.
    'header_action_size'  => [44, 64],
    'header_search_width' => [280, 900],
    'header_border_width' => [0, 6],
];

/** Keys stored as booleans. */
const HEADER_BOOLS = [
    'header_show_tagline', 'header_nav_enabled', 'header_show_notifications',
    'header_show_wishlist', 'header_show_compare', 'header_show_cart',
    'header_show_account', 'header_search_voice', 'header_show_track',
];

/**
 * Clamp, enum-check and hex-check a raw set of header values.
 *
 * Validation lives HERE rather than only in the admin form, because these
 * values are interpolated into a <style> block: a row edited straight in the
 * database must not be able to close that block and inject markup. A value
 * that fails its check falls back to the default, which is the shipped look.
 *
 * $raw is whatever the caller has - the stored settings group on the
 * storefront, or an unsaved POST body in the admin preview endpoint. Both go
 * through this one function, which is what guarantees the preview shows
 * exactly what saving would publish.
 */
function header_settings_normalize(array $raw): array
{
    $out = [];

    foreach (HEADER_DEFAULTS as $key => $default) {
        $value = (string) ($raw[$key] ?? $default);

        if (in_array($key, HEADER_BOOLS, true)) {
            $out[$key] = in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
            continue;
        }

        if (isset(HEADER_RANGES[$key])) {
            [$min, $max] = HEADER_RANGES[$key];
            $out[$key] = (string) (is_numeric($value)
                ? max($min, min($max, (int) $value))
                : (int) $default);
            continue;
        }

        if (isset(HEADER_ENUMS[$key])) {
            $out[$key] = in_array($value, HEADER_ENUMS[$key], true) ? $value : $default;
            continue;
        }

        if ($key === 'menu_style') {
            $out[$key] = isset(HEADER_MENU_STYLES[$value]) ? $value : $default;
            continue;
        }

        // Colours are optional: blank means "keep following the theme", which
        // is what lets every colour default reproduce the current header.
        if (str_ends_with($key, '_color') || str_ends_with($key, '_bg')) {
            $out[$key] = preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : '';
            continue;
        }

        // Free text (the placeholder). Length-capped and stripped of the two
        // characters that could break out of an HTML attribute even before e_attr().
        $out[$key] = mb_substr(str_replace(["\r", "\n", '<', '>'], '', $value), 0, 120);
    }

    return $out;
}

/**
 * The validated header settings for this request, read once and reused.
 *
 * The storefront header is included on every page, so the group is fetched a
 * single time; header_settings_normalize() does the actual work.
 */
function header_settings(): array
{
    static $cache = null;

    return $cache ??= header_settings_normalize(settings_group('header'));
}

/** True when this header setting is on. */
function header_on(string $key): bool
{
    $h = header_settings();
    return ($h[$key] ?? '0') === '1';
}

/**
 * Which CSS custom property each optional setting writes.
 *
 * "Optional" means the value is only printed when the operator actually chose
 * one. That is deliberate and it is what makes the whole feature safe:
 *
 *   - Nothing chosen  -> the property is absent, and app.css's own
 *                        `var(--sik-hd-x, <shipped value>)` fallback applies.
 *                        The header renders exactly as it shipped.
 *   - Style chosen    -> the menu style sets `--sik-hd-x-auto` on .sik-header,
 *                        which app.css consults between the two.
 *   - Operator chose  -> the property exists on :root and wins over both.
 *
 * Resolving the chain at the *use site* rather than here is the load-bearing
 * detail: a `--x-auto` declared by a style on .sik-header is invisible to a
 * :root declaration, because custom properties substitute against the element
 * the value is computed on.
 */
const HEADER_OPTIONAL_VARS = [
    'header_bg'              => '--sik-hd-bg',
    'header_border_color'    => '--sik-hd-bc',
    'header_nav_bg'          => '--sik-hd-navbg',
    'menu_link_color'        => '--sik-hd-navfg',
    'menu_hover_color'       => '--sik-hd-navhover-fg',
    'menu_hover_bg'          => '--sik-hd-navhover-bg',
    'menu_active_color'      => '--sik-hd-navactive',
    'header_action_color'    => '--sik-hd-actionfg',
    'header_action_hover_bg' => '--sik-hd-actionbg',
    'announce_bg'            => '--sik-hd-ann-bg',
    'announce_color'         => '--sik-hd-ann-fg',
];

/**
 * The header settings as CSS custom properties.
 *
 * Printed into the same <style> block in includes/header.php that already
 * carries the theme tokens - this EXTENDS that mechanism, it does not add a
 * second one. Every value is a clamped integer, a fixed keyword or a
 * validated hex colour by the time it gets here.
 *
 * The `-sm` / `-lg` companions exist because the shipped header already steps
 * at 768px and at 1440px/1536px. Deriving the step from the admin's number
 * instead of freezing it is what keeps "change nothing, see nothing" true at
 * every breakpoint rather than only at 1280.
 */
function header_css_vars(array $h): string
{
    $n = static fn (string $k): int => (int) $h[$k];

    $logo   = $n('header_logo_height');
    $navPad = $n('menu_item_padding');
    $search = $n('header_search_width');

    // Always printed: these are scale, and scale always has a number.
    $lines = [
        // Row heights. The shipped values are 60 on phones and 76 from 768 up.
        '--sik-hd-h-sm'       => $n('header_height_mobile') . 'px',
        '--sik-hd-h'          => $n('header_height') . 'px',

        // The wordmark is 8px shorter on phones in the shipped header (32/40),
        // so the phone size is derived from the desktop one and never inverts.
        '--sik-hd-logo'       => $logo . 'px',
        '--sik-hd-logo-sm'    => max(14, $logo - 8) . 'px',

        '--sik-hd-navgap'     => $n('menu_spacing') . 'px',
        '--sik-hd-navpad'     => $navPad . 'px',
        // 1440px and up gets one spacing step more, exactly as shipped (8 -> 12).
        '--sik-hd-navpad-lg'  => ($navPad + 4) . 'px',
        '--sik-hd-navfs'      => $n('menu_font_size') . 'px',
        '--sik-hd-navfw'      => (string) $n('menu_font_weight'),
        '--sik-hd-navtt'      => $h['menu_text_transform'],
        // Tracking follows the case: uppercase labels need the extra air,
        // sentence case does not. Derived, so it is not a knob of its own.
        '--sik-hd-navls'      => $h['menu_text_transform'] === 'uppercase' ? 'var(--ls-wide)' : 'var(--ls-normal)',

        '--sik-hd-icon'       => $n('header_icon_size') . 'px',
        '--sik-hd-tap'        => $n('header_action_size') . 'px',

        '--sik-hd-searchw'    => $search . 'px',
        // 1536px and up gets the same +80px the shipped header gives it.
        '--sik-hd-searchw-lg' => ($search + 80) . 'px',

        '--sik-hd-bw'         => $n('header_border_width') . 'px',
    ];

    // Printed only when the operator overrode the menu style's own treatment.
    if ($h['menu_align'] !== 'auto') {
        $lines['--sik-hd-navjust'] =
            ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'][$h['menu_align']];
    }
    if ($h['header_shadow'] !== 'auto') {
        $lines['--sik-hd-shadow'] = HEADER_SHADOWS[$h['header_shadow']];
    }
    if ($h['header_stuck_shadow'] !== 'auto') {
        $lines['--sik-hd-shadow-stuck'] = HEADER_SHADOWS[$h['header_stuck_shadow']];
    }

    // Two knobs a menu style is entitled to have its own opinion about: the
    // E-commerce style gives the search field the whole row and makes the
    // category bar heavier, and both are named in its blurb. A style may still
    // only beat a value the operator LEFT ALONE, so the chosen value is
    // republished under a `-set` name that exists only once it differs from
    // the shipped default, and the style rules in app.css read that name first.
    //
    // The plain `--sik-hd-navfw` / `--sik-hd-searchw` above cannot serve here:
    // they are always printed, so a style reading them would be overridden by
    // a number the operator never actually chose.
    foreach ([
        'menu_font_weight'    => ['--sik-hd-navfw-set', ''],
        'header_search_width' => ['--sik-hd-searchw-set', 'px'],
    ] as $key => [$property, $unit]) {
        if ($h[$key] !== (string) HEADER_DEFAULTS[$key]) {
            $lines[$property] = $h[$key] . $unit;
        }
    }

    foreach (HEADER_OPTIONAL_VARS as $key => $property) {
        if (($h[$key] ?? '') !== '') {
            $lines[$property] = (string) $h[$key];
        }
    }

    // One derived colour rather than a second field: the "you are here" label
    // and the rule beneath it are one signal, and letting them drift apart is
    // how a nav ends up with an orange underline under a navy word.
    if (($h['menu_active_color'] ?? '') !== '') {
        $lines['--sik-hd-navactive-fg'] = (string) $h['menu_active_color'];
    }

    $css = '';
    foreach ($lines as $property => $value) {
        $css .= '            ' . $property . ': ' . $value . ";\n";
    }
    return $css;
}

/**
 * Body classes that switch the structural variants.
 *
 * Structure lives in classes and scale lives in custom properties, which is
 * what lets the admin preview swap a whole menu style inside a live iframe
 * without a server round trip - the same classes, applied to the same real
 * storefront document.
 */
function header_body_class(array $h): string
{
    $classes = [
        'sik-hstyle--' . $h['menu_style'],
        'sik-hhover--' . $h['menu_hover_effect'],
    ];

    if ($h['header_nav_enabled'] !== '1') {
        $classes[] = 'sik-hnav-off';
    }
    if ($h['header_search_from'] !== '768') {
        $classes[] = 'sik-hsearch--' . $h['header_search_from'];
    }
    if ($h['header_burger_position'] === 'right') {
        $classes[] = 'sik-hburger-right';
    }
    if ($h['header_show_tagline'] === '1') {
        $classes[] = 'sik-htagline';
    }

    return implode(' ', $classes);
}
