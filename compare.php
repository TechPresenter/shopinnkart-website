<?php
/**
 * ShopInnKart - Side-by-side product comparison.
 *
 * The list itself lives in `compare_items` (per user or per session); this page
 * only reads it. Every value shown is re-read from the database by
 * compare_products(), so a stale browser list can never fake a price.
 *
 * LAYOUT NOTE (the bit that is easy to get wrong)
 * -----------------------------------------------
 * The product header has to stay pinned while the rows scroll DOWN, and the
 * attribute column has to stay pinned while the columns scroll ACROSS. Those
 * two demands fight: `position: sticky; top:` only resolves against a scrolling
 * ancestor, and a wrapper carrying `overflow-x: auto` computes its overflow-y
 * to `auto` as well, which makes the WRAPPER the scrollport and leaves the page
 * scroll unable to pin anything inside it. (Measured in Edge 140: the header
 * cell tracked its wrapper straight off screen to -241px.)
 *
 * So the matrix is two panes, not one. The header pane is sticky against the
 * page; the row pane scrolls normally. Each pane scrolls sideways on its own
 * and pins its own first column with `left: 0` - which does work - and
 * compare.js mirrors scrollLeft between the two so the columns stay lined up.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/widgets.php';

// Admin > Settings > Widgets can switch comparison off, or restrict it to
// signed-in customers.
if (feature_refusal('compare') !== null) {
    // A switched-off feature is a route that no longer exists on this store, so
    // the status goes out before a byte of the page. "Sign in first" is a
    // working page for the wrong visitor, so that case stays a 200.
    if (!compare_enabled()) {
        http_response_code(404);
    }

    seo_set([
        'title'     => 'Comparison unavailable',
        'robots'    => 'noindex, nofollow',
        'canonical' => url('compare.php'),
    ]);

    require INCLUDES_PATH . '/header.php';
    saved_list_unavailable('compare');
    require INCLUDES_PATH . '/footer.php';
    exit;
}

seo_set([
    'title'       => 'Compare Products',
    'description' => 'Put up to ' . compare_max() . ' products side by side and compare price, rating, availability, warranty and full specifications.',
    'robots'      => 'noindex, follow',
    'canonical'   => url('compare.php'),
]);

$comparison = compare_products();
$products   = $comparison['products'];
$specKeys   = $comparison['spec_keys'];
$specs      = $comparison['specs'];
$maxCompare = compare_max();
$count      = count($products);
$slotsLeft  = max(0, $maxCompare - $count);

// Highlights come straight off `product_features` for the compared ids only -
// one query for the whole matrix rather than get_product_detail() per column,
// which would pull images, videos and variants nobody is going to read.
$highlights = [];
if ($products !== []) {
    [$ph, $hParams] = Database::inPlaceholders(
        array_map(static fn (array $p): int => (int) $p['id'], $products),
        'h'
    );
    foreach (Database::fetchAll(
        'SELECT `product_id`, `feature` FROM `product_features`
         WHERE `product_id` IN (' . $ph . ') ORDER BY `sort_order`, `id`',
        $hParams
    ) as $featureRow) {
        $highlights[(int) $featureRow['product_id']][] = (string) $featureRow['feature'];
    }
}

/**
 * One comparison row.
 *
 * @var array<int, array{
 *   group:string, label:string, cells:array<int,string>,
 *   best:array<int,bool>, best_label:string, differs:bool
 * }> $rows
 */
$rows = [];
$usedLabels = [];
$sameRows = 0;

/**
 * Add a row, unless it would be an empty one.
 *
 * Two refusals, for two different kinds of noise:
 *   - a label already claimed (a spec sheet that repeats "Warranty" after the
 *     column above it has already shown it);
 *   - a row with no value on ANY compared product. Eleven festive-lighting
 *     products share four spec keys and scatter the other thirty across one or
 *     two products each, so without this the table would be mostly dashes.
 *
 * @param array<int,string> $cells Pre-rendered HTML, keyed by product id.
 * @param array<int,string> $raw   The plain values behind them, same keys.
 * @param array<int,bool>   $best  Ids that win this row, from $winners().
 */
