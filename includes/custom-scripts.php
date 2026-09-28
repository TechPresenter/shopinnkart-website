<?php
/**
 * ShopInnKart - Operator-authored snippets injected into the storefront.
 *
 * WHY THIS REPLACED TWO TEXTAREAS
 * ------------------------------
 * The store had exactly two places to put code: the `custom_css` and
 * `custom_js` settings on Settings > Theme. One box each, no name, no on/off,
 * no order, and no consent category - so a Google Ads tag, a Hotjar snippet, a
 * Search Console meta tag and a one-line CSS fix all had to be concatenated
 * into the same field by hand, and any one of them could only be removed by
 * editing around the others. This table is one row per snippet, which is what
 * makes "switch that one off" and "what is live right now?" answerable.
 *
 * THE OLD PATH NO LONGER EMITS
 * ---------------------------
 * includes/header.php and includes/footer.php now read this table and nothing
 * else. Leaving the two settings in the page as well would print every
 * migrated snippet twice, so database/migrations/2026_09_28_custom_scripts.php
 * moves their values in here as rows and blanks the settings. That makes this
 * table the one source of truth; the migration is the only thing that ever
 * reads those two keys again.
 *
 * WHAT THE STORAGE CONTRACT ACTUALLY IS
 * ------------------------------------
 * `code` is stored EXACTLY as the operator typed it. Nothing is stripped,
 * nothing is entity-encoded, nothing is "sanitised".
 *
 * That is a deliberate reversal. The old path ran both boxes through
 * strip_tags(), which is not a safety measure on either field - it is a bug
 * dressed as one. The value was already inside a <script> element, so
 * strip_tags removed nothing an attacker needed and stopped nothing an
 * attacker would do; meanwhile it silently ate valid JavaScript, because
 * strip_tags treats `if (a <b)` as the start of a tag and deletes from there
 * to the next `>`. A filter that mangles correct code while blocking no
 * incorrect code is worse than no filter, because it makes the field look
 * guarded.
 *
 * A snippet field is remote code execution by design - that is the feature.
 * The controls that actually hold are:
 *
 *   1. the `settings.scripts` permission, which ADMIN_SUPER_ONLY_PERMISSIONS
 *      says only a Super Admin may ever delegate;
 *   2. step-up re-authentication on every write (ADMIN_REAUTH_WINDOW), so a
 *      borrowed session cannot add a snippet;
 *   3. an activity_logs row and a critical security_events row per change,
 *      with a sha256 of the code before and after, so a change is never silent;
 *   4. this file refusing to emit anything inside /admin, so a snippet can
 *      never run on the back office's origin and session. Belt to the other
 *      four's braces: no admin screen renders the storefront chrome today
 *      (measured - 0 files under admin/ require includes/header.php or
 *      includes/footer.php), so nothing currently reaches the check. It is here
 *      for the day one does, which is exactly when nobody would think of it;
 *   5. a door check (custom_scripts_problem) that rejects what is structurally
 *      wrong rather than editing what is right.
 *
 * On the way OUT, exactly one substitution is made, and only for CSS and JS:
 * seo_code_escape() rewrites `</script` and `</style` to `<\/script` and
 * `<\/style`. Those are the only byte sequences that can terminate a raw text
 * element from the inside, so neutralising them is complete, and it is a no-op
 * for code that does not contain them. An HTML snippet is markup on purpose
 * and is emitted verbatim - it is validated at the door instead, by the same
 * element allowlist includes/seo.php uses for its per-record head/body blocks.
 * The one exception is the CSP nonce: seo_code_problem()'s allowlist accepts
 * `script` in both slots, and the JavaScript box's own refusal message sends an
 * operator who wants the tag itself to the HTML type - so in nonce mode an HTML
 * snippet's own <script> is given the nonce it would otherwise be blocked for
 * lacking. See custom_scripts_element().
 *
 * CONSENT
 * -------
 * Every row carries one of essential / analytics / marketing, and only
 * `essential` runs before the visitor has answered. The other two ask
 * consent_allows(), which is the same single mechanism the third-party tag
 * block in includes/footer.php uses - a second answer computed here would
 * drift from the banner the visitor actually used, and the Cookie Policy
 * promises the banner is the answer.
 *
 * Two consequences an operator has to be told about rather than discover, so
 * custom_scripts_reach() states them on the admin screen:
 *   - consent_offered_categories() only offers Analytics when the store's own
 *     analytics mode needs an opt-in, and only offers Advertising when a
 *     third-party tag id is configured. A category the banner never offers can
 *     never be granted, so a snippet in it can never run.
 *   - consent_allows() is false for a signed-in admin by design. The owner
 *     testing their own tag sees nothing until they use a private window.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** What the snippet is, and therefore which element it is wrapped in. */
