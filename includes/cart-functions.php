<?php
/**
 * ShopInnKart - Cart, wishlist, compare and coupon logic.
 *
 * The cart stores product/variant ids and quantities only. Prices, discounts,
 * shipping and tax are recalculated from the database on every read, so a
 * tampered browser payload cannot change what a customer is charged.
 */

declare(strict_types=1);

// ===========================================================================
//  CART CONTAINER
// ===========================================================================

/**
 * The current cart row, creating one if needed.
 * Pass $fresh after writing to `carts` (coupon apply/remove) so the memoised
 * row does not hand stale coupon data to cart_totals() later in the request.
 */
function get_or_create_cart(bool $fresh = false): array
{
    static $cart = null;
    if ($cart !== null && !$fresh) {
        return $cart;
    }

    $userId = current_user_id();
    $sessionId = session_key();

    if ($userId !== null) {
        $row = Database::fetch('SELECT * FROM `carts` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT 1', ['uid' => $userId]);
        if ($row === null) {
            $id = Database::insert('carts', ['user_id' => $userId, 'session_id' => null]);
            $row = Database::fetch('SELECT * FROM `carts` WHERE `id` = :id', ['id' => $id]);
        }
        return $cart = $row;
    }

    if ($sessionId === '') {
        // No session (CLI or cookies disabled) - hand back an empty in-memory cart.
        return $cart = ['id' => 0, 'user_id' => null, 'session_id' => null, 'coupon_id' => null, 'coupon_code' => null];
    }

    $row = Database::fetch(
        'SELECT * FROM `carts` WHERE `session_id` = :sid AND `user_id` IS NULL ORDER BY `id` DESC LIMIT 1',
        ['sid' => $sessionId]
    );
    if ($row === null) {
        $id = Database::insert('carts', ['user_id' => null, 'session_id' => $sessionId]);
        $row = Database::fetch('SELECT * FROM `carts` WHERE `id` = :id', ['id' => $id]);
    }
    return $cart = $row;
}

function cart_id(): int
{
    return (int) get_or_create_cart()['id'];
}

/** Fold a guest cart into the customer's cart at login. */
function cart_merge_guest_into_user(string $sessionId, int $userId): void
{
    if ($sessionId === '') {
        return;
    }

    try {
        $guestCart = Database::fetch(
            'SELECT * FROM `carts` WHERE `session_id` = :sid AND `user_id` IS NULL ORDER BY `id` DESC LIMIT 1',
            ['sid' => $sessionId]
        );
        if ($guestCart === null) {
            return;
        }

        $userCart = Database::fetch('SELECT * FROM `carts` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT 1', ['uid' => $userId]);

        if ($userCart === null) {
            // Nothing to merge into - just claim the guest cart.
            Database::update('carts', ['user_id' => $userId, 'session_id' => null], '`id` = :id', ['id' => (int) $guestCart['id']]);
            return;
        }

        Database::transaction(static function () use ($guestCart, $userCart) {
            $guestItems = Database::fetchAll('SELECT * FROM `cart_items` WHERE `cart_id` = :id', ['id' => (int) $guestCart['id']]);

            foreach ($guestItems as $item) {
                $existing = Database::fetch(
                    'SELECT * FROM `cart_items`
                     WHERE `cart_id` = :cid AND `product_id` = :pid
                       AND (`variant_id` <=> :vid) LIMIT 1',
                    ['cid' => (int) $userCart['id'], 'pid' => (int) $item['product_id'], 'vid' => $item['variant_id']]
                );

                if ($existing !== null) {
                    Database::update(
                        'cart_items',
                        ['quantity' => (int) $existing['quantity'] + (int) $item['quantity']],
                        '`id` = :id',
                        ['id' => (int) $existing['id']]
                    );
                } else {
                    Database::update('cart_items', ['cart_id' => (int) $userCart['id']], '`id` = :id', ['id' => (int) $item['id']]);
                }
            }

            // Carry a guest coupon over only when the account cart has none.
            if (empty($userCart['coupon_code']) && !empty($guestCart['coupon_code'])) {
                Database::update('carts', [
                    'coupon_id'   => $guestCart['coupon_id'],
                    'coupon_code' => $guestCart['coupon_code'],
                ], '`id` = :id', ['id' => (int) $userCart['id']]);
            }

            Database::delete('carts', '`id` = :id', ['id' => (int) $guestCart['id']]);
        });
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Cart merge failed: ' . $e->getMessage());
    }
}

// ===========================================================================
//  CART LINES
// ===========================================================================

/**
 * Add a product (optionally a variant) to the cart.
 *
 * @return array{ok:bool, message:string, item_id:?int}
 */
function cart_add(int $productId, ?int $variantId = null, int $quantity = 1): array
{
    $quantity = max(1, $quantity);

    $product = Database::fetch(
        'SELECT * FROM `products` WHERE `id` = :id AND ' . product_visible_sql('products') . ' LIMIT 1',
        ['id' => $productId]
    );
    if ($product === null) {
        return ['ok' => false, 'message' => 'This product is no longer available.', 'item_id' => null];
    }

    $variant = null;
    if ((int) $product['has_variants'] === 1) {
        if ($variantId === null) {
            // Fall back to the default variant so "add to cart" works from a card.
            $variant = Database::fetch(
                "SELECT * FROM `product_variants`
                 WHERE `product_id` = :pid AND `status` = 'active'
                 ORDER BY `is_default` DESC, `id` LIMIT 1",
                ['pid' => $productId]
            );
            if ($variant === null) {
                return ['ok' => false, 'message' => 'Please choose an option before adding to cart.', 'item_id' => null];
            }
            $variantId = (int) $variant['id'];
        } else {
            $variant = get_variant($variantId, $productId);
            if ($variant === null) {
                return ['ok' => false, 'message' => 'That option is not available.', 'item_id' => null];
            }
        }
    } else {
        $variantId = null;
    }

    $availableStock = $variant !== null ? (int) $variant['stock'] : (int) $product['stock'];
    if ($availableStock <= 0) {
        return ['ok' => false, 'message' => 'This product is out of stock.', 'item_id' => null];
    }

    $cartId = cart_id();
    if ($cartId === 0) {
        return ['ok' => false, 'message' => 'Please enable cookies to use the cart.', 'item_id' => null];
    }

    $existing = Database::fetch(
        'SELECT * FROM `cart_items` WHERE `cart_id` = :cid AND `product_id` = :pid AND (`variant_id` <=> :vid) LIMIT 1',
        ['cid' => $cartId, 'pid' => $productId, 'vid' => $variantId]
    );

    $maxPerOrder = max(1, (int) $product['max_order_qty']);
    $requested = ($existing !== null ? (int) $existing['quantity'] : 0) + $quantity;
    $capped = min($requested, $availableStock, $maxPerOrder);

    if ($capped < $requested) {
        $message = $availableStock < $maxPerOrder
            ? 'Only ' . $availableStock . ' left in stock - quantity adjusted.'
            : 'You can order up to ' . $maxPerOrder . ' of this item.';
    } else {
        $message = 'Added to cart.';
    }

    if ($existing !== null) {
        Database::update('cart_items', ['quantity' => $capped], '`id` = :id', ['id' => (int) $existing['id']]);
        $itemId = (int) $existing['id'];
    } else {
        $itemId = Database::insert('cart_items', [
            'cart_id'    => $cartId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity'   => $capped,
        ]);
    }

    return ['ok' => true, 'message' => $message, 'item_id' => $itemId];
}

