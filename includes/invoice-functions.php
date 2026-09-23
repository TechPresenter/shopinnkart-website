<?php
/**
 * ShopInnKart - Invoice records.
 *
 * An invoice is a frozen financial document, not a view of the order. Once
 * issued it keeps its own copy of the company block, the addresses, the priced
 * lines and the tax split, so a later change to a product name, a tax rate or
 * the store address cannot rewrite history.
 *
 * Every figure here is recomputed from `orders` / `order_items` server-side.
 * Nothing on this path reads request input.
 *
 * Duplicate protection is layered:
 *   1. a MySQL named lock serialises concurrent callers for one order,
 *   2. a fast-path SELECT returns the existing row,
 *   3. UNIQUE(order_id) in the schema is the last word if both of those lose.
 */

declare(strict_types=1);

// ===========================================================================
//  NUMBERING
// ===========================================================================

/**
 * The series an invoice dated $date belongs to, e.g. "INV-2026".
 * Indian stores usually reset numbering each financial year (Apr-Mar), so that
 * is selectable; the default is the calendar year.
 */
function invoice_series(?string $date = null): string
{
    $timestamp = $date === null ? time() : (strtotime($date) ?: time());
    $prefix = trim((string) setting('invoice_prefix', INVOICE_PREFIX)) ?: INVOICE_PREFIX;

    if ((string) setting('invoice_year_mode', 'calendar') === 'financial') {
        $year  = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $start = $month >= 4 ? $year : $year - 1;

        return sprintf('%s-%d-%02d', $prefix, $start, ($start + 1) % 100);
    }

    return $prefix . '-' . date('Y', $timestamp);
}

/**
 * Reserve the next number in a series.
 *
 * LAST_INSERT_ID(expr) makes the read-modify-write a single atomic statement,
 * so two concurrent checkouts cannot be handed the same number the way
 * generate_order_number()'s COUNT(*) can.
 */
function invoice_next_sequence(string $series): int
{
    $start = max(1, setting_int('invoice_start_number', 1));

    // Seed the row at (start - 1) so the first increment yields exactly $start.
    Database::query(
        'INSERT IGNORE INTO `invoice_counters` (`series`, `last_number`) VALUES (:s, :n)',
        ['s' => $series, 'n' => $start - 1]
    );

    Database::query(
        'UPDATE `invoice_counters` SET `last_number` = LAST_INSERT_ID(`last_number` + 1) WHERE `series` = :s',
        ['s' => $series]
    );

    $next = (int) Database::fetchColumn('SELECT LAST_INSERT_ID()');

    // A series that already ran past the configured start keeps counting; this
    // only matters the first time the start number is raised in settings.
    return $next < $start ? $start : $next;
}

/** "INV-2026-000001" */
function invoice_format_number(string $series, int $sequence): string
{
    $pad = max(3, min(10, setting_int('invoice_number_padding', 6)));

    return $series . '-' . str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
}

// ===========================================================================
//  COMPANY / STORE BLOCK
// ===========================================================================

/** The seller details printed at the top of every invoice. */
function invoice_company_details(): array
{
    $address = (string) setting('invoice_company_address', (string) setting('store_address', ''));

    return [
        'name'       => (string) setting('invoice_company_name', (string) setting('store_name', SITE_NAME)),
        'address'    => $address,
        'city'       => (string) setting('store_city', ''),
        'state'      => (string) setting('invoice_seller_state', (string) setting('store_state', '')),
        'pincode'    => (string) setting('store_pincode', ''),
        'country'    => (string) setting('store_country', 'India'),
        'email'      => (string) setting('store_email', ''),
        'phone'      => (string) setting('store_phone', ''),
        'website'    => SITE_URL,
        'gstin'      => trim((string) setting('gst_number', '')),
        'pan'        => trim((string) setting('invoice_pan', '')),
        'logo'       => brand_logo_rel(),
        'tax_label'  => (string) setting('tax_label', 'GST'),
        'terms'      => (string) setting('invoice_terms', invoice_default_terms()),
        'footer'     => (string) setting('invoice_footer', 'This is a computer generated invoice and does not require a signature.'),
        'signatory'  => (string) setting('invoice_signatory', ''),
    ];
}

function invoice_default_terms(): string
{
    return "1. Goods once sold will be taken back or exchanged only as per the store return policy.\n"
        . "2. All disputes are subject to the jurisdiction of the seller's registered location.\n"
        . '3. Please retain this invoice for warranty and after-sales service claims.';
}

// ===========================================================================
//  DATA ASSEMBLY  (server-side only — never trusts request input)
// ===========================================================================

