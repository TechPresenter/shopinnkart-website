<?php
/**
 * ShopInnKart - Nested rows inside a homepage section.
 *
 * A section in `homepage_sections` has always been flat: one widget type, one
 * data source, one band of output. The owner's reference builder puts another
 * level inside it - a section holds ROWS, a row holds ITEMS, and the LAYOUT is
 * chosen per row rather than per section. This file is that model, end to end:
 * it parses the structure, validates what an admin posts, and renders it.
 *
 * WHERE IT LIVES
 * --------------
 * In the `settings` LONGTEXT column `homepage_sections` already carries, under
 * a single `rows` key. No schema change: the column is JSON, it already holds
 * `product_ids`, and a separate table for what is really one section's own
 * shape would mean a join, a second delete path and a second ordering story
 * for no gain.
 *
 * ADDITIVE, DELIBERATELY
 * ----------------------
 * A section with no `rows` key is untouched by every function here -
 * homepage_rows_render() returns an empty string for it and the caller falls
 * through to whatever it did before. That is the whole compatibility promise:
 * the live sections have no `rows` key, so not one of them changes.
 *
 * THE ONE HOOK
 * ------------
 * render_widget() in includes/widgets.php asks this file first. Everything
 * else - the admin form, the designer canvas - calls homepage_rows_render()
 * directly, so there is exactly one renderer and the canvas cannot disagree
 * with the shop.
 *
 * SHAPE
 * -----
 *   settings.rows[] = [
 *     'id'         => 'r_1a2b3c4d',      regenerated server-side on every save
 *     'layout'     => 'grid'|'banner'|'image_title',
 *     'title'      => 'Festive picks',   optional row heading
 *     'columns'    => 1..6,              desktop columns; tablet/mobile derived
 *     'gap'        => 'none'|'sm'|'md'|'lg',
 *     'aspect'     => 'auto'|'1x1'|'4x3'|'3x2'|'16x9'|'21x9',
 *     'visibility' => ['status' => 'active'|'inactive',
 *                      'device_visibility' => ..., 'auth_visibility' => ...],
 *     'items'      => [
 *       [
 *         'id'         => 'i_9f8e7d6c',
 *         'type'       => 'image'|'product'|'category'|'link'|'text',
 *         'visibility' => the same three keys as a row,
 *         'payload'    => ['image','alt','title','text','url','ref_id'],
 *       ],
 *     ],
 *   ]
 *
 * The visibility keys are spelled `device_visibility` / `auth_visibility` on
 * purpose: visibility_allows() in functions.php reads exactly those names, so
 * a row and an item are filtered by the storefront's own rule rather than by a
 * second copy of it that could drift.
 */

declare(strict_types=1);

// A section is a band on a page, not a page builder. These caps are what stops
// one row of JSON growing until the settings column, the form post and the
// render all become someone's afternoon.
//
// They are NOT the whole limit. The form posts one field per value - 8 for a
// row, 10 for an item - and php.ini's max_input_vars (1000 here) is a hard
// ceiling on how many fields reach $_POST. 12 x 24 would be 3,015 fields, so
// the real ceiling is homepage_rows_field_budget() below, and a post that
// crosses it is refused by homepage_rows_post_truncated() rather than
// half-applied. Lifting it for good means posting the rows as one JSON field
// instead of one field per value; until then these two numbers are the
// intent and the budget is the enforcement.
const HOMEPAGE_ROWS_MAX      = 12;
const HOMEPAGE_ROW_ITEMS_MAX = 24;

// What one row and one item cost in form fields. Counted from _form.php's two
// render closures; a field added there has to be added here.
const HOMEPAGE_ROW_FIELDS  = 8;
const HOMEPAGE_ITEM_FIELDS = 10;

/**
 * How many rows-and-items fields one save can carry on THIS server.
 *
 * max_input_vars is per-server, so this is read rather than assumed. The
 * margin covers the section's own ~40 fields plus the csrf token and the
 * completeness marker, with room to spare: being refused one item early is a
 * great deal better than being truncated one item late.
 */
function homepage_rows_field_budget(): int
{
    $max = (int) ini_get('max_input_vars');
    if ($max <= 0) {
        $max = 1000;
    }
    return max(0, $max - 80);
}

/** The most items a whole section can hold once its rows are paid for. */
function homepage_rows_item_budget(int $rowCount): int
{
    return max(0, intdiv(homepage_rows_field_budget() - $rowCount * HOMEPAGE_ROW_FIELDS, HOMEPAGE_ITEM_FIELDS));
}

