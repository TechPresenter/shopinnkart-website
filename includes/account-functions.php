<?php
/**
 * ShopInnKart - Customer notifications and preferences.
 *
 * `notification_queue` (see notifications.php) is outbound delivery — email,
 * SMS. This file is the in-app feed the customer reads under the bell icon,
 * plus the preference store behind Account > Preferences.
 */

declare(strict_types=1);

// ===========================================================================
//  NOTIFICATIONS
// ===========================================================================

/** Icon and tone per notification type, so the feed reads at a glance. */
function notification_types(): array
{
    return [
        'order'     => ['icon' => 'bag',      'tone' => 'blue',   'label' => 'Orders'],
        'shipping'  => ['icon' => 'truck',    'tone' => 'cyan',   'label' => 'Shipping'],
        'delivery'  => ['icon' => 'package',  'tone' => 'green',  'label' => 'Delivery'],
        'promotion' => ['icon' => 'percent',  'tone' => 'orange', 'label' => 'Offers'],
        'wishlist'  => ['icon' => 'heart',    'tone' => 'red',    'label' => 'Wishlist'],
        'product'   => ['icon' => 'tag',      'tone' => 'violet', 'label' => 'Products'],
        'account'   => ['icon' => 'user',     'tone' => 'navy',   'label' => 'Account'],
        'system'    => ['icon' => 'bell',     'tone' => 'gray',   'label' => 'Updates'],
    ];
}

/**
 * Record a notification for a customer.
 *
 * Real events only — an order moving, a price dropping, a wishlist item coming
 * back into stock. Nothing here invents activity to make the feed look busy.
 *
 * @return int|null The new id, or null when it could not be written.
 */
function notify_user(
    int $userId,
    string $type,
    string $title,
    ?string $message = null,
    ?string $url = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $image = null
): ?int {
    if ($userId <= 0 || trim($title) === '') {
        return null;
    }
    if (!array_key_exists($type, notification_types())) {
        $type = 'system';
    }

    try {
        // Collapse a repeat of the same event on the same object: an order that
        // bounces between statuses should update one row, not stack five.
        if ($referenceType !== null && $referenceId !== null) {
            $existing = Database::fetch(
                'SELECT `id` FROM `user_notifications`
                 WHERE `user_id` = :uid AND `type` = :type
                   AND `reference_type` = :rtype AND `reference_id` = :rid
                   AND `title` = :title
                 LIMIT 1',
                ['uid' => $userId, 'type' => $type, 'rtype' => $referenceType, 'rid' => $referenceId, 'title' => $title]
            );
            if ($existing !== null) {
                Database::update('user_notifications', [
                    'message'    => $message,
                    'url'        => $url,
                    'image'      => $image,
                    'is_read'    => 0,
                    'read_at'    => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ], '`id` = :id', ['id' => (int) $existing['id']]);
                return (int) $existing['id'];
            }
        }

        return Database::insert('user_notifications', [
            'user_id'        => $userId,
            'type'           => $type,
            'title'          => mb_substr($title, 0, 200),
            'message'        => $message !== null ? mb_substr($message, 0, 500) : null,
            'icon'           => notification_types()[$type]['icon'] ?? 'bell',
            'url'            => $url !== null ? mb_substr($url, 0, 255) : null,
            'image'          => $image,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'notify_user failed: ' . $e->getMessage());
        return null;
    }
}

/** Unread count for the header badge. */
function unread_notification_count(?int $userId = null): int
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return 0;
    }

    try {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM `user_notifications` WHERE `user_id` = :uid AND `is_read` = 0',
            ['uid' => $userId]
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The customer's feed, newest first, decorated for rendering.
 *
 * @param string $filter '' for everything, or a notification type key.
 */
function user_notifications(?int $userId = null, int $limit = 20, int $offset = 0, string $filter = ''): array
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return [];
    }

    $where = '`user_id` = :uid';
    $params = ['uid' => $userId];

    if ($filter !== '' && array_key_exists($filter, notification_types())) {
        $where .= ' AND `type` = :type';
        $params['type'] = $filter;
    } elseif ($filter === 'unread') {
        $where .= ' AND `is_read` = 0';
    }

    try {
        $rows = Database::fetchAll(
            'SELECT * FROM `user_notifications` WHERE ' . $where
            . ' ORDER BY `created_at` DESC, `id` DESC'
            . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    } catch (Throwable $e) {
        return [];
    }

    $types = notification_types();
    foreach ($rows as &$row) {
        $meta = $types[$row['type']] ?? $types['system'];
        $row['icon']      = $row['icon'] ?: $meta['icon'];
        $row['tone']      = $meta['tone'];
        $row['type_label'] = $meta['label'];
        $row['ago']       = time_ago($row['created_at']);
        $row['href']      = !empty($row['url']) ? url($row['url']) : null;
        $row['image_url'] = !empty($row['image']) ? img_url($row['image']) : null;
    }
    unset($row);

    return $rows;
}

