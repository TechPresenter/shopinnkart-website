<?php
/**
 * ShopInnKart Admin - Analytics & privacy.
 *
 * One screen for the question "who is allowed to watch this shopper, and who
 * asked them". It owns three things:
 *
 *   1. the consent layer - whether a banner is needed at all, and its wording;
 *   2. DNT / GPC;
 *   3. the third-party tag ids that the consent layer gates.
 *
 * The Google Analytics and Meta Pixel ids stay owned by Settings > SEO, where
 * they have always lived; this screen shows their current state read-only and
 * links there, because two screens writing one key is how a value ends up
 * different depending on which page you saved last.
 *
 * The help text works hard on one distinction on purpose. Our own first-party
 * analytics in `anonymous` mode sets no cookie and stores no IP, so it needs
 * no banner; the third-party tags do. An owner who reads "analytics" and
 * switches off the wrong one gets a store that has stopped counting and is
 * still handing every visit to Google.
 *
 * Phase B1 shipped the consent half. B2 added the collector, so this screen
 * also carries its housekeeping (excluded addresses, retention, beacon timing)
 * and a read-only panel saying whether it is actually recording anything right
 * now - which is the first thing an owner wants after switching it on.
 *
 * B3 added the daily totals and the clean-up, so it also answers the two
 * questions an owner asks next: are my reports up to date, and what is this
 * still holding on to. Both come with a button, because "run it now" is the
 * only honest answer for a store whose host has no cron.
 *
 * It now also owns VISITOR LOCATION. The lookup itself has existed since B2 and
 * was gated on a setting no screen wrote, so every store read "Unknown" and had
 * no way to change that. Two fields turn it on; everything else about the card
 * is the truth an owner needs in order to read the resulting numbers:
 *
 *   - the source and, for Cloudflare, why the header is only believed when the
 *     connection really came from Cloudflare;
 *   - a LIVE line from an_geo_status() saying what happened on THIS request,
 *     which is a different question from what is configured;
 *   - the granularity, defaulting to country, because a city inferred from an
 *     address is a guess and an owner who knows that reads the rows differently.
 *
 * No address is stored by any of it - see includes/analytics/geo.php - and the
 * card says so, because "we record where you are" and "we keep your address"
 * are the same sentence to most readers and only one of them is true here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// settings.view / settings.edit, like every other screen in this tab strip.
// B3 introduces the finer-grained `analytics.settings` permission (already
// registered by the B1 migration) once there is analytics data to protect.
$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once INCLUDES_PATH . '/consent.php';

// The location half: the option keys below are the values geo.php accepts, and
// the labels are this screen's copy. Required here rather than further down
// because the field spec is built from AN_GEO_SOURCES / AN_GEO_PRECISIONS, so a
// value added to one cannot go missing from the other.
require_once INCLUDES_PATH . '/analytics/geo.php';

/** Plain-language consequence of each mode, shown under the select. */
const ANALYTICS_MODES = [
    'off'          => 'Off - nothing is counted',
    'anonymous'    => 'Anonymous - count visits, no cookie, no IP stored (recommended)',
    'consent_only' => 'Consent only - count nothing until the visitor allows analytics',
    'notice'       => 'Notice - identify devices by default, with an opt-out',
];

/** One line per location source, saying what it actually reads. */
const ANALYTICS_GEO_SOURCE_LABELS = [
    'off'        => 'Off - every location reads Unknown',
    'cloudflare' => 'Cloudflare - the country header it adds to each request',
    'geolite2'   => 'MaxMind GeoLite2 - a database file you put on this server',
];

/** How much of a location may be kept. Country is the narrower answer. */
const ANALYTICS_GEO_PRECISION_LABELS = [
    'country' => 'Country only (recommended)',
    'city'    => 'Country, city, and state within India',
];

/**
 * options for a select, in the order geo.php lists the valid values.
 *
 * Built from the constants in geo.php on purpose: settings_handle_save()
 * refuses any value not in this array, so a source added to geo.php without a
 * label here would be rejected by the screen with no explanation - and a label
 * here for a value geo.php does not accept would be saved and then read as
 * "off". The array_key_exists guard makes the mismatch visible instead.
 */
function analytics_geo_options(array $values, array $labels): array
{
    $options = [];
    foreach ($values as $value) {
        $options[$value] = $labels[$value] ?? $value;
    }
    return $options;
}

