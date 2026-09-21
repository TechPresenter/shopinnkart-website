<?php
/**
 * ShopInnKart - Search results.
 *
 * The header dropdown and this page share includes/search-functions.php for
 * trending terms, near-miss suggestions and logging, and product-listing.php
 * for the filters, the sort control and the pager. What is specific to a
 * search lives here: the result header, and the two states where there is
 * nothing to list.
 *
 * A submitted search is a deliberate act, so it is the one that gets logged
 * with its real result count - correcting whatever the live dropdown wrote
 * while the query was still being typed.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/product-listing.php';
require_once INCLUDES_PATH . '/search-functions.php';

/**
 * Search box + a row of terms, shared by the no-query and no-result states.
 *
 * Rendered from search_bar() rather than hand-rolled, so the microphone, the
 * clear button and the live dropdown behave here exactly as they do in the
 * header.
 */
function search_page_prompt(string $query, array $terms, string $termsLabel): void
{
    ?>
    <div style="max-width:520px;margin:0 auto">
        <?php search_bar([
            'variant'     => 'page',
            'id'          => 'searchPageInput',
            'value'       => $query,
            'placeholder' => 'Search phones, laptops, headphones…',
            'autofocus'   => $query === '',
        ]); ?>
        <?php /* Hidden by CSS when voice-search.js finds no Speech Recognition
                 and removes the microphone this sentence points at. */ ?>
        <p class="sik-search__hint">
            <?= icon('mic') ?>
            <span>Or tap the microphone and say what you are looking for.</span>
        </p>
    </div>

    <?php if ($terms !== []): ?>
        <p class="sik-eyebrow" style="margin:var(--sp-6) 0 var(--sp-3);color:var(--sik-muted)">
            <?= e($termsLabel) ?>
        </p>
        <div class="sik-pills" style="justify-content:center">
            <?php foreach ($terms as $term): ?>
                <a class="sik-pill" href="<?= e(url('search.php') . '?q=' . rawurlencode((string) $term)) ?>">
                    <?= icon('search', 'w-3.5 h-3.5') ?><?= e($term) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

$query = trim((string) input('q', ''));

// ---------------------------------------------------------------------------
//  No query: prompt instead of listing the whole catalogue.
// ---------------------------------------------------------------------------
if ($query === '') {
    seo_set([
        'title'       => 'Search',
        'description' => 'Search the ShopInnKart catalogue by product, brand or model number.',
        'robots'      => 'noindex, follow',
    ]);

    require INCLUDES_PATH . '/header.php';

    $popularTerms = search_popular_terms(8);
    $popularProducts = get_products_for_source('best', 8);
    ?>
    <div class="sik-container" style="padding-top:var(--sp-4)">
        <?= breadcrumbs([['label' => 'Home', 'url' => url()], ['label' => 'Search']]) ?>
    </div>

    <div class="sik-container sik-section sik-section--sm">
        <div class="sik-empty" style="padding-block:var(--sp-7)">
            <?= icon('search', 'w-14 h-14') ?>
            <h1 class="sik-empty__title">What are you looking for?</h1>
            <p class="sik-empty__text">Search by product name, brand or model number.</p>
            <?php search_page_prompt('', $popularTerms, 'Trending searches'); ?>
        </div>
    </div>

    <?php if ($popularProducts !== []): ?>
        <div class="sik-container sik-section sik-section--sm">
            <div class="sik-heading">
                <h2 class="sik-heading__title">POPULAR <span class="sik-heading__accent">RIGHT NOW</span></h2>
            </div>
            <?= product_grid($popularProducts) ?>
        </div>
    <?php endif; ?>

    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
//  Results
// ---------------------------------------------------------------------------
$crumbs = [
    ['label' => 'Home', 'url' => url()],
    ['label' => 'Search'],
    ['label' => str_limit($query, 40)],
];

$listing = product_listing_query([
    'base'        => ['q' => $query],
    'breadcrumbs' => $crumbs,
    'clear_url'   => url('search.php') . '?q=' . rawurlencode($query),
    'empty'       => static function () use ($query): void {
        $suggestions = search_suggestions($query, 6);
        // Decorated rows for product_card(); search_popular_products() is the
        // JSON-shaped version the dropdown eats.
        $popular = get_products_for_source('best', 4);
        ?>
        <div style="grid-column:1/-1">
            <div class="sik-empty" style="padding-block:var(--sp-7)">
                <img src="<?= e(asset('images/placeholders/empty-search.svg')) ?>" alt="" width="200" height="150">
                <p class="sik-empty__title">No results for &ldquo;<?= e(str_limit($query, 60)) ?>&rdquo;</p>
                <p class="sik-empty__text">
                    Check the spelling, use fewer words, or try one of the searches below.
                </p>
                <?php search_page_prompt($query, $suggestions, 'Try searching for'); ?>
            </div>

            <?php if ($popular !== []): ?>
                <div style="margin-top:var(--sp-7)">
                    <div class="sik-heading">
                        <h2 class="sik-heading__title">CUSTOMER <span class="sik-heading__accent">FAVOURITES</span></h2>
                        <p class="sik-heading__sub">Best sellers other shoppers are buying right now.</p>
                    </div>
                    <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:3;--cols-mobile:2">
                        <?php foreach ($popular as $product): ?>
                            <?= product_card($product) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    },
]);

$total = (int) $listing['pagination']['total'];

// Plain text, curly quotes and all: the shared listing header runs it through
// e(), so an HTML entity here would be shown to the shopper as an entity.
$listing['config']['title'] = $total . ' ' . ($total === 1 ? 'result' : 'results')
    . ' for “' . str_limit($query, 60) . '”';
$listing['config']['subtitle'] = $total === 0
    ? 'Nothing matched that search — try a shorter or differently spelled term.'
    : 'Narrow it down with the filters, or sort to put the best matches first.';

// Terms to try next, offered as pills under the heading by the shared listing
// header. Only worth showing when there is a result page to leave.
if ($total > 0) {
    $related = search_suggestions($query, 6);
    if ($related !== []) {
        $listing['config']['pills'] = array_map(static fn (string $term): array => [
            'label' => $term,
            'url'   => url('search.php') . '?q=' . rawurlencode($term),
        ], $related);
        $listing['config']['pills_label'] = 'Related searches';
    }
}

// Only the first page of a query is a real search event.
if ((int) $listing['pagination']['current'] === 1) {
    search_log_query($query, $total);
}

seo_set([
    'title'       => 'Search results for "' . $query . '"',
    'description' => $total . ' products found for "' . $query . '" at ' . setting('store_name', SITE_NAME) . '.',
    'robots'      => 'noindex, follow',
]);
seo_add_schema(seo_breadcrumb_schema($crumbs));

require INCLUDES_PATH . '/header.php';

// Breadcrumbs and the field move above the listing so the query can be edited
// without hunting for the header box, which is behind a toggle on a phone.
// The listing keeps ownership of the <h1>, so there is still only one.
$listing['config']['breadcrumbs'] = [];
?>
<div class="sik-container" style="padding-top:var(--sp-4)">
    <?= breadcrumbs($crumbs) ?>
    <?php if ($total > 0): ?>
        <?php /* Only when there is a result set to narrow. With no results the
                 empty state below owns the field, and two of them one above the
                 other would just be the same control twice. */ ?>
        <div style="max-width:620px;margin-top:var(--sp-3)">
            <?php search_bar([
                'variant'     => 'page',
                'id'          => 'searchResultsInput',
                'value'       => $query,
                'placeholder' => 'Search phones, laptops, headphones…',
            ]); ?>
        </div>
    <?php endif; ?>
</div>
<?php

product_listing_render($listing);

require INCLUDES_PATH . '/footer.php';
