<?php
/**
 * ShopInnKart Admin - UI primitives (phase A1).
 *
 * Helpers that RETURN markup, in the same idiom as admin/includes/functions.php:
 * a required argument or two, then one options array. They are additive - no
 * page is obliged to use them until its roll-out wave - and they exist to stop
 * the two patterns the audit found most of:
 *
 *   1. 270 of 305 card titles are <div>s, so a screen reader hears one heading
 *      per admin page and cannot navigate the sections of a settings screen.
 *   2. Detail pages hand-build label/value rows out of inline-styled divs,
 *      three different ways (`bk-facts`, `trk-facts`, and bare flex).
 *
 * Markup and classes are section 44 of assets/css/admin.css.
 */

declare(strict_types=1);

/**
 * A complete card: header, body, optional footer.
 *
 * @param string $title    Heading text. Empty means no header row at all.
 * @param string $bodyHtml ALREADY-ESCAPED markup for the body.
 * @param array  $o        id, sub, actions, foot, flush, level (2|3), tone
 *                         (default|muted|danger), class, attrs (name => value)
 */
function admin_card(string $title, string $bodyHtml, array $o = []): string
{
    return admin_card_open($title, $o) . $bodyHtml . admin_card_close((string) ($o['foot'] ?? ''));
}

/**
 * The opening half of a card, for a page that prints its body inline.
 *
 * Always pair it with admin_card_close(). The id is generated when the caller
 * does not give one, because aria-labelledby needs a target and a page that
 * has to invent unique ids by hand stops bothering.
 */
function admin_card_open(string $title = '', array $o = []): string
{
    static $seq = 0;

    $id    = trim((string) ($o['id'] ?? ''));
    if ($id === '') {
        $id = 'adcard' . (++$seq);
    }
    $level = ((int) ($o['level'] ?? 2)) === 3 ? 'h3' : 'h2';
    $tone  = (string) ($o['tone'] ?? 'default');
    $sub   = trim((string) ($o['sub'] ?? ''));
    $acts  = (string) ($o['actions'] ?? '');

    $class = 'ad-card';
    if ($tone === 'muted' || $tone === 'danger') {
        $class .= ' ad-card--' . $tone;
    }
    if (!empty($o['class'])) {
        $class .= ' ' . $o['class'];
    }

    $attrs = '';
    foreach ((array) ($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . e($k) . '="' . e_attr((string) $v) . '"';
    }

    // Labelled by its own heading when it has one; a card with no title is a
    // plain container, and claiming a label it does not have is worse than
    // having none.
    $html = '<section class="' . e($class) . '" id="' . e_attr($id) . '"'
          . ($title !== '' ? ' aria-labelledby="' . e_attr($id) . '-t"' : '')
          . $attrs . '>';

    if ($title !== '' || $acts !== '') {
        $html .= '<header class="ad-card__head"><div class="ad-minw0">';
        if ($title !== '') {
            $html .= '<' . $level . ' class="ad-card__title" id="' . e_attr($id) . '-t">' . e($title) . '</' . $level . '>';
        }
        if ($sub !== '') {
            $html .= '<p class="ad-card__sub">' . e($sub) . '</p>';
        }
        $html .= '</div>';
        if ($acts !== '') {
            $html .= '<div class="ad-card__actions">' . $acts . '</div>';
        }
        $html .= '</header>';
    }

    return $html . '<div class="ad-card__body' . (!empty($o['flush']) ? ' ad-card__body--flush' : '') . '">';
}

/** Closes admin_card_open(). $footHtml is already-escaped markup. */
function admin_card_close(string $footHtml = ''): string
{
    return '</div>'
        . ($footHtml !== '' ? '<footer class="ad-card__foot">' . $footHtml . '</footer>' : '')
        . '</section>';
}

/**
 * A definition list of facts, for detail screens.
 *
 * Each row is [label, valueHtml, options], where options may carry:
 *   copy  - text a copy button puts on the clipboard (admin.js [data-copy])
 *   mono  - render the value in the mono face (ids, SKUs, AWBs)
 *   hint  - a second, quieter line under the value
 *
 * The VALUE is markup and is printed as given, because callers legitimately
 * pass a status pill or a link. The LABEL and the hint are plain text and are
 * escaped here, so the common case cannot go wrong.
 *
 * @param array $rows array<int, array{0:string,1:string,2?:array}>
 * @param array $o    class, attrs
 */
function admin_kv(array $rows, array $o = []): string
{
    $class = 'ad-kv' . (!empty($o['class']) ? ' ' . $o['class'] : '');
    $attrs = '';
    foreach ((array) ($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . e($k) . '="' . e_attr((string) $v) . '"';
    }

    $html = '<dl class="' . e($class) . '"' . $attrs . '>';
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row[0])) {
            continue;
        }
        $label = (string) $row[0];
        $value = (string) ($row[1] ?? '');
        $opt   = (array) ($row[2] ?? []);

        $html .= '<div class="ad-kv__row">'
              . '<dt class="ad-kv__key">' . e($label) . '</dt>'
              . '<dd class="ad-kv__val">';

        $inner = !empty($opt['mono']) ? '<span class="ad-mono">' . $value . '</span>' : $value;

        if (!empty($opt['copy'])) {
            // The button is labelled with the field name, not "Copy": a row of
            // six identical "Copy" buttons is unusable with a screen reader.
            $inner = '<span class="ad-cluster">' . $inner
                   . '<button type="button" class="ad-iconbtn ad-iconbtn--sm"'
                   . ' data-copy="' . e_attr((string) $opt['copy']) . '"'
                   . ' aria-label="Copy ' . e_attr($label) . '">'
                   . icon('copy', 'w-4 h-4') . '</button></span>';
        }
        $html .= $inner;

        if (!empty($opt['hint'])) {
            $html .= '<span class="ad-kv__hint">' . e((string) $opt['hint']) . '</span>';
        }
        $html .= '</dd></div>';
    }

    return $html . '</dl>';
}