$spec = [
    'analytics_mode' => [
        'type'    => 'select',
        'label'   => 'How this store counts its own visits',
        'options' => ANALYTICS_MODES,
        'default' => 'anonymous',
        'group'   => 'analytics',
        // No help line: each option above already states its own consequence,
        // including that anonymous needs no banner.
    ],
    'analytics_honor_dnt' => [
        'type'    => 'bool',
        'label'   => 'Honour Do Not Track and Global Privacy Control',
        'default' => '1',
        'group'   => 'analytics',
        'help'    => 'No tag loads, and advertising is treated as refused.',
    ],
    'google_tag_manager_id' => [
        'type'          => 'text',
        'label'         => 'Google Tag Manager container ID',
        'max'           => 20,
        'placeholder'   => 'GTM-XXXXXXX',
        'group'         => 'analytics',
        'pattern'       => '/^GTM-[A-Z0-9]{4,10}$/i',
        // "Loads only after the visitor allows advertising" is the card
        // subtitle's job; it is true of every tag on this card.
        'pattern_error' => 'A Tag Manager container ID looks like GTM-ABC1234.',
    ],
    'clarity_project_id' => [
        'type'          => 'text',
        'label'         => 'Microsoft Clarity project ID',
        'max'           => 20,
        'placeholder'   => 'abcdefghij',
        'group'         => 'analytics',
        'pattern'       => '/^[a-z0-9]{6,15}$/i',
        'pattern_error' => 'A Clarity project ID is 6-15 letters and digits.',
        'help'          => 'Session recording and heatmaps. Counts as advertising.',
    ],
    'consent_banner_title' => [
        'type'        => 'text',
        'label'       => 'Banner heading',
        'max'         => 120,
        'placeholder' => 'Your choice about cookies',
        'group'       => 'analytics',
        'help'        => 'Blank uses the shipped wording.',
    ],
    'consent_banner_text' => [
        'type'        => 'textarea',
        'label'       => 'Banner text',
        'max'         => 600,
        'rows'        => 4,
        'group'       => 'analytics',
        'help'        => 'Blank uses the shipped wording; edit the Cookie Policy to match.',
    ],
    'analytics_exclude_ips' => [
        'type'        => 'textarea',
        'label'       => 'Addresses never counted',
        'max'         => 1000,
        'rows'        => 3,
        'group'       => 'analytics',
        'placeholder' => "203.0.113.0/24\n198.51.100.7",
        'help'        => 'One per line, or comma separated. Address or CIDR range.',
    ],
    'analytics_retention_days' => [
        'type'      => 'number',
        'label'     => 'Days of detailed rows to keep',
        'default'   => '60',
        // Required on purpose. A blank non-required field is STORED as an
        // empty string, and settings_values() then renders the default in its
        // place - so the screen would show 60 while the row held nothing, and
        // the two would disagree until the next save. There is also no useful
        // meaning for "blank" here: every answer is a number of days.
        'required'  => true,
        'min_value' => 30,
        'max_value' => 180,
        'group'     => 'analytics',
        'help'      => 'Shortening loses detail, never the daily totals.',
    ],
    'analytics_debug_timing' => [
        'type'    => 'bool',
        'label'   => 'Log how long each beacon takes',
        'default' => '0',
        'group'   => 'analytics',
        // One line per beacon in the application log; for diagnosing a slow
        // store, not for everyday use.
        'help'    => 'Leave off: the log grows with your traffic.',
    ],
    'analytics_geo_source' => [
        'type'    => 'select',
        'label'   => 'Where location comes from',
        'options' => analytics_geo_options(AN_GEO_SOURCES, ANALYTICS_GEO_SOURCE_LABELS),
        'default' => 'off',
        // Required for the same reason the two day counts are: a select that
        // posts nothing is STORED as an empty string, and settings_values()
        // then renders the default over it, so the screen would show Off while
        // the row held nothing and the two would disagree until the next save.
        'required' => true,
        'group'   => 'analytics',
        'help'    => 'No visitor address is ever stored, whichever you choose.',
    ],
    'analytics_geo_precision' => [
        'type'     => 'select',
        'label'    => 'How precisely location is recorded',
        'options'  => analytics_geo_options(AN_GEO_PRECISIONS, ANALYTICS_GEO_PRECISION_LABELS),
        'default'  => 'country',
        'required' => true,
        'group'    => 'analytics',
        'help'     => 'City from an address is a guess, often the wrong town.',
    ],
    'analytics_rollup_on_demand' => [
        'type'    => 'bool',
        'label'   => 'Update the totals when a report is opened',
        'default' => '1',
        'group'   => 'analytics',
        'help'    => 'Leave on unless your host runs the nightly job.',
    ],
    'analytics_commerce_window_days' => [
        'type'      => 'number',
        'label'     => 'Days over which sales figures are corrected',
        'default'   => '45',
        // Same reasoning as the retention field: a blank number of days has
        // no meaning, and a blank non-required field is stored as an empty
        // string while the screen goes on showing the default.
        'required'  => true,
        'min_value' => 1,
        'max_value' => 180,
        'group'     => 'analytics',
        // An order confirmed, cancelled or refunded after the fact changes
        // the day it was PLACED on, so the nightly job has to revisit that
        // day. Anything shorter than the returns window leaves it wrong.
        'help'      => 'Longer than your returns window is the right answer.',
    ],
];

// Admin > Appearance harvests every screen's spec; see settings_spec_harvest().
if (defined('SETTINGS_SPEC_ONLY')) {
    return ['spec' => $spec, 'group' => 'analytics'];
}

