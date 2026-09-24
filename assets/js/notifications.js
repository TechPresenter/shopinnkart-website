/* ==========================================================================
   ShopInnKart - Toast notifications
   Self-contained. No library.
   Usage: SIK.toast('Added to cart', 'success');
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};

    const ICONS = {
        success: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>',
        error:   '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/></svg>',
        info:    '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'
    };
    const CLOSE = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';

    let container = null;

    /* How many are on screen at once. Beyond this they QUEUE - the fifth
       "Saved" must not push the first error off the screen before it has been
       read, which is what dropping the oldest used to do. */
    const MAX_VISIBLE = 4;
    const queue = [];

    function getContainer() {
        if (container && document.body.contains(container)) return container;
        container = document.getElementById('sikToasts');
        if (!container) {
            container = document.createElement('div');
            container.id = 'sikToasts';
            container.className = 'sik-toasts';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }
        return container;
    }

    /** Start the auto-dismiss clock, replacing any clock already running. */
    function arm(toast, ms) {
        clearTimeout(parseInt(toast.dataset.timer || '0', 10));
        if (!ms) { toast.dataset.timer = '0'; return; }   // 0 = stays until dismissed
        toast.dataset.timer = String(setTimeout(function () { dismiss(toast); }, ms));
    }

    function dismiss(toast) {
        if (!toast || toast.dataset.leaving === '1') return;
        toast.dataset.leaving = '1';
        clearTimeout(parseInt(toast.dataset.timer || '0', 10));
        toast.classList.add('is-leaving');
        /* The removal is remembered as well as the auto-dismiss, because the
           collapse path below can bring a toast back DURING its leave
           animation. Without this handle that toast reappeared and then
           vanished 240ms later, having said its piece to nobody. */
        toast.dataset.gone = String(setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            flush();
        }, 240));
    }

    /** Move queued toasts onto the screen as slots free up. */
    function flush() {
        const wrap = getContainer();
        while (queue.length && wrap.children.length < MAX_VISIBLE) {
            const next = queue.shift();
            if (!next || !next.node) continue;
            show(next.node, next.life);
        }
    }

    function show(toast, life) {
        getContainer().appendChild(toast);
        arm(toast, life);
    }

    /**
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {{title?:string, duration?:number, action?:{label:string, href?:string, onClick?:Function}}} options
     */
    SIK.toast = function (message, type, options) {
        if (!message) return;
        type = type || 'info';
        options = options || {};

        const wrap = getContainer();

        /* How long it stays. An error is a thing to read and act on, not a
           thing to glimpse; one with an action button needs long enough to
           reach the button. 0 means it waits to be dismissed. */
        const life = options.duration !== undefined
            ? options.duration
            : (type === 'error' ? 6500 : (options.action ? 6000 : 3800));

        // Collapse an identical toast that is already on screen, or queued.
        const existing = wrap.querySelector('.sik-toast[data-message="' + CSS.escape(message) + '"]');
        if (existing) {
            clearTimeout(parseInt(existing.dataset.gone || '0', 10));
            existing.dataset.gone = '0';
            existing.classList.remove('is-leaving');
            existing.dataset.leaving = '';
            arm(existing, life);
            return existing;
        }
        for (let q = 0; q < queue.length; q++) {
            if (queue[q].node.dataset.message === message) return queue[q].node;
        }

        const toast = document.createElement('div');
        toast.className = 'sik-toast sik-toast--' + type;
        toast.dataset.message = message;

        /* The live region, per toast rather than per container.
           aria-live is resolved from the NEAREST ancestor that carries it, so
           a value on the toast itself wins over the polite container - an
           error interrupts, a "Saved" waits its turn. role=alert without an
           explicit aria-live is assertive by implication, but Edge and NVDA
           disagree about nested regions often enough to be worth stating. */
        if (type === 'error' || type === 'warning') {
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
        } else {
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
        }
        toast.setAttribute('aria-atomic', 'true');

        let html = '<span class="sik-toast__icon">' + (ICONS[type] || ICONS.info) + '</span><div class="sik-toast__body">';
        if (options.title) html += '<strong class="sik-toast__title">' + SIK.escapeHtml(options.title) + '</strong>';
        html += SIK.escapeHtml(message);

        if (options.action && options.action.label) {
            /* A link when it goes somewhere, a button when it does something.
               An <a href="#"> that runs a callback is a link that lies, and it
               is the one control in a toast a keyboard user has to reach. */
            if (options.action.href) {
                html += '<a class="sik-toast__action" href="' + SIK.escapeHtml(options.action.href) + '">'
                    + SIK.escapeHtml(options.action.label) + '</a>';
            } else {
                html += '<button type="button" class="sik-toast__action sik-toast__action--btn">'
                    + SIK.escapeHtml(options.action.label) + '</button>';
            }
        }
        html += '</div><button type="button" class="sik-toast__close" aria-label="Dismiss">' + CLOSE + '</button>';
        toast.innerHTML = html;

        toast.querySelector('.sik-toast__close').addEventListener('click', () => dismiss(toast));

        if (options.action && typeof options.action.onClick === 'function') {
            const link = toast.querySelector('.sik-toast__action');
            if (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    options.action.onClick();
                    dismiss(toast);
                });
            }
        }

        // Hold the timer while the pointer is over it OR the keyboard is in
        // it. Without the focus pair, tabbing to the action button started a
        // race between the operator and the clock.
        const hold = function () { clearTimeout(parseInt(toast.dataset.timer || '0', 10)); };
        const resume = function () { if (toast.parentNode && life) arm(toast, 1800); };
        toast.addEventListener('mouseenter', hold);
        toast.addEventListener('mouseleave', resume);
        toast.addEventListener('focusin', hold);
        toast.addEventListener('focusout', resume);

        if (wrap.children.length < MAX_VISIBLE) show(toast, life);
        else queue.push({ node: toast, life: life });

        return toast;
    };

    /** Take everything down at once - the queue included. */
    SIK.toastClear = function () {
        queue.length = 0;
        Array.prototype.slice.call(getContainer().children).forEach(dismiss);
    };

    SIK.toastSuccess = (m, o) => SIK.toast(m, 'success', o);
    SIK.toastError   = (m, o) => SIK.toast(m, 'error', o);
    SIK.toastWarning = (m, o) => SIK.toast(m, 'warning', o);
    SIK.toastInfo    = (m, o) => SIK.toast(m, 'info', o);

    /** Render an API result as the right kind of toast. */
    SIK.toastResult = function (result, successMessage) {
        if (!result) return;
        if (result.aborted) return;
        if (result.success) {
            SIK.toast(successMessage || result.message || 'Done', 'success');
        } else {
            SIK.toast(result.message || 'Something went wrong. Please try again.', 'error');
        }
    };

    /* Server-side flash messages arrive as a JSON blob in the page. */
    document.addEventListener('DOMContentLoaded', function () {
        const node = document.getElementById('sikFlash');
        if (!node) return;
        try {
            const messages = JSON.parse(node.textContent || '[]');
            messages.forEach(function (item, index) {
                setTimeout(() => SIK.toast(item.message, item.type || 'info'), index * 260);
            });
        } catch (e) { /* malformed flash payload - ignore */ }
    });
})();