/* ==========================================================================
   A2 - Overlay core: menu, tooltip, help popover, modal, drawer.

   Behaviour is assets/js/admin.js (initDropdowns, initTooltips, initPopovers,
   SIK.admin.modal, SIK.admin.remote); the look is admin.css sections 45-46.

   Every helper here RETURNS markup and escapes what it prints. The one
   deliberate exception is the argument named *Html, which is markup the
   caller has already built - a status pill, a form, a table - and is
   documented as such at each call.
   ========================================================================== */

/**
 * A short, collision-free id for a component that was not given one.
 *
 * Auto-ids matter here because aria-controls, aria-labelledby and
 * aria-describedby all need a target, and a page obliged to invent unique ids
 * by hand simply stops labelling things.
 */
function admin_ui_id(string $prefix): string
{
    static $seq = 0;

    return $prefix . (++$seq);
}

/**
 * Turn an options array into an attribute string. Values are attribute-escaped;
 * a value of TRUE prints the bare attribute (hidden, disabled).
 */
function admin_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $k => $v) {
        if ($v === false || $v === null) {
            continue;
        }
        $out .= ' ' . e($k) . ($v === true ? '' : '="' . e_attr((string) $v) . '"');
    }

    return $out;
}

/**
 * Tooltip attributes, ready to paste into a tag: admin_tip('Refunded in full').
 *
 * NEVER the accessible name. A tooltip does not exist on a touch device at
 * all, so an icon button keeps its aria-label and this only ever adds detail
 * a sighted mouse user would otherwise have to guess.
 *
 * @param string $pos top|bottom|left|right - a preference; JS flips it when
 *                    the tip would leave the viewport.
 */
