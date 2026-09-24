<?php
/**
 * ShopInnKart Admin - CSV product import.
 *
 * Every row is validated before a single write happens, and the writes then
 * run in one transaction. A file with one bad row imports nothing, which is
 * far easier to recover from than a half-applied catalogue.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('products.create');

require_once ADMIN_PATH . '/products/_shared.php';

/** One cell from a CSV row, or '' when that column is not in the file. */
function import_cell(array $row, array $index, string $column): string
{
    if (!isset($index[$column]) || !isset($row[$index[$column]])) {
        return '';
    }
    return trim((string) $row[$index[$column]]);
}

/** CSV truthiness. Blank keeps the supplied default. */
function import_bool(string $value, int $default = 0): int
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return $default;
    }
    return in_array($value, ['1', 'yes', 'true', 'y', 'on'], true) ? 1 : 0;
}

/** Replace a product's tags with the named ones, creating any that are new. */
function import_apply_tags(int $productId, array $names): void
{
    Database::delete('product_tags', '`product_id` = :id', ['id' => $productId]);

    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $slug = slugify($name);
        $tagId = Database::fetchColumn('SELECT `id` FROM `tags` WHERE `slug` = :slug', ['slug' => $slug]);
        if ($tagId === null) {
            $tagId = Database::insert('tags', [
                'name' => mb_substr($name, 0, 100),
                'slug' => mb_substr($slug, 0, 120),
            ]);
        }
        Database::query(
            'INSERT IGNORE INTO `product_tags` (`product_id`, `tag_id`) VALUES (:pid, :tid)',
            ['pid' => $productId, 'tid' => (int) $tagId]
        );
    }
}

$columns         = product_csv_columns();
$requiredColumns = product_csv_required_columns();

// A header-only file to start from when the catalogue is still empty.
if (($_GET['template'] ?? '') === '1') {
    stream_csv('product-import-template.csv', $columns, [[
        'DEMO-SKU-001', 'Demo Product', 'demo-product', 'Lexton', 'String & Curtain Lights',
        'One line summary', '<p>Full description</p>', '999', '289', '180',
        '25', '5', '0.350', '18', '9405', '6 Month Seller Warranty', '',
        'Lexton', 'LX-STAR-138', 'LX-STAR-138-WW', 'Plugs into any standard Indian socket',
        '1', '5', '1', '0', 'active', '0', '1', '0', '0', '', '', '', '', '', '', 'Diwali|Warm White',
    ]]);
}

const IMPORT_MAX_ROWS = 5000;

$rowErrors  = [];
$fileError  = '';
$summary    = null;

