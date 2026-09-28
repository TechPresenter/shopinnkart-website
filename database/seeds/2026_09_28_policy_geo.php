<?php
/**
 * ShopInnKart - Policy copy for location, server-side sending and data rights.
 *
 * The six edits in analytics-policy-copy.php fixed what the policy said about
 * cookies and consent. Three things it still does not mention at all:
 *
 *   LOCATION. includes/analytics/geo.php resolves a country (and, for India, a
 *   state, and a city where the source gives one) while the session row is
 *   being written. The address it resolves from is a local variable: it is not
 *   returned, not logged and not stored. Neither policy said that location is
 *   derived at all, and the Privacy Policy's "Device and usage data" row said
 *   the purpose was "security, fraud prevention and keeping the cart working",
 *   which is not why the country is kept.
 *
 *   SERVER-SIDE SENDING. Meta's Conversions API and the custom webhook are
 *   configured in Settings > Integrations. A send from the server is not a
 *   cookie and is not stopped by blocking cookies, so a reader who follows the
 *   Cookie Policy's advice would be wrong about it. THIS SEED WILL NOT WRITE
 *   THAT PARAGRAPH UNTIL THE SENDER EXISTS: policy_sender_probe() looks for it
 *   in the code, and an absent sender holds the edit back with a reason
 *   printed. A policy must not describe a feature in the future tense.
 *
 *   DATA RIGHTS. privacy.php and includes/privacy.php have been shipped:
 *   Data & Privacy in the customer's account exports everything the store
 *   holds and deletes the account, both confirmed by an emailed link. The
 *   Privacy Policy still said "write to us" as though nothing were built.
 *
 * Runs AFTER analytics-policy-copy.php - the location sentence is anchored to a
 * sentence that seed writes - and it says so when the anchor is missing rather
 * than guessing where the sentence went.
 *
 *     php database/seeds/2026_09_28_policy_geo.php --dry    report only
 *     php database/seeds/2026_09_28_policy_geo.php          apply
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

// policy_copy_apply(), policy_copy_report() and the shared conditions. The
// sibling's CLI block is guarded by realpath($argv[0]), so requiring it here
// runs nothing.
require_once __DIR__ . '/analytics-policy-copy.php';

/**
 * Is there really a server-side sender in this build?
 *
 * The Privacy Policy is about to tell customers that an order can leave this
 * server for an advertising platform with their details hashed. That sentence is
 * true only if code exists that does it AND something calls that code. Both
 * halves are checked, because a sender nothing calls sends nothing:
 *
 *   meta   a file holding the Graph API host, the access-token setting and a
 *          SHA-256 call - the hashing is part of the claim, so a sender that
 *          posted details in the clear must not inherit this paragraph - AND a
 *          caller of integration_queue_purchase() outside that file.
 *   hook   a file that reads the webhook URL setting and posts or signs with it,
 *          and is neither the screen that saves it nor the register that
 *          documents it - AND a caller of integration_harvest_webhooks().
 *
 * A search for evidence, rather than a function_exists() check on a name nobody
 * had agreed when this was written. A false "not found" holds a paragraph back
 * and prints why; a false "found" would publish a promise, so the two failures
 * are not weighted the same.
 *
 * @return array{live:bool, why:string}
 */
