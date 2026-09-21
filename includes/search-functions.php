<?php
/**
 * ShopInnKart - Shared search helpers.
 *
 * The live-suggestion endpoint and the results page ask the same four
 * questions: what is trending, what did this person probably mean, which
 * shelves can we always point at, and how does the query get logged. They used
 * to carry their own near-identical copies, so the trending row in the dropdown
 * could disagree with the trending row on the page that dropdown opens, and a
 * fix to the log collapsing had to be made twice. One file, one answer.
 *
 * Required by /search.php and /api/products/search.php.
 */

declare(strict_types=1);

/**
 * Trending terms from the search log.
 *
 * Falls back to best sellers while the log is still empty: a new store has no
 * search history, and an empty "trending" row reads as a broken feature rather
 * than a young one.
 *
 * @return array<int, string>
 */
function search_popular_terms(int $limit = 8): array
{
    $limit = max(1, min(20, $limit));

    // `results_count` is a snapshot of what the term found ON THE DAY IT WAS
    // TYPED, not what it finds now — so it cannot be the only test. When this
    // store sold electronics, "phone" logged 12 results; the catalogue is now
    // festive lighting, "phone" matches nothing, and the row still says 12. The
    // trending rail happily offered "phone", "laptop" and "audio", each of them
    // a one-click trip to an empty results page.
    //
    // EXISTS re-checks the term against the CURRENT catalogue, so a chip can
    // only appear if it still leads somewhere. Matching on name is deliberately
    // the narrow half of what search_suggestions() does (it also does SOUNDEX
    // and a loose match): a trending chip should be a confident hit, not a
    // fuzzy one.
    //
    // HAVING COUNT(*) > 1 drops the half-typed fragments. The live box logs on
    // keystrokes and rewrites the row only while a term EXTENDS the one this
    // session just logged, so a stray "aud" or "li" from a different session
    // survives as its own row — with a real results_count, which is exactly how
    // two-letter fragments ended up billed as trending searches.
    $terms = Database::fetchColumnAll(
        'SELECT sl.`query` FROM `search_logs` sl
         WHERE sl.`results_count` > 0
           AND sl.`created_at` >= DATE_SUB(NOW(), INTERVAL 60 DAY)
           AND CHAR_LENGTH(sl.`query`) >= 3
           AND EXISTS (
                SELECT 1 FROM `products` p
                 WHERE ' . product_visible_sql('p') . '
                   AND p.`name` LIKE CONCAT(\'%\', sl.`query`, \'%\')
           )
         GROUP BY sl.`query`
         HAVING COUNT(*) > 1
         ORDER BY COUNT(*) DESC, MAX(sl.`created_at`) DESC
         LIMIT ' . $limit
    );

    // Top up, rather than only substituting when the log is completely empty.
    //
    // Once the EXISTS check above started rejecting the dead electronics terms,
    // a real log of 55 rows collapsed to a single surviving term — and the old
    // `=== []` guard does not fire on a list of one, so the rail rendered one
    // lonely chip. Any shortfall now gets filled.
    //
    // Categories and brands, not product names: these render as CHIPS, and a
    // product name here is "Lexton Artificial Leaf Curtain LED String Light —
    // 18 Leaves, 5 Metre". Category and brand names are short, and both are
    // guaranteed to return results because they come from the live catalogue.
    if (count($terms) < $limit) {
        $lower = array_map('mb_strtolower', $terms);

        $filler = Database::fetchColumnAll(
            "SELECT c.`name` FROM `categories` c
              WHERE c.`status` = 'active'
                AND EXISTS (SELECT 1 FROM `products` p
                             WHERE p.`category_id` = c.`id` AND " . product_visible_sql('p') . ")
              ORDER BY c.`parent_id` IS NULL, c.`sort_order`, c.`name`"
        );

        $filler = array_merge($filler, Database::fetchColumnAll(
            "SELECT b.`name` FROM `brands` b
              WHERE b.`status` = 'active'
                AND EXISTS (SELECT 1 FROM `products` p
                             WHERE p.`brand_id` = b.`id` AND " . product_visible_sql('p') . ")
              ORDER BY b.`name`"
        ));

        foreach ($filler as $candidate) {
            if (count($terms) >= $limit) {
                break;
            }
            if (!in_array(mb_strtolower((string) $candidate), $lower, true)) {
                $terms[] = (string) $candidate;
                $lower[] = mb_strtolower((string) $candidate);
            }
        }
    }

    return array_values(array_map('strval', $terms));
}

/**
 * Record the query.
 *
 * A live-search box fires on nearly every keystroke, so when the new term
 * extends the one this session just logged we rewrite that row instead of
 * filling the log with half-typed words. The submitted search on /search.php
 * runs through the same rule, which is what lets it correct the count the
 * dropdown wrote a moment earlier rather than adding a second row.
 */
function search_log_query(string $query, int $results): void
{
    $previousId = (int) ($_SESSION['_search_log_id'] ?? 0);
    $previous   = (string) ($_SESSION['_search_log_q'] ?? '');

    try {
        $isRefinement = $previousId > 0 && $previous !== ''
            && (stripos($query, $previous) === 0 || stripos($previous, $query) === 0);

        if ($isRefinement) {
            Database::update(
                'search_logs',
                ['query' => mb_substr($query, 0, 255), 'results_count' => max(0, $results)],
                '`id` = :id',
                ['id' => $previousId]
            );
        } else {
            $previousId = Database::insert('search_logs', [
                'query'         => mb_substr($query, 0, 255),
                'results_count' => max(0, $results),
                'user_id'       => current_user_id(),
                'session_id'    => session_key() ?: null,
                'ip_address'    => client_ip(),
            ]);
        }

        $_SESSION['_search_log_id'] = $previousId;
        $_SESSION['_search_log_q']  = $query;
    } catch (Throwable $e) {
        // Analytics must never take the search down with it.
        ErrorHandler::log('warning', 'search log write failed: ' . $e->getMessage());
    }
}

/**
 * Terms worth trying instead of, or alongside, the one that was typed.
 *
 * Closest matches first, then what other shoppers search for, then the shelves
 * we can always point at - so the list is never left half empty and a dead-end
 * result page still offers somewhere to go.
 *
 * @return array<int, string>
 */
function search_suggestions(string $query, int $limit = 5): array
{
    $limit  = max(1, min(12, $limit));
    $prefix = mb_substr($query, 0, 3);
    $fuzzy  = ['like' => $prefix . '%', 'loose' => '%' . $prefix . '%', 'q' => $query];

    $pools = [
        Database::fetchColumnAll(
            "SELECT `name` FROM `brands`
             WHERE `status` = 'active' AND (`name` LIKE :like OR `name` LIKE :loose OR SOUNDEX(`name`) = SOUNDEX(:q))
             ORDER BY `sort_order`, `name` LIMIT 5",
            $fuzzy
        ),
        Database::fetchColumnAll(
            "SELECT `name` FROM `categories`
             WHERE `status` = 'active' AND (`name` LIKE :like OR `name` LIKE :loose OR SOUNDEX(`name`) = SOUNDEX(:q))
             ORDER BY `sort_order`, `name` LIMIT 5",
            $fuzzy
        ),
        search_popular_terms(5),
        Database::fetchColumnAll(
            "SELECT `name` FROM `brands` WHERE `status` = 'active'
             ORDER BY `is_featured` DESC, `sort_order`, `name` LIMIT 5"
        ),
        Database::fetchColumnAll(
            "SELECT `name` FROM `categories` WHERE `status` = 'active' AND `parent_id` IS NULL
             ORDER BY `is_featured` DESC, `sort_order`, `name` LIMIT 5"
        ),
    ];

    $picked = [];
    foreach ($pools as $pool) {
        foreach ($pool as $name) {
            $key = mb_strtolower((string) $name);
            if ($key === mb_strtolower($query) || isset($picked[$key])) {
                continue;
            }
            $picked[$key] = (string) $name;
            if (count($picked) >= $limit) {
                break 2;
            }
        }
    }

    return array_values($picked);
}

/**
 * Shelves for the "browse categories" row: the admin's featured categories,
 * falling back to the top level of the tree when none are flagged.
 *
 * Images come from the categories table, never from a hard-coded list, and go
 * through img_url() so a missing file degrades to the placeholder.
 *
 * @return array<int, array{id:int,name:string,slug:string,url:string,image_url:string,product_count:int}>
 */
function search_top_categories(int $limit = 8): array
{
    $limit = max(1, min(16, $limit));

    $rows = featured_categories($limit);
    if ($rows === []) {
        $rows = array_slice(category_tree(), 0, $limit);
    }

    return array_values(array_map(static fn (array $row): array => [
        'id'            => (int) $row['id'],
        'name'          => (string) $row['name'],
        'slug'          => (string) $row['slug'],
        'url'           => category_url((string) $row['slug']),
        'image_url'     => img_url($row['image'] ?? null),
        'product_count' => (int) ($row['product_count'] ?? 0),
    ], array_slice($rows, 0, $limit)));
}

/**
 * One decorated product reduced to what a suggestion row needs.
 *
 * The admin product pickers read id / name / sku / image_url / price_display
 * off this endpoint too, so those five keys are load-bearing - add fields,
 * never rename them.
 */
function search_product_row(array $product): array
{
    return [
        'id'            => (int) $product['id'],
        'name'          => (string) $product['name'],
        'url'           => (string) $product['url'],
        'image_url'     => (string) $product['image_url'],
        'price_display' => (string) $product['price_display'],
        'mrp_display'   => (string) ($product['mrp_display'] ?? ''),
        'on_sale'       => !empty($product['on_sale']),
        'discount'      => (int) ($product['discount'] ?? 0),
        'brand_name'    => (string) ($product['brand_name'] ?? ''),
        'category_name' => (string) ($product['category_name'] ?? ''),
        'sku'           => (string) ($product['sku'] ?? ''),
        'in_stock'      => !empty($product['in_stock']),
        'rating'        => round((float) ($product['rating'] ?? 0), 1),
    ];
}

/**
 * Products to fall back on when a query finds nothing, so the panel and the
 * results page are never a dead end. Best sellers first; a catalogue with
 * nothing flagged still gets the popular list rather than an empty row.
 */
function search_popular_products(int $limit = 4): array
{
    $limit = max(1, min(12, $limit));

    $products = get_products_for_source('best', $limit);
    if ($products === []) {
        $products = get_products_for_source('auto', $limit);
    }

    return array_values(array_map('search_product_row', $products));
}
