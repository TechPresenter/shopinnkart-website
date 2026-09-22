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
            $lines = Database::fetchAll(
                'SELECT ci.`id` AS item_id, ci.`quantity`, ci.`product_id`, ci.`variant_id`
                 FROM `cart_items` ci
                 WHERE ci.`cart_id` = :cid
                 ORDER BY ci.`id`',
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

    if ($variantId !== null) {
        $before = (int) Database::fetchColumn('SELECT `stock` FROM `product_variants` WHERE `id` = :id', ['id' => $variantId]);
        $after = max(0, $before + $delta);
        Database::update('product_variants', ['stock' => $after], '`id` = :id', ['id' => $variantId]);

        // Keep the parent product's stock as the sum of its variants.
        $variantTotal = (int) Database::fetchColumn(
            "SELECT COALESCE(SUM(`stock`), 0) FROM `product_variants` WHERE `product_id` = :pid AND `status` = 'active'",
            ['pid' => $productId]
        );
        Database::update('products', ['stock' => $variantTotal], '`id` = :id', ['id' => $productId]);
    } else {
        $before = (int) Database::fetchColumn('SELECT `stock` FROM `products` WHERE `id` = :id', ['id' => $productId]);
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
    $items = Database::fetchAll('SELECT * FROM `order_items` WHERE `order_id` = :id', ['id' => $orderId]);
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
            'UPDATE `products` SET `sold_count` = GREATEST(0, `sold_count` - :q) WHERE `id` = :id',
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
 * Move an order to a new status, journalling the change and handling the
 * stock/payment side effects that go with it.
 */
/**
 * @param bool $sendCustomerEmail Set false when the caller sends its own
 *        customer-facing message for this transition. Checkout uses it for the
 *        COD auto-confirmation, which happens in the same breath as the order
 *        confirmation email — without it the customer receives "order placed"
 *        and "order confirmed" seconds apart, both carrying the same invoice.
 *        The in-app notification and the invoice are unaffected.
 */
function update_order_status(
    int $orderId,
    string $newStatus,
    ?string $note = null,
    string $changedBy = 'admin',
    bool $sendCustomerEmail = true
): array {
    if (!array_key_exists($newStatus, ORDER_STATUSES)) {
        return ['ok' => false, 'message' => 'Unknown order status.'];
    }

    $order = get_order($orderId);
    if ($order === null) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }

    $oldStatus = (string) $order['status'];
    if ($oldStatus === $newStatus) {
        return ['ok' => true, 'message' => 'Order is already ' . ORDER_STATUSES[$newStatus] . '.'];
    }

    // An order with a live courier consignment is cancelled at the courier
    // FIRST. Otherwise the stock comes back below while the parcel keeps
    // moving, and the courier collects COD for an order the books say is dead.
    if ($newStatus === ORDER_STATUS_CANCELLED && ($refusal = order_shipment_cancel_refusal($orderId)) !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    try {
        Database::transaction(static function () use ($order, $orderId, $oldStatus, $newStatus, $note, $changedBy) {
            $update = ['status' => $newStatus];

            switch ($newStatus) {
                case ORDER_STATUS_CONFIRMED:
                    $update['confirmed_at'] = date('Y-m-d H:i:s');
                    break;
                case ORDER_STATUS_SHIPPED:
                    $update['shipped_at'] = date('Y-m-d H:i:s');
                    break;
                case ORDER_STATUS_DELIVERED:
                    $update['delivered_at'] = date('Y-m-d H:i:s');
                    // COD is collected on delivery.
                    if ($order['payment_method'] === PAYMENT_METHOD_COD && $order['payment_status'] !== PAYMENT_STATUS_PAID) {
                        $update['payment_status'] = PAYMENT_STATUS_PAID;
                        Database::update('payments', [
                            'status'  => PAYMENT_STATUS_PAID,
                            'paid_at' => date('Y-m-d H:i:s'),
                        ], '`order_id` = :id', ['id' => $orderId]);
                    }
                    break;
                case ORDER_STATUS_CANCELLED:
                    $update['cancelled_at'] = date('Y-m-d H:i:s');
                    break;
            }

            // Release stock only on the first move into a releasing state.
            if (in_array($newStatus, STOCK_RELEASING_STATUSES, true)
                && !in_array($oldStatus, STOCK_RELEASING_STATUSES, true)) {
                restore_order_stock($orderId, $newStatus === ORDER_STATUS_CANCELLED ? 'cancel' : 'return');

                if ($order['coupon_id'] !== null) {
                    Database::query(
                        'UPDATE `coupons` SET `used_count` = GREATEST(0, `used_count` - 1) WHERE `id` = :id',
                        ['id' => (int) $order['coupon_id']]
                    );
                    Database::delete('coupon_usage', '`order_id` = :oid', ['oid' => $orderId]);
                }
            }

            if ($newStatus === ORDER_STATUS_REFUNDED) {
                $update['payment_status'] = PAYMENT_STATUS_REFUNDED;
                Database::update('payments', ['status' => PAYMENT_STATUS_REFUNDED], '`order_id` = :id', ['id' => $orderId]);
            }

            Database::update('orders', $update, '`id` = :id', ['id' => $orderId]);

            Database::insert('order_status_history', [
                'order_id'   => $orderId,
                'status'     => $newStatus,
                'note'       => $note,
                'changed_by' => $changedBy,
                'admin_id'   => $changedBy === 'admin' ? admin_id() : null,
            ]);
        });
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Order status update failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not update the order status.'];
    }

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
        // stands — but its payment figures follow the order.
        if ($updated !== null && in_array($newStatus, [ORDER_STATUS_CANCELLED, ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED], true)) {
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

    return ['ok' => true, 'message' => 'Order marked as ' . ORDER_STATUSES[$newStatus] . '.'];
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

    Database::update('orders', ['cancel_reason' => mb_substr($reason, 0, 255)], '`id` = :id', ['id' => $orderId]);

    return update_order_status($orderId, ORDER_STATUS_CANCELLED, $reason, $changedBy);
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

function order_has_live_shipment(int $orderId): bool
{
    if ($orderId <= 0 || !is_file(INCLUDES_PATH . '/shipping-service.php')) {
        return false;
    }
    try {
        require_once INCLUDES_PATH . '/shipping-service.php';
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