function admin_tip(string $text, string $pos = 'top'): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $pos = in_array($pos, ['top', 'bottom', 'left', 'right'], true) ? $pos : 'top';

    return ' data-tooltip="' . e_attr($text) . '"'
        . ($pos === 'top' ? '' : ' data-tooltip-pos="' . $pos . '"');
}

/**
 * A round (i) that opens a small dialog of help text.
 *
 * Click to toggle, not hover: hover help cannot be read on a phone, and the
 * form help it replaces is the 11-12px grey paragraphs the audit found under
 * nearly every settings field.
 *
 * @param string $text Plain help text, or pre-built markup when $o['html'].
 * @param array  $o    field (what the help is about, for the label), id,
 *                     label (overrides the generated aria-label), html (bool),
 *                     class, attrs
 */
function admin_help(string $text, array $o = []): string
{
    $id    = trim((string) ($o['id'] ?? '')) ?: admin_ui_id('adhelp');
    $field = trim((string) ($o['field'] ?? ''));
    $label = trim((string) ($o['label'] ?? '')) ?: ($field !== '' ? 'About ' . $field : 'More information');
    $body  = !empty($o['html']) ? $text : '<p>' . nl2br(e($text)) . '</p>';

    return '<span class="ad-help-wrap">'
        . '<button type="button" class="ad-help' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '"'
        . ' data-popover="' . e_attr($id) . '" aria-controls="' . e_attr($id) . '"'
        . ' aria-expanded="false" aria-label="' . e_attr($label) . '"'
        . admin_attrs((array) ($o['attrs'] ?? [])) . '>'
        . icon('help', 'w-3.5 h-3.5') . '</button>'
        . '<div class="ad-popover" id="' . e_attr($id) . '" role="dialog"'
        . ' aria-label="' . e_attr($label) . '">' . $body . '</div>'
        . '</span>';
}

/**
 * A menu button and its panel.
 *
 *   admin_dropdown([
 *     'id'      => 'orderMore',
 *     'trigger' => ['label' => 'More', 'icon' => 'dots', 'variant' => 'btn',
 *                   'aria_label' => 'More actions for order SIK-1001'],
 *     'items'   => [
 *       ['label' => 'Print invoice', 'url' => '...', 'icon' => 'printer', 'kbd' => 'P'],
 *       ['divider' => true],
 *       ['heading' => 'Danger zone'],
 *       ['label' => 'Cancel order', 'tone' => 'danger',
 *        'form' => ['action' => '...', 'fields' => ['id' => 9]],
 *        'confirm' => ['text' => 'Cancel this order?', 'label' => 'Cancel order']],
 *       ['label' => 'Export', 'disabled' => 'Nothing to export yet'],
 *     ],
 *     'align' => 'end', 'sheet_on_phone' => true,
 *   ]);
 *
 * Item shapes: label(+url|form|attrs), divider, heading, html (raw block).
 * A disabled item carries its REASON, which becomes the tooltip - "why not"
 * is the only useful thing a dead control can say.
 */
