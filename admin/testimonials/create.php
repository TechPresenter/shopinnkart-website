<?php
/**
 * ShopInnKart Admin - Add a testimonial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

$testimonial = [
    'customer_name' => '',
    'designation'   => 'Verified Buyer',
    'avatar'        => null,
    'rating'        => 5,
    'title'         => '',
    'message'       => '',
    'sort_order'    => 0,
    'status'        => 'active',
];
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
        $avatar = admin_handle_image('avatar', 'testimonials');

        $id = Database::insert('testimonials', [
            'customer_name' => $submitted['customer_name'],
            'designation'   => $submitted['designation'] !== '' ? $submitted['designation'] : null,
            'avatar'        => $avatar,
            'rating'        => $submitted['rating'],
            'title'         => $submitted['title'] !== '' ? $submitted['title'] : null,
            'message'       => $submitted['message'],
            'sort_order'    => $submitted['sort_order'],
            'status'        => $submitted['status'],
        ]);

        log_activity('testimonial.created', 'testimonial', $id,
            'Added testimonial from "' . $submitted['customer_name'] . '"');
        admin_after_write();

        flash('success', 'Testimonial from "' . $submitted['customer_name'] . '" added.');
        redirect(admin_url('testimonials/'));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Testimonial';
$pageSubtitle = 'Testimonials feed the social-proof block on the homepage.';
$breadcrumbs  = [
    ['label' => 'Dashboard',    'url' => admin_url('dashboard.php')],
    ['label' => 'Testimonials', 'url' => admin_url('testimonials/')],
    ['label' => 'Add Testimonial'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