/**
 * Build the complete, priced invoice payload for an order.
 *
 * @return array The structure stored in invoices.snapshot and handed to the
 *               PDF renderer and the email templates.
 */
function invoice_build_data(array $order): array
{
    $orderId = (int) $order['id'];

    $items = Database::fetchAll(
        'SELECT oi.*, p.`hsn_code`
         FROM `order_items` oi
         LEFT JOIN `products` p ON p.`id` = oi.`product_id`
         WHERE oi.`order_id` = :id
         ORDER BY oi.`id`',
        ['id' => $orderId]
    );

    $company  = invoice_company_details();
    $taxLabel = $company['tax_label'];

    // ---- Lines -----------------------------------------------------------
    // A line whose stored total equals its subtotal was priced tax-inclusive,
    // so the tax has to be carved out of it rather than added on top. This is
    // the same rule invoice.php has always applied on screen.
    $lines = [];
    $lineTaxableTotal = 0.0;
    $lineTaxTotal = 0.0;
    $taxGroups = [];
    $inclusiveLines = 0;

    foreach ($items as $index => $item) {
        $subtotal  = (float) $item['subtotal'];
        $total     = (float) $item['total'];
        $taxAmount = (float) $item['tax_amount'];
        $taxRate   = (float) $item['tax_rate'];
        $inclusive = abs($total - $subtotal) < 0.01;

        $taxable = money_round($inclusive ? $subtotal - $taxAmount : $subtotal);
        $unit    = (float) $item['price'];
        $mrp     = (float) $item['mrp'];

        $lines[] = [
            'position'     => $index + 1,
            'name'         => (string) $item['product_name'],
            'variant'      => (string) ($item['variant_name'] ?? ''),
            'sku'          => (string) $item['product_sku'],
            'hsn'          => (string) ($item['hsn_code'] ?? ''),
            'quantity'     => (int) $item['quantity'],
            'mrp'          => $mrp,
            'unit_price'   => $unit,
            'line_discount' => money_round(max(0.0, ($mrp - $unit) * (int) $item['quantity'])),
            'taxable'      => $taxable,
            'tax_rate'     => $taxRate,
            'tax_amount'   => money_round($taxAmount),
            'total'        => money_round($total),
            'tax_inclusive' => $inclusive,
        ];

        $lineTaxableTotal += $taxable;
        $lineTaxTotal     += $taxAmount;
        $inclusiveLines   += $inclusive ? 1 : 0;

        if ($taxAmount > 0 || $taxRate > 0) {
            $key = number_format($taxRate, 2, '.', '');
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = ['rate' => $taxRate, 'taxable' => 0.0, 'tax' => 0.0];
            }
            $taxGroups[$key]['taxable'] += $taxable;
            $taxGroups[$key]['tax']     += $taxAmount;
        }
    }

    ksort($taxGroups, SORT_NUMERIC);

    // ---- GST split -------------------------------------------------------
    // Same state as the seller means CGST + SGST at half the rate each;
    // anywhere else is a single IGST line.
    $placeOfSupply = (string) ($order['shipping_state'] ?: $order['billing_state'] ?: '');
    $sellerState   = $company['state'];
    $intraState    = $sellerState !== ''
        && strcasecmp(trim($sellerState), trim($placeOfSupply)) === 0;

    $taxRows = [];
    foreach ($taxGroups as $group) {
        $taxable = money_round($group['taxable']);
        $tax     = money_round($group['tax']);

        if ($intraState) {
            $half = money_round($tax / 2);
            $taxRows[] = ['label' => 'CGST @ ' . invoice_rate_label($group['rate'] / 2), 'taxable' => $taxable, 'amount' => $half];
            // The second half absorbs any rounding remainder so the two
            // components always add back to the stored tax exactly.
            $taxRows[] = ['label' => 'SGST @ ' . invoice_rate_label($group['rate'] / 2), 'taxable' => $taxable, 'amount' => money_round($tax - $half)];
        } else {
            $taxRows[] = ['label' => 'IGST @ ' . invoice_rate_label($group['rate']), 'taxable' => $taxable, 'amount' => $tax];
        }
    }

    // ---- Order-level money ----------------------------------------------
    $subtotal        = (float) $order['subtotal'];
    $discount        = (float) $order['discount_amount'];
    $shipping        = (float) $order['shipping_amount'];
    $tax             = (float) $order['tax_amount'];
    $paymentCharge   = (float) $order['payment_charge'];
    $paymentDiscount = (float) $order['payment_discount'];
    $grandTotal      = (float) $order['total_amount'];

    // Tax-inclusive pricing means `subtotal` already contains `tax_amount`;
    // adding it again would overstate the total by the whole tax figure. The
    // lines are the trusted signal, with the store setting as the fallback for
    // an order that somehow has none.
    $taxInclusive = $lines === []
        ? setting_bool('tax_inclusive', true)
        : $inclusiveLines === count($lines);

    // Independent recomputation. A mismatch means the order row and its parts
    // disagree — the invoice still prints the stored total (that is what the
    // customer was charged) but the discrepancy is recorded and logged.
    $computed = money_round(
        $subtotal - $discount + $shipping + $paymentCharge - $paymentDiscount
        + ($taxInclusive ? 0.0 : $tax)
    );
    $variance = money_round($computed - $grandTotal);
    if (abs($variance) >= 0.01) {
        ErrorHandler::log('warning', sprintf(
            'Invoice totals mismatch on order %s: stored %.2f, computed %.2f (variance %.2f).',
            (string) $order['order_number'],
            $grandTotal,
            $computed,
            $variance
        ));
    }

    $paymentStatus = (string) $order['payment_status'];
    // A refunded order WAS paid: the money came in and then went back out, and
    // the going back out is the credit note's job to say, not this document's.
    $amountPaid = in_array($paymentStatus, PAYMENT_STATUSES_SETTLED, true)
        ? $grandTotal
        : 0.0;
    $balanceDue = money_round(max(0.0, $grandTotal - $amountPaid));

    $paymentName = (string) (Database::fetchColumn(
        'SELECT `name` FROM `payment_methods` WHERE `code` = :code LIMIT 1',
        ['code' => (string) $order['payment_method']]
    ) ?? ucfirst(str_replace('_', ' ', (string) $order['payment_method'])));

    return [
        'company' => $company,
        'order' => [
            'id'              => $orderId,
            'number'          => (string) $order['order_number'],
            'date'            => (string) $order['created_at'],
            'status'          => (string) $order['status'],
            'status_label'    => ORDER_STATUSES[$order['status']] ?? (string) $order['status'],
            'payment_method'  => (string) $order['payment_method'],
            'payment_name'    => $paymentName,
            'payment_status'  => $paymentStatus,
            'payment_status_label' => PAYMENT_STATUSES[$paymentStatus] ?? ucfirst($paymentStatus),
            'shipping_method' => (string) $order['shipping_method'],
            'tracking_number' => (string) ($order['tracking_number'] ?? ''),
            'courier_name'    => (string) ($order['courier_name'] ?? ''),
            'customer_note'   => (string) ($order['customer_note'] ?? ''),
        ],
        'customer' => [
            'name'  => (string) $order['customer_name'],
            'email' => (string) $order['customer_email'],
            'phone' => (string) $order['customer_phone'],
        ],
        'billing'  => invoice_address_block($order, 'billing'),
        'shipping' => invoice_address_block($order, 'shipping'),
        'lines'    => $lines,
        'tax' => [
            'label'           => $taxLabel,
            'rows'            => $taxRows,
            'groups'          => array_values($taxGroups),
            'intra_state'     => $intraState,
            'inclusive'       => $taxInclusive,
            'place_of_supply' => $placeOfSupply,
            'seller_state'    => $sellerState,
            'line_taxable'    => money_round($lineTaxableTotal),
            'line_tax'        => money_round($lineTaxTotal),
        ],
        'totals' => [
            'currency'         => (string) setting('currency_code', CURRENCY),
            'subtotal'         => money_round($subtotal),
            'discount'         => money_round($discount),
            'coupon_code'      => (string) ($order['coupon_code'] ?? ''),
            'shipping'         => money_round($shipping),
            'tax'              => money_round($tax),
            'payment_charge'   => money_round($paymentCharge),
            'payment_discount' => money_round($paymentDiscount),
            'grand_total'      => money_round($grandTotal),
            'amount_paid'      => money_round($amountPaid),
            'balance_due'      => $balanceDue,
            'computed_total'   => $computed,
            'variance'         => $variance,
            'in_words'         => amount_in_words($grandTotal),
        ],
    ];
}

