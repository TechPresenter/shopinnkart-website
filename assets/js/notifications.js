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

    function dismiss(toast) {
        if (!toast || toast.dataset.leaving === '1') return;
        toast.dataset.leaving = '1';
        toast.classList.add('is-leaving');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 240);
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

        // Collapse an identical toast that is already on screen.
        const existing = wrap.querySelector('.sik-toast[data-message="' + CSS.escape(message) + '"]');
        if (existing) {
            existing.classList.remove('is-leaving');
            existing.dataset.leaving = '';
            clearTimeout(parseInt(existing.dataset.timer || '0', 10));
            existing.dataset.timer = String(setTimeout(() => dismiss(existing), options.duration || 3800));
            return existing;
        }

        const toast = document.createElement('div');
        toast.className = 'sik-toast sik-toast--' + type;
        toast.dataset.message = message;

        let html = '<span class="sik-toast__icon">' + (ICONS[type] || ICONS.info) + '</span><div class="sik-toast__body">';
        if (options.title) html += '<strong class="sik-toast__title">' + SIK.escapeHtml(options.title) + '</strong>';
        html += SIK.escapeHtml(message);

        if (options.action && options.action.label) {
            const href = options.action.href ? SIK.escapeHtml(options.action.href) : '#';
            html += '<a class="sik-toast__action" href="' + href + '">'
                + SIK.escapeHtml(options.action.label) + '</a>';
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

        wrap.appendChild(toast);

        // Keep at most 4 visible.
        while (wrap.children.length > 4) dismiss(wrap.firstElementChild);

        toast.dataset.timer = String(setTimeout(() => dismiss(toast), options.duration || 3800));

        // Pause the timer while the pointer is over the toast.
        toast.addEventListener('mouseenter', () => clearTimeout(parseInt(toast.dataset.timer, 10)));
        toast.addEventListener('mouseleave', function () {
            toast.dataset.timer = String(setTimeout(() => dismiss(toast), 1800));
        });

        return toast;
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