function notification_total(?int $userId = null, string $filter = ''): int
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return 0;
    }

    $where = '`user_id` = :uid';
    $params = ['uid' => $userId];
    if ($filter !== '' && array_key_exists($filter, notification_types())) {
        $where .= ' AND `type` = :type';
        $params['type'] = $filter;
    } elseif ($filter === 'unread') {
        $where .= ' AND `is_read` = 0';
    }

    try {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM `user_notifications` WHERE ' . $where, $params);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * How many notifications the customer holds of each type.
 *
 * One GROUP BY rather than notification_total() once per type — the filter bar
 * on notifications.php needs all eight numbers to decide which tabs to offer.
 *
 * @return array<string,int> type key => count, types with none omitted
 */
function notification_type_counts(?int $userId = null): array
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return [];
    }

    try {
        return array_map('intval', Database::fetchPairs(
            'SELECT `type`, COUNT(*) FROM `user_notifications` WHERE `user_id` = :uid GROUP BY `type`',
            ['uid' => $userId]
        ));
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark one notification read. Ownership is enforced in the WHERE clause. */
function mark_notification_read(int $id, ?int $userId = null): bool
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return false;
    }

    return Database::update(
        'user_notifications',
        ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
        '`id` = :id AND `user_id` = :uid AND `is_read` = 0',
        ['id' => $id, 'uid' => $userId]
    ) > 0;
}

function mark_all_notifications_read(?int $userId = null): int
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return 0;
    }

    return Database::update(
        'user_notifications',
        ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
        '`user_id` = :uid AND `is_read` = 0',
        ['uid' => $userId]
    );
}

function delete_notification(int $id, ?int $userId = null): bool
{
    $userId = $userId ?? current_user_id();
    if ($userId === null) {
        return false;
    }
    return Database::delete('user_notifications', '`id` = :id AND `user_id` = :uid', ['id' => $id, 'uid' => $userId]) > 0;
}

/**
 * Raise the in-app notification that goes with an order status change.
 * Called from update_order_status() alongside the outbound email.
 */
function notify_user_order_status(?array $order, string $status): void
{
    if ($order === null || empty($order['user_id'])) {
        return;
    }

    $number = (string) $order['order_number'];
    $url = 'order-details.php?order=' . urlencode($number);

    $copy = [
        ORDER_STATUS_CONFIRMED        => ['order',    'Order confirmed',    'We have your order ' . $number . ' and it is being prepared.'],
        ORDER_STATUS_PROCESSING       => ['order',    'Order being packed', 'Order ' . $number . ' is being picked and packed.'],
        ORDER_STATUS_SHIPPED          => ['shipping', 'Order shipped',      'Order ' . $number . ' has left our warehouse.'],
        ORDER_STATUS_OUT_FOR_DELIVERY => ['shipping', 'Out for delivery',   'Order ' . $number . ' arrives today.'],
        ORDER_STATUS_DELIVERED        => ['delivery', 'Order delivered',    'Order ' . $number . ' has been delivered. Tell us how it went.'],
        ORDER_STATUS_CANCELLED        => ['order',    'Order cancelled',    'Order ' . $number . ' was cancelled.'],
        ORDER_STATUS_RETURNED         => ['order',    'Return received',    'We have received the return for order ' . $number . '.'],
        ORDER_STATUS_REFUNDED         => ['order',    'Refund issued',      'The refund for order ' . $number . ' is on its way to you.'],
    ];

    if (!isset($copy[$status])) {
        return;
    }

    [$type, $title, $message] = $copy[$status];
    notify_user((int) $order['user_id'], $type, $title, $message, $url, 'order', (int) $order['id']);
}

