<?php
/**
 * ShopInnKart Admin - Add an FAQ entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('faq.create');

$faq = [
    'category'   => 'General',
    'question'   => '',
    'answer'     => '',
    'sort_order' => 0,
    'status'     => 'active',
];
$errors = [];

if (is_post()) {
    csrf_require();

    $submitted = [
        'category'   => (string) input('category', 'General'),
        'question'   => (string) input('question', ''),
        'answer'     => (string) ($_POST['answer'] ?? ''),
        'sort_order' => input_int('sort_order', 0),
        'status'     => (string) input('status', 'active'),
    ];
    $submitted['answer'] = trim($submitted['answer']);
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
        $id = Database::insert('faqs', [
            'category'   => $submitted['category'],
            'question'   => $submitted['question'],
            'answer'     => $submitted['answer'],
            'sort_order' => $submitted['sort_order'],
            'status'     => $submitted['status'],
        ]);

        log_activity('faq.created', 'faq', $id,
            'Added FAQ "' . str_limit($submitted['question'], 60) . '" to ' . $submitted['category']);
        admin_after_write();

        flash('success', 'Question added to "' . $submitted['category'] . '".');
        redirect(admin_url('faq/'));
    }
}

$categories = Database::fetchColumnAll('SELECT DISTINCT `category` FROM `faqs` ORDER BY `category`');

$isEdit       = false;
$pageTitle    = 'Add Question';
$pageSubtitle = 'Questions are grouped by category on the storefront FAQ page.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'FAQ',       'url' => admin_url('faq/')],
    ['label' => 'Add Question'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