// ===========================================================================
//  VOCABULARY
// ===========================================================================

/** Row layouts. Adding one means one branch in homepage_rows_render_item(). */
function homepage_row_layouts(): array
{
    return [
        'grid' => [
            'label' => 'Grid',
            'help'  => 'Items side by side, in the number of columns this row asks for.',
        ],
        'banner' => [
            'label' => 'Banner only',
            'help'  => 'Full-width artwork, one item per line. Captions are not drawn.',
        ],
        'image_title' => [
            'label' => 'Image + title',
            'help'  => 'A picture with its name underneath, like the category tiles.',
        ],
    ];
}

/** Item types. Each one is drawn by something the storefront already has. */
function homepage_item_types(): array
{
    return [
        'image'    => ['label' => 'Image',    'help' => 'A picture, optionally linked.'],
        'product'  => ['label' => 'Product',  'help' => 'One product, drawn with the storefront product card.'],
        'category' => ['label' => 'Category', 'help' => 'One category tile, with its live item count.'],
        'link'     => ['label' => 'Link',     'help' => 'A titled link, with optional artwork and a line of copy.'],
        'text'     => ['label' => 'Text',     'help' => 'A block of copy. Basic HTML survives; scripts do not.'],
    ];
}

function homepage_row_gaps(): array
{
    return ['none' => 'None', 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'];
}

/**
 * Who sees a section, a row or an item.
 *
 * These two lived in admin/homepage/_meta.php while only a SECTION could be
 * restricted. A row and an item answer exactly the same questions and are
 * filtered by exactly the same visibility_allows(), so the lists moved down
 * here where both the admin and the storefront can reach them. The keys match
 * the ENUMs on `homepage_sections` and the branches in visibility_allows() -
 * nothing here is invented.
 */
function homepage_device_visibility(): array
{
    return [
        'all'            => 'All devices',
        'desktop'        => 'Desktop only',
        'tablet'         => 'Tablet only',
        'mobile'         => 'Mobile only',
        'desktop_tablet' => 'Desktop + tablet',
        'tablet_mobile'  => 'Tablet + mobile',
    ];
}

function homepage_auth_visibility(): array
{
    return ['all' => 'Everyone', 'guest' => 'Signed-out visitors', 'user' => 'Signed-in customers'];
}

function homepage_row_aspects(): array
{
    return [
        'auto' => 'Natural',
        '1x1'  => 'Square',
        '4x3'  => '4 : 3',
        '3x2'  => '3 : 2',
        '16x9' => '16 : 9',
        '21x9' => '21 : 9 (wide banner)',
    ];
}

/**
 * The payload keys an item may carry, and how long each may be.
 *
 * ONE whitelist for every type, not one per type. _form.php already explains
 * the reasoning for the section's own fields - "hiding them outright makes a
 * saved value invisible when the type changes later" - and it holds twice as
 * hard here, where flipping an item from Image to Link is a two-second mistake
 * that would otherwise cost the admin the caption they had just typed. Unknown
 * keys are still dropped; known-but-unused ones are kept.
 */
function homepage_item_payload_limits(): array
{
    return ['image' => 255, 'alt' => 150, 'title' => 150, 'text' => 2000, 'url' => 255];
}

/** Label for a layout or a type, even for a value written before a rename. */
function homepage_row_layout_label(string $layout): string
{
    return (string) (homepage_row_layouts()[$layout]['label'] ?? ucwords(str_replace('_', ' ', $layout)));
}

function homepage_item_type_label(string $type): string
{
    return (string) (homepage_item_types()[$type]['label'] ?? ucwords(str_replace('_', ' ', $type)));
}

// ===========================================================================
//  READING
// ===========================================================================

/**
 * The rows stored on a section, normalised.
 *
 * Takes the whole row from `homepage_sections` (or anything carrying a
 * `settings` string or array). Returns [] for a section that has none, which
 * is the signal every caller treats as "behave exactly as before".
 *
 * Stored rows are re-normalised on read, not trusted: the column is JSON that
 * a restore, a seed or an older build could have written, and everything below
 * assumes the exact shape in the header.
 */
function homepage_rows_of(array $section): array
{
    $settings = $section['settings'] ?? null;
    $raw = is_array($settings)
        ? ($settings['rows'] ?? null)
        : (json_decode_safe(is_string($settings) ? $settings : null, [])['rows'] ?? null);

    if ($raw === null) {
        return [];
    }

    $notes = [];
    return homepage_rows_normalise($raw, $notes, false);
}

/** True when this section is built from rows rather than from its widget type. */
function homepage_section_has_rows(array $section): bool
{
    return homepage_rows_count($section) > 0;
}

/**
 * How many rows a section carries, without normalising them.
 *
 * homepage_rows_of() re-normalises on every read, and normalising an item
 * whose copy is HTML means a DOMDocument parse through sanitize_html() -
 * measured at about 1.2ms an item on this box, against 0.03ms for an item
 * with no copy. A screen that only wants the NUMBER should not pay that, and
 * the list screen asks it of every section on the page at once.
 *
 * Nothing here is rendered or stored, so nothing here needs sanitising: the
 * answer is a count, clamped to the same cap the model enforces so that a
 * hand-edited column cannot make the list disagree with the editor.
 */
function homepage_rows_count(array $section): int
{
    $settings = $section['settings'] ?? null;
    $raw = is_array($settings)
        ? ($settings['rows'] ?? null)
        : (json_decode_safe(is_string($settings) ? $settings : null, [])['rows'] ?? null);

    return is_array($raw)
        ? min(HOMEPAGE_ROWS_MAX, count(array_filter($raw, 'is_array')))
        : 0;
}

// ===========================================================================
//  VALIDATION
// ===========================================================================

/**
 * A fresh server-side id.
 *
 * Whatever the browser posted as an id is thrown away and replaced here. Ids
 * are only ever DOM keys, so nothing points at them - and regenerating means a
 * crafted form cannot make two rows share a key, smuggle markup through one,
 * or grow one to a megabyte.
 */
function homepage_rows_new_id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(4));
}