/** Change the quantity of a cart line. Quantity 0 removes it. */
function cart_update(int $itemId, int $quantity): array
{
    $cartId = cart_id();
    $item = Database::fetch(
        'SELECT * FROM `cart_items` WHERE `id` = :id AND `cart_id` = :cid LIMIT 1',
        ['id' => $itemId, 'cid' => $cartId]
    );
    if ($item === null) {
        return ['ok' => false, 'message' => 'That item is not in your cart.'];
    }

    if ($quantity <= 0) {
        Database::delete('cart_items', '`id` = :id AND `cart_id` = :cid', ['id' => $itemId, 'cid' => $cartId]);
        return ['ok' => true, 'message' => 'Item removed.'];
    }

    $product = Database::fetch('SELECT * FROM `products` WHERE `id` = :id', ['id' => (int) $item['product_id']]);
    if ($product === null) {
        Database::delete('cart_items', '`id` = :id', ['id' => $itemId]);
        return ['ok' => false, 'message' => 'That product is no longer available.'];
    }

    $stock = (int) $product['stock'];
    if ($item['variant_id'] !== null) {
        $variant = get_variant((int) $item['variant_id']);
        $stock = $variant !== null ? (int) $variant['stock'] : 0;
    }

    $capped = min($quantity, max(0, $stock), max(1, (int) $product['max_order_qty']));
    if ($capped <= 0) {
        Database::delete('cart_items', '`id` = :id', ['id' => $itemId]);
        return ['ok' => false, 'message' => 'That item is now out of stock and has been removed.'];
    }

    Database::update('cart_items', ['quantity' => $capped], '`id` = :id', ['id' => $itemId]);

    return [
        'ok'      => true,
        'message' => $capped < $quantity ? 'Only ' . $capped . ' available - quantity adjusted.' : 'Cart updated.',
        'quantity' => $capped,
    ];
}

function cart_remove(int $itemId): bool
{
    return Database::delete('cart_items', '`id` = :id AND `cart_id` = :cid', [
        'id'  => $itemId,
        'cid' => cart_id(),
    ]) > 0;
}

function cart_clear(): void
{
    $cartId = cart_id();
    if ($cartId > 0) {
        Database::delete('cart_items', '`cart_id` = :cid', ['cid' => $cartId]);
        Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => $cartId]);
    }
}

/**
 * Every cart line with live product data and freshly resolved prices.
 * Lines whose product vanished or went inactive are dropped automatically.
 */
function cart_items(): array
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return [];
    }

    $rows = Database::fetchAll(
        'SELECT ci.`id` AS item_id, ci.`quantity`, ci.`variant_id`,
                ci.`combo_id`, ci.`combo_group`,
                p.*, b.`name` AS brand_name, c.`name` AS category_name
         FROM `cart_items` ci
         INNER JOIN `products` p ON p.`id` = ci.`product_id`
         LEFT JOIN `brands` b ON b.`id` = p.`brand_id`
         LEFT JOIN `categories` c ON c.`id` = p.`category_id`
         WHERE ci.`cart_id` = :cid
         ORDER BY ci.`id`',
        ['cid' => $cartId]
    );

    $items = [];
    foreach ($rows as $row) {
        $variant = null;
        if ($row['variant_id'] !== null) {
            $variant = get_variant((int) $row['variant_id'], (int) $row['id']);
            if ($variant === null) {
                // Variant was deleted or disabled - drop the line.
                Database::delete('cart_items', '`id` = :id', ['id' => (int) $row['item_id']]);
                continue;
            }
        }

        if ($row['status'] !== 'active') {
            Database::delete('cart_items', '`id` = :id', ['id' => (int) $row['item_id']]);
            continue;
        }

        $pricing = product_effective_price($row, $variant);
        $stock = $variant !== null ? (int) $variant['stock'] : (int) $row['stock'];
        $quantity = min((int) $row['quantity'], max(0, $stock));

        $taxRate = (float) $row['tax_rate'];
        $lineSubtotal = money_round($pricing['price'] * $quantity);

        $items[] = [
            'item_id'       => (int) $row['item_id'],
            'product_id'    => (int) $row['id'],
            'variant_id'    => $variant !== null ? (int) $variant['id'] : null,
            // Null on an ordinary line. A line inside a set carries both, and
            // the cart renders one card per group rather than one per product.
            'combo_id'      => $row['combo_id'] !== null ? (int) $row['combo_id'] : null,
            'combo_group'   => $row['combo_group'],
            'name'          => $row['name'],
            'slug'          => $row['slug'],
            'url'           => product_url($row['slug']),
            'sku'           => $variant !== null ? $variant['sku'] : $row['sku'],
            'brand_name'    => $row['brand_name'],
            'variant_name'  => $variant !== null ? $variant['variant_name'] : null,
            'image'         => $variant !== null && !empty($variant['image']) ? $variant['image'] : $row['main_image'],
            'image_url'     => img_url($variant !== null && !empty($variant['image']) ? $variant['image'] : $row['main_image']),
            'quantity'      => $quantity,
            'requested_qty' => (int) $row['quantity'],
            'mrp'           => $pricing['mrp'],
            'price'         => $pricing['price'],
            'discount'      => $pricing['discount'],
            'subtotal'      => $lineSubtotal,
            'tax_rate'      => $taxRate,
            'stock'         => $stock,
            'in_stock'      => $stock > 0,
            'stock_state'   => stock_status($stock, (int) $row['low_stock_threshold']),
            'max_qty'       => min(max(0, $stock), max(1, (int) $row['max_order_qty'])),
            'free_shipping' => (int) $row['free_shipping'] === 1,
            'cod_available' => (int) $row['cod_available'] === 1,
            'price_display' => money($pricing['price']),
            'mrp_display'   => money($pricing['mrp']),
            'subtotal_display' => money($lineSubtotal),
        ];
    }

    return $items;
}

/**
 * product_id => units of it currently in the cart.
 *
 * Lets an Add to Cart button render its "already in your cart" state from the
 * database instead of the browser inferring it from a stale badge. One grouped
 * query serves every card on the page.
 *
 * @return array<int,int>
 */
function cart_product_quantities(bool $fresh = false): array
{
    static $map = null;
    if ($map !== null && !$fresh) {
        return $map;
    }

    $cartId = cart_id();
    if ($cartId === 0) {
        return $map = [];
    }

    $rows = Database::fetchAll(
        'SELECT `product_id`, SUM(`quantity`) AS `units` FROM `cart_items`
         WHERE `cart_id` = :cid GROUP BY `product_id`',
        ['cid' => $cartId]
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['product_id']] = (int) $row['units'];
    }
    return $map;
}

/** Units of one product in the cart (0 when it is not there). */
function cart_units_of(int $productId): int
{
    return cart_product_quantities()[$productId] ?? 0;
}

/** Total units in the cart, for the header badge. */
function cart_count(): int
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return 0;
    }
    return (int) Database::fetchColumn(
        'SELECT COALESCE(SUM(`quantity`), 0) FROM `cart_items` WHERE `cart_id` = :cid',
        ['cid' => $cartId]
    );
}

function cart_is_empty(): bool
{
    return cart_count() === 0;
}

// ===========================================================================
//  TOTALS
// ===========================================================================