const CUSTOM_SCRIPT_TYPES = [
    'js'   => 'JavaScript',
    'css'  => 'CSS',
    'html' => 'HTML',
];

/** Where in the document it lands. */
const CUSTOM_SCRIPT_PLACEMENTS = [
    'head'       => 'Head',
    'body_start' => 'Body start',
    'body_end'   => 'Body end',
];

/** Which visitor decision has to be in hand first. */
const CUSTOM_SCRIPT_CONSENT = [
    'essential' => 'Essential',
    'analytics' => 'Analytics',
    'marketing' => 'Marketing',
];

/**
 * Character ceiling on one snippet.
 *
 * The same 20,000 seo_code_problem() holds a per-record block to, for the same
 * reason: past that it belongs in a file with a URL, not inline in every page.
 */
const CUSTOM_SCRIPT_MAX_CHARS = 20000;

// ===========================================================================
//  READING
// ===========================================================================

/**
 * Is the table there?
 *
 * Asked rather than assumed because the storefront reads this on every page
 * view and a site whose migration has not been run must render, not 500.
 */
function custom_scripts_installed(): bool
{
    static $installed = null;

    if ($installed === null) {
        try {
            $installed = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `information_schema`.`TABLES`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t',
                ['t' => 'custom_scripts']
            ) > 0;
        } catch (Throwable $e) {
            $installed = false;
        }
    }

    return $installed;
}

/**
 * Every snippet, in the order the admin screen lists them and the storefront
 * emits them: by placement, then by the operator's own order.
 *
 * @return array<int,array<string,mixed>>
 */
function custom_scripts_all(): array
{
    if (!custom_scripts_installed()) {
        return [];
    }

    try {
        return Database::fetchAll(
            'SELECT * FROM `custom_scripts`
             ORDER BY FIELD(`placement`, \'head\', \'body_start\', \'body_end\'), `sort_order`, `id`'
        );
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'custom_scripts read failed: ' . $e->getMessage());
        return [];
    }
}

/** One snippet, or null. */
function custom_scripts_find(int $id): ?array
{
    if (!custom_scripts_installed() || $id <= 0) {
        return null;
    }

    return Database::fetch('SELECT * FROM `custom_scripts` WHERE `id` = :id', ['id' => $id]);
}

/**
 * The enabled snippets for one placement, read once per request.
 *
 * One query for all three placements rather than one per injection point: the
 * head and the body-end are two calls on every page view, and a page that
 * renders no snippets should still cost one query, not three.
 *
 * @return array<int,array<string,mixed>>
 */
function custom_scripts_enabled(string $placement): array
{
    static $rows = null;

    if ($rows === null) {
        $rows = ['head' => [], 'body_start' => [], 'body_end' => []];

        if (custom_scripts_installed()) {
            try {
                $all = Database::fetchAll(
                    'SELECT `id`, `name`, `type`, `placement`, `consent`, `code`
                     FROM `custom_scripts`
                     WHERE `is_enabled` = 1
                     ORDER BY `sort_order`, `id`'
                );
                foreach ($all as $row) {
                    $slot = (string) $row['placement'];
                    if (isset($rows[$slot])) {
                        $rows[$slot][] = $row;
                    }
                }
            } catch (Throwable $e) {
                ErrorHandler::log('warning', 'custom_scripts read failed: ' . $e->getMessage());
            }
        }
    }

    return $rows[$placement] ?? [];
}

