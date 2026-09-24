<?php
/**
 * ShopInnKart Admin - Add a homepage section.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';
// The nested rows -> items model, shared with the storefront renderer.
require_once INCLUDES_PATH . '/homepage-rows.php';

$zone = admin_filter('zone', array_keys(homepage_zones()), 'home');

$section = [
    'section_key'       => '',
    'zone'              => $zone,
    'widget_type'       => 'product_grid',
    'title'             => '',
    'title_accent'      => '',
    'subtitle'          => '',
    'description'       => '',
    'custom_html'       => '',
    'link_text'         => '',
    'link_url'          => '',
    'image'             => null,
    'mobile_image'      => null,
    'data_source'       => 'auto',
    'source_id'         => null,
    'item_limit'        => 8,
    'layout'            => 'grid',
    'card_style'        => 'standard',
    'cols_desktop'      => 4,
    'cols_tablet'       => 3,
    'cols_mobile'       => 2,
    'autoplay'          => 0,
    'autoplay_speed'    => 4000,
    'show_arrows'       => 1,
    'show_dots'         => 1,
    'animation'         => 'fade-up',
    'bg_color'          => '',
    'text_color'        => '',
    'container'         => 'boxed',
    'padding'           => 'md',
    'device_visibility' => 'all',
    'auth_visibility'   => 'all',
    'start_date'        => null,
    'end_date'          => null,
    'lazy_load'         => 0,
    'settings'          => null,
    // A new section lands at the bottom of its zone unless the admin says otherwise.
    'sort_order'        => 1 + (int) Database::fetchColumn(
        'SELECT COALESCE(MAX(`sort_order`), 0) FROM `homepage_sections` WHERE `zone` = :zone',
        ['zone' => $zone]
    ),
    'status'            => 'active',
];
$errors = [];

if (is_post()) {
    csrf_require();

    // PHP dropped the tail of this post (max_input_vars), so most of the
    // section's own fields never arrived. Creating a row out of the defaults
    // that are left would be worse than not creating one.
    if (homepage_rows_post_truncated()) {
        flash('error', 'That section has more rows and items than one form post can carry, '
            . 'so nothing was created. Build it with fewer rows or items and save again.');
        redirect(admin_url('homepage/create.php?zone=' . urlencode($zone)));
    }

    $data   = homepage_form_input();
    $errors = homepage_validate($data);

    $rowNotes = [];
    $rows = homepage_rows_input($rowNotes);

    // Repopulate the form from what was typed, keeping the picker selection
    // and any rows that were built before the validation failed.
    $section = array_merge($section, $data, [
        'settings' => homepage_rows_merge_settings(
            homepage_merge_settings(null, $data['product_ids']),
            $rows
        ),
    ]);

    if ($errors !== []) {
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $columns = homepage_columns($data);
        $columns['section_key']  = homepage_unique_key($data['section_key']);
        $columns['image']        = admin_handle_image('image', 'widgets');
        $columns['mobile_image'] = admin_handle_image('mobile_image', 'widgets');
        $columns['settings']     = $section['settings'];

        $id = Database::insert('homepage_sections', $columns);

        log_activity('homepage_section.created', 'homepage_section', $id,
            'Added ' . homepage_widget_label($columns['widget_type']) . ' section "' . $columns['section_key']
            . '" to the ' . $columns['zone'] . ' zone');
        admin_after_write();

        flash('success', 'Section "' . $columns['section_key'] . '" created.');
        foreach ($rowNotes as $note) {
            flash('warning', $note);
        }
        redirect(admin_url('homepage/edit.php?id=' . $id));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Section';
$zoneMeta     = homepage_zones()[$section['zone']] ?? homepage_zones()['home'];
$pageSubtitle = 'Place a new widget in the ' . $zoneMeta['label'] . ' zone.';
$breadcrumbs  = [
    ['label' => 'Dashboard',         'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder',  'url' => admin_url('homepage/?zone=' . urlencode((string) $section['zone']))],
    ['label' => 'Add Section'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