/**
 * Recalculate every money value for the cart. This is the single source of
 * truth used by the cart page, the checkout page and order creation.
 *
 * @param array|null $items Pass pre-fetched items to avoid a second query.
 */
function cart_totals(?array $items = null, string $shippingMethod = SHIPPING_STANDARD, ?string $paymentMethod = null): array
{
    $items = $items ?? cart_items();
    $cart = get_or_create_cart();

    $subtotal = 0.0;
    $mrpTotal = 0.0;
    $units = 0;
    $allFreeShipping = $items !== [];

    foreach ($items as $item) {
        $subtotal += (float) $item['subtotal'];
        $mrpTotal += (float) $item['mrp'] * (int) $item['quantity'];
        $units += (int) $item['quantity'];
        if (!$item['free_shipping']) {
            $allFreeShipping = false;
        }
    }
    $subtotal = money_round($subtotal);
    $mrpTotal = money_round($mrpTotal);

    // --- coupon -------------------------------------------------------------
    $discount = 0.0;
    $couponCode = null;
    $couponFreeShipping = false;
    $couponMessage = null;

    if (!empty($cart['coupon_code'])) {
        $result = validate_coupon((string) $cart['coupon_code'], $subtotal, $items);
        if ($result['ok']) {
            $discount = (float) $result['discount'];
            $couponCode = $result['coupon']['code'];
            $couponFreeShipping = $result['free_shipping'];
        } else {
            // The coupon stopped qualifying (cart changed, expired, limit hit).
            Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => (int) $cart['id']]);
            $couponMessage = $result['message'];
        }
    }
    $discount = money_round(min($discount, $subtotal));

    // --- combos -------------------------------------------------------------
    // A set's saving is the difference between its components' own prices and
    // the combo price. It is taken off here rather than by rewriting the line
    // prices, because tax, the free-delivery threshold and the coupon's minimum
    // order are all computed from those lines - see cart_combo_groups().
    //
    // It stacks with a coupon on purpose: the coupon was validated against the
    // subtotal BEFORE this, which is the stricter order. The pair is then
    // clamped so the two together can never exceed what is in the basket.
    $comboDiscount = cart_combo_discount($items);
    $discount      = money_round(min($discount + $comboDiscount, $subtotal));


    // --- shipping -----------------------------------------------------------
    $shipping = calculate_shipping($subtotal - $discount, $shippingMethod, $allFreeShipping || $couponFreeShipping);

    // --- tax ----------------------------------------------------------------
    $taxable = max(0.0, $subtotal - $discount);
    $tax = calculate_tax($items, $subtotal, $discount);

    // --- payment surcharge / discount --------------------------------------
    $paymentCharge = 0.0;
    $paymentDiscount = 0.0;
    if ($paymentMethod !== null) {
        $method = Database::fetch(
            "SELECT * FROM `payment_methods` WHERE `code` = :code AND `status` = 'active' LIMIT 1",
            ['code' => $paymentMethod]
        );
        if ($method !== null) {
            $paymentCharge = money_round((float) $method['extra_charge']);
            if ((float) $method['discount_percent'] > 0) {
                $paymentDiscount = money_round($taxable * ((float) $method['discount_percent'] / 100));
            }
        }
    }

    $taxInclusive = setting_bool('tax_inclusive', true);
    $total = $taxable + $shipping['amount'] + $paymentCharge - $paymentDiscount;
    if (!$taxInclusive) {
        $total += $tax;
    }
    $total = money_round(max(0.0, $total));

    // The free-shipping meter has to measure the same bar the shipping quote
    // actually clears. calculate_shipping() honours the chosen method's
    // `free_above`, so reading the global setting here made the panel promise
    // "add x more for free shipping" against a threshold that was never the one
    // being tested. And when delivery is already free - every line ships free,
    // the coupon covers it, or the method costs nothing - there is nothing left
    // to unlock, so the meter must read complete rather than ask for more money.
    $freeShippingThreshold = $shipping['free_above'] ?? setting_float('free_shipping_threshold', 999);
    $remainingForFree = ($shipping['is_free'] || $freeShippingThreshold <= 0)
        ? 0.0
        : max(0.0, $freeShippingThreshold - $taxable);

    return [
        'items_count'        => count($items),
        'units'              => $units,
        'mrp_total'          => $mrpTotal,
        'subtotal'           => $subtotal,
        'product_saving'     => money_round(max(0.0, $mrpTotal - $subtotal)),
        'discount'           => $discount,
        // Reported separately so the basket can name the set that earned it.
        // It is already included in 'discount' - do not subtract it twice.
        'combo_discount'     => money_round($comboDiscount),
        'coupon_code'        => $couponCode,
        'coupon_message'     => $couponMessage,
        'shipping'           => $shipping['amount'],
        'shipping_label'     => $shipping['label'],
        'shipping_method'    => $shipping['code'],
        'shipping_free'      => $shipping['is_free'],
        'shipping_eta'       => $shipping['eta'],
        'tax'                => $tax,
        'tax_inclusive'      => $taxInclusive,
        'tax_label'          => (string) setting('tax_label', 'GST'),
        'payment_charge'     => $paymentCharge,
        'payment_discount'   => $paymentDiscount,
        'total'              => $total,
        'total_saving'       => money_round(max(0.0, $mrpTotal - $subtotal) + $discount + $paymentDiscount),
        'free_ship_threshold' => $freeShippingThreshold,
        'free_ship_remaining' => money_round($remainingForFree),
        'free_ship_progress'  => ($shipping['is_free'] || $freeShippingThreshold <= 0)
            ? 100
            : min(100, (int) round(($taxable / $freeShippingThreshold) * 100)),
        // Pre-formatted strings so views never format money themselves.
        'display' => [
            'subtotal'  => money($subtotal),
            'discount'  => money($discount),
            'shipping'  => $shipping['is_free'] ? 'FREE' : money($shipping['amount']),
            'tax'       => money($tax),
            'total'     => money($total),
            'saving'    => money(max(0.0, $mrpTotal - $subtotal) + $discount + $paymentDiscount),
            'remaining' => money($remainingForFree),
        ],
    ];
}

/** Shipping cost for an order value and chosen method. */
function calculate_shipping(float $orderValue, string $methodCode = SHIPPING_STANDARD, bool $forceFree = false): array
{
    $method = Database::fetch(
        "SELECT * FROM `shipping_methods` WHERE `code` = :code AND `status` = 'active' LIMIT 1",
        ['code' => $methodCode]
    );

    if ($method === null) {
        $method = Database::fetch("SELECT * FROM `shipping_methods` WHERE `status` = 'active' ORDER BY `sort_order` LIMIT 1");
    }

    if ($method === null) {
        // No configured methods - fall back to the global setting.
        $cost = setting_float('default_shipping_cost', 79);
        $threshold = setting_float('free_shipping_threshold', 999);
        $isFree = $forceFree || (setting_bool('free_shipping_enabled', true) && $orderValue >= $threshold);
        $days = setting_int('default_delivery_days', 4);
        return [
            'code'    => SHIPPING_STANDARD,
            'label'   => 'Standard Delivery',
            'amount'  => $isFree ? 0.0 : money_round($cost),
            'is_free' => $isFree,
            'eta'     => $days . ' - ' . ($days + 2) . ' business days',
            // The order value that unlocks free delivery, so the cart's meter
            // can measure against the same number this quote was decided on.
            'free_above' => setting_bool('free_shipping_enabled', true) ? $threshold : null,
        ];
    }

    $freeAbove = $method['free_above'] !== null ? (float) $method['free_above'] : null;
    $isFree = $forceFree
        || ((float) $method['cost'] <= 0)
        || ($freeAbove !== null && setting_bool('free_shipping_enabled', true) && $orderValue >= $freeAbove);

    return [
        'code'    => (string) $method['code'],
        'label'   => (string) $method['name'],
        'amount'  => $isFree ? 0.0 : money_round((float) $method['cost']),
        'is_free' => $isFree,
        'eta'     => (int) $method['min_days'] . ' - ' . (int) $method['max_days'] . ' business days',
        'free_above' => setting_bool('free_shipping_enabled', true) ? $freeAbove : null,
    ];
}

