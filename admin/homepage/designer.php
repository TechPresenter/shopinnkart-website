<?php
/**
 * ShopInnKart Admin - Homepage designer.
 *
 * Three panes over the same `homepage_sections` rows the list page edits:
 * a live storefront canvas, the drag-ordered section list, and an inspector.
 *
 * The inspector is not a second editor. It includes _form.php - the same file
 * create.php and edit.php use - and shows its cards three tabs at a time via
 * the `data-insp` attribute. A reimplemented inspector would be a second set
 * of field names able to drift from homepage_form_input(), and a save that
 * quietly drops a column is the kind of bug found only after the data is gone.
 * For the same reason the form posts to edit.php rather than saving here.
 *
 * Selecting a section is a navigation, not a client-side swap: this admin is
 * server-rendered throughout, and a local round trip costs less than a second
 * copy of the form's conditional logic in JavaScript.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.view');

require_once __DIR__ . '/_meta.php';

$canEdit = admin_can('homepage.edit');

$zones = homepage_zones();
$zone  = (string) input('zone', 'home');
if (!isset($zones[$zone])) {
    $zone = 'home';
}

$sections = Database::fetchAll(
    "SELECT * FROM `homepage_sections` WHERE `zone` = :zone ORDER BY `sort_order` ASC, `id` ASC",
    ['zone' => $zone]
);

// The selected row, defaulting to the first in the zone so the inspector is
// never an empty pane on arrival.
$selectedId = input_int('id');
$section    = null;
foreach ($sections as $row) {
    if ((int) $row['id'] === $selectedId) {
        $section = $row;
        break;
    }
}
if ($section === null && $sections !== [] && $selectedId === 0) {
    $section    = $sections[0];
    $selectedId = (int) $section['id'];
}

$liveCount = 0;
foreach ($sections as $row) {
    if ($row['status'] === 'active') {
        $liveCount++;
    }
}

/* _form.php renders in "edit" shape and posts to edit.php, which sends the
   browser back here. $errors is always defined because the form reads it. */
$isEdit  = true;
$errors  = [];
$selfUrl = admin_url('homepage/designer.php?zone=' . urlencode($zone)
    . ($selectedId > 0 ? '&id=' . $selectedId : ''));
$formAction = $section !== null
    ? admin_url('homepage/edit.php?id=' . $selectedId . '&return=' . urlencode(
        (string) parse_url($selfUrl, PHP_URL_PATH) . '?' . (string) parse_url($selfUrl, PHP_URL_QUERY)
    ))
    : '';

