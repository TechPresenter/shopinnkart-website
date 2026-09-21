<?php
/**
 * ShopInnKart Admin - Header preview computation.
 *
 * Takes the UNSAVED state of the form on admin/appearance/header.php and
 * returns exactly what the storefront would print for it:
 *
 *   vars   the CSS custom property block header_css_vars() would emit
 *   body   the class list header_body_class() would put on <body>
 *   flags  the handful of decisions that are markup rather than CSS
 *
 * Why an endpoint instead of recomputing it in JavaScript: the preview has to
 * be the truth, not a good imitation of it. Both this and the live header call
 * header_settings_normalize() and header_css_vars(), so the preview cannot
 * drift from what saving would publish - not when a clamp changes, not when a
 * new field is added, not ever. The alternative is a second copy of that logic
 * in JS that is correct on the day it is written and wrong a month later.
 *
 * Read-only: it validates and formats, it never writes a setting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once INCLUDES_PATH . '/header-settings.php';

api_require_method('POST');
api_require_admin('settings.view');
api_require_csrf();

// Every value comes from HEADER_DEFAULTS' key list, so a crafted POST cannot
// introduce a key that normalisation does not know how to check.
$submitted = [];
foreach (array_keys(HEADER_DEFAULTS) as $key) {
    if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
        $submitted[$key] = (string) $_POST[$key];
    }
}

$h = header_settings_normalize($submitted);

json_success('OK', [
    'vars'  => header_css_vars($h),
    'body'  => header_body_class($h),
    'flags' => [
        // Controls the storefront renders conditionally rather than hides.
        'notifications' => $h['header_show_notifications'] === '1',
        'wishlist'      => $h['header_show_wishlist'] === '1',
        'compare'       => $h['header_show_compare'] === '1',
        'cart'          => $h['header_show_cart'] === '1',
        'account'       => $h['header_show_account'] === '1',
        'voice'         => $h['header_search_voice'] === '1',
        'placeholder'   => $h['header_search_placeholder'],
        'burgerLeft'    => $h['header_burger_position'] === 'left',
    ],
]);
