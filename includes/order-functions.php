<?php
/**
 * ShopInnKart - Order lifecycle.
 *
 * create_order() is the only place an order is written. It runs inside a
 * single transaction, re-reads every price and stock level from the database
 * under a row lock, and rolls back completely on any failure.
 */

declare(strict_types=1);

// ===========================================================================
//  PAYMENT GATEWAY ABSTRACTION
// ===========================================================================

/**
 * Contract every payment method implements. Adding Razorpay / Cashfree /
 * Stripe / PayU later means dropping in a new class and registering it in
 * PaymentGatewayFactory - checkout itself does not change.
 */
interface PaymentGatewayInterface
{
    /** Machine code, matching payment_methods.code. */
    public function code(): string;

    /** Customer-facing label. */
    public function label(): string;

    /** True when the customer is redirected to / charged by a third party. */
    public function isOnline(): bool;

    /** Called before the order row is committed. Return false to abort. */
    public function validate(array $order, array $context): bool;

    /**
     * Called immediately after the order is committed.
     * Online gateways return ['redirect' => $url] or ['payload' => [...]].
     */
    public function initiate(array $order): array;

    /** Verify a gateway callback/webhook payload. */
    public function verify(array $payload): bool;
}

/** Cash on Delivery - the only gateway enabled out of the box. */
final class CodGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return PAYMENT_METHOD_COD;
    }

    public function label(): string
    {
        return 'Cash on Delivery';
    }

    public function isOnline(): bool
    {
        return false;
    }

    public function validate(array $order, array $context): bool
    {
        if (!setting_bool('cod_enabled', true)) {
            return false;
        }

        $max = setting_float('cod_max_amount', 50000);
        if ($max > 0 && (float) ($order['total_amount'] ?? 0) > $max) {
            return false;
        }

        // Every line must allow COD.
        foreach ($context['items'] ?? [] as $item) {
            if (empty($item['cod_available'])) {
                return false;
            }
        }

        // The delivery pincode must support COD when it is on file.
        $pincode = $order['shipping_pincode'] ?? null;
        if ($pincode) {
            $row = Database::fetch('SELECT `cod_available` FROM `pincodes` WHERE `pincode` = :p LIMIT 1', ['p' => $pincode]);
            if ($row !== null && (int) $row['cod_available'] !== 1) {
                return false;
            }
        }

        return true;
    }

    public function initiate(array $order): array
    {
        return ['status' => PAYMENT_STATUS_PENDING, 'redirect' => null, 'payload' => []];
    }

    public function verify(array $payload): bool
    {
        return true;
    }
}

/** Resolves a payment method code to its gateway implementation. */
final class PaymentGatewayFactory
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    private static array $gateways = [
        PAYMENT_METHOD_COD => CodGateway::class,
    ];

    public static function register(string $code, string $class): void
    {
        if (!is_subclass_of($class, PaymentGatewayInterface::class)) {
            throw new InvalidArgumentException($class . ' must implement PaymentGatewayInterface.');
        }
        self::$gateways[$code] = $class;
    }

    public static function make(string $code): ?PaymentGatewayInterface
    {
        $class = self::$gateways[$code] ?? null;
        return $class === null ? null : new $class();
    }

    /** Payment methods that are both configured in the admin and implemented. */
    public static function available(): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM `payment_methods` WHERE `status` = 'active' ORDER BY `sort_order`, `id`"
        );
        return array_values(array_filter($rows, static fn ($row) => isset(self::$gateways[$row['code']])));
    }
}

// ===========================================================================
//  ORDER NUMBERS
// ===========================================================================

/**
 * Sequential daily order number: SIK + YYYYMMDD + 4-digit counter.
 * The caller retries on a unique-key collision, which is the only race
 * this can lose.
 */
function generate_order_number(): string
{
    $prefix = (string) setting('order_prefix', ORDER_PREFIX);
    $datePart = date('Ymd');

    $todayCount = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `orders` WHERE DATE(`created_at`) = CURDATE()'
    );

    return $prefix . $datePart . str_pad((string) ($todayCount + 1), 4, '0', STR_PAD_LEFT);
}

// ===========================================================================
//  ORDER CREATION
// ===========================================================================

/**
 * Create an order from the current cart.
 *
 * $input keys:
 *   customer_name, customer_email, customer_phone,
 *   shipping_* (name, phone, address, address2, landmark, city, state, pincode, country),
 *   billing_same (bool) or billing_*,
 *   shipping_method, payment_method, customer_note, save_address (bool)
 *
 * @return array{ok:bool, message:string, order:?array, errors:array}
 */