/** A stored id, scrubbed to the shape this file writes, or a fresh one. */
function homepage_rows_safe_id(string $id, string $prefix): string
{
    return preg_match('/^[ri]_[0-9a-f]{8}$/', $id) === 1 ? $id : homepage_rows_new_id($prefix);
}

/**
 * A stored image path, or '' when it is not one.
 *
 * The same rule delete_upload() enforces - root-relative, under uploads/, no
 * traversal - plus an extension check, because this value is written straight
 * into an <img>. `assets/images/` is allowed alongside it because the shipped
 * artwork lives there and widgets.php already serves it from there (see
 * banner_image_is_stock()); nothing else on disk is reachable.
 *
 * Existence is NOT required: img_url() already falls back to the placeholder
 * for a path whose file has gone, and refusing to save one would mean an admin
 * could not name a path before the upload lands.
 */
function homepage_rows_image_path(string $raw): string
{
    $path = ltrim(trim($raw), '/');
    if ($path === '') {
        return '';
    }

    // chr(92) rather than an escaped literal, and a POSIX class rather than a
    // \x escape: files in this tree have been written through a heredoc more
    // than once and a heredoc eats the escape (see admin_safe_return()).
    if (strpos($path, chr(92)) !== false || preg_match('/[[:cntrl:]]/', $path) === 1) {
        return '';
    }
    if (strpos($path, '..') !== false) {
        return '';
    }
    if (strpos($path, 'uploads/') !== 0 && strpos($path, 'assets/images/') !== 0) {
        return '';
    }
    if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ALLOWED_IMAGE_EXTENSIONS, true)) {
        return '';
    }

    return mb_substr($path, 0, 255);
}

/**
 * A link target, or '' when it is not one.
 *
 * Relative paths (the usual case - "shop.php?sort=new") and absolute http(s)
 * URLs are kept. Anything carrying another scheme is refused, which is how
 * `javascript:` and `data:` would get in; so is a scheme-relative "//host",
 * which reads like a path and is not one.
 */
function homepage_rows_url(string $raw): string
{
    $url = trim($raw);
    if ($url === '') {
        return '';
    }
    if (preg_match('/[[:cntrl:][:space:]]/', $url) === 1) {
        return '';
    }
    if (strncmp($url, '//', 2) === 0) {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 && preg_match('#^https?://#i', $url) !== 1) {
        return '';
    }

    return mb_substr($url, 0, 255);
}

/**
 * A posted value as a plain string, or the default when it is not one.
 *
 * Every vocabulary slot below is read straight out of $_POST, where a name
 * ending in `[]` - `rows[0][gap][]=x` - arrives as an ARRAY. A plain (string)
 * cast on an array raises "Array to string conversion", and this project's
 * ErrorHandler turns a warning into an exception, so one crafted field would
 * 500 the whole save instead of being clamped like every other bad value.
 * Nothing here may throw: that is the promise the header makes.
 */
function homepage_rows_str($value, string $default = ''): string
{
    return is_scalar($value) ? (string) $value : $default;
}