require_once INCLUDES_PATH . '/analytics/rollup.php';

$selfUrl = settings_url('analytics');
$action  = (string) input('action', '');

// ---------------------------------------------------------------------------
// The three things an owner can ask this screen to DO. Each one is a separate
// POST with its own action, so none of them can be triggered by saving the
// form - and the destructive one is a different button in a different card
// with its own confirmation.
// ---------------------------------------------------------------------------
if (is_post() && $action === 'rollup_now') {
    admin_require_action('settings.edit');

    if (!analytics_rollup_lock(3)) {
        flash('error', 'Another update is already running. Try again in a moment.');
        redirect($selfUrl);
    }

    try {
        // Forced, so it re-reads the recent days even when the cursor thinks
        // they are done - which is what somebody pressing this button after
        // fixing something actually wants.
        $result = analytics_rollup_run(['rebuild' => date('Y-m-d', strtotime('-7 days')), 'days' => 8]);
    } finally {
        analytics_rollup_unlock();
    }

    log_activity('analytics.rollup', 'analytics', 0, 'Rebuilt the last 7 days of analytics totals');
    admin_after_write();

    flash(
        $result['errors'] === [] ? 'success' : 'error',
        $result['errors'] === []
            ? 'Totals rebuilt for the last 7 days in ' . number_format($result['ms'] / 1000, 1) . ' seconds.'
            : 'Some days could not be rebuilt: ' . e(reset($result['errors']))
    );
    redirect($selfUrl);
}

if (is_post() && $action === 'purge_now') {
    admin_require_action('settings.edit');

    $purge = analytics_purge();
    $total = array_sum($purge['deleted']);

    log_activity('analytics.purge', 'analytics', 0, 'Deleted ' . $total . ' analytics rows past retention');
    admin_after_write();

    if ($purge['through'] === '') {
        flash('error', 'Nothing was deleted: no day has been totalled yet, and detail is never deleted '
                     . 'before the totals that replace it exist.');
    } else {
        flash('success', number_format($total) . ' row(s) older than ' . analytics_retention_days()
                       . ' days deleted.' . ($purge['capped'] !== []
                ? ' More remains - run it again, or let the nightly job finish it.' : ''));
    }
    redirect($selfUrl);
}

if (is_post() && $action === 'erase_all') {
    admin_require_action('settings.edit');

    // Typing the word is the confirmation. A dialog is dismissed by reflex;
    // this cannot be.
    if (strtoupper(trim((string) input('confirm', ''))) !== 'ERASE') {
        flash('error', 'Nothing was deleted. Type ERASE in the box to confirm.');
        redirect($selfUrl);
    }

    $deleted = analytics_erase_all();

    log_activity('analytics.erase', 'analytics', 0,
        'Erased all analytics data (' . array_sum($deleted) . ' rows)');
    admin_after_write();

    flash('success', 'All analytics data erased (' . number_format(array_sum($deleted)) . ' rows). '
                   . 'Counting continues from now unless you switch it off above.');
    redirect($selfUrl);
}

if (is_post() && $action === '') {
    settings_handle_save('analytics', 'analytics', $spec, settings_group('analytics'));
}

$stored = settings_group('analytics');
$errors = errors_pull();
$values = settings_values($spec, $stored);

// ---------------------------------------------------------------------------
// What the storefront will actually do, computed from the same functions the
// storefront uses - not from a second reading of the settings table. A status
// panel that agrees with the code only by coincidence is worse than none.
// ---------------------------------------------------------------------------
$tagIds     = consent_tag_ids();
$gaRaw      = trim((string) setting('google_analytics_id', ''));
$pixelRaw   = trim((string) setting('meta_pixel_id', ''));
$hasTags    = consent_has_tags();
$needsOptIn = consent_analytics_needs_optin();
$bannerOn   = $hasTags || $needsOptIn;

$tagRows = [
    ['Google Analytics',   $gaRaw,              $tagIds['ga'],      'Settings > SEO'],
    ['Google Tag Manager', (string) $values['google_tag_manager_id'], $tagIds['gtm'], 'this screen'],
    ['Meta Pixel',         $pixelRaw,           $tagIds['pixel'],   'Settings > SEO'],
    ['Microsoft Clarity',  (string) $values['clarity_project_id'],   $tagIds['clarity'], 'this screen'],
];

// ---------------------------------------------------------------------------
// The collector's own health.
//
// Read-only, and deliberately from the raw tables rather than the rollups: on
// this screen the question is "is it running RIGHT NOW", and the rollups are
// yesterday's answer. Counting today's rows is an index range scan on
// ix_created, and it is one page, not a dashboard.
//
// analytics_tracking_allowed() is NOT used here: it answers false for an
// admin, by design, so on this page it would always report "not counting".
// ---------------------------------------------------------------------------
require_once INCLUDES_PATH . '/analytics/collect.php';

