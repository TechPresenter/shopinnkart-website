<?php
/**
 * ShopInnKart Admin - Create or update a footer column.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 * A failed submission goes back to the builder through the flash bag, tagged
 * with __form so the right one of the many forms on that page shows the errors.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_meta.php';

$admin = admin_require_action('homepage.edit');

// One list, shared with the builder screen. The hand-kept copy that used to sit
// here disagreed with the column enum in both directions: it offered
// 'newsletter', which the storefront drew as a plain link list, and it withheld
// 'payment', which the enum allowed and nothing could ever select.
$types   = array_keys(footer_column_types());
$listUrl = admin_url('footer/');

$id = input_int('id');
$existing = $id > 0
    ? Database::fetch('SELECT * FROM `footer_columns` WHERE `id` = :id', ['id' => $id])
    : null;

if ($id > 0 && $existing === null) {
    flash('error', 'That footer column no longer exists.');
    redirect($listUrl);
}

$data = [
    'title'       => (string) input('title', ''),
    'column_type' => (string) input('column_type', 'links'),
    'content'     => sanitize_html((string) input('content', '')),
    'sort_order'  => input_int('sort_order', 0),
    'status'      => (string) input('status', 'active'),
];

$v = new Validator($data, ['title' => 'Title', 'sort_order' => 'Sort order']);
$v->required('title')->max('title', 120)
  ->in('column_type', $types)
  ->in('status', ['active', 'inactive'])
  ->between('sort_order', 0, 9999);

if ($v->fails()) {
    flash_old(array_merge($_POST, ['__form' => 'column:' . $id]));
    flash_errors($v->errors());
    flash('error', 'Please correct the highlighted fields.');
    redirect($listUrl);
}

$data['content'] = $data['content'] !== '' ? $data['content'] : null;

// The builder disables the Content box for a type that ignores it, and a
// disabled control posts nothing — so an untouched save would read back as ''
// and wipe text the operator never saw a field for. Keep what is stored: the
// value costs nothing, and switching the type back brings the words with it.
if ($existing !== null && !footer_type_reads_content((string) $data['column_type'])) {
    $data['content'] = $existing['content'];
}

if ($existing === null) {
    $id = Database::insert('footer_columns', $data);
    log_activity('footer_column.created', 'footer_column', $id, 'Added footer column "' . $data['title'] . '"');
    $message = 'Footer column "' . $data['title'] . '" added.';
} else {
    Database::update('footer_columns', $data, '`id` = :id', ['id' => $id]);
    log_activity('footer_column.updated', 'footer_column', $id, 'Updated footer column "' . $data['title'] . '"');
    $message = 'Footer column "' . $data['title'] . '" saved.';
}

admin_after_write();

flash('success', $message);
redirect($listUrl);
