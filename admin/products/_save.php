<?php
/**
 * ShopInnKart Admin - Product form validation + persistence.
 *
 * create.php and edit.php both post into product_form_save(), so the two
 * screens can only ever differ by which row they are pointed at.
 * Nothing is written until every field (product, variants, children) passes,
 * which is what lets a failed submit re-render without touching the database.
 */

declare(strict_types=1);


// Include-only: this partial assumes its parent page already ran the
// authentication and permission checks. Refuse to run as an entry point.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
require_once ADMIN_PATH . '/products/_shared.php';
// seo_editor_input() reads the nine fields the shared SEO tab posts.
require_once ADMIN_PATH . '/includes/seo-editor.php';

// ===========================================================================
//  Reading the request
// ===========================================================================

/** Blank product row used by create.php. */
function product_form_defaults(): array
{
    return [
        'id'                  => 0,
        'name'                => '',
        'slug'                => '',
        'sku'                 => '',
        'brand_id'            => '',
        'category_id'         => '',
        'short_description'   => '',
        'description'         => '',
        'price'               => '',
        'sale_price'          => '',
        'cost_price'          => '',
        'stock'               => '0',
        'low_stock_threshold' => (string) setting_int('low_stock_threshold', 5),
        'weight'              => '',
        'tax_rate'            => '18.00',
        'hsn_code'            => '',
        'warranty'            => '',
        'emi_text'            => '',
        'manufacturer'        => '',
        'model_number'        => '',
        'part_number'         => '',
        'compatibility'       => '',
        'main_image'          => null,
        'hover_image'         => null,
        'video_url'           => '',
        'badge_text'          => '',
        'badge_color'         => '',
        'min_order_qty'       => '1',
        'max_order_qty'       => '10',
        'cod_available'       => 1,
        'free_shipping'       => 0,
        'status'              => 'active',
        'published_at'        => '',
        'is_featured'         => 0,
        'is_new_arrival'      => 0,
        'is_best_seller'      => 0,
        'is_trending'         => 0,
        'has_variants'        => 0,
        'meta_title'          => '',
        'meta_description'    => '',
    ];
}

/** Every scalar field the form posts, trimmed. Numbers stay strings until validated. */
function product_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        'name'                => $text('name'),
        'slug'                => $text('slug'),
        'sku'                 => $text('sku'),
        'brand_id'            => $text('brand_id'),
        'category_id'         => $text('category_id'),
        'short_description'   => $text('short_description'),
        'description'         => sanitize_html((string) ($_POST['description'] ?? '')),
        'price'               => $text('price'),
        'sale_price'          => $text('sale_price'),
        'cost_price'          => $text('cost_price'),
        'stock'               => $text('stock'),
        'low_stock_threshold' => $text('low_stock_threshold'),
        'weight'              => $text('weight'),
        'tax_rate'            => $text('tax_rate'),
        'hsn_code'            => $text('hsn_code'),
        'warranty'            => $text('warranty'),
        'emi_text'            => $text('emi_text'),
        'manufacturer'        => $text('manufacturer'),
        'model_number'        => $text('model_number'),
        'part_number'         => $text('part_number'),
        'compatibility'       => $text('compatibility'),
        'min_order_qty'       => $text('min_order_qty'),
        'max_order_qty'       => $text('max_order_qty'),
        'cod_available'       => input_bool('cod_available') ? 1 : 0,
        'free_shipping'       => input_bool('free_shipping') ? 1 : 0,
        'status'              => $text('status'),
        'published_at'        => $text('published_at'),
        'is_featured'         => input_bool('is_featured') ? 1 : 0,
        'is_new_arrival'      => input_bool('is_new_arrival') ? 1 : 0,
        'is_best_seller'      => input_bool('is_best_seller') ? 1 : 0,
        'is_trending'         => input_bool('is_trending') ? 1 : 0,
        'badge_text'          => $text('badge_text'),
        'badge_color'         => $text('badge_color'),
        'video_url'           => $text('video_url'),
        // The SEO tab posts nine fields through the shared editor. Reading them
        // with seo_editor_input() rather than by hand is what keeps every entity
        // form validating robots and custom JSON-LD the same way.
        ...seo_editor_input(),
    ];
}

