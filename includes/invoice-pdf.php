<?php
/**
 * ShopInnKart - PDF invoice rendering (TCPDF).
 *
 * Files land under storage/invoices/, which the storage/.htaccess blocks from
 * the web entirely. Nothing hands out a filesystem path: download.php streams
 * the bytes after checking who is asking.
 *
 * A failure here is recorded on the invoice row and never propagates — the
 * order and the confirmation email must survive a PDF problem.
 */

declare(strict_types=1);

// ===========================================================================
//  TCPDF BOOTSTRAP
// ===========================================================================

/**
 * TCPDF reads its configuration from constants that must exist before the
 * library is parsed, so they are declared here rather than in config/.
 */
function invoice_pdf_bootstrap(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $base = ROOT_PATH . '/vendor/tcpdf/';
    if (!is_file($base . 'tcpdf.php')) {
        return $ready = false;
    }

    if (!class_exists('TCPDF', false)) {
        $cache = STORAGE_PATH . '/cache/pdf/';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        define('K_TCPDF_EXTERNAL_CONFIG', true);
        define('K_PATH_MAIN', $base);
        define('K_PATH_URL', '');
        define('K_PATH_FONTS', $base . 'fonts/');
        define('K_PATH_CACHE', is_dir($cache) ? $cache : sys_get_temp_dir() . '/');
        define('K_BLANK_IMAGE', $base . 'images/_blank.png');
        define('K_CELL_HEIGHT_RATIO', 1.25);
        define('K_TITLE_MAGNIFICATION', 1.3);
        define('K_SMALL_RATIO', 2 / 3);
        define('K_THAI_TOPCHARS', true);
        // User-supplied HTML must never be able to invoke TCPDF methods.
        define('K_TCPDF_CALLS_IN_HTML', false);
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);

        define('PDF_PAGE_FORMAT', 'A4');
        define('PDF_PAGE_ORIENTATION', 'P');
        define('PDF_UNIT', 'mm');
        define('PDF_CREATOR', 'ShopInnKart');
        define('PDF_AUTHOR', 'ShopInnKart');
        define('PDF_HEADER_TITLE', '');
        define('PDF_HEADER_STRING', '');
        define('PDF_HEADER_LOGO', '');
        define('PDF_HEADER_LOGO_WIDTH', 0);
        define('PDF_MARGIN_HEADER', 5);
        define('PDF_MARGIN_FOOTER', 10);
        define('PDF_MARGIN_TOP', 15);
        define('PDF_MARGIN_BOTTOM', 20);
        define('PDF_MARGIN_LEFT', 15);
        define('PDF_MARGIN_RIGHT', 15);
        define('PDF_FONT_NAME_MAIN', 'dejavusans');
        define('PDF_FONT_SIZE_MAIN', 9);
        define('PDF_FONT_NAME_DATA', 'dejavusans');
        define('PDF_FONT_SIZE_DATA', 8);
        define('PDF_FONT_MONOSPACED', 'courier');
        define('PDF_IMAGE_SCALE_RATIO', 1.25);

        require_once $base . 'tcpdf.php';
    }

    require_once INCLUDES_PATH . '/invoice-pdf-document.php';

    return $ready = class_exists('SikInvoicePdf', false);
}

// ===========================================================================
//  STORAGE
// ===========================================================================

/** The customer-facing filename, e.g. INV-2026-000001.pdf */
function invoice_pdf_filename(array $invoice): string
{
    $number = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice['invoice_number']) ?: 'invoice';

    return $number . '.pdf';
}

/**
 * Absolute path of a stored PDF, or null when it has not been rendered.
 *
 * The stored value is relative and is re-validated here: a path that escapes
 * storage/invoices (via traversal or a tampered row) is rejected rather than
 * opened.
 */
