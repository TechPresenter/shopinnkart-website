<?php
/**
 * ShopInnKart Admin - Shipping labels, 100 x 150 mm (4 x 6 in thermal).
 *
 *   ?shipment=12            one label
 *   ?shipments=12,13,14     a batch, one label per page, in the order given
 *   ?awb=MK019000031        how the mock courier's generateLabel() links here
 *   &provider=mock          narrows an AWB to one courier
 *   &return=/admin/...      where Back goes (checked by admin_safe_return)
 *
 * Why it is shaped like this:
 *
 * A standalone document, not an admin page. A label printer prints exactly
 * the page it is given, so the sidebar and topbar must not exist in the DOM
 * at all - hiding them with print CSS still leaves their box on the page.
 *
 * Black on white, and nothing that depends on a background colour. A thermal
 * head has no grey, and browsers drop backgrounds when printing unless told
 * otherwise; a white-on-black "COD" box that loses its black prints as a
 * blank. So COD is a heavy border and large type instead.
 *
 * One label per shipment, never per order. The AWB is the consignment; a
 * cancelled and rebooked order has a new one, and the old label must die with
 * the old consignment. That is why a cancelled shipment is refused rather than
 * printed, as is one without an AWB: a label with no barcode gets stuck on a
 * parcel anyway, and the courier refuses it at pickup.
 *
 * Everything comes from the shipment row where the shipment has it. The COD
 * figure is what the courier was told at booking, which is what the rider
 * collects; if the order total changed afterwards, the order is what is
 * wrong, and reprinting a different amount would not change what the courier
 * remits.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('orders.view');

require_once INCLUDES_PATH . '/shipping-service.php';
require_once INCLUDES_PATH . '/barcode.php';
require_once __DIR__ . '/_print.php';

$selection = shipping_print_selection();
$notes     = $selection['notes'];
$context   = shipping_print_context($selection['shipments']);
$printable = shipping_print_printable($selection['shipments'], $context, $notes);
$storeName = (string) setting('store_name', SITE_NAME);

$labels         = [];
$incompleteFrom = [];   // provider names, once each

foreach ($printable as $shipment) {
    $awb = (string) $shipment['awb'];

    // Bar height grows with the AWB: the SVG scales to the label's width, so
    // a longer code is drawn smaller and would otherwise come out squat.
    $awbSvg = barcode_code128_svg($awb, max(60, 5 * strlen($awb)), 2.0);
    if ($awbSvg === '') {
        $notes[] = '#' . (int) $shipment['id'] . ': AWB "' . $awb . '" has characters a Code 128 barcode cannot carry, '
            . 'so no scannable label can be made for it.';
        continue;
    }

    $order    = $context['orders'][(int) $shipment['order_id']];
    $provider = shipping_print_provider($context, $shipment) ?? [];
    $provName = (string) ($provider['name'] ?? $shipment['provider_code']);

    // Ship-from is the courier's pickup location, as the courier has it on
    // file. Only the sender's NAME falls back to the store: mixing a store
    // address into a half-filled pickup address would print a return address
    // that exists nowhere.
    $fromLines = [];
    if (trim((string) ($provider['pickup_address'] ?? '')) !== '') {
        $fromLines[] = str_limit((string) $provider['pickup_address'], 110);
    }
    $fromCity = trim(implode(', ', array_filter([
        trim((string) ($provider['pickup_city'] ?? '')),
        trim((string) ($provider['pickup_state'] ?? '')),
    ])) . ' ' . trim((string) ($provider['pickup_pincode'] ?? '')));
    if ($fromCity !== '') {
        $fromLines[] = $fromCity;
    }
    if (trim((string) ($provider['pickup_address'] ?? '')) === '' || trim((string) ($provider['pickup_phone'] ?? '')) === '') {
        $incompleteFrom[$provName] = true;
    }

    // Items: the four lines that fit, then a count. Names are clipped here
    // rather than by CSS alone so the text in the DOM matches what prints.
    $lines    = $context['items'][(int) $order['id']] ?? [];
    $itemRows = [];
    $units    = 0;
    foreach ($lines as $i => $line) {
        $units += (int) $line['quantity'];
        if ($i < 4) {
            $itemRows[] = str_limit((string) $line['product_name'], 44) . ' × ' . (int) $line['quantity'];
        }
    }

    $dims = '';
    if ((float) $shipment['length_cm'] > 0 && (float) $shipment['width_cm'] > 0 && (float) $shipment['height_cm'] > 0) {
        $cm   = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
        $dims = $cm($shipment['length_cm']) . ' × ' . $cm($shipment['width_cm']) . ' × ' . $cm($shipment['height_cm']) . ' cm';
    }

    $orderNumber = (string) $order['order_number'];

    $labels[] = [
        'id'          => (int) $shipment['id'],
        'awb'         => $awb,
        'awb_svg'     => $awbSvg,
        'courier'     => trim((string) $shipment['courier_name']) !== '' ? (string) $shipment['courier_name'] : $provName,
        'provider'    => $provName,
        'show_via'    => trim((string) $shipment['courier_name']) !== '' && strcasecmp(trim((string) $shipment['courier_name']), $provName) !== 0,
        'is_cod'      => (int) $shipment['is_cod'] === 1,
        'cod_amount'  => (float) $shipment['cod_amount'],
        'to_name'     => str_limit((string) ($order['shipping_name'] ?: $order['customer_name']), 48),
        'to_lines'    => shipping_print_address_lines($order),
        'to_city'     => trim(implode(', ', array_filter([trim((string) $order['shipping_city']), trim((string) $order['shipping_state'])]))),
        'to_pin'      => (string) $order['shipping_pincode'],
        'to_phone'    => (string) ($order['shipping_phone'] ?: $order['customer_phone']),
        'order_no'    => $orderNumber,
        'order_svg'   => barcode_code128_svg($orderNumber, 60, 2.0),
        'booked'      => format_date($shipment['created_at']),
        'weight'      => shipping_print_kg($shipment['weight_grams']),
        'dims'        => $dims,
        'items'       => $itemRows,
        'items_more'  => max(0, count($lines) - count($itemRows)),
        'units'       => $units,
        'from_name'   => trim((string) ($provider['pickup_name'] ?? '')) !== '' ? (string) $provider['pickup_name'] : $storeName,
        'from_lines'  => $fromLines,
        'from_phone'  => (string) ($provider['pickup_phone'] ?? ''),
        'from_gstin'  => (string) ($provider['gst_number'] ?? ''),
    ];
}

foreach (array_keys($incompleteFrom) as $name) {
    $notes[] = $name . ' has no pickup address or phone on file, so the return address on these labels is incomplete. '
        . 'Fill it in under Shipping > Integrations > ' . $name . ' > Configure.';
}

// A status a script (or a test) can read; the screen says the same in words.
if (!$selection['requested']) {
    http_response_code(400);
} elseif ($selection['shipments'] === []) {
    http_response_code(404);
} elseif ($labels === []) {
    http_response_code(422);
}

// Back: to the order when one shipment was asked for, otherwise the shipping
// hub, unless the page that linked here said where it lives.
$fallback = count($selection['shipments']) === 1
    ? admin_url('orders/view.php?id=' . (int) ($selection['shipments'][0]['order_id'] ?? 0))
    : admin_url('shipping/');
$backUrl  = admin_safe_return(is_string($_GET['return'] ?? null) ? $_GET['return'] : '', $fallback);

$count = count($labels);
$title = $count === 1 ? 'Label ' . $labels[0]['awb'] : ($count . ' shipping labels');
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
        @page { size: 100mm 150mm; margin: 0; }

        :root { color-scheme: light; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%; }

        <?= shipping_print_toolbar_css() ?>

        /* ---- the label: 100 x 150 mm, 3 mm inside the edge ------------- */
        .lbl-sheet { display: flex; flex-direction: column; align-items: center; gap: 24px; padding: 20px 16px 40px; }
        .lbl { width: 100mm; height: 150mm; padding: 3mm; display: flex; flex-direction: column;
               overflow: hidden; background: #fff; color: #000; line-height: 1.2; }
        .lbl > * + * { border-top: .4mm solid #000; }

        .lbl-cap { display: block; font-size: 6.5pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }

        .lbl-head { display: flex; align-items: stretch; gap: 2mm; padding-bottom: 2mm; }
        .lbl-courier { flex: 1 1 auto; min-width: 0; align-self: center; }
        .lbl-courier strong { display: block; font-size: 15pt; line-height: 1.05; overflow-wrap: anywhere; }
        .lbl-courier span { display: block; margin-top: .8mm; font-size: 7.5pt; }
        .lbl-pay { flex: 0 0 auto; min-width: 36mm; max-width: 50mm; padding: 1mm 2mm; text-align: center;
                   display: flex; flex-direction: column; justify-content: center; border: .5mm solid #000; }
        .lbl-pay--cod { border-width: 1.2mm; }
        .lbl-pay b { font-size: 11pt; letter-spacing: .1em; }
        .lbl-pay strong { font-size: 16pt; line-height: 1.1; white-space: nowrap; }
        .lbl-pay small { font-size: 6.5pt; }

        .lbl-awb { padding: 1.5mm 0; }
        .lbl-awb .sik-barcode { display: block; width: 100%; height: auto; max-height: 27mm; margin: 0 auto; }

        .lbl-to { padding: 1.8mm 0; }
        .lbl-to__name { margin-top: .6mm; font-size: 12pt; font-weight: 700; overflow-wrap: anywhere; }
        .lbl-to__addr { margin-top: .6mm; font-size: 9.5pt; line-height: 1.25; overflow-wrap: anywhere; }
        .lbl-to__pin { display: flex; align-items: baseline; justify-content: space-between; gap: 2mm; margin-top: .8mm; }
        .lbl-to__pin strong { font-size: 22pt; letter-spacing: .05em; line-height: 1; }
        .lbl-to__pin span { font-size: 10pt; font-weight: 700; white-space: nowrap; }

        .lbl-order { display: flex; align-items: center; gap: 2.5mm; padding: 1.5mm 0; }
        .lbl-order__code { flex: 0 0 50mm; min-width: 0; }
        .lbl-order__code .sik-barcode { display: block; width: 100%; height: auto; max-height: 13mm; }
        .lbl-order__meta { flex: 1 1 auto; min-width: 0; font-size: 8pt; line-height: 1.35; }
        .lbl-order__meta b { font-weight: 700; }

        .lbl-items { flex: 1 1 auto; min-height: 0; overflow: hidden; padding: 1.5mm 0; font-size: 8pt; }
        .lbl-items ul { margin: .6mm 0 0; padding: 0; list-style: none; }
        .lbl-items li { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .lbl-from { padding-top: 1.5mm; font-size: 7.5pt; line-height: 1.3; overflow-wrap: anywhere; }
        .lbl-from strong { font-size: 8.5pt; }

        @media screen {
            body { background: #e5e7eb; }
            .lbl { box-shadow: 0 1px 3px rgba(0, 0, 0, .3); }
        }
        /* A 100 mm label is 378 CSS px; scale the preview, never the print. */
        @media screen and (max-width: 420px) { .lbl { zoom: .86; } }
        @media screen and (max-width: 359px) { .lbl { zoom: .76; } }

        @media print {
            .lbl-sheet { display: block; padding: 0; }
            .lbl { break-after: page; page-break-after: always; }
            .lbl:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>

<?= shipping_print_toolbar(
    $count > 0 ? ($count === 1 ? '1 label' : $count . ' labels') : 'Nothing to print',
    'Label printer, 100 × 150 mm (4 × 6 in) stock, scale 100%, margins none.',
    $notes,
    $backUrl,
    $count > 0
) ?>

<?php if ($labels === []): ?>
    <div class="pr-screen pr-empty">
        <h1><?= $selection['requested'] ? 'No label can be printed' : 'Which shipments?' ?></h1>
        <p>
            <?php if (!$selection['requested']): ?>
                Open this page from a shipment, or give <code>?shipment=</code>, <code>?shipments=</code>
                (comma-separated) or <code>?awb=</code>.
            <?php else: ?>
                None of the shipments asked for can be labelled. The reasons are listed above.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <main class="lbl-sheet">
        <?php foreach ($labels as $label): ?>
            <section class="lbl" aria-label="Shipping label, AWB <?= e_attr($label['awb']) ?>">

                <div class="lbl-head">
                    <div class="lbl-courier">
                        <strong><?= e($label['courier']) ?></strong>
                        <?php if ($label['show_via']): ?><span>via <?= e($label['provider']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($label['is_cod']): ?>
                        <div class="lbl-pay lbl-pay--cod">
                            <b>COD</b>
                            <strong><?= e(shipping_print_rs($label['cod_amount'])) ?></strong>
                            <small>Collect cash on delivery</small>
                        </div>
                    <?php else: ?>
                        <div class="lbl-pay">
                            <b>PREPAID</b>
                            <small>Do not collect cash</small>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="lbl-awb"><?= $label['awb_svg'] /* built from escaped text in barcode.php */ ?></div>

                <div class="lbl-to">
                    <span class="lbl-cap">Deliver to</span>
                    <div class="lbl-to__name"><?= e($label['to_name']) ?></div>
                    <div class="lbl-to__addr">
                        <?php foreach ($label['to_lines'] as $line): ?><?= e($line) ?><br><?php endforeach; ?>
                        <?= e($label['to_city']) ?>
                    </div>
                    <div class="lbl-to__pin">
                        <strong>PIN <?= e($label['to_pin']) ?></strong>
                        <?php if ($label['to_phone'] !== ''): ?><span>Ph <?= e($label['to_phone']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="lbl-order">
                    <div class="lbl-order__code">
                        <span class="lbl-cap">Order</span>
                        <?php if ($label['order_svg'] !== ''): ?>
                            <?= $label['order_svg'] ?>
                        <?php else: ?>
                            <strong><?= e($label['order_no']) ?></strong>
                        <?php endif; ?>
                    </div>
                    <div class="lbl-order__meta">
                        <div>Booked <b><?= e($label['booked']) ?></b></div>
                        <?php if ($label['weight'] !== ''): ?><div>Weight <b><?= e($label['weight']) ?></b></div><?php endif; ?>
                        <?php if ($label['dims'] !== ''): ?><div>Size <b><?= e($label['dims']) ?></b></div><?php endif; ?>
                        <div>Shipment <b>#<?= (int) $label['id'] ?></b></div>
                    </div>
                </div>

                <div class="lbl-items">
                    <span class="lbl-cap">Contents &middot; <?= (int) $label['units'] ?> <?= $label['units'] === 1 ? 'unit' : 'units' ?></span>
                    <ul>
                        <?php foreach ($label['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                        <?php if ($label['items_more'] > 0): ?>
                            <li>+ <?= (int) $label['items_more'] ?> more <?= $label['items_more'] === 1 ? 'item' : 'items' ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="lbl-from">
                    <span class="lbl-cap">If undelivered, return to</span>
                    <strong><?= e($label['from_name']) ?></strong><br>
                    <?php foreach ($label['from_lines'] as $line): ?><?= e($line) ?><br><?php endforeach; ?>
                    <?php if ($label['from_phone'] !== ''): ?>Ph <?= e($label['from_phone']) ?><?php endif; ?>
                    <?php if ($label['from_gstin'] !== ''): ?><?= $label['from_phone'] !== '' ? ' &middot; ' : '' ?>GSTIN <?= e($label['from_gstin']) ?><?php endif; ?>
                </div>

            </section>
        <?php endforeach; ?>
    </main>
<?php endif; ?>

</body>
</html>
