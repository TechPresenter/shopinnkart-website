<?php
/**
 * ShopInnKart Admin - Create or update one floating action button.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 * A failed submission goes back to the builder through the flash bag, tagged
 * with __form so the right one of the many forms on that page reopens with the
 * errors - the same mechanism the Footer Builder uses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

require_once INCLUDES_PATH . '/floating-functions.php';

$listUrl = admin_url('floating/');

$id = input_int('id');
$existing = $id > 0
    ? Database::fetch('SELECT * FROM `floating_buttons` WHERE `id` = :id', ['id' => $id])
    : null;

if ($id > 0 && $existing === null) {
    flash('error', 'That floating button no longer exists.');
    redirect($listUrl);
}

$action = (string) input('action_type', 'link');

$data = [
    'label'             => trim((string) input('label', '')),
    'tooltip'           => trim((string) input('tooltip', '')),
    'icon'              => (string) input('icon', 'sparkle'),
    'action_type'       => $action,
    'phone'             => trim((string) input('phone', '')),
    'whatsapp_number'   => trim((string) input('whatsapp_number', '')),
    'whatsapp_message'  => trim((string) input('whatsapp_message', '')),
    'url'               => trim((string) input('url', '')),
    'open_new_tab'      => input_bool('open_new_tab') ? 1 : 0,
    'position'          => (string) input('position', 'bottom-right'),
    'size'              => (string) input('size', 'md'),
    'bg_color'          => trim((string) input('bg_color', '')),
    'text_color'        => trim((string) input('text_color', '')),
    'animation'         => (string) input('animation', 'none'),
    'show_label'        => input_bool('show_label') ? 1 : 0,
    'device_visibility' => (string) input('device_visibility', 'all'),
    'auth_visibility'   => (string) input('auth_visibility', 'all'),
    'sort_order'        => input_int('sort_order', 0),
    'status'            => input('status') === 'active' ? 'active' : 'inactive',
];

$v = new Validator($data, [
    'label'      => 'Label',
    'url'        => 'URL',
    'sort_order' => 'Order',
    'icon'       => 'Icon',
]);

$v->required('label')->max('label', 60)
  ->max('tooltip', 120)
  ->in('icon', icon_names())
  ->in('action_type', array_keys(floating_action_types()))
  ->in('position', array_keys(floating_positions()))
  ->in('size', array_keys(floating_sizes()))
  ->in('animation', array_keys(floating_animations()))
  ->in('device_visibility', array_keys(floating_device_options()))
  ->in('auth_visibility', array_keys(floating_auth_options()))
  ->max('phone', 30)->max('whatsapp_number', 30)->max('whatsapp_message', 255)->max('url', 255)
  ->between('sort_order', 0, 9999);

// A link button with no link is the one mistake that would render a control
// that goes nowhere, so it is caught here rather than silently dropped at
// render time. The other three action types can fall back: an empty WhatsApp
// or phone field borrows the store number, and scroll_top needs nothing.
if ($data['action_type'] === 'link' && $data['url'] === '') {
    $v->rule('url', false, 'A link button needs a URL. Use a page such as contact.php, a full https:// address, or mailto:/tel:.');
}

foreach (['bg_color' => 'Background colour', 'text_color' => 'Icon colour'] as $colorKey => $colorLabel) {
    if ($data[$colorKey] !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $data[$colorKey]) !== 1) {
        $v->rule($colorKey, false, $colorLabel . ' must be a 6-digit hex colour such as #25D366, or empty for the theme default.');
    }
}

if ($v->fails()) {
    flash_old(array_merge($_POST, ['__form' => 'button:' . $id]));
    flash_errors($v->errors());
    flash('error', 'Please correct the highlighted fields.');
    redirect($listUrl);
}

// Empty means "not set" for every optional column, so the storefront's
// fallbacks (store WhatsApp number, store phone, theme colours) can tell an
// empty field from a deliberate blank string.
foreach (['tooltip', 'phone', 'whatsapp_number', 'whatsapp_message', 'url', 'bg_color', 'text_color'] as $nullable) {
    $data[$nullable] = $data[$nullable] !== '' ? $data[$nullable] : null;
}

if ($existing === null) {
    // button_key is the stable slug the seed rows use. A hand-made button gets
    // one derived from its label so the column stays meaningful and unique.
    $base = slugify($data['label']) ?: 'button';
    $base = substr(str_replace('-', '_', $base), 0, 30);
    $key  = $base;
    $n    = 1;
    while (Database::fetchColumn('SELECT `id` FROM `floating_buttons` WHERE `button_key` = :k', ['k' => $key]) !== null) {
        $key = $base . '_' . (++$n);
    }

    $id = Database::insert('floating_buttons', $data + ['button_key' => $key]);
    log_activity('floating_button.created', 'floating_button', $id, 'Added floating button "' . $data['label'] . '"');
    $message = 'Floating button "' . $data['label'] . '" added.';
} else {
    Database::update('floating_buttons', $data, '`id` = :id', ['id' => $id]);
    log_activity('floating_button.updated', 'floating_button', $id, 'Updated floating button "' . $data['label'] . '"');
    $message = 'Floating button "' . $data['label'] . '" saved.';
}

admin_after_write();

flash('success', $message);
redirect($listUrl);