function invoice_pdf_path(array $invoice): ?string
{
    $relative = (string) ($invoice['pdf_path'] ?? '');
    if ($relative === '') {
        return null;
    }

    $root = realpath(STORAGE_PATH . '/invoices');
    $full = realpath(STORAGE_PATH . '/' . ltrim($relative, '/\\'));

    if ($root === false || $full === false) {
        return null;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $full = str_replace('\\', '/', $full);

    if (strpos($full, $root) !== 0 || !is_file($full)) {
        return null;
    }

    return $full;
}

/**
 * Where a freshly rendered PDF should be written.
 * The random token keeps the on-disk name unguessable even if the directory
 * protection is ever misconfigured.
 */
function invoice_pdf_target(array $invoice): array
{
    $date = strtotime((string) $invoice['invoice_date']) ?: time();
    $dir  = sprintf('invoices/%s/%s', date('Y', $date), date('m', $date));

    $safeNumber = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice['invoice_number']) ?: 'invoice';
    $name = $safeNumber . '__' . bin2hex(random_bytes(8)) . '.pdf';

    return [
        'relative' => $dir . '/' . $name,
        'absolute' => STORAGE_PATH . '/' . $dir . '/' . $name,
        'dir'      => STORAGE_PATH . '/' . $dir,
    ];
}

// ===========================================================================
//  RENDERING
// ===========================================================================

/**
 * Render (or re-render) the PDF for an invoice and record it on the row.
 *
 * @return array{ok:bool, path:?string, filename:?string, error:?string, cached:bool}
 */
function invoice_render_pdf(array $invoice, bool $force = false): array
{
    $fail = static function (string $error) use ($invoice): array {
        try {
            Database::update(
                'invoices',
                ['pdf_error' => mb_substr($error, 0, 500)],
                '`id` = :id',
                ['id' => (int) $invoice['id']]
            );
        } catch (Throwable $e) {
            // Recording the failure is best-effort; the caller still gets it.
        }
        ErrorHandler::log('warning', 'Invoice PDF failed for ' . $invoice['invoice_number'] . ': ' . $error);

        return ['ok' => false, 'path' => null, 'filename' => null, 'error' => $error, 'cached' => false];
    };

    if (!$force) {
        $existing = invoice_pdf_path($invoice);
        if ($existing !== null) {
            return ['ok' => true, 'path' => $existing, 'filename' => invoice_pdf_filename($invoice), 'error' => null, 'cached' => true];
        }
    }

    if (!invoice_pdf_bootstrap()) {
        return $fail('TCPDF is not installed in vendor/tcpdf.');
    }

    $data = invoice_snapshot($invoice);
    if ($data === []) {
        return $fail('Invoice snapshot is empty and the order could not be re-read.');
    }

    $target = invoice_pdf_target($invoice);
    if (!is_dir($target['dir']) && !@mkdir($target['dir'], 0775, true) && !is_dir($target['dir'])) {
        return $fail('Could not create ' . $target['dir']);
    }

    try {
        $pdf = new SikInvoicePdf('P', 'mm', 'A4', true, 'UTF-8', false);

        $pdf->sikInvoiceNumber = (string) $invoice['invoice_number'];
        $pdf->sikOrderNumber   = (string) $invoice['order_number'];
        $pdf->sikCompanyName   = (string) $data['company']['name'];
        $pdf->sikFooterNote    = mb_substr((string) $data['company']['footer'], 0, 110);

        $pdf->SetCreator('ShopInnKart');
        $pdf->SetAuthor((string) $data['company']['name']);
        $pdf->SetTitle('Tax Invoice ' . $invoice['invoice_number']);
        $pdf->SetSubject('Invoice for order ' . $invoice['order_number']);
        $pdf->SetKeywords('invoice, ' . $invoice['invoice_number'] . ', ' . $invoice['order_number']);

        $pdf->SetPrintHeader(true);
        $pdf->SetPrintFooter(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(12);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setImageScale(1.25);
        // Keeps the PDF byte-identical across renders and stops TCPDF probing
        // the filesystem for a language file it does not have.
        $pdf->setFontSubsetting(true);

        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);

        invoice_pdf_write_masthead($pdf, $data, $invoice);
        $pdf->writeHTML(invoice_pdf_body_html($data, $invoice), true, false, true, false, '');

        $bytes = $pdf->Output('', 'S');
    } catch (Throwable $e) {
        return $fail('TCPDF error: ' . $e->getMessage());
    }

    if (!is_string($bytes) || strncmp($bytes, '%PDF-', 5) !== 0) {
        return $fail('TCPDF produced no usable output.');
    }

    if (@file_put_contents($target['absolute'], $bytes, LOCK_EX) === false) {
        return $fail('Could not write ' . $target['absolute']);
    }
    @chmod($target['absolute'], 0640);

    // Replace the previous file rather than leaving orphans behind.
    $previous = invoice_pdf_path($invoice);
    if ($previous !== null && $previous !== $target['absolute']) {
        @unlink($previous);
    }

    Database::update('invoices', [
        'pdf_path'         => $target['relative'],
        'pdf_filename'     => invoice_pdf_filename($invoice),
        'pdf_size'         => strlen($bytes),
        'pdf_generated_at' => date('Y-m-d H:i:s'),
        'pdf_error'        => null,
    ], '`id` = :id', ['id' => (int) $invoice['id']]);

    return [
        'ok'       => true,
        'path'     => $target['absolute'],
        'filename' => invoice_pdf_filename($invoice),
        'error'    => null,
        'cached'   => false,
    ];
}