/**
 * GST across the cart.
 * With tax-inclusive pricing the tax is extracted from the line price;
 * otherwise it is added on top.
 */
function calculate_tax(array $items, float $subtotal, float $discount = 0.0): float
{
    if (!setting_bool('tax_enabled', true)) {
        return 0.0;
    }

    $inclusive = setting_bool('tax_inclusive', true);
    // Spread any cart-level discount proportionally across the lines.
    $ratio = $subtotal > 0 ? max(0.0, ($subtotal - $discount) / $subtotal) : 1.0;

    $tax = 0.0;
    foreach ($items as $item) {
        $rate = (float) ($item['tax_rate'] ?? setting_float('default_tax_rate', 18));
        if ($rate <= 0) {
            continue;
        }
        $lineValue = (float) $item['subtotal'] * $ratio;
        $tax += $inclusive
            ? $lineValue - ($lineValue * 100 / (100 + $rate))
            : $lineValue * ($rate / 100);
    }

    return money_round($tax);
}

// ===========================================================================
//  COUPONS
// ===========================================================================

/**
 * Validate a coupon against the current cart. All checks are server-side.
 *
 * @return array{ok:bool, message:string, discount:float, free_shipping:bool, coupon:?array}
 */
function validate_coupon(string $code, float $subtotal, ?array $items = null, ?int $userId = null): array
{
    $fail = static fn (string $message): array => [
        'ok' => false, 'message' => $message, 'discount' => 0.0, 'free_shipping' => false, 'coupon' => null,
    ];

    $code = strtoupper(trim($code));
    if ($code === '') {
        return $fail('Enter a coupon code.');
    }

    $coupon = Database::fetch('SELECT * FROM `coupons` WHERE UPPER(`code`) = :code LIMIT 1', ['code' => $code]);
    if ($coupon === null) {
        return $fail('This coupon code is not valid.');
    }
    if ($coupon['status'] !== 'active') {
        return $fail('This coupon is no longer active.');
    }
    if (!schedule_is_live($coupon['start_date'], $coupon['end_date'])) {
        $notStarted = !empty($coupon['start_date']) && strtotime((string) $coupon['start_date']) > time();
        return $fail($notStarted ? 'This coupon is not active yet.' : 'This coupon has expired.');
    }
    if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
        return $fail('This coupon has reached its usage limit.');
    }
    if ($subtotal < (float) $coupon['minimum_order']) {
        return $fail('Add ' . money((float) $coupon['minimum_order'] - $subtotal) . ' more to use this coupon.');
    }

    $userId = $userId ?? current_user_id();

    // Per-customer usage limit.
    if ($userId !== null && (int) $coupon['per_user_limit'] > 0) {
        $used = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `coupon_usage` WHERE `coupon_id` = :cid AND `user_id` = :uid',
            ['cid' => (int) $coupon['id'], 'uid' => $userId]
        );
        if ($used >= (int) $coupon['per_user_limit']) {
            return $fail('You have already used this coupon.');
        }
    }

    // Restrictions.
    $restrictions = Database::fetchAll(
        'SELECT * FROM `coupon_restrictions` WHERE `coupon_id` = :cid',
        ['cid' => (int) $coupon['id']]
    );

    if ($restrictions !== []) {
        $items = $items ?? cart_items();
        $check = coupon_restrictions_pass($restrictions, $items, $userId);
        if (!$check['ok']) {
            return $fail($check['message']);
        }
        // A product/category/brand restriction narrows the discountable base.
        $subtotal = $check['eligible_subtotal'] ?? $subtotal;
        if ($subtotal <= 0) {
            return $fail('This coupon does not apply to the items in your cart.');
        }
    }

    // Discount.
    $discount = 0.0;
    $freeShipping = false;

    if ($coupon['type'] === COUPON_TYPE_PERCENTAGE) {
        $discount = $subtotal * ((float) $coupon['value'] / 100);
        if ($coupon['maximum_discount'] !== null && (float) $coupon['maximum_discount'] > 0) {
            $discount = min($discount, (float) $coupon['maximum_discount']);
        }
    } elseif ($coupon['type'] === COUPON_TYPE_FIXED) {
        $discount = min((float) $coupon['value'], $subtotal);
    } else {
        $freeShipping = true;
    }

    return [
        'ok'            => true,
        'message'       => 'Coupon applied.',
        'discount'      => money_round($discount),
        'free_shipping' => $freeShipping,
        'coupon'        => $coupon,
    ];
}

/**
 * Evaluate coupon restrictions.
 * Product/category/brand rules are OR-ed within their type; first_order and
 * user rules are hard gates.
 */
function coupon_restrictions_pass(array $restrictions, array $items, ?int $userId): array
{
    $byType = [];
    foreach ($restrictions as $restriction) {
        $byType[$restriction['restriction_type']][] = $restriction['reference_id'] !== null
            ? (int) $restriction['reference_id']
            : null;
    }

    if (isset($byType['first_order'])) {
        if ($userId === null) {
            return ['ok' => false, 'message' => 'Sign in to use this first-order coupon.'];
        }
        $orderCount = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `orders` WHERE `user_id` = :uid AND `status` NOT IN ('cancelled')",
            ['uid' => $userId]
        );
        if ($orderCount > 0) {
            return ['ok' => false, 'message' => 'This coupon is only valid on your first order.'];
        }
    }

    if (isset($byType['user'])) {
        if ($userId === null || !in_array($userId, $byType['user'], true)) {
            return ['ok' => false, 'message' => 'This coupon is not available on your account.'];
        }
    }

    // Narrow the discountable subtotal when catalogue restrictions exist.
    $hasCatalogueRule = isset($byType['product']) || isset($byType['category']) || isset($byType['brand']);
    if (!$hasCatalogueRule) {
        return ['ok' => true, 'message' => '', 'eligible_subtotal' => null];
    }

    $productIds = array_map(static fn ($i) => (int) $i['product_id'], $items);
    if ($productIds === []) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    [$placeholders, $params] = Database::inPlaceholders($productIds, 'p');
    $meta = Database::fetchAll(
        'SELECT `id`, `category_id`, `brand_id` FROM `products` WHERE `id` IN (' . $placeholders . ')',
        $params
    );
    $metaById = [];
    foreach ($meta as $row) {
        $metaById[(int) $row['id']] = $row;
    }

    $eligibleSubtotal = 0.0;
    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $row = $metaById[$productId] ?? null;
        if ($row === null) {
            continue;
        }

        $matches = false;
        if (isset($byType['product']) && in_array($productId, $byType['product'], true)) {
            $matches = true;
        }
        if (!$matches && isset($byType['category']) && $row['category_id'] !== null) {
            foreach ($byType['category'] as $categoryId) {
                if ($categoryId !== null && in_array((int) $row['category_id'], category_with_descendants($categoryId), true)) {
                    $matches = true;
                    break;
                }
            }
        }
        if (!$matches && isset($byType['brand']) && $row['brand_id'] !== null
            && in_array((int) $row['brand_id'], $byType['brand'], true)) {
            $matches = true;
        }

        if ($matches) {
            $eligibleSubtotal += (float) $item['subtotal'];
        }
    }

    if ($eligibleSubtotal <= 0) {
        return ['ok' => false, 'message' => 'This coupon does not apply to the items in your cart.'];
    }

    return ['ok' => true, 'message' => '', 'eligible_subtotal' => money_round($eligibleSubtotal)];
}

