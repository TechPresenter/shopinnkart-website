<?php
/**
 * ShopInnKart Admin - Combo validation + persistence.
 *
 * create.php and edit.php both post into combo_form_save(). The combo row and
 * its component list are written in one transaction, because a combo whose
 * items failed to save would go on advertising a set price for a bundle that
 * is now empty.
 *
 * Nothing here computes `mrp`, and nothing here trusts a typed `price` in
 * percentage mode: combo_recalculate() settles both from what the components
 * cost today, and a stored MRP that drifts from the catalogue is how a store
 * ends up advertising a saving it is not giving.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/includes/marketing.php';
// SEO_EDITOR_FIELDS and seo_editor_input(): the combo form posts the same nine
// SEO fields as every other entity form now that `combos` has the columns.
require_once ADMIN_PATH . '/includes/seo-editor.php';

// ===========================================================================
//  Option lists
// ===========================================================================

/**
 * A combo has a status the other marketing rows do not: `draft` is the parking
 * space for a half-built set, and the activation rules below lean on it rather
 * than refusing the save outright.
 */
function combo_status_options(): array
{
    return ['active' => 'Active', 'inactive' => 'Inactive', 'draft' => 'Draft'];
}

/** How the set price is arrived at. */
function combo_pricing_mode_options(): array
{
    return [
        'fixed'      => 'Fixed set price',
        'percentage' => 'Percentage off the components',
    ];
}

/** What limits how many sets can be sold. */
function combo_stock_mode_options(): array
{
    return [
        'components' => 'Follow component stock',
        'track'      => 'Track a limited run',
    ];
}

// ===========================================================================
//  Reading the form
// ===========================================================================

/** Blank combo used by create.php. */
function combo_form_defaults(): array
{
    return [
        'id'               => 0,
        'name'             => '',
        'slug'             => '',
        'subtitle'         => '',
        'description'      => '',
        'image'            => null,
        'banner'           => null,
        'pricing_mode'     => 'fixed',
        'price'            => '',
        'discount_percent' => '',
        'stock_mode'       => 'components',
        'stock'            => '',
        'sold_count'       => 0,
        'max_per_order'    => '5',
        'is_featured'      => 0,
        'is_flash'         => 0,
        'is_best_seller'   => 0,
        'badge_text'       => '',
        'start_date'       => '',
        'end_date'         => '',
        'sort_order'       => 0,
        // A new combo cannot be sellable yet - there is nothing in it - so it
        // opens as a draft rather than as a status the save would refuse.
        'status'           => 'draft',
        // The nine the shared SEO editor owns. combos used to carry only three
        // of them, which is why this form had a hand-written SEO card; the rest
        // arrived with the per-entity SEO migration, so it uses seo_editor()
        // like the other five entity forms now.
        ...array_fill_keys(SEO_EDITOR_FIELDS, ''),
        'items'            => [],
    ];
}

/** Every scalar field the form posts. Numbers stay strings until validated. */
function combo_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        'name'             => $text('name'),
        'slug'             => $text('slug'),
        'subtitle'         => $text('subtitle'),
        'description'      => sanitize_html((string) ($_POST['description'] ?? '')),
        'pricing_mode'     => $text('pricing_mode'),
        'price'            => $text('price'),
        'discount_percent' => $text('discount_percent'),
        'stock_mode'       => $text('stock_mode'),
        'stock'            => $text('stock'),
        'max_per_order'    => $text('max_per_order'),
        'is_featured'      => input_bool('is_featured') ? 1 : 0,
        'is_flash'         => input_bool('is_flash') ? 1 : 0,
        'is_best_seller'   => input_bool('is_best_seller') ? 1 : 0,
        'badge_text'       => $text('badge_text'),
        'start_date'       => $text('start_date'),
        'end_date'         => $text('end_date'),
        'sort_order'       => $text('sort_order'),
        'status'           => $text('status'),
        // seo_editor_input() reads the nine fields the shared panel posts,
        // already trimmed, null-when-blank, with robots validated against the
        // allowlist and the custom JSON-LD rejected unless it parses. Reading
        // them by hand here is exactly how this form drifted from the other
        // five the first time. Nulls become '' because everything in this
        // array is a string until the payload converts it back.
        ...array_map(
            static fn ($value): string => (string) ($value ?? ''),
            seo_editor_input()
        ),
    ];
}