$collector = ['ready' => false, 'mode' => analytics_mode()];

if (analytics_tables_ready()) {
    $collector['ready']     = true;
    $collector['views']     = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `an_pageviews` WHERE `created_at` >= CURDATE()'
    );
    $collector['sessions']  = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `an_sessions` WHERE `day` = CURDATE()'
    );
    $collector['last_seen'] = Database::fetchColumn(
        'SELECT MAX(`created_at`) FROM `an_pageviews`'
    );
    // Every refusal answers 204, so these counters are the only way a real
    // browser being turned away by mistake ever becomes visible.
    $collector['rejects'] = Database::fetchPairs(
        "SELECT REPLACE(`k`, 'rejects.', ''), `v` FROM `an_state`
          WHERE `k` LIKE 'rejects.%' ORDER BY CAST(`v` AS UNSIGNED) DESC"
    );
}

/** Plain English for each refusal reason, so the counters are readable. */
const ANALYTICS_REJECTS = [
    'token'      => 'Unsigned or expired page token',
    'bot'        => 'Known crawler',
    'mode'       => 'Counting was switched off',
    'dnt'        => 'Do Not Track / GPC',
    'consent'    => 'Visitor had not agreed',
    'excluded'   => 'Address on your exclude list',
    'site'       => 'Sent from another site',
    'method'     => 'Not a POST',
    'body'       => 'Oversized body',
    'kind'       => 'Unknown beacon type',
    'event_name' => 'Event the browser may not send',
    'target'     => 'Beacon for a page view that never arrived',
    'cap_pv'     => 'Too many page views on one token',
];

// ---------------------------------------------------------------------------
// The rollup's own health: how fresh the reports are, when the job last ran,
// and what the retention window is currently holding.
//
// Deliberately NOT calling analytics_rollup_lazy() here. This screen is where
// an owner comes when something looks wrong, and a page that silently repairs
// the thing it is reporting on cannot be used to diagnose it. The button does
// it, visibly, when asked.
// ---------------------------------------------------------------------------
$rollup = analytics_rollup_status();

// Log retention is shown, never edited here: the windows live on Security >
// Settings and are enforced by bin/prune-logs.php. Two screens writing one
// key is how a value ends up depending on which page you saved last, and two
// jobs deleting from one table on different rules is how a retention promise
// quietly becomes untrue.
require_once INCLUDES_PATH . '/retention.php';
$logWindows = [];
foreach (['error_logs', 'search_logs', 'notification_queue'] as $table) {
    $spec_ = retention_tables()[$table] ?? null;
    if ($spec_ !== null) {
        $logWindows[] = [$spec_['label'], retention_days($table)];
    }
}

// ---------------------------------------------------------------------------
// Visitor location, as it stands ON THIS REQUEST.
//
// an_geo_status() is the single source: this screen must not compute "is geo
// working" a second way, because a status panel that agrees with the collector
// only by coincidence is worse than no panel. The distinction it draws matters
// here - `live` is about this one request (Cloudflare's header arrives per
// request), `configured_ok` is about the setup - so the card shows both rather
// than flattening them into one green tick.
// ---------------------------------------------------------------------------
$geo = an_geo_status();

// A location is only ever written as part of a visit, so "counting off" beats
// anything the source says. Without this the panel would read Recording while
// the store was counting nothing at all - the source really is working, and
// there is no visit for it to be working on.
$geoBlocked = ($collector['mode'] ?? '') === 'off' && $geo['source'] !== 'off';

$geoState = 'gray';
$geoWord  = 'Off';
if ($geoBlocked) {
    $geoWord = 'Not recording';
} elseif ($geo['source'] !== 'off') {
    if ($geo['live']) {
        $geoState = 'green';
        $geoWord  = 'Recording';
    } elseif ($geo['configured_ok']) {
        // Cloudflare, configured and capable, but this particular request did
        // not come through it. Not a fault, and not a green tick either.
        $geoState = 'amber';
        $geoWord  = 'Not on this request';
    } else {
        $geoState = 'red';
        $geoWord  = 'Not working';
    }
}