function create_order(array $input): array
{
    $fail = static fn (string $message, array $errors = []): array => [
        'ok' => false, 'message' => $message, 'order' => null, 'errors' => $errors,
    ];

    $userId = current_user_id();
    $cartId = cart_id();

    if ($cartId === 0) {
        return $fail('Your cart could not be read. Please refresh and try again.');
    }

    // ---- 1. Validate the customer + address payload -----------------------
    $validator = new Validator($input, [
        'customer_name'    => 'Full name',
        'customer_email'   => 'Email address',
        'customer_phone'   => 'Mobile number',
        'shipping_address' => 'Address',
        'shipping_city'    => 'City',
        'shipping_state'   => 'State',
        'shipping_pincode' => 'PIN code',
    ]);

    $validator->required('customer_name')->max('customer_name', 150)
        ->required('customer_email')->email('customer_email')
        ->required('customer_phone')->phone('customer_phone')
        ->required('shipping_address')->max('shipping_address', 255)
        ->required('shipping_city')->max('shipping_city', 100)
        ->required('shipping_state')->in('shipping_state', INDIAN_STATES)
        ->required('shipping_pincode')->pincode('shipping_pincode');

    if ($validator->fails()) {
        return $fail('Please correct the highlighted fields.', $validator->errors());
    }

    if ($userId === null && !setting_bool('guest_checkout', true)) {
        return $fail('Please sign in to place an order.');
    }

    $shippingMethod = (string) ($input['shipping_method'] ?? SHIPPING_STANDARD);
    $paymentMethod = (string) ($input['payment_method'] ?? PAYMENT_METHOD_COD);

    $gateway = PaymentGatewayFactory::make($paymentMethod);
    if ($gateway === null) {
        return $fail('That payment method is not available.', ['payment_method' => 'Choose a valid payment method.']);
    }

    // ---- 2. Serviceability -------------------------------------------------
    $pincode = (string) $input['shipping_pincode'];
    $pincodeRow = Database::fetch('SELECT * FROM `pincodes` WHERE `pincode` = :p LIMIT 1', ['p' => $pincode]);
    if ($pincodeRow !== null && (int) $pincodeRow['is_serviceable'] !== 1) {
        return $fail('We do not deliver to ' . $pincode . ' yet.', ['shipping_pincode' => 'Not serviceable.']);
    }

    $orderRow = null;

    try {
        $orderRow = Database::transaction(static function (PDO $pdo) use (
            $input, $userId, $cartId, $shippingMethod, $paymentMethod, $gateway, $pincodeRow, $fail
        ) {
            // ---- 3. Re-read the cart INSIDE the transaction, locking stock --
            // By product, not by the order the lines were added in: the loop
            // below locks each product row, and two checkouts holding the same
            // two products in opposite cart order deadlocked on
            // products.PRIMARY. restore_order_stock() walks them the same way,
            // so a checkout and a cancellation cannot deadlock either.
            $lines = Database::fetchAll(
                'SELECT ci.`id` AS item_id, ci.`quantity`, ci.`product_id`, ci.`variant_id`
                 FROM `cart_items` ci
                 WHERE ci.`cart_id` = :cid
                 ORDER BY ci.`product_id`, ci.`variant_id`, ci.`id`',
                ['cid' => $cartId]
            );

            if ($lines === []) {
                throw new RuntimeException('EMPTY_CART');
            }

            $items = [];
            $subtotal = 0.0;

            foreach ($lines as $line) {
                // FOR UPDATE holds the row until commit, so two simultaneous
                // checkouts cannot both claim the last unit.
                $product = Database::fetch(
                    'SELECT * FROM `products` WHERE `id` = :id FOR UPDATE',
                    ['id' => (int) $line['product_id']]
                );

                if ($product === null || $product['status'] !== 'active') {
                    throw new RuntimeException('UNAVAILABLE:' . ($product['name'] ?? 'A product in your cart'));
                }

                $variant = null;
                if ($line['variant_id'] !== null) {
                    $variant = Database::fetch(
                        'SELECT * FROM `product_variants` WHERE `id` = :id AND `product_id` = :pid FOR UPDATE',
                        ['id' => (int) $line['variant_id'], 'pid' => (int) $product['id']]
                    );
                    if ($variant === null || $variant['status'] !== 'active') {
                        throw new RuntimeException('UNAVAILABLE:' . $product['name']);
                    }
                }

                $quantity = max(1, (int) $line['quantity']);
                $available = $variant !== null ? (int) $variant['stock'] : (int) $product['stock'];

                if ($available < $quantity) {
                    throw new RuntimeException(
                        'STOCK:' . $product['name'] . '|' . $available
                    );
                }

                // ---- 4. Price comes from the database, never the browser ---
                $pricing = product_effective_price($product, $variant);
                $lineSubtotal = money_round($pricing['price'] * $quantity);
                $subtotal += $lineSubtotal;

                $items[] = [
                    'product'       => $product,
                    'variant'       => $variant,
                    'quantity'      => $quantity,
                    'mrp'           => $pricing['mrp'],
                    'price'         => $pricing['price'],
                    'subtotal'      => $lineSubtotal,
                    'tax_rate'      => (float) $product['tax_rate'],
                    'cod_available' => (int) $product['cod_available'] === 1,
                    'free_shipping' => (int) $product['free_shipping'] === 1,
                ];
            }

            $subtotal = money_round($subtotal);

            $minOrder = setting_float('min_order_amount', 0);
            if ($minOrder > 0 && $subtotal < $minOrder) {
                throw new RuntimeException('MINORDER:' . $minOrder);
            }

            // ---- 5. Coupon (re-validated server side) --------------------
            $cart = Database::fetch('SELECT * FROM `carts` WHERE `id` = :id', ['id' => $cartId]);
            $discount = 0.0;
            $couponId = null;
            $couponCode = null;
            $couponFreeShipping = false;

            if (!empty($cart['coupon_code'])) {
                $totalsItems = array_map(static fn ($i) => [
                    'product_id' => (int) $i['product']['id'],
                    'subtotal'   => $i['subtotal'],
                    'quantity'   => $i['quantity'],
                ], $items);

                $couponResult = validate_coupon((string) $cart['coupon_code'], $subtotal, $totalsItems, $userId);
                if ($couponResult['ok']) {
                    $discount = money_round(min((float) $couponResult['discount'], $subtotal));
                    $couponId = (int) $couponResult['coupon']['id'];
                    $couponCode = (string) $couponResult['coupon']['code'];
                    $couponFreeShipping = (bool) $couponResult['free_shipping'];
                }
                // An invalid coupon is silently dropped rather than blocking checkout.
            }

            // ---- 6. Shipping ---------------------------------------------
            $allFreeShipping = true;
            foreach ($items as $item) {
                if (!$item['free_shipping']) {
                    $allFreeShipping = false;
                    break;
                }
            }
            $shipping = calculate_shipping($subtotal - $discount, $shippingMethod, $allFreeShipping || $couponFreeShipping);

            // ---- 7. Tax ---------------------------------------------------
            $taxItems = array_map(static fn ($i) => ['subtotal' => $i['subtotal'], 'tax_rate' => $i['tax_rate']], $items);
            $tax = calculate_tax($taxItems, $subtotal, $discount);
            $taxInclusive = setting_bool('tax_inclusive', true);

            // ---- 8. Payment surcharge ------------------------------------
            $paymentCharge = 0.0;
            $paymentDiscount = 0.0;
            $methodRow = Database::fetch(
                "SELECT * FROM `payment_methods` WHERE `code` = :code AND `status` = 'active' LIMIT 1",
                ['code' => $paymentMethod]
            );
            if ($methodRow !== null) {
                $paymentCharge = money_round((float) $methodRow['extra_charge']);
                if ((float) $methodRow['discount_percent'] > 0) {
                    $paymentDiscount = money_round(($subtotal - $discount) * ((float) $methodRow['discount_percent'] / 100));
                }
            }

            // ---- 9. Grand total ------------------------------------------
            $total = ($subtotal - $discount) + $shipping['amount'] + $paymentCharge - $paymentDiscount;
            if (!$taxInclusive) {
                $total += $tax;
            }
            $total = money_round(max(0.0, $total));

            // ---- 10. Addresses -------------------------------------------
            $billingSame = !empty($input['billing_same']) || empty($input['billing_address']);
            $estimatedDays = $pincodeRow !== null
                ? (int) $pincodeRow['delivery_days']
                : setting_int('default_delivery_days', 4);

            $orderData = [
                'order_number'      => '',
                'user_id'           => $userId,
                'customer_name'     => trim((string) $input['customer_name']),
                'customer_email'    => strtolower(trim((string) $input['customer_email'])),
                'customer_phone'    => (string) (normalize_phone((string) $input['customer_phone']) ?? $input['customer_phone']),

                'shipping_name'     => trim((string) ($input['shipping_name'] ?? $input['customer_name'])),
                'shipping_phone'    => (string) (normalize_phone((string) ($input['shipping_phone'] ?? $input['customer_phone'])) ?? $input['customer_phone']),
                'shipping_address'  => trim((string) $input['shipping_address']),
                'shipping_address2' => trim((string) ($input['shipping_address2'] ?? '')) ?: null,
                'shipping_landmark' => trim((string) ($input['shipping_landmark'] ?? '')) ?: null,
                'shipping_city'     => trim((string) $input['shipping_city']),
                'shipping_state'    => trim((string) $input['shipping_state']),
                'shipping_pincode'  => trim((string) $input['shipping_pincode']),
                'shipping_country'  => trim((string) ($input['shipping_country'] ?? 'India')),

                'billing_name'      => $billingSame ? trim((string) ($input['shipping_name'] ?? $input['customer_name'])) : trim((string) ($input['billing_name'] ?? '')),
                'billing_phone'     => $billingSame ? (string) ($input['shipping_phone'] ?? $input['customer_phone']) : (string) ($input['billing_phone'] ?? ''),
                'billing_address'   => $billingSame ? trim((string) $input['shipping_address']) : trim((string) ($input['billing_address'] ?? '')),
                'billing_city'      => $billingSame ? trim((string) $input['shipping_city']) : trim((string) ($input['billing_city'] ?? '')),
                'billing_state'     => $billingSame ? trim((string) $input['shipping_state']) : trim((string) ($input['billing_state'] ?? '')),
                'billing_pincode'   => $billingSame ? trim((string) $input['shipping_pincode']) : trim((string) ($input['billing_pincode'] ?? '')),
                'billing_country'   => $billingSame ? trim((string) ($input['shipping_country'] ?? 'India')) : trim((string) ($input['billing_country'] ?? 'India')),

                'subtotal'          => $subtotal,
                'discount_amount'   => $discount,
                'coupon_id'         => $couponId,
                'coupon_code'       => $couponCode,
                'shipping_amount'   => $shipping['amount'],
                'tax_amount'        => $tax,
                'payment_charge'    => $paymentCharge,
                'payment_discount'  => $paymentDiscount,
                'total_amount'      => $total,

                'shipping_method'   => $shipping['code'],
                'payment_method'    => $paymentMethod,
                'payment_status'    => PAYMENT_STATUS_PENDING,
                'status'            => ORDER_STATUS_PENDING,

                'customer_note'     => trim((string) ($input['customer_note'] ?? '')) ?: null,
                'estimated_delivery' => date('Y-m-d', strtotime('+' . max(1, $estimatedDays) . ' days')),
                'ip_address'        => client_ip(),
                'user_agent'        => user_agent(),
            ];

            // ---- 11. Gateway pre-flight ----------------------------------
            if (!$gateway->validate($orderData, ['items' => $items])) {
                throw new RuntimeException('GATEWAY:' . $gateway->label() . ' is not available for this order.');
            }

            // ---- 12. Insert the order (retrying on number collision) ------
            $orderId = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $orderData['order_number'] = generate_order_number();
                try {
                    $orderId = Database::insert('orders', $orderData);
                    break;
                } catch (PDOException $e) {
                    // 23000 = integrity constraint violation (duplicate order_number)
                    if ($e->getCode() !== '23000' || $attempt === 4) {
                        throw $e;
                    }
                    // Nudge the counter and retry.
                    $orderData['order_number'] = (string) setting('order_prefix', ORDER_PREFIX)
                        . date('Ymd')
                        . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                }
            }

            if ($orderId === 0) {
                throw new RuntimeException('Could not allocate an order number.');
            }

            // ---- 13. Order items + stock ---------------------------------
            // A cart-level coupon reduces the taxable value of every line, so
            // the same proportion is applied here that calculate_tax() used.
            // Without it the line taxes would not sum to orders.tax_amount and
            // the GST invoice would not reconcile.
            $taxRatio = $subtotal > 0 ? max(0.0, ($subtotal - $discount) / $subtotal) : 1.0;

            foreach ($items as $item) {
                $product = $item['product'];
                $variant = $item['variant'];
                $taxableValue = $item['subtotal'] * $taxRatio;
                $lineTax = $taxInclusive
                    ? money_round($taxableValue - ($taxableValue * 100 / (100 + $item['tax_rate'])))
                    : money_round($taxableValue * ($item['tax_rate'] / 100));

                Database::insert('order_items', [
                    'order_id'      => $orderId,
                    'product_id'    => (int) $product['id'],
                    'variant_id'    => $variant !== null ? (int) $variant['id'] : null,
                    'vendor_id'     => $product['vendor_id'] !== null ? (int) $product['vendor_id'] : null,
                    'product_name'  => (string) $product['name'],
                    'product_sku'   => (string) ($variant['sku'] ?? $product['sku']),
                    'product_image' => $variant !== null && !empty($variant['image']) ? $variant['image'] : $product['main_image'],
                    'variant_name'  => $variant !== null ? (string) $variant['variant_name'] : null,
                    'mrp'           => $item['mrp'],
                    'price'         => $item['price'],
                    'quantity'      => $item['quantity'],
                    'tax_rate'      => $item['tax_rate'],
                    'tax_amount'    => $lineTax,
                    'subtotal'      => $item['subtotal'],
                    'total'         => $taxInclusive ? $item['subtotal'] : money_round($item['subtotal'] + $lineTax),
                ]);

                adjust_stock(
                    (int) $product['id'],
                    $variant !== null ? (int) $variant['id'] : null,
                    -$item['quantity'],
                    'order',
                    'order',
                    $orderId,
                    'Order ' . $orderData['order_number']
                );

                Database::query(
                    'UPDATE `products` SET `sold_count` = `sold_count` + :q WHERE `id` = :id',
                    ['q' => $item['quantity'], 'id' => (int) $product['id']]
                );

                // Burn down flash-sale and deal allocations.
                Database::query(
                    'UPDATE `flash_sale_products` fsp
                     INNER JOIN `flash_sales` fs ON fs.`id` = fsp.`flash_sale_id`
                     SET fsp.`stock_sold` = fsp.`stock_sold` + :q
                     WHERE fsp.`product_id` = :pid AND fs.`status` = \'active\'
                       AND fs.`start_time` <= NOW() AND fs.`end_time` >= NOW()',
                    ['q' => $item['quantity'], 'pid' => (int) $product['id']]
                );
                Database::query(
                    'UPDATE `deals` d
                     INNER JOIN `deal_products` dp ON dp.`deal_id` = d.`id`
                     SET d.`stock_sold` = d.`stock_sold` + :q
                     WHERE dp.`product_id` = :pid AND d.`status` = \'active\'
                       AND d.`start_time` <= NOW() AND d.`end_time` >= NOW()',
                    ['q' => $item['quantity'], 'pid' => (int) $product['id']]
                );
            }

            // ---- 14. Coupon usage ----------------------------------------
            if ($couponId !== null) {
                Database::insert('coupon_usage', [
                    'coupon_id' => $couponId,
                    'user_id'   => $userId,
                    'order_id'  => $orderId,
                    'email'     => $orderData['customer_email'],
                    'discount'  => $discount,
                ]);
                Database::query('UPDATE `coupons` SET `used_count` = `used_count` + 1 WHERE `id` = :id', ['id' => $couponId]);
            }

            // ---- 15. Payment record --------------------------------------
            Database::insert('payments', [
                'order_id' => $orderId,
                'gateway'  => $paymentMethod,
                'amount'   => $total,
                'currency' => (string) setting('currency_code', CURRENCY),
                'status'   => PAYMENT_STATUS_PENDING,
            ]);

            // ---- 16. History ---------------------------------------------
            Database::insert('order_status_history', [
                'order_id'   => $orderId,
                'status'     => ORDER_STATUS_PENDING,
                'note'       => 'Order placed',
                'changed_by' => 'customer',
            ]);

            // ---- 17. Save the address to the account ---------------------
            if ($userId !== null && !empty($input['save_address'])) {
                save_customer_address($userId, $input);
            }

            // ---- 18. Empty the cart --------------------------------------
            Database::delete('cart_items', '`cart_id` = :cid', ['cid' => $cartId]);
            Database::update('carts', ['coupon_id' => null, 'coupon_code' => null], '`id` = :id', ['id' => $cartId]);

            return Database::fetch('SELECT * FROM `orders` WHERE `id` = :id', ['id' => $orderId]);
        });
    } catch (RuntimeException $e) {
        $message = $e->getMessage();

        if ($message === 'EMPTY_CART') {
            return $fail('Your cart is empty.');
        }
        if (strpos($message, 'STOCK:') === 0) {
            [$name, $available] = explode('|', substr($message, 6)) + ['', '0'];
            return $fail(
                (int) $available > 0
                    ? sprintf('Only %d left of "%s". Please update your cart.', (int) $available, $name)
                    : sprintf('"%s" just went out of stock. Please remove it from your cart.', $name)
            );
        }
        if (strpos($message, 'UNAVAILABLE:') === 0) {
            return $fail('"' . substr($message, 12) . '" is no longer available. Please update your cart.');
        }
        if (strpos($message, 'MINORDER:') === 0) {
            return $fail('Minimum order value is ' . money((float) substr($message, 9)) . '.');
        }
        if (strpos($message, 'GATEWAY:') === 0) {
            return $fail(substr($message, 8), ['payment_method' => 'Choose another payment method.']);
        }

        ErrorHandler::log('error', 'Order creation failed: ' . $message);
        return $fail('We could not place your order. Please try again.');
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Order creation failed: ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        return $fail('We could not place your order. Please try again.');
    }

    if ($orderRow === null) {
        return $fail('We could not place your order. Please try again.');
    }

    // ---- 19. Post-commit side effects (never roll the order back) ---------
    //
    // Everything below is best-effort. The customer has paid and the order is
    // committed; an invoice, a PDF or an SMTP problem must not turn that into
    // a failed checkout. Each step is isolated so one failure cannot stop the
    // next — a PDF that will not render still gets the confirmation email out.
    try {
        $gateway->initiate($orderRow);

        if ($paymentMethod === PAYMENT_METHOD_COD && setting_bool('auto_confirm_cod', true)) {
            // Confirms the order, which issues the invoice on its way through
            // update_order_status(). The status email is suppressed because
            // notify_order_placed() below is the customer's confirmation and
            // already carries the invoice — sending both is one message too many.
            update_order_status((int) $orderRow['id'], ORDER_STATUS_CONFIRMED, 'COD order auto-confirmed', 'system', false);
            $orderRow = get_order((int) $orderRow['id']);
        }
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Post-order gateway hook failed: ' . $e->getMessage());
    }

    try {
        // Covers the paths that did not go through auto-confirmation, e.g. a
        // prepaid order that is already marked paid.
        issue_order_invoice($orderRow);
        $orderRow = get_order((int) $orderRow['id']) ?? $orderRow;
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Invoice issue on checkout failed: ' . $e->getMessage());
    }

    try {
        notify_order_placed($orderRow);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Order confirmation email failed to queue: ' . $e->getMessage());
    }

    return ['ok' => true, 'message' => 'Order placed successfully.', 'order' => $orderRow, 'errors' => []];
}

