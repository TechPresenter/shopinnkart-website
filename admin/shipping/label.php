<?php
/**
 * ShopInnKart Admin - Shipping labels, 100 x 150 mm (4 x 6 in thermal).
 *
 *   ?shipment=12            one label
 *   ?shipments=12,13,14     a batch, one label per page, in the order given
 *   ?ids[]=12&ids[]=13      the same, as the shipments list's bulk bar sends it
 *   ?awb=MK019000031        a label by AWB
 *   &provider=mock          narrows an AWB to one courier
 *   &reprint=1              also print shipments already past pickup, marked REPRINT
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
 * parcel anyway, and the courier refuses it at pickup. An RTO-delivered or
 * returned consignment is refused the same way - its AWB is closed.
 *
 * A label is for a parcel still on our shelf. One already past pickup (in
 * transit, delivered ...) is held back unless the operator asks for it with
 * "Reprint anyway" (&reprint=1, behind a confirm), and then it says REPRINT on
 * its face, so a second label for a live parcel is always a decision.
 *
 * Nothing on it is ever cut short. A long address is set in smaller type, the
 * contents list gives up its space first, and if the ship-to still does not
 * fit, the label grows past 150 mm and the screen says it will print across
 * two labels. A clipped address or a return address pushed off the bottom
 * edge is a parcel that cannot be delivered or come back; a two-part label is
 * only untidy.
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
$reprint   = ($_GET['reprint'] ?? '') === '1';

/**
 * The largest type size, from $sizes (points, largest first), at which $lines
 * wrap into no more than $budgetMm of height across the label's 94 mm.
 *
 * An estimate, not a layout: Arial averages a little over half an em per
 * character. It only has to choose well for the common long address; the
 * label grows rather than clips when it is wrong, and the screen says so.
 * Returns [size, index into $sizes].
 */
$fitType = static function (array $lines, array $sizes, float $budgetMm, float $lineHeight): array {
    foreach ($sizes as $i => $pt) {
        $mm      = $pt * 0.3528;
        $perLine = max(1, (int) floor(94 / ($mm * 0.52)));
        $rows    = 0;
        foreach ($lines as $line) {
            $rows += max(1, (int) ceil(mb_strlen($line) / $perLine));
        }
        if ($rows * $mm * $lineHeight <= $budgetMm) {
            return [$pt, $i];
        }
    }
    return [end($sizes), count($sizes) - 1];
};

$labels         = [];
$incompleteFrom = [];   // provider names, once each
$held           = [];   // past pickup, not asked for as a reprint

