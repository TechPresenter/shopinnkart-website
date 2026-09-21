/* ==========================================================================
   ShopInnKart - Voice search

   Wraps the browser's own Speech Recognition. Nothing is downloaded and no
   audio leaves the browser's own pipeline — if the engine is not there, the
   microphone button removes itself and the field behaves as a normal search
   box. The feature is entirely optional.

   States surfaced to the user: idle → listening → processing → success,
   plus explicit copy for "not supported" and "microphone blocked".
   ========================================================================== */
(function () {
    'use strict';

    window.SIK = window.SIK || {};
    const $$ = SIK.$$;

    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    /* Speech Recognition is gated on a secure context. localhost counts as
       one; plain http on any other host does not, and no browser setting
       can override that - so on an insecure origin the engine may exist
       and still refuse every single time. Treat it as unsupported rather
       than letting the shopper chase a permission they cannot grant. */
    const SECURE = window.isSecureContext !== false;
    const SUPPORTED = !!Recognition && SECURE;

    const Voice = { supported: SUPPORTED };

    /* ----------------------------------------------------------------------
       Language: follow the customer's saved preference, then the document,
       then the browser. en-IN gives noticeably better results for Indian
       product names and accents than a bare "en".
       ---------------------------------------------------------------------- */
    function preferredLang() {
        const fromConfig = (SIK.config && SIK.config.voiceLang) || '';
        if (fromConfig) return fromConfig;

        const docLang = document.documentElement.getAttribute('lang') || '';
        if (docLang && docLang.indexOf('-') !== -1) return docLang;
        if (docLang === 'en') return 'en-IN';
        return navigator.language || 'en-IN';
    }

    /* ----------------------------------------------------------------------
       One controller per microphone button
       ---------------------------------------------------------------------- */
    function attach(button) {
        if (button.dataset.voiceBound === '1') return;
        button.dataset.voiceBound = '1';

        const wrap = button.closest('[data-search]');
        const input = document.querySelector(button.dataset.voiceTarget)
            || (wrap && wrap.querySelector('input[type="search"]'));
        const status = wrap && wrap.querySelector('[data-voice-status]');

        if (!input) { button.remove(); return; }

        let recognition = null;
        let listening = false;
        let finalText = '';
        let silenceTimer = null;

        function say(message, tone) {
            if (!status) return;
            if (!message) { status.hidden = true; status.textContent = ''; return; }
            status.hidden = false;
            status.textContent = message;
            status.className = 'sik-search__voice-status' + (tone ? ' is-' + tone : '');
        }

        function setState(state) {
            button.dataset.state = state;
            wrap && wrap.classList.toggle('is-listening', state === 'listening');
            button.classList.toggle('is-listening', state === 'listening');
            button.classList.toggle('is-processing', state === 'processing');
            button.setAttribute('aria-pressed', String(state === 'listening'));
            button.setAttribute(
                'aria-label',
                state === 'listening' ? 'Stop listening' : 'Search by voice'
            );
        }

        function stop(silent) {
            listening = false;
            clearTimeout(silenceTimer);
            if (recognition) {
                try { recognition.stop(); } catch (e) { /* already stopping */ }
            }
            setState('idle');
            if (!silent) say('');
        }

        function start() {
            if (listening) { stop(); return; }

            try {
                recognition = new Recognition();
            } catch (e) {
                say('Voice search could not start in this browser.', 'error');
                return;
            }

            recognition.lang = preferredLang();
            recognition.interimResults = true;
            recognition.continuous = false;
            recognition.maxAlternatives = 1;

            finalText = '';

            recognition.onstart = function () {
                listening = true;
                setState('listening');
                say('Listening… say a product, brand or category.', 'listening');

                // Some engines never fire onend if the room is silent.
                clearTimeout(silenceTimer);
                silenceTimer = setTimeout(function () {
                    if (listening) {
                        stop(true);
                        say('Didn’t catch that. Tap the microphone to try again.', 'warn');
                    }
                }, 9000);
            };

            recognition.onresult = function (event) {
                let interim = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const chunk = event.results[i][0].transcript;
                    if (event.results[i].isFinal) finalText += chunk;
                    else interim += chunk;
                }

                // Show the partial transcript live so the user can see it working.
                const shown = (finalText + interim).trim();
                if (shown) {
                    input.value = shown;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }

                if (finalText.trim()) {
                    clearTimeout(silenceTimer);
                    setState('processing');
                    say('Searching for “' + finalText.trim() + '”', 'success');
                }
            };

            recognition.onerror = function (event) {
                listening = false;
                clearTimeout(silenceTimer);
                setState('idle');

                // Only a secure page can be fixed from browser settings, so only
                // there is it honest to send the shopper looking.
                const blocked = SECURE
                    ? 'Microphone access is blocked. Allow it in your browser settings to search by voice.'
                    : 'Voice search needs a secure (https) connection, so it is not available here.';

                const messages = {
                    'not-allowed':    blocked,
                    'service-not-allowed': blocked,
                    'no-speech':      'We didn’t hear anything. Tap the microphone and try again.',
                    'audio-capture':  'No microphone found on this device.',
                    'network':        'Voice search needs a connection. Check your network and try again.',
                    'aborted':        ''
                };
                const message = messages[event.error];

                if (message === '') { say(''); return; }
                say(message || 'Voice search stopped unexpectedly. Please try again.', 'error');

                // Only a missing microphone is permanent. A denied permission can
                // be granted a moment later, and disabling the button for the rest
                // of the page forces a reload to try again.
                if (event.error === 'audio-capture') {
                    button.disabled = true;
                    button.classList.add('is-disabled');
                }
            };

            recognition.onend = function () {
                listening = false;
                clearTimeout(silenceTimer);

                const query = finalText.trim();
                if (!query) { setState('idle'); return; }

                setState('idle');
                say('Searching for “' + query + '”', 'success');

                input.value = query;
                if (SIK.search && typeof SIK.search.remember === 'function') {
                    SIK.search.remember(query);
                }

                // Submit after a beat so the transcript is readable first.
                setTimeout(function () {
                    const form = input.closest('form');
                    if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
                }, 550);
            };

            try {
                recognition.start();
            } catch (e) {
                // start() throws if a previous session has not finished.
                listening = false;
                setState('idle');
            }
        }

        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            start();
        });

        // Escape cancels a live session without submitting.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && listening) stop();
        });
    }

    /* ----------------------------------------------------------------------
       Boot
       ---------------------------------------------------------------------- */
    function init() {
        const buttons = $$('[data-voice-search]');
        if (!buttons.length) return;

        if (!SUPPORTED) {
            // Remove rather than disable: a permanently dead control is worse
            // than no control. The status line explains it if anyone asks.
            buttons.forEach(function (button) {
                const wrap = button.closest('[data-search]');
                button.remove();
                if (wrap) wrap.classList.add('sik-search--no-voice');
            });
            return;
        }

        buttons.forEach(attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Search bars injected later (mobile drawer, quick view) get wired too.
    document.addEventListener('sik:refresh', init);

    Voice.init = init;
    SIK.voice = Voice;
})();
