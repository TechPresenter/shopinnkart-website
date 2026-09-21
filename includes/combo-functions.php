<?php
/**
 * ShopInnKart - Combo offers (bundles).
 *
 * A combo is a curated set of products sold together at a set price. It is NOT
 * a second kind of product: the basket holds the component lines it already
 * knows how to hold, tagged with `combo_id` and a `combo_group`, and renders a
 * group as one card. Stock, tax, variants, fulfilment and returns therefore
 * keep working through the paths that already handle them.
 *
 * The honest-saving rule, which shapes combo_pricing():
 *
 *   The struck-through number is what the same items cost TODAY bought
 *   separately, not their combined MRP. Nine of this catalogue's eleven
 *   products are already discounted, so comparing a combo against MRP would
 *   advertise a saving the shopper could get anyway by adding the items one
 *   at a time. Both numbers are returned; the UI shows `regular`.
 */

declare(strict_types=1);

/** The predicate for "a shopper may see this combo right now". */
function combo_live_sql(string $alias = 'c'): string
{
    return "{$alias}.`status` = 'active'
            AND ({$alias}.`start_date` IS NULL OR {$alias}.`start_date` <= NOW())
            AND ({$alias}.`end_date`   IS NULL OR {$alias}.`end_date`   >= NOW())";
}

function combo_url(string $slug): string
{
    return url('combo/' . rawurlencode($slug));
}

/**
 * One combo by id or slug. Returns null for anything a shopper may not see,
 * unless $anyStatus - which only the admin passes.
 */
function combo_find($idOrSlug, bool $anyStatus = false): ?array
{
    $where = is_numeric($idOrSlug) ? 'c.`id` = :key' : 'c.`slug` = :key';
    if (!$anyStatus) {
        $where .= ' AND ' . combo_live_sql();
    }

    return Database::fetch(
        "SELECT c.* FROM `combos` c WHERE {$where} LIMIT 1",
        ['key' => $idOrSlug]
    );
}

/**
 * The components of a combo, each decorated with its live product data.
 *
 * A component whose product has since been deleted or unpublished is dropped
 * rather than rendered as a gap - and combo_pricing() then prices what is
 * actually left, so a half-missing combo can never be sold at the full set's
 * price.
 *
 * @return array<int, array<string, mixed>>
 */
function combo_items(int $comboId): array
{
    $rows = Database::fetchAll(
        "SELECT ci.*, p.`name`, p.`slug`, p.`price`, p.`sale_price`, p.`stock`,
                p.`status`, p.`main_image`
           FROM `combo_items` ci
           INNER JOIN `products` p ON p.`id` = ci.`product_id`
          WHERE ci.`combo_id` = :id AND p.`status` = 'active'
          ORDER BY ci.`sort_order`, ci.`id`",
        ['id' => $comboId]
    );

    foreach ($rows as &$row) {
        $pricing = product_effective_price($row);
        $row['quantity']    = max(1, (int) $row['quantity']);
        $row['unit_mrp']    = (float) $pricing['mrp'];
        $row['unit_price']  = (float) $pricing['price'];
        $row['line_mrp']    = $row['unit_mrp'] * $row['quantity'];
        $row['line_price']  = $row['unit_price'] * $row['quantity'];
        $row['url']         = product_url((string) $row['slug']);
        $row['image_url']   = img_url($row['main_image'] ?? null);
    }

    return $rows;
}

/**
 * What a combo costs and what it saves.
 *
 * `mrp`     sum of component MRP - the manufacturer's number
 * `regular` sum of what those components cost TODAY, bought separately
 * `price`   what the set costs
 * `saving`  regular - price, floored at zero
 *
 * A combo priced above `regular` saves nothing; `saving` reports 0 rather than
 * a negative, and combo_is_sellable() refuses to advertise it.
 *
 * @param array<int, array<string, mixed>>|null $items pass to avoid a re-query
 */
function combo_pricing(array $combo, ?array $items = null): array
{
    $items ??= combo_items((int) $combo['id']);

    $mrp     = 0.0;
    $regular = 0.0;
    foreach ($items as $item) {
        $mrp     += (float) $item['line_mrp'];
        $regular += (float) $item['line_price'];
    }

    // Percentage mode derives the price from what the items cost today, so a
    // "20% off the set" combo stays 20% off as the catalogue's own prices move.
    $price = (string) $combo['pricing_mode'] === 'percentage'
        ? $regular * (1 - max(0.0, min(100.0, (float) $combo['discount_percent'])) / 100)
        : (float) $combo['price'];

    $price  = money_round(max(0.0, $price));
    $saving = money_round(max(0.0, $regular - $price));

    return [
        'mrp'      => money_round($mrp),
        'regular'  => money_round($regular),
        'price'    => $price,
        'saving'   => $saving,
        'percent'  => $regular > 0 ? (int) round($saving / $regular * 100) : 0,
        'items'    => $items,
    ];
}

/**
 * How many sets can be sold right now.
 *
 * The component with the least headroom decides, because a set cannot ship
 * without all of it. `stock_mode = track` puts a second, lower ceiling on top
 * for a limited run.
 */