function policy_sender_probe(string $which): array
{
    static $scan = null;

    if ($scan === null) {
        $scan = ['meta' => [], 'hook' => [], 'meta_call' => [], 'hook_call' => []];

        // database/ is skipped so this seed's own words cannot satisfy it, and
        // tests/ so that a suite exercising a sender is not mistaken for the
        // store using it. (The regression suites live outside ROOT_PATH anyway.)
        $skip = ['vendor', 'node_modules', '.git', 'storage', 'uploads', 'database', 'logs', 'backups', 'cache', 'tests'];

        $walk = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(ROOT_PATH, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($skip): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), $skip, true);
                    }
                    return strtolower($file->getExtension()) === 'php';
                }
            )
        );

        foreach ($walk as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getPathname();
            $code = (string) @file_get_contents($path);
            if ($code === '') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen(ROOT_PATH) + 1));

            // The screen that stores a value and the register that documents
            // it both name these keys; neither sends anything.
            $isStorage = $relative === 'includes/integrations.php'
                || $relative === 'admin/settings/integrations.php';

            if (strpos($code, 'graph.facebook.com') !== false
                && strpos($code, 'meta_capi_token') !== false
                && (stripos($code, 'sha256') !== false || stripos($code, "'sha256'") !== false)) {
                $scan['meta'][] = $relative;
            }

            // Not the register, not the screen, and doing something with the
            // URL rather than only naming it: posting, or signing with the
            // paired secret. includes/webhook-functions.php is the INBOUND
            // payment-callback verifier and names neither key, so it cannot
            // satisfy this by accident.
            if (!$isStorage
                && strpos($code, 'integration_webhook_url') !== false
                && (stripos($code, 'curl_') !== false || stripos($code, 'http_post') !== false
                    || stripos($code, 'stream_context_create') !== false
                    || strpos($code, 'integration_webhook_secret') !== false)) {
                $scan['hook'][] = $relative;
            }

            // Who actually feeds the queue. A sender with no caller is a
            // capability, not a behaviour, and a policy describes behaviour.
            //
            // $isStorage is excluded HERE as well, and that exclusion is the
            // whole point: admin/settings/integrations.php prints
            // `integration_queue_purchase((int) $order['id']);` inside a <pre>
            // as the snippet an integrator is told to paste into the order
            // confirmation page. Counting that as wiring published a paragraph
            // about a send that has never happened. A snippet in some other
            // screen could still fool this, which is why the report always
            // prints WHERE it found the call - that line is what caught it.
            if (!$isStorage
                && !in_array($relative, $scan['meta'], true)
                && !in_array($relative, $scan['hook'], true)) {
                if (strpos($code, 'integration_queue_purchase(') !== false) {
                    $scan['meta_call'][] = $relative;
                }
                if (strpos($code, 'integration_harvest_webhooks(') !== false) {
                    $scan['hook_call'][] = $relative;
                }
            }
        }
    }

    $sender = $scan[$which] ?? [];
    $caller = $scan[$which . '_call'] ?? [];

    if ($sender !== [] && $caller !== []) {
        return [
            'live' => true,
            'why'  => 'sender in ' . $sender[0] . ', queued from ' . implode(', ', array_slice($caller, 0, 2)),
        ];
    }

    if ($sender === []) {
        return [
            'live' => false,
            'why'  => $which === 'meta'
                ? 'no Meta Conversions API sender in the code yet (looked for the Graph host, the token setting and a SHA-256 call)'
                : 'nothing sends to integration_webhook_url yet (looked for the setting being read and used outside the settings screen)',
        ];
    }

    return [
        'live' => false,
        'why'  => $which === 'meta'
            ? 'the Meta sender exists (' . $sender[0] . ') but nothing calls integration_queue_purchase(), so no real order is ever queued - only the test button sends, with a made-up order'
            : 'the webhook sender exists (' . $sender[0] . ') but nothing calls integration_harvest_webhooks(), so no order event is ever queued',
    ];
}

/** Conditions these edits add to the shared ones. */
function policy_geo_conditions(): array
{
    return [
        // privacy_enabled(). With self-service off the right still exists, but
        // it is handled by a person, and the paragraph would be wrong.
        'privacy_self_service' => [
            'label'  => 'the customer can export and delete from their own account',
            'test'   => static fn (): bool => setting_bool('sec_privacy_self_service', true),
            'actual' => static fn (): string => 'sec_privacy_self_service is off, so Data & Privacy is not self service',
        ],
        'meta_capi_sender' => [
            'label'    => 'a Meta Conversions API sender exists and something queues real events',
            'test'     => static fn (): bool => policy_sender_probe('meta')['live'],
            'actual'   => static fn (): string => policy_sender_probe('meta')['why'],
            'evidence' => static fn (): string => policy_sender_probe('meta')['why'],
        ],
        'webhook_sender' => [
            'label'    => 'a custom webhook sender exists and something queues real events',
            'test'     => static fn (): bool => policy_sender_probe('hook')['live'],
            'actual'   => static fn (): string => policy_sender_probe('hook')['why'],
            'evidence' => static fn (): string => policy_sender_probe('hook')['why'],
        ],
    ];
}

