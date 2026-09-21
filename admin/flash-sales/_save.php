<?php
/**
 * ShopInnKart Admin - Flash sale validation + persistence.
 *
 * create.php and edit.php both post into flash_sale_form_save(). The product
 * rows are reconciled rather than rebuilt: flash_sale_products.stock_sold is
 * incremented by the order transaction, and wiping the row would hand back
 * stock that has already been sold.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/includes/marketing.php';

/** Discount types a flash sale may use. */
function flash_sale_discount_types(): array
{
    return ['percentage' => 'Percentage off', 'fixed' => 'Fixed amount off'];
}

/**
 * The per-product inputs the picker posts.
 * The unit cap is named product_stock_limit rather than stock_limit: the sale
 * itself already posts a scalar `stock_limit`, and PHP cannot hold a scalar
 * and an array under one name.
 */
function flash_sale_product_fields(): array
{
    return ['sale_price', 'product_stock_limit'];
}

/** Blank flash sale used by create.php. */
function flash_sale_form_defaults(): array
{
    return [
        'id'             => 0,
        'name'           => '',
        'subtitle'       => '',
        'discount_type'  => 'percentage',
        'discount_value' => '',
        'stock_limit'    => '',
        // A flash sale is short by definition, so a new one is offered a
        // six-hour window the admin can move.
        'start_time'     => date('Y-m-d\TH:i'),
        'end_time'       => date('Y-m-d\TH:i', strtotime('+6 hours')),
        'status'         => 'active',
        'products'       => [],
    ];
}

/** Every scalar field the form posts. Numbers stay strings until validated. */
function flash_sale_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        'name'           => $text('name'),
        'subtitle'       => $text('subtitle'),
        'discount_type'  => $text('discount_type'),
        'discount_value' => $text('discount_value'),
        'stock_limit'    => $text('stock_limit'),
        'start_time'     => $text('start_time'),
        'end_time'       => $text('end_time'),
        'status'         => $text('status'),
    ];
}