$pageTitle    = 'Homepage Designer';
$pageSubtitle = $zones[$zone]['label'] . ' · ' . count($sections) . ' sections, ' . $liveCount . ' live';
$breadcrumbs  = [
    ['label' => 'Dashboard',        'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder', 'url' => admin_url('homepage/?zone=' . urlencode($zone))],
    ['label' => 'Designer'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('homepage/?zone=' . urlencode($zone))) . '">'
    . icon('list', 'w-4 h-4') . ' List view</a>';
if ($canEdit) {
    $pageActions .= ' <a class="ad-btn ad-btn--primary" href="'
        . e(admin_url('homepage/create.php?zone=' . urlencode($zone))) . '">'
        . icon('plus', 'w-4 h-4') . ' Add Section</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-container">

    <!-- Zone switcher: the same four zones the list page offers. -->
    <div class="ad-tabs" style="margin-bottom:12px;border-radius:10px;border:1px solid var(--ad-border)">
        <?php foreach ($zones as $zoneKey => $meta): ?>
            <a class="ad-tab <?= $zoneKey === $zone ? 'is-active' : '' ?>"
               href="<?= e(admin_url('homepage/designer.php?zone=' . urlencode($zoneKey))) ?>">
                <?= icon($meta['icon'], 'w-4 h-4') ?> <?= e($meta['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Below 1100px only one pane is mounted; this chooses which. -->
    <div class="ad-designer__switch" role="tablist" aria-label="Designer panes">
        <button type="button" role="tab" data-pane-btn="canvas">Preview</button>
        <button type="button" role="tab" data-pane-btn="list" class="is-active" aria-selected="true">Sections</button>
        <button type="button" role="tab" data-pane-btn="inspector">Design</button>
    </div>

    <div class="ad-designer" data-designer data-zone="<?= e_attr($zone) ?>"
         data-order-url="<?= e_attr(admin_url('homepage/order.php')) ?>"
         data-toggle-url="<?= e_attr(admin_url('homepage/toggle.php')) ?>">

        <!-- ============================ canvas ============================ -->
        <section class="ad-designer__pane" data-pane="canvas" aria-label="Storefront preview">
            <div class="ad-designer__head">
                <span class="ad-designer__title">Preview</span>
                <div class="ad-btngroup" style="margin-left:auto" role="group" aria-label="Preview width">
                    <button type="button" class="ad-btn ad-btn--sm is-active" data-device="phone">Phone</button>
                    <button type="button" class="ad-btn ad-btn--sm" data-device="tablet">Tablet</button>
                    <button type="button" class="ad-btn ad-btn--sm" data-device="desktop">Desktop</button>
                </div>
            </div>
            <div class="ad-designer__body ad-designer__body--flush ad-canvas" data-device="phone" data-canvas>
                <iframe class="ad-canvas__frame" data-canvas-frame title="Storefront preview"
                        src="<?= e(admin_url('homepage/preview.php?zone=' . urlencode($zone)
                            . ($selectedId > 0 ? '&focus=' . $selectedId : ''))) ?>"></iframe>
            </div>
        </section>

        <!-- ============================= list ============================= -->
        <section class="ad-designer__pane is-shown" data-pane="list" aria-label="Sections">
            <div class="ad-designer__head">
                <span class="ad-designer__title">Sections</span>
                <span class="ad-designer__count"><?= count($sections) ?></span>
            </div>
            <div class="ad-designer__body">
                <?php if ($sections === []): ?>
                    <p class="ad-insp__empty">No sections in this zone yet.</p>
                <?php else: ?>
                    <ol class="ad-seclist" data-seclist>
                        <?php foreach ($sections as $row): ?>
                            <?php
                            $rowId   = (int) $row['id'];
                            $type    = (string) $row['widget_type'];
                            $heading = trim((string) ($row['title'] ?? '') . ' ' . (string) ($row['title_accent'] ?? ''));
                            $name    = $heading !== '' ? $heading : homepage_widget_label($type);
                            $off     = $row['status'] !== 'active';
                            ?>
                            <li class="ad-sec <?= $rowId === $selectedId ? 'is-selected' : '' ?> <?= $off ? 'is-off' : '' ?>"
                                data-id="<?= $rowId ?>" data-name="<?= e_attr($name) ?>">

                                <?php if ($canEdit): ?>
                                    <button type="button" class="ad-sec__grip" data-grip
                                            aria-label="Drag to reorder <?= e_attr($name) ?>">
                                        <?= icon('menu', 'w-4 h-4') ?>
                                    </button>
                                <?php else: ?>
                                    <span class="ad-sec__grip" aria-hidden="true"><?= icon('menu', 'w-4 h-4') ?></span>
                                <?php endif; ?>

                                <a class="ad-sec__body" href="<?= e(admin_url('homepage/designer.php?zone='
                                        . urlencode($zone) . '&id=' . $rowId)) ?>">
                                    <span class="ad-sec__name"><?= e($name) ?></span>
                                    <span class="ad-sec__type"><?= e(homepage_widget_label($type)) ?></span>
                                </a>

                                <span class="ad-sec__side">
                                    <?php if ($canEdit): ?>
                                        <span class="ad-sec__move">
                                            <button type="button" data-move="up" aria-label="Move <?= e_attr($name) ?> up">
                                                <?= icon('chevron-up', 'w-3 h-3') ?>
                                            </button>
                                            <button type="button" data-move="down" aria-label="Move <?= e_attr($name) ?> down">
                                                <?= icon('chevron-down', 'w-3 h-3') ?>
                                            </button>
                                        </span>

                                        <label class="ad-switch" title="<?= $off ? 'Hidden' : 'Live' ?>">
                                            <span class="sik-sr">Show <?= e($name) ?> on the storefront</span>
                                            <input type="checkbox" data-toggle-endpoint
                                                   data-id="<?= $rowId ?>" data-field="status"
                                                   <?= $off ? '' : 'checked' ?>>
                                            <span class="ad-switch__track"></span>
                                        </label>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <!-- Reorder outcomes are announced, because a drag that silently
                     failed to save looks exactly like one that worked. -->
                <p class="sik-sr" role="status" aria-live="polite" data-designer-status></p>
            </div>
        </section>

        <!-- =========================== inspector =========================== -->
        <section class="ad-designer__pane ad-insp" data-pane="inspector" aria-label="Section design">
            <?php if ($section === null): ?>
                <div class="ad-designer__head"><span class="ad-designer__title">Design</span></div>
                <div class="ad-designer__body">
                    <p class="ad-insp__empty">Select a section to edit it.</p>
                </div>
            <?php else: ?>
                <div class="ad-designer__head">
                    <span class="ad-designer__title" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= e(homepage_widget_label((string) $section['widget_type'])) ?>
                    </span>
                    <span class="ad-designer__count" style="margin-left:auto">
                        <?= $section['status'] === 'active' ? 'Live' : 'Hidden' ?>
                    </span>
                </div>

                <div class="ad-insp__tabs" role="tablist" aria-label="Design tabs">
                    <button type="button" class="ad-insp__tab is-active" role="tab"
                            aria-selected="true" data-insp-tab="content">Content</button>
                    <button type="button" class="ad-insp__tab" role="tab"
                            aria-selected="false" data-insp-tab="style">Style</button>
                    <button type="button" class="ad-insp__tab" role="tab"
                            aria-selected="false" data-insp-tab="visibility">Visibility</button>
                </div>

                <div class="ad-designer__body" data-insp-body>
                    <?php require __DIR__ . '/_form.php'; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<style>
