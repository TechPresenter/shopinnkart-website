<?php
/**
 * ShopInnKart Admin - Invoice settings.
 *
 * Everything that appears on a tax invoice and controls how invoices are
 * numbered. Numbering fields are deliberately locked once a series has issued
 * its first invoice: changing the prefix or the start number after the fact
 * would either duplicate a number or leave a gap, and a tax invoice series has
 * to be continuous.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once ROOT_PATH . '/database/seeds/email-templates.php';

$issuedCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM `invoices`');
$numberingLocked = $issuedCount > 0;

$spec = [
    'invoice_prefix' => [
        'type' => 'text', 'label' => 'Invoice prefix', 'required' => true, 'max' => 12,
        'placeholder' => 'INV',
        'help' => 'Invoice numbers read PREFIX-YEAR-SEQUENCE, e.g. INV-' . date('Y') . '-000001.',
        'attr' => $numberingLocked ? 'readonly' : '',
    ],
    'invoice_year_mode' => [
        'type' => 'select', 'label' => 'Numbering resets',
        'options' => [
            'calendar'  => 'Every calendar year (' . invoice_series() . '-000001)',
            'financial' => 'Every financial year, Apr-Mar',
        ],
        'attr' => $numberingLocked ? 'disabled' : '',
    ],
    'invoice_start_number' => [
        'type' => 'number', 'label' => 'Starting invoice number', 'min_value' => 1, 'max_value' => 999999,
        'help' => 'Only applies to a series that has not issued an invoice yet.',
        'attr' => $numberingLocked ? 'readonly' : '',
    ],
    'invoice_number_padding' => [
        'type' => 'number', 'label' => 'Sequence digits', 'min_value' => 3, 'max_value' => 10,
        'help' => '6 gives 000001.',
        'attr' => $numberingLocked ? 'readonly' : '',
    ],

    'invoice_company_name' => [
        'type' => 'text', 'label' => 'Company name', 'max' => 150,
        'help' => 'Leave blank to use the store name.',
    ],
    'invoice_company_address' => [
        'type' => 'textarea', 'label' => 'Registered address', 'rows' => 3,
        'help' => 'Leave blank to use the store address.',
    ],
    'invoice_seller_state' => [
        'type' => 'select', 'label' => 'Place of business (state)',
        'options' => array_merge(['' => 'Not set'], array_combine(INDIAN_STATES, INDIAN_STATES)),
        'help' => 'Decides the GST split: same state as the customer bills CGST + SGST, any other state bills IGST.',
    ],
    'gst_number' => [
        'type' => 'text', 'label' => 'GSTIN', 'max' => 20,
        'placeholder' => '29ABCDE1234F1Z5',
        'help' => 'Printed on every invoice when set. Leave blank if you are not GST registered.',
    ],
    'invoice_pan' => [
        'type' => 'text', 'label' => 'PAN', 'max' => 15,
    ],
    'invoice_signatory' => [
        'type' => 'text', 'label' => 'Authorised signatory', 'max' => 120,
        'help' => 'Printed above the signature line.',
    ],
    'invoice_terms' => [
        'type' => 'textarea', 'label' => 'Terms & conditions', 'rows' => 5,
    ],
    'invoice_footer' => [
        'type' => 'textarea', 'label' => 'Invoice footer note', 'rows' => 2,
    ],
    'invoice_auto_generate' => [
        'type' => 'bool', 'label' => 'Generate invoices automatically',
        'help' => 'Raise an invoice as soon as an order is confirmed or paid.',
    ],
    'invoice_attach_to_order_email' => [
        'type' => 'bool', 'label' => 'Attach the PDF to order emails',
    ],
];

$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Save
// ---------------------------------------------------------------------------
if (is_post() && ($action === '' || $action === 'settings')) {
    // A locked field must not be writable just because the input was re-enabled
    // in the browser — drop it from the spec entirely before the save runs.
    if ($numberingLocked) {
        foreach (['invoice_prefix', 'invoice_year_mode', 'invoice_start_number', 'invoice_number_padding'] as $locked) {
            unset($spec[$locked], $_POST[$locked]);
        }
    }

    settings_handle_save('invoice', 'invoice', $spec, settings_group('invoice'));
}

// ---------------------------------------------------------------------------
//  Preview: render a real invoice PDF for the most recent order
// ---------------------------------------------------------------------------
if (is_post() && $action === 'preview') {
    admin_require_action('settings.edit');

    $invoice = Database::fetch('SELECT * FROM `invoices` ORDER BY `id` DESC LIMIT 1');

    if ($invoice === null) {
        flash('warning', 'No invoice has been issued yet, so there is nothing to preview. Place a test order first.');
        redirect(settings_url('invoice'));
    }

    // Re-render so the preview reflects settings saved a moment ago rather than
    // the snapshot frozen when the invoice was first issued.
    $order = get_order((int) $invoice['order_id']);
    if ($order !== null) {
        Database::update('invoices', [
            'snapshot' => json_encode(invoice_build_data($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], '`id` = :id', ['id' => (int) $invoice['id']]);
        $invoice = get_invoice((int) $invoice['id']);
    }

    $pdf = invoice_render_pdf($invoice, true);

    if (!$pdf['ok']) {
        flash('error', 'Preview failed: ' . (string) $pdf['error']);
        redirect(settings_url('invoice'));
    }

    flash('success', 'Preview regenerated for ' . $invoice['invoice_number'] . '.');
    redirect(url('invoice-download.php?invoice=' . rawurlencode((string) $invoice['invoice_number']) . '&view=1'));
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$stored = settings_group('invoice');
$errors = errors_pull();
$values = settings_values($spec, $stored);

$counters = Database::fetchAll('SELECT * FROM `invoice_counters` ORDER BY `series`');
$pdfStats = Database::fetch(
    'SELECT COUNT(*) total,
            SUM(CASE WHEN `pdf_path` IS NOT NULL AND `pdf_path` <> \'\' THEN 1 ELSE 0 END) rendered,
            SUM(CASE WHEN `pdf_error` IS NOT NULL THEN 1 ELSE 0 END) failed
     FROM `invoices`'
) ?? ['total' => 0, 'rendered' => 0, 'failed' => 0];

$pageTitle = 'Invoice settings';
$pageSubtitle = 'Numbering, company details and what appears on the printed bill.';
$breadcrumbs = settings_breadcrumbs('invoice');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('invoice') ?>

<?php
$currentSeries = invoice_series();
$lastNumber = 0;
foreach ($counters as $counter) {
    if ((string) $counter['series'] === $currentSeries) {
        $lastNumber = (int) $counter['last_number'];
    }
}
$nextNumber = invoice_format_number($currentSeries, max($lastNumber + 1, setting_int('invoice_start_number', 1)));
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Invoices issued', number_format((int) $pdfStats['total']), 'file-text', 'navy', 'Across all series') ?>
    <?= admin_stat_card('PDFs stored', number_format((int) $pdfStats['rendered']), 'download', 'green', 'Rendered and on disk') ?>
    <?= admin_stat_card('PDF errors', number_format((int) $pdfStats['failed']), 'alert',
        (int) $pdfStats['failed'] > 0 ? 'red' : 'green', 'Retried on next download') ?>
    <?= admin_stat_card('Next number', $nextNumber, 'hash', 'primary', 'Series ' . $currentSeries) ?>
</div>

<?php if ($numberingLocked): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            <strong><?= number_format($issuedCount) ?> invoice<?= $issuedCount === 1 ? ' has' : 's have' ?> already been issued,
            so the numbering fields are locked.</strong>
            A tax invoice series has to run unbroken. Changing the prefix, the reset mode or the start
            number now would either repeat a number already given to a customer or leave a gap you would
            have to account for. Company details, terms and behaviour below stay editable.
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <div style="display:grid;gap:18px">

        <form class="ad-form" method="post" action="<?= e(settings_url('invoice')) ?>" data-guard-unsaved>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Numbering</div>
                        <div class="ad-card__sub">How each invoice number is built.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <?= settings_field('invoice_prefix', $spec, $values, $errors) ?>
                        <?= settings_field('invoice_year_mode', $spec, $values, $errors) ?>
                    </div>
                    <div class="ad-row ad-row--2">
                        <?= settings_field('invoice_start_number', $spec, $values, $errors) ?>
                        <?= settings_field('invoice_number_padding', $spec, $values, $errors) ?>
                    </div>
                    <p class="ad-muted" style="font-size:12.5px">
                        Next invoice will be <strong class="ad-mono"><?= e($nextNumber) ?></strong>.
                    </p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Company details</div>
                        <div class="ad-card__sub">The seller block printed at the top of every invoice.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?= settings_field('invoice_company_name', $spec, $values, $errors) ?>
                    <?= settings_field('invoice_company_address', $spec, $values, $errors) ?>
                    <div class="ad-row ad-row--2">
                        <?= settings_field('invoice_seller_state', $spec, $values, $errors) ?>
                        <?= settings_field('gst_number', $spec, $values, $errors) ?>
                    </div>
                    <div class="ad-row ad-row--2">
                        <?= settings_field('invoice_pan', $spec, $values, $errors) ?>
                        <?= settings_field('invoice_signatory', $spec, $values, $errors) ?>
                    </div>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Wording &amp; behaviour</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <?= settings_field('invoice_terms', $spec, $values, $errors) ?>
                    <?= settings_field('invoice_footer', $spec, $values, $errors) ?>
                    <?= settings_field('invoice_auto_generate', $spec, $values, $errors) ?>
                    <?= settings_field('invoice_attach_to_order_email', $spec, $values, $errors) ?>
                </div>
                <div class="ad-card__foot">
                    <button type="submit" class="ad-btn ad-btn--primary">Save invoice settings</button>
                </div>
            </div>
        </form>
    </div>

    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Preview</div>
                    <div class="ad-card__sub">Re-renders the newest invoice with the settings above.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <form method="post" action="<?= e(settings_url('invoice')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview">
                    <button type="submit" class="ad-btn ad-btn--block" <?= $issuedCount === 0 ? 'disabled' : '' ?>>
                        <?= icon('eye', 'w-4 h-4') ?> Preview invoice PDF
                    </button>
                </form>
                <?php if ($issuedCount === 0): ?>
                    <p class="ad-muted" style="font-size:12.5px;margin-top:10px">
                        No invoices issued yet — place a test order first.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div><div class="ad-card__title">Where the rest comes from</div></div>
            </div>
            <div class="ad-card__body">
                <p class="ad-muted" style="font-size:12.5px;line-height:1.7;margin:0">
                    Logo and store address:
                    <a href="<?= e(settings_url('store')) ?>">Store settings</a><br>
                    Tax rates and the tax label:
                    <a href="<?= e(settings_url('tax')) ?>">Tax settings</a><br>
                    Currency and formatting:
                    <a href="<?= e(settings_url('general')) ?>">General settings</a>
                </p>
            </div>
        </div>

        <?php if ($counters !== []): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div><div class="ad-card__title">Series counters</div></div>
                </div>
                <div class="ad-card__body ad-card__body--flush">
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Series</th><th>Last</th><th>Next</th></tr></thead>
                            <tbody>
                            <?php foreach ($counters as $counter): ?>
                                <tr>
                                    <td class="ad-mono"><?= e((string) $counter['series']) ?></td>
                                    <td class="ad-mono"><?= (int) $counter['last_number'] ?></td>
                                    <td class="ad-mono"><?= (int) $counter['last_number'] + 1 ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
