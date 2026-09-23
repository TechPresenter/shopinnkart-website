/* ==========================================================================
   ShopInnKart - Cart, mini cart, coupons and the Add to Cart button.

   The button markup is rendered once, by add_to_cart_button() in
   includes/widgets.php, and driven from here. Nothing on the storefront may
   hand-roll a second add-to-cart control: give this file a `.sik-atc` with a
   [data-add-cart] hook and every state below comes for free.

   Server is authoritative. The browser sends ids and a quantity; price, stock,
   caps, coupon and every total come back from /api/cart/*.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Cart = {};

    /** How long the green "Added" tick stays before the button settles. */
    const SUCCESS_MS = 1500;

    /* ======================================================================
       1. The Add to Cart button state machine

       default -> loading -> success -> in cart
                        \-> (error) -> default

       Copy is never invented here: every label is read off the data-label-*
       attributes the PHP renderer printed, so wording stays in one place and
       an admin-configured deal label survives a click.
       ====================================================================== */
    const Button = {};

    Button.label = function (button, text) {
        if (!button || text === undefined || text === null) return;
        const node = button.querySelector('.sik-atc__label') || button.querySelector('.sik-btn__label');
        if (node) node.textContent = text;
    };

    /** Clear a pending "settle" so a fast second click cannot fight it. */
    Button.clearTimer = function (button) {
        if (button && button._atcTimer) {
            clearTimeout(button._atcTimer);
            button._atcTimer = null;
        }
    };

    /**
     * Enter the in-flight state. Returns false when the button is already busy,
     * which is what makes a double click a no-op rather than two cart lines.
     * SIK.showLoader() owns the guard and the disabled flag; it only injects a
     * spinner when the button has none, and .sik-atc ships with one.
     */
    Button.start = function (button) {
        if (!button) return true;
        if (!SIK.showLoader(button)) return false;

        Button.clearTimer(button);
        button.classList.remove('is-success');
        button.setAttribute('aria-busy', 'true');
        Button.label(button, button.dataset.labelLoading || 'Working...');
        return true;
    };

    /** Success: tick, brief label change, then settle into the "in cart" state. */
    Button.succeed = function (button, units) {
        if (!button) return;
        SIK.hideLoader(button);
        button.removeAttribute('aria-busy');
        button.classList.add('is-success');
        Button.label(button, button.dataset.labelAdded || 'Added');

        Button.clearTimer(button);
        button._atcTimer = setTimeout(function () {
            button.classList.remove('is-success');
            Button.settle(button, units);
        }, SUCCESS_MS);
    };

    /**
     * The resting state once the cart knows about this product. Showing the
     * count is the whole point: a second click has to look deliberate.
     */
    Button.settle = function (button, units) {
        if (!button || button.dataset.loading === '1' || button.classList.contains('is-success')) return;

        const name = button.dataset.productName || 'this product';
        // Only an actual "add to cart" has an in-cart resting state. Buy Now
        // still goes to checkout however full the cart already is, and "Move to
        // Cart" belongs to the wishlist item, not to the cart's contents.
        const count = button.hasAttribute('data-add-cart') ? (Number(units) || 0) : 0;

        if (count > 0) {
            button.classList.add('is-incart');
            Button.label(button, (button.dataset.labelIncart || 'In cart') + ' - ' + count);
            button.setAttribute('aria-label', 'Add another ' + name + ' to cart');
        } else {
            button.classList.remove('is-incart');
            Button.label(button, button.dataset.label || 'Add to cart');
            button.setAttribute('aria-label', (button.dataset.label || 'Add to cart') + ': ' + name);
        }
    };

    /** Failure: hand the button straight back, the toast carries the reason. */
    Button.fail = function (button) {
        if (!button) return;
        SIK.hideLoader(button);
        button.removeAttribute('aria-busy');
        button.classList.remove('is-success');
        Button.settle(button, button.classList.contains('is-incart') ? Number(button.dataset.units || 1) : 0);
    };

    Cart.button = Button;

    /* ======================================================================
       2. Badge, totals and cross-surface button sync
       ====================================================================== */
    Cart.setCount = function (count) {
        SIK.setBadge('[data-cart-count]', 'Cart', count);
    };

    Cart.renderTotals = function (totals) {
        if (!totals || !totals.display) return;

        const map = {
            subtotal: totals.display.subtotal,
            discount: '- ' + totals.display.discount,
            shipping: totals.display.shipping,
            tax: totals.display.tax,
            total: totals.display.total,
            saving: totals.display.saving
        };
        Object.keys(map).forEach(function (key) {
            $$('[data-total="' + key + '"]').forEach(el => { el.textContent = map[key]; });
        });

        // Rows that only appear when they have a value.
        $$('[data-total-row="discount"]').forEach(el => { el.hidden = !(totals.discount > 0); });
        $$('[data-total-row="saving"]').forEach(el => { el.hidden = !(totals.total_saving > 0); });
        $$('[data-total-row="tax"]').forEach(el => { el.hidden = !(totals.tax > 0); });

        // "Subtotal (3 items)" has to move with the numbers beside it, or the
        // panel starts contradicting itself after a quantity change.
        const units = Number(totals.units) || 0;
        $$('[data-cart-units]').forEach(function (el) {
            el.textContent = units + ' item' + (units === 1 ? '' : 's');
        });

        $$('[data-coupon-badge]').forEach(function (el) {
            el.textContent = totals.coupon_code || '';
            el.hidden = !totals.coupon_code;
        });

        // The drawer shows the same grand total as the page; it used to update
        // only when the item list was re-rendered, so a coupon left it stale.
        $$('#sikCartDrawerTotal').forEach(el => { el.textContent = totals.display.total; });

        // Free-shipping meter. free_ship_remaining is already 0 when delivery
        // is free for any reason, so this needs no rule of its own.
        $$('[data-ship-progress]').forEach(el => { el.style.width = totals.free_ship_progress + '%'; });
        $$('[data-ship-remaining]').forEach(el => { el.textContent = totals.display.remaining; });
        $$('[data-ship-bar]').forEach(el => { el.hidden = totals.free_ship_remaining <= 0; });
        $$('[data-ship-done]').forEach(el => { el.hidden = totals.free_ship_remaining > 0; });

        document.dispatchEvent(new CustomEvent('sik:totals', { detail: totals }));
    };

    /** product_id => units, folded across variants of the same product. */
    function unitsByProduct(items) {
        const map = {};
        (items || []).forEach(function (item) {
            map[item.product_id] = (map[item.product_id] || 0) + (Number(item.quantity) || 0);
        });
        return map;
    }

    /**
     * Push the cart's contents onto every add-to-cart button on the page, so a
     * product added from a rail also reads "In Cart" in the grid behind it.
     */
    Cart.syncButtons = function (items) {
        const units = unitsByProduct(items);
        $$('.sik-atc[data-add-cart]').forEach(function (button) {
            const count = units[button.dataset.productId] || 0;
            button.dataset.units = String(count);
            Button.settle(button, count);
        });
    };

    /** Pull the authoritative cart state from the server. */
    Cart.sync = async function (renderDrawer) {
        const result = await SIK.get('cart/get.php');
        if (!result.success) return result;

        Cart.setCount(result.data.count || 0);
        Cart.renderTotals(result.data.totals);
        Cart.syncButtons(result.data.items);

        if (renderDrawer !== false) Cart.renderMini(result.data);
        return result;
    };

    /**
     * Apply one API payload to every cart surface at once.
     *
     * @param {boolean} [renderDrawer] Pass false to leave the drawer's DOM
     *        alone. Re-rendering it destroys whatever is focused inside it, so
     *        a quantity edit made *in* the drawer must not rebuild it - the
     *        caret would be thrown back to the top of the document mid-edit.
     */
    function applyPayload(data, renderDrawer) {
        if (!data) return;
        Cart.setCount(data.count || 0);
        Cart.renderTotals(data.totals);
        if (renderDrawer !== false) Cart.renderMini(data);
        Cart.syncButtons(data.items);
    }
    Cart.apply = applyPayload;

    /* ======================================================================
       3. Add
       ====================================================================== */
    Cart.add = async function (productId, variantId, quantity, button) {
        if (!Button.start(button)) return { success: false, busy: true };

        const result = await SIK.post('cart/add.php', {
            product_id: parseInt(productId, 10),
            variant_id: variantId ? parseInt(variantId, 10) : null,
            quantity: parseInt(quantity || 1, 10)
        });

        if (result.success) {
            applyPayload(result.data);

            const units = unitsByProduct(result.data.items)[parseInt(productId, 10)] || 0;
            if (button) {
                button.dataset.units = String(units);
                Button.succeed(button, units);
            }

            SIK.toast(result.message || 'Added to cart', 'success', {
                action: { label: 'View cart', href: SIK.config.baseUrl + '/cart.php' }
            });

            if (result.data.open_drawer !== false) SIK.openDrawer('sikCartDrawer');
        } else {
            Button.fail(button);
            SIK.toast(result.message || 'Could not add to cart.', 'error');
        }
        return result;
    };

    /* ======================================================================
       4. Update / remove
       ====================================================================== */
    /** Every copy of a line: the cart page and the open drawer can both hold one. */
    function lineNodes(itemId) {
        return $$('[data-cart-row="' + itemId + '"]');
    }

    function lineBusy(itemId, busy) {
        lineNodes(itemId).forEach(function (row) {
            row.classList.toggle('sik-loading', !!busy);
            row.setAttribute('aria-busy', busy ? 'true' : 'false');
        });
    }

    Cart.update = async function (itemId, quantity) {
        lineBusy(itemId, true);

        const result = await SIK.post('cart/update.php', {
            item_id: parseInt(itemId, 10),
            quantity: parseInt(quantity, 10)
        });

        lineBusy(itemId, false);

        if (result.success) {
            // Rebuild the drawer only when the line actually went away; a plain
            // quantity change is patched in place so focus survives it.
            const survived = (result.data.items || []).some(i => String(i.item_id) === String(itemId));
            applyPayload(result.data, !survived);
            Cart.repriceLine(itemId, result.data);

            if (!survived) {
                Cart.dropLine(itemId, result.data);
            }
            // cart_update() clamps to live stock, so the reply can differ from
            // what was asked for. Only say something when it did.
            if (result.message && result.message !== 'Cart updated.') {
                SIK.toast(result.message, 'info');
            }
        } else {
            SIK.toast(result.message || 'Could not update the cart.', 'error');
            // Put the row back in sync with the server rather than leaving the
            // input showing a number the cart never accepted.
            Cart.sync();
        }
        return result;
    };

    Cart.remove = async function (itemId, button) {
        if (button && !SIK.showLoader(button)) return { success: false, busy: true };
        lineBusy(itemId, true);

        const result = await SIK.post('cart/remove.php', { item_id: parseInt(itemId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            applyPayload(result.data);
            Cart.dropLine(itemId, result.data);
            SIK.toast('Item removed', 'success');
        } else {
            lineBusy(itemId, false);
            SIK.toast(result.message || 'Could not remove the item.', 'error');
        }
        return result;
    };

    /** Fade a removed row out of the cart page and the drawer. */
    Cart.dropLine = function (itemId, data) {
        const rows = lineNodes(itemId);
        if (!rows.length) return;

        rows.forEach(function (row) {
            row.style.transition = 'opacity var(--dur-base) var(--ease-out), transform var(--dur-base) var(--ease-out)';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-14px)';
            setTimeout(function () { row.remove(); }, 240);
        });

        // The empty cart page is a different layout, not a shorter list: the
        // summary, coupon panel and checkout button all go away with it. Let the
        // server render that state rather than keeping a second copy of the
        // empty-state markup here. The drawer needs no reload - renderMini()
        // already draws its own empty state.
        if (data && data.count === 0 && $('[data-cart-page]')) {
            setTimeout(function () { window.location.reload(); }, 260);
        }
    };

    /** Refresh one row's line total after a quantity change. */
    Cart.repriceLine = function (itemId, data) {
        if (!data || !data.items) return;
        const item = data.items.find(i => String(i.item_id) === String(itemId));
        if (!item) return;

        lineNodes(itemId).forEach(function (row) {
            const subtotal = row.querySelector('[data-line-subtotal]');
            if (subtotal) subtotal.textContent = item.subtotal_display;

            const input = row.querySelector('input[data-cart-qty]');
            if (input && String(input.value) !== String(item.quantity)) input.value = item.quantity;
        });
    };

    /* ======================================================================
       5. Mini cart drawer
       ====================================================================== */
    // Has the drawer ever held real lines? Until it has, every call paints the
    // skeleton first.
    let miniRendered = false;

    /**
     * Fill the drawer from the server. The drawer arrives from footer.php
     * empty, and the bones go in here rather than there: rendered in the page
     * they were two shimmer animations running inside a closed drawer on every
     * page view, for something most visits never open. Once it has held real
     * lines those stay on screen while this refreshes them, so a re-open never
     * flashes back to bones.
     *
     * If the very first fetch fails there is nothing real to fall back on, so
     * the skeleton gives way to a Retry rather than shimmering over a request
     * that is over.
     */
    Cart.loadMini = async function () {
        const body = $('#sikCartDrawerBody');
        if (!body) return null;

        if (!miniRendered) {
            body.innerHTML = '';
            body.appendChild(SIK.skeleton.make('cartline', 2));
            body.setAttribute('aria-busy', 'true');
        }

        const result = await Cart.sync();
        if (result.success || miniRendered) return result;

        body.removeAttribute('aria-busy');
        SIK.skeleton.error(body, {
            title: 'Unable to load your cart',
            text: result.message || 'The connection dropped or the server did not answer.',
            // loadMini() puts the bones back itself, because nothing real has
            // been in the drawer yet.
            retry: function () { Cart.loadMini(); }
        });
        return result;
    };

    Cart.renderMini = function (data) {
        const body = $('#sikCartDrawerBody');
        const foot = $('#sikCartDrawerFoot');
        if (!body) return;

        miniRendered = true;
        body.removeAttribute('aria-busy');

        const items = (data && data.items) || [];
        const totals = (data && data.totals) || {};
        const esc = SIK.escapeHtml;

        if (!items.length) {
            body.innerHTML = '<div class="sik-empty">'
                + '<img src="' + SIK.config.baseUrl + '/assets/images/placeholders/empty-cart.svg" alt=""'
                + ' width="160" height="120" loading="lazy">'
                + '<h3 class="sik-empty__title">Your cart is empty</h3>'
                + '<p class="sik-empty__text">Find something to light up your space.</p>'
                + '<a class="sik-btn sik-btn--primary" href="' + SIK.config.baseUrl + '/shop.php">Start shopping</a>'
                + '</div>';
            if (foot) foot.hidden = true;
            return;
        }

        if (foot) foot.hidden = false;

        let html = '';

        if (totals.display && totals.free_ship_remaining > 0) {
            html += '<div class="sik-shipbar" data-ship-bar>'
                + 'Add <strong data-ship-remaining>' + esc(totals.display.remaining) + '</strong>'
                + ' more for free delivery'
                + '<div class="sik-progress" style="margin-top:var(--sp-2)">'
                + '<span data-ship-progress style="width:' + (totals.free_ship_progress || 0) + '%"></span>'
                + '</div></div>';
        } else if (totals.display) {
            html += '<div class="sik-shipbar" data-ship-done><strong>Free delivery</strong> unlocked on this order</div>';
        }

        items.forEach(function (item) {
            const out = !item.in_stock || item.quantity <= 0;
            html += '<div class="sik-minicart__item' + (out ? ' is-out' : '') + '" data-cart-row="' + item.item_id + '">'
                + '<a href="' + esc(item.url) + '" tabindex="-1" aria-hidden="true">'
                + '<img class="sik-minicart__img" src="' + esc(item.image_url) + '" alt=""'
                + ' width="60" height="60" loading="lazy"></a>'
                + '<div style="flex:1;min-width:0">'
                + '<a href="' + esc(item.url) + '" class="sik-minicart__name">' + esc(item.name) + '</a>'
                + (item.variant_name ? '<div class="sik-minicart__variant">' + esc(item.variant_name) + '</div>' : '')
                + (out ? '<div class="sik-minicart__variant" style="color:var(--sik-danger-ink);font-weight:600">Out of stock</div>' : '')
                + '<div class="sik-minicart__row">';

            if (out) {
                html += '<span class="sik-caption">Remove to continue</span>';
            } else {
                html += '<div class="sik-qty">'
                    + '<button type="button" data-qty-minus aria-label="Decrease quantity of ' + esc(item.name) + '">&minus;</button>'
                    + '<input type="number" data-cart-qty data-item-id="' + item.item_id + '"'
                    + ' value="' + item.quantity + '" min="1" max="' + item.max_qty + '" step="1" inputmode="numeric"'
                    + ' aria-label="Quantity of ' + esc(item.name) + '">'
                    + '<button type="button" data-qty-plus aria-label="Increase quantity of ' + esc(item.name) + '">+</button>'
                    + '</div>';
            }

            html += '<span class="sik-price sik-num" style="font-size:var(--fs-base)" data-line-subtotal>'
                + esc(item.subtotal_display) + '</span>'
                + '</div></div>'
                + '<button type="button" class="sik-minicart__remove" data-cart-remove data-item-id="' + item.item_id + '"'
                + ' aria-label="Remove ' + esc(item.name) + ' from cart">'
                + '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"'
                + ' stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
                + '</button>'
                + '</div>';
        });

        body.innerHTML = html;
        SIK.images.watch(body);

        const totalNode = $('#sikCartDrawerTotal');
        if (totalNode && totals.display) totalNode.textContent = totals.display.total;
    };

    /* ======================================================================
       6. Coupons

       Applying used to reload the page. It does not have to: the API returns
       the recalculated cart, and the panel already ships both of its states, so
       the swap is a class toggle rather than a second copy of the markup.
       ====================================================================== */
    function couponPanel(applied, totals) {
        const panel = $('[data-coupon-panel]');
        if (!panel) return;

        const appliedBox = panel.querySelector('[data-coupon-applied]');
        const form = panel.querySelector('[data-coupon-form]');
        const offers = panel.querySelector('[data-coupon-offers]');

        if (appliedBox) {
            appliedBox.hidden = !applied;
            const code = appliedBox.querySelector('[data-coupon-code]');
            const saving = appliedBox.querySelector('[data-coupon-saving]');
            if (code && totals) code.textContent = totals.coupon_code || '';
            if (saving && totals && totals.display) saving.textContent = totals.display.discount;
        }
        if (form) {
            form.hidden = !!applied;
            const input = form.querySelector('input[name="code"]');
            if (input && !applied) input.value = '';
        }
        // The cart carries one coupon at a time, so the offer list has nothing
        // to offer while one is on.
        if (offers) offers.hidden = !!applied;
    }

    Cart.applyCoupon = async function (code, button) {
        code = (code || '').trim();
        if (!code) {
            SIK.toast('Enter a coupon code.', 'warning');
            return;
        }
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('coupons/validate.php', { code: code, apply: true });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            applyPayload(result.data);
            couponPanel(true, result.data.totals);
            SIK.toast(result.message || 'Coupon applied', 'success');
        } else {
            SIK.toast(result.message || 'That coupon is not valid.', 'error');
        }
        return result;
    };

    Cart.removeCoupon = async function (button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('coupons/remove.php', {});

        if (button) SIK.hideLoader(button);

        if (result.success) {
            applyPayload(result.data);
            couponPanel(false, result.data.totals);
            SIK.toast('Coupon removed', 'success');
        } else {
            SIK.toast(result.message || 'Could not remove the coupon.', 'error');
        }
        return result;
    };

    /* ======================================================================
       7. Event wiring
       ====================================================================== */
    /** Read the quantity / variant a button was told to source. */
    function readSource(button, key, fallback) {
        const selector = button.dataset[key];
        if (!selector) return fallback;
        const node = document.querySelector(selector);
        return node ? node.value : fallback;
    }

    function bind() {
        SIK.on('click', '[data-add-cart]', function (e) {
            e.preventDefault();
            Cart.add(
                this.dataset.productId,
                readSource(this, 'variantFrom', this.dataset.variantId),
                readSource(this, 'qtyFrom', this.dataset.qty || 1),
                this
            );
        });

        SIK.on('click', '[data-buy-now]', async function (e) {
            e.preventDefault();
            const result = await Cart.add(
                this.dataset.productId,
                readSource(this, 'variantFrom', this.dataset.variantId),
                readSource(this, 'qtyFrom', this.dataset.qty || 1),
                this
            );
            if (result && result.success) {
                window.location.href = SIK.config.baseUrl + '/checkout.php';
            }
        });

        // Quantity change on the cart page and in the drawer. The steppers in
        // app.js dispatch this same event, so one handler covers both.
        SIK.on('change', 'input[data-cart-qty]', function () {
            Cart.update(this.dataset.itemId, this.value);
        });

        SIK.on('click', '[data-cart-remove]', function (e) {
            e.preventDefault();
            Cart.remove(this.dataset.itemId, this);
        });

        SIK.on('submit', '[data-coupon-form]', function (e) {
            e.preventDefault();
            const input = this.querySelector('input[name="code"]');
            Cart.applyCoupon(input ? input.value : '', this.querySelector('button[type="submit"]'));
        });

        SIK.on('click', '[data-coupon-remove]', function (e) {
            e.preventDefault();
            Cart.removeCoupon(this);
        });

        SIK.on('click', '[data-coupon-apply]', function (e) {
            e.preventDefault();
            const field = document.querySelector('[data-coupon-form] input[name="code"]');
            if (field) field.value = this.dataset.couponApply;
            Cart.applyCoupon(this.dataset.couponApply, this);
        });

        // Opening the drawer refreshes it from the server.
        SIK.on('click', '[data-open-cart]', function (e) {
            e.preventDefault();
            SIK.openDrawer('sikCartDrawer');
            Cart.loadMini();
        });
        // The phone's bottom-bar Cart opens the same drawer through the
        // generic [data-open-drawer] handler in app.js, which knows nothing
        // about carts - so it used to open onto the skeleton lines and leave
        // them shimmering for good. It only needs the fetch; app.js has
        // already opened the drawer.
        SIK.on('click', '[data-open-drawer="sikCartDrawer"]', function () {
            Cart.loadMini();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    SIK.cart = Cart;
})();
