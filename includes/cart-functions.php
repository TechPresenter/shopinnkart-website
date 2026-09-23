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
 * This visitor's cart row, or null when they have never put anything in one.
 *
 * Reading a cart must never write one. cart_count() runs from
 * includes/header.php on every single page, so the old create-on-read gave
 * every crawler and every one-page visit a `carts` row of its own - 3,797 of
 * the live database's 3,800 rows were empty guest carts, which buried the real
 * abandoned-cart figure the store reports on.
 *
 * Pass $fresh after writing to `carts` (coupon apply/remove, a rehome) so the
 * memoised row does not hand stale coupon data to cart_totals() later in the
 * same request.
 */
function cart_row(bool $fresh = false): ?array
{
    // false = "not looked up yet"; null = "looked up, there isn't one".
    static $cart = false;
    if ($cart !== false && !$fresh) {
        return $cart;
    }

    $userId = current_user_id();
    if ($userId !== null) {
        return $cart = Database::fetch(
            'SELECT * FROM `carts` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT 1',
            ['uid' => $userId]
        );
    }

    $sessionId = session_key();
    if ($sessionId === '') {
        return $cart = null;
    }

    return $cart = Database::fetch(
        'SELECT * FROM `carts` WHERE `session_id` = :sid AND `user_id` IS NULL ORDER BY `id` DESC LIMIT 1',
        ['sid' => $sessionId]
    );
}

/**
 * The cart row, created on demand.
 *
 * Only the writers call this - cart_add(), cart_add_combo() and the coupon
 * endpoints - so a cart exists exactly when something has been put in it.
 */
function get_or_create_cart(bool $fresh = false): array
{
    $row = cart_row($fresh);
    if ($row !== null) {
        return $row;
    }

    $userId = current_user_id();
    $sessionId = session_key();

    if ($userId === null && $sessionId === '') {
        // No session (CLI or cookies disabled) - hand back an empty in-memory cart.
        return ['id' => 0, 'user_id' => null, 'session_id' => null, 'coupon_id' => null, 'coupon_code' => null];
    }

    $id = Database::insert('carts', [
        'user_id'    => $userId,
        'session_id' => $userId === null ? $sessionId : null,
    ]);

    return cart_row(true)
        ?? ['id' => $id, 'user_id' => $userId, 'session_id' => $sessionId, 'coupon_id' => null, 'coupon_code' => null];
}

/** The cart's id, or 0 when this visitor has no cart yet. */
function cart_id(): int
{
    return (int) (cart_row()['id'] ?? 0);
}

/**
 * Carry a guest's basket across a session-id rotation.
 *
 * includes/init.php rotates the id every 30 minutes to blunt fixation, and a
 * guest cart is keyed on that id: without this the shopper came back from a
 * long browse to an empty basket, and the row they had filled was orphaned.
 * Never throws - a rotation must not be able to break the page it happens on.
 */
function cart_session_rehome(string $oldSessionId, string $newSessionId): void
{
    if ($oldSessionId === '' || $newSessionId === '' || $oldSessionId === $newSessionId) {
        return;
    }

    try {
        Database::query(
            'UPDATE `carts` SET `session_id` = :new WHERE `session_id` = :old AND `user_id` IS NULL',
            ['new' => $newSessionId, 'old' => $oldSessionId]
        );
        cart_row(true);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Cart rehome after session rotation failed: ' . $e->getMessage());
    }
}

/**
 * The most units of one product a single cart line may hold: live stock, and
 * the product's own per-order cap.
 */
