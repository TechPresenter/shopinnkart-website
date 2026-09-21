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

        // Debounced search-as-you-type on list screens.
        $$('[data-filter-search]').forEach(function (input) {
            const form = input.closest('form');
            if (!form) return;
            input.addEventListener('input', SIK.debounce(() => form.submit(), 550));
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
        });
    }

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

    /* -------------------------------------------------------------------
       Homepage designer: drag to reorder
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
            syncMoveButtons();
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

        /** The first and last rows cannot move further. */
        function syncMoveButtons() {
            const all = rows();
            all.forEach(function (row, i) {
                const up = row.querySelector('[data-move="up"]');
                const dn = row.querySelector('[data-move="down"]');
                if (up) up.disabled = i === 0;
                if (dn) dn.disabled = i === all.length - 1;
                row.setAttribute('aria-posinset', String(i + 1));
                row.setAttribute('aria-setsize', String(all.length));
            });
        }
        syncMoveButtons();

        // ---- the drag ----------------------------------------------------
        let dragRow = null;
        let marker  = null;

        function placeMarker(y) {
            const others = rows().filter(function (r) { return r !== dragRow; });
            let before = null;
            for (let i = 0; i < others.length; i++) {
                const box = others[i].getBoundingClientRect();
                if (y < box.top + box.height / 2) { before = others[i]; break; }
            }
            if (before) list.insertBefore(marker, before);
            else list.appendChild(marker);
        }

        list.addEventListener('pointerdown', function (e) {
            const grip = e.target.closest('[data-grip]');
            if (!grip || e.button > 0) return;

            dragRow = grip.closest('.ad-sec');
            if (!dragRow) return;

            // Capture routes every later move to this element even though the
            // row itself goes pointer-events:none below.
            grip.setPointerCapture(e.pointerId);
            e.preventDefault();

            dragRow.classList.add('is-dragging');
            marker = document.createElement('li');
            marker.className = 'ad-sec-gap';
            placeMarker(e.clientY);
            say('Dragging ' + (dragRow.dataset.name || 'section') + '.');
        });

        list.addEventListener('pointermove', function (e) {
            if (!dragRow) return;
            e.preventDefault();
            placeMarker(e.clientY);

            // Nudge the pane when the pointer nears its edge, or a long list
            // cannot be reordered past one screenful.
            const pane = list.closest('.ad-designer__body');
            if (pane) {
                const box = pane.getBoundingClientRect();
                if (e.clientY < box.top + 40) pane.scrollTop -= 12;
                else if (e.clientY > box.bottom - 40) pane.scrollTop += 12;
            }
        });

        function endDrag() {
            if (!dragRow) return;
            if (marker && marker.parentNode) marker.parentNode.replaceChild(dragRow, marker);
            dragRow.classList.remove('is-dragging');
            const moved = dragRow;
            dragRow = null;
            marker = null;
            say((moved.dataset.name || 'Section') + ' moved. Saving\u2026');
            commit();
        }

        list.addEventListener('pointerup', endDrag);
        list.addEventListener('pointercancel', function () {
            // Cancelled, not dropped: put the row back where it was.
            if (!dragRow) return;
            if (marker && marker.parentNode) marker.parentNode.removeChild(marker);
            dragRow.classList.remove('is-dragging');
            dragRow = null;
            marker = null;
            say('Drag cancelled.');
        });

        // ---- the keyboard path -------------------------------------------
        list.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-move]');
            if (!btn) return;
            e.preventDefault();
            const row = btn.closest('.ad-sec');
            if (!row) return;

            if (btn.dataset.move === 'up' && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
            } else if (btn.dataset.move === 'down' && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
            } else {
                return;
            }
            // Focus follows the row, or the next press lands on a button that
            // has moved out from under the finger.
            btn.focus();
            say((row.dataset.name || 'Section') + ' moved ' + btn.dataset.move + '. Saving\u2026');
            commit();
        });
    }
    Admin.initDesigner = initDesigner;

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
        initDesigner();

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