/** Persist a checkout address to the customer's address book. */
function save_customer_address(int $userId, array $input): void
{
    $pincode = trim((string) $input['shipping_pincode']);
    $line1 = trim((string) $input['shipping_address']);

    $exists = Database::fetchColumn(
        'SELECT `id` FROM `user_addresses` WHERE `user_id` = :uid AND `address_line1` = :line AND `pincode` = :pin LIMIT 1',
        ['uid' => $userId, 'line' => $line1, 'pin' => $pincode]
    );
    if ($exists !== null) {
        return;
    }

    $isFirst = (int) Database::fetchColumn('SELECT COUNT(*) FROM `user_addresses` WHERE `user_id` = :uid', ['uid' => $userId]) === 0;

    Database::insert('user_addresses', [
        'user_id'       => $userId,
        'label'         => 'Home',
        'full_name'     => trim((string) ($input['shipping_name'] ?? $input['customer_name'])),
        'phone'         => (string) (normalize_phone((string) ($input['shipping_phone'] ?? $input['customer_phone'])) ?? ''),
        'address_line1' => $line1,
        'address_line2' => trim((string) ($input['shipping_address2'] ?? '')) ?: null,
        'landmark'      => trim((string) ($input['shipping_landmark'] ?? '')) ?: null,
        'city'          => trim((string) $input['shipping_city']),
        'state'         => trim((string) $input['shipping_state']),
        'pincode'       => $pincode,
        'country'       => trim((string) ($input['shipping_country'] ?? 'India')),
        'is_default'    => $isFirst ? 1 : 0,
    ]);
}

