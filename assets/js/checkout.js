/* ==========================================================================
   ShopInnKart - Checkout
   Address selection, delivery and payment method changes, live totals and
   guarded order submission.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Checkout = {};
    let submitting = false;

    /* ----------------------------------------------------------------------
       Live totals - the server is always the authority
       ---------------------------------------------------------------------- */
    let totalsSeq = 0;
    // The pending "paint the shimmer" timer, whoever armed it. The shimmer is
    // page state, not per-request state, so exactly one timer may ever be in
    // flight: a newer request replaces the older one's before it can fire.
    // Without that, a request superseded while its own timer was still pending
    // painted `is-recalc` AFTER the winner had cleaned up, and -
    // `-webkit-text-fill-color: transparent` being what that class does - all
    // nine figures stayed blank and shimmering for good, next to a Place order
    // button that was enabled again.
    let totalsTimer = null;

    /** Every figure on the page, not the list captured when a request started. */
    function showRecalc(on) {
        $$('[data-total]').forEach(el => el.classList.toggle('is-recalc', on));
    }

    Checkout.refreshTotals = async function () {
        const form = $('#sikCheckoutForm');
        if (!form) return;

        const shipping = form.querySelector('input[name="shipping_method"]:checked');
        const payment = form.querySelector('input[name="payment_method"]:checked');

        // Only the figures are being recalculated, so only the figures turn
        // into skeletons - same width, same line, nothing in the summary moves
        // (app.css 30b). The summary still refuses clicks meanwhile, so an
        // order can never be placed against a total that is about to change.
        const seq = ++totalsSeq;
        const summary = $('[data-order-summary]');
        if (summary) {
            summary.classList.add('sik-recalc');
            summary.setAttribute('aria-busy', 'true');
        }
        clearTimeout(totalsTimer);
        totalsTimer = setTimeout(function () {
            totalsTimer = null;
            showRecalc(true);
        }, 120);

        const result = await SIK.get('checkout/totals.php', {
            shipping_method: shipping ? shipping.value : 'standard',
            payment_method: payment ? payment.value : 'cod'
        });

        // A later change already asked again; its answer is the one to show,
        // and it owns every bit of the shared state - including the timer this
        // one armed, which that later request already replaced. So there is
        // nothing here to undo, and nothing this one can leave behind.
        if (seq !== totalsSeq) return;

        clearTimeout(totalsTimer);
        totalsTimer = null;
        showRecalc(false);
        if (summary) {
            summary.classList.remove('sik-recalc');
            summary.removeAttribute('aria-busy');
        }

        if (result.success && SIK.cart) {
            SIK.cart.renderTotals(result.data.totals);

            const charge = $('[data-total-row="payment_charge"]');
            if (charge) {
                charge.hidden = !(result.data.totals.payment_charge > 0);
                const value = charge.querySelector('[data-total="payment_charge"]');
                if (value) value.textContent = SIK.formatCurrency(result.data.totals.payment_charge);
            }

            const discountRow = $('[data-total-row="payment_discount"]');
            if (discountRow) {
                discountRow.hidden = !(result.data.totals.payment_discount > 0);
                const value = discountRow.querySelector('[data-total="payment_discount"]');
                if (value) value.textContent = '- ' + SIK.formatCurrency(result.data.totals.payment_discount);
            }
        }
    };

    /* ----------------------------------------------------------------------
       Saved addresses
       ---------------------------------------------------------------------- */
    Checkout.selectAddress = function (card) {
        $$('[data-address-card]').forEach(c => c.classList.remove('is-selected'));
        card.classList.add('is-selected');

        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;

        // Copy the saved address into the hidden form fields the API reads.
        const map = {
            shipping_name: 'name',
            shipping_phone: 'phone',
            shipping_address: 'address',
            shipping_address2: 'address2',
            shipping_landmark: 'landmark',
            shipping_city: 'city',
            shipping_state: 'state',
            shipping_pincode: 'pincode',
            shipping_country: 'country'
        };
        Object.keys(map).forEach(function (fieldName) {
            const input = document.querySelector('[name="' + fieldName + '"]');
            const value = card.dataset[map[fieldName]];
            if (input && value !== undefined) input.value = value;
        });

        const newForm = $('#sikNewAddress');
        if (newForm) newForm.hidden = true;

        Checkout.refreshTotals();
    };

    Checkout.showNewAddress = function () {
        $$('[data-address-card]').forEach(c => c.classList.remove('is-selected'));
        const newForm = $('#sikNewAddress');
        if (newForm) {
            newForm.hidden = false;
            const first = newForm.querySelector('input:not([type=hidden])');
            if (first) first.focus();
        }
    };

    /* ----------------------------------------------------------------------
       Client-side pre-validation
       Server-side validation still runs; this is only to save a round trip.
       ---------------------------------------------------------------------- */
    Checkout.validate = function (form) {
        const errors = {};
        const value = name => {
            const field = form.querySelector('[name="' + name + '"]');
            return field ? String(field.value || '').trim() : '';
        };

        if (!value('customer_name')) errors.customer_name = 'Enter your full name.';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value('customer_email'))) errors.customer_email = 'Enter a valid email address.';
        if (!/^[6-9]\d{9}$/.test(value('customer_phone').replace(/\D/g, '').replace(/^(0|91)/, ''))) {
            errors.customer_phone = 'Enter a valid 10-digit mobile number.';
        }
        if (!value('shipping_address')) errors.shipping_address = 'Enter your address.';
        if (!value('shipping_city')) errors.shipping_city = 'Enter your city.';
        if (!value('shipping_state')) errors.shipping_state = 'Select your state.';
        if (!/^[1-9]\d{5}$/.test(value('shipping_pincode'))) errors.shipping_pincode = 'Enter a valid 6-digit PIN code.';

        if (!form.querySelector('input[name="payment_method"]:checked')) {
            errors.payment_method = 'Choose a payment method.';
        }
        return errors;
    };

    Checkout.showErrors = function (form, errors) {
        $$('.sik-error', form).forEach(el => el.remove());
        $$('.is-invalid', form).forEach(el => el.classList.remove('is-invalid'));

        let firstField = null;
        Object.keys(errors).forEach(function (name) {
            const input = form.querySelector('[name="' + name + '"]');
            if (!input) return;
            input.classList.add('is-invalid');
            const error = document.createElement('span');
            error.className = 'sik-error';
            error.textContent = errors[name];
            (input.parentNode || form).appendChild(error);
            if (!firstField) firstField = input;
        });

        if (firstField) {
            firstField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstField.focus({ preventScroll: true });
        }
    };

    /* ----------------------------------------------------------------------
       Place order
       ---------------------------------------------------------------------- */
    Checkout.placeOrder = async function (form, button) {
        if (submitting) return;

        const errors = Checkout.validate(form);
        if (Object.keys(errors).length) {
            Checkout.showErrors(form, errors);
            SIK.toast('Please correct the highlighted fields.', 'error');
            return;
        }

        submitting = true;
        SIK.showLoader(button);

        const payload = {};
        new FormData(form).forEach(function (value, key) {
            payload[key] = value;
        });
        payload.billing_same = !!form.querySelector('[name="billing_same"]:checked');
        payload.save_address = !!form.querySelector('[name="save_address"]:checked');

        const result = await SIK.post('checkout/process.php', payload);

        if (!result.success) {
            submitting = false;
            SIK.hideLoader(button);

            if (result.errors && Object.keys(result.errors).length) {
                Checkout.showErrors(form, result.errors);
            }
            SIK.toast(result.message || 'We could not place your order. Please try again.', 'error');
            return;
        }

        // Success: never re-enable the button, so a double tap cannot double order.
        SIK.toast('Order placed successfully', 'success');

        if (result.data.redirect) {
            window.location.href = result.data.redirect;
        } else {
            window.location.href = SIK.config.baseUrl + '/order-success.php?order=' + encodeURIComponent(result.data.order_number);
        }
    };

    /* ----------------------------------------------------------------------
       Wiring
       ---------------------------------------------------------------------- */
    function bind() {
        const form = $('#sikCheckoutForm');
        if (!form) return;

        SIK.on('click', '[data-address-card]', function (e) {
            if (e.target.closest('a, button')) return;
            Checkout.selectAddress(this);
        }, form);

        // checkout.php renders the customer's default address already carrying
        // .is-selected with its radio checked, but selectAddress() — the only
        // thing that copies it into the hidden shipping_* inputs the API reads
        // — ran on click alone. A returning customer who accepted their default
        // and pressed Place Order therefore submitted an empty address and was
        // bounced by validation on the happy path. Apply it once on load.
        const preselectedRadio = form.querySelector('[data-address-card] input[type="radio"]:checked');
        const preselected = (preselectedRadio && preselectedRadio.closest('[data-address-card]'))
            || form.querySelector('[data-address-card].is-selected');
        if (preselected) Checkout.selectAddress(preselected);

        SIK.on('click', '[data-new-address]', function (e) {
            e.preventDefault();
            Checkout.showNewAddress();
        });

        // The payment list moves its .is-selected highlight on change; the
        // delivery list only recalculated the total, so switching Standard to
        // Express repriced the order but left the orange border and peach fill
        // on Standard — two rows claiming to be chosen at once.
        SIK.on('change', 'input[name="shipping_method"]', function () {
            // Delivery and payment options share the .sik-paymethod class, so
            // the highlight is moved via each radio's own label rather than by
            // a class selector that would also repaint the payment list.
            $$('input[name="shipping_method"]', form).forEach(function (radio) {
                const option = radio.closest('label');
                if (option) option.classList.toggle('is-selected', radio.checked);
            });
            Checkout.refreshTotals();
        });

        SIK.on('change', 'input[name="payment_method"]', function () {
            $$('[data-paymethod]').forEach(function (method) {
                const radio = method.querySelector('input[type="radio"]');
                method.classList.toggle('is-selected', !!(radio && radio.checked));
            });
            Checkout.refreshTotals();
        });

        SIK.on('change', '[name="billing_same"]', function () {
            const billing = $('#sikBillingFields');
            if (billing) billing.hidden = this.checked;
        });

        // Auto-fill city/state from the PIN code.
        const pincode = form.querySelector('[name="shipping_pincode"]');
        if (pincode) {
            pincode.addEventListener('blur', async function () {
                const value = this.value.trim();
                if (!/^[1-9]\d{5}$/.test(value)) return;

                const result = await SIK.get('delivery/check-pincode.php', { pincode: value });
                if (!result.success) return;

                if (!result.data.serviceable) {
                    SIK.toast(result.data.message || 'We do not deliver to this PIN code yet.', 'warning');
                    return;
                }
                const city = form.querySelector('[name="shipping_city"]');
                const state = form.querySelector('[name="shipping_state"]');
                if (city && !city.value && result.data.city) city.value = result.data.city;
                if (state && !state.value && result.data.state) state.value = result.data.state;

                const eta = $('[data-delivery-eta]');
                if (eta && result.data.delivery_date) {
                    eta.hidden = false;
                    eta.textContent = 'Estimated delivery by ' + result.data.delivery_date;
                }
                // COD may be unavailable at this address.
                const cod = form.querySelector('input[name="payment_method"][value="cod"]');
                if (cod) {
                    cod.disabled = !result.data.cod_available;
                    const wrap = cod.closest('[data-paymethod]');
                    if (wrap) {
                        wrap.classList.toggle('is-disabled', !result.data.cod_available);
                        wrap.style.opacity = result.data.cod_available ? '' : '.5';
                    }
                    if (!result.data.cod_available && cod.checked) {
                        cod.checked = false;
                        SIK.toast('Cash on Delivery is not available for this PIN code.', 'warning');
                    }
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            // The sticky bar on phones and the summary button are both [data-place-order];
            // the one that was actually pressed is the one that shows it is working.
            Checkout.placeOrder(form, (e.submitter && e.submitter.hasAttribute('data-place-order'))
                ? e.submitter
                : form.querySelector('[data-place-order]'));
        });

        // Warn before leaving a half-filled checkout.
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty && !submitting) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        Checkout.refreshTotals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    SIK.checkout = Checkout;
})();