/**
 * Variant rows rebuilt from the parallel form arrays.
 * Parallel arrays (rather than variants[0][sku]) keep the rows aligned even
 * after the repeater has had rows removed and re-added.
 */
function product_form_variants(): array
{
    $ids      = input_array('variant_id');
    $keys     = input_array('variant_key');
    $skus     = input_array('variant_sku');
    $names    = input_array('variant_name');
    $prices   = input_array('variant_price');
    $sales    = input_array('variant_sale_price');
    $stocks   = input_array('variant_stock');
    $statuses = input_array('variant_status');
    $default  = (string) input('variant_default', '');

    $attributes = product_variant_attributes();
    $attributeInput = [];
    foreach ($attributes as $attribute) {
        $attributeInput[(int) $attribute['id']] = input_array('variant_attr_' . (int) $attribute['id']);
    }

    $rows = [];
    foreach ($skus as $i => $sku) {
        $sku  = trim((string) $sku);
        $name = trim((string) ($names[$i] ?? ''));

        // A completely blank row is the repeater's leftover, not a variant.
        if ($sku === '' && $name === '') {
            continue;
        }

        $key = (string) ($keys[$i] ?? $i);
        $attrs = [];
        foreach ($attributeInput as $attributeId => $values) {
            $valueId = (int) ($values[$i] ?? 0);
            if ($valueId > 0) {
                $attrs[$attributeId] = $valueId;
            }
        }

        $rows[] = [
            'row'          => $i,
            'id'           => (int) ($ids[$i] ?? 0),
            'key'          => $key,
            'sku'          => $sku,
            'variant_name' => $name,
            'price'        => trim((string) ($prices[$i] ?? '')),
            'sale_price'   => trim((string) ($sales[$i] ?? '')),
            'stock'        => trim((string) ($stocks[$i] ?? '')),
            'status'       => ($statuses[$i] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'is_default'   => $key !== '' && $key === $default ? 1 : 0,
            'attrs'        => $attrs,
        ];
    }

    // Exactly one default, so the PDP always has a variant to pre-select.
    $hasDefault = false;
    foreach ($rows as $row) {
        if ($row['is_default'] === 1) {
            $hasDefault = true;
            break;
        }
    }
    if (!$hasDefault && $rows !== []) {
        $rows[0]['is_default'] = 1;
    }

    return $rows;
}

/** Specification rows from the repeater's parallel arrays. */
function product_form_specs(): array
{
    $groups = input_array('spec_group');
    $keys   = input_array('spec_key');
    $values = input_array('spec_value');

    $rows = [];
    foreach ($keys as $i => $key) {
        $key   = trim((string) $key);
        $value = trim((string) ($values[$i] ?? ''));
        if ($key === '' || $value === '') {
            continue;
        }
        $group = trim((string) ($groups[$i] ?? ''));
        $rows[] = [
            'spec_group' => mb_substr($group === '' ? 'General' : $group, 0, 100),
            'spec_key'   => mb_substr($key, 0, 150),
            'spec_value' => mb_substr($value, 0, 500),
        ];
    }
    return $rows;
}

/** Feature bullets from the repeater. */
function product_form_features(): array
{
    $rows = [];
    foreach (input_array('feature') as $feature) {
        $feature = trim((string) $feature);
        if ($feature !== '') {
            $rows[] = mb_substr($feature, 0, 300);
        }
    }
    return $rows;
}

// ===========================================================================
//  Validation
// ===========================================================================

/**
 * Validate the whole submission. Returns field => message.
 * Variant errors are keyed variant_<row>_<field> so the row can highlight itself.
 */
function product_form_validate(array $in, array $variants, ?int $productId): array
{
    $slug = $in['slug'] !== '' ? slugify($in['slug']) : slugify($in['name']);

    $data = $in;
    $data['slug'] = $slug;

    $v = new Validator($data, [
        'sku'                 => 'SKU',
        'hsn_code'            => 'HSN code',
        'low_stock_threshold' => 'Low stock threshold',
        'min_order_qty'       => 'Minimum order quantity',
        'max_order_qty'       => 'Maximum order quantity',
        'meta_title'          => 'Meta title',
        'meta_description'    => 'Meta description',
        'video_url'           => 'Video URL',
    ]);

    $v->required('name')->max('name', 255)
      ->required('slug')->max('slug', 280)
      ->regex('slug', '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'Slug may only contain lowercase letters, numbers and hyphens.')
      ->unique('slug', 'products', 'slug', $productId, 'That slug is already used by another product.')
      ->required('sku')->max('sku', 80)
      ->unique('sku', 'products', 'sku', $productId, 'That SKU is already used by another product.')
      ->required('price')->numeric('price')
      ->numeric('sale_price')->numeric('cost_price')->numeric('weight')
      ->integer('stock')->integer('low_stock_threshold')
      ->numeric('tax_rate')->between('tax_rate', 0, 100)
      ->integer('min_order_qty')->integer('max_order_qty')
      ->max('hsn_code', 20)->max('warranty', 150)->max('emi_text', 150)
      ->max('manufacturer', 150)->max('model_number', 100)->max('part_number', 100)
      ->max('compatibility', 500)->max('badge_text', 40)
      ->max('video_url', 255)->max('meta_title', 255)
      ->in('status', array_keys(product_status_options()))
      ->in('badge_color', array_keys(product_badge_colors()));

    if ($in['video_url'] !== '') {
        $v->url('video_url');
    }
    if ($in['published_at'] !== '') {
        $v->date('published_at');
    }
    if ($in['category_id'] !== '') {
        $v->exists('category_id', 'categories');
    }
    if ($in['brand_id'] !== '') {
        $v->exists('brand_id', 'brands');
    }

    $v->rule('price', !is_numeric($in['price']) || (float) $in['price'] >= 0, 'Price cannot be negative.');
    $v->rule(
        'sale_price',
        $in['sale_price'] === '' || !is_numeric($in['sale_price']) || !is_numeric($in['price'])
            || ((float) $in['sale_price'] > 0 && (float) $in['sale_price'] < (float) $in['price']),
        'Sale price must be greater than zero and lower than the price.'
    );
    $v->rule('min_order_qty', $in['min_order_qty'] === '' || (int) $in['min_order_qty'] >= 1, 'Minimum order quantity must be at least 1.');
    $v->rule(
        'max_order_qty',
        $in['max_order_qty'] === '' || $in['min_order_qty'] === '' || (int) $in['max_order_qty'] >= (int) $in['min_order_qty'],
        'Maximum order quantity cannot be below the minimum.'
    );
    $v->rule('stock', $in['stock'] === '' || (int) $in['stock'] >= 0, 'Stock cannot be negative.');

    $errors = $v->errors();

    // --- variants ----------------------------------------------------------
    $seenSkus = [];
    foreach ($variants as $variant) {
        $row = (int) $variant['row'];
        $prefix = 'variant_' . $row . '_';
        $sku = $variant['sku'];

        if ($sku === '') {
            $errors[$prefix . 'sku'] = 'Every variant needs its own SKU.';
        } elseif (mb_strlen($sku) > 80) {
            $errors[$prefix . 'sku'] = 'Variant SKU must not exceed 80 characters.';
        } elseif (isset($seenSkus[mb_strtolower($sku)])) {
            $errors[$prefix . 'sku'] = 'That SKU is repeated on another variant row.';
        } else {
            $seenSkus[mb_strtolower($sku)] = true;
            $clash = Database::fetchColumn(
                'SELECT `id` FROM `product_variants` WHERE `sku` = :sku AND `id` <> :id LIMIT 1',
                ['sku' => $sku, 'id' => $variant['id']]
            );
            if ($clash !== null) {
                $errors[$prefix . 'sku'] = 'That SKU already belongs to another variant.';
            }
        }

        if ($variant['variant_name'] === '') {
            $errors[$prefix . 'name'] = 'Give the variant a name, e.g. "Warm White / 5 m".';
        }
        if ($variant['price'] === '' || !is_numeric($variant['price']) || (float) $variant['price'] < 0) {
            $errors[$prefix . 'price'] = 'Enter a valid variant price.';
        }
        if ($variant['sale_price'] !== ''
            && (!is_numeric($variant['sale_price'])
                || (is_numeric($variant['price']) && (float) $variant['sale_price'] >= (float) $variant['price'])
                || (float) $variant['sale_price'] <= 0)) {
            $errors[$prefix . 'sale_price'] = 'Variant sale price must be lower than its price.';
        }
        if ($variant['stock'] !== '' && (!ctype_digit($variant['stock']) || (int) $variant['stock'] < 0)) {
            $errors[$prefix . 'stock'] = 'Variant stock must be a whole number.';
        }
    }

    return $errors;
}

// ===========================================================================
//  Uploads
// ===========================================================================

/** Store every file dropped on the gallery field. Bad files are reported, not fatal. */
function product_upload_gallery(): array
{
    $paths = [];
    if (!isset($_FILES['gallery']['name']) || !is_array($_FILES['gallery']['name'])) {
        return $paths;
    }

    foreach ($_FILES['gallery']['name'] as $i => $name) {
        if ((int) $_FILES['gallery']['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $result = upload_image([
            'name'     => $name,
            'type'     => $_FILES['gallery']['type'][$i],
            'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
            'error'    => $_FILES['gallery']['error'][$i],
            'size'     => $_FILES['gallery']['size'][$i],
        ], 'products');

        if ($result['ok']) {
            $paths[] = (string) $result['path'];
        } else {
            flash('warning', (string) $name . ': ' . (string) $result['error']);
        }
    }
    return $paths;
}

/**
 * Duplicate an uploaded file so a copied product owns its own images.
 * Sharing the path would make deleting the copy wipe the original's picture.
 */
function product_copy_upload(?string $path): ?string
{
    if ($path === null || trim($path) === '') {
        return null;
    }
    $relative = ltrim($path, '/');
    if (strpos($relative, 'uploads/') !== 0 || strpos($relative, '..') !== false) {
        return null;
    }

    $source = ROOT_PATH . '/' . $relative;
    if (!is_file($source)) {
        return null;
    }

    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $folder    = dirname($relative);
    $filename  = date('Ymd') . '-' . bin2hex(random_bytes(8)) . ($extension !== '' ? '.' . $extension : '');
    $target    = ROOT_PATH . '/' . $folder . '/' . $filename;

    if (!@copy($source, $target)) {
        return null;
    }
    @chmod($target, 0644);

    return $folder . '/' . $filename;
}

// ===========================================================================
//  Saving
// ===========================================================================

/**
 * Validate and persist the whole form.
 *
 * @return array{ok:bool,id:?int,errors:array}
 */
function product_form_save(?int $productId): array
{
    $in       = product_form_input();
    $variants = product_form_variants();
    $errors   = product_form_validate($in, $variants, $productId);

    if ($errors !== []) {
        return ['ok' => false, 'id' => $productId, 'errors' => $errors];
    }

    $current = $productId !== null
        ? Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => $productId])
        : null;

    if ($productId !== null && $current === null) {
        return ['ok' => false, 'id' => null, 'errors' => ['name' => 'That product no longer exists.']];
    }

    // Files are written before the transaction: a rolled-back save may leave an
    // orphan file behind, but it can never leave a row pointing at nothing.
    $mainImage = $current['main_image'] ?? null;
    if (input_bool('remove_main_image')) {
        delete_upload($mainImage);
        $mainImage = null;
    }
    $mainImage = admin_handle_image('main_image', 'products', $mainImage);

    $hoverImage = $current['hover_image'] ?? null;
    if (input_bool('remove_hover_image')) {
        delete_upload($hoverImage);
        $hoverImage = null;
    }
    $hoverImage = admin_handle_image('hover_image', 'products', $hoverImage);

    $galleryPaths = product_upload_gallery();

    $slug = unique_slug('products', slugify($in['slug'] !== '' ? $in['slug'] : $in['name']), $productId);

    $numeric = static fn (string $value): ?float => $value === '' || !is_numeric($value) ? null : (float) $value;

    $row = [
        'name'                => mb_substr($in['name'], 0, 255),
        'slug'                => $slug,
        'sku'                 => mb_substr($in['sku'], 0, 80),
        'brand_id'            => $in['brand_id'] === '' ? null : (int) $in['brand_id'],
        'category_id'         => $in['category_id'] === '' ? null : (int) $in['category_id'],
        'short_description'   => $in['short_description'] !== '' ? $in['short_description'] : null,
        'description'         => $in['description'] !== '' ? $in['description'] : null,
        'price'               => (float) $in['price'],
        'sale_price'          => $numeric($in['sale_price']),
        'cost_price'          => $numeric($in['cost_price']),
        'low_stock_threshold' => $in['low_stock_threshold'] === '' ? 5 : (int) $in['low_stock_threshold'],
        'weight'              => $numeric($in['weight']),
        'tax_rate'            => $in['tax_rate'] === '' ? 18.0 : (float) $in['tax_rate'],
        'hsn_code'            => $in['hsn_code'] !== '' ? $in['hsn_code'] : null,
        'warranty'            => $in['warranty'] !== '' ? $in['warranty'] : null,
        'emi_text'            => $in['emi_text'] !== '' ? $in['emi_text'] : null,
        'manufacturer'        => $in['manufacturer'] !== '' ? $in['manufacturer'] : null,
        'model_number'        => $in['model_number'] !== '' ? $in['model_number'] : null,
        'part_number'         => $in['part_number'] !== '' ? $in['part_number'] : null,
        'compatibility'       => $in['compatibility'] !== '' ? $in['compatibility'] : null,
        'main_image'          => $mainImage,
        'hover_image'         => $hoverImage,
        'video_url'           => $in['video_url'] !== '' ? $in['video_url'] : null,
        'badge_text'          => $in['badge_text'] !== '' ? $in['badge_text'] : null,
        'badge_color'         => $in['badge_color'] !== '' ? $in['badge_color'] : null,
        'min_order_qty'       => $in['min_order_qty'] === '' ? 1 : max(1, (int) $in['min_order_qty']),
        'max_order_qty'       => $in['max_order_qty'] === '' ? 10 : max(1, (int) $in['max_order_qty']),
        'cod_available'       => (int) $in['cod_available'],
        'free_shipping'       => (int) $in['free_shipping'],
        'status'              => $in['status'],
        'published_at'        => $in['published_at'] !== '' ? date('Y-m-d H:i:s', (int) strtotime($in['published_at'])) : null,
        'is_featured'         => (int) $in['is_featured'],
        'is_new_arrival'      => (int) $in['is_new_arrival'],
        'is_best_seller'      => (int) $in['is_best_seller'],
        'is_trending'         => (int) $in['is_trending'],
        // All nine SEO columns, not just the two the form used to carry.
        // product_form_input() spreads seo_editor_input() into $in, which has
        // already trimmed, null-normalised, validated robots and dropped
        // unparseable JSON-LD - so these are copied across by name rather than
        // re-derived here, which is what let seven of them (focus_keyword,
        // canonical_url, robots, og_*, schema_json) be silently dropped on
        // every product save while the editor still rendered their inputs.
        ...array_intersect_key($in, array_flip(SEO_EDITOR_FIELDS)),
    ];

    $isCreate = $productId === null;
    $targetStock = $in['stock'] === '' ? 0 : max(0, (int) $in['stock']);

    $savedId = Database::transaction(static function () use (
        $row, $productId, $isCreate, $variants, $galleryPaths, $targetStock
    ): int {
        if ($isCreate) {
            // Stock starts at zero and is moved in below, so the journal shows
            // where the first units came from.
            $id = Database::insert('products', $row + ['stock' => 0, 'has_variants' => 0]);
        } else {
            $id = (int) $productId;
            Database::update('products', $row, '`id` = :id', ['id' => $id]);
        }

        product_save_gallery($id, $galleryPaths);
        product_save_specs($id, product_form_specs());
        product_save_features($id, product_form_features());
        product_save_tags($id, input_array('tags'), (string) input('new_tags', ''));
        product_save_relations($id, input_array('product_ids'));
        product_save_variants($id, $variants);

        if ($variants !== []) {
            // Parent stock is only ever the sum of what the variants hold.
            $total = (int) Database::fetchColumn(
                "SELECT COALESCE(SUM(`stock`), 0) FROM `product_variants`
                 WHERE `product_id` = :id AND `status` = 'active'",
                ['id' => $id]
            );
            Database::update('products', ['has_variants' => 1, 'stock' => $total], '`id` = :id', ['id' => $id]);
        } else {
            Database::update('products', ['has_variants' => 0], '`id` = :id', ['id' => $id]);
            $before = (int) Database::fetchColumn('SELECT `stock` FROM `products` WHERE `id` = :id', ['id' => $id]);
            if ($before !== $targetStock) {
                adjust_stock(
                    $id,
                    null,
                    $targetStock - $before,
                    $targetStock > $before ? 'restock' : 'adjust',
                    'product_form',
                    $id,
                    'Stock set from the product editor'
                );
            }
        }

        return $id;
    });

    log_activity(
        $isCreate ? 'product.created' : 'product.updated',
        'product',
        $savedId,
        ($isCreate ? 'Created' : 'Updated') . ' product "' . $row['name'] . '" (' . $row['sku'] . ')'
    );
    admin_after_write();

    return ['ok' => true, 'id' => $savedId, 'errors' => []];
}