/** Attach a validated coupon to the cart. */
function cart_apply_coupon(string $code): array
{
    $items = cart_items();
    if ($items === []) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    $subtotal = array_sum(array_column($items, 'subtotal'));
    $result = validate_coupon($code, money_round((float) $subtotal), $items);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message']];
    }

    Database::update('carts', [
        'coupon_id'   => (int) $result['coupon']['id'],
        'coupon_code' => $result['coupon']['code'],
    ], '`id` = :id', ['id' => cart_id()]);

    // Drop the memoised row. cart.php got away without this because it
    // redirects straight after, which rebuilds the memo in a new request;
    // an AJAX caller that reads cart_totals() in THIS request would
    // otherwise be handed the discount from before the coupon was applied.
    get_or_create_cart(true);

    return ['ok' => true, 'message' => 'Coupon "' . $result['coupon']['code'] . '" applied.', 'discount' => $result['discount']];
}

function cart_remove_coupon(): void
{
    Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => cart_id()]);
    // Same reason as cart_apply_coupon(): without this a caller that reads
    // the totals in the same request still sees the removed coupon.
    get_or_create_cart(true);
}

// ===========================================================================
//  WISHLIST & COMPARE - FEATURE GATES
//
//  Admin > Settings > Widgets owns both features. "Disabled" here means
//  genuinely disabled: the header icon, the card button, the product-page
//  button, the /wishlist and /compare pages AND the api/wishlist/*,
//  api/compare/* endpoints all refuse from these same four functions, so a
//  hidden button can never sit in front of a live endpoint.
//
//  Guest behaviour is one setting with three values rather than a pair of
//  switches that could contradict each other:
//    session - a signed-out visitor gets the session-backed list, merged into
//              the account on sign-in. (The shipped default; it is what both
//              features already did.)
//    prompt  - sign-in required. Controls render as links to the login page and
//              the API answers 401.
//    hidden  - a signed-out visitor sees no controls at all and the API
//              answers 403.
// ===========================================================================

function wishlist_enabled(): bool
{
    return setting_bool('wishlist_enabled', true);
}

function compare_enabled(): bool
{
    return setting_bool('compare_enabled', true);
}

/** session | prompt | hidden */
function wishlist_guest_mode(): string
{
    $mode = (string) setting('wishlist_guest_mode', 'session');
    return in_array($mode, ['session', 'prompt', 'hidden'], true) ? $mode : 'session';
}

/** session | prompt | hidden */
function compare_guest_mode(): string
{
    $mode = (string) setting('compare_guest_mode', 'session');
    return in_array($mode, ['session', 'prompt', 'hidden'], true) ? $mode : 'session';
}

/**
 * Why this visitor may not use the feature, or null when they may.
 *
 * One shape for every caller: the pages read `message`, the API reads `status`
 * and `code`. That is what keeps a refused endpoint a clean 401/403 with a
 * sentence a human can read, instead of a 500 from code that assumed the
 * feature was on.
 *
 * @param string $feature wishlist | compare
 * @return array{code:string, message:string, status:int}|null
 */
function feature_refusal(string $feature): ?array
{
    $isWishlist = $feature === 'wishlist';
    $noun       = $isWishlist ? 'wishlist' : 'product comparison';

    if (!($isWishlist ? wishlist_enabled() : compare_enabled())) {
        return [
            'code'    => 'feature_disabled',
            'message' => 'The ' . $noun . ' is turned off on this store.',
            'status'  => 403,
        ];
    }

    if (is_logged_in()) {
        return null;
    }

    $mode = $isWishlist ? wishlist_guest_mode() : compare_guest_mode();

    if ($mode === 'prompt') {
        return [
            'code'    => 'auth',
            'message' => 'Please sign in to use your ' . $noun . '.',
            'status'  => 401,
        ];
    }
    if ($mode === 'hidden') {
        return [
            'code'    => 'feature_disabled',
            'message' => 'The ' . $noun . ' is only available to signed-in customers.',
            'status'  => 403,
        ];
    }

    return null;
}

/**
 * API guard. Answers with the feature's own status and machine-readable code
 * (feature_disabled / auth) and never returns when the feature is refused, so
 * every endpoint refuses in exactly the same shape.
 */
function api_require_feature(string $feature): void
{
    $refusal = feature_refusal($feature);
    if ($refusal !== null) {
        json_error($refusal['message'], [], (int) $refusal['status'], (string) $refusal['code']);
    }
}

/** True when this visitor can actually add and remove things. */
function wishlist_usable(): bool
{
    return feature_refusal('wishlist') === null;
}

function compare_usable(): bool
{
    return feature_refusal('compare') === null;
}

/** Signed-out visitor who has to sign in first, rather than being locked out. */
function wishlist_needs_login(): bool
{
    return wishlist_enabled() && !is_logged_in() && wishlist_guest_mode() === 'prompt';
}

function compare_needs_login(): bool
{
    return compare_enabled() && !is_logged_in() && compare_guest_mode() === 'prompt';
}

/**
 * Should the control render on this surface at all?
 *
 * @param string $surface header | card | pdp
 */
function wishlist_shows_on(string $surface): bool
{
    if (!wishlist_enabled() || (!wishlist_usable() && !wishlist_needs_login())) {
        return false;
    }
    return setting_bool('wishlist_show_' . $surface, true);
}

function compare_shows_on(string $surface): bool
{
    if (!compare_enabled() || (!compare_usable() && !compare_needs_login())) {
        return false;
    }
    return setting_bool('compare_show_' . $surface, true);
}

// ===========================================================================
//  WISHLIST
// ===========================================================================

/** The signed-in customer's wishlist row, creating one on demand. */
function get_or_create_wishlist(int $userId): array
{
    $row = Database::fetch('SELECT * FROM `wishlists` WHERE `user_id` = :uid LIMIT 1', ['uid' => $userId]);
    if ($row === null) {
        $id = Database::insert('wishlists', ['user_id' => $userId]);
        $row = Database::fetch('SELECT * FROM `wishlists` WHERE `id` = :id', ['id' => $id]);
    }
    return $row;
}

/**
 * Toggle a product in the wishlist.
 * Guests get a session-backed list that is merged on login.
 *
 * @return array{ok:bool, added:bool, message:string, count:int}
 */