$addRow = static function (
    string $group,
    string $label,
    array $cells,
    array $raw,
    array $best = [],
    string $bestLabel = ''
) use (&$rows, &$usedLabels, &$sameRows): void {
    $key = mb_strtolower(trim($label));
    if (isset($usedLabels[$key])) {
        return;
    }

    $filled = array_filter($raw, static fn ($v): bool => $v !== null && $v !== '');
    if ($filled === []) {
        return;
    }

    $usedLabels[$key] = true;
    $differs = count(array_unique($raw)) > 1;
    if (!$differs) {
        $sameRows++;
    }

    $rows[] = [
        'group'      => $group,
        'label'      => $label,
        'cells'      => $cells,
        'best'       => $best,
        'best_label' => $bestLabel,
        'differs'    => $differs,
    ];
};

/**
 * Which products win a row - or nobody, which is the common answer.
 *
 * Only four things on this page carry a defensible ordering: a lower price is
 * better, a bigger discount is better, a higher customer rating is better, and
 * in stock beats out of stock. Everything else - "8 Modes", "Copper", "IP44" -
 * is a difference, not a ranking, and gets no winner mark.
 *
 * Three cases return no winner at all, because none of them tells a shopper
 * anything: fewer than two products have a value, every value is identical, or
 * the row is not one of the four above.
 *
 * @param array<int, float|null> $values Score per product id; null = no value.
 * @param string                 $dir    min|max
 * @return array<int, bool>
 */
$winners = static function (array $values, string $dir): array {
    $scored = array_filter($values, static fn ($v): bool => $v !== null);
    if (count($scored) < 2 || count(array_unique($scored)) < 2) {
        return [];
    }

    $target = $dir === 'min' ? min($scored) : max($scored);
    $out = [];
    foreach ($scored as $id => $value) {
        if ($value === $target) {
            $out[$id] = true;
        }
    }
    return $out;
};

/** The muted em-dash used wherever one product has a value and another does not. */
$blank = '<span class="sik-cmp__none">&mdash;</span>';

/** Yes / No cell for a boolean product attribute. */
$yesNo = static function (bool $on) use ($blank): string {
    return $on
        ? '<span class="sik-cmp__yes">' . icon('check', 'w-3.5 h-3.5') . 'Yes</span>'
        : '<span class="sik-cmp__no">No</span>';
};

