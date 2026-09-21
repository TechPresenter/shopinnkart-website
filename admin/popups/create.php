<?php
/**
 * ShopInnKart Admin - Create a popup or pop-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.create');

require_once __DIR__ . '/_shared.php';

// ?mode=popin lets the list screen open the form already set to the right kind.
$mode = admin_filter('mode', array_keys(popup_display_modes()), 'popup');

$popup = [
    'name'              => '',
    'display_mode'      => $mode,
    'popup_type'        => $mode === 'popin' ? 'cart_reminder' : 'newsletter',
    'title'             => '',
    'subtitle'          => '',
    'content'           => '',
    'image'             => null,
    'mobile_image'      => null,
    'video_url'         => '',
    'coupon_code'       => '',
    'product_id'        => 0,
    'button_text'       => '',
    'button_url'        => '',
    'position'          => $mode === 'popin' ? 'bottom-left' : 'center',
    'size'              => 'md',
    'bg_color'          => '',
    'text_color'        => '',
    'trigger_type'      => 'timed',
    'trigger_value'     => 5,
    'frequency'         => 'session',
    'display_pages'     => 'all',
    'device_visibility' => 'all',
    'auth_visibility'   => 'all',
    'show_close'        => 1,
    'start_date'        => null,
    'end_date'          => null,
    'sort_order'        => 0,
    'status'            => 'active',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $submitted = popup_form_input();
    $popup = array_merge($popup, $submitted);
    $errors = popup_validate_input($submitted);

    if ($errors !== []) {
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $image  = admin_handle_image('image', 'popups');
        $mobile = admin_handle_image('mobile_image', 'popups');

        $id = Database::insert('popups', popup_row_from_input($submitted, $image, $mobile));

        log_activity(
            'popup.created',
            'popup',
            $id,
            'Created ' . $submitted['display_mode'] . ' "' . $submitted['name'] . '"'
        );
        admin_after_write();

        flash('success', 'Popup "' . $submitted['name'] . '" created.');
        redirect(admin_url('popups/edit.php?id=' . $id));
    }
}

$isEdit       = false;
// A rejected submission can carry a bogus display_mode, so the heading falls
// back rather than indexing into the map blindly.
$pageTitle    = 'New ' . (popup_display_modes()[$popup['display_mode']] ?? 'Popup');
$pageSubtitle = 'Decide what it says, who sees it and when it fires.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Popups',    'url' => admin_url('popups/?mode=' . $mode)],
    ['label' => 'New'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