if (is_post()) {
    csrf_require();

    $upload = $_FILES['csv'] ?? null;

    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $fileError = 'Choose a CSV file to import.';
    } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $fileError = 'The upload did not complete. Try a smaller file.';
    } elseif (!is_uploaded_file((string) $upload['tmp_name'])) {
        $fileError = 'Invalid upload source.';
    } elseif ((int) $upload['size'] > MAX_UPLOAD_SIZE) {
        $fileError = 'The file is larger than ' . (MAX_UPLOAD_SIZE / 1048576) . ' MB.';
    } elseif (!in_array(strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
        $fileError = 'Only .csv files can be imported.';
    }

    $handle = $fileError === '' ? fopen((string) $upload['tmp_name'], 'r') : false;
    if ($fileError === '' && $handle === false) {
        $fileError = 'The uploaded file could not be opened.';
    }

    // -----------------------------------------------------------------------
    //  Pass 1 - read and validate everything
    // -----------------------------------------------------------------------
    $parsed = [];

    if ($fileError === '' && $handle !== false) {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            $fileError = 'That file is empty.';
        } else {
            // Excel writes a UTF-8 BOM in front of the first header cell.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

            $index = [];
            foreach ($header as $position => $name) {
                $key = strtolower(trim((string) $name));
                if ($key !== '' && in_array($key, $columns, true)) {
                    $index[$key] = $position;
                }
            }

            $missing = array_values(array_diff($requiredColumns, array_keys($index)));
            if ($missing !== []) {
                $fileError = 'These required columns are missing from the header row: ' . implode(', ', $missing) . '.';
            }
        }
    }

    if ($fileError === '' && $handle !== false) {
        $brandsByName = [];
        foreach (Database::fetchAll('SELECT `id`, `name` FROM `brands`') as $brand) {
            $brandsByName[mb_strtolower((string) $brand['name'])] = (int) $brand['id'];
        }
        $categoriesByName = [];
        foreach (Database::fetchAll('SELECT `id`, `name` FROM `categories`') as $category) {
            $categoriesByName[mb_strtolower((string) $category['name'])] = (int) $category['id'];
        }

        $seenSkus  = [];
        $seenSlugs = [];
        $line = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            if ($data === [null] || trim(implode('', array_map('strval', $data))) === '') {
                continue;
            }
            if (count($parsed) >= IMPORT_MAX_ROWS) {
                $fileError = 'That file has more than ' . IMPORT_MAX_ROWS . ' rows. Split it and import in batches.';
                break;
            }

            $sku    = import_cell($data, $index, 'sku');
            $name   = import_cell($data, $index, 'name');
            $errors = [];

            // --- identity ---------------------------------------------------
            if ($sku === '') {
                $errors[] = 'SKU is required.';
            } elseif (mb_strlen($sku) > 80) {
                $errors[] = 'SKU must not exceed 80 characters.';
            } elseif (isset($seenSkus[mb_strtolower($sku)])) {
                $errors[] = 'This SKU appears earlier in the file (line ' . $seenSkus[mb_strtolower($sku)] . ').';
            }

            $existingId = $sku === '' ? null : Database::fetchColumn(
                'SELECT `id` FROM `products` WHERE `sku` = :sku LIMIT 1',
                ['sku' => $sku]
            );
            $existingId = $existingId === null ? null : (int) $existingId;

            if ($name === '' && $existingId === null) {
                $errors[] = 'Name is required for a new product.';
            } elseif (mb_strlen($name) > 255) {
                $errors[] = 'Name must not exceed 255 characters.';
            }

            // --- slug --------------------------------------------------------
            $slug = import_cell($data, $index, 'slug');
            $wantsSlug = $slug !== '' || $existingId === null;
            if ($wantsSlug) {
                $slug = slugify($slug !== '' ? $slug : $name);
                if (isset($seenSlugs[$slug])) {
                    $errors[] = 'This slug collides with line ' . $seenSlugs[$slug] . '.';
                } else {
                    $clashSql = 'SELECT `id` FROM `products` WHERE `slug` = :slug'
                        . ($existingId !== null ? ' AND `id` <> :id' : '') . ' LIMIT 1';
                    $clashParams = ['slug' => $slug];
                    if ($existingId !== null) {
                        $clashParams['id'] = $existingId;
                    }
                    if (Database::fetchColumn($clashSql, $clashParams) !== null) {
                        $errors[] = 'The slug "' . $slug . '" already belongs to another product.';
                    }
                }
            }

            // --- numbers -----------------------------------------------------
            $price = import_cell($data, $index, 'price');
            if ($existingId === null && $price === '') {
                $errors[] = 'Price is required for a new product.';
            } elseif ($price !== '' && (!is_numeric($price) || (float) $price < 0)) {
                $errors[] = 'Price must be a number of zero or more.';
            }

            $salePrice = import_cell($data, $index, 'sale_price');
            if ($salePrice !== '') {
                if (!is_numeric($salePrice) || (float) $salePrice <= 0) {
                    $errors[] = 'Sale price must be a positive number.';
                } elseif ($price !== '' && is_numeric($price) && (float) $salePrice >= (float) $price) {
                    $errors[] = 'Sale price must be lower than the price.';
                }
            }

            foreach (['cost_price', 'weight', 'tax_rate'] as $column) {
                $value = import_cell($data, $index, $column);
                if ($value !== '' && !is_numeric($value)) {
                    $errors[] = str_replace('_', ' ', $column) . ' must be a number.';
                }
            }
            $taxRate = import_cell($data, $index, 'tax_rate');
            if ($taxRate !== '' && is_numeric($taxRate) && ((float) $taxRate < 0 || (float) $taxRate > 100)) {
                $errors[] = 'Tax rate must be between 0 and 100.';
            }

            foreach (['stock', 'low_stock_threshold', 'min_order_qty', 'max_order_qty'] as $column) {
                $value = import_cell($data, $index, $column);
                if ($value !== '' && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0)) {
                    $errors[] = str_replace('_', ' ', $column) . ' must be a whole number of zero or more.';
                }
            }

            // --- lookups & enums ---------------------------------------------
            $brandName = import_cell($data, $index, 'brand');
            $brandId = null;
            if ($brandName !== '') {
                $brandId = $brandsByName[mb_strtolower($brandName)] ?? null;
                if ($brandId === null) {
                    $errors[] = 'No brand named "' . $brandName . '". Create it first.';
                }
            }

            $categoryName = import_cell($data, $index, 'category');
            $categoryId = null;
            if ($categoryName !== '') {
                $categoryId = $categoriesByName[mb_strtolower($categoryName)] ?? null;
                if ($categoryId === null) {
                    $errors[] = 'No category named "' . $categoryName . '". Create it first.';
                }
            }

            $status = strtolower(import_cell($data, $index, 'status'));
            if ($status !== '' && !array_key_exists($status, product_status_options())) {
                $errors[] = 'Status must be one of: ' . implode(', ', array_keys(product_status_options())) . '.';
            }

            $badgeColor = strtolower(import_cell($data, $index, 'badge_color'));
            if ($badgeColor !== '' && !array_key_exists($badgeColor, product_badge_colors())) {
                $errors[] = 'Badge colour must be one of: ' . implode(', ', array_keys(product_badge_colors())) . '.';
            }

            $videoUrl = import_cell($data, $index, 'video_url');
            if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Video URL is not a valid URL.';
            }

            $mainImage = ltrim(import_cell($data, $index, 'main_image'), '/');
            if ($mainImage !== '' && strpos($mainImage, '..') !== false) {
                $errors[] = 'Main image path may not contain "..".';
            }

            if ($errors !== []) {
                foreach ($errors as $message) {
                    $rowErrors[] = ['line' => $line, 'sku' => $sku, 'message' => $message];
                }
                continue;
            }

            $seenSkus[mb_strtolower($sku)] = $line;
            if ($wantsSlug) {
                $seenSlugs[$slug] = $line;
            }

            // --- build the row we will write --------------------------------
            $row = [];
            if ($existingId === null) {
                $row['sku']  = $sku;
                $row['name'] = mb_substr($name, 0, 255);
                $row['slug'] = mb_substr($slug, 0, 280);
            } else {
                if ($name !== '') {
                    $row['name'] = mb_substr($name, 0, 255);
                }
                if ($wantsSlug) {
                    $row['slug'] = mb_substr($slug, 0, 280);
                }
            }

            $nullableText = [
                'short_description' => 65535, 'description' => 4294967295, 'hsn_code' => 20,
                'warranty' => 150, 'emi_text' => 150, 'manufacturer' => 150, 'model_number' => 100,
                'part_number' => 100, 'compatibility' => 500, 'badge_text' => 40, 'badge_color' => 20,
                'video_url' => 255, 'meta_title' => 255, 'meta_description' => 65535, 'main_image' => 255,
            ];
            foreach ($nullableText as $column => $length) {
                if (!isset($index[$column])) {
                    continue;
                }
                $value = $column === 'main_image' ? $mainImage : import_cell($data, $index, $column);
                if ($column === 'badge_color') {
                    $value = $badgeColor;
                }
                if ($column === 'description') {
                    $value = sanitize_html($value);
                }
                $row[$column] = $value === '' ? null : mb_substr($value, 0, $length);
            }

            foreach (['sale_price', 'cost_price', 'weight'] as $column) {
                if (!isset($index[$column])) {
                    continue;
                }
                $value = import_cell($data, $index, $column);
                $row[$column] = $value === '' ? null : (float) $value;
            }

            if ($price !== '') {
                $row['price'] = (float) $price;
            }
            if ($taxRate !== '') {
                $row['tax_rate'] = (float) $taxRate;
            }
            foreach (['low_stock_threshold', 'min_order_qty', 'max_order_qty'] as $column) {
                $value = import_cell($data, $index, $column);
                if ($value !== '') {
                    $row[$column] = max($column === 'low_stock_threshold' ? 0 : 1, (int) $value);
                }
            }

            foreach (['cod_available' => 1, 'free_shipping' => 0, 'is_featured' => 0,
                      'is_new_arrival' => 0, 'is_best_seller' => 0, 'is_trending' => 0] as $column => $default) {
                if (isset($index[$column])) {
                    $row[$column] = import_bool(import_cell($data, $index, $column), $default);
                }
            }

            if ($status !== '') {
                $row['status'] = $status;
            }
            if (isset($index['brand'])) {
                $row['brand_id'] = $brandId;
            }
            if (isset($index['category'])) {
                $row['category_id'] = $categoryId;
            }

            $stockCell = import_cell($data, $index, 'stock');

            $parsed[] = [
                'line'        => $line,
                'existing_id' => $existingId,
                'row'         => $row,
                'stock'       => isset($index['stock']) && $stockCell !== '' ? (int) $stockCell : null,
                'tags'        => isset($index['tags'])
                    ? array_filter(array_map('trim', explode('|', import_cell($data, $index, 'tags'))), static fn ($t) => $t !== '')
                    : null,
            ];
        }
    }

    if ($handle !== false && $handle !== null) {
        fclose($handle);
    }

    if ($fileError === '' && $rowErrors === [] && $parsed === []) {
        $fileError = 'That file has a header row but no product rows.';
    }

    // -----------------------------------------------------------------------
    //  Pass 2 - write, all or nothing
    // -----------------------------------------------------------------------
    if ($fileError === '' && $rowErrors === []) {
        $created = 0;
        $updated = 0;

        Database::transaction(static function () use ($parsed, &$created, &$updated): void {
            foreach ($parsed as $item) {
                if ($item['existing_id'] !== null) {
                    $productId = $item['existing_id'];
                    if ($item['row'] !== []) {
                        Database::update('products', $item['row'], '`id` = :id', ['id' => $productId]);
                    }
                    $updated++;
                } else {
                    // Stock arrives as a movement below, never as a silent column write.
                    $productId = Database::insert('products', $item['row'] + ['stock' => 0]);
                    $created++;
                }

                if ($item['stock'] !== null) {
                    $product = Database::fetch(
                        'SELECT `stock`, `has_variants` FROM `products` WHERE `id` = :id',
                        ['id' => $productId]
                    );
                    // A product built from variants owns its stock through them.
                    if ($product !== null && (int) $product['has_variants'] === 0) {
                        $delta = $item['stock'] - (int) $product['stock'];
                        if ($delta !== 0) {
                            adjust_stock($productId, null, $delta, 'import', 'csv_import', $productId,
                                'CSV import, line ' . $item['line']);
                        }
                    }
                }

                if ($item['tags'] !== null) {
                    import_apply_tags($productId, $item['tags']);
                }
            }
        });

        log_activity('product.imported', 'product', null,
            'CSV import: ' . $created . ' created, ' . $updated . ' updated');
        admin_after_write();

        $summary = ['created' => $created, 'updated' => $updated, 'total' => count($parsed)];
        flash('success', 'Import finished: ' . $created . ' created, ' . $updated . ' updated.');
    }
}

