<?php
/**
 * ShopInnKart - Consent layer and third-party tag gating.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Until now includes/footer.php loaded Google Tag Manager, Google Analytics
 * and the Meta Pixel unconditionally, on every storefront page, for every
 * visitor, with no consent asked anywhere and nothing in the UI to refuse
 * them. The Cookie Policy meanwhile told shoppers those scripts "are not part
 * of the application" and that analytics and marketing cookies could be
 * refused - neither of which was true, because there was no mechanism.
 *
 * This file is the mechanism. Everything about "may we run somebody else's
 * script in this visitor's browser" is decided here and nowhere else, so the
 * answer cannot drift between the footer, a landing page and the policy text.
 *
 * TWO DIFFERENT THINGS, AND WHY ONLY ONE OF THEM ASKS
 * ---------------------------------------------------
 *   1. Third-party tags (GA, GTM, Meta Pixel, Clarity). These hand the
 *      visitor's IP, page URL and a durable advertising identifier to another
 *      company. They need consent, and they get it: nothing third-party is
 *      emitted or injected until `marketing` is granted.
 *
 *   2. Our own first-party analytics (Track B, phases B2-B5). In the owner's
 *      chosen `anonymous` mode it sets NO cookie, stores NO IP and creates no
 *      device identifier, so there is nothing to consent to and no banner is
 *      shown for it. Switching the mode to `consent_only` or `notice` changes
 *      that - those modes do put an identifier on the device - and the banner
 *      then grows an Analytics category on its own.
 *
 * The admin help text on Settings > Analytics says the same thing, because the
 * failure mode here is an owner switching off the wrong one and believing the
 * store got more private when only the counting stopped.
 *
 * THE COOKIE
 * ----------
 *   sik_consent = v1.a{0|1}.m{0|1}.{unixtime}
 *
 * First-party, SameSite=Lax, Secure where the session cookie is secure,
 * 6 months. It carries no identifier: three bits and a timestamp, the same
 * value for everyone who answers the same way. The timestamp is what lets a
 * stale answer lapse and re-ask, and what a policy change can invalidate.
 *
 * NO DARK PATTERN
 * ---------------
 * Accept, Reject and Choose are one button class, one size, one row, and
 * Reject is a single click exactly like Accept. Closing the banner without
 * answering does not count as consent, and a refusal is stored (not merely
 * "not accepted") so it survives a reload instead of re-asking on every page.
 */

declare(strict_types=1);

// Direct access guard. SIK_LEAN_BOOTSTRAP is the analytics collector, which
// boots config + database + helpers deliberately WITHOUT init.php (no session,
// no mailer, no cart) and still has to ask this file what the visitor allowed.
// It is a second legitimate bootstrap, not a way in from the web.
if (!defined('SIK_BOOTSTRAPPED') && !defined('SIK_LEAN_BOOTSTRAP')) {
    http_response_code(404);
    exit;
}

/** Cookie name. Public: assets/js/consent.js is told this, it does not guess. */
const CONSENT_COOKIE = 'sik_consent';

/** Value format version. Bump to invalidate every stored answer at once. */
const CONSENT_VERSION = 'v1';

/** 6 months, in seconds. Also how long a stored answer stays valid. */
const CONSENT_LIFETIME = 15552000;

/** The categories a visitor can decide. `necessary` is not one of them. */
const CONSENT_CATEGORIES = ['analytics', 'marketing'];

// ===========================================================================
//  WHAT THE BROWSER IS TELLING US
// ===========================================================================

/**
 * A Do Not Track / Global Privacy Control signal on this request, or ''.
 *
 * GPC is checked first because it is the one with legal weight in some
 * jurisdictions and the one browsers still send. DNT is kept because Firefox
 * still sends it and some visitors still set it; honouring a signal nobody
 * enforces costs us nothing and is what the policy page now promises.
 *
 * Only the headers are visible here. `navigator.globalPrivacyControl` and
 * `navigator.doNotTrack` are checked again in consent.js, for the browser that
 * exposes the property without sending the header.
 */
function consent_privacy_signal(): string
{
    if ((string) ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') {
        return 'gpc';
    }
    if ((string) ($_SERVER['HTTP_DNT'] ?? '') === '1') {
        return 'dnt';
    }
    return '';
}

/** Does the owner want DNT/GPC honoured? Default yes, and it should stay yes. */
function consent_honours_signals(): bool
{
    return setting_bool('analytics_honor_dnt', true);
}

