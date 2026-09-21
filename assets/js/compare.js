/* ==========================================================================
   ShopInnKart - Product comparison
   Up to SIK.config.maxCompare products, with a floating bar at the bottom.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    const Compare = {};

    Compare.setCount = function (count) {
        SIK.setBadge('[data-compare-count]', 'Compare products', count);
    };

    Compare.markButtons = function (productId, active) {
        $$('[data-compare][data-product-id="' + productId + '"]').forEach(function (button) {
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
            button.setAttribute('aria-label', active ? 'Remove from compare' : 'Add to compare');
        });
    };

    /** Render the floating compare bar from the server payload. */
    Compare.renderBar = function (items) {
        let bar = $('#sikCompareBar');

        // The bar is a shortcut BACK to this page, plus a Clear all the page
        // already owns. On /compare it is a duplicate control that parks itself
        // over the bottom of the table, so it is not drawn here at all.
        if (document.querySelector('[data-compare-page]')) {
            if (bar) bar.classList.remove('is-open');
            document.body.classList.remove('sik-has-comparebar');
            return;
        }

        if (!items || !items.length) {
            if (bar) bar.classList.remove('is-open');
            // The floating WhatsApp / back-to-top stack offsets off this flag.
            document.body.classList.remove('sik-has-comparebar');
            return;
        }

        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'sikCompareBar';
            bar.className = 'sik-comparebar';
            document.body.appendChild(bar);
        }

        let html = '<div class="sik-container" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">'
            + '<div class="sik-comparebar__items">';

        items.forEach(function (item) {
            html += '<div class="sik-comparebar__item">'
                // Explicit intrinsic size: the .sik-comparebar__item box is 62px
                // square, so without it the thumb has no reserved space while it loads.
                + '<img src="' + SIK.escapeHtml(item.image_url) + '" alt="' + SIK.escapeHtml(item.name) + '"'
                + ' width="54" height="54" loading="lazy">'
                + '<button type="button" class="sik-comparebar__remove" data-compare-remove data-product-id="' + item.id + '" aria-label="Remove ' + SIK.escapeHtml(item.name) + ' from compare">'
                + '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>'
                + '</button></div>';
        });

        const remaining = Math.max(0, SIK.config.maxCompare - items.length);
        for (let i = 0; i < remaining; i++) {
            html += '<div class="sik-comparebar__item sik-comparebar__item--empty">Add</div>';
        }

        html += '</div>'
            + '<div style="margin-left:auto;display:flex;gap:8px;align-items:center">'
            + '<button type="button" class="sik-btn sik-btn--outline sik-btn--sm" data-compare-clear>Clear all</button>'
            + '<a class="sik-btn sik-btn--primary sik-btn--sm" href="' + SIK.config.baseUrl + '/compare.php">Compare (' + items.length + ')</a>'
            + '</div></div>';

        bar.innerHTML = html;
        bar.classList.add('is-open');
        document.body.classList.add('sik-has-comparebar');
    };

    Compare.sync = async function () {
        const result = await SIK.get('compare/list.php');
        if (result.success) {
            Compare.setCount(result.data.count || 0);
            Compare.renderBar(result.data.items || []);
            (result.data.items || []).forEach(item => Compare.markButtons(item.id, true));
        }
        return result;
    };

    Compare.toggle = async function (productId, button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('compare/add.php', { product_id: parseInt(productId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            Compare.setCount(result.data.count || 0);
            Compare.markButtons(productId, !!result.data.added);
            Compare.renderBar(result.data.items || []);
            SIK.toast(result.message, 'success');
        } else {
            SIK.toast(result.message || 'Could not update the comparison.', 'warning');
        }
        return result;
    };

    Compare.remove = async function (productId, button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('compare/remove.php', { product_id: parseInt(productId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            Compare.setCount(result.data.count || 0);
            Compare.markButtons(productId, false);
            Compare.renderBar(result.data.items || []);

            // The comparison table is a matrix: pulling one product out changes
            // every row's "these differ" highlighting and the column count, so
            // the server rebuilds it rather than the browser patching a grid.
            if (document.querySelector('[data-compare-page]')) window.location.reload();
        } else {
            SIK.toast(result.message || 'Could not update the comparison.', 'error');
        }
        return result;
    };

    Compare.clear = async function (button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('compare/remove.php', { clear: true });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            Compare.setCount(0);
            Compare.renderBar([]);
            $$('[data-compare]').forEach(b => Compare.markButtons(b.dataset.productId, false));
            if (document.querySelector('[data-compare-page]')) window.location.reload();
            else SIK.toast('Comparison cleared', 'success');
        } else {
            SIK.toast(result.message || 'Could not clear the comparison.', 'error');
        }
        return result;
    };

    /* ----------------------------------------------------------------------
       The comparison page itself.

       Three jobs, none of which the markup can do alone:

       1. Mirror scrollLeft between the pinned header pane and the row pane.
          They have to be two separate scrollers - a single `overflow-x: auto`
          wrapper becomes the scrollport and kills `position: sticky; top:` for
          everything inside it - so the columns only stay lined up because this
          keeps them lined up.
       2. Tell the header pane when it has actually detached, so it can drop the
          brand line and shrink the image. CSS has no `:stuck`, so a 1px
          sentinel above the pane and an IntersectionObserver stand in for it.
       3. The "differences only" filter, which is one class on the container.
       ---------------------------------------------------------------------- */
    Compare.initPage = function () {
        const page = $('[data-compare-page]');
        if (!page) return;

        // --- 1. keep the two panes in step ---------------------------------
        const panes = $$('[data-cmp-scroll]', page);
        if (panes.length > 1) {
            // One flag for the pair: assigning scrollLeft fires 'scroll' on the
            // other pane, which would assign it straight back.
            let echo = false;
            panes.forEach(function (pane) {
                pane.addEventListener('scroll', function () {
                    if (echo) return;
                    echo = true;
                    panes.forEach(function (other) {
                        if (other !== pane) other.scrollLeft = pane.scrollLeft;
                    });
                    requestAnimationFrame(function () { echo = false; });
                }, { passive: true });
            });
        }

        // --- 2. pinned / not pinned ----------------------------------------
        const head = $('[data-cmp-head]', page);
        const sentinel = $('[data-cmp-sentinel]', page);
        if (head && sentinel && window.IntersectionObserver) {
            let observer = null;
            const watch = function () {
                if (observer) observer.disconnect();
                // The offset is whatever CSS resolved - which depends on
                // whether the site header is sticky and how tall it measured -
                // so it is read back rather than guessed at.
                const offset = Math.round(parseFloat(getComputedStyle(head).top) || 0);
                observer = new IntersectionObserver(function (entries) {
                    const box = entries[0].boundingClientRect;
                    head.classList.toggle('is-stuck', !entries[0].isIntersecting && box.top < offset);
                }, { rootMargin: '-' + (offset + 1) + 'px 0px 0px 0px', threshold: 0 });
                observer.observe(sentinel);
            };
            watch();
            window.addEventListener('resize', SIK.debounce(watch, 200));
        }

        // --- 3. differences only -------------------------------------------
        SIK.on('change', '[data-cmp-diffonly]', function () {
            const table = $('.sik-cmp', page);
            if (table) table.classList.toggle('is-diffonly', this.checked);
        }, page);
    };

    function bind() {
        SIK.on('click', '[data-compare]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Compare.toggle(this.dataset.productId, this);
        });

        SIK.on('click', '[data-compare-remove]', function (e) {
            e.preventDefault();
            Compare.remove(this.dataset.productId, this);
        });

        SIK.on('click', '[data-compare-clear]', function (e) {
            e.preventDefault();
            Compare.clear(this);
        });

        // Only fetch the bar when something is already being compared. The
        // count comes from the server config, not from the header badge: an
        // admin who switches the header compare icon off still has card
        // buttons, and the bar is then the only way back to /compare - reading
        // a control that may have been removed made the bar vanish on the next
        // page load.
        const badge = $('[data-compare-count]');
        const stored = badge
            ? parseInt(badge.dataset.count || '0', 10)
            : parseInt((SIK.config && SIK.config.compareCount) || 0, 10);
        if (stored > 0) {
            Compare.sync();
        }

        Compare.initPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    SIK.compare = Compare;
})();