/** Replace gallery rows: drop what was ticked, keep alt text, append new files. */
function product_save_gallery(int $productId, array $newPaths): void
{
    foreach (array_map('intval', input_array('remove_images')) as $imageId) {
        $image = Database::fetchColumn(
            'SELECT `image` FROM `product_images` WHERE `id` = :id AND `product_id` = :pid',
            ['id' => $imageId, 'pid' => $productId]
        );
        if ($image !== null) {
            Database::delete('product_images', '`id` = :id', ['id' => $imageId]);
            delete_upload((string) $image);
        }
    }

    $altText = isset($_POST['image_alt']) && is_array($_POST['image_alt']) ? $_POST['image_alt'] : [];
    foreach ($altText as $imageId => $alt) {
        if (!is_scalar($alt)) {
            continue;
        }
        Database::update(
            'product_images',
            ['alt_text' => trim((string) $alt) !== '' ? mb_substr(trim((string) $alt), 0, 200) : null],
            '`id` = :id AND `product_id` = :pid',
            ['id' => (int) $imageId, 'pid' => $productId]
        );
    }

    $sort = (int) Database::fetchColumn(
        'SELECT COALESCE(MAX(`sort_order`), 0) FROM `product_images` WHERE `product_id` = :id',
        ['id' => $productId]
    );

    foreach ($newPaths as $path) {
        Database::insert('product_images', [
            'product_id' => $productId,
            'image'      => $path,
            'sort_order' => ++$sort,
        ]);
    }
}

