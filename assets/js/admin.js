/* ==========================================================================
   ShopInnKart Admin - Dashboard JavaScript
   Depends on app.js (SIK namespace) and notifications.js (SIK.toast).
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Admin = {};

    /* ----------------------------------------------------------------------
       Sidebar
       ---------------------------------------------------------------------- */
    function initSidebar() {
        const toggle = $('#adSidebarToggle');
        const sidebar = $('#adSidebar');
        const overlay = $('#adOverlay');
        if (!toggle || !sidebar) return;

        const mobileQuery = window.matchMedia('(max-width: 1023px)');
        const isMobile = () => mobileQuery.matches;

        function closeDrawer() {
            sidebar.removeAttribute('role');
            sidebar.setAttribute('aria-modal', 'false');
            sidebar.classList.remove('is-open');
            if (overlay) overlay.classList.remove('is-open');
            // A modal owns the same lock (see openModal below), so only release
            // it when nothing else is holding the page still.
            if (!$('.ad-modal.is-open')) document.body.classList.remove('sik-no-scroll');
            syncExpanded();
        }

        // The button means "is the sidebar showing its labels?" — on mobile that
        // is the open drawer, on desktop it is the un-collapsed rail.
        function syncExpanded() {
            toggle.setAttribute('aria-expanded', String(isMobile()
                ? sidebar.classList.contains('is-open')
                : !document.body.classList.contains('ad-collapsed')));
        }
        syncExpanded();

        toggle.addEventListener('click', function () {
            if (isMobile()) {
                const open = sidebar.classList.toggle('is-open');
                if (overlay) overlay.classList.toggle('is-open', open);
                document.body.classList.toggle('sik-no-scroll', open);

                // A drawer over an overlay is a modal in everything but name,
                // so say so and put the caret inside it. Without this the next
                // Tab landed in the page behind the veil.
                sidebar.setAttribute('aria-modal', open ? 'true' : 'false');
                if (open) {
                    sidebar.setAttribute('role', 'dialog');
                    const firstLink = sidebar.querySelector('.ad-nav__link');
                    if (firstLink) firstLink.focus();
                } else {
                    sidebar.removeAttribute('role');
                }
            } else {
                const collapsed = document.body.classList.toggle('ad-collapsed');
                // Remember the choice for a year so it survives navigation.
                document.cookie = 'sik_admin_sidebar=' + (collapsed ? '1' : '0')
                    + ';path=/;max-age=31536000;samesite=lax';
            }
            syncExpanded();
        });

        // Crossing the breakpoint (rotating a tablet, dragging a window narrow
        // and back) with the drawer open would otherwise strand `sik-no-scroll`
        // on <body>: above 1024px the overlay is display:none and the toggle
        // takes the desktop branch, so no control is left that can release it
        // and the whole admin becomes unscrollable until a reload.
        mobileQuery.addEventListener('change', closeDrawer);

        if (overlay) overlay.addEventListener('click', closeDrawer);

        /* The phone nav drawer predates the layer stack and is not on it (A4
           owns the shell). Its Escape still has to obey the one rule the stack
           exists for: Escape closes the TOP layer only. If a menu, popover or
           modal is open over the drawer, app.js is closing that one and this
           listener must keep its hands off, or a single Escape takes the nav
           with it.

           MEASURED (A2): reading the count in the BUBBLE phase does not work,
           and the guard that used to live in the Tab listener below silently
           did nothing. Both listeners sit on `document`; app.js registers
           first, so app.js's Escape had already run SIK.closeTop() and popped
           the menu by the time this one looked - it saw a count of 0 and
           closed the navigation too. Instrumented on the phone dashboard with
           the nav out and a menu over it: capture phase sees layers=1, bubble
           phase sees layers=0 and navOpen=false, i.e. both had gone.

           Capture runs before any bubble listener, so the count here is the
           one that was true when the key was pressed. When nothing is stacked
           this closes the drawer and app.js's closeTop() then finds an empty
           stack and returns. */
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!sidebar.classList.contains('is-open')) return;
            if (window.SIK && SIK.layers && SIK.layers.count() > 0) return;
            closeDrawer();
            toggle.focus();
        }, true);

        document.addEventListener('keydown', function (e) {
            // Trap Tab while the drawer is open. Only while it IS open and
            // only on mobile: on desktop the sidebar is part of the page and
            // trapping there would strand the keyboard in the nav.
            if (e.key !== 'Tab' || !isMobile() || !sidebar.classList.contains('is-open')) {
                return;
            }

            const focusable = [].slice.call(sidebar.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (el) {
                // A link inside a collapsed group is display:none and cannot
                // take focus, so it must not be one of the wrap points.
                return el.offsetParent !== null || el === document.activeElement;
            });
            if (focusable.length === 0) { return; }

            const first = focusable[0];
            const last  = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            } else if (!sidebar.contains(document.activeElement)) {
                // Focus escaped some other way (a click on the page behind);
                // pull it back rather than leaving the trap half-applied.
                e.preventDefault();
                first.focus();
            }
        });

        // Collapsible groups.
        SIK.on('click', '[data-nav-toggle]', function (e) {
            e.preventDefault();
            // In the collapsed rail there is nowhere to expand into, so jump
            // straight to the first child instead - but only while the rail is
            // actually narrow. It now widens on hover and on focus-within, and
            // in that state the submenu has somewhere to open, so teleporting
            // would skip past the other children the admin can now see.
            const sidebarEl = document.getElementById('adSidebar');
            const railIsNarrow = document.body.classList.contains('ad-collapsed')
                && !isMobile()
                && !(sidebarEl && sidebarEl.matches(':hover, :focus-within'));
            if (railIsNarrow) {
                const first = this.parentElement.querySelector('.ad-nav__sublink');
                if (first) { window.location.href = first.href; return; }
            }
            const group = this.closest('[data-nav-group]');
            if (!group) return;
            const open = group.classList.toggle('is-open');
            this.setAttribute('aria-expanded', String(open));
        });
    }

    /* ======================================================================
       OVERLAY CORE  (phase A2)

       Menus, context menus, tooltips, help popovers, modals, drawers and the
       remote quick view. Five components, ONE rule about who is on top:

         Every overlay pushes a handle onto SIK.layers (app.js) and pops it on
         close. The page has exactly ONE Escape listener, in app.js boot(),
         and it closes the TOP layer. That is why a menu opened inside a
         drawer closes the menu and leaves the drawer standing - nothing here
         listens for Escape on its own behalf, and nothing here closes a layer
         it did not open.

       The one keydown listener this file adds is for the tooltip, which is
       not a layer: it is a transient hint that belongs to whatever is
       focused, it never traps or blocks anything, and hiding it must not cost
       the operator the Escape that closes the dialog underneath.
       ====================================================================== */

    const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');
    const FINE_POINTER = window.matchMedia('(hover: hover) and (pointer: fine)');
    const PHONE = window.matchMedia('(max-width: 639px)');

    /** Anything that can take focus, for the modal's Tab trap. */
    const AD_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]),'
        + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function visible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    function pushLayer(el, close, opts) {
        if (!SIK.layers) return null;          // app.js older than this file
        const handle = { type: 'admin', el: el, close: close };
        if (opts) Object.keys(opts).forEach(k => { handle[k] = opts[k]; });
        return SIK.layers.push(handle);
    }

    function popLayer(el) {
        if (SIK.layers) SIK.layers.pop(el);
    }

    /** Fire a namespaced event that bubbles from the component. */
    function emit(el, name, detail) {
        el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} }));
    }

    /**
     * Run fn when a transition on `el` ends, or after `ms`, whichever is
     * first, and never twice. Reduced motion collapses every duration to
     * .01ms, so the transitionend path still fires and the timeout is only
     * ever the safety net for a transition that is never started at all
     * (a hidden element, a browser that skipped it).
     */
    function afterTransition(el, ms, fn) {
        let done = false;
        const finish = function () {
            if (done) return;
            done = true;
            el.removeEventListener('transitionend', onEnd);
            fn();
        };
        const onEnd = function (e) { if (e.target === el) finish(); };
        el.addEventListener('transitionend', onEnd);
        setTimeout(finish, REDUCED.matches ? 20 : ms);
    }

    /* ----------------------------------------------------------------------
       Menus and dropdowns

       Markup: admin_dropdown() in admin/includes/ui.php. The legacy
       [data-dropdown] / .ad-dropdown markup (the topbar account menu before
       A2, and anything a page still hand-writes) is driven by the SAME code -
       the only difference is which panel selector matches.
       ---------------------------------------------------------------------- */
    const PANEL_SEL = '.ad-menu__panel, .ad-dropdown__panel';
    const ITEM_SEL  = '.ad-menu__item, .ad-dropdown__item';

    let openMenuEl = null;     // only one menu is open at a time
    let typeAhead = '';
    let typeAheadTimer = null;

    function menuPanel(menu) { return menu.querySelector(PANEL_SEL); }
    function menuToggle(menu) { return menu.querySelector('[data-dropdown-toggle]'); }

    function menuItems(menu) {
        const panel = menuPanel(menu);
        if (!panel) return [];
        return Array.prototype.filter.call(
            panel.querySelectorAll(ITEM_SEL),
            el => el.getAttribute('aria-disabled') !== 'true' && visible(el)
        );
    }

    /**
     * Put the panel where it fits, in VIEWPORT coordinates.
     *
     * The panel is position:fixed (section 45 says why: an absolute one is
     * clipped by .ad-card's overflow and by the table wrapper's), so the
     * browser cannot anchor it to the trigger on its own and this does it:
     * it measures the trigger and the panel, picks a side, and writes left and
     * top. Measured AFTER opening, because a visibility:hidden panel has no
     * useful box until its own rules are live.
     *
     * A menu closes on scroll and on resize, so the coordinates never have to
     * follow anything; they only have to be right at the moment it opens.
     */
    function placeMenu(menu) {
        const panel = menuPanel(menu);
        if (!panel) return;

        // Whatever a previous opening wrote, so a sheet is not left holding
        // desktop coordinates that would beat its own `inset` rule.
        panel.style.left = '';
        panel.style.top = '';
        panel.style.maxHeight = '';
        if (menu.classList.contains('is-sheet')) return;          // docked to the bottom
        if (menu.classList.contains('ad-menu--context')) return;   // placed at the pointer

        // Remember what the server asked for, so a flip on one opening does
        // not become the permanent alignment on the next.
        if (panel._adAlign === undefined) {
            panel._adAlign = panel.classList.contains('is-start') ? 'start' : 'end';
        }
        panel.classList.remove('is-up');
        panel.classList.toggle('is-start', panel._adAlign === 'start');

        const pad = 8, gap = 8;
        const vw = window.innerWidth, vh = window.innerHeight;
        const trigger = menuToggle(menu) || menu;
        const tr = trigger.getBoundingClientRect();
        // offsetWidth/Height, NOT getBoundingClientRect: the entrance is a
        // transition from scale(.98), so for the first frames the rect is the
        // SCALED box and every sum below would be a couple of pixels out. The
        // layout box ignores transforms and is what the panel will settle at.
        let pw = panel.offsetWidth, ph = panel.offsetHeight;

        // Vertical: below by default. Flip up only when there is genuinely
        // more room above - a panel taller than both gaps is better left
        // below, where it can scroll.
        const below = vh - tr.bottom - gap - pad;
        const above = tr.top - gap - pad;
        const up = ph > below && above > below;
        panel.classList.toggle('is-up', up);
        // A panel taller than the side it ended on scrolls inside itself
        // rather than running off the screen.
        const room = Math.max(120, up ? above : below);
        if (ph > room) {
            panel.style.maxHeight = Math.round(room) + 'px';
            ph = panel.offsetHeight;
        }
        const top = up ? Math.max(pad, tr.top - ph - gap) : Math.min(tr.bottom + gap, vh - ph - pad);

        // Horizontal: the panel's end edge sits on the trigger's end edge by
        // default (`is-start` puts its start edge on the trigger's start
        // edge). Either alignment can run off a screen edge, so each one flips
        // to the other before anything is clamped - and the class follows the
        // alignment actually used, because it is also the corner the entrance
        // grows from.
        let start = panel.classList.contains('is-start');
        let left = start ? tr.left : tr.right - pw;
        if (left + pw > vw - pad) { start = false; left = tr.right - pw; }
        if (left < pad) { start = true; left = tr.left; }
        panel.classList.toggle('is-start', start);
        left = Math.min(Math.max(pad, left), Math.max(pad, vw - pw - pad));

        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(Math.max(pad, top)) + 'px';
    }

    /**
     * Move roving focus to one item. The trigger keeps the only tab stop.
     *
     * preventScroll is not a nicety: focusing an item in a tall menu makes the
     * browser scroll the nearest scrollable ancestor, and a menu closes on
     * scroll - so without it, arrow-keying into a menu closed the menu.
     * The panel is scrolled by hand instead, which fires on the panel and is
     * the one scroll the close handler ignores.
     */
    function focusItem(menu, item) {
        if (!item) return;
        menuItems(menu).forEach(el => el.classList.remove('is-active'));
        item.classList.add('is-active');
        try { item.focus({ preventScroll: true }); } catch (err) { item.focus(); }

        const panel = menuPanel(menu);
        if (!panel || panel.scrollHeight <= panel.clientHeight) return;
        const pr = panel.getBoundingClientRect();
        const ir = item.getBoundingClientRect();
        if (ir.top < pr.top) panel.scrollTop -= (pr.top - ir.top) + 4;
        else if (ir.bottom > pr.bottom) panel.scrollTop += (ir.bottom - pr.bottom) + 4;
    }

    function openMenu(menu, opts) {
        if (!menu || menu.classList.contains('is-open')) return;
        opts = opts || {};
        closeMenu();                                   // one at a time

        // The sheet decision is taken per opening, not per page load: a
        // rotation or a resize between two clicks must get the right frame.
        const asSheet = menu.dataset.menuSheet === '1' && PHONE.matches;
        menu.classList.toggle('is-sheet', asSheet);
        menu.classList.add('is-open');

        const toggle = menuToggle(menu);
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        // The context menu has no toggle and sets this itself before opening,
        // so it must not be cleared here or Escape would have nowhere to
        // hand focus back to.
        menu._adReturnFocus = toggle || menu._adReturnFocus || null;

        placeMenu(menu);
        openMenuEl = menu;
        menu._adOpenedAt = Date.now();
        // A desktop menu does NOT lock scrolling: the page is still usable
        // behind it and the menu closes on scroll. The phone sheet does,
        // because it is a modal surface with a scrim.
        pushLayer(menu, function () { closeMenu(menu, { returnFocus: true }); }, { lock: asSheet });
        emit(menu, 'sik:menu:open');

        if (opts.focus === 'first') focusItem(menu, menuItems(menu)[0]);
        else if (opts.focus === 'last') {
            const items = menuItems(menu);
            focusItem(menu, items[items.length - 1]);
        }
    }

    function closeMenu(menu, opts) {
        menu = menu || openMenuEl;
        if (!menu || !menu.classList.contains('is-open')) return;
        opts = opts || {};

        menu.classList.remove('is-open');
        const toggle = menuToggle(menu);
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        menuItems(menu).forEach(el => el.classList.remove('is-active'));
        popLayer(menu);
        if (openMenuEl === menu) openMenuEl = null;
        emit(menu, 'sik:menu:close');

        // Only when focus is still INSIDE the panel that is going away. A
        // click elsewhere has already moved it, and yanking it back to the
        // trigger would undo the operator's own choice.
        const back = menu._adReturnFocus;
        if (back && document.contains(back)
            && (opts.returnFocus === true || menu.contains(document.activeElement))) {
            back.focus();
        }
        // The panel keeps `is-sheet` until the exit transition has run, or the
        // sheet would jump to the corner on its way out.
        afterTransition(menuPanel(menu) || menu, 400, function () {
            if (!menu.classList.contains('is-open')) menu.classList.remove('is-sheet');
        });
    }

    function typeAheadJump(menu, ch) {
        const items = menuItems(menu);
        if (!items.length) return;
        clearTimeout(typeAheadTimer);
        typeAhead += ch.toLowerCase();
        typeAheadTimer = setTimeout(function () { typeAhead = ''; }, 700);

        // "ddd" cycles through every item starting with d; "de" refines and
        // looks for "de" from where the caret already is. That is the
        // behaviour a menu in any desktop toolkit has.
        const allSame = typeAhead.split('').every(c => c === typeAhead[0]);
        const needle = allSame ? typeAhead.charAt(0) : typeAhead;
        const cur = items.indexOf(document.activeElement);
        const from = (allSame || typeAhead.length === 1) ? cur + 1 : Math.max(cur, 0);

        for (let n = 0; n < items.length; n++) {
            const item = items[((from + n) % items.length + items.length) % items.length];
            if ((item.textContent || '').trim().toLowerCase().indexOf(needle) === 0) {
                focusItem(menu, item);
                return;
            }
        }
    }

    function initDropdowns() {
        SIK.on('click', '[data-dropdown-toggle]', function (e) {
            e.preventDefault();
            const menu = this.closest('[data-dropdown], .ad-menu, .ad-dropdown');
            if (!menu) return;
            if (menu.classList.contains('is-open')) closeMenu(menu, { returnFocus: true });
            else openMenu(menu);
        });

        SIK.on('click', '[data-dropdown-close]', function () { closeMenu(); });

        // Opening from the keyboard lands on an item; opening with the mouse
        // leaves focus on the trigger, which is what a pointer user expects.
        SIK.on('keydown', '[data-dropdown-toggle]', function (e) {
            const menu = this.closest('[data-dropdown], .ad-menu, .ad-dropdown');
            if (!menu) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!menu.classList.contains('is-open')) openMenu(menu);
                const items = menuItems(menu);
                focusItem(menu, e.key === 'ArrowDown' ? items[0] : items[items.length - 1]);
            } else if (e.key === 'Tab' && menu.classList.contains('is-open')) {
                // Tabbing off an open trigger closes the panel and lets focus
                // carry on; it must not leave a panel open behind the caret.
                closeMenu(menu, { returnFocus: false });
            }
        });

        // One keydown listener for every panel, on the document. Escape is
        // NOT handled here: app.js owns it and closes the top layer.
        //
        // The test is the PANEL, not the menu: the trigger is inside the menu
        // too, and when both handlers ran the roving focus moved twice on a
        // single ArrowDown (the toggle handler put it on item 1, this one
        // then stepped it on to item 2).
        document.addEventListener('keydown', function (e) {
            if (!openMenuEl) return;
            // The toggle's own handler ran first on this very event and has
            // already moved the roving focus INTO the panel; without this the
            // second listener would step it on again and one ArrowDown would
            // skip an item.
            if (e.defaultPrevented) return;
            const menu = openMenuEl;
            const panel = menuPanel(menu);
            if (!panel || !panel.contains(document.activeElement)) return;

            const items = menuItems(menu);
            const i = items.indexOf(document.activeElement);

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    focusItem(menu, items[(i + 1) % items.length]);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    focusItem(menu, items[(i - 1 + items.length) % items.length]);
                    break;
                case 'Home':
                    e.preventDefault();
                    focusItem(menu, items[0]);
                    break;
                case 'End':
                    e.preventDefault();
                    focusItem(menu, items[items.length - 1]);
                    break;
                case 'Tab':
                    // Close and let focus carry on from the trigger, which is
                    // where the panel logically sits in the reading order.
                    closeMenu(menu, { returnFocus: true });
                    break;
                case ' ':
                    // Space activates a link item, which it does not do natively.
                    if (document.activeElement && document.activeElement.tagName === 'A') {
                        e.preventDefault();
                        document.activeElement.click();
                    }
                    break;
                default:
                    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        e.preventDefault();
                        typeAheadJump(menu, e.key);
                    }
            }
        });

        // An outside press closes. pointerdown rather than click, so the menu
        // is already gone by the time the click lands on whatever is under it.
        document.addEventListener('pointerdown', function (e) {
            if (!openMenuEl) return;
            if (openMenuEl.contains(e.target)) return;
            if (e.target.closest && e.target.closest('.ad-menu__scrim')) { closeMenu(); return; }
            closeMenu();
        }, true);

        // A menu is anchored to its trigger and cannot follow it, so it closes
        // rather than drifting. Scrolling INSIDE the panel is not that, and
        // neither is the scroll the browser performs while the menu is still
        // opening (focusing an item, the page settling under a sheet).
        window.addEventListener('scroll', function (e) {
            if (!openMenuEl) return;
            if (Date.now() - (openMenuEl._adOpenedAt || 0) < 250) return;
            const panel = menuPanel(openMenuEl);
            if (panel && (e.target === panel || panel.contains(e.target))) return;
            closeMenu();
        }, true);
        window.addEventListener('resize', function () { closeMenu(); });

        // Activating an item closes the menu. A form item is left alone: its
        // own submit handler (and, from A3, the confirm dialog) runs first.
        SIK.on('click', ITEM_SEL, function (e) {
            if (this.getAttribute('aria-disabled') === 'true') { e.preventDefault(); return; }
            const menu = this.closest('[data-dropdown], .ad-menu, .ad-dropdown');
            if (menu) setTimeout(function () { closeMenu(menu); }, 0);
        });
    }

    Admin.menu = { open: openMenu, close: closeMenu };

    /* ----------------------------------------------------------------------
       Context menu

       `data-context-menu="auto"` on a row mirrors that row's own kebab;
       `data-context-menu="#tplId"` clones a <template>. It is never the only
       path to an action - it can only ever show what is already in the kebab,
       so an operator who never right-clicks loses nothing.
       ---------------------------------------------------------------------- */
    let ctxMenu = null;

    function contextHost() {
        if (ctxMenu && document.contains(ctxMenu)) return ctxMenu;
        ctxMenu = document.createElement('div');
        ctxMenu.className = 'ad-menu ad-menu--context';
        ctxMenu.id = 'adContextMenu';
        ctxMenu.setAttribute('data-dropdown', 'menu');
        ctxMenu.innerHTML = '<div class="ad-menu__panel" role="menu"></div>';
        document.body.appendChild(ctxMenu);
        return ctxMenu;
    }

    /** The source of truth for a row's context items: its own kebab. */
    function contextSource(row) {
        const spec = row.dataset.contextMenu || 'auto';
        if (spec !== 'auto') {
            const tpl = document.querySelector(spec);
            return (tpl && tpl.content) ? tpl.content.cloneNode(true) : null;
        }
        const kebab = row.querySelector('.ad-rowmenu ' + PANEL_SEL) || row.querySelector(PANEL_SEL);
        if (!kebab) return null;
        const frag = document.createDocumentFragment();
        Array.prototype.forEach.call(kebab.children, n => frag.appendChild(n.cloneNode(true)));
        return frag;
    }

    function openContextMenu(row, x, y) {
        const items = contextSource(row);
        if (!items || !items.childNodes.length) return false;

        const host = contextHost();
        const panel = host.querySelector(PANEL_SEL);
        panel.textContent = '';
        panel.appendChild(items);
        // Clones keep their roving tabindex; the panel is opened the same way
        // an anchored menu is, so every key behaves identically.
        Array.prototype.forEach.call(panel.querySelectorAll(ITEM_SEL), el => el.setAttribute('tabindex', '-1'));

        host.style.setProperty('--ad-cm-x', Math.round(x) + 'px');
        host.style.setProperty('--ad-cm-y', Math.round(y) + 'px');
        host._adReturnFocus = (document.activeElement instanceof HTMLElement
            && document.activeElement !== document.body) ? document.activeElement : row;
        if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '-1');
        // A context menu takes focus however it was opened. A native one
        // does, and without it the arrow keys have nothing to rove over and
        // Escape has nothing to give focus back to.
        openMenu(host, { focus: 'first' });

        // Placed at the pointer, so it is nudged back inside the viewport
        // rather than flipped around an anchor it does not have.
        const r = panel.getBoundingClientRect();
        if (r.right > window.innerWidth - 8) {
            host.style.setProperty('--ad-cm-x', Math.max(8, Math.round(x - r.width)) + 'px');
        }
        if (r.bottom > window.innerHeight - 8) {
            host.style.setProperty('--ad-cm-y', Math.max(8, Math.round(y - r.height)) + 'px');
        }
        return true;
    }

    function initContextMenus() {
        document.addEventListener('contextmenu', function (e) {
            const row = e.target.closest && e.target.closest('[data-context-menu]');
            if (!row) return;
            // Shift + right-click is the escape hatch to the browser's own
            // menu, and a link, a field or a live selection keeps it too:
            // "copy link address" and "search for this" are real actions.
            if (e.shiftKey) return;
            if (e.target.closest('a[href], input, textarea, select, [contenteditable]')) return;
            const sel = window.getSelection();
            if (sel && !sel.isCollapsed && row.contains(sel.anchorNode)) return;
            if (openContextMenu(row, e.clientX, e.clientY)) e.preventDefault();
        });

        // Long press, coarse pointers only. 8px of movement is a scroll, not
        // a press, and cancels it - the same SLOP the drag engine uses.
        let pressTimer = null, pressX = 0, pressY = 0;
        document.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse') return;
            const row = e.target.closest && e.target.closest('[data-context-menu]');
            if (!row) return;
            pressX = e.clientX; pressY = e.clientY;
            pressTimer = setTimeout(function () {
                pressTimer = null;
                openContextMenu(row, pressX, pressY);
            }, 500);
        }, true);
        const cancelPress = function () {
            if (pressTimer === null) return;
            clearTimeout(pressTimer);
            pressTimer = null;
        };
        document.addEventListener('pointermove', function (e) {
            if (pressTimer !== null
                && (Math.abs(e.clientX - pressX) > 8 || Math.abs(e.clientY - pressY) > 8)) cancelPress();
        }, true);
        document.addEventListener('pointerup', cancelPress, true);
        document.addEventListener('pointercancel', cancelPress, true);

        // Shift+F10 and the ContextMenu key, placed under the focused element
        // rather than at a pointer that a keyboard user does not have.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'ContextMenu' && !(e.key === 'F10' && e.shiftKey)) return;
            const row = document.activeElement && document.activeElement.closest
                ? document.activeElement.closest('[data-context-menu]') : null;
            if (!row) return;
            const r = document.activeElement.getBoundingClientRect();
            if (openContextMenu(row, r.left, r.bottom + 4)) e.preventDefault();
        });
    }

    /* ----------------------------------------------------------------------
       Tooltip

       ONE node, reused. It is never an accessible name (icon buttons keep
       their aria-label) and it never appears on touch, so nothing essential
       may live only here. While it is showing, the element it describes
       points at it with aria-describedby; that is removed again on hide, so
       a stale reference can never outlive the tip.
       ---------------------------------------------------------------------- */
    let tipEl = null, tipTimer = null, tipOwner = null, tipPrevDescribedBy = null;

    function tipNode() {
        if (tipEl && document.contains(tipEl)) return tipEl;
        tipEl = document.createElement('div');
        tipEl.className = 'ad-tooltip';
        tipEl.id = 'adTip';
        tipEl.setAttribute('role', 'tooltip');
        document.body.appendChild(tipEl);
        return tipEl;
    }

    function placeTip(owner, tip) {
        const pos = owner.dataset.tooltipPos || 'top';
        const r = owner.getBoundingClientRect();
        const t = tip.getBoundingClientRect();
        const gap = 8, pad = 8;
        let top, left;

        if (pos === 'bottom')      { top = r.bottom + gap; left = r.left + (r.width - t.width) / 2; }
        else if (pos === 'left')   { top = r.top + (r.height - t.height) / 2; left = r.left - t.width - gap; }
        else if (pos === 'right')  { top = r.top + (r.height - t.height) / 2; left = r.right + gap; }
        else                       { top = r.top - t.height - gap; left = r.left + (r.width - t.width) / 2; }

        // Auto-flip: a tip that would leave the viewport goes to the other
        // side rather than being clipped at the edge.
        if (top < pad) top = r.bottom + gap;
        if (top + t.height > window.innerHeight - pad) top = Math.max(pad, r.top - t.height - gap);
        left = Math.min(Math.max(pad, left), window.innerWidth - t.width - pad);

        // left/top, not transform: transform is what the entrance animates,
        // and an inline one would silently cancel it.
        tip.style.left = Math.round(left) + 'px';
        tip.style.top = Math.round(top) + 'px';
    }

    function showTip(owner) {
        const text = (owner.getAttribute('data-tooltip') || '').trim();
        if (!text) return;
        const tip = tipNode();
        tip.textContent = text;                      // textContent, never innerHTML
        tip.classList.add('is-open');
        placeTip(owner, tip);
        tipOwner = owner;

        tipPrevDescribedBy = owner.getAttribute('aria-describedby');
        owner.setAttribute('aria-describedby', 'adTip');
    }

    function hideTip() {
        if (!tipEl) return;
        clearTimeout(tipTimer);
        tipEl.classList.remove('is-open');
        if (tipOwner) {
            if (tipPrevDescribedBy) tipOwner.setAttribute('aria-describedby', tipPrevDescribedBy);
            else tipOwner.removeAttribute('aria-describedby');
        }
        tipOwner = null;
        tipPrevDescribedBy = null;
    }

    function initTooltips() {
        // Hover: fine pointers only, after a beat, so running the mouse across
        // a toolbar does not flash six tips on the way past.
        //
        // mouseover/mouseout, not mouseenter/mouseleave: SIK.on delegates from
        // the document and the enter/leave pair does not bubble, so a
        // delegated mouseenter never fires at all.
        SIK.on('mouseover', '[data-tooltip]', function (e) {
            if (!FINE_POINTER.matches) return;
            if (this === tipOwner) return;
            if (e.relatedTarget && this.contains(e.relatedTarget)) return;   // moved within
            const el = this;
            clearTimeout(tipTimer);
            tipTimer = setTimeout(function () { showTip(el); }, 350);
        });
        SIK.on('mouseout', '[data-tooltip]', function (e) {
            if (e.relatedTarget && this.contains(e.relatedTarget)) return;
            hideTip();
        });

        // Focus shows it at once and on EVERY pointer type: a keyboard on a
        // tablet is still a keyboard, and waiting 350ms after a deliberate
        // Tab is just a delay.
        SIK.on('focusin', '[data-tooltip]', function () {
            clearTimeout(tipTimer);
            if (this.matches(':focus-visible')) showTip(this);
        });
        SIK.on('focusout', '[data-tooltip]', hideTip);
        SIK.on('click', '[data-tooltip]', hideTip);

        // A tip is anchored to an element it cannot follow.
        window.addEventListener('scroll', hideTip, true);
        window.addEventListener('resize', hideTip);

        // The ONE keydown this file adds. A tooltip is not a layer: it traps
        // nothing and blocks nothing, so hiding it must not consume the
        // Escape that closes the dialog underneath. Nothing is prevented and
        // no layer is touched.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hideTip();
        });
    }

    /* ----------------------------------------------------------------------
       Help popover

       Click to toggle, not hover: hover help cannot be read on a phone, and
       it replaces the 11-12px grey paragraphs under settings fields, which
       are exactly the text a small screen has no room for.
       ---------------------------------------------------------------------- */
    let openPopover = null;

    /**
     * Park a fixed-position panel under (or over) the control that opened it,
     * inside the viewport. The plain version of what placeMenu() does for
     * menus, for a panel with no alignment options of its own.
     */
    function anchorTo(el, trigger, gap) {
        gap = gap || 6;
        const pad = 8;
        const tr = trigger.getBoundingClientRect();
        el.style.left = '0px';
        el.style.top = '0px';
        // The layout box, not the rect: the entrance transitions a transform.
        const pw = el.offsetWidth, ph = el.offsetHeight;
        const below = window.innerHeight - tr.bottom - gap - pad;
        const top = (ph > below && tr.top - gap - pad > below)
            ? Math.max(pad, tr.top - ph - gap)                // more room above
            : Math.min(tr.bottom + gap, Math.max(pad, window.innerHeight - ph - pad));
        const left = Math.min(Math.max(pad, tr.left), Math.max(pad, window.innerWidth - pw - pad));
        el.style.left = Math.round(left) + 'px';
        el.style.top = Math.round(top) + 'px';
    }

    function closePopover(pop, returnFocus) {
        pop = pop || openPopover;
        if (!pop) return;
        pop.classList.remove('is-open');
        const trigger = document.querySelector('[data-popover="' + pop.id + '"]');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            if (returnFocus !== false && pop.contains(document.activeElement)) trigger.focus();
        }
        popLayer(pop);
        if (openPopover === pop) openPopover = null;
    }

    function initPopovers() {
        SIK.on('click', '[data-popover]', function (e) {
            e.preventDefault();
            const pop = document.getElementById(this.dataset.popover);
            if (!pop) return;
            if (pop.classList.contains('is-open')) { closePopover(pop); return; }

            closePopover();
            pop.classList.add('is-open');
            this.setAttribute('aria-expanded', 'true');
            openPopover = pop;
            pushLayer(pop, function () { closePopover(pop); });

            // Anchored under the (i) in VIEWPORT coordinates, for the same
            // measured reason the menu panel is (section 45): a 320px popover
            // opened from a settings field is wider than the space left
            // inside .ad-card, and an absolutely-positioned one was cut off
            // at the card's edge instead of sliding back into view.
            anchorTo(pop, this, 6);
            // Focusing the dialog is what makes the help readable to a screen
            // reader, and it is what lets Escape hand focus back to the (i).
            pop.setAttribute('tabindex', '-1');
            pop.focus();
        });

        document.addEventListener('pointerdown', function (e) {
            if (!openPopover) return;
            if (openPopover.contains(e.target)) return;
            if (e.target.closest && e.target.closest('[data-popover="' + openPopover.id + '"]')) return;
            closePopover(openPopover, false);
        }, true);

        // A fixed panel cannot follow the (i) it belongs to, so it closes
        // instead of drifting away from it - the same rule as the menu.
        // Scrolling INSIDE the help text is not that.
        window.addEventListener('scroll', function (e) {
            if (!openPopover) return;
            if (e.target === openPopover || openPopover.contains(e.target)) return;
            closePopover(openPopover, false);
        }, true);
        window.addEventListener('resize', function () { closePopover(openPopover, false); });
    }

    /* ----------------------------------------------------------------------
       Modals

       Compatibility first: Admin.openModal(id) / Admin.closeModal(),
       [data-modal-open], [data-modal-close], the .ad-modal__backdrop click
       and the data-field-* prefill all keep their exact meanings. What is new
       is the entrance, the Tab trap, `inert` on the rest of the page, focus
       return and the layer handle.
       ---------------------------------------------------------------------- */
    const modalState = new WeakMap();

    function modalEl(target) {
        if (typeof target === 'string') return document.getElementById(target);
        if (target && target.closest) return target.closest('.ad-modal') || target;
        return target;
    }

    /**
     * Make everything OUTSIDE the modal inert, so the page behind it is
     * genuinely unreachable and not merely covered.
     *
     * It walks the modal's ancestor chain and inerts each level's other
     * children, rather than only the children of <body>. That is not
     * pedantry: every modal in this admin is rendered inside the page
     * content, so it is a descendant of .ad-shell - inerting .ad-shell as a
     * body child made the MODAL ITSELF inert, and focus stayed on <body>.
     *
     * The toast region is left alive on purpose: an operator must still hear
     * "Saved" while a dialog is open, and it is aria-live, not a focus
     * target. The count on data-ad-inert lets two stacked modals unwind in
     * any order without one of them un-inerting the other's page.
     */
    function inertSiblings(modal, on) {
        const keep = [document.getElementById('sikToasts'), tipEl, ctxMenu].filter(Boolean);
        let node = modal;
        while (node && node.parentElement) {
            const parent = node.parentElement;
            const branch = node;
            Array.prototype.forEach.call(parent.children, function (child) {
                if (child === branch) return;
                if (keep.indexOf(child) !== -1) return;
                const n = parseInt(child.getAttribute('data-ad-inert') || '0', 10);
                if (on) {
                    if (n === 0 && !child.hasAttribute('inert')) child.setAttribute('inert', '');
                    child.setAttribute('data-ad-inert', String(n + 1));
                } else if (n > 0) {
                    if (n === 1) {
                        child.removeAttribute('inert');
                        child.removeAttribute('data-ad-inert');
                    } else {
                        child.setAttribute('data-ad-inert', String(n - 1));
                    }
                }
            });
            if (parent === document.body) break;
            node = parent;
        }
    }

    function modalTrap(modal) {
        return function (e) {
            if (e.key !== 'Tab') return;
            const panel = modal.querySelector('.ad-modal__panel') || modal;
            const items = Array.prototype.filter.call(panel.querySelectorAll(AD_FOCUSABLE), visible);
            if (!items.length) { e.preventDefault(); panel.focus(); return; }
            const i = items.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) { e.preventDefault(); items[items.length - 1].focus(); }
            else if (!e.shiftKey && i === items.length - 1) { e.preventDefault(); items[0].focus(); }
        };
    }

    /**
     * Take ownership of a modal that the SERVER rendered open - the
     * validation-error path on blog/categories.php and settings/shipping.php.
     * Without this the entrance would never run and the panel would sit at
     * opacity 0 behind its own scrim. Called from boot(), in the same tick as
     * the no-js -> js swap, so nothing paints in between.
     */
    function adoptOpenModal(modal) {
        if (modalState.has(modal)) return;
        modal.hidden = false;
        modal.classList.add('is-open', 'is-shown');
        wireOpenModal(modal, { returnFocus: null });
    }

    /**
     * Give the dialog its SEMANTICS, rather than assuming the markup has them.
     *
     * MEASURED by the verifier on blog/categories.php - the one admin screen
     * whose modal is its primary control - role, aria-modal and
     * aria-labelledby all came back null. admin_modal_open() writes all three,
     * but the two pages that ship a modal today were written before that
     * helper existed and adoptOpenModal() only ever gave them the trap and
     * `inert`. A screen reader therefore met a focus-trapped region it could
     * not name and was never told was a dialog, while the fixture the phase
     * was tested against - built with the helper - passed.
     *
     * Applied on the way IN, so it covers every hand-written modal at once,
     * now and later, and is a no-op wherever the helper already did the work.
     * Nothing is overwritten: an explicit role, an aria-label, or an
     * aria-labelledby that resolves is left exactly as the author wrote it.
     */
    let modalTitleSeq = 0;
    function nameModal(modal) {
        if (!modal.getAttribute('role')) modal.setAttribute('role', 'dialog');
        if (!modal.getAttribute('aria-modal')) modal.setAttribute('aria-modal', 'true');

        const named = modal.getAttribute('aria-labelledby');
        if (named && document.getElementById(named)) return;
        if (modal.getAttribute('aria-label')) return;

        // The helper's own title first, then whatever the head leads with -
        // blog/categories.php heads its modal with a bare <strong>.
        const head = modal.querySelector('.ad-modal__title')
            || modal.querySelector('.ad-modal__head h1, .ad-modal__head h2, .ad-modal__head h3,'
                                 + ' .ad-modal__head h4, .ad-modal__head strong, .ad-modal__head b,'
            // settings/shipping.php heads its two modals with this instead,
            // and already names them by hand - listed so the next page that
            // copies that shape is named without anyone having to notice.
                                 + ' .ad-modal__head .ad-card__title');
        if (!head) return;                       // nothing to name it with
        if (!head.id) head.id = 'adModalTitle' + (++modalTitleSeq);
        modal.setAttribute('aria-labelledby', head.id);
    }

    function wireOpenModal(modal, opts) {
        nameModal(modal);
        const panel = modal.querySelector('.ad-modal__panel') || modal;
        const trap = modalTrap(modal);
        modal.addEventListener('keydown', trap);
        inertSiblings(modal, true);
        modalState.set(modal, {
            trap: trap,
            returnFocus: opts.returnFocus,
            onClose: opts.onClose || null
        });
        pushLayer(modal, function () { Admin.modal.close(modal); }, { lock: true });

        // [autofocus], else the first real field, else the panel. The panel is
        // tabindex="-1" so a dialog with nothing to type in still moves the
        // caret inside, which is what makes Escape and the trap meaningful.
        const auto = panel.querySelector('[autofocus]');
        const field = panel.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
        const target = auto || field || panel;
        if (target === panel && !panel.hasAttribute('tabindex')) panel.setAttribute('tabindex', '-1');
        setTimeout(function () { try { target.focus(); } catch (err) { /* detached */ } }, 20);

        emit(modal, 'sik:modal:open');
    }

    Admin.modal = {
        open: function (target, opts) {
            const modal = modalEl(target);
            if (!modal || modal.classList.contains('is-open')) return modal;
            opts = opts || {};

            // Remember whether this modal's resting state is `hidden`, so a
            // helper-built modal goes back to it on close and a legacy one
            // (four pages, no `hidden` attribute) is left exactly as it was.
            if (modal.dataset.adRest === undefined) {
                modal.dataset.adRest = modal.hasAttribute('hidden') ? 'hidden' : 'open';
            }
            modal.hidden = false;
            modal.classList.add('is-open');
            modal.removeAttribute('aria-hidden');
            // The entrance is a class flip on the NEXT frame, not
            // @starting-style: you cannot transition out of display:none, and
            // this works in every engine including the headless one the
            // screenshots are taken in.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { modal.classList.add('is-shown'); });
            });

            const opener = opts.returnFocus !== undefined
                ? opts.returnFocus
                : (document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                    ? document.activeElement : null);
            wireOpenModal(modal, { returnFocus: opener, onClose: opts.onClose });
            return modal;
        },

        close: function (target) {
            const modal = modalEl(target) || document.querySelector('.ad-modal.is-open');
            if (!modal || !modal.classList.contains('is-open')) return;
            const state = modalState.get(modal) || {};

            modal.classList.remove('is-shown');
            if (state.trap) modal.removeEventListener('keydown', state.trap);
            modalState.delete(modal);
            popLayer(modal);
            inertSiblings(modal, false);

            const panel = modal.querySelector('.ad-modal__panel') || modal;
            afterTransition(panel, 400, function () {
                if (modal.classList.contains('is-shown')) return;   // reopened meanwhile
                modal.classList.remove('is-open');
                if (modal.dataset.adRest === 'hidden') modal.hidden = true;
            });

            if (state.returnFocus && document.contains(state.returnFocus)) state.returnFocus.focus();
            if (typeof state.onClose === 'function') state.onClose();
            emit(modal, 'sik:modal:close');
        }
    };

    // The pre-A2 names, unchanged. Four pages and the roll-out waves call them.
    Admin.openModal = function (id, opts) { return Admin.modal.open(id, opts); };
    Admin.closeModal = function (target) { Admin.modal.close(target); };

    function initModals() {
        SIK.on('click', '[data-modal-open]', function (e) {
            e.preventDefault();
            const modal = document.getElementById(this.dataset.modalOpen);
            if (!modal) return;

            // Prefill BEFORE opening, so the field the caret lands on already
            // holds its value: data-field-* on the trigger -> input names.
            Object.keys(this.dataset).forEach(function (key) {
                if (key.indexOf('field') !== 0 || key === 'field') return;
                // dataset key "fieldFirstName" -> input name "first_name"
                const name = key.slice(5)
                    .replace(/^[A-Z]/, m => m.toLowerCase())
                    .replace(/[A-Z]/g, m => '_' + m.toLowerCase());
                const input = modal.querySelector('[name="' + name + '"]');
                if (!input) return;
                if (input.type === 'checkbox') input.checked = this.dataset[key] === '1';
                else input.value = this.dataset[key];
            }, this);

            Admin.modal.open(modal, { returnFocus: this });
        });

        SIK.on('click', '[data-modal-close]', function (e) {
            e.preventDefault();
            Admin.modal.close(this.closest('.ad-modal'));
        });

        SIK.on('click', '.ad-modal__backdrop', function () {
            const modal = this.closest('.ad-modal');
            if (modal && modal.dataset.modalStatic === '1') return;
            Admin.modal.close(modal);
        });

        // Escape is app.js's, through the layer stack. The listener this
        // function used to own has gone with it - a second one would have
        // closed a modal that a menu on top of it was supposed to close.
        $$('.ad-modal.is-open').forEach(adoptOpenModal);
    }

    /* ----------------------------------------------------------------------
       Confirm dialog  (A3)

       SIK.admin.confirm({...}) -> Promise<boolean>, built on the A2 modal, so
       the layer stack, the Tab trap, `inert` on the rest of the page, focus
       return, Escape and the reduced-motion rules are the ones already proved
       in A2 rather than a second set written here.

       The declarative half is [data-confirm] on a link, a button or a form.

       NOTHING MAY LOSE ITS GUARD IN THE SWAP, so the guard is three layers
       deep and each one is measurable on its own:

         1. the dialog;
         2. if the dialog cannot be BUILT or OPENED - no .ad-modal CSS, an
            exception in the builder, a body that is not there yet - the same
            handler falls back to window.confirm() synchronously and still
            blocks the action. It never lets the click through;
         3. if admin.js never runs at all, the inline onsubmit/onclick that
            admin_confirm_attrs() emits beside the data-confirm is still on
            the element, and the browser's own confirm() blocks. Layer 3 is
            disarmed the instant layer 1 is armed, so nobody ever sees two
            prompts.

       ORDER MATTERS in that last sentence: the listeners are attached first
       and the inline attributes are stripped afterwards, never the other way
       round. A throw in between would otherwise take the guard away and put
       nothing back.

       The listeners are armed at PARSE time (see the arming block below), not
       inside boot(). A delete button must stay guarded even if some later
       init() throws on a page this file has never seen.
       ---------------------------------------------------------------------- */

    const CONFIRM_TONES = ['danger', 'warning', 'default'];
    let confirmSeq = 0;

    /* Compare what was typed with what was asked for: trimmed, inner runs of
       whitespace collapsed, case folded. The operator is re-typing a name that
       is on the screen in front of them - "Acme  Ltd" against "acme ltd" is
       the same intent, and a guard that rejects it only teaches people to
       copy-paste, which defeats the point of asking. */
    function confirmNorm(value) {
        return String(value == null ? '' : value).trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function confirmDefaults(o) {
        o = o || {};
        return {
            title:        String(o.title || 'Are you sure?'),
            text:         String(o.text || ''),
            confirmLabel: String(o.confirmLabel || 'Confirm'),
            cancelLabel:  String(o.cancelLabel || 'Cancel'),
            tone:         CONFIRM_TONES.indexOf(o.tone) !== -1 ? o.tone : 'danger',
            requireText:  String(o.requireText || ''),
            returnFocus:  o.returnFocus
        };
    }

    /**
     * Build the dialog with DOM calls, never innerHTML.
     *
     * Every string here is operator-supplied - a product name, a role name, a
     * file name - and reaches this function through a data- attribute. Setting
     * it as textContent is the JS half of the e() rule: there is no markup
     * context for it to break out of.
     */
    function buildConfirm(o) {
        const id = 'adConfirm' + (++confirmSeq);

        const modal = document.createElement('div');
        modal.className = 'ad-modal ad-modal--sm ad-confirm'
            + (o.tone === 'danger' || o.tone === 'warning' ? ' ad-modal--' + o.tone : '');
        modal.id = id;
        modal.setAttribute('role', 'alertdialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', id + '-title');
        if (o.text) modal.setAttribute('aria-describedby', id + '-text');
        modal.hidden = true;

        const backdrop = document.createElement('div');
        backdrop.className = 'ad-modal__backdrop';
        backdrop.setAttribute('data-modal-close', '');
        modal.appendChild(backdrop);

        const panel = document.createElement('div');
        panel.className = 'ad-modal__panel';
        panel.setAttribute('tabindex', '-1');
        modal.appendChild(panel);

        const head = document.createElement('header');
        head.className = 'ad-modal__head';
        const headBox = document.createElement('div');
        headBox.className = 'ad-minw0';
        const h2 = document.createElement('h2');
        h2.className = 'ad-modal__title';
        h2.id = id + '-title';
        h2.textContent = o.title;
        headBox.appendChild(h2);
        head.appendChild(headBox);
        panel.appendChild(head);

        const body = document.createElement('div');
        body.className = 'ad-modal__body';
        if (o.text) {
            const p = document.createElement('p');
            p.className = 'ad-confirm__text';
            p.id = id + '-text';
            p.textContent = o.text;
            body.appendChild(p);
        }

        let input = null;
        if (o.requireText) {
            const field = document.createElement('div');
            field.className = 'ad-confirm__require';

            const label = document.createElement('label');
            label.className = 'ad-confirm__label';
            label.setAttribute('for', id + '-type');
            /* Split so the name itself is a <strong> the eye can read off,
               without ever concatenating it into markup. */
            label.appendChild(document.createTextNode('Type '));
            const strong = document.createElement('strong');
            strong.textContent = o.requireText;
            label.appendChild(strong);
            label.appendChild(document.createTextNode(' to confirm'));
            field.appendChild(label);

            input = document.createElement('input');
            input.type = 'text';
            input.className = 'sik-input ad-confirm__input';
            input.id = id + '-type';
            input.autocomplete = 'off';
            input.setAttribute('autocapitalize', 'off');
            input.setAttribute('spellcheck', 'false');
            field.appendChild(input);
            body.appendChild(field);
        }
        panel.appendChild(body);

        const foot = document.createElement('footer');
        foot.className = 'ad-modal__foot';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'ad-btn';
        cancel.setAttribute('data-confirm-cancel', '');
        cancel.textContent = o.cancelLabel;

        const go = document.createElement('button');
        go.type = 'button';
        go.className = 'ad-btn ' + (o.tone === 'warning' ? 'ad-btn--primary' : 'ad-btn--danger');
        go.setAttribute('data-confirm-go', '');
        go.textContent = o.confirmLabel;

        /* Cancel takes the caret, not the destructive button. A2's
           wireOpenModal() looks for [autofocus] FIRST and a text field second,
           so this wins - unless there is something to type, and then the field
           is the right place to land anyway. */
        if (input) input.setAttribute('autofocus', '');
        else cancel.setAttribute('autofocus', '');

        foot.appendChild(cancel);
        foot.appendChild(go);
        panel.appendChild(foot);

        return { modal: modal, go: go, cancel: cancel, input: input };
    }

    /**
     * What layers 2 and 3 look like: the browser's own dialog, carrying the
     * same words. A typed confirmation has no native equivalent, so it
     * degrades to prompt() - and still checks the answer.
     */
    function confirmNative(o) {
        const lines = [o.title, o.text].filter(Boolean).join('\n\n') || 'Are you sure?';
        if (o.requireText) {
            const typed = window.prompt(lines + '\n\nType "' + o.requireText + '" to confirm:', '');
            return typed !== null && confirmNorm(typed) === confirmNorm(o.requireText);
        }
        return window.confirm(lines);
    }

    /**
     * @param {{title?:string, text?:string, confirmLabel?:string,
     *          cancelLabel?:string, tone?:'danger'|'warning'|'default',
     *          requireText?:string, returnFocus?:Element}} opts
     * @returns {Promise<boolean>}
     */
    Admin.confirm = function (opts) {
        const o = confirmDefaults(opts);

        return new Promise(function (resolve) {
            let built = null;
            try {
                built = document.body ? buildConfirm(o) : null;
            } catch (err) {
                built = null;
            }
            if (!built || !Admin.modal || typeof Admin.modal.open !== 'function') {
                resolve(confirmNative(o));                  // layer 2
                return;
            }

            const modal = built.modal;
            document.body.appendChild(modal);

            let answer = false;
            let settled = false;
            const settle = function (value) {
                if (settled) return;
                settled = true;
                resolve(value);
            };

            built.cancel.addEventListener('click', function () {
                answer = false;
                Admin.modal.close(modal);
            });
            built.go.addEventListener('click', function () {
                if (built.go.disabled) return;
                answer = true;
                Admin.modal.close(modal);
            });

            if (built.input) {
                built.go.disabled = true;
                const sync = function () {
                    built.go.disabled = confirmNorm(built.input.value) !== confirmNorm(o.requireText);
                };
                built.input.addEventListener('input', sync);
                /* There is no form here, so Enter would otherwise do nothing
                   at all. An operator who has just typed the name expects it
                   to fire. */
                built.input.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    sync();
                    if (!built.go.disabled) built.go.click();
                });
                sync();
            }

            /* open() is wrapped because a THROW here used to be the one way
               the guard could fail closed and stay closed. new Promise()
               catches a synchronous throw in its executor and rejects, and a
               rejected confirm resolves to nothing at all - so a broken modal
               engine turned every Delete button on the page into a control
               that did nothing, for ever, with no message. It now falls to
               layer 2 like every other way the dialog can fail to open. */
            let opened = null;
            try {
                opened = Admin.modal.open(modal, {
                    returnFocus: o.returnFocus,
                    onClose: function () {
                        /* Take the node out once the exit has run. Reduced
                           motion collapses the transition and
                           afterTransition() still fires on its own timeout,
                           so the node never leaks. */
                        afterTransition(modal.querySelector('.ad-modal__panel') || modal, 400, function () {
                            if (modal.parentNode) modal.parentNode.removeChild(modal);
                        });
                        settle(answer);
                    }
                });
            } catch (err) {
                opened = null;
            }

            if (!opened || !modal.classList.contains('is-open')) {
                if (modal.parentNode) modal.parentNode.removeChild(modal);
                settle(confirmNative(o));                   // layer 2, open refused
            }
        });
    };

    /* ---------------- the declarative half: [data-confirm] ---------------- */

    const CONFIRM_FLAG = 'adConfirmed';

    function confirmOptsFrom(el) {
        const d = el.dataset;
        return {
            title:        d.confirmTitle || 'Are you sure?',
            text:         d.confirm || '',
            confirmLabel: d.confirmLabel || 'Confirm',
            cancelLabel:  d.confirmCancel || 'Cancel',
            tone:         d.confirmTone || 'danger',
            requireText:  d.confirmRequire || '',
            returnFocus:  el
        };
    }

    /**
     * Strip layer 3 from the elements this file is now guarding.
     *
     * The attribute is renamed rather than thrown away: it is the only
     * evidence that the page shipped a no-JS guard at all, and the test reads
     * it back to prove the fallback was really there before it was disarmed.
     */
    function disarmNative(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const list = Array.prototype.slice.call(scope.querySelectorAll('[data-confirm]'));
        if (scope !== document && scope.matches && scope.matches('[data-confirm]')) list.push(scope);

        list.forEach(function (el) {
            ['onsubmit', 'onclick'].forEach(function (name) {
                const value = el.getAttribute(name);
                if (!value || value.indexOf('confirm(') === -1) return;
                el.setAttribute('data-confirm-native', name);
                el.removeAttribute(name);
            });
        });
    }
    Admin.disarmNative = disarmNative;

    function isSubmitControl(el) {
        const tag = el.tagName;
        if (tag === 'BUTTON') return el.type === 'submit' || !el.hasAttribute('type');
        if (tag === 'INPUT') return el.type === 'submit' || el.type === 'image';
        return false;
    }

    /**
     * Replay the action in a NEW TASK, never in this one.
     *
     * When the answer comes from the dialog it arrives tasks later and this
     * makes no difference. When it comes from the native confirm it arrives
     * SYNCHRONOUSLY, still inside the submit event that was just cancelled -
     * and while a form is "firing submission events" the HTML spec has
     * requestSubmit() do nothing at all. The operator pressed OK and watched
     * the page sit there. setTimeout puts the replay after the dispatch
     * finishes, which is the only place it is allowed to work.
     */
    function replayLater(replay) {
        setTimeout(replay, 0);
    }

    /** Ask, then replay the original action exactly as the browser would. */
    function confirmThen(el, replay) {
        let promise;
        try {
            promise = Admin.confirm(confirmOptsFrom(el));
        } catch (err) {
            /* Layer 2 again, for a throw on the way IN to the promise. */
            if (confirmNative(confirmOptsFrom(el))) replayLater(replay);
            return;
        }
        promise.then(function (yes) {
            if (!yes) return;
            disarmNative(el);                 // belt and braces before the replay
            replayLater(replay);
        }, function () {
            /* A rejected promise must never mean "yes". Nothing happens. */
        });
    }

    function onConfirmClick(e) {
        const el = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
        if (!el) return;

        /* The replay. Clear the flag and let the event run its normal course -
           no preventDefault, no stopPropagation - so every other delegated
           handler on the page still sees it. */
        if (el.dataset[CONFIRM_FLAG] === '1') {
            delete el.dataset[CONFIRM_FLAG];
            return;
        }

        /* A form, or a control that submits one, is handled on `submit`
           instead: that path carries the submitter, keeps its name/value and
           re-runs the form's own validation. Doing it here would swallow the
           submit event that [data-bulk-form] and friends are listening for. */
        if (el.tagName === 'FORM') return;
        if (el.form && isSubmitControl(el)) return;

        /* A modifier click is guarded too. Ctrl+click on a delete link would
           otherwise perform the deletion in a new tab with nothing asked;
           losing the new tab is the cheaper half of that trade. */
        e.preventDefault();
        e.stopPropagation();

        confirmThen(el, function () {
            el.dataset[CONFIRM_FLAG] = '1';
            el.click();
        });
    }

    function onConfirmSubmit(e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        const submitter = e.submitter || null;
        const el = (submitter && submitter.closest && submitter.closest('[data-confirm]'))
            || (form.matches('[data-confirm]') ? form : null);
        if (!el) return;

        if (form.dataset[CONFIRM_FLAG] === '1') {
            delete form.dataset[CONFIRM_FLAG];
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        confirmThen(el, function () {
            form.dataset[CONFIRM_FLAG] = '1';
            /* requestSubmit() fires a real submit event, so the bulk form's id
               collection and the dirty guard still run. form.submit() does
               not, which is why it is only the fallback. */
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
            else form.submit();
        });
    }

    /**
     * Ask before an action that is NOT driven by [data-confirm] - the three
     * places inside this file that used to call window.confirm() straight.
     * Same dialog, same fallback; the caller just gets the answer late.
     */
    function confirmGate(e, form, opts) {
        if (form.dataset[CONFIRM_FLAG] === '1') {
            delete form.dataset[CONFIRM_FLAG];
            return true;                      // the replay: let it through
        }
        e.preventDefault();
        Admin.confirm(opts).then(function (yes) {
            if (!yes) return;
            form.dataset[CONFIRM_FLAG] = '1';
            /* A new task, for the same reason replayLater() exists: this
               answer can arrive synchronously from the native fallback. */
            replayLater(function () {
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        });
        return false;
    }

    /* ------------------------------ arming ------------------------------- */

    /**
     * Armed here, at parse time, and NOT from boot().
     *
     * admin.js is deferred, so the document is parsed and no click is
     * possible yet - but boot() runs a dozen init()s, and a throw in any one
     * of them would leave every delete button on the page unguarded if the
     * guard were the last thing in the list. Capture phase, so the decision
     * is taken before any delegated handler acts on the same click.
     */
    document.addEventListener('click', onConfirmClick, true);
    document.addEventListener('submit', onConfirmSubmit, true);
    disarmNative(document);                   // layer 3 off, now that 1 is on

    /* ----------------------------------------------------------------------
       Remote quick view

       data-modal-url / data-drawer-url on a REAL link: the href stays the
       working no-JS path, and the fragment is the same page fetched with
       ?partial=1 (admin_partial_request() in admin/includes/functions.php).

       Rules that matter more than the mechanism:
         - the shell opens at once, so the click is acknowledged;
         - bones come from the shared skeleton kit, never a fork;
         - nothing ends on a shimmer. It becomes content, or a Retry block.
       ---------------------------------------------------------------------- */
    function remoteShell(as) {
        const id = as === 'drawer' ? 'adRemoteDrawer' : 'adRemoteModal';
        let el = document.getElementById(id);
        if (el) return el;

        el = document.createElement(as === 'drawer' ? 'aside' : 'div');
        el.id = id;
        if (as === 'drawer') {
            el.className = 'ad-drawer ad-drawer--sheet';
            el.setAttribute('role', 'dialog');
            el.setAttribute('aria-labelledby', id + '-title');
            el.setAttribute('aria-hidden', 'true');
            el.innerHTML =
                '<header class="ad-drawer__head"><div class="ad-minw0">'
                + '<h2 class="ad-drawer__title" id="' + id + '-title"></h2></div>'
                + '<button type="button" class="ad-iconbtn" data-close-drawer="' + id + '" aria-label="Close">'
                + '&#215;</button></header>'
                + '<div class="ad-drawer__body ad-remote"></div>';
        } else {
            el.className = 'ad-modal ad-modal--lg';
            el.setAttribute('role', 'dialog');
            el.setAttribute('aria-modal', 'true');
            el.setAttribute('aria-labelledby', id + '-title');
            el.hidden = true;
            el.innerHTML =
                '<div class="ad-modal__backdrop" data-modal-close></div>'
                + '<div class="ad-modal__panel" tabindex="-1">'
                + '<header class="ad-modal__head"><div class="ad-minw0">'
                + '<h2 class="ad-modal__title" id="' + id + '-title"></h2></div>'
                + '<button type="button" class="ad-iconbtn" data-modal-close aria-label="Close">'
                + '&#215;</button></header>'
                + '<div class="ad-modal__body ad-remote"></div></div>';
        }
        document.body.appendChild(el);
        return el;
    }

    /** Bones built from the shared kit's classes (app.css 30b). */
    function remoteBones(into) {
        into.textContent = '';
        const wrap = document.createElement('div');
        wrap.className = 'ad-remote__bones';
        wrap.setAttribute('aria-hidden', 'true');
        [88, 100, 74, 94, 60].forEach(function (pct) {
            const line = document.createElement('div');
            line.className = 'sik-skel sik-skel__line';
            line.style.width = pct + '%';
            wrap.appendChild(line);
        });
        into.appendChild(wrap);
        into.setAttribute('aria-busy', 'true');
    }

    function remoteError(into, retry) {
        into.textContent = '';
        into.removeAttribute('aria-busy');
        const block = document.createElement('div');
        block.className = 'ad-remote__error';
        block.setAttribute('role', 'alert');

        const t = document.createElement('p');
        t.className = 'ad-remote__error-title';
        t.textContent = "Couldn't load this";
        const p = document.createElement('p');
        p.className = 'ad-remote__error-text';
        p.textContent = 'The panel did not arrive. Check the connection and try again, or open the full page.';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ad-btn ad-btn--primary';
        btn.textContent = 'Retry';
        btn.addEventListener('click', retry);

        block.appendChild(t);
        block.appendChild(p);
        block.appendChild(btn);
        into.appendChild(block);
        // Announce the failure and give the caret somewhere to be, or focus
        // is left inside a region that no longer has anything in it.
        setTimeout(function () { try { btn.focus(); } catch (err) {} }, 20);
    }

    Admin.remote = function (url, o) {
        o = o || {};
        const as = o.as === 'drawer' ? 'drawer' : 'modal';
        const shell = remoteShell(as);
        const body = shell.querySelector('.ad-remote');
        const titleEl = shell.querySelector('.ad-modal__title, .ad-drawer__title');

        if (o.size && as === 'modal') {
            shell.className = 'ad-modal ad-modal--' + (['sm', 'md', 'lg', 'xl'].indexOf(o.size) >= 0 ? o.size : 'lg');
        }
        titleEl.textContent = o.title || 'Loading…';
        remoteBones(body);

        if (as === 'drawer') {
            // app.js owns drawers: focus-in, the Tab trap, the scrim and the
            // layer handle all come from there (spec 4.7, do not fork it).
            SIK.openDrawer(shell.id);
        } else {
            Admin.modal.open(shell, { returnFocus: o.returnFocus });
        }

        const sep = url.indexOf('?') === -1 ? '?' : '&';
        const load = function () {
            remoteBones(body);
            fetch(url + sep + 'partial=1', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (res) {
                const type = res.headers.get('content-type') || '';
                // A redirect to the sign-in page answers 200 with a full
                // document. Treat anything that is not our fragment as a
                // failure rather than injecting a login form into a dialog.
                if (!res.ok || type.indexOf('text/html') === -1) throw new Error('HTTP ' + res.status);
                return res.text();
            }).then(function (html) {
                if (html.indexOf('<!doctype') === 0 || html.indexOf('<!DOCTYPE') === 0) {
                    throw new Error('not a partial');
                }
                body.removeAttribute('aria-busy');
                // Server-rendered admin markup from our own origin, fetched
                // same-origin with credentials: this is the documented quick
                // view mechanism, and the only innerHTML in this file.
                body.innerHTML = html;
                const partial = body.querySelector('.ad-partial');
                if (partial && partial.dataset.title) titleEl.textContent = partial.dataset.title;
                else if (!o.title) titleEl.textContent = '';
                Admin.refresh(body);
                emit(shell, 'sik:remote:load', { url: url });
            }).catch(function () {
                titleEl.textContent = o.title || 'Quick view';
                remoteError(body, load);
            });
        };
        load();
        return shell;
    };

    function initRemote() {
        SIK.on('click', '[data-modal-url], [data-drawer-url]', function (e) {
            // A modified click is a deliberate "open this properly": let the
            // href do its job. That is why the mechanism needs a real link.
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
            e.preventDefault();
            const url = this.dataset.modalUrl || this.dataset.drawerUrl;
            Admin.remote(url, {
                as: this.dataset.drawerUrl ? 'drawer' : 'modal',
                size: this.dataset.modalSize,
                title: this.dataset.modalTitle || this.getAttribute('aria-label') || '',
                returnFocus: this
            });
        });
    }

    /**
     * Re-run the scope-aware initialisers over freshly injected HTML.
     *
     * Everything else in this file is delegated from the document, so it
     * already covers new nodes; these four measure or bind per element and
     * have to be told. initResponsiveTables() in particular is the whole
     * reason a quick view can contain a table at all - the card view is
     * derived at runtime and never hand-written.
     */
    Admin.refresh = function (root) {
        const scope = root || document;
        try { initResponsiveTables(scope); } catch (err) { /* keep going */ }
        try { initCharts(scope); } catch (err) {}
        try { initTruncationTitles(scope); } catch (err) {}
        /* Injected markup arrives with its no-JS guard still attached. The
           delegated listeners already cover it; this takes the inline
           fallback off so the quick view does not prompt twice. */
        try { disarmNative(scope); } catch (err) {}
        document.dispatchEvent(new CustomEvent('sik:admin:refresh', { detail: { root: scope } }));
    };

    /* ----------------------------------------------------------------------
       Table selection + bulk actions
       ---------------------------------------------------------------------- */
    function initBulk() {
        const master = $('[data-check-all]');
        const bar = $('[data-bulk-bar]');

        function sync() {
            const boxes = $$('[data-check-row]');
            const checked = boxes.filter(b => b.checked);

            if (bar) {
                bar.classList.toggle('is-open', checked.length > 0);
                const count = $('[data-bulk-count]', bar);
                if (count) count.textContent = String(checked.length);
            }
            if (master) {
                master.checked = boxes.length > 0 && checked.length === boxes.length;
                master.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }
        }

        if (master) {
            master.addEventListener('change', function () {
                $$('[data-check-row]').forEach(b => { b.checked = master.checked; });
                sync();
            });
        }

        SIK.on('change', '[data-check-row]', sync);

        // Bulk form submission collects the selected ids.
        SIK.on('submit', '[data-bulk-form]', function (e) {
            const checked = $$('[data-check-row]').filter(b => b.checked);
            if (!checked.length) {
                e.preventDefault();
                SIK.toast('Select at least one row first.', 'warning');
                return;
            }

            const action = this.querySelector('[name="bulk_action"]');
            if (action && !action.value) {
                e.preventDefault();
                SIK.toast('Choose an action to apply.', 'warning');
                return;
            }
            /* The count is only known at click time, so this one cannot be a
               static data-confirm. It goes through the same dialog and the
               same fallback; confirmGate() replays the submit with
               requestSubmit(), so the id collection below still runs. */
            if (action && action.value === 'delete'
                && !confirmGate(e, this, {
                    title: 'Delete ' + checked.length + ' item' + (checked.length === 1 ? '' : 's') + '?',
                    text: 'This cannot be undone.',
                    confirmLabel: 'Delete',
                    tone: 'danger'
                })) {
                return;
            }

            // Replace any stale hidden ids, then add the current selection.
            $$('input[name="ids[]"]', this).forEach(i => i.remove());
            checked.forEach(function (box) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'ids[]';
                hidden.value = box.value;
                this.appendChild(hidden);
            }, this);
        });

        sync();
    }

    /* ----------------------------------------------------------------------
       Slug auto-fill
       ---------------------------------------------------------------------- */
    function slugify(value) {
        return String(value).toLowerCase().trim()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function initSlug() {
        $$('[data-slug-source]').forEach(function (source) {
            const target = document.querySelector(source.dataset.slugSource);
            if (!target) return;

            // Once an admin edits the slug by hand, stop overwriting it.
            let locked = target.value.trim() !== '';
            target.addEventListener('input', () => { locked = true; });

            source.addEventListener('input', function () {
                if (!locked) target.value = slugify(source.value);
            });
        });

        SIK.on('blur', '[data-slugify]', function () {
            this.value = slugify(this.value);
        });
    }

    /* ----------------------------------------------------------------------
       Image upload previews
       ---------------------------------------------------------------------- */
    function initUploads() {
        $$('[data-drop]').forEach(function (drop) {
            const input = drop.querySelector('input[type="file"]');
            const preview = document.querySelector(drop.dataset.drop);
            if (!input) return;

            drop.addEventListener('click', () => input.click());
            drop.addEventListener('dragover', function (e) {
                e.preventDefault();
                drop.classList.add('is-drag');
            });
            drop.addEventListener('dragleave', () => drop.classList.remove('is-drag'));
            drop.addEventListener('drop', function (e) {
                e.preventDefault();
                drop.classList.remove('is-drag');
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            });

            input.addEventListener('change', function () {
                if (!preview) return;
                preview.innerHTML = '';

                Array.prototype.forEach.call(input.files, function (file) {
                    if (!file.type.startsWith('image/')) return;

                    const item = document.createElement('div');
                    item.className = 'ad-preview__item';
                    const img = document.createElement('img');
                    img.alt = file.name;

                    const reader = new FileReader();
                    reader.onload = e => { img.src = e.target.result; };
                    reader.readAsDataURL(file);

                    item.appendChild(img);
                    preview.appendChild(item);
                });
            });
        });

        // Remove an already-saved image.
        SIK.on('click', '[data-remove-image]', function (e) {
            e.preventDefault();

            /* Nothing is destroyed until the form is saved, so this is a
               warning rather than a danger: the tone says "you can still back
               out by not saving". */
            const trigger = this;
            Admin.confirm({
                title: 'Remove this image?',
                text: 'It is taken off the record when you save the form.',
                confirmLabel: 'Remove',
                tone: 'warning',
                returnFocus: trigger
            }).then(function (yes) {
                if (!yes) return;
                const item = trigger.closest('.ad-preview__item');
                const field = document.querySelector(trigger.dataset.removeImage);
                if (field) field.value = '1';   // hidden "remove_image" flag
                if (item) item.remove();
            });
        });
    }

    /* ----------------------------------------------------------------------
       Repeaters (specifications, features, variants, menu items)
       ---------------------------------------------------------------------- */
    function initRepeaters() {
        SIK.on('click', '[data-repeat-add]', function (e) {
            e.preventDefault();
            const wrap = document.querySelector(this.dataset.repeatAdd);
            const template = wrap ? wrap.querySelector('[data-repeat-template]') : null;
            if (!wrap || !template) return;

            const index = wrap.querySelectorAll('.ad-repeater__row:not([data-repeat-template])').length;
            const html = template.innerHTML.replace(/__INDEX__/g, String(index));

            const row = document.createElement('div');
            row.className = 'ad-repeater__row';
            row.innerHTML = html;
            wrap.insertBefore(row, template);

            const first = row.querySelector('input, select, textarea');
            if (first) first.focus();
        });

        SIK.on('click', '[data-repeat-remove]', function (e) {
            e.preventDefault();
            const row = this.closest('.ad-repeater__row');
            if (row) row.remove();
        });
    }

    /* ----------------------------------------------------------------------
       Inline status toggles
       ---------------------------------------------------------------------- */
    function initInlineToggles() {
        SIK.on('change', '[data-toggle-endpoint]', async function () {
            const input = this;
            const previous = !input.checked;

            const result = await SIK.post(input.dataset.toggleEndpoint, {
                id: parseInt(input.dataset.id, 10),
                field: input.dataset.field,
                value: input.checked ? 1 : 0
            });

            if (result.success) {
                SIK.toast(result.message || 'Updated', 'success');
            } else {
                input.checked = previous;   // roll the switch back
                SIK.toast(result.message || 'Could not update.', 'error');
            }
        });
    }

    /* ----------------------------------------------------------------------
       Filters
       ---------------------------------------------------------------------- */
    function initFilters() {
        // Changing a filter select submits immediately.
        SIK.on('change', '[data-auto-submit]', function () {
            const form = this.closest('form');
            if (form) form.submit();
        });

        // Debounced search-as-you-type on list screens - with a physical
        // keyboard only. The submit reloads the page, and on a phone a reload
        // dismisses the on-screen keyboard: a thumb typist pauses longer than
        // 550ms mid-word all the time, so the list refreshed on "lan" and threw
        // the keyboard away before "lantern" was finished. On a coarse pointer
        // the keyboard's own Search key (implicit submission - every one of
        // these forms either has a Filter button or a single text field) and
        // the Filter button are the submit. The query is read per keystroke,
        // not once, so a tablet that gains or loses its keyboard follows along.
        const coarse = window.matchMedia('(pointer: coarse)');
        $$('[data-filter-search]').forEach(function (input) {
            const form = input.closest('form');
            if (!form) return;
            // Labels the phone keyboard's action key "Search" instead of "Go".
            if (!input.hasAttribute('enterkeyhint')) input.setAttribute('enterkeyhint', 'search');
            input.addEventListener('input', SIK.debounce(function () {
                if (!coarse.matches) form.submit();
            }, 550));
        });

        // Custom date range reveal.
        SIK.on('change', '[data-range-preset]', function () {
            const custom = document.querySelector('[data-range-custom]');
            if (custom) custom.hidden = this.value !== 'custom';
            if (this.value !== 'custom') {
                const form = this.closest('form');
                if (form) form.submit();
            }
        });
    }

    /* ----------------------------------------------------------------------
       Unsaved-changes guard
       ---------------------------------------------------------------------- */
    function initDirtyGuard() {
        $$('form[data-guard-unsaved]').forEach(function (form) {
            let dirty = false;
            let saving = false;

            form.addEventListener('input', () => { dirty = true; });
            form.addEventListener('change', () => { dirty = true; });
            form.addEventListener('submit', () => { saving = true; });

            window.addEventListener('beforeunload', function (e) {
                if (dirty && !saving) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       Product picker (used by widgets, deals, flash sales, mega menus)
       ---------------------------------------------------------------------- */
    function initProductPicker() {
        SIK.on('input', '[data-product-search]', SIK.debounce(async function () {
            const input = this;
            const results = document.querySelector(input.dataset.productSearch);
            if (!results) return;

            const term = input.value.trim();
            if (term.length < 2) { results.innerHTML = ''; return; }

            const result = await SIK.get('products/search.php', { q: term, limit: 8 });
            if (!result.success) return;

            results.innerHTML = (result.data.products || []).map(function (p) {
                return '<button type="button" class="ad-dropdown__item" data-pick-product="' + p.id + '"'
                    + ' data-name="' + SIK.escapeHtml(p.name) + '" data-image="' + SIK.escapeHtml(p.image_url) + '">'
                    + '<img src="' + SIK.escapeHtml(p.image_url) + '" alt="" width="28" height="28" style="border-radius:5px">'
                    + '<span style="flex:1;min-width:0"><span style="display:block;font-weight:600">' + SIK.escapeHtml(p.name) + '</span>'
                    + '<span style="font-size:11.5px;color:var(--ad-muted)">' + SIK.escapeHtml(p.sku || '') + ' · ' + SIK.escapeHtml(p.price_display) + '</span></span>'
                    + '</button>';
            }).join('') || '<div class="ad-dropdown__item ad-muted">No products found</div>';
        }, 300));

        SIK.on('click', '[data-pick-product]', function (e) {
            e.preventDefault();
            const list = document.querySelector('[data-picked-products]');
            if (!list) return;

            const id = this.dataset.pickProduct;
            if (list.querySelector('[data-picked="' + id + '"]')) {
                SIK.toast('That product is already selected.', 'info');
                return;
            }

            const row = document.createElement('div');
            row.className = 'ad-cellflex';
            row.style.cssText = 'padding:8px;border:1px solid var(--ad-border);border-radius:8px;margin-bottom:6px';
            row.dataset.picked = id;
            row.innerHTML = '<img class="ad-thumb" src="' + SIK.escapeHtml(this.dataset.image) + '" alt="">'
                + '<span class="ad-cellflex__name" style="flex:1">' + SIK.escapeHtml(this.dataset.name) + '</span>'
                + '<input type="hidden" name="product_ids[]" value="' + SIK.escapeHtml(id) + '">'
                + '<button type="button" class="ad-btn ad-btn--icon ad-btn--danger-ghost" data-unpick>&times;</button>';
            list.appendChild(row);

            const results = document.querySelector('[data-product-results]');
            if (results) results.innerHTML = '';
            const search = document.querySelector('[data-product-search]');
            if (search) search.value = '';
        });

        SIK.on('click', '[data-unpick]', function (e) {
            e.preventDefault();
            const row = this.closest('[data-picked]');
            if (row) row.remove();
        });
    }

    /* ----------------------------------------------------------------------
       Order status quick-change
       ---------------------------------------------------------------------- */
    function initOrderStatus() {
        SIK.on('change', '[data-order-status]', function () {
            const select = this;
            const label = select.options[select.selectedIndex].text;

            /* The select has ALREADY moved by the time `change` fires, so the
               cancel path has to put it back. That is why this is not a
               data-confirm: the guard has an undo to perform, not just a
               submit to stop. */
            Admin.confirm({
                title: 'Change this order?',
                text: 'The status becomes "' + label + '". The customer is notified.',
                confirmLabel: 'Change status',
                tone: 'warning',
                returnFocus: select
            }).then(function (yes) {
                if (!yes) {
                    select.value = select.dataset.current;
                    return;
                }
                const form = select.closest('form');
                if (form) form.submit();
            });
        });
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */
    /* ----------------------------------------------------------------------
       Responsive tables
       ----------------------------------------------------------------------
       admin.css ships a mobile card view for data tables — .ad-table--stack
       plus data-label="…" on each <td>, which below 768px turns every row into
       a labelled card. It was written as opt-in and then never opted into:
       across 55 admin files, 0 of 91 tables carried the class and not a single
       data-label existed anywhere. Every table therefore fell back to sideways
       scrolling inside .ad-tablewrap on a phone — a 1180px table dragged
       through a 331px window.

       Doing it here rather than editing 91 tables by hand: the labels are
       already in each table's own <thead>, so there is nothing for a human to
       type, nothing to get out of sync when a column is renamed, and no risk of
       mangling markup in 55 files. It also picks up tables rendered later by
       AJAX, which a static edit would miss.

       Cells whose header is empty (checkbox and actions columns) are left
       without a label deliberately — the CSS already gives those two their own
       treatment, and a card row reading "Actions:" adds nothing. */
    function initResponsiveTables(scope) {
        const root = scope || document;

        $$('.ad-table', root).forEach(function (table) {
            const head = table.tHead;
            if (!head || !head.rows.length) return;   // layout table, not a data table

            const headers = [];
            [...head.rows[head.rows.length - 1].cells].forEach(function (th) {
                // A header that holds a control rather than a name is not a
                // label for anything: the select-all column's <th> contains the
                // master checkbox and its screen-reader text, so every row card
                // on Orders was captioning its own checkbox "SELECT ALL
                // ORDERS". The CSS already gives that column its own treatment.
                const label = th.querySelector('input, button, select')
                    ? ''
                    : th.textContent.replace(/\s+/g, ' ').trim();
                // MEASURED (A2): cells were matched to headers by index, which
                // a colspan silently breaks. orders/view.php has
                // `<th colspan="2">Product</th>` over a thumbnail cell and a
                // name cell, so every label after it was off by one and the
                // card view read "Unit price: SIK-FLT-1001", "Qty: Rs 1,999".
                // One entry per COLUMN, not per <th>. colSpan is 1 by default,
                // so every other table in the admin is unchanged.
                for (let s = 0; s < Math.max(1, th.colSpan); s++) headers.push(label);
            });

            [...table.tBodies].forEach(function (body) {
                [...body.rows].forEach(function (row) {
                    // A colspan row is a message ("No results"), not a record —
                    // labelling it would caption the empty state.
                    if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;

                    [...row.cells].forEach(function (cell, i) {
                        if (cell.hasAttribute('data-label')) return;
                        const label = headers[i];
                        if (label) cell.setAttribute('data-label', label);
                    });
                });
            });

            table.classList.add('ad-table--stack');
            addStackControls(table);
            watchFit(table);
        });
        fitAll();
    }

    /* ----------------------------------------------------------------------
       Does this table fit? - the measurement the card view is gated on
       ----------------------------------------------------------------------
       The card view used to be a media query: stack below 768px, scroll
       sideways above it. Both halves were wrong, because "does this table fit"
       is a question about the table and the box it was given, not about the
       window. A three-column Key/Value table in a sidebar card fits a 320px
       phone and was being stacked; a twelve-column products list does not fit
       the ~700px a 1024px laptop leaves beside the sidebar and was being left
       to scroll - 35 list tables did exactly that at 768-1440px, and
       products/index was cut off after the SOLD column with Rating, Status,
       Added and Actions off-screen and unreachable.

       So the table is measured instead, twice:

         min     - its intrinsic minimum. A table cannot lay out narrower than
                   its min-content width, so asking it for 1px returns that.
                   Every column is then exactly as wide as its longest word.
         natural - what it would take if nothing wrapped at all.

       and it keeps its table shape while the space it has lets it stand at
       least a fifth of the way from the first to the second. At the minimum
       itself every column is exactly its longest word, a product name wraps to
       four lines and the "table" has stopped being readable as one - that is
       where the card view becomes the better rendering, not a fallback.

       The fraction is the only judgement in here, and a fifth is deliberately
       mean: a table gets the benefit of the doubt and only gives up its shape
       when it is genuinely out of room. Measured against the admin's own list
       screens, it is also what keeps two tables SIDE BY SIDE agreeing with
       each other - reports/sales' payment and shipping breakdowns sit in one
       row at 552px each with slack of 0.74 and 0.29, and at a third one would
       have stayed a table while its twin stacked.
       ---------------------------------------------------------------------- */
    const FIT_EASE   = 0.20;   // of the way from min to natural, at least
    const WIDE_CARD  = 480;    // a card this wide holds fields side by side
    const fitted     = [];     // {table, wrap, avail}
    let   fitObserver = null;

    function watchFit(table) {
        const wrap = table.parentNode;
        if (!wrap || !wrap.nodeType || wrap.nodeType !== 1) return;
        if (fitted.some(function (e) { return e.table === table; })) return;
        const entry = { table: table, wrap: wrap, avail: -1 };
        fitted.push(entry);

        if (typeof ResizeObserver === 'function') {
            if (!fitObserver) {
                fitObserver = new ResizeObserver(function (entries) {
                    entries.forEach(function (ro) {
                        fitted.forEach(function (e) { if (e.wrap === ro.target) fit(e); });
                    });
                });
            }
            fitObserver.observe(wrap);
        }
    }

    /** Width the table cannot go below, in the shape it is asked for. */
    function intrinsic(table, width) {
        const prev = table.style.width;
        table.style.width = width;
        const w = table.getBoundingClientRect().width;
        table.style.width = prev;
        return w;
    }

    function fit(entry) {
        const table = entry.table;
        const wrap  = entry.wrap;
        const avail = wrap.clientWidth;

        // A table in a closed tab or an unopened drawer has no width to judge.
        // The observer calls back when it is shown.
        if (!avail) return;
        // The observer also fires on every height change - and stacking changes
        // the height, so without this the first stack would call itself back
        // forever. Only a change of WIDTH can change the answer.
        if (avail === entry.avail) return;
        entry.avail = avail;

        // Measure unstacked: a stacked table is display:block and has no
        // column widths left to report. Nothing repaints between the two
        // classList calls, so there is nothing to see.
        const was = table.classList.contains('is-stacked');
        if (was) table.classList.remove('is-stacked');

        /* --- the actions column is measured as it will be DRAWN -------------
           Dropping `nowrap` from .ad-table__actions is what stopped a four
           button cell setting a 160px floor under every list - it was the
           widest single cause of the sideways scroll this sweep removed. But
           a column squeezed to one button's width then breaks the buttons
           onto a line each, and that is what a reader notices first: three
           icons stacked one per line on customers at 1280px, two on orders, a
           phone number broken mid-number in the column beside them.

           Both ends are fixed by measuring the table in the shape it will
           actually be drawn in. `is-actrow` (admin.css) puts the row back on
           one line, and it goes on BEFORE the minimum is taken, so the
           minimum includes it. A table that clears the test can therefore
           always draw its buttons in a row; one that cannot has run out of
           room in a way a card fixes and a squeezed table does not. One
           decision instead of two - and no 1px boundary between a crushed
           table and a card, which is what measuring it separately produced. */
        const hasActions = !!table.querySelector('.ad-table__actions');
        table.classList.toggle('is-actrow', hasActions);

        const min     = intrinsic(table, '1px');
        const natural = intrinsic(table, 'max-content');
        const needs   = min + Math.max(0, natural - min) * FIT_EASE;
        const stacked = avail < Math.min(needs, natural);

        // A card lays its actions out as a row of its own already.
        if (stacked) table.classList.remove('is-actrow');

        table.classList.toggle('is-stacked', stacked);
        wrap.classList.toggle('is-stacked', stacked);
        if (stacked) {
            table.dataset.stack = avail >= WIDE_CARD ? 'wide' : 'narrow';
        } else {
            delete table.dataset.stack;
        }

        // The sort control and the select-all box only exist while the <thead>
        // that holds the real ones is hidden. Toggled here rather than by a CSS
        // descendant rule because the bar's parent is whatever box the table
        // was in, which is not always .ad-tablewrap.
        const bar = table.previousElementSibling;
        if (bar && bar.classList.contains('ad-stackbar')) bar.classList.toggle('is-on', stacked);
    }

    function fitAll() {
        fitted.forEach(function (e) { e.avail = -1; fit(e); });
    }

    /* Re-measure once the web font has replaced the fallback: every column
       width in the first measurement was taken in the wrong typeface. */
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(fitAll).catch(function () {});
    }
    /* ResizeObserver covers a box that changes width inside a still window
       (the designer's panes, an opening drawer). This covers the window
       itself for anything that predates it. */
    window.addEventListener('resize', SIK.debounce(function () {
        fitted.forEach(fit);
        if (Admin.initTruncationTitles) Admin.initTruncationTitles();
    }, 120));

    /**
     * Surface the two controls the card view hides with the <thead>.
     *
     * Driving the real inputs rather than reimplementing them keeps the
     * existing bulk-action logic the single source of truth.
     */
    function addStackControls(table) {
        const wrap = table.parentNode;
        if (!wrap) return;
        const head = table.tHead;
        if (!head) return;
        // Idempotent: initResponsiveTables() may be called again after AJAX.
        if (table.previousElementSibling
            && table.previousElementSibling.classList.contains('ad-stackbar')) return;

        const sorts  = [].slice.call(head.querySelectorAll('a[href*="sort="]'));
        const master = head.querySelector('[data-check-all]');
        if (!sorts.length && !master) return;

        const bar = document.createElement('div');
        bar.className = 'ad-stackbar';

        if (master) {
            const label = document.createElement('label');
            label.className = 'ad-stackbar__all';
            const proxy = document.createElement('input');
            proxy.type = 'checkbox';
            proxy.setAttribute('aria-label', 'Select all rows on this page');
            proxy.addEventListener('change', function () {
                master.checked = proxy.checked;
                master.dispatchEvent(new Event('change', { bubbles: true }));
            });
            master.addEventListener('change', function () { proxy.checked = master.checked; });
            label.appendChild(proxy);
            label.appendChild(document.createTextNode(' Select all'));
            bar.appendChild(label);
        }

        if (sorts.length) {
            const select = document.createElement('select');
            select.className = 'sik-select ad-stackbar__sort';
            select.setAttribute('aria-label', 'Sort this list');
            const first = document.createElement('option');
            first.value = '';
            first.textContent = 'Sort by\u2026';
            select.appendChild(first);
            sorts.forEach(function (a) {
                const o = document.createElement('option');
                o.value = a.getAttribute('href');
                o.textContent = a.textContent.replace(/\s+/g, ' ').trim();
                select.appendChild(o);
            });
            select.addEventListener('change', function () {
                if (select.value) { window.location.href = select.value; }
            });
            bar.appendChild(select);
        }

        wrap.insertBefore(bar, table);
    }
    Admin.initResponsiveTables = initResponsiveTables;
    Admin.refitTables = fitAll;

    /* ----------------------------------------------------------------------
       Bar charts: every figure reachable by tap, keyboard and screen reader
       ----------------------------------------------------------------------
       admin_bar_chart() prints a value and a date on each bar, and below
       768px the CSS hides both - at 9px a column cannot hold them legibly. The
       only readout left was a title= tooltip, which needs a hovering mouse, so
       on a phone not one figure in the chart could be read.

       So the chart becomes a row of data points, the way charting libraries
       expose them to assistive tech: each column is a named image (role=img,
       "14 Sep: ₹12,340"), and the chart is one Tab stop whose arrow keys walk
       the points (a roving tabindex, so thirty bars are not thirty Tab
       presses). Tapping, or dragging a finger along the bars, picks the
       column under it by x alone - a zero-value bar is 3px tall, and nobody
       can hit that - and writes the figure into a readout line under the
       chart. The readout is shown only where the printed labels are hidden;
       above 768px the chart looks exactly as it did.

       Done here rather than in admin_bar_chart() because the markup already
       carries everything needed: the label and the formatted value are both
       in each column. */
    function initCharts(scope) {
        $$('.ad-chart', scope || document).forEach(function (chart) {
            if (chart.dataset.chartReady === '1') return;
            const cols = [].slice.call(chart.querySelectorAll('.ad-chart__col'));
            if (!cols.length) return;
            chart.dataset.chartReady = '1';

            cols.forEach(function (col) {
                const label = col.querySelector('.ad-chart__label');
                const value = col.querySelector('.ad-chart__value');
                const name = label && value
                    ? label.textContent.trim() + ': ' + value.textContent.trim()
                    : (col.getAttribute('title') || '');
                col.setAttribute('role', 'img');
                col.setAttribute('aria-label', name);
                col.dataset.readout = name;
                // The tooltip said the same thing as the new name; left in
                // place it is read out a second time as the description.
                col.removeAttribute('title');
                col.tabIndex = -1;
            });

            // The latest point is the one most often wanted, so it is the
            // first stop when the keyboard arrives.
            cols[cols.length - 1].tabIndex = 0;

            const card = chart.closest('.ad-card');
            const title = card ? card.querySelector('.ad-card__title') : null;
            chart.setAttribute('role', 'group');
            chart.setAttribute('aria-label', (title ? title.textContent.trim() + '. ' : '')
                + cols.length + ' bars; use the arrow keys to move between them.');

            // Hidden from assistive tech: the focused column already says the
            // same thing, and saying it twice is noise.
            const readout = document.createElement('p');
            readout.className = 'ad-chart__readout';
            readout.setAttribute('aria-hidden', 'true');
            readout.textContent = window.matchMedia('(hover: hover)').matches
                ? 'Point at a bar to read its figure.'
                : 'Tap a bar to read its figure.';
            chart.parentNode.insertBefore(readout, chart.nextSibling);
            watchDensity(chart, cols.length);

            let active = null;
            function select(col) {
                if (!col || col === active) return;
                if (active) active.classList.remove('is-active');
                active = col;
                col.classList.add('is-active');
                chart.classList.add('has-active');
                cols.forEach(function (c) { c.tabIndex = c === col ? 0 : -1; });

                const text = col.dataset.readout || '';
                const split = text.lastIndexOf(': ');
                readout.textContent = '';
                if (split > -1) {
                    const figure = document.createElement('strong');
                    figure.textContent = text.slice(split + 2);
                    readout.appendChild(figure);
                    readout.appendChild(document.createTextNode(' · ' + text.slice(0, split)));
                } else {
                    readout.textContent = text;
                }
            }

            /** The column whose horizontal span is nearest x. */
            function columnAt(x) {
                let best = null;
                let bestDistance = Infinity;
                cols.forEach(function (col) {
                    const box = col.getBoundingClientRect();
                    const distance = x < box.left ? box.left - x : (x > box.right ? x - box.right : 0);
                    if (distance < bestDistance) { best = col; bestDistance = distance; }
                });
                return best;
            }

            let scrubbing = null;
            chart.addEventListener('pointerdown', function (e) {
                if (e.button > 0) return;
                scrubbing = e.pointerId;
                select(columnAt(e.clientX));
            });
            chart.addEventListener('pointermove', function (e) {
                // A finger scrubs while it is down; a mouse reads on hover,
                // which is what the removed tooltip used to do.
                if (e.pointerId === scrubbing || e.pointerType === 'mouse') select(columnAt(e.clientX));
            });
            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
                chart.addEventListener(type, function (e) {
                    if (e.pointerId === scrubbing) scrubbing = null;
                });
            });

            chart.addEventListener('focusin', function (e) {
                const col = e.target.closest('.ad-chart__col');
                if (col) select(col);
            });

            chart.addEventListener('keydown', function (e) {
                const i = cols.indexOf(active || document.activeElement);
                let next = -1;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = Math.min(cols.length - 1, i + 1);
                else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = Math.max(0, i - 1);
                else if (e.key === 'Home') next = 0;
                else if (e.key === 'End') next = cols.length - 1;
                if (next < 0) return;
                e.preventDefault();
                cols[next].focus();
            });
        });
    }

    /* ----------------------------------------------------------------------
       How much a chart can print, measured rather than assumed
       ----------------------------------------------------------------------
       The chart used to demand 26px per column and scroll sideways inside its
       card when it could not have it - 667px of scroll on reports/customers at
       768px, still 526px at 1280px - while printing a date and a figure under
       every bar. Above 768px those were printed whatever the width, so on a
       30-point chart in a half-width card they simply overlapped each other.

       The columns now divide whatever width there is (admin.css), so the only
       question left is what can still be READ at that size. A date label needs
       ~44px of column and a figure ~34px; below each of those the text is
       hidden and the readout line under the chart carries the figures instead,
       one at a time, on hover, tap, focus or arrow key. Nothing becomes
       unreachable - it changes how it is reached.
       ---------------------------------------------------------------------- */
    const charts = [];
    let chartObserver = null;

    /** The widest thing in a set of columns, plus a breath of air. */
    function widest(chart, selector) {
        let max = 0;
        chart.querySelectorAll(selector).forEach(function (el) {
            const w = el.getBoundingClientRect().width;
            if (w > max) max = w;
        });
        return max ? max + 6 : 0;
    }

    function watchDensity(chart, count) {
        // Measured now, while both are still on screen: a date label is ~33px
        // but a rupee figure can be 60px or 12px depending on the report, and
        // guessing a single number for both is how the dashboard chart came to
        // overflow its card by 8px at 1440px with its values still printed.
        charts.push({
            chart: chart,
            count: count,
            width: -1,
            labelRoom: widest(chart, '.ad-chart__label'),
            valueRoom: widest(chart, '.ad-chart__value')
        });
        if (typeof ResizeObserver === 'function') {
            if (!chartObserver) {
                chartObserver = new ResizeObserver(function (entries) {
                    entries.forEach(function (ro) {
                        charts.forEach(function (c) { if (c.chart === ro.target) density(c); });
                    });
                });
            }
            chartObserver.observe(chart);
        }
        density(charts[charts.length - 1]);
    }

    function density(entry) {
        const width = entry.chart.clientWidth;
        if (!width || !entry.count) return;
        if (width === entry.width) return;
        entry.width = width;
        const per = width / entry.count;
        entry.chart.classList.toggle('ad-chart--novalues', per < entry.valueRoom);
        entry.chart.classList.toggle('ad-chart--nolabels', per < entry.labelRoom);
    }

    window.addEventListener('resize', SIK.debounce(function () {
        charts.forEach(density);
    }, 120));

    Admin.initCharts = initCharts;

    /* -------------------------------------------------------------------
       Reorderable lists
       -------------------------------------------------------------------
       One implementation for every list an admin puts in order by hand: the
       homepage designer's sections, a mega panel's rows, a combo's
       components and its gallery. It has two paths and neither is the lesser
       one:

       - A Pointer Events drag from the row's [data-grip]. The menu and combo
         screens used HTML5 drag-and-drop, which never fires from a touch
         screen, so on a phone those lists could not be dragged at all.
         Pointer Events are one model for mouse, pen and finger.
       - [data-move="up|down"] buttons, the keyboard and screen-reader path.

       The helper only moves DOM nodes. What a move MEANS belongs to the
       caller, through onChange: the designer saves to the server, while a
       form renumbers its own position inputs so that what it posts is
       exactly what it posted before.

       opts.row       selector for a row; rows are direct children of `list`
       opts.onChange  function (row, how) once a row has landed somewhere new
       opts.status    live region for announcements (one is made if absent)
       opts.scroller  element to auto-scroll during a drag (default: the page)
       ------------------------------------------------------------------- */
    function sortable(list, opts) {
        if (!list || list.dataset.sortableReady === '1') return null;
        list.dataset.sortableReady = '1';
        opts = opts || {};

        const rowSelector = opts.row || 'li';
        const onChange = opts.onChange || function () {};

        // A drag that silently did nothing and one that worked look the same
        // to someone who cannot see the list, so every outcome is spoken.
        let status = opts.status || null;
        if (!status) {
            status = document.createElement('p');
            status.className = 'sik-sr';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            list.parentNode.insertBefore(status, list.nextSibling);
        }

        function rows() {
            return [].slice.call(list.children).filter(function (el) { return el.matches(rowSelector); });
        }
        function nameOf(row) { return row.dataset.name || 'Row'; }
        function say(message) { status.textContent = message; }

        /**
         * The first row cannot go up and the last cannot go down.
         *
         * aria-disabled rather than `disabled`: pressing "up" until a row
         * reaches the top would otherwise disable the very button that has
         * focus, and a disabled button drops focus to <body> - the keyboard
         * user is thrown back to the top of the page mid-task.
         */
        function sync() {
            const all = rows();
            all.forEach(function (row, i) {
                const up = row.querySelector('[data-move="up"]');
                const dn = row.querySelector('[data-move="down"]');
                if (up) up.setAttribute('aria-disabled', String(i === 0));
                if (dn) dn.setAttribute('aria-disabled', String(i === all.length - 1));
                if (row.tagName === 'LI') {
                    row.setAttribute('aria-posinset', String(i + 1));
                    row.setAttribute('aria-setsize', String(all.length));
                }
            });
        }
        sync();
        // Rows added or removed by the page (a combo's product picker, the
        // typed-position sort) re-enable the right buttons without every
        // caller having to remember to ask.
        new MutationObserver(sync).observe(list, { childList: true });

        // ---- the drag ----------------------------------------------------
        let dragRow = null;
        let marker = null;
        let grip = null;
        let pointerId = null;
        let startIndex = -1;
        let lastY = 0;
        let frame = 0;

        /* Arming a FINGER drag, and why it is not armed on touch immediately.
           -----------------------------------------------------------------
           The grip used to carry `touch-action: none`, which hands the whole
           gesture to this script the moment a finger lands on it. That is
           right for the drag and wrong for everything else: the grips form a
           tall strip down the left edge of the list, and inside that strip a
           flick could not scroll the page at all - on a phone, a list longer
           than the screen became a trap you had to steer around.

           So the grip now says `touch-action: manipulation` and the drag is
           armed by a deliberate press instead:

             - finger down, still for HOLD_MS  -> drag (we then preventDefault
               every touchmove, so the page does not scroll under it);
             - finger down, moves first        -> the browser scrolls, exactly
               as it would anywhere else on the page.

           SLOP is what tells the two apart. It has to be small enough that a
           press is not mistaken for a flick and large enough to survive the
           wobble of a fingertip; 8px is the usual figure and matches what the
           platform itself uses to start a scroll.

           A mouse or a pen is armed on press as before: neither has a scroll
           gesture to protect, and a mouse that waits a quarter of a second
           before it picks anything up just feels broken. */
        const HOLD_MS = 260;
        const SLOP    = 8;
        let pending = null;

        /** Park the drop marker before the first row whose middle is below y. */
        function placeMarker(y) {
            const others = rows().filter(function (r) { return r !== dragRow; });
            let before = null;
            for (let i = 0; i < others.length; i++) {
                const box = others[i].getBoundingClientRect();
                if (y < box.top + box.height / 2) { before = others[i]; break; }
            }
            const last = others[others.length - 1];
            list.insertBefore(marker, before || (last ? last.nextSibling : null));
        }

        /**
         * Scroll while the pointer rests near an edge, or a list taller than
         * the screen can only be reordered one screenful at a time. The page's
         * top edge is the bottom of the sticky topbar, not the viewport's.
         * Returns whether it scrolled, so the loop knows to keep going.
         */
        function autoScroll(y) {
            const pane = opts.scroller;
            if (pane) {
                const box = pane.getBoundingClientRect();
                if (y < box.top + 40 && pane.scrollTop > 0) { pane.scrollTop -= 12; return true; }
                if (y > box.bottom - 40 && pane.scrollTop + pane.clientHeight < pane.scrollHeight) {
                    pane.scrollTop += 12;
                    return true;
                }
                return false;
            }
            const bar = document.querySelector('.ad-topbar');
            const top = bar ? bar.getBoundingClientRect().bottom : 0;
            const root = document.scrollingElement || document.documentElement;
            if (y < top + 48 && root.scrollTop > 0) { window.scrollBy(0, -14); return true; }
            if (y > window.innerHeight - 48 && root.scrollTop + window.innerHeight < root.scrollHeight) {
                window.scrollBy(0, 14);
                return true;
            }
            return false;
        }

        function tick() {
            frame = 0;
            if (!dragRow) return;
            if (autoScroll(lastY)) {
                placeMarker(lastY);
                frame = window.requestAnimationFrame(tick);
            }
        }

        function finish(drop) {
            if (!dragRow) return;
            const row = dragRow;
            // Cleared first: moving the row below detaches the captured grip,
            // which fires lostpointercapture, and that must find nothing to do.
            dragRow = null;
            if (frame) { window.cancelAnimationFrame(frame); frame = 0; }
            if (grip && pointerId !== null && grip.hasPointerCapture && grip.hasPointerCapture(pointerId)) {
                grip.releasePointerCapture(pointerId);
            }
            if (marker && marker.parentNode) {
                if (drop) marker.parentNode.replaceChild(row, marker);
                else marker.parentNode.removeChild(marker);
            }
            row.classList.remove('is-dragging');
            marker = null;
            grip = null;
            pointerId = null;

            if (!drop) {
                say('Move cancelled. ' + nameOf(row) + ' is back where it was.');
                return;
            }
            const all = rows();
            const index = all.indexOf(row);
            if (index === startIndex) {
                say(nameOf(row) + ' was not moved.');
                return;
            }
            say(nameOf(row) + ' moved to position ' + (index + 1) + ' of ' + all.length + '.');
            onChange(row, 'drag');
        }

        /** Stop waiting for a hold; the gesture belongs to the page now. */
        function disarm() {
            if (!pending) return;
            window.clearTimeout(pending.timer);
            pending = null;
        }

        /** Actually pick the row up. Shared by the mouse path and the hold. */
        function begin(handle, row, id, y) {
            pending = null;

            // Capture routes every later move to the grip, wherever the finger
            // wanders.
            try {
                handle.setPointerCapture(id);
            } catch (err) {
                // Only a pointer the browser is not tracking (a scripted
                // event) is refused; the drag still works uncaptured.
            }

            grip = handle;
            pointerId = id;
            dragRow = row;
            startIndex = rows().indexOf(row);
            row.classList.add('is-dragging');

            marker = document.createElement(/^(UL|OL)$/.test(list.tagName) ? 'li' : 'div');
            marker.className = 'ad-reorder-gap';
            marker.setAttribute('aria-hidden', 'true');
            lastY = y;
            placeMarker(lastY);
            say('Dragging ' + nameOf(row) + '. Release to drop it, or press Escape to cancel.');
        }

        list.addEventListener('pointerdown', function (e) {
            const handle = e.target.closest('[data-grip]');
            if (!handle || e.button > 0 || !e.isPrimary || dragRow || pending) return;
            const row = handle.closest(rowSelector);
            if (!row || row.parentNode !== list || rows().length < 2) return;

            if (e.pointerType === 'touch') {
                // Not yet: see HOLD_MS above. Nothing is prevented here, so if
                // the finger turns out to be scrolling the browser is free to.
                pending = {
                    handle: handle,
                    row: row,
                    id: e.pointerId,
                    x: e.clientX,
                    y: e.clientY,
                    timer: window.setTimeout(function () {
                        if (!pending) return;
                        begin(pending.handle, pending.row, pending.id, pending.y);
                    }, HOLD_MS),
                };
                return;
            }

            // preventDefault keeps a mouse drag from selecting text.
            e.preventDefault();
            begin(handle, row, e.pointerId, e.clientY);
        });

        list.addEventListener('pointermove', function (e) {
            if (pending && e.pointerId === pending.id) {
                // Moved before the hold finished: this is a scroll, not a drag.
                if (Math.abs(e.clientY - pending.y) > SLOP || Math.abs(e.clientX - pending.x) > SLOP) disarm();
                return;
            }
            if (!dragRow || e.pointerId !== pointerId) return;
            e.preventDefault();
            lastY = e.clientY;
            placeMarker(lastY);
            if (!frame) frame = window.requestAnimationFrame(tick);
        });

        /* preventDefault on a POINTER event does not stop a touch scroll -
           only touch-action or a cancelled touchmove does, and touch-action
           has to be decided before the finger lands. So the page is held
           still here, once the drag is armed and not a moment before. */
        list.addEventListener('touchmove', function (e) {
            if (dragRow) {
                // A scroll already under way makes touchmove uncancellable;
                // calling preventDefault then only logs a warning.
                if (e.cancelable) e.preventDefault();
                return;
            }
            // The browser took the gesture over while we were still waiting
            // for the hold. It owns it; stop waiting.
            if (pending && !e.cancelable) disarm();
        }, { passive: false });

        list.addEventListener('pointerup', function (e) {
            if (pending && e.pointerId === pending.id) { disarm(); return; }
            if (e.pointerId === pointerId) finish(true);
        });
        // Cancelled is not dropped: the row goes back where it was. The
        // browser cancels when it takes the gesture over (a system swipe, a
        // palm), and a lost capture means the grip vanished mid-drag.
        list.addEventListener('pointercancel', function (e) {
            if (pending && e.pointerId === pending.id) { disarm(); return; }
            if (e.pointerId === pointerId) finish(false);
        });
        list.addEventListener('lostpointercapture', function (e) {
            if (e.pointerId === pointerId) finish(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dragRow) finish(false);
            else if (e.key === 'Escape') disarm();
        });
        // A page that scrolls away under a waiting finger is scrolling, so the
        // press was never a drag. (scroll fires on the page, not on the list.)
        window.addEventListener('scroll', function () { disarm(); }, { passive: true, capture: true });

        // ---- the keyboard path -------------------------------------------
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-move]');
            if (!btn) return;
            const row = btn.closest(rowSelector);
            if (!row || row.parentNode !== list) return;
            e.preventDefault();
            if (btn.getAttribute('aria-disabled') === 'true') return;

            const all = rows();
            const i = all.indexOf(row);
            const up = btn.dataset.move === 'up';
            const neighbour = all[up ? i - 1 : i + 1];
            if (!neighbour) return;

            // Move the NEIGHBOUR past the row, never the row itself. The row
            // holds the focused button, and a focused node that leaves the
            // DOM - even for a same-tick re-insert - drops focus to <body>.
            list.insertBefore(neighbour, up ? row.nextSibling : row);
            sync();
            row.scrollIntoView({ block: 'nearest' });
            say(nameOf(row) + ' moved ' + (up ? 'up' : 'down') + ' to position '
                + (up ? i : i + 2) + ' of ' + all.length + '.');
            onChange(row, 'button');
        });

        return { sync: sync };
    }
    Admin.sortable = sortable;

    /* -------------------------------------------------------------------
       Homepage designer: reorder sections, saved as you go
       ------------------------------------------------------------------- */
    function initDesigner(scope) {
        const root = (scope || document).querySelector('[data-designer]');
        if (!root || root.dataset.designerReady === '1') return;
        root.dataset.designerReady = '1';

        const list = root.querySelector('[data-seclist]');
        if (!list) return;

        const zone   = root.dataset.zone || 'home';
        const status = root.querySelector('[data-designer-status]');

        function rows() {
            return [].slice.call(list.querySelectorAll('.ad-sec'));
        }

        function say(message) {
            if (status) status.textContent = message;
        }

        /** Persist the order shown on screen. */
        function commit() {
            const ids = rows().map(function (r) { return r.dataset.id; }).filter(Boolean);
            const body = new FormData();
            body.append('zone', zone);
            body.append('order', ids.join(','));
            body.append('csrf_token', (window.SIK_CONFIG && SIK_CONFIG.csrfToken) || '');

            return fetch(root.dataset.orderUrl, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json().catch(function () { return {}; }); })
              .then(function (data) {
                  if (data && data.success) { say('Order saved.'); return; }
                  // A 409 means someone else changed the zone; the order on
                  // screen is no longer a safe thing to write.
                  say((data && data.message) || 'Could not save the new order.');
                  if (data && data.message && /reload/i.test(data.message)) {
                      window.location.reload();
                  }
              })
              .catch(function () { say('Could not save the new order - you may be offline.'); });
        }

        // Drag and the move buttons are the shared list behaviour; all this
        // screen adds is that a move is saved the moment it lands. The
        // announcement the helper makes stands until the save answers it.
        sortable(list, {
            row: '.ad-sec',
            status: status,
            scroller: list.closest('.ad-designer__body'),
            onChange: function () { commit(); }
        });
    }
    Admin.initDesigner = initDesigner;

    /* ----------------------------------------------------------------------
       An ellipsis is not a way of reading the rest
       ----------------------------------------------------------------------
       These components clip with `text-overflow: ellipsis`, which is the right
       look in a topbar and in a sidebar rail but leaves the cut text with no
       affordance at all: the topbar title lost 103-119px of a product name at
       768-1024px and carried no tooltip. Anything actually clipped gets a
       `title` here, so the full text is one hover or one focus away; admin.css
       turns the cursor to match.

       Measured rather than applied blindly, so a title that fits does not grow
       a redundant tooltip that a screen reader would then read twice. That is
       also why `.ad-sec__name` is still in the list although it now wraps:
       nothing there is clipped any more, so the else-branch below takes the
       titles an earlier version added back off again.
       ---------------------------------------------------------------------- */
    function initTruncationTitles(scope) {
        const root = scope || document;
        $$('.ad-topbar__title, .ad-sec__name, .ad-nav__label', root).forEach(function (el) {
            const full = el.textContent.replace(/\s+/g, ' ').trim();
            if (el.scrollWidth > el.clientWidth + 1) {
                if (!el.getAttribute('title')) el.setAttribute('title', full);
            } else if (el.getAttribute('title') === full) {
                el.removeAttribute('title');
            }
        });
    }
    Admin.initTruncationTitles = initTruncationTitles;

    function boot() {
        // The layout ships `no-js` on <html> so a stylesheet can tell the two
        // states apart without an inline script (the admin is being kept
        // CSP-ready). Swapped here, first thing, before any init() runs.
        document.documentElement.classList.replace('no-js', 'js');

        initSidebar();
        initDropdowns();
        initContextMenus();
        initTooltips();
        initPopovers();
        initModals();
        initRemote();
        initBulk();
        initSlug();
        initUploads();
        initRepeaters();
        initInlineToggles();
        initFilters();
        initDirtyGuard();
        initProductPicker();
        initOrderStatus();
        initResponsiveTables();
        initCharts();
        initDesigner();
        initTruncationTitles();

        // Copy buttons (order numbers, coupon codes, API keys).
        SIK.on('click', '[data-copy]', async function (e) {
            e.preventDefault();
            try {
                await navigator.clipboard.writeText(this.dataset.copy);
                SIK.toast('Copied', 'success');
            } catch (err) {
                window.prompt('Copy:', this.dataset.copy);
            }
        });

        /* [data-confirm] is NOT wired here any more. Its listeners are armed
           at parse time, above, so the guard survives a throw in any of the
           init()s between this comment and the top of boot(). */

        SIK.on('click', '[data-print]', function (e) {
            e.preventDefault();
            window.print();
        });

        initSeoEditor();
    }

    /* ----------------------------------------------------------------------
       SEO editor: live Google/social previews, length counters, checklist.

       Everything here reads the form the admin is already filling in, so the
       preview is the real record rather than a mock-up. The counters colour at
       the points where search engines actually truncate, not at arbitrary
       limits, and the checklist only ever states what it can verify.
       ---------------------------------------------------------------------- */
    function initSeoEditor() {
        document.querySelectorAll('[data-seo-editor]').forEach(function (root) {
            const form = root.closest('form') || document;
            const field = k => root.querySelector('[data-seo="' + k + '"]');
            const out = k => root.querySelector('[data-seo-preview="' + k + '"]');
            const counter = k => root.querySelector('[data-seo-count="' + k + '"]');

            // Where to borrow a title/description from when the SEO fields are
            // blank - the same fallback the storefront applies at render time,
            // so the preview does not promise something the page will not do.
            const donor = name => name ? form.querySelector('[name="' + name + '"]') : null;
            const titleDonor = donor(root.dataset.titleFrom);
            const descDonor = donor(root.dataset.descriptionFrom);

            const siteName = root.dataset.siteName || '';
            const previewUrl = root.dataset.previewUrl || '';

            const trim = (s, n) => (s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s);

            function effectiveTitle() {
                const own = (field('title')?.value || '').trim();
                if (own) return own;
                const borrowed = (titleDonor?.value || '').trim();
                return borrowed ? borrowed + (siteName ? ' | ' + siteName : '') : (siteName || 'Untitled');
            }

            function effectiveDescription() {
                const own = (field('description')?.value || '').trim();
                if (own) return own;
                return (descDonor?.value || '').replace(/<[^>]*>/g, '').trim();
            }

            function setCounter(key, value, ideal, max) {
                const el = counter(key);
                if (!el) return;
                el.textContent = String(value.length);
                el.classList.remove('is-warn', 'is-over');
                if (value.length > max) el.classList.add('is-over');
                else if (value.length > ideal) el.classList.add('is-warn');
            }

            function checklist(title, description) {
                const focus = (field('focus')?.value || '').trim().toLowerCase();
                const items = [];
                const add = (ok, text) => items.push({ ok: ok, text: text });

                add(title.length >= 20 && title.length <= 60,
                    'Title is 20-60 characters (' + title.length + ')');
                add(description.length >= 70 && description.length <= 160,
                    'Description is 70-160 characters (' + description.length + ')');

                if (focus) {
                    add(title.toLowerCase().indexOf(focus) !== -1, 'Focus keyword appears in the title');
                    add(description.toLowerCase().indexOf(focus) !== -1, 'Focus keyword appears in the description');
                    add(previewUrl.toLowerCase().indexOf(focus.replace(/\s+/g, '-')) !== -1,
                        'Focus keyword appears in the URL');
                }

                const list = root.querySelector('[data-seo-checks]');
                if (!list) return;
                list.innerHTML = '';
                items.forEach(function (item) {
                    const li = document.createElement('li');
                    li.className = 'sik-seo__check ' + (item.ok ? 'is-ok' : 'is-todo');
                    li.textContent = item.text;
                    list.appendChild(li);
                });
            }

            function validateSchema() {
                const box = field('schema');
                const status = root.querySelector('[data-seo-schema-status]');
                if (!box || !status) return;
                const raw = box.value.trim();
                if (raw === '') {
                    status.textContent = 'Added alongside the schema this page already generates. '
                        + 'Invalid JSON is ignored.';
                    status.classList.remove('is-ok', 'is-bad');
                    return;
                }
                try {
                    JSON.parse(raw);
                    status.textContent = 'Valid JSON — this block will be published.';
                    status.classList.add('is-ok'); status.classList.remove('is-bad');
                } catch (err) {
                    status.textContent = 'Not valid JSON, so it will be ignored: ' + err.message;
                    status.classList.add('is-bad'); status.classList.remove('is-ok');
                }
            }

            function refresh() {
                const title = effectiveTitle();
                const description = effectiveDescription();

                if (out('url')) out('url').textContent = previewUrl.replace(/^https?:\/\//, '');
                if (out('title')) out('title').textContent = trim(title, 60);
                if (out('description')) {
                    out('description').textContent = description
                        ? trim(description, 160)
                        : 'No description yet — search engines will pick their own text from the page.';
                }
                if (out('site')) out('site').textContent = siteName;
                if (out('ogtitle')) {
                    out('ogtitle').textContent = trim((field('ogtitle')?.value || '').trim() || title, 70);
                }
                if (out('ogdesc')) {
                    out('ogdesc').textContent = trim((field('ogdesc')?.value || '').trim() || description, 120);
                }

                setCounter('title', field('title')?.value || '', 60, 70);
                setCounter('description', field('description')?.value || '', 160, 200);

                checklist(title, description);
                validateSchema();
            }

            root.addEventListener('input', refresh);
            if (titleDonor) titleDonor.addEventListener('input', refresh);
            if (descDonor) descDonor.addEventListener('input', refresh);
            refresh();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    SIK.admin = Admin;
})();
