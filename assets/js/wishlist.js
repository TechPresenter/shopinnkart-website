/* ==========================================================================
   ShopInnKart - Wishlist
   Guests get a session-backed list; signing in merges it into the account.

   "Move to Cart" is the same .sik-atc component as every other add-to-cart
   button (see add_to_cart_button() in includes/widgets.php); this file only
   adds the second half of the move - taking the item off the wishlist once the
   server has confirmed it reached the cart.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $$ = SIK.$$;

    const Wishlist = {};

    Wishlist.setCount = function (count) {
        SIK.setBadge('[data-wishlist-count]', 'Wishlist', count);
    };

    /** Reflect membership on every button pointing at this product. */
    Wishlist.markButtons = function (productId, active) {
        $$('[data-wishlist][data-product-id="' + productId + '"]').forEach(function (button) {
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
            button.setAttribute('aria-label', active ? 'Remove from wishlist' : 'Add to wishlist');
            const svg = button.querySelector('svg');
            if (svg) svg.setAttribute('fill', active ? 'currentColor' : 'none');
        });
    };

    Wishlist.toggle = async function (productId, button) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('wishlist/add.php', { product_id: parseInt(productId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            Wishlist.setCount(result.data.count || 0);
            Wishlist.markButtons(productId, !!result.data.added);
            SIK.toast(result.message, 'success', result.data.added ? {
                action: { label: 'View wishlist', href: SIK.config.baseUrl + '/wishlist.php' }
            } : undefined);
        } else {
            SIK.toast(result.message || 'Could not update the wishlist.', 'error');
        }
        return result;
    };

    /**
     * Take a product off the wishlist.
     *
     * @param {boolean} quiet Suppress the toast - the caller is announcing a
     *                        bigger action (a move to cart) and two toasts for
     *                        one click reads as an error.
     */
    Wishlist.remove = async function (productId, button, quiet) {
        if (button && !SIK.showLoader(button)) return;

        const result = await SIK.post('wishlist/remove.php', { product_id: parseInt(productId, 10) });

        if (button) SIK.hideLoader(button);

        if (result.success) {
            Wishlist.setCount(result.data.count || 0);
            Wishlist.markButtons(productId, false);
            Wishlist.dropCard(productId);
            if (!quiet) SIK.toast('Removed from wishlist', 'success');
        } else {
            SIK.toast(result.message || 'Could not remove the item.', 'error');
        }
        return result;
    };

    /** Fade a saved card off the wishlist page. */
    Wishlist.dropCard = function (productId) {
        const card = document.querySelector('[data-wishlist-row="' + productId + '"]');
        if (!card) return;

        card.style.transition = 'opacity var(--dur-base) var(--ease-out), transform var(--dur-base) var(--ease-out)';
        card.style.opacity = '0';
        card.style.transform = 'scale(.96)';

        setTimeout(function () {
            card.remove();
            // The empty wishlist is a different layout (its own illustration and
            // calls to action), so let the server render it rather than keeping
            // a duplicate copy of that markup in here.
            if (!document.querySelectorAll('[data-wishlist-row]').length
                && document.querySelector('[data-wishlist-page]')) {
                window.location.reload();
            }
        }, 240);
    };

    /**
     * Move a saved item into the cart.
     *
     * The add runs first and only a confirmed add removes the item, so a
     * sold-out or capped product stays safely on the wishlist. Price, stock and
     * caps are the server's call - nothing here is read off the card.
     */
    Wishlist.moveToCart = async function (productId, button) {
        if (!SIK.cart) return;

        const result = await SIK.cart.add(productId, null, 1, button);
        if (!result || !result.success) return result;

        // Quiet: SIK.cart.add() already said "Added to cart".
        await Wishlist.remove(productId, null, true);
        return result;
    };

    function bind() {
        SIK.on('click', '[data-wishlist]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Wishlist.toggle(this.dataset.productId, this);
        });

        SIK.on('click', '[data-wishlist-remove]', function (e) {
            e.preventDefault();
            Wishlist.remove(this.dataset.productId, this, false);
        });

        SIK.on('click', '[data-wishlist-to-cart]', function (e) {
            e.preventDefault();
            Wishlist.moveToCart(this.dataset.productId, this);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    SIK.wishlist = Wishlist;
})();