/** Fetch the PDF for an order, rendering it on demand if it is missing. */
function invoice_pdf_for_order(int $orderId, bool $force = false): array
{
    $invoice = get_invoice_for_order($orderId);
    if ($invoice === null) {
        return ['ok' => false, 'path' => null, 'filename' => null, 'error' => 'No invoice for this order.', 'cached' => false];
    }

    return invoice_render_pdf($invoice, $force);
}

// ===========================================================================
//  LAYOUT
// ===========================================================================

/**
 * The letterhead: logo and seller block on the left, invoice identity on the
 * right. Drawn with the low-level API because the logo is usually an SVG and
 * writeHTML's <img> handling of SVG is unreliable.
 */
function invoice_pdf_write_masthead(TCPDF $pdf, array $data, array $invoice): void
{
    $company = $data['company'];
    $top = $pdf->GetY();

    // ---- Logo ------------------------------------------------------------
    $logoBottom = $top;
    $logoFile = invoice_pdf_logo_file((string) $company['logo']);

    if ($logoFile !== null) {
        try {
            if (strtolower(pathinfo($logoFile, PATHINFO_EXTENSION)) === 'svg') {
                $pdf->ImageSVG($logoFile, 15, $top, 42, 12, '', 'T', '', 0, false);
            } else {
                $pdf->Image($logoFile, 15, $top, 42, 0, '', '', 'T', true, 300, '', false, false, 0, 'LT');
            }
            $logoBottom = max($logoBottom, $top + 13);
        } catch (Throwable $e) {
            $logoFile = null; // fall through to the wordmark
        }
    }

    if ($logoFile === null) {
        $pdf->SetXY(15, $top);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColor(15, 33, 67);
        $pdf->Cell(90, 8, (string) $company['name'], 0, 1, 'L');
        $logoBottom = $top + 10;
    }

    // ---- Seller block ----------------------------------------------------
    $pdf->SetXY(15, $logoBottom);
    $pdf->SetFont('dejavusans', '', 7.5);
    $pdf->SetTextColor(75, 85, 99);

    $sellerLines = array_filter([
        $logoFile !== null ? (string) $company['name'] : '',
        (string) $company['address'],
        trim(implode(', ', array_filter([$company['city'], $company['state'], $company['pincode']]))),
        (string) $company['country'],
        trim(implode('  ·  ', array_filter([$company['phone'], $company['email']]))),
    ], static fn ($line): bool => trim((string) $line) !== '');

    $pdf->MultiCell(95, 3.6, implode("\n", $sellerLines), 0, 'L', false, 1, 15, null, true, 0, false, true, 0, 'T');

    if ($company['gstin'] !== '' || $company['pan'] !== '') {
        $ids = array_filter([
            $company['gstin'] !== '' ? 'GSTIN: ' . $company['gstin'] : '',
            $company['pan'] !== '' ? 'PAN: ' . $company['pan'] : '',
        ]);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->MultiCell(95, 4, implode('    ', $ids), 0, 'L', false, 1, 15, null, true, 0, false, true, 0, 'T');
    }

    $leftBottom = $pdf->GetY();

    // ---- Invoice identity (right) ---------------------------------------
    // A document for a supply that never happened must say so on its face -
    // the customer downloads this PDF, and an order cancelled before dispatch
    // used to hand back a tax invoice that read exactly like a live one.
    $cancelled = (string) ($invoice['status'] ?? 'issued') === 'cancelled';

    $pdf->SetXY(110, $top);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetTextColor(...($cancelled ? [185, 28, 28] : [15, 33, 67]));
    $pdf->Cell(85, 8, $cancelled ? 'TAX INVOICE - CANCELLED' : 'TAX INVOICE', 0, 1, 'R');

    $paid = !$cancelled && (string) $data['order']['payment_status'] === PAYMENT_STATUS_PAID;
    $pdf->SetXY(110, $top + 9);
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(...($paid ? [4, 120, 87] : [180, 83, 9]));
    $pdf->Cell(85, 5, $cancelled
        ? 'NOTHING SUPPLIED - NOTHING DUE'
        : strtoupper((string) $data['order']['payment_status_label']), 0, 1, 'R');

    // Credit notes stand beside the invoice rather than replacing it, so the
    // invoice names them and the reader can follow the money both ways.
    $credits = get_credit_notes_for_order((int) $invoice['order_id']);
    if ($credits !== []) {
        $pdf->SetXY(110, $top + 14);
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(180, 83, 9);
        $pdf->Cell(85, 4, 'CREDITED BY ' . implode(', ', array_map(
            static fn (array $note): string => (string) $note['note_number'], $credits
        )), 0, 1, 'R');
    }

    $meta = [
        ['Invoice no.', (string) $invoice['invoice_number']],
        ['Invoice date', format_date((string) $invoice['invoice_date'])],
        ['Order no.', (string) $invoice['order_number']],
        ['Order date', format_date((string) $data['order']['date'])],
    ];
    if (($data['tax']['place_of_supply'] ?? '') !== '') {
        $meta[] = ['Place of supply', (string) $data['tax']['place_of_supply']];
    }

    // The credit line above takes a row of its own, so the meta block starts
    // below it rather than on top of it.
    $y = $top + ($credits !== [] ? 19.5 : 15.5);
    foreach ($meta as [$label, $value]) {
        $pdf->SetXY(110, $y);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(38, 4.4, $label, 0, 0, 'R');

        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(47, 4.4, $value, 0, 1, 'R');
        $y += 4.4;
    }

    $pdf->SetXY(15, max($leftBottom, $y) + 2);
    $pdf->SetTextColor(17, 24, 39);
}

