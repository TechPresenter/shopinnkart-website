<?php
/**
 * ShopInnKart - Policy copy for the consent layer and the store's own analytics.
 *
 * THE SIX EDITS THE OWNER APPROVED, AND WHAT HAD TO CHANGE IN THEM
 * ---------------------------------------------------------------
 * These six were written in phase B1, before the first-party analytics
 * collector, the consent banner and Settings > Integrations existed. Each was
 * re-read against today's code before being run, and four of the six said
 * something the code does not do. They are corrected here rather than applied
 * as approved, because an approved sentence that is false is still false:
 *
 *   1. (cookie §2) "Analytics and marketing scripts are not part of the
 *      application" - wrong in both directions now. The store runs its OWN
 *      first-party analytics, it is part of the application and it is ON
 *      (analytics_mode = anonymous), and the prepared replacement never
 *      mentioned it: it described only other companies' scripts. It also said
 *      "When you first arrive we ask", flatly. consent_layer_active() shows no
 *      banner at all when no third-party id is configured - which is this
 *      store's state today - so that promise was unkeepable. Rewritten: our
 *      own counting described plainly, other companies' scripts described as
 *      gated, and the banner promised only "where one of them is configured".
 *
 *   2. (cookie §2 table, Analytics row) the prepared text offered a withdrawal
 *      that does not exist. Third-party analytics (GA4, Clarity) is gated in
 *      consent_tag_html() behind the MARKETING category; the banner grows an
 *      Analytics category only in consent_only/notice mode, which this store
 *      does not use. And the row still did not mention the store's own
 *      counting, which is enabled and needs no identifier. Split into two
 *      rows: ours, and other companies'.
 *
 *   3. (cookie §2 table, Marketing row) correct as approved. Extended with
 *      what withdrawal actually does, because assets/js/consent.js deletes the
 *      first-party ids those tags wrote and cannot unload a running script -
 *      "off at once" would have been the one word too many.
 *
 *   4. (cookie §6, Do Not Track) correct as approved, and stronger than it
 *      claimed: an honoured signal stops the store's own counting too
 *      (analytics_tracking_allowed() -> consent_signal_active()), not just the
 *      third-party tags. Said so.
 *
 *   5. (cookie FAQ) "Closing the banner without answering is not consent" -
 *      the banner cannot be closed without answering at all: the Close button
 *      renders only under a DNT/GPC signal and Escape is ignored until an
 *      answer exists. And "analytics stay off" was false: our own counting is
 *      unaffected by the banner. Both corrected.
 *
 *   6. (privacy §7) the same two errors as #1, the same correction.
 *
 * WHAT IT DOES NOT TOUCH
 * ----------------------
 * Nothing about what data is collected, kept or shared changes here - only the
 * description of it, which is now accurate. Location, the server-side sends
 * and the data-rights pointer are a separate, later change:
 * database/seeds/2026_09_28_policy_geo.php.
 *
 * SAFETY
 * ------
 *   - Idempotent. An edit whose new text is already present is reported as
 *     already applied and left alone - recognised by a fingerprint, not a byte
 *     match, because the later geo seed splices a sentence into one of these
 *     paragraphs and a byte match then mistook this seed's own work for a hand
 *     edit and refused. Running the two seeds in either order, twice, is a
 *     no-op; test_policy_copy.php pins that.
 *   - Anchored twice. Each edit knows the pristine wording AND the wording the
 *     un-run 2026-09-23 draft would have left, so it applies on a store where
 *     that draft was run and on one where it was not.
 *   - It REFUSES rather than guesses. An edit whose anchor is gone and whose
 *     new text is absent - hand-edited since - is refused, named, and the run
 *     exits 1. Nothing is half-written: a page is saved only when every edit
 *     on it resolved.
 *   - Every claim has a precondition. A sentence saying "we honour Do Not
 *     Track" is not written to a store whose analytics_honor_dnt is off; the
 *     edit is held back and the reason is printed.
 *
 * IF THE MODE CHANGES LATER
 * -------------------------
 * This copy describes analytics_mode = anonymous. Switching to consent_only or
 * notice puts a 13-month identifier (the sik_vid cookie) on the device and adds
 * an Analytics category to the banner, and several of these sentences then
 * understate what happens. There is no reverse seed: the policy has to be
 * revised by hand, and the preconditions below are only what stops this version
 * being written to a store it is already wrong for.
 *
 *     php database/seeds/analytics-policy-copy.php --dry    report only
 *     php database/seeds/analytics-policy-copy.php          apply
 *
 * NOTE for a fresh install: database/schema.sql still seeds the ORIGINAL
 * wording, so a new store needs this seed as well until that file is updated.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/**
 * The claims these edits depend on, checked against today's settings.
 *
 * label   what the policy would be promising
 * test    is it true right now?
 * actual  what is true instead, for the report
 *
 * @return array<string,array{label:string,test:callable,actual:callable}>
 */