if ($products !== []) {

    // ---------------------------------------------------------------- price
    $cells = $raw = $score = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $score[$id] = (float) $p['final_price'];
        $raw[$id]   = (string) $p['final_price'];
        $cells[$id] = '<span class="sik-price">' . e($p['price_display']) . '</span>'
            . (!empty($p['on_sale'])
                ? '<span class="sik-price--mrp">' . e($p['mrp_display']) . '</span>'
                : '');
    }
    $addRow('Price & value', 'Price', $cells, $raw, $winners($score, 'min'), 'Lowest price');

    // ------------------------------------------------------------- discount
    // An undiscounted product scores 0 but carries an EMPTY raw value, so a
    // comparison where nobody is on offer drops the row instead of printing a
    // column of dashes.
    $cells = $raw = $score = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $off = (int) $p['discount'];
        $score[$id] = (float) $off;
        $raw[$id]   = $off > 0 ? (string) $off : '';
        $cells[$id] = $off > 0
            ? '<span class="sik-cmp__off">' . $off . '% off</span>'
              . '<span class="sik-cmp__sub">You save ' . e(money((float) $p['saving'])) . '</span>'
            : $blank;
    }
    $addRow('Price & value', 'Discount', $cells, $raw, $winners($score, 'max'), 'Biggest saving');

    // --------------------------------------------------------------- rating
    // Unrated products are not "0 stars", they are unknown - so they score null
    // and take no part in the ranking.
    $cells = $raw = $score = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $rated = (int) $p['rating_count'] > 0;
        $score[$id] = $rated ? (float) $p['rating_avg'] : null;
        $raw[$id]   = $rated ? number_format((float) $p['rating_avg'], 1) : '';
        $cells[$id] = $rated
            ? rating_stars((float) $p['rating_avg'], 'w-3.5 h-3.5')
              . '<span class="sik-cmp__sub">'
              . e(number_format((float) $p['rating_avg'], 1)) . ' &middot; '
              . number_format((int) $p['rating_count']) . ' rating' . ((int) $p['rating_count'] === 1 ? '' : 's')
              . '</span>'
            : '<span class="sik-cmp__none">Not rated yet</span>';
    }
    $addRow('Price & value', 'Customer rating', $cells, $raw, $winners($score, 'max'), 'Highest rated');

    // --------------------------------------------------------- availability
    $rank = [STOCK_OUT => 0.0, STOCK_LOW => 1.0, STOCK_IN => 2.0];
    $tone = [STOCK_IN => 'green', STOCK_LOW => 'amber', STOCK_OUT => 'red'];
    $cells = $raw = $score = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $state = (string) $p['stock_state'];
        $score[$id] = $rank[$state] ?? 0.0;
        $raw[$id]   = $state;
        $cells[$id] = '<span class="sik-status sik-status--' . e_attr($tone[$state] ?? 'gray') . '">'
            . e($p['stock_label']) . '</span>';
    }
    $addRow('Price & value', 'Availability', $cells, $raw, $winners($score, 'max'), 'In stock');

    // ---------------------------------------------------------------- brand
    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $raw[$id] = (string) ($p['brand_name'] ?? '');
        $cells[$id] = !empty($p['brand_name'])
            ? '<a class="sik-cmp__link" href="' . e(brand_url((string) $p['brand_slug'])) . '">' . e($p['brand_name']) . '</a>'
            : $blank;
    }
    $addRow('Product details', 'Brand', $cells, $raw);

    // ------------------------------------------------------------- category
    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $raw[$id] = (string) ($p['category_name'] ?? '');
        $cells[$id] = !empty($p['category_name'])
            ? '<a class="sik-cmp__link" href="' . e(category_url((string) $p['category_slug'])) . '">' . e($p['category_name']) . '</a>'
            : $blank;
    }
    $addRow('Product details', 'Category', $cells, $raw);

    // ------------------------------------------------------------------ sku
    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $raw[$id]   = (string) $p['sku'];
        $cells[$id] = '<span class="sik-cmp__sku">' . e($p['sku']) . '</span>';
    }
    $addRow('Product details', 'SKU', $cells, $raw);

    // ------------------------------------------------------------- warranty
    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $raw[$id]   = (string) ($p['warranty'] ?? '');
        $cells[$id] = !empty($p['warranty']) ? e($p['warranty']) : $blank;
    }
    $addRow('Product details', 'Warranty', $cells, $raw);

    // ------------------------------------------------- delivery & payment
    // A yes/no row only earns its space when at least one product says yes;
    // "No" down the whole column is not a comparison.
    foreach ([
        ['Free delivery', 'free_shipping'],
        ['Cash on delivery', 'cod_available'],
    ] as [$label, $field]) {
        $cells = $raw = [];
        $anyYes = false;
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $on = !empty($p[$field]);
            $anyYes = $anyYes || $on;
            $raw[$id]   = $on ? 'yes' : 'no';
            $cells[$id] = $yesNo($on);
        }
        if ($anyYes) {
            $addRow('Product details', $label, $cells, $raw);
        }
    }

    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $raw[$id]   = (string) ($p['emi_text'] ?? '');
        $cells[$id] = !empty($p['emi_text']) ? e($p['emi_text']) : $blank;
    }
    $addRow('Product details', 'EMI', $cells, $raw);

    // ----------------------------------------------------------- highlights
    $cells = $raw = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $list = $highlights[$id] ?? [];
        $raw[$id] = implode('|', $list);
        if ($list === []) {
            $cells[$id] = $blank;
            continue;
        }
        $html = '<ul class="sik-cmp__list">';
        foreach ($list as $feature) {
            $html .= '<li>' . e($feature) . '</li>';
        }
        $cells[$id] = $html . '</ul>';
    }
    $addRow('Highlights', 'Key highlights', $cells, $raw);

    // --------------------------------------------------------------- specs
    // compare_products() already returns the UNION of keys that at least one
    // compared product carries, so every key here has data somewhere; $addRow
    // is the belt-and-braces check.
    foreach ($specKeys as $key) {
        $cells = $raw = [];
        foreach ($products as $p) {
            $id = (int) $p['id'];
            $value = $specs[$id][$key] ?? null;
            $raw[$id]   = (string) $value;
            $cells[$id] = ($value !== null && $value !== '') ? e($value) : $blank;
        }
        $addRow('Specifications', $key, $cells, $raw);
    }
}