// ===========================================================================
//  MAY THIS ONE RUN, ON THIS REQUEST
// ===========================================================================

/**
 * Is this request part of the back office?
 *
 * includes/seo.php asks `defined('IS_ADMIN') && IS_ADMIN` for the same
 * question, but nothing in this tree ever defines IS_ADMIN (grepped: zero
 * definitions), so that guard is dead. This uses the SCRIPT_NAME test
 * security_csp_script_scope() uses in includes/security-headers.php, which is
 * the one that works.
 */
function custom_scripts_in_admin(): bool
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    return $script !== '' && strpos($script, '/admin/') !== false;
}

/** Load the consent layer if the caller reached us before the footer did. */
function custom_scripts_load_consent(): void
{
    // Head injection happens long before includes/footer.php requires
    // consent.php, and without this every gated snippet would be withheld from
    // everybody - the exact bug seo_custom_code_html() carries a note about.
    if (!function_exists('consent_allows') && is_file(INCLUDES_PATH . '/consent.php')) {
        require_once INCLUDES_PATH . '/consent.php';
    }
}

/**
 * Will this snippet be emitted on this request?
 *
 * @param array<string,mixed> $row an enabled custom_scripts row
 */
function custom_scripts_may_run(array $row): bool
{
    if (custom_scripts_in_admin()) {
        return false;
    }

    $consent = (string) ($row['consent'] ?? 'marketing');
    $type    = (string) ($row['type'] ?? 'js');

    // A page that set SIK_NO_THIRD_PARTY did it because its URL carries a live
    // secret - reset-password.php's token, verify-email.php's. Its docblock
    // names "the store's own custom_js" among what must not run there, and it
    // is right to: JavaScript can read document.location and post it anywhere,
    // and raw HTML can carry a script that does. So on those pages only CSS
    // survives, and only when it is essential - a stylesheet cannot read the
    // address bar, so suppressing it would cost the page its layout and buy
    // nothing.
    if (!empty($GLOBALS['SIK_NO_THIRD_PARTY'])) {
        return $type === 'css' && $consent === 'essential';
    }

    if ($consent === 'essential') {
        return true;
    }

    custom_scripts_load_consent();

    if (!function_exists('consent_allows')) {
        return false;
    }

    // The category has to be one the banner actually offers, not merely one the
    // cookie has a bit for.
    //
    // "Accept all" in assets/js/consent.js calls answer(true, true) whatever the
    // banner is showing, so a store in `anonymous` analytics mode - which offers
    // no Analytics toggle at all - still ends up with `a1` in the cookie of
    // every visitor who accepted. Honouring that bit would run an analytics
    // snippet on the strength of an answer to a question nobody was asked, and
    // the choices panel would not even render a switch to withdraw it.
    //
    // It is also what makes the admin screen's "Never runs" verdict true rather
    // than nearly true: custom_scripts_reach() is the one function both sides
    // ask, so the badge and the page cannot disagree.
    if (!custom_scripts_reach($consent)['ok']) {
        return false;
    }

    return consent_allows($consent);
}

/**
 * Whether a consent category can ever be granted on this store, and why not.
 *
 * The banner only offers a category the store has a reason to ask about, so
 * "marketing" with no tag id configured is a choice the visitor is never given
 * - and a snippet waiting on it waits forever. The admin screen prints this
 * instead of letting an operator conclude their snippet is broken.
 *
 * @return array{ok:bool, why:string}
 */
function custom_scripts_reach(string $consent): array
{
    if ($consent === 'essential') {
        return ['ok' => true, 'why' => ''];
    }

    custom_scripts_load_consent();

    if (!function_exists('consent_offered_categories')) {
        return ['ok' => false, 'why' => 'The consent layer is not available, so nothing gated can run.'];
    }

    if (in_array($consent, consent_offered_categories(), true)) {
        return ['ok' => true, 'why' => ''];
    }

    return [
        'ok'  => false,
        'why' => $consent === 'analytics'
            ? 'The banner offers no Analytics choice while Settings > Analytics is in anonymous or off '
              . 'mode, so this can never be granted.'
            : 'The banner offers no Advertising choice until a third-party tag id is configured, so this '
              . 'can never be granted.',
    ];
}

