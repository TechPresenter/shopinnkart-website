<?php
/**
 * ShopInnKart Admin - Shared marketing helpers.
 *
 * Coupons, deals, flash sales and banners all schedule themselves with the
 * same start/end pair, and three of the four attach products the same way.
 * Keeping the state badge, the schedule filter and the product picker here
 * means the four screens can never drift apart on what "Live" means.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

// ===========================================================================
//  Scheduling
// ===========================================================================

/** Stored datetime -> value for <input type="datetime-local">. */
function marketing_dt_input($value): string
{
    if (empty($value)) {
        return '';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

/** <input type="datetime-local"> -> stored datetime, or null when left blank. */
function marketing_dt_save($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Where a scheduled row sits right now: inactive, scheduled, live or expired.
 * A live row whose window shuts inside three days reports back as "Ends in …"
 * so the list surfaces what needs renewing before a customer runs into it.
 */
function marketing_state($start, $end, string $status = 'active'): array
{
    if ($status !== 'active') {
        return ['key' => 'inactive', 'label' => 'Inactive', 'tone' => 'gray'];
    }

    $now = time();
    if (!empty($start) && strtotime((string) $start) > $now) {
        return ['key' => 'scheduled', 'label' => 'Scheduled', 'tone' => 'blue'];
    }
    if (!empty($end) && strtotime((string) $end) < $now) {
        return ['key' => 'expired', 'label' => 'Expired', 'tone' => 'red'];
    }
    if (!empty($end)) {
        $left = seconds_until($end);
        if ($left > 0 && $left <= 259200) {
            return ['key' => 'live', 'label' => 'Ends ' . marketing_time_left($left), 'tone' => 'amber'];
        }
    }
    return ['key' => 'live', 'label' => 'Live', 'tone' => 'green'];
}

/** "in 2 days" / "in 5 hours" / "in 12 min". */
function marketing_time_left(int $seconds): string
{
    if ($seconds >= 86400) {
        $days = (int) floor($seconds / 86400);
        return 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
    }
    if ($seconds >= 3600) {
        $hours = (int) floor($seconds / 3600);
        return 'in ' . $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    return 'in ' . max(1, (int) floor($seconds / 60)) . ' min';
}

/** Pill for a marketing_state() result. */
function marketing_state_badge(array $state): string
{
    return '<span class="sik-status sik-status--' . e_attr($state['tone']) . '">' . e($state['label']) . '</span>';
}

/** The filter options offered by every marketing list screen. */
function marketing_state_options(): array
{
    return [
        'live'      => 'Live now',
        'scheduled' => 'Scheduled',
        'expired'   => 'Expired',
        'inactive'  => 'Inactive',
    ];
}

/**
 * WHERE fragment for the live/scheduled/expired filter.
 * Table alias and column names are supplied by the calling page, never by the
 * request, so nothing user-controlled reaches the SQL.
 */
function marketing_state_where(string $state, string $alias, string $startColumn, string $endColumn): string
{
    $start = $alias . '.`' . $startColumn . '`';
    $end   = $alias . '.`' . $endColumn . '`';
    $live  = $alias . ".`status` = 'active'";

    switch ($state) {
        case 'live':
            return "{$live} AND ({$start} IS NULL OR {$start} <= NOW()) AND ({$end} IS NULL OR {$end} >= NOW())";
        case 'scheduled':
            return "{$live} AND {$start} IS NOT NULL AND {$start} > NOW()";
        case 'expired':
            return "{$end} IS NOT NULL AND {$end} < NOW()";
        case 'inactive':
            return $alias . ".`status` = 'inactive'";
        default:
            return '1';
    }
}

/** Human summary of a schedule window for a table cell. */
function marketing_window_text($start, $end): string
{
    $from = empty($start) ? 'Always' : format_datetime($start, 'd M Y, g:i A');
    $to   = empty($end) ? 'no end date' : format_datetime($end, 'd M Y, g:i A');
    return $from . ' → ' . $to;
}

// ===========================================================================
//  Usage / stock meters
// ===========================================================================

/**
 * Redemption meter. A NULL or zero limit means unlimited, which is a number
 * a bar cannot draw, so that case reports the raw count instead.
 */
function marketing_usage_bar(int $used, ?int $limit, string $noun = 'used'): string
{
    if ($limit === null || $limit <= 0) {
        return '<div style="min-width:120px">'
            . '<strong>' . number_format($used) . '</strong>'
            . '<span class="ad-cellflex__meta"> ' . e($noun) . ' &middot; no limit</span>'
            . '</div>';
    }

    $percent = min(100.0, ($used / $limit) * 100);
    $tone = $percent >= 100 ? 'var(--ad-danger)' : ($percent >= 80 ? 'var(--ad-warning)' : 'var(--ad-primary)');

    return '<div style="min-width:120px">'
        . '<div class="ad-cellflex__meta" style="margin-bottom:4px">'
        . '<strong style="color:var(--ad-ink)">' . number_format($used) . '</strong> / ' . number_format($limit)
        . ' &middot; ' . round($percent) . '%</div>'
        . '<div class="sik-progress"><span style="width:' . round($percent, 2) . '%;background:' . $tone . '"></span></div>'
        . '</div>';
}

// ===========================================================================
//  Option lists
// ===========================================================================

/**
 * id => indented name for the multi-select restriction pickers.
 * admin_category_options() renders <option> markup for a single-choice field,
 * which cannot mark several rows selected, so the tree is flattened here.
 */
function marketing_category_choices(): array
{
    $rows = Database::fetchAll('SELECT `id`, `name`, `parent_id` FROM `categories` ORDER BY `sort_order`, `name`');

    $byParent = [];
    foreach ($rows as $row) {
        $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
    }

    $choices = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, $byParent, &$choices): void {
        foreach ($byParent[$parentId] ?? [] as $row) {
            $id = (int) $row['id'];
            $choices[$id] = str_repeat('— ', $depth) . (string) $row['name'];
            $walk($id, $depth + 1);
        }
    };
    $walk(0, 0);

    return $choices;
}

// ===========================================================================
//  Product picker
// ===========================================================================

/**
 * Product rows for a list of ids, returned in the order the ids were given
 * so a saved sort survives the round trip.
 */
function marketing_product_rows(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    [$placeholders, $params] = Database::inPlaceholders($ids, 'pid');
    $rows = Database::fetchAll(
        'SELECT `id`, `name`, `sku`, `main_image`, `price`, `sale_price`, `stock`
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

/** Selling price a picker row should show as its "current" price. */
function marketing_product_price(array $product): float
{
    $sale = $product['sale_price'] ?? null;
    return ($sale !== null && (float) $sale > 0) ? (float) $sale : (float) $product['price'];
}

/**
 * Read a picker back out of the request.
 *
 * @param string $name   hidden input name used by the picker
 * @param array  $fields extra per-row input names, e.g. ['deal_price']
 * @return array productId => [field => raw submitted value], in posted order
 */
function marketing_picked_products(string $name, array $fields = []): array
{
    $picked = [];

    foreach (input_array($name) as $raw) {
        $productId = (int) $raw;
        if ($productId <= 0 || isset($picked[$productId])) {
            continue;
        }

        $values = [];
        foreach ($fields as $field) {
            $bag = $_POST[$field] ?? [];
            $values[$field] = is_array($bag) ? trim((string) ($bag[$productId] ?? '')) : '';
        }
        $picked[$productId] = $values;
    }

    return $picked;
}

/**
 * Render the searchable product picker.
 *
 * $options:
 *   name    string  hidden input name ('product_ids' posts product_ids[])
 *   single  bool    accept one product only; posts `name` without the []
 *   rows    array   attached rows: ['id','name','sku','image','meta','values'=>[field=>value]]
 *   fields  array   per-row numeric inputs: ['name','label','placeholder','step','width']
 *   empty   string  text shown while nothing is attached
 *   help    string  hint under the search box
 */
function marketing_product_picker(array $options): string
{
    $name   = (string) ($options['name'] ?? 'product_ids');
    $single = !empty($options['single']);
    $rows   = $options['rows'] ?? [];
    $fields = $options['fields'] ?? [];
    $emptyText = (string) ($options['empty'] ?? 'No products attached yet.');
    $help   = (string) ($options['help'] ?? '');

    $html = marketing_picker_assets();

    $html .= '<div class="ad-picker" data-mkpick'
        . ' data-mkpick-name="' . e_attr($name) . '"'
        . ' data-mkpick-single="' . ($single ? '1' : '0') . '"'
        . ' data-mkpick-fields="' . e_attr((string) json_encode(array_values($fields))) . '">';

    $html .= '<div class="ad-picker__search">'
        . icon('search', 'w-4 h-4')
        . '<input class="sik-input" type="search" data-mkpick-search autocomplete="off"'
        . ' placeholder="Search products by name or SKU&hellip;" aria-label="Search products to attach">'
        . '<div class="ad-picker__results" data-mkpick-results hidden></div>'
        . '</div>';

    if ($help !== '') {
        $html .= '<span class="sik-help">' . e($help) . '</span>';
    }

    $html .= '<div class="ad-picker__list" data-mkpick-list>';
    foreach ($rows as $row) {
        $html .= marketing_picker_row($name, $single, $fields, $row);
    }
    $html .= '</div>';

    $html .= '<p class="ad-picker__empty ad-muted" data-mkpick-empty' . ($rows !== [] ? ' hidden' : '') . '>'
        . e($emptyText) . '</p>';

    return $html . '</div>';
}

/** One attached row. The picker's JS builds the same markup for new picks. */
function marketing_picker_row(string $name, bool $single, array $fields, array $row): string
{
    $productId = (int) $row['id'];
    $inputName = $single ? $name : $name . '[]';

    $html = '<div class="ad-picker__row" data-mkpick-row="' . $productId . '">'
        . '<img class="ad-thumb" src="' . e(img_url($row['image'] ?? null)) . '" alt="" width="38" height="38" loading="lazy">'
        . '<span class="ad-picker__info">'
        . '<span class="ad-cellflex__name">' . e((string) ($row['name'] ?? 'Product #' . $productId)) . '</span>'
        . '<span class="ad-cellflex__meta">' . e((string) ($row['meta'] ?? '')) . '</span>'
        . '</span>';

    foreach ($fields as $field) {
        $fieldName = (string) $field['name'];
        $value = (string) ($row['values'][$fieldName] ?? '');
        $html .= '<label class="ad-picker__field">'
            . '<span>' . e((string) $field['label']) . '</span>'
            . '<input class="sik-input" type="number" name="' . e_attr($fieldName . '[' . $productId . ']') . '"'
            . ' value="' . e_attr($value) . '"'
            . ' step="' . e_attr((string) ($field['step'] ?? '1')) . '" min="0"'
            . ' placeholder="' . e_attr((string) ($field['placeholder'] ?? '')) . '"'
            . ' style="width:' . e_attr((string) ($field['width'] ?? '116px')) . '">'
            . '</label>';
    }

    return $html
        . '<input type="hidden" name="' . e_attr($inputName) . '" value="' . $productId . '">'
        . '<button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-mkpick-remove'
        . ' aria-label="Remove this product">' . icon('close', 'w-4 h-4') . '</button>'
        . '</div>';
}

/**
 * Picker CSS + JS, emitted once per page no matter how many pickers a form
 * renders. The behaviour is picker-local so several can share one screen.
 */
function marketing_picker_assets(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    ob_start();
    ?>
    <style>
        /* Scoped to the marketing product pickers: a search box that drops a
           result list over the rows it is about to add to. */
        .ad-picker { display: grid; gap: 10px; }
        .ad-picker__search { position: relative; }
        .ad-picker__search > svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--ad-muted); }
        .ad-picker__search .sik-input { padding-left: 34px; }
        .ad-picker__results {
            position: absolute; z-index: var(--ad-z-dropdown); left: 0; right: 0; top: calc(100% + 4px);
            background: var(--ad-elevated); border: 1px solid var(--ad-line); border-radius: var(--ad-radius-lg);
            box-shadow: var(--ad-shadow-3); max-height: 300px; overflow-y: auto; padding: 5px;
        }
        .ad-picker__results[hidden] { display: none; }
        .ad-picker__results .ad-dropdown__item { width: 100%; text-align: left; }
        .ad-picker__list { display: grid; gap: 8px; }
        .ad-picker__row {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 9px 10px; border: 1px solid var(--ad-border); border-radius: 10px; background: var(--ad-bg);
        }
        .ad-picker__info { flex: 1; min-width: 150px; display: grid; }
        .ad-picker__field { display: grid; gap: 3px; font-size: 11px; color: var(--ad-muted); }
        .ad-picker__field .sik-input { padding: 7px 10px; font-size: 12.5px; }
        .ad-picker__empty { font-size: 13px; padding: 14px; text-align: center; border: 1px dashed var(--ad-border); border-radius: 10px; }
        .ad-picker__empty[hidden] { display: none; }
    </style>
    <script>
        // app.js and admin.js are deferred, so SIK only exists once the document
        // has parsed. Everything below waits for that rather than for load.
        document.addEventListener('DOMContentLoaded', function () {
            'use strict';
            // SIK.get resolves relative endpoints against /api, and the lookup
            // is an admin-only endpoint there rather than under /admin, which
            // Apache blocks for includes.
            var ENDPOINT = 'admin/product-search.php';

            function esc(value) { return SIK.escapeHtml(value == null ? '' : String(value)); }

            function syncEmpty(box) {
                var empty = box.querySelector('[data-mkpick-empty]');
                if (empty) empty.hidden = box.querySelectorAll('[data-mkpick-row]').length > 0;
            }

            function closeResults(box) {
                var results = box.querySelector('[data-mkpick-results]');
                if (results) { results.innerHTML = ''; results.hidden = true; }
            }

            function rowHtml(box, data) {
                var single = box.dataset.mkpickSingle === '1';
                var name = box.dataset.mkpickName + (single ? '' : '[]');
                var fields = [];
                try { fields = JSON.parse(box.dataset.mkpickFields || '[]'); } catch (err) { fields = []; }

                var inputs = fields.map(function (field) {
                    return '<label class="ad-picker__field"><span>' + esc(field.label) + '</span>'
                        + '<input class="sik-input" type="number" name="' + esc(field.name) + '[' + esc(data.id) + ']"'
                        + ' step="' + esc(field.step || '1') + '" min="0"'
                        + ' placeholder="' + esc(field.placeholder || '') + '"'
                        + ' style="width:' + esc(field.width || '116px') + '"></label>';
                }).join('');

                return '<div class="ad-picker__row" data-mkpick-row="' + esc(data.id) + '">'
                    + '<img class="ad-thumb" src="' + esc(data.image) + '" alt="" width="38" height="38">'
                    + '<span class="ad-picker__info">'
                    + '<span class="ad-cellflex__name">' + esc(data.name) + '</span>'
                    + '<span class="ad-cellflex__meta">' + esc(data.meta) + '</span>'
                    + '</span>' + inputs
                    + '<input type="hidden" name="' + esc(name) + '" value="' + esc(data.id) + '">'
                    + '<button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-mkpick-remove'
                    + ' aria-label="Remove this product">&times;</button>'
                    + '</div>';
            }

            SIK.on('input', '[data-mkpick-search]', SIK.debounce(async function () {
                var box = this.closest('[data-mkpick]');
                if (!box) return;
                var results = box.querySelector('[data-mkpick-results]');
                var term = this.value.trim();

                if (term.length < 2) { closeResults(box); return; }

                var response = await SIK.get(ENDPOINT, { q: term, limit: 8 });
                if (!response.success) { SIK.toast(response.message || 'Product search failed.', 'error'); return; }

                var products = (response.data && response.data.products) || [];
                results.innerHTML = products.length
                    ? products.map(function (p) {
                        return '<button type="button" class="ad-dropdown__item" data-mkpick-add'
                            + ' data-id="' + esc(p.id) + '" data-name="' + esc(p.name) + '"'
                            + ' data-image="' + esc(p.image_url) + '" data-meta="' + esc(p.meta) + '">'
                            + '<img src="' + esc(p.image_url) + '" alt="" width="28" height="28" style="border-radius:5px">'
                            + '<span style="flex:1;min-width:0">'
                            + '<span style="display:block;font-weight:600">' + esc(p.name) + '</span>'
                            + '<span style="font-size:11.5px;color:var(--ad-muted)">' + esc(p.meta) + '</span>'
                            + '</span></button>';
                    }).join('')
                    : '<div class="ad-dropdown__item ad-muted">No products match that search.</div>';
                results.hidden = false;
            }, 300));

            SIK.on('click', '[data-mkpick-add]', function (e) {
                e.preventDefault();
                var box = this.closest('[data-mkpick]');
                var list = box ? box.querySelector('[data-mkpick-list]') : null;
                if (!list) return;

                if (list.querySelector('[data-mkpick-row="' + this.dataset.id + '"]')) {
                    SIK.toast('That product is already attached.', 'info');
                    return;
                }
                // Single-product pickers hold one row, so a new pick replaces it.
                if (box.dataset.mkpickSingle === '1') list.innerHTML = '';

                list.insertAdjacentHTML('beforeend', rowHtml(box, this.dataset));
                closeResults(box);
                var search = box.querySelector('[data-mkpick-search]');
                if (search) search.value = '';
                syncEmpty(box);
            });

            SIK.on('click', '[data-mkpick-remove]', function (e) {
                e.preventDefault();
                var box = this.closest('[data-mkpick]');
                var row = this.closest('[data-mkpick-row]');
                if (row) row.remove();
                if (box) syncEmpty(box);
            });

            document.addEventListener('click', function (e) {
                if (e.target.closest('[data-mkpick]')) return;
                SIK.$$('[data-mkpick]').forEach(closeResults);
            });
        });
    </script>
    <?php
    return (string) ob_get_clean();
}
