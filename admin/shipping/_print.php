<?php
/**
 * ShopInnKart Admin - Shared plumbing for the printable shipping documents.
 *
 * label.php and manifest.php accept the same selections (shipment ids, AWBs),
 * refuse the same shipments and show the same screen-only toolbar. It lives
 * here once so the two pages can't disagree about which shipments a URL
 * means, or about which of them are fit to print.
 *
 * Nothing here writes. Both pages are GETs that print what the shipment rows
 * already say. Any change of state goes through shipping-service.php.
 *
 * Reads are batched (one IN query per table) rather than looped through
 * shipment_get(): a manifest of 100 parcels would otherwise be 300 queries.
 */

declare(strict_types=1);

// Include-only: the parent page already ran the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * The most shipments one document prints. A day's pickup rarely goes past
 * this, and a bigger batch means a slow page and a long print job that jams
 * halfway through.
 */
const SHIPPING_PRINT_MAX = 100;

/**
 * A query-string list split on commas or whitespace. An array value (?x[]=) is
 * refused, not coerced - except under `ids`, which is how the shipments list's
 * bulk bar sends its selection (admin.js adds one ids[] per ticked row). Even
 * there only a flat list of strings is read; anything nested is refused.
 */
function shipping_print_tokens(string $key, array &$notes): array
{
    $raw = $_GET[$key] ?? '';
    if ($key === 'ids' && is_array($raw)) {
        $flat = array_filter($raw, 'is_string');
        if (count($flat) !== count($raw)) {
            $notes[] = 'Ignored part of "ids": each entry must be one shipment number.';
        }
        $raw = implode(',', $flat);
    }
    if (!is_string($raw)) {
        $notes[] = 'Ignored "' . $key . '": give it as a comma-separated list.';
        return [];
    }
    return preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** "a, b, c and 4 more" - a skipped list stays one readable line however long it is. */
function shipping_print_list(array $items, int $show = 8): string
{
    $items = array_values($items);
    $head  = implode(', ', array_slice($items, 0, $show));
    return count($items) > $show ? $head . ' and ' . (count($items) - $show) . ' more' : $head;
}

/**
 * Resolve ?shipment= / ?shipments= / ?ids[]= / ?awb= (and optional &provider=)
 * into shipment rows, in the order they were asked for, without duplicates.
 *
 * Everything that could not be resolved is described in `notes`, which the
 * page shows on screen only. A junk token never stops the rest printing: an
 * operator who pasted 40 ids with one typo wants 39 labels and a note, not an
 * error page.
 *
 * AWB lookups are case-insensitive (the column's collation) and, like
 * shipment_find(), resolve to the newest row. They are NOT scoped to a courier
 * unless &provider= is given, because the mock courier links its labels by AWB
 * alone. So an AWB that two couriers have both issued is refused as ambiguous
 * instead of guessed at: the wrong label on a parcel is worse than none.
 *
 * @return array{requested: bool, shipments: array<int, array>, notes: string[]}
 */
function shipping_print_selection(): array
{
    $notes = [];

    $ids      = [];   // id => true, insertion order = request order
    $junkIds  = [];
    foreach (['shipment', 'shipments', 'ids'] as $key) {
        foreach (shipping_print_tokens($key, $notes) as $token) {
            // shipments.id is INT UNSIGNED; anything outside it cannot exist.
            if (ctype_digit($token) && strlen($token) <= 10 && (int) $token > 0 && (int) $token <= 4294967295) {
                $ids[(int) $token] = true;
            } else {
                $junkIds[] = $token;
            }
        }
    }

    $awbs     = [];   // UPPERCASE awb => as typed
    $junkAwbs = [];
    foreach (shipping_print_tokens('awb', $notes) as $token) {
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,63}$~', $token) === 1) {
            $awbs[strtoupper($token)] ??= $token;
        } else {
            $junkAwbs[] = $token;
        }
    }

    $requested = $ids !== [] || $awbs !== [] || $junkIds !== [] || $junkAwbs !== [];

    // Shown truncated and escaped at render; a pasted token can be anything.
    $clip = static fn (string $t): string => '"' . (mb_strlen($t) > 24 ? mb_substr($t, 0, 24) . '…' : $t) . '"';
    if ($junkIds !== []) {
        $notes[] = 'Not a shipment number: ' . shipping_print_list(array_map($clip, $junkIds)) . '.';
    }
    if ($junkAwbs !== []) {
        $notes[] = 'Not an AWB: ' . shipping_print_list(array_map($clip, $junkAwbs)) . '.';
    }

    $total = count($ids) + count($awbs);
    if ($total > SHIPPING_PRINT_MAX) {
        $ids  = array_slice($ids, 0, SHIPPING_PRINT_MAX, true);
        $awbs = array_slice($awbs, 0, max(0, SHIPPING_PRINT_MAX - count($ids)), true);
        $notes[] = 'Only the first ' . SHIPPING_PRINT_MAX . ' are included here; ' . ($total - SHIPPING_PRINT_MAX)
            . ' more were left off. Print them as a second batch.';
    }

    $provider = trim((string) (is_string($_GET['provider'] ?? null) ? $_GET['provider'] : ''));
    if ($provider !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $provider) !== 1) {
        $notes[] = 'Ignored the courier filter: it is not a courier code.';
        $provider = '';
    }

    $selected = [];

    if ($ids !== []) {
        [$in, $params] = Database::inPlaceholders(array_keys($ids), 'id');
        $found = [];
        foreach (Database::fetchAll("SELECT * FROM `shipments` WHERE `id` IN ($in)", $params) as $row) {
            $found[(int) $row['id']] = $row;
        }
        $missing = [];
        foreach (array_keys($ids) as $id) {
            if (isset($found[$id])) {
                $selected[$id] = $found[$id];
            } else {
                $missing[] = '#' . $id;
            }
        }
        if ($missing !== []) {
            $notes[] = 'No such shipment: ' . shipping_print_list($missing) . '.';
        }
    }

    if ($awbs !== []) {
        [$in, $params] = Database::inPlaceholders(array_values($awbs), 'awb');
        $sql = "SELECT * FROM `shipments` WHERE `awb` IN ($in)";
        if ($provider !== '') {
            $sql .= ' AND `provider_code` = :provider';
            $params['provider'] = $provider;
        }
        $byAwb = [];
        foreach (Database::fetchAll($sql . ' ORDER BY `id` DESC', $params) as $row) {
            $byAwb[strtoupper((string) $row['awb'])][] = $row;
        }

        $missing = [];
        foreach ($awbs as $upper => $typed) {
            $rows = $byAwb[$upper] ?? [];
            if ($rows === []) {
                $missing[] = $typed;
                continue;
            }
            if (count(array_unique(array_column($rows, 'provider_code'))) > 1) {
                $which = array_map(static fn (array $r): string => '#' . (int) $r['id'] . ' ' . $r['provider_code'], $rows);
                $notes[] = 'AWB ' . $typed . ' was issued by more than one courier (' . implode(', ', $which)
                    . '). Open it by shipment number, or add &provider= to the link.';
                continue;
            }
            $row = $rows[0];   // newest, the same row shipment_find() returns
            $selected[(int) $row['id']] ??= $row;
        }
        if ($missing !== []) {
            $notes[] = 'No shipment with AWB ' . shipping_print_list($missing)
                . ($provider !== '' ? ' at courier "' . $provider . '"' : '') . '.';
        }
    }

    return ['requested' => $requested, 'shipments' => array_values($selected), 'notes' => $notes];
}

