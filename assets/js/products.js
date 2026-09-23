/* ==========================================================================
   ShopInnKart - Product listing & detail interactions
   AJAX filters, sorting, pagination, quick view, gallery zoom, variant
   selection, pincode check and stock alerts.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Products = {};

    /* ======================================================================
       1. AJAX filtering / sorting / pagination
       ====================================================================== */
    let filterController = null;

    Products.collectFilters = function (form) {
        const params = {};
        if (!form) return params;

        new FormData(form).forEach(function (value, key) {
            if (value === '' || value === null) return;
            if (key.endsWith('[]')) {
                const name = key.slice(0, -2);
                params[name] = params[name] || [];
                params[name].push(value);
            } else if (params[key] !== undefined) {
                params[key] = [].concat(params[key], value);
            } else {
                params[key] = value;
            }
        });
        return params;
    };

    /** Flatten the filter model into a query string the server understands. */
    function toQuery(params) {
        const search = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            const value = params[key];
            if (Array.isArray(value)) value.forEach(v => search.append(key + '[]', v));
            else search.append(key, value);
        });
        return search.toString();
    }

    // The skeleton handle of the refetch in flight (SIK.skeleton.start), and
    // the request behind "Show more products".
    let gridLoad = null;
    let moreController = null;
    let moreError = null;

    /** The listing's paging facts, kept on [data-pagination] by the server and by us. */
    function pagerState() {
        const node = $('[data-pagination]');
        if (!node || !node.dataset.total) return null;
        return {
            current: parseInt(node.dataset.current, 10) || 1,
            last:    parseInt(node.dataset.last, 10) || 1,
            total:   parseInt(node.dataset.total, 10) || 0,
            perPage: parseInt(node.dataset.perPage, 10) || 12,
            from:    parseInt(node.dataset.from, 10) || 0,
            to:      parseInt(node.dataset.to, 10) || 0
        };
    }

    /**
     * How many cards the answer will hold, so the skeleton is the size of the
     * result rather than a guess. A sort or a page change keeps the same total,
     * so the count is exact. A filter change can return anything; the current
     * count, held to the 6-12 the grid is designed around, is the best guess.
     */
    function expectedCards(grid, extra) {
        const state = pagerState();
        const onScreen = $$('[data-product-card]', grid).length;
        if (state && state.total > 0 && extra && (extra.page || extra.sort)) {
            const page = extra.page ? parseInt(extra.page, 10) || 1 : 1;
            return Math.max(1, Math.min(state.perPage, state.total - (page - 1) * state.perPage));
        }
        return Math.min(12, Math.max(6, onScreen || 12));
    }

    /**
     * Skeleton cards shaped like the ones on screen: the brand line and the
     * rating row are optional in product_card(), and a skeleton that draws a
     * row none of the real cards have is a row taller than the result.
     */
    Products.skeletonCards = function (grid, count) {
        const list = grid.classList.contains('sik-list');
        const frag = SIK.skeleton.make(list ? 'card-row' : 'card', count);
        const hasCards = !!grid.querySelector('[data-product-card]');
        if (hasCards) {
            if (!grid.querySelector('.sik-card__rating')) $$('[data-skel-part="rating"]', frag).forEach(n => n.remove());
            if (!grid.querySelector('.sik-card__brand')) $$('[data-skel-part="brand"]', frag).forEach(n => n.remove());
            if (!grid.querySelector('.sik-price--off')) $$('[data-skel-part="sale"]', frag).forEach(n => n.remove());
        }
        return frag;
    };

    /** Everything around the grid that describes it: count, pager, chips, range, Show more. */
    function applyListingMeta(data, range) {
        const pagination = data.pagination || {};

        const countNode = $('[data-result-count]');
        if (countNode) countNode.textContent = pagination.total;

        const pager = $('[data-pagination]');
        if (pager) {
            pager.innerHTML = data.pagination_html || '';
            pager.dataset.current = pagination.current || 1;
            pager.dataset.last = pagination.last || 1;
            pager.dataset.total = pagination.total || 0;
            pager.dataset.perPage = pagination.per_page || 12;
            // After an append the grid starts where it started before.
            pager.dataset.from = range && range.from ? range.from : (pagination.from || 0);
            pager.dataset.to = pagination.to || 0;
        }

        const chips = $('[data-active-filters]');
        if (chips && data.chips_html !== undefined) chips.innerHTML = data.chips_html || '';

        syncListingRange();
    }

    /** "Showing 1–24 of 30" and the Show more button, from the paging facts. */
    function syncListingRange() {
        const state = pagerState();
        const rangeNode = $('[data-listing-range]');
        const more = $('[data-load-more]');

        if (rangeNode) {
            if (state && state.total > 0 && state.last > 1) {
                rangeNode.hidden = false;
                rangeNode.textContent = 'Showing ' + state.from + '–' + state.to + ' of ' + state.total;
            } else {
                rangeNode.hidden = true;
            }
        }
        if (more) more.hidden = !(state && state.current < state.last);
    }

    function clearMoreError() {
        if (moreError) { moreError.remove(); moreError = null; }
    }

    Products.applyFilters = async function (extra, pushState) {
        const form = $('[data-filter-form]');
        const grid = $('[data-product-grid]');
        if (!grid) return;

        const params = Object.assign(Products.collectFilters(form), extra || {});
        // A new filter selection always returns to page 1.
        if (!extra || !extra.page) delete params.page;

        const query = toQuery(params);

        // Whatever was in flight - an earlier filter, a Show more - is
        // superseded: its answer would describe a grid that no longer exists.
        if (filterController) filterController.abort();
        if (moreController) moreController.abort();
        if (gridLoad) gridLoad.cancel();
        filterController = new AbortController();
        clearMoreError();

        // The toolbar, the filters and the chips stay exactly where they are;
        // only the results turn into skeletons, and only if the answer is slow.
        const count = expectedCards(grid, extra);
        const load = gridLoad = SIK.skeleton.start(grid, function () {
            const skeletons = Products.skeletonCards(grid, count);
            grid.innerHTML = '';
            grid.appendChild(skeletons);
        });
        const more = $('[data-load-more]');
        if (more) more.hidden = true;

        const result = await SIK.apiRequest('products/list.php', {
            method: 'GET',
            params: params,
            signal: filterController.signal
        });

        // A newer request owns the grid now, and already cancelled this one.
        if (result.aborted) return;
        load.settle();

        if (!result.success) {
            // Never a skeleton left shimmering over a request that has already
            // failed: a dropped connection or a 500 gets the Retry block, with
            // the filters still selected.
            SIK.toast(result.message || 'Could not load products.', 'error');
            Products.showLoadError(grid, extra, pushState);
            // Show more stays hidden: the next page of WHICH result is exactly
            // what the failed request did not tell us.
            return;
        }

        grid.innerHTML = result.data.html || '';
        SIK.skeleton.reveal(grid);
        applyListingMeta(result.data);

        if (pushState !== false && history.pushState) {
            const url = window.location.pathname + (query ? '?' + query : '');
            history.pushState({ filters: params }, '', url);
        }

        SIK.refresh(grid);

        // Scroll the grid back into view after a page change.
        if (extra && extra.page) {
            const top = grid.getBoundingClientRect().top + window.scrollY - 120;
            window.scrollTo({ top: top, behavior: 'smooth' });
        }
    };

    /** Fill the grid with `count` skeleton cards now (kept for other callers). */
    Products.showSkeletons = function (grid, count) {
        const skeletons = Products.skeletonCards(grid, count || expectedCards(grid));
        grid.innerHTML = '';
        grid.appendChild(skeletons);
    };

    /**
     * Recoverable error state for the results grid: the shared Retry block,
     * spanning every column, so a failed request reads as part of the page and
     * offers the way back the toast alone could not.
     */
    Products.showLoadError = function (grid, extra, pushState) {
        SIK.skeleton.error(grid, {
            title: 'Unable to load products',
            text: 'The connection dropped or the server did not answer. Your filters are still selected.',
            retry: function () { Products.applyFilters(extra, pushState); }
        });
    };

    /**
     * "Show more products": the next page, appended under what is already
     * there. The loaded products stay; skeletons for exactly the number of
     * cards the next page holds go on the end at once, so when the cards
     * replace them nothing below the grid moves.
     */
    Products.loadMore = async function (button) {
        const grid = $('[data-product-grid]');
        const state = pagerState();
        if (!grid || !state || state.current >= state.last) return;
        if (grid.getAttribute('aria-busy') === 'true') return;
        if (button && !SIK.showLoader(button)) return;

        clearMoreError();
        const next = state.current + 1;
        const expected = Math.max(1, Math.min(state.perPage, state.total - state.current * state.perPage));
        const skeletons = Array.prototype.slice.call(Products.skeletonCards(grid, expected).children);
        const firstSkeleton = skeletons[0];
        skeletons.forEach(node => grid.appendChild(node));
        grid.setAttribute('aria-busy', 'true');

        moreController = new AbortController();
        const params = Object.assign(Products.collectFilters($('[data-filter-form]')), { page: next });
        const result = await SIK.apiRequest('products/list.php', {
            method: 'GET',
            params: params,
            signal: moreController.signal
        });

        if (button) SIK.hideLoader(button);
        if (result.aborted) return;

        grid.removeAttribute('aria-busy');

        if (!result.success) {
            skeletons.forEach(node => node.remove());
            // One way forward at a time: the Retry below the products, not a
            // Retry and a Show more button asking for the same page.
            const more = $('[data-load-more]');
            if (more) more.hidden = true;
            moreError = SIK.skeleton.error(grid, {
                after: grid,
                title: 'Unable to load more products',
                text: 'The products above are still here. Try again to load the next page.',
                retry: function () { Products.loadMore(button); }
            });
            // Hiding the button the user just pressed hands focus back to
            // <body>: a keyboard or screen-reader user would be dropped at the
            // top of the document and never told a Retry had appeared. Focus
            // follows the way forward instead, exactly as it follows the first
            // new product when the page does arrive.
            const retry = moreError.querySelector('[data-skel-retry]');
            if (retry) retry.focus({ preventScroll: true });
            return;
        }

        const holder = document.createElement('div');
        holder.innerHTML = result.data.html || '';
        const cards = $$('[data-product-card]', holder);

        // Swap in the same task, so the skeletons never paint without their
        // replacements and the grid's height is never in between.
        const anchor = firstSkeleton && firstSkeleton.parentNode === grid ? firstSkeleton : null;
        cards.forEach(card => grid.insertBefore(card, anchor));
        skeletons.forEach(node => node.remove());
        SIK.skeleton.reveal(cards);

        applyListingMeta(result.data, { from: state.from || 1 });
        SIK.refresh(grid);

        // Keyboard and screen-reader users land on the first new product
        // rather than being left on a button that may now be gone.
        const firstLink = cards[0] ? cards[0].querySelector('.sik-card__name a') : null;
        if (firstLink) firstLink.focus({ preventScroll: true });
    };

    /* ======================================================================
       2. Quick view
       ====================================================================== */
    // Which Quick View request is current. A shopper who opens one product,
    // closes it and opens another before the first answer lands must see the
    // second product, not whichever response happens to arrive last.
    let quickSeq = 0;

    function quickViewModal() {
        let modal = $('#sikQuickView');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'sikQuickView';
            modal.className = 'sik-modal';
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-label', 'Quick view');
            modal.innerHTML = '<div class="sik-modal__backdrop"></div><div class="sik-modal__panel">'
                + '<button type="button" class="sik-modal__close" data-close-modal aria-label="Close">'
                + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>'
                + '</button><div id="sikQuickViewBody"></div></div>';
            document.body.appendChild(modal);
        }
        return modal;
    }

    /**
     * Quick View opens on the click, with the product-detail skeleton, and the
     * product replaces it when it arrives. It used to wait with a spinner in
     * the card's button and then pop the finished dialog, which on a slow line
     * read as a click that did nothing. The panel is top-anchored (app.css
     * 30b), so the swap grows it downward and moves nothing already on screen.
     */
    Products.quickView = async function (productId, button) {
        const modal = quickViewModal();
        const body = $('#sikQuickViewBody');
        const seq = ++quickSeq;

        body.innerHTML = '';
        body.appendChild(SIK.skeleton.make('detail', 1));
        body.setAttribute('aria-busy', 'true');

        if (!modal.classList.contains('is-open')) {
            SIK.openModal('sikQuickView');
            trapQuickView(modal, button);
        }

        const result = await SIK.get('products/details.php', { id: productId, view: 'quick' });

        // Superseded, or closed while it loaded: either way nothing to draw.
        if (seq !== quickSeq || !modal.classList.contains('is-open')) return;
        body.removeAttribute('aria-busy');

        if (!result.success) {
            SIK.skeleton.error(body, {
                title: 'Unable to load this product',
                text: result.message || 'The connection dropped or the server did not answer.',
                retry: function () { Products.quickView(productId, button); }
            });
            return;
        }

        body.innerHTML = result.data.html || '';
        SIK.skeleton.reveal(body);
        SIK.refresh(modal);
        Products.initVariants(modal);
        Products.initGallery(modal);
    };

    /**
     * Keep the keyboard inside Quick View while it is open, and put focus back
     * on the card that opened it when it closes.
     *
     * Escape already closes the top layer (app.js), and clicking the backdrop or
     * the X does too - all three routes end with .is-open coming off, so the
     * observer below is the one place that has to restore focus.
     */
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]),'
        + ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function trapQuickView(modal, opener) {
        // Anything still focusable behind the modal would be reachable by Tab.
        const panel = modal.querySelector('.sik-modal__panel') || modal;

        if (!modal._trapBound) {
            modal._trapBound = true;

            modal.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab') return;

                const items = Array.prototype.filter.call(
                    panel.querySelectorAll(FOCUSABLE),
                    el => el.offsetParent !== null || el === document.activeElement
                );
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

            // Closing happens in app.js, which knows nothing about this trigger.
            new MutationObserver(function () {
                if (modal.classList.contains('is-open')) return;
                const back = modal._opener;
                modal._opener = null;
                if (back && document.contains(back)) back.focus();
            }).observe(modal, { attributes: true, attributeFilter: ['class'] });
        }

        modal._opener = opener || null;

        // Land on the close button rather than the first gallery thumbnail, so
        // the first thing a keyboard user meets is the way out.
        const close = modal.querySelector('.sik-modal__close');
        if (close) setTimeout(() => close.focus(), 60);
    }

    /* ======================================================================
       3. Product gallery (thumbnails, zoom, lightbox)
       ====================================================================== */
    Products.initGallery = function (root) {
        const gallery = $('[data-gallery]', root || document);
        if (!gallery || gallery.dataset.galleryBound === '1') return;
        gallery.dataset.galleryBound = '1';

        const main = $('[data-gallery-main]', gallery);
        const image = main ? $('img', main) : null;
        if (!image) return;

        // Thumbnail switching.
        SIK.on('click', '[data-gallery-thumb]', function (e) {
            e.preventDefault();
            $$('[data-gallery-thumb]', gallery).forEach(t => t.classList.remove('is-active'));
            this.classList.add('is-active');
            image.src = this.dataset.galleryThumb;
            image.alt = this.dataset.galleryAlt || image.alt;
            main.classList.remove('is-zoomed');
            image.style.transformOrigin = 'center';
        }, gallery);

        // Inner zoom: follow the pointer while hovering.
        main.addEventListener('mousemove', function (e) {
            if (!main.classList.contains('is-zoomed')) return;
            const rect = main.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            image.style.transformOrigin = x + '% ' + y + '%';
        });

        main.addEventListener('click', function () {
            main.classList.toggle('is-zoomed');
            main.style.cursor = main.classList.contains('is-zoomed') ? 'zoom-out' : 'zoom-in';
        });

        main.addEventListener('mouseleave', function () {
            main.classList.remove('is-zoomed');
            main.style.cursor = 'zoom-in';
        });

        // Swipe between images on touch.
        let startX = 0;
        main.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
        main.addEventListener('touchend', function (e) {
            const dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) < 45) return;
            const thumbs = $$('[data-gallery-thumb]', gallery);
            const current = thumbs.findIndex(t => t.classList.contains('is-active'));
            const next = dx < 0 ? current + 1 : current - 1;
            if (thumbs[next]) thumbs[next].click();
        }, { passive: true });

        // Fullscreen lightbox.
        SIK.on('click', '[data-gallery-expand]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Products.openLightbox(image.src, image.alt);
        }, gallery);
    };

    Products.openLightbox = function (src, alt) {
        let box = $('#sikLightbox');
        if (!box) {
            box = document.createElement('div');
            box.id = 'sikLightbox';
            box.className = 'sik-modal';
            box.innerHTML = '<div class="sik-modal__backdrop"></div>'
                + '<div class="sik-modal__panel" style="max-width:min(94vw,1000px);background:transparent;box-shadow:none">'
                + '<button type="button" class="sik-modal__close" data-close-modal aria-label="Close">'
                + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
                + '<img id="sikLightboxImg" style="width:100%;height:auto;background:#fff;border-radius:14px" alt=""></div>';
            document.body.appendChild(box);
        }
        const img = $('#sikLightboxImg');
        img.src = src;
        img.alt = alt || '';
        SIK.openModal('sikLightbox');
    };

    /* ======================================================================
       4. Variant selection
       ====================================================================== */
    Products.initVariants = function (root) {
        const wrap = $('[data-variants]', root || document);
        if (!wrap || wrap.dataset.variantsBound === '1') return;
        wrap.dataset.variantsBound = '1';

        let variants = [];
        try {
            variants = JSON.parse(wrap.dataset.variants || '[]');
        } catch (e) {
            return;
        }
        if (!variants.length) return;

        const selection = {};

        // Seed the selection from the default variant.
        const initial = variants.find(v => v.is_default === 1 || v.is_default === '1') || variants[0];
        (initial.attributes || []).forEach(a => { selection[a.attribute_id] = a.attribute_value_id; });

        function findVariant() {
            return variants.find(function (variant) {
                return (variant.attributes || []).every(function (a) {
                    return String(selection[a.attribute_id]) === String(a.attribute_value_id);
                });
            }) || null;
        }

        /** Grey out combinations that do not exist. */
        function refreshAvailability() {
            $$('[data-variant-option]', wrap).forEach(function (option) {
                const attributeId = option.dataset.attributeId;
                const valueId = option.dataset.valueId;

                const probe = Object.assign({}, selection);
                probe[attributeId] = valueId;

                const exists = variants.some(function (variant) {
                    return (variant.attributes || []).every(function (a) {
                        return String(probe[a.attribute_id]) === String(a.attribute_value_id);
                    });
                });

                option.classList.toggle('is-disabled', !exists);
                option.disabled = !exists;
                option.classList.toggle('is-active', String(selection[attributeId]) === String(valueId));
            });
        }

        function render() {
            const variant = findVariant();
            refreshAvailability();

            const hidden = $('#sikSelectedVariant');
            if (hidden) hidden.value = variant ? variant.id : '';

            if (!variant) return;

            const priceNode = $('[data-variant-price]');
            if (priceNode) priceNode.textContent = variant.price_display;

            const mrpNode = $('[data-variant-mrp]');
            if (mrpNode) {
                mrpNode.textContent = variant.mrp_display;
                mrpNode.hidden = !(variant.discount > 0);
            }

            const offNode = $('[data-variant-off]');
            if (offNode) {
                offNode.textContent = variant.discount + '% OFF';
                offNode.hidden = !(variant.discount > 0);
            }

            const skuNode = $('[data-variant-sku]');
            if (skuNode) skuNode.textContent = variant.sku;

            const stockNode = $('[data-variant-stock]');
            if (stockNode) {
                stockNode.textContent = variant.stock_label;
                stockNode.className = 'sik-status sik-status--' + (
                    variant.stock_state === 'in_stock' ? 'green' :
                    variant.stock_state === 'low_stock' ? 'amber' : 'red'
                );
            }

            const qty = $('#sikQty');
            if (qty) {
                qty.max = String(Math.max(1, variant.stock));
                if (parseInt(qty.value, 10) > variant.stock) qty.value = String(Math.max(1, variant.stock));
            }

            // Only the buttons wired to THIS picker. The old selector caught
            // every [data-add-cart] on the page, so choosing a variant on a
            // product page also disabled every card in the recommendation rail
            // below it - and, because nothing ever put the label back, they
            // stayed reading "Out of Stock" for the rest of the visit.
            $$('[data-add-cart][data-variant-from], [data-buy-now][data-variant-from]').forEach(function (button) {
                button.disabled = !variant.in_stock;
                button.classList.toggle('is-out', !variant.in_stock);

                if (variant.in_stock) {
                    // Hand the button back to its own state machine so it
                    // returns to "Add to Cart" or "In Cart - n" as appropriate.
                    if (SIK.cart && SIK.cart.button) {
                        SIK.cart.button.settle(button, parseInt(button.dataset.units || '0', 10));
                    }
                } else {
                    const label = $('.sik-atc__label', button) || $('.sik-btn__label', button);
                    if (label) label.textContent = 'Out of stock';
                    button.setAttribute('aria-label',
                        (button.dataset.productName || 'This product') + ' is out of stock in this option');
                }
            });

            // Variant-specific image.
            if (variant.image_url) {
                const mainImg = $('[data-gallery-main] img');
                if (mainImg) mainImg.src = variant.image_url;
            }

            document.dispatchEvent(new CustomEvent('sik:variant', { detail: variant }));
        }

        SIK.on('click', '[data-variant-option]', function (e) {
            e.preventDefault();
            if (this.disabled) return;
            selection[this.dataset.attributeId] = this.dataset.valueId;
            render();
        }, wrap);

        render();
    };

    /* ======================================================================
       5. Pincode / delivery check
       ====================================================================== */
    Products.checkPincode = async function (pincode, resultNode, button) {
        if (!/^[1-9]\d{5}$/.test(String(pincode))) {
            if (resultNode) {
                resultNode.hidden = false;
                resultNode.className = 'sik-alert sik-alert--warning';
                resultNode.textContent = 'Enter a valid 6-digit PIN code.';
            }
            return;
        }

        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.get('delivery/check-pincode.php', {
            pincode: pincode,
            product_id: (button && button.dataset.productId) || ''
        });

        if (button) SIK.hideLoader(button);
        if (!resultNode) return;

        resultNode.hidden = false;

        if (result.success && result.data.serviceable) {
            resultNode.className = 'sik-alert sik-alert--success';
            resultNode.innerHTML = '<div><strong>Delivery available to ' + SIK.escapeHtml(result.data.city || pincode) + '</strong>'
                + '<div style="margin-top:2px">Estimated delivery by <strong>' + SIK.escapeHtml(result.data.delivery_date) + '</strong>'
                + (result.data.cod_available ? ' · Cash on Delivery available' : ' · Prepaid only')
                + '</div></div>';
            try { localStorage.setItem('sik_pincode', pincode); } catch (e) { /* noop */ }
        } else {
            resultNode.className = 'sik-alert sik-alert--error';
            resultNode.textContent = (result.data && result.data.message) || result.message
                || 'We do not deliver to this PIN code yet.';
        }
    };

    /* ======================================================================
       6. Back-in-stock alert
       ====================================================================== */
    Products.notifyMe = async function (form, button) {
        if (button && !SIK.showLoader(button)) return;

        const data = Object.fromEntries(new FormData(form).entries());
        const result = await SIK.post('products/stock-alert.php', data);

        if (button) SIK.hideLoader(button);
        SIK.toastResult(result, 'We will email you when this is back in stock.');
        if (result.success) form.reset();
    };

    /* ======================================================================
       7. Reviews
       ====================================================================== */
    Products.submitReview = async function (form, button) {
        if (button && !SIK.showLoader(button)) return;

        const data = Object.fromEntries(new FormData(form).entries());
        const result = await SIK.post('reviews/create.php', data);

        if (button) SIK.hideLoader(button);

        // Clear previous field errors.
        $$('.sik-error', form).forEach(el => el.remove());
        $$('.is-invalid', form).forEach(el => el.classList.remove('is-invalid'));

        if (result.success) {
            SIK.toast(result.message || 'Thank you! Your review is awaiting approval.', 'success');
            form.reset();
            $$('[data-star-input]', form).forEach(s => s.classList.remove('is-selected'));
        } else {
            if (result.errors) {
                Object.keys(result.errors).forEach(function (field) {
                    const input = form.querySelector('[name="' + field + '"]');
                    if (!input) return;
                    input.classList.add('is-invalid');
                    const error = document.createElement('span');
                    error.className = 'sik-error';
                    error.textContent = result.errors[field];
                    input.parentNode.appendChild(error);
                });
            }
            SIK.toast(result.message || 'Please correct the highlighted fields.', 'error');
        }
    };

    /* ======================================================================
       8. Wiring
       ====================================================================== */
    function bind() {
        // --- filters ---------------------------------------------------------
        SIK.on('change', '[data-filter-form] input, [data-filter-form] select', function () {
            Products.applyFilters();
        });

        SIK.on('submit', '[data-filter-form]', function (e) {
            e.preventDefault();
            Products.applyFilters();
        });

        SIK.on('click', '[data-filter-clear]', function (e) {
            e.preventDefault();
            const form = $('[data-filter-form]');
            if (form) form.reset();

            // product_listing_clear_url() already works out what has to survive
            // a reset — the search query on search.php, the category slug when
            // Apache rewriting is off. Navigating to the bare pathname threw
            // all of that away, so "Clear all filters" on a search for "iphone"
            // landed on the empty search prompt with the query gone.
            const href = this.getAttribute('href');
            window.location.href = href && href !== '#' ? href : window.location.pathname;
        });

        SIK.on('click', '[data-filter-chip-remove]', function (e) {
            e.preventDefault();
            const name = this.dataset.name, value = this.dataset.value;

            // The sidebar and the drawer are two separate forms. The mirror in
            // product-listing.php syncs them on the `change` event, which a
            // programmatic `input.checked = false` never fires — so clearing a
            // chip on a phone updated the grid but left the drawer's checkbox
            // still ticked. Clear the input in every filter form directly.
            const forms = $$('[data-filter-form]');
            if (!forms.length) return;

            forms.forEach(function (form) {
                $$('[name="' + name + '"], [name="' + name + '[]"]', form).forEach(function (input) {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        if (String(input.value) === String(value)) input.checked = false;
                    } else {
                        input.value = '';
                    }
                });
            });
            Products.applyFilters();
        });

        SIK.on('change', '[data-sort]', function () {
            Products.applyFilters({ sort: this.value });
        });

        SIK.on('click', '[data-page]', function (e) {
            e.preventDefault();
            Products.applyFilters({ page: this.dataset.page });
        });

        SIK.on('click', '[data-load-more-btn]', function (e) {
            e.preventDefault();
            Products.loadMore(this);
        });
        // The server renders Show more hidden; it is a scripted control, so
        // it appears only once the script that drives it is running.
        syncListingRange();

        // Grid / list toggle.
        SIK.on('click', '[data-view-mode]', function (e) {
            e.preventDefault();
            const mode = this.dataset.viewMode;
            const grid = $('[data-product-grid]');
            if (!grid) return;

            $$('[data-view-mode]').forEach(b => b.classList.toggle('is-active', b === this));
            grid.classList.toggle('sik-grid', mode === 'grid');
            grid.classList.toggle('sik-list', mode === 'list');
            $$('.sik-card', grid).forEach(card => card.classList.toggle('sik-card--row', mode === 'list'));

            // The API only renders horizontal cards when it is told the view is
            // list, and it reads that from the filter forms. Without this the
            // next filter, sort or page click drops square grid cards into the
            // single-column .sik-list container and every product renders as a
            // full-width square image.
            $$('[data-filter-form]').forEach(function (form) {
                let field = form.querySelector('input[name="view"]');
                if (!field) {
                    field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'view';
                    form.appendChild(field);
                }
                field.value = mode;
            });

            try { localStorage.setItem('sik_view_mode', mode); } catch (err) { /* noop */ }
        });

        // Restore the saved grid/list preference.
        try {
            const saved = localStorage.getItem('sik_view_mode');
            if (saved === 'list') {
                const button = $('[data-view-mode="list"]');
                if (button) button.click();
            }
        } catch (e) { /* noop */ }

        // Browser back/forward through filter states.
        window.addEventListener('popstate', function () {
            if ($('[data-product-grid]')) window.location.reload();
        });

        // --- quick view -------------------------------------------------------
        SIK.on('click', '[data-quickview]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Products.quickView(this.dataset.productId, this);
        });

        // --- pincode ----------------------------------------------------------
        SIK.on('submit', '[data-pincode-form]', function (e) {
            e.preventDefault();
            const input = this.querySelector('input[name="pincode"]');
            Products.checkPincode(
                input ? input.value.trim() : '',
                this.parentElement.querySelector('[data-pincode-result]'),
                this.querySelector('button[type="submit"]')
            );
        });

        // Pre-fill the last pincode the visitor checked.
        try {
            const saved = localStorage.getItem('sik_pincode');
            const field = $('[data-pincode-form] input[name="pincode"]');
            if (saved && field && !field.value) field.value = saved;
        } catch (e) { /* noop */ }

        // --- stock alert ------------------------------------------------------
        SIK.on('submit', '[data-stock-alert-form]', function (e) {
            e.preventDefault();
            Products.notifyMe(this, this.querySelector('button[type="submit"]'));
        });

        // --- reviews ----------------------------------------------------------
        SIK.on('submit', '[data-review-form]', function (e) {
            e.preventDefault();
            Products.submitReview(this, this.querySelector('button[type="submit"]'));
        });

        SIK.on('click', '[data-star-input]', function (e) {
            e.preventDefault();
            const rating = parseInt(this.dataset.starInput, 10);
            const wrap = this.closest('[data-star-group]');
            if (!wrap) return;

            $$('[data-star-input]', wrap).forEach(function (star) {
                star.classList.toggle('is-selected', parseInt(star.dataset.starInput, 10) <= rating);
            });
            const hidden = wrap.querySelector('input[name="rating"]');
            if (hidden) hidden.value = String(rating);
        });

        SIK.on('change', '[data-review-sort]', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('review_sort', this.value);
            url.hash = 'reviews';
            window.location.href = url.toString();
        });

        // --- mobile filter drawer --------------------------------------------
        SIK.on('click', '[data-open-filters]', function (e) {
            e.preventDefault();
            SIK.openDrawer('sikFilterDrawer');
        });

        Products.initGallery();
        Products.initVariants();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    document.addEventListener('sik:refresh', function (e) {
        Products.initGallery(e.detail.root);
        Products.initVariants(e.detail.root);
    });

    SIK.products = Products;
})();