// ===========================================================================
//  PREFERENCES
// ===========================================================================

/**
 * Every preference the storefront understands, with its options and default.
 * Adding one here is all it takes for it to appear on the preferences screen
 * and be persisted — no migration, no template edit.
 */
function preference_schema(): array
{
    $languages = [];
    try {
        foreach (Database::fetchAll("SELECT `code`, `name` FROM `languages` WHERE `status` = 'active' ORDER BY `sort_order`") as $row) {
            $languages[$row['code']] = $row['name'];
        }
    } catch (Throwable $e) {
        $languages = [];
    }
    if ($languages === []) {
        $languages = ['en' => 'English'];
    }

    $currencies = [];
    try {
        foreach (Database::fetchAll("SELECT `code`, `name`, `symbol` FROM `currencies` WHERE `status` = 'active' ORDER BY `is_default` DESC, `code`") as $row) {
            $currencies[$row['code']] = $row['name'] . ' (' . $row['symbol'] . ')';
        }
    } catch (Throwable $e) {
        $currencies = [];
    }
    if ($currencies === []) {
        $currencies = [(string) setting('currency_code', CURRENCY) => 'Indian Rupee (₹)'];
    }

    return [
        'regional' => [
            'label'   => 'Language & region',
            'hint'    => 'How prices and content are presented to you.',
            'icon'    => 'globe',
            'fields'  => [
                'language' => [
                    'label'   => 'Language',
                    'type'    => 'select',
                    'options' => $languages,
                    'default' => (string) array_key_first($languages),
                    'note'    => count($languages) < 2 ? 'More languages are coming soon.' : null,
                ],
                'currency' => [
                    'label'   => 'Currency',
                    'type'    => 'select',
                    'options' => $currencies,
                    'default' => (string) setting('currency_code', CURRENCY),
                    'note'    => count($currencies) < 2 ? 'Additional currencies are coming soon.' : null,
                ],
            ],
        ],
        'notifications' => [
            'label'  => 'Notifications',
            'hint'   => 'What we tell you about, and where.',
            'icon'   => 'bell',
            'fields' => [
                'notify_order_updates' => [
                    'label' => 'Order and delivery updates',
                    'type'  => 'toggle',
                    'default' => '1',
                    'note'  => 'Confirmation, dispatch and delivery. We recommend leaving this on.',
                ],
                'notify_price_drops' => [
                    'label' => 'Price drops on my wishlist',
                    'type'  => 'toggle',
                    'default' => '1',
                ],
                'notify_back_in_stock' => [
                    'label' => 'Back-in-stock alerts',
                    'type'  => 'toggle',
                    'default' => '1',
                ],
                'notify_promotions' => [
                    'label' => 'Deals, flash sales and offers',
                    'type'  => 'toggle',
                    'default' => '0',
                ],
            ],
        ],
        'communication' => [
            'label'  => 'How we contact you',
            'hint'   => 'Order updates are always sent by email — the rest is your call.',
            'icon'   => 'mail',
            'fields' => [
                'channel_email' => ['label' => 'Email',    'type' => 'toggle', 'default' => '1'],
                'channel_sms'   => ['label' => 'SMS',      'type' => 'toggle', 'default' => '0'],
                'channel_whatsapp' => ['label' => 'WhatsApp', 'type' => 'toggle', 'default' => '0'],
            ],
        ],
        'shopping' => [
            'label'  => 'Shopping',
            'hint'   => 'Small things that make browsing fit how you shop.',
            'icon'   => 'bag',
            'fields' => [
                'default_sort' => [
                    'label'   => 'Default product sorting',
                    'type'    => 'select',
                    'options' => PRODUCT_SORT_OPTIONS,
                    'default' => 'popularity',
                ],
                'products_per_page' => [
                    'label'   => 'Products per page',
                    'type'    => 'select',
                    'options' => ['12' => '12', '24' => '24', '36' => '36', '48' => '48'],
                    'default' => (string) setting_int('products_per_page', 12),
                ],
                'save_recently_viewed' => [
                    'label' => 'Remember recently viewed products',
                    'type'  => 'toggle',
                    'default' => '1',
                ],
            ],
        ],
    ];
}