/**
 * The orders, order lines and courier rows the documents print from.
 *
 * @return array{orders: array<int, array>, items: array<int, array[]>, providers: array<string, array>}
 */
function shipping_print_context(array $shipments): array
{
    $orders = [];
    $items  = [];

    $orderIds = array_values(array_unique(array_map(static fn (array $s): int => (int) $s['order_id'], $shipments)));
    if ($orderIds !== []) {
        [$in, $params] = Database::inPlaceholders($orderIds, 'o');
        foreach (Database::fetchAll("SELECT * FROM `orders` WHERE `id` IN ($in)", $params) as $row) {
            $orders[(int) $row['id']] = $row;
        }
        $lines = Database::fetchAll(
            "SELECT `order_id`, `product_name`, `quantity` FROM `order_items` WHERE `order_id` IN ($in) ORDER BY `id`",
            $params
        );
        foreach ($lines as $row) {
            $items[(int) $row['order_id']][] = $row;
        }
    }

    // Keyed both ways: a shipment carries provider_id, but that column is
    // nullable, and provider_code is what every other lookup uses.
    $providers = [];
    foreach (shipping_providers_all() as $row) {
        $providers['id:' . (int) $row['id']]  = $row;
        $providers['code:' . $row['code']]    = $row;
    }

    return ['orders' => $orders, 'items' => $items, 'providers' => $providers];
}