function admin_dropdown(array $o): string
{
    $id      = trim((string) ($o['id'] ?? '')) ?: admin_ui_id('admenu');
    $panelId = $id . '-panel';
    $items   = (array) ($o['items'] ?? []);
    $role    = ((string) ($o['role'] ?? 'menu')) === 'dialog' ? 'dialog' : 'menu';
    $align   = ((string) ($o['align'] ?? 'end')) === 'start' ? 'start' : 'end';
    $sheet   = !empty($o['sheet_on_phone']);

    $t         = (array) ($o['trigger'] ?? []);
    $variant   = (string) ($t['variant'] ?? 'btn');
    $tLabel    = (string) ($t['label'] ?? '');
    $tIcon     = (string) ($t['icon'] ?? '');
    $tAria     = trim((string) ($t['aria_label'] ?? ''));

    $tClass = match ($variant) {
        'icon'  => 'ad-iconbtn',
        'ghost' => 'ad-btn ad-btn--sm',
        default => 'ad-btn',
    };
    if (!empty($t['class'])) {
        $tClass .= ' ' . $t['class'];
    }

    $html = '<div class="ad-menu"'
          . ' id="' . e_attr($id) . '" data-dropdown="menu"'
          . ($sheet ? ' data-menu-sheet="1"' : '')
          . admin_attrs((array) ($o['attrs'] ?? [])) . '>';

    // aria-haspopup names the SHAPE of the thing that opens; aria-controls
    // points at it; aria-expanded is flipped by JS and must start false so a
    // no-JS page does not claim an open panel.
    $html .= '<button type="button" class="' . e($tClass) . '" data-dropdown-toggle'
          . ' aria-haspopup="' . $role . '" aria-expanded="false"'
          . ' aria-controls="' . e_attr($panelId) . '"'
          . ($tAria !== '' ? ' aria-label="' . e_attr($tAria) . '"' : '')
          . admin_attrs((array) ($t['attrs'] ?? [])) . '>';
    if (!empty($t['html'])) {
        // Already-built markup, for a trigger that is more than icon + label
        // (the account button is an avatar, a name and a caret).
        $html .= $t['html'];
    } else {
        if ($tIcon !== '') {
            $html .= icon($tIcon, $variant === 'icon' ? 'w-5 h-5' : 'w-4 h-4');
        }
        if ($tLabel !== '') {
            $html .= '<span>' . e($tLabel) . '</span>';
        }
        if ($variant !== 'icon' && !empty($t['caret'])) {
            $html .= icon('chevron-down', 'w-3.5 h-3.5');
        }
    }
    $html .= '</button>';

    if ($sheet) {
        // Only ever visible as the phone sheet's scrim (section 45). A click
        // on it closes the menu, the same as an outside click on a desktop.
        $html .= '<div class="ad-menu__scrim" data-dropdown-close></div>';
    }

    $html .= '<div class="ad-menu__panel' . ($align === 'start' ? ' is-start' : '') . '"'
          . ' id="' . e_attr($panelId) . '" role="' . $role . '"'
          . ($tAria !== '' ? ' aria-label="' . e_attr($tAria) . '"' : '')
          . '>';

    foreach ($items as $item) {
        $html .= admin_dropdown_item((array) $item, $role);
    }

    return $html . '</div></div>';
}

