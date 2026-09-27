<?php
/**
 * ShopInnKart - The integrations register.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Eight third-party services can be wired into this store, and until now the
 * answer to "which screen owns this id" was different for each one: Google
 * Analytics and the Meta Pixel on Settings > SEO, Tag Manager and Clarity on
 * Settings > Analytics, and Meta CAPI, Google Ads and a custom webhook nowhere
 * at all. Settings > Integrations is the one screen that shows all eight, and
 * this file is the register it reads, so "who owns this key" is written down
 * once instead of being implied by whichever form happens to hold a field.
 *
 * THE ONE RULE THIS FILE ENFORCES
 * ------------------------------
 * A key has exactly one owner, and only that owner's screen writes it. Two
 * screens writing one key is how a value ends up different depending on which
 * page was saved last, and it cannot be fixed by being careful: the second
 * screen's blank field posts an empty string and wipes the first screen's
 * value. So `owner` below is not a comment, it is the flag the Integrations
 * screen reads to decide whether to render an input or a read-only row.
 *
 * WHY FIVE OF THEM ARE STILL OWNED ELSEWHERE
 * -----------------------------------------
 * Settings > SEO and Settings > Analytics declare those five keys in their own
 * field spec and write them on every save. Moving the value here without also
 * deleting the field there would create exactly the double-write above. So
 * ownership stays with the writer, and Integrations shows those five read-only
 * with a link to the screen that owns them. Consolidating them properly means
 * editing those two screens, which is a separate change.
 *
 * WHAT IS CONFIGURED HERE AND NOT SENT
 * -----------------------------------
 * The Meta Conversions API and the custom webhook are storage only. No
 * server-to-server sender exists for either, and `sends` records that, so the
 * screen can say "stored, nothing is sent" rather than leave an operator
 * believing their conversions are flowing.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED') && !defined('SIK_LEAN_BOOTSTRAP')) {
    http_response_code(404);
    exit;
}

// The gated tag ids and their patterns come from the gate itself, so the admin
// can never accept a value the storefront would silently drop.
require_once __DIR__ . '/consent.php';

// secret_encrypt() / secret_status() live with the mailer, which needed them
// first. init.php already loads it; the guard is for a leaner bootstrap.
if (!function_exists('secret_status')) {
    require_once __DIR__ . '/mailer.php';
}

/** Screens that own at least one integration key, and how to name them. */
const INTEGRATION_OWNERS = [
    'integrations' => 'Integrations',
    'seo'          => 'SEO',
    'analytics'    => 'Analytics',
];

/**
 * The register. One entry per configurable value, in the order the screen shows
 * them.
 *
 *   owner   which settings screen WRITES this key (see INTEGRATION_OWNERS)
 *   slot    the consent.php tag slot this id feeds, or '' if it loads no script
 *   secret  true when the value must never be rendered back into the form
 *   sends   'gated'  - a third-party script, loaded only after marketing consent
 *           'meta'   - a meta tag in our own HTML; no request to anybody
 *           'stored' - saved, and nothing sends it yet
 *   cost    the consent and privacy consequence, one line, operator-facing
 */
const INTEGRATION_REGISTER = [
    'google_site_verification' => [
        'label' => 'Google Search Console',
        'owner' => 'seo',
        'slot'  => '',
        'sends' => 'meta',
        'cost'  => 'A meta tag proving you own the domain. Loads no code.',
    ],
    'bing_site_verification' => [
        'label' => 'Bing Webmaster Tools',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'meta',
        'cost'  => 'A meta tag only. Nothing is fetched from Microsoft.',
    ],
    'pinterest_site_verification' => [
        'label' => 'Pinterest',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'meta',
        'cost'  => 'A meta tag only. Nothing is fetched from Pinterest.',
    ],
    'yandex_site_verification' => [
        'label' => 'Yandex',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'meta',
        'cost'  => 'A meta tag only. Nothing is fetched from Yandex.',
    ],
    'google_analytics_id' => [
        'label' => 'Google Analytics 4',
        'owner' => 'seo',
        'slot'  => 'ga',
        'sends' => 'gated',
        'cost'  => 'Sends every page view to Google with an advertising cookie.',
    ],
    'google_tag_manager_id' => [
        'label' => 'Google Tag Manager',
        'owner' => 'analytics',
        'slot'  => 'gtm',
        'sends' => 'gated',
        'cost'  => 'A container that can run any tag added inside it.',
    ],
    'google_ads_conversion_id' => [
        'label' => 'Google Ads',
        'owner' => 'integrations',
        'slot'  => 'gads',
        'sends' => 'gated',
        'cost'  => 'Builds Google remarketing lists via doubleclick.net on every page.',
    ],
    'meta_pixel_id' => [
        'label' => 'Meta Pixel',
        'owner' => 'seo',
        'slot'  => 'pixel',
        'sends' => 'gated',
        'cost'  => 'Tells Meta who browsed what, linked to their account.',
    ],
    'meta_capi_dataset_id' => [
        'label' => 'Meta CAPI dataset',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'stored',
        'cost'  => 'Would send orders to Meta from our server, bypassing blockers.',
    ],
    'meta_capi_token' => [
        'label'  => 'Meta CAPI access token',
        'owner'  => 'integrations',
        'slot'   => '',
        'secret' => true,
        'sends'  => 'stored',
        'cost'   => 'A credential that can write to your Meta ad account.',
    ],
    'meta_capi_test_code' => [
        'label' => 'Meta CAPI test event code',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'stored',
        'cost'  => 'Routes test events away from your real ad reporting.',
    ],
    'clarity_project_id' => [
        'label' => 'Microsoft Clarity',
        'owner' => 'analytics',
        'slot'  => 'clarity',
        'sends' => 'gated',
        'cost'  => 'Records mouse movement and can replay a session.',
    ],
    'integration_webhook_url' => [
        'label' => 'Custom webhook',
        'owner' => 'integrations',
        'slot'  => '',
        'sends' => 'stored',
        'cost'  => 'Would post store events to a URL you control.',
    ],
    'integration_webhook_secret' => [
        'label'  => 'Webhook signing secret',
        'owner'  => 'integrations',
        'slot'   => '',
        'secret' => true,
        'sends'  => 'stored',
        'cost'   => 'Lets your endpoint prove a request really came from us.',
    ],
];