function product_save_specs(int $productId, array $specs): void
{
    Database::delete('product_specifications', '`product_id` = :id', ['id' => $productId]);
    foreach ($specs as $i => $spec) {
        Database::insert('product_specifications', $spec + ['product_id' => $productId, 'sort_order' => $i]);
    }
}

function product_save_features(int $productId, array $features): void
{
    Database::delete('product_features', '`product_id` = :id', ['id' => $productId]);
    foreach ($features as $i => $feature) {
        Database::insert('product_features', [
            'product_id' => $productId,
            'feature'    => $feature,
            'sort_order' => $i,
        ]);
    }
}

/** Attach the picked tags, creating any typed into the "new tags" box. */
function product_save_tags(int $productId, array $tagIds, string $newTags): void
{
    Database::delete('product_tags', '`product_id` = :id', ['id' => $productId]);

    $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds))));

    foreach (explode(',', $newTags) as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $slug = slugify($name);
        $existing = Database::fetchColumn('SELECT `id` FROM `tags` WHERE `slug` = :slug', ['slug' => $slug]);
        $ids[] = $existing !== null
            ? (int) $existing
            : Database::insert('tags', ['name' => mb_substr($name, 0, 100), 'slug' => mb_substr($slug, 0, 120)]);
    }

    foreach (array_unique($ids) as $tagId) {
        if (Database::exists('tags', '`id` = :id', ['id' => $tagId])) {
            Database::query(
                'INSERT IGNORE INTO `product_tags` (`product_id`, `tag_id`) VALUES (:pid, :tid)',
                ['pid' => $productId, 'tid' => $tagId]
            );
        }
    }
}