/** One row of admin_dropdown(). Split out so the roll-out can reuse it. */
function admin_dropdown_item(array $item, string $role = 'menu'): string
{
    $itemRole = $role === 'menu' ? ' role="menuitem"' : '';

    if (!empty($item['divider'])) {
        return '<div role="separator" class="ad-menu__sep"></div>';
    }
    if (isset($item['heading'])) {
        // presentation, not a heading: a menu's own grouping label is not part
        // of the document outline, and role=menu only admits menuitems.
        return '<div class="ad-menu__label" role="presentation">' . e((string) $item['heading']) . '</div>';
    }
    if (isset($item['html'])) {
        // A role="menu" may only contain menuitems, separators and groups, so
        // a rich block inside one is hidden from assistive tech by default and
        // whatever it says belongs in the TRIGGER's accessible name instead
        // (the account menu says "Account menu: {name}"). Pass announce=>true
        // only for a menu whose role is "dialog", where the rule does not bite.
        return '<div class="ad-menu__head" role="presentation"'
            . (empty($item['announce']) ? ' aria-hidden="true"' : '') . '>'
            . $item['html'] . '</div>';
    }

    $label = (string) ($item['label'] ?? '');
    $cls   = 'ad-menu__item' . (($item['tone'] ?? '') === 'danger' ? ' ad-menu__item--danger' : '');
    if (!empty($item['class'])) {
        $cls .= ' ' . $item['class'];
    }

    $inner = (!empty($item['icon']) ? icon((string) $item['icon'], 'w-4 h-4') : '')
           . '<span class="ad-menu__text">' . e($label) . '</span>'
           . (!empty($item['kbd']) ? '<kbd class="ad-menu__kbd">' . e((string) $item['kbd']) . '</kbd>' : '');

    // A dead control that cannot say why is a dead end. The reason becomes the
    // tooltip AND aria-describedby fodder via the title-free data-tooltip.
    if (!empty($item['disabled'])) {
        $reason = is_string($item['disabled']) ? $item['disabled'] : '';

        return '<span class="' . e($cls) . '"' . $itemRole . ' aria-disabled="true" tabindex="-1"'
            . ($reason !== '' ? admin_tip($reason) : '') . '>' . $inner . '</span>';
    }

    $extra = admin_attrs((array) ($item['attrs'] ?? []));
    if (!empty($item['confirm'])) {
        /* A3 moved the attribute writing into one helper, so the dialog and
           the no-JS fallback can never drift apart. The vocabulary A2 defined
           here is unchanged; what is added is the onclick beside it. */
        $c = (array) $item['confirm'];
        $extra .= admin_confirm_attrs((string) ($c['text'] ?? 'Are you sure?'), [
            'title'   => (string) ($c['title'] ?? ''),
            'label'   => (string) ($c['label'] ?? ''),
            'cancel'  => (string) ($c['cancel'] ?? ''),
            'tone'    => (string) ($c['tone'] ?? 'danger'),
            'require' => (string) ($c['require'] ?? ''),
            'on'      => 'click',
        ]);
    }

    if (!empty($item['form'])) {
        $f      = (array) $item['form'];
        $method = strtolower((string) ($f['method'] ?? 'post')) === 'get' ? 'get' : 'post';
        $out    = '<form class="ad-menu__form" method="' . $method . '"'
                . ' action="' . e_attr((string) ($f['action'] ?? '')) . '">';
        if ($method === 'post') {
            $out .= csrf_field();
        }
        foreach ((array) ($f['fields'] ?? []) as $k => $v) {
            $out .= '<input type="hidden" name="' . e_attr((string) $k) . '" value="' . e_attr((string) $v) . '">';
        }

        return $out . '<button type="submit" class="' . e($cls) . '"' . $itemRole
            . ' tabindex="-1"' . $extra . '>' . $inner . '</button></form>';
    }

    if (!empty($item['url'])) {
        return '<a class="' . e($cls) . '"' . $itemRole . ' tabindex="-1"'
            . ' href="' . e_attr((string) $item['url']) . '"'
            . (!empty($item['target']) ? ' target="' . e_attr((string) $item['target']) . '" rel="noopener"' : '')
            . $extra . '>' . $inner . '</a>';
    }

    return '<button type="button" class="' . e($cls) . '"' . $itemRole
        . ' tabindex="-1"' . $extra . '>' . $inner . '</button>';
}

/**
 * The opening half of a modal. Pair with admin_modal_close().
 *
 * @param array $o size (sm|md|lg|xl), sheet (bool: bottom sheet under 640px),
 *                 sub, icon, tone (default|danger|warning), dismissible
 *                 (default true), role (dialog|alertdialog), describedby,
 *                 class, attrs
 */
