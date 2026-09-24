/* ==========================================================================
   ShopInnKart - Consent banner.

   Loaded (deferred) by includes/consent.php, and ONLY when that file decides
   there is something to consent to. A store with no GA / GTM / Pixel / Clarity
   id and an anonymous analytics mode never ships this file at all.

   Contract - window.SIK_CONSENT, printed by consent_js_config():
     cookie      string   cookie name ("sik_consent")
     path        string   cookie path
     secure      bool     add ;secure
     maxAge      int      cookie lifetime in seconds (6 months)
     honorDnt    bool     owner switch: honour DNT / GPC
     signal      string   ''|'gpc'|'dnt' as the SERVER saw it
     decided     bool     a valid answer is already stored
     categories  array    which of analytics|marketing the banner offers
     granted     object   {analytics:bool, marketing:bool}
     tags        object   {ga,gtm,pixel,clarity} - present only while NOT yet
                          granted, so accepting starts them without a reload

   Public API for the rest of the site (documented, deliberately small):
     SIK.consent.allows('analytics'|'marketing'|'necessary') -> bool
     SIK.consent.state()      -> {analytics, marketing, decided, signal}
     SIK.consent.open()       -> reopen the banner with the choices expanded
     SIK.consent.set(a, m)    -> store an answer programmatically
     SIK.consent.onChange(fn) -> fn(state) on every stored answer, and once now

   The banner writes the cookie itself: no endpoint, no round trip, nothing to
   rate-limit, and no way for a network failure to lose somebody's refusal.
   ========================================================================== */
