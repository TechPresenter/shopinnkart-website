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

    Products.applyFilters = async function (extra, pushState) {
        const form = $('[data-filter-form]');
        const grid = $('[data-product-grid]');
        if (!grid) return;

        const params = Object.assign(Products.collectFilters(form), extra || {});
        // A new filter selection always returns to page 1.
        if (!extra || !extra.page) delete params.page;

        const query = toQuery(params);

        grid.classList.add('sik-loading');
        Products.showSkeletons(grid);

        if (filterController) filterController.abort();
        filterController = new AbortController();

        const result = await SIK.apiRequest('products/list.php', {
            method: 'GET',
            params: params,
            signal: filterController.signal
        });

        if (result.aborted) return;

        grid.classList.remove('sik-loading');

        if (!result.success) {
            // The grid is full of skeletons at this point. Returning here used
            // to leave them shimmering forever, so a dropped connection or a
            // 500 turned the results column into a permanent loading state with
            // no way back. Replace them with a recoverable error instead.
            SIK.toast(result.message || 'Could not load products.', 'error');
            Products.showLoadError(grid, extra, pushState);
            return;
        }

        grid.innerHTML = result.data.html || '';

        const countNode = $('[data-result-count]');
        if (countNode) countNode.textContent = result.data.pagination.total;

        const pager = $('[data-pagination]');
        if (pager) pager.innerHTML = result.data.pagination_html || '';

        const chips = $('[data-active-filters]');
        if (chips) chips.innerHTML = result.data.chips_html || '';

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

    Products.showSkeletons = function (grid) {
        const count = Math.min(8, Math.max(4, grid.children.length || 8));
        let html = '';
        for (let i = 0; i < count; i++) {
            html += '<div><div class="sik-skeleton sik-skeleton--card"></div>'
                + '<div class="sik-skeleton sik-skeleton--text" style="width:45%;margin-top:12px"></div>'
                + '<div class="sik-skeleton sik-skeleton--text" style="width:85%"></div>'
                + '<div class="sik-skeleton sik-skeleton--text" style="width:35%"></div></div>';
        }
        grid.innerHTML = html;
    };

    /**
     * Recoverable error state for the results grid.
     *
     * Matches the .sik-empty structure product-listing.php renders for "no
     * products match", so a failed request looks like part of the page rather
     * than a broken one, and offers the retry the toast alone could not.
     */
    Products.showLoadError = function (grid, extra, pushState) {
        grid.innerHTML =
            '<div class="sik-empty" style="grid-column:1/-1">'
            + '<p class="sik-empty__title">We could not load these products</p>'
            + '<p class="sik-empty__text">The connection dropped or the server did not answer. '
            + 'Your filters are still selected &mdash; try again.</p>'
            + '<button type="button" class="sik-btn sik-btn--primary" data-filter-retry>Try again</button>'
            + '</div>';

        const retry = grid.querySelector('[data-filter-retry]');
        if (!retry) return;

        retry.addEventListener('click', function () {
            retry.disabled = true;
            retry.textContent = 'Retrying…';
            Products.applyFilters(extra, pushState);
        }, { once: true });
    };

    /* ======================================================================
       2. Quick view
       ====================================================================== */
    Products.quickView = async function (productId, button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.get('products/details.php', { id: productId, view: 'quick' });

        if (button) SIK.hideLoader(button);

        if (!result.success) {
            SIK.toast(result.message || 'Could not load the product.', 'error');
            return;
        }

        let modal = $('#sikQuickView');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'sikQuickView';
            modal.className = 'sik-modal';
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = '<div class="sik-modal__backdrop"></div><div class="sik-modal__panel">'
                + '<button type="button" class="sik-modal__close" data-close-modal aria-label="Close">'
                + '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>'
                + '</button><div id="sikQuickViewBody"></div></div>';
            document.body.appendChild(modal);
        }

        $('#sikQuickViewBody').innerHTML = result.data.html || '';
        SIK.openModal('sikQuickView');
        trapQuickView(modal, button);
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
