/* ==========================================================================
   ShopInnKart - Account area & general form handling
   Auth forms, addresses, newsletter, contact and order actions.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Account = {};

    /* ----------------------------------------------------------------------
       Generic AJAX form handler
       Any <form data-ajax-form="endpoint.php"> is submitted as JSON and its
       field errors are rendered inline.
       ---------------------------------------------------------------------- */
    Account.clearErrors = function (form) {
        $$('.sik-error', form).forEach(el => el.remove());
        $$('.is-invalid', form).forEach(el => el.classList.remove('is-invalid'));
    };

    Account.showErrors = function (form, errors) {
        Account.clearErrors(form);
        let first = null;

        Object.keys(errors || {}).forEach(function (name) {
            const input = form.querySelector('[name="' + name + '"]');
            if (!input) return;
            input.classList.add('is-invalid');
            const error = document.createElement('span');
            error.className = 'sik-error';
            error.textContent = errors[name];

            // Anchor the message to the field, not to the input's immediate
            // parent. Password inputs sit in a flex row beside a show/hide
            // button, so appending there made the error a third flex item and
            // squashed the input to roughly half its width.
            const field = input.closest('.sik-field') || input.parentNode || form;
            field.appendChild(error);
            if (!first) first = input;
        });

        if (first) first.focus();
    };

    Account.submit = async function (form) {
        const endpoint = form.dataset.ajaxForm;
        const button = form.querySelector('[type="submit"]');
        if (!endpoint) return;
        if (button && !SIK.showLoader(button)) return;

        Account.clearErrors(form);

        const payload = {};
        new FormData(form).forEach(function (value, key) {
            if (key.endsWith('[]')) {
                const name = key.slice(0, -2);
                payload[name] = payload[name] || [];
                payload[name].push(value);
            } else {
                payload[key] = value;
            }
        });
        // Unchecked boxes are absent from FormData; send them explicitly.
        $$('input[type="checkbox"]', form).forEach(function (box) {
            if (!box.name) return;
            if (!(box.name in payload)) payload[box.name] = box.checked ? '1' : '0';
        });

        const result = await SIK.post(endpoint, payload);

        if (button) SIK.hideLoader(button);

        if (result.success) {
            SIK.toast(result.message || 'Saved', 'success');

            if (form.dataset.resetOnSuccess === 'true') form.reset();

            if (result.data && result.data.redirect) {
                setTimeout(() => { window.location.href = result.data.redirect; }, 600);
            } else if (form.dataset.reloadOnSuccess === 'true') {
                setTimeout(() => window.location.reload(), 700);
            }
        } else {
            if (result.errors && Object.keys(result.errors).length) {
                Account.showErrors(form, result.errors);
            }
            SIK.toast(result.message || 'Something went wrong. Please try again.', 'error');
        }

        // Lets a screen keep its own state in step with the save without having
        // to re-implement the submit. The preferences form uses it to clear its
        // "unsaved changes" line the moment the write lands.
        form.dispatchEvent(new CustomEvent('sik:form-result', { detail: result, bubbles: true }));

        return result;
    };

    /* ----------------------------------------------------------------------
       Password strength meter
       ---------------------------------------------------------------------- */
    function scorePassword(value) {
        let score = 0;
        if (value.length >= 8) score++;
        if (value.length >= 12) score++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;
        return Math.min(4, score);
    }

    function initPasswordMeter() {
        $$('[data-password-meter]').forEach(function (input) {
            const meter = document.querySelector(input.dataset.passwordMeter);
            if (!meter) return;

            const labels = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'];
            // Two scales on purpose. The bar is a filled block, so the bright
            // tones are fine there (non-text needs 3:1). The label is 11px
            // semibold text and needs 4.5:1 — #F59E0B is 2.15:1 on white, which
            // this codebase documents elsewhere as unreadable at any size.
            const barColors   = ['#DC2626', '#F59E0B', '#F59E0B', '#16A34A', '#15803D'];
            const labelColors = ['var(--sik-danger-ink)', 'var(--sik-primary-ink)',
                                 'var(--sik-primary-ink)', 'var(--sik-success-ink)', 'var(--sik-success-ink)'];

            input.addEventListener('input', function () {
                const value = input.value;
                if (!value) { meter.hidden = true; return; }

                const score = scorePassword(value);
                meter.hidden = false;
                meter.innerHTML = '<div class="sik-progress" style="height:5px"><span style="width:'
                    + ((score + 1) * 20) + '%;background:' + barColors[score] + '"></span></div>'
                    + '<span style="display:block;margin-top:var(--sp-1);font-size:var(--fs-2xs);color:'
                    + labelColors[score] + ';font-weight:var(--fw-semibold)">' + labels[score] + '</span>';
            });
        });

        // Show / hide password.
        SIK.on('click', '[data-toggle-password]', function (e) {
            e.preventDefault();
            const input = document.querySelector(this.dataset.togglePassword);
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            this.classList.toggle('is-active', !showing);
        });
    }

    /* ----------------------------------------------------------------------
       Orders
       ---------------------------------------------------------------------- */
    Account.cancelOrder = async function (orderId, button) {
        const reason = window.prompt('Please tell us why you are cancelling this order:');
        if (reason === null) return;
        if (!reason.trim()) {
            SIK.toast('A reason is required to cancel.', 'warning');
            return;
        }

        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('orders/cancel.php', {
            order_id: parseInt(orderId, 10),
            reason: reason.trim()
        });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            SIK.toast(result.message || 'Order cancelled', 'success');
            setTimeout(() => window.location.reload(), 900);
        } else {
            SIK.toast(result.message || 'Could not cancel the order.', 'error');
        }
    };

    Account.trackOrder = async function (form, button) {
        if (button && !SIK.showLoader(button)) return;

        const data = Object.fromEntries(new FormData(form).entries());
        const result = await SIK.post('orders/track.php', data);

        if (button) SIK.hideLoader(button);

        const panel = $('[data-track-result]');
        if (!panel) return;

        panel.hidden = false;
        if (result.success) {
            panel.innerHTML = result.data.html || '';
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            panel.innerHTML = '<div class="sik-alert sik-alert--error">'
                + SIK.escapeHtml(result.message || 'We could not find that order.') + '</div>';
        }
    };

    /** Put every item from a past order back in the cart. */
    Account.reorder = async function (orderId, button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('orders/reorder.php', { order_id: parseInt(orderId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            if (SIK.cart) {
                SIK.cart.setCount(result.data.count || 0);
                SIK.cart.sync();
            }
            SIK.toast(result.message || 'Items added to your cart', 'success', {
                action: { label: 'View cart', href: SIK.config.baseUrl + '/cart.php' }
            });
        } else {
            SIK.toast(result.message || 'Could not reorder these items.', 'error');
        }
    };

    /* ----------------------------------------------------------------------
       Notification centre (notifications.php)

       The rows are the same markup the header bell paints - both come from
       notification_row_html() server-side. Nothing here rebuilds a row; the
       per-row controls are cloned from a <template> the page rendered, and
       appended pages arrive as HTML from /api/notifications/list.php.
       ---------------------------------------------------------------------- */
    function initNotificationCentre() {
        const feed = $('[data-notif-feed]');
        if (!feed) return;

        const list = $('[data-notif-list]', feed);
        const tools = $('[data-notif-tools]', feed);
        const more = $('[data-notif-more]', feed);
        const pager = $('[data-notif-pager]');
        const readAll = $('[data-notif-read-all]');
        if (!list) return;

        let offset = parseInt(feed.dataset.offset || '0', 10) || 0;
        let loading = false;

        /** Header badge, the mark-all button and the Unread tab all read one number. */
        function applyUnread(count) {
            count = Number(count) || 0;
            SIK.setBadge('[data-notification-count]', 'Notifications', count, 'unread');
            if (readAll) readAll.hidden = count === 0;

            const tab = $('.sik-tab[href*="filter=unread"] .sik-tab__count');
            if (tab) tab.textContent = String(count);
        }

        function markRowRead(item) {
            if (!item) return;
            item.classList.remove('is-unread');
            const row = $('.sik-notif', item);
            if (row) {
                row.classList.remove('is-unread');
                const dot = $('.sik-notif__dot', row);
                if (dot) dot.remove();
            }
            const button = $('[data-notif-read]', item);
            if (button) button.remove();
        }

        /** Wrap a server-rendered .sik-notif in the list item it belongs in. */
        function wrapRow(row) {
            const id = row.dataset.notification || '';
            const isUnread = row.classList.contains('is-unread');

            const item = document.createElement('li');
            item.className = 'sik-feeditem' + (isUnread ? ' is-unread' : '');
            item.dataset.feedItem = id;
            item.appendChild(row);

            if (tools && tools.content.firstElementChild) {
                const cluster = tools.content.firstElementChild.cloneNode(true);
                const read = $('[data-notif-read]', cluster);
                if (read) {
                    if (isUnread) read.dataset.notifRead = id;
                    else read.remove();
                }
                const remove = $('[data-notif-delete]', cluster);
                if (remove) remove.dataset.notifDelete = id;
                item.appendChild(cluster);
            }

            return item;
        }

        /* The pager is the no-JS path. With JS the same "is there more" answer
           drives one Load-more button instead, so only ever one control shows. */
        if (more && pager && $('.sik-pager', pager)) {
            pager.hidden = true;
            more.hidden = false;
        }

        if (more) {
            more.addEventListener('click', async function () {
                if (loading || !SIK.showLoader(more)) return;
                loading = true;

                const result = await SIK.get('notifications/list.php', {
                    filter: feed.dataset.filter || '',
                    limit: 20,
                    offset: offset
                });

                loading = false;
                SIK.hideLoader(more);

                if (!result.success) {
                    SIK.toastResult(result);
                    return;
                }

                const holder = document.createElement('div');
                holder.innerHTML = result.data.html || '';
                const rows = $$('.sik-notif', holder);

                rows.forEach(row => list.appendChild(wrapRow(row)));

                offset += rows.length;
                feed.dataset.offset = String(offset);
                applyUnread(result.data.unread);

                if (!result.data.has_more || rows.length === 0) {
                    more.hidden = true;
                }
                if (rows.length > 0) {
                    // Send focus to the first row that just arrived, otherwise a
                    // keyboard user is left at a button that has vanished.
                    const first = $('.sik-notif', list.children[list.children.length - rows.length]);
                    if (first && first.tagName === 'A') first.focus({ preventScroll: true });
                }
            });
        }

        SIK.on('click', '[data-notif-read]', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!SIK.showLoader(this)) return;

            const item = this.closest('[data-feed-item]');
            const result = await SIK.post('notifications/read.php', { id: this.dataset.notifRead });
            SIK.hideLoader(this);

            if (!result.success) { SIK.toastResult(result); return; }

            markRowRead(item);
            applyUnread(result.data ? result.data.unread : 0);
        }, feed);

        SIK.on('click', '[data-notif-delete]', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!SIK.showLoader(this)) return;

            const item = this.closest('[data-feed-item]');
            const result = await SIK.post('notifications/delete.php', { id: this.dataset.notifDelete });
            SIK.hideLoader(this);

            if (!result.success) { SIK.toastResult(result); return; }

            if (item) item.remove();
            applyUnread(result.data ? result.data.unread : 0);
            SIK.toast(result.message || 'Notification removed.', 'success');

            // The empty state, the tab counts and the pager are all server work.
            // Rather than rebuild three of them here, ask for the page again.
            if (!list.querySelector('[data-feed-item]')) {
                setTimeout(() => window.location.reload(), 500);
            }
        }, feed);

        // Opening a notification clears it. Not awaited - the row is a link and
        // the write is cosmetic, so the navigation must not wait on it.
        SIK.on('click', '[data-notification]', function () {
            if (!this.classList.contains('is-unread')) return;
            const item = this.closest('[data-feed-item]');
            SIK.post('notifications/read.php', { id: this.dataset.notification });
            markRowRead(item);
        }, feed);

        if (readAll) {
            readAll.addEventListener('click', async function () {
                if (!SIK.showLoader(readAll)) return;

                const result = await SIK.post('notifications/read.php', { all: true });
                SIK.hideLoader(readAll);

                if (!result.success) { SIK.toastResult(result); return; }

                $$('[data-feed-item]', list).forEach(markRowRead);
                applyUnread(result.data ? result.data.unread : 0);
                SIK.toast(result.message || 'All notifications marked as read.', 'success');

                // The unread filter is now empty by definition.
                if ((feed.dataset.filter || '') === 'unread') {
                    setTimeout(() => window.location.reload(), 600);
                }
            });
        }
    }

    /* ----------------------------------------------------------------------
       Preferences (preferences.php)

       The form itself is a [data-ajax-form], so the save, the field errors and
       the toast all come from Account.submit. This only tracks whether what is
       on screen still matches what is stored.
       ---------------------------------------------------------------------- */
    function initPreferences() {
        const form = $('[data-prefs-form]');
        if (!form) return;

        const state = $('[data-prefs-state]', form);
        let dirty = false;

        function setDirty(value) {
            dirty = value;
            form.classList.toggle('is-dirty', value);
            if (state) {
                state.textContent = value ? 'You have unsaved changes.' : 'Everything here is saved.';
            }
        }

        form.addEventListener('change', function () { if (!dirty) setDirty(true); });
        form.addEventListener('input', function () { if (!dirty) setDirty(true); });

        form.addEventListener('sik:form-result', function (event) {
            if (event.detail && event.detail.success) setDirty(false);
        });

        setDirty(false);
    }

    /* ----------------------------------------------------------------------
       Wiring
       ---------------------------------------------------------------------- */
    function bind() {
        SIK.on('submit', '[data-ajax-form]', function (e) {
            e.preventDefault();
            Account.submit(this);
        });

        SIK.on('click', '[data-cancel-order]', function (e) {
            e.preventDefault();
            Account.cancelOrder(this.dataset.cancelOrder, this);
        });

        SIK.on('click', '[data-reorder]', function (e) {
            e.preventDefault();
            Account.reorder(this.dataset.reorder, this);
        });

        SIK.on('submit', '[data-track-form]', function (e) {
            e.preventDefault();
            Account.trackOrder(this, this.querySelector('[type="submit"]'));
        });

        // Address delete needs a confirmation.
        SIK.on('click', '[data-delete-address]', async function (e) {
            e.preventDefault();
            if (!window.confirm('Delete this address?')) return;

            if (!SIK.showLoader(this)) return;
            const result = await SIK.post('account/address-delete.php', {
                address_id: parseInt(this.dataset.deleteAddress, 10)
            });
            SIK.hideLoader(this);

            if (result.success) {
                const card = this.closest('[data-address-row]');
                if (card) card.remove();
                SIK.toast('Address deleted', 'success');
            } else {
                SIK.toast(result.message || 'Could not delete the address.', 'error');
            }
        });

        SIK.on('click', '[data-set-default-address]', async function (e) {
            e.preventDefault();
            if (!SIK.showLoader(this)) return;
            const result = await SIK.post('account/address-default.php', {
                address_id: parseInt(this.dataset.setDefaultAddress, 10)
            });
            SIK.hideLoader(this);
            SIK.toastResult(result, 'Default address updated');
            if (result.success) setTimeout(() => window.location.reload(), 600);
        });

        // Newsletter (footer, popup and inline forms share this).
        SIK.on('submit', '[data-newsletter-form]', async function (e) {
            e.preventDefault();
            const form = this;
            const button = form.querySelector('[type="submit"]');
            const input = form.querySelector('input[name="email"]');

            if (!input || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(input.value.trim())) {
                SIK.toast('Enter a valid email address.', 'warning');
                if (input) input.focus();
                return;
            }
            if (button && !SIK.showLoader(button)) return;

            const result = await SIK.post('newsletter/subscribe.php', {
                email: input.value.trim(),
                source: form.dataset.newsletterForm || 'footer'
            });

            if (button) SIK.hideLoader(button);

            if (result.success) {
                SIK.toast(result.message || 'You are subscribed. Watch your inbox for offers.', 'success');
                form.reset();
                const popup = form.closest('.sik-modal, .sik-popin');
                if (popup) {
                    // A signup is the conversion a newsletter popup exists for,
                    // so it is counted before the panel closes itself.
                    if (SIK.trackPopupConversion) SIK.trackPopupConversion(form);
                    setTimeout(function () {
                        if (popup.classList.contains('sik-popin')) popup.classList.remove('is-open');
                        else SIK.closeModal(popup);
                    }, 1200);
                }
            } else {
                SIK.toast(result.message || 'Could not subscribe. Please try again.', 'error');
            }
        });

        initPasswordMeter();
        initNotificationCentre();
        initPreferences();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    SIK.account = Account;
})();

