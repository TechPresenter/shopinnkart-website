<?php
/**
 * ShopInnKart Admin - Header field spec.
 *
 * The header's *contract* already exists in includes/header-settings.php:
 * HEADER_DEFAULTS names every knob, HEADER_RANGES bounds the numbers,
 * HEADER_ENUMS lists the allowed keywords and header_settings_normalize()
 * enforces all three on read as well as on save.
 *
 * This file adds the one thing a contract cannot carry: what to call each knob
 * in the admin. Everything else is *derived* from the contract, so a knob added
 * to HEADER_DEFAULTS shows up here with its real bounds and its real options
 * without anyone editing a second list. The labels below are the only new
 * information; the type, the min, the max and the option set are all read back
 * out of includes/header-settings.php.
 *
 * Required by admin/appearance/header.php (renders it) and by
 * includes/appearance-registry.php (resets it), which is why it lives in a file
 * of its own rather than at the top of the form.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a
// host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once INCLUDES_PATH . '/header-settings.php';

/** Human labels for the enum values, keyed exactly as HEADER_ENUMS is. */
const HEADER_ENUM_LABELS = [
    'menu_align' => [
        'auto' => 'Auto (follow the menu style)', 'start' => 'Left', 'center' => 'Centred', 'end' => 'Right',
    ],
    'menu_font_weight' => [
        '400' => '400 Regular', '500' => '500 Medium', '600' => '600 Semibold',
        '700' => '700 Bold', '800' => '800 Extrabold',
    ],
    'menu_text_transform' => [
        'none' => 'As typed', 'uppercase' => 'UPPERCASE', 'capitalize' => 'Capitalise Each Word',
    ],
    'menu_hover_effect' => [
        'pill' => 'Tinted pill', 'underline' => 'Underline', 'color' => 'Colour change only',
    ],
    'header_search_from' => [
        '768' => 'Tablets and up (768px)', '1024' => 'Laptops and up (1024px)',
        'never' => 'Never inline — always the overlay',
    ],
    'header_burger_position' => ['left' => 'Left of the logo', 'right' => 'Right, beside the actions'],
    'header_shadow' => [
        'auto' => 'Auto (follow the menu style)', 'none' => 'None',
        'soft' => 'Soft', 'card' => 'Card', 'lift' => 'Lift',
    ],
    'header_stuck_shadow' => [
        'auto' => 'Auto (follow the menu style)', 'none' => 'None',
        'soft' => 'Soft', 'card' => 'Card', 'lift' => 'Lift',
    ],
];

/**
 * Label + help for each key. A key absent here still renders — it falls back to
 * a humanised version of its own name — so forgetting to describe a new knob
 * costs a nice sentence, never a missing control.
 */
