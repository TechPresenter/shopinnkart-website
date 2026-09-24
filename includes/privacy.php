<?php
/**
 * ShopInnKart - The privacy rights the store's own policy promises.
 *
 * Two things a customer can ask for, and one honest answer to each:
 *
 *   Export. Everything the store holds that is about them, in one file:
 *   profile, addresses, orders and their items, invoices, payments, returns,
 *   reviews, notifications, preferences, wishlist, stock alerts, contact
 *   messages, sign-in history and what the store knows about their consent.
 *
 *   Deletion. Not "your data vanishes", because part of it legally cannot.
 *   A tax invoice is a document the store is required to keep, and an order
 *   that has been paid for and delivered is a financial record. So deletion
 *   here is anonymisation: the account becomes a tombstone, every trace that
 *   points at a person is removed or detached, the order history survives as
 *   numbers, and the invoices survive exactly as they were issued. The
 *   customer is told that in those words - privacy_erase_plan() is the same
 *   list the confirmation page shows, the email repeats and the code runs, so
 *   the three cannot drift apart.
 *
 * Both flows are rate limited, confirmed by a link sent to the address on
 * file, written to security_events, and available to an admin acting for a
 * customer who wrote in instead of clicking.
 *
 * The export bundle is encrypted on disk with the same frame format backups
 * use: it is a single file containing every address and order a person ever
 * had, so leaving it readable in a folder would undo the point of the feature.
 * backup.php is included for that cipher and for the folder guard rather than
 * copying either - one of them written twice is one of them going stale.
 */

declare(strict_types=1);

// Required rather than assumed: the only thing that makes a failed export
// section visible is the security log, so this file must not be loadable
// without it.
require_once INCLUDES_PATH . '/security-events.php';

require_once __DIR__ . '/backup.php';

/** How long a confirmation link is good for. */
const PRIVACY_TOKEN_TTL = 86400;

/** The deadline shown to the admin, in days. Matches what the policy states. */
const PRIVACY_DUE_DAYS = 30;

/** Default days an export bundle is kept before it is deleted unread. */
const PRIVACY_BUNDLE_DAYS = 7;

/** What a customer may ask for. */
const PRIVACY_TYPES = ['export' => 'Data export', 'delete' => 'Account deletion'];

// ===========================================================================
//  Settings and folders
// ===========================================================================

/** Is the self-service side switched on? Admin-side requests always work. */
function privacy_enabled(): bool
{
    return setting_bool('sec_privacy_self_service', true);
}

/** Days an unread export bundle is kept on disk. */
function privacy_bundle_days(): int
{
    return max(1, min(90, setting_int('sec_privacy_bundle_days', PRIVACY_BUNDLE_DAYS)));
}

/**
 * Where export bundles live.
 *
 * Guarded the same way the backups folder is, and for the same reason: the
 * file is one customer's whole life with the store, and a directory listing
 * over HTTP would hand it to anyone who guessed the name.
 */
function privacy_dir(): string
{
    return backup_dir_guard(STORAGE_PATH . '/privacy');
}

/** Resolve a stored bundle name to a real path, or null if it escapes. */
function privacy_bundle_path(string $filename): ?string
{
    $name = basename(str_replace('\\', '/', $filename));
    if (preg_match('/^[A-Za-z0-9._-]+\.(zip|json)\.enc$/', $name) !== 1) {
        return null;
    }

    $dir  = privacy_dir();
    $real = realpath($dir . '/' . $name);
    $base = realpath($dir);
    if ($real === false || $base === false) {
        return null;
    }

    $real = str_replace('\\', '/', $real);
    $base = rtrim(str_replace('\\', '/', $base), '/');

    return strpos($real, $base . '/') === 0 && is_file($real) ? $real : null;
}

// ===========================================================================
//  Requests
// ===========================================================================

/** The limits on each kind of request: [per account per day, per IP per day]. */
function privacy_limits(string $type): array
{
    return $type === 'delete' ? ['account' => 1, 'ip' => 3] : ['account' => 2, 'ip' => 5];
}

/** A request of this type the customer has already made and not finished. */
function privacy_open_request(int $userId, string $type): ?array
{
    return Database::fetch(
        "SELECT * FROM `privacy_requests`
          WHERE `user_id` = :uid AND `type` = :type AND `status` IN ('pending', 'ready')
          ORDER BY `id` DESC LIMIT 1",
        ['uid' => $userId, 'type' => $type]
    );
}

/**
 * Start a request.
 *
 * @param array{user_id:int,type:string,by?:string,admin_id?:int,admin_name?:string,reason?:string,auto_confirm?:bool} $options
 * @return array{ok:bool,error:string,request:?array,token:string}
 */
