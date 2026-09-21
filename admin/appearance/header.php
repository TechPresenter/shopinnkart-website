<?php
/**
 * ShopInnKart Admin - Appearance > Header.
 *
 * The editor for includes/header-settings.php. That file already defines the
 * whole contract - every knob, its bounds, its allowed values - and the
 * storefront header already reads it on every page. Until this screen existed
 * the contract had no operator, which is why a fresh install has no rows in the
 * `header` settings group at all: every value is still its shipped default.
 *
 * Two things this screen deliberately does not do:
 *
 *   - It does not validate. settings_handle_save() does, against the spec that
 *     admin/appearance/_header-spec.php derives from the contract, and
 *     header_settings_normalize() does it again on read. One vocabulary, two
 *     enforcement points, no third copy here.
 *   - It does not simulate. The preview is the real storefront in an iframe,
 *     restyled by the exact CSS block header_css_vars() would print and the
 *     exact classes header_body_class() would set, both computed on the server
 *     by admin/appearance/header-preview.php. Anything the preview cannot show
 *     without a save is named as such rather than faked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once ADMIN_PATH . '/appearance/_header-spec.php';

$spec   = header_field_spec();
$stored = settings_group('header');

if (is_post()) {
    settings_handle_save('header', 'header', $spec, $stored, [
        'redirect' => admin_url('appearance/header.php'),
        'label'    => 'Header',
    ]);
}

$errors = errors_pull();
$values = settings_values($spec, $stored);

/**
 * Which fieldset each key belongs to.
 *
 * Checked against the spec below, so a knob added to HEADER_DEFAULTS that
 * nobody placed here is reported on screen instead of silently vanishing from
 * the form - which is the failure a hand-written form always eventually has.
 */
$layout = [
    'style' => ['menu_style'],
    'logo'  => ['header_logo_height', 'header_show_tagline', 'header_height'],
    'nav'   => ['header_nav_enabled', 'menu_align', 'menu_spacing', 'menu_item_padding', 'menu_hover_effect'],
    'type'  => ['menu_font_size', 'menu_font_weight', 'menu_text_transform'],
    'icons' => [
        'header_icon_size', 'header_action_size', 'header_show_notifications',
        'header_show_wishlist', 'header_show_compare', 'header_show_cart', 'header_show_track',
        'header_show_account', 'header_show_track', 'header_action_color', 'header_action_hover_bg',
    ],
    'search' => ['header_search_from', 'header_search_width', 'header_search_placeholder', 'header_search_voice', 'header_search_popular'],
    'mobile' => ['header_height_mobile', 'header_burger_position'],
    'color'  => [
        'header_bg', 'header_border_width', 'header_border_color', 'header_shadow',
        'header_stuck_shadow', 'header_nav_bg', 'menu_link_color', 'menu_hover_color',
        'menu_hover_bg', 'menu_active_color', 'announce_bg', 'announce_color',
    ],
];

$placed = [];
foreach ($layout as $keys) {
    foreach ($keys as $key) {
        $placed[$key] = true;
    }
}
$unplaced = array_values(array_diff(array_keys($spec), array_keys($placed)));

/**
 * The markup-level switches as the storefront is serving them right now.
 *
 * The preview compares the unsaved form against THIS, not against what it can
 * find in the iframe's DOM. Looking for the control in the page conflates two
 * different reasons a control can be absent: the operator switched it off, or
 * it is conditional on something else entirely - the notification bell only
 * renders for a signed-in customer, so a signed-out preview would report
 * "save to add the bell" forever, which is a lie about what saving would do.
 */
$live = header_settings_normalize($stored);
$liveFlags = [
    'notifications' => $live['header_show_notifications'] === '1',
    'wishlist'      => $live['header_show_wishlist'] === '1',
    'compare'       => $live['header_show_compare'] === '1',
    'cart'          => $live['header_show_cart'] === '1',
    'account'       => $live['header_show_account'] === '1',
    'voice'         => $live['header_search_voice'] === '1',
];