// ===========================================================================
//  STOCK
// ===========================================================================

/**
 * Move stock and journal the change. Negative $delta removes stock.
 * Stock can never go below zero.
 */
function adjust_stock(
    int $productId,
    ?int $variantId,
    int $delta,
    string $movementType = 'adjust',
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $note = null
): bool {
    if ($delta === 0) {
        return true;
    }

    // FOR UPDATE: the latest committed stock, held until the caller commits.
    // A plain read inside a transaction can return an older snapshot, and the
    // absolute write below then undid another order's movement.
    if ($variantId !== null) {
        $before = (int) Database::fetchColumn('SELECT `stock` FROM `product_variants` WHERE `id` = :id FOR UPDATE', ['id' => $variantId]);
        $after = max(0, $before + $delta);
        Database::update('product_variants', ['stock' => $after], '`id` = :id', ['id' => $variantId]);

        // Keep the parent product's stock as the sum of its variants.
        $variantTotal = (int) Database::fetchColumn(
            "SELECT COALESCE(SUM(`stock`), 0) FROM `product_variants` WHERE `product_id` = :pid AND `status` = 'active'",
            ['pid' => $productId]
        );
        Database::update('products', ['stock' => $variantTotal], '`id` = :id', ['id' => $productId]);
    } else {
        $before = (int) Database::fetchColumn('SELECT `stock` FROM `products` WHERE `id` = :id FOR UPDATE', ['id' => $productId]);
        $after = max(0, $before + $delta);
        Database::update('products', ['stock' => $after], '`id` = :id', ['id' => $productId]);
    }

    Database::insert('stock_movements', [
        'product_id'     => $productId,
        'variant_id'     => $variantId,
        'movement_type'  => $movementType,
        'quantity'       => $delta,
        'stock_before'   => $before,
        'stock_after'    => $after,
        'reference_type' => $referenceType,
        'reference_id'   => $referenceId,
        'admin_id'       => admin_id(),
        'note'           => $note,
    ]);

    // Restocking clears any pending back-in-stock alerts.
    if ($delta > 0 && $before <= 0 && $after > 0) {
        queue_back_in_stock_alerts($productId, $variantId);
    }

    // Warn the store once, on the movement that crosses the threshold. Testing
    // the crossing rather than the level stops every subsequent sale of an
    // already-low product raising another alert.
    if ($delta < 0) {
        notify_low_stock_crossed($productId, $before, $after);
    }

    return true;
}