/** Flat map of key => definition, for validation. */
function preference_fields(): array
{
    $fields = [];
    foreach (preference_schema() as $group) {
        foreach ($group['fields'] as $key => $definition) {
            $fields[$key] = $definition;
        }
    }
    return $fields;
}

/** Every preference for a customer, defaults filled in. Memoised per request. */
function user_preferences(?int $userId = null): array
{
    static $cache = [];

    $userId = $userId ?? current_user_id();
    $fields = preference_fields();

    $defaults = [];
    foreach ($fields as $key => $definition) {
        $defaults[$key] = (string) ($definition['default'] ?? '');
    }

    if ($userId === null) {
        // Guests keep preferences for the session only — nothing to persist to.
        return array_merge($defaults, array_intersect_key($_SESSION['_prefs'] ?? [], $defaults));
    }

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    try {
        $stored = Database::fetchPairs(
            'SELECT `pref_key`, `pref_value` FROM `user_preferences` WHERE `user_id` = :uid',
            ['uid' => $userId]
        );
    } catch (Throwable $e) {
        $stored = [];
    }

    return $cache[$userId] = array_merge($defaults, array_intersect_key($stored, $defaults));
}

/** Read one preference. */
function user_preference(string $key, ?int $userId = null, $default = null)
{
    $prefs = user_preferences($userId);
    return $prefs[$key] ?? $default;
}

function user_preference_bool(string $key, ?int $userId = null): bool
{
    return in_array((string) user_preference($key, $userId, '0'), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Persist a set of preferences. Unknown keys and values outside a field's
 * option list are dropped rather than stored.
 *
 * @return array{saved:int, errors:array}
 */
function save_user_preferences(array $input, ?int $userId = null): array
{
    $userId = $userId ?? current_user_id();
    $fields = preference_fields();
    $errors = [];
    $clean = [];

    foreach ($fields as $key => $definition) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = is_scalar($input[$key]) ? (string) $input[$key] : '';

        if (($definition['type'] ?? 'text') === 'toggle') {
            $clean[$key] = in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
            continue;
        }

        if (isset($definition['options'])) {
            if (!array_key_exists($value, $definition['options'])) {
                $errors[$key] = 'Choose one of the available options.';
                continue;
            }
            $clean[$key] = $value;
            continue;
        }

        $clean[$key] = mb_substr($value, 0, 255);
    }

    if ($errors !== []) {
        return ['saved' => 0, 'errors' => $errors];
    }

    if ($userId === null) {
        $_SESSION['_prefs'] = array_merge($_SESSION['_prefs'] ?? [], $clean);
        return ['saved' => count($clean), 'errors' => []];
    }

    try {
        Database::transaction(static function () use ($clean, $userId) {
            foreach ($clean as $key => $value) {
                Database::query(
                    'INSERT INTO `user_preferences` (`user_id`, `pref_key`, `pref_value`)
                     VALUES (:uid, :k, :v)
                     ON DUPLICATE KEY UPDATE `pref_value` = VALUES(`pref_value`)',
                    ['uid' => $userId, 'k' => $key, 'v' => $value]
                );
            }
        });
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'save_user_preferences failed: ' . $e->getMessage());
        return ['saved' => 0, 'errors' => ['_' => 'Could not save your preferences. Please try again.']];
    }

    return ['saved' => count($clean), 'errors' => []];
}

/**
 * The account menu, shared by the header dropdown and the account sidebar so
 * the two can never list different things.
 */