function analytics_policy_conditions(): array
{
    return [
        // "This shop counts its own visits, it is on, and it puts nothing on
        // your device." Only `anonymous` is all three: consent_only and notice
        // both set the sik_vid cookie, and off counts nothing.
        'own_analytics_anonymous' => [
            'label'  => "the store's own analytics is on and in anonymous mode",
            'test'   => static fn (): bool => (string) setting('analytics_mode', 'off') === 'anonymous',
            'actual' => static fn (): string => "analytics_mode is '" . (string) setting('analytics_mode', 'off')
                . "', and this wording describes 'anonymous'",
        ],
        // consent_honours_signals()
        'honours_signals' => [
            'label'  => 'Do Not Track and Global Privacy Control are honoured',
            'test'   => static fn (): bool => setting_bool('analytics_honor_dnt', true),
            'actual' => static fn (): string => 'analytics_honor_dnt is off, so the store does not honour them',
        ],
    ];
}

/**
 * Every edit, in the order the owner approved them.
 *
 * id      stable name, used in the report and by the tests
 * slug    page
 * needs   condition keys from analytics_policy_conditions()
 * find    candidate anchors, first match wins; keyed for the report
 * text    what replaces the matched anchor
 *
 * @return list<array{id:string,slug:string,needs:list<string>,find:array<string,string>,text:string}>
 */
