/* ==========================================================================
   ShopInnKart - Core JavaScript
   Vanilla ES2017+. No jQuery, no framework, no build step.

   window.SIK is the shared namespace. Feature modules (cart.js, search.js,
   products.js ...) attach themselves to it and reuse these primitives.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};

    /* ----------------------------------------------------------------------
       Runtime config - printed by includes/footer.php
       ---------------------------------------------------------------------- */
    const cfg = window.SIK_CONFIG || {};
    SIK.config = {
        baseUrl:        cfg.baseUrl || '',
        apiUrl:         cfg.apiUrl || '',
        csrfToken:      cfg.csrfToken || '',
        currencySymbol: cfg.currencySymbol || '₹',
        grouping:       cfg.grouping || 'indian',
        loggedIn:       !!cfg.loggedIn,
        maxCompare:     cfg.maxCompare || 4,
        // How many products this visitor is already comparing. compare.js uses
        // it to decide whether to restore the floating bar, because the header
        // badge it used to read can be switched off in Settings > Widgets.
        compareCount:   cfg.compareCount || 0,
        freeShipAt:     cfg.freeShipAt || 0,
        // Only present when a "cart value" popup is on the page; cart.js keeps
        // the live figure flowing through its 'sik:totals' event after that.
        cartSubtotal:   cfg.cartSubtotal || 0
    };

    /* ----------------------------------------------------------------------
       Tiny DOM helpers
       ---------------------------------------------------------------------- */
    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

    SIK.$ = $;
    SIK.$$ = $$;

    /** Delegated event binding — survives DOM replaced by AJAX. */
    SIK.on = function (event, selector, handler, root) {
        (root || document).addEventListener(event, function (e) {
            const target = e.target.closest(selector);
            if (target && (root || document).contains(target)) {
                handler.call(target, e, target);
            }
        });
    };

    /* ----------------------------------------------------------------------
       Colour theme

       The inline script at the top of includes/header.php has already stamped
       data-theme before first paint, so nothing here runs on load except
       syncing the controls. The shopper's choice is "light", "dark" or
       "system", remembered in localStorage; "system" keeps following the OS
       for as long as the page is open.
       ---------------------------------------------------------------------- */
    const THEME_KEY = 'sik-theme';
    const docEl = document.documentElement;
    const osDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function resolveTheme(mode) {
        if (mode === 'light' || mode === 'dark') return mode;
        return osDark && osDark.matches ? 'dark' : 'light';
    }

    function syncThemeControls() {
        const theme = docEl.getAttribute('data-theme') || 'light';
        const mode  = docEl.getAttribute('data-theme-mode') || 'system';
        $$('[data-theme-toggle]').forEach(function (btn) {
            const label = 'Switch to ' + (theme === 'dark' ? 'light' : 'dark') + ' theme';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        });
        $$('[data-theme-set]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.dataset.themeSet === mode ? 'true' : 'false');
        });
    }

    function applyTheme(mode, persist) {
        if (mode !== 'light' && mode !== 'dark') mode = 'system';
        const theme = resolveTheme(mode);

        // One clean cut: without this every transition on the page animates
        // from the old palette to the new one at once.
        docEl.classList.add('sik-theme-switching');
        docEl.setAttribute('data-theme', theme);
        docEl.setAttribute('data-theme-mode', mode);

        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta && meta.getAttribute('data-' + theme)) {
            meta.setAttribute('content', meta.getAttribute('data-' + theme));
        }
        if (persist) {
            try { localStorage.setItem(THEME_KEY, mode); } catch (e) { /* private mode */ }
        }

        syncThemeControls();
        requestAnimationFrame(() => requestAnimationFrame(() => docEl.classList.remove('sik-theme-switching')));
        document.dispatchEvent(new CustomEvent('sik:theme', { detail: { theme: theme, mode: mode } }));
    }

    SIK.theme = {
        get:  () => docEl.getAttribute('data-theme') || 'light',
        mode: () => docEl.getAttribute('data-theme-mode') || 'system',
        set:  (mode) => applyTheme(mode, true)
    };

    SIK.initTheme = function () {
        // No control on the page means the operator switched the toggle off:
        // the store default is the only mode, so there is nothing to follow.
        if (!document.querySelector('[data-theme-toggle], [data-theme-set]')) return;

        syncThemeControls();

        SIK.on('click', '[data-theme-toggle]', function () {
            applyTheme(SIK.theme.get() === 'dark' ? 'light' : 'dark', true);
        });
        SIK.on('click', '[data-theme-set]', function () {
            applyTheme(this.dataset.themeSet, true);
        });

        if (osDark) {
            const onOsChange = () => { if (SIK.theme.mode() === 'system') applyTheme('system', false); };
            if (osDark.addEventListener) osDark.addEventListener('change', onOsChange);
            else if (osDark.addListener) osDark.addListener(onOsChange);
        }

        // Changed in another tab.
        window.addEventListener('storage', function (e) {
            if (e.key === THEME_KEY && e.newValue) applyTheme(e.newValue, false);
        });
    };

    SIK.debounce = function (fn, wait) {
        let timer;
        return function () {
            const args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(ctx, args), wait || 250);
        };
    };

    SIK.throttle = function (fn, limit) {
        let waiting = false;
        return function () {
            if (waiting) return;
            fn.apply(this, arguments);
            waiting = true;
            setTimeout(() => { waiting = false; }, limit || 150);
        };
    };

    /**
     * Update a header count badge and, crucially, the accessible name of the
     * control that owns it. The badge itself is aria-hidden — it duplicates
     * information visually — so without this a screen-reader user hears "Cart"
     * whether it holds nothing or nine items.
     *
     * Only .sik-action hosts are relabelled; the bottom-nav badges sit next to a
     * visible text label and need no aria-label of their own.
     *
     * `unit` names what is being counted. It defaults to "item" because that is
     * what the cart, wishlist and compare all hold; the notification bell counts
     * unread messages and passes its own noun. Mirrors header_action_label().
     */
    SIK.setBadge = function (selector, noun, count, unit) {
        count = Number(count) || 0;
        unit = unit || 'item';

        SIK.$$(selector).forEach(function (el) {
            const previous = Number(el.dataset.count) || 0;
            el.textContent = count > 99 ? '99+' : String(count);
            el.dataset.count = String(count);
            el.style.display = count > 0 ? '' : 'none';

            // One small nod when a count goes up, so an add from a product card
            // visibly lands in the header. Restarted by forcing a reflow.
            if (count > previous) {
                el.classList.remove('is-bumped');
                void el.offsetWidth;
                el.classList.add('is-bumped');
            }

            const host = el.closest('.sik-action');
            if (!host) return;
            host.setAttribute(
                'aria-label',
                noun + (count === 0 ? ', empty' : ', ' + count + ' ' + unit + (count === 1 ? '' : 's'))
            );
        });
    };

    SIK.escapeHtml = function (value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /* ----------------------------------------------------------------------
       Currency formatting (mirrors PHP money())
       ---------------------------------------------------------------------- */
    SIK.formatCurrency = function (amount, withSymbol) {
        const value = Number(amount) || 0;
        const decimals = value % 1 === 0 ? 0 : 2;
        let formatted;

        if (SIK.config.grouping === 'indian') {
            const negative = value < 0;
            const parts = Math.abs(value).toFixed(decimals).split('.');
            let intPart = parts[0];
            if (intPart.length > 3) {
                const last3 = intPart.slice(-3);
                const rest = intPart.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
                intPart = rest + ',' + last3;
            }
            formatted = (negative ? '-' : '') + intPart + (parts[1] ? '.' + parts[1] : '');
        } else {
            formatted = value.toLocaleString('en-US', {
                minimumFractionDigits: decimals, maximumFractionDigits: decimals
            });
        }
        return withSymbol === false ? formatted : SIK.config.currencySymbol + formatted;
    };

    /* ----------------------------------------------------------------------
       API layer
       Every request carries the CSRF token and expects the standard envelope
       { success, message, data, errors }.
       ---------------------------------------------------------------------- */
    SIK.apiRequest = async function (endpoint, options) {
        options = options || {};
        const method = (options.method || 'GET').toUpperCase();

        let url = endpoint.indexOf('http') === 0
            ? endpoint
            : SIK.config.apiUrl + '/' + String(endpoint).replace(/^\/+/, '');

        const init = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        };

        if (method === 'GET') {
            if (options.params) {
                // Arrays must go out as repeated name[] pairs. Handing an array
                // straight to URLSearchParams joins it with commas, which PHP
                // then reads as a single string and every multi-select filter
                // silently stops working.
                const search = new URLSearchParams();
                Object.keys(options.params).forEach(function (key) {
                    const value = options.params[key];
                    if (value === null || value === undefined || value === '') return;
                    if (Array.isArray(value)) {
                        const name = key.endsWith('[]') ? key : key + '[]';
                        value.forEach(v => search.append(name, v));
                    } else {
                        search.append(key, value);
                    }
                });
                const qs = search.toString();
                if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
            }
        } else {
            init.headers['Content-Type'] = 'application/json';
            init.headers['X-CSRF-Token'] = SIK.config.csrfToken;
            const payload = Object.assign({}, options.body || {});
            payload.csrf_token = SIK.config.csrfToken;
            init.body = JSON.stringify(payload);
        }

        if (options.signal) init.signal = options.signal;

        try {
            const response = await fetch(url, init);
            const text = await response.text();

            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (parseError) {
                // A PHP fatal or an HTML error page landed here.
                console.error('Non-JSON response from ' + url, text.slice(0, 400));
                return { success: false, message: 'Something went wrong. Please try again.', errors: {}, status: response.status };
            }

            data.status = response.status;

            // A stale CSRF token means the session rotated (or expired) while
            // the page sat open. Say so plainly instead of "something went wrong".
            if (data.code === 'csrf') {
                data.message = data.message || 'Your session expired. Please refresh the page.';
            } else if (data.code === 'auth' && !SIK.config.loggedIn) {
                data.message = data.message || 'Please sign in to continue.';
            }
            return data;
        } catch (networkError) {
            if (networkError.name === 'AbortError') {
                return { success: false, aborted: true, message: '', errors: {} };
            }
            console.error('Network error', networkError);
            return { success: false, message: 'Network error. Please check your connection.', errors: {} };
        }
    };

    /** Convenience wrappers. */
    SIK.get  = (endpoint, params) => SIK.apiRequest(endpoint, { method: 'GET', params: params });
    SIK.post = (endpoint, body)   => SIK.apiRequest(endpoint, { method: 'POST', body: body });

    /* ----------------------------------------------------------------------
       Button loading state - prevents double submits
       ---------------------------------------------------------------------- */
    SIK.showLoader = function (button) {
        if (!button || button.dataset.loading === '1') return false;
        button.dataset.loading = '1';
        button.classList.add('is-loading');
        button.disabled = true;
        if (!$('.sik-spinner', button)) {
            const spinner = document.createElement('span');
            spinner.className = 'sik-spinner';
            button.insertBefore(spinner, button.firstChild);
        }
        return true;
    };

    SIK.hideLoader = function (button) {
        if (!button) return;
        delete button.dataset.loading;
        button.classList.remove('is-loading');
        button.disabled = false;
    };

    /* ----------------------------------------------------------------------
       Skeleton loading

       The markup lives in includes/skeletons.php and is printed once per page
       as <template id="sikSkel-{name}">; the look is app.css section 30b.
       Only regions whose content genuinely arrives after the page use this -
       see the map at the top of skeletons.php.

       A refetch goes: busy + old content dimmed at once, skeletons after
       SKEL_DELAY if the answer is not back yet, then the answer (faded in) or
       the Retry block. Nothing is ever held back once it has arrived, and no
       path ends with a skeleton still on screen.
       ---------------------------------------------------------------------- */
    const SKEL_DELAY = 120;

    SIK.skeleton = {
        /** `count` copies of a skeleton template, as one fragment. */
        make: function (name, count) {
            const tpl = document.getElementById('sikSkel-' + name);
            const frag = document.createDocumentFragment();
            for (let i = 0; i < (count || 1); i++) {
                if (tpl && tpl.content) {
                    frag.appendChild(tpl.content.cloneNode(true));
                } else {
                    // A page rendered without the storefront footer: a plain
                    // bone still beats an empty region.
                    const bone = document.createElement('div');
                    bone.className = 'sik-skel sik-skel__bone';
                    bone.style.minHeight = '96px';
                    bone.setAttribute('aria-hidden', 'true');
                    frag.appendChild(bone);
                }
            }
            return frag;
        },

        /**
         * Begin loading a region. `fill(region)` draws the skeleton and runs at
         * most once, only if the answer takes longer than SKEL_DELAY.
         *
         * The returned handle must end in exactly one of:
         *   settle()  the answer is here (or it failed) - clears busy/stale;
         *   cancel()  a newer request owns the region now - only stops the
         *             timer, so a stale fill can never land on fresh content.
         */
        start: function (region, fill) {
            region.setAttribute('aria-busy', 'true');
            region.classList.add('sik-stale');
            let shown = false;
            const timer = setTimeout(function () {
                region.classList.remove('sik-stale');
                shown = true;
                fill(region);
            }, SKEL_DELAY);

            return {
                get shown() { return shown; },
                cancel: function () { clearTimeout(timer); },
                settle: function () {
                    clearTimeout(timer);
                    region.classList.remove('sik-stale');
                    region.removeAttribute('aria-busy');
                }
            };
        },

        /** Fade in freshly inserted content (every element child, or a list). */
        reveal: function (target) {
            const nodes = Array.isArray(target) ? target : Array.prototype.slice.call(target.children);
            nodes.forEach(function (node) {
                if (!node || node.nodeType !== 1) return;
                node.classList.add('sik-skel-reveal');
                node.addEventListener('animationend', function () {
                    node.classList.remove('sik-skel-reveal');
                }, { once: true });
            });
        },

        /**
         * Replace a skeleton with the failure block: a title, a line of text
         * and a Retry button that calls opts.retry().
         *
         * opts.title / opts.text  the copy (defaults to "Unable to load products")
         * opts.into               replace this element's content (default: region)
         * opts.after              or insert the block after this node instead
         * Returns the block, so a caller can remove it on the next attempt.
         */
        error: function (region, opts) {
            opts = opts || {};
            const holder = document.createElement('div');
            holder.appendChild(SIK.skeleton.make('error', 1));
            let block = holder.firstElementChild;

            if (!block || !block.hasAttribute('data-skel-error')) {
                block = document.createElement('div');
                block.className = 'sik-empty sik-empty--sm sik-loaderr';
                block.innerHTML = '<p class="sik-empty__title" role="alert" data-skel-error-title></p>'
                    + '<p class="sik-empty__text" data-skel-error-text></p>'
                    + '<button type="button" class="sik-btn sik-btn--primary" data-skel-retry>'
                    + '<span class="sik-btn__label">Retry</span></button>';
            }

            const title = block.querySelector('[data-skel-error-title]');
            const text = block.querySelector('[data-skel-error-text]');
            if (title) title.textContent = opts.title || 'Unable to load products';
            if (text && opts.text) text.textContent = opts.text;

            const retry = block.querySelector('[data-skel-retry]');
            if (retry) {
                if (typeof opts.retry === 'function') {
                    retry.addEventListener('click', function () {
                        if (!SIK.showLoader(retry)) return;
                        opts.retry(block);
                    }, { once: true });
                } else {
                    retry.remove();
                }
            }

            if (opts.after && opts.after.parentNode) {
                opts.after.parentNode.insertBefore(block, opts.after.nextSibling);
            } else {
                const target = opts.into || region;
                target.innerHTML = '';
                target.appendChild(block);
            }
            return block;
        }
    };

    /* ----------------------------------------------------------------------
       Images: placeholder, fade-in, graceful failure

       Every storefront <img> already has width/height and sits in a fixed-ratio
       well, so arriving images never move the page. This adds the in-between:
       a lazy image that comes on screen before it has loaded shimmers in its
       own box (img.is-pending, app.css 30b) and fades in when it lands. One
       that is ready by the time it is seen gets no effect at all, and nothing
       is ever hidden that has already painted. A broken image gets a neutral
       glyph instead of the browser's torn-page icon and alt text.
       ---------------------------------------------------------------------- */
    // The 24-unit drawing sits inside a 72-unit canvas, so the glyph is a third
    // of whatever box it lands in without any CSS padding. That inset used to
    // be `padding: 34%` on .is-broken, and a percentage padding resolves
    // against the containing block's WIDTH: on an element sized by height
    // alone - the wordmarks - it was several times the element's own height and
    // the box grew instead of keeping its shape. Baked into the artwork it
    // cannot distort anything.
    const BROKEN_IMG = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-24 -24 72 72" fill="none" stroke="#9CA3AF"'
        + ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">'
        + '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.6"/>'
        + '<path d="M21 16l-5-5-7.5 7.5"/><path d="M3 3l18 18" stroke-opacity=".7"/></svg>'
    );

    function breakImage(img) {
        if (!img || img.dataset.imgBroken === '1') return;
        // Never for the hover twin: it is invisible until hovered anyway.
        if (img.classList.contains('sik-card__img--hover')) return;
        img.dataset.imgBroken = '1';

        // Pin the box before the glyph goes in. BROKEN_IMG is a 1:1 24x24 SVG,
        // and an element sized on one axis with the other left to the image's
        // own proportions collapses to a square: the header and footer
        // wordmarks are height-only, so 188x52 became 52x52 and the header
        // lost 136px of width. The width/height attributes are the declared
        // proportions; the rendered box is the fallback for an <img> that
        // carries none.
        let w = parseFloat(img.getAttribute('width'));
        let h = parseFloat(img.getAttribute('height'));
        if (!(w > 0 && h > 0)) {
            // One attribute without the other would mix a declared width with a
            // rendered height, so it is both or neither.
            const box = img.getBoundingClientRect();
            w = box.width;
            h = box.height;
        }
        if (w > 0 && h > 0) img.style.aspectRatio = w + ' / ' + h;

        img.classList.remove('is-pending', 'is-shown');
        img.classList.add('is-broken');

        // Inside a <picture> the resource is re-selected when the src changes,
        // and a matching <source srcset> still beats the img's own src - so
        // the broken file would simply come back and the visitor would keep
        // the browser's torn-page icon. The storefront uses <picture> for
        // exactly the images most likely to be missing: the hero and promo
        // slides and the popup poster, whose mobile variant is a separate
        // upload that can go missing on its own.
        const parent = img.parentElement;
        if (parent && parent.tagName === 'PICTURE') {
            $$('source', parent).forEach(source => source.remove());
        }
        img.removeAttribute('srcset');
        img.removeAttribute('sizes');
        img.src = BROKEN_IMG;
    }

    const imageObserver = ('IntersectionObserver' in window)
        ? new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                const img = entry.target;
                imageObserver.unobserve(img);
                if (img.complete) return;
                img.classList.add('is-pending');
            });
        }, { rootMargin: '0px 0px 80px 0px' })
        : null;

    SIK.images = {
        /** Start watching the lazy images inside `root` (default: the page). */
        watch: function (root) {
            $$('img', root).forEach(function (img) {
                if (img.dataset.imgWatched === '1') return;
                img.dataset.imgWatched = '1';

                // Failed before this script ran: complete, with no pixels.
                if (img.complete) {
                    const src = img.getAttribute('src') || '';
                    if (src && img.naturalWidth === 0 && !/\.svg(\?|#|$)/i.test(src) && src.indexOf('data:') !== 0) {
                        breakImage(img);
                    }
                    return;
                }
                if (imageObserver && img.loading === 'lazy' && !img.classList.contains('sik-card__img--hover')) {
                    imageObserver.observe(img);
                }
            });
        }
    };

    // load and error do not bubble, but they can be captured - one pair of
    // listeners covers every image, including ones injected later.
    document.addEventListener('load', function (e) {
        const img = e.target;
        if (!img || img.tagName !== 'IMG') return;
        if (imageObserver) imageObserver.unobserve(img);
        if (!img.classList.contains('is-pending')) return;
        img.classList.remove('is-pending');
        img.classList.add('is-shown');
        img.addEventListener('animationend', function () { img.classList.remove('is-shown'); }, { once: true });
    }, true);

    document.addEventListener('error', function (e) {
        const img = e.target;
        if (!img || img.tagName !== 'IMG') return;
        if (imageObserver) imageObserver.unobserve(img);
        if ((img.getAttribute('src') || '').indexOf('data:') === 0) return;
        breakImage(img);
    }, true);

    /* ----------------------------------------------------------------------
       Overlay, drawers and modals
       ---------------------------------------------------------------------- */
    let openLayers = [];

    function ensureOverlay() {
        let overlay = $('#sikOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sikOverlay';
            overlay.className = 'sik-overlay';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', () => SIK.closeTop());
        }
        return overlay;
    }

    // Remembers what had focus before a layer opened so it can be handed back
    // on close — otherwise closing a drawer drops the caret at the top of the
    // document and a keyboard user has to tab all the way back.
    const returnFocusTo = new WeakMap();

    /* ----------------------------------------------------------------------
       SIK.layers — one stack for every overlay on the page

       The storefront's drawers and modals AND the admin's menus, tooltips,
       popovers, modals, drawers and quick views (assets/js/admin.js) all push
       here. It exists so Escape closes exactly ONE thing: the topmost layer.
       A menu opened inside a drawer has to close the menu and leave the drawer
       standing, and a per-component Escape listener can never get that right,
       because each listener only knows about itself. There is one Escape
       handler on the page, in boot() below, and it calls SIK.closeTop().

       A handle is { el, close, type, lock, overlay }:
         el       the overlay element; identity in the stack
         close    what closeTop() calls — the component's own close function
         lock     this layer holds body scroll still. Drawers and modals: yes.
                  Menus, tooltips and popovers: no; the page may still scroll
                  under them, and they close on scroll instead.
         overlay  this layer shows the shared #sikOverlay scrim.
       ---------------------------------------------------------------------- */
    function layerPush(handle) {
        if (!handle || !handle.el) return handle;
        openLayers.push(handle);
        if (handle.lock) document.body.classList.add('sik-no-scroll');
        if (handle.overlay) ensureOverlay().classList.add('is-open');
        return handle;
    }

    /**
     * Remove every handle for this element and release only what nothing else
     * is still holding. A non-locking layer (an admin menu) must never release
     * the scroll lock a drawer underneath it owns, and must never release a
     * lock it did not take — the admin sidebar sets `sik-no-scroll` itself,
     * outside this stack.
     */
    function layerPop(elOrHandle) {
        const el = (elOrHandle && elOrHandle.el) ? elOrHandle.el : elOrHandle;
        if (!el) return;
        const gone = openLayers.filter(l => l.el === el);
        if (!gone.length) return;
        openLayers = openLayers.filter(l => l.el !== el);
        if (gone.some(l => l.lock) && !openLayers.some(l => l.lock)) {
            document.body.classList.remove('sik-no-scroll');
        }
        if (gone.some(l => l.overlay) && !openLayers.some(l => l.overlay)) {
            ensureOverlay().classList.remove('is-open');
        }
    }

    SIK.layers = {
        push: layerPush,
        pop: layerPop,
        /** The layer Escape would close, or null. */
        top: function () { return openLayers.length ? openLayers[openLayers.length - 1] : null; },
        count: function () { return openLayers.length; },
        has: function (el) { return openLayers.some(l => l.el === el); }
    };

    SIK.openDrawer = function (id) {
        const drawer = document.getElementById(id);
        if (!drawer) return;
        // Prefer whatever actually had focus; fall back to the control that
        // opens this drawer so closing still lands somewhere sensible.
        const active = document.activeElement;
        const opener = (active instanceof HTMLElement && active !== document.body)
            ? active
            : document.querySelector('[data-open-drawer="' + id + '"]');
        if (opener) returnFocusTo.set(drawer, opener);

        // Any control that points at this drawer reports it as open. The burger
        // draws its X from that attribute, so the morph needs no second flag.
        $$('[data-open-drawer="' + id + '"]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'true');
        });
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        layerPush({
            type: 'drawer', el: drawer, lock: true, overlay: true,
            close: function () { SIK.closeDrawer(drawer); }
        });
        // Move focus in for keyboard users.
        const focusable = drawer.querySelector('button, [href], input, select, textarea');
        if (focusable) setTimeout(() => focusable.focus(), 120);

        // Keep focus inside. The account dropdown, the search overlay and quick
        // view all trap Tab; the drawers did not, so on a phone — where the
        // menu drawer IS the navigation — tabbing past the last link dropped
        // focus onto page content sitting behind the scrim, with the focus ring
        // invisible underneath it.
        drawer.setAttribute('aria-modal', 'true');
        drawer._sikTrap = function (e) {
            if (e.key !== 'Tab') return;
            const items = Array.prototype.filter.call(
                drawer.querySelectorAll(FOCUSABLE),
                el => el.offsetParent !== null || el === document.activeElement
            );
            if (!items.length) return;
            const i = items.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) {
                e.preventDefault();
                items[items.length - 1].focus();
            } else if (!e.shiftKey && i === items.length - 1) {
                e.preventDefault();
                items[0].focus();
            }
        };
        drawer.addEventListener('keydown', drawer._sikTrap);
    };

    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]),'
        + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    SIK.closeDrawer = function (id) {
        const drawer = typeof id === 'string' ? document.getElementById(id) : id;
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.removeAttribute('aria-modal');
        if (drawer.id) {
            $$('[data-open-drawer="' + drawer.id + '"]').forEach(function (btn) {
                btn.setAttribute('aria-expanded', 'false');
            });
        }
        if (drawer._sikTrap) {
            drawer.removeEventListener('keydown', drawer._sikTrap);
            delete drawer._sikTrap;
        }
        layerPop(drawer);
        const opener = returnFocusTo.get(drawer);
        if (opener && document.contains(opener)) opener.focus();
        returnFocusTo.delete(drawer);
    };

    SIK.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        // Quick View now opens on the click itself, so the second half of a
        // double-click lands on the backdrop that just appeared under the
        // pointer. The backdrop handler ignores clicks this young.
        modal._openedAt = Date.now();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        layerPush({
            type: 'modal', el: modal, lock: true,
            close: function () { SIK.closeModal(modal); }
        });
    };

    SIK.closeModal = function (id) {
        const modal = typeof id === 'string' ? document.getElementById(id) : id;
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        layerPop(modal);
    };

    /** Close the most recently opened layer, and only that one. */
    SIK.closeTop = function () {
        const layer = openLayers[openLayers.length - 1];
        if (!layer) return;
        if (typeof layer.close === 'function') layer.close();
        else if (layer.type === 'drawer') SIK.closeDrawer(layer.el);
        else SIK.closeModal(layer.el);
    };

    SIK.closeAll = function () {
        // A close() that fails to pop its own handle would spin this loop for
        // ever, so a layer that does not go on its own is dropped by hand.
        let guard = 0;
        while (openLayers.length && guard++ < 50) {
            const before = openLayers.length;
            SIK.closeTop();
            if (openLayers.length >= before) openLayers.pop();
        }
    };

    /* ----------------------------------------------------------------------
       Countdown timers
       Any element with data-countdown="<seconds remaining>" gets ticked.
       ---------------------------------------------------------------------- */
    function pad(n) { return n < 10 ? '0' + n : String(n); }

    SIK.initCountdowns = function (root) {
        $$('[data-countdown]', root).forEach(function (el) {
            if (el.dataset.countdownBound === '1') return;
            el.dataset.countdownBound = '1';
            el.dataset.endsAt = String(Date.now() + (parseInt(el.dataset.countdown, 10) || 0) * 1000);
        });
    };

    function tickCountdowns() {
        const now = Date.now();
        $$('[data-countdown][data-countdown-bound="1"]').forEach(function (el) {
            let remaining = Math.max(0, Math.floor((parseInt(el.dataset.endsAt, 10) - now) / 1000));

            const days = Math.floor(remaining / 86400); remaining -= days * 86400;
            const hours = Math.floor(remaining / 3600); remaining -= hours * 3600;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining - minutes * 60;

            const set = (key, value) => {
                const node = el.querySelector('[data-cd="' + key + '"]');
                if (node) node.textContent = pad(value);
            };
            set('days', days);
            set('hours', hours);
            set('minutes', minutes);
            set('seconds', seconds);

            const total = parseInt(el.dataset.endsAt, 10) - now;
            if (total <= 0 && el.dataset.expired !== '1') {
                el.dataset.expired = '1';
                el.classList.add('is-expired');
                const message = el.dataset.expiredText;
                if (message) el.innerHTML = '<span class="text-sm font-semibold">' + SIK.escapeHtml(message) + '</span>';
                document.dispatchEvent(new CustomEvent('sik:countdown-expired', { detail: { el: el } }));
            }
        });
    }

    /* ----------------------------------------------------------------------
       Carousel rails (categories, products, brands, testimonials)
       Native scroll-snap, so touch/swipe is free.
       ---------------------------------------------------------------------- */
    SIK.initRails = function (root) {
        $$('[data-rail]', root).forEach(function (rail) {
            if (rail.dataset.railBound === '1') return;
            rail.dataset.railBound = '1';

            const track = $('[data-rail-track]', rail);
            if (!track) return;

            const prev = $('[data-rail-prev]', rail);
            const next = $('[data-rail-next]', rail);
            const dotsWrap = $('[data-rail-dots]', rail);

            const step = () => {
                const first = track.firstElementChild;
                if (!first) return track.clientWidth;
                const gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || '16') || 16;
                return first.getBoundingClientRect().width + gap;
            };

            const sync = () => {
                const maxScroll = track.scrollWidth - track.clientWidth - 2;
                if (prev) prev.disabled = track.scrollLeft <= 2;
                if (next) next.disabled = track.scrollLeft >= maxScroll;

                if (dotsWrap) {
                    const pages = Math.max(1, Math.ceil(track.scrollWidth / Math.max(1, track.clientWidth)));
                    const active = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
                    if (dotsWrap.childElementCount !== pages) {
                        dotsWrap.innerHTML = '';
                        for (let i = 0; i < pages; i++) {
                            const dot = document.createElement('button');
                            dot.type = 'button';
                            dot.className = 'sik-rail__dot';
                            dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                            dot.addEventListener('click', () => track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' }));
                            dotsWrap.appendChild(dot);
                        }
                    }
                    Array.prototype.forEach.call(dotsWrap.children, (dot, i) => {
                        dot.classList.toggle('is-active', i === active);
                    });
                }
            };

            if (prev) prev.addEventListener('click', () => track.scrollBy({ left: -step(), behavior: 'smooth' }));
            if (next) next.addEventListener('click', () => track.scrollBy({ left: step(), behavior: 'smooth' }));

            track.addEventListener('scroll', SIK.throttle(sync, 100), { passive: true });
            window.addEventListener('resize', SIK.debounce(sync, 200));
            sync();

            // Autoplay, pausing on hover and when the tab is hidden.
            const autoplayMs = parseInt(rail.dataset.autoplay || '0', 10);
            if (autoplayMs > 0) {
                let timer = null;
                const advance = () => {
                    if (document.hidden) return;
                    const maxScroll = track.scrollWidth - track.clientWidth - 2;
                    if (track.scrollLeft >= maxScroll) track.scrollTo({ left: 0, behavior: 'smooth' });
                    else track.scrollBy({ left: step(), behavior: 'smooth' });
                };
                const start = () => { stop(); timer = setInterval(advance, autoplayMs); };
                const stop = () => { if (timer) { clearInterval(timer); timer = null; } };
                rail.addEventListener('mouseenter', stop);
                rail.addEventListener('mouseleave', start);
                rail.addEventListener('touchstart', stop, { passive: true });
                start();
            }
        });
    };

    /* ----------------------------------------------------------------------
       Accordions & tabs
       ---------------------------------------------------------------------- */
    SIK.initAccordions = function (root) {
        SIK.on('click', '[data-acc-toggle]', function (e) {
            e.preventDefault();
            const item = this.closest('[data-acc-item]');
            if (!item) return;
            const group = item.closest('[data-acc-single]');
            const isOpen = item.classList.contains('is-open');

            if (group) {
                $$('[data-acc-item]', group).forEach(other => {
                    other.classList.remove('is-open');
                    const head = $('[data-acc-toggle]', other);
                    if (head) head.setAttribute('aria-expanded', 'false');
                });
            }
            item.classList.toggle('is-open', !isOpen);
            this.setAttribute('aria-expanded', String(!isOpen));
        }, root);
    };

    SIK.initTabs = function (root) {
        SIK.on('click', '[data-tab]', function (e) {
            e.preventDefault();
            const key = this.dataset.tab;
            const wrap = this.closest('[data-tabs]');
            if (!wrap) return;

            $$('[data-tab]', wrap).forEach(tab => {
                const active = tab.dataset.tab === key;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            $$('[data-tabpanel]', wrap).forEach(panel => {
                panel.classList.toggle('is-active', panel.dataset.tabpanel === key);
            });

            if (history.replaceState) {
                history.replaceState(null, '', '#' + key);
            }
        }, root);

        // Deep link support: /product/x#reviews
        const hash = (location.hash || '').replace('#', '');
        if (hash) {
            const target = $('[data-tab="' + CSS.escape(hash) + '"]');
            if (target) target.click();
        }
    };

    /* ----------------------------------------------------------------------
       Quantity steppers
       ---------------------------------------------------------------------- */
    SIK.initQty = function (root) {
        SIK.on('click', '[data-qty-minus], [data-qty-plus]', function (e) {
            e.preventDefault();
            const wrap = this.closest('.sik-qty');
            const input = wrap ? $('input', wrap) : null;
            if (!input) return;

            const min = parseInt(input.min || '1', 10);
            const max = parseInt(input.max || '99', 10);
            let value = parseInt(input.value || '1', 10) || min;

            value += this.hasAttribute('data-qty-plus') ? 1 : -1;
            value = Math.max(min, Math.min(max, value));

            input.value = String(value);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, root);

        SIK.on('change', '.sik-qty input', function () {
            const min = parseInt(this.min || '1', 10);
            const max = parseInt(this.max || '99', 10);
            let value = parseInt(this.value, 10);
            if (isNaN(value)) value = min;
            this.value = String(Math.max(min, Math.min(max, value)));
        }, root);
    };

    /* ----------------------------------------------------------------------
       Scroll-triggered entrance animations & lazy images
       ---------------------------------------------------------------------- */
    SIK.initAnimations = function (root) {
        const nodes = $$('[data-anim]:not(.is-in), .sik-reveal:not(.is-in)', root);
        if (!nodes.length) return;

        // Settings > Theme > "Enable entrance animations" off lands as
        // body.sik-noanim; it is treated exactly like a reduced-motion request.
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            || document.body.classList.contains('sik-noanim');
        if (reduced || !('IntersectionObserver' in window)) {
            nodes.forEach(n => n.classList.add('is-in'));
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                const delay = parseInt(entry.target.dataset.animDelay || '0', 10);
                setTimeout(() => entry.target.classList.add('is-in'), delay);
                observer.unobserve(entry.target);
            });
            // threshold 0 rather than 0.05: the fraction is measured against the
            // element's own box, so a block taller than the viewport can never
            // reach 5% and would never reveal. The rootMargin already supplies
            // the "wait until it is properly on screen" behaviour that the
            // threshold was there for.
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0 });

        nodes.forEach(n => observer.observe(n));

        // Backstop. threshold 0.05 is measured against the element's own box, so
        // a block taller than the viewport can never reach 5% visible and would
        // sit at opacity:0 for the entire visit - the footer grid is 819px tall
        // on a phone and hit exactly that. Anything still hidden after a beat
        // gets revealed regardless. An entrance animation is never worth losing
        // the content to.
        setTimeout(function () {
            nodes.forEach(function (n) {
                if (n.classList.contains('is-in')) return;
                const r = n.getBoundingClientRect();
                if (r.top < window.innerHeight && r.bottom > 0) {
                    n.classList.add('is-in');
                    observer.unobserve(n);
                }
            });
        }, 1200);
    };

    /** Load widget bodies over AJAX when they scroll into view. */
    SIK.initLazyWidgets = function () {
        const widgets = $$('[data-lazy-widget]');
        if (!widgets.length || !('IntersectionObserver' in window)) {
            widgets.forEach(w => loadWidget(w));
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                loadWidget(entry.target);
            });
        }, { rootMargin: '250px' });

        widgets.forEach(w => observer.observe(w));
    };

    async function loadWidget(el) {
        if (el.dataset.loaded === '1') return;
        el.dataset.loaded = '1';

        // Whatever the shell arrived with (a reserved skeleton, or nothing) is
        // what a Retry puts back.
        if (el._skelHtml === undefined) el._skelHtml = el.innerHTML;

        // A deferred skeleton (recently viewed, which is empty on a first
        // visit) is only drawn while the shell is still below the fold: if the
        // answer turns out empty, the band collapses where nobody can see it
        // move. Already on screen, it waits for the real thing instead.
        const deferred = el.querySelector('template[data-skel-deferred]');
        if (deferred && el.getBoundingClientRect().top > window.innerHeight) {
            el.appendChild(deferred.content.cloneNode(true));
            el.setAttribute('aria-busy', 'true');
        }

        const result = await SIK.get('widgets/render.php', { key: el.dataset.lazyWidget });
        el.removeAttribute('aria-busy');
        el.classList.remove('is-failed');

        if (result.success && result.data && result.data.html) {
            el.innerHTML = result.data.html;
            SIK.skeleton.reveal(el);
            SIK.refresh(el);
            return;
        }

        // Answered, with nothing to show (or the section is gone): the shell
        // was only ever a placeholder, so it goes.
        if (result.success || result.status === 404 || !el.querySelector('.sik-skel')) {
            el.remove();
            return;
        }

        // The request itself failed while a skeleton was promising content.
        // The Retry block takes the skeleton's exact height, so failing moves
        // nothing either.
        const height = el.offsetHeight;
        el.classList.add('is-failed');
        el.style.minHeight = height + 'px';
        SIK.skeleton.error(el, {
            title: el.dataset.skelError || 'Unable to load this section',
            retry: function () {
                el.classList.remove('is-failed');
                el.style.minHeight = '';
                el.innerHTML = el._skelHtml;
                el.dataset.loaded = '';
                loadWidget(el);
            }
        });
    }

    /* ----------------------------------------------------------------------
       Back to top
       ---------------------------------------------------------------------- */
    function initBackToTop() {
        // Every "scroll to top" row in Admin > Content > Floating Buttons draws
        // one of these, and an admin may place more than one (a left stack and
        // a right stack, say), so this binds them all rather than a single id.
        const buttons = $$('[data-back-to-top]');
        if (!buttons.length) return;

        const toggle = SIK.throttle(function () {
            const show = window.scrollY > 500;
            buttons.forEach(function (button) {
                button.classList.toggle('is-visible', show);
                // Hidden means hidden: out of sight AND out of the tab order,
                // so a keyboard user never focuses an invisible control.
                button.hidden = !show;
            });
        }, 200);

        window.addEventListener('scroll', toggle, { passive: true });
        buttons.forEach(function (button) {
            button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        });
        toggle();
    }

    /* ----------------------------------------------------------------------
       Popups & pop-ins
       Frequency is honoured client-side via localStorage / sessionStorage.
       ---------------------------------------------------------------------- */
    function popupSeen(id, frequency) {
        const key = 'sik_popup_' + id;
        if (frequency === 'always') return false;
        if (frequency === 'session') return sessionStorage.getItem(key) === '1';

        const stored = localStorage.getItem(key);
        if (!stored) return false;
        if (frequency === 'once') return true;
        if (frequency === 'daily') {
            return (Date.now() - parseInt(stored, 10)) < 86400000;
        }
        return false;
    }

    function markPopupSeen(id, frequency) {
        const key = 'sik_popup_' + id;
        if (frequency === 'session') sessionStorage.setItem(key, '1');
        else if (frequency !== 'always') localStorage.setItem(key, String(Date.now()));
    }

    SIK.initPopups = function () {
        $$('[data-popup]').forEach(function (popup) {
            const id = popup.dataset.popup;
            const frequency = popup.dataset.frequency || 'session';
            const trigger = popup.dataset.trigger || 'timed';
            const value = parseInt(popup.dataset.triggerValue || '5', 10);
            const isPopin = popup.classList.contains('sik-popin');

            if (popupSeen(id, frequency)) return;

            let fired = false;
            const show = function () {
                if (fired) return;
                fired = true;
                markPopupSeen(id, frequency);
                if (isPopin) popup.classList.add('is-open');
                else SIK.openModal(popup.id);
                SIK.post('widgets/popup-track.php', { id: id, event: 'impression' });
            };

            if (trigger === 'immediate') {
                show();
            } else if (trigger === 'timed') {
                setTimeout(show, Math.max(0, value) * 1000);
            } else if (trigger === 'scroll') {
                const onScroll = SIK.throttle(function () {
                    const scrolled = (window.scrollY / Math.max(1, document.body.scrollHeight - window.innerHeight)) * 100;
                    if (scrolled >= value) { show(); window.removeEventListener('scroll', onScroll); }
                }, 200);
                window.addEventListener('scroll', onScroll, { passive: true });
            } else if (trigger === 'exit') {
                const onLeave = function (e) {
                    if (e.clientY <= 0) { show(); document.removeEventListener('mouseleave', onLeave); }
                };
                document.addEventListener('mouseleave', onLeave);
                // Phones have no exit intent; fall back to a longer timer.
                setTimeout(show, 45000);
            } else if (trigger === 'cart_value') {
                // Admin > Popups offers "when the cart reaches a value", so the
                // branch has to exist or the popup is rendered and never shown.
                // The page footer stamps the current subtotal into SIK_CONFIG
                // only when a popup actually asks for it; cart.js then
                // broadcasts every later change on 'sik:totals'.
                const meets = function (amount) { return Number(amount) >= value; };
                if (meets(SIK.config.cartSubtotal)) {
                    show();
                } else {
                    const onTotals = function (e) {
                        if (meets(e.detail && e.detail.subtotal)) {
                            show();
                            document.removeEventListener('sik:totals', onTotals);
                        }
                    };
                    document.addEventListener('sik:totals', onTotals);
                }
            }
        });

        SIK.on('click', '[data-popup-close]', function (e) {
            e.preventDefault();
            const popin = this.closest('.sik-popin');
            if (popin) { popin.classList.remove('is-open'); return; }
            const modal = this.closest('.sik-modal');
            if (modal) SIK.closeModal(modal);
        });

        // A conversion is the shopper acting on the popup rather than dismissing
        // it: following the call to action, or copying the coupon it offered.
        // Newsletter signups report themselves from the subscribe handler.
        SIK.on('click', '[data-popup] a.sik-btn, [data-popup] [data-copy]', function () {
            SIK.trackPopupConversion(this);
        });
    };

    /** Count one conversion for the popup containing `el`. At most one per view. */
    SIK.trackPopupConversion = function (el) {
        const host = el && el.closest ? el.closest('[data-popup]') : null;
        if (!host || host.dataset.converted === '1') return;
        host.dataset.converted = '1';
        SIK.post('widgets/popup-track.php', { id: host.dataset.popup, event: 'conversion' });
    };

    /* ----------------------------------------------------------------------
       Re-initialise everything inside a container after an AJAX swap
       ---------------------------------------------------------------------- */
    SIK.refresh = function (root) {
        SIK.images.watch(root);
        SIK.initAnimations(root);
        SIK.initRails(root);
        SIK.initCountdowns(root);
        initScratchCards(root);
        document.dispatchEvent(new CustomEvent('sik:refresh', { detail: { root: root || document } }));
    };

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */
    function boot() {
        SIK.images.watch();
        SIK.initTheme();
        initAnnounce();
        initScratchCards();
        SIK.initAccordions();
        SIK.initTabs();
        SIK.initQty();
        SIK.initAnimations();
        SIK.initRails();
        SIK.initCountdowns();
        SIK.initLazyWidgets();
        SIK.initPopups();
        initBackToTop();

        setInterval(tickCountdowns, 1000);
        tickCountdowns();

        // Global close handlers.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') SIK.closeTop();
        });
        SIK.on('click', '[data-close-drawer]', function (e) {
            e.preventDefault();
            SIK.closeDrawer(this.dataset.closeDrawer || this.closest('.sik-drawer'));
        });
        SIK.on('click', '[data-open-drawer]', function (e) {
            e.preventDefault();
            SIK.openDrawer(this.dataset.openDrawer);
        });
        SIK.on('click', '[data-close-modal]', function (e) {
            e.preventDefault();
            SIK.closeModal(this.dataset.closeModal || this.closest('.sik-modal'));
        });
        SIK.on('click', '.sik-modal__backdrop', function () {
            const modal = this.closest('.sik-modal');
            if (modal && Date.now() - (modal._openedAt || 0) < 400) return;
            SIK.closeModal(modal);
        });

        // Copy-to-clipboard (coupon codes, product links).
        SIK.on('click', '[data-copy]', async function (e) {
            e.preventDefault();
            const text = this.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
                SIK.toast('Copied to clipboard', 'success');
            } catch (err) {
                // Clipboard API needs a secure context; fall back to a prompt.
                window.prompt('Copy this:', text);
            }
        });

        // Native share with a link-copy fallback.
        SIK.on('click', '[data-share]', async function (e) {
            e.preventDefault();
            const data = { title: this.dataset.shareTitle || document.title, url: this.dataset.share };
            if (navigator.share) {
                try { await navigator.share(data); } catch (err) { /* user dismissed */ }
            } else {
                try {
                    await navigator.clipboard.writeText(data.url);
                    SIK.toast('Link copied', 'success');
                } catch (err) { window.prompt('Copy this link:', data.url); }
            }
        });

        makeScrollersFocusable();
        collapseTocOnPhones();
    }

    /**
     * The policy-page table of contents is a sidebar on desktop, where being
     * open is right, but it stacks above the document on a phone — 16 links is
     * ~758px of chrome before the first word of the actual Terms.
     *
     * It ships `open` so that a visitor without JavaScript still sees it. Here
     * we close it below the sidebar breakpoint, and only while the visitor has
     * not touched it themselves.
     */
    function collapseTocOnPhones() {
        const toc = document.querySelector('details.sik-toc');
        if (!toc) return;

        const mq = window.matchMedia('(min-width: 1024px)');
        let userDecided = false;
        toc.addEventListener('toggle', function () {
            if (toc.dataset.autoToggling !== '1') userDecided = true;
        });

        const apply = () => {
            if (userDecided) return;
            toc.dataset.autoToggling = '1';
            toc.open = mq.matches;
            delete toc.dataset.autoToggling;
        };

        apply();
        if (mq.addEventListener) mq.addEventListener('change', apply);
    }

    /**
     * A horizontally scrolling box that contains no focusable element cannot be
     * scrolled with a keyboard at all. The order-items table is 560px wide
     * inside a ~290px column on a phone and the invoice table 640px, so a
     * keyboard or switch user simply could not reach the price columns.
     *
     * Only boxes that actually overflow become tab stops — making every
     * .sik-scroll-x focusable would add pointless stops on wide screens where
     * the table already fits.
     */
    function makeScrollersFocusable() {
        const scrollers = document.querySelectorAll('.sik-scroll-x, .sik-tablewrap');
        if (!scrollers.length) return;

        const sync = () => {
            scrollers.forEach(function (box) {
                const overflows = box.scrollWidth - box.clientWidth > 2;
                if (overflows) {
                    if (box.getAttribute('tabindex') === null) {
                        box.setAttribute('tabindex', '0');
                        box.setAttribute('role', 'region');
                        if (!box.getAttribute('aria-label')) {
                            const caption = box.querySelector('caption, th');
                            box.setAttribute('aria-label',
                                (caption && caption.textContent.trim()) || 'Scrollable table');
                        }
                    }
                } else if (box.getAttribute('role') === 'region') {
                    box.removeAttribute('tabindex');
                    box.removeAttribute('role');
                }
            });
        };

        sync();
        let timer = null;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(sync, 150);
        });
    }

    /**
     * The announcement strip is dismissible, and a dismissal is remembered for
     * this browser only.
     *
     * The key is a signature of what the strip SAYS, not a flag: a shopper who
     * closed last week's sale banner should still see the next one. The
     * countdown is excluded from that signature, or it would change once a
     * second and no dismissal would ever match.
     */
    function initAnnounce() {
        const bar = document.querySelector('[data-announce]');
        if (!bar) return;

        const KEY = 'sik-announce-dismissed';
        const parts = $$('.sik-announce__item span, .sik-announce__promo-text, .sik-announce__code-value', bar);
        const sig = parts.map(n => (n.textContent || '').trim()).join('|').slice(0, 180);

        try {
            if (sig && localStorage.getItem(KEY) === sig) { bar.hidden = true; return; }
        } catch (e) { /* private mode: the strip simply always shows */ }

        bar.addEventListener('click', function (e) {
            if (!e.target.closest('[data-announce-close]')) return;
            bar.hidden = true;
            try { localStorage.setItem(KEY, sig); } catch (err) { /* nothing to remember it with */ }
            document.dispatchEvent(new CustomEvent('sik:announce-dismissed'));
        });
    }
    /**
     * The scratch coupon.
     *
     * A canvas foil is drawn over the prize and rubbed away with a pointer;
     * once enough of it is gone the card reveals itself. The foil is created
     * HERE rather than in the markup, so a visitor without JavaScript - or one
     * whose canvas is blocked - gets the plain Reveal button underneath and
     * still ends up with the code. Reduced motion skips the foil entirely for
     * the same reason: rubbing is a gesture, and a gesture is not something to
     * require of someone who asked for less movement.
     */
    function initScratchCards(root) {
        $$('[data-scratch]', root).forEach(function (card) {
            if (card.dataset.scratchBound === '1') return;
            card.dataset.scratchBound = '1';

            const reveal = () => {
                if (card.classList.contains('is-revealed')) return;
                card.classList.add('is-revealed');
                document.dispatchEvent(new CustomEvent('sik:scratch-revealed', { detail: { card: card } }));
            };

            const button = $('[data-scratch-reveal]', card);
            if (button) button.addEventListener('click', reveal);

            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
                || document.body.classList.contains('sik-noanim');
            if (reduced || !window.CanvasRenderingContext2D) return;

            const canvas = document.createElement('canvas');
            canvas.className = 'sik-scratch__foil';
            // The foil is decoration over a real button; the button is what
            // assistive tech should find.
            canvas.setAttribute('aria-hidden', 'true');
            card.appendChild(canvas);

            const ctx = canvas.getContext('2d');
            let cleared = false;

            function paint() {
                const rect = card.getBoundingClientRect();
                if (!rect.width || !rect.height) return;
                const dpr = Math.min(window.devicePixelRatio || 1, 2);
                canvas.width = Math.round(rect.width * dpr);
                canvas.height = Math.round(rect.height * dpr);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                const foil = ctx.createLinearGradient(0, 0, rect.width, rect.height);
                foil.addColorStop(0, '#d8b04a');
                foil.addColorStop(0.45, '#f6e6a8');
                foil.addColorStop(1, '#c79a35');
                ctx.globalCompositeOperation = 'source-over';
                ctx.fillStyle = foil;
                ctx.fillRect(0, 0, rect.width, rect.height);

                ctx.fillStyle = 'rgba(90, 50, 10, .62)';
                ctx.font = '600 12px system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('Scratch here', rect.width / 2, rect.height / 2);
            }

            paint();
            let resizeTimer = null;
            window.addEventListener('resize', function () {
                if (cleared) return;
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(paint, 180);
            });

            function scratchAt(e) {
                const rect = canvas.getBoundingClientRect();
                ctx.globalCompositeOperation = 'destination-out';
                ctx.beginPath();
                ctx.arc(e.clientX - rect.left, e.clientY - rect.top, 18, 0, Math.PI * 2);
                ctx.fill();
            }

            /** How much of the foil is gone, sampled on a grid rather than per pixel. */
            function clearedEnough() {
                const w = canvas.width, h = canvas.height;
                if (!w || !h) return false;
                let gone = 0, total = 0;
                const data = ctx.getImageData(0, 0, w, h).data;
                const step = Math.max(4, Math.floor(w / 40)) * 4;
                for (let i = 3; i < data.length; i += step) {
                    total++;
                    if (data[i] < 24) gone++;
                }
                return total > 0 && gone / total > 0.5;
            }

            let drawing = false;
            canvas.addEventListener('pointerdown', function (e) {
                drawing = true;
                canvas.setPointerCapture(e.pointerId);
                scratchAt(e);
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!drawing) return;
                scratchAt(e);
            });
            const finish = function () {
                if (!drawing) return;
                drawing = false;
                // getImageData on a tainted or zero-sized canvas throws; a
                // failure to measure must not cost the shopper the coupon.
                let done = false;
                try { done = clearedEnough(); } catch (err) { done = true; }
                if (done) { cleared = true; reveal(); }
            };
            canvas.addEventListener('pointerup', finish);
            canvas.addEventListener('pointercancel', finish);
            canvas.addEventListener('pointerleave', finish);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
