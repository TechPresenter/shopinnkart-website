<?php
/**
 * ShopInnKart - Code 128 barcodes as inline SVG.
 *
 * Shipping labels need a barcode the courier's handheld can read, and a
 * library for that would be the only Composer dependency in the project. Code
 * 128 is small enough to do by hand: 107 fixed patterns and a weighted sum.
 *
 * Why Code 128 and not QR: every courier scanner in India reads 1D Code 128,
 * and it is what Shiprocket, Delhivery and the rest print for the AWB.
 *
 * Only code sets B and C are used. B covers printable ASCII (32-126), which is
 * everything an AWB or order number contains; A exists for control characters,
 * which never appear on a label and are rejected instead. C packs two digits
 * into one symbol, which is what keeps a long numeric AWB short enough to scan
 * at label width - so the encoder chooses between B and C per position to
 * produce the fewest symbols, rather than following a rule of thumb.
 *
 * The SVG is drawn in module units and scales with CSS. Bars are pure black
 * rects with crispEdges and there is no anti-aliased grey anywhere: a thermal
 * printer has no grey, and a half-tone edge thresholds unpredictably into a
 * bar that is a dot too wide.
 */

declare(strict_types=1);

/**
 * Bar/space widths for symbol values 0-106, in modules, bar first.
 * 103-105 are Start A/B/C; 106 is the stop pattern, the only one of 13 modules.
 */
const BARCODE_C128_PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const BARCODE_C128_START_B = 104;
const BARCODE_C128_START_C = 105;
const BARCODE_C128_TO_B    = 100;   // "Code B" when read in set C
const BARCODE_C128_TO_C    = 99;    // "Code C" when read in set B
const BARCODE_C128_STOP    = 106;

/**
 * The symbol values for $data: start, data (with any set switches), checksum.
 * The stop pattern is not included; it has no value in the checksum.
 *
 * Returns null for empty input or any character outside ASCII 32-126.
 *
 * Exposed separately from the SVG so the checksum can be checked by hand.
 *
 * @return int[]|null
 */
function barcode_code128_values(string $data): ?array
{
    $n = strlen($data);
    if ($n === 0 || preg_match('/^[\x20-\x7E]+$/', $data) !== 1) {
        return null;
    }

    // Fewest symbols, by dynamic programming from the end of the string.
    //   $cost[$i][$set]  symbols needed for data[$i..] when already in $set
    // At each position the encoder either writes in the current set or pays one
    // symbol to switch first. Set C can only write a pair of digits, so a lone
    // or odd trailing digit forces B. Ties keep the current set: a switch that
    // saves nothing is just a longer barcode.
    $isPair = static fn (int $i): bool => $i + 1 < $n && ctype_digit($data[$i] . $data[$i + 1]);

    $cost   = [$n => ['B' => 0, 'C' => 0]];
    $choice = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $stayB = 1 + $cost[$i + 1]['B'];
        $stayC = $isPair($i) ? 1 + $cost[$i + 2]['C'] : PHP_INT_MAX;

        foreach (['B' => [$stayB, $stayC, 'C'], 'C' => [$stayC, $stayB, 'B']] as $set => [$stay, $other, $otherSet]) {
            $switch = $other === PHP_INT_MAX ? PHP_INT_MAX : 1 + $other;
            if ($stay <= $switch) {
                $cost[$i][$set]   = $stay;
                $choice[$i][$set] = $set;
            } else {
                $cost[$i][$set]   = $switch;
                $choice[$i][$set] = $otherSet;
            }
        }
    }

    // The start code picks the first set for free, so start in whichever set
    // is cheaper from position 0. B wins a tie.
    $set    = $cost[0]['C'] < $cost[0]['B'] && $isPair(0) ? 'C' : 'B';
    $values = [$set === 'C' ? BARCODE_C128_START_C : BARCODE_C128_START_B];

    for ($i = 0; $i < $n;) {
        $want = $choice[$i][$set];
        if ($want !== $set) {
            $values[] = $want === 'C' ? BARCODE_C128_TO_C : BARCODE_C128_TO_B;
            $set      = $want;
        }
        if ($set === 'C') {
            $values[] = (int) substr($data, $i, 2);
            $i += 2;
        } else {
            $values[] = ord($data[$i]) - 32;
            $i++;
        }
    }

    // Modulo-103: the start value counts once, each following symbol is
    // weighted by its position (1, 2, 3 ...), switch codes included.
    $sum = $values[0];
    foreach (array_slice($values, 1) as $position => $value) {
        $sum += ($position + 1) * $value;
    }
    $values[] = $sum % 103;

    return $values;
}

/**
 * A Code 128 barcode for $data as a standalone <svg>, human-readable text
 * underneath, 10-module quiet zone either side.
 *
 * $height is the bar height and $module the narrowest bar, both in SVG user
 * units. The element carries a viewBox, so CSS can size it to the label and
 * everything - bars and text - scales together.
 *
 * Returns '' when $data cannot be encoded (empty, or outside ASCII 32-126), so
 * the caller can print "no barcode" rather than an image of the wrong thing.
 */
function barcode_code128_svg(string $data, int $height = 60, float $module = 2.0): string
{
    $values = barcode_code128_values($data);
    if ($values === null || $height <= 0 || $module <= 0) {
        return '';
    }
    $values[] = BARCODE_C128_STOP;

    $quiet = 10;
    $fmt   = static fn (float $v): string => rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');

    $rects = '';
    $x     = $quiet;   // in modules
    foreach ($values as $value) {
        foreach (str_split(BARCODE_C128_PATTERNS[$value]) as $k => $width) {
            $width = (int) $width;
            if ($k % 2 === 0) {   // even positions are bars, odd are spaces
                $rects .= '<rect x="' . $fmt($x * $module) . '" y="0" width="' . $fmt($width * $module)
                    . '" height="' . $height . '"/>';
            }
            $x += $width;
        }
    }
    $totalModules = $x + $quiet;

    // Text size follows the module, which keeps it proportionate to the bars
    // and always narrower than them: a set-B symbol is 11 modules wide, a
    // monospace glyph at 8 modules is about 5.
    $fontSize = $module * 8;
    $gap      = $module * 2;
    $width    = $totalModules * $module;
    $total    = $height + $gap + $fontSize * 1.15;
    $label    = htmlspecialchars($data, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" class="sik-barcode" role="img"'
        . ' aria-label="Barcode ' . $label . '"'
        . ' width="' . $fmt($width) . '" height="' . $fmt($total) . '"'
        . ' viewBox="0 0 ' . $fmt($width) . ' ' . $fmt($total) . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<g fill="#000">' . $rects . '</g>'
        . '<text x="' . $fmt($width / 2) . '" y="' . $fmt($height + $gap + $fontSize * 0.9) . '"'
        . ' text-anchor="middle" fill="#000" font-size="' . $fmt($fontSize) . '"'
        . ' font-family="Consolas, \'DejaVu Sans Mono\', \'Courier New\', monospace" font-weight="700">'
        . $label . '</text>'
        . '</svg>';
}