/** Is a signal present AND honoured? Then the answer is no, whatever is stored. */
function consent_signal_active(): bool
{
    return consent_honours_signals() && consent_privacy_signal() !== '';
}

// ===========================================================================
//  THE STORED ANSWER
// ===========================================================================

/**
 * Parse a cookie value into ['analytics'=>bool,'marketing'=>bool,'at'=>int].
 *
 * Anything that is not exactly the shape we write is treated as absent rather
 * than half-read: a mangled cookie must never resolve to "accepted".
 */
function consent_parse(string $raw): ?array
{
    if (preg_match('/^v1\.a([01])\.m([01])\.(\d{1,12})$/', $raw, $m) !== 1) {
        return null;
    }

    $at = (int) $m[3];

    // A timestamp in the future is clock skew or a forged value; a very old one
    // has simply lapsed. Both mean "ask again" rather than "assume yes".
    if ($at <= 0 || $at > time() + 86400 || $at < time() - CONSENT_LIFETIME) {
        return null;
    }

    return [
        'analytics' => $m[1] === '1',
        'marketing' => $m[2] === '1',
        'at'        => $at,
    ];
}

/** Serialise an answer into the cookie value. */
function consent_encode(bool $analytics, bool $marketing, ?int $at = null): string
{
    return CONSENT_VERSION
        . '.a' . ($analytics ? '1' : '0')
        . '.m' . ($marketing ? '1' : '0')
        . '.' . ($at ?? time());
}

/** The answer this browser sent, or null when it has not answered. */
function consent_stored(): ?array
{
    static $parsed = false;

    if ($parsed === false) {
        $raw    = (string) ($_COOKIE[CONSENT_COOKIE] ?? '');
        $parsed = $raw === '' ? null : consent_parse($raw);
    }

    return $parsed;
}

/**
 * Write the answer from PHP.
 *
 * consent.js normally writes this itself - that is what keeps answering the
 * banner a request-free interaction - but the server needs the same ability
 * for tests and for any future no-JS path, and the flags have to match exactly
 * or the two would write two different cookies.
 */