foreach ($printable as $shipment) {
    $awb    = (string) $shipment['awb'];
    $status = (string) $shipment['status'];

    // Already with the courier or finished: a new label is a replacement at
    // best, and a second live label for one parcel at worst.
    $pastPickup = shipping_status_rank($status) >= shipping_status_rank('in_transit');
    if ($pastPickup && !$reprint) {
        $held[] = '#' . (int) $shipment['id'] . ' (' . strtolower(shipping_status_label($status)) . ')';
        continue;
    }

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
        $fromLines[] = trim((string) $provider['pickup_address']);
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

    // Ship-to type size. The address gets about 34 mm of the label; a longer
    // one is set smaller rather than cut (see the docblock). The name gets two
    // lines' worth at 12 pt. Past the second address step the label also
    // goes dense: shorter barcodes and fewer content lines buy the address room.
    $toName  = trim((string) ($order['shipping_name'] ?: $order['customer_name']));
    $toLines = shipping_print_address_lines($order);
    $toCity  = trim(implode(', ', array_filter([trim((string) $order['shipping_city']), trim((string) $order['shipping_state'])])));
    [, $addrStep] = $fitType(array_merge($toLines, [$toCity]), [9.5, 8.5, 7.5, 6.5], 34.0, 1.25);
    [, $nameStep] = $fitType([$toName], [12, 10.5, 9, 8], 10.5, 1.15);
    $dense = $addrStep >= 2;

    // Items: the lines that fit, then a count. Item names MAY be summarised -
    // the contents list is the part of the label that gives way - but by a
    // plain clip, never str_limit()'s strip_tags(), which ate "Kids toy <3 pack".
    $lines    = $context['items'][(int) $order['id']] ?? [];
    $itemRows = [];
    $units    = 0;
    foreach ($lines as $i => $line) {
        $units += (int) $line['quantity'];
        if ($i < ($dense ? 2 : 4)) {
            $itemRows[] = shipping_print_clip((string) $line['product_name'], 60) . ' × ' . (int) $line['quantity'];
        }
    }

    $dims = '';
    if ((float) $shipment['length_cm'] > 0 && (float) $shipment['width_cm'] > 0 && (float) $shipment['height_cm'] > 0) {
        $cm   = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
        $dims = $cm($shipment['length_cm']) . ' × ' . $cm($shipment['width_cm']) . ' × ' . $cm($shipment['height_cm']) . ' cm';
    }

    $orderNumber = (string) $order['order_number'];

    $courierName = trim((string) $shipment['courier_name']) !== '' ? (string) $shipment['courier_name'] : $provName;

    $labels[] = [
        'id'          => (int) $shipment['id'],
        'awb'         => $awb,
        'awb_svg'     => $awbSvg,
        'courier'     => $courierName,
        'courier_long' => mb_strlen($courierName) > 24,
        'provider'    => $provName,
        'show_via'    => trim((string) $shipment['courier_name']) !== '' && strcasecmp(trim((string) $shipment['courier_name']), $provName) !== 0,
        'reprint'     => $pastPickup ? shipping_status_label($status) : '',
        'dense'       => $dense,
        'is_cod'      => (int) $shipment['is_cod'] === 1,
        'cod_amount'  => (float) $shipment['cod_amount'],
        'to_name'     => $toName,
        'name_step'   => $nameStep,
        'to_lines'    => $toLines,
        'addr_step'   => $addrStep,
        'to_city'     => $toCity,
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

// Held-back reprints are offered, never printed silently: the button carries
// a confirm, and what it prints says REPRINT.
$actions = '';
if ($held !== []) {
    $notes[] = 'Held back, already past pickup: ' . shipping_print_list($held) . '. A label is for a parcel still '
        . 'with us; use Reprint anyway only to replace a damaged label on a parcel the courier already has.';
    $again   = admin_url('shipping/label.php?' . http_build_query(array_merge($_GET, ['reprint' => '1'])));
    $confirm = count($held) === 1
        ? 'Print a replacement label for a consignment that is already past pickup? It will be marked REPRINT.'
        : 'Print replacement labels for ' . count($held) . ' consignments that are already past pickup? They will be marked REPRINT.';
    /* DELIBERATELY the browser's own confirm(), and the only one left in the
       admin. This is a PRINT document: _print.php ships its own toolbar CSS
       and loads neither admin.css nor admin.js, so SIK.admin.confirm() is not
       here to be called and its dialog would have no styles if it were. A
       native confirm that works beats a data-confirm that does nothing. */
    $actions = '<a class="pr-btn" href="' . e($again) . '" onclick="return confirm(' . e_attr((string) json_encode($confirm)) . ')">'
        . icon('printer', 'pr-ico') . '<span>Reprint anyway</span></a>';
}

// A status a script (or a test) can read; the screen says the same in words.
if (!$selection['requested']) {
    http_response_code(400);
} elseif ($selection['shipments'] === []) {
    http_response_code(404);
} elseif ($labels === []) {
    http_response_code(422);
}

// Back: to the order when one shipment was asked for, otherwise the shipments
// list, unless the page that linked here said where it lives. Not the
// Integrations page (shipping/): that needs settings.view, and a label is
// printed by orders roles.
$fallback = count($selection['shipments']) === 1
    ? admin_url('orders/view.php?id=' . (int) ($selection['shipments'][0]['order_id'] ?? 0))
    : admin_url('shipping/shipments.php');
$backUrl  = admin_safe_return(is_string($_GET['return'] ?? null) ? $_GET['return'] : '', $fallback);

$count = count($labels);
$title = $count === 1 ? 'Label ' . $labels[0]['awb'] : ($count . ' shipping labels');

$addrSteps = ['', ' lbl-to__addr--s1', ' lbl-to__addr--s2', ' lbl-to__addr--s3'];
$nameSteps = ['', ' lbl-to__name--s1', ' lbl-to__name--s2', ' lbl-to__name--s3'];
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

        /* ---- the label: 100 x 150 mm, 3 mm inside the edge -------------
           At least 150 mm, never clipped. The contents list takes its
           height from what is left (flex-basis 0), so it is the part that
           gives way; everything else keeps its natural height, and when that
           alone is over 150 mm the label grows (and the screen says so). */
        .lbl-sheet { display: flex; flex-direction: column; align-items: center; gap: 24px; padding: 20px 16px 40px; }
        .lbl { position: relative; width: 100mm; min-height: 150mm; padding: 3mm; display: flex; flex-direction: column;
               background: #fff; color: #000; line-height: 1.2; }
        .lbl > * { flex: 0 0 auto; }
        .lbl > * + * { border-top: .4mm solid #000; }

        .lbl-cap { display: block; font-size: 6.5pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }

        .lbl-head { display: flex; align-items: stretch; gap: 2mm; padding-bottom: 2mm; }
        .lbl-courier { flex: 1 1 auto; min-width: 0; align-self: center; }
        .lbl-courier strong { display: block; font-size: 15pt; line-height: 1.05; overflow-wrap: anywhere; }
        .lbl-courier--long strong { font-size: 11.5pt; }
        .lbl-courier span { display: block; margin-top: .8mm; font-size: 7.5pt; }
        /* Heavy border, not a fill: see the docblock on thermal heads. */
        .lbl-reprint { display: inline-block; margin-top: 1mm; padding: .3mm 1.5mm; border: .5mm solid #000;
                       font-size: 8pt; font-weight: 700; font-style: normal; letter-spacing: .08em; }
        .lbl-pay { flex: 0 0 auto; min-width: 36mm; max-width: 50mm; padding: 1mm 2mm; text-align: center;
                   display: flex; flex-direction: column; justify-content: center; border: .5mm solid #000; }
        .lbl-pay--cod { border-width: 1.2mm; }
        .lbl-pay b { font-size: 11pt; letter-spacing: .1em; }
        .lbl-pay strong { font-size: 16pt; line-height: 1.1; white-space: nowrap; }
        .lbl-pay small { font-size: 6.5pt; }

        .lbl-awb { padding: 1.5mm 0; }
        .lbl-awb .sik-barcode { display: block; width: 100%; height: auto; max-height: 27mm; margin: 0 auto; }
        /* Still well above the 15%-of-width height scanners want. */
        .lbl--dense .lbl-awb .sik-barcode { max-height: 20mm; }

        .lbl-to { padding: 1.8mm 0; }
        .lbl-to__name { margin-top: .6mm; font-size: 12pt; line-height: 1.15; font-weight: 700; overflow-wrap: anywhere; }
        .lbl-to__name--s1 { font-size: 10.5pt; }
        .lbl-to__name--s2 { font-size: 9pt; }
        .lbl-to__name--s3 { font-size: 8pt; }
        .lbl-to__addr { margin-top: .6mm; font-size: 9.5pt; line-height: 1.25; overflow-wrap: anywhere; }
        .lbl-to__addr--s1 { font-size: 8.5pt; }
        .lbl-to__addr--s2 { font-size: 7.5pt; }
        .lbl-to__addr--s3 { font-size: 6.5pt; }
        .lbl-to__pin { display: flex; align-items: baseline; justify-content: space-between; gap: 2mm; margin-top: .8mm; }
        .lbl-to__pin strong { font-size: 22pt; letter-spacing: .05em; line-height: 1; }
        .lbl-to__pin span { font-size: 10pt; font-weight: 700; white-space: nowrap; }

        .lbl-order { display: flex; align-items: center; gap: 2.5mm; padding: 1.5mm 0; }
        .lbl-order__code { flex: 0 0 50mm; min-width: 0; }
        .lbl-order__code .sik-barcode { display: block; width: 100%; height: auto; max-height: 13mm; }
        .lbl--dense .lbl-order__code .sik-barcode { max-height: 10mm; }
        .lbl-order__meta { flex: 1 1 auto; min-width: 0; font-size: 8pt; line-height: 1.35; }
        .lbl-order__meta b { font-weight: 700; }

        /* Basis 0 with a floor of its caption: the list is sized from the room
           left over, so it never adds height of its own to the label. */
        .lbl > .lbl-items { flex: 1 1 0; min-height: 6mm; overflow: hidden; padding: 1.5mm 0; font-size: 8pt; }
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
    $count > 0,
    $actions
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
            <section class="lbl<?= $label['dense'] ? ' lbl--dense' : '' ?>" data-awb="<?= e_attr($label['awb']) ?>"
                     aria-label="Shipping label, AWB <?= e_attr($label['awb']) ?>">

                <div class="lbl-head">
                    <div class="lbl-courier<?= $label['courier_long'] ? ' lbl-courier--long' : '' ?>">
                        <strong><?= e($label['courier']) ?></strong>
                        <?php if ($label['show_via']): ?><span>via <?= e($label['provider']) ?></span><?php endif; ?>
                        <?php if ($label['reprint'] !== ''): ?>
                            <em class="lbl-reprint">REPRINT &middot; <?= e($label['reprint']) ?></em>
                        <?php endif; ?>
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
                    <div class="lbl-to__name<?= $nameSteps[$label['name_step']] ?? '' ?>"><?= e($label['to_name']) ?></div>
                    <div class="lbl-to__addr<?= $addrSteps[$label['addr_step']] ?? '' ?>">
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

    <script>
    // A label taller than its 150 mm stock prints across two. The layout grows
    // rather than clip an address, so say so here, before the print job does.
    // The yardstick is a 150 mm box inside each label, so the small-screen zoom
    // applies to both sides of the comparison.
    (function () {
        var long = [];
        document.querySelectorAll('.lbl').forEach(function (label) {
            var stock = document.createElement('div');
            stock.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:150mm;visibility:hidden';
            label.appendChild(stock);
            if (label.offsetHeight > stock.offsetHeight + 1) {
                long.push(label.getAttribute('data-awb'));
            }
            label.removeChild(stock);
        });
        var bar = document.querySelector('.pr-bar');
        if (!long.length || !bar) {
            return;
        }
        var box = bar.querySelector('.pr-notes');
        if (!box) {
            box = document.createElement('div');
            box.className = 'pr-notes';
            box.setAttribute('role', 'status');
            box.appendChild(document.createElement('strong'));
            box.appendChild(document.createElement('ul'));
            bar.appendChild(box);
        }
        var list = box.querySelector('ul');
        long.forEach(function (awb) {
            var item = document.createElement('li');
            item.textContent = 'AWB ' + awb + ': the address is too long for one 100 x 150 mm label, so it will print '
                + 'across two labels. Nothing is cut off; stick both on the parcel.';
            list.appendChild(item);
        });
        var n = list.children.length;
        box.querySelector('strong').textContent = n === 1
            ? '1 thing was left out or needs a look:'
            : n + ' things were left out or need a look:';
    }());
    </script>
<?php endif; ?>

</body>
</html>