const HEADER_FIELD_TEXT = [
    'menu_style' => ['Menu style', 'Structural layout. The knobs below then apply on top of whichever style is selected.'],

    'header_logo_height'  => ['Logo height (px)', 'Phones automatically get 8px less, exactly as the shipped header does.'],
    'header_show_tagline' => ['Show the tagline beside the logo', 'Uses the tagline from Settings → General. Appears from 1024px up only — below that the lead slot is already carrying the menu button and the wordmark.'],

    'header_height'        => ['Header height (px)', 'From 768px up. The shipped header is 76px.'],
    'header_height_mobile' => ['Header height on phones (px)', 'Below 768px. The shipped header is 60px.'],

    'header_nav_enabled'  => ['Show the category bar', 'Off hides the department bar at every width; the drawer still has every link.'],
    'menu_align'          => ['Category bar alignment', ''],
    'menu_spacing'        => ['Gap between links (px)', ''],
    'menu_item_padding'   => ['Padding inside each link (px)', 'Large screens get one step more, as shipped.'],
    'menu_font_size'      => ['Link size (px)', ''],
    'menu_font_weight'    => ['Link weight', ''],
    'menu_text_transform' => ['Link case', 'Uppercase also widens the tracking — one decision, not two.'],
    'menu_hover_effect'   => ['Hover effect', ''],

    'header_icon_size'          => ['Action icon size (px)', ''],
    'header_action_size'        => ['Action tap target (px)', 'Cannot go below 44px — that is the WCAG 2.5.5 target size, not a preference.'],
    'header_show_notifications' => ['Show the notification bell', ''],
    'header_show_wishlist'      => ['Show the wishlist icon', 'Also needs the wishlist feature itself to be on, in Settings → Widgets.'],
    'header_show_compare'       => ['Show the compare icon', 'Also needs the comparison feature itself to be on, in Settings → Widgets.'],
    'header_show_cart'          => ['Show the cart icon', 'Hiding it does not disable the cart — the cart page still works.'],
    'header_show_account'       => ['Show the account icon', 'Hiding it does not disable sign-in — /login.php still works.'],
    'header_show_track'         => ['Show the track-order icon', 'Links to /track-order.php, which works for guests as well as signed-in customers.'],

    'header_search_from'        => ['Show the search field inline from', ''],
    'header_search_width'       => ['Search field width (px)', 'Screens from 1536px up get 80px more, as shipped.'],
    'header_search_placeholder' => ['Search placeholder', ''],
    'header_search_voice'       => ['Offer voice search', 'Needs a browser with speech recognition AND an https connection - browsers refuse the microphone on a plain http page, so the button hides itself there rather than failing on every tap.'],
    'header_search_popular'     => ['Popular searches', 'Comma separated, shown under the search field. Left empty the row builds itself from your featured categories.'],

    'header_burger_position' => ['Menu button position', ''],

    'header_bg'              => ['Header background', ''],
    'header_border_width'    => ['Bottom border width (px)', ''],
    'header_border_color'    => ['Bottom border colour', ''],
    'header_shadow'          => ['Shadow at rest', ''],
    'header_stuck_shadow'    => ['Shadow once stuck', 'Applies after the header sticks on scroll.'],
    'header_nav_bg'          => ['Category bar background', ''],
    'menu_link_color'        => ['Category link colour', ''],
    'menu_hover_color'       => ['Category link colour on hover', ''],
    'menu_hover_bg'          => ['Category link background on hover', ''],
    'menu_active_color'      => ['Current-page link colour', 'Also colours the rule beneath it, so the two can never disagree.'],
    'header_action_color'    => ['Action icon colour', ''],
    'header_action_hover_bg' => ['Action icon background on hover', ''],
    'announce_bg'            => ['Announcement strip background', 'The ticker above the header. Its own on/off switch is in Settings → Widgets.'],
    'announce_color'         => ['Announcement strip text', ''],
];

/**
 * The field spec for every header setting, in the _layout.php vocabulary.
 *
 * Derived from the contract, never parallel to it: the loop walks
 * HEADER_DEFAULTS, so this can only ever describe keys that actually exist, and
 * it can never miss one.
 */
function header_field_spec(): array
{
    $spec = [];

    foreach (HEADER_DEFAULTS as $key => $default) {
        [$label, $help] = HEADER_FIELD_TEXT[$key]
            ?? [ucfirst(str_replace('_', ' ', $key)), ''];

        $field = [
            'label'   => $label,
            'help'    => $help,
            'default' => (string) $default,
            'group'   => 'header',
        ];

        if ($key === 'menu_style') {
            $field['type']     = 'select';
            $field['required'] = true;
            $field['options']  = array_map(
                static fn (array $style): string => (string) $style['label'],
                HEADER_MENU_STYLES
            );
        } elseif (in_array($key, HEADER_BOOLS, true)) {
            $field['type'] = 'bool';
        } elseif (isset(HEADER_RANGES[$key])) {
            [$min, $max] = HEADER_RANGES[$key];
            $field['type']      = 'number';
            $field['required']  = true;
            $field['min_value'] = $min;
            $field['max_value'] = $max;
        } elseif (isset(HEADER_ENUMS[$key])) {
            $field['type']     = 'select';
            $field['required'] = true;
            $options = [];
            foreach (HEADER_ENUMS[$key] as $value) {
                $options[$value] = HEADER_ENUM_LABELS[$key][$value] ?? $value;
            }
            $field['options'] = $options;
        } elseif (str_ends_with($key, '_color') || str_ends_with($key, '_bg')) {
            // Deliberately not `required`: blank is the shipped state and means
            // "keep following the theme", which is what makes every colour
            // default reproduce the current header byte for byte.
            $field['type'] = 'color';
            $field['help'] = trim($help . ' Leave blank to follow the theme.');
        } else {
            $field['type'] = 'text';
            $field['max']  = 120;
        }

        $spec[$key] = $field;
    }

    return $spec;
}

/** The shipped value of every header setting, straight from the contract. */
function header_field_defaults(): array
{
    return array_map('strval', HEADER_DEFAULTS);
}
