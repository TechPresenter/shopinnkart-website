<?php
/**
 * ShopInnKart Admin - Coupon validation + persistence.
 *
 * create.php and edit.php both post into coupon_form_save(), so the two
 * screens can only differ by which row they point at. The coupon row and its
 * restrictions are written in one transaction: a coupon that saved but lost
 * its "first order only" rule would quietly hand discounts to everyone.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/includes/marketing.php';

/** Restriction types the UI writes, in the order they are rendered. */
function coupon_restriction_types(): array
{
    return ['product', 'category', 'brand', 'user', 'first_order'];
}

/** Blank coupon used by create.php. */
function coupon_form_defaults(): array
{
    return [
        'id'               => 0,
        'code'             => '',
        'description'      => '',
        'type'             => COUPON_TYPE_PERCENTAGE,
        'value'            => '',
        'minimum_order'    => '0',
        'maximum_discount' => '',
        'start_date'       => '',
        'end_date'         => '',
        'usage_limit'      => '',
        'per_user_limit'   => '1',
        'used_count'       => 0,
        'status'           => 'active',
    ];
}

/** No restrictions at all = the coupon applies to the whole catalogue. */
function coupon_restriction_defaults(): array
{
    return [
        'first_order' => 0,
        'products'    => [],
        'categories'  => [],
        'brands'      => [],
        'user_emails' => '',
    ];
}

/** Every scalar field the form posts. Numbers stay strings until validated. */
function coupon_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        // Codes are compared case-insensitively at checkout, so they are stored
        // in one canonical shape rather than however they were typed.
        'code'             => mb_strtoupper($text('code')),
        'description'      => $text('description'),
        'type'             => $text('type'),
        'value'            => $text('value'),
        'minimum_order'    => $text('minimum_order'),
        'maximum_discount' => $text('maximum_discount'),
        'start_date'       => $text('start_date'),
        'end_date'         => $text('end_date'),
        'usage_limit'      => $text('usage_limit'),
        'per_user_limit'   => $text('per_user_limit'),
        'status'           => $text('status'),
    ];
}

/** The restriction block as submitted. */
function coupon_restriction_input(): array
{
    $ints = static fn (string $key): array => array_values(array_unique(array_filter(
        array_map('intval', input_array($key)),
        static fn (int $id): bool => $id > 0
    )));

    return [
        'first_order' => input_bool('restrict_first_order') ? 1 : 0,
        'products'    => $ints('restrict_products'),
        'categories'  => $ints('restrict_categories'),
        'brands'      => $ints('restrict_brands'),
        'user_emails' => trim((string) ($_POST['restrict_users'] ?? '')),
    ];
}

/** The restrictions already stored against a coupon, in form shape. */
function coupon_restrictions_load(int $couponId): array
{
    $restrictions = coupon_restriction_defaults();

    $rows = Database::fetchAll(
        'SELECT `restriction_type`, `reference_id` FROM `coupon_restrictions` WHERE `coupon_id` = :cid ORDER BY `id`',
        ['cid' => $couponId]
    );

    $userIds = [];
    foreach ($rows as $row) {
        $referenceId = $row['reference_id'] !== null ? (int) $row['reference_id'] : null;

        switch ($row['restriction_type']) {
            case 'first_order': $restrictions['first_order'] = 1; break;
            case 'product':     if ($referenceId) { $restrictions['products'][] = $referenceId; }   break;
            case 'category':    if ($referenceId) { $restrictions['categories'][] = $referenceId; } break;
            case 'brand':       if ($referenceId) { $restrictions['brands'][] = $referenceId; }     break;
            case 'user':        if ($referenceId) { $userIds[] = $referenceId; }                    break;
        }
    }

    if ($userIds !== []) {
        [$placeholders, $params] = Database::inPlaceholders($userIds, 'uid');
        $emails = Database::fetchColumnAll(
            'SELECT `email` FROM `users` WHERE `id` IN (' . $placeholders . ') ORDER BY `email`',
            $params
        );
        $restrictions['user_emails'] = implode("\n", $emails);
    }

    return $restrictions;
}

/** Split the customer textarea into individual addresses. */
function coupon_restriction_emails(string $raw): array
{
    $emails = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $emails[mb_strtolower($part)] = $part;
        }
    }
    return array_values($emails);
}

/** Which of the given ids actually exist. Table comes from a fixed allowlist. */
function coupon_existing_ids(string $table, array $ids): array
{
    if (!in_array($table, ['products', 'categories', 'brands'], true)) {
        throw new InvalidArgumentException('coupon_existing_ids: unsupported table ' . $table);
    }
    if ($ids === []) {
        return [];
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'i');
    return array_map('intval', Database::fetchColumnAll(
        sprintf('SELECT `id` FROM `%s` WHERE `id` IN (%s)', $table, $placeholders),
        $params
    ));
}

