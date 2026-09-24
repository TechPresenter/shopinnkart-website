<?php
/**
 * ShopInnKart Admin - Homepage Builder.
 *
 * One row in `homepage_sections` is one widget on the storefront. This screen
 * shows a single zone at a time in render order, because that ordering is the
 * whole point — a widget list sorted by anything else tells you nothing about
 * what the page looks like.
 *
 * Reordering works two ways on purpose: the number input saves over AJAX so a
 * long zone does not jump back to the top after every nudge, and the arrow
 * buttons are plain forms so the screen still works with JavaScript off.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.view');

require_once __DIR__ . '/_meta.php';

$zones = homepage_zones();
$zone  = admin_filter('zone', array_keys($zones), 'home');

$widgets = Database::fetchAll(
    'SELECT * FROM `homepage_sections` WHERE `zone` = :zone ORDER BY `sort_order` ASC, `id` ASC',
    ['zone' => $zone]
);

$zoneCounts = Database::fetchPairs(
    'SELECT `zone`, COUNT(*) FROM `homepage_sections` GROUP BY `zone`'
);

$activeCount = count(array_filter($widgets, static fn (array $w): bool => $w['status'] === 'active'));
$canEdit     = admin_can('homepage.edit');
// Prefixed on purpose: header.php renders in this scope and uses $lastIndex,
// $i and $crumb for the breadcrumb trail.
$lastWidgetIndex = count($widgets) - 1;

$pageTitle    = 'Homepage Builder';
$pageSubtitle = $zones[$zone]['label'] . ' · ' . count($widgets) . ' section'
    . (count($widgets) === 1 ? '' : 's') . ', ' . $activeCount . ' live';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder'],
];

$pageActions = '<a class="ad-btn" href="' . e(homepage_preview_url(['zone' => $zone, 'section_key' => ''])) . '"'
    . ' target="_blank" rel="noopener">' . icon('external', 'w-4 h-4') . ' View zone</a>';

// The designer is the same rows with a canvas and drag ordering; this list
// stays because it shows schedule, source and item counts at a glance.
$pageActions .= '<a class="ad-btn" href="'
    . e(admin_url('homepage/designer.php?zone=' . urlencode($zone))) . '">'
    . icon('grid', 'w-4 h-4') . ' Designer</a>';
if ($canEdit) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="'
        . e(admin_url('homepage/create.php?zone=' . urlencode($zone))) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Section</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Sections in this zone', (string) count($widgets), 'grid', 'primary', $zones[$zone]['sub']) ?>
    <?= admin_stat_card('Live right now', (string) $activeCount, 'check-circle', 'green',
        'Active, in schedule and visible') ?>
    <?= admin_stat_card('Menus', 'Menu Builder', 'menu', 'navy', 'Header, mobile and footer menus',
        $canEdit ? admin_url('menus/') : null) ?>
    <?= admin_stat_card('Footer', 'Footer Builder', 'list', 'violet', 'Columns and link lists',
        $canEdit ? admin_url('footer/') : null) ?>
</div>

<div class="ad-card">
    <div class="ad-tabs">
        <?php foreach ($zones as $key => $meta): ?>
            <a class="ad-tab <?= $zone === $key ? 'is-active' : '' ?>"
               href="<?= e(admin_url('homepage/?zone=' . urlencode($key))) ?>">
                <?= icon($meta['icon'], 'w-4 h-4') ?> <?= e($meta['label']) ?>
                <span class="ad-tab__count"><?= (int) ($zoneCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="ad-card__head">
        <div>
            <div class="ad-card__title"><?= e($zones[$zone]['label']) ?></div>
            <div class="ad-card__sub"><?= e($zones[$zone]['sub']) ?> &middot; rendered top to bottom in this order.</div>
        </div>
        <div class="ad-btngroup">
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('homepage/trust-features.php')) ?>">
                <?= icon('shield', 'w-4 h-4') ?> Trust Features
            </a>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('homepage/categories.php')) ?>">
                <?= icon('grid', 'w-4 h-4') ?> Featured Categories
            </a>
            <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('homepage/newsletter.php')) ?>">
                <?= icon('mail', 'w-4 h-4') ?> Newsletter
            </a>
        </div>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($widgets === []): ?>
            <?= admin_empty(
                'No sections in this zone yet',
                'Add a hero, a product row or a promo band and it appears on the storefront straight away.',
                $canEdit ? 'Add Section' : null,
                $canEdit ? admin_url('homepage/create.php?zone=' . urlencode($zone)) : null,
                'grid'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th style="width:78px">Order</th>
                            <th>Section</th>
                            <th>Data source</th>
                            <th class="ad-table__num">Items</th>
                            <th>Visibility</th>
                            <th>Schedule</th>
                            <th>Enabled</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($widgets as $i => $widget): ?>
                            <?php
                            $id       = (int) $widget['id'];
                            $type     = (string) $widget['widget_type'];
                            $schedule = homepage_schedule_state($widget);
                            $heading  = trim((string) ($widget['title'] ?? '') . ' ' . (string) ($widget['title_accent'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:4px">
                                        <?php if ($canEdit): ?>
                                            <label class="sik-sr" for="order<?= $id ?>">Sort order</label>
                                            <input class="sik-input" type="number" id="order<?= $id ?>"
                                                   value="<?= (int) $widget['sort_order'] ?>" min="0" max="9999" step="1"
                                                   data-sort-order data-id="<?= $id ?>"
                                                   style="width:64px;padding:6px 7px;font-size:13px">
                                            <div style="display:grid;gap:2px">
                                                <form method="post" action="<?= e(admin_url('homepage/order.php')) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="hidden" name="move" value="up">
                                                    <button type="submit" class="ad-btn ad-btn--icon" title="Move up"
                                                            aria-label="Move up" style="width:26px;height:20px"
                                                            <?= $i === 0 ? 'disabled' : '' ?>>
                                                        <?= icon('chevron-up', 'w-3 h-3') ?>
                                                    </button>
                                                </form>
                                                <form method="post" action="<?= e(admin_url('homepage/order.php')) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="hidden" name="move" value="down">
                                                    <button type="submit" class="ad-btn ad-btn--icon" title="Move down"
                                                            aria-label="Move down" style="width:26px;height:20px"
                                                            <?= $i === $lastWidgetIndex ? 'disabled' : '' ?>>
                                                        <?= icon('chevron-down', 'w-3 h-3') ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <?= (int) $widget['sort_order'] ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="ad-cellflex">
                                        <span class="ad-stat__icon ad-stat__icon--navy" style="width:34px;height:34px;flex:none">
                                            <?= icon(homepage_widget_icon($type), 'w-4 h-4') ?>
                                        </span>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($canEdit): ?>
                                                    <a href="<?= e(admin_url('homepage/edit.php?id=' . $id)) ?>">
                                                        <?= e($heading !== '' ? $heading : homepage_widget_label($type)) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($heading !== '' ? $heading : homepage_widget_label($type)) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <?= e(homepage_widget_label($type)) ?>
                                                &middot; <span class="ad-mono"><?= e($widget['section_key']) ?></span>
                                                <?php if ((int) $widget['lazy_load'] === 1): ?>
                                                    &middot; lazy
                                                <?php endif; ?>
                                                <?php
                                                /* A section built from nested rows draws its rows instead of
                                                   its widget type, so the type shown above is no longer the
                                                   whole story and the list has to say so.

                                                   A count, not the rows: this runs once per section on the
                                                   page, and homepage_rows_of() would re-sanitise every item's
                                                   copy to answer it. */
                                                $widgetRows = homepage_rows_count($widget);
                                                ?>
                                                <?php if ($widgetRows > 0): ?>
                                                    &middot; <?= $widgetRows === 1
                                                        ? '1 row' : $widgetRows . ' rows' ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                <td class="ad-muted"><?= e(homepage_source_summary($widget)) ?></td>
                                <td class="ad-table__num"><?= (int) $widget['item_limit'] ?></td>

                                <td>
                                    <?php if ($widget['device_visibility'] !== 'all'): ?>
                                        <span class="sik-status sik-status--blue">
                                            <?= e(homepage_device_visibility()[$widget['device_visibility']] ?? $widget['device_visibility']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($widget['auth_visibility'] !== 'all'): ?>
                                        <span class="sik-status sik-status--violet">
                                            <?= e(homepage_auth_visibility()[$widget['auth_visibility']] ?? $widget['auth_visibility']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($widget['device_visibility'] === 'all' && $widget['auth_visibility'] === 'all'): ?>
                                        <span class="ad-muted">Everyone</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="sik-status sik-status--<?= e($schedule['tone']) ?>">
                                        <?= e($schedule['label']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($canEdit): ?>
                                        <label class="ad-switch">
                                            <input type="checkbox" data-toggle-endpoint="<?= e(admin_url('homepage/toggle.php')) ?>"
                                                   data-id="<?= $id ?>" data-field="status"
                                                   <?= $widget['status'] === 'active' ? 'checked' : '' ?>>
                                            <span class="ad-switch__track"></span>
                                            <span class="sik-sr">Enable <?= e($widget['section_key']) ?></span>
                                        </label>
                                    <?php else: ?>
                                        <?= admin_state_badge((string) $widget['status']) ?>
                                    <?php endif; ?>
                                </td>

                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--icon" title="Preview on the storefront"
                                       aria-label="Preview <?= e_attr($widget['section_key']) ?>"
                                       href="<?= e(homepage_preview_url($widget)) ?>" target="_blank" rel="noopener">
                                        <?= icon('eye', 'w-4 h-4') ?>
                                    </a>
                                    <?php if ($canEdit): ?>
                                        <a class="ad-btn ad-btn--icon" title="Edit"
                                           aria-label="Edit <?= e_attr($widget['section_key']) ?>"
                                           href="<?= e(admin_url('homepage/edit.php?id=' . $id)) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </a>
                                        <?= admin_delete_form(
                                            admin_url('homepage/delete.php'),
                                            $id,
                                            'Delete the "' . ($heading !== '' ? $heading : $widget['section_key']) . '" section? This cannot be undone.'
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit && $widgets !== []): ?>
<script>
    // The number input saves on change so reordering a long zone never reloads
    // the page. The arrow buttons next to it are plain form posts, so the same
    // screen still reorders with JavaScript switched off.
    document.addEventListener('DOMContentLoaded', function () {
        var endpoint = (window.SIK_CONFIG && SIK_CONFIG.adminUrl ? SIK_CONFIG.adminUrl : '') + '/homepage/order.php';

        SIK.on('change', '[data-sort-order]', async function () {
            var input = this;
            if (input.dataset.saving === '1') return;

            var value = parseInt(input.value, 10);
            if (isNaN(value) || value < 0) { value = 0; }
            input.value = String(value);

            input.dataset.saving = '1';
            input.disabled = true;

            var result = await SIK.post(endpoint, { id: parseInt(input.dataset.id, 10), sort_order: value });

            input.disabled = false;
            delete input.dataset.saving;

            SIK.toast(
                result.success ? (result.message || 'Order saved.') : (result.message || 'Could not save the order.'),
                result.success ? 'success' : 'error'
            );
        });
    });
</script>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
