<?php
/**
 * ShopInnKart Admin - Edit an attribute and its values.
 *
 * Removing a value cascades into product_variant_attributes, which would
 * silently break a live variant, so a value that is in use is never deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('attributes.edit');

$id = input_int('id');
$attribute = $id > 0
    ? Database::fetch('SELECT * FROM `attributes` WHERE `id` = :id', ['id' => $id])
    : null;

if ($attribute === null) {
    flash('error', 'That attribute no longer exists.');
    redirect(admin_url('attributes/'));
}

/** value id => number of product variants pinned to it. */
$usageByValue = Database::fetchPairs(
    'SELECT av.`id`, COUNT(DISTINCT pva.`variant_id`)
     FROM `attribute_values` av
     LEFT JOIN `product_variant_attributes` pva ON pva.`attribute_value_id` = av.`id`
     WHERE av.`attribute_id` = :id
     GROUP BY av.`id`',
    ['id' => $id]
);
$usageByValue = array_map('intval', $usageByValue);

$existingIds = array_map('intval', array_keys($usageByValue));

/** attributes.slug is unique and unique_slug() does not cover this table. */
$uniqueAttributeSlug = static function (string $base, int $ignoreId): string {
    $slug = $base;
    $suffix = 1;
    while (Database::fetchColumn(
        'SELECT `id` FROM `attributes` WHERE `slug` = :slug AND `id` <> :id LIMIT 1',
        ['slug' => $slug, 'id' => $ignoreId]
    ) !== null) {
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
    return $slug;
};

$errors = [];
$values = [];

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

        // Only ids that really belong to this attribute may be updated in place.
        $rowId = (int) ($rowIds[$i] ?? 0);
        if (!in_array($rowId, $existingIds, true)) {
            $rowId = 0;
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
            'id'         => $rowId,
            'value'      => mb_substr($name, 0, 150),
            'slug'       => mb_substr($slug, 0, 180),
            'color_code' => $submitted['type'] === 'color' && $color !== '' ? $color : null,
            'sort_order' => isset($rowSorts[$i]) && is_numeric($rowSorts[$i]) ? (int) $rowSorts[$i] : $i,
            'in_use'     => $rowId > 0 ? ($usageByValue[$rowId] ?? 0) : 0,
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
            slugify($submitted['slug'] !== '' ? $submitted['slug'] : $submitted['name']),
            $id
        );

        $keptIds   = array_values(array_filter(array_column($rows, 'id')));
        $removeIds = array_values(array_diff($existingIds, $keptIds));

        // A value a variant depends on stays put; the admin is told why.
        $blockedIds = array_values(array_filter(
            $removeIds,
            static fn (int $valueId): bool => ($usageByValue[$valueId] ?? 0) > 0
        ));
        $removeIds = array_values(array_diff($removeIds, $blockedIds));

        Database::transaction(static function () use ($id, $submitted, $slug, $rows, $removeIds): void {
            Database::update('attributes', [
                'name'       => $submitted['name'],
                'slug'       => $slug,
                'type'       => $submitted['type'],
                'is_variant' => $submitted['is_variant'],
                'is_filter'  => $submitted['is_filter'],
                'sort_order' => $submitted['sort_order'],
                'status'     => $submitted['status'],
            ], '`id` = :id', ['id' => $id]);

            foreach ($removeIds as $valueId) {
                Database::delete('attribute_values', '`id` = :id AND `attribute_id` = :attr', [
                    'id'   => $valueId,
                    'attr' => $id,
                ]);
            }

            // Park every surviving row on a throwaway slug first. Without this,
            // swapping two values' slugs would trip uq_attr_value mid-update.
            foreach ($rows as $row) {
                if ($row['id'] > 0) {
                    Database::update('attribute_values', ['slug' => '__tmp_' . $row['id']], '`id` = :id', ['id' => $row['id']]);
                }
            }

            foreach ($rows as $row) {
                $data = [
                    'value'      => $row['value'],
                    'slug'       => $row['slug'],
                    'color_code' => $row['color_code'],
                    'sort_order' => $row['sort_order'],
                ];

                if ($row['id'] > 0) {
                    Database::update('attribute_values', $data, '`id` = :id', ['id' => $row['id']]);
                } else {
                    Database::insert('attribute_values', $data + ['attribute_id' => $id]);
                }
            }
        });

        log_activity(
            'attribute.updated',
            'attribute',
            $id,
            'Updated attribute "' . $submitted['name'] . '" — ' . count($rows) . ' value(s), '
                . count($removeIds) . ' removed'
        );
        admin_after_write();

        if ($blockedIds !== []) {
            flash('warning', count($blockedIds) . ' value(s) could not be removed because product variants still use them.');
        }
        flash('success', 'Attribute "' . $submitted['name'] . '" saved.');
        redirect(admin_url('attributes/edit.php?id=' . $id));
    }
}

if ($values === []) {
    $values = Database::fetchAll(
        'SELECT av.`id`, av.`value`, av.`slug`, av.`color_code`, av.`sort_order`,
                (SELECT COUNT(DISTINCT pva.`variant_id`) FROM `product_variant_attributes` pva
                 WHERE pva.`attribute_value_id` = av.`id`) AS in_use
         FROM `attribute_values` av
         WHERE av.`attribute_id` = :id
         ORDER BY av.`sort_order` ASC, av.`value` ASC',
        ['id' => $id]
    );
}

$variantUse = (int) Database::fetchColumn(
    'SELECT COUNT(DISTINCT pva.`variant_id`) FROM `product_variant_attributes` pva WHERE pva.`attribute_id` = :id',
    ['id' => $id]
);

$isEdit       = true;
$pageTitle    = 'Edit Attribute';
$pageSubtitle = $attribute['name'] . ' · ' . count($values) . ' value' . (count($values) === 1 ? '' : 's')
    . ' · used by ' . $variantUse . ' variant' . ($variantUse === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Attributes', 'url' => admin_url('attributes/')],
    ['label' => (string) $attribute['name']],
];

$pageActions = '';
if (admin_can('attributes.delete') && $variantUse === 0) {
    $confirm = json_encode(
        'Delete "' . $attribute['name'] . '" and its ' . count($values) . ' value(s)? This cannot be undone.'
    );
    $pageActions = '<form method="post" action="' . e(admin_url('attributes/delete.php')) . '" class="ad-inline-form"'
        . ' onsubmit="return confirm(' . e_attr((string) $confirm) . ')">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($variantUse > 0): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            <?= number_format($variantUse) ?> product variant<?= $variantUse === 1 ? '' : 's' ?>
            depend<?= $variantUse === 1 ? 's' : '' ?> on this attribute, so it cannot be deleted and the
            values in use cannot be removed. Renaming a value is safe.
        </div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
