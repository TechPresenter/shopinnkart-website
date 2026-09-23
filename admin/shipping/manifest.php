<?php
/**
 * ShopInnKart Admin - Pickup manifest, A4.
 *
 *   ?shipments=12,13,14     the parcels in this handover, in the order given
 *   ?ids[]=12&ids[]=13      the same, as the shipments list's bulk bar sends it
 *   ?awb=MK01..,MK02..      how the mock courier's generateManifest() links here
 *   &provider=mock          narrows the AWBs to one courier
 *   &return=/admin/...      where Back goes (checked by admin_safe_return)
 *
 * The manifest is the one piece of paper that proves a parcel left: the rider
 * signs for a count, and a lost-in-transit claim starts from that signature.
 * The page is shaped around that:
 *
 * One manifest per courier, not one per batch. An aggregator such as
 * Shiprocket sends a different rider for each courier partner, and a rider
 * can't sign for parcels someone else collects. A mixed selection prints one
 * sheet per courier, each starting on a new page with its own totals and
 * signature block.
 *
 * The same refusals as the label (no AWB, cancelled, RTO delivered, returned),
 * for the same reason: a line for a parcel on a void or closed consignment is a
 * signature for nothing. A shipment that is already past pickup is printed but
 * flagged on screen, because reprinting an old manifest is legitimate and only
 * the operator knows which it is.
 *
 * Standalone document, black on white, for the same reasons as label.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';
require_once __DIR__ . '/_print.php';

$selection = shipping_print_selection();
$notes     = $selection['notes'];
$context   = shipping_print_context($selection['shipments']);
$printable = shipping_print_printable($selection['shipments'], $context, $notes);
$storeName = (string) setting('store_name', SITE_NAME);

// ---- one sheet per courier, in the order each courier first appears ------
$sheets = [];
$late   = [];
foreach ($printable as $shipment) {
    $provider = shipping_print_provider($context, $shipment) ?? [];
    $provName = (string) ($provider['name'] ?? $shipment['provider_code']);
    $courier  = trim((string) $shipment['courier_name']);

    $key = $shipment['provider_code'] . '|' . strtolower($courier);
    if (!isset($sheets[$key])) {
        $pickup = trim(implode(', ', array_filter([
            trim((string) ($provider['pickup_address'] ?? '')),
            trim((string) ($provider['pickup_city'] ?? '')),
            trim((string) ($provider['pickup_state'] ?? '')),
        ])) . ' ' . trim((string) ($provider['pickup_pincode'] ?? '')));

        $sheets[$key] = [
            'courier'   => $courier !== '' ? $courier : $provName,
            'provider'  => $provName,
            'show_via'  => $courier !== '' && strcasecmp($courier, $provName) !== 0,
            'pickup'    => $pickup,
            'phone'     => (string) ($provider['pickup_phone'] ?? ''),
            'gstin'     => (string) ($provider['gst_number'] ?? ''),
            'rows'      => [],
            'cod_count' => 0,
            'cod_total' => 0.0,
            'grams'     => 0,
            'unweighed' => 0,
        ];
    }

    $order = $context['orders'][(int) $shipment['order_id']];
    $isCod = (int) $shipment['is_cod'] === 1;

    $sheets[$key]['rows'][] = [
        'id'        => (int) $shipment['id'],
        'awb'       => (string) $shipment['awb'],
        'order_no'  => (string) $order['order_number'],
        // Whole, and not through str_limit(), whose strip_tags() cut "Rahul <Raj>
        // Sharma" to "Rahul  Sharma". The table cell wraps a long one.
        'consignee' => trim((string) ($order['shipping_name'] ?: $order['customer_name'])),
        'city'      => trim((string) $order['shipping_city']),
        'pin'       => (string) $order['shipping_pincode'],
        'is_cod'    => $isCod,
        'cod'       => (float) $shipment['cod_amount'],
        'weight'    => shipping_print_kg($shipment['weight_grams']),
    ];

    if ($isCod) {
        $sheets[$key]['cod_count']++;
        $sheets[$key]['cod_total'] += (float) $shipment['cod_amount'];
    }
    if ((int) $shipment['weight_grams'] > 0) {
        $sheets[$key]['grams'] += (int) $shipment['weight_grams'];
    } else {
        $sheets[$key]['unweighed']++;
    }

    // Past pickup: already with the courier, or finished.
    if (shipping_status_rank((string) $shipment['status']) >= shipping_status_rank('in_transit')) {
        $late[] = '#' . (int) $shipment['id'] . ' (' . shipping_status_label((string) $shipment['status']) . ')';
    }
}
$sheets = array_values($sheets);

if ($late !== []) {
    $notes[] = 'Already past pickup: ' . shipping_print_list($late) . '. They are on the manifest below; '
        . 'leave them out if they are not part of this handover, or the rider signs for parcels that are not there.';
}

if (!$selection['requested']) {
    http_response_code(400);
} elseif ($selection['shipments'] === []) {
    http_response_code(404);
} elseif ($sheets === []) {
    http_response_code(422);
}

// The shipments list the manifest is ticked from - not the Integrations page,
// which needs settings.view that an orders role printing a manifest may lack.
$backUrl = admin_safe_return(is_string($_GET['return'] ?? null) ? $_GET['return'] : '', admin_url('shipping/shipments.php'));

$parcels = count($printable);
$today   = format_date(date('Y-m-d'));
$printed = format_datetime(date('Y-m-d H:i:s'));
$title   = 'Pickup manifest ' . $today;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> &middot; <?= e($storeName) ?> Admin</title>
    <?= brand_favicon_links() ?>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 12mm 14mm;
            /* Chrome 131+ fills this in; elsewhere it is simply absent. */
            @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8pt Arial, sans-serif; }
        }

        :root { color-scheme: light; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font: 10pt/1.35 Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%; }

        <?= shipping_print_toolbar_css() ?>

        .mf-wrap { padding: 20px 16px 40px; }
        .mf { width: 210mm; max-width: 100%; margin: 0 auto; padding: 12mm; background: #fff; color: #000; }
        .mf + .mf { margin-top: 24px; }

        .mf-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4mm 8mm;
                   padding-bottom: 3mm; border-bottom: .6mm solid #000; }
        .mf-head h1 { margin: 0; font-size: 18pt; line-height: 1.1; }
        .mf-head h2 { margin: 1mm 0 0; font-size: 11pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .mf-head dl { margin: 0; display: grid; grid-template-columns: auto auto; gap: .5mm 4mm; font-size: 9.5pt; }
        .mf-head dt { font-weight: 700; }
        .mf-head dd { margin: 0; }

        .mf-from { margin: 3mm 0; font-size: 9pt; overflow-wrap: anywhere; }
        .mf-from b { font-weight: 700; }

        /* No sideways scroll: a print view is the one page that cannot offer
           one on paper, and on screen it was scrolling 264px at 360-390px. */
        .mf-tablewrap { overflow-x: visible; min-width: 0; }
        .mf-table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        .mf-table th, .mf-table td { border: .3mm solid #000; padding: 1.4mm 2mm; text-align: left; vertical-align: top; }
        .mf-table td { overflow-wrap: anywhere; }
        .mf-table th { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; }
        .mf-table thead { display: table-header-group; }
        .mf-table tr { break-inside: avoid; page-break-inside: avoid; }
        /* nowrap on the cell, not on its header: "WEIGHT" over "12.5 kg" was the
           column sticking out of the 7-column table on a phone. */
        .mf-num { text-align: right !important; }
        td.mf-num { white-space: nowrap; }
        .mf-mono { font-family: Consolas, "DejaVu Sans Mono", "Courier New", monospace; font-weight: 700; overflow-wrap: anywhere; }
        .mf-total td { border-top: .8mm solid #000; font-weight: 700; }

        .mf-sum { margin: 3mm 0 0; font-size: 9.5pt; }

        .mf-sign { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 12mm;
                   break-inside: avoid; page-break-inside: avoid; }
        .mf-sign h3 { margin: 0 0 5mm; font-size: 10pt; text-transform: uppercase; letter-spacing: .08em; }
        .mf-line { display: flex; align-items: flex-end; gap: 2mm; margin-top: 7mm; font-size: 9pt; }
        .mf-line span { flex: none; }
        .mf-line i { flex: 1 1 auto; border-bottom: .3mm solid #000; height: 1em; }

        @media screen {
            body { background: #e5e7eb; }
            .mf { box-shadow: 0 1px 3px rgba(0, 0, 0, .3); }
        }
        /* On a phone the sheet becomes one card per shipment.
           Seven columns cannot be laid out in 296px however small the type is
           set - measured at 400px of demand against 296px of room, which used
           to be a 264px sideways scroll and then, with the scroll gone, 46px
           of the page hanging off the right at 360px. The card view is the
           same information in the shape a phone can hold. @media screen only:
           printed, and on anything wider, this is an ordinary manifest table. */
        @media screen and (max-width: 640px) {
            .mf { padding: 16px; }
            .mf-sign { grid-template-columns: 1fr; gap: 6mm; }
            .mf-table { font-size: 8.5pt; }

            .mf-table thead { display: none; }
            .mf-table, .mf-table tbody, .mf-table tr, .mf-table td { display: block; width: auto; }
            .mf-table tr { border: .3mm solid #000; margin-bottom: 3mm; padding: 1.5mm 2.5mm; }
            .mf-table td {
                border: 0;
                border-top: .2mm dashed #999;
                padding: 1.2mm 0;
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                gap: 3mm;
                text-align: right;
            }
            .mf-table tr td:first-child { border-top: 0; }
            .mf-table td::before {
                content: attr(data-label);
                flex: none;
                text-align: left;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .04em;
                font-size: 7.5pt;
            }
            /* The totals row spans the table, so it has no label of its own. */
            .mf-table .mf-total td[colspan] { justify-content: flex-start; text-align: left; }
            .mf-table .mf-total td[colspan]::before { content: none; }
            .mf-num { text-align: right !important; }
        }

        @media print {
            .mf-wrap { padding: 0; }
            .mf { width: auto; max-width: none; margin: 0; padding: 0; }
            .mf + .mf { margin-top: 0; break-before: page; page-break-before: always; }
            .mf-tablewrap { overflow: visible; }
        }
    </style>
</head>
<body>

<?= shipping_print_toolbar(
    $parcels > 0
        ? $parcels . ' ' . ($parcels === 1 ? 'parcel' : 'parcels') . ' · ' . count($sheets) . ' ' . (count($sheets) === 1 ? 'courier' : 'couriers')
        : 'Nothing to print',
    'A4, portrait. One sheet per courier; each rider signs their own.',
    $notes,
    $backUrl,
    $sheets !== []
) ?>

<?php if ($sheets === []): ?>
    <div class="pr-screen pr-empty">
        <h1><?= $selection['requested'] ? 'No manifest can be printed' : 'Which shipments?' ?></h1>
        <p>
            <?php if (!$selection['requested']): ?>
                Give <code>?shipments=</code> or <code>?awb=</code>, each a comma-separated list.
            <?php else: ?>
                None of the shipments asked for can go on a manifest. The reasons are listed above.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <main class="mf-wrap">
        <?php foreach ($sheets as $sheet): ?>
            <?php $rowCount = count($sheet['rows']); ?>
            <section class="mf" aria-label="Manifest for <?= e_attr($sheet['courier']) ?>">

                <div class="mf-head">
                    <div>
                        <h1><?= e($storeName) ?></h1>
                        <h2>Pickup manifest</h2>
                    </div>
                    <dl>
                        <dt>Date</dt>       <dd><?= e($today) ?></dd>
                        <dt>Courier</dt>    <dd><?= e($sheet['courier']) ?><?php if ($sheet['show_via']): ?> (via <?= e($sheet['provider']) ?>)<?php endif; ?></dd>
                        <dt>Parcels</dt>    <dd><?= $rowCount ?></dd>
                        <dt>Printed by</dt> <dd><?= e((string) ($admin['name'] ?? '')) ?>, <?= e($printed) ?></dd>
                    </dl>
                </div>

                <?php if ($sheet['pickup'] !== '' || $sheet['phone'] !== '' || $sheet['gstin'] !== ''): ?>
                    <p class="mf-from">
                        <b>Pickup from:</b> <?= e($sheet['pickup']) ?>
                        <?php if ($sheet['phone'] !== ''): ?> &middot; Ph <?= e($sheet['phone']) ?><?php endif; ?>
                        <?php if ($sheet['gstin'] !== ''): ?> &middot; GSTIN <?= e($sheet['gstin']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="mf-tablewrap">
                    <table class="mf-table">
                        <thead>
                            <tr>
                                <th class="mf-num">#</th>
                                <th>AWB</th>
                                <th>Order no.</th>
                                <th>Consignee</th>
                                <th>City / PIN</th>
                                <th class="mf-num">COD amount</th>
                                <th class="mf-num">Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sheet['rows'] as $n => $row): ?>
                                <?php // data-label is written out here rather than derived, because this
                                      // print view loads neither admin.css nor admin.js - the card view
                                      // and initResponsiveTables() that every other admin table gets do
                                      // not reach it. Used only by the phone block in the stylesheet
                                      // above; on paper and on a desk these are an ordinary table. ?>
                                <tr>
                                    <td class="mf-num" data-label="#"><?= $n + 1 ?></td>
                                    <td class="mf-mono" data-label="AWB"><?= e($row['awb']) ?></td>
                                    <td data-label="Order no."><?= e($row['order_no']) ?></td>
                                    <td data-label="Consignee"><?= e($row['consignee']) ?></td>
                                    <td data-label="City / PIN"><?= e($row['city']) ?><?= $row['city'] !== '' ? ' ' : '' ?><strong><?= e($row['pin']) ?></strong></td>
                                    <td class="mf-num" data-label="COD amount"><?= $row['is_cod'] ? e(shipping_print_rs($row['cod'])) : 'Prepaid' ?></td>
                                    <td class="mf-num" data-label="Weight"><?= $row['weight'] !== '' ? e($row['weight']) : '&mdash;' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php // The totals row sits in tbody, not tfoot: a tfoot repeats on every printed page, and a grand total at the foot of page 1 reads as that page's subtotal. ?>
                            <tr class="mf-total">
                                <td colspan="5">
                                    Total: <?= $rowCount ?> <?= $rowCount === 1 ? 'shipment' : 'shipments' ?>
                                    (<?= (int) $sheet['cod_count'] ?> COD, <?= $rowCount - (int) $sheet['cod_count'] ?> prepaid)
                                </td>
                                <td class="mf-num" data-label="COD total"><?= e(shipping_print_rs($sheet['cod_total'])) ?></td>
                                <td class="mf-num" data-label="Total weight">
                                    <?= e(shipping_print_kg($sheet['grams']) ?: '0 kg') ?>
                                    <?php if ($sheet['unweighed'] > 0): ?><br><small>+<?= (int) $sheet['unweighed'] ?> unweighed</small><?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mf-sum">
                    Cash to be collected by the courier on this manifest:
                    <strong><?= e(shipping_print_rs($sheet['cod_total'])) ?></strong>
                    across <?= (int) $sheet['cod_count'] ?> COD <?= (int) $sheet['cod_count'] === 1 ? 'parcel' : 'parcels' ?>.
                </p>

                <div class="mf-sign">
                    <div>
                        <h3>Handed over by</h3>
                        <div class="mf-line"><span>Name</span><i></i></div>
                        <div class="mf-line"><span>Signature</span><i></i></div>
                        <div class="mf-line"><span>Date &amp; time</span><i></i></div>
                    </div>
                    <div>
                        <h3>Received by courier</h3>
                        <div class="mf-line"><span>Name / ID</span><i></i></div>
                        <div class="mf-line"><span>Signature</span><i></i></div>
                        <div class="mf-line"><span>Date &amp; time</span><i></i></div>
                        <div class="mf-line"><span>Parcels received</span><i></i><span>of <?= $rowCount ?></span></div>
                    </div>
                </div>

            </section>
        <?php endforeach; ?>
    </main>
<?php endif; ?>

</body>
</html>