/* Scoped to the inspector: _form.php is a two-column page form, and inside a
   370px pane it has to become one column with only the active tab's cards
   showing. The full-page create/edit screens are untouched. */
.ad-insp .ad-form__grid,
.ad-insp .ad-form > .ad-grid { display: block; }
.ad-insp .ad-card { margin: 0 0 12px; }
.ad-insp [data-insp="none"] { display: none; }
.ad-insp [data-insp]:not([data-insp="content"]) { display: none; }
.ad-insp[data-tab="style"] [data-insp] { display: none; }
.ad-insp[data-tab="style"] [data-insp="style"] { display: block; }
.ad-insp[data-tab="visibility"] [data-insp] { display: none; }
.ad-insp[data-tab="visibility"] [data-insp="visibility"] { display: block; }
.ad-insp[data-tab="content"] [data-insp="content"] { display: block; }
</style>

<script>
(function () {
    var root = document.querySelector('[data-designer]');
    if (!root) { return; }

    /* ---- preview width ------------------------------------------------- */
    var canvas = root.querySelector('[data-canvas]');
    root.querySelectorAll('[data-device]').forEach(function (btn) {
        if (btn.tagName !== 'BUTTON') { return; }
        btn.addEventListener('click', function () {
            root.querySelectorAll('button[data-device]').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            canvas.dataset.device = btn.dataset.device;
        });
    });

    /* ---- inspector tabs ------------------------------------------------- */
    var insp = root.querySelector('.ad-insp');
    if (insp) {
        insp.dataset.tab = 'content';
        insp.querySelectorAll('[data-insp-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                insp.dataset.tab = tab.dataset.inspTab;
                insp.querySelectorAll('[data-insp-tab]').forEach(function (t) {
                    var on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            });
        });
    }

    /* ---- one pane at a time on narrow screens --------------------------- */
    var switcher = document.querySelector('.ad-designer__switch');
    if (switcher) {
        // The markup and the switch must agree on which pane is mounted;
        // syncing from the active button on load means only one of them
        // has to be right.
        (function () {
            var on = switcher.querySelector('[data-pane-btn].is-active');
            if (!on) { return; }
            root.querySelectorAll('[data-pane]').forEach(function (p) {
                p.classList.toggle('is-shown', p.dataset.pane === on.dataset.paneBtn);
            });
        }());

        switcher.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pane-btn]');
            if (!btn) { return; }
            switcher.querySelectorAll('[data-pane-btn]').forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            root.querySelectorAll('[data-pane]').forEach(function (p) {
                p.classList.toggle('is-shown', p.dataset.pane === btn.dataset.paneBtn);
            });
        });
    }

    /* ---- keep the canvas in step with the selection ---------------------- */
    var frame = root.querySelector('[data-canvas-frame]');
    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !e.data || e.data.type !== 'sik:select') { return; }
        var row = root.querySelector('.ad-sec[data-id="' + Number(e.data.id) + '"] .ad-sec__body');
        if (row) { window.location.href = row.getAttribute('href'); }
    });

    // Reload the canvas after a toggle, so "Hidden" appears without a refresh.
    root.addEventListener('change', function (e) {
        if (!e.target.matches('[data-toggle-endpoint]')) { return; }
        var row = e.target.closest('.ad-sec');
        if (row) { row.classList.toggle('is-off', !e.target.checked); }
        // The existing inline-toggle handler has already sent the request;
        // give it a beat to land before asking the preview to re-render.
        setTimeout(function () {
            if (frame) { frame.contentWindow.location.reload(); }
        }, 350);
    });
}());
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