(function () {
    'use strict';

    var C = window.SIK_CONSENT;
    if (!C) { return; }

    var doc = document;
    var root = doc.getElementById('sikConsent');
    var listeners = [];
    var lastFocus = null;

    var state = {
        analytics: !!(C.granted && C.granted.analytics),
        marketing: !!(C.granted && C.granted.marketing),
        decided: !!C.decided,
        signal: C.signal || ''
    };

    /* ---------------------------------------------------------------------
       DNT / GPC.

       The server already checked the Sec-GPC and DNT request headers. This is
       the second half: a browser can expose navigator.doNotTrack without ever
       sending the header, and that visitor's answer is still no.
       --------------------------------------------------------------------- */
    function clientSignal() {
        if (!C.honorDnt) { return ''; }
        if (navigator.globalPrivacyControl === true) { return 'gpc'; }
        var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
        return (dnt === '1' || dnt === 'yes') ? 'dnt' : '';
    }

    var signal = state.signal || clientSignal();

    /* --- cookie ---------------------------------------------------------- */

    function writeCookie(a, m) {
        var value = 'v1.a' + (a ? 1 : 0) + '.m' + (m ? 1 : 0) + '.' + Math.floor(Date.now() / 1000);
        doc.cookie = C.cookie + '=' + value +
            ';path=' + C.path +
            ';max-age=' + C.maxAge +
            ';samesite=Lax' +
            (C.secure ? ';secure' : '');
    }

    /* --- the third-party tags -------------------------------------------
       The same four loaders consent_tag_html() prints server-side. They live
       in both places because both paths are real: a visitor who accepted on an
       earlier page gets them in the HTML, and a visitor who accepts right now
       gets them here rather than after a reload that would lose their scroll
       position and any half-typed form.
       --------------------------------------------------------------------- */
    function script(src) {
        var s = doc.createElement('script');
        s.async = true;
        s.src = src;
        doc.head.appendChild(s);
    }

    function loadTags() {
        var t = C.tags;
        if (!t) { return; }
        C.tags = null;                       // once only, however many clicks

        if (t.ga || t.gtm) {
            window.dataLayer = window.dataLayer || [];
            if (!window.gtag) {
                window.gtag = function () { window.dataLayer.push(arguments); };
            }
            window.gtag('consent', 'default', {
                ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
                analytics_storage: 'denied', functionality_storage: 'granted', security_storage: 'granted'
            });
            window.gtag('consent', 'update', {
                ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted',
                analytics_storage: 'granted'
            });
        }
        if (t.gtm) {
            window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
            script('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(t.gtm));
        }
        if (t.ga) {
            script('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(t.ga));
            window.gtag('js', new Date());
            window.gtag('config', t.ga);
        }
        if (t.pixel) {
            var f = window;
            if (!f.fbq) {
                var n = f.fbq = function () {
                    n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
                };
                if (!f._fbq) { f._fbq = n; }
                n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
                script('https://connect.facebook.net/en_US/fbevents.js');
            }
            f.fbq('init', t.pixel);
            f.fbq('track', 'PageView');
        }
        if (t.clarity) {
            window.clarity = window.clarity || function () {
                (window.clarity.q = window.clarity.q || []).push(arguments);
            };
            script('https://www.clarity.ms/tag/' + encodeURIComponent(t.clarity));
        }
    }

    /* --- state ----------------------------------------------------------- */

    function publish() {
        for (var i = 0; i < listeners.length; i++) {
            try { listeners[i](snapshot()); } catch (e) { /* one bad listener must not break the rest */ }
        }
    }

    function snapshot() {
        return {
            analytics: state.analytics, marketing: state.marketing,
            decided: state.decided, signal: signal
        };
    }

    function store(a, m) {
        if (signal) { a = false; m = false; }     // the signal always wins
        state.analytics = !!a;
        state.marketing = !!m;
        state.decided = true;
        writeCookie(state.analytics, state.marketing);
        if (state.marketing) { loadTags(); }
        publish();
    }

    /* --- the banner ------------------------------------------------------ */

    function show(expand) {
        if (!root) { return; }
        lastFocus = doc.activeElement;
        root.hidden = false;
        // A repaint between removing [hidden] and adding the class is what lets
        // the transition run at all; it is skipped under reduced motion by CSS.
        requestAnimationFrame(function () { root.classList.add('is-open'); });
        if (expand) { toggleChoices(true); }
        try { root.focus({ preventScroll: true }); } catch (e) { root.focus(); }
    }

    function hide() {
        if (!root) { return; }
        root.classList.remove('is-open');
        root.hidden = true;
        if (lastFocus && lastFocus.focus) {
            try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* gone from the DOM */ }
        }
        lastFocus = null;
    }

    function toggleChoices(open) {
        var panel = doc.getElementById('sikConsentChoices');
        var button = root && root.querySelector('[data-consent-choose]');
        if (!panel) { return; }
        panel.hidden = !open;
        if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function switches() {
        return root ? root.querySelectorAll('[data-consent-category]') : [];
    }

    function readSwitches() {
        var picked = { analytics: false, marketing: false };
        var boxes = switches();
        for (var i = 0; i < boxes.length; i++) {
            picked[boxes[i].getAttribute('data-consent-category')] = boxes[i].checked;
        }
        return picked;
    }

    function syncSwitches() {
        var boxes = switches();
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = !!state[boxes[i].getAttribute('data-consent-category')];
        }
    }

    function answer(a, m) {
        store(a, m);
        hide();
    }

    if (root) {
        root.addEventListener('click', function (event) {
            var target = event.target.closest('button');
            if (!target) { return; }

            if (target.hasAttribute('data-consent-accept')) {
                answer(true, true);
            } else if (target.hasAttribute('data-consent-reject')) {
                answer(false, false);
            } else if (target.hasAttribute('data-consent-save')) {
                var picked = readSwitches();
                answer(picked.analytics, picked.marketing);
            } else if (target.hasAttribute('data-consent-choose')) {
                toggleChoices(target.getAttribute('aria-expanded') !== 'true');
            } else if (target.hasAttribute('data-consent-dismiss')) {
                hide();
            }
        });

        root.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') { return; }
            var panel = doc.getElementById('sikConsentChoices');
            if (panel && !panel.hidden) {
                toggleChoices(false);
                var button = root.querySelector('[data-consent-choose]');
                if (button) { button.focus(); }
                return;
            }
            // Escape closes the banner only when an answer already exists.
            // Dismissing an unanswered banner is not an answer, and treating it
            // as one in either direction would be the dark pattern this avoids.
            if (state.decided) { hide(); }
        });
    }

    /* "Cookie preferences", anywhere on the page. */
    doc.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-consent-open]');
        if (!opener) { return; }
        event.preventDefault();
        syncSwitches();
        show(true);
    });

    /* --- boot ------------------------------------------------------------ */

    if (signal) {
        if (root) { root.classList.add('is-signal'); }
        // The server did not see a header but this browser says no: clear any
        // stored grant so the next page view is clean as well.
        if (state.analytics || state.marketing) {
            state.analytics = state.marketing = false;
            writeCookie(false, false);
            if (window.gtag) {
                window.gtag('consent', 'update', {
                    ad_storage: 'denied', ad_user_data: 'denied',
                    ad_personalization: 'denied', analytics_storage: 'denied'
                });
            }
        }
        state.decided = true;
    } else if (!state.decided) {
        show(false);
    }

    window.SIK = window.SIK || {};
    window.SIK.consent = {
        allows: function (category) {
            if (category === 'necessary') { return true; }
            return category === 'analytics' ? state.analytics
                 : category === 'marketing' ? state.marketing : false;
        },
        state: snapshot,
        open: function () { syncSwitches(); show(true); },
        set: function (a, m) { store(a, m); syncSwitches(); },
        onChange: function (fn) {
            if (typeof fn !== 'function') { return; }
            listeners.push(fn);
            fn(snapshot());
        }
    };

    // Anything that loaded before this file can still ask, without a race.
    doc.dispatchEvent(new CustomEvent('sik:consent', { detail: snapshot() }));
}());