function wishlist_toggle(int $productId): array
{
    // The gate first: whatever the caller is, a disabled feature never writes.
    $refusal = feature_refusal('wishlist');
    if ($refusal !== null) {
        return ['ok' => false, 'added' => false, 'message' => $refusal['message'], 'count' => 0] + $refusal;
    }

    $exists = Database::fetchColumn(
        'SELECT `id` FROM `products` WHERE `id` = :id AND ' . product_visible_sql('products'),
        ['id' => $productId]
    );
    if ($exists === null) {
        return ['ok' => false, 'added' => false, 'message' => 'This product is not available.', 'count' => wishlist_count()];
    }

    $userId = current_user_id();

    if ($userId === null) {
        $list = $_SESSION['_wishlist'] ?? [];
        $index = array_search($productId, $list, true);
        if ($index !== false) {
            unset($list[$index]);
            $_SESSION['_wishlist'] = array_values($list);
            return ['ok' => true, 'added' => false, 'message' => 'Removed from wishlist.', 'count' => count($list)];
        }
        $list[] = $productId;
        $_SESSION['_wishlist'] = array_values(array_unique($list));
        return ['ok' => true, 'added' => true, 'message' => 'Added to wishlist.', 'count' => count($_SESSION['_wishlist'])];
    }

    $wishlist = get_or_create_wishlist($userId);
    $item = Database::fetch(
        'SELECT `id` FROM `wishlist_items` WHERE `wishlist_id` = :wid AND `product_id` = :pid LIMIT 1',
        ['wid' => (int) $wishlist['id'], 'pid' => $productId]
    );

    if ($item !== null) {
        Database::delete('wishlist_items', '`id` = :id', ['id' => (int) $item['id']]);
        return ['ok' => true, 'added' => false, 'message' => 'Removed from wishlist.', 'count' => wishlist_count()];
    }

    Database::insert('wishlist_items', ['wishlist_id' => (int) $wishlist['id'], 'product_id' => $productId]);
    return ['ok' => true, 'added' => true, 'message' => 'Added to wishlist.', 'count' => wishlist_count()];
}

function wishlist_remove(int $productId): bool
{
    if (feature_refusal('wishlist') !== null) {
        return false;
    }

    $userId = current_user_id();
    if ($userId === null) {
        $list = $_SESSION['_wishlist'] ?? [];
        $index = array_search($productId, $list, true);
        if ($index === false) {
            return false;
        }
        unset($list[$index]);
        $_SESSION['_wishlist'] = array_values($list);
        return true;
    }

    $wishlist = get_or_create_wishlist($userId);
    return Database::delete('wishlist_items', '`wishlist_id` = :wid AND `product_id` = :pid', [
        'wid' => (int) $wishlist['id'],
        'pid' => $productId,
    ]) > 0;
}

/** Product ids currently in the wishlist. */
function wishlist_product_ids(): array
{
    // A refused visitor has no list to read, so every count, badge and page
    // that hangs off this one reader reports empty rather than each having to
    // remember the gate for itself. Nothing is deleted - re-enabling the
    // feature brings the stored list straight back.
    if (feature_refusal('wishlist') !== null) {
        return [];
    }

    $userId = current_user_id();
    if ($userId === null) {
        return array_map('intval', $_SESSION['_wishlist'] ?? []);
    }

    return array_map('intval', Database::fetchColumnAll(
        'SELECT wi.`product_id` FROM `wishlist_items` wi
         INNER JOIN `wishlists` w ON w.`id` = wi.`wishlist_id`
         WHERE w.`user_id` = :uid',
        ['uid' => $userId]
    ));
}

function wishlist_count(): int
{
    return count(wishlist_product_ids());
}

function in_wishlist(int $productId): bool
{
    static $ids = null;
    if ($ids === null) {
        $ids = wishlist_product_ids();
    }
    return in_array($productId, $ids, true);
}

/** Full wishlist rows for the wishlist page. */
function wishlist_products(): array
{
    $ids = wishlist_product_ids();
    if ($ids === []) {
        return [];
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'w');
    $rows = Database::fetchAll(
        product_select_sql() . ' WHERE p.`id` IN (' . $placeholders . ') AND ' . product_visible_sql()
        . ' ORDER BY p.`id` DESC',
        $params
    );
    return array_map('decorate_product', $rows);
}

/** Merge a guest wishlist into the account at login. */
function wishlist_merge_guest_into_user(int $userId): void
{
    $guest = $_SESSION['_wishlist'] ?? [];
    if ($guest === []) {
        return;
    }

    $wishlist = get_or_create_wishlist($userId);
    foreach ($guest as $productId) {
        try {
            Database::query(
                'INSERT IGNORE INTO `wishlist_items` (`wishlist_id`, `product_id`) VALUES (:wid, :pid)',
                ['wid' => (int) $wishlist['id'], 'pid' => (int) $productId]
            );
        } catch (Throwable $e) {
            // Product may have been deleted; skip it.
        }
    }
    unset($_SESSION['_wishlist']);
}

// ===========================================================================
//  COMPARE
// ===========================================================================

function compare_max(): int
{
    return max(2, min(6, setting_int('max_compare_items', MAX_COMPARE_ITEMS)));
}

/** Add or remove a product from the comparison list. */
function compare_toggle(int $productId): array
{
    $refusal = feature_refusal('compare');
    if ($refusal !== null) {
        return ['ok' => false, 'added' => false, 'message' => $refusal['message'], 'count' => 0] + $refusal;
    }

    $exists = Database::fetchColumn(
        'SELECT `id` FROM `products` WHERE `id` = :id AND ' . product_visible_sql('products'),
        ['id' => $productId]
    );
    if ($exists === null) {
        return ['ok' => false, 'added' => false, 'message' => 'This product is not available.', 'count' => compare_count()];
    }

    $userId = current_user_id();
    $sessionId = session_key();
    $current = compare_product_ids();

    if (in_array($productId, $current, true)) {
        compare_remove($productId);
        return ['ok' => true, 'added' => false, 'message' => 'Removed from compare.', 'count' => compare_count()];
    }

    if (count($current) >= compare_max()) {
        return [
            'ok' => false, 'added' => false,
            'message' => 'You can compare up to ' . compare_max() . ' products.',
            'count' => count($current),
        ];
    }

    Database::insert('compare_items', [
        'user_id'    => $userId,
        'session_id' => $userId === null ? $sessionId : null,
        'product_id' => $productId,
    ]);

    return ['ok' => true, 'added' => true, 'message' => 'Added to compare.', 'count' => compare_count()];
}

function compare_remove(int $productId): bool
{
    if (feature_refusal('compare') !== null) {
        return false;
    }

    $userId = current_user_id();
    if ($userId !== null) {
        return Database::delete('compare_items', '`user_id` = :uid AND `product_id` = :pid', [
            'uid' => $userId, 'pid' => $productId,
        ]) > 0;
    }
    return Database::delete('compare_items', '`session_id` = :sid AND `product_id` = :pid', [
        'sid' => session_key(), 'pid' => $productId,
    ]) > 0;
}

function compare_clear(): void
{
    if (feature_refusal('compare') !== null) {
        return;
    }

    $userId = current_user_id();
    if ($userId !== null) {
        Database::delete('compare_items', '`user_id` = :uid', ['uid' => $userId]);
    } else {
        Database::delete('compare_items', '`session_id` = :sid', ['sid' => session_key()]);
    }
}