function analytics_policy_edits(): array
{
    // ---- 1. cookie §2: what analytics actually is here ---------------------
    $c1Pristine = '<p>Analytics and marketing scripts are not part of the application. They load only when the store administrator has entered the corresponding identifier in the settings, and where none is configured no such script and no such cookie is present.</p>';

    $c1Draft = '<p>Analytics and marketing scripts are third party code that runs in your browser, and we treat them as such. They load only when the store administrator has configured the corresponding identifier <em>and</em> you have allowed that category, so on a first visit none of them has run. Where no identifier is configured, no such script and no such cookie exists at all.</p>'
        . "\n"
        . '<p>When you first arrive we ask. The banner offers <strong>Accept all</strong>, <strong>Reject all</strong> and <strong>Choose</strong> side by side, at the same size: refusing takes exactly one click, the same as accepting. Your answer is stored in a first party cookie named <code>sik_consent</code>, which holds nothing but your answer and the date - no identifier, no profile - and lasts six months. You can change it at any time through <strong>Cookie preferences</strong> in the footer.</p>';

    $c1New = '<p>Analytics is part of the application, and it is switched on. This shop counts its own visits, with its own code, on its own server: which pages were opened and in what order, how long a page was actually in front of you, what kind of device and browser it was, and which site, search or campaign sent you here. That counting writes no cookie, puts no identifier on your device and never stores your IP address. Visits within a single day are grouped by a key derived from a shortened form of your network address mixed with a secret that is replaced every day and then destroyed, so the same visitor cannot be recognised on a later day by anyone, ourselves included. That is why these figures are approximate, and why we do not ask your permission for them: there is nothing in them that leads back to you.</p>'
        . "\n"
        . '<p>Scripts belonging to other companies are a different matter, and we treat them as such. Google Analytics, Google Tag Manager, Google Ads, the Meta Pixel and Microsoft Clarity run inside your browser and report your visit to those companies. Such a script loads only when the store administrator has configured its identifier <em>and</em> you have allowed the advertising category, so on a first visit none of them has run. Where no identifier is configured, no such script and no such cookie exists at all, and there is nothing to ask you about.</p>'
        . "\n"
        . '<p>Where one of them is configured, we ask before it loads. The banner offers <strong>Accept all</strong>, <strong>Reject all</strong> and <strong>Choose</strong> in one row, at one size: refusing takes exactly one click, the same as accepting, and an unanswered banner loads nothing. Your answer is kept in a first party cookie named <code>sik_consent</code> that holds your answer and the date and nothing else - no identifier, no profile - for six months. <strong>Cookie preferences</strong> in the footer reopens the choice at any time, and appears whenever there is a choice to make.</p>';

    // ---- 2. cookie §2 table: one Analytics row was two different things ----
    $c2Pristine = '<tr><td>Analytics</td><td>Counts visits and shows which pages fail</td><td>Enabled only if the administrator has configured an analytics identifier in the store settings</td><td>Set by the analytics provider</td><td>Yes</td></tr>';

    $c2Draft = '<tr><td>Analytics</td><td>Counts visits and shows which pages fail</td><td>Enabled only if the administrator has configured an analytics identifier in the store settings</td><td>Set by the analytics provider</td><td>Yes. Off until you allow it, and you can withdraw that at any time from Cookie preferences in the footer</td></tr>';

    $c2New = '<tr><td>Analytics, ours</td><td>Counts visits and shows us which pages fail</td><td>On. It sets no cookie, puts no identifier on your device and keeps no IP address</td><td>Nothing is stored on your device</td><td>There is nothing here to refuse: no cookie, and nothing stored that identifies you. A Do Not Track or Global Privacy Control signal from your browser switches the counting off as well</td></tr>'
        . "\n"
        . '<tr><td>Analytics, other companies</td><td>Reports your visit to a provider such as Google or Microsoft</td><td>Present only if the administrator has configured that provider\'s identifier</td><td>Set by that provider</td><td>Yes. These stay off until you allow advertising, because they hand your visit to the same companies, and Cookie preferences in the footer withdraws it</td></tr>';

    // ---- 3. cookie §2 table: Marketing ------------------------------------
    $c3Pristine = '<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes</td></tr>';

    $c3Draft = '<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes. Off until you allow it, and you can withdraw that at any time from Cookie preferences in the footer</td></tr>';

    $c3New = '<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes. Off until you allow it, and Cookie preferences in the footer withdraws it: the identifiers those scripts left on our own domain are deleted straight away and nothing of theirs loads on any later page. A script already running in the page you are on cannot be unloaded, so that one ends when you navigate</td></tr>';

    // ---- 4. cookie §6: DNT and GPC ----------------------------------------
    $c4Pristine = '<p>Browsers send these signals inconsistently and there is no settled standard for honouring them. We do not rely on tracking cookies for the site to work, so the practical effect of such a signal here is limited to whichever optional analytics the administrator has enabled.</p>';

    $c4Draft = '<p>We honour them. If your browser sends <strong>Global Privacy Control</strong> (<code>Sec-GPC</code>) or <strong>Do Not Track</strong> (<code>DNT</code>), no analytics or advertising script is loaded and the advertising category is treated as refused, whatever is stored in your consent cookie. You are not asked to choose again on top of a signal you have already sent. We check the request header and the browser property, because not every browser sends both.</p>';

    $c4New = '<p>We honour them. If your browser sends <strong>Global Privacy Control</strong> (<code>Sec-GPC</code>) or <strong>Do Not Track</strong> (<code>DNT</code>), no other company\'s script is loaded, the advertising category is treated as refused whatever is stored in your consent cookie, and this shop\'s own counting stops too - the page view is not recorded at all. You are not asked to choose again on top of a signal you have already sent. We check both the request header and the browser property, because not every browser sends both.</p>';

    // ---- 5. cookie FAQ: the unanswered banner -----------------------------
    $c5Anchor = '<h3>Is local storage different from a cookie?</h3>';

    $c5New = '<h3>What happens if I just ignore the banner?</h3>'
        . "\n"
        . '<p>Nothing of anybody else\'s loads. An unanswered banner is not consent, so advertising and other companies\' analytics stay off, and we ask again next time. The banner offers no way to dismiss it without answering, because dismissing one is not an answer. Our own visit counting runs either way - there is nothing in it for consent to apply to.</p>'
        . "\n"
        . '<h3>Is local storage different from a cookie?</h3>';

    // ---- 6. privacy §7: the cross-reference -------------------------------
    $p6Pristine = '<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary and how to refuse the rest in your browser.</p>';

    $p6Draft = '<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary. Analytics and advertising are off until you allow them: we ask on your first visit, refusing is a single click, and <strong>Cookie preferences</strong> in the footer reopens the choice whenever you want to change it. We also honour Global Privacy Control and Do Not Track signals sent by your browser.</p>';

    $p6New = '<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary. Two different things live there. This shop counts its own visits without a cookie, without an identifier on your device and without keeping your IP address, so there is nothing in that to switch off. Scripts belonging to Google, Meta and Microsoft stay off until you allow advertising, refusing takes one click, and <strong>Cookie preferences</strong> in the footer reopens that choice whenever there is one to make. We also honour Global Privacy Control and Do Not Track signals sent by your browser, and such a signal stops our own counting as well.</p>';

    return [
        [
            'id'    => 'cookie-analytics-is-ours',
            'slug'  => 'cookie-policy',
            'needs' => ['own_analytics_anonymous'],
            'find'  => ['pristine' => $c1Pristine, 'draft' => $c1Draft],
            'text'  => $c1New,
            // 2026_09_28_policy_geo.php adds the location sentence INSIDE the
            // first of these three paragraphs, so the whole block is no longer
            // a byte match once that seed has run. This phrase is: it is the
            // one sentence nothing else edits, and it appears nowhere else.
            'done'  => 'Analytics is part of the application, and it is switched on.',
        ],
        [
            'id'    => 'cookie-table-analytics',
            'slug'  => 'cookie-policy',
            'needs' => ['own_analytics_anonymous', 'honours_signals'],
            'find'  => ['pristine' => $c2Pristine, 'draft' => $c2Draft],
            'text'  => $c2New,
        ],
        [
            'id'    => 'cookie-table-marketing',
            'slug'  => 'cookie-policy',
            'needs' => [],
            'find'  => ['pristine' => $c3Pristine, 'draft' => $c3Draft],
            'text'  => $c3New,
        ],
        [
            'id'    => 'cookie-dnt',
            'slug'  => 'cookie-policy',
            'needs' => ['honours_signals'],
            'find'  => ['pristine' => $c4Pristine, 'draft' => $c4Draft],
            'text'  => $c4New,
        ],
        [
            'id'    => 'cookie-faq-ignored-banner',
            'slug'  => 'cookie-policy',
            'needs' => ['own_analytics_anonymous'],
            'find'  => ['pristine' => $c5Anchor],
            'text'  => $c5New,
        ],
        [
            'id'    => 'privacy-cookie-crossref',
            'slug'  => 'privacy-policy',
            'needs' => ['own_analytics_anonymous', 'honours_signals'],
            'find'  => ['pristine' => $p6Pristine, 'draft' => $p6Draft],
            'text'  => $p6New,
        ],
    ];
}