function admin_modal_open(string $id, string $title, array $o = []): string
{
    // Read the option ONCE. Reading it again inside the ternary was a notice
    // on every modal that did not name a size, because the default satisfied
    // in_array() and the branch then went back for a key that was not there.
    $size  = (string) ($o['size'] ?? 'md');
    $size  = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
    $role  = ((string) ($o['role'] ?? 'dialog')) === 'alertdialog' ? 'alertdialog' : 'dialog';
    $tone  = (string) ($o['tone'] ?? '');
    $sub   = trim((string) ($o['sub'] ?? ''));
    $dism  = ($o['dismissible'] ?? true) !== false;

    $class = 'ad-modal ad-modal--' . $size
           . (!empty($o['sheet']) ? ' ad-modal--sheet' : '')
           . ($tone === 'danger' || $tone === 'warning' ? ' ad-modal--' . $tone : '')
           . (!empty($o['class']) ? ' ' . $o['class'] : '');

    // `hidden` is the no-JS resting state: with scripts off this is markup at
    // the foot of the document, and it must not paint over the page or be
    // read out as if it were open.
    $html = '<div class="' . e($class) . '" id="' . e_attr($id) . '" role="' . $role . '"'
          . ' aria-modal="true" aria-labelledby="' . e_attr($id) . '-title"'
          . (!empty($o['describedby']) ? ' aria-describedby="' . e_attr((string) $o['describedby']) . '"' : '')
          . ($dism ? '' : ' data-modal-static="1"')
          . ' hidden' . admin_attrs((array) ($o['attrs'] ?? [])) . '>'
          . '<div class="ad-modal__backdrop"' . ($dism ? ' data-modal-close' : '') . '></div>'
          . '<div class="ad-modal__panel" tabindex="-1">'
          . '<span class="ad-sheet__handle" aria-hidden="true"></span>'
          . '<header class="ad-modal__head"><div class="ad-minw0">'
          . '<h2 class="ad-modal__title" id="' . e_attr($id) . '-title">'
          . (!empty($o['icon']) ? icon((string) $o['icon'], 'w-5 h-5') . ' ' : '')
          . e($title) . '</h2>';

    if ($sub !== '') {
        $html .= '<p class="ad-modal__sub">' . e($sub) . '</p>';
    }
    $html .= '</div>';

    if ($dism) {
        $html .= '<button type="button" class="ad-iconbtn" data-modal-close aria-label="Close">'
               . icon('close', 'w-5 h-5') . '</button>';
    }

    return $html . '</header><div class="ad-modal__body">';
}

/** Closes admin_modal_open(). $footHtml is already-escaped markup. */
function admin_modal_close(string $footHtml = ''): string
{
    return '</div>'
        . ($footHtml !== '' ? '<footer class="ad-modal__foot">' . $footHtml . '</footer>' : '')
        . '</div></div>';
}

/**
 * The opening half of a drawer. Pair with admin_drawer_close().
 *
 * The ENGINE is app.js SIK.openDrawer/closeDrawer, opened by
 * [data-open-drawer="{id}"] - focus-in, the Tab trap, focus return, the scrim
 * and the layer stack all come from there. This helper only emits markup it
 * already knows how to drive.
 *
 * @param array $o side (right|left), size (md|lg), sheet (bool), sub, class, attrs
 */
function admin_drawer_open(string $id, string $title, array $o = []): string
{
    $class = 'ad-drawer'
           . (((string) ($o['side'] ?? 'right')) === 'left' ? ' ad-drawer--left' : '')
           . (((string) ($o['size'] ?? 'md')) === 'lg' ? ' ad-drawer--lg' : '')
           . (!empty($o['sheet']) ? ' ad-drawer--sheet' : '')
           . (!empty($o['class']) ? ' ' . $o['class'] : '');
    $sub = trim((string) ($o['sub'] ?? ''));

    // aria-hidden starts true and app.js flips it, which matches what the
    // storefront drawers already do; the CSS keeps a closed drawer out of the
    // tab order with visibility:hidden either way.
    return '<aside class="' . e($class) . '" id="' . e_attr($id) . '" role="dialog"'
        . ' aria-labelledby="' . e_attr($id) . '-title" aria-hidden="true"'
        . admin_attrs((array) ($o['attrs'] ?? [])) . '>'
        . '<header class="ad-drawer__head"><div class="ad-minw0">'
        . '<h2 class="ad-drawer__title" id="' . e_attr($id) . '-title">' . e($title) . '</h2>'
        . ($sub !== '' ? '<p class="ad-drawer__sub">' . e($sub) . '</p>' : '')
        . '</div>'
        . '<button type="button" class="ad-iconbtn" data-close-drawer="' . e_attr($id) . '" aria-label="Close">'
        . icon('close', 'w-5 h-5') . '</button>'
        . '</header><div class="ad-drawer__body">';
}