$pageTitle    = 'Analytics & Privacy';
$pageSubtitle = 'Consent, Do Not Track, visitor location, and the tags they gate.';
$breadcrumbs  = settings_breadcrumbs('analytics');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('analytics') ?>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" data-guard-unsaved>
        <?= csrf_field() ?>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Third-party tags</div>
                    <div class="ad-card__sub">Google and Meta scripts. None loads until the visitor allows advertising.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('google_tag_manager_id', $spec, $values, $errors) ?>
                <?= settings_field('clarity_project_id', $spec, $values, $errors) ?>

                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        Google Analytics and Meta Pixel IDs are edited in
                        <a href="<?= e(settings_url('seo')) ?>">Settings &rsaquo; SEO</a>.
                        This screen decides whether they may run.
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">This store's own counting</div>
                    <div class="ad-card__sub">Your own server counts the visit, and nobody else is told.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('analytics_mode', $spec, $values, $errors) ?>
                <?= settings_field('analytics_honor_dnt', $spec, $values, $errors) ?>

                <div class="sik-alert sik-alert--warning" style="margin:0">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div>
                        <strong>This is not a master privacy switch.</strong>
                        Off stops <em>your</em> visitor counts, not Google or Meta.
                        To stop them, clear the tag IDs above.
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Consent banner wording</div>
                    <div class="ad-card__sub">Accept, Reject and Choose are always equal, and not configurable.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('consent_banner_title', $spec, $values, $errors) ?>
                <?= settings_field('consent_banner_text', $spec, $values, $errors) ?>

                <details>
                    <summary>Why the three buttons cannot be changed</summary>
                    <p>
                        Accept, Reject and Choose are shown together, at the same size, in that order.
                        An easier Accept than Reject is what makes a consent banner invalid, so the
                        screen does not offer it as a setting.
                    </p>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Collector</div>
                    <div class="ad-card__sub">Whose visits are skipped, and how long the detail is kept.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('analytics_exclude_ips', $spec, $values, $errors) ?>
                <?= settings_field('analytics_retention_days', $spec, $values, $errors) ?>
                <?= settings_field('analytics_debug_timing', $spec, $values, $errors) ?>

                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        <strong>You are never counted yourself.</strong>
                        No page view is recorded while an admin is signed in.
                        The list above is for colleagues.
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0" id="location">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Visitor location</div>
                    <div class="ad-card__sub">Worked out as the visit is counted, then the address is dropped.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('analytics_geo_source', $spec, $values, $errors) ?>
                <?= settings_field('analytics_geo_precision', $spec, $values, $errors) ?>

                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        <strong>No address is stored.</strong>
                        The lookup happens in memory as the visit is recorded, and only the
                        country - and the city, if you ask for it - is written down.
                        That is why your own counting still needs no banner.
                    </div>
                </div>

                <details>
                    <summary>Setting up Cloudflare</summary>
                    <p>
                        Cloudflare adds <code class="ad-mono">CF-IPCountry</code> to every request it forwards,
                        once <strong>IP Geolocation</strong> is switched on in its Network settings.
                        City and state need its <em>Add visitor location headers</em> transform as well,
                        which depends on your plan.
                    </p>
                    <p>
                        The header is believed <strong>only</strong> when the machine that connected is inside
                        Cloudflare's <?= (int) $geo['ranges'] ?> published ranges, which are embedded in
                        <code class="ad-mono">includes/analytics/geo.php</code>. Otherwise it is ignored:
                        <code class="ad-mono">CF-IPCountry</code> is just a header, so without that check a
                        visitor could send one and label their own traffic, which is worse than no data.
                    </p>
                </details>

                <details>
                    <summary>Setting up MaxMind GeoLite2</summary>
                    <p>
                        Put <code class="ad-mono"><?= e(AN_GEO_FILES[0]) ?></code> (or
                        <code class="ad-mono"><?= e(AN_GEO_FILES[1]) ?></code>) in
                        <code class="ad-mono"><?= e($geo['dir']) ?></code>, which is denied to the web,
                        and unpack the MaxMind reader so that
                        <code class="ad-mono"><?= e(ROOT_PATH . AN_GEO_READER_DIRS[0]) ?>/MaxMind/Db/Reader.php</code>
                        exists. The panel on the right then says whether both were found, what the file's
                        build date is, and whether it can actually resolve an address.
                        Nothing is sent to MaxMind: the lookup reads that local file.
                    </p>
                    <p>
                        GeoLite2 is rebuilt weekly and its accuracy drifts as addresses are reassigned,
                        so a copy more than <?= (int) AN_GEO_STALE_DAYS ?> days old is flagged.
                        MaxMind's licence asks that you credit it wherever you publish figures drawn from it:
                        <em>includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com</em>.
                    </p>
                </details>

                <details>
                    <summary>What these numbers can and cannot tell you</summary>
                    <p>
                        <strong>Country</strong> is reliable enough to act on.
                        <strong>City</strong> is inferred from the address block and is often the wrong town -
                        frequently the city of the network's registered office, not the visitor's.
                        <strong>State</strong> is recorded for India only; a worldwide region column would
                        multiply the size of the daily totals for rows nobody here reads.
                    </p>
                    <p>
                        Visits counted <em>before</em> you switch this on carry no location at all and go on
                        reading Unknown, because the address they were counted from was never kept.
                        Switching the source back to Off stops new locations being recorded and leaves the
                        ones already collected in place.
                    </p>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Daily totals</div>
                    <div class="ad-card__sub">Reports read one total per day, not the individual visits.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('analytics_rollup_on_demand', $spec, $values, $errors) ?>
                <?= settings_field('analytics_commerce_window_days', $spec, $values, $errors) ?>

                <details>
                    <summary>Run the nightly job from cron instead</summary>
                    <p>
                        Once a day, a few minutes after midnight. It is faster than the on-demand catch-up,
                        it reaches further back, and it does the clean-up in the same pass. On demand,
                        a report catches up at most three days and at most once every fifteen minutes,
                        so it is never slow because of it.<br>
                        <code class="ad-mono">php <?= e(ROOT_PATH) ?>/bin/analytics-rollup.php --quiet</code>
                    </p>
                </details>
            </div>
            <?= settings_save_bar('Changes apply on the next storefront page load.') ?>
        </div>
    </form>

    <aside class="ad-side">
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">What the storefront does now</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:12px">
                <div>
                    <div style="font-size:var(--ad-text-sm);color:var(--ad-muted)">Consent banner</div>
                    <?php if ($bannerOn): ?>
                        <span class="sik-status sik-status--green">Shown</span>
                        <div style="font-size:var(--ad-text-sm);margin-top:4px">
                            <?= $hasTags ? 'A third-party tag is configured' : 'The analytics mode needs an opt-in' ?>,
                            so visitors are asked before anything runs.
                        </div>
                    <?php else: ?>
                        <span class="sik-status sik-status--gray">Not shown</span>
                        <div style="font-size:var(--ad-text-sm);margin-top:4px">
                            Nothing to ask about: no banner, no consent cookie, no <code>consent.js</code>.
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div style="font-size:var(--ad-text-sm);color:var(--ad-muted);margin-bottom:4px">Tags</div>
                    <?php foreach ($tagRows as [$label, $raw, $valid, $where]): ?>
                        <div style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:var(--ad-text-sm)">
                            <?php if ($raw === ''): ?>
                                <span class="sik-status sik-status--gray">Off</span>
                            <?php elseif ($valid === ''): ?>
                                <span class="sik-status sik-status--red">Invalid</span>
                            <?php else: ?>
                                <span class="sik-status sik-status--amber">On consent</span>
                            <?php endif; ?>
                            <span style="flex:1;min-width:0"><?= e($label) ?></span>
                            <?php if ($raw !== '' && $valid === ''): ?>
                                <span class="ad-muted" title="Set in <?= e_attr($where) ?>">not a valid ID</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div>
                    <div style="font-size:var(--ad-text-sm);color:var(--ad-muted)">Do Not Track / GPC</div>
                    <?php if (setting_bool('analytics_honor_dnt', true)): ?>
                        <span class="sik-status sik-status--green">Honoured</span>
                    <?php else: ?>
                        <span class="sik-status sik-status--red">Ignored</span>
                    <?php endif; ?>
                </div>

                <div>
                    <div style="font-size:var(--ad-text-sm);color:var(--ad-muted)">Your own page views</div>
                    <span class="sik-status sik-status--green">Never tagged</span>
                    <div style="font-size:var(--ad-text-sm);margin-top:4px">
                        The shop and the back office share one session.
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Collector</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:12px;font-size:var(--ad-text-sm)">
                <?php if (!$collector['ready']): ?>
                    <div>
                        <span class="sik-status sik-status--red">Tables missing</span>
                        <div style="margin-top:4px">
                            Run <code>php database/migrations/2026_09_24_analytics_tables.php</code>.
                            Nothing is being recorded until you do.
                        </div>
                    </div>
                <?php elseif ($collector['mode'] === 'off'): ?>
                    <div>
                        <span class="sik-status sik-status--gray">Not counting</span>
                        <div style="margin-top:4px">
                            The mode above is <strong>Off</strong>, so no page ships the counter and nothing
                            new is recorded. What was already collected is still here.
                        </div>
                    </div>
                <?php else: ?>
                    <div>
                        <span class="sik-status sik-status--green">Counting</span>
                        <div style="margin-top:4px">
                            <strong><?= number_format($collector['views']) ?></strong>
                            page view<?= $collector['views'] === 1 ? '' : 's' ?> and
                            <strong><?= number_format($collector['sessions']) ?></strong>
                            visit<?= $collector['sessions'] === 1 ? '' : 's' ?> so far today.
                            <?php if ($collector['views'] === 0 && $collector['last_seen'] !== null): ?>
                                Nothing since <?= e(format_datetime((string) $collector['last_seen'])) ?>.
                            <?php elseif ($collector['views'] === 0): ?>
                                Nothing yet - the first real visitor will appear here.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($collector['ready'] && !empty($collector['rejects'])): ?>
                    <div>
                        <div style="color:var(--ad-muted);margin-bottom:4px">Beacons turned away</div>
                        <?php foreach ($collector['rejects'] as $reason => $count): ?>
                            <div style="display:flex;gap:8px;padding:2px 0">
                                <span style="flex:1;min-width:0"><?= e(ANALYTICS_REJECTS[$reason] ?? $reason) ?></span>
                                <span class="ad-muted"><?= number_format((int) $count) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="ad-muted" style="margin-top:6px">
                            Crawlers and expired tokens are normal. Anything else large is worth a look.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Visitor location</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:12px;font-size:var(--ad-text-sm)">
                <?php
                // The live line. Everything here comes from an_geo_status(), so it
                // describes this request rather than repeating the setting back.
                ?>
                <div>
                    <span class="sik-status sik-status--<?= e($geoState) ?>"><?= e($geoWord) ?></span>
                    <?php if ($geoBlocked): ?>
                        <div style="margin-top:4px">
                            Counting is switched off above, so there is no visit to attach a location to.
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:4px"><?= e($geo['detail']) ?></div>
                    <?php if ($geo['hint'] !== ''): ?>
                        <div class="ad-muted" style="margin-top:4px"><?= e($geo['hint']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($geo['source'] !== 'off'): ?>
                    <div>
                        <?php // "Kept" only where something is actually being kept. ?>
                        <div style="color:var(--ad-muted)"><?= $geoBlocked
                                ? 'Would be kept, once counting is on'
                                : ($geo['configured_ok'] ? 'Kept for each visit' : 'Would be kept, once this works') ?></div>
                        <?= $geo['precision'] === 'country'
                                ? 'The country code, and nothing else.'
                                : 'The country code, the city, and the state within India.' ?>
                    </div>
                <?php endif; ?>

                <?php if ($geo['source'] === 'geolite2'): ?>
                    <div>
                        <div style="display:flex;gap:8px;padding:2px 0">
                            <span style="flex:1;min-width:0">Database file</span>
                            <span class="ad-muted"><?= $geo['file'] !== '' ? e($geo['file']) : 'not found' ?></span>
                        </div>
                        <div style="display:flex;gap:8px;padding:2px 0">
                            <span style="flex:1;min-width:0">Built</span>
                            <span class="ad-muted"><?php
                                if ($geo['built'] !== null) {
                                    echo e(format_date($geo['built']));
                                } elseif ($geo['file_date'] !== null) {
                                    // Not the build date: the reader could not be
                                    // asked, so this is when the file landed here.
                                    echo e(format_date($geo['file_date'])) . ' (copied)';
                                } else {
                                    echo 'unknown';
                                }
                            ?></span>
                        </div>
                        <div style="display:flex;gap:8px;padding:2px 0">
                            <span style="flex:1;min-width:0">MaxMind reader</span>
                            <span class="ad-muted"><?= $geo['reader'] ? 'installed' : 'not installed' ?></span>
                        </div>
                        <?php if ($geo['reader_error'] !== ''): ?>
                            <div class="ad-muted" style="margin-top:4px"><?= e($geo['reader_error']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($geo['source'] === 'off' && an_geo_database_file() !== ''): ?>
                    <div class="ad-muted">
                        A GeoLite2 file is already in <code class="ad-mono">storage/geo</code><?=
                            $geo['reader'] ? ', and the reader is installed' : ', but the reader is not installed' ?>.
                    </div>
                <?php endif; ?>

                <?php if ($geo['attribution'] !== ''): ?>
                    <div class="ad-muted"><?= e($geo['attribution']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Reports</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:12px;font-size:var(--ad-text-sm)">
                <?php if (!$rollup['ready']): ?>
                    <div>
                        <span class="sik-status sik-status--red">Tables missing</span>
                        <div style="margin-top:4px">
                            Run <code class="ad-mono">php database/migrations/2026_09_24_analytics_rollups.php</code>.
                        </div>
                    </div>
                <?php else: ?>
                    <div>
                        <?php if ($rollup['through'] === null): ?>
                            <span class="sik-status sik-status--gray">Never totalled</span>
                            <div style="margin-top:4px">
                                Happens on the first nightly run, or with the button below.
                            </div>
                        <?php elseif ($rollup['lag_days'] <= 0): ?>
                            <span class="sik-status sik-status--green">Up to date</span>
                            <div style="margin-top:4px">
                                Complete through <?= e(format_date($rollup['through'])) ?>.
                                Today keeps changing until midnight.
                            </div>
                        <?php else: ?>
                            <span class="sik-status sik-status--amber"><?= (int) $rollup['lag_days'] ?> day<?= $rollup['lag_days'] === 1 ? '' : 's' ?> behind</span>
                            <div style="margin-top:4px">
                                Complete only through <?= e(format_date($rollup['through'])) ?>.
                                Reports are missing the days since.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($rollup['last_run'])): ?>
                        <div class="ad-muted">
                            Last run <?= e(time_ago((string) ($rollup['last_run']['at'] ?? ''))) ?>,
                            <?= number_format((int) ($rollup['last_run']['days'] ?? 0)) ?> day(s) in
                            <?= e(number_format(((float) ($rollup['last_run']['ms'] ?? 0)) / 1000, 1)) ?> s,
                            by <?= e((string) ($rollup['last_run']['by'] ?? 'cron')) ?>.
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rollup_now">
                        <button type="submit" class="ad-btn ad-btn--sm">Rebuild the last 7 days</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">What is stored</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:12px;font-size:var(--ad-text-sm)">
                <?php if ($rollup['ready']): ?>
                    <div>
                        <strong><?= number_format($rollup['raw_rows']) ?></strong> visit-by-visit row(s),
                        kept <?= (int) $rollup['retention_days'] ?> days<?php if ($rollup['oldest_raw'] !== null): ?>,
                        oldest <?= e(format_date($rollup['oldest_raw'])) ?><?php endif; ?>.
                        <div class="ad-muted" style="margin-top:4px">
                            <strong><?= number_format($rollup['rollup_rows']) ?></strong> daily total(s), kept for good.
                            Shortening the window above loses detail, never history.
                        </div>
                    </div>

                    <?php if ($rollup['purge'] !== null): ?>
                        <div class="ad-muted">
                            Last clean-up <?= e(time_ago((string) $rollup['purge']['at'])) ?><?php
                                if (($rollup['purge']['through'] ?? '') !== ''): ?>,
                                deleted through <?= e(format_date((string) $rollup['purge']['through'])) ?><?php
                                endif; ?>.
                        </div>
                    <?php else: ?>
                        <div class="ad-muted">The clean-up has never run.</div>
                    <?php endif; ?>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge_now">
                        <button type="submit" class="ad-btn ad-btn--sm ad-btn--ghost">Delete detail past the window</button>
                    </form>

                    <?php
                    // The totals are the permanent record. Deleting the rows they are built from
                    // before they exist would leave a hole nothing could fill, so the purge refuses
                    // any day that has not been totalled first.
                    ?>
                    <div class="ad-muted">
                        Detail is never deleted for a day that has not been totalled first.
                    </div>
                <?php endif; ?>

                <?php if ($logWindows !== []): ?>
                    <div>
                        <div style="color:var(--ad-muted);margin-bottom:4px">Logs, kept separately</div>
                        <?php foreach ($logWindows as [$label, $days]): ?>
                            <div style="display:flex;gap:8px;padding:2px 0">
                                <span style="flex:1;min-width:0"><?= e($label) ?></span>
                                <span class="ad-muted"><?= (int) $days ?> days</span>
                            </div>
                        <?php endforeach; ?>
                        <div class="ad-muted" style="margin-top:6px">
                            Set in <a href="<?= e(admin_url('security/settings.php#retention')) ?>">Security &rsaquo; Data retention</a>
                            and deleted by <code class="ad-mono">bin/prune-logs.php</code>, not by the analytics job.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Erase everything</div>
                </div>
            </div>
            <div class="ad-card__body" style="font-size:var(--ad-text-sm);display:grid;gap:10px">
                <?php // A callout, not a bolded line in a card body. Every other
                      // irreversible action in this admin says so in a warning box,
                      // and this one deletes more rows than any of them. ?>
                <div class="sik-alert sik-alert--warning">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div>
                        <strong>There is no undo.</strong>
                        Every visit, page view, daily total and referring URL.
                    </div>
                </div>
                <div class="ad-muted">
                    Counting carries on. To stop collecting, set the mode to <strong>Off</strong> instead.
                </div>
                <form method="post" style="display:grid;gap:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="erase_all">
                    <input type="text" name="confirm" class="sik-input" placeholder="Type ERASE"
                           autocomplete="off" spellcheck="false" aria-label="Type ERASE to confirm">
                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger">Erase all analytics data</button>
                </form>
            </div>
        </div>

        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Still to do</div>
                </div>
            </div>
            <div class="ad-card__body" style="font-size:var(--ad-text-sm);display:grid;gap:8px">
                <?php
                // What the policy pages have to match, in the same words the
                // reports use - an_geo_note() is the one sentence, so the screen,
                // the report footnote and the policy cannot drift apart.
                //
                // This card deliberately does NOT claim whether the published
                // policy is correct. It cannot read those pages and be sure, and
                // a screen that asserts the state of a page somebody else edits
                // is a lie waiting for the next edit.
                ?>
                <div>
                    <strong>Your policies have to say this.</strong>
                    <?= e(an_geo_note(false)) ?>
                    No visitor address is stored, whatever the source.
                    Check the <a href="<?= e(page_url('cookie-policy')) ?>">Cookie Policy</a> and
                    <a href="<?= e(url('privacy-policy')) ?>">Privacy Policy</a> against it.
                </div>

                <div>
                    <strong>One gap.</strong> Anything pasted into
                    <a href="<?= e(settings_url('theme')) ?>">Custom JS</a> runs before this layer, so it
                    runs for visitors who refused.
                </div>
                <div class="ad-muted">
                    The figures these settings govern are in
                    <a href="<?= e(admin_url('analytics/')) ?>">Analytics</a>; location appears there under
                    <a href="<?= e(admin_url('analytics/acquisition.php')) ?>">Acquisition</a>.
                </div>
            </div>
        </div>
    </aside>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