function cart_line_ceiling(int $productId, ?int $variantId = null): int
{
    $product = Database::fetch('SELECT `stock`, `max_order_qty` FROM `products` WHERE `id` = :id', ['id' => $productId]);
    if ($product === null) {
        return 0;
    }

    $stock = (int) $product['stock'];
    if ($variantId !== null) {
        $stock = (int) Database::fetchColumn('SELECT `stock` FROM `product_variants` WHERE `id` = :id', ['id' => $variantId]);
    }

    return max(0, min($stock, max(1, (int) $product['max_order_qty'])));
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

                // The per-order cap was only ever applied when a line was
                // added, so a full guest cart folded into a full account cart
                // walked straight past it - ten plus ten went in as twenty.
                $ceiling = cart_line_ceiling(
                    (int) $item['product_id'],
                    $item['variant_id'] === null ? null : (int) $item['variant_id']
                );

                if ($existing !== null) {
                    $merged = (int) $existing['quantity'] + (int) $item['quantity'];
                    Database::update(
                        'cart_items',
                        ['quantity' => max(1, min($merged, max(1, $ceiling)))],
                        '`id` = :id',
                        ['id' => (int) $existing['id']]
                    );
                } else {
                    Database::update('cart_items', [
                        'cart_id'  => (int) $userCart['id'],
                        'quantity' => max(1, min((int) $item['quantity'], max(1, $ceiling))),
                    ], '`id` = :id', ['id' => (int) $item['id']]);
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

    // The first thing actually going in is what brings a cart into existence.
    $cartId = (int) get_or_create_cart()['id'];
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
 * The payment method a total is priced against.
 *
 * One lookup for the cart, the checkout summary and create_order(), so the
 * handling fee shown and the handling fee charged are read from the same row.
 */
function pricing_payment_method(?string $code): ?array
{
    if ($code === null || $code === '') {
        return null;
    }

    return Database::fetch(
        "SELECT * FROM `payment_methods` WHERE `code` = :code AND `status` = 'active' LIMIT 1",
        ['code' => $code]
    );
}

/**
 * THE PRICING ENGINE - the one place a basket's money is decided.
 *
 * The cart page, /api/checkout/totals and create_order() all price through
 * here. They used to each do their own arithmetic, and create_order()'s copy
 * had no combo logic at all: the shopper agreed to a set price and the order,
 * the payment row and the invoice were written at the full component price.
 * One engine means the figure on the checkout page and the figure in the
 * order row cannot disagree.
 *
 * Nothing in here writes. Callers that want a side effect (dropping a coupon
 * that stopped qualifying, claiming a usage) do it themselves.
 *
 * @param array $lines   cart_items() rows, or the same shape rebuilt from
 *                       locked product rows inside create_order(). Each needs
 *                       product_id, variant_id, quantity, price, mrp,
 *                       tax_rate, free_shipping, combo_id, combo_group.
 * @param array $options coupon_code, coupon (a pre-locked coupon row),
 *                       user_id, email, phone, shipping_method,
 *                       payment_method.
 */
function cart_quote(array $lines, array $options = []): array
{
    $shippingMethod = (string) ($options['shipping_method'] ?? SHIPPING_STANDARD);
    $paymentMethod  = $options['payment_method'] ?? null;

    $subtotal = 0.0;
    $mrpTotal = 0.0;
    $units = 0;
    $allFreeShipping = $lines !== [];

    foreach ($lines as $index => $line) {
        $quantity = max(0, (int) $line['quantity']);
        $lineSubtotal = money_round((float) $line['price'] * $quantity);
        $lines[$index]['quantity'] = $quantity;
        $lines[$index]['subtotal'] = $lineSubtotal;

        $subtotal += $lineSubtotal;
        $mrpTotal += (float) $line['mrp'] * $quantity;
        $units    += $quantity;
        if (empty($line['free_shipping'])) {
            $allFreeShipping = false;
        }
    }
    $subtotal = money_round($subtotal);
    $mrpTotal = money_round($mrpTotal);

    // --- coupon -------------------------------------------------------------
    $couponDiscount = 0.0;
    $couponRow = null;
    $couponCode = null;
    $couponFreeShipping = false;
    $couponMessage = null;
    $couponOk = true;

    $requestedCoupon = trim((string) ($options['coupon_code'] ?? ''));
    if ($requestedCoupon !== '') {
        $result = validate_coupon($requestedCoupon, $subtotal, $lines, $options['user_id'] ?? null, [
            'email'   => $options['email'] ?? null,
            'phone'   => $options['phone'] ?? null,
            'coupon'  => $options['coupon'] ?? null,
            'locking' => !empty($options['locking']),
        ]);
        if ($result['ok']) {
            $couponDiscount     = (float) $result['discount'];
            $couponRow          = $result['coupon'];
            $couponCode         = (string) $result['coupon']['code'];
            $couponFreeShipping = (bool) $result['free_shipping'];
        } else {
            $couponOk = false;
            $couponMessage = $result['message'];
        }
    }
    $couponDiscount = money_round(min($couponDiscount, $subtotal));

    // --- combos -------------------------------------------------------------
    // A set's saving is the difference between its components' own prices and
    // the combo price. It is taken off here rather than by rewriting the line
    // prices, because tax, the free-delivery threshold and the coupon's minimum
    // order are all computed from those lines - see cart_combo_groups().
    //
    // It stacks with a coupon on purpose: the coupon was validated against the
    // subtotal BEFORE this, which is the stricter order. The pair is then
    // clamped so the two together can never exceed what is in the basket.
    // create_order() passes its own, derived from the whole cart lines before
    // a part-covered flash sale split any of them: a set is counted in
    // components, not in what each component happened to cost.
    $comboGroups   = $options['combo_groups'] ?? cart_combo_groups($lines);
    $comboDiscount = 0.0;
    foreach ($comboGroups as $group) {
        $comboDiscount += (float) $group['saving'];
    }
    $comboDiscount = money_round($comboDiscount);

    $discount = money_round(min($couponDiscount + $comboDiscount, $subtotal));

    // --- shipping -----------------------------------------------------------
    $shipping = calculate_shipping($subtotal - $discount, $shippingMethod, $allFreeShipping || $couponFreeShipping);

    // --- tax ----------------------------------------------------------------
    $taxable = max(0.0, $subtotal - $discount);
    $tax = calculate_tax($lines, $subtotal, $discount);

    // --- payment surcharge / discount --------------------------------------
    $methodRow = pricing_payment_method(is_string($paymentMethod) ? $paymentMethod : null);
    $paymentCharge = 0.0;
    $paymentDiscount = 0.0;
    if ($methodRow !== null) {
        $paymentCharge = money_round((float) $methodRow['extra_charge']);
        if ((float) $methodRow['discount_percent'] > 0) {
            $paymentDiscount = money_round($taxable * ((float) $methodRow['discount_percent'] / 100));
        }
    }

    $taxInclusive = setting_bool('tax_inclusive', true);
    $total = $taxable + $shipping['amount'] + $paymentCharge - $paymentDiscount;
    if (!$taxInclusive) {
        $total += $tax;
    }
    $total = money_round(max(0.0, $total));

    return [
        'lines'            => $lines,
        'items_count'      => count($lines),
        'units'            => $units,
        'mrp_total'        => $mrpTotal,
        'subtotal'         => $subtotal,
        'combo_groups'     => $comboGroups,
        'combo_discount'   => $comboDiscount,
        'coupon'           => $couponRow,
        'coupon_code'      => $couponCode,
        'coupon_discount'  => $couponDiscount,
        'coupon_ok'        => $couponOk,
        'coupon_message'   => $couponMessage,
        'coupon_free_shipping' => $couponFreeShipping,
        'discount'         => $discount,
        'taxable'          => $taxable,
        // The proportion of each line that is still taxable after a cart-level
        // discount. order_items and calculate_tax() have to use the same one or
        // the line taxes will not sum to orders.tax_amount.
        'tax_ratio'        => $subtotal > 0 ? max(0.0, ($subtotal - $discount) / $subtotal) : 1.0,
        'shipping'         => $shipping,
        'tax'              => $tax,
        'tax_inclusive'    => $taxInclusive,
        'payment_method_row' => $methodRow,
        'payment_charge'   => $paymentCharge,
        'payment_discount' => $paymentDiscount,
        'all_free_shipping' => $allFreeShipping,
        'total'            => $total,
    ];
}

/**
 * Every money value for the current cart, in the shape the views expect.
 *
 * A thin presentation layer over cart_quote(): the arithmetic lives there so
 * checkout and order creation share it, and this adds the display strings and
 * the free-delivery meter.
 *
 * @param array|null $items Pass pre-fetched items to avoid a second query.
 */
function cart_totals(?array $items = null, string $shippingMethod = SHIPPING_STANDARD, ?string $paymentMethod = null): array
{
    $items = $items ?? cart_items();
    $cart = cart_row();

    $quote = cart_quote($items, [
        'coupon_code'     => $cart['coupon_code'] ?? null,
        'shipping_method' => $shippingMethod,
        'payment_method'  => $paymentMethod,
    ]);

    // The coupon stopped qualifying (cart changed, expired, limit hit). This
    // is the one write cart_totals() makes, and the engine deliberately leaves
    // it to the caller.
    $couponMessage = null;
    if (!$quote['coupon_ok'] && !empty($cart['id'])) {
        Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => (int) $cart['id']]);
        cart_row(true);
        $couponMessage = $quote['coupon_message'];
    }

    $subtotal = $quote['subtotal'];
    $mrpTotal = $quote['mrp_total'];
    $discount = $quote['discount'];
    $taxable  = $quote['taxable'];
    $shipping = $quote['shipping'];
    $tax      = $quote['tax'];
    $paymentCharge   = $quote['payment_charge'];
    $paymentDiscount = $quote['payment_discount'];
    $taxInclusive    = $quote['tax_inclusive'];
    $comboDiscount   = $quote['combo_discount'];
    $couponCode      = $quote['coupon_code'];
    $units           = $quote['units'];
    $total           = $quote['total'];

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
 * The one message an unknown, inactive, not-yet-started, expired or exhausted
 * code gets.
 *
 * Telling those four apart confirmed to a guesser that a code exists, which is
 * most of the work of guessing a private, customer-issued code. A shopper
 * holding a real code that simply does not fit this basket still gets the
 * specific reason (minimum order, already used, selected items only).
 */
function coupon_refusal_message(): string
{
    return "This code can't be applied to your order.";
}

/**
 * How many times one customer has used a coupon.
 *
 * Keyed on identity, not on the session: an account id when they are signed
 * in, and the email and phone on the order either way. Counting only user_id
 * let a signed-out shopper use a once-per-customer coupon as often as they
 * liked, and let a registered customer who had used it sign out and use it
 * again with the same email.
 */
function coupon_uses_by_identity(int $couponId, ?int $userId, ?string $email, ?string $phone, bool $locking = false): int
{
    $where  = [];
    $params = ['cid' => $couponId];

    if ($userId !== null) {
        $where[] = '`user_id` = :uid';
        $params['uid'] = $userId;
    }
    $email = $email === null ? '' : mb_strtolower(trim($email));
    if ($email !== '') {
        $where[] = 'LOWER(`email`) = :email';
        $params['email'] = $email;
    }
    $phone = $phone === null ? '' : (string) (normalize_phone($phone) ?? '');
    if ($phone !== '') {
        $where[] = '`phone` = :phone';
        $params['phone'] = $phone;
    }

    if ($where === []) {
        return 0;
    }

    // Inside create_order this has to be a LOCKING read. The transaction's
    // REPEATABLE READ snapshot was fixed by its first SELECT, so a plain count
    // here still showed zero usages after the other tab had already committed
    // one - both checkouts passed a per_user_limit of 1. A locking read always
    // sees the latest committed rows, and the coupon row is already held
    // exclusively, so only one checkout is ever inside this at a time.
    $suffix = $locking ? ' LOCK IN SHARE MODE' : '';

    return (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `coupon_usage` WHERE `coupon_id` = :cid AND (' . implode(' OR ', $where) . ')' . $suffix,
        $params
    );
}

/**
 * Validate a coupon against the current cart. All checks are server-side.
 *
 * @param array $options email / phone (the identity the per-customer limit is
 *                       keyed on) and `coupon` - a row already read FOR UPDATE
 *                       by create_order(), so the limits are tested against
 *                       the locked figures rather than a stale snapshot.
 * @return array{ok:bool, message:string, reason:?string, discount:float, free_shipping:bool, coupon:?array}
 */
function validate_coupon(string $code, float $subtotal, ?array $items = null, ?int $userId = null, array $options = []): array
{
    $fail = static fn (string $message, string $reason): array => [
        'ok' => false, 'message' => $message, 'reason' => $reason,
        'discount' => 0.0, 'free_shipping' => false, 'coupon' => null,
    ];

    $code = strtoupper(trim($code));
    if ($code === '') {
        return $fail('Enter a coupon code.', 'empty');
    }

    $coupon = $options['coupon'] ?? null;
    if ($coupon === null) {
        $coupon = Database::fetch('SELECT * FROM `coupons` WHERE UPPER(`code`) = :code LIMIT 1', ['code' => $code]);
    }

    // Everything down to the usage limit answers with the same sentence: see
    // coupon_refusal_message().
    if ($coupon === null) {
        return $fail(coupon_refusal_message(), 'unknown');
    }
    if ($coupon['status'] !== 'active') {
        return $fail(coupon_refusal_message(), 'inactive');
    }
    if (!schedule_is_live($coupon['start_date'], $coupon['end_date'])) {
        return $fail(coupon_refusal_message(), 'schedule');
    }
    if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
        return $fail(coupon_refusal_message(), 'exhausted');
    }
    if ($subtotal < (float) $coupon['minimum_order']) {
        return $fail('Add ' . money((float) $coupon['minimum_order'] - $subtotal) . ' more to use this coupon.', 'minimum');
    }

    $userId = $userId ?? current_user_id();

    // Per-customer usage limit, keyed on who the customer is rather than on
    // whether they happen to be signed in.
    if ((int) $coupon['per_user_limit'] > 0) {
        $used = coupon_uses_by_identity(
            (int) $coupon['id'],
            $userId,
            $options['email'] ?? (current_user()['email'] ?? null),
            $options['phone'] ?? null,
            !empty($options['locking'])
        );
        if ($used >= (int) $coupon['per_user_limit']) {
            return $fail('You have already used this coupon.', 'per_user');
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
            return $fail($check['message'], 'restriction');
        }
        // A product/category/brand restriction narrows the discountable base.
        $subtotal = $check['eligible_subtotal'] ?? $subtotal;
        if ($subtotal <= 0) {
            return $fail('This coupon does not apply to the items in your cart.', 'restriction');
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
        'reason'        => null,
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

// ---------------------------------------------------------------------------
//  Guessing a coupon code
//
//  Private and customer-issued codes are short, so trying them is cheap. The
//  only limit used to be 20/min on /api/coupons/validate.php, kept in
//  $_SESSION - a new cookie reset it - and /api/cart/coupon.php and the cart
//  page's own form had no limit at all.
//
//  Two buckets, both through the shared database-backed limiter:
//    coupon.try   every attempt, generous, so a real shopper is never stopped
//    coupon.fail  only wrong codes, tight, so guessing costs something
//  Both are keyed on the address AND on the basket/account, so neither a
//  shared office address nor a fresh cookie hands out a new quota. A code that
//  turns out to be real clears the failure bucket, which is why a mistyped
//  first attempt never blocks the second.
// ---------------------------------------------------------------------------

/** The identities a coupon attempt is counted against. */
function coupon_guess_keys(): array
{
    $keys = ['ip:' . client_ip()];

    $userId = current_user_id();
    if ($userId !== null) {
        return array_merge($keys, ['user:' . $userId]);
    }

    // Only a basket that EXISTS gets a key of its own. A visitor who has not
    // started one has cart_id() === 0, and "cart:0" was therefore a single
    // bucket shared by every basket-less visitor on the internet: thirty tries
    // from one empty session spent it, and the next such visitor - any address,
    // any browser - was told "Too many coupon attempts" on their first try.
    // The address key still holds the guesser; this one only ever had to stop
    // somebody spreading the guessing across baskets of their own.
    $cartId = cart_id();
    if ($cartId > 0) {
        $keys[] = 'cart:' . $cartId;
    }

    return $keys;
}

/**
 * May this visitor try another coupon code?
 *
 * @return array{ok:bool, message:string, retry_after:int}
 */
function coupon_guess_allowed(): array
{
    foreach (coupon_guess_keys() as $key) {
        if (!rate_limit_allows('coupon.fail', $key, 10, 900)) {
            return [
                'ok' => false,
                'message' => 'Too many coupon attempts. Please wait a few minutes and try again.',
                'retry_after' => rate_limit_retry_after(900),
            ];
        }
        if (!rate_limit_attempt('coupon.try', $key, 30, 300)) {
            return [
                'ok' => false,
                'message' => 'Too many coupon attempts. Please wait a few minutes and try again.',
                'retry_after' => rate_limit_retry_after(300),
            ];
        }
    }

    return ['ok' => true, 'message' => '', 'retry_after' => 0];
}

/**
 * Record how an attempt turned out.
 *
 * Only a code that does not exist, is not live or is exhausted counts as a
 * guess; "you have already used this" or "add ₹200 more" came from somebody
 * holding a real code, and charging them for it would lock out the customer
 * the coupon was issued to.
 */
function coupon_guess_record(?string $reason): void
{
    if ($reason === null) {
        // A code that turned out to be real. Forgiving the failures before it
        // is what keeps a mistyped first attempt from costing anything.
        foreach (coupon_guess_keys() as $key) {
            rate_limit_clear('coupon.fail', $key);
        }
        return;
    }

    if (!in_array($reason, ['unknown', 'inactive', 'schedule', 'exhausted'], true)) {
        return;
    }

    $spent = false;
    foreach (coupon_guess_keys() as $key) {
        rate_limit_attempt('coupon.fail', $key, 10, 900);
        // The attempt that used the budget up is the one worth telling the
        // admin about - once, not once per refused try afterwards.
        if (!rate_limit_allows('coupon.fail', $key, 10, 900)) {
            $spent = true;
        }
    }

    if ($spent) {
        security_event('coupon.guess_burst', 'medium', [
            'reason' => $reason,
        ], current_user_id(), current_user_id() === null ? null : 'customer');
    }
}

/** Attach a validated coupon to the cart. */
function cart_apply_coupon(string $code): array
{
    $allowed = coupon_guess_allowed();
    if (!$allowed['ok']) {
        return ['ok' => false, 'message' => $allowed['message'], 'rate_limited' => true];
    }

    $items = cart_items();
    if ($items === []) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    $subtotal = array_sum(array_column($items, 'subtotal'));
    $result = validate_coupon($code, money_round((float) $subtotal), $items);
    coupon_guess_record($result['ok'] ? null : ($result['reason'] ?? 'unknown'));

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
    cart_row(true);

    return ['ok' => true, 'message' => 'Coupon "' . $result['coupon']['code'] . '" applied.', 'discount' => $result['discount']];
}

function cart_remove_coupon(): void
{
    $cartId = cart_id();
    if ($cartId === 0) {
        // Nothing to detach, and nothing worth minting a cart row for: this
        // endpoint is reachable from any session, and creating one here would
        // put back the empty rows cart_row() was split out to stop.
        return;
    }

    Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => $cartId]);
    // Same reason as cart_apply_coupon(): without this a caller that reads
    // the totals in the same request still sees the removed coupon.
    cart_row(true);
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
        // Live only. This used to pass anyStatus, so a set the store had
        // switched off - or one whose end_date had passed - went on taking its
        // saving off the basket for as long as the lines sat there. The
        // products stay; they are still real, they simply stop being a set and
        // fall through to the loose list at their own prices.
        $combo = combo_find($data['combo_id']);
        if ($combo === null) {
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
 * How many whole sets of one combo the basket already holds, across every
 * group. combos.max_per_order is a limit on the order, so it has to be counted
 * this way rather than per add-to-cart click.
 */
function combo_sets_in_cart(int $comboId, ?array $items = null): int
{
    $sets = 0;
    foreach (cart_combo_groups($items) as $group) {
        if ((int) $group['combo']['id'] === $comboId) {
            $sets += (int) $group['sets'];
        }
    }

    return $sets;
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

    // max_per_order is a cap on the basket, not on one click: every add-combo
    // call minted a fresh group, so three calls each at the cap put three
    // times the cap in the cart and create_order() never looked.
    $already  = combo_sets_in_cart($comboId);
    $ceiling  = min(
        combo_available_sets($combo, $items),
        max(1, (int) $combo['max_per_order']) - $already
    );
    if ($quantity > $ceiling) {
        $quantity = $ceiling;
    }
    if ($quantity < 1) {
        return [
            'ok' => false,
            'message' => $already > 0
                ? 'You already have the most of this combo we can sell in one order.'
                : 'That combo is out of stock.',
        ];
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