/** The courier row a shipment was booked with, or null when it has since been deleted. */
function shipping_print_provider(array $ctx, array $shipment): ?array
{
    return $ctx['providers']['id:' . (int) ($shipment['provider_id'] ?? 0)]
        ?? $ctx['providers']['code:' . (string) $shipment['provider_code']]
        ?? null;
}

/**
 * Why a shipment cannot go on a label or a manifest, or null when it can.
 *
 * Both documents are stuck to or handed over with a real parcel, so each
 * refusal here is a parcel that would otherwise leave on a consignment the
 * courier does not have.
 */
function shipping_print_refusal(array $shipment, array $ctx): ?string
{
    $ref    = '#' . (int) $shipment['id'];
    $status = (string) $shipment['status'];
    $awb    = (string) ($shipment['awb'] ?? '');

    if ($status === 'failed_booking') {
        return $ref . ': the booking failed, so there is no consignment to print.';
    }
    if ($awb === '') {
        return $ref . ': no AWB yet (' . strtolower(shipping_status_label($status)) . '). '
            . 'Request the AWB on the shipment first - the courier will not accept a parcel without one.';
    }
    if ($status === 'cancelled') {
        return $ref . ': cancelled, so AWB ' . $awb . ' is void. A parcel sent out on it would go nowhere.';
    }
    // Finished the other way: the parcel came back (RTO) or was returned, and
    // the courier has closed AWB. A fresh label - COD box and all - would put a
    // closed consignment back on a parcel.
    if (in_array($status, ['rto_delivered', 'returned'], true)) {
        return $ref . ': ' . strtolower(shipping_status_label($status)) . ', so AWB ' . $awb
            . ' is closed at the courier. Book a new shipment if the parcel goes out again.';
    }
    if (!isset($ctx['orders'][(int) $shipment['order_id']])) {
        return $ref . ': its order #' . (int) $shipment['order_id'] . ' no longer exists.';
    }
    return null;
}

/** Keep the shipments that can be printed; describe the rest in $notes. */
function shipping_print_printable(array $shipments, array $ctx, array &$notes): array
{
    $keep = [];
    foreach ($shipments as $shipment) {
        $why = shipping_print_refusal($shipment, $ctx);
        if ($why === null) {
            $keep[] = $shipment;
        } else {
            $notes[] = $why;
        }
    }
    return $keep;
}

/**
 * An amount as "Rs 1,24,999".
 *
 * "Rs" rather than the rupee sign: some label-printer drivers substitute
 * their own device font, which has no U+20B9, and the one figure a delivery
 * rider must read correctly would print with a box in front of it.
 */
function shipping_print_rs(float $amount): string
{
    return 'Rs ' . money($amount, false);
}

/** Grams as "0.8 kg", or '' when unknown. */
function shipping_print_kg($grams): string
{
    if ($grams === null || (int) $grams <= 0) {
        return '';
    }
    return rtrim(rtrim(number_format((int) $grams / 1000, 2, '.', ''), '0'), '.') . ' kg';
}

/**
 * Ship-to address lines, whole.
 *
 * Never clipped: the tail of an address is usually the locality, and a rider
 * cannot deliver to "Gunjur Village…". A long address is the label's problem
 * to fit (label.php sets the type smaller, then lets the label grow), not the
 * address's problem to lose. And never passed through str_limit(), whose
 * strip_tags() deletes text after any "<" - "House 5<6 Main Road" printed as
 * "House 5". Output is escaped with e() where it is printed.
 *
 * The landmark is printed as the customer wrote it, behind a neutral
 * "Landmark:". Customers type their own preposition, and a forced "Near "
 * turned "Opposite Jyoti Nivas College" into directions that contradict
 * themselves.
 */
