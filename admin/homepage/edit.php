<?php
/**
 * ShopInnKart Admin - Edit a homepage section.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';
// The nested rows -> items model, shared with the storefront renderer.
require_once INCLUDES_PATH . '/homepage-rows.php';

$id = input_int('id');
$section = $id > 0
    ? Database::fetch('SELECT * FROM `homepage_sections` WHERE `id` = :id', ['id' => $id])
    : null;

if ($section === null) {
    flash('error', 'That section no longer exists.');
    redirect(admin_url('homepage/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    // PHP dropped the tail of this post (max_input_vars). Half of it is here
    // and half is not, and the half that is missing reads as "clear that
    // column" - so nothing is written at all. See the note on the function.
    if (homepage_rows_post_truncated()) {
        flash('error', 'That section has more rows and items than one form post can carry, '
            . 'so nothing was saved. Delete a row or an item and save again.');
        redirect(admin_url('homepage/edit.php?id=' . $id));
    }

    $data   = homepage_form_input();
    $errors = homepage_validate($data);

    // The rows fieldset, when this form carried one. null means "this post
    // says nothing about rows", which leaves whatever is stored alone.
    $rowNotes = [];
    $rows = homepage_rows_input($rowNotes);

    $stored = $section;
    $section = array_merge($section, $data);

    if ($errors !== []) {
        flash('error', 'Please correct the highlighted fields.');
        // Keep the picker and the rows showing what was submitted, not what
        // is stored, so a failed save does not throw the editing away.
        $section['settings'] = homepage_rows_merge_settings(
            homepage_merge_settings($stored['settings'], $data['product_ids']),
            $rows
        );
    } else {
        $columns = homepage_columns($data);
        $columns['section_key'] = homepage_unique_key($data['section_key'], $id);
        // Two merges, one column: each one preserves every key it does not
        // own, so product_ids and rows cannot overwrite one another.
        $columns['settings']    = homepage_rows_merge_settings(
            homepage_merge_settings($stored['settings'], $data['product_ids']),
            $rows
        );

        // "Remove" clears the stored path and deletes the file; a new upload
        // replaces it and admin_handle_image() removes the old one for us.
        $image = $stored['image'];
        if (input_bool('remove_image')) {
            delete_upload($image);
            $image = null;
        }
        $columns['image'] = admin_handle_image('image', 'widgets', $image);

        $mobile = $stored['mobile_image'];
        if (input_bool('remove_mobile_image')) {
            delete_upload($mobile);
            $mobile = null;
        }
        $columns['mobile_image'] = admin_handle_image('mobile_image', 'widgets', $mobile);

        Database::update('homepage_sections', $columns, '`id` = :id', ['id' => $id]);

        log_activity('homepage_section.updated', 'homepage_section', $id,
            'Updated section "' . $columns['section_key'] . '" in the ' . $columns['zone'] . ' zone');
        admin_after_write();

        flash('success', 'Section "' . $columns['section_key'] . '" saved.');
        // Say so when the caps trimmed something, rather than leave an admin
        // hunting for the row that silently did not save.
        foreach ($rowNotes as $note) {
            flash('warning', $note);
        }

        // The designer posts here too, and must get its own page back.
        // admin_safe_return() refuses anything outside this admin, so a
        // crafted ?return= cannot turn a save into an open redirect.
        redirect(admin_safe_return(
            (string) input('return', ''),
            admin_url('homepage/?zone=' . urlencode((string) $columns['zone']))
        ));
    }
}

$isEdit    = true;
$zoneMeta  = homepage_zones()[(string) $section['zone']] ?? homepage_zones()['home'];
$pageTitle = 'Edit Section';
$pageSubtitle = homepage_widget_label((string) $section['widget_type']) . ' in ' . $zoneMeta['label']
    . ' · ' . $section['section_key'];
$breadcrumbs = [
    ['label' => 'Dashboard',        'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder', 'url' => admin_url('homepage/?zone=' . urlencode((string) $section['zone']))],
    ['label' => 'Edit Section'],
];

$pageActions = '<a class="ad-btn" target="_blank" rel="noopener" href="'
    . e(homepage_preview_url($section)) . '">' . icon('external', 'w-4 h-4') . ' Preview</a>';

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