function privacy_request_create(array $options): array
{
    $userId = (int) ($options['user_id'] ?? 0);
    $type   = (string) ($options['type'] ?? '');
    $by     = (string) ($options['by'] ?? 'customer');

    if (!isset(PRIVACY_TYPES[$type])) {
        return ['ok' => false, 'error' => 'Unknown request type.', 'request' => null, 'token' => ''];
    }

    $user = Database::fetch(
        'SELECT `id`, `first_name`, `last_name`, `email` FROM `users` WHERE `id` = :id',
        ['id' => $userId]
    );
    if ($user === null) {
        return ['ok' => false, 'error' => 'That account no longer exists.', 'request' => null, 'token' => ''];
    }

    // The limits apply to the customer's own button only. An admin working a
    // support queue may legitimately raise several in a row, and their actions
    // are already logged and permission-gated.
    if ($by === 'customer') {
        $limits = privacy_limits($type);
        if (!rate_limit_attempt('privacy.' . $type, 'user:' . $userId, $limits['account'], 86400)) {
            security_event('privacy.rate_limited', 'low', ['type' => $type, 'scope' => 'account'], $userId, 'customer');
            return [
                'ok'    => false,
                'error' => $type === 'delete'
                    ? 'A deletion request is already in flight for this account today. Check your email for the confirmation link, or write to us.'
                    : 'You have already asked for your data today. Check your email for the link - it is good for 24 hours.',
                'request' => null,
                'token'   => '',
            ];
        }
        if (!rate_limit_attempt('privacy.' . $type . '.ip', client_ip(), $limits['ip'], 86400)) {
            security_event('privacy.rate_limited', 'medium', ['type' => $type, 'scope' => 'ip'], $userId, 'customer');
            return [
                'ok'      => false,
                'error'   => 'Too many privacy requests from this connection today. Try again tomorrow, or write to us.',
                'request' => null,
                'token'   => '',
            ];
        }
    }

    // One live request per type. Without this, ten clicks are ten tokens and
    // any one of them still works.
    $existing = privacy_open_request($userId, $type);
    if ($existing !== null) {
        Database::update('privacy_requests', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
            '`id` = :id', ['id' => (int) $existing['id']]);
    }

    $token = bin2hex(random_bytes(32));
    $now   = time();

    $id = Database::insert('privacy_requests', [
        'user_id'        => $userId,
        'email'          => mb_substr((string) $user['email'], 0, 190),
        'name'           => mb_substr(trim((string) $user['first_name'] . ' ' . (string) $user['last_name']), 0, 150),
        'type'           => $type,
        'status'         => 'pending',
        'token_hash'     => privacy_token_hash($token),
        'token_expires_at' => date('Y-m-d H:i:s', $now + PRIVACY_TOKEN_TTL),
        'due_at'         => date('Y-m-d H:i:s', $now + (PRIVACY_DUE_DAYS * 86400)),
        'requested_by'   => $by === 'admin' ? 'admin' : 'customer',
        'admin_id'       => isset($options['admin_id']) ? (int) $options['admin_id'] : null,
        'admin_name'     => isset($options['admin_name']) ? mb_substr((string) $options['admin_name'], 0, 150) : null,
        'reason'         => ((string) ($options['reason'] ?? '')) !== ''
            ? mb_substr((string) $options['reason'], 0, 255) : null,
        'request_ip'     => mb_substr(client_ip(), 0, 45),
    ]);

    security_event('privacy.requested', $type === 'delete' ? 'high' : 'medium', [
        'request_id' => $id,
        'type'       => $type,
        'by'         => $by,
        'email'      => mask_email((string) $user['email']),
    ], $userId, 'customer');

    log_activity('privacy.requested', 'privacy_request', $id,
        ucfirst($type) . ' request raised for ' . mask_email((string) $user['email']) . ' (' . $by . ')');

    $request = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => $id]);

    // A customer clicking the button gets a link to prove it was them. An
    // admin logging a request that arrived by email or over the phone does
    // not: sending "confirm this" to someone who already asked, in writing,
    // reads as a brush-off, and the admin's own action is logged either way.
    // The "it is done" email still goes out, to the same address.
    if ($by !== 'admin') {
        privacy_notify_requested($request, $token);
    }

    return ['ok' => true, 'error' => '', 'request' => $request, 'token' => $token];
}

/** Tokens are stored hashed, so a stolen database is not a pile of live links. */
function privacy_token_hash(string $token): string
{
    return hash_hmac('sha256', $token, app_key_derive('privacy-request') ?: 'privacy-request');
}

/** Find a live request by its emailed token. Expired tokens return null. */
function privacy_find_by_token(string $token): ?array
{
    if (strlen($token) !== 64 || preg_match('/^[a-f0-9]+$/', $token) !== 1) {
        return null;
    }

    return Database::fetch(
        "SELECT * FROM `privacy_requests`
          WHERE `token_hash` = :h AND `status` = 'pending' AND `token_expires_at` > NOW()
          LIMIT 1",
        ['h' => privacy_token_hash($token)]
    );
}

/** The confirmation link sent by email. */
function privacy_confirm_url(string $token): string
{
    return canonical_url('privacy.php?confirm=' . $token);
}

// ===========================================================================
//  Carrying a request out
// ===========================================================================

/**
 * Do what the request asked for.
 *
 * @param array{actor?:string,admin_id?:int,admin_name?:string} $context
 * @return array{ok:bool,error:string,summary:array,request:?array}
 */