/** "9" / "2.5" — trailing zeros trimmed so rates read naturally. */
function invoice_rate_label(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
}

/** Billing falls back to shipping, which is what create_order() stores. */
function invoice_address_block(array $order, string $prefix): array
{
    $pick = static function (string $field) use ($order, $prefix) {
        $value = (string) ($order[$prefix . '_' . $field] ?? '');
        if ($value === '' && $prefix === 'billing') {
            $value = (string) ($order['shipping_' . $field] ?? '');
        }
        return $value;
    };

    return [
        'name'     => $pick('name'),
        'phone'    => $pick('phone'),
        'address'  => $pick('address'),
        // Only the shipping address has a second line and a landmark.
        'address2' => $prefix === 'shipping' ? (string) ($order['shipping_address2'] ?? '') : '',
        'landmark' => $prefix === 'shipping' ? (string) ($order['shipping_landmark'] ?? '') : '',
        'city'     => $pick('city'),
        'state'    => $pick('state'),
        'pincode'  => $pick('pincode'),
        'country'  => $pick('country'),
    ];
}

/** Rupees in words, as Indian invoices are expected to carry. */
function amount_in_words(float $amount): string
{
    $units = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty',
        50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
    ];

    $twoDigits = static function (int $n) use ($units): string {
        if ($n <= 20) {
            return $units[$n];
        }
        $tens = intdiv($n, 10) * 10;
        $rest = $n % 10;
        return trim($units[$tens] . ($rest > 0 ? ' ' . $units[$rest] : ''));
    };

    $amount = round($amount, 2);
    $rupees = (int) floor($amount);
    $paise  = (int) round(($amount - $rupees) * 100);

    if ($rupees === 0 && $paise === 0) {
        return 'Zero Rupees Only';
    }

    $words = [];
    foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $divisor => $name) {
        if ($rupees >= $divisor) {
            $count = intdiv($rupees, $divisor);
            $rupees %= $divisor;
            // Crore can exceed 99, so it recurses; the rest never do.
            $words[] = ($divisor === 10000000 && $count > 99 ? amount_in_words_int($count) : $twoDigits($count)) . ' ' . $name;
        }
    }
    if ($rupees > 0) {
        $words[] = ($words === [] ? '' : 'and ') . $twoDigits($rupees);
    }

    $result = trim(implode(' ', $words)) . ' Rupees';
    if ($paise > 0) {
        $result .= ' and ' . $twoDigits($paise) . ' Paise';
    }

    return $result . ' Only';
}