/**
 * The one-line verdict the admin list shows against a snippet.
 *
 * Computed from the same functions the storefront calls, so the column cannot
 * claim something is live that the page would withhold.
 *
 * @param array<string,mixed> $row
 * @return array{tone:string, label:string, note:string}
 */
function custom_scripts_status(array $row): array
{
    if ((int) ($row['is_enabled'] ?? 0) !== 1) {
        return ['tone' => 'gray', 'label' => 'Off', 'note' => ''];
    }

    $consent = (string) ($row['consent'] ?? 'marketing');

    if ($consent === 'essential') {
        // Not "on every page": custom_scripts_may_run() withholds JavaScript and
        // raw HTML from the pages that carry a live token in their URL, whatever
        // the consent category. Two pages is a short enough list to name, and an
        // operator debugging "why is my tag not on the reset screen" is owed the
        // answer here rather than in a file they will not read.
        return [
            'tone'  => 'green',
            'label' => 'Live',
            'note'  => (string) ($row['type'] ?? 'js') === 'css'
                ? 'On every page.'
                : 'On every page except password reset and email verification, '
                  . 'whose URLs carry a one-time token.',
        ];
    }

    $reach = custom_scripts_reach($consent);

    if (!$reach['ok']) {
        return ['tone' => 'red', 'label' => 'Never runs', 'note' => $reach['why']];
    }

    return [
        'tone'  => 'amber',
        'label' => 'After consent',
        'note'  => 'Runs once the visitor allows ' . CUSTOM_SCRIPT_CONSENT[$consent] . '.',
    ];
}

// ===========================================================================
//  RENDERING
// ===========================================================================

/**
 * The markup for one placement, or ''.
 *
 * `head` is called from includes/header.php after the theme's own style block,
 * so a CSS snippet still lands after the tokens it may want to override -
 * which is where the old custom_css setting sat, and the cascade has to keep
 * behaving the same way. `body_start` and `body_end` are called from
 * includes/header.php and includes/footer.php respectively.
 */
function custom_scripts_render(string $placement): string
{
    if (!isset(CUSTOM_SCRIPT_PLACEMENTS[$placement]) || custom_scripts_in_admin()) {
        return '';
    }

    $out = '';

    foreach (custom_scripts_enabled($placement) as $row) {
        if (!custom_scripts_may_run($row)) {
            continue;
        }

        $code = trim((string) $row['code']);
        if ($code === '') {
            continue;
        }

        // The id and nothing else. An operator reading view-source needs to
        // know which row printed a block; the name and the notes are their
        // private working copy and stay out of the page.
        $out .= "\n<!-- sik:snippet " . (int) $row['id'] . " -->\n";

        $out .= custom_scripts_element((string) $row['type'], $code);
    }

    return $out === '' ? '' : $out . "\n";
}

/**
 * Wrap one snippet's code in its element.
 *
 * csp_nonce_attr() is printed on the script tags because the store can be put
 * into CSP nonce mode (Security > Content policy), and an inline script
 * without the nonce is refused outright in that mode. It returns '' while the
 * policy is on 'unsafe-inline', so this costs nothing until it matters.
 */