/** Pass $fresh to re-read after adding or removing a comparison item. */
function compare_product_ids(bool $fresh = false): array
{
    static $ids = null;
    if ($ids !== null && !$fresh) {
        return $ids;
    }

    // Same reasoning as wishlist_product_ids(): one reader carries the gate for
    // the badge, the bar, the page and the API alike.
    if (feature_refusal('compare') !== null) {
        return $ids = [];
    }

    $userId = current_user_id();
    $sessionId = session_key();

    if ($userId === null && $sessionId === '') {
        return $ids = [];
    }

    $rows = $userId !== null
        ? Database::fetchColumnAll('SELECT `product_id` FROM `compare_items` WHERE `user_id` = :uid ORDER BY `id`', ['uid' => $userId])
        : Database::fetchColumnAll('SELECT `product_id` FROM `compare_items` WHERE `session_id` = :sid ORDER BY `id`', ['sid' => $sessionId]);

    return $ids = array_map('intval', $rows);
}

function compare_count(): int
{
    if (feature_refusal('compare') !== null) {
        return 0;
    }

    $userId = current_user_id();
    $sessionId = session_key();

    if ($userId !== null) {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM `compare_items` WHERE `user_id` = :uid', ['uid' => $userId]);
    }
    if ($sessionId === '') {
        return 0;
    }
    return (int) Database::fetchColumn('SELECT COUNT(*) FROM `compare_items` WHERE `session_id` = :sid', ['sid' => $sessionId]);
}

function in_compare(int $productId): bool
{
    return in_array($productId, compare_product_ids(), true);
}

/** Full product rows plus a merged specification matrix for the compare table. */
function compare_products(): array
{
    $ids = compare_product_ids();
    if ($ids === []) {
        return ['products' => [], 'spec_keys' => [], 'specs' => []];
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'c');
    $rows = Database::fetchAll(
        product_select_sql() . ' WHERE p.`id` IN (' . $placeholders . ') AND ' . product_visible_sql()
        . ' ORDER BY FIELD(p.`id`, ' . implode(',', $ids) . ')',
        $params
    );
    $products = array_map('decorate_product', $rows);

    $specRows = Database::fetchAll(
        'SELECT `product_id`, `spec_group`, `spec_key`, `spec_value`, `sort_order`
         FROM `product_specifications` WHERE `product_id` IN (' . $placeholders . ')
         ORDER BY `sort_order`, `id`',
        $params
    );

    // Build a union of spec keys so every row in the table lines up.
    $specKeys = [];
    $specs = [];
    foreach ($specRows as $row) {
        $key = $row['spec_key'];
        if (!in_array($key, $specKeys, true)) {
            $specKeys[] = $key;
        }
        $specs[(int) $row['product_id']][$key] = $row['spec_value'];
    }

    return ['products' => $products, 'spec_keys' => $specKeys, 'specs' => $specs];
}

/** Claim a guest comparison list at login. */
function compare_merge_guest_into_user(string $sessionId, int $userId): void
{
    if ($sessionId === '') {
        return;
    }
    try {
        $guestIds = Database::fetchColumnAll(
            'SELECT `product_id` FROM `compare_items` WHERE `session_id` = :sid', ['sid' => $sessionId]
        );
        foreach ($guestIds as $productId) {
            $already = Database::fetchColumn(
                'SELECT `id` FROM `compare_items` WHERE `user_id` = :uid AND `product_id` = :pid LIMIT 1',
                ['uid' => $userId, 'pid' => (int) $productId]
            );
            if ($already === null) {
                Database::insert('compare_items', ['user_id' => $userId, 'session_id' => null, 'product_id' => (int) $productId]);
            }
        }
        Database::delete('compare_items', '`session_id` = :sid', ['sid' => $sessionId]);

        // Wishlist and recently-viewed follow the same login handover.
        wishlist_merge_guest_into_user($userId);
        recently_viewed_merge($sessionId, $userId);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Compare merge failed: ' . $e->getMessage());
    }
}

// ===========================================================================
//  Advertised offers
//
//  An offer is a coupon the store has chosen to show on the storefront — the
//  "Show as an offer" switch in Marketing > Offers & Coupons. Its discount,
//  dates, usage limits and product / category / brand restrictions are the
//  coupon's own and validate_coupon() enforces them at checkout, so nothing
//  displayed here can promise more than the checkout will give.
// ===========================================================================

/**
 * Live offers for one storefront placement.
 *
 * @param string     $placement home | product | cart
 * @param array|null $product   on a product page, only offers that can apply to it
 */
function public_offers(string $placement, ?array $product = null, int $limit = 6): array
{
    $rows = cache_remember('offers.public.' . $placement, 120, static function () use ($placement): array {
        return Database::fetchAll(
            "SELECT * FROM `coupons`
             WHERE `status` = 'active' AND `offer_visible` = 1
               AND FIND_IN_SET(:placement, `offer_placements`) > 0
               AND (`start_date` IS NULL OR `start_date` <= NOW())
               AND (`end_date` IS NULL OR `end_date` >= NOW())
               AND (`usage_limit` IS NULL OR `used_count` < `usage_limit`)
             ORDER BY `offer_sort`, `id`",
            ['placement' => $placement]
        );
    });

    if ($rows === []) {
        return [];
    }

    [$in, $params] = Database::inPlaceholders(array_map(static fn ($r) => (int) $r['id'], $rows), 'oc');
    $rules = [];
    foreach (Database::fetchAll(
        'SELECT `coupon_id`, `restriction_type`, `reference_id` FROM `coupon_restrictions` WHERE `coupon_id` IN (' . $in . ')',
        $params
    ) as $rule) {
        $rules[(int) $rule['coupon_id']][] = $rule;
    }

    $offers = [];
    foreach ($rows as $row) {
        $own   = $rules[(int) $row['id']] ?? [];
        $types = array_column($own, 'restriction_type');

        // Issued to named customers: not something to show everybody.
        if (in_array('user', $types, true)) {
            continue;
        }
        if ($product !== null && !offer_applies_to_product($own, $product)) {
            continue;
        }

        $row['first_order_only'] = in_array('first_order', $types, true);
        $row['scoped']           = (bool) array_intersect($types, ['product', 'category', 'brand']);
        $row['headline']         = offer_headline($row);
        $row['terms']            = offer_terms($row, $product !== null);
        $offers[] = $row;

        if (count($offers) >= $limit) {
            break;
        }
    }

    return $offers;
}

/**
 * Could this coupon's catalogue rules apply to this product?
 * The same test coupon_restrictions_pass() runs per cart line: any product,
 * category (including subcategories) or brand rule matching is enough.
 */
function offer_applies_to_product(array $rules, array $product): bool
{
    $scoped = array_filter($rules, static fn ($r) => in_array($r['restriction_type'], ['product', 'category', 'brand'], true));
    if ($scoped === []) {
        return true;
    }

    $productId  = (int) ($product['id'] ?? 0);
    $categoryId = (int) ($product['category_id'] ?? 0);
    $brandId    = (int) ($product['brand_id'] ?? 0);

    foreach ($scoped as $rule) {
        $ref = (int) $rule['reference_id'];
        if ($rule['restriction_type'] === 'product' && $ref === $productId) {
            return true;
        }
        if ($rule['restriction_type'] === 'category' && $categoryId > 0
            && in_array($categoryId, category_with_descendants($ref), true)) {
            return true;
        }
        if ($rule['restriction_type'] === 'brand' && $brandId > 0 && $ref === $brandId) {
            return true;
        }
    }
    return false;
}

