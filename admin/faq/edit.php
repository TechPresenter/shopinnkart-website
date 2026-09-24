<?php
/**
 * ShopInnKart Admin - Edit an FAQ entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('faq.edit');

$id = input_int('id');
$faq = $id > 0
    ? Database::fetch('SELECT * FROM `faqs` WHERE `id` = :id', ['id' => $id])
    : null;

if ($faq === null) {
    flash('error', 'That question no longer exists.');
    redirect(admin_url('faq/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $submitted = [
        'category'   => (string) input('category', 'General'),
        'question'   => (string) input('question', ''),
        'answer'     => trim((string) ($_POST['answer'] ?? '')),
        'sort_order' => input_int('sort_order', 0),
        'status'     => (string) input('status', 'active'),
    ];
    $faq = array_merge($faq, $submitted);

    $v = new Validator($submitted, ['category' => 'Category', 'question' => 'Question', 'answer' => 'Answer']);
    $v->required('category')->max('category', 100)
      ->required('question')->max('question', 500)
      ->required('answer')->max('answer', 5000)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', ['active', 'inactive']);

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        Database::update('faqs', [
            'category'   => $submitted['category'],
            'question'   => $submitted['question'],
            'answer'     => $submitted['answer'],
            'sort_order' => $submitted['sort_order'],
            'status'     => $submitted['status'],
        ], '`id` = :id', ['id' => $id]);

        log_activity('faq.updated', 'faq', $id, 'Updated FAQ "' . str_limit($submitted['question'], 60) . '"');
        admin_after_write();

        flash('success', 'Question saved.');
        redirect(admin_url('faq/edit.php?id=' . $id));
    }
}

$categories = Database::fetchColumnAll('SELECT DISTINCT `category` FROM `faqs` ORDER BY `category`');

$isEdit       = true;
$pageTitle    = 'Edit Question';
$pageSubtitle = (string) $faq['category'];
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'FAQ',       'url' => admin_url('faq/')],
    ['label' => str_limit((string) $faq['question'], 50)],
];

$pageActions = '<a class="ad-btn" href="' . e(url('faq')) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View on store</a>';

if (admin_can('faq.delete')) {
    $confirm = 'Delete "' . str_limit((string) $faq['question'], 70) . '"? This cannot be undone.';
    $pageActions .= '<form method="post" action="' . e(admin_url('faq/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