function combo_available_sets(array $combo, ?array $items = null): int
{
    $items ??= combo_items((int) $combo['id']);
    if ($items === []) {
        return 0;
    }

    $sets = PHP_INT_MAX;
    foreach ($items as $item) {
        $sets = min($sets, intdiv(max(0, (int) $item['stock']), max(1, (int) $item['quantity'])));
    }

    if ((string) $combo['stock_mode'] === 'track' && $combo['stock'] !== null) {
        $sets = min($sets, max(0, (int) $combo['stock'] - (int) $combo['sold_count']));
    }

    return max(0, $sets === PHP_INT_MAX ? 0 : $sets);
}

/**
 * Is this combo fit to show and sell?
 *
 * Every reason to say no is a reason a shopper would otherwise hit at the
 * basket instead: nothing in it, nothing left, or it saves nothing.
 */
function combo_is_sellable(array $combo, ?array $items = null): bool
{
    $items ??= combo_items((int) $combo['id']);
    if (count($items) < 2) {
        return false;
    }
    if (combo_available_sets($combo, $items) < 1) {
        return false;
    }

    return combo_pricing($combo, $items)['saving'] > 0;
}

/**
 * Live combos, newest-priority first.
 *
 * `$filters`: featured, flash, best, limit, product_id (combos containing it),
 * exclude (combo id).
 *
 * Unsellable combos are filtered out AFTER the query rather than in SQL,
 * because "sellable" depends on component stock and on pricing that is
 * computed, not stored.
 */
function combo_list(array $filters = []): array
{
    $where  = [combo_live_sql()];
    $params = [];

    foreach (['featured' => 'is_featured', 'flash' => 'is_flash', 'best' => 'is_best_seller'] as $key => $column) {
        if (!empty($filters[$key])) {
            $where[] = "c.`{$column}` = 1";
        }
    }

    if (!empty($filters['product_id'])) {
        $where[] = 'c.`id` IN (SELECT `combo_id` FROM `combo_items` WHERE `product_id` = :pid)';
        $params['pid'] = (int) $filters['product_id'];
    }

    if (!empty($filters['exclude'])) {
        $where[] = 'c.`id` <> :ex';
        $params['ex'] = (int) $filters['exclude'];
    }

    $rows = Database::fetchAll(
        'SELECT c.* FROM `combos` c WHERE ' . implode(' AND ', $where)
        . ' ORDER BY c.`sort_order`, c.`id` DESC',
        $params
    );

    $limit = max(1, (int) ($filters['limit'] ?? 12));
    $out   = [];
    foreach ($rows as $row) {
        $items = combo_items((int) $row['id']);
        if (!combo_is_sellable($row, $items)) {
            continue;
        }
        $out[] = combo_decorate($row, $items);
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** A combo with everything a template needs, so no view does arithmetic. */
function combo_decorate(array $combo, ?array $items = null): array
{
    $items   ??= combo_items((int) $combo['id']);
    $pricing   = combo_pricing($combo, $items);
    $available = combo_available_sets($combo, $items);

    $combo['items']          = $items;
    $combo['item_count']     = count($items);
    $combo['unit_count']     = array_sum(array_map(static fn ($i) => (int) $i['quantity'], $items));
    $combo['pricing']        = $pricing;
    $combo['price_display']  = money($pricing['price']);
    $combo['regular_display']= money($pricing['regular']);
    $combo['saving_display'] = money($pricing['saving']);
    $combo['percent']        = $pricing['percent'];
    $combo['available']      = $available;
    $combo['url']            = combo_url((string) $combo['slug']);
    $combo['image_url']      = img_url($combo['image'] ?? null);
    $combo['badge']          = combo_badge($combo);

    // "Only 3 sets left" is worth saying; "412 left" is noise.
    $combo['low_stock'] = $available > 0 && $available <= 5;

    return $combo;
}

/** The one badge a combo card shows, or null. */
function combo_badge(array $combo): ?array
{
    $custom = trim((string) ($combo['badge_text'] ?? ''));
    if ($custom !== '') {
        return ['label' => $custom, 'tone' => 'soft'];
    }
    if (!empty($combo['is_flash'])) {
        return ['label' => 'Flash deal', 'tone' => 'red'];
    }
    if (!empty($combo['is_best_seller'])) {
        return ['label' => 'Best seller', 'tone' => 'amber'];
    }
    if (!empty($combo['is_featured'])) {
        return ['label' => 'Featured', 'tone' => 'soft'];
    }

    return null;
}

/**
 * Recompute and store a combo's MRP, and its price when it is in percentage
 * mode.
 *
 * Called after every admin save and whenever a component's price could have
 * moved. The stored numbers are a cache of a computation, never a source of
 * truth - which is why nothing reads `combos.mrp` without this having run.
 */
function combo_recalculate(int $comboId): void
{
    $combo = combo_find($comboId, true);
    if ($combo === null) {
        return;
    }

    $pricing = combo_pricing($combo, combo_items($comboId));

    Database::update(
        'combos',
        [
            'mrp'   => $pricing['mrp'],
            'price' => $pricing['price'],
        ],
        '`id` = :id',
        ['id' => $comboId]
    );
}

/** A unique group id for one instance of a combo in one basket. */
function combo_group_id(): string
{
    // 13 chars, matching the column: enough to separate two instances in one
    // basket, and it never leaves the server as anything meaningful.
    return substr(bin2hex(random_bytes(8)), 0, 13);
}