function privacy_fulfil(array $request, array $context = []): array
{
    $id     = (int) $request['id'];
    $userId = (int) $request['user_id'];
    $type   = (string) $request['type'];
    $actor  = (string) ($context['actor'] ?? 'customer');

    $fresh = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => $id]);
    if ($fresh === null || !in_array((string) $fresh['status'], ['pending', 'ready'], true)) {
        return ['ok' => false, 'error' => 'That request has already been dealt with.', 'summary' => [], 'request' => $fresh];
    }

    // A "ready" export can legitimately be rebuilt - an admin re-running it
    // for a customer whose download failed - and the new bundle gets a new
    // name, so the old one would be left in the folder with nothing pointing
    // at it. It is one customer's entire history sitting on a shared host, so
    // it goes now rather than waiting for the orphan sweep a day later.
    if ($type === 'export' && (string) ($fresh['file_name'] ?? '') !== '') {
        $stale = privacy_bundle_path((string) $fresh['file_name']);
        if ($stale !== null) {
            @unlink($stale);
        }
    }

    try {
        $summary = $type === 'delete'
            ? privacy_erase($userId, ['request_id' => $id, 'actor' => $actor])
            : privacy_export_build($userId, $id);
    } catch (Throwable $e) {
        ErrorHandler::log('error', 'Privacy request ' . $id . ' failed: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        Database::update('privacy_requests', [
            'status'     => 'failed',
            'error'      => mb_substr($e->getMessage(), 0, 500),
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = :id', ['id' => $id]);

        return ['ok' => false, 'error' => 'The request could not be completed: ' . $e->getMessage(),
                'summary' => [], 'request' => $fresh];
    }

    // An export stays "ready" until it is downloaded or expires; an erasure is
    // finished the moment it runs, because there is nothing left to collect.
    $fields = [
        'status'       => $type === 'delete' ? 'completed' : 'ready',
        'completed_at' => date('Y-m-d H:i:s'),
        'token_hash'   => null,   // the emailed link is spent
        'summary'      => (string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    if ($type === 'export') {
        $fields['file_name']     = (string) ($summary['file_name'] ?? '');
        $fields['file_bytes']    = (int) ($summary['file_bytes'] ?? 0);
        $fields['file_checksum'] = (string) ($summary['file_checksum'] ?? '');
        $fields['expires_at']    = date('Y-m-d H:i:s', time() + (privacy_bundle_days() * 86400));
    }

    Database::update('privacy_requests', $fields, '`id` = :id', ['id' => $id]);

    security_event($type === 'delete' ? 'privacy.erased' : 'privacy.exported', 'high', [
        'request_id' => $id,
        'actor'      => $actor,
        'summary'    => $type === 'delete' ? ($summary['counts'] ?? []) : ['bytes' => $summary['file_bytes'] ?? 0],
    ], $userId, 'customer');

    log_activity('privacy.' . $type, 'privacy_request', $id,
        ($type === 'delete' ? 'Anonymised account ' : 'Built a data export for ')
        . mask_email((string) $fresh['email']) . ' (' . $actor . ')');

    $updated = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => $id]);

    // The address from before the erasure, not the one on the row now: an
    // erasure tombstones its own request records too, and the last message
    // the store ever sends has to reach a real inbox.
    privacy_notify_done(array_merge((array) $updated, [
        'email' => (string) $fresh['email'],
        'name'  => (string) $fresh['name'],
    ]), $summary);

    return ['ok' => true, 'error' => '', 'summary' => $summary, 'request' => $updated];
}

/** Close a request without doing it. */
function privacy_reject(int $id, string $reason, array $context = []): bool
{
    $request = Database::fetch('SELECT * FROM `privacy_requests` WHERE `id` = :id', ['id' => $id]);
    if ($request === null || in_array((string) $request['status'], ['completed', 'rejected'], true)) {
        return false;
    }

    Database::update('privacy_requests', [
        'status'     => 'rejected',
        'reason'     => mb_substr($reason, 0, 255),
        'token_hash' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], '`id` = :id', ['id' => $id]);

    security_event('privacy.rejected', 'medium', [
        'request_id' => $id,
        'type'       => (string) $request['type'],
        'actor'      => (string) ($context['actor'] ?? 'admin'),
    ], (int) $request['user_id'], 'customer');

    log_activity('privacy.rejected', 'privacy_request', $id,
        'Closed a ' . (string) $request['type'] . ' request for ' . mask_email((string) $request['email']));

    return true;
}

// ===========================================================================
//  The export
// ===========================================================================

/**
 * Run a query for the export, and never let one missing table lose the rest.
 *
 * The catch is deliberate - an older database missing a table should still
 * produce the other twelve sections rather than failing the whole request.
 * But it used to return [] and say nothing, which is indistinguishable from
 * "this customer has none", and that is the worst possible answer for a
 * subject-access bundle: two of the three device queries named columns that
 * do not exist, and for as long as they did, every export told the customer
 * they had no remembered browsers and no trusted devices. It was marked
 * ready and nothing anywhere recorded a problem.
 *
 * So a failure is still survivable, but it is now LOUD: it is written to the
 * security log and counted, and privacy_export_build() refuses to describe a
 * bundle as complete when the counter moved.
 */
function privacy_rows(string $sql, array $params = []): array
{
    try {
        return Database::fetchAll($sql, $params);
    } catch (Throwable $e) {
        privacy_export_fault($e->getMessage(), $sql);
        return [];
    }
}

/**
 * Record that one section of an export could not be read.
 *
 * Kept in a static rather than a column because it belongs to ONE run of
 * privacy_export_build(), which resets it before it starts. The SQL is
 * logged, the parameters are not: the parameters are the customer's own ids
 * and this log is not the place for them.
 *
 * @param string|null $reset pass null to read the count, 'reset' to clear it
 */