/** The visibility block, shared by rows and items. */
function homepage_rows_visibility($raw): array
{
    $raw    = is_array($raw) ? $raw : [];
    $status = homepage_rows_str($raw['status'] ?? null, 'active');
    $device = homepage_rows_str($raw['device_visibility'] ?? null, 'all');
    $auth   = homepage_rows_str($raw['auth_visibility'] ?? null, 'all');

    return [
        'status'            => $status === 'inactive' ? 'inactive' : 'active',
        'device_visibility' => isset(homepage_device_visibility()[$device]) ? $device : 'all',
        'auth_visibility'   => isset(homepage_auth_visibility()[$auth]) ? $auth : 'all',
    ];
}

/**
 * Turn anything at all into a valid rows array.
 *
 * Strict in the sense that matters for stored JSON: it never throws and never
 * half-accepts. Every key is whitelisted, every value is clamped to a legal
 * one, every id is replaced, and the caps are enforced by truncation. What
 * comes out is always renderable.
 *
 * $notes collects what was dropped, so the caller can say so rather than leave
 * an admin wondering where their thirteenth row went.
 *
 * @param mixed $raw   whatever was posted or stored
 * @param array $notes out-param, by reference
 * @param bool  $fresh regenerate every id (a save), or keep the stored ones
 */
function homepage_rows_normalise($raw, array &$notes = [], bool $fresh = true): array
{
    $notes = [];
    if (!is_array($raw)) {
        return [];
    }

    // array_values, because a drag posts the rows in DOM order under whatever
    // indices the markup happened to be carrying.
    $raw = array_values($raw);
    if (count($raw) > HOMEPAGE_ROWS_MAX) {
        $notes[] = 'Only the first ' . HOMEPAGE_ROWS_MAX . ' rows were kept.';
        $raw = array_slice($raw, 0, HOMEPAGE_ROWS_MAX);
    }

    $layouts = homepage_row_layouts();
    $gaps    = homepage_row_gaps();
    $aspects = homepage_row_aspects();
    $types   = homepage_item_types();
    $limits  = homepage_item_payload_limits();

    $rows = [];
    foreach ($raw as $rowRaw) {
        if (!is_array($rowRaw)) {
            continue;
        }

        $layout = homepage_rows_str($rowRaw['layout'] ?? null, 'grid');
        if (!isset($layouts[$layout])) {
            $layout = 'grid';
        }
        $gap = homepage_rows_str($rowRaw['gap'] ?? null, 'md');
        if (!isset($gaps[$gap])) {
            $gap = 'md';
        }
        $aspect = homepage_rows_str($rowRaw['aspect'] ?? null, 'auto');
        if (!isset($aspects[$aspect])) {
            $aspect = 'auto';
        }

        // A banner is one thing across the width by definition, so its column
        // count is not an option an admin can get wrong.
        $columns = $layout === 'banner'
            ? 1
            : max(1, min(6, (int) ($rowRaw['columns'] ?? 3)));

        $itemsRaw = is_array($rowRaw['items'] ?? null) ? array_values($rowRaw['items']) : [];
        if (count($itemsRaw) > HOMEPAGE_ROW_ITEMS_MAX) {
            $notes[] = 'A row was trimmed to ' . HOMEPAGE_ROW_ITEMS_MAX . ' items.';
            $itemsRaw = array_slice($itemsRaw, 0, HOMEPAGE_ROW_ITEMS_MAX);
        }

        $items = [];
        foreach ($itemsRaw as $itemRaw) {
            if (!is_array($itemRaw)) {
                continue;
            }

            $type = homepage_rows_str($itemRaw['type'] ?? null, 'image');
            if (!isset($types[$type])) {
                $type = 'image';
            }

            $payloadRaw = is_array($itemRaw['payload'] ?? null) ? $itemRaw['payload'] : [];
            $payload = [];
            foreach ($limits as $key => $max) {
                $value = $payloadRaw[$key] ?? '';
                $payload[$key] = mb_substr(is_scalar($value) ? trim((string) $value) : '', 0, $max);
            }

            // Three of the six are not plain text and are not treated as such.
            $payload['image']  = homepage_rows_image_path($payload['image']);
            $payload['url']    = homepage_rows_url($payload['url']);
            $payload['text']   = $payload['text'] === '' ? '' : sanitize_html($payload['text']);
            $payload['ref_id'] = max(0, (int) ($payloadRaw['ref_id'] ?? 0));

            $storedId = $itemRaw['id'] ?? null;
            $items[] = [
                'id'         => $fresh || !is_string($storedId)
                    ? homepage_rows_new_id('i_')
                    : homepage_rows_safe_id($storedId, 'i_'),
                'type'       => $type,
                'visibility' => homepage_rows_visibility($itemRaw['visibility'] ?? null),
                'payload'    => $payload,
            ];
        }

        $storedId = $rowRaw['id'] ?? null;
        $title    = $rowRaw['title'] ?? '';
        $rows[] = [
            'id'         => $fresh || !is_string($storedId)
                ? homepage_rows_new_id('r_')
                : homepage_rows_safe_id($storedId, 'r_'),
            'layout'     => $layout,
            'title'      => mb_substr(is_scalar($title) ? trim((string) $title) : '', 0, 150),
            'columns'    => $columns,
            'gap'        => $gap,
            'aspect'     => $aspect,
            'visibility' => homepage_rows_visibility($rowRaw['visibility'] ?? null),
            // An EMPTY row is kept. The designer has to be able to add a row
            // and then fill it, and a row that vanished on save would make
            // that impossible.
            'items'      => $items,
        ];
    }

    return $rows;
}