/** Integer-only helper used when the crore count itself needs spelling out. */
function amount_in_words_int(int $n): string
{
    return trim(str_replace([' Rupees Only', ' Rupees'], '', amount_in_words((float) $n)));
}

// ===========================================================================
//  ISSUING
// ===========================================================================

/** Which orders deserve an invoice. A failed payment never gets one. */
function invoice_should_issue(array $order): bool
{
    if ((string) $order['payment_status'] === PAYMENT_STATUS_FAILED) {
        return false;
    }

    // A prepaid order that has not been paid for yet is not a sale.
    $isCod = (string) $order['payment_method'] === PAYMENT_METHOD_COD;
    if (!$isCod && (string) $order['payment_status'] !== PAYMENT_STATUS_PAID) {
        return false;
    }

    return true;
}

function get_invoice_for_order(int $orderId): ?array
{
    return Database::fetch('SELECT * FROM `invoices` WHERE `order_id` = :id LIMIT 1', ['id' => $orderId]);
}

function get_invoice_by_number(string $invoiceNumber): ?array
{
    return Database::fetch('SELECT * FROM `invoices` WHERE `invoice_number` = :n LIMIT 1', ['n' => $invoiceNumber]);
}

function get_invoice(int $invoiceId): ?array
{
    return Database::fetch('SELECT * FROM `invoices` WHERE `id` = :id LIMIT 1', ['id' => $invoiceId]);
}

/** The decoded snapshot, rebuilt from the live order only if it is missing. */
function invoice_snapshot(array $invoice): array
{
    $decoded = json_decode((string) ($invoice['snapshot'] ?? ''), true);
    if (is_array($decoded) && isset($decoded['company'], $decoded['totals'])) {
        return $decoded;
    }

    $order = get_order((int) $invoice['order_id']);

    return $order === null ? [] : invoice_build_data($order);
}

/**
 * Issue (or fetch) the invoice for an order.
 *
 * Safe to call repeatedly — a payment gateway that fires its webhook five
 * times still ends up with exactly one invoice and one number.
 *
 * @return array{ok:bool, invoice:?array, created:bool, error:?string}
 */