/* ---------------------------------------------------------------------------
   Phone field: keep the hint honest about the chosen country
   ---------------------------------------------------------------------------
   The server is the authority - it re-validates the pair on submit whatever
   happens here. This only spares the shopper a round trip to learn that the
   country they picked expects a different number of digits.
   --------------------------------------------------------------------------- */
(function () {
    'use strict';

    function wire(select) {
        if (select.dataset.phoneBound === '1') { return; }
        select.dataset.phoneBound = '1';

        const field  = select.closest('.sik-field') || document;
        const number = field.querySelector('[data-phone-number]');
        const hint   = field.querySelector('[data-phone-hint]');
        if (!number) { return; }

        function describe(option) {
            const min = Number(option.dataset.min || 0);
            const max = Number(option.dataset.max || 0);
            if (!min) { return ''; }
            const digits = min === max ? min + ' digits' : min + '\u2013' + max + ' digits';
            // The country name is the tail of the option text, after the "·".
            const parts = option.textContent.split('\u00B7');
            const name  = (parts[parts.length - 1] || '').trim();
            return name + ' numbers are ' + digits
                + (option.value === 'IN' ? ', starting 6, 7, 8 or 9' : '') + '.';
        }

        function sync() {
            const option = select.options[select.selectedIndex];
            if (!option) { return; }

            // Only the hint is rewritten. Rewriting the number - stripping a
            // dial code the shopper typed, say - would edit the field under
            // their cursor, and the server already accepts every shape.
            if (hint && !hint.dataset.locked) {
                hint.textContent = 'Optional. ' + describe(option);
            }
            const max = Number(option.dataset.max || 0);
            if (max) {
                // Room for spaces and a pasted dial code, not a hard cap.
                number.setAttribute('maxlength', String(max + 8));
            }
        }

        select.addEventListener('change', sync);
        sync();
    }

    function init(scope) {
        (scope || document).querySelectorAll('[data-phone-country]').forEach(wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
    document.addEventListener('sik:refresh', function () { init(); });
}());
