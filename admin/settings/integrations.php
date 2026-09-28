<?php
/**
 * ShopInnKart Admin - Integrations.
 *
 * One screen that shows every third-party service this store can be wired into,
 * whether it is actually running, and which screen owns its id. Before it
 * existed the answer was scattered: Google Analytics and the Meta Pixel on
 * Settings > SEO, Tag Manager and Clarity on Settings > Analytics, and Meta
 * CAPI, Google Ads and a custom webhook nowhere at all.
 *
 * ONE KEY, ONE WRITER
 * ------------------
 * This screen writes only the keys includes/integrations.php marks as its own.
 * The five that Settings > SEO and Settings > Analytics already declare in
 * their field spec are shown read-only, with a link to the screen that owns
 * them, because a second form with the same field would wipe the first one's
 * value the moment somebody pressed Save on a blank input. The register is the
 * single place that decides which is which, so the table below and the form
 * below it cannot disagree.
 *
 * VALUES ARE READ KEY BY KEY, NOT BY GROUP
 * ---------------------------------------
 * setting_save() fixes a row's group when it inserts it and never moves it
 * afterwards, so these keys are spread over three groups. google_tag_manager_id
 * is the live example: its row is still in the `seo` group, which is why
 * Settings > Analytics shows that field blank even when a container is
 * configured and firing. Reading by key is what makes this screen tell the
 * truth about it.
 *
 * THE TWO SENDERS, AND WHY THIS SCREEN NEVER SAYS "SENDING" LIGHTLY
 * ----------------------------------------------------------------
 * The Meta Conversions API and the custom webhook are no longer storage only:
 * includes/integration-senders.php queues events and
 * bin/send-integration-events.php delivers them from cron. But a saved token is
 * not a flowing conversion, so every badge and subtitle on this screen comes
 * from integration_sender_status(), which will only say "Sending" when the
 * switch is on, the credentials are readable AND something really queues events.
 *
 * For the webhook all three are true: the worker harvests order events itself.
 * For Meta the third is not: a Purchase can only be queued from the request that
 * shows the order to its buyer, because that is the only place the visitor's
 * advertising decision can be read - and that page is not this agent's file. So
 * the card says "Ready, no source" and prints the one line that finishes it,
 * rather than implying conversions are arriving.
 *
 * NOTHING HERE MAKES AN HTTP CALL ON SAVE
 * --------------------------------------
 * The test buttons QUEUE a clearly-marked event; the worker sends it. "Run the
 * sender now" is the one deliberate exception, the same shape Settings > Email's
 * queue button already has, and it is capped at five events so a page load can
 * never hang on somebody else's server for long.
 *
 * PERMISSIONS
 * ----------
 * settings.view to read, settings.edit to change - including for the test and
 * run buttons, which are writes. No field here takes operator-authored code, so
 * settings.scripts is not required; Admin > SEO > Scripts keeps that gate for
 * the screen that does accept raw markup.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once INCLUDES_PATH . '/integrations.php';
require_once INCLUDES_PATH . '/integration-senders.php';

/**
 * Tokens that must never be rendered back into the form.
 *
 * Handled before settings_handle_save() runs, because the shared pipeline
 * stores what it is given - the same thing Settings > Email does with the SMTP
 * password.
 */
const INTEGRATION_SECRET_KEYS = ['meta_capi_token', 'integration_webhook_secret'];

/** A pasted verification token, not a whole <meta> tag. */
const INTEGRATION_VERIFY_PATTERN = '/^[A-Za-z0-9_-]{8,190}$/';

$tagPatterns = consent_tag_patterns();