// ===========================================================================
//  SAVING
// ===========================================================================

/**
 * True when PHP dropped the tail of this post before user code ever ran.
 *
 * WHY THIS EXISTS
 * ---------------
 * A row costs 8 form fields and an item costs 10, and php.ini's
 * `max_input_vars` - 1000 on this box, and on most - is a hard ceiling on how
 * many name=value pairs reach $_POST at all. PHP does not fail the request
 * when the body carries more: it parses the first 1000 in order, throws the
 * rest away and carries on.
 *
 * The Rows card sits in the MIDDLE of the section form, so the tail that gets
 * thrown away is not only the later rows. It is every one of the section's own
 * fields that is rendered after the card - layout, card style, colours,
 * container, padding, animation, visibility, lazy load, sort order,
 * product_ids. Those arrive absent, homepage_form_input() reads its defaults
 * for each, and the save writes the defaults back. Measured on this build: a
 * save of 12 rows of 24 items (3,015 fields) stored 4 rows AND silently reset
 * 17 columns, while flashing "Section saved."
 *
 * So a truncated post is refused outright rather than half-applied. The marker
 * is the LAST field in the form: if it did not arrive, neither did everything
 * between it and wherever PHP stopped.
 *
 * This is a guard, not the cure. The cure is to stop sending one field per
 * value - see the note on HOMEPAGE_ROWS_MAX.
 */
function homepage_rows_post_truncated(): bool
{
    // Only a form that renders the Rows card sends both markers, so no other
    // endpoint can be refused by this.
    return input_bool('rows_present') && !input_bool('form_complete');
}

/**
 * The rows a form posted, or null when the form did not carry the fieldset.
 *
 * null and [] are different answers and the difference matters: null means
 * "this post says nothing about rows, leave them alone", [] means "the admin
 * deleted the last one". Only a form that renders the Rows card sends the
 * `rows_present` marker, so a post from anywhere else can never wipe them.
 */
function homepage_rows_input(array &$notes = []): ?array
{
    if (!input_bool('rows_present')) {
        $notes = [];
        return null;
    }

    $raw = $_POST['rows'] ?? null;
    return homepage_rows_normalise(is_array($raw) ? $raw : [], $notes, true);
}

/**
 * Fold rows into an existing settings JSON string.
 *
 * Every other key survives untouched - `product_ids` above all, which
 * homepage_merge_settings() writes and widgets.php reads. $rows === null
 * leaves whatever is stored alone; [] removes the key entirely, so a section
 * whose last row was deleted is byte-for-byte a section that never had one.
 */
function homepage_rows_merge_settings(?string $currentJson, ?array $rows): ?string
{
    $settings = json_decode_safe($currentJson, []);

    if ($rows !== null) {
        if ($rows === []) {
            unset($settings['rows']);
        } else {
            $settings['rows'] = $rows;
        }
    }

    return $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_SLASHES);
}

// ===========================================================================
//  RENDERING
// ===========================================================================

/** "1 item - Grid": the line the inspector puts under a row's name. */
function homepage_row_summary(array $row): string
{
    $count = count($row['items'] ?? []);
    return ($count === 1 ? '1 item' : $count . ' items')
        . ' - ' . homepage_row_layout_label((string) ($row['layout'] ?? 'grid'));
}

/** CSS aspect-ratio value for a row's aspect key; '' means natural. */
function homepage_row_aspect_css(string $aspect): string
{
    return [
        '1x1'  => '1 / 1',
        '4x3'  => '4 / 3',
        '3x2'  => '3 / 2',
        '16x9' => '16 / 9',
        '21x9' => '21 / 9',
    ][$aspect] ?? '';
}