/**
 * The picker, read back out of the request.
 *
 * The three inputs are parallel arrays emitted together inside one row
 * element, so removing a row removes all three and the indexes stay aligned.
 * The order input is `item_sort[]` and not `sort_order[]`: the combo carries
 * its own scalar `sort_order` in the sidebar, and PHP collapses a scalar and
 * an array of the same name into whichever the browser sent last, which would
 * quietly wreck both.
 *
 * @return array<int, array{product_id:int,quantity:int,sort:int}>
 */
function combo_form_picked(): array
{
    $ids   = input_array('product_id');
    $qtys  = input_array('quantity');
    $sorts = input_array('item_sort');

    $rows = [];
    foreach ($ids as $index => $rawId) {
        $productId = (int) $rawId;
        if ($productId <= 0) {
            continue;
        }

        $quantity = (int) ($qtys[$index] ?? 1);

        // Two rows for the same product each report stock/quantity to
        // combo_available_sets(), which takes the minimum per row - so one
        // unit in stock would read as "a set is available" when the set
        // actually needs two, and the shopper buys a combo that cannot ship.
        // Summed into one row, the arithmetic is the one the basket will do.
        if (isset($rows[$productId])) {
            $rows[$productId]['quantity'] += $quantity;
            continue;
        }

        $rows[$productId] = [
            'product_id' => $productId,
            'quantity'   => $quantity,
            'sort'       => (int) ($sorts[$index] ?? ($index + 1)),
        ];
    }

    // The number inputs are the control; dragging only rewrote them, so the
    // numbers are what decides the stored order.
    uasort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

    return array_values($rows);
}

/**
 * Product rows for a list of ids, in the order the ids were given.
 *
 * marketing_product_rows() is the sibling helper and would almost do, except
 * that its SELECT has no `status`. Without it an operator can pick a draft
 * product, watch this form price it, save, and have combo_items() drop it on
 * the way out - that join is `p.status = 'active'` - leaving a combo that
 * prices and sizes itself differently from the one that was built.
 */
function combo_form_products(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'pid');
    $rows = Database::fetchAll(
        'SELECT `id`, `name`, `slug`, `sku`, `status`, `main_image`, `price`, `sale_price`, `stock`
         FROM `products` WHERE `id` IN (' . $placeholders . ')',
        $params
    );

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[$id] = $byId[$id];
        }
    }
    return $ordered;
}

/**
 * The components stored against a combo, including the ones the shopper's view
 * drops.
 *
 * combo_items() joins `p.status = 'active'`, which is right for the storefront
 * and wrong for this form: a component unpublished after the combo was built
 * would simply not appear in the builder, and the next save - which replaces
 * the list wholesale - would delete the row for good without anyone having
 * seen it. The form loads every stored row and prices it with
 * product_effective_price(), the same function combo_items() uses, so the two
 * can never disagree about a live product.
 *
 * @return array<int, array{product_id:int,quantity:int,sort:int}>
 */
function combo_form_items(int $comboId): array
{
    $rows = Database::fetchAll(
        'SELECT `product_id`, `quantity` FROM `combo_items`
         WHERE `combo_id` = :id ORDER BY `sort_order`, `id`',
        ['id' => $comboId]
    );

    $picked   = [];
    $position = 0;
    foreach ($rows as $row) {
        $picked[] = [
            'product_id' => (int) $row['product_id'],
            'quantity'   => max(1, (int) $row['quantity']),
            'sort'       => ++$position,
        ];
    }
    return $picked;
}

/**
 * A combo_items()-shaped array built from what was just submitted.
 *
 * This is what lets combo_pricing(), combo_available_sets() and the preview
 * card give the server's verdict on a combo that has not been written yet,
 * without one line of pricing being reimplemented here.
 *
 * @param array<int, array{product_id:int,quantity:int,sort:int}> $picked
 */
function combo_form_synthetic_items(array $picked, array $products): array
{
    $items = [];
    foreach ($picked as $row) {
        $product = $products[$row['product_id']] ?? null;
        // combo_items() drops exactly these, so the verdict has to drop them
        // too; otherwise the form promises a saving on a component the
        // storefront will never price.
        if ($product === null || (string) $product['status'] !== STATUS_ACTIVE) {
            continue;
        }

        $pricing  = product_effective_price($product);
        $quantity = max(1, (int) $row['quantity']);

        $items[] = [
            'product_id' => (int) $product['id'],
            'name'       => (string) $product['name'],
            'quantity'   => $quantity,
            'stock'      => (int) $product['stock'],
            'unit_mrp'   => (float) $pricing['mrp'],
            'unit_price' => (float) $pricing['price'],
            'line_mrp'   => (float) $pricing['mrp'] * $quantity,
            'line_price' => (float) $pricing['price'] * $quantity,
        ];
    }
    return $items;
}

