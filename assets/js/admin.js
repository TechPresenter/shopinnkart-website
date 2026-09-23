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

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeDrawer();
                toggle.focus();
                return;
            }

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

    /* ----------------------------------------------------------------------
       Dropdowns
       ---------------------------------------------------------------------- */
    function initDropdowns() {
        SIK.on('click', '[data-dropdown-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const dropdown = this.closest('[data-dropdown]');
            if (!dropdown) return;

            $$('[data-dropdown]').forEach(d => { if (d !== dropdown) d.classList.remove('is-open'); });
            const open = dropdown.classList.toggle('is-open');
            this.setAttribute('aria-expanded', String(open));
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-dropdown]')) {
                $$('[data-dropdown].is-open').forEach(function (d) {
                    d.classList.remove('is-open');
                    const button = d.querySelector('[data-dropdown-toggle]');
                    if (button) button.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }

    /* ----------------------------------------------------------------------
       Modals
       ---------------------------------------------------------------------- */
    Admin.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        document.body.classList.add('sik-no-scroll');
        const focusable = modal.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focusable) setTimeout(() => focusable.focus(), 80);
    };

    Admin.closeModal = function (target) {
        const modal = typeof target === 'string' ? document.getElementById(target) : target;
        if (!modal) return;
        modal.classList.remove('is-open');
        if (!$('.ad-modal.is-open')) document.body.classList.remove('sik-no-scroll');
    };

    function initModals() {
        SIK.on('click', '[data-modal-open]', function (e) {
            e.preventDefault();
            Admin.openModal(this.dataset.modalOpen);

            // Prefill the modal form from data-field-* attributes on the trigger.
            const modal = document.getElementById(this.dataset.modalOpen);
            if (!modal) return;

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
        });

        SIK.on('click', '[data-modal-close]', function (e) {
            e.preventDefault();
            Admin.closeModal(this.closest('.ad-modal'));
        });

        SIK.on('click', '.ad-modal__backdrop', function () {
            Admin.closeModal(this.closest('.ad-modal'));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const open = $('.ad-modal.is-open');
                if (open) Admin.closeModal(open);
            }
        });
    }

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
            if (action && action.value === 'delete'
                && !window.confirm('Delete ' + checked.length + ' selected item(s)? This cannot be undone.')) {
                e.preventDefault();
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
            if (!window.confirm('Remove this image?')) return;

            const item = this.closest('.ad-preview__item');
            const field = document.querySelector(this.dataset.removeImage);
            if (field) field.value = '1';   // hidden "remove_image" flag
            if (item) item.remove();
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
            if (!window.confirm('Change this order to "' + label + '"?')) {
                select.value = select.dataset.current;
                return;
            }
            const form = select.closest('form');
            if (form) form.submit();
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

            const headers = [...head.rows[head.rows.length - 1].cells].map(function (th) {
                // A header that holds a control rather than a name is not a
                // label for anything: the select-all column's <th> contains the
                // master checkbox and its screen-reader text, so every row card
                // on Orders was captioning its own checkbox "SELECT ALL
                // ORDERS". The CSS already gives that column its own treatment.
                if (th.querySelector('input, button, select')) return '';
                return th.textContent.replace(/\s+/g, ' ').trim();
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

        list.addEventListener('pointerdown', function (e) {
            const handle = e.target.closest('[data-grip]');
            if (!handle || e.button > 0 || !e.isPrimary || dragRow) return;
            const row = handle.closest(rowSelector);
            if (!row || row.parentNode !== list || rows().length < 2) return;

            // Capture routes every later move to the grip, wherever the finger
            // wanders; preventDefault keeps a mouse drag from selecting text.
            e.preventDefault();
            try {
                handle.setPointerCapture(e.pointerId);
            } catch (err) {
                // Only a pointer the browser is not tracking (a scripted
                // event) is refused; the drag still works uncaptured.
            }

            grip = handle;
            pointerId = e.pointerId;
            dragRow = row;
            startIndex = rows().indexOf(row);
            row.classList.add('is-dragging');

            marker = document.createElement(/^(UL|OL)$/.test(list.tagName) ? 'li' : 'div');
            marker.className = 'ad-reorder-gap';
            marker.setAttribute('aria-hidden', 'true');
            lastY = e.clientY;
            placeMarker(lastY);
            say('Dragging ' + nameOf(row) + '. Release to drop it, or press Escape to cancel.');
        });

        list.addEventListener('pointermove', function (e) {
            if (!dragRow || e.pointerId !== pointerId) return;
            e.preventDefault();
            lastY = e.clientY;
            placeMarker(lastY);
            if (!frame) frame = window.requestAnimationFrame(tick);
        });

        list.addEventListener('pointerup', function (e) {
            if (e.pointerId === pointerId) finish(true);
        });
        // Cancelled is not dropped: the row goes back where it was. The
        // browser cancels when it takes the gesture over (a system swipe, a
        // palm), and a lost capture means the grip vanished mid-drag.
        list.addEventListener('pointercancel', function (e) {
            if (e.pointerId === pointerId) finish(false);
        });
        list.addEventListener('lostpointercapture', function (e) {
            if (e.pointerId === pointerId) finish(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dragRow) finish(false);
        });

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
        initSidebar();
        initDropdowns();
        initModals();
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

        // Confirm any link marked destructive.
        SIK.on('click', '[data-confirm]', function (e) {
            if (!window.confirm(this.dataset.confirm)) e.preventDefault();
        });

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