/** Every register entry, each carrying its own key and the defaults filled in. */
function integration_register(): array
{
    $rows = [];
    foreach (INTEGRATION_REGISTER as $key => $entry) {
        $rows[$key] = $entry + ['key' => $key, 'secret' => false, 'slot' => '', 'sends' => 'stored'];
    }
    return $rows;
}

/** One entry, or [] for a key this register does not know. */
function integration_entry(string $key): array
{
    return integration_register()[$key] ?? [];
}

/** Which settings screen writes this key. '' when the key is unknown. */
function integration_owner(string $key): string
{
    return (string) (integration_entry($key)['owner'] ?? '');
}

/** The keys Settings > Integrations is allowed to write. */
function integration_owned_keys(): array
{
    $keys = [];
    foreach (integration_register() as $key => $entry) {
        if ($entry['owner'] === 'integrations') {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Current values for the whole register, read key by key.
 *
 * Not settings_group(): setting_save() fixes a row's group when it inserts it
 * and never moves it, so these keys are spread across three groups and one
 * sweep would silently miss some. google_tag_manager_id is the live example,
 * because its row is still in the `seo` group it was created in.
 */
function integration_values(): array
{
    $values = [];
    foreach (array_keys(integration_register()) as $key) {
        $values[$key] = trim((string) setting($key, ''));
    }
    return $values;
}

/**
 * Whether a stored secret is readable, without revealing it.
 *
 * secret_status() separates "nothing stored" from "stored under a key this
 * install no longer has", which is the difference between an operator needing
 * to paste a token and needing to fix APP_KEY.
 *
 * @return array{state:string, label:string, tone:string}
 */
function integration_secret_state(string $stored): array
{
    $status = $stored === '' ? 'empty' : secret_status($stored);

    $map = [
        'empty'     => ['not set', 'gray'],
        'ok'        => ['set', 'green'],
        'plaintext' => ['set, not encrypted', 'amber'],
        'no-key'    => ['unreadable: no APP_KEY', 'red'],
        'wrong-key' => ['unreadable: APP_KEY changed', 'red'],
        'corrupt'   => ['unreadable: value damaged', 'red'],
    ];
    [$label, $tone] = $map[$status] ?? ['unknown', 'amber'];

    return ['state' => $status, 'label' => $label, 'tone' => $tone];
}

/**
 * What the storefront will actually do with a configured tag id.
 *
 * `valid` is the gate's own verdict: consent.php drops an id that does not match
 * its pattern, and before this screen existed it dropped it without saying so.
 * `note` is the one thing an operator can act on.
 *
 * @return array{configured:bool, valid:bool, note:string}
 */
function integration_tag_state(string $key, string $value): array
{
    $slot     = (string) (integration_entry($key)['slot'] ?? '');
    $value    = trim($value);
    $patterns = consent_tag_patterns();

    if ($slot === '' || !isset($patterns[$slot])) {
        return ['configured' => $value !== '', 'valid' => true, 'note' => ''];
    }

    if ($value === '') {
        return ['configured' => false, 'valid' => true, 'note' => ''];
    }

    if (preg_match($patterns[$slot], $value) !== 1) {
        return [
            'configured' => true,
            'valid'      => false,
            'note'       => 'Saved, but the storefront drops it: wrong format.',
        ];
    }

    // Meta issues 15-16 digits. The gate accepts 10-20 so that tightening it
    // cannot stop a pixel that is firing today; saying so here is the half an
    // operator can act on.
    if ($slot === 'pixel' && !in_array(strlen($value), [15, 16], true)) {
        return [
            'configured' => true,
            'valid'      => true,
            'note'       => 'Unusual length. A Meta Pixel ID is 15-16 digits.',
        ];
    }

    return ['configured' => true, 'valid' => true, 'note' => ''];
}

/**
 * How many gated tags are live, how many are misconfigured, and what else is
 * merely stored.
 *
 * @return array{gated:int, live:int, broken:int, stored:int, metas:int}
 */
function integration_summary(): array
{
    $values = integration_values();
    $counts = ['gated' => 0, 'live' => 0, 'broken' => 0, 'stored' => 0, 'metas' => 0];

    foreach (integration_register() as $key => $entry) {
        $state = integration_tag_state($key, $values[$key]);

        if ($entry['sends'] === 'gated') {
            $counts['gated']++;
            if ($state['configured'] && $state['valid']) {
                $counts['live']++;
            } elseif ($state['configured']) {
                $counts['broken']++;
            }
            continue;
        }

        if ($values[$key] === '') {
            continue;
        }

        if ($entry['sends'] === 'meta') {
            $counts['metas']++;
        } else {
            $counts['stored']++;
        }
    }

    return $counts;
}
