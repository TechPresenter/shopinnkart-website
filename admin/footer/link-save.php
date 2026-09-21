<?php
/**
 * ShopInnKart Admin - Create or update a footer link.
 *
 * POST + CSRF + permission, enforced by admin_require_action().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('homepage.edit');

$listUrl  = admin_url('footer/');
$id       = input_int('id');
$columnId = input_int('column_id');

$existing = $id > 0
    ? Database::fetch('SELECT * FROM `footer_links` WHERE `id` = :id', ['id' => $id])
    : null;

if ($id > 0 && $existing === null) {
    flash('error', 'That footer link no longer exists.');
    redirect($listUrl);
}

// An edit keeps its own column unless the form explicitly moved it.
if ($columnId <= 0 && $existing !== null) {
    $columnId = (int) $existing['column_id'];
}

$column = $columnId > 0
    ? Database::fetch('SELECT `id`, `title` FROM `footer_columns` WHERE `id` = :id', ['id' => $columnId])
    : null;

if ($column === null) {
    flash('error', 'That footer column no longer exists.');
    redirect($listUrl);
}

$data = [
    'column_id'    => $columnId,
    'label'        => (string) input('label', ''),
    'url'          => (string) input('url', ''),
    'open_new_tab' => input_bool('open_new_tab') ? 1 : 0,
    'sort_order'   => input_int('sort_order', 0),
    'status'       => (string) input('status', 'active'),
];

$v = new Validator($data, ['label' => 'Label', 'url' => 'URL', 'sort_order' => 'Sort order']);
$v->required('label')->max('label', 120)
  ->required('url')->max('url', 255)
  ->in('status', ['active', 'inactive'])
  ->between('sort_order', 0, 9999);

if ($v->fails()) {
    // A new link belongs to a per-column form, so its token carries the column.
    flash_old(array_merge($_POST, [
        '__form' => $id > 0 ? 'link:' . $id : 'link:0:' . $columnId,
    ]));
    flash_errors($v->errors());
    flash('error', 'Please correct the highlighted fields.');
    redirect($listUrl);
}

if ($existing === null) {
    // A new link lands at the end of its column unless a position was typed.
    if ($data['sort_order'] === 0) {
        $data['sort_order'] = 1 + (int) Database::fetchColumn(
            'SELECT COALESCE(MAX(`sort_order`), 0) FROM `footer_links` WHERE `column_id` = :id',
            ['id' => $columnId]
        );
    }

    $id = Database::insert('footer_links', $data);
    log_activity('footer_link.created', 'footer_link', $id,
        'Added "' . $data['label'] . '" to the "' . $column['title'] . '" footer column');
    $message = 'Link "' . $data['label'] . '" added.';
} else {
    Database::update('footer_links', $data, '`id` = :id', ['id' => $id]);
    log_activity('footer_link.updated', 'footer_link', $id,
        'Updated "' . $data['label'] . '" in the "' . $column['title'] . '" footer column');
    $message = 'Link "' . $data['label'] . '" saved.';
}

admin_after_write();

flash('success', $message);
redirect($listUrl);