$pageTitle    = 'Import Products';
$pageSubtitle = 'Upload a CSV. Rows are matched on SKU, so an existing product is updated instead of duplicated.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Products', 'url' => admin_url('products/')],
    ['label' => 'Import'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('products/import.php?template=1')) . '">'
    . icon('download', 'w-4 h-4') . ' Download template</a>'
    . '<a class="ad-btn" href="' . e(admin_url('products/')) . '">Back to list</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?php if ($fileError !== ''): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div><strong>Nothing was imported.</strong> <?= e($fileError) ?></div>
    </div>
<?php endif; ?>

<?php if ($rowErrors !== []): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Nothing was imported</div>
                <div class="ad-card__sub">
                    <?= count($rowErrors) ?> problem(s) found. Fix them in the file and upload it again —
                    the import runs in one transaction, so no rows were written.
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr><th class="ad-table__num">Line</th><th>SKU</th><th>Problem</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rowErrors as $rowError): ?>
                            <tr>
                                <td class="ad-table__num"><?= (int) $rowError['line'] ?></td>
                                <td class="ad-mono"><?= e($rowError['sku'] !== '' ? $rowError['sku'] : '—') ?></td>
                                <td><?= e($rowError['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($summary !== null): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <div class="ad-grid ad-grid--3">
                <?= admin_stat_card('Rows processed', number_format($summary['total']), 'list', 'navy') ?>
                <?= admin_stat_card('Products created', number_format($summary['created']), 'plus', 'green') ?>
                <?= admin_stat_card('Products updated', number_format($summary['updated']), 'refresh', 'blue') ?>
            </div>
            <div style="margin-top:16px">
                <a class="ad-btn ad-btn--primary" href="<?= e(admin_url('products/')) ?>">
                    <?= icon('package', 'w-4 h-4') ?> Open the product list
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <div class="ad-card">
        <div class="ad-card__head">
            <div class="ad-card__title">Upload a file</div>
        </div>
        <form class="ad-card__body" method="post" enctype="multipart/form-data"
              action="<?= e(admin_url('products/import.php')) ?>">
            <?= csrf_field() ?>

            <div class="ad-field">
                <span class="sik-label">CSV file</span>
                <div class="ad-drop" data-drop="#csvPreview">
                    <?= icon('upload', 'w-6 h-6') ?>
                    <div style="font-size:13px;margin-top:6px">Click or drop your .csv here</div>
                    <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
                </div>
                <div class="ad-preview" id="csvPreview"></div>
                <span class="sik-help">
                    Maximum <?= (int) (MAX_UPLOAD_SIZE / 1048576) ?> MB and <?= IMPORT_MAX_ROWS ?> rows per file.
                    Columns may appear in any order; unknown columns are ignored.
                </span>
            </div>

            <button type="submit" class="ad-btn ad-btn--primary">
                <?= icon('upload', 'w-4 h-4') ?> Validate &amp; import
            </button>
        </form>
    </div>

    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Expected columns</div>
                <div class="ad-card__sub">Header names must match exactly (lower case).</div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr><th>Column</th><th>Required</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $column): ?>
                            <tr>
                                <td class="ad-mono"><?= e($column) ?></td>
                                <td>
                                    <?php if (in_array($column, $requiredColumns, true)): ?>
                                        <span class="sik-status sik-status--red">Required</span>
                                    <?php else: ?>
                                        <span class="ad-muted">Optional</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ad-card__foot">
            <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                <code>brand</code> and <code>category</code> are matched by name and must already exist.
                <code>tags</code> is pipe separated, e.g. <code>Diwali|Warm White</code>.
                Booleans accept 1/0, yes/no or true/false. Stock changes are journalled as import movements.
            </span>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