/** Resolve the configured logo to a readable file inside the project. */
function invoice_pdf_logo_file(string $configured): ?string
{
    $candidates = [];
    $configured = trim($configured);

    if ($configured !== '') {
        // Settings may hold a project-relative path or an absolute URL.
        $relative = $configured;
        if (preg_match('#^https?://#i', $relative)) {
            $path = parse_url($relative, PHP_URL_PATH) ?: '';
            $relative = BASE_PATH !== '' && strpos($path, BASE_PATH) === 0
                ? substr($path, strlen(BASE_PATH))
                : $path;
        }
        $candidates[] = ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    $candidates[] = ROOT_PATH . '/' . BRAND_LOGO;

    $root = str_replace('\\', '/', realpath(ROOT_PATH) ?: ROOT_PATH);
    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            continue;
        }
        // Never reach outside the project for a logo.
        if (strpos(str_replace('\\', '/', $real), $root) !== 0) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['svg', 'png', 'jpg', 'jpeg', 'gif'], true)) {
            continue;
        }

        return $real;
    }

    return null;
}

/**
 * Everything below the masthead, as HTML.
 *
 * TCPDF understands a narrow slice of HTML/CSS — tables, inline styles, a few
 * text properties. No flexbox, no grid, no external stylesheet. <thead>
 * repeats automatically when the item table crosses a page boundary.
 */