/**
 * Apply (or report) a list of policy edits against the `pages` table.
 *
 * Shared with 2026_09_28_policy_geo.php: two seeds writing policy text two
 * different ways is how one of them ends up guessing.
 *
 * @param list<array> $edits
 * @param array<string,array{label:string,test:callable,actual:callable}> $extra conditions this
 *        caller adds to the shared ones, same shape as analytics_policy_conditions()
 * @return array{applied:int,already:int,held:int,refused:int,lines:list<string>,saved:list<string>}
 */
function policy_copy_apply(array $edits, bool $dryRun, array $extra = []): array
{
    $conditions = $extra + analytics_policy_conditions();
    $result     = ['applied' => 0, 'already' => 0, 'held' => 0, 'refused' => 0, 'lines' => [], 'saved' => []];

    // Grouped by page: a page is written once, and only when every edit on it
    // resolved, so a refusal never leaves half a policy rewritten.
    $bySlug = [];
    foreach ($edits as $edit) {
        $bySlug[$edit['slug']][] = $edit;
    }

    foreach ($bySlug as $slug => $pageEdits) {
        $page = Database::fetch('SELECT `id`, `content` FROM `pages` WHERE `slug` = :s', ['s' => $slug]);

        if ($page === null) {
            foreach ($pageEdits as $edit) {
                $result['refused']++;
                $result['lines'][] = '  REFUSED  ' . $edit['id'] . ' - no page with slug ' . $slug;
            }
            continue;
        }

        $text    = (string) $page['content'];
        $before  = $text;
        $refused = false;

        foreach ($pageEdits as $edit) {
            // A claim whose precondition is false is not written at all.
            $blocked  = null;
            $evidence = [];
            foreach ($edit['needs'] as $key) {
                $condition = $conditions[$key] ?? null;
                if ($condition === null) {
                    $blocked = 'unknown condition ' . $key;
                    break;
                }
                if (!($condition['test'])()) {
                    $blocked = ($condition['actual'])();
                    break;
                }
                // Printed on success too. A condition that answers "yes" for
                // the wrong reason is only visible if it says why it said yes.
                if (isset($condition['evidence'])) {
                    $evidence[] = ($condition['evidence'])();
                }
            }

            if ($blocked !== null) {
                $result['held']++;
                $result['lines'][] = '  HELD     ' . $edit['id'] . ' - ' . $blocked;
                continue;
            }

            // "Have I already been applied?" is asked of a FINGERPRINT, not of
            // the whole replacement, because a later seed may legitimately
            // splice a sentence into the paragraph this one wrote - and then an
            // exact-match test would call its own work a hand edit and refuse.
            // Default is the whole text; an edit that expects to be added to
            // names a phrase nothing else changes.
            if (strpos($text, (string) ($edit['done'] ?? $edit['text'])) !== false) {
                $result['already']++;
                $result['lines'][] = '  already  ' . $edit['id'];
                continue;
            }

            $matched = null;
            foreach ($edit['find'] as $name => $anchor) {
                if (strpos($text, $anchor) !== false) {
                    $matched = $name;
                    // str_replace, not preg: policy text is full of regex
                    // metacharacters and the match has to be literal.
                    $text = str_replace($anchor, $edit['text'], $text);
                    break;
                }
            }

            if ($matched === null) {
                $refused = true;
                $result['refused']++;
                $result['lines'][] = '  REFUSED  ' . $edit['id']
                    . ' - the text it replaces is not on the page any more, and the new text is not there either.'
                    . ' Edited by hand? Nothing is written to ' . $slug . '.'
                    . (isset($edit['hint']) ? ' ' . $edit['hint'] : '');
                continue;
            }

            $result['applied']++;
            $result['lines'][] = '  edit     ' . $edit['id'] . ' (matched ' . $matched . ')'
                . ($evidence === [] ? '' : "\n           because " . implode('; ', $evidence));
        }

        if ($refused) {
            $result['lines'][] = '  ---      ' . $slug . ' left untouched, because an edit on it was refused';
            continue;
        }

        if ($text === $before) {
            continue;
        }

        $result['saved'][] = $slug . ' ' . strlen($before) . ' -> ' . strlen($text) . ' chars';

        if (!$dryRun) {
            Database::update('pages', ['content' => $text], '`id` = :id', ['id' => (int) $page['id']]);
        }
    }

    return $result;
}

