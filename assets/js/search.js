/* ==========================================================================
   ShopInnKart - live search

   Drives everything that drops down from the search field rendered by
   includes/search-bar.php:

     idle    recent searches (localStorage) + trending terms (search_logs)
             + a shelf of real categories to browse
     typing  grouped product / category / brand rows with the matched
             substring highlighted, behind skeleton rows while in flight
     miss    a plain message, the near-miss terms the API returns, and a row
             of popular products so the panel is never a dead end

   Every row is a real <a href>, so the panel works with a keyboard, with a
   screen reader, and with JavaScript half broken. Arrow keys move a cursor
   over them without taking focus out of the field.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const HISTORY_KEY = 'sik_search_history';
    const HISTORY_MAX = 8;
    const MIN_TERM = 2;

    const Search = {};

    /* Panels know how to redraw their own idle state, so the delegated
       "forget this search" handlers can refresh whichever panel they fired in
       without hunting for its input again. */
    const idlePanels = new WeakMap();

    /* ----------------------------------------------------------------------
       Recent searches
       ---------------------------------------------------------------------- */
    Search.history = function () {
        try {
            const stored = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            if (!Array.isArray(stored)) return [];
            return stored.filter(t => typeof t === 'string' && t.trim() !== '').slice(0, HISTORY_MAX);
        } catch (e) { return []; }
    };

    Search.remember = function (term) {
        term = String(term || '').trim();
        if (term.length < MIN_TERM) return;
        try {
            const list = Search.history().filter(t => t.toLowerCase() !== term.toLowerCase());
            list.unshift(term);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(list.slice(0, HISTORY_MAX)));
        } catch (e) { /* storage disabled or full */ }
    };

    /** Drop one term. A history you cannot edit is a history people stop using. */
    Search.forget = function (term) {
        const needle = String(term || '').trim().toLowerCase();
        if (!needle) return;
        try {
            const list = Search.history().filter(t => t.toLowerCase() !== needle);
            localStorage.setItem(HISTORY_KEY, JSON.stringify(list));
        } catch (e) { /* storage disabled */ }
    };

    Search.clearHistory = function () {
        try { localStorage.removeItem(HISTORY_KEY); } catch (e) { /* noop */ }
    };

    /* ----------------------------------------------------------------------
       Markup helpers
       ---------------------------------------------------------------------- */
    const esc = s => SIK.escapeHtml(s);

    /* Geometry copied from includes/icons.php so the JS-drawn glyphs and the
       PHP-drawn ones are the same drawings. Size and stroke come from CSS. */
    const ICONS = {
        search:   '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        clock:    '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/>',
        trending: '<path d="M3 17l6-6 4 4 7-7"/><path d="M15 8h5v5"/>',
        grid:     '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/>'
                  + '<rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        tag:      '<path d="M11.2 3H20a1 1 0 011 1v8.8a1 1 0 01-.3.7l-7.6 7.6a1.5 1.5 0 01-2.1 0l-7.4-7.4a1.5 1.5 0 010-2.1l7.9-7.3a1 1 0 01.7-.3z"/>'
                  + '<circle cx="16.5" cy="7.5" r="1.4"/>',
        bag:      '<path d="M5 8h14l-1.2 12.2a1.5 1.5 0 01-1.5 1.3H7.7a1.5 1.5 0 01-1.5-1.3z"/><path d="M9 8V6a3 3 0 016 0v2"/>',
        close:    '<path d="M6 6l12 12M18 6L6 18"/>',
        chevron:  '<path d="M9 6l6 6-6 6"/>',
        arrow:    '<path d="M4 12h15"/><path d="M13 6l6 6-6 6"/>',
        alert:    '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>'
    };

    function svg(name) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false">'
            + (ICONS[name] || ICONS.search) + '</svg>';
    }

    function section(label, iconName, body, action) {
        return '<div class="sik-suggest__section">'
            + '<div class="sik-suggest__head">'
            + '<span class="sik-suggest__label">' + svg(iconName) + esc(label) + '</span>'
            + (action || '')
            + '</div>' + body + '</div>';
    }

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Wrap the part of `text` the shopper actually typed in a <mark>.
     * Falls back to the individual words when the whole phrase is not present,
     * which is what makes "iphone 15" light up inside "Apple iPhone 15 Pro".
     * Everything else on the path is escaped, so this stays the only markup.
     */
    function highlight(text, term) {
        const source = String(text === null || text === undefined ? '' : text);
        const needle = String(term || '').trim();
        if (!needle) return esc(source);

        let parts = [needle];
        if (source.toLowerCase().indexOf(needle.toLowerCase()) === -1) {
            parts = needle.split(/\s+/).filter(word => word.length >= MIN_TERM);
        }
        if (!parts.length) return esc(source);

        const pattern = new RegExp('(' + parts.map(escapeRegExp).join('|') + ')', 'ig');
        let out = '', last = 0, match;

        while ((match = pattern.exec(source)) !== null) {
            if (match[0] === '') { pattern.lastIndex++; continue; }
            out += esc(source.slice(last, match.index))
                + '<mark class="sik-suggest__hit">' + esc(match[0]) + '</mark>';
            last = match.index + match[0].length;
        }
        return out + esc(source.slice(last));
    }

    /** A term pill. `removable` adds the second control that forgets it. */
    function chip(term, iconName, removable) {
        const safe = esc(term);
        return '<span class="sik-suggest__chip">'
            + '<button type="button" class="sik-suggest__chipmain" data-sg data-search-term="' + safe + '">'
            + svg(iconName) + '<span class="sik-suggest__chiptext">' + safe + '</span>'
            + '</button>'
            + (removable
                ? '<button type="button" class="sik-suggest__chipx" data-search-forget="' + safe + '"'
                    + ' aria-label="Remove ' + safe + ' from recent searches">' + svg('close') + '</button>'
                : '')
            + '</span>';
    }

    function chips(terms, iconName, removable, extraClass) {
        return '<div class="sik-suggest__chips' + (extraClass || '') + '">'
            + terms.map(term => chip(term, iconName, removable)).join('')
            + '</div>';
    }

    /* The category name is rendered right under the picture, so the picture
       itself is decorative — alt text here would only be read out twice. */
    function categoryTile(category) {
        return '<a class="sik-suggest__cat" href="' + esc(category.url) + '" data-sg>'
            + '<img src="' + esc(category.image_url) + '" alt="" loading="lazy" width="48" height="48">'
            + '<span class="sik-suggest__cat-name">' + esc(category.name) + '</span>'
            + '</a>';
    }

    function productRow(item, term) {
        const meta = [item.brand_name, item.category_name].filter(Boolean).join(' · ');
        const discount = parseInt(item.discount, 10) || 0;

        return '<a class="sik-suggest__item" href="' + esc(item.url) + '" data-sg>'
            + '<img class="sik-suggest__thumb" src="' + esc(item.image_url) + '" alt="" loading="lazy" width="44" height="44">'
            + '<span class="sik-suggest__body">'
            +   '<span class="sik-suggest__name">' + highlight(item.name, term) + '</span>'
            +   (meta ? '<span class="sik-suggest__meta">' + highlight(meta, term) + '</span>' : '')
            + '</span>'
            + '<span class="sik-suggest__side">'
            +   '<span class="sik-suggest__price sik-num">' + esc(item.price_display) + '</span>'
            +   (item.on_sale && item.mrp_display
                    ? '<span class="sik-suggest__mrp sik-num">' + esc(item.mrp_display) + '</span>' : '')
            +   (discount > 0 ? '<span class="sik-suggest__off">' + discount + '% off</span>' : '')
            + '</span></a>';
    }

    function categoryRow(item, term) {
        const count = parseInt(item.product_count, 10) || 0;
        const lead = item.image_url
            ? '<img class="sik-suggest__thumb" src="' + esc(item.image_url) + '" alt="" loading="lazy" width="44" height="44">'
            : '<span class="sik-suggest__tile">' + svg('grid') + '</span>';

        return '<a class="sik-suggest__item" href="' + esc(item.url) + '" data-sg>' + lead
            + '<span class="sik-suggest__body">'
            +   '<span class="sik-suggest__name">' + highlight(item.name, term) + '</span>'
            +   '<span class="sik-suggest__meta">' + count + (count === 1 ? ' product' : ' products') + '</span>'
            + '</span>'
            + '<span class="sik-suggest__chev">' + svg('chevron') + '</span></a>';
    }

    function brandRow(item, term) {
        const lead = item.has_logo
            ? '<img class="sik-suggest__thumb" src="' + esc(item.logo_url) + '" alt="" loading="lazy" width="44" height="44">'
            : '<span class="sik-suggest__tile">' + svg('tag') + '</span>';

        return '<a class="sik-suggest__item" href="' + esc(item.url) + '" data-sg>' + lead
            + '<span class="sik-suggest__body">'
            +   '<span class="sik-suggest__name">' + highlight(item.name, term) + '</span>'
            +   '<span class="sik-suggest__meta">Shop the brand</span>'
            + '</span>'
            + '<span class="sik-suggest__chev">' + svg('chevron') + '</span></a>';
    }

    /* ----------------------------------------------------------------------
       Panel states
       ---------------------------------------------------------------------- */
    function open(panel) {
        panel.classList.add('is-open');
    }

    /**
     * Announce a one-line summary to screen readers.
     *
     * The suggestions panel is not itself a live region: it is rewritten on
     * every keystroke, so announcing it read the whole list aloud each time.
     * Only the summary below is spoken.
     */
    function announce(panel, message) {
        const status = panel.parentElement
            ? panel.parentElement.querySelector('[data-search-status]')
            : null;
        if (status && status.textContent !== message) status.textContent = message;
    }

    function renderIdle(panel, seed) {
        const recent   = Search.history();
        const trending = (seed && seed.popular) || [];
        const shelves  = (seed && seed.categories) || [];
        let html = '';

        if (recent.length) {
            html += section(
                'Recent searches', 'clock',
                chips(recent, 'clock', true),
                '<button type="button" class="sik-suggest__action" data-search-clear>Clear all</button>'
            );
        }

        if (trending.length) {
            html += section('Trending searches', 'trending', chips(trending, 'trending', false));
        }

        if (shelves.length) {
            html += section(
                'Browse categories', 'grid',
                '<div class="sik-suggest__cats">' + shelves.map(categoryTile).join('') + '</div>'
            );
        }

        if (!html) {
            html = '<div class="sik-suggest__empty">' + svg('search')
                + '<p class="sik-suggest__empty-title">Start typing to search</p>'
                + '<p class="sik-suggest__empty-text">Look for a product, a brand or a model number.</p></div>';
        }

        panel.innerHTML = html;
        panel.removeAttribute('aria-busy');
        open(panel);
    }

    /* Skeleton rows, not a spinner: the shape of the answer is already known,
       so the list does not jump when the real rows land. They carry no text,
       which keeps the live region quiet until there is something to say. */
    function renderSkeleton(panel) {
        let rows = '';
        for (let i = 0; i < 3; i++) {
            rows += '<div class="sik-suggest__skel">'
                + '<span class="sik-skeleton sik-suggest__skel-thumb"></span>'
                + '<span class="sik-suggest__skel-lines">'
                +   '<span class="sik-skeleton sik-skeleton--text" style="width:68%;display:block"></span>'
                +   '<span class="sik-skeleton sik-skeleton--text" style="width:38%;display:block"></span>'
                + '</span></div>';
        }
        panel.innerHTML = '<div class="sik-suggest__section">' + rows + '</div>';
        panel.setAttribute('aria-busy', 'true');
        open(panel);
    }

    function renderEmpty(panel, data, term) {
        const suggestions = data.suggestions || [];
        const popular = data.popular_products || [];

        let html = '<div class="sik-suggest__empty">' + svg('search')
            + '<p class="sik-suggest__empty-title">No matches for “' + esc(term) + '”</p>'
            + '<p class="sik-suggest__empty-text">Check the spelling, use fewer words, '
            + 'or try a brand or model number.</p></div>';

        if (suggestions.length) {
            html += section('Try searching for', 'search',
                chips(suggestions, 'search', false, ' sik-suggest__chips--center'));
        }

        if (popular.length) {
            html += section('Popular right now', 'trending',
                popular.map(item => productRow(item, '')).join(''));
        }

        panel.innerHTML = html;
        open(panel);
    }

    function renderResults(panel, data, term) {
        const products   = data.products || [];
        const categories = data.categories || [];
        const brands     = data.brands || [];

        if (!products.length && !categories.length && !brands.length) {
            renderEmpty(panel, data, term);
            return;
        }

        let html = '';

        if (products.length) {
            html += section('Products', 'bag', products.map(item => productRow(item, term)).join(''));
        }
        if (categories.length) {
            html += section('Categories', 'grid', categories.map(item => categoryRow(item, term)).join(''));
        }
        if (brands.length) {
            html += section('Brands', 'tag', brands.map(item => brandRow(item, term)).join(''));
        }

        // data.total counts PRODUCTS only. A query that matches a category or a
        // brand but no product name still renders rows here — and this store's
        // own top-level category is the trigger: /api/products/search.php?q=festive
        // returns products 0, categories 1, total 0. The footer then read
        // "See all 0 results for festive" and pointed at a genuinely empty
        // results page, while the live region announced "No matches" with a
        // match visible on screen.
        //
        // The footer is a link to the PRODUCT results page, so it is only
        // meaningful when there are products to see.
        const total = parseInt(data.total, 10) || products.length;

        if (total > 0) {
            html += '<a class="sik-suggest__footer" data-sg href="'
                + esc(SIK.config.baseUrl + '/search.php?q=') + encodeURIComponent(term) + '">'
                + 'See all ' + total + (total === 1 ? ' result' : ' results') + ' for “' + esc(term) + '”'
                + svg('arrow') + '</a>';
        }

        panel.innerHTML = html;

        // Announce what is actually on screen, not the product count.
        const shown = products.length + categories.length + brands.length;
        announce(panel, shown === 0
            ? 'No matches for ' + term
            : shown + (shown === 1 ? ' result' : ' results') + ' for ' + term);
        open(panel);
    }

    function renderError(panel, message) {
        panel.innerHTML = '<div class="sik-suggest__error">' + svg('alert')
            + '<p>' + esc(message || 'Suggestions could not be loaded.') + '</p>'
            + '<button type="button" class="sik-btn sik-btn--outline sik-btn--sm" data-search-retry>'
            + '<span class="sik-btn__label">Try again</span></button></div>';
        panel.removeAttribute('aria-busy');
        announce(panel, message || 'Suggestions could not be loaded.');
        open(panel);
    }

    /* ----------------------------------------------------------------------
       Wiring one search box
       ---------------------------------------------------------------------- */
    function attach(input) {
        if (input.dataset.searchBound === '1') return;
        input.dataset.searchBound = '1';

        const wrap = input.closest('[data-search]') || input.parentElement;
        const panel = wrap ? wrap.querySelector('[data-search-results]') : null;
        if (!panel) return;

        let controller = null;
        let seed = null;
        let seedRequest = null;

        function clearActive() {
            $$('.is-active', panel).forEach(el => el.classList.remove('is-active'));
        }

        function close() {
            panel.classList.remove('is-open');
            panel.removeAttribute('aria-busy');
            clearActive();
        }

        /* The idle payload never changes while the page is open, so it is
           fetched once and shared by every idle render of this panel. */
        function loadSeed() {
            if (seed) return Promise.resolve(seed);
            if (!seedRequest) {
                seedRequest = SIK.get('products/search.php', { popular: 1 }).then(function (result) {
                    seed = (result && result.success && result.data)
                        ? result.data
                        : { popular: [], categories: [], products: [] };
                    return seed;
                });
            }
            return seedRequest;
        }

        // The floating dialog is a search box, not a browse surface. On the
        // /search page and the 404 the idle panel earns its space - there is
        // nothing else on screen to act on - but in the popup it turned a
        // one-line control into a full card of trending chips and category
        // tiles before the user had typed a character. In there the panel stays
        // shut until there is a query.
        const inPopup = !!wrap.closest('[data-search-pop]');

        function showIdle() {
            if (inPopup) { close(); return Promise.resolve(); }
            return loadSeed().then(function (data) { renderIdle(panel, data); });
        }
        idlePanels.set(panel, showIdle);

        async function handle() {
            const term = input.value.trim();

            if (term.length < MIN_TERM) {
                if (controller) { controller.abort(); controller = null; }
                await showIdle();
                return;
            }

            if (controller) controller.abort();
            controller = new AbortController();
            const signal = controller.signal;

            renderSkeleton(panel);

            const result = await SIK.apiRequest('products/search.php', {
                method: 'GET',
                params: { q: term, limit: 6 },
                signal: signal
            });

            // A newer keystroke already replaced this request.
            if (signal.aborted || result.aborted) return;

            panel.removeAttribute('aria-busy');
            if (result.success) renderResults(panel, result.data || {}, term);
            else renderError(panel, result.message);
        }

        const run = SIK.debounce(handle, 240);

        input.addEventListener('input', run);
        input.addEventListener('focus', run);

        // Retry after a failed request. showLoader() is what stops a frustrated
        // double-tap turning into two in-flight requests.
        panel.addEventListener('click', function (e) {
            const retry = e.target.closest('[data-search-retry]');
            if (!retry) return;
            e.preventDefault();
            if (!SIK.showLoader(retry)) return;
            handle().then(function () { SIK.hideLoader(retry); });
        });

        // Keyboard navigation. Focus stays in the field - the cursor is a class
        // on the row - so typing never has to be interrupted to steer.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                close();
                input.blur();
                return;
            }

            const items = $$('[data-sg]', panel);
            if (!items.length || !panel.classList.contains('is-open')) return;

            const current = items.findIndex(item => item.classList.contains('is-active'));

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const step = e.key === 'ArrowDown' ? 1 : -1;
                const next = (current + step + items.length + 1) % (items.length + 1);
                clearActive();
                // The extra slot is "nothing selected", so arrowing past either
                // end hands the field back rather than trapping the cursor.
                if (next < items.length) {
                    items[next].classList.add('is-active');
                    items[next].scrollIntoView({ block: 'nearest' });
                }
            } else if (e.key === 'Enter' && current >= 0) {
                e.preventDefault();
                Search.remember(input.value.trim());
                items[current].click();
            }
        });

        // Pointer and keyboard cursors must not disagree.
        panel.addEventListener('mousemove', function (e) {
            const row = e.target.closest('[data-sg]');
            if (!row || row.classList.contains('is-active')) return;
            clearActive();
        });

        // Clicking outside closes the panel.
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) close();
        });

        const form = input.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                Search.remember(input.value.trim());
            });
        }
    }

    /* ----------------------------------------------------------------------
       Clear button — shown only while the field has something in it.
       ---------------------------------------------------------------------- */
    function syncClear(input) {
        const wrap = input.closest('[data-search]');
        const clear = wrap && wrap.querySelector('[data-search-clear-input]');
        if (clear) clear.hidden = input.value.trim() === '';
    }

    function bindClear() {
        SIK.on('input', '[data-search] input[type="search"]', function () { syncClear(this); });

        SIK.on('click', '[data-search-clear-input]', function (e) {
            e.preventDefault();
            const wrap = this.closest('[data-search]');
            const input = wrap && wrap.querySelector('input[type="search"]');
            if (!input) return;

            input.value = '';
            input.focus();
            syncClear(input);
            // Falls through to the idle panel rather than closing it: an empty
            // field is exactly when recent and trending searches are useful.
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        $$('[data-search] input[type="search"]').forEach(syncClear);
    }

    /** Redraw whichever idle panel a control inside it belongs to. */
    function refreshIdle(el) {
        const panel = el.closest('[data-search-results]');
        const redraw = panel && idlePanels.get(panel);
        if (redraw) redraw();
        else if (panel) panel.classList.remove('is-open');
    }

    function bind() {
        $$('[data-search-input]').forEach(attach);
        bindClear();

        SIK.on('click', '[data-search-term]', function (e) {
            e.preventDefault();
            const term = this.dataset.searchTerm;
            const input = this.closest('[data-search]')
                ? this.closest('[data-search]').querySelector('[data-search-input]')
                : $('[data-search-input]');
            if (input) {
                input.value = term;
                // form.submit() skips submit listeners, so the term is banked here.
                Search.remember(term);
                const form = input.closest('form');
                if (form) form.submit();
            }
        });

        SIK.on('click', '[data-search-forget]', function (e) {
            e.preventDefault();
            Search.forget(this.dataset.searchForget);
            refreshIdle(this);
        });

        SIK.on('click', '[data-search-clear]', function (e) {
            e.preventDefault();
            Search.clearHistory();
            refreshIdle(this);
        });

        /* ------------------------------------------------------------------
           The floating mini search.

           One dialog serves every width and every entry point: the search icon,
           the inline desktop field, Ctrl/Cmd+K and "/". Routing them all here is
           what stops the suggestion list existing as two separate surfaces that
           drift apart.
           ------------------------------------------------------------------ */
        const pop = $('[data-search-pop]');
        let popReturn = null;   // what to hand focus back to on close
        let restoring = false;  // true only while closePop() returns focus to the trigger

        const popFocusables = function () {
            return Array.prototype.filter.call(
                pop.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])'),
                function (el) { return el.offsetParent !== null || el === document.activeElement; }
            );
        };

        const openPop = function (trigger, seed) {
            if (!pop || !pop.hidden) return;

            popReturn = trigger instanceof HTMLElement ? trigger : null;
            pop.hidden = false;

            // Locking the body removes the scrollbar, which on a desktop mouse
            // would shift the whole page left by its width as the dialog opens.
            // Pay the width back as padding so nothing moves.
            const bar = window.innerWidth - document.documentElement.clientWidth;
            if (bar > 0) document.body.style.paddingRight = bar + 'px';
            document.body.classList.add('sik-searchpop-open');

            document.querySelectorAll('[data-open-search]').forEach(function (btn) {
                btn.setAttribute('aria-expanded', 'true');
            });

            // A microphone error from a previous open ("access is blocked…")
            // would otherwise still be sitting under the field the next time the
            // dialog appears, reading as a fresh failure of something the user
            // has not touched yet.
            const stale = pop.querySelector('[data-voice-status]');
            if (stale) { stale.hidden = true; stale.textContent = ''; }

            const field = pop.querySelector('[data-search-input]');
            if (field) {
                attach(field);
                // Carry over whatever was already typed in the inline field so
                // opening the dialog never costs the user their keystrokes.
                if (typeof seed === 'string' && seed !== '') field.value = seed;
                field.focus();
                // Caret to the end rather than selecting, so typing continues
                // the query instead of replacing it.
                const end = field.value.length;
                try { field.setSelectionRange(end, end); } catch (err) { /* type=search on old WebKit */ }
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        };

        const closePop = function () {
            if (!pop || pop.hidden) return;

            pop.hidden = true;
            document.body.classList.remove('sik-searchpop-open');
            document.body.style.paddingRight = '';

            document.querySelectorAll('[data-open-search]').forEach(function (btn) {
                btn.setAttribute('aria-expanded', 'false');
            });

            // Returning focus to whatever opened the dialog is correct and has
            // to stay. But when that trigger is the inline field, focusing it
            // re-fires the handover listener below — whose only guard is
            // `pop.hidden`, which this function has just set to true. The
            // dialog reopened instantly, so Escape, the close button and the
            // backdrop all became no-ops and there was no way out except
            // navigating away.
            //
            // `restoring` marks the focus THIS function causes so the handover
            // can ignore exactly that one and nothing else. Cleared on a
            // timeout rather than synchronously because the focus event is
            // dispatched asynchronously in some browsers.
            if (popReturn && document.contains(popReturn)) {
                restoring = true;
                popReturn.focus();
                setTimeout(function () { restoring = false; }, 0);
            }
            popReturn = null;
        };

        if (pop) {
            SIK.on('click', '[data-open-search]', function (e) {
                e.preventDefault();
                openPop(this, '');
            });

            SIK.on('click', '[data-search-pop-close]', function (e) {
                e.preventDefault();
                closePop();
            });

            // The inline desktop field is a real form so it still submits with
            // JavaScript off. With JavaScript on, touching it hands over to the
            // dialog, so there is one suggestion surface rather than two.
            const inline = document.querySelector('.sik-search--header [data-search-input]');
            if (inline) {
                const handover = function (e) {
                    if (!pop.hidden) return;
                    // Set by closePop() while it hands focus back to this
                    // field. Without it, closing the dialog reopened it.
                    if (restoring) return;
                    e.preventDefault();
                    inline.blur();

                    // Close the inline field's OWN suggestion panel before
                    // opening the dialog. attach() wired this field too, so its
                    // debounced request still resolves ~240ms after the handover
                    // and calls open() on the inline panel — which then sat at
                    // z-index 60 behind the dialog's translucent backdrop as a
                    // full ghost list of recent and trending searches, and
                    // stayed open behind the page after the dialog closed.
                    // Not attach()'s close() — that one closes over its own
                    // `panel` and is not in scope here.
                    const inlineWrap = inline.closest('.sik-search');
                    const inlinePanel = inlineWrap && inlineWrap.querySelector('.sik-suggest');
                    if (inlinePanel) {
                        inlinePanel.classList.remove('is-open');
                        inlinePanel.removeAttribute('aria-busy');
                    }

                    openPop(inline, inline.value);
                };
                inline.addEventListener('focus', handover);
                inline.addEventListener('mousedown', handover);
            }

            pop.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    // Escape always dismisses the whole dialog. An earlier version
                    // let the suggestion list swallow the first press, but the list
                    // is open from the moment the dialog is (it shows recent and
                    // trending searches before anything is typed), so Escape
                    // appeared to do nothing at all. The list is part of this
                    // dialog, not a layer above it.
                    e.preventDefault();
                    e.stopPropagation();
                    closePop();
                    return;
                }

                if (e.key !== 'Tab') return;

                // Focus stays inside an aria-modal dialog.
                const items = popFocusables();
                if (!items.length) return;
                const first = items[0];
                const last = items[items.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });

            // Following a suggestion navigates away; unlock the page first so a
            // back-button restore does not land on a frozen body.
            pop.addEventListener('click', function (e) {
                if (e.target.closest('a[href]')) document.body.classList.remove('sik-searchpop-open');
            });
        }

        // Ctrl/Cmd+K anywhere, and "/" when the caret is not already in a field.
        document.addEventListener('keydown', function (e) {
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                || (document.activeElement && document.activeElement.isContentEditable);

            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                if (!pop) return;
                e.preventDefault();
                pop.hidden ? openPop(document.activeElement, '') : closePop();
                return;
            }

            if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing) {
                if (!pop) return;
                e.preventDefault();
                openPop(document.activeElement, '');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    // Search fields injected later (the mobile panel, quick view) get wired too.
    document.addEventListener('sik:refresh', function () {
        $$('[data-search-input]').forEach(attach);
    });

    SIK.search = Search;
})();