/** Closes admin_drawer_open(). $footHtml is already-escaped markup. */
function admin_drawer_close(string $footHtml = ''): string
{
    return '</div>'
        . ($footHtml !== '' ? '<footer class="ad-drawer__foot">' . $footHtml . '</footer>' : '')
        . '</aside>';
}

/* ==========================================================================
   Confirmation  (A3)
   ========================================================================== */

/**
 * The attributes that turn a link, a button or a form into a guarded action.
 *
 * Emits TWO guards from one source of truth, which is the whole point of the
 * helper - a hand-written pair drifts, and the day the dialog says "Delete the
 * brand" while the fallback says "Are you sure?" is the day nobody trusts
 * either of them:
 *
 *   data-confirm="..."           SIK.admin.confirm() picks this up and shows
 *                                the real dialog.
 *   onclick / onsubmit           the browser's own confirm(), which is what
 *                                guards the action if admin.js never runs.
 *                                admin.js strips this the moment its own
 *                                listeners are armed, so nobody sees two
 *                                prompts. See the A3 block in admin.js.
 *
 * `require` is for an action that cannot be undone - deleting a customer,
 * emptying a log, restoring a database. The operator has to type the name.
 *
 * @param string $text  The question. Say what will happen, in one sentence.
 * @param array  $o     title, label (confirm button), cancel, tone
 *                      (danger|warning|default), require (text to type),
 *                      on ('submit' for a <form>, 'click' for a link or
 *                      button, 'none' to leave the no-JS guard off).
 */
function admin_confirm_attrs(string $text, array $o = []): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $title   = trim((string) ($o['title'] ?? ''));
    $label   = trim((string) ($o['label'] ?? ''));
    $cancel  = trim((string) ($o['cancel'] ?? ''));
    $require = trim((string) ($o['require'] ?? ''));
    $tone    = (string) ($o['tone'] ?? 'danger');
    $tone    = in_array($tone, ['danger', 'warning', 'default'], true) ? $tone : 'danger';
    $on      = (string) ($o['on'] ?? 'click');

    $out = ' data-confirm="' . e_attr($text) . '"';
    if ($title !== '')   { $out .= ' data-confirm-title="' . e_attr($title) . '"'; }
    if ($label !== '')   { $out .= ' data-confirm-label="' . e_attr($label) . '"'; }
    if ($cancel !== '')  { $out .= ' data-confirm-cancel="' . e_attr($cancel) . '"'; }
    $out .= ' data-confirm-tone="' . e_attr($tone) . '"';
    if ($require !== '') { $out .= ' data-confirm-require="' . e_attr($require) . '"'; }

    if ($on !== 'submit' && $on !== 'click') {
        return $out;
    }

    /* The fallback carries the SAME words the dialog will. A custom title is
       a heading, so it leads and the sentence follows a blank line; the
       default title ("Are you sure?") adds nothing a native confirm box does
       not already say, so it is left out. A typed confirmation has no native
       equivalent at all - the no-JS operator gets the plain question, which
       is still a guard. */
    $native = $title !== '' ? $title . "\n\n" . $text : $text;
    if ($require !== '') {
        $native .= "\n\n" . 'This cannot be undone.';
    }

    $attr = $on === 'submit' ? 'onsubmit' : 'onclick';
    return $out . ' ' . $attr . '="return confirm(' . e_attr((string) json_encode($native)) . ')"';
}

/** admin_confirm_attrs() for a <form>: the no-JS guard goes on submit. */
function admin_confirm_form_attrs(string $text, array $o = []): string
{
    $o['on'] = 'submit';
    return admin_confirm_attrs($text, $o);
}