/** How many knobs are no longer on their shipped value. */
$changed = 0;
foreach ($spec as $key => $field) {
    if ((string) $values[$key] !== (string) $field['default']) {
        $changed++;
    }
}

$pageTitle    = 'Header';
$pageSubtitle = 'Menu style, sizing, actions, search and colour for the storefront header.';
$breadcrumbs  = [
    ['label' => 'Dashboard',  'url' => admin_url('dashboard.php')],
    ['label' => 'Appearance', 'url' => admin_url('appearance/')],
    ['label' => 'Header'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<style>
    .aph-layout { display: grid; gap: 18px; align-items: start; }
    @media (min-width: 1280px) { .aph-layout { grid-template-columns: minmax(0, 1fr) 470px; } }

    /* [hidden] has to beat .ad-field's own display, or a conditional block
       renders anyway. Same defect the Menu Builder hit; scoped to this screen
       rather than added to the shared stylesheet. */
    .ad-field[hidden] { display: none; }

    .aph-styles { display: grid; gap: 10px; grid-template-columns: 1fr; }
    @media (min-width: 640px) { .aph-styles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .aph-style { position: relative; display: block; cursor: pointer; }
    .aph-style input { position: absolute; opacity: 0; pointer-events: none; }
    .aph-style__box {
        border: 1.5px solid var(--ad-border); border-radius: 10px; padding: 12px 14px;
        display: flex; flex-direction: column; gap: 6px; height: 100%;
        transition: border-color var(--dur-fast, .15s) var(--ease-out, ease);
    }
    .aph-style input:checked + .aph-style__box {
        border-color: var(--ad-primary);
        box-shadow: inset 0 0 0 1px var(--ad-primary);
    }
    .aph-style input:focus-visible + .aph-style__box { outline: 2px solid var(--ad-primary); outline-offset: 2px; }
    .aph-style__name { font-size: 13.5px; font-weight: 700; }
    .aph-style__blurb { font-size: 12px; line-height: 1.5; color: var(--ad-muted); }
    .aph-style__notes { display: flex; flex-wrap: wrap; gap: 5px; }
    .aph-style__note {
        font-size: 10.5px; padding: 3px 7px; border-radius: 999px;
        background: var(--ad-bg); color: var(--ad-muted); border: 1px solid var(--ad-border);
    }

    .aph-preview { position: sticky; top: 84px; }
    .aph-stage {
        position: relative; overflow: hidden; border-radius: 10px;
        border: 1px solid var(--ad-border); background: var(--ad-bg); height: 460px;
    }
    .aph-frame { position: absolute; top: 0; left: 0; border: 0; background: #fff; transform-origin: 0 0; }
    .aph-seg { display: inline-flex; border: 1px solid var(--ad-border); border-radius: 8px; overflow: hidden; }
    .aph-seg button {
        border: 0; background: var(--ad-surface); color: var(--ad-muted);
        font: inherit; font-size: 12px; padding: 6px 10px; cursor: pointer; min-height: var(--tap, 44px);
    }
    .aph-seg button.is-on { background: var(--ad-primary); color: #fff; font-weight: 600; }
    .aph-pending {
        font-size: 11.5px; line-height: 1.5; color: #B45309; margin: 10px 0 0;
        display: none;
    }
    .aph-pending.is-on { display: block; }
</style>

<div class="aph-layout">
    <form method="post" action="<?= e(admin_url('appearance/header.php')) ?>" class="ad-form" id="aphForm">
        <?= csrf_field() ?>

        <?php if ($unplaced !== []): ?>
            <div class="sik-alert sik-alert--warning">
                <div><strong><?= count($unplaced) ?> header setting(s) exist in the contract but are not on
                    this form</strong> — <code><?= e(implode(', ', $unplaced)) ?></code>. They keep their
                    stored value; add them to <code>$layout</code> in this file to edit them.</div>
            </div>
        <?php endif; ?>

        <!-- Menu style ------------------------------------------------- -->
        <section class="ad-card" id="style">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Menu style</h2>
                    <p class="ad-card__sub">Structural layout. Everything below applies on top of whichever
                        style is selected, so the two never fight.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="aph-styles">
                    <?php foreach (HEADER_MENU_STYLES as $styleKey => $style): ?>
                        <label class="aph-style">
                            <input type="radio" name="menu_style" value="<?= e_attr($styleKey) ?>"
                                   <?= $values['menu_style'] === $styleKey ? 'checked' : '' ?>>
                            <span class="aph-style__box">
                                <span class="aph-style__name"><?= e($style['label']) ?></span>
                                <span class="aph-style__blurb"><?= e($style['blurb']) ?></span>
                                <span class="aph-style__notes">
                                    <?php foreach ($style['notes'] as $note): ?>
                                        <span class="aph-style__note"><?= e($note) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($errors['menu_style'])): ?>
                    <span class="sik-error"><?= e($errors['menu_style']) ?></span>
                <?php endif; ?>
            </div>
        </section>

        <!-- Logo & height ---------------------------------------------- -->
        <section class="ad-card" id="logo">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Logo &amp; height</h2>
                    <p class="ad-card__sub">The wordmark itself lives in Settings &rarr; General; this is how
                        much room it gets.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['header_logo_height', 'header_height'], $spec, $values, $errors) ?>
                </div>
                <?= settings_field('header_show_tagline', $spec, $values, $errors) ?>
            </div>
        </section>

        <!-- Navigation -------------------------------------------------- -->
        <section class="ad-card" id="nav">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Category bar</h2>
                    <p class="ad-card__sub">Which links appear is the
                        <a href="<?= e(admin_url('menus/')) ?>">Menu Builder</a>'s job. This is how the bar
                        that carries them behaves.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('header_nav_enabled', $spec, $values, $errors) ?>
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['menu_align', 'menu_hover_effect', 'menu_spacing', 'menu_item_padding'], $spec, $values, $errors) ?>
                </div>
            </div>
        </section>

        <!-- Navigation type --------------------------------------------- -->
        <section class="ad-card" id="type">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Navigation type</h2>
                    <p class="ad-card__sub">Shown as <strong>Typography</strong> on the Appearance hub. The
                        storefront font family is in
                        <a href="<?= e(admin_url('settings/theme.php')) ?>">Settings &rarr; Theme</a>.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--3">
                    <?= settings_fields(['menu_font_size', 'menu_font_weight', 'menu_text_transform'], $spec, $values, $errors) ?>
                </div>
            </div>
        </section>

        <!-- Icons & actions --------------------------------------------- -->
        <section class="ad-card" id="icons">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Icons &amp; actions</h2>
                    <p class="ad-card__sub">Switching a control off removes its markup rather than hiding it —
                        the bell in particular costs two queries before it paints.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['header_icon_size', 'header_action_size'], $spec, $values, $errors) ?>
                </div>
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields([
                        'header_show_notifications', 'header_show_cart',
                        'header_show_wishlist', 'header_show_compare',
                        'header_show_account', 'header_show_track',
                    ], $spec, $values, $errors) ?>
                </div>
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['header_action_color', 'header_action_hover_bg'], $spec, $values, $errors) ?>
                </div>
            </div>
        </section>

        <!-- Search ------------------------------------------------------ -->
        <section class="ad-card" id="search">
            <div class="ad-card__head">
                <div><h2 class="ad-card__title">Search</h2></div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['header_search_from', 'header_search_width'], $spec, $values, $errors) ?>
                </div>
                <?= settings_field('header_search_placeholder', $spec, $values, $errors) ?>
                <?= settings_field('header_search_popular', $spec, $values, $errors) ?>
                <?= settings_field('header_search_voice', $spec, $values, $errors) ?>
            </div>
        </section>

        <!-- Mobile ------------------------------------------------------ -->
        <section class="ad-card" id="mobile">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Mobile</h2>
                    <p class="ad-card__sub">Below 768px. The bottom bar is in
                        <a href="<?= e(admin_url('settings/widgets.php')) ?>">Settings &rarr; Widgets</a>.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields(['header_height_mobile', 'header_burger_position'], $spec, $values, $errors) ?>
                </div>
            </div>
        </section>

        <!-- Colour & edges ---------------------------------------------- -->
        <section class="ad-card" id="color">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Colour &amp; edges</h2>
                    <p class="ad-card__sub">Every colour here is optional. Blank means the header keeps
                        following <a href="<?= e(admin_url('settings/theme.php')) ?>">the theme</a>, which is
                        why leaving them all blank reproduces the shipped header exactly.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--2">
                    <?= settings_fields([
                        'header_bg', 'header_nav_bg',
                        'header_border_width', 'header_border_color',
                        'header_shadow', 'header_stuck_shadow',
                        'menu_link_color', 'menu_active_color',
                        'menu_hover_color', 'menu_hover_bg',
                        'announce_bg', 'announce_color',
                    ], $spec, $values, $errors) ?>
                </div>
            </div>
        </section>

        <div class="ad-card">
            <?= settings_save_bar($changed . ' of ' . count($spec) . ' settings differ from the shipped header.') ?>
        </div>

        <p class="ad-muted" style="font-size:12px;line-height:1.6;margin:12px 2px 0">
            Resetting lives on <a href="<?= e(admin_url('appearance/')) ?>">the Appearance hub</a>, where it
            shows exactly which values change before it changes them. The fields on this screen are spread
            over six of its cards: <strong>Header</strong>, <strong>Typography</strong>,
            <strong>Icons</strong>, <strong>Mobile</strong>, and — because the two icons are owned by the
            features themselves — <strong>Wishlist</strong> and <strong>Compare</strong>. Resetting only the
            first four leaves <em>Show the wishlist icon</em> and <em>Show the compare icon</em> exactly as
            they are.
        </p>
    </form>

    <!-- Preview ---------------------------------------------------------- -->
    <div class="aph-preview">
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <h2 class="ad-card__title">Live preview</h2>
                    <p class="ad-card__sub">The real storefront, restyled by the server with your unsaved
                        values.</p>
                </div>
            </div>
            <div class="ad-card__body">
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
                    <span class="aph-seg" role="group" aria-label="Preview width">
                        <button type="button" data-w="390">390</button>
                        <button type="button" data-w="768">768</button>
                        <button type="button" data-w="1440" class="is-on">1440</button>
                    </span>
                    <button type="button" class="ad-btn ad-btn--sm ad-btn--icon" data-reload
                            title="Reload preview" aria-label="Reload preview">
                        <?= icon('refresh', 'w-4 h-4') ?>
                    </button>
                    <span class="ad-muted" style="font-size:11.5px" data-preview-status></span>
                </div>

                <div class="aph-stage" data-stage>
                    <iframe class="aph-frame" data-frame src="<?= e(url()) ?>"
                            title="Storefront header preview" referrerpolicy="same-origin"></iframe>
                </div>

                <p class="aph-pending" data-pending></p>

                <p class="ad-muted" style="font-size:11.5px;line-height:1.5;margin:10px 0 0">
                    Sizing, spacing, type and colour are applied live because they are CSS custom properties.
                    Adding or removing a control changes the markup, so those show the note above until you
                    save.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var form   = document.getElementById('aphForm');
    var stage  = document.querySelector('[data-stage]');
    var frame  = document.querySelector('[data-frame]');
    var status = document.querySelector('[data-preview-status]');
    var pending = document.querySelector('[data-pending]');
    if (!form || !frame) return;

    var width = 1440;
    var baseBodyClass = null;   // the iframe's body classes minus the header ones
    var timer = null;

    /* What the storefront is serving right now, for the "needs a save" note. */
    var SAVED_FLAGS = <?= e_json($liveFlags) ?>;

    /* Classes header_body_class() owns. Everything else on <body> - the bottom
       nav flag, anything a later pass adds - is preserved, so the preview never
       silently removes a class it did not put there. */
    var OWNED = /^(sik-hstyle--|sik-hhover--|sik-hsearch--)|^(sik-hnav-off|sik-hburger-right|sik-htagline)$/;

    function fit() {
        var scale = Math.min(1, stage.clientWidth / width);
        frame.style.width = width + 'px';
        frame.style.height = Math.round(stage.clientHeight / scale) + 'px';
        frame.style.transform = 'scale(' + scale + ')';
    }

    document.querySelectorAll('[data-w]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-w]').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
            width = parseInt(btn.dataset.w, 10) || 1440;
            fit();
        });
    });

    document.querySelector('[data-reload]').addEventListener('click', function () {
        baseBodyClass = null;
        frame.src = frame.src;
    });

    window.addEventListener('resize', fit);
    fit();

    frame.addEventListener('load', function () {
        baseBodyClass = null;
        apply();
    });

    /* The server computes the preview. It runs the same
       header_settings_normalize() + header_css_vars() the live header runs, so
       the preview cannot drift from what saving would publish - which is the
       whole reason this is a fetch and not a pile of JavaScript. */
    function request() {
        var data = new FormData(form);

        /* An unchecked checkbox posts nothing at all. On save that is correct -
           settings_handle_save() reads absence as 0 - but the preview endpoint
           fills a missing key from HEADER_DEFAULTS, so an unchecked switch
           would preview as ON and the preview would promise a header that
           saving does not produce. Stating every switch explicitly is what
           keeps the two in step. */
        form.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            if (box.name) data.set(box.name, box.checked ? '1' : '0');
        });

        status.textContent = 'Updating…';

        fetch('<?= e(admin_url('appearance/header-preview.php')) ?>', {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': (window.SIK_CONFIG && window.SIK_CONFIG.csrfToken) || '' }
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.data) { status.textContent = 'Preview unavailable'; return; }
                paint(json.data);
                status.textContent = '';
            })
            .catch(function () { status.textContent = 'Preview unavailable'; });
    }

    function paint(data) {
        var doc = null;
        try { doc = frame.contentDocument; } catch (e) { return; }
        if (!doc || !doc.body) return;

        if (baseBodyClass === null) {
            baseBodyClass = (doc.body.className || '').split(/\s+/).filter(function (c) {
                return c && !OWNED.test(c);
            });
        }
        doc.body.className = baseBodyClass.concat((data.body || '').split(/\s+/)).join(' ').trim();

        var style = doc.getElementById('aphPreviewVars');
        if (!style) {
            style = doc.createElement('style');
            style.id = 'aphPreviewVars';
            doc.head.appendChild(style);
        }
        style.textContent = ':root{\n' + (data.vars || '') + '}';

        var input = doc.querySelector('[name="q"], input[type="search"]');
        if (input && data.flags && typeof data.flags.placeholder === 'string') {
            input.placeholder = data.flags.placeholder;
        }

        /* Markup-level differences the preview cannot honestly show: the
           control is either rendered or it is not, and faking one would show a
           header the storefront will never produce. Name them instead.

           Compared against the SAVED settings, printed by the server, rather
           than against what is findable in the iframe - see $liveFlags. */
        var save = [];
        if (data.flags) {
            [['notifications', 'notification bell'], ['wishlist', 'wishlist icon'],
             ['compare', 'compare icon'], ['cart', 'cart icon'], ['account', 'account icon'],
             ['voice', 'voice search']].forEach(function (pair) {
                if (data.flags[pair[0]] !== SAVED_FLAGS[pair[0]]) {
                    save.push((data.flags[pair[0]] ? 'add' : 'remove') + ' the ' + pair[1]);
                }
            });
        }

        if (save.length) {
            pending.textContent = 'Save to ' + save.join(', ') + '. These change the markup, not the CSS, '
                + 'so the preview cannot show them without a round trip.';
            pending.classList.add('is-on');
        } else {
            pending.textContent = '';
            pending.classList.remove('is-on');
        }
    }

    function apply() {
        clearTimeout(timer);
        timer = setTimeout(request, 180);
    }

    form.addEventListener('input', apply);
    form.addEventListener('change', apply);
})();
</script>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