function custom_scripts_element(string $type, string $code): string
{
    if (!function_exists('seo_code_escape')) {
        require_once INCLUDES_PATH . '/seo.php';
    }

    if ($type === 'css') {
        // No nonce: security_csp_policy() keeps style-src on 'unsafe-inline' in
        // both modes, so an inline <style> needs nothing extra.
        return '<style>' . seo_code_escape($code, 'style') . "</style>\n";
    }

    if ($type === 'js') {
        $nonce = function_exists('csp_nonce_attr') ? csp_nonce_attr() : '';

        // Emitted exactly where the operator asked for it, and NOT deferred.
        // Choosing "head" for a snippet is usually a statement about ordering
        // - a consent-mode default, an anti-flicker class, a queue another tag
        // will drain - and silently deferring it would break that while
        // looking like it worked. The screen says the head runs before the
        // page paints.
        return '<script' . $nonce . '>' . seo_code_escape($code, 'script') . "</script>\n";
    }

    // HTML: markup on purpose, emitted as written. custom_scripts_problem()
    // is what refuses anything that is not a short list of known elements.
    //
    // With ONE addition, and only in nonce mode: seo_code_problem()'s body and
    // head allowlists both accept `script`, and the JavaScript box refuses a
    // tag-wrapped body with "choose the HTML type if you need the tag itself" -
    // so "HTML snippet that is a <script>" is a path this screen recommends.
    // Measured with sec_csp_script_mode = 'nonce': script-src becomes
    // "'self' 'nonce-...'" with no 'unsafe-inline', so that snippet's script is
    // refused by the browser while this screen still reports it Live. The nonce
    // is therefore added to any opening <script> that does not already carry
    // one. csp_nonce_attr() returns '' on 'unsafe-inline', which is the store's
    // default, and then this is byte-for-byte the verbatim emit above.
    $nonce = function_exists('csp_nonce_attr') ? csp_nonce_attr() : '';

    if ($nonce !== '') {
        $code = (string) preg_replace(
            '~<script\b(?![^>]*\bnonce\s*=)~i',
            '<script' . $nonce,
            $code
        );
    }

    return $code . "\n";
}

// ===========================================================================
//  THE DOOR
// ===========================================================================

/**
 * Why this snippet cannot be saved, or null.
 *
 * Validation at the door, never a rewrite on the way out: the operator has to
 * be able to paste working code and get that code back. So what is checked is
 * shape - is it the right kind of thing, in a place that can hold it - and the
 * two paste mistakes that produce silently broken pages.
 */
function custom_scripts_problem(string $type, string $placement, string $code): ?string
{
    $code = trim($code);

    if ($code === '') {
        return 'There is no code in this snippet.';
    }
    if (mb_strlen($code) > CUSTOM_SCRIPT_MAX_CHARS) {
        return 'That is over ' . number_format(CUSTOM_SCRIPT_MAX_CHARS)
            . ' characters. Put anything that long in a file and link to it.';
    }
    if (!isset(CUSTOM_SCRIPT_TYPES[$type]) || !isset(CUSTOM_SCRIPT_PLACEMENTS[$placement])) {
        return 'Unknown type or placement.';
    }

    // A whole <script>...</script> pasted into the JavaScript box would be
    // wrapped in a second one and the page would run nothing. Same for CSS.
    // Caught here because the result is a snippet that looks saved and does
    // nothing at all.
    if ($type === 'js' && preg_match('~^\s*<script\b~i', $code) === 1) {
        return 'Paste the JavaScript only, without the <script> tags. Choose the HTML type if you need '
            . 'the tag itself.';
    }
    if ($type === 'css' && preg_match('~^\s*<style\b~i', $code) === 1) {
        return 'Paste the CSS only, without the <style> tags.';
    }

    if ($type === 'css' && $placement !== 'head') {
        return 'CSS goes in the head, where the rest of the cascade is. A stylesheet that arrives after '
            . 'the page has started painting repaints it.';
    }

    if ($type === 'html') {
        if (!function_exists('seo_code_problem')) {
            require_once INCLUDES_PATH . '/seo.php';
        }
        // The same element allowlist the per-record SEO blocks are held to, so
        // a verification <meta> is accepted and a stray <h1> painting over the
        // page is not.
        return seo_code_problem($code, $placement === 'head' ? 'head' : 'body');
    }

    return null;
}

// ===========================================================================
//  WRITING
// ===========================================================================

/**
 * Where a new snippet goes in its placement: last.
 */
function custom_scripts_next_order(string $placement): int
{
    $max = Database::fetchColumn(
        'SELECT MAX(`sort_order`) FROM `custom_scripts` WHERE `placement` = :p',
        ['p' => $placement]
    );

    return (int) $max + 1;
}

/**
 * Swap a snippet with its neighbour inside its own placement.
 *
 * Order only means anything between snippets that land in the same place, so
 * that is the list being reordered. The whole group is renumbered densely
 * rather than the two rows' values being exchanged: rows imported from the old
 * settings, or inserted while two admins were both adding one, can share a
 * sort_order, and swapping equal values moves nothing.
 *
 * @param int $direction -1 for earlier, +1 for later
 */
