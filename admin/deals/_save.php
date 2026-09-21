<?php
/**
 * ShopInnKart Admin - Deal validation + persistence.
 *
 * create.php and edit.php both post into deal_form_save(). The deal row and
 * its product list are written in one transaction, because a deal whose
 * products failed to save would price nothing while still advertising itself
 * on the homepage.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/includes/marketing.php';

/** Discount types a deal may use. */
function deal_discount_types(): array
{
    return ['percentage' => 'Percentage off', 'fixed' => 'Fixed amount off'];
}

/** Blank deal used by create.php. */
function deal_form_defaults(): array
{
    return [
        'id'             => 0,
        'title'          => '',
        'subtitle'       => '',
        'description'    => '',
        'product_id'     => '',
        'discount_type'  => 'percentage',
        'discount_value' => '',
        'stock_limit'    => '',
        'stock_sold'     => 0,
        'button_text'    => 'Shop The Deal',
        'button_url'     => '',
        // A deal without a window would never end, so a fresh one is offered
        // a sensible 24 hours the admin can move.
        'start_time'     => date('Y-m-d\TH:i'),
        'end_time'       => date('Y-m-d\TH:i', strtotime('+1 day')),
        'status'         => 'active',
        'products'       => [],
    ];
}

/** Every scalar field the form posts. Numbers stay strings until validated. */
function deal_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        'title'          => $text('title'),
        'subtitle'       => $text('subtitle'),
        'description'    => sanitize_html((string) ($_POST['description'] ?? '')),
        'product_id'     => $text('product_id'),
        'discount_type'  => $text('discount_type'),
        'discount_value' => $text('discount_value'),
        'stock_limit'    => $text('stock_limit'),
        'button_text'    => $text('button_text'),
        'button_url'     => $text('button_url'),
        'start_time'     => $text('start_time'),
        'end_time'       => $text('end_time'),
        'status'         => $text('status'),
    ];
}

/** The attached products already stored against a deal, in picker order. */
function deal_products_load(int $dealId): array
{
    $rows = Database::fetchAll(
        'SELECT `product_id`, `deal_price` FROM `deal_products`
         WHERE `deal_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $dealId]
    );

    $products = [];
    foreach ($rows as $row) {
        $products[(int) $row['product_id']] = [
            'deal_price' => $row['deal_price'] !== null ? (string) (float) $row['deal_price'] : '',
        ];
    }
    return $products;
}

/**
 * The values the form should render: the stored row, overlaid with whatever
 * was just submitted so a failed save re-renders what the admin typed.
 */
function deal_form_state(?int $id): array
{
    $deal = $id !== null
        ? Database::fetch('SELECT * FROM `deals` WHERE `id` = :id', ['id' => $id])
        : deal_form_defaults();

    if ($deal === null) {
        return deal_form_defaults();
    }

    if ($id !== null) {
        $deal['start_time'] = marketing_dt_input($deal['start_time']);
        $deal['end_time']   = marketing_dt_input($deal['end_time']);
        $deal['products']   = deal_products_load($id);
    }

    if (is_post()) {
        $deal = array_merge($deal, deal_form_input());
        $deal['products'] = marketing_picked_products('product_ids', ['deal_price']);
    }

    return $deal;
}

/**
 * Validate and write a deal.
 *
 * @param int|null $id null to insert, otherwise the row to update
 * @return array{ok:bool,id:int,errors:array}
 */
function deal_form_save(?int $id): array
{
    $input    = deal_form_input();
    $products = marketing_picked_products('product_ids', ['deal_price']);

    $start = marketing_dt_save($input['start_time']);
    $end   = marketing_dt_save($input['end_time']);

    $v = new Validator($input, [
        'title'          => 'Deal title',
        'product_id'     => 'Headline product',
        'discount_value' => 'Discount value',
        'stock_limit'    => 'Stock limit',
        'button_url'     => 'Button link',
    ]);

    $v->required('title')->max('title', 200)
      ->max('subtitle', 255)
      ->max('button_text', 60)
      ->max('button_url', 255)
      ->in('discount_type', array_keys(deal_discount_types()))
      ->required('discount_value')->numeric('discount_value')
      ->integer('stock_limit')
      ->in('status', [STATUS_ACTIVE, STATUS_INACTIVE])
      ->rule('start_time', $start !== null, 'A deal needs a start time.')
      ->rule('end_time', $end !== null, 'A deal needs an end time.')
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

    if ($input['product_id'] !== '') {
        $v->integer('product_id')->exists('product_id', 'products');
    }

    // Every attached product must still exist, and a hand-typed deal price has
    // to be a real amount — the storefront divides by it.
    $attached = marketing_product_rows(array_keys($products));
    $v->rule('product_ids', count($attached) === count($products),
        'One of the attached products no longer exists. Remove it and save again.');

    foreach ($products as $productId => $values) {
        $price = $values['deal_price'];
        if ($price !== '' && (!is_numeric($price) || (float) $price <= 0)) {
            $v->rule('deal_price', false, 'Deal prices must be blank (use the deal discount) or above zero.');
            break;
        }
    }

    if ($v->fails()) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $v->errors()];
    }

    $payload = [
        'title'          => $input['title'],
        'subtitle'       => $input['subtitle'] !== '' ? $input['subtitle'] : null,
        'description'    => $input['description'] !== '' ? $input['description'] : null,
        'product_id'     => $input['product_id'] !== '' ? (int) $input['product_id'] : null,
        'discount_type'  => $input['discount_type'],
        'discount_value' => (float) $input['discount_value'],
        'stock_limit'    => $input['stock_limit'] === '' ? null : (int) $input['stock_limit'],
        'button_text'    => $input['button_text'] !== '' ? $input['button_text'] : 'Shop The Deal',
        'button_url'     => $input['button_url'] !== '' ? $input['button_url'] : null,
        'start_time'     => $start,
        'end_time'       => $end,
        'status'         => $input['status'],
    ];

    $dealId = Database::transaction(static function () use ($id, $payload, $products): int {
        if ($id === null) {
            $newId = Database::insert('deals', $payload);
        } else {
            Database::update('deals', $payload, '`id` = :id', ['id' => $id]);
            $newId = $id;
        }

        // The list is replaced wholesale; sort_order follows the picker order.
        Database::delete('deal_products', '`deal_id` = :did', ['did' => $newId]);

        $sortOrder = 0;
        foreach ($products as $productId => $values) {
            Database::insert('deal_products', [
                'deal_id'    => $newId,
                'product_id' => $productId,
                'deal_price' => $values['deal_price'] === '' ? null : (float) $values['deal_price'],
                'sort_order' => $sortOrder++,
            ]);
        }

        return $newId;
    });

    log_activity(
        $id === null ? 'deal.created' : 'deal.updated',
        'deal',
        $dealId,
        ($id === null ? 'Created' : 'Updated') . ' deal "' . $payload['title'] . '" with '
            . count($products) . ' product(s)'
    );
    admin_after_write();

    return ['ok' => true, 'id' => $dealId, 'errors' => []];
}