/**
 * Rewrite the "related" links only. Upsell/cross-sell rows created elsewhere
 * are left alone because this picker does not show them.
 */
function product_save_relations(int $productId, array $relatedIds): void
{
    Database::delete(
        'product_relations',
        "`product_id` = :id AND `relation_type` = 'related'",
        ['id' => $productId]
    );

    $sort = 0;
    foreach (array_unique(array_filter(array_map('intval', $relatedIds))) as $relatedId) {
        if ($relatedId === $productId || !Database::exists('products', '`id` = :id', ['id' => $relatedId])) {
            continue;
        }
        Database::query(
            'INSERT IGNORE INTO `product_relations` (`product_id`, `related_id`, `relation_type`, `sort_order`)
             VALUES (:pid, :rid, :type, :sort)',
            ['pid' => $productId, 'rid' => $relatedId, 'type' => 'related', 'sort' => $sort++]
        );
    }
}

/** Upsert the submitted variants, drop the ones removed from the repeater. */
function product_save_variants(int $productId, array $variants): void
{
    $existing = Database::fetchPairs(
        'SELECT `id`, `stock` FROM `product_variants` WHERE `product_id` = :id',
        ['id' => $productId]
    );

    $keptIds = [];
    $firstId = null;
    $defaultId = null;

    foreach ($variants as $variant) {
        $data = [
            'sku'          => mb_substr($variant['sku'], 0, 80),
            'variant_name' => mb_substr($variant['variant_name'], 0, 255),
            'price'        => (float) $variant['price'],
            'sale_price'   => $variant['sale_price'] === '' ? null : (float) $variant['sale_price'],
            'status'       => $variant['status'],
        ];

        $variantId = $variant['id'];
        $before = 0;

        if ($variantId > 0 && array_key_exists($variantId, $existing)) {
            $before = (int) $existing[$variantId];
            Database::update('product_variants', $data, '`id` = :id AND `product_id` = :pid', [
                'id' => $variantId, 'pid' => $productId,
            ]);
        } else {
            // New rows start empty so the first units arrive as a stock movement.
            $variantId = Database::insert('product_variants', $data + [
                'product_id' => $productId,
                'stock'      => 0,
                'is_default' => 0,
            ]);
        }

        $keptIds[] = $variantId;
        $firstId = $firstId ?? $variantId;
        if ($variant['is_default'] === 1) {
            $defaultId = $variantId;
        }

        $target = $variant['stock'] === '' ? 0 : max(0, (int) $variant['stock']);
        if ($target !== $before) {
            adjust_stock(
                $productId,
                $variantId,
                $target - $before,
                $target > $before ? 'restock' : 'adjust',
                'product_form',
                $productId,
                'Variant stock set from the product editor'
            );
        }

        Database::delete('product_variant_attributes', '`variant_id` = :id', ['id' => $variantId]);
        foreach ($variant['attrs'] as $attributeId => $valueId) {
            $valid = Database::exists(
                'attribute_values',
                '`id` = :vid AND `attribute_id` = :aid',
                ['vid' => $valueId, 'aid' => $attributeId]
            );
            if ($valid) {
                Database::insert('product_variant_attributes', [
                    'variant_id'         => $variantId,
                    'attribute_id'       => $attributeId,
                    'attribute_value_id' => $valueId,
                ]);
            }
        }
    }

    foreach (array_keys($existing) as $oldId) {
        if (!in_array((int) $oldId, $keptIds, true)) {
            $image = Database::fetchColumn('SELECT `image` FROM `product_variants` WHERE `id` = :id', ['id' => (int) $oldId]);
            Database::delete('product_variants', '`id` = :id AND `product_id` = :pid', ['id' => (int) $oldId, 'pid' => $productId]);
            delete_upload($image === null ? null : (string) $image);
        }
    }

    Database::update('product_variants', ['is_default' => 0], '`product_id` = :id', ['id' => $productId]);
    $default = $defaultId ?? $firstId;
    if ($default !== null) {
        Database::update('product_variants', ['is_default' => 1], '`id` = :id', ['id' => $default]);
    }
}