/**
 * The values the form should render: the stored row, overlaid with whatever
 * was just submitted so a failed save re-renders what the admin typed.
 */
function combo_form_state(?int $id): array
{
    // anyStatus, because the admin is the one person who has to be able to
    // open a draft, an inactive or an expired combo.
    $combo = $id !== null ? combo_find($id, true) : combo_form_defaults();

    if ($combo === null) {
        return combo_form_defaults();
    }

    if ($id !== null) {
        $combo['start_date'] = marketing_dt_input($combo['start_date']);
        $combo['end_date']   = marketing_dt_input($combo['end_date']);
        $combo['items']      = combo_form_items($id);
    }

    if (is_post()) {
        // The file inputs are not re-postable, so the stored image paths stay
        // as they are. Everything else comes back from the submit, including
        // the picker's order and its quantities, so a rejected save re-renders
        // the set the operator built rather than the one still on disk.
        $combo          = array_merge($combo, combo_form_input());
        $combo['items'] = combo_form_picked();
    }

    return $combo;
}

// ===========================================================================
//  Slug
// ===========================================================================

/**
 * A slug nothing else in `combos` is already using.
 *
 * unique_slug() and Validator::unique() each carry a hard-coded table
 * allowlist with no `combos` in it, and neither file is ours to edit - so this
 * is the loop product_unique_sku() runs, against the table's own
 * UNIQUE KEY uq_combos_slug. Getting it wrong is a 500 on save, not a silent
 * bug.
 */
function combo_form_slug(string $name, string $slug, ?int $id): string
{
    $base = slugify($slug !== '' ? $slug : $name);
    if ($base === '') {
        $base = 'combo';
    }
    // The column is VARCHAR(220); trimming the base leaves room for a suffix.
    $base = mb_substr($base, 0, 200);

    $candidate = $base;
    $suffix    = 1;
    $where     = '`slug` = :slug' . ($id !== null ? ' AND `id` <> :id' : '');

    while (true) {
        $params = ['slug' => $candidate];
        if ($id !== null) {
            $params['id'] = $id;
        }
        if (!Database::exists('combos', $where, $params)) {
            return $candidate;
        }
        $candidate = $base . '-' . (++$suffix);
    }
}

// ===========================================================================
//  Gallery
// ===========================================================================

/** Newly uploaded gallery files. A rejected file warns and is skipped. */
function combo_form_gallery_upload(): array
{
    $paths = [];
    if (!isset($_FILES['gallery']['name']) || !is_array($_FILES['gallery']['name'])) {
        return $paths;
    }

    foreach ($_FILES['gallery']['name'] as $i => $name) {
        if ((int) $_FILES['gallery']['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $result = upload_image([
            'name'     => $name,
            'type'     => $_FILES['gallery']['type'][$i],
            'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
            'error'    => $_FILES['gallery']['error'][$i],
            'size'     => $_FILES['gallery']['size'][$i],
        ], 'combos');

        if ($result['ok']) {
            $paths[] = (string) $result['path'];
        } else {
            // One rejected picture must not throw away the twenty other fields
            // the operator has just filled in.
            flash('warning', (string) $name . ': ' . (string) $result['error']);
        }
    }
    return $paths;
}

/** Removals, alt text, the reordering and the new files, in that order. */
function combo_form_save_gallery(int $comboId, array $newPaths): void
{
    foreach (array_map('intval', input_array('remove_images')) as $imageId) {
        $image = Database::fetchColumn(
            'SELECT `image` FROM `combo_images` WHERE `id` = :id AND `combo_id` = :cid',
            ['id' => $imageId, 'cid' => $comboId]
        );
        if ($image !== null) {
            Database::delete('combo_images', '`id` = :id', ['id' => $imageId]);
            delete_upload((string) $image);
        }
    }

    $altText = isset($_POST['image_alt']) && is_array($_POST['image_alt']) ? $_POST['image_alt'] : [];
    foreach ($altText as $imageId => $alt) {
        if (!is_scalar($alt)) {
            continue;
        }
        Database::update(
            'combo_images',
            ['alt_text' => trim((string) $alt) !== '' ? mb_substr(trim((string) $alt), 0, 200) : null],
            '`id` = :id AND `combo_id` = :cid',
            ['id' => (int) $imageId, 'cid' => $comboId]
        );
    }

    // The number inputs are what posts here too; the drag handle is a shortcut
    // that rewrites them.
    $order = isset($_POST['image_order']) && is_array($_POST['image_order']) ? $_POST['image_order'] : [];
    foreach ($order as $imageId => $position) {
        if (!is_scalar($position)) {
            continue;
        }
        Database::update(
            'combo_images',
            ['sort_order' => (int) $position],
            '`id` = :id AND `combo_id` = :cid',
            ['id' => (int) $imageId, 'cid' => $comboId]
        );
    }

    // Read the ceiling after the removals and the renumber, so a new file
    // cannot land on a position that is about to be taken.
    $sort = (int) Database::fetchColumn(
        'SELECT COALESCE(MAX(`sort_order`), 0) FROM `combo_images` WHERE `combo_id` = :id',
        ['id' => $comboId]
    );

    foreach ($newPaths as $path) {
        Database::insert('combo_images', [
            'combo_id'   => $comboId,
            'image'      => $path,
            'sort_order' => ++$sort,
        ]);
    }
}

