/* ==========================================================================
   ShopInnKart - Header behaviour
   Sticky header, mega menu, mobile drawer, hero slider.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $ = SIK.$, $$ = SIK.$$;

    /* ----------------------------------------------------------------------
       Announcement bar - rotate the messages on desktop

       The three seeded messages need roughly 1759px side by side and the
       container tops out at 1232px, so laying them out in a row always meant
       clipping one. Showing them one at a time removes the constraint.
       ---------------------------------------------------------------------- */
    function initAnnounce() {
        const row = $('.sik-announce__row--static');
        if (!row) return;

        const items = $$('.sik-announce__item', row);
        if (items.length < 2) return;

        row.classList.add('is-rotating');
        let index = 0;
        items[0].classList.add('is-active');

        const show = function (next) {
            items[index].classList.remove('is-active');
            index = next;
            items[index].classList.add('is-active');
        };

        // Honour the OS setting: no automatic movement, first message stays.
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        let timer = setInterval(() => show((index + 1) % items.length), 5000);

        // Don't swap a message out from under someone reading or hovering it.
        const stop = () => { clearInterval(timer); timer = null; };
        const start = () => { if (!timer) timer = setInterval(() => show((index + 1) % items.length), 5000); };
        row.addEventListener('mouseenter', stop);
        row.addEventListener('mouseleave', start);
        row.addEventListener('focusin', stop);
        row.addEventListener('focusout', start);
        document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
    }

    /* ----------------------------------------------------------------------
       Header height + sticky shadow

       Two jobs that used to be one, and the split matters: the measurement is
       what every sticky sidebar, anchor offset and scroll-padding rule in the
       stylesheet reads, and it used to sit behind the `is this header sticky?`
       guard. With Admin > Settings > Header set to anything but "sticky",
       --sik-header-h stayed at its 75px authored default while the real header
       measured 124px, and every one of those offsets was 49px out.
       ---------------------------------------------------------------------- */
    function initHeader() {
        const header = $('#sikHeader');
        if (!header) return;

        // Expose the real header height so anchor offsets line up.
        //
        // This must NOT write back into --sik-header-base: .sik-header__row uses
        // that variable as its min-height, so feeding the measured height —
        // which includes the 1px bottom border — back into it made the header
        // grow by a pixel on every single resize. The row sizes itself from the
        // authored --sik-header-base; the measurement lands in --sik-header-h.
        let last = 0;
        const setHeight = () => {
            const h = Math.round(header.getBoundingClientRect().height);
            if (h === last || h === 0) return;
            last = h;
            document.documentElement.style.setProperty('--sik-header-h', h + 'px');
        };
        setHeight();

        // A ResizeObserver catches everything the resize event misses — the web
        // font landing and re-flowing the nav row is the common one, and that
        // fires no resize at all.
        if (window.ResizeObserver) {
            new ResizeObserver(setHeight).observe(header);
        } else {
            window.addEventListener('resize', SIK.debounce(setHeight, 200));
        }

        if (!header.classList.contains('sik-header--sticky')) return;

        // position:sticky keeps the header in flow, so pinning costs the page no
        // layout and nothing has to be reserved underneath it. The shadow is the
        // only cue that it has detached.
        const onScroll = SIK.throttle(function () {
            header.classList.toggle('is-stuck', window.scrollY > 8);
        }, 100);

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ----------------------------------------------------------------------
       Everything that hangs off the header - the mega menus and the account
       and notification dropdowns - is mutually exclusive. Opening one tells
       the others to shut, through this one event, so no two panels can ever
       be on screen together.
       ---------------------------------------------------------------------- */
    const PANEL_OPEN = 'sik:header-panel';

    function announcePanel(source) {
        document.dispatchEvent(new CustomEvent(PANEL_OPEN, { detail: { source: source } }));
    }

    /** Everything a keyboard user can land on. */
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), ' +
        'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function focusablesIn(root) {
        // offsetParent is null for anything display:none — the "Mark all read"
        // button when there is nothing to mark, for instance.
        return $$(FOCUSABLE, root).filter(el => el.offsetParent !== null);
    }

    /* ----------------------------------------------------------------------
       Mega menu - hover on pointer devices, click on touch
       ---------------------------------------------------------------------- */
    function initMegaMenu() {
        const items = $$('[data-nav-item]');
        if (!items.length) return;

        const hoverCapable = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        let closeTimer = null;

        const closeAll = function (except) {
            items.forEach(function (item) {
                if (item === except) return;
                item.classList.remove('is-open');
                const link = $('.sik-nav__link', item);
                if (link) link.setAttribute('aria-expanded', 'false');
            });
        };

        items.forEach(function (item) {
            const panel = $('[data-mega]', item);
            const link = $('.sik-nav__link', item);
            if (!panel || !link) return;

            const open = function () {
                clearTimeout(closeTimer);
                closeAll(item);
                announcePanel(item);
                item.classList.add('is-open');
                link.setAttribute('aria-expanded', 'true');
            };
            const close = function () {
                item.classList.remove('is-open');
                link.setAttribute('aria-expanded', 'false');
            };

            if (hoverCapable) {
                item.addEventListener('mouseenter', open);
                item.addEventListener('mouseleave', function () {
                    closeTimer = setTimeout(close, 140);
                });
            }

            link.addEventListener('click', function (e) {
                // On touch, the first tap opens the panel instead of navigating.
                if (!hoverCapable) {
                    if (!item.classList.contains('is-open')) {
                        e.preventDefault();
                        open();
                        return;
                    }
                }
            });

            link.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (!item.classList.contains('is-open')) {
                        e.preventDefault();
                        open();
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    open();
                    const first = focusablesIn(panel)[0];
                    if (first) first.focus();
                } else if (e.key === 'Escape') {
                    close();
                    link.focus();
                }
            });

            // Escape only worked while focus was still on the trigger. Once a
            // keyboard user tabbed into the panel there was no way out of it —
            // and focus has to come back to the trigger, not to the top of the
            // document, or the next Tab restarts the whole navigation.
            item.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (!item.classList.contains('is-open')) return;
                e.stopPropagation();
                close();
                link.focus();
            });

            // Tabbing past the last link in the panel should close it too,
            // otherwise the menu stays open over the page behind the user.
            item.addEventListener('focusout', function (e) {
                if (e.relatedTarget && item.contains(e.relatedTarget)) return;
                close();
            });

            // Opening by keyboard should also work for pointer users who tab in.
            link.addEventListener('focus', function () {
                if (hoverCapable) open();
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-nav-item]')) closeAll(null);
        });

        document.addEventListener(PANEL_OPEN, function (e) {
            closeAll(e.detail && e.detail.source);
        });
    }

    /* ----------------------------------------------------------------------
       Header dropdown menus - the account panel and the notification bell

       Both triggers are plain links, so without this file they still reach
       account.php and notifications.php. Everything that only makes sense once
       a panel can actually open - the ARIA state, the `hidden` attribute that
       would otherwise block the transition - is applied here.
       ---------------------------------------------------------------------- */
    function initMenus() {
        const menus = $$('[data-menu]');
        if (!menus.length) return;

        const closers = new WeakMap();

        const closeAll = function (except) {
            menus.forEach(function (menu) {
                if (menu === except) return;
                const close = closers.get(menu);
                if (close) close(false);
            });
        };

        menus.forEach(function (menu) {
            const trigger = $('[data-menu-toggle]', menu);
            const panel = $('[data-menu-panel]', menu);
            if (!trigger || !panel) return;

            // From here the panel is governed by visibility, which keeps it out
            // of the tab order just as well but can be animated.
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'false');
            if (panel.id) trigger.setAttribute('aria-controls', panel.id);

            const isOpen = () => menu.classList.contains('is-open');

            const open = function (moveFocus) {
                if (isOpen()) return;
                closeAll(menu);
                announcePanel(menu);
                menu.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                menu.dispatchEvent(new CustomEvent('sik:menu-open', { bubbles: false }));

                if (moveFocus) {
                    const first = focusablesIn(panel)[0];
                    if (first) first.focus();
                }
            };

            const close = function (restoreFocus) {
                if (!isOpen()) return;
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                if (restoreFocus) trigger.focus();
            };

            closers.set(menu, close);

            trigger.addEventListener('click', function (e) {
                e.preventDefault();      // the href is the no-JS fallback
                isOpen() ? close(false) : open(false);
            });

            trigger.addEventListener('keydown', function (e) {
                // Enter already arrives as a click on a link; Space does not.
                if (e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    isOpen() ? close(false) : open(true);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    open(true);
                } else if (e.key === 'Escape') {
                    close(true);
                }
            });

            panel.addEventListener('keydown', function (e) {
                const list = focusablesIn(panel);
                if (!list.length) return;
                const index = list.indexOf(document.activeElement);

                if (e.key === 'Escape') {
                    // Stop here: an open drawer underneath must not close too.
                    e.preventDefault();
                    e.stopPropagation();
                    close(true);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    list[(index + 1) % list.length].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    list[(index - 1 + list.length) % list.length].focus();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    list[0].focus();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    list[list.length - 1].focus();
                } else if (e.key === 'Tab') {
                    // Trapped while open: tabbing off either end wraps rather
                    // than dropping focus onto the page behind the panel.
                    if (e.shiftKey && index <= 0) {
                        e.preventDefault();
                        list[list.length - 1].focus();
                    } else if (!e.shiftKey && index === list.length - 1) {
                        e.preventDefault();
                        list[0].focus();
                    }
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-menu]')) return;
            closeAll(null);
        });

        document.addEventListener(PANEL_OPEN, function (e) {
            closeAll(e.detail && e.detail.source);
        });
    }

    /* ----------------------------------------------------------------------
       Notification panel

       The first page of the feed is rendered server-side, so the panel is
       never empty while it waits on a request. Opening it refreshes against
       the API because an order can move while the page sits open.
       ---------------------------------------------------------------------- */
    function initNotifications() {
        const trigger = $('#sikBellToggle');
        const menu = trigger ? trigger.closest('[data-menu]') : null;
        if (!menu) return;

        const list = $('[data-notifications-list]', menu);
        const readAll = $('[data-notifications-read-all]', menu);
        if (!list) return;

        let loading = false;

        const applyUnread = function (count) {
            count = Number(count) || 0;
            SIK.setBadge('[data-notification-count]', 'Notifications', count, 'unread');
            if (readAll) readAll.hidden = count === 0;
        };

        const refresh = async function () {
            if (loading) return;
            loading = true;
            list.setAttribute('aria-busy', 'true');

            const result = await SIK.get('notifications/list.php', { limit: 6 });

            loading = false;
            list.removeAttribute('aria-busy');

            if (result.aborted) return;
            if (!result.success) {
                // Leave what is already on screen rather than blanking the panel
                // over a dropped request.
                SIK.toast(result.message || 'Could not load notifications.', 'error');
                return;
            }

            list.innerHTML = result.data.html;
            applyUnread(result.data.unread);
        };

        menu.addEventListener('sik:menu-open', refresh);

        if (readAll) {
            readAll.addEventListener('click', async function () {
                if (!SIK.showLoader(readAll)) return;

                const result = await SIK.post('notifications/read.php', { all: true });
                SIK.hideLoader(readAll);

                if (!result.success) {
                    SIK.toastResult(result);
                    return;
                }

                $$('.sik-notif.is-unread', list).forEach(function (row) {
                    row.classList.remove('is-unread');
                    const dot = $('.sik-notif__dot', row);
                    if (dot) dot.remove();
                });
                applyUnread(result.data ? result.data.unread : 0);
                SIK.toast(result.message || 'All notifications marked as read.', 'success');
            });
        }

        // Reading one clears it. Not awaited: the row is a link and the write is
        // cosmetic, so making the navigation wait on it would be the worse trade.
        SIK.on('click', '[data-notification]', function () {
            if (!this.classList.contains('is-unread')) return;
            SIK.post('notifications/read.php', { id: this.dataset.notification });
        }, menu);
    }

    /* ----------------------------------------------------------------------
       Mobile accordion menu
       ---------------------------------------------------------------------- */
    function initMobileMenu() {
        SIK.on('click', '[data-mmenu-toggle]', function (e) {
            e.preventDefault();
            const item = this.closest('[data-mmenu-item]');
            if (!item) return;
            const wasOpen = item.classList.contains('is-open');

            // Only one branch open at a time keeps the drawer navigable.
            const siblings = item.parentElement ? $$(':scope > [data-mmenu-item]', item.parentElement) : [];
            siblings.forEach(s => { if (s !== item) s.classList.remove('is-open'); });

            item.classList.toggle('is-open', !wasOpen);
            this.setAttribute('aria-expanded', String(!wasOpen));
        });
    }

    /* ----------------------------------------------------------------------
       Hero slider
       ---------------------------------------------------------------------- */
    function initHero() {
        const hero = $('[data-hero]');
        if (!hero) return;

        const slides = $$('[data-hero-slide]', hero);
        if (slides.length < 2) return;

        const dots = $$('[data-hero-dot]', hero);
        const prev = $('[data-hero-prev]', hero);
        const next = $('[data-hero-next]', hero);
        const interval = parseInt(hero.dataset.autoplay || '0', 10);

        let index = slides.findIndex(s => s.classList.contains('is-active'));
        if (index < 0) index = 0;
        let timer = null;

        function show(target) {
            index = (target + slides.length) % slides.length;
            slides.forEach((slide, i) => slide.classList.toggle('is-active', i === index));
            dots.forEach((dot, i) => {
                dot.classList.toggle('is-active', i === index);
                dot.setAttribute('aria-current', String(i === index));
            });
        }

        function start() {
            if (interval > 0) { stop(); timer = setInterval(() => show(index + 1), interval); }
        }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }

        if (prev) prev.addEventListener('click', () => { show(index - 1); start(); });
        if (next) next.addEventListener('click', () => { show(index + 1); start(); });
        dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));

        hero.addEventListener('mouseenter', stop);
        hero.addEventListener('mouseleave', start);
        document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));

        // Swipe on touch devices.
        let startX = 0, startY = 0, swiping = false;
        hero.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            swiping = true;
            stop();
        }, { passive: true });

        hero.addEventListener('touchend', function (e) {
            if (!swiping) return;
            swiping = false;
            const dx = e.changedTouches[0].clientX - startX;
            const dy = e.changedTouches[0].clientY - startY;
            // Ignore mostly-vertical gestures so page scrolling still works.
            if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
                show(dx < 0 ? index + 1 : index - 1);
            }
            start();
        }, { passive: true });

        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) start();
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */
    function boot() {
        initAnnounce();
        initHeader();
        initMegaMenu();
        initMenus();
        initNotifications();
        initMobileMenu();
        initHero();

        // Highlight the active bottom-nav tab.
        const path = window.location.pathname.split('/').pop() || 'index.php';
        $$('[data-bottomnav-item]').forEach(function (item) {
            if (item.dataset.bottomnavItem === path) item.classList.add('is-current');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
