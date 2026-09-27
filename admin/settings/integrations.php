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
 * WHAT IS STORED AND NOT SENT
 * --------------------------
 * The Meta Conversions API and the custom webhook have no sender. Nothing on
 * this screen pretends otherwise: both carry a "nothing sends it" badge, and the
 * register records it so the summary tiles count them separately from the tags
 * that really are on the page.
 *
 * PERMISSIONS
 * ----------
 * settings.view to read, settings.edit to change. No field here takes
 * operator-authored code - the closest thing, a Google Ads conversion id, is a
 * fixed-format token pasted into Google's own loader - so settings.scripts is
 * not required. Admin > SEO > Scripts keeps that gate for the screen that does
 * accept raw markup.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once INCLUDES_PATH . '/integrations.php';

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

    // --- Meta Conversions API. Stored; no sender exists. -------------------
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
    ],

    // --- custom webhook. Also stored; no sender exists. --------------------
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

// ---------------------------------------------------------------------------
//  Save
// ---------------------------------------------------------------------------
if (is_post()) {
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

/** Where an id owned by another screen is edited. */
$ownerUrl = static function (string $owner): string {
    return $owner === 'integrations' ? '' : settings_url($owner);
};

/** One line saying when a service's code runs, if ever. */
const INTEGRATION_LOADS = [
    'gated'  => 'After consent',
    'meta'   => 'Meta tag only',
    'stored' => 'Nothing sends it',
];

$secretStates = [];
foreach (INTEGRATION_SECRET_KEYS as $key) {
    $secretStates[$key] = integration_secret_state($all[$key]);
}

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
        'Stored, not sending',
        (string) $summary['stored'],
        'lock',
        $summary['stored'] > 0 ? 'amber' : 'gray',
        'no sender is built'
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
                <?php foreach (integration_register() as $key => $entry): ?>
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
                        <td><?= e(INTEGRATION_LOADS[(string) $entry['sends']] ?? '') ?></td>
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
    <form class="ad-form" method="post" data-guard-unsaved>
        <?= csrf_field() ?>

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
                    <div class="ad-card__sub">Saved here. Nothing is sent to Meta yet.</div>
                </div>
                <span class="sik-status sik-status--amber">Not sending</span>
            </div>
            <div class="ad-card__body">
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
            </div>
            <div class="ad-card__foot">
                <details style="margin-right:auto">
                    <summary style="cursor:pointer;font-size:var(--ad-text-sm)">Why it is not sending</summary>
                    <div class="ad-muted" style="font-size:var(--ad-text-sm);margin-top:8px;max-width:70ch">
                        The Conversions API needs a server-to-server sender: order events, hashed customer
                        details, retries and a deduplication id shared with the browser pixel. None of that
                        is built, so these fields only store what a sender would need. They are here rather
                        than nowhere because a token belongs in one place, encrypted - but nothing about a
                        saved token means events are flowing, and this card will keep saying so until a
                        sender exists.
                    </div>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Custom webhook</div>
                    <div class="ad-card__sub">Saved here. Nothing posts to it yet.</div>
                </div>
                <span class="sik-status sik-status--amber">Not sending</span>
            </div>
            <div class="ad-card__body">
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
            </div>
            <?= settings_save_bar('Verification tags apply immediately. Tag ids wait for consent.') ?>
        </div>
    </form>

    <div style="display:grid;gap:18px;align-content:start">
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
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