$spec = [
    // --- verification: three keys the storefront already read and no screen
    //     had ever offered a field for --------------------------------------
    'bing_site_verification' => [
        'type'          => 'text',
        'label'         => 'Bing Webmaster Tools',
        'max'           => 190,
        'group'         => 'integrations',
        'placeholder'   => '1A2B3C4D5E6F...',
        'pattern'       => INTEGRATION_VERIFY_PATTERN,
        'pattern_error' => 'Paste the content value only, not the whole meta tag.',
    ],
    'pinterest_site_verification' => [
        'type'          => 'text',
        'label'         => 'Pinterest',
        'max'           => 190,
        'group'         => 'integrations',
        'pattern'       => INTEGRATION_VERIFY_PATTERN,
        'pattern_error' => 'Paste the content value only, not the whole meta tag.',
    ],
    'yandex_site_verification' => [
        'type'          => 'text',
        'label'         => 'Yandex',
        'max'           => 190,
        'group'         => 'integrations',
        'pattern'       => INTEGRATION_VERIFY_PATTERN,
        'pattern_error' => 'Paste the content value only, not the whole meta tag.',
    ],

    // --- Google Ads. Gated by includes/consent.php like every other tag ----
    'google_ads_conversion_id' => [
        'type'          => 'text',
        'label'         => 'Conversion ID',
        'max'           => 20,
        'group'         => 'integrations',
        'placeholder'   => 'AW-123456789',
        // The gate's own pattern, so this screen cannot accept an id the
        // storefront would then drop without saying anything.
        'pattern'       => $tagPatterns['gads'],
        'pattern_error' => 'A Google Ads conversion ID looks like AW-123456789.',
    ],

    // --- Meta Conversions API ----------------------------------------------
    'meta_capi_enabled' => [
        'type'  => 'bool',
        'label' => 'Send Purchase events to Meta',
        'group' => 'integrations',
        'help'  => 'Off means nothing is queued and nothing is sent.',
    ],
    'meta_capi_dataset_id' => [
        'type'          => 'text',
        'label'         => 'Dataset ID',
        'max'           => 20,
        'group'         => 'integrations',
        'placeholder'   => '123456789012345',
        'pattern'       => '/^\d{15,16}$/',
        'pattern_error' => 'A dataset ID is 15-16 digits.',
        'help'          => 'Blank means the Meta Pixel ID above.',
    ],
    'meta_capi_token' => [
        'type'  => 'password',
        'label' => 'Access token',
        'group' => 'integrations',
    ],
    'meta_capi_test_code' => [
        'type'          => 'text',
        'label'         => 'Test event code',
        'max'           => 40,
        'group'         => 'integrations',
        'placeholder'   => 'TEST12345',
        'pattern'       => '/^TEST[A-Za-z0-9]{1,20}$/i',
        'pattern_error' => 'A test event code looks like TEST12345.',
        'help'          => 'Required for the test button. Keeps tests out of reporting.',
    ],

    // --- custom webhook ----------------------------------------------------
    'integration_webhook_enabled' => [
        'type'  => 'bool',
        'label' => 'Post order events to my endpoint',
        'group' => 'integrations',
        'help'  => 'Off means nothing is queued and nothing is sent.',
    ],
    'integration_webhook_url' => [
        'type'        => 'url',
        'label'       => 'Endpoint URL',
        'max'         => 255,
        'group'       => 'integrations',
        'placeholder' => 'https://example.com/hooks/shopinnkart',
    ],
    'integration_webhook_secret' => [
        'type'  => 'password',
        'label' => 'Signing secret',
        'group' => 'integrations',
    ],
    // Rendered as checkboxes below rather than by settings_field(); the spec
    // entry is what makes the key ownable, harvestable and validated on save.
    'integration_webhook_events' => [
        'type'          => 'text',
        'label'         => 'Events',
        'max'           => 200,
        'group'         => 'integrations',
        'pattern'       => '/^[a-z0-9._,]*$/',
        'pattern_error' => 'Choose events from the list.',
    ],
    'integration_queue_retention_days' => [
        'type'      => 'number',
        'label'     => 'Keep sent events for (days)',
        'group'     => 'integrations',
        'min_value' => 1,
        'max_value' => 365,
        'step'      => '1',
        'help'      => 'A sent webhook event holds a copy of the order.',
    ],
];

// Admin > Appearance harvests specs through settings_spec_harvest(). The hook
// sits above every branch that writes, so a harvest can never save.
if (defined('SETTINGS_SPEC_ONLY')) {
    return ['spec' => $spec, 'group' => 'integrations'];
}

// ---------------------------------------------------------------------------
//  Guard: the register decides what this screen may write, so a key that moves
//  to another owner stops being writable here without anyone remembering to
//  delete the field.
// ---------------------------------------------------------------------------
$notOurs = array_diff(array_keys($spec), integration_owned_keys());
if ($notOurs !== []) {
    // Loud rather than silent: a spec that has drifted past the register is the
    // double-write this screen exists to prevent.
    throw new RuntimeException(
        'Settings > Integrations must not write keys owned elsewhere: ' . implode(', ', $notOurs)
    );
}

// Read key by key. See the file docblock: these keys do not share a group.
$stored = [];
foreach (array_keys($spec) as $key) {
    $stored[$key] = (string) setting($key, '');
}