function account_menu(): array
{
    return [
        [
            'label' => 'Shopping',
            'items' => [
                ['label' => 'My Orders',        'url' => 'orders.php',           'icon' => 'bag'],
                ['label' => 'Purchase History', 'url' => 'purchase-history.php', 'icon' => 'clock'],
                ['label' => 'Wishlist',         'url' => 'wishlist.php',         'icon' => 'heart',   'badge' => 'wishlist'],
                ['label' => 'Cart',             'url' => 'cart.php',             'icon' => 'cart',    'badge' => 'cart'],
                ['label' => 'Compare',          'url' => 'compare.php',          'icon' => 'compare', 'badge' => 'compare'],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                ['label' => 'My Account',    'url' => 'account.php',       'icon' => 'grid'],
                ['label' => 'Profile',       'url' => 'profile.php',       'icon' => 'user'],
                ['label' => 'Notifications', 'url' => 'notifications.php', 'icon' => 'bell', 'badge' => 'notifications'],
                ['label' => 'Saved Addresses', 'url' => 'addresses.php',   'icon' => 'location'],
                ['label' => 'My Reviews',    'url' => 'my-reviews.php',    'icon' => 'star'],
            ],
        ],
        [
            'label' => 'Settings',
            'items' => [
                ['label' => 'Preferences',     'url' => 'preferences.php',     'icon' => 'settings'],
                ['label' => 'Change Password', 'url' => 'change-password.php', 'icon' => 'lock'],
                // Hidden while the owner has customer 2FA switched off, the
                // same way the wishlist entry disappears with its feature.
                ['label' => 'Two-Step Sign In', 'url' => 'account-security.php', 'icon' => 'shield'],
            ],
        ],
    ];
}

/** Live counts for the account menu badges. */
function account_menu_badges(): array
{
    return [
        'wishlist'      => wishlist_count(),
        'cart'          => cart_count(),
        'compare'       => compare_count(),
        'notifications' => unread_notification_count(),
    ];
}

// ===========================================================================
//  ORDER SCOPES AND FILTERING
//
//  orders.php shows what is still on its way; purchase-history.php shows the
//  complete record with date and status filtering and a per-item view. Both
//  read through the helpers below so the two screens can never disagree about
//  what "active" means or apply a filter differently.
// ===========================================================================

/** Statuses an order passes through while it is still on its way to you. */
function account_active_statuses(): array
{
    return [
        ORDER_STATUS_PENDING,
        ORDER_STATUS_CONFIRMED,
        ORDER_STATUS_PROCESSING,
        ORDER_STATUS_PACKED,
        ORDER_STATUS_SHIPPED,
        ORDER_STATUS_OUT_FOR_DELIVERY,
    ];
}

/** Statuses that close an order — it is finished, one way or the other. */
function account_closed_statuses(): array
{
    return [
        ORDER_STATUS_DELIVERED,
        ORDER_STATUS_CANCELLED,
        ORDER_STATUS_RETURNED,
        ORDER_STATUS_REFUNDED,
    ];
}

/** A Y-m-d string the calendar actually contains, or '' — never a raw input. */
function account_valid_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
}

/**
 * Normalise the query string into the one filter shape every account order
 * screen passes around. Anything unrecognised collapses to the default rather
 * than reaching SQL.
 */
function account_order_filters(array $input = []): array
{
    $scope = (string) ($input['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'active', 'closed'], true)) {
        $scope = 'all';
    }

    $status = (string) ($input['status'] ?? '');
    if ($status !== '' && !array_key_exists($status, ORDER_STATUSES)) {
        $status = '';
    }

    $from = account_valid_date((string) ($input['from'] ?? ''));
    $to   = account_valid_date((string) ($input['to'] ?? ''));
    // A backwards range returns nothing at all, which reads as a bug rather
    // than as a filter, so swap the ends instead.
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $perPage = (int) ($input['per_page'] ?? 10);

    return [
        'scope'    => $scope,
        'status'   => $status,
        'from'     => $from,
        'to'       => $to,
        'q'        => trim(mb_substr((string) ($input['q'] ?? ''), 0, 80)),
        'page'     => max(1, (int) ($input['page'] ?? 1)),
        'per_page' => max(1, min(50, $perPage)),
    ];
}

/**
 * WHERE fragment + bound params for a customer's orders under these filters.
 *
 * @param bool $withStatus false builds the same clause without the status leg,
 *                         which is what the filter tabs count against.
 * @return array{0:string,1:array}
 */
function account_order_where(int $userId, array $filters, bool $withStatus = true): array
{
    $where  = 'o.`user_id` = :uid';
    $params = ['uid' => $userId];

    if ($filters['scope'] !== 'all') {
        $statuses = $filters['scope'] === 'active' ? account_active_statuses() : account_closed_statuses();
        [$placeholders, $scopeParams] = Database::inPlaceholders($statuses, 'sc');
        $where .= ' AND o.`status` IN (' . $placeholders . ')';
        $params = array_merge($params, $scopeParams);
    }

    if ($withStatus && $filters['status'] !== '') {
        $where .= ' AND o.`status` = :status';
        $params['status'] = $filters['status'];
    }

    if ($filters['from'] !== '') {
        $where .= ' AND o.`created_at` >= :from';
        $params['from'] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where .= ' AND o.`created_at` <= :to';
        $params['to'] = $filters['to'] . ' 23:59:59';
    }

    if ($filters['q'] !== '') {
        // Order number or any product name in the order — the two things a
        // customer actually remembers about a past purchase.
        //
        // Two placeholders for one value: PDO runs with emulation off, and a
        // named placeholder bound twice in the same statement is an error.
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['q']) . '%';
        $where .= ' AND (o.`order_number` LIKE :qnum OR EXISTS ('
            . 'SELECT 1 FROM `order_items` oi WHERE oi.`order_id` = o.`id` AND oi.`product_name` LIKE :qname))';
        $params['qnum']  = $like;
        $params['qname'] = $like;
    }

    return [$where, $params];
}

