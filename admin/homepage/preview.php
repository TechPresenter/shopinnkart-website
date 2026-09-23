<?php
/**
 * ShopInnKart Admin - Live storefront preview for the homepage designer.
 *
 * Renders one zone with the real storefront renderers, inside a bare document
 * that loads the real storefront stylesheets. The designer shows this in an
 * iframe it can size to a phone or a desktop; because the storefront CSS is
 * responsive, the width of the frame is the whole of the device simulation.
 *
 * It calls render_widget() rather than reimplementing anything, for the same
 * reason menus/preview.php does: a second renderer in JavaScript is a second
 * renderer that can disagree with the first, and a preview that confidently
 * shows something the shop does not draw is worse than no preview.
 *
 * Two deliberate differences from the live page:
 *   - Disabled sections ARE rendered, marked "Hidden". The designer is where
 *     you decide what to switch on, so you have to be able to see what you are
 *     switching on. zone_widgets() filters those out, so this reads its own rows.
 *   - Lazy widgets render their real body (context force), because a preview
 *     of an empty deferred shell tells the admin nothing.
 *
 * -----------------------------------------------------------------------
 * @measure-scope storefront-fragment
 *
 * This file is NOT an admin screen and must not be measured as one.
 *
 * The "no admin page scrolls sideways" sweep kept reporting one last inner
 * scroller in the whole admin, here: `div.sik-rail__track`, up to 1166px wide,
 * plus five clipped `sik-*` boxes (the hero frame, the news strip, a promo
 * card, its own ellipsised tag chip). The decision, taken deliberately rather
 * than patched away:
 *
 *   The canvas is an HONEST storefront preview, so a carousel that swipes is
 *   correct here, because it is correct on the shop.
 *
 * `.sik-rail__track` is the storefront's own product carousel - `overflow-x:
 * auto` with `scrollbar-width: none` and its own prev/next buttons - and this
 * file renders the real widgets against app.css precisely so that what an
 * admin sees is what a shopper gets. Making it stop scrolling here would mean
 * either editing storefront CSS (a different shop for everyone, to satisfy a
 * crawler) or drawing the preview with admin rules (a preview that lies).
 * Neither is worth it. designer.php mounts this in an iframe it sizes to a
 * phone, a tablet or a desktop (designer.php:126-129), and that iframe - not
 * the admin viewport - is the width the carousel is answering.
 *
 * So the sweep exempts it. The annotation above is what the crawler reads
 * (admin-overflow/pages.php), and the <meta> in the document below says the
 * same thing to anything that only has the rendered page. If a future change
 * ever puts admin CHROME in here - a toolbar, a form, anything drawn with
 * admin.css - then this stops being a fragment and the exemption must go with
 * it.
 * -----------------------------------------------------------------------
 *
 * GET + permission. Nothing here writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.view');

require_once __DIR__ . '/_meta.php';
require_once INCLUDES_PATH . '/widgets.php';

$zones = homepage_zones();
$zone  = (string) request_input('zone', 'home');
if (!isset($zones[$zone])) {
    $zone = 'home';
}

// The one section the designer currently has selected, so the preview can mark
// it. 0 means nothing is selected.
$focus = request_int('focus');

// Every row in the zone, in builder order - not zone_widgets(), which applies
// the storefront's status and visibility filters.
$sections = Database::fetchAll(
    "SELECT * FROM `homepage_sections` WHERE `zone` = :zone ORDER BY `sort_order` ASC, `id` ASC",
    ['zone' => $zone]
);

?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php /* Says to any layout crawler what the docblock says to a reader: the
         body of this document is storefront, drawn with storefront CSS, and
         its sideways-scrolling carousel is the shop's, not the admin's. */ ?>
<meta name="sik-measure-scope" content="storefront-fragment">
<title>Preview &mdash; <?= e($zones[$zone]['label']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/utilities.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>
    /* The preview is a fragment, not a page: no header, no footer, and no
       min-height stretching a single band down a 1500px frame. */
    body { background: var(--sik-bg); margin: 0; }

    /* A disabled section still renders, dimmed and captioned, because the
       designer is where you decide to switch it on. */
    .pv-off { position: relative; opacity: .42; filter: grayscale(.55); }
    .pv-off::after {
        content: "Hidden";
        position: absolute;
        top: 8px;
        left: 8px;
        z-index: 3;
        padding: 3px 9px;
        border-radius: 999px;
        background: #0F2143;
        color: #fff;
        font: 700 10px/1.4 system-ui, sans-serif;
        letter-spacing: .09em;
        text-transform: uppercase;
    }

    /* The selected section, so the middle pane and the canvas agree on what
       you are editing. outline, not border: a border would shift the layout
       and the preview would stop matching the shop. */
    .pv-sel { outline: 2px solid var(--sik-primary, #E4007C); outline-offset: -2px; }

    .pv-empty {
        padding: 48px 20px;
        text-align: center;
        color: #64748B;
        font: 500 14px/1.6 system-ui, sans-serif;
    }
    /* A widget that renders nothing at all would otherwise be invisible in the
       designer, and an admin would think the preview was broken. */
    .pv-blank {
        margin: 10px;
        padding: 18px;
        border: 1px dashed #CBD5E1;
        border-radius: 10px;
        color: #64748B;
        font: 500 13px/1.5 system-ui, sans-serif;
        text-align: center;
    }
</style>
</head>
<body>

<?php if ($sections === []): ?>
    <div class="pv-empty">This zone has no sections yet.</div>
<?php else: ?>
    <?php foreach ($sections as $section): ?>
        <?php
        $id      = (int) $section['id'];
        $off     = $section['status'] !== 'active';
        $classes = 'pv-section' . ($off ? ' pv-off' : '') . ($focus === $id ? ' pv-sel' : '');

        // force: render the real body even for a lazy widget, whose live
        // output is an empty shell that JS fills on scroll.
        $html = trim(render_widget($section, ['force' => true]));
        ?>
        <div class="<?= e_attr($classes) ?>" data-section="<?= $id ?>" id="pv-<?= $id ?>">
            <?php if ($html === ''): ?>
                <div class="pv-blank">
                    &ldquo;<?= e(homepage_widget_label((string) $section['widget_type'])) ?>&rdquo;
                    renders nothing right now &mdash; it has no content to show yet.
                </div>
            <?php else: ?>
                <?= $html ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
/* The designer asks for a section to be scrolled into view when its row is
   selected, and tells the designer when a section in the canvas is clicked.
   Same origin, so postMessage targets this origin explicitly rather than "*". */
(function () {
    var ORIGIN = window.location.origin;

    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN || !e.data || e.data.type !== 'sik:focus') { return; }
        var el = document.getElementById('pv-' + e.data.id);
        document.querySelectorAll('.pv-sel').forEach(function (n) { n.classList.remove('pv-sel'); });
        if (!el) { return; }
        el.classList.add('pv-sel');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    document.addEventListener('click', function (e) {
        var host = e.target.closest ? e.target.closest('[data-section]') : null;
        if (!host) { return; }
        // A preview is for looking at; following a storefront link inside the
        // frame would navigate away from the preview and strand the designer.
        if (e.target.closest('a')) { e.preventDefault(); }
        parent.postMessage({ type: 'sik:select', id: Number(host.dataset.section) }, ORIGIN);
    });
}());
</script>
</body>
</html>