$action = (string) input('action', '');

// ---------------------------------------------------------------------------
//  Queue a test event
// ---------------------------------------------------------------------------
// It queues and returns. The worker sends it, so no page load ever waits on
// Meta or on an endpoint that is still being set up.
if (is_post() && $action === 'queue_test') {
    admin_require_action('settings.edit');

    $channel = (string) input('channel', '');
    if (!in_array($channel, ['meta_capi', 'webhook'], true)) {
        flash('error', 'Unknown channel.');
        redirect(settings_url('integrations'));
    }

    // Five a minute: a test button is not a way to fill a queue.
    if (!rate_limit_attempt('integration_test', 'admin_' . (int) $admin['id'], 5, 60)) {
        flash('error', 'Too many test events. Please wait a minute.');
        redirect(settings_url('integrations'));
    }

    $result = integration_queue_test($channel);

    if (!$result['ok']) {
        flash('error', $result['reason']);
    } elseif ($result['state'] === 'duplicate') {
        flash('info', 'That test event was already queued.');
    } else {
        log_activity('integrations.test_queued', 'settings', null,
            'Queued a test ' . $channel . ' event');
        flash('success', 'Test event queued. The sender delivers it on its next run.');
    }

    redirect(settings_url('integrations'));
}

// ---------------------------------------------------------------------------
//  Run the sender once, from here
// ---------------------------------------------------------------------------
// The same shape as Settings > Email's queue button. Capped low on purpose:
// this is the one place on the screen that makes a real outbound request, and a
// page load must not hang on five minutes of someone else's timeouts.
if (is_post() && $action === 'run_sender') {
    admin_require_action('settings.edit');

    if (!rate_limit_attempt('integration_run', 'admin_' . (int) $admin['id'], 5, 60)) {
        flash('error', 'Too many runs. Please wait a minute.');
        redirect(settings_url('integrations'));
    }

    $harvest = integration_harvest_webhooks(100);
    $result  = integration_process_queue(5);

    $parts = [];
    if ($harvest['queued'] > 0) {
        $parts[] = $harvest['queued'] . ' newly queued';
    }
    if ($harvest['first_run']) {
        $parts[] = 'watermark set, nothing backfilled';
    }
    $parts[] = $result['sent'] . ' sent';
    if ($result['retry'] > 0) {
        $parts[] = $result['retry'] . ' will be retried';
    }
    if ($result['failed'] > 0) {
        $parts[] = $result['failed'] . ' gave up';
    }
    if ($result['skipped'] > 0) {
        $parts[] = $result['skipped'] . ' skipped';
    }

    log_activity('integrations.sender_run', 'settings', null,
        'Ran the integration sender: ' . implode(', ', $parts));
    admin_after_write();

    flash($result['failed'] > 0 ? 'error' : 'success', 'Sender run: ' . implode(', ', $parts) . '.');
    redirect(settings_url('integrations'));
}

// ---------------------------------------------------------------------------
//  Put given-up events back
// ---------------------------------------------------------------------------
if (is_post() && $action === 'retry_failed') {
    admin_require_action('settings.edit');

    $count = integration_queue_retry_failed(7);
    log_activity('integrations.retry_failed', 'settings', null,
        'Requeued ' . $count . ' given-up integration event(s)');

    flash($count > 0 ? 'success' : 'info',
        $count > 0 ? $count . ' event(s) queued again.' : 'Nothing to requeue.');
    redirect(settings_url('integrations'));
}

