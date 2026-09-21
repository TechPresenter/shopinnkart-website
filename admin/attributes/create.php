<?php
/**
 * ShopInnKart Admin - Create an attribute and its values.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('attributes.create');

$attribute = [
    'name'       => '',
    'slug'       => '',
    'type'       => 'select',
    'is_variant' => 1,
    'is_filter'  => 1,
    'sort_order' => 0,
    'status'     => 'active',
];
$values = [];
$errors = [];

/** attributes.slug is unique and unique_slug() does not cover this table. */
$uniqueAttributeSlug = static function (string $base): string {
    $slug = $base;
    $suffix = 1;
    while (Database::fetchColumn('SELECT `id` FROM `attributes` WHERE `slug` = :slug LIMIT 1', ['slug' => $slug]) !== null) {
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
    return $slug;
};

if (is_post()) {
    csrf_require();

    $submitted = [
        'name'       => (string) input('name', ''),
        'slug'       => (string) input('slug', ''),
        'type'       => (string) input('type', 'select'),
        'is_variant' => input_bool('is_variant') ? 1 : 0,
        'is_filter'  => input_bool('is_filter') ? 1 : 0,
        'sort_order' => input_int('sort_order', 0),
        'status'     => (string) input('status', 'active'),
    ];
    $attribute = array_merge($attribute, $submitted);

    // Value rows arrive as parallel arrays, one entry per repeater row.
    $rowIds    = input_array('value_id');
    $rowNames  = input_array('value_name');
    $rowSlugs  = input_array('value_slug');
    $rowColors = input_array('value_color');
    $rowSorts  = input_array('value_sort');

    $rows     = [];
    $seenSlug = [];
    $badColor = false;

    for ($i = 0, $n = count($rowNames); $i < $n; $i++) {
        $name = trim((string) $rowNames[$i]);
        if ($name === '') {
            continue;   // an emptied row means "drop this one"
        }

        $slugBase = slugify(trim((string) ($rowSlugs[$i] ?? '')) !== '' ? (string) $rowSlugs[$i] : $name);
        $slug = $slugBase;
        $suffix = 1;
        while (isset($seenSlug[$slug])) {
            $suffix++;
            $slug = $slugBase . '-' . $suffix;
        }
        $seenSlug[$slug] = true;

        $color = trim((string) ($rowColors[$i] ?? ''));
        if ($color !== '') {
            if (preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color) !== 1) {
                $badColor = true;
            } else {
                $color = '#' . ltrim($color, '#');
            }
        }

        $rows[] = [
            'id'         => 0,
            'value'      => mb_substr($name, 0, 150),
            'slug'       => mb_substr($slug, 0, 180),
            'color_code' => $submitted['type'] === 'color' && $color !== '' ? $color : null,
            'sort_order' => isset($rowSorts[$i]) && is_numeric($rowSorts[$i]) ? (int) $rowSorts[$i] : $i,
            'in_use'     => 0,
        ];
    }
    $values = $rows;

    $v = new Validator($submitted, ['name' => 'Attribute name']);
    $v->required('name')->max('name', 100)
      ->max('slug', 120)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('type', ['select', 'color', 'text'])
      ->in('status', ['active', 'inactive'])
      ->rule('values', !$badColor, 'Colour codes must be a hex value such as #1A2B3C.');

    if ($v->fails()) {
        $errors = $v->errors();
        flash('error', 'Please correct the highlighted fields.');
    } else {
        $slug = $uniqueAttributeSlug(
            slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['name'])
        );

        $id = Database::transaction(static function () use ($submitted, $slug, $rows): int {
            $attributeId = Database::insert('attributes', [
                'name'       => $submitted['name'],
                'slug'       => $slug,
                'type'       => $submitted['type'],
                'is_variant' => $submitted['is_variant'],
                'is_filter'  => $submitted['is_filter'],
                'sort_order' => $submitted['sort_order'],
                'status'     => $submitted['status'],
            ]);

            foreach ($rows as $row) {
                Database::insert('attribute_values', [
                    'attribute_id' => $attributeId,
                    'value'        => $row['value'],
                    'slug'         => $row['slug'],
                    'color_code'   => $row['color_code'],
                    'sort_order'   => $row['sort_order'],
                ]);
            }

            return $attributeId;
        });

        log_activity(
            'attribute.created',
            'attribute',
            $id,
            'Created attribute "' . $submitted['name'] . '" with ' . count($rows) . ' value(s)'
        );
        admin_after_write();

        flash('success', 'Attribute "' . $submitted['name'] . '" created.');
        redirect(admin_url('attributes/edit.php?id=' . $id));
    }
}

$isEdit       = false;
$pageTitle    = 'Add Attribute';
$pageSubtitle = 'Attributes such as Colour, Storage or RAM build product variants and shop filters.';
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Attributes', 'url' => admin_url('attributes/')],
    ['label' => 'Add Attribute'],
];

require ADMIN_PATH . '/includes/header.php';
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