/** @return list<array> */
function policy_geo_edits(): array
{
    // ---- location, in the Cookie Policy's own-analytics paragraph ---------
    $cookieGeoAnchor = 'That counting writes no cookie, puts no identifier on your device and never stores your IP address.';

    $cookieGeoNew = $cookieGeoAnchor
        . ' Where the shop can tell roughly where you are, it looks that up while the page view is being written'
        . ' and keeps only the result: the country, and where the shop has asked for city precision also the city'
        . ' and, in India, the state. The address it was looked up from is not written down.';

    // ---- privacy §3: the table row, split in two --------------------------
    $deviceRow = '<tr><td>Device and usage data</td><td>IP address, browser and device type, pages viewed, cookie identifiers</td><td>Security, fraud prevention, and keeping the cart and session working</td><td>Short lived, as set out in the Cookie Policy</td></tr>';

    $visitRow = $deviceRow
        . "\n"
        . '<tr><td>Visit statistics</td><td>Pages viewed and their order, time spent on a page, device, browser and operating system, the site, search or campaign that sent you, and an approximate location worked out from your network address</td><td>To see what the shop is used for, which pages fail and which campaigns are worth running</td><td>Visit level rows are deleted automatically once they pass the retention window set in the store settings. Only daily totals, which name nobody, are kept after that</td></tr>';

    // ---- privacy §3: what makes those statistics different ----------------
    $neverAnchor = '<h2>4. What We Never Collect</h2>';

    $visitNote = '<p>The last row is deliberately unlike the others. Visit statistics carry no IP address, no name'
        . ' and no identifier that lives on your device: visits inside a single day are grouped by a key derived'
        . ' from a shortened form of your network address and a secret that is replaced every day and then destroyed,'
        . ' which is why the totals are approximate and why we need no consent banner for them. Location is looked up'
        . ' while the visit is being written and only the result is kept - the country, and where the store has asked'
        . ' for city precision also the city and, in India, the state - never the address itself; where the store has'
        . ' no location source configured, the reports simply say Unknown. Where you are signed in, the visits in that'
        . ' window are linked to your account, which is why they appear in the data export and are detached by the'
        . ' deletion described in section 9.</p>'
        . "\n\n"
        . $neverAnchor;

    // ---- privacy §6: a send that is not a cookie -------------------------
    $techBullet = '<li><strong>Technology and communication providers</strong> that send transactional email and SMS on our behalf, under contract and only for that purpose.</li>';

    // Every field named here was read out of integration_meta_user_data() and
    // integration_meta_payload(): hashed em, ph, fn, ln, ct, country, and an
    // unhashed custom_data of order number, value and line items. No IP and no
    // browser string are sent, deliberately, and the policy may say so.
    $serverBullet = $techBullet
        . "\n"
        . '<li><strong>Meta, from our server</strong> receives a purchase event where the administrator has connected'
        . " Meta's Conversions API and you have allowed advertising. It goes from here rather than from your browser,"
        . ' and what identifies you in it is a one way hash of your email address, phone number, first and last name,'
        . ' city and country - not those values themselves, and not your IP address or your browser. The order number,'
        . ' the amount and the product codes and quantities go unhashed, because measuring the sale is the point of it.'
        . ' The answer honoured is the one in force when the event was recorded; refusing advertising stops every event'
        . ' after that, and where the connection is not configured nothing is sent at all.</li>';

    // ---- privacy §6: the owner's own webhook ------------------------------
    // The fields are the ones integration_webhook_payload() really puts in the
    // body, read from the code: customer name, email, phone and the full
    // shipping address, unhashed. It is NOT consent-gated
    // (integration_channel_needs_consent), and saying so is the honest half.
    $webhookBullet = '<li><strong>A system the store owner has connected</strong> receives order events where the'
        . ' administrator has switched a webhook on and chosen which events it gets - their own back office, an'
        . ' accounting tool, a courier system.'
        . ' That message carries the order and, readable, the name, email address and phone number on it and the'
        . ' full delivery address, because carrying exactly that is what such a connection is for. It is signed so'
        . ' the receiver can prove it came from us. This one is not part of the advertising choice: it is the'
        . " store's own order data going to a system the store runs, not a tag in your browser. The destination and"
        . " what happens to the data there are the administrator's responsibility, and where no webhook is"
        . ' configured nothing is sent.</li>';

    $governmentBullet = '<li><strong>Government authorities</strong> where a law, a court order or a lawful investigation requires disclosure.</li>';

    // ---- cookie §3: blocking cookies does not stop a server-side send ----
    $firstThirdAnchor = '<p>First party cookies are set by this website and are the ones that keep your session, cart and preferences working. Third party cookies are set by another domain whose script the site loads, such as an analytics provider or a payment gateway page. We do not control what a third party stores, so read their own policy; the payment gateway in particular runs its own session on its own domain while you are paying.</p>';

    $firstThirdNew = $firstThirdAnchor
        . "\n"
        . '<p>Not everything that reaches another company travels in a cookie. Where the administrator has connected'
        . " Meta's Conversions API, a purchase can be reported to Meta from this server rather than from your browser,"
        . ' identified by a one way hash of your email address, phone number, name, city and country instead of by'
        . ' those values themselves. That happens only where you have allowed advertising - which means blocking'
        . ' cookies does not stop it, and refusing advertising does. Section 6 of the'
        . ' <a href="{{page:privacy-policy}}">Privacy Policy</a> lists who receives data from this store and what'
        . ' each one gets.</p>';

    // ---- cookie §5: the site's own control, before the browser's ---------
    $browserItem = '<li><strong>Browser settings.</strong> Every modern browser can block all cookies, block only third party cookies, or clear what is already stored. The controls sit under Privacy or Site settings.</li>';

    $siteItem = '<li><strong>On this site.</strong> <strong>Cookie preferences</strong> in the footer reopens the'
        . ' choice you were offered on your first visit, and turning advertising off there deletes the identifiers'
        . ' those scripts left on our own domain. It appears whenever there is a choice to make.</li>'
        . "\n"
        . $browserItem;

    // ---- cookie FAQ: advertising needs a yes, not only an id -------------
    $adFaqOld = '<p>Only if the store administrator has configured an advertising pixel. If none is configured, no advertising cookie is set by this site.</p>';

    $adFaqNew = '<p>Only if the store administrator has configured an advertising pixel <em>and</em> you have allowed'
        . ' advertising. If none is configured, or you have not allowed it, no advertising cookie is set by this site.</p>';

    // ---- privacy §9: the rights are self service now ---------------------
    $writeToUs = '{{#store_email}}<p>To exercise any of these rights, write to <a href="mailto:{{store_email}}">{{store_email}}</a> from the email address registered on the account, so that we can verify the request without asking you for further identity documents.</p>{{/store_email}}';

    $selfService = '<p>Two of these you can do yourself. Signed in, <a href="{{url:privacy.php}}">Data &amp; Privacy</a>'
        . ' in your account will export everything the store holds about you, and will close and anonymise the account.'
        . ' Neither happens on a click: we email the address on the account and wait for you to open the link, so that'
        . ' a borrowed laptop cannot download your history and a stolen inbox cannot delete it. Deletion removes your'
        . ' profile, addresses, wishlist, preferences, signed-in devices and the link between you and your past visits;'
        . ' your reviews stay on the products, under "Deleted customer"; and your tax invoices and order ledger stay'
        . ' exactly as issued, with the name printed on them, because the law requires a seller to keep them.</p>'
        . "\n"
        . $writeToUs;

    return [
        [
            'id'    => 'cookie-location-sentence',
            'slug'  => 'cookie-policy',
            'needs' => ['own_analytics_anonymous'],
            'find'  => ['seeded' => $cookieGeoAnchor],
            'text'  => $cookieGeoNew,
            'hint'  => 'Run database/seeds/analytics-policy-copy.php first - this sentence is one it writes.',
        ],
        [
            'id'    => 'cookie-server-side-send',
            'slug'  => 'cookie-policy',
            'needs' => ['meta_capi_sender'],
            'find'  => ['pristine' => $firstThirdAnchor],
            'text'  => $firstThirdNew,
        ],
        [
            'id'    => 'cookie-managing-on-site',
            'slug'  => 'cookie-policy',
            'needs' => [],
            'find'  => ['pristine' => $browserItem],
            'text'  => $siteItem,
        ],
        [
            'id'    => 'cookie-faq-advertising-consent',
            'slug'  => 'cookie-policy',
            'needs' => [],
            'find'  => ['pristine' => $adFaqOld],
            'text'  => $adFaqNew,
        ],
        [
            'id'    => 'privacy-visit-statistics-row',
            'slug'  => 'privacy-policy',
            'needs' => [],
            'find'  => ['pristine' => $deviceRow],
            'text'  => $visitRow,
        ],
        [
            'id'    => 'privacy-visit-statistics-note',
            'slug'  => 'privacy-policy',
            'needs' => ['own_analytics_anonymous'],
            'find'  => ['pristine' => $neverAnchor],
            'text'  => $visitNote,
        ],
        [
            'id'    => 'privacy-server-side-send',
            'slug'  => 'privacy-policy',
            'needs' => ['meta_capi_sender'],
            'find'  => ['pristine' => $techBullet],
            'text'  => $serverBullet,
        ],
        [
            'id'    => 'privacy-owner-webhook',
            'slug'  => 'privacy-policy',
            'needs' => ['webhook_sender'],
            'find'  => ['pristine' => $governmentBullet],
            'text'  => $webhookBullet . "\n" . $governmentBullet,
        ],
        [
            'id'    => 'privacy-rights-self-service',
            'slug'  => 'privacy-policy',
            'needs' => ['privacy_self_service'],
            'find'  => ['pristine' => $writeToUs],
            'text'  => $selfService,
        ],
    ];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $dryRun = in_array('--dry', $argv, true);
    $result = policy_copy_apply(policy_geo_edits(), $dryRun, policy_geo_conditions());

    exit(policy_copy_report('Policy copy: location, server-side sending and data rights', $result, $dryRun));
}
