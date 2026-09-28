/* ==========================================================================
   ShopInnKart Admin - Sound
   Depends on notifications.js (SIK.toast). Loaded on every admin page.

   TWO SOUNDS, and one variant of the first:

     tick    a save landed. 45ms, a falling triangle chirp. Percussive, not
             musical - the brief asked for a "mini click", so it is a tick.
     alert   a save did NOT land. The same tick, lower and played twice.
     chime   something ARRIVED that the operator did not ask for. Two rising
             sine notes, 305ms all told.

   SYNTHESISED, NOT SHIPPED. Three reasons, in order of weight:
     1. a file can 404. This store deploys by copying a tree to shared
        hosting, and assets/sounds/ is the kind of directory that gets left
        behind; a feature whose failure mode is a broken-looking admin is
        worse than one with no files to lose.
     2. it costs no download. Two more requests on every admin page, for
        something that is OFF by default, is a bad trade.
     3. a tick is an envelope, not a recording. Everything worth
        art-directing about a 45ms blip is the attack, the decay and the
        pitch, all of which are three numbers here and can be retuned by
        reading this file - not by opening an audio editor.

   OFF BY DEFAULT, and per operator. Some of these screens are worked in
   shared rooms and on shared machines, and the one thing worse than an admin
   with no sound is an admin that makes a noise nobody asked for at a desk
   with three other people at it. So: nothing is audible, and no
   AudioContext is even constructed, until somebody presses the switch in the
   footer. The switch is a per-BROWSER preference (localStorage), not a store
   setting - which room you are sitting in is not something the store knows.

   prefers-reduced-motion is deliberately NOT consulted. It is a statement
   about motion; somebody who gets migraines from parallax has said nothing
   at all about whether they want to hear a save land. There is no reliable
   OS-level "quiet" signal to read, so the switch is the whole answer.
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};

    /* admin-sound.js is named like an optional module (admin-<name>.js), so a
       page that ever calls admin_require_script('sound') would get a second
       copy after the footer's own tag. A second copy would wrap SIK.toast a
       second time and every save would tick twice. */
    if (SIK.adminSound) { return; }

    var KEY = 'sik_admin_sound';

    /* ----------------------------------------------------------------------
       The preference.
       localStorage is not "sometimes empty" - the ACCESSOR ITSELF throws in a
       window with site data blocked, so both directions are wrapped and a
       throw is read as "off", which is also the default.
       ---------------------------------------------------------------------- */
    function readPref() {
        try {
            return window.localStorage.getItem(KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function writePref(on) {
        try {
            window.localStorage.setItem(KEY, on ? '1' : '0');
        } catch (e) { /* preference is session-only in this browser; still works */ }
    }

    var enabled = readPref();

    /* ----------------------------------------------------------------------
       The audio context.

       AUTOPLAY. A context constructed before the document has been touched
       is born 'suspended', and resume() is refused outside a user gesture.
       So: it is built lazily, on demand, and resumed from the gesture
       listener below. Every entry point returns null rather than throwing,
       because a browser that refuses to make a sound must not also fill the
       console on every page load.
       ---------------------------------------------------------------------- */
    var AC = window.AudioContext || window.webkitAudioContext || null;
    var ctx = null;
    var master = null;

    /** The context if it is RUNNING and can be scheduled on; null otherwise. */
    function live() {
        if (!AC) { return null; }
        try {
            if (!ctx) {
                ctx = new AC();
                /* One gain between every voice and the speakers, so a future
                   volume control has somewhere to live and so the peaks below
                   stay relative to one place. */
                master = ctx.createGain();
                master.gain.value = 1;
                master.connect(ctx.destination);
            }
            if (ctx.state === 'suspended') {
                /* Resolves asynchronously even inside a gesture, so this call
                   does not make the context usable on THIS tick - flush()
                   below is what plays whatever was waiting. */
                ctx.resume().then(flush, noop);
            }
            return ctx.state === 'running' ? ctx : null;
        } catch (e) {
            return null;
        }
    }

    function noop() {}

    /* ----------------------------------------------------------------------
       The voices.

       Peaks are fractions of full scale and they are LOW on purpose: an
       operator who hears the tick two hundred times in a day must not come
       to hate it, and the way a UI sound becomes hateful is by being as loud
       as the thing it is commenting on.
       ---------------------------------------------------------------------- */

    /**
     * One shaped note. o = {type, from, to, dur, peak, cut, at}.
     */
    function blip(c, o) {
        var t0 = c.currentTime + (o.at || 0);
        var osc = c.createOscillator();
        var gain = c.createGain();
        var lp = c.createBiquadFilter();

        osc.type = o.type;
        osc.frequency.setValueAtTime(o.from, t0);
        if (o.to && o.to !== o.from) {
            // A falling pitch is what makes a blip read as a physical tick
            // rather than a beep: a struck thing loses pitch as it decays.
            osc.frequency.exponentialRampToValueAtTime(o.to, t0 + o.dur);
        }

        // A triangle at 1.5kHz still has audible partials above 8k, and those
        // are the fizz that makes a short sound feel cheap. Rolling them off
        // is the difference between a tick and a spark.
        lp.type = 'lowpass';
        lp.frequency.value = o.cut;
        lp.Q.value = 0.7;

        /* 2ms of attack rather than none. A gain that steps from silence to
           peak in a single sample is a DC edge, and a DC edge is heard as a
           pop ON TOP of the note - louder than the note. 2ms is short enough
           to still read as percussive. */
        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.linearRampToValueAtTime(o.peak, t0 + 0.002);
        /* Exponential, not linear. Loudness is logarithmic, so a linear fade
           is heard as a note that HOLDS and then stops dead - a buzzer. An
           exponential ramp cannot reach 0, hence the floor. */
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + o.dur);

        osc.connect(lp);
        lp.connect(gain);
        gain.connect(master);
        osc.start(t0);
        // Stopped, not left running: an oscillator with no stop() keeps its
        // node graph alive forever, and this one fires on every save.
        osc.stop(t0 + o.dur + 0.02);
    }

    var VOICES = {
        // The tick. 45ms, 1500Hz -> 850Hz.
        tick: function (c) {
            blip(c, { type: 'triangle', from: 1500, to: 850, dur: 0.045, peak: 0.05, cut: 5200 });
        },
        /* The same gesture, an octave down and doubled. Deliberately NOT a
           new timbre: an operator should not have to learn a second sound to
           know that the second sound is the first one going wrong. */
        alert: function (c) {
            blip(c, { type: 'triangle', from: 760, to: 430, dur: 0.05, peak: 0.045, cut: 4200 });
            blip(c, { type: 'triangle', from: 760, to: 430, dur: 0.05, peak: 0.045, cut: 4200, at: 0.105 });
        },
        /* Arrival: E5 then B5, a rising fifth, sine. Rising because the thing
           it announces is news rather than a verdict, sine because the tick
           owns the percussive register and these two must not be confusable.
           305ms is the longest sound here and it is still shorter than the
           toast's own entrance animation. */
        chime: function (c) {
            blip(c, { type: 'sine', from: 659.25, to: 659.25, dur: 0.16, peak: 0.055, cut: 6000 });
            blip(c, { type: 'sine', from: 987.77, to: 987.77, dur: 0.20, peak: 0.05, cut: 6000, at: 0.105 });
        }
    };

    /* ----------------------------------------------------------------------
       Playing, and the one-slot wait.

       A sound asked for while the context is still suspended is held in a
       SINGLE slot with a deadline rather than a queue. The case this exists
       for is the main save path: POST -> redirect -> flash -> toast, where
       the toast fires on a brand new document that has had no gesture yet,
       so the tick for the save cannot play at the moment it is asked for.
       The next click or keypress plays it - if it is still young. A queue
       would fire a backlog of stale ticks at whatever the operator touched
       next, which is worse than silence.
       ---------------------------------------------------------------------- */
    var WAIT_MS = 5000;   // beyond this a held sound is stale; drop it
    var GAP_MS = 300;     // floor between two sounds: a bulk action is one tick, not nine
    var BAD_GAP_MS = 120; // ...except when the second one is the bad news
    var pending = null;
    var lastPlayedAt = 0;
    var lastKind = null;

    function render(kind) {
        var c = live();
        if (!c) { return false; }
        try {
            VOICES[kind](c);
            lastPlayedAt = Date.now();
            lastKind = kind;
            return true;
        } catch (e) {
            return false;
        }
    }

    function flush() {
        if (!pending) { return; }
        var held = pending;
        pending = null;
        if (Date.now() - held.at < WAIT_MS) { render(held.kind); }
    }

    function play(kind) {
        if (!enabled || !VOICES[kind]) { return; }

        /* A tick from a tab nobody is looking at is a noise with no referent.
           A chime is the opposite - the whole point of an arrival sound is
           that it reaches somebody who has looked away. */
        if (kind !== 'chime' && document.visibilityState === 'hidden') { return; }

        /* The floor is relaxed for bad news arriving on the heels of good. A
           bulk action that reports "3 updated" and then "2 could not be
           updated" 200ms later would otherwise have its failure swallowed by
           its success, and that is the one direction this must never fail in.
           A RUN of alerts is still throttled: nine failures are one noise. */
        var floor = (kind === 'alert' && lastKind !== 'alert') ? BAD_GAP_MS : GAP_MS;
        if (Date.now() - lastPlayedAt < floor) { return; }

        if (!render(kind)) {
            pending = { kind: kind, at: Date.now() };
        }
    }

    /* ----------------------------------------------------------------------
       What counts as "the operator did this".

       There is no flag on a toast that says who caused it, so it is inferred
       from three signals:

         - a gesture in the last GESTURE_MS;
         - a REQUEST the operator started that is still in flight, or settled
           in the last GESTURE_MS. This is the one that matters. A gesture
           window alone is a bet on how fast the server answers: the harness
           caught a save whose toast landed ~1.3s after its click being
           classed as an unprompted arrival and chiming instead of ticking,
           and a real save over a slow connection would do the same. Every
           AJAX save in the admin goes through SIK.apiRequest (SIK.post /
           SIK.get in app.js), so the round trip can be watched directly
           instead of guessed at;
         - the LOAD window. A flash replayed at DOMContentLoaded is the answer
           to a form the operator submitted on the PREVIOUS page: the gesture
           happened, just not in this document. Without this, every
           redirect-after-save would be heard as an arrival.

       The inference is deliberately biased towards "the operator did this",
       because that is the case this admin actually has. Nothing in the admin
       polls today, so a real arrival sound has no source yet - when one is
       built it should call SIK.adminSound.notify() and not rely on any of
       this.
       ---------------------------------------------------------------------- */
    var GESTURE_MS = 1500;
    var LOAD_MS = 2500;   // covers notifications.js's 260ms-per-flash stagger
    var bootAt = Date.now();
    var lastGestureAt = 0;
    var inFlight = 0;
    var lastSettledAt = 0;

    function prompted() {
        var now = Date.now();
        return inFlight > 0
            || (now - lastSettledAt) < GESTURE_MS
            || (now - lastGestureAt) < GESTURE_MS
            || (now - bootAt) < LOAD_MS;
    }

    /* Only a request that began close behind a gesture is counted. A poller,
       when one exists, will also go through apiRequest, and its answers must
       not be mistaken for something the operator asked for. */
    if (typeof SIK.apiRequest === 'function') {
        var api = SIK.apiRequest;
        SIK.apiRequest = function () {
            if ((Date.now() - lastGestureAt) >= GESTURE_MS) {
                return api.apply(this, arguments);
            }
            inFlight++;
            var settle = function (v) { inFlight--; lastSettledAt = Date.now(); return v; };
            var out;
            try {
                out = api.apply(this, arguments);
            } catch (e) {
                settle();
                throw e;
            }
            /* apiRequest is an async function so this is always a promise -
               but it is somebody else's function, so the non-promise branch
               stays rather than leaving inFlight stuck above zero forever if
               that ever changes. */
            if (out && typeof out.then === 'function') {
                /* settle on BOTH paths, and rethrow on neither: rethrowing
                   here would reject a derived promise that nobody is holding,
                   which the browser reports as an unhandled rejection. `out`
                   itself is what is returned, so the caller's own error
                   handling is untouched. */
                out.then(settle, settle);
            } else {
                settle();
            }
            return out;
        };
    }

    /** null = say nothing. */
    function classify(type) {
        if (type === 'error' || type === 'warning') { return 'alert'; }
        if (!prompted()) { return 'chime'; }
        // An operator-initiated 'info' is a line of text the operator is
        // already reading. It does not need a noise as well.
        return type === 'success' ? 'tick' : null;
    }

    /* ----------------------------------------------------------------------
       The hook.

       Wrapping SIK.toast rather than listening for clicks, because the toast
       IS how this admin reports a save: admin/includes/header.php turns every
       server-side flash into one and admin.js calls it for every AJAX result.
       Hooking the button instead would tick for a button that saved nothing,
       and would say nothing at all on the redirect-after-save path, where the
       answer arrives on a document the click never reached.

       It does NOT dodge client-side validation, and an earlier note here was
       wrong to claim it did: admin.js toasts "Select at least one row first."
       as a `warning`, and classify() above sends every warning to the alert,
       so a bulk action with nothing ticked does make the failure noise. That
       is the right answer - it IS a failure - but it is not something hooking
       the toast avoids.

       Deliberately NOT de-duplicated against the toast's own collapse: two
       saves three seconds apart collapse into one toast on screen, and they
       are still two saves and deserve two ticks. GAP_MS is what stops a burst.
       ---------------------------------------------------------------------- */
    if (typeof SIK.toast === 'function') {
        var inner = SIK.toast;
        SIK.toast = function (message, type, options) {
            var node = inner.apply(this, arguments);
            try {
                var kind = classify(type || 'info');
                if (kind) { play(kind); }
            } catch (e) { /* a sound must never break the message it comments on */ }
            return node;
        };
    }

    /* ----------------------------------------------------------------------
       Gestures: they date the toasts above AND they are the only thing that
       can unlock the context.
       ---------------------------------------------------------------------- */
    function onGesture() {
        lastGestureAt = Date.now();
        // Only if the operator has asked for sound - an admin with the switch
        // off never constructs an AudioContext at all.
        if (enabled) {
            if (live()) { flush(); }
        }
    }

    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, onGesture, { capture: true, passive: true });
    });

    /* Coming back to a backgrounded tab can leave the context suspended (and
       iOS interrupts it outright). Re-resuming here means the first save after
       lunch is not silently swallowed. */
    document.addEventListener('visibilitychange', function () {
        if (enabled && document.visibilityState === 'visible') { live(); }
    });

    /* ----------------------------------------------------------------------
       The switch.
       ---------------------------------------------------------------------- */
    var SEL = '[data-sik-sound-toggle]';

    function syncToggles() {
        var btns = document.querySelectorAll(SEL);
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('aria-pressed', enabled ? 'true' : 'false');
        }
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var btn = (t && t.closest) ? t.closest(SEL) : null;
        if (!btn) { return; }

        enabled = !enabled;
        writePref(enabled);
        syncToggles();

        // Anything already waiting for a gesture is cancelled by switching
        // off: the whole point of the switch is that the next thing is silent.
        if (!enabled) { pending = null; }

        if (enabled) {
            /* The confirmation IS the sound. A switch that says it turned
               sound on and then stays silent is indistinguishable from a
               broken one - and this click is the gesture that unlocks the
               context, so it is the one moment the tick is certain to be
               heard. The throttle is stepped over: the operator asked. */
            lastPlayedAt = 0;
            if (!render('tick')) { pending = { kind: 'tick', at: Date.now() }; }
        }
    });

    /* Two admin tabs are one operator. localStorage fires `storage` in the
       OTHER tabs only, so this keeps their switches from disagreeing. */
    window.addEventListener('storage', function (ev) {
        if (ev.key !== null && ev.key !== KEY) { return; }
        enabled = readPref();
        syncToggles();
    });

    /* The server prints aria-pressed="false" because it cannot know what this
       browser stored. This is the correction, and it happens before paint:
       the script is deferred, so the footer is already parsed. The second
       call is for the case where it is not. */
    syncToggles();
    document.addEventListener('DOMContentLoaded', syncToggles);

    /* ----------------------------------------------------------------------
       The small public surface. `notify` is what a future poller - a new
       order arriving, an alert - should call; there is nothing polling in the
       admin today, so the chime is reachable only through an unprompted
       toast and through this.
       ---------------------------------------------------------------------- */
    SIK.adminSound = {
        isEnabled: function () { return enabled; },
        set: function (on) {
            enabled = !!on;
            writePref(enabled);
            if (!enabled) { pending = null; }
            syncToggles();
        },
        tick: function () { play('tick'); },
        alert: function () { play('alert'); },
        notify: function () { play('chime'); },
        // Named for what it is: whether this browser will currently make a
        // sound at all, which is not the same question as the preference.
        canPlay: function () { return !!(enabled && AC); }
    };
})();
