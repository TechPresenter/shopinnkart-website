<?php
/**
 * ShopInnKart Admin - Edit a testimonial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

$id = input_int('id');
$testimonial = $id > 0
    ? Database::fetch('SELECT * FROM `testimonials` WHERE `id` = :id', ['id' => $id])
    : null;

if ($testimonial === null) {
    flash('error', 'That testimonial no longer exists.');
    redirect(admin_url('testimonials/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $submitted = [
        'customer_name' => (string) input('customer_name', ''),
        'designation'   => (string) input('designation', ''),
        'rating'        => input_int('rating', 5),
        'title'         => (string) input('title', ''),
        'message'       => trim((string) ($_POST['message'] ?? '')),
        'sort_order'    => input_int('sort_order', 0),
        'status'        => (string) input('status', 'active'),
    ];
    $testimonial = array_merge($testimonial, $submitted);

    $v = new Validator($submitted, ['customer_name' => 'Customer name', 'message' => 'Testimonial']);
    $v->required('customer_name')->max('customer_name', 120)
      ->max('designation', 120)
      ->max('title', 200)
      ->required('message')->max('message', 2000)
      ->integer('rating')->between('rating', 1, 5)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive']);

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $avatar = $testimonial['avatar'];
        if ((string) input('remove_avatar', '0') === '1' && empty($_FILES['avatar']['name'])) {
            delete_upload($avatar);
            $avatar = null;
        }
        $avatar = admin_handle_image('avatar', 'testimonials', $avatar);

        Database::update('testimonials', [
            'customer_name' => $submitted['customer_name'],
            'designation'   => $submitted['designation'] !== '' ? $submitted['designation'] : null,
            'avatar'        => $avatar,
            'rating'        => $submitted['rating'],
            'title'         => $submitted['title'] !== '' ? $submitted['title'] : null,
            'message'       => $submitted['message'],
            'sort_order'    => $submitted['sort_order'],
            'status'        => $submitted['status'],
        ], '`id` = :id', ['id' => $id]);

        log_activity('testimonial.updated', 'testimonial', $id,
            'Updated testimonial from "' . $submitted['customer_name'] . '"');
        admin_after_write();

        flash('success', 'Testimonial saved.');
        redirect(admin_url('testimonials/edit.php?id=' . $id));
    }
}

$isEdit       = true;
$pageTitle    = 'Edit Testimonial';
$pageSubtitle = $testimonial['customer_name'] . ' · ' . (int) $testimonial['rating'] . '/5';
$breadcrumbs  = [
    ['label' => 'Dashboard',    'url' => admin_url('dashboard.php')],
    ['label' => 'Testimonials', 'url' => admin_url('testimonials/')],
    ['label' => (string) $testimonial['customer_name']],
];

$confirm = 'Delete the testimonial from "' . $testimonial['customer_name'] . '"? This cannot be undone.';
$pageActions = '<form method="post" action="' . e(admin_url('testimonials/delete.php')) . '" class="ad-inline-form"'
    . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
    . csrf_field()
    . '<input type="hidden" name="id" value="' . $id . '">'
    . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
    . '</form>';

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