/**
 * The values the form should render: the stored row, overlaid with whatever
 * was just submitted so a failed save re-renders what the admin typed.
 */
function coupon_form_state(?int $id): array
{
    $coupon = $id !== null
        ? Database::fetch('SELECT * FROM `coupons` WHERE `id` = :id', ['id' => $id])
        : coupon_form_defaults();

    if ($coupon === null) {
        return coupon_form_defaults() + ['restrictions' => coupon_restriction_defaults()];
    }

    // The stored window is a DATETIME; the form field wants Y-m-dTH:i.
    $coupon['start_date'] = marketing_dt_input($coupon['start_date'] ?? '');
    $coupon['end_date']   = marketing_dt_input($coupon['end_date'] ?? '');
    $coupon['restrictions'] = $id !== null ? coupon_restrictions_load($id) : coupon_restriction_defaults();

    if (is_post()) {
        $coupon = array_merge($coupon, coupon_form_input());
        $coupon['restrictions'] = coupon_restriction_input();
    }

    return $coupon;
}

/**
 * Validate and write a coupon.
 *
 * @param int|null $id null to insert, otherwise the row to update
 * @return array{ok:bool,id:int,errors:array}
 */
function coupon_form_save(?int $id): array
{
    $input        = coupon_form_input();
    $restrictions = coupon_restriction_input();

    $start = marketing_dt_save($input['start_date']);
    $end   = marketing_dt_save($input['end_date']);

    $v = new Validator($input, [
        'code'             => 'Coupon code',
        'value'            => 'Discount value',
        'minimum_order'    => 'Minimum order',
        'maximum_discount' => 'Maximum discount',
        'usage_limit'      => 'Total usage limit',
        'per_user_limit'   => 'Per-customer limit',
    ]);

    $v->required('code')->max('code', 60)
      ->regex('code', '/^[A-Z0-9][A-Z0-9_-]{2,59}$/',
          'Use 3–60 characters: letters, digits, hyphens or underscores.')
      ->unique('code', 'coupons', 'code', $id, 'That coupon code is already in use.')
      ->max('description', 255)
      ->in('type', array_keys(COUPON_TYPES))
      ->numeric('minimum_order')
      ->numeric('maximum_discount')
      ->integer('usage_limit')
      ->required('per_user_limit')->integer('per_user_limit')->between('per_user_limit', 0, 999)
      ->in('status', [STATUS_ACTIVE, STATUS_INACTIVE]);

    // Free shipping carries no amount; the other two types are meaningless at zero.
    if ($input['type'] === COUPON_TYPE_PERCENTAGE) {
        $v->required('value')->numeric('value')
          ->between('value', 0.01, 100, 'A percentage discount must be between 0.01 and 100.');
    } elseif ($input['type'] === COUPON_TYPE_FIXED) {
        $v->required('value')->numeric('value')
          ->rule('value', is_numeric($input['value']) && (float) $input['value'] > 0,
              'A fixed discount must be more than zero.');
    }

    $v->rule('minimum_order', $input['minimum_order'] === '' || (is_numeric($input['minimum_order']) && (float) $input['minimum_order'] >= 0),
        'The minimum order cannot be negative.');
    $v->rule('maximum_discount', $input['maximum_discount'] === '' || (is_numeric($input['maximum_discount']) && (float) $input['maximum_discount'] > 0),
        'Leave the cap blank for no cap, or enter an amount above zero.');
    $v->rule('usage_limit', $input['usage_limit'] === '' || (int) $input['usage_limit'] >= 1,
        'Leave the total limit blank for unlimited, or enter at least 1.');
    $v->rule('start_date', $input['start_date'] === '' || $start !== null, 'Enter a valid start date.');
    $v->rule('end_date', $input['end_date'] === '' || $end !== null, 'Enter a valid end date.');
    $v->rule('end_date', $start === null || $end === null || $end > $start,
        'The end date must come after the start date.');

    // Restrictions. Ids come from our own pickers, so a miss means the row was
    // deleted in another tab — say so instead of silently dropping the rule.
    $validProducts   = coupon_existing_ids('products', $restrictions['products']);
    $validCategories = coupon_existing_ids('categories', $restrictions['categories']);
    $validBrands     = coupon_existing_ids('brands', $restrictions['brands']);

    $v->rule('restrict_products', count($validProducts) === count($restrictions['products']),
        'One of the selected products no longer exists. Remove it and save again.');
    $v->rule('restrict_categories', count($validCategories) === count($restrictions['categories']),
        'One of the selected categories no longer exists. Remove it and save again.');
    $v->rule('restrict_brands', count($validBrands) === count($restrictions['brands']),
        'One of the selected brands no longer exists. Remove it and save again.');

    $emails  = coupon_restriction_emails($restrictions['user_emails']);
    $userIds = [];
    $unknown = [];

    if ($emails !== []) {
        [$placeholders, $params] = Database::inPlaceholders($emails, 'em');
        $found = Database::fetchPairs(
            'SELECT `email`, `id` FROM `users` WHERE `email` IN (' . $placeholders . ')',
            $params
        );

        $byEmail = [];
        foreach ($found as $email => $userId) {
            $byEmail[mb_strtolower((string) $email)] = (int) $userId;
        }
        foreach ($emails as $email) {
            $key = mb_strtolower($email);
            if (isset($byEmail[$key])) {
                $userIds[] = $byEmail[$key];
            } else {
                $unknown[] = $email;
            }
        }
        $userIds = array_values(array_unique($userIds));
    }

    $v->rule('restrict_users', $unknown === [],
        'No customer account matches: ' . implode(', ', array_slice($unknown, 0, 5)));

    if ($v->fails()) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $v->errors()];
    }

    $isPercentage = $input['type'] === COUPON_TYPE_PERCENTAGE;

    // --- offer fields ----------------------------------------------------
    // A coupon is advertised only when an operator has said so AND has chosen
    // at least one surface for it. Ticking the switch and no placement would
    // otherwise store a visible offer that appears nowhere.
    $allowedPlacements = ['header', 'home', 'scratch', 'product', 'cart'];
    $offerPlacements   = array_values(array_intersect(
        $allowedPlacements,
        array_map('strval', (array) ($_POST['offer_placements'] ?? []))
    ));
    $offerTitle   = trim((string) ($_POST['offer_title'] ?? ''));
    $offerSort    = max(0, min(999, (int) ($_POST['offer_sort'] ?? 0)));
    $offerVisible = (!empty($_POST['offer_visible']) && $offerPlacements !== []) ? 1 : 0;

    $payload = [
        'code'             => $input['code'],
        'description'      => $input['description'] !== '' ? $input['description'] : null,
        'type'             => $input['type'],
        'value'            => $input['type'] === COUPON_TYPE_FREE_SHIPPING ? 0 : (float) $input['value'],
        'minimum_order'    => $input['minimum_order'] === '' ? 0 : (float) $input['minimum_order'],
        // A cap only means something on a percentage discount.
        'maximum_discount' => ($isPercentage && $input['maximum_discount'] !== '')
            ? (float) $input['maximum_discount']
            : null,
        'start_date'       => $start,
        'end_date'         => $end,
        'usage_limit'      => $input['usage_limit'] === '' ? null : (int) $input['usage_limit'],
        'per_user_limit'   => (int) $input['per_user_limit'],
        'status'           => $input['status'],

        // What the storefront is allowed to advertise. Only placements the
        // storefront actually renders are stored, so a stale value in the
        // column can never make public_offers() look somewhere that no longer
        // exists.
        'offer_visible'    => $offerVisible,
        'offer_title'      => $offerTitle !== '' ? $offerTitle : null,
        'offer_placements' => implode(',', $offerPlacements),
        'offer_sort'       => $offerSort,
    ];

    $rules = [];
    if ($restrictions['first_order'] === 1) {
        $rules[] = ['restriction_type' => 'first_order', 'reference_id' => null];
    }
    foreach ($validProducts as $productId) {
        $rules[] = ['restriction_type' => 'product', 'reference_id' => $productId];
    }
    foreach ($validCategories as $categoryId) {
        $rules[] = ['restriction_type' => 'category', 'reference_id' => $categoryId];
    }
    foreach ($validBrands as $brandId) {
        $rules[] = ['restriction_type' => 'brand', 'reference_id' => $brandId];
    }
    foreach ($userIds as $userId) {
        $rules[] = ['restriction_type' => 'user', 'reference_id' => $userId];
    }

    $couponId = Database::transaction(static function () use ($id, $payload, $rules): int {
        if ($id === null) {
            $newId = Database::insert('coupons', $payload);
        } else {
            Database::update('coupons', $payload, '`id` = :id', ['id' => $id]);
            $newId = $id;
        }

        // Restrictions are replaced wholesale — there is no partial edit of a
        // rule set, and a leftover row would silently narrow the coupon.
        Database::delete('coupon_restrictions', '`coupon_id` = :cid', ['cid' => $newId]);
        foreach ($rules as $rule) {
            Database::insert('coupon_restrictions', $rule + ['coupon_id' => $newId]);
        }

        return $newId;
    });

    log_activity(
        $id === null ? 'coupon.created' : 'coupon.updated',
        'coupon',
        $couponId,
        ($id === null ? 'Created' : 'Updated') . ' coupon "' . $payload['code'] . '"'
            . ($rules !== [] ? ' with ' . count($rules) . ' restriction(s)' : '')
    );
    admin_after_write();

    return ['ok' => true, 'id' => $couponId, 'errors' => []];
}