// ---------------------------------------------------------------------------
//  Save
// ---------------------------------------------------------------------------
if (is_post() && ($action === '' || $action === 'settings')) {
    // POST + CSRF + settings.edit before anything is read from the body. The
    // shared pipeline checks the same three again; the token is per session, not
    // per form, so checking twice is free.
    admin_require_action('settings.edit');

    $secretErrors = [];

    foreach (INTEGRATION_SECRET_KEYS as $key) {
        $raw = trim((string) input($key, ''));

        // Blank means "keep what is stored", which is what lets the form never
        // render a token back into the HTML.
        if ($raw === '') {
            unset($_POST[$key]);
            continue;
        }

        // The shared pipeline skips max/pattern checks for a password field, so
        // an unbounded paste would go straight into a longtext column.
        if (mb_strlen($raw) > 512) {
            $secretErrors[$key] = integration_entry($key)['label'] . ' is too long (512 characters maximum).';
            continue;
        }

        $sealed = secret_encrypt($raw);
        if ($sealed === '') {
            // secret_encrypt() fails closed when APP_KEY is missing rather than
            // writing the token in the clear. Say so instead of saving nothing.
            $secretErrors[$key] = integration_entry($key)['label']
                . ' was not saved: this install has no application key to encrypt it with.';
            continue;
        }

        $_POST[$key] = $sealed;
    }

    // The event list posts as checkboxes; the stored value is a CSV. Rebuilt
    // from the known list rather than from the body, so an invented event name
    // cannot be saved and then quietly ignored by the sender.
    $ticked = (array) ($_POST['webhook_events'] ?? []);
    $chosen = [];
    foreach (array_keys(INTEGRATION_WEBHOOK_EVENTS) as $event) {
        if (in_array($event, $ticked, true)) {
            $chosen[] = $event;
        }
    }
    $_POST['integration_webhook_events'] = implode(',', $chosen);

    if ($secretErrors !== []) {
        // The bounce-back must not carry a token through the session.
        $safeOld = $_POST;
        foreach (INTEGRATION_SECRET_KEYS as $key) {
            unset($safeOld[$key]);
        }

        flash_errors($secretErrors);
        flash_old($safeOld);
        flash('error', 'Nothing was saved. Please fix the highlighted fields.');
        redirect(settings_url('integrations'));
    }

    settings_handle_save('integrations', 'integrations', $spec, $stored);
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$errors  = errors_pull();
$values  = settings_values($spec, $stored);
$all     = integration_values();
$summary = integration_summary();
$status  = integration_sender_status();
$queue   = integration_queue_stats();
$plan    = integration_meta_event_plan();
$chosen  = integration_webhook_selected_events();

/** Where an id owned by another screen is edited. */
$ownerUrl = static function (string $owner): string {
    return $owner === 'integrations' ? '' : settings_url($owner);
};

/**
 * One line saying when a service's code runs, if ever.
 *
 * `queued` is deliberately not a fixed string: the honest answer for one of our
 * own senders depends on whether it is switched on, so the cell asks
 * integration_sender_status() instead of stating a capability as a fact.
 */
const INTEGRATION_LOADS = [
    'gated'  => 'After consent',
    'meta'   => 'Meta tag only',
    'stored' => 'Nothing sends it',
];

$runsLabel = static function (string $key, array $entry) use ($status): string {
    if ((string) $entry['sends'] !== 'queued') {
        return INTEGRATION_LOADS[(string) $entry['sends']] ?? '';
    }

    $channel = strpos($key, 'meta_capi') === 0 ? 'meta' : 'webhook';
    $state   = $status[$channel]['state'];

    if ($state === 'sending') {
        return 'Sent from cron';
    }
    if ($state === 'no-source') {
        return 'Sender ready';
    }

    return $status[$channel]['badge'];
};

$secretStates = [];
foreach (INTEGRATION_SECRET_KEYS as $key) {
    $secretStates[$key] = integration_secret_state($all[$key]);
}

/** The exact snippet that makes the browser pixel agree with the server. */
$dedupSnippet = "fbq('track', 'Purchase', { value: 1234.00, currency: '"
    . integration_currency() . "' },\n        { eventID: 'sik.purchase.' + orderNumber });";

$pageTitle    = 'Integrations';
$pageSubtitle = 'Every third-party tag and key, and who owns each one.';
$breadcrumbs  = settings_breadcrumbs('integrations');
$pageActions  = '<a class="ad-btn" href="' . e(settings_url('analytics')) . '">'
    . icon('shield-check', 'w-4 h-4') . ' Consent &amp; privacy</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('integrations') ?>

<div class="ad-grid ad-grid--3" style="margin-bottom:20px">
    <?= admin_stat_card(
        'Tags on your pages',
        (string) $summary['live'] . ' of ' . (string) $summary['gated'],
        'megaphone',
        $summary['live'] > 0 ? 'primary' : 'gray',
        'load only after consent'
    ) ?>
    <?= admin_stat_card(
        'Misconfigured',
        (string) $summary['broken'],
        'alert',
        $summary['broken'] > 0 ? 'red' : 'green',
        $summary['broken'] > 0 ? 'saved but dropped' : 'nothing dropped'
    ) ?>
    <?= admin_stat_card(
        'Our own senders',
        (string) $summary['senders_on'] . ' of ' . (string) $summary['senders'],
        'send',
        $summary['senders_on'] > 0 ? 'primary' : 'gray',
        'queued, then sent from cron'
    ) ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Every service this store can talk to</div>
            <div class="ad-card__sub">What it costs your customers, and which screen owns it.</div>
        </div>
    </div>
    <div class="ad-tablewrap">
        <table class="ad-table ad-table--stack">
            <thead>
                <tr>
                    <th scope="col">Service</th>
                    <th scope="col">Value</th>
                    <th scope="col">Runs</th>
                    <th scope="col">Owned by</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (integration_table_rows() as $key => $entry): ?>
                    <?php
                    $value = $all[$key];
                    $state = integration_tag_state($key, $value);
                    $url   = $ownerUrl((string) $entry['owner']);
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600"><?= e((string) $entry['label']) ?></div>
                            <div class="ad-muted" style="font-size:var(--ad-text-xs)"><?= e((string) $entry['cost']) ?></div>
                        </td>
                        <td>
                            <?php if (!empty($entry['secret'])): ?>
                                <span class="sik-status sik-status--<?= e_attr($secretStates[$key]['tone']) ?>">
                                    <?= e($secretStates[$key]['label']) ?>
                                </span>
                            <?php elseif ($value === ''): ?>
                                <span class="ad-muted">not set</span>
                            <?php else: ?>
                                <code style="font-size:var(--ad-text-xs)"><?= e($value) ?></code>
                            <?php endif; ?>
                            <?php if ($state['note'] !== ''): ?>
                                <div style="font-size:var(--ad-text-xs);color:var(--ad-danger-ink)">
                                    <?= e($state['note']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($runsLabel((string) $key, $entry)) ?></td>
                        <td>
                            <?php if ($url === ''): ?>
                                <span class="sik-status sik-status--blue">This screen</span>
                            <?php else: ?>
                                <a href="<?= e($url) ?>">
                                    <?= e((string) (INTEGRATION_OWNERS[$entry['owner']] ?? $entry['owner'])) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="ad-card__foot">
        <details style="margin-right:auto">
            <summary style="cursor:pointer;font-size:var(--ad-text-sm)">Why some ids are edited elsewhere</summary>
            <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:70ch">
                Settings &gt; SEO and Settings &gt; Analytics already write those five ids from their own
                forms. A second form for the same key would not just duplicate it - the blank field on
                whichever screen was saved last would overwrite the value set on the other. So they stay
                where they are written and appear here read-only. Consolidating them means removing the
                field from those two screens in the same change.
            </div>
        </details>
    </div>
</div>

<div class="ad-grid ad-grid--sidebar">
    <form class="ad-form" method="post" action="<?= e(settings_url('integrations')) ?>" data-guard-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Site verification</div>
                    <div class="ad-card__sub">Meta tags in your HTML. No script, no consent needed.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_fields([
                    'bing_site_verification',
                    'pinterest_site_verification',
                    'yandex_site_verification',
                ], $spec, $values, $errors) ?>

                <div class="ad-field">
                    <span class="sik-label">Google Search Console</span>
                    <p class="ad-muted" style="font-size:var(--ad-text-sm);margin:0">
                        <?= $all['google_site_verification'] === ''
                            ? 'Not set.'
                            : '<code>' . e($all['google_site_verification']) . '</code>' ?>
                        <a href="<?= e(settings_url('seo')) ?>">Edit on SEO</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Google Ads</div>
                    <div class="ad-card__sub">Loads with Google Analytics, only after consent.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('google_ads_conversion_id', $spec, $values, $errors) ?>
            </div>
            <div class="ad-card__foot">
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">What this does and does not do</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:70ch">
                        Google Ads and Google Analytics are both gtag products, so the store loads one
                        <code>gtag.js</code> and configures each id on it rather than adding a loader of
                        its own. Google's library then fetches its own Ads script and, measured in a
                        browser, goes on to contact <code>doubleclick.net</code> and
                        <code>google.com/pagead</code> to build remarketing lists. Switching this on is a
                        real addition to what leaves your customers' browsers, not just a line of
                        configuration. It does <em>not</em> report individual conversions: a purchase
                        event on the order-confirmation page is a separate change.
                    </div>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Meta Conversions API</div>
                    <div class="ad-card__sub">Hashed Purchase events, queued here and posted from cron.</div>
                </div>
                <span class="sik-status sik-status--<?= e_attr($status['meta']['tone']) ?>">
                    <?= e($status['meta']['badge']) ?>
                </span>
            </div>
            <div class="ad-card__body">
                <?= settings_field('meta_capi_enabled', $spec, $values, $errors) ?>
                <?= settings_fields(['meta_capi_dataset_id', 'meta_capi_test_code'], $spec, $values, $errors) ?>

                <div class="ad-field">
                    <?= settings_field('meta_capi_token', $spec, $values, $errors) ?>
                    <span class="sik-help">
                        Currently
                        <span class="sik-status sik-status--<?= e_attr($secretStates['meta_capi_token']['tone']) ?>">
                            <?= e($secretStates['meta_capi_token']['label']) ?>
                        </span>
                        - encrypted, never shown again.
                    </span>
                </div>

                <?php if ($status['meta']['state'] === 'no-source'): ?>
                    <div class="sik-alert sik-alert--warning" style="margin:0">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            The sender works, but nothing queues a Purchase yet: the page that confirms an
                            order has to call it. Test events work now.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ad-card__foot" style="flex-wrap:wrap;gap:10px">
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">What Meta receives, and what it does not</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:70ch">
                        <p style="margin:0 0 8px">
                            Six customer fields, each trimmed, lowercased, stripped of punctuation and then
                            SHA-256 hashed before it leaves this server: email, phone, first name, last
                            name, city and country. A readable email address is never sent, never stored in
                            the queue and never written to a log.
                        </p>
                        <p style="margin:0 0 8px">
                            Meta is <em>not</em> sent your customer's IP address or browser string. Both are
                            in the <code>orders</code> table and Meta would use both for matching; starting
                            to share them is a decision for you, not a default.
                        </p>
                        <p style="margin:0">
                            A visitor who refused advertising in the consent banner has no event sent. The
                            decision in force when the order was placed is recorded on the queued event and
                            checked again before it is posted. A visitor who changes their mind later is
                            honoured from that moment on - nothing here can find their earlier events,
                            because the store stores no identifier that would let it.
                        </p>
                    </div>
                </details>
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">Why only Purchase, and how double counting is prevented</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:70ch">
                        <p style="margin:0 0 8px">
                            A server event beside a browser pixel is counted twice unless both carry the
                            same <code>event_id</code>. This store's pixel fires
                            <code><?= e(implode(', ', INTEGRATION_PIXEL_EVENTS)) ?></code> and nothing else,
                            and it passes no event ID, so the server never sends
                            <?= e(implode(', ', INTEGRATION_PIXEL_EVENTS)) ?> - it would double every page.
                            Purchase is safe because the pixel does not fire it at all.
                        </p>
                        <?php if ($plan['blocked'] !== []): ?>
                            <p style="margin:0 0 8px">Held back on purpose:</p>
                            <ul style="margin:0 0 8px 18px">
                                <?php foreach ($plan['blocked'] as $event => $why): ?>
                                    <li><code><?= e($event) ?></code> - <?= e($why) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <p style="margin:0 0 8px">
                            The id is derived, not random, so both sides compute the same string without
                            coordinating: <code>sik.purchase.&lt;order number&gt;</code>. To finish the job,
                            the page that confirms an order queues the server event and the pixel emits the
                            same id:
                        </p>
<pre style="margin:0;padding:10px;overflow:auto;font-size:var(--ad-text-xs);background:var(--ad-surface-2);border-radius:8px">require_once INCLUDES_PATH . '/integration-senders.php';
integration_queue_purchase((int) $order['id']);

<?= e($dedupSnippet) ?></pre>
                    </div>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Custom webhook</div>
                    <div class="ad-card__sub">Signed POSTs to your endpoint, queued and retried.</div>
                </div>
                <span class="sik-status sik-status--<?= e_attr($status['webhook']['tone']) ?>">
                    <?= e($status['webhook']['badge']) ?>
                </span>
            </div>
            <div class="ad-card__body">
                <?= settings_field('integration_webhook_enabled', $spec, $values, $errors) ?>
                <?= settings_field('integration_webhook_url', $spec, $values, $errors) ?>

                <div class="ad-field">
                    <?= settings_field('integration_webhook_secret', $spec, $values, $errors) ?>
                    <span class="sik-help">
                        Currently
                        <span class="sik-status sik-status--<?= e_attr($secretStates['integration_webhook_secret']['tone']) ?>">
                            <?= e($secretStates['integration_webhook_secret']['label']) ?>
                        </span>
                        - encrypted, never shown again.
                    </span>
                </div>

                <div class="ad-field">
                    <span class="sik-label">Events to send</span>
                    <?php if (!empty($errors['integration_webhook_events'])): ?>
                        <span class="sik-error"><?= e((string) $errors['integration_webhook_events']) ?></span>
                    <?php endif; ?>
                    <div style="display:grid;gap:8px;margin-top:4px">
                        <?php foreach (INTEGRATION_WEBHOOK_EVENTS as $event => $what): ?>
                            <label class="ad-switch">
                                <input type="checkbox" name="webhook_events[]"
                                       value="<?= e_attr($event) ?>"
                                       <?= in_array($event, $chosen, true) ? 'checked' : '' ?>>
                                <span class="ad-switch__track"></span>
                                <span><code><?= e($event) ?></code> &mdash; <?= e($what) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="sik-help">Nothing outside this list is ever posted.</span>
                </div>

                <?= settings_field('integration_queue_retention_days', $spec, $values, $errors) ?>
            </div>
            <div class="ad-card__foot" style="flex-wrap:wrap;gap:10px">
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">How to verify a request came from us</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:76ch">
                        <p style="margin:0 0 8px">
                            Every POST carries four headers. The signature is
                            <strong>HMAC-SHA256</strong>, keyed with the signing secret above, over the
                            exact bytes <code>"&lt;timestamp&gt;.&lt;raw request body&gt;"</code> - the
                            timestamp is inside the signed material, so a captured request cannot be
                            replayed later with a fresh one.
                        </p>
<pre style="margin:0 0 8px;padding:10px;overflow:auto;font-size:var(--ad-text-xs);background:var(--ad-surface-2);border-radius:8px"><?= e(INTEGRATION_HEADER_EVENT) ?>:      order.paid
<?= e(INTEGRATION_HEADER_DELIVERY) ?>:   sik.order.paid.SCF-1AF05AE9
<?= e(INTEGRATION_HEADER_TIMESTAMP) ?>:  1759050000
<?= e(INTEGRATION_HEADER_SIGNATURE) ?>:  sha256=9f86d081...</pre>
<pre style="margin:0 0 8px;padding:10px;overflow:auto;font-size:var(--ad-text-xs);background:var(--ad-surface-2);border-radius:8px">$body = file_get_contents('php://input');
$ts   = $_SERVER['HTTP_<?= e(strtoupper(str_replace('-', '_', INTEGRATION_HEADER_TIMESTAMP))) ?>'] ?? '';
$sig  = $_SERVER['HTTP_<?= e(strtoupper(str_replace('-', '_', INTEGRATION_HEADER_SIGNATURE))) ?>'] ?? '';

if (abs(time() - (int) $ts) > 300) { http_response_code(408); exit; }   // too old
$mine = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
if (!hash_equals($mine, $sig)) { http_response_code(401); exit; }       // not us</pre>
                        <p style="margin:0">
                            Reply <code>2xx</code> to accept. Anything else is retried: after one minute,
                            five, twenty-five and two hours, then given up on - about two and a half hours
                            in all. A <code>4xx</code> other than <code>429</code> is taken as "this request
                            is wrong" and is not retried. Use the
                            <code><?= e(INTEGRATION_HEADER_DELIVERY) ?></code> header as an idempotency key:
                            it is the same string on every retry of the same event.
                        </p>
                    </div>
                </details>
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">What the endpoint receives, and consent</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:76ch">
                        <p style="margin:0 0 8px">
                            The order's totals and items, plus the customer's name, email, phone and
                            shipping address - readable, not hashed, because the endpoint is yours and that
                            is what a webhook is for. The queued copy of that body is deleted with the
                            retention setting above, on the worker's <code>--prune</code> pass.
                        </p>
                        <p style="margin:0">
                            These events are <em>not</em> gated on the visitor's advertising consent: your
                            own endpoint processing your own orders is not a third-party tag on a
                            customer's page. If you forward them to an ad network you have moved that line
                            yourself, and the consent banner will not have covered it.
                        </p>
                    </div>
                </details>
            </div>
            <?= settings_save_bar('Verification tags apply immediately. Senders start on the next worker run.') ?>
        </div>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Event queue</div>
                    <div class="ad-card__sub">Nothing is sent inside a checkout. Cron sends it.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?php if (!$queue['installed']): ?>
                    <div class="sik-alert sik-alert--error" style="margin:0">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div>
                            The queue tables are missing. Run
                            <code>database/migrations/2026_09_28_integration_queue.php</code>.
                        </div>
                    </div>
                <?php else: ?>
                    <?= admin_kv([
                        ['Waiting to send', e((string) $queue['total']['pending'])],
                        ['Delivered', e((string) $queue['total']['sent'])],
                        ['Gave up', $queue['total']['failed'] > 0
                            ? '<span class="sik-status sik-status--red">' . e((string) $queue['total']['failed']) . '</span>'
                            : e('0')],
                        ['Not sent (consent or off)', e((string) $queue['total']['skipped'])],
                        ['Last delivery', e($queue['last_sent'] ?? 'never')],
                        ['Meta CAPI', e($queue['meta']['sent'] . ' sent, ' . $queue['meta']['pending'] . ' waiting')],
                        ['Webhook', e($queue['webhook']['sent'] . ' sent, ' . $queue['webhook']['pending'] . ' waiting')],
                    ]) ?>
                <?php endif; ?>
            </div>
            <div class="ad-card__foot" style="flex-wrap:wrap;gap:8px">
                <form method="post" action="<?= e(settings_url('integrations')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="run_sender">
                    <button class="ad-btn ad-btn--primary ad-btn--sm" type="submit">
                        <?= icon('send', 'w-4 h-4') ?> Run the sender now
                    </button>
                </form>
                <?php if ($queue['installed'] && $queue['total']['failed'] > 0): ?>
                    <form method="post" action="<?= e(settings_url('integrations')) ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retry_failed">
                        <button class="ad-btn ad-btn--sm" type="submit">
                            <?= icon('rotate', 'w-4 h-4') ?> Try the given-up ones again
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Send a test event</div>
                    <div class="ad-card__sub">Queues a marked test with no real customer in it.</div>
                </div>
            </div>
            <div class="ad-card__body" style="display:grid;gap:10px">
                <form method="post" action="<?= e(settings_url('integrations')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="queue_test">
                    <input type="hidden" name="channel" value="meta_capi">
                    <button class="ad-btn ad-btn--block ad-btn--sm" type="submit">
                        <?= icon('zap', 'w-4 h-4') ?> Test Meta CAPI
                    </button>
                </form>
                <form method="post" action="<?= e(settings_url('integrations')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="queue_test">
                    <input type="hidden" name="channel" value="webhook">
                    <button class="ad-btn ad-btn--block ad-btn--sm" type="submit">
                        <?= icon('zap', 'w-4 h-4') ?> Test webhook
                    </button>
                </form>
                <p class="ad-muted" style="font-size:var(--ad-text-xs);margin:0">
                    A sender has to be switched on and saved first. A CAPI test also needs a test event
                    code, or it would land in your real ad reporting.
                </p>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">The consent gate</div>
                    <div class="ad-card__sub">No tag here loads before a visitor allows advertising.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= admin_kv([
                    ['Tags configured', e($summary['live'] + $summary['broken'] . ' of ' . $summary['gated'])],
                    ['Banner shown', e(consent_has_tags() ? 'Yes, advertising category' : 'No tags, so no banner')],
                    ['DNT / GPC', e(setting_bool('analytics_honor_dnt', true) ? 'Honoured' : 'Ignored')],
                    ['Meta CAPI honours it', e('Yes, recorded per event')],
                    ['Webhook honours it', e('No - your own endpoint')],
                ]) ?>
            </div>
            <div class="ad-card__foot">
                <a class="ad-btn" href="<?= e(settings_url('analytics')) ?>">
                    <?= icon('sliders', 'w-4 h-4') ?> Consent settings
                </a>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__body">
                <div class="sik-alert sik-alert--info" style="margin:0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>
                        An id saved in the wrong format is dropped by the storefront. Every field here is
                        checked against the same pattern the gate uses, so it cannot happen silently.
                    </div>
                </div>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">The worker</div>
                    <div class="ad-card__sub">Schedule it, or nothing above ever leaves the queue.</div>
                </div>
            </div>
            <div class="ad-card__body">
<pre style="margin:0;padding:10px;overflow:auto;font-size:var(--ad-text-xs);background:var(--ad-surface-2);border-radius:8px">php bin/send-integration-events.php --prune</pre>
                <p class="ad-muted" style="font-size:var(--ad-text-sm);margin:8px 0 0">
                    Every five minutes. It harvests new order events, sends what is due, and gives up
                    loudly - a non-zero exit, so cron mails you.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