function invoice_pdf_body_html(array $data, array $invoice): string
{
    $money = static fn ($amount): string => e(money((float) $amount, true, 2));
    $esc = static fn ($value): string => e((string) $value);

    $taxLabel = $esc($data['tax']['label']);

    // ---- Addresses -------------------------------------------------------
    $html = '<table cellpadding="6" cellspacing="0" width="100%" style="width:100%;">'
        . '<tr>'
        . '<td width="50%" style="background-color:#f9fafb;border:0.5px solid #e5e7eb;">'
        . '<span style="font-size:6.5pt;color:#6b7280;font-weight:bold;">BILLED TO</span><br />'
        . invoice_pdf_address_html($data['billing'], $data['customer'], true)
        . '</td>'
        . '<td width="4"></td>'
        . '<td width="50%" style="background-color:#f9fafb;border:0.5px solid #e5e7eb;">'
        . '<span style="font-size:6.5pt;color:#6b7280;font-weight:bold;">SHIPPED TO</span><br />'
        . invoice_pdf_address_html($data['shipping'], $data['customer'], false)
        . '</td>'
        . '</tr></table>';

    $html .= '<br />';

    // ---- Line items ------------------------------------------------------
    $showHsn = false;
    foreach ($data['lines'] as $line) {
        if (trim((string) $line['hsn']) !== '') {
            $showHsn = true;
            break;
        }
    }
    $showTax = ($data['tax']['line_tax'] ?? 0) > 0;

    // Column widths must total exactly 100%. TCPDF does not normalise them —
    // an over-100 total silently crushes the trailing columns until a header
    // like "Amount" renders as a single letter.
    $columns = [['key' => 'position', 'label' => '#', 'align' => 'center', 'width' => $showTax ? 4 : 5]];
    $columns[] = ['key' => 'item', 'label' => 'Item', 'align' => 'left', 'width' => 0]; // filled below
    if ($showHsn) {
        $columns[] = ['key' => 'hsn', 'label' => 'HSN', 'align' => 'center', 'width' => $showTax ? 8 : 12];
    }
    $columns[] = ['key' => 'quantity', 'label' => 'Qty', 'align' => 'center', 'width' => $showTax ? 6 : 8];
    $columns[] = ['key' => 'unit_price', 'label' => 'Unit price', 'align' => 'right', 'width' => $showTax ? 13 : 16];
    if ($showTax) {
        $columns[] = ['key' => 'taxable', 'label' => 'Taxable', 'align' => 'right', 'width' => 13];
        $columns[] = ['key' => 'tax_rate', 'label' => $taxLabel . '%', 'align' => 'right', 'width' => 7];
        $columns[] = ['key' => 'tax_amount', 'label' => $taxLabel, 'align' => 'right', 'width' => 10];
    }
    $columns[] = ['key' => 'total', 'label' => 'Amount', 'align' => 'right', 'width' => $showTax ? 12 : 17];

    // The item column absorbs whatever is left, so the row always sums to 100.
    $used = array_sum(array_column($columns, 'width'));
    foreach ($columns as $index => $column) {
        if ($column['key'] === 'item') {
            $columns[$index]['width'] = 100 - $used;
        }
    }

    $html .= '<table cellpadding="4" cellspacing="0" width="100%" style="width:100%;font-size:7.5pt;border:0.5px solid #e5e7eb;">';
    $html .= '<thead><tr style="background-color:#0f2143;color:#ffffff;font-weight:bold;">';
    foreach ($columns as $column) {
        $html .= '<th width="' . $column['width'] . '%" align="' . $column['align'] . '">' . $column['label'] . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($data['lines'] as $index => $line) {
        $shade = $index % 2 === 1 ? ' style="background-color:#f9fafb;"' : '';

        $itemCell = '<span style="font-weight:bold;">' . $esc($line['name']) . '</span>';
        if (trim((string) $line['variant']) !== '') {
            $itemCell .= '<br /><span style="color:#6b7280;font-size:6.5pt;">' . $esc($line['variant']) . '</span>';
        }
        if (trim((string) $line['sku']) !== '') {
            $itemCell .= '<br /><span style="color:#6b7280;font-size:6.5pt;">SKU ' . $esc($line['sku']) . '</span>';
        }

        $html .= '<tr' . $shade . '>';
        foreach ($columns as $column) {
            // The width has to repeat on every body cell: TCPDF sizes each row
            // independently, so widths declared only on <th> leave the body
            // rows auto-distributed and visibly out of step with the header.
            $style = $column['key'] === 'total' ? ' style="font-weight:bold;"' : '';

            switch ($column['key']) {
                case 'position':
                case 'quantity':
                    $value = (string) (int) $line[$column['key']];
                    break;
                case 'item':
                    $value = $itemCell;
                    break;
                case 'hsn':
                    $value = trim((string) $line['hsn']) !== '' ? $esc($line['hsn']) : '—';
                    break;
                case 'tax_rate':
                    $value = $esc(invoice_rate_label((float) $line['tax_rate']));
                    break;
                default:
                    $value = $money($line[$column['key']]);
            }

            $html .= '<td width="' . $column['width'] . '%" align="' . $column['align'] . '"' . $style . '>' . $value . '</td>';
        }
        $html .= '</tr>';
    }

    if ($data['lines'] === []) {
        $html .= '<tr><td colspan="' . count($columns) . '" align="center" style="color:#6b7280;padding:10px;">This invoice has no line items.</td></tr>';
    }

    $html .= '</tbody></table><br />';

    // ---- Tax summary + totals, side by side ------------------------------
    $totals = $data['totals'];

    $taxSummary = '';
    if ($data['tax']['rows'] !== []) {
        $taxSummary = '<table cellpadding="3" cellspacing="0" width="100%" style="width:100%;font-size:7pt;border:0.5px solid #e5e7eb;">'
            . '<tr style="background-color:#f3f4f6;font-weight:bold;">'
            . '<td width="46%">' . $taxLabel . ' summary</td>'
            . '<td width="27%" align="right">Taxable</td>'
            . '<td width="27%" align="right">Tax</td>'
            . '</tr>';
        foreach ($data['tax']['rows'] as $row) {
            $taxSummary .= '<tr>'
                . '<td>' . $esc($row['label']) . '</td>'
                . '<td align="right">' . $money($row['taxable']) . '</td>'
                . '<td align="right">' . $money($row['amount']) . '</td>'
                . '</tr>';
        }
        $taxSummary .= '</table>';
    }

    $summaryRow = static function (string $label, string $value, bool $bold = false, string $color = '#111827') use ($esc): string {
        $weight = $bold ? 'font-weight:bold;' : '';
        return '<tr>'
            . '<td align="left" style="color:#6b7280;' . $weight . '">' . $label . '</td>'
            . '<td align="right" style="' . $weight . 'color:' . $color . ';">' . $value . '</td>'
            . '</tr>';
    };

    // With tax-inclusive pricing the item prices already contain the tax, so
    // listing it as its own addend makes the column fail to add up on the page
    // (24,899 − 1,500 + 3,569 ≠ 23,399). It is stated after the total instead.
    $inclusive = (bool) ($data['tax']['inclusive'] ?? false);

    $summary = '<table cellpadding="3" cellspacing="0" width="100%" style="width:100%;font-size:8pt;">';
    $summary .= $summaryRow(
        $inclusive ? 'Item total (incl. ' . $taxLabel . ')' : 'Subtotal',
        $money($totals['subtotal'])
    );

    if ((float) $totals['discount'] > 0) {
        $label = 'Discount' . ($totals['coupon_code'] !== '' ? ' (' . $esc($totals['coupon_code']) . ')' : '');
        $summary .= $summaryRow($label, '− ' . $money($totals['discount']), false, '#047857');
    }
    $summary .= $summaryRow('Shipping', (float) $totals['shipping'] > 0 ? $money($totals['shipping']) : 'FREE');

    if ((float) $totals['payment_charge'] > 0) {
        $summary .= $summaryRow('Payment handling', $money($totals['payment_charge']));
    }
    if ((float) $totals['payment_discount'] > 0) {
        $summary .= $summaryRow('Prepaid discount', '− ' . $money($totals['payment_discount']), false, '#047857');
    }
    if (!$inclusive) {
        $summary .= $summaryRow($taxLabel, $money($totals['tax']));
    }

    $summary .= '<tr><td colspan="2" style="border-bottom:0.5px solid #e5e7eb;"></td></tr>';
    $summary .= '<tr>'
        . '<td align="left" style="font-weight:bold;font-size:9.5pt;">Grand total</td>'
        . '<td align="right" style="font-weight:bold;font-size:9.5pt;">' . $money($totals['grand_total']) . '</td>'
        . '</tr>';

    if ($inclusive && (float) $totals['tax'] > 0) {
        $summary .= '<tr>'
            . '<td align="left" style="color:#6b7280;font-size:7pt;">of which ' . $taxLabel . ' (included)</td>'
            . '<td align="right" style="color:#6b7280;font-size:7pt;">' . $money($totals['tax']) . '</td>'
            . '</tr>';
    }

    $summary .= $summaryRow('Amount paid', $money($totals['amount_paid']));

    if ((float) $totals['balance_due'] > 0) {
        $summary .= $summaryRow('Balance due', $money($totals['balance_due']), true, '#b45309');
    }
    $summary .= '</table>';

    $html .= '<table cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr>'
        . '<td width="52%" valign="top">' . $taxSummary . '</td>'
        . '<td width="3%"></td>'
        . '<td width="45%" valign="top">' . $summary . '</td>'
        . '</tr></table>';

    // ---- Amount in words --------------------------------------------------
    $html .= '<br /><table cellpadding="5" cellspacing="0" width="100%" style="width:100%;background-color:#f9fafb;border:0.5px solid #e5e7eb;">'
        . '<tr><td style="font-size:7.5pt;">'
        . '<span style="color:#6b7280;">Amount in words: </span>'
        . '<span style="font-weight:bold;">' . $esc($totals['in_words']) . '</span>'
        . '</td></tr></table>';

    // ---- Payment ---------------------------------------------------------
    $paymentBits = [
        'Method: ' . $esc($data['order']['payment_name']),
        'Status: ' . $esc($data['order']['payment_status_label']),
    ];
    if (trim((string) $data['order']['tracking_number']) !== '') {
        $paymentBits[] = 'Tracking: ' . $esc($data['order']['tracking_number']);
    }

    $html .= '<br /><table cellpadding="0" cellspacing="0" width="100%" style="width:100%;font-size:7.5pt;">'
        . '<tr><td><span style="color:#6b7280;font-weight:bold;font-size:6.5pt;">PAYMENT</span><br />'
        . implode(' &nbsp;·&nbsp; ', $paymentBits)
        . '</td></tr></table>';

    // ---- Terms -----------------------------------------------------------
    $terms = trim((string) $data['company']['terms']);
    if ($terms !== '') {
        $html .= '<br /><table cellpadding="5" cellspacing="0" width="100%" style="width:100%;border:0.5px solid #e5e7eb;">'
            . '<tr><td style="font-size:6.8pt;color:#4b5563;">'
            . '<span style="color:#6b7280;font-weight:bold;font-size:6.5pt;">TERMS &amp; CONDITIONS</span><br />'
            . nl2br($esc($terms))
            . '</td></tr></table>';
    }

    // ---- Signature -------------------------------------------------------
    $signatory = trim((string) $data['company']['signatory']);
    $html .= '<br /><table cellpadding="0" cellspacing="0" width="100%" style="width:100%;font-size:7pt;"><tr>'
        . '<td width="55%" valign="top" style="color:#6b7280;">'
        . $esc($data['company']['footer'])
        . '</td>'
        . '<td width="45%" align="right" valign="top">'
        . 'For <span style="font-weight:bold;">' . $esc($data['company']['name']) . '</span><br /><br /><br />'
        . ($signatory !== '' ? $esc($signatory) . '<br />' : '')
        . '<span style="color:#6b7280;">Authorised signatory</span>'
        . '</td></tr></table>';

    return $html;
}

/** One address block for the billed-to / shipped-to panels. */
function invoice_pdf_address_html(array $address, array $customer, bool $withEmail): string
{
    $esc = static fn ($value): string => e((string) $value);

    $lines = [];
    if (trim((string) $address['name']) !== '') {
        $lines[] = '<span style="font-weight:bold;">' . $esc($address['name']) . '</span>';
    }
    foreach (['address', 'address2'] as $field) {
        if (trim((string) ($address[$field] ?? '')) !== '') {
            $lines[] = $esc($address[$field]);
        }
    }
    if (trim((string) ($address['landmark'] ?? '')) !== '') {
        $lines[] = 'Near ' . $esc($address['landmark']);
    }

    $cityLine = trim(implode(', ', array_filter([$address['city'], $address['state']])));
    if (trim((string) $address['pincode']) !== '') {
        $cityLine = trim($cityLine . ' ' . $address['pincode']);
    }
    if ($cityLine !== '') {
        $lines[] = $esc($cityLine);
    }
    if (trim((string) $address['country']) !== '') {
        $lines[] = $esc($address['country']);
    }
    if (trim((string) $address['phone']) !== '') {
        $lines[] = $esc($address['phone']);
    }
    if ($withEmail && trim((string) $customer['email']) !== '') {
        $lines[] = $esc($customer['email']);
    }

    return '<span style="font-size:7.5pt;">' . implode('<br />', $lines) . '</span>';
}