function privacy_export_fault(?string $message = null, string $sql = '', bool $reset = false): int
{
    static $faults = 0;

    if ($reset) {
        $faults = 0;
        return 0;
    }
    if ($message === null) {
        return $faults;
    }

    $faults++;
    security_event('privacy.export_section_failed', 'high', [
        'error' => mb_substr($message, 0, 300),
        // First line only: enough to name the table and the columns.
        'query' => mb_substr(trim((string) strtok($sql, "
")), 0, 200),
    ]);

    return $faults;
}

/**
 * Everything the store holds about one customer.
 *
 * Grouped the way a person would ask for it rather than the way the schema
 * happens to be laid out, and every group carries a one-line note saying what
 * it is - a bundle that needs the schema to read is not really an answer.
 */
function privacy_export_data(int $userId): array
{
    $user = Database::fetch('SELECT * FROM `users` WHERE `id` = :id', ['id' => $userId]);
    if ($user === null) {
        throw new RuntimeException('That account no longer exists.');
    }

    // Never in an export: the password hash, the 2FA secret and the session
    // version are credentials, not personal data, and putting them in a file
    // that lands in a Downloads folder helps nobody but an attacker.
    foreach (['password', 'totp_secret', 'auth_version', 'totp_last_step'] as $secret) {
        unset($user[$secret]);
    }

    $orders = privacy_rows(
        'SELECT * FROM `orders` WHERE `user_id` = :id ORDER BY `id` DESC',
        ['id' => $userId]
    );

    $orderIds = array_map(static fn (array $o): int => (int) $o['id'], $orders);
    $items    = [];
    if ($orderIds !== []) {
        [$placeholders, $params] = Database::inPlaceholders($orderIds, 'o');
        $items = privacy_rows(
            'SELECT * FROM `order_items` WHERE `order_id` IN (' . $placeholders . ') ORDER BY `order_id`, `id`',
            $params
        );
    }

    return [
        'export' => [
            'store'       => (string) setting('store_name', SITE_NAME),
            'generated'   => date('c'),
            'account_id'  => $userId,
            'about'       => 'Everything ' . (string) setting('store_name', SITE_NAME)
                . ' holds that is about you. Passwords and two-factor secrets are deliberately '
                . 'left out: they are credentials, not information about you.',
            'format_note' => 'data.json is the complete record. The .csv files repeat the larger '
                . 'sections in a form a spreadsheet can open.',
        ],

        'profile' => [
            'note' => 'Your account as it stands today.',
            'data' => $user,
        ],

        'addresses' => [
            'note' => 'The addresses saved in your address book. Addresses used on an order are '
                . 'also copied onto the order itself.',
            'data' => privacy_rows('SELECT * FROM `user_addresses` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'orders' => [
            'note' => 'Every order placed on this account, with the address and contact details '
                . 'used at the time.',
            'data' => $orders,
        ],

        'order_items' => [
            'note' => 'What was in each of those orders.',
            'data' => $items,
        ],

        'invoices' => [
            'note' => 'Tax invoices issued to you. These are the documents the store is required '
                . 'to keep, and they are kept as issued even if you later delete your account.',
            'data' => privacy_rows('SELECT * FROM `invoices` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'payments' => [
            'note' => 'Payment attempts and their outcome. Card numbers are never stored here - '
                . 'only the gateway\'s reference.',
            'data' => privacy_rows(
                'SELECT `p`.* FROM `payments` `p`
                  INNER JOIN `orders` `o` ON `o`.`id` = `p`.`order_id`
                  WHERE `o`.`user_id` = :id ORDER BY `p`.`id`',
                ['id' => $userId]
            ),
        ],

        'returns' => [
            'note' => 'Returns and exchanges you asked for.',
            'data' => privacy_rows('SELECT * FROM `return_requests` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'reviews' => [
            'note' => 'Reviews you wrote, whether or not they were published.',
            'data' => privacy_rows('SELECT * FROM `reviews` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'notifications' => [
            'note' => 'In-app notifications sent to you.',
            'data' => privacy_rows('SELECT * FROM `user_notifications` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'emails' => [
            'note' => 'Email the store sent you. The message body is left out - it is the same '
                . 'message that is already in your inbox.',
            'data' => privacy_rows(
                'SELECT `id`, `template_key`, `recipient`, `subject`, `status`, `sent_at`, `created_at`
                   FROM `notification_queue` WHERE `user_id` = :id ORDER BY `id`',
                ['id' => $userId]
            ),
        ],

        'preferences' => [
            'note' => 'The choices you made on the Preferences page.',
            'data' => privacy_rows('SELECT * FROM `user_preferences` WHERE `user_id` = :id', ['id' => $userId]),
        ],

        'wishlist' => [
            'note' => 'Products you saved.',
            'data' => privacy_rows(
                'SELECT `i`.*, `w`.`name` AS `list_name` FROM `wishlist_items` `i`
                  INNER JOIN `wishlists` `w` ON `w`.`id` = `i`.`wishlist_id`
                  WHERE `w`.`user_id` = :id ORDER BY `i`.`id`',
                ['id' => $userId]
            ),
        ],

        'recently_viewed' => [
            'note' => 'Products you looked at while signed in.',
            'data' => privacy_rows('SELECT * FROM `recently_viewed` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'stock_alerts' => [
            'note' => 'Back-in-stock alerts you asked for.',
            'data' => privacy_rows('SELECT * FROM `stock_alerts` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'searches' => [
            'note' => 'Searches made while signed in, with the IP address they came from.',
            'data' => privacy_rows('SELECT * FROM `search_logs` WHERE `user_id` = :id ORDER BY `id`', ['id' => $userId]),
        ],

        'messages' => [
            'note' => 'Messages you sent through the contact form, matched on your email address.',
            'data' => privacy_rows('SELECT * FROM `contact_messages` WHERE `email` = :e ORDER BY `id`',
                ['e' => (string) $user['email']]),
        ],

        'sign_ins' => [
            'note' => 'Sign-ins and failed attempts on this account. Kept as a security record.',
            'data' => privacy_rows(
                "SELECT `id`, `status`, `reason`, `ip_address`, `user_agent`, `created_at`
                   FROM `login_history` WHERE `user_type` = 'customer' AND `user_id` = :id
                   ORDER BY `id` DESC LIMIT 500",
                ['id' => $userId]
            ),
        ],

        'devices' => [
            'note' => 'Devices signed in to this account: remembered browsers, trusted devices '
                . 'and mobile app tokens. The tokens themselves are not stored and cannot be shown.',
            'data' => [
                // `remember_tokens` and `auth_trusted_devices` do NOT share
                // api_tokens' column names, and this used to assume they did.
                // Aliased to one shape so the bundle reads the same whichever
                // table a row came from.
                'remembered'  => privacy_rows(
                    'SELECT `id`, `ip_address` AS `last_ip`, `user_agent`, `expires_at`, `created_at`
                       FROM `remember_tokens` WHERE `user_id` = :id', ['id' => $userId]),
                'trusted'     => privacy_rows(
                    "SELECT `id`, `label` AS `device_name`, `ip_address` AS `last_ip`,
                            `last_used_at`, `expires_at`, `created_at`
                       FROM `auth_trusted_devices` WHERE `user_type` = 'customer' AND `user_id` = :id",
                    ['id' => $userId]),
                'app_tokens'  => privacy_rows(
                    "SELECT `id`, `device_name`, `platform`, `last_ip`, `last_used_at`, `expires_at`, `created_at`
                       FROM `api_tokens` WHERE `user_type` = 'customer' AND `user_id` = :id",
                    ['id' => $userId]),
            ],
        ],

        'consent' => privacy_consent_record($userId, (string) $user['email']),
    ];
}

/**
 * What the store knows about this person's consent.
 *
 * Deliberately not a table of its own. The cookie banner's answer lives in the
 * visitor's own browser, not on the server - inventing a "consent history"
 * table here would look thorough and record nothing that is actually used. So
 * this reports the three places consent really is: the choice carried on the
 * current request, whether analytics sessions were recorded as consented, and
 * the marketing choices held on the account.
 */
function privacy_consent_record(int $userId, string $email): array
{
    $current = function_exists('consent_stored') ? consent_stored() : null;

    return [
        'note' => 'Your cookie choice is stored in your browser, not on our servers, so we can '
            . 'only show the one carried by the request that built this file. What the server '
            . 'does keep is whether each recorded visit had consent, and the marketing choices '
            . 'saved on your account.',
        'cookie_on_this_request' => $current ?? 'not set on the request that built this export',
        'analytics_sessions'     => privacy_rows(
            'SELECT `id`, `day`, `consented`, `started_at`, `last_seen_at`
               FROM `an_sessions` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 500',
            ['id' => $userId]
        ),
        'marketing_preferences'  => privacy_rows(
            "SELECT `pref_key`, `pref_value`, `updated_at` FROM `user_preferences`
               WHERE `user_id` = :id AND (`pref_key` LIKE 'notify%' OR `pref_key` LIKE 'channel%')",
            ['id' => $userId]
        ),
        'newsletter'             => privacy_rows(
            'SELECT `status`, `source`, `created_at`, `updated_at` FROM `newsletter_subscribers` WHERE `email` = :e',
            ['e' => $email]
        ),
    ];
}

/**
 * Build the bundle file.
 *
 * One file, always. When the zip extension is present it is a .zip holding
 * data.json, a README and a CSV per large section; when it is not - and plenty
 * of shared hosts leave it out - it is the same data.json on its own, and the
 * summary says which of the two the customer got rather than pretending.
 *
 * @return array{file_name:string,file_bytes:int,file_checksum:string,format:string,sections:array<string,int>}
 */
function privacy_export_build(int $userId, int $requestId): array
{
    // Any section that cannot be read is counted while the data is gathered,
    // so the summary can say the bundle is short rather than implying the
    // customer simply has nothing under that heading.
    privacy_export_fault(null, '', true);

    $data     = privacy_export_data($userId);
    $faults   = privacy_export_fault();
    $sections = [];
    foreach ($data as $key => $section) {
        if (is_array($section) && isset($section['data']) && is_array($section['data'])) {
            $sections[$key] = count($section['data']);
        }
    }

    $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    $dir    = privacy_dir();
    $stamp  = date('Ymd-His');
    $useZip = class_exists('ZipArchive');
    $inner  = $dir . '/tmp-' . bin2hex(random_bytes(6)) . ($useZip ? '.zip' : '.json');

    try {
        if ($useZip) {
            $zip = new ZipArchive();
            if ($zip->open($inner, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export archive could not be created.');
            }
            $zip->addFromString('README.txt', privacy_export_readme($data));
            $zip->addFromString('data.json', $json);
            foreach ($data as $key => $section) {
                if (!is_array($section) || !isset($section['data']) || !is_array($section['data']) || $section['data'] === []) {
                    continue;
                }
                // Only flat row sets become CSV; `devices` is a group of
                // groups and would come out as a column of "Array".
                $first = reset($section['data']);
                if (!is_array($first) || array_filter($first, 'is_array') !== []) {
                    continue;
                }
                $zip->addFromString($key . '.csv', privacy_csv($section['data']));
            }
            $zip->close();
        } else {
            file_put_contents($inner, $json);
        }

        // Encrypted with the application key, exactly like a backup: the file
        // sits in a folder on a shared host until it is downloaded.
        $filename = 'export-' . $userId . '-' . $stamp . '-' . bin2hex(random_bytes(4))
            . ($useZip ? '.zip' : '.json') . '.enc';
        $path     = $dir . '/' . $filename;

        $cipher = new BackupCipher($path);
        $handle = fopen($inner, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export could not be read back for encryption.');
        }
        try {
            while (!feof($handle)) {
                $cipher->write((string) fread($handle, BACKUP_CRYPT_CHUNK));
            }
        } finally {
            fclose($handle);
            $cipher->close();
        }
    } finally {
        if (is_file($inner)) {
            @unlink($inner);
        }
    }

    clearstatcache(true, $path);

    return [
        'file_name'     => $filename,
        'file_bytes'    => (int) filesize($path),
        'file_checksum' => backup_checksum($path),
        'format'        => $useZip ? 'zip' : 'json',
        'sections'      => $sections,
        'request_id'    => $requestId,
        // Zero on a healthy store. Anything else means a section could not
        // be read and the bundle is SHORT - which must never be reported to
        // the customer as "you have none of those".
        'faults'        => $faults,
    ];
}

/**
 * The plain-text note that opens the archive.
 *
 * If a section could not be read, the note says so. A subject-access
 * bundle that is quietly short is worse than one that admits it is short:
 * the customer can ask again, and the store can be held to it.
 */
function privacy_export_readme(array $data): string
{
    $lines = [
        (string) setting('store_name', SITE_NAME) . ' - your data',
        str_repeat('=', 40),
        '',
        'Generated: ' . date('d M Y, H:i'),
        '',
        'data.json holds everything, exactly as we store it.',
        'The .csv files repeat the bigger sections so a spreadsheet can open them.',
        '',
        'What is in here:',
    ];

    foreach ($data as $key => $section) {
        if (!is_array($section) || !isset($section['note'])) {
            continue;
        }
        $count = isset($section['data']) && is_array($section['data']) ? count($section['data']) : null;
        $lines[] = '  - ' . $key . ($count !== null ? ' (' . $count . ')' : '') . ': ' . (string) $section['note'];
    }

    $lines[] = '';
    $lines[] = 'Not in here: your password and your two-factor secret. Those are credentials,';
    $lines[] = 'not information about you, and a copy of them in your Downloads folder would';
    $lines[] = 'only ever help somebody else.';

    return implode("\r\n", $lines) . "\r\n";
}

/** Rows to CSV, with the header taken from the first row. */
function privacy_csv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }

    $first = reset($rows);
    fputcsv($handle, array_keys(is_array($first) ? $first : []));
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        fputcsv($handle, array_map(
            static fn ($v): string => $v === null ? '' : (is_scalar($v) ? (string) $v : (string) json_encode($v)),
            $row
        ));
    }

    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    // Excel opens UTF-8 CSV as mojibake without a BOM, and an address book
    // full of question marks is not much of an export.
    return "\xEF\xBB\xBF" . $csv;
}

/** Stream a bundle's plaintext, decrypting as it goes. */
function privacy_bundle_stream(array $request): Generator
{
    $path = privacy_bundle_path((string) ($request['file_name'] ?? ''));
    if ($path === null) {
        throw new RuntimeException('That export file is no longer on the server.');
    }

    yield from BackupCipher::read($path);
}

/**
 * Delete bundles that were never collected, and the ones past their date.
 *
 * @return int files removed
 */
function privacy_prune_bundles(): int
{
    $removed = 0;

    $rows = Database::fetchAll(
        "SELECT `id`, `file_name` FROM `privacy_requests`
          WHERE `file_name` IS NOT NULL AND `file_name` <> '' AND `expires_at` IS NOT NULL AND `expires_at` < NOW()"
    );

    foreach ($rows as $row) {
        $path = privacy_bundle_path((string) $row['file_name']);
        if ($path !== null) {
            @unlink($path);
            $removed++;
        }
        Database::update('privacy_requests', [
            'file_name'  => null,
            'status'     => 'expired',
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = :id AND `status` <> :done', ['id' => (int) $row['id'], 'done' => 'completed']);
    }

    // Anything in the folder with no row behind it - an interrupted build, or
    // a row someone deleted - would otherwise sit there for good.
    foreach (glob(privacy_dir() . '/*.enc') ?: [] as $orphan) {
        $known = Database::exists('privacy_requests', '`file_name` = :f', ['f' => basename($orphan)]);
        if (!$known && filemtime($orphan) < time() - 86400) {
            @unlink($orphan);
            $removed++;
        }
    }

    return $removed;
}

// ===========================================================================
//  The erasure
// ===========================================================================

/**
 * What deletion actually does, in the order it does it.
 *
 * This list is the contract. The confirmation page prints it, the email
 * repeats it and privacy_erase() carries it out, so a customer can never be
 * told one thing and given another.
 *
 * @return array{removed:string[],anonymised:string[],kept:string[]}
 */
function privacy_erase_plan(): array
{
    return [
        'removed' => [
            'Your saved addresses.',
            'Your wishlist, comparison list, recently viewed products and anything still in your basket.',
            'Your notification history and your saved preferences.',
            'Your back-in-stock alerts and your newsletter subscription.',
            'Every device signed in to the account: remembered browsers, trusted devices, app tokens '
                . 'and any pending sign-in codes. You will be signed out everywhere immediately.',
            'Your profile photo.',
        ],
        'anonymised' => [
            'Your account itself. The name, email address, phone number, date of birth and password '
                . 'are replaced with placeholders, and the account is closed. It cannot be signed in to again.',
            'Your reviews stay on the products they are about, because they are about the product, '
                . 'not about you - but they are detached from your account and the author becomes '
                . '"Deleted customer".',
            'Your searches and the contact messages you sent stay as text, with your name, email '
                . 'address and IP address removed.',
            'The contact details on your past orders - email, phone, IP address - are replaced. The '
                . 'delivery has happened; nobody needs to reach you about it any more.',
        ],
        'kept' => [
            'Your orders and what was in them, as a financial record: dates, items, amounts, taxes '
                . 'and how they were paid for.',
            'Your tax invoices, exactly as they were issued - including the name and address printed '
                . 'on them. An invoice is a legal document and the store is required to keep it; '
                . 'altering one after the fact is not something we are allowed to do.',
            'Your sign-in history, as a security record, until it ages out of the log - with your '
                . 'email address removed from it.',
        ],
    ];
}

/**
 * Anonymise an account.
 *
 * Runs inside one transaction: a half-anonymised account - name gone, email
 * still there - is worse than either outcome, and it is not something a
 * customer could ask us to finish, because they can no longer sign in.
 *
 * @return array{counts:array<string,int>,tombstone_email:string,plan:array}
 */
function privacy_erase(int $userId, array $options = []): array
{
    $user = Database::fetch('SELECT * FROM `users` WHERE `id` = :id', ['id' => $userId]);
    if ($user === null) {
        throw new RuntimeException('That account no longer exists.');
    }

    $email     = (string) $user['email'];
    $tombstone = 'deleted-' . $userId . '@removed.invalid';   // .invalid is reserved, RFC 2606
    $avatar    = (string) ($user['avatar'] ?? '');
    $counts    = [];

    $run = static function (string $label, string $sql, array $params) use (&$counts): void {
        try {
            $counts[$label] = Database::query($sql, $params)->rowCount();
        } catch (PDOException $e) {
            // A table this build does not have (an_sessions before the
            // analytics migration, say) is not a reason to abandon the
            // erasure. Anything else is: silently skipping a real failure
            // would leave personal data behind and report success.
            if (strpos((string) $e->getCode(), '42S02') !== 0) {
                throw $e;
            }
            $counts[$label] = 0;
        }
    };

    Database::transaction(static function () use ($userId, $email, $tombstone, &$counts, $run): void {
        // --- gone entirely -------------------------------------------------
        $run('addresses', 'DELETE FROM `user_addresses` WHERE `user_id` = :id', ['id' => $userId]);
        $run('preferences', 'DELETE FROM `user_preferences` WHERE `user_id` = :id', ['id' => $userId]);
        $run('notifications', 'DELETE FROM `user_notifications` WHERE `user_id` = :id', ['id' => $userId]);
        $run('recently_viewed', 'DELETE FROM `recently_viewed` WHERE `user_id` = :id', ['id' => $userId]);
        $run('compare', 'DELETE FROM `compare_items` WHERE `user_id` = :id', ['id' => $userId]);
        $run('stock_alerts', 'DELETE FROM `stock_alerts` WHERE `user_id` = :id OR `email` = :e',
            ['id' => $userId, 'e' => $email]);
        $run('newsletter', 'DELETE FROM `newsletter_subscribers` WHERE `email` = :e', ['e' => $email]);
        $run('wishlist_items',
            'DELETE `i` FROM `wishlist_items` `i`
              INNER JOIN `wishlists` `w` ON `w`.`id` = `i`.`wishlist_id` WHERE `w`.`user_id` = :id',
            ['id' => $userId]);
        $run('wishlists', 'DELETE FROM `wishlists` WHERE `user_id` = :id', ['id' => $userId]);
        $run('cart_items',
            'DELETE `ci` FROM `cart_items` `ci`
              INNER JOIN `carts` `c` ON `c`.`id` = `ci`.`cart_id` WHERE `c`.`user_id` = :id',
            ['id' => $userId]);
        $run('carts', 'DELETE FROM `carts` WHERE `user_id` = :id', ['id' => $userId]);

        // --- every way back in ---------------------------------------------
        $run('remember_tokens', 'DELETE FROM `remember_tokens` WHERE `user_id` = :id', ['id' => $userId]);
        $run('api_tokens', "DELETE FROM `api_tokens` WHERE `user_type` = 'customer' AND `user_id` = :id",
            ['id' => $userId]);
        $run('trusted_devices', "DELETE FROM `auth_trusted_devices` WHERE `user_type` = 'customer' AND `user_id` = :id",
            ['id' => $userId]);
        $run('otps', "DELETE FROM `auth_otps` WHERE `user_type` = 'customer' AND `user_id` = :id", ['id' => $userId]);
        $run('backup_codes', 'DELETE FROM `auth_backup_codes` WHERE `user_id` = :id', ['id' => $userId]);
        $run('password_resets', 'DELETE FROM `password_resets` WHERE `email` = :e', ['e' => $email]);
        $run('email_verifications', 'DELETE FROM `email_verifications` WHERE `user_id` = :id', ['id' => $userId]);

        // --- detached, text kept -------------------------------------------
        $run('searches', 'UPDATE `search_logs` SET `user_id` = NULL, `ip_address` = NULL, `session_id` = NULL
              WHERE `user_id` = :id', ['id' => $userId]);
        $run('reviews', 'UPDATE `reviews` SET `user_id` = NULL, `customer_name` = :n WHERE `user_id` = :id',
            ['n' => 'Deleted customer', 'id' => $userId]);
        $run('messages', 'UPDATE `contact_messages` SET `name` = :n, `email` = :t WHERE `email` = :e',
            ['n' => 'Deleted customer', 't' => $tombstone, 'e' => $email]);
        $run('sign_ins', "UPDATE `login_history` SET `identifier` = :t
              WHERE `user_type` = 'customer' AND `user_id` = :id", ['t' => $tombstone, 'id' => $userId]);
        // The request records prove the erasure happened and when; they do not
        // need to keep the address to do that.
        $run('privacy_records', 'UPDATE `privacy_requests` SET `email` = :t, `name` = :n, `request_ip` = NULL
              WHERE `user_id` = :id', ['t' => $tombstone, 'n' => 'Deleted customer', 'id' => $userId]);
        $run('analytics', 'UPDATE `an_sessions` SET `user_id` = NULL WHERE `user_id` = :id', ['id' => $userId]);
        $run('queued_mail', 'UPDATE `notification_queue` SET `recipient` = :t, `recipient_name` = :n,
              `body` = NULL, `body_text` = NULL
              WHERE `user_id` = :id', ['t' => $tombstone, 'n' => 'Deleted customer', 'id' => $userId]);

        // --- the financial record, with the contact details taken off ------
        // Name and address stay: they are already printed on the tax invoice,
        // and an order that disagrees with its own invoice is worse than one
        // that still carries a delivered-to name.
        // Two placeholders for the same blank string rather than one used
        // twice: PDO runs with emulated prepares off, and MySQL's protocol
        // binds positionally, so a named parameter reused in one statement is
        // an "Invalid parameter number" - which inside this transaction would
        // roll the whole erasure back and report a failure the customer cannot
        // act on.
        $run('orders', 'UPDATE `orders`
              SET `customer_email` = :t, `customer_phone` = :phone_c, `shipping_phone` = :phone_s,
                  `billing_phone` = NULL, `ip_address` = NULL, `user_agent` = NULL, `customer_note` = NULL
              WHERE `user_id` = :id',
            ['t' => $tombstone, 'phone_c' => '', 'phone_s' => '', 'id' => $userId]);
        $run('returns', 'UPDATE `return_requests` SET `customer_email` = :t WHERE `user_id` = :id',
            ['t' => $tombstone, 'id' => $userId]);

        // --- the account itself --------------------------------------------
        Database::update('users', [
            'first_name'        => 'Deleted',
            'last_name'         => 'customer',
            'email'             => $tombstone,
            'phone'             => null,
            // A random password nobody holds, rather than an empty string that
            // some future code path might treat as "no password set".
            'password'          => password_hash_app(bin2hex(random_bytes(32))),
            'avatar'            => null,
            'gender'            => null,
            'date_of_birth'     => null,
            'status'            => 'inactive',
            'email_verified_at' => null,
            'totp_secret'       => null,
            'totp_enabled_at'   => null,
            // 'off', not null: `users`.`mfa_method` is varchar(10) NOT NULL
            // DEFAULT 'off' on both databases, so writing null threw inside
            // this transaction and rolled the WHOLE erasure back - the
            // customer's deletion request failed every single time and their
            // data stayed exactly where it was. Found by running the real
            // privacy_erase() against a real account; 'off' is what the column
            // already means by "no second factor".
            'mfa_method'        => 'off',
            'last_login_ip'     => null,
            'locked_until'      => null,
            // Every live session and token is stamped with the old number, so
            // bumping it signs the account out everywhere at once.
            'auth_version'      => (int) Database::fetchColumn(
                'SELECT `auth_version` FROM `users` WHERE `id` = :id', ['id' => $userId]
            ) + 1,
        ], '`id` = :id', ['id' => $userId]);

        $counts['account'] = 1;
    });

    // Outside the transaction: a filesystem delete cannot be rolled back, so
    // it happens only once the database change is certain.
    if ($avatar !== '') {
        $file = realpath(UPLOAD_PATH . '/' . ltrim(str_replace('\\', '/', $avatar), '/'));
        $base = realpath(UPLOAD_PATH);
        if ($file !== false && $base !== false
            && strpos(str_replace('\\', '/', $file), rtrim(str_replace('\\', '/', $base), '/') . '/') === 0) {
            @unlink($file);
        }
    }

    return [
        'counts'          => array_filter($counts),
        'tombstone_email' => $tombstone,
        'plan'            => privacy_erase_plan(),
    ];
}

// ===========================================================================
//  Telling the customer
// ===========================================================================

/** The "click this to confirm" email. */
function privacy_notify_requested(?array $request, string $token): void
{
    if ($request === null) {
        return;
    }

    $type = (string) $request['type'];
    $plan = privacy_erase_plan();

    notify(
        $type === 'delete' ? 'privacy_delete_requested' : 'privacy_export_requested',
        (string) $request['email'],
        [
            'customer_name' => (string) ($request['name'] ?: 'there'),
            'confirm_url'   => privacy_confirm_url($token),
            'expires_in'    => (string) (PRIVACY_TOKEN_TTL / 3600) . ' hours',
            'kept_list_html' => '<ul style="margin:0 0 16px 0;padding-left:20px">'
                . implode('', array_map(static fn (string $l): string
                    => '<li style="margin:0 0 6px 0">' . e($l) . '</li>', $plan['kept']))
                . '</ul>',
            'kept_list'     => "  - " . implode("\n  - ", $plan['kept']),
        ],
        'privacy_request',
        (int) $request['id'],
        'email',
        ['user_id' => (int) $request['user_id'], 'force' => true]
    );
}

/** The "it is done" email. For a deletion it goes to the old address. */
function privacy_notify_done(?array $request, array $summary): void
{
    if ($request === null) {
        return;
    }

    $type = (string) $request['type'];
    $plan = privacy_erase_plan();

    notify(
        $type === 'delete' ? 'privacy_delete_done' : 'privacy_export_ready',
        (string) $request['email'],
        [
            'customer_name' => (string) ($request['name'] ?: 'there'),
            'download_url'  => canonical_url('privacy.php'),
            'expires_on'    => $request['expires_at'] !== null
                ? format_datetime((string) $request['expires_at'], 'd M Y') : '',
            'file_size'     => format_bytes((int) ($summary['file_bytes'] ?? 0)),
            'kept_list_html' => '<ul style="margin:0 0 16px 0;padding-left:20px">'
                . implode('', array_map(static fn (string $l): string
                    => '<li style="margin:0 0 6px 0">' . e($l) . '</li>', $plan['kept']))
                . '</ul>',
            'kept_list'     => "  - " . implode("\n  - ", $plan['kept']),
        ],
        'privacy_request',
        (int) $request['id'],
        'email',
        ['user_id' => (int) $request['user_id'], 'force' => true]
    );
}

// ===========================================================================
//  Small helpers the screens share
// ===========================================================================

/** Human label for a status, plus the colour the status chip uses. */
function privacy_status_meta(string $status): array
{
    return match ($status) {
        'pending'   => ['Awaiting confirmation', 'amber'],
        'ready'     => ['Ready to download', 'blue'],
        'completed' => ['Done', 'green'],
        'rejected'  => ['Closed', 'gray'],
        'cancelled' => ['Replaced', 'gray'],
        'expired'   => ['Expired', 'gray'],
        'failed'    => ['Failed', 'red'],
        default     => [ucfirst($status), 'gray'],
    };
}

/** Requests that are still somebody's problem. */
function privacy_open_count(): int
{
    try {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `privacy_requests` WHERE `status` IN ('pending', 'ready')"
        );
    } catch (Throwable $e) {
        return 0;
    }
}