$colspan = $count + 1;
$diffRows = count($rows) - $sameRows;

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container sik-section sik-section--sm" data-compare-page>

    <?= breadcrumbs([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'Compare Products', 'url' => url('compare.php')],
    ]) ?>

    <div class="sik-heading sik-heading--row sik-cmp__intro" style="margin-top:var(--sp-5)">
        <div>
            <h1 class="sik-heading__title">COMPARE <span class="sik-heading__accent">PRODUCTS</span></h1>
            <p class="sik-heading__sub">
                <?php if ($products === []): ?>
                    Line up to <?= $maxCompare ?> products side by side and see exactly what separates them.
                <?php else: ?>
                    <?= $count ?> product<?= $count === 1 ? '' : 's' ?> side by side &middot;
                    <?= $diffRows ?> of <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> differ
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($products === []): ?>

        <!-- ========================= Empty state ========================= -->
        <div class="sik-empty sik-cmp__empty">
            <?= icon('compare') ?>
            <h2 class="sik-empty__title">Nothing to compare yet</h2>
            <p class="sik-empty__text">
                Pick up to <?= $maxCompare ?> products and we will line up their price, rating,
                availability and full specifications in one table.
            </p>
            <ol class="sik-cmp__steps">
                <li><span>1</span>Browse the shop or a category</li>
                <li><span>2</span>Tap <?= icon('compare', 'w-4 h-4') ?> on any product card</li>
                <li><span>3</span>Come back here to see them side by side</li>
            </ol>
            <div class="sik-cmp__emptyacts">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('shop.php')) ?>">
                    <?= icon('grid', 'w-4 h-4') ?> Browse all products
                </a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('deals.php')) ?>">
                    <?= icon('percent', 'w-4 h-4') ?> Today&rsquo;s deals
                </a>
            </div>
        </div>

    <?php else: ?>

        <!-- =========================== Toolbar =========================== -->
        <div class="sik-cmp__bar">
            <?php if ($sameRows > 0 && $count > 1): ?>
                <label class="sik-cmp__switch">
                    <input type="checkbox" data-cmp-diffonly>
                    <span>Show differences only</span>
                    <span class="sik-cmp__switchcount"><?= $diffRows ?></span>
                </label>
            <?php endif; ?>

            <div class="sik-cmp__baracts">
                <?php if ($slotsLeft > 0): ?>
                    <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e(url('shop.php')) ?>">
                        <?= icon('plus', 'w-4 h-4') ?>
                        <span class="sik-btn__label">Add more products</span>
                    </a>
                <?php endif; ?>
                <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm sik-cmp__clear" data-compare-clear>
                    <?= icon('trash', 'w-4 h-4') ?><span class="sik-btn__label">Remove all</span>
                </button>
            </div>
        </div>

        <?php if ($slotsLeft === 0): ?>
            <div class="sik-alert sik-alert--info sik-cmp__limit" role="status">
                <?= icon('info', 'w-4 h-4') ?>
                <div>
                    <strong>All <?= $maxCompare ?> comparison slots are in use.</strong>
                    Remove a product below before adding a different one.
                </div>
            </div>
        <?php endif; ?>

        <!-- ========================== The matrix ========================= -->
        <?php // 1px marker the IntersectionObserver in compare.js watches to
              // know the header pane has detached and can shrink. ?>
        <div class="sik-cmp__sentinel" data-cmp-sentinel aria-hidden="true"></div>

        <div class="sik-cmp" style="--cmp-n:<?= $count ?>">

            <div class="sik-cmp__head sik-cmp__pane sik-cmp__pane--head" data-cmp-scroll data-cmp-head>
                <table class="sik-cmp__table">
                    <caption class="sik-sr">The <?= $count ?> products you are comparing</caption>
                    <colgroup>
                        <col class="sik-cmp__c0">
                        <?php foreach ($products as $p): ?><col><?php endforeach; ?>
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="sik-cmp__corner">
                                <span class="sik-cmp__cornerlead">Comparing</span>
                                <span class="sik-cmp__cornernum"><?= $count ?> of <?= $maxCompare ?></span>
                            </th>
                            <?php foreach ($products as $p): ?>
                                <th scope="col" class="sik-cmp__col" data-compare-col="<?= (int) $p['id'] ?>">
                                    <div class="sik-cmp__card">
                                        <div class="sik-cmp__tools">
                                            <?php // Both tools are the shared saved-list control, so an admin
                                                  // who hides the wishlist gets an empty string here and the
                                                  // rail simply loses a button. ?>
                                            <?= wishlist_shows_on('card')
                                                ? wishlist_button((int) $p['id'], (string) $p['name'])
                                                : '' ?>
                                            <button type="button" class="sik-iconbtn sik-cmp__drop"
                                                    data-compare-remove data-product-id="<?= (int) $p['id'] ?>"
                                                    aria-label="Remove <?= e_attr($p['name']) ?> from the comparison">
                                                <?= icon('close', 'w-4 h-4') ?>
                                            </button>
                                        </div>

                                        <a class="sik-cmp__thumb" href="<?= e($p['url']) ?>" tabindex="-1" aria-hidden="true">
                                            <img src="<?= e($p['image_url']) ?>" alt=""
                                                 width="200" height="200" loading="lazy" decoding="async">
                                        </a>

                                        <?php if (!empty($p['brand_name'])): ?>
                                            <span class="sik-cmp__eyebrow"><?= e($p['brand_name']) ?></span>
                                        <?php endif; ?>

                                        <a class="sik-cmp__name" href="<?= e($p['url']) ?>"><?= e($p['name']) ?></a>

                                        <span class="sik-cmp__headprice">
                                            <span class="sik-price"><?= e($p['price_display']) ?></span>
                                            <?php if (!empty($p['on_sale'])): ?>
                                                <span class="sik-price--mrp"><?= e($p['mrp_display']) ?></span>
                                            <?php endif; ?>
                                        </span>

                                        <div class="sik-cmp__acts">
                                            <?= add_to_cart_button($p, ['size' => 'sm']) ?>
                                        </div>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                </table>
            </div>

            <div class="sik-cmp__pane sik-cmp__pane--body" data-cmp-scroll
                 tabindex="0" role="region" aria-label="Comparison details, scrolls sideways">
                <table class="sik-cmp__table">
                    <caption class="sik-sr">Attribute by attribute comparison</caption>
                    <colgroup>
                        <col class="sik-cmp__c0">
                        <?php foreach ($products as $p): ?><col><?php endforeach; ?>
                    </colgroup>
                    <?php // The visible column heads live in the pinned pane above, which is a
                          // separate table - so this one carries its own zero-height heading row
                          // to keep every cell associated with its product for a screen reader. ?>
                    <thead class="sik-cmp__srhead">
                        <tr>
                            <th scope="col"><span class="sik-sr">Attribute</span></th>
                            <?php foreach ($products as $p): ?>
                                <th scope="col"><span class="sik-sr"><?= e($p['name']) ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $group = null; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php if ($row['group'] !== $group): $group = $row['group']; ?>
                                <tr class="sik-cmp__grouprow">
                                    <th colspan="<?= $colspan ?>" scope="colgroup" class="sik-cmp__group">
                                        <span><?= e($group) ?></span>
                                    </th>
                                </tr>
                            <?php endif; ?>
                            <tr class="sik-cmp__row<?= $row['differs'] ? ' is-diff' : ' is-same' ?>">
                                <th scope="row" class="sik-cmp__label">
                                    <?= e($row['label']) ?>
                                    <?php if ($row['differs']): ?>
                                        <span class="sik-sr">(values differ)</span>
                                    <?php endif; ?>
                                </th>
                                <?php foreach ($products as $p): ?>
                                    <?php $id = (int) $p['id']; $isBest = isset($row['best'][$id]); ?>
                                    <td class="sik-cmp__cell<?= $isBest ? ' is-best' : '' ?>">
                                        <?= $row['cells'][$id] ?>
                                        <?php if ($isBest): ?>
                                            <span class="sik-cmp__best">
                                                <?= icon('check', 'w-3 h-3') ?><?= e($row['best_label']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================ Footer =========================== -->
        <div class="sik-cmp__foot">
            <a class="sik-btn sik-btn--outline" href="<?= e(url('shop.php')) ?>">
                <?= icon('arrow-left', 'w-4 h-4') ?> Continue shopping
            </a>
            <p class="sik-cmp__footnote">
                <?php if ($slotsLeft > 0): ?>
                    <?= $slotsLeft ?> more slot<?= $slotsLeft === 1 ? '' : 's' ?> free &mdash;
                    add up to <?= $maxCompare ?> products at a time.
                <?php else: ?>
                    You are comparing the maximum of <?= $maxCompare ?> products.
                <?php endif; ?>
            </p>
        </div>

    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
