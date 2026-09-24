<?php
/**
 * ShopInnKart - Policy copy for the consent layer. PREPARED, NOT RUN.
 *
 * Phase B1 changed what the storefront actually does, and two policy pages now
 * describe the old behaviour. Both were wrong in the visitor's favour to read
 * and against the visitor in fact:
 *
 *   1. Cookie Policy, §2: "Analytics and marketing scripts are not part of the
 *      application." They were part of the application. includes/footer.php
 *      loaded Google Tag Manager, Google Analytics and the Meta Pixel on every
 *      storefront page whenever an id was configured.
 *
 *   2. Cookie Policy, §2 table: analytics and marketing were listed as
 *      refusable - "Yes" - when the only mechanism offered was the visitor's
 *      own browser settings. There was no control anywhere on the site.
 *
 *   3. Cookie Policy, §6: Do Not Track was described as something we do not
 *      really act on. It is now honoured, strictly, and the setting that turns
 *      that off is visible in the admin.
 *
 *   4. Privacy Policy, §7: the cross-reference tells the reader to refuse
 *      cookies "in your browser", which is no longer the only way.
 *
 * Every edit below is a sentence-level swap on the live text. Nothing about
 * what data is collected, kept or shared changes - only the description of the
 * controls, which is now accurate. It is still a policy change, and it is the
 * store owner who publishes policy, so this file is DELIBERATELY NOT RUN by
 * any migration or installer. Read it, then run it.
 *
 * Idempotent: an edit whose old text is gone - already applied, or edited by
 * hand since - is skipped and reported, never re-applied.
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
 * Page slug => column => list of [old, new] pairs.
 * An empty "new" removes the old text outright.
 */
function analytics_policy_edits(): array
{
    return [
        'cookie-policy' => [
            'content' => [
                // 1 + 2: the two false sentences, replaced by what the site does.
                [
                    '<p>Analytics and marketing scripts are not part of the application. They load only when the store administrator has entered the corresponding identifier in the settings, and where none is configured no such script and no such cookie is present.</p>',
                    '<p>Analytics and marketing scripts are third party code that runs in your browser, and we treat them as such. They load only when the store administrator has configured the corresponding identifier <em>and</em> you have allowed that category, so on a first visit none of them has run. Where no identifier is configured, no such script and no such cookie exists at all.</p>'
                    . "\n"
                    . '<p>When you first arrive we ask. The banner offers <strong>Accept all</strong>, <strong>Reject all</strong> and <strong>Choose</strong> side by side, at the same size: refusing takes exactly one click, the same as accepting. Your answer is stored in a first party cookie named <code>sik_consent</code>, which holds nothing but your answer and the date - no identifier, no profile - and lasts six months. You can change it at any time through <strong>Cookie preferences</strong> in the footer.</p>',
                ],

                // 2, in the table: "Yes" was true only of the browser's own controls.
                [
                    '<tr><td>Analytics</td><td>Counts visits and shows which pages fail</td><td>Enabled only if the administrator has configured an analytics identifier in the store settings</td><td>Set by the analytics provider</td><td>Yes</td></tr>',
                    '<tr><td>Analytics</td><td>Counts visits and shows which pages fail</td><td>Enabled only if the administrator has configured an analytics identifier in the store settings</td><td>Set by the analytics provider</td><td>Yes. Off until you allow it, and you can withdraw that at any time from Cookie preferences in the footer</td></tr>',
                ],
                [
                    '<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes</td></tr>',
                    '<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes. Off until you allow it, and you can withdraw that at any time from Cookie preferences in the footer</td></tr>',
                ],

                // 3: DNT and GPC are now honoured.
                [
                    '<p>Browsers send these signals inconsistently and there is no settled standard for honouring them. We do not rely on tracking cookies for the site to work, so the practical effect of such a signal here is limited to whichever optional analytics the administrator has enabled.</p>',
                    '<p>We honour them. If your browser sends <strong>Global Privacy Control</strong> (<code>Sec-GPC</code>) or <strong>Do Not Track</strong> (<code>DNT</code>), no analytics or advertising script is loaded and the advertising category is treated as refused, whatever is stored in your consent cookie. You are not asked to choose again on top of a signal you have already sent. We check the request header and the browser property, because not every browser sends both.</p>',
                ],

                // A new answer to a question the page now invites.
                [
                    '<h3>Is local storage different from a cookie?</h3>',
                    '<h3>What happens if I just ignore the banner?</h3>' . "\n"
                    . '<p>Nothing loads. Closing the banner without answering is not consent, so analytics and advertising stay off, and we ask again on your next visit.</p>' . "\n"
                    . '<h3>Is local storage different from a cookie?</h3>',
                ],
            ],
        ],

        'privacy-policy' => [
            'content' => [
                // 4: the browser is no longer the only control.
                [
                    '<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary and how to refuse the rest in your browser.</p>',
                    '<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary. Analytics and advertising are off until you allow them: we ask on your first visit, refusing is a single click, and <strong>Cookie preferences</strong> in the footer reopens the choice whenever you want to change it. We also honour Global Privacy Control and Do Not Track signals sent by your browser.</p>',
                ],
            ],
        ],
    ];
}

/**
 * @return array{applied:int, skipped:int, lines:string[]}
 */
function analytics_policy_apply(bool $dryRun): array
{
    $applied = 0;
    $skipped = 0;
    $lines   = [];

    foreach (analytics_policy_edits() as $slug => $columns) {
        $page = Database::fetch('SELECT `id`, `content` FROM `pages` WHERE `slug` = :s', ['s' => $slug]);
        if ($page === null) {
            $lines[] = '  MISSING page ' . $slug;
            continue;
        }

        foreach ($columns as $column => $pairs) {
            $text    = (string) $page[$column];
            $changed = false;

            foreach ($pairs as $index => [$old, $new]) {
                if (strpos($text, $old) === false) {
                    $skipped++;
                    $lines[] = '  skip  ' . $slug . ' #' . ($index + 1) . ' (old text not found)';
                    continue;
                }
                // str_replace, not preg: the policy text carries regex
                // metacharacters and the match has to be literal.
                $text = str_replace($old, $new, $text);
                $changed = true;
                $applied++;
                $lines[] = '  edit  ' . $slug . ' #' . ($index + 1);
            }

            if ($changed && !$dryRun) {
                Database::update('pages', [$column => $text], '`id` = :id', ['id' => (int) $page['id']]);
            }
        }
    }

    return ['applied' => $applied, 'skipped' => $skipped, 'lines' => $lines];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $dryRun = in_array('--dry', $argv, true);
    $result = analytics_policy_apply($dryRun);

    echo ($dryRun ? "Policy copy for the consent layer (DRY RUN)\n" : "Policy copy for the consent layer\n");
    foreach ($result['lines'] as $line) {
        echo $line . "\n";
    }
    echo '  ' . $result['applied'] . ' edit(s) ' . ($dryRun ? 'would apply' : 'applied')
        . ', ' . $result['skipped'] . " skipped\n";

    if (!$dryRun && $result['applied'] > 0) {
        // The footer caches the policy link list for 10 minutes; the page
        // bodies are not cached, but busting is cheap and avoids a stale
        // footer while somebody is checking the change.
        cache_bust();
        echo "  cache cleared\n";
    }
    exit(0);
}