/** Print a run and return the exit code. */
function policy_copy_report(string $title, array $result, bool $dryRun): int
{
    echo $title . ($dryRun ? " (DRY RUN - nothing written)\n" : "\n");

    foreach ($result['lines'] as $line) {
        echo $line . "\n";
    }

    foreach ($result['saved'] as $saved) {
        echo '  ' . ($dryRun ? 'would save ' : 'saved     ') . $saved . "\n";
    }

    echo '  ' . $result['applied'] . ' edit(s) ' . ($dryRun ? 'would apply' : 'applied')
        . ', ' . $result['already'] . ' already applied'
        . ', ' . $result['held'] . ' held back'
        . ', ' . $result['refused'] . " refused\n";

    if ($result['held'] > 0) {
        echo "  A held edit is a claim today's settings do not support. Change the setting, or leave the policy as it is.\n";
    }

    if (!$dryRun && $result['applied'] > 0) {
        // The footer caches the policy link list for ten minutes; the bodies
        // are not cached, but busting is cheap and avoids a stale footer while
        // somebody is checking the change.
        cache_bust();
        echo "  cache cleared\n";
    }

    return $result['refused'] > 0 ? 1 : 0;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $dryRun = in_array('--dry', $argv, true);
    $result = policy_copy_apply(analytics_policy_edits(), $dryRun);

    exit(policy_copy_report('Policy copy: the consent layer and our own analytics', $result, $dryRun));
}