/**
 * A page of the customer's orders, decorated for the order card.
 *
 * @return array{items:array, pagination:array}
 */
function account_orders(int $userId, array $filters): array
{
    [$where, $params] = account_order_where($userId, $filters);

    $total = (int) Database::fetchColumn('SELECT COUNT(*) FROM `orders` o WHERE ' . $where, $params);
    $pagination = paginate($total, (int) $filters['per_page'], (int) $filters['page']);

    $orders = Database::fetchAll(
        'SELECT o.* FROM `orders` o WHERE ' . $where
        . ' ORDER BY o.`created_at` DESC, o.`id` DESC'
        . ' LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        $params
    );

    foreach ($orders as &$order) {
        $order['items']          = get_order_items((int) $order['id']);
        $order['item_count']     = count($order['items']);
        $order['unit_count']     = array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $order['items']));
        $order['total_display']  = money((float) $order['total_amount']);
        $order['can_cancel']     = can_cancel_order($order);
        $order['has_invoice']    = order_has_invoice($order);
        $order['is_active']      = in_array((string) $order['status'], account_active_statuses(), true);
    }
    unset($order);

    return ['items' => $orders, 'pagination' => $pagination];
}

/**
 * The same filters resolved to individual purchased lines rather than orders —
 * the per-item view of the purchase history.
 *
 * @return array{items:array, pagination:array}
 */
function account_order_lines(int $userId, array $filters): array
{
    [$where, $params] = account_order_where($userId, $filters);

    $total = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `order_items` oi JOIN `orders` o ON o.`id` = oi.`order_id` WHERE ' . $where,
        $params
    );
    $pagination = paginate($total, (int) $filters['per_page'], (int) $filters['page']);

    // order_items is a historical snapshot, so it cannot answer "can they buy
    // this again?". The current product and variant rows are joined in for
    // exactly that: a Buy-again control has to reflect stock as it is now, not
    // as it was on the day of the order.
    $rows = Database::fetchAll(
        'SELECT oi.*, o.`order_number`, o.`status`, o.`created_at` AS ordered_at, o.`delivered_at`,
                o.`id` AS order_id, p.`slug` AS product_slug,
                p.`stock` AS current_stock, p.`status` AS product_status,
                pv.`stock` AS variant_stock, pv.`status` AS variant_status
         FROM `order_items` oi
         JOIN `orders` o ON o.`id` = oi.`order_id`
         LEFT JOIN `products` p ON p.`id` = oi.`product_id`
         LEFT JOIN `product_variants` pv ON pv.`id` = oi.`variant_id`
         WHERE ' . $where
        . ' ORDER BY o.`created_at` DESC, o.`id` DESC, oi.`id` ASC'
        . ' LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        $params
    );

    foreach ($rows as &$row) {
        $row['image_url']        = img_url($row['product_image']);
        $row['url']              = $row['product_slug'] ? product_url((string) $row['product_slug']) : null;
        $row['price_display']    = money((float) $row['price']);
        $row['subtotal_display'] = money((float) $row['subtotal']);
        $row['order_url']        = url('order-details.php?id=' . (int) $row['order_id']);

        // Buyable again only if the product still exists, is still active, and
        // the exact thing they bought still has stock. A line whose variant has
        // since been retired is not re-orderable even when the parent is.
        $liveProduct = $row['product_slug'] !== null && $row['product_status'] === 'active';
        $hasStock    = $row['variant_id'] !== null
            ? ($row['variant_status'] === 'active' && (int) $row['variant_stock'] > 0)
            : (int) $row['current_stock'] > 0;

        $row['can_reorder'] = $liveProduct && $hasStock;
        $row['in_stock']    = $row['can_reorder'];
        $row['name']        = (string) $row['product_name'];
        $row['slug']        = (string) ($row['product_slug'] ?? '');
    }
    unset($row);

    return ['items' => $rows, 'pagination' => $pagination];
}