// ===========================================================================
//  Validation + persistence
// ===========================================================================

/**
 * Why this combo may not go live, or null when it may.
 *
 * combo_is_sellable() answers the same question with a bare bool, and a bare
 * bool is no use to an operator staring at a form: its three reasons - too few
 * items, no complete set in stock, no saving - need three different fixes. The
 * verdict still comes from the data layer, so the admin and the storefront
 * cannot drift apart on what "sellable" means.
 *
 * @param array $items combo_form_synthetic_items() output
 */
function combo_form_activation_refusal(array $combo, array $items): ?string
{
    if (count($items) < 2) {
        return 'A live combo needs at least two published products. Save it as a draft while you build it.';
    }

    // Named per product, because "out of stock" on a six-item set is a
    // scavenger hunt otherwise.
    foreach ($items as $item) {
        if ((int) $item['stock'] < (int) $item['quantity']) {
            return '"' . $item['name'] . '" has ' . (int) $item['stock'] . ' in stock but the set needs '
                . (int) $item['quantity'] . ', so no complete set can ship.';
        }
    }

    if ((string) $combo['stock_mode'] === 'track'
        && (int) $combo['stock'] - (int) $combo['sold_count'] < 1) {
        return 'The limited run is finished: ' . (int) $combo['sold_count'] . ' of ' . (int) $combo['stock']
            . ' sets sold. Raise the run, or switch back to following component stock.';
    }

    if (combo_available_sets($combo, $items) < 1) {
        return 'No complete set can be assembled from the stock these components have left.';
    }

    // The honest-saving rule: `regular` is what the components cost TODAY, and
    // a set priced at or above it advertises a discount the shopper could get
    // anyway by adding the items one at a time.
    $pricing = combo_pricing($combo, $items);
    if ($pricing['saving'] <= 0) {
        return 'This combo would save nothing - the set is priced at ' . money($pricing['price'])
            . ' against ' . money($pricing['regular'])
            . ', which is what the items cost today bought separately.';
    }

    return null;
}

/**
 * Validate and write a combo.
 *
 * @param int|null $id null to insert, otherwise the row to update
 * @return array{ok:bool,id:int,errors:array}
 */