/**
 * The one stylesheet these rows need, printed once per request.
 *
 * It lives here rather than in assets/css/app.css for a boring reason - other
 * teams are in that file - and for a better one: nothing outside a rows-built
 * section uses these rules, and a storefront with no such section should not
 * download them. Everything else reuses app.css tokens, so a row inherits the
 * store's radius, colours and type scale without restating any of them.
 */
function homepage_rows_styles(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style id="hr-css">'
        . '.hr-rows{display:grid;gap:var(--sp-8,32px)}'
        . '.hr-row{display:grid;gap:var(--sp-4,16px);min-width:0}'
        . '.hr-row__title{margin:0;font-size:var(--fs-lg,18px);font-weight:var(--fw-semibold,600);'
        . 'line-height:var(--lh-snug,1.35);color:var(--sik-ink,inherit)}'
        . '.hr-items{display:grid;gap:var(--hr-gap,20px);'
        . 'grid-template-columns:repeat(var(--hr-cols-mobile,2),minmax(0,1fr))}'
        . '@media(min-width:768px){.hr-items{grid-template-columns:repeat(var(--hr-cols-tablet,3),minmax(0,1fr))}}'
        . '@media(min-width:1024px){.hr-items{grid-template-columns:repeat(var(--hr-cols-desktop,4),minmax(0,1fr))}}'
        /* A banner is one across the width at every size, so it overrides all
           three tracks - and must come after them to win on equal specificity. */
        . '.hr-items--banner{grid-template-columns:minmax(0,1fr)}'
        . '@media(min-width:768px){.hr-items--banner{grid-template-columns:minmax(0,1fr)}}'
        . '@media(min-width:1024px){.hr-items--banner{grid-template-columns:minmax(0,1fr)}}'
        . '.hr-item{display:block;min-width:0;color:inherit;text-decoration:none}'
        . '.hr-media{position:relative;display:block;width:100%;overflow:hidden;'
        . 'border-radius:var(--sik-radius,12px);background:var(--sik-media,#F1F1F1);'
        . 'border:1px solid var(--sik-card-border,var(--sik-line,#E5E7EB));'
        . 'aspect-ratio:var(--hr-aspect,1 / 1)}'
        . '.hr-media img{display:block;width:100%;height:100%;object-fit:cover;'
        . 'transition:transform var(--dur-slower,.5s) var(--ease-out,ease)}'
        . '.hr-media--auto{aspect-ratio:auto}'
        . '.hr-media--auto img{height:auto}'
        . '@media(hover:hover){a.hr-item:hover .hr-media img{transform:scale(1.04)}'
        . 'a.hr-item:hover .hr-title{text-decoration:underline;text-underline-offset:3px}}'
        . '.hr-body{display:grid;gap:2px;margin-top:var(--sp-3,12px);min-width:0}'
        . '.hr-title{font-size:var(--fs-md,15px);font-weight:var(--fw-semibold,600);'
        . 'line-height:var(--lh-snug,1.35);color:var(--sik-ink,inherit)}'
        . '.hr-text{font-size:var(--fs-sm,13px);line-height:1.55;color:var(--sik-muted,#6B7280)}'
        . '.hr-prose{min-width:0;font-size:var(--fs-md,15px);line-height:1.65}'
        . '.hr-ghost{opacity:.42;filter:grayscale(.55)}'
        . '.hr-hole{padding:20px;border:1px dashed var(--sik-line,#CBD5E1);'
        . 'border-radius:var(--sik-radius,12px);text-align:center;'
        . 'color:var(--sik-muted,#6B7280);font:500 13px/1.5 system-ui,sans-serif}'
        . '</style>';
}

/** Absolute URLs pass through; everything else resolves against the store. */
function homepage_rows_href(string $url): string
{
    return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
}

/**
 * Render every row on a section. THE one renderer.
 *
 * The storefront, the designer canvas and anything added later all come
 * through here, so what an admin sees is what a shopper gets.
 *
 * Returns '' for a section with no rows, which is the caller's signal to carry
 * on exactly as it did before this file existed.
 *
 * @param array $context 'preview' => true draws hidden rows dimmed and puts a
 *              caption where an empty row would otherwise be invisible, the
 *              way admin/homepage/preview.php already does for hidden
 *              sections. On the storefront they are simply not rendered.
 */