/**
 * Email the store when a product falls to or below its low-stock threshold.
 * Never throws — stock movement must not fail because an alert could not queue.
 */
function notify_low_stock_crossed(int $productId, int $before, int $after): void
{
    if (!setting_bool('low_stock_alerts_enabled', true)) {
        return;
    }

    try {
        $product = Database::fetch(
            'SELECT `id`, `name`, `sku`, `slug`, `low_stock_threshold` FROM `products` WHERE `id` = :id',
            ['id' => $productId]
        );
        if ($product === null) {
            return;
        }

        // The per-product threshold wins; the store-wide setting is the default
        // for products that do not set one.
        $threshold = (int) ($product['low_stock_threshold'] ?? 0);
        if ($threshold <= 0) {
            $threshold = max(0, setting_int('low_stock_threshold', 5));
        }

        if ($threshold <= 0 || $before <= $threshold || $after > $threshold) {
            return;
        }

        notify_admins('low_stock_admin', [
            'product_name'   => (string) $product['name'],
            'product_sku'    => (string) ($product['sku'] ?? ''),
            'stock_quantity' => (string) $after,
            'threshold'      => (string) $threshold,
            'product_url'    => admin_url('products/edit.php?id=' . $productId),
        ], 'product', $productId, [
            'email_type' => 'admin_low_stock',
            // One alert per product per crossing, not one per unit sold.
            'idempotency_key' => 'low_stock:' . $productId . ':' . $after,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Low-stock alert failed for product ' . $productId . ': ' . $e->getMessage());
    }
}

/** Put every unit from an order back on the shelf. */
function restore_order_stock(int $orderId, string $reason = 'cancel'): void
{
    // Ordered by product, not by line: adjust_stock() takes a row lock on each
    // product, so two cancellations (or a cancel and a return) touching the
    // same two products in opposite line order took those locks in opposite
    // order and deadlocked on products.PRIMARY - and the loser's whole status
    // change was rolled back. Every transaction now takes them in one sequence.
    $items = Database::fetchAll(
        'SELECT * FROM `order_items` WHERE `order_id` = :id ORDER BY `product_id`, `variant_id`, `id`',
        ['id' => $orderId]
    );
    $order = Database::fetch('SELECT `order_number` FROM `orders` WHERE `id` = :id', ['id' => $orderId]);

    foreach ($items as $item) {
        if ($item['product_id'] === null) {
            continue; // product was deleted
        }
        adjust_stock(
            (int) $item['product_id'],
            $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
            (int) $item['quantity'],
            $reason,
            'order',
            $orderId,
            ucfirst($reason) . ' of order ' . ($order['order_number'] ?? $orderId)
        );

        Database::query(
            // sold_count is UNSIGNED: subtract at most what is there, or a
            // count already below the quantity overflows in strict mode.
            'UPDATE `products` SET `sold_count` = `sold_count` - LEAST(`sold_count`, :q) WHERE `id` = :id',
            ['q' => (int) $item['quantity'], 'id' => (int) $item['product_id']]
        );
    }
}

// ===========================================================================
//  ORDER READS
// ===========================================================================

function get_order(int $orderId): ?array
{
    return Database::fetch('SELECT * FROM `orders` WHERE `id` = :id LIMIT 1', ['id' => $orderId]);
}

function get_order_by_number(string $orderNumber): ?array
{
    return Database::fetch('SELECT * FROM `orders` WHERE `order_number` = :num LIMIT 1', ['num' => $orderNumber]);
}

function get_order_items(int $orderId): array
{
    $items = Database::fetchAll(
        'SELECT oi.*, p.`slug` AS product_slug
         FROM `order_items` oi
         LEFT JOIN `products` p ON p.`id` = oi.`product_id`
         WHERE oi.`order_id` = :id ORDER BY oi.`id`',
        ['id' => $orderId]
    );

    foreach ($items as &$item) {
        $item['image_url'] = img_url($item['product_image']);
        $item['url'] = $item['product_slug'] ? product_url($item['product_slug']) : null;
        $item['price_display'] = money((float) $item['price']);
        $item['subtotal_display'] = money((float) $item['subtotal']);
    }
    unset($item);

    return $items;
}

function get_order_history(int $orderId): array
{
    return Database::fetchAll(
        'SELECT * FROM `order_status_history` WHERE `order_id` = :id ORDER BY `id` ASC',
        ['id' => $orderId]
    );
}

/**
 * Timeline model for the tracking page: each canonical step with whether it
 * has been reached and when.
 */
function order_timeline(array $order): array
{
    $history = get_order_history((int) $order['id']);
    $reachedAt = [];
    foreach ($history as $entry) {
        if (!isset($reachedAt[$entry['status']])) {
            $reachedAt[$entry['status']] = $entry['created_at'];
        }
    }

    $status = (string) $order['status'];

    // Terminal negative states get their own short timeline.
    if (in_array($status, [ORDER_STATUS_CANCELLED, ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED], true)) {
        return [
            ['status' => ORDER_STATUS_PENDING, 'label' => ORDER_STATUSES[ORDER_STATUS_PENDING], 'done' => true, 'at' => $reachedAt[ORDER_STATUS_PENDING] ?? $order['created_at']],
            ['status' => $status, 'label' => ORDER_STATUSES[$status], 'done' => true, 'current' => true, 'at' => $reachedAt[$status] ?? $order['updated_at']],
        ];
    }

    $currentIndex = array_search($status, ORDER_TIMELINE, true);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;

    $timeline = [];
    foreach (ORDER_TIMELINE as $index => $step) {
        $timeline[] = [
            'status'  => $step,
            'label'   => ORDER_STATUSES[$step],
            'done'    => $index <= $currentIndex,
            'current' => $index === $currentIndex,
            'at'      => $reachedAt[$step] ?? null,
        ];
    }
    return $timeline;
}

// ===========================================================================
//  ORDER MUTATIONS
// ===========================================================================

/**
 * How far along the lifecycle a status is. Higher never yields to lower.
 *
 * The one table: update_order_status() refuses a move that does not raise it,
 * and the shipping hub reads it through shipping_order_rank(). The three
 * terminal states rank above delivered so a courier update, a return and a
 * cancellation cannot walk each other backwards.
 *
 * -1 for anything unknown, which therefore never counts as forward.
 */
function order_status_rank(string $status): int
{
    return [
        ORDER_STATUS_PENDING          => 0,
        ORDER_STATUS_CONFIRMED        => 10,
        ORDER_STATUS_PROCESSING       => 20,
        ORDER_STATUS_PACKED           => 30,
        ORDER_STATUS_SHIPPED          => 40,
        ORDER_STATUS_OUT_FOR_DELIVERY => 50,
        ORDER_STATUS_DELIVERED        => 60,
        ORDER_STATUS_RETURNED         => 70,
        ORDER_STATUS_REFUNDED         => 80,
        ORDER_STATUS_CANCELLED        => 90,
    ][$status] ?? -1;
}

/**
 * Run a transaction that may lose a deadlock, and try it again.
 *
 * InnoDB picks a victim when two transactions want the same rows in a
 * different order and rolls it back whole - so the work never half-happened
 * and re-running it is safe. Without this the loser surfaced as "Could not
 * update the order status." to an admin, or as a 500 to a courier's webhook
 * (which Shiprocket does not promise to retry).
 *
 * Only for a transaction of its own: inside an outer one the deadlock has
 * already killed that transaction, so re-running the inner block would work
 * without its locks. The wait is short and jittered so two victims do not
 * line up and collide again.
 *
 * A deadlock only - not a lock-wait timeout (1205). That one means someone
 * else is holding the row for a long time, so trying again straight away just
 * waits again; it is reported instead, which is how a courier webhook answers
 * 500 and gets its retry later, and how an admin is told to try again.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function db_retry_deadlock(callable $work, int $attempts = 3)
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return $work();
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $isDeadlock = (string) $e->getCode() === '40001' || $driverCode === 1213;
            if (!$isDeadlock || $attempt >= $attempts || Database::inTransaction()) {
                throw $e;
            }
            ErrorHandler::log('warning', 'Deadlock on attempt ' . $attempt . ', retrying: ' . $e->getMessage());
            usleep(random_int(20000, 120000) * $attempt);
        }
    }
}

/**
 * Move an order to a new status, journalling the change and handling the
 * stock/payment side effects that go with it.
 *
 * The order row is locked and re-read inside the transaction, and every
 * decision is made on that locked row. Two callers at once - two courier
 * updates for one parcel, two admins on one order - used to both read the old
 * status, both pass "first move into a releasing state", and restock the order
 * twice, give the coupon back twice and mark COD paid twice. Now the second one
 * waits, sees the first one's result, and changes nothing.
 *
 * That includes the CALLER's own reason for the move. Every caller decides
 * whether its move is legal on a read taken before the lock, and a courier
 * webhook can deliver the order in between: the forward-only rule below is
 * applied to the locked row for everyone, and a caller with a rule of its own
 * (the customer's cancellation window, the returns screen's "delivered only")
 * re-states it in `precondition`.
 *
 * @param bool $sendCustomerEmail Set false when the caller sends its own
 *        customer-facing message for this transition. Checkout uses it for the
 *        COD auto-confirmation, which happens in the same breath as the order
 *        confirmation email — without it the customer receives "order placed"
 *        and "order confirmed" seconds apart, both carrying the same invoice.
 *        The in-app notification and the invoice are unaffected.
 * @param array $options
 *        precondition    callable(array $lockedOrder): bool|string - the
 *                        caller's reason for the move, re-checked on the
 *                        locked row. true proceeds; false means someone got
 *                        there first and nothing changes (ok, changed=false);
 *                        a string refuses the move with that message.
 *        allow_backwards a deliberate correction to an earlier status, which
 *                        the forward-only rule otherwise refuses. The stock,
 *                        coupon and payment side effects still run only on the
 *                        FIRST move into a releasing state, so a correction
 *                        never restocks an order twice.
 *        shipped_at      when it left, as a courier reported it ('Y-m-d H:i:s');
 *        delivered_at    when it arrived. Both default to now.
 *        cod_uncollected RETURNED only: the parcel came back to us undelivered,
 *                        so a COD 'paid' (set by a delivery that was wrong) was
 *                        never collected and is reset to failed.
 *        cancel_reason   CANCELLED only: stored with the status, so a refused
 *                        cancellation leaves no reason on a live order.
 *        return_reason   RETURNED / REFUNDED only: the same, for the returns
 *                        screen - a refused return must leave no reason behind.
 * @return array{ok:bool, message:string, changed?:bool}
 */
function update_order_status(
    int $orderId,
    string $newStatus,
    ?string $note = null,
    string $changedBy = 'admin',
    bool $sendCustomerEmail = true,
    array $options = []
): array {
    if (!array_key_exists($newStatus, ORDER_STATUSES)) {
        return ['ok' => false, 'message' => 'Unknown order status.'];
    }

    $order = get_order($orderId);
    if ($order === null) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }

    // A fast answer only; the locked re-read below is the one that counts.
    if ((string) $order['status'] === $newStatus) {
        return ['ok' => true, 'changed' => false, 'message' => 'Order is already ' . ORDER_STATUSES[$newStatus] . '.'];
    }

    // An order with a live courier consignment is cancelled at the courier
    // FIRST. Otherwise the stock comes back below while the parcel keeps
    // moving, and the courier collects COD for an order the books say is dead.
    // Outside the transaction on purpose: it calls the courier, and no row
    // lock is held across a network call.
    if ($newStatus === ORDER_STATUS_CANCELLED && ($refusal = order_shipment_cancel_refusal($orderId)) !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    try {
        // Retried on a deadlock: the whole status change is one transaction,
        // so the victim's work is rolled back cleanly and running it again is
        // free. Before this, a cancel that lost a stock-lock race answered
        // "Could not update the order status." and the order stayed live.
        $outcome = db_retry_deadlock(static fn (): array => Database::transaction(
            static function () use ($orderId, $newStatus, $note, $changedBy, $sendCustomerEmail, $options): array {
            $order = Database::fetch('SELECT * FROM `orders` WHERE `id` = :id FOR UPDATE', ['id' => $orderId]);
            if ($order === null) {
                return ['refused' => 'Order not found.'];
            }
            $oldStatus = (string) $order['status'];
            if ($oldStatus === $newStatus) {
                return ['changed' => false, 'status' => $oldStatus];
            }

            // The caller's own rule, re-stated on the locked row. A string is
            // a refusal to report; false is "someone got there first".
            if (isset($options['precondition'])) {
                $verdict = ($options['precondition'])($order);
                if ($verdict !== true) {
                    return is_string($verdict)
                        ? ['refused' => $verdict]
                        : ['changed' => false, 'status' => $oldStatus];
                }
            }

            // Forward only, for every caller. Each of them tests this on its
            // own pre-lock read - update-status.php by timeline index,
            // returns.php by "delivered only" - and a courier webhook moving
            // the order while they waited for the lock turned that test into a
            // rewind: a delivered order back to shipped with delivered_at and
            // a collected COD still on it, or a refunded one to returned.
            if (empty($options['allow_backwards'])
                && order_status_rank($newStatus) <= order_status_rank($oldStatus)) {
                return ['refused' => 'Order is now ' . (ORDER_STATUSES[$oldStatus] ?? $oldStatus)
                    . ' and cannot be moved back to ' . (ORDER_STATUSES[$newStatus] ?? $newStatus) . '.'];
            }

            // Re-checked under the lock, which booking takes too: a courier
            // booking made between the check above and this lock would
            // otherwise leave a cancelled order with a live consignment.
            if ($newStatus === ORDER_STATUS_CANCELLED && order_has_live_shipment($orderId, true)) {
                return ['refused' => 'A courier booking for this order was made a moment ago. Cancel that shipment first, then the order.'];
            }

            $now    = date('Y-m-d H:i:s');
            $update = ['status' => $newStatus];
            $email  = $sendCustomerEmail;

            switch ($newStatus) {
                case ORDER_STATUS_CONFIRMED:
                    $update['confirmed_at'] = $now;
                    break;
                case ORDER_STATUS_SHIPPED:
                    $update['shipped_at'] = order_event_time($options['shipped_at'] ?? null);
                    break;
                case ORDER_STATUS_DELIVERED:
                    $update['delivered_at'] = order_event_time($options['delivered_at'] ?? null);
                    // COD is collected on delivery - at the delivery, not when
                    // we happened to hear about it.
                    if ($order['payment_method'] === PAYMENT_METHOD_COD && $order['payment_status'] !== PAYMENT_STATUS_PAID) {
                        $update['payment_status'] = PAYMENT_STATUS_PAID;
                        Database::update('payments', [
                            'status'  => PAYMENT_STATUS_PAID,
                            'paid_at' => $update['delivered_at'],
                        ], '`order_id` = :id', ['id' => $orderId]);
                    }
                    break;
                case ORDER_STATUS_CANCELLED:
                    $update['cancelled_at'] = $now;
                    if (isset($options['cancel_reason'])) {
                        $update['cancel_reason'] = mb_substr((string) $options['cancel_reason'], 0, 255);
                    }
                    break;
                case ORDER_STATUS_RETURNED:
                    if (!empty($options['cod_uncollected'])
                        && $order['payment_method'] === PAYMENT_METHOD_COD
                        && $order['payment_status'] === PAYMENT_STATUS_PAID) {
                        $update['payment_status'] = PAYMENT_STATUS_FAILED;
                        Database::update('payments', ['status' => PAYMENT_STATUS_FAILED, 'paid_at' => null],
                            '`order_id` = :id', ['id' => $orderId]);
                        $note = trim(($note ?? '') . ' COD not collected: the parcel came back (RTO). Payment reset from paid to failed.');
                    }
                    break;
            }

            // Written with the status, in the same transaction, the way
            // cancel_reason is: a return refused under the lock must leave no
            // "Return reason" on an order that is still out with the courier.
            if (isset($options['return_reason'])
                && in_array($newStatus, [ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED], true)) {
                $update['return_reason'] = mb_substr((string) $options['return_reason'], 0, 255);
            }

            // A jump past 'shipped' - one courier update carrying pickup and
            // delivery together, or a manual "delivered" - still records when
            // the parcel left, which the storefront and the emails show.
            if (empty($order['shipped_at']) && !isset($update['shipped_at'])
                && in_array($newStatus, [ORDER_STATUS_OUT_FOR_DELIVERY, ORDER_STATUS_DELIVERED, ORDER_STATUS_RETURNED], true)) {
                if (!empty($options['shipped_at'])) {
                    $update['shipped_at'] = order_event_time($options['shipped_at']);
                } elseif ($newStatus !== ORDER_STATUS_RETURNED) {
                    $update['shipped_at'] = $update['delivered_at'] ?? $now;
                }
            }

            // Release stock only on the first move into a releasing state -
            // judged on the locked row, so only one caller ever does it.
            if (in_array($newStatus, STOCK_RELEASING_STATUSES, true)
                && !in_array($oldStatus, STOCK_RELEASING_STATUSES, true)) {
                restore_order_stock($orderId, $newStatus === ORDER_STATUS_CANCELLED ? 'cancel' : 'return');

                if ($order['coupon_id'] !== null) {
                    // used_count is UNSIGNED: "GREATEST(0, used_count - 1)"
                    // overflows at 0 in strict mode before GREATEST can clamp
                    // it, and the whole return failed with it.
                    Database::query(
                        'UPDATE `coupons` SET `used_count` = `used_count` - LEAST(`used_count`, 1) WHERE `id` = :id',
                        ['id' => (int) $order['coupon_id']]
                    );
                    Database::delete('coupon_usage', '`order_id` = :oid', ['oid' => $orderId]);
                }
            }

            if ($newStatus === ORDER_STATUS_REFUNDED) {
                // Only money that was taken can be given back. An RTO'd COD
                // order was never paid: marking it refunded showed the invoice
                // paid in full and told the customer their money was on its way.
                if ($order['payment_status'] === PAYMENT_STATUS_PAID) {
                    $update['payment_status'] = PAYMENT_STATUS_REFUNDED;
                    Database::update('payments', ['status' => PAYMENT_STATUS_REFUNDED], '`order_id` = :id', ['id' => $orderId]);
                } else {
                    $email = false;
                }
            }

            Database::update('orders', $update, '`id` = :id', ['id' => $orderId]);

            Database::insert('order_status_history', [
                'order_id'   => $orderId,
                'status'     => $newStatus,
                'note'       => $note,
                'changed_by' => $changedBy,
                'admin_id'   => $changedBy === 'admin' ? admin_id() : null,
            ]);

            return ['changed' => true, 'order' => $order, 'old' => $oldStatus, 'email' => $email];
        }));
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Order status update failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the order status.'];
    }

    if (isset($outcome['refused'])) {
        return ['ok' => false, 'message' => (string) $outcome['refused']];
    }
    if (empty($outcome['changed'])) {
        $is = (string) ($outcome['status'] ?? $newStatus);
        return ['ok' => true, 'changed' => false, 'message' => 'Order is already ' . (ORDER_STATUSES[$is] ?? $is) . '.'];
    }
    $order             = $outcome['order'];
    $oldStatus         = (string) $outcome['old'];
    $sendCustomerEmail = (bool) $outcome['email'];

    try {
        $updated = get_order($orderId);

        // Confirmation is the point a sale becomes real, so it is also the
        // point the invoice is issued. issue_order_invoice() is idempotent, so
        // a status that flips back and forth never produces a second one.
        if ($updated !== null && $newStatus === ORDER_STATUS_CONFIRMED) {
            issue_order_invoice($updated);
            $updated = get_order($orderId);
        }

        // A cancellation or refund does not void the invoice — the document
        // stands — but its payment figures follow the order. Delivery too:
        // that is when COD is collected, and the invoice kept showing the
        // whole amount due on a paid order.
        if ($updated !== null && in_array($newStatus, [ORDER_STATUS_DELIVERED, ORDER_STATUS_CANCELLED, ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED], true)) {
            invoice_sync_payment($orderId);
        }

        // Outbound email, then the in-app feed the customer sees under the bell.
        if ($sendCustomerEmail) {
            notify_order_status_changed($updated, $newStatus);
        }
        notify_user_order_status($updated, $newStatus);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Order status notification failed: ' . $e->getMessage());
    }

    if ($changedBy === 'admin') {
        log_activity('order.status_changed', 'order', $orderId,
            'Order ' . $order['order_number'] . ': ' . $oldStatus . ' -> ' . $newStatus);
    }

    return ['ok' => true, 'changed' => true, 'message' => 'Order marked as ' . ORDER_STATUSES[$newStatus] . '.'];
}

/**
 * A reported event time ('Y-m-d H:i:s'), or now when there is none or it will
 * not parse. Never in the future: a courier clock running ahead would push a
 * delivery date - and the return window hanging off it - forward.
 */
function order_event_time($value): string
{
    $now = date('Y-m-d H:i:s');
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
        return $now;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
        return $now;
    }
    return $value > $now ? $now : $value;
}

/** Can the customer still cancel this order themselves? */
function can_cancel_order(array $order): bool
{
    if (!in_array($order['status'], CANCELLABLE_STATUSES, true)) {
        return false;
    }

    // Once a courier holds a booking for it, the customer's button would free
    // stock the warehouse has already packed and handed over. Support can
    // still cancel - update_order_status() then cancels the consignment too.
    if (order_has_live_shipment((int) $order['id'])) {
        return false;
    }

    $windowHours = setting_int('cancel_window_hours', 24);
    if ($windowHours <= 0) {
        return true;
    }

    $placedAt = strtotime((string) $order['created_at']);
    return $placedAt !== false && (time() - $placedAt) <= ($windowHours * 3600);
}

/** Customer-initiated cancellation. */
function cancel_order(int $orderId, string $reason, string $changedBy = 'customer'): array
{
    $order = get_order($orderId);
    if ($order === null) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }
    if ($changedBy === 'customer' && !can_cancel_order($order)) {
        return ['ok' => false, 'message' => 'This order can no longer be cancelled. Please contact support.'];
    }

    // Written with the status, in the same transaction. Written first, a
    // refusal (the parcel already with the courier) left a "Cancellation
    // reason" on an order that went on to be delivered.
    return update_order_status($orderId, ORDER_STATUS_CANCELLED, $reason, $changedBy, true, [
        'cancel_reason' => $reason,
        // Cancelled ranks above every other status, so the forward-only rule
        // lets it through from anywhere: the rules that decide whether THIS
        // cancellation is legal are re-stated here, on the locked row. A COD
        // delivery landing between the read above and the lock used to book
        // the goods back onto the shelf and leave the order cancelled with its
        // payment showing paid.
        'precondition' => static function (array $locked) use ($changedBy) {
            $status = (string) $locked['status'];
            if ($changedBy === 'customer') {
                return in_array($status, CANCELLABLE_STATUSES, true)
                    ? true
                    : 'This order can no longer be cancelled. Please contact support.';
            }
            // Support may cancel what the customer no longer can - but not a
            // parcel the customer already has, and not an order whose stock
            // has already come back.
            if ($status === ORDER_STATUS_DELIVERED) {
                return 'This order has already been delivered. Process it as a return instead.';
            }
            return in_array($status, STOCK_RELEASING_STATUSES, true)
                ? 'This order is already ' . (ORDER_STATUSES[$status] ?? $status) . '.'
                : true;
        },
    ]);
}