/* ---------------------------------------------------------------------------
   Coupon apply / remove, without losing the form
   ---------------------------------------------------------------------------
   The two forms post to cart.php on their own, which works with JavaScript
   off. Here they are intercepted so the totals refresh in place: a round trip
   would throw away every address field the shopper has typed but not saved.
   --------------------------------------------------------------------------- */
(function () {
    const box = document.querySelector('[data-checkout-coupon]');
    if (!box) { return; }

    const onRow   = box.querySelector('[data-coupon-on]');
    const offForm = box.querySelector('[data-coupon-form]');
    const message = box.querySelector('[data-coupon-msg]');
    const input   = box.querySelector('#sikCheckoutCoupon');

    const cfg  = window.SIK_CONFIG || {};
    const endpoint = (cfg.apiUrl || '/api') + '/cart/coupon.php';

    function say(text, ok) {
        if (!message) { return; }
        message.textContent = text || '';
        message.hidden = !text;
        message.classList.toggle('is-error', ok === false);
    }

    /* The server is the only thing that knows the real totals, so every row it
       sends is written straight through rather than recomputed here. */
    function paint(totals) {
        const display = totals.display || {};
        Object.keys(display).forEach(function (key) {
            document.querySelectorAll('[data-total="' + key + '"]').forEach(function (el) {
                el.textContent = (key === 'discount' ? '- ' : '') + display[key];
            });
        });

        const discountRow = document.querySelector('[data-total-row="discount"]');
        if (discountRow) { discountRow.hidden = !(parseFloat(totals.discount) > 0); }

        const code = totals.coupon_code || null;
        if (onRow) { onRow.hidden = code === null; }
        if (offForm) { offForm.hidden = code !== null; }

        const codeEl = box.querySelector('[data-coupon-code]');
        if (codeEl && code) { codeEl.textContent = code; }
        const savingEl = box.querySelector('[data-coupon-saving]');
        if (savingEl && display.discount) { savingEl.textContent = display.discount; }

        // The tag beside the Discount label lives outside this box.
        const tag = document.querySelector('[data-total-row="discount"] .sik-tag');
        if (tag) { tag.textContent = code || ''; tag.hidden = code === null; }
    }

    function send(action, code, button) {
        const body = new FormData();
        body.append('action', action);
        if (code) { body.append('code', code); }
        body.append('csrf_token', cfg.csrfToken || '');

        if (button) { button.disabled = true; }
        say('');

        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (res.d && res.d.success && res.d.data && res.d.data.totals) {
                    paint(res.d.data.totals);
                    say(res.d.message, true);
                    if (action === 'remove' && input) { input.value = ''; }
                    return;
                }
                // A rejected code is a normal outcome, not a failure: the
                // message explains which rule it missed.
                say((res.d && res.d.message) || 'That code could not be applied.', false);
            })
            .catch(function () {
                say('Could not reach the server. Check your connection and try again.', false);
            })
            .then(function () { if (button) { button.disabled = false; } });
    }

    if (offForm) {
        offForm.addEventListener('submit', function (e) {
            const code = input ? input.value.trim() : '';
            if (code === '') { return; }   // let the browser show its own required message
            e.preventDefault();
            send('apply', code, offForm.querySelector('button[type="submit"]'));
        });
    }

    if (onRow) {
        onRow.addEventListener('submit', function (e) {
            e.preventDefault();
            send('remove', null, onRow.querySelector('[data-coupon-remove]'));
        });
    }
}());