/**
 * How many orders sit behind each status tab, under everything except the
 * status filter itself — a tab that would show nothing is never offered.
 *
 * @return array<string,int>
 */
function account_order_status_counts(int $userId, array $filters): array
{
    [$where, $params] = account_order_where($userId, $filters, false);

    $counts = Database::fetchPairs(
        'SELECT o.`status`, COUNT(*) FROM `orders` o WHERE ' . $where . ' GROUP BY o.`status`',
        $params
    );

    return array_map('intval', $counts);
}

/**
 * Totals for the filtered set: how many orders, how many units, how much spent.
 *
 * Money that came back — cancelled, returned, refunded — is not spending, so it
 * is excluded from the amount while still being counted as an order.
 *
 * @return array{orders:int, units:int, spent:float}
 */
function account_order_summary(int $userId, array $filters): array
{
    [$where, $params] = account_order_where($userId, $filters);
    [$refundedList, $refundedParams] = Database::inPlaceholders(account_refunded_statuses(), 'rf');

    // Two statements rather than a correlated subquery: PDO runs with emulation
    // off, and a named placeholder cannot appear twice in one prepared query.
    $row = Database::fetch(
        'SELECT COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN o.`status` IN (' . $refundedList . ') THEN 0 ELSE o.`total_amount` END), 0) AS spent
         FROM `orders` o WHERE ' . $where,
        array_merge($params, $refundedParams)
    );

    $units = (int) Database::fetchColumn(
        'SELECT COALESCE(SUM(oi.`quantity`), 0) FROM `order_items` oi
         JOIN `orders` o ON o.`id` = oi.`order_id` WHERE ' . $where,
        $params
    );

    return [
        'orders' => (int) ($row['orders'] ?? 0),
        'units'  => $units,
        'spent'  => (float) ($row['spent'] ?? 0),
    ];
}

/** Statuses where the money did not stay with us. */
function account_refunded_statuses(): array
{
    return [ORDER_STATUS_CANCELLED, ORDER_STATUS_RETURNED, ORDER_STATUS_REFUNDED];
}

/**
 * Is there an invoice to link to?
 *
 * invoice.php dates the document from `confirmed_at`, so an order that never
 * got confirmed has nothing to bill — linking one anyway hands the customer a
 * document for a purchase that was never accepted.
 */
function order_has_invoice(array $order): bool
{
    $status = (string) $order['status'];

    // An issued invoice is the authoritative answer whatever the status says.
    // A cancelled order still keeps the invoice it was given: the document was
    // real, and the cancellation is recorded against it rather than erasing it.
    if (!empty($order['id']) && get_invoice_for_order((int) $order['id']) !== null) {
        return true;
    }

    // A cancelled order was never fulfilled, so there is no sale to invoice —
    // even when it had been confirmed before being pulled back.
    if (in_array($status, [ORDER_STATUS_PENDING, ORDER_STATUS_CANCELLED], true)) {
        return false;
    }

    if (!empty($order['confirmed_at'])) {
        return true;
    }

    // A status past confirmation counts too: older rows predate confirmed_at
    // being written, and they unquestionably represent a real sale.
    return in_array($status, [
        ORDER_STATUS_PROCESSING,
        ORDER_STATUS_PACKED,
        ORDER_STATUS_SHIPPED,
        ORDER_STATUS_OUT_FOR_DELIVERY,
        ORDER_STATUS_DELIVERED,
        ORDER_STATUS_RETURNED,
        ORDER_STATUS_REFUNDED,
    ], true);
}