// ===========================================================================
//  Form state
// ===========================================================================

/**
 * Everything _form.php renders. Reads the database normally, but re-reads the
 * request after a failed submit so nothing the admin typed is thrown away.
 */
function product_form_state(?int $productId): array
{
    $posted = is_post();

    if ($productId !== null) {
        $product = Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => $productId]);
        if ($product === null) {
            return [];
        }
        $images = Database::fetchAll(
            'SELECT `id`, `image`, `alt_text` FROM `product_images` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
            ['id' => $productId]
        );
    } else {
        $product = product_form_defaults();
        $images = [];
    }

    if ($posted) {
        $product = array_merge($product, product_form_input());
        $product['id'] = $productId ?? 0;
        $product['main_image'] = input_bool('remove_main_image') ? null : ($product['main_image'] ?? null);
        $product['hover_image'] = input_bool('remove_hover_image') ? null : ($product['hover_image'] ?? null);

        $specs    = product_form_specs();
        $features = product_form_features();
        $tagIds   = array_map('intval', input_array('tags'));
        $newTags  = (string) input('new_tags', '');
        $variants = product_form_variants();
        $relatedIds = array_map('intval', input_array('product_ids'));
    } else {
        $specs = $productId === null ? [] : Database::fetchAll(
            'SELECT `spec_group`, `spec_key`, `spec_value` FROM `product_specifications`
             WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
            ['id' => $productId]
        );
        $features = $productId === null ? [] : Database::fetchColumnAll(
            'SELECT `feature` FROM `product_features` WHERE `product_id` = :id ORDER BY `sort_order`, `id`',
            ['id' => $productId]
        );
        $tagIds = $productId === null ? [] : array_map('intval', Database::fetchColumnAll(
            'SELECT `tag_id` FROM `product_tags` WHERE `product_id` = :id',
            ['id' => $productId]
        ));
        $newTags = '';
        $relatedIds = $productId === null ? [] : array_map('intval', Database::fetchColumnAll(
            "SELECT `related_id` FROM `product_relations`
             WHERE `product_id` = :id AND `relation_type` = 'related' ORDER BY `sort_order`, `id`",
            ['id' => $productId]
        ));

        $variants = [];
        $rows = $productId === null ? [] : Database::fetchAll(
            'SELECT * FROM `product_variants` WHERE `product_id` = :id ORDER BY `is_default` DESC, `id`',
            ['id' => $productId]
        );
        foreach ($rows as $i => $variantRow) {
            $variants[] = [
                'row'          => $i,
                'id'           => (int) $variantRow['id'],
                'key'          => (string) $i,
                'sku'          => (string) $variantRow['sku'],
                'variant_name' => (string) $variantRow['variant_name'],
                'price'        => (string) $variantRow['price'],
                'sale_price'   => $variantRow['sale_price'] === null ? '' : (string) $variantRow['sale_price'],
                'stock'        => (string) $variantRow['stock'],
                'status'       => (string) $variantRow['status'],
                'is_default'   => (int) $variantRow['is_default'],
                'attrs'        => Database::fetchPairs(
                    'SELECT `attribute_id`, `attribute_value_id` FROM `product_variant_attributes` WHERE `variant_id` = :id',
                    ['id' => (int) $variantRow['id']]
                ),
            ];
        }
    }

    $related = [];
    if ($relatedIds !== []) {
        [$placeholders, $params] = Database::inPlaceholders($relatedIds, 'r');
        $related = Database::fetchAll(
            'SELECT `id`, `name`, `main_image` FROM `products` WHERE `id` IN (' . $placeholders . ')',
            $params
        );
    }

    return [
        'product'  => $product,
        'images'   => $images,
        'specs'    => $specs,
        'features' => $features,
        'tag_ids'  => $tagIds,
        'new_tags' => $newTags,
        'related'  => $related,
        'variants' => $variants,
    ];
}