function custom_scripts_move(int $id, int $direction): bool
{
    $row = custom_scripts_find($id);
    if ($row === null || ($direction !== -1 && $direction !== 1)) {
        return false;
    }

    $placement = (string) $row['placement'];

    $ids = array_map('intval', Database::fetchColumnAll(
        'SELECT `id` FROM `custom_scripts` WHERE `placement` = :p ORDER BY `sort_order`, `id`',
        ['p' => $placement]
    ));

    $at = array_search($id, $ids, true);
    if ($at === false) {
        return false;
    }

    $to = (int) $at + $direction;
    if ($to < 0 || $to >= count($ids)) {
        return false;   // already at the end of its group
    }

    [$ids[(int) $at], $ids[$to]] = [$ids[$to], $ids[(int) $at]];

    Database::transaction(static function () use ($ids): void {
        foreach ($ids as $position => $rowId) {
            Database::update('custom_scripts', ['sort_order' => $position + 1], '`id` = :id', ['id' => $rowId]);
        }
    });

    return true;
}

/**
 * Record a snippet change in both places the admin already looks.
 *
 * activity_logs is the "who did what" list an operator reads; security_events
 * is the tamper-evident one the Security dashboard ranks, and it already
 * curates `settings.scripts_changed` by name - so this reuses that type rather
 * than inventing a second one the dashboard would not show.
 *
 * The code itself is never written to either log: a sha256 is enough to prove
 * a change happened and to tell two versions apart, and a log row is a second
 * copy of an executable payload in a table with different retention.
 */
function custom_scripts_audit(string $what, array $row, string $before = '', string $after = ''): void
{
    $admin = function_exists('admin_user') ? admin_user() : null;

    $name = (string) ($row['name'] ?? '');
    $id   = (int) ($row['id'] ?? 0);

    log_activity(
        'scripts.' . $what,
        'custom_script',
        $id > 0 ? $id : null,
        ucfirst($what) . ' the "' . $name . '" snippet (' . (string) ($row['type'] ?? '?')
            . ', ' . (string) ($row['placement'] ?? '?') . ', ' . (string) ($row['consent'] ?? '?') . ')'
    );

    security_event('settings.scripts_changed', 'critical', [
        'snippet'     => $name,
        'snippet_id'  => $id,
        'change'      => $what,
        'type'        => (string) ($row['type'] ?? ''),
        'placement'   => (string) ($row['placement'] ?? ''),
        'consent'     => (string) ($row['consent'] ?? ''),
        'enabled'     => (int) ($row['is_enabled'] ?? 0) === 1,
        'bytes_from'  => $before === '' ? 0 : mb_strlen($before),
        'bytes_to'    => $after === '' ? 0 : mb_strlen($after),
        'sha256_from' => $before === '' ? null : hash('sha256', $before),
        'sha256_to'   => $after === '' ? null : hash('sha256', $after),
    ], $admin !== null ? (int) $admin['id'] : null, 'admin');
}

// ===========================================================================
//  SUMMARY, for the screen
// ===========================================================================

/**
 * Counts the admin screen's three stat cards read.
 *
 * `stuck` is the figure this screen exists to surface: enabled, saved, and
 * waiting on a consent category the banner will never offer.
 *
 * @return array{total:int, live:int, gated:int, stuck:int, off:int}
 */
function custom_scripts_summary(): array
{
    $out = ['total' => 0, 'live' => 0, 'gated' => 0, 'stuck' => 0, 'off' => 0];

    foreach (custom_scripts_all() as $row) {
        $out['total']++;

        if ((int) $row['is_enabled'] !== 1) {
            $out['off']++;
            continue;
        }

        $status = custom_scripts_status($row);

        if ($status['label'] === 'Live') {
            $out['live']++;
        } elseif ($status['label'] === 'Never runs') {
            $out['stuck']++;
        } else {
            $out['gated']++;
        }
    }

    return $out;
}