function consent_store(bool $analytics, bool $marketing): void
{
    $value = consent_encode($analytics, $marketing);

    if (!headers_sent()) {
        setcookie(CONSENT_COOKIE, $value, [
            'expires'  => time() + CONSENT_LIFETIME,
            'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
            'secure'   => session_cookie_secure(),
            // Readable by consent.js on purpose: the banner has to know what
            // was chosen without a round trip, and the value is not a secret.
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    $_COOKIE[CONSENT_COOKIE] = $value;
}

// ===========================================================================
//  THE RESOLVED STATE
// ===========================================================================

/**
 * Everything the page needs, in one answer.
 *
 * Resolution order, strictest first:
 *   1. an honoured DNT/GPC signal - the browser already said no;
 *   2. the signed-in admin - the shop and the back office share one session,
 *      and the owner's own page views are not the store's traffic;
 *   3. a page that opted out of everything third-party (reset-password.php,
 *      whose URL carries a live token that a tag container would report);
 *   4. the stored cookie;
 *   5. nothing - undecided, which means denied until asked.
 */
function consent_state(): array
{
    static $state = null;

    if ($state !== null) {
        return $state;
    }

    $signal  = consent_honours_signals() ? consent_privacy_signal() : '';
    $stored  = consent_stored();
    $isAdmin = function_exists('admin_user') && admin_user() !== null;
    $noThird = !empty($GLOBALS['SIK_NO_THIRD_PARTY']);

    $state = [
        'analytics' => false,
        'marketing' => false,
        'decided'   => $stored !== null,
        'at'        => $stored['at'] ?? 0,
        'signal'    => $signal,
        'admin'     => $isAdmin,
        'suppress'  => $noThird,
    ];

    if ($signal !== '') {
        // A signal is an answer, so the banner does not nag on top of it.
        $state['decided'] = true;
        return $state;
    }

    if ($stored !== null) {
        $state['analytics'] = $stored['analytics'];
        $state['marketing'] = $stored['marketing'];
    }

    return $state;
}

/** Has this visitor allowed `analytics` or `marketing`? */
function consent_allows(string $category): bool
{
    if (!in_array($category, CONSENT_CATEGORIES, true)) {
        // `necessary` is always on; anything unknown never is. Callers get a
        // straight answer either way rather than a warning.
        return $category === 'necessary';
    }

    $state = consent_state();

    return $state[$category] && !$state['admin'] && !$state['suppress'];
}

// ===========================================================================
//  THE TAGS
// ===========================================================================

/**
 * Configured third-party ids, validated against their real formats.
 *
 * The validation is not cosmetic: these values end up inside a script URL and
 * a JS call, so anything that is not a short token from the known alphabet is
 * a paste error or an injection attempt, and is dropped rather than printed.
 *
 * A blank entry means "not configured", which is how a store that uses none of
 * them ships no third-party script and no banner at all.
 */
function consent_tag_ids(): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $match = static function (string $key, string $pattern): string {
        $value = trim((string) setting($key, ''));
        return preg_match($pattern, $value) === 1 ? $value : '';
    };

    $ids = [
        'ga'      => $match('google_analytics_id',   '/^(G-[A-Z0-9]{4,15}|UA-\d{4,10}-\d{1,4}|AW-\d{6,15})$/i'),
        'gtm'     => $match('google_tag_manager_id', '/^GTM-[A-Z0-9]{4,10}$/i'),
        'pixel'   => $match('meta_pixel_id',         '/^\d{10,20}$/'),
        'clarity' => $match('clarity_project_id',    '/^[a-z0-9]{6,15}$/i'),
    ];

    return $ids;
}

/** Are any marketing tags configured at all? */
function consent_has_tags(): bool
{
    foreach (consent_tag_ids() as $id) {
        if ($id !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Does our own analytics mode put an identifier on the device?
 *
 * `anonymous` (the owner's choice) does not, so it needs no consent and adds
 * no category to the banner. `consent_only` counts nothing until analytics is
 * granted; `notice` sets an identifier with an opt-out. Both of those are a
 * reason to ask, so both add the Analytics category.
 */
function consent_analytics_needs_optin(): bool
{
    return in_array((string) setting('analytics_mode', 'off'), ['consent_only', 'notice'], true);
}

/**
 * Is there anything on this store worth asking about?
 *
 * No tags configured and an anonymous (or off) analytics mode means there is
 * nothing to consent to - so no banner, no preferences link, and consent.js is
 * never loaded. A store that runs no tags pays nothing for this feature.
 */
function consent_layer_active(): bool
{
    if (!empty($GLOBALS['SIK_NO_THIRD_PARTY'])) {
        return false;
    }
    if (function_exists('admin_user') && admin_user() !== null) {
        return false;
    }

    return consent_has_tags() || consent_analytics_needs_optin();
}

/** Which categories the banner should offer, in display order. */
function consent_offered_categories(): array
{
    $offered = [];

    if (consent_analytics_needs_optin()) {
        $offered[] = 'analytics';
    }
    if (consent_has_tags()) {
        $offered[] = 'marketing';
    }

    return $offered;
}

/** Should the banner open by itself on this page view? */
function consent_needs_banner(): bool
{
    if (!consent_layer_active()) {
        return false;
    }

    return !consent_state()['decided'];
}

// ===========================================================================
//  RENDERING
// ===========================================================================

/**
 * The third-party tag block, or '' when nothing may run.
 *
 * Nothing here is emitted speculatively. Google's own advice is to load gtag
 * with Consent Mode defaults set to denied and flip them later, but that still
 * fetches googletagmanager.com before the visitor has said a word - which is
 * exactly the request this phase exists to remove. So the tags load only once
 * `marketing` is granted, and the Consent Mode calls are made anyway, before
 * the loader, because a GTM container can carry tags of its own that read
 * those signals.
 */
function consent_tag_html(): string
{
    if (!consent_allows('marketing')) {
        return '';
    }

    $ids = consent_tag_ids();
    if (!consent_has_tags()) {
        return '';
    }

    $html = '';

    if ($ids['ga'] !== '' || $ids['gtm'] !== '') {
        // Consent Mode v2. Declared denied then granted in the same breath, so
        // the container never sees an undeclared state whatever order its own
        // tags fire in.
        $html .= "<script>\n"
            . "window.dataLayer = window.dataLayer || [];\n"
            . "function gtag(){dataLayer.push(arguments);}\n"
            . "gtag('consent', 'default', {ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',"
            . "analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted'});\n"
            . "gtag('consent', 'update', {ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',"
            . "analytics_storage:'granted'});\n"
            . "</script>\n";
    }

    if ($ids['gtm'] !== '') {
        $html .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . e($ids['gtm']) . '"'
            . ' height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>' . "\n"
            . "<script>\n"
            . "(function (w, d, s, l, i) {\n"
            . "    w[l] = w[l] || []; w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });\n"
            . "    var f = d.getElementsByTagName(s)[0], j = d.createElement(s), dl = l !== 'dataLayer' ? '&l=' + l : '';\n"
            . "    j.async = true; j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;\n"
            . "    f.parentNode.insertBefore(j, f);\n"
            . "})(window, document, 'script', 'dataLayer', " . e_json($ids['gtm']) . ");\n"
            . "</script>\n";
    }

    if ($ids['ga'] !== '') {
        $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ids['ga']) . '"></script>' . "\n"
            . "<script>\n"
            . "gtag('js', new Date());\n"
            . "gtag('config', " . e_json($ids['ga']) . ");\n"
            . "</script>\n";
    }

    if ($ids['pixel'] !== '') {
        $html .= "<script>\n"
            . "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
            . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;\n"
            . "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;\n"
            . "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}\n"
            . "(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\n"
            . "fbq('init', " . e_json($ids['pixel']) . "); fbq('track', 'PageView');\n"
            . "</script>\n"
            . '<noscript><img height="1" width="1" style="display:none" alt=""'
            . ' src="https://www.facebook.com/tr?id=' . e($ids['pixel']) . '&ev=PageView&noscript=1"></noscript>' . "\n";
    }

    if ($ids['clarity'] !== '') {
        $html .= "<script>\n"
            . "(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};\n"
            . "t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;\n"
            . "y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);\n"
            . "})(window, document, 'clarity', 'script', " . e_json($ids['clarity']) . ");\n"
            . "</script>\n";
    }

    return $html;
}

/**
 * The config consent.js reads. Its shape is documented in assets/js/consent.js.
 *
 * `tags` is empty once marketing is already granted, because the server has
 * then printed the real tags itself and consent.js has nothing left to inject.
 */
function consent_js_config(): array
{
    $state = consent_state();

    return [
        'cookie'     => CONSENT_COOKIE,
        'path'       => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'secure'     => session_cookie_secure(),
        'maxAge'     => CONSENT_LIFETIME,
        'honorDnt'   => consent_honours_signals(),
        'signal'     => $state['signal'],
        'decided'    => $state['decided'],
        'categories' => consent_offered_categories(),
        'granted'    => ['analytics' => $state['analytics'], 'marketing' => $state['marketing']],
        // consent.js injects these itself when the visitor accepts, so the tags
        // start in the same page view rather than after a reload. They are
        // public identifiers, visible in any page that runs them.
        'tags'       => consent_allows('marketing') ? [] : consent_tag_ids(),
    ];
}

/**
 * The Cookie Policy URL, or '' when there is nothing published to link to.
 *
 * Cached for ten minutes: a dead "Read the Cookie Policy" link on a consent
 * banner undermines the banner, and a query on every storefront page view to
 * prevent one is worse than both. Falls back to the Privacy Policy, because a
 * store that has only one of the two still has somewhere honest to send the
 * reader.
 */
function consent_policy_url(): string
{
    $slug = (string) cache_remember('consent.policy_slug', 600, static function (): string {
        foreach (['cookie-policy', 'privacy-policy'] as $candidate) {
            if (Database::exists('pages', "`slug` = :s AND `status` = 'active'", ['s' => $candidate])) {
                return $candidate;
            }
        }
        return '';
    });

    return $slug === '' ? '' : page_url($slug);
}

/**
 * A "Cookie preferences" control for the footer.
 *
 * A button rather than a link, because it opens something on this page; it
 * renders only when there is something to reopen.
 */
function consent_preferences_button(string $class = 'sik-consent-link'): string
{
    if (!consent_layer_active()) {
        return '';
    }

    return '<button type="button" class="' . e_attr($class) . '" data-consent-open>Cookie preferences</button>';
}

/**
 * The banner itself.
 *
 * Shipped `hidden` and revealed by consent.js, for three reasons:
 *   - the client-side DNT/GPC check has to run before anything is shown;
 *   - without JavaScript nothing can be stored, and a banner whose buttons do
 *     nothing is worse than no banner - with JS off no tag loads anyway;
 *   - it is a fixed bottom sheet, so it pushes no content either way, and its
 *     layout shift is 0 by construction rather than by luck.
 *
 * It is a NON-modal dialog on purpose: `aria-modal` is absent and no focus
 * trap is installed, so a screen-reader user can read the page - the Cookie
 * Policy link included - and come back to it. Consent that can only be given
 * without reading the page is not consent.
 */
function consent_banner_html(): string
{
    if (!consent_layer_active()) {
        return '';
    }

    $offered   = consent_offered_categories();
    $state     = consent_state();
    $signal    = $state['signal'];
    $cookieUrl = consent_policy_url();

    $title = trim((string) setting('consent_banner_title', ''));
    $title = $title !== '' ? $title : 'Your choice about cookies';

    $body = trim((string) setting('consent_banner_text', ''));
    if ($body === '') {
        $body = in_array('marketing', $offered, true)
            ? 'We use cookies that are needed to run the shop - your cart, your sign-in and checkout security. '
              . 'We would also like to load analytics and advertising services from Google and Meta, which share '
              . 'your visit with them. Those stay off until you say yes.'
            : 'We use cookies that are needed to run the shop. We would also like to measure how the shop is used. '
              . 'That stays off until you say yes.';
    }

    $labels = [
        'analytics' => ['Analytics', 'Counts visits and shows us which pages fail. Never used to advertise to you.'],
        'marketing' => ['Advertising', 'Google and Meta tags that measure ads and can follow you to other sites.'],
    ];

    ob_start();
    ?>
<div class="sik-consent<?= $signal !== '' ? ' is-signal' : '' ?>" id="sikConsent" role="dialog"
     aria-labelledby="sikConsentTitle" aria-describedby="sikConsentText" tabindex="-1" hidden>
    <div class="sik-consent__inner">
        <div class="sik-consent__copy">
            <h2 class="sik-consent__title" id="sikConsentTitle"><?= e($title) ?></h2>
            <p class="sik-consent__text" id="sikConsentText"><?= e($body) ?></p>
            <p class="sik-consent__signal">
                Your browser is sending a <strong>Do&nbsp;Not&nbsp;Track / Global Privacy Control</strong> signal,
                so analytics and advertising stay off. There is nothing to choose while that signal is on.
            </p>
            <p class="sik-consent__meta">
                Necessary cookies are always on - without them the cart and checkout cannot work.
                <?php if ($cookieUrl !== ''): ?>
                    <a href="<?= e($cookieUrl) ?>">Read the Cookie Policy</a>.
                <?php endif; ?>
            </p>
        </div>

        <?php // One class, one size, one row: refusing is exactly as easy as accepting. ?>
        <div class="sik-consent__actions">
            <button type="button" class="sik-consent__btn" data-consent-accept>Accept all</button>
            <button type="button" class="sik-consent__btn" data-consent-reject>Reject all</button>
            <button type="button" class="sik-consent__btn" data-consent-choose
                    aria-expanded="false" aria-controls="sikConsentChoices">Choose</button>
        </div>

        <?php // Signal present: there is nothing to choose, so only a way out. ?>
        <div class="sik-consent__actions sik-consent__actions--signal">
            <button type="button" class="sik-consent__btn" data-consent-dismiss>Close</button>
        </div>
    </div>

    <div class="sik-consent__choices" id="sikConsentChoices" hidden>
        <ul class="sik-consent__list">
            <li class="sik-consent__row">
                <div class="sik-consent__rowtext">
                    <span class="sik-consent__rowname">Necessary</span>
                    <span class="sik-consent__rowhelp">Sign-in, cart, checkout and anti-fraud. Cannot be switched off.</span>
                </div>
                <span class="sik-consent__always">Always on</span>
            </li>
            <?php foreach ($offered as $category): ?>
                <?php [$name, $help] = $labels[$category]; ?>
                <li class="sik-consent__row">
                    <div class="sik-consent__rowtext">
                        <label class="sik-consent__rowname" for="sikConsent_<?= e_attr($category) ?>"><?= e($name) ?></label>
                        <span class="sik-consent__rowhelp"><?= e($help) ?></span>
                    </div>
                    <label class="sik-consent__toggle">
                        <input type="checkbox" id="sikConsent_<?= e_attr($category) ?>"
                               data-consent-category="<?= e_attr($category) ?>"
                               <?= $state[$category] ? 'checked' : '' ?>>
                        <span class="sik-consent__track" aria-hidden="true"></span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="sik-consent__actions sik-consent__actions--save">
            <button type="button" class="sik-consent__btn" data-consent-save>Save my choices</button>
        </div>
    </div>
</div>
<script>window.SIK_CONSENT = <?= e_json(consent_js_config()) ?>;</script>
<script src="<?= e(asset('js/consent.js')) ?>" defer></script>
    <?php
    return (string) ob_get_clean();
}