/** Rows already attached to a sale, keyed by product id in picker order. */
function flash_sale_products_load(int $saleId): array
{
    $rows = Database::fetchAll(
        'SELECT `product_id`, `sale_price`, `stock_limit`, `stock_sold` FROM `flash_sale_products`
         WHERE `flash_sale_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $saleId]
    );

    $products = [];
    foreach ($rows as $row) {
        $products[(int) $row['product_id']] = [
            'sale_price'          => $row['sale_price'] !== null ? (string) (float) $row['sale_price'] : '',
            'product_stock_limit' => $row['stock_limit'] !== null ? (string) (int) $row['stock_limit'] : '',
            'stock_sold'          => (int) $row['stock_sold'],
        ];
    }
    return $products;
}

/**
 * The values the form should render: the stored row, overlaid with whatever
 * was just submitted so a failed save re-renders what the admin typed.
 */
function flash_sale_form_state(?int $id): array
{
    $sale = $id !== null
        ? Database::fetch('SELECT * FROM `flash_sales` WHERE `id` = :id', ['id' => $id])
        : flash_sale_form_defaults();

    if ($sale === null) {
        return flash_sale_form_defaults();
    }

    $stored = [];
    if ($id !== null) {
        $sale['start_time'] = marketing_dt_input($sale['start_time']);
        $sale['end_time']   = marketing_dt_input($sale['end_time']);
        $stored             = flash_sale_products_load($id);
        $sale['products']   = $stored;
    }

    if (is_post()) {
        $sale = array_merge($sale, flash_sale_form_input());

        // stock_sold is never posted, so carry it across from the stored row.
        $submitted = marketing_picked_products('product_ids', flash_sale_product_fields());
        foreach ($submitted as $productId => $values) {
            $submitted[$productId]['stock_sold'] = (int) ($stored[$productId]['stock_sold'] ?? 0);
        }
        $sale['products'] = $submitted;
    }

    return $sale;
}

/**
 * Validate and write a flash sale.
 *
 * @param int|null $id null to insert, otherwise the row to update
 * @return array{ok:bool,id:int,errors:array}
 */
function flash_sale_form_save(?int $id): array
{
    $input    = flash_sale_form_input();
    $products = marketing_picked_products('product_ids', flash_sale_product_fields());

    $start = marketing_dt_save($input['start_time']);
    $end   = marketing_dt_save($input['end_time']);

    $v = new Validator($input, [
        'name'           => 'Sale name',
        'discount_value' => 'Discount value',
        'stock_limit'    => 'Stock limit',
    ]);

    $v->required('name')->max('name', 200)
      ->max('subtitle', 255)
      ->in('discount_type', array_keys(flash_sale_discount_types()))
      ->required('discount_value')->numeric('discount_value')
      ->integer('stock_limit')
      ->in('status', [STATUS_ACTIVE, STATUS_INACTIVE])
      ->rule('start_time', $start !== null, 'A flash sale needs a start time.')
      ->rule('end_time', $end !== null, 'A flash sale needs an end time.')
      ->rule('end_time', $start === null || $end === null || $end > $start,
          'The end time must come after the start time.')
      ->rule('stock_limit', $input['stock_limit'] === '' || (int) $input['stock_limit'] >= 1,
          'Leave the stock limit blank for unlimited, or enter at least 1.');

    if ($input['discount_type'] === 'percentage') {
        $v->between('discount_value', 0.01, 100, 'A percentage discount must be between 0.01 and 100.');
    } else {
        $v->rule('discount_value', is_numeric($input['discount_value']) && (float) $input['discount_value'] > 0,
            'A fixed discount must be more than zero.');
    }

    $attached = marketing_product_rows(array_keys($products));
    $v->rule('product_ids', count($attached) === count($products),
        'One of the attached products no longer exists. Remove it and save again.');

    foreach ($products as $values) {
        if ($values['sale_price'] !== '' && (!is_numeric($values['sale_price']) || (float) $values['sale_price'] <= 0)) {
            $v->rule('sale_price', false, 'Sale prices must be blank (use the sale discount) or above zero.');
            break;
        }
    }
    foreach ($products as $values) {
        $cap = $values['product_stock_limit'];
        if ($cap !== '' && (!ctype_digit($cap) || (int) $cap < 1)) {
            $v->rule('product_stock_limit', false, 'Per-product unit caps must be blank or a whole number above zero.');
            break;
        }
    }

    if ($v->fails()) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $v->errors()];
    }

    $payload = [
        'name'           => $input['name'],
        'subtitle'       => $input['subtitle'] !== '' ? $input['subtitle'] : null,
        'discount_type'  => $input['discount_type'],
        'discount_value' => (float) $input['discount_value'],
        'stock_limit'    => $input['stock_limit'] === '' ? null : (int) $input['stock_limit'],
        'start_time'     => $start,
        'end_time'       => $end,
        'status'         => $input['status'],
    ];

    $saleId = Database::transaction(static function () use ($id, $payload, $products): int {
        if ($id === null) {
            $newId = Database::insert('flash_sales', $payload);
        } else {
            Database::update('flash_sales', $payload, '`id` = :id', ['id' => $id]);
            $newId = $id;
        }

        $existing = array_map('intval', Database::fetchColumnAll(
            'SELECT `product_id` FROM `flash_sale_products` WHERE `flash_sale_id` = :sid',
            ['sid' => $newId]
        ));
        $keep = array_map('intval', array_keys($products));

        // Detach only what the admin actually removed; the rows that stay keep
        // their stock_sold counter.
        foreach (array_diff($existing, $keep) as $productId) {
            Database::delete(
                'flash_sale_products',
                '`flash_sale_id` = :sid AND `product_id` = :pid',
                ['sid' => $newId, 'pid' => $productId]
            );
        }

        $sortOrder = 0;
        foreach ($products as $productId => $values) {
            $row = [
                'sale_price'  => $values['sale_price'] === '' ? null : (float) $values['sale_price'],
                'stock_limit' => $values['product_stock_limit'] === '' ? null : (int) $values['product_stock_limit'],
                'sort_order'  => $sortOrder++,
            ];

            if (in_array((int) $productId, $existing, true)) {
                Database::update(
                    'flash_sale_products',
                    $row,
                    '`flash_sale_id` = :sid AND `product_id` = :pid',
                    ['sid' => $newId, 'pid' => $productId]
                );
            } else {
                Database::insert('flash_sale_products', $row + [
                    'flash_sale_id' => $newId,
                    'product_id'    => (int) $productId,
                ]);
            }
        }

        return $newId;
    });

    log_activity(
        $id === null ? 'flash_sale.created' : 'flash_sale.updated',
        'flash_sale',
        $saleId,
        ($id === null ? 'Created' : 'Updated') . ' flash sale "' . $payload['name'] . '" with '
            . count($products) . ' product(s)'
    );
    admin_after_write();

    return ['ok' => true, 'id' => $saleId, 'errors' => []];
}