function generate_invoice_for_order(int $orderId, bool $force = false): array
{
    $miss = static fn (string $error): array => ['ok' => false, 'invoice' => null, 'created' => false, 'error' => $error];

    $order = get_order($orderId);
    if ($order === null) {
        return $miss('Order not found.');
    }

    // Fast path: already issued.
    $existing = get_invoice_for_order($orderId);
    if ($existing !== null) {
        return ['ok' => true, 'invoice' => $existing, 'created' => false, 'error' => null];
    }

    if (!$force && !invoice_should_issue($order)) {
        return $miss('Order is not in an invoiceable state (' . $order['payment_status'] . ' / ' . $order['payment_method'] . ').');
    }

    // Serialise concurrent callers for this one order. Two webhooks arriving
    // together would otherwise both pass the check above, both burn a sequence
    // number, and one would then die on UNIQUE(order_id).
    $lockName = 'sik_invoice_' . $orderId;
    $locked = (int) Database::fetchColumn('SELECT GET_LOCK(:name, 5)', ['name' => $lockName]) === 1;

    try {
        if ($locked) {
            // Re-check now that we hold the lock: the other caller may have
            // finished while we waited.
            $existing = get_invoice_for_order($orderId);
            if ($existing !== null) {
                return ['ok' => true, 'invoice' => $existing, 'created' => false, 'error' => null];
            }
        }

        $data = invoice_build_data($order);
        $issuedAt = date('Y-m-d H:i:s');
        $series = invoice_series($issuedAt);
        $sequence = invoice_next_sequence($series);
        $number = invoice_format_number($series, $sequence);

        $invoiceId = Database::insert('invoices', [
            'invoice_number'  => $number,
            'series'          => $series,
            'sequence_no'     => $sequence,
            'order_id'        => $orderId,
            'order_number'    => (string) $order['order_number'],
            'user_id'         => $order['user_id'] === null ? null : (int) $order['user_id'],
            'customer_name'   => (string) $order['customer_name'],
            'customer_email'  => (string) $order['customer_email'],
            'customer_phone'  => (string) $order['customer_phone'],
            'invoice_date'    => $issuedAt,
            'currency'        => $data['totals']['currency'],
            'subtotal'        => $data['totals']['subtotal'],
            'discount_amount' => $data['totals']['discount'],
            'coupon_code'     => $data['totals']['coupon_code'] !== '' ? $data['totals']['coupon_code'] : null,
            'shipping_amount' => $data['totals']['shipping'],
            'tax_amount'      => $data['totals']['tax'],
            'payment_charge'  => $data['totals']['payment_charge'],
            'payment_discount' => $data['totals']['payment_discount'],
            'total_amount'    => $data['totals']['grand_total'],
            'amount_paid'     => $data['totals']['amount_paid'],
            'balance_due'     => $data['totals']['balance_due'],
            'payment_status'  => $data['order']['payment_status'],
            'payment_method'  => $data['order']['payment_method'],
            'place_of_supply' => $data['tax']['place_of_supply'] !== '' ? $data['tax']['place_of_supply'] : null,
            'seller_gstin'    => $data['company']['gstin'] !== '' ? $data['company']['gstin'] : null,
            'snapshot'        => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'          => 'issued',
        ]);

        $invoice = get_invoice($invoiceId);
        if ($invoice === null) {
            return $miss('Invoice row could not be read back after insert.');
        }

        log_activity('invoice.issued', 'invoice', $invoiceId, 'Invoice ' . $number . ' issued for order ' . $order['order_number']);

        return ['ok' => true, 'invoice' => $invoice, 'created' => true, 'error' => null];
    } catch (PDOException $e) {
        // 23000 = integrity constraint. Losing the UNIQUE(order_id) race means
        // somebody else issued it; their row is the correct answer.
        if ($e->getCode() === '23000') {
            $existing = get_invoice_for_order($orderId);
            if ($existing !== null) {
                ErrorHandler::log('info', 'Duplicate invoice insert collapsed for order ' . $orderId . ' — returning existing ' . $existing['invoice_number'] . '.');
                return ['ok' => true, 'invoice' => $existing, 'created' => false, 'error' => null];
            }
        }
        ErrorHandler::log('error', 'Invoice generation failed for order ' . $orderId . ': ' . $e->getMessage());
        return $miss('Invoice generation failed: ' . $e->getMessage());
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Invoice generation failed for order ' . $orderId . ': ' . $e->getMessage());
        return $miss('Invoice generation failed: ' . $e->getMessage());
    } finally {
        if ($locked) {
            Database::query('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
        }
    }
}

/**
 * Issue the invoice for an order and render its PDF.
 *
 * The one call the order lifecycle makes. Never throws and never reports a
 * problem upwards: by the time this runs the order is committed and the money
 * is taken, so nothing here is allowed to look like a checkout failure.
 *
 * @return array|null The invoice row, or null when the order is not invoiceable.
 */
function issue_order_invoice(?array $order, bool $renderPdf = true): ?array
{
    if ($order === null || empty($order['id'])) {
        return null;
    }

    $orderId = (int) $order['id'];

    try {
        $result = generate_invoice_for_order($orderId);
        if (!$result['ok'] || $result['invoice'] === null) {
            return null;
        }
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Invoice issue failed for order ' . $orderId . ': ' . $e->getMessage());
        return null;
    }

    $invoice = $result['invoice'];

    if ($renderPdf) {
        try {
            // A PDF failure is recorded on the row and retried on first
            // download; the invoice record itself is already valid without it.
            invoice_render_pdf($invoice);
            $invoice = get_invoice((int) $invoice['id']) ?? $invoice;
        } catch (Throwable $e) {
            ErrorHandler::log('warning', 'Invoice PDF render failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    return $invoice;
}

/**
 * Refresh the stored payment figures on an already-issued invoice.
 * Used when a COD order is finally marked paid, or a refund lands. The invoice
 * number, date and line items never change.
 */
function invoice_sync_payment(int $orderId): void
{
    $invoice = get_invoice_for_order($orderId);
    $order = get_order($orderId);
    if ($invoice === null || $order === null) {
        return;
    }

    // A cancelled invoice is a document for nothing: it owes nothing and
    // nothing was paid against it, whatever the order says.
    if ((string) $invoice['status'] === 'cancelled') {
        return;
    }

    $status = (string) $order['payment_status'];
    $total  = (float) $invoice['total_amount'];
    $paid   = in_array($status, PAYMENT_STATUSES_SETTLED, true) ? $total : 0.0;

    if ($status === (string) $invoice['payment_status'] && abs($paid - (float) $invoice['amount_paid']) < 0.01) {
        return;
    }

    Database::update('invoices', [
        'payment_status' => $status,
        'amount_paid'    => money_round($paid),
        'balance_due'    => money_round(max(0.0, $total - $paid)),
    ], '`id` = :id', ['id' => (int) $invoice['id']]);
}

// ===========================================================================
//  CANCELLING AND CREDITING
//
//  A tax invoice is a declaration of supply, and an "issued" one for goods
//  that never left the building over-reports output tax - the customer could
//  also download an invoice for an order that was cancelled the same hour.
//  The honest treatment depends on whether anything was actually supplied, so
//  there are two, and only one of them can apply to an order:
//
//    nothing supplied  the invoice is CANCELLED. There was no supply, so
//                      there is nothing to declare and nothing to credit.
//    supplied, then came back or was refunded
//                      the invoice STANDS - withdrawing a document the buyer
//                      may already have claimed input credit against is not
//                      ours to do - and a numbered CREDIT NOTE in its own
//                      series reverses it.
// ===========================================================================

/** Did this order ever leave us? */
function order_was_supplied(array $order): bool
{
    return $order['shipped_at'] !== null
        || $order['delivered_at'] !== null
        || in_array((string) $order['status'], [
            ORDER_STATUS_SHIPPED, ORDER_STATUS_OUT_FOR_DELIVERY,
            ORDER_STATUS_DELIVERED, ORDER_STATUS_RETURNED,
        ], true);
}

/** The credit note series for a date, e.g. "CRN-2026". Mirrors invoice_series(). */
function credit_note_series(?string $date = null): string
{
    $timestamp = $date === null ? time() : (strtotime($date) ?: time());
    $prefix = trim((string) setting('credit_note_prefix', 'CRN')) ?: 'CRN';

    if ((string) setting('invoice_year_mode', 'calendar') === 'financial') {
        $year  = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $start = $month >= 4 ? $year : $year - 1;

        return sprintf('%s-%d-%02d', $prefix, $start, ($start + 1) % 100);
    }

    return $prefix . '-' . date('Y', $timestamp);
}

/** Credit notes raised against an order, oldest first. */
function get_credit_notes_for_order(int $orderId): array
{
    try {
        return Database::fetchAll(
            'SELECT * FROM `credit_notes` WHERE `order_id` = :o ORDER BY `id`',
            ['o' => $orderId]
        );
    } catch (Throwable $e) {
        // The table arrives with 2026_09_23_security_commerce_payments.php; a
        // site mid-migration still renders its orders.
        return [];
    }
}

/** How much of an order has already been credited back. */
function credit_notes_total(int $orderId): float
{
    try {
        return money_round((float) Database::fetchColumn(
            'SELECT COALESCE(SUM(`total_amount`), 0) FROM `credit_notes` WHERE `order_id` = :o',
            ['o' => $orderId]
        ));
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Raise a numbered credit note against an order's invoice.
 *
 * @param float|null $amount What to credit. null means "whatever is left".
 *        Never more than the invoice total less what has been credited
 *        already, so two status moves for the same money raise one note's
 *        worth of credit between them.
 * @param string $cause Why, as a key: 'order:returned', 'refund:<event id>'.
 *        UNIQUE(order_id, cause) is what makes a redelivery a no-op.
 *
 * @return array|null the credit note row, or null when there is nothing to credit
 */
function credit_note_issue(int $orderId, ?float $amount, string $cause, string $reason = ''): ?array
{
    try {
        $invoice = get_invoice_for_order($orderId);
        $order   = get_order($orderId);

        if ($invoice === null || $order === null || (string) $invoice['status'] !== 'issued') {
            return null;   // nothing was ever declared, so nothing to reverse
        }

        $existing = Database::fetch(
            'SELECT * FROM `credit_notes` WHERE `order_id` = :o AND `cause` = :c LIMIT 1',
            ['o' => $orderId, 'c' => $cause]
        );
        if ($existing !== null) {
            return $existing;
        }

        $remaining = money_round((float) $invoice['total_amount'] - credit_notes_total($orderId));
        $value = money_round(min($amount === null ? $remaining : max(0.0, $amount), $remaining));
        if ($value < 0.01) {
            return null;
        }

        $snapshot = invoice_snapshot($invoice);
        $full = abs($value - (float) $invoice['total_amount']) < 0.01;

        // Negative lines, which is what a credit note is. A partial credit
        // cannot honestly be split across lines, so it carries one line saying
        // exactly what it is against.
        $lines = [];
        if ($full) {
            foreach ($snapshot['lines'] ?? [] as $index => $line) {
                $lines[] = [
                    'position'   => $index + 1,
                    'name'       => (string) $line['name'],
                    'sku'        => (string) ($line['sku'] ?? ''),
                    'hsn'        => (string) ($line['hsn'] ?? ''),
                    'quantity'   => -(int) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'taxable'    => -(float) $line['taxable'],
                    'tax_rate'   => (float) $line['tax_rate'],
                    'tax_amount' => -(float) $line['tax_amount'],
                    'total'      => -(float) $line['total'],
                ];
            }
        } else {
            $lines[] = [
                'position'   => 1,
                'name'       => 'Credit against invoice ' . $invoice['invoice_number'],
                'sku'        => '', 'hsn' => '', 'quantity' => -1,
                'unit_price' => $value, 'taxable' => -$value, 'tax_rate' => 0.0,
                'tax_amount' => 0.0, 'total' => -$value,
            ];
        }

        // The tax being reversed, in the same proportion as the credit.
        $share = (float) $invoice['total_amount'] > 0
            ? $value / (float) $invoice['total_amount']
            : 1.0;
        $tax = money_round((float) $invoice['tax_amount'] * $share);

        $issuedAt = date('Y-m-d H:i:s');
        $series   = credit_note_series($issuedAt);
        $sequence = invoice_next_sequence($series);
        $number   = invoice_format_number($series, $sequence);

        $noteId = Database::insert('credit_notes', [
            'note_number'    => $number,
            'series'         => $series,
            'sequence_no'    => $sequence,
            'invoice_id'     => (int) $invoice['id'],
            'invoice_number' => (string) $invoice['invoice_number'],
            'order_id'       => $orderId,
            'order_number'   => (string) $order['order_number'],
            'user_id'        => $order['user_id'] === null ? null : (int) $order['user_id'],
            'customer_name'  => (string) $order['customer_name'],
            'customer_email' => (string) $order['customer_email'],
            'note_date'      => $issuedAt,
            'currency'       => (string) $invoice['currency'],
            'subtotal'       => money_round(-($value - $tax)),
            'tax_amount'     => money_round(-$tax),
            'total_amount'   => $value,
            'reason'         => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'cause'          => mb_substr($cause, 0, 60),
            'snapshot'       => json_encode([
                'company'  => $snapshot['company'] ?? invoice_company_details(),
                'against'  => ['invoice' => (string) $invoice['invoice_number'], 'date' => (string) $invoice['invoice_date']],
                'customer' => $snapshot['customer'] ?? [],
                'billing'  => $snapshot['billing'] ?? [],
                'lines'    => $lines,
                'totals'   => ['credit' => $value, 'tax' => $tax, 'full' => $full],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        log_activity('credit_note.issued', 'invoice', (int) $invoice['id'],
            'Credit note ' . $number . ' for ' . money($value) . ' against invoice ' . $invoice['invoice_number']);

        // The stored PDF was rendered before this note existed and names no
        // credit against it; the next download renders one that does.
        invoice_pdf_invalidate($invoice);

        return get_credit_note($noteId);
    } catch (PDOException $e) {
        // Lost the UNIQUE(order_id, cause) race: the winner's note is the answer.
        if ($e->getCode() === '23000') {
            return Database::fetch('SELECT * FROM `credit_notes` WHERE `order_id` = :o AND `cause` = :c LIMIT 1',
                ['o' => $orderId, 'c' => $cause]);
        }
        ErrorHandler::log('error', 'Credit note failed for order ' . $orderId . ': ' . $e->getMessage());
        return null;
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Credit note failed for order ' . $orderId . ': ' . $e->getMessage());
        return null;
    }
}

function get_credit_note(int $id): ?array
{
    return Database::fetch('SELECT * FROM `credit_notes` WHERE `id` = :id LIMIT 1', ['id' => $id]);
}

/**
 * Credit a refund a gateway reported. Never throws: the money has already
 * moved, and paperwork must not turn that into a webhook failure.
 */
function credit_note_for_refund(int $orderId, float $amount, string $cause, string $reason = ''): ?array
{
    return credit_note_issue($orderId, $amount, $cause, $reason);
}

/**
 * Throw away a stored PDF so the next download renders the document as it now
 * stands. The PDF is written once at issue time and served from disk after
 * that, so without this a cancelled invoice kept handing out the live-looking
 * copy that was rendered before anything went wrong.
 */
function invoice_pdf_invalidate(array $invoice): void
{
    try {
        if (function_exists('invoice_pdf_path')) {
            $file = invoice_pdf_path($invoice);
            if ($file !== null) {
                @unlink($file);
            }
        }

        Database::update('invoices', [
            'pdf_path' => null, 'pdf_filename' => null, 'pdf_size' => null,
            'pdf_generated_at' => null, 'pdf_error' => null,
        ], '`id` = :id', ['id' => (int) $invoice['id']]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Could not invalidate invoice PDF ' . ($invoice['invoice_number'] ?? '?') . ': ' . $e->getMessage());
    }
}

/** Mark an order's invoice cancelled. Only ever for a supply that never happened. */
function invoice_cancel_for_order(int $orderId, string $reason = ''): bool
{
    $invoice = get_invoice_for_order($orderId);
    if ($invoice === null || (string) $invoice['status'] !== 'issued') {
        return false;
    }

    Database::update('invoices', [
        'status'      => 'cancelled',
        'amount_paid' => 0.00,
        'balance_due' => 0.00,
    ], '`id` = :id', ['id' => (int) $invoice['id']]);

    invoice_pdf_invalidate($invoice);

    log_activity('invoice.cancelled', 'invoice', (int) $invoice['id'],
        'Invoice ' . $invoice['invoice_number'] . ' cancelled' . ($reason !== '' ? ': ' . $reason : '.'));

    return true;
}

/**
 * The one entry point the order lifecycle calls when an order ends badly.
 * Decides between the two honest treatments and applies exactly one.
 */
function invoice_settle_for_status(array $order, string $newStatus, ?string $reason = null): void
{
    if (!in_array($newStatus, [ORDER_STATUS_CANCELLED, ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED], true)) {
        return;
    }

    try {
        if (get_invoice_for_order((int) $order['id']) === null) {
            return;
        }

        $why = trim((string) ($reason ?? '')) !== ''
            ? (string) $reason
            : 'Order ' . (ORDER_STATUSES[$newStatus] ?? $newStatus);

        if (order_was_supplied($order)) {
            credit_note_issue((int) $order['id'], null, 'order:' . $newStatus, $why);
            return;
        }

        invoice_cancel_for_order((int) $order['id'], $why);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'Invoice settlement failed for order ' . (int) $order['id'] . ': ' . $e->getMessage());
    }
}

// ===========================================================================
//  ACCESS CONTROL
// ===========================================================================

/**
 * May the current visitor see this invoice?
 *
 * Deliberately the same three-way test invoice.php has always used:
 * the signed-in owner, a guest who placed it in this session, or staff.
 */
function invoice_viewable(array $invoice, array $order): bool
{
    if (admin_can('orders.view')) {
        return true;
    }

    $userId = current_user_id();
    if ($userId !== null && $order['user_id'] !== null && (int) $order['user_id'] === $userId) {
        return true;
    }

    // session_placed_order() lives in api/includes/order-handler.php, which is
    // not part of the default bootstrap — a page that has not loaded it simply
    // has no guest claim to check.
    return function_exists('session_placed_order')
        && session_placed_order((string) $order['order_number']);
}