function homepage_rows_render(array $section, array $context = []): string
{
    $rows = homepage_rows_of($section);
    if ($rows === []) {
        return '';
    }

    // product_card() and category_tile() belong to the storefront and live in
    // widgets.php, which requires this file back. Loading it here rather than
    // at the top keeps that cycle out of the include order: by render time
    // every function is defined whichever of the two was entered first.
    if (!function_exists('product_card')) {
        require_once INCLUDES_PATH . '/widgets.php';
    }

    $preview   = !empty($context['preview']);
    $cardStyle = (string) ($section['card_style'] ?? 'standard');

    $body = '';
    foreach ($rows as $index => $row) {
        $body .= homepage_rows_render_row($row, $index, $preview, $cardStyle);
    }

    if (trim($body) === '') {
        // Every row is hidden or empty. On the shop that means the section
        // draws nothing at all, leaving no empty band of section rhythm
        // behind - the same call render_widget()'s lazy shell makes.
        return $preview
            ? homepage_rows_shell($section, '<div class="hr-hole">Every row in this section is hidden.</div>')
            : '';
    }

    return homepage_rows_shell($section, '<div class="hr-rows">' . $body . '</div>');
}

/**
 * The section chrome around the rows: the same wrapper, heading and container
 * rules every other widget uses, taken from widgets.php rather than restated.
 */
function homepage_rows_shell(array $section, string $inner): string
{
    ob_start();
    ?>
    <section <?= widget_section_attrs($section) ?>>
        <div class="<?= ($section['container'] ?? 'boxed') === 'full' ? '' : 'sik-container' ?>">
            <?php widget_heading($section); ?>
            <div data-anim="<?= e_attr((string) ($section['animation'] ?? 'fade-up')) ?>">
                <?= $inner ?>
            </div>
        </div>
    </section>
    <?php
    return homepage_rows_styles() . (string) ob_get_clean();
}

/** One row. Returns '' when the storefront should not draw it. */
function homepage_rows_render_row(array $row, int $index, bool $preview, string $cardStyle): string
{
    $hidden = !homepage_rows_visible($row['visibility']);
    if ($hidden && !$preview) {
        return '';
    }

    $layout  = (string) $row['layout'];
    $columns = (int) $row['columns'];
    $aspect  = homepage_row_aspect_css((string) $row['aspect']);
    $gapPx   = ['none' => '0px', 'sm' => '10px', 'md' => '20px', 'lg' => '36px'][$row['gap']] ?? '20px';

    // Tablet and mobile are derived, not asked for. A row is a band inside a
    // section, and making an admin answer the columns question three times per
    // row - for a phone that can never show six tiles - is a form nobody fills
    // in correctly twice.
    $style = '--hr-gap:' . $gapPx
        . ';--hr-cols-desktop:' . $columns
        . ';--hr-cols-tablet:' . max(1, min($columns, 3))
        . ';--hr-cols-mobile:' . max(1, min($columns, 2));
    if ($aspect !== '') {
        $style .= ';--hr-aspect:' . $aspect;
    }

    $items = '';
    foreach ($row['items'] as $item) {
        $items .= homepage_rows_render_item($item, $row, $preview, $cardStyle);
    }

    if (trim($items) === '') {
        if (!$preview) {
            return '';
        }
        // The canvas has to show an empty row, or "Add row" looks broken.
        $items = '<div class="hr-hole">Row ' . ($index + 1) . ' has no items yet.</div>';
    }

    return '<div class="hr-row' . ($hidden ? ' hr-ghost' : '') . '">'
        . ($row['title'] !== '' ? '<h3 class="hr-row__title">' . e($row['title']) . '</h3>' : '')
        . '<div class="hr-items' . ($layout === 'banner' ? ' hr-items--banner' : '')
        . '" style="' . e_attr($style) . '">' . $items . '</div>'
        . '</div>';
}

/** Storefront visibility for a row or an item. */
function homepage_rows_visible(array $visibility): bool
{
    if (($visibility['status'] ?? 'active') !== 'active') {
        return false;
    }
    // The storefront's own device/audience rule, not a second copy of it.
    return visibility_allows($visibility);
}