function combo_form_save(?int $id): array
{
    $input  = combo_form_input();
    $picked = combo_form_picked();

    // `slug` joins the three it already read: it is what decides whether the
    // slug really moved, and so whether /combo/<old> needs a 301 to survive.
    $current = $id !== null
        ? Database::fetch('SELECT `image`, `banner`, `sold_count`, `slug` FROM `combos` WHERE `id` = :id', ['id' => $id])
        : ['image' => null, 'banner' => null, 'sold_count' => 0, 'slug' => ''];

    if ($current === null) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => ['name' => 'That combo no longer exists.']];
    }

    $start    = marketing_dt_save($input['start_date']);
    $end      = marketing_dt_save($input['end_date']);
    $products = combo_form_products(array_column($picked, 'product_id'));

    $v = new Validator($input, [
        'name'             => 'Combo name',
        'slug'             => 'Slug',
        'subtitle'         => 'Subtitle',
        'price'            => 'Set price',
        'discount_percent' => 'Discount',
        'stock'            => 'Sets in the run',
        'max_per_order'    => 'Maximum per order',
        'badge_text'       => 'Badge text',
        'sort_order'       => 'Sort order',
        'meta_title'       => 'Meta title',
        'og_image'         => 'Social image',
    ]);

    $v->required('name')->max('name', 200)
      ->max('slug', 220)
      ->max('subtitle', 255)
      ->max('badge_text', 40)
      ->max('meta_title', 255)
      ->max('og_image', 255)
      // required() ahead of each in(): in() lets an empty value through, and
      // this MySQL runs without STRICT_TRANS_TABLES, so a post that simply
      // omits one of the three selects writes '' into a NOT NULL enum - a
      // combo with no status, which neither combo_live_sql() nor any list
      // filter can ever match again.
      ->required('pricing_mode')->in('pricing_mode', array_keys(combo_pricing_mode_options()))
      ->required('stock_mode')->in('stock_mode', array_keys(combo_stock_mode_options()))
      ->required('status')->in('status', [STATUS_ACTIVE, STATUS_INACTIVE, STATUS_DRAFT])
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->required('max_per_order')->integer('max_per_order')->between('max_per_order', 1, 255)
      ->rule('start_date', $input['start_date'] === '' || $start !== null, 'Enter a valid start date.')
      ->rule('end_date', $input['end_date'] === '' || $end !== null, 'Enter a valid end date.')
      ->rule('end_date', $start === null || $end === null || $end > $start,
          'The end date must come after the start date.');

    // Only the field the chosen mode actually uses is required; the other one
    // is ignored on the way into the payload.
    if ($input['pricing_mode'] === 'percentage') {
        $v->required('discount_percent')->numeric('discount_percent')
          ->between('discount_percent', 0, 100, 'A percentage discount must be between 0 and 100.');
    } else {
        $v->required('price')->numeric('price')
          ->rule('price', is_numeric($input['price']) && (float) $input['price'] > 0,
              'A fixed set price must be more than zero.');
    }

    if ($input['stock_mode'] === 'track') {
        $v->required('stock')->integer('stock')
          ->between('stock', 1, 999999, 'A limited run needs at least one set.');
    }

    foreach ($picked as $row) {
        if ($row['quantity'] < 1 || $row['quantity'] > 99) {
            $v->rule('quantity', false, 'Every product in the set needs a quantity between 1 and 99.');
            break;
        }
    }

    // A component that has vanished or been unpublished is dropped by
    // combo_items() on the way to the storefront, and the set then prices and
    // sizes itself differently from the one on this screen. Name the product -
    // "one of your products" is not a fix.
    foreach ($picked as $row) {
        $product = $products[$row['product_id']] ?? null;
        if ($product === null) {
            $v->rule('items', false, 'One of the chosen products no longer exists. Remove it and save again.');
            break;
        }
        if ((string) $product['status'] !== STATUS_ACTIVE) {
            $v->rule('items', false, '"' . (string) $product['name'] . '" is ' . (string) $product['status']
                . ', and the storefront will not price a component it does not sell.'
                . ' Publish it, or take it out of the set.');
            break;
        }
    }

    // Draft and inactive combos are always saveable: an operator has to be
    // able to park a half-built set and come back to it. Only `active` makes a
    // promise to a shopper, so only `active` has to be able to keep it.
    if ($input['status'] === STATUS_ACTIVE && !$v->fails()) {
        $preview = [
            'id'               => (int) $id,
            'pricing_mode'     => $input['pricing_mode'],
            'price'            => $input['pricing_mode'] === 'percentage' ? 0.0 : (float) $input['price'],
            'discount_percent' => (float) $input['discount_percent'],
            'stock_mode'       => $input['stock_mode'],
            'stock'            => $input['stock'] === '' ? null : (int) $input['stock'],
            'sold_count'       => (int) $current['sold_count'],
        ];
        $refusal = combo_form_activation_refusal($preview, combo_form_synthetic_items($picked, $products));
        if ($refusal !== null) {
            $v->rule('status', false, $refusal);
        }
    }

    // A typed slug that another combo holds, or that is a reserved address, is
    // refused and named. combo_form_slug() below still quietly numbers a slug
    // DERIVED from the name, which is the right behaviour for something the
    // admin did not choose.
    $slugErrors = [];
    seo_editor_slug_check('combo', $input['slug'], $id, $slugErrors);

    if ($v->fails() || $slugErrors !== []) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $v->errors() + $slugErrors];
    }

    // Images only move once the rest of the form is known good, so a rejected
    // submit never leaves an orphan file in /uploads/combos.
    $image = $current['image'];
    if ((string) input('remove_image', '0') === '1' && empty($_FILES['image']['name'])) {
        delete_upload($image);
        $image = null;
    }
    $image = admin_handle_image('image', 'combos', $image);

    $banner = $current['banner'];
    if ((string) input('remove_banner', '0') === '1' && empty($_FILES['banner']['name'])) {
        delete_upload($banner);
        $banner = null;
    }
    $banner = admin_handle_image('banner', 'combos', $banner);

    $gallery = combo_form_gallery_upload();

    $blankToNull = static fn (string $value): ?string => $value !== '' ? $value : null;

    // `coupon_id`, `sold_count`, `views`, `mrp` and `created_at` are missing on
    // purpose: Database::update() writes only the keys it is handed, and
    // listing them here would reset a coupon pairing or a sales counter every
    // time somebody fixed a typo in the subtitle.
    $payload = [
        'name'             => $input['name'],
        'slug'             => combo_form_slug($input['name'], $input['slug'], $id),
        'subtitle'         => $blankToNull($input['subtitle']),
        'description'      => $blankToNull($input['description']),
        'image'            => $image,
        'banner'           => $banner,
        'pricing_mode'     => $input['pricing_mode'],
        // In percentage mode `price` is derived, not typed: it goes in at zero
        // and combo_recalculate() fills it from what the components cost once
        // the items exist. `mrp` is never written from this form at all.
        'price'            => $input['pricing_mode'] === 'percentage' ? 0.0 : (float) $input['price'],
        'discount_percent' => $input['pricing_mode'] === 'percentage' ? (float) $input['discount_percent'] : 0.0,
        'stock_mode'       => $input['stock_mode'],
        'stock'            => $input['stock_mode'] === 'track' ? (int) $input['stock'] : null,
        'max_per_order'    => (int) $input['max_per_order'],
        'is_featured'      => (int) $input['is_featured'],
        'is_flash'         => (int) $input['is_flash'],
        'is_best_seller'   => (int) $input['is_best_seller'],
        'badge_text'       => $blankToNull($input['badge_text']),
        'start_date'       => $start,
        'end_date'         => $end,
        'sort_order'       => (int) $input['sort_order'],
        'status'           => $input['status'],
    ];

    // The nine SEO columns, in one place rather than nine lines that can drift.
    foreach (SEO_EDITOR_FIELDS as $seoColumn) {
        $payload[$seoColumn] = $blankToNull((string) ($input[$seoColumn] ?? ''));
    }

    // The SEO panel's extended fields, staged before the write so a refused
    // code field can still fail the save.
    $seoErrors = [];
    $seoMeta   = seo_editor_meta_input($seoErrors);
    if ($seoErrors !== []) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $seoErrors];
    }

    $comboId = Database::transaction(static function () use ($id, $payload, $picked): int {
        if ($id === null) {
            $newId = Database::insert('combos', $payload);
        } else {
            Database::update('combos', $payload, '`id` = :id', ['id' => $id]);
            $newId = $id;
        }

        // The list is replaced wholesale; sort_order follows the picker order.
        // A leftover row would go on being priced into the set, so the shopper
        // would pay for a bundle the builder no longer shows.
        Database::delete('combo_items', '`combo_id` = :cid', ['cid' => $newId]);

        $sortOrder = 0;
        foreach ($picked as $row) {
            Database::insert('combo_items', [
                'combo_id'   => $newId,
                'product_id' => $row['product_id'],
                // combo_items() never joins product_variants and never reads
                // this column, so a variant picker here would store a choice
                // nothing honours. NULL until variants are wired.
                'variant_id' => null,
                'quantity'   => $row['quantity'],
                'sort_order' => $sortOrder++,
            ]);
        }

        return $newId;
    });

    combo_form_save_gallery($comboId, $gallery);

    seo_entity_meta_save('combo', $comboId, $seoMeta);
    // A renamed combo keeps its old /combo/<slug> working as a 301 unless the
    // admin unticked the box beside the slug field.
    seo_editor_slug_change('combo', (string) ($current['slug'] ?? ''), (string) $payload['slug']);

    // Outside the transaction, and after the items exist: the stored mrp and
    // price are a cache of a computation over the components, so nothing may
    // read them until this has run.
    combo_recalculate($comboId);

    log_activity(
        $id === null ? 'combo.created' : 'combo.updated',
        'combo',
        $comboId,
        ($id === null ? 'Created' : 'Updated') . ' combo "' . $payload['name'] . '" with '
            . count($picked) . ' product(s)'
    );
    admin_after_write();

    return ['ok' => true, 'id' => $comboId, 'errors' => []];
}