/** Orders belonging to a customer, paginated. */
function customer_orders(int $userId, int $page = 1, int $perPage = 10, string $status = ''): array
{
    $where = '`user_id` = :uid';
    $params = ['uid' => $userId];

    if ($status !== '' && array_key_exists($status, ORDER_STATUSES)) {
        $where .= ' AND `status` = :status';
        $params['status'] = $status;
    }

    $total = (int) Database::fetchColumn('SELECT COUNT(*) FROM `orders` WHERE ' . $where, $params);
    $pagination = paginate($total, $perPage, $page);

    $orders = Database::fetchAll(
        'SELECT * FROM `orders` WHERE ' . $where . ' ORDER BY `id` DESC LIMIT ' . $pagination['per_page']
        . ' OFFSET ' . $pagination['offset'],
        $params
    );

    foreach ($orders as &$order) {
        $order['items'] = get_order_items((int) $order['id']);
        $order['item_count'] = count($order['items']);
        $order['total_display'] = money((float) $order['total_amount']);
        $order['can_cancel'] = can_cancel_order($order);
    }
    unset($order);

    return ['items' => $orders, 'pagination' => $pagination];
}

// ===========================================================================
//  SHIPPING HOOKS
//
//  The order module does not depend on the shipping module: these load it
//  lazily and treat "not installed yet" (no shipments table on a site that
//  has not run the migration) as "no shipment".
// ===========================================================================

/**
 * @param bool $locking Read the latest committed rows with a locking read, for
 *        a caller inside a transaction that holds the order lock. A lock wait
 *        or any other error then propagates: "could not tell" must not read as
 *        "no shipment" when the answer decides whether stock comes back.
 */
function order_has_live_shipment(int $orderId, bool $locking = false): bool
{
    if ($orderId <= 0 || !is_file(INCLUDES_PATH . '/shipping-service.php')) {
        return false;
    }
    require_once INCLUDES_PATH . '/shipping-service.php';
    if ($locking) {
        return shipping_hub_installed() && Database::fetchColumn(
            "SELECT `id` FROM `shipments` WHERE `order_id` = :o AND `status` NOT IN ('cancelled','failed_booking')
              LIMIT 1 LOCK IN SHARE MODE",
            ['o' => $orderId]
        ) !== null;
    }
    try {
        return shipment_live_for_order($orderId) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/** Null when the order may be cancelled; otherwise why not. */
function order_shipment_cancel_refusal(int $orderId): ?string
{
    if (!order_has_live_shipment($orderId)) {
        return null;
    }
    require_once INCLUDES_PATH . '/shipping-service.php';
    return shipping_release_for_order_cancel($orderId);
}