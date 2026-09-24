/**
 * ShopInnKart - first-party analytics beacon.
 *
 * Loaded by includes/footer.php ONLY when the server has already decided this
 * page view is counted: tracking on, no DNT/GPC, not an admin, not a bot. A
 * store with analytics off never ships this file at all.
 *
 * Contract with the server (api/analytics/collect.php):
 *   window.SIK_CONFIG.an = { u: collector URL, t: signed page token,
 *                            pt: page type, id: entity id,
 *                            hb: heartbeat seconds, v: measure vitals }
 *
 * Design rules, in order of importance:
 *   1. It must never break or slow the page. Everything is in a try/catch,
 *      every listener is passive, and every send is sendBeacon (which the
 *      browser flushes on its own schedule, off the main thread).
 *   2. It sends no identifier. There is no cookie here and no localStorage:
 *      the server works out who this is, from data it throws away.
 *   3. It never reports money. add to cart, checkout and purchase are written
 *      server-side where the rows are written - an ad blocker cannot hide them
 *      and a console cannot invent them.
 */
(function () {
    'use strict';

    var C = (window.SIK_CONFIG || {}).an;
    if (!C || !C.u || !C.t) { return; }

    // Automation and privacy signals. The server checks the headers; this is
    // the half only the browser knows (a GPC property with no header, an
    // automated browser that sends a perfectly ordinary UA).
    if (navigator.webdriver === true) { return; }
    if (navigator.globalPrivacyControl === true) { return; }
    var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
    if (dnt === '1' || dnt === 'yes') { return; }

    var IDLE_MS = 1800000;          // 30 min with no input stops the clock
    var MAX_HB = 30;                // matches the server's per-token budget

    var seq = 0;                    // bfcache re-shows: a new pv on the same token
    var engaged = 0;                // foreground ms on this page view
    var sentEngaged = 0;            // what the last beacon already reported
    var maxScroll = 0;
    var hbCount = 0;
    var lastInput = Date.now();
    var ticker = null;
    var ended = false;              // an end beacon has gone for this page view
    var vitalsSent = false;         // the load metrics travel exactly once

    // -----------------------------------------------------------------------
    //  Transport
    // -----------------------------------------------------------------------

    function send(kind, extra) {
        try {
            var body = new URLSearchParams();
            body.set('t', C.t);
            body.set('k', kind);
            for (var k in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, k) && extra[k] !== null && extra[k] !== undefined && extra[k] !== '') {
                    body.set(k, String(extra[k]));
                }
            }

            // sendBeacon survives the page being closed, which is exactly when
            // the end beacon fires. URLSearchParams arrives as an ordinary form
            // POST, so the server reads it from $_POST.
            if (navigator.sendBeacon && navigator.sendBeacon(C.u, body)) { return; }

            fetch(C.u, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', mode: 'same-origin' })
                .catch(function () { /* a lost beacon is a lost count, nothing more */ });
        } catch (e) { /* never throw into the page */ }
    }

    // -----------------------------------------------------------------------
    //  What page is this? (the server re-checks all of it)
    // -----------------------------------------------------------------------

    var CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid', 'ttclid', 'li_fat_id'];

    /**
     * Path plus the only query parameters that may leave the browser.
     *
     * Everything else is dropped here as well as on the server: this page's
     * URL may hold a password-reset token, an order number or an email
     * address, and none of those belong in an analytics table.
     */
    function currentPath() {
        var url = new URL(location.href);
        var keep = new URLSearchParams();
        var p = url.searchParams;

        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) {
            if (p.get(k)) { keep.set(k, p.get(k).slice(0, 100)); }
        });
        CLICK_IDS.forEach(function (k) {
            if (p.get(k)) { keep.set(k, '1'); }   // presence only; the id IS the identifier
        });
        if (p.get('page') && /^\d{1,4}$/.test(p.get('page'))) { keep.set('page', p.get('page')); }
        if (C.pt === 5 && p.get('q')) { keep.set('q', p.get('q').slice(0, 100)); }

        var qs = keep.toString();
        return url.pathname + (qs ? '?' + qs : '');
    }

    /** The referrer's host, and never anything after it. */
    function referrerHost() {
        try {
            if (!document.referrer) { return ''; }
            var h = new URL(document.referrer).hostname;
            return h === location.hostname ? '' : h;
        } catch (e) { return ''; }
    }

    function pageview() {
        var ua = navigator.userAgentData;
        send('pv', {
            u: currentPath(),
            r: referrerHost(),
            w: window.innerWidth || 0,
            tp: navigator.maxTouchPoints || 0,
            m: ua ? (ua.mobile ? 1 : 0) : '',
            p: ua ? ua.platform : '',
            // navigator.language used to be sent here as `l`. Nothing on the
            // server ever read it - geo.php explicitly refuses to guess a
            // country from a language preference - so it was a fingerprinting
            // bit travelling for no purpose. A measurement system should send
            // what it uses and nothing else; put it back the day something
            // reads it.
            s: seq
        });
    }

    // -----------------------------------------------------------------------
    //  Engagement and scroll
    // -----------------------------------------------------------------------

    function tick() {
        if (document.visibilityState !== 'visible') { return; }
        if (Date.now() - lastInput > IDLE_MS) { return; }   // open tab, nobody there
        engaged += 1000;
    }

    function startTicking() {
        if (ticker === null) { ticker = setInterval(tick, 1000); }
    }

    function stopTicking() {
        if (ticker !== null) { clearInterval(ticker); ticker = null; }
    }

    /**
     * How far down this page the visitor has got, as a percentage.
     *
     * Called on every scroll AND once when the page starts, which is not a
     * belt-and-braces call: a page that fits the window cannot be scrolled, so
     * a depth measured only from scroll events stays at 0 for it forever. The
     * report would then say nobody read the short pages - the ones most likely
     * to have been read to the end.
     */
    var scrollQueued = false;
    function onScroll() {
        if (scrollQueued) { return; }
        scrollQueued = true;
        requestAnimationFrame(function () {
            scrollQueued = false;
            var h = document.documentElement;
            var total = Math.max(h.scrollHeight, document.body ? document.body.scrollHeight : 0);
            if (total <= 0) { return; }
            var pct = Math.round(((window.scrollY || h.scrollTop) + window.innerHeight) / total * 100);
            if (pct > maxScroll) { maxScroll = Math.min(100, pct); }
        });
    }

    function heartbeat() {
        if (ended || hbCount >= MAX_HB) { return; }
        if (document.visibilityState !== 'visible') { return; }
        var delta = engaged - sentEngaged;
        if (delta <= 0) { return; }       // nothing happened; do not spend a hit
        hbCount++;
        sentEngaged = engaged;
        send('hb', { q: seq, e: engaged, d: delta, s: maxScroll });
    }

    /**
     * The last word on this page view: engagement, scroll and the vitals.
     *
     * It can run more than once, because "hidden" is not "gone": a shopper who
     * switches tabs and comes back is still reading this page, and a single
     * end beacon would stop counting them for good. The server takes the
     * GREATEST of what it is told, so a second end can only raise a number.
     * The vitals go with the FIRST one only - they describe how this page
     * loaded, they do not change, and sending them twice would count one slow
     * page as two in the percentile.
     */
    function end() {
        if (ended) { return; }
        ended = true;
        stopTicking();
        var payload = { q: seq, e: engaged, d: Math.max(0, engaged - sentEngaged), s: maxScroll };
        sentEngaged = engaged;
        if (!vitalsSent) { vitalsSent = true; finalizeVitals(payload); }
        send('end', payload);
    }

    // -----------------------------------------------------------------------
    //  Core Web Vitals
    //
    //  Measured here rather than vendored: web-vitals is 5 KB for five numbers
    //  and would need a build step this project does not have. The awkward
    //  parts - CLS session windows, INP's percentile - are copied from its
    //  documented algorithm, not invented.
    // -----------------------------------------------------------------------

    var vitals = {};
    var clsValue = 0, clsWindow = 0, clsFirst = 0, clsLast = 0;
    var interactions = {};

    function observe(type, cb, opts) {
        try {
            var po = new PerformanceObserver(function (list) { cb(list.getEntries(), po); });
            po.observe(Object.assign({ type: type, buffered: true }, opts || {}));
            return po;
        } catch (e) { return null; }
    }

    if (C.v && window.PerformanceObserver) {
        // LCP: keep the latest candidate. It stops changing at the first
        // interaction or when the page is hidden, which is when we read it.
        observe('largest-contentful-paint', function (entries) {
            vitals.lcp = entries[entries.length - 1].startTime;
        });

        observe('paint', function (entries) {
            entries.forEach(function (e) {
                if (e.name === 'first-contentful-paint') { vitals.fcp = e.startTime; }
            });
        });

        // CLS: the largest burst of shifts inside a 5 s window whose gaps are
        // under 1 s. A running total would punish a long page for shifting
        // twice an hour apart.
        observe('layout-shift', function (entries) {
            entries.forEach(function (e) {
                if (e.hadRecentInput) { return; }   // the visitor caused it
                if (clsWindow && e.startTime - clsLast < 1000 && e.startTime - clsFirst < 5000) {
                    clsWindow += e.value;
                } else {
                    clsWindow = e.value;
                    clsFirst = e.startTime;
                }
                clsLast = e.startTime;
                if (clsWindow > clsValue) { clsValue = clsWindow; }
            });
        });

        // INP: the worst interaction, or the (n/50)th worst on a page with
        // fifty or more - one unlucky tap should not define the page.
        var record = function (entries) {
            entries.forEach(function (e) {
                var id = e.interactionId;
                if (!id) { return; }
                if (!interactions[id] || e.duration > interactions[id]) { interactions[id] = e.duration; }
            });
        };
        observe('event', record, { durationThreshold: 40 });
        observe('first-input', record);

        try {
            var nav = performance.getEntriesByType('navigation')[0];
            if (nav) { vitals.ttfb = Math.max(0, nav.responseStart - (nav.activationStart || 0)); }
        } catch (e) { /* no navigation timing */ }
    }

    function finalizeVitals(payload) {
        if (!C.v) { return; }
        if (vitals.lcp) { payload.lcp = Math.round(vitals.lcp); }
        if (vitals.fcp) { payload.fcp = Math.round(vitals.fcp); }
        if (vitals.ttfb) { payload.ttfb = Math.round(vitals.ttfb); }
        if (clsValue > 0) { payload.cls = Math.round(clsValue * 1000); }

        var list = [];
        for (var id in interactions) {
            if (Object.prototype.hasOwnProperty.call(interactions, id)) { list.push(interactions[id]); }
        }
        if (list.length) {
            list.sort(function (a, b) { return b - a; });
            payload.inp = Math.round(list[Math.min(Math.floor(list.length / 50), list.length - 1)]);
        }
    }

    // -----------------------------------------------------------------------
    //  Public: client-only events. Never money.
    // -----------------------------------------------------------------------

    var ALLOWED = ['outbound', 'download', 'share', 'video_play', 'filter_use', 'popup_view', 'popup_convert'];
    var eventCount = 0;

    window.SIK = window.SIK || {};
    window.SIK.track = function (name, props) {
        if (ALLOWED.indexOf(name) === -1 || eventCount >= 20) { return; }
        eventCount++;
        send('ev', { n: name, q: seq, lb: props && props.label ? String(props.label).slice(0, 100) : '' });
    };

    // -----------------------------------------------------------------------
    //  Wiring
    // -----------------------------------------------------------------------

    function start() {
        pageview();
        startTicking();
        setInterval(heartbeat, (C.hb || 60) * 1000);

        ['keydown', 'pointerdown', 'scroll'].forEach(function (evt) {
            addEventListener(evt, function () { lastInput = Date.now(); }, { passive: true, capture: true });
        });
        addEventListener('scroll', onScroll, { passive: true });
        // The starting depth: what is on screen before anybody scrolls. On a
        // page that fits the window this is the only measurement there will
        // ever be, and it is 100%.
        onScroll();
        // A rotation or a resized window changes how much of the page is
        // visible without any scrolling at all.
        addEventListener('resize', onScroll, { passive: true });

        addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                stopTicking();
                end();
            } else {
                // Back on this tab: the page view is not over after all. The
                // clock restarts and a later end beacon is allowed again,
                // because otherwise every shopper who glances at another tab
                // is recorded as having stopped reading at that moment.
                ended = false;
                lastInput = Date.now();
                startTicking();
            }
        });

        // pagehide covers the browsers where visibilitychange does not fire on
        // a real unload (older Safari).
        addEventListener('pagehide', end);

        // Restored from the back/forward cache: the page is being viewed again,
        // so it is a new page view on the same token, with fresh counters.
        addEventListener('pageshow', function (e) {
            if (!e.persisted) { return; }
            seq++;
            // vitalsSent is deliberately NOT reset: a restored page did not
            // load again, so the LCP and TTFB still in memory belong to the
            // first view. Sending them with the new sequence would count one
            // slow load twice in the percentile.
            engaged = 0; sentEngaged = 0; maxScroll = 0; hbCount = 0; ended = false;
            lastInput = Date.now();
            startTicking();
            pageview();
        });
    }

    // A prerendered page is not a visit until it is shown.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', start, { once: true });
    } else {
        start();
    }
})();