/** One item, drawn the way its row's layout asks for. */
function homepage_rows_render_item(array $item, array $row, bool $preview, string $cardStyle): string
{
    $hidden = !homepage_rows_visible($item['visibility']);
    if ($hidden && !$preview) {
        return '';
    }

    $layout  = (string) $row['layout'];
    $type    = (string) $item['type'];
    $payload = $item['payload'];

    // A product or a category in a Grid row is the storefront's OWN card, so
    // the price, the badges, the stock state and Add to Cart are the shop's.
    // There is no second product card anywhere in this file.
    if ($layout === 'grid' && $type === 'product') {
        $html = homepage_rows_product_card($payload['ref_id'], $cardStyle);
    } elseif ($layout === 'grid' && $type === 'category') {
        $html = homepage_rows_category_tile($payload['ref_id']);
    } elseif ($type === 'text') {
        $html = $payload['text'] !== '' ? '<div class="hr-prose">' . $payload['text'] . '</div>' : '';
    } else {
        $html = homepage_rows_tile($item, $row);
    }

    if (trim($html) === '') {
        if (!$preview) {
            return '';
        }
        $html = '<div class="hr-hole">' . e(homepage_item_type_label($type))
            . ' item - nothing to show yet.</div>';
    }

    return $hidden ? '<div class="hr-ghost">' . $html . '</div>' : $html;
}

/**
 * The picture-first tile: banner rows, image+title rows, and every image or
 * link item wherever it sits. One function, because "a picture with an
 * optional caption and an optional link" is the same thing in all three.
 */
function homepage_rows_tile(array $item, array $row): string
{
    $payload = $item['payload'];
    $type    = (string) $item['type'];

    // A product or a category asked for as a tile borrows its own artwork and
    // name, so an admin does not have to retype either - and so a rename on
    // the product reaches the homepage by itself.
    if ($type === 'product') {
        $product = homepage_rows_product($payload['ref_id']);
        if ($product === null) {
            return '';
        }
        $src   = (string) $product['image_url'];
        $title = $payload['title'] !== '' ? $payload['title'] : (string) $product['name'];
        $href  = (string) $product['url'];
    } elseif ($type === 'category') {
        $category = homepage_rows_category($payload['ref_id']);
        if ($category === null) {
            return '';
        }
        $src   = (string) category_tile_image($category)['src'];
        $title = $payload['title'] !== '' ? $payload['title'] : (string) $category['name'];
        $href  = category_url((string) $category['slug']);
    } else {
        // image and link: a picture, a caption and a link, in any combination.
        if ($payload['image'] === '' && $payload['title'] === '') {
            return '';
        }
        $src   = $payload['image'] === '' ? '' : img_url($payload['image']);
        $title = $payload['title'];
        $href  = $payload['url'];
    }

    // A banner is artwork across the width; a caption under it would repeat
    // words the picture almost always has baked in already.
    $showBody = (string) $row['layout'] !== 'banner';
    $natural  = (string) $row['aspect'] === 'auto';

    $media = '';
    if ($src !== '') {
        $media = '<span class="hr-media' . ($natural ? ' hr-media--auto' : '') . '">'
            . '<img src="' . e($src) . '" alt="' . e_attr($payload['alt']) . '"'
            . ' loading="lazy" decoding="async">'
            . '</span>';
    }

    $body = '';
    if ($showBody && ($title !== '' || $payload['text'] !== '')) {
        $body = '<span class="hr-body">'
            . ($title !== '' ? '<span class="hr-title">' . e($title) . '</span>' : '')
            . ($payload['text'] !== '' ? '<span class="hr-text">' . $payload['text'] . '</span>' : '')
            . '</span>';
    }

    if ($media === '' && $body === '') {
        return '';
    }

    return $href !== ''
        ? '<a class="hr-item" href="' . e(homepage_rows_href($href)) . '">' . $media . $body . '</a>'
        : '<div class="hr-item">' . $media . $body . '</div>';
}

/**
 * One product, through the storefront's own query, so that an archived,
 * out-of-stock-hidden or deleted product falls out of the row by itself
 * instead of rendering a card for something nobody can buy.
 */
function homepage_rows_product(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = get_products_for_source('manual', 1, null, [$id])[0] ?? null;
    }
    return $cache[$id];
}

function homepage_rows_product_card(int $id, string $cardStyle = 'standard'): string
{
    $product = homepage_rows_product($id);
    return $product === null ? '' : product_card($product, $cardStyle);
}

/** One active category, with the live item count its tile prints. */
function homepage_rows_category(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = Database::fetch(
            "SELECT c.`id`, c.`name`, c.`slug`, c.`image`,
                    (SELECT COUNT(*) FROM `products` p
                      WHERE p.`category_id` = c.`id` AND p.`status` = 'active') AS `product_count`
               FROM `categories` c
              WHERE c.`id` = :id AND c.`status` = 'active'",
            ['id' => $id]
        );
    }
    return $cache[$id];
}

function homepage_rows_category_tile(int $id): string
{
    $category = homepage_rows_category($id);
    return $category === null ? '' : category_tile($category);
}