function shipping_print_address_lines(array $order): array
{
    $lines = [];
    foreach (['shipping_address', 'shipping_address2'] as $key) {
        $line = trim((string) ($order[$key] ?? ''));
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $landmark = trim((string) ($order['shipping_landmark'] ?? ''));
    if ($landmark !== '') {
        $lines[] = 'Landmark: ' . $landmark;
    }
    return $lines;
}

/**
 * $text cut to $width characters with an ellipsis, for text that may be
 * summarised (item names) - never an address. Plain multibyte clipping: no
 * strip_tags(), which deletes real text after a "<".
 */
function shipping_print_clip(string $text, int $width): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > $width ? rtrim(mb_substr($text, 0, $width - 1)) . '…' : $text;
}

/**
 * The toolbar and skipped-shipment notes. Screen only: the print stylesheet
 * hides `.pr-screen`, so nothing here reaches the label stock.
 *
 * $actions is extra, already-escaped button markup placed before Print (the
 * label's "Reprint anyway").
 */
function shipping_print_toolbar(string $title, string $hint, array $notes, string $backUrl, bool $canPrint, string $actions = ''): string
{
    $html = '<div class="pr-screen pr-bar">'
        . '<div class="pr-bar__row">'
        . '<a class="pr-btn" href="' . e($backUrl) . '">' . icon('arrow-left', 'pr-ico') . '<span>Back</span></a>'
        . '<div class="pr-bar__title"><strong>' . e($title) . '</strong><span>' . e($hint) . '</span></div>'
        . $actions
        . '<button type="button" class="pr-btn pr-btn--primary" onclick="window.print()"'
        . ($canPrint ? '' : ' disabled') . '>' . icon('printer', 'pr-ico') . '<span>Print</span></button>'
        . '</div>';

    if ($notes !== []) {
        $html .= '<div class="pr-notes" role="status"><strong>'
            . e(count($notes) === 1 ? '1 thing was left out or needs a look:' : count($notes) . ' things were left out or need a look:')
            . '</strong><ul>';
        foreach ($notes as $note) {
            $html .= '<li>' . e($note) . '</li>';
        }
        $html .= '</ul></div>';
    }

    return $html . '</div>';
}

/** Screen styles for the toolbar, shared by both documents. */
function shipping_print_toolbar_css(): string
{
    return <<<'CSS'
        .pr-bar { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid #d1d5db;
                  padding: 10px 16px; font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Arial, sans-serif; }
        .pr-bar__row { display: flex; align-items: center; gap: 12px; max-width: 960px; margin: 0 auto; }
        .pr-bar__title { flex: 1 1 auto; min-width: 0; }
        .pr-bar__title strong { display: block; font-size: 15px; }
        .pr-bar__title span { display: block; color: #4b5563; font-size: 12.5px; }
        .pr-btn { display: inline-flex; align-items: center; gap: 6px; flex: none; min-height: 40px; padding: 0 14px;
                  border: 1px solid #9ca3af; border-radius: 8px; background: #fff; color: #111827; font: inherit;
                  font-weight: 600; text-decoration: none; cursor: pointer; }
        .pr-btn--primary { background: #111827; border-color: #111827; color: #fff; }
        .pr-btn[disabled] { opacity: .45; cursor: not-allowed; }
        .pr-ico { width: 16px; height: 16px; flex: none; }
        .pr-notes { max-width: 960px; margin: 10px auto 0; padding: 10px 12px; border: 1px solid #f59e0b;
                    border-radius: 8px; background: #fffbeb; color: #78350f; font-size: 13px; }
        .pr-notes ul { margin: 6px 0 0; padding-left: 18px; }
        .pr-notes li { overflow-wrap: anywhere; }
        .pr-empty { max-width: 560px; margin: 40px auto; padding: 0 16px; text-align: center;
                    font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Arial, sans-serif; color: #374151; }
        .pr-empty h1 { font-size: 18px; margin: 0 0 6px; color: #111827; }
        @media screen and (max-width: 480px) {
            .pr-bar__title span { display: none; }
            .pr-btn { padding: 0 10px; }
        }
        @media print { .pr-screen { display: none !important; } }
        CSS;
}