/** The offer's one-line promise, e.g. "10% off orders over ₹599". */
function offer_headline(array $coupon): string
{
    $title = trim((string) ($coupon['offer_title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    $value = (float) $coupon['value'];
    $base = match ((string) $coupon['type']) {
        COUPON_TYPE_PERCENTAGE => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% off',
        COUPON_TYPE_FIXED      => money($value) . ' off',
        default                => 'Free delivery',
    };

    $minimum = (float) $coupon['minimum_order'];
    return $minimum > 0 ? $base . ' on orders over ' . money($minimum) : $base;
}

/** The conditions a shopper needs before relying on the offer. */
function offer_terms(array $coupon, bool $onProductPage = false): string
{
    $parts = [];

    if (!empty($coupon['first_order_only'])) {
        $parts[] = 'First order only';
    }
    if ((string) $coupon['type'] === COUPON_TYPE_PERCENTAGE && (float) ($coupon['maximum_discount'] ?? 0) > 0) {
        $parts[] = 'Up to ' . money((float) $coupon['maximum_discount']) . ' off';
    }
    // A custom headline may leave the minimum out; it still has to be said.
    if (trim((string) ($coupon['offer_title'] ?? '')) !== '' && (float) $coupon['minimum_order'] > 0) {
        $parts[] = 'On orders over ' . money((float) $coupon['minimum_order']);
    }
    if (!empty($coupon['scoped']) && !$onProductPage) {
        $parts[] = 'On selected items';
    }
    if (!empty($coupon['end_date'])) {
        $parts[] = 'Ends ' . format_date($coupon['end_date'], 'j M');
    }

    return implode(' · ', $parts);
}

/**
 * The one offer the announcement strip advertises.
 *
 * The first offer an operator has marked visible with the `header` placement,
 * and only while there is still time on its clock. Returns null when nothing
 * is running, so the strip renders its messages alone rather than an empty
 * chip with a dead countdown in it.
 *
 * `seconds_left` is null for an offer with no end date: a promo that does not
 * end does not get an urgency timer.
 */
function header_promo(): ?array
{
    foreach (public_offers('header', null, 4) as $offer) {
        $ends = !empty($offer['end_date']) ? strtotime((string) $offer['end_date']) : null;
        if ($ends !== null && $ends <= time()) {
            continue;
        }
        $offer['seconds_left'] = $ends !== null ? max(0, $ends - time()) : null;
        return $offer;
    }

    return null;
}
/**
 * The combo sets in a basket, one entry per group.
 *
 * `sets` is derived from the component lines rather than trusted from a stored
 * number: a shopper who edits a component's quantity, or a line that was
 * capped by stock in cart_items(), must not keep the full set's discount. The
 * smallest whole set the remaining lines can still make decides.
 *
 * @param array<int, array<string, mixed>>|null $items
 * @return array<string, array<string, mixed>> keyed by combo_group
 */
function cart_combo_groups(?array $items = null): array
{
    $items ??= cart_items();
    $groups = [];

    foreach ($items as $item) {
        $group = $item['combo_group'] ?? null;
        if ($group === null || empty($item['combo_id'])) {
            continue;
        }
        $groups[$group]['combo_id'] = (int) $item['combo_id'];
        $groups[$group]['lines'][]  = $item;
    }

    $out = [];
    foreach ($groups as $group => $data) {
        $combo = combo_find($data['combo_id'], true);
        if ($combo === null) {
            // The combo was deleted after it went in the basket. The products
            // stay - they are still real - they simply stop being a set.
            continue;
        }

        $recipe = [];
        foreach (combo_items((int) $combo['id']) as $component) {
            $recipe[(int) $component['product_id']] = max(1, (int) $component['quantity']);
        }
        if ($recipe === []) {
            continue;
        }

        $sets = PHP_INT_MAX;
        foreach ($data['lines'] as $line) {
            $per = $recipe[(int) $line['product_id']] ?? null;
            if ($per === null) {
                continue;
            }
            $sets = min($sets, intdiv((int) $line['quantity'], $per));
        }
        // Every component of the recipe has to be present, or it is not a set.
        $present = count(array_unique(array_map(static fn ($l) => (int) $l['product_id'], $data['lines'])));
        if ($sets === PHP_INT_MAX || $sets < 1 || $present < count($recipe)) {
            continue;
        }

        $pricing = combo_pricing($combo);

        $out[$group] = [
            'group'        => $group,
            'combo'        => combo_decorate($combo),
            'sets'         => $sets,
            'lines'        => $data['lines'],
            'line_ids'     => array_map(static fn ($l) => (int) $l['item_id'], $data['lines']),
            'saving'       => money_round($pricing['saving'] * $sets),
            'price'        => money_round($pricing['price'] * $sets),
            'regular'      => money_round($pricing['regular'] * $sets),
        ];
    }

    return $out;
}

/** What every combo in the basket takes off the subtotal. */
function cart_combo_discount(?array $items = null): float
{
    $total = 0.0;
    foreach (cart_combo_groups($items) as $group) {
        $total += (float) $group['saving'];
    }

    return money_round($total);
}

/**
 * Put a combo in the basket.
 *
 * Adds the component lines the cart already knows how to hold, tagged with one
 * group id, so stock, tax and fulfilment all keep working through the existing
 * paths. Returns the same shape cart_add() does.
 */
function cart_add_combo(int $comboId, int $quantity = 1): array
{
    $combo = combo_find($comboId);
    if ($combo === null) {
        return ['ok' => false, 'message' => 'That combo is no longer available.'];
    }

    $items = combo_items($comboId);
    if (!combo_is_sellable($combo, $items)) {
        return ['ok' => false, 'message' => 'That combo is no longer available.'];
    }

    $quantity = max(1, $quantity);
    $ceiling  = min(combo_available_sets($combo, $items), max(1, (int) $combo['max_per_order']));
    if ($quantity > $ceiling) {
        $quantity = $ceiling;
    }
    if ($quantity < 1) {
        return ['ok' => false, 'message' => 'That combo is out of stock.'];
    }

    $cartId = (int) get_or_create_cart()['id'];
    $group  = combo_group_id();

    // One transaction: a half-added set is worse than a rejected one, because
    // it prices as loose products and the shopper is charged the difference.
    Database::transaction(static function () use ($items, $cartId, $comboId, $group, $quantity): void {
        foreach ($items as $component) {
            Database::insert('cart_items', [
                'cart_id'     => $cartId,
                'product_id'  => (int) $component['product_id'],
                'variant_id'  => $component['variant_id'] !== null ? (int) $component['variant_id'] : null,
                'quantity'    => max(1, (int) $component['quantity']) * $quantity,
                'combo_id'    => $comboId,
                'combo_group' => $group,
            ]);
        }
    });

    cache_bust();

    return [
        'ok'      => true,
        'message' => $quantity > 1
            ? $quantity . ' sets added to your cart.'
            : 'Combo added to your cart.',
        'group'   => $group,
        'count'   => cart_count(),
    ];
}

/** Remove one whole set from the basket. */
function cart_remove_combo(string $group): bool
{
    $cartId = cart_id();
    if ($cartId === 0 || $group === '') {
        return false;
    }

    $removed = Database::delete(
        'cart_items',
        '`cart_id` = :cid AND `combo_group` = :g',
        ['cid' => $cartId, 'g' => $group]
    );
    cache_bust();

    return $removed > 0;
}
