<?php
/**
 * ShopInnKart Admin - Customer record.
 *
 * Everything support needs on one screen: who they are, what they bought,
 * where they ship, what they saved, what they said, and how they signed in.
 *
 * Four of those lists grow with the customer, and all four used to be SELECTed
 * whole: a fixture account with 3,631 orders rendered 3,632 <tr> and 4.4 MB of
 * HTML before anything reached the browser. Every list is paged now, each on
 * its own ?op / ?ap / ?wp / ?rp key so moving one does not reset the others,
 * and every figure the page prints - the stat cards, the "N orders" line, the
 * card counts - is a COUNT(*) rather than count() over rows fetched to be
 * thrown away. Sign-ins stay at their fixed 15 and link out to Logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('customers.view');

require_once __DIR__ . '/_filters.php';

$customerId = (int) input_int('id');

if ($customerId <= 0) {
    flash('error', 'No customer was selected.');
    redirect(admin_url('customers/'));
}

$customer = Database::fetch(
    'SELECT `id`, `first_name`, `last_name`, `email`, `phone`, `avatar`, `gender`, `date_of_birth`,
            `status`, `email_verified_at`, `failed_logins`, `locked_until`,
            `last_login_at`, `last_login_ip`, `created_at`, `updated_at`
     FROM `users` WHERE `id` = :id LIMIT 1',
    ['id' => $customerId]
);

if ($customer === null) {
    flash('error', 'That customer no longer exists.');
    redirect(admin_url('customers/'));
}

$customerName = customer_full_name($customer);

// ---------------------------------------------------------------------------
// Account actions. POST + CSRF + customers.edit, all enforced in one call.
// ---------------------------------------------------------------------------
if (is_post()) {
    admin_require_action('customers.edit');

    $action   = (string) input('action', '');
    $backUrl  = admin_url('customers/view.php?id=' . $customerId);

    if ($action === 'block' || $action === 'activate') {
        $newStatus = $action === 'block' ? 'blocked' : 'active';

        if ($customer['status'] === $newStatus) {
            flash('info', 'That account is already ' . $newStatus . '.');
            redirect($backUrl);
        }

        $update = ['status' => $newStatus];
        if ($newStatus === 'active') {
            // Reactivating clears a lockout, otherwise the customer is still
            // shut out by the failed-login counter they never see.
            $update['failed_logins'] = 0;
            $update['locked_until']  = null;
        }
        Database::update('users', $update, '`id` = :id', ['id' => $customerId]);

        log_activity(
            'customer.' . ($newStatus === 'blocked' ? 'blocked' : 'activated'),
            'user',
            $customerId,
            ($newStatus === 'blocked' ? 'Blocked' : 'Activated') . ' customer "' . $customerName . '"'
        );
        admin_after_write();

        // current_user() re-reads status on every request, so a block takes
        // effect on the customer's very next page load.
        flash('success', $newStatus === 'blocked'
            ? $customerName . ' is blocked and cannot sign in.'
            : $customerName . ' is active again.');
        redirect($backUrl);
    }

    if ($action === 'reset_password') {
        if ($customer['status'] !== 'active') {
            flash('warning', 'Activate the account first - a blocked customer cannot sign in with a new password.');
            redirect($backUrl);
        }

        $token = create_password_reset((string) $customer['email'], 'customer');
        $resetUrl = url('reset-password.php')
            . '?token=' . urlencode($token)
            . '&email=' . urlencode((string) $customer['email']);

        notify_password_reset((string) $customer['email'], (string) $customer['first_name'], $resetUrl);

        log_activity('customer.password_reset_sent', 'user', $customerId,
            'Sent a password reset link to ' . $customer['email']);
        admin_after_write();

        flash('success', 'A password reset link has been queued for ' . $customer['email'] . '. It expires in 1 hour.');
        redirect($backUrl);
    }

    flash('error', 'Unknown action.');
    redirect($backUrl);
}

// ---------------------------------------------------------------------------
// Lifetime figures. Voided orders still belong in the history table, but they
// must not inflate spend, so the sum is filtered and the count is not.
// ---------------------------------------------------------------------------
$stats = Database::fetch(
    'SELECT COUNT(*) AS orders_count,
            COALESCE(SUM(CASE WHEN `status` IN (' . CUSTOMER_SPEND_ORDER_STATUSES . ')
                              THEN `total_amount` ELSE 0 END), 0) AS lifetime_spend,
            SUM(CASE WHEN `status` IN (' . CUSTOMER_SPEND_ORDER_STATUSES . ') THEN 1 ELSE 0 END) AS paid_orders,
            MAX(`created_at`) AS last_order_at
     FROM `orders` WHERE `user_id` = :id',
    ['id' => $customerId]
) ?? ['orders_count' => 0, 'lifetime_spend' => 0, 'paid_orders' => 0, 'last_order_at' => null];

$orderCount    = (int) $stats['orders_count'];
$lifetimeSpend = (float) $stats['lifetime_spend'];
$paidOrders    = (int) $stats['paid_orders'];
$averageOrder  = $paidOrders > 0 ? $lifetimeSpend / $paidOrders : 0.0;

// ---------------------------------------------------------------------------
// How many of each, asked of SQL.
//
// Every list below used to be SELECTed whole and then count()ed in PHP - so
// printing "3,631 orders" cost 3,631 rows, and a customer with years of
// history rendered megabytes of <tr> that nobody scrolls to. COUNT(*) is the
// figure; the lists themselves are paged.
//
// The wishlist count repeats the render query's INNER JOIN on products on
// purpose: an item whose product was deleted is not rendered, so counting
// wishlist_items alone would print a number the table cannot show.
// ---------------------------------------------------------------------------
// One placeholder per subquery: emulated prepares are off, so PDO will not let
// the same named parameter be bound three times.
$counts = Database::fetch(
    'SELECT (SELECT COUNT(*) FROM `user_addresses` WHERE `user_id` = :id_a) AS addresses,
            (SELECT COUNT(*) FROM `reviews` WHERE `user_id` = :id_r)        AS reviews,
            (SELECT COUNT(*)
               FROM `wishlists` w
               INNER JOIN `wishlist_items` wi ON wi.`wishlist_id` = w.`id`
               INNER JOIN `products` p ON p.`id` = wi.`product_id`
              WHERE w.`user_id` = :id_w)                                    AS wishlist',
    ['id_a' => $customerId, 'id_r' => $customerId, 'id_w' => $customerId]
) ?? ['addresses' => 0, 'reviews' => 0, 'wishlist' => 0];

$addressCount  = (int) $counts['addresses'];
$reviewCount   = (int) $counts['reviews'];
$wishlistCount = (int) $counts['wishlist'];

// ---------------------------------------------------------------------------
// Four lists, four pagers.
//
// One shared ?page= would move all four at once, so each list carries its own
// key. Addresses are cards rather than rows, so they get a smaller page.
// PDO cannot bind LIMIT/OFFSET with emulated prepares off - the same cast the
// rest of the admin uses.
// ---------------------------------------------------------------------------
$addressesPerPage = 6;

$pagers = [];
$slice  = static function (string $key, int $total, int $perPage) use (&$pagers): string {
    $pagers[$key] = paginate($total, $perPage, max(1, (int) ($_GET[$key] ?? 1)));
    return ' LIMIT ' . (int) $pagers[$key]['per_page'] . ' OFFSET ' . (int) $pagers[$key]['offset'];
};

$orderLimit    = $slice('op', $orderCount, ADMIN_PER_PAGE);
$addressLimit  = $slice('ap', $addressCount, $addressesPerPage);
$wishlistLimit = $slice('wp', $wishlistCount, ADMIN_PER_PAGE);
$reviewLimit   = $slice('rp', $reviewCount, ADMIN_PER_PAGE);

$orders = Database::fetchAll(
    'SELECT o.`id`, o.`order_number`, o.`status`, o.`payment_status`, o.`payment_method`,
            o.`total_amount`, o.`created_at`,
            (SELECT COUNT(*) FROM `order_items` oi WHERE oi.`order_id` = o.`id`) AS item_count
     FROM `orders` o
     WHERE o.`user_id` = :id
     ORDER BY o.`id` DESC' . $orderLimit,
    ['id' => $customerId]
);

$addresses = Database::fetchAll(
    'SELECT * FROM `user_addresses` WHERE `user_id` = :id
     ORDER BY `is_default` DESC, `id` DESC' . $addressLimit,
    ['id' => $customerId]
);

$wishlist = Database::fetchAll(
    'SELECT p.`id`, p.`name`, p.`slug`, p.`main_image`, p.`price`, p.`sale_price`,
            p.`stock`, p.`low_stock_threshold`, p.`status` AS product_status, wi.`created_at` AS added_at
     FROM `wishlists` w
     INNER JOIN `wishlist_items` wi ON wi.`wishlist_id` = w.`id`
     INNER JOIN `products` p ON p.`id` = wi.`product_id`
     WHERE w.`user_id` = :id
     ORDER BY wi.`created_at` DESC, wi.`id` DESC' . $wishlistLimit,
    ['id' => $customerId]
);

$reviews = Database::fetchAll(
    'SELECT r.`id`, r.`rating`, r.`title`, r.`comment`, r.`status`, r.`verified_purchase`,
            r.`created_at`, r.`product_id`, p.`name` AS product_name
     FROM `reviews` r
     LEFT JOIN `products` p ON p.`id` = r.`product_id`
     WHERE r.`user_id` = :id
     ORDER BY r.`id` DESC' . $reviewLimit,
    ['id' => $customerId]
);

// Attempts made before the account existed, or with the email typed by hand,
// carry no user_id - match on the address too so failures are not hidden.
//
// This one was already bounded at 15, and stays bounded: the sidebar is not
// where somebody reads a year of sign-ins. It now says how many there are and
// links to Logs > Login history, which is the screen that pages them.
$loginCount = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM `login_history`
      WHERE `user_type` = 'customer' AND (`user_id` = :id OR `identifier` = :email)",
    ['id' => $customerId, 'email' => $customer['email']]
);

$logins = Database::fetchAll(
    "SELECT `status`, `reason`, `ip_address`, `user_agent`, `created_at`
     FROM `login_history`
     WHERE `user_type` = 'customer' AND (`user_id` = :id OR `identifier` = :email)
     ORDER BY `id` DESC LIMIT 15",
    ['id' => $customerId, 'email' => $customer['email']]
);

$pageTitle    = $customerName;
$pageSubtitle = $customer['email'] . ' · Customer #' . $customerId;
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Customers', 'url' => admin_url('customers/')],
    ['label' => $customerName],
];

$pageActions = '<a class="ad-btn" href="' . e(admin_url('customers/')) . '">'
    . icon('arrow-left', 'w-4 h-4') . ' Back to list</a>';
if (admin_can('customers.edit')) {
    $pageActions .= '<a class="ad-btn ad-btn--primary" href="'
        . e(admin_url('customers/edit.php?id=' . $customerId)) . '">'
        . icon('edit', 'w-4 h-4') . ' Edit</a>';
}

/**
 * Pager for one of this page's four lists.
 *
 * admin_pagination() always writes ?page=, which is right for a screen with a
 * single table and wrong here - four lists sharing one key would all jump
 * together. This renders the same .sik-pager markup against its own key and
 * drops the reader back at the list it belongs to instead of the top of a very
 * tall page. Every other parameter survives, so the customer id is kept.
 */
$listPager = static function (string $key, string $anchor) use ($pagers): string {
    $pagination = $pagers[$key];
    if ($pagination['last'] <= 1) {
        return '';
    }

    $link = static function (int $page) use ($key, $anchor): string {
        $query = $_GET;
        $query[$key] = $page;
        return e(admin_url('customers/view.php') . '?' . http_build_query($query) . '#' . $anchor);
    };

    $html = '<nav class="sik-pager" aria-label="' . e_attr(ucfirst($anchor)) . ' pagination">';
    $html .= $pagination['current'] > 1
        ? '<a class="sik-pager__link" href="' . $link($pagination['current'] - 1) . '" rel="prev">Prev</a>'
        : '<span class="sik-pager__link is-disabled">Prev</span>';

    foreach ($pagination['pages'] as $page) {
        if ($page === '…') {
            $html .= '<span class="sik-pager__gap">…</span>';
            continue;
        }
        $html .= $page === $pagination['current']
            ? '<span class="sik-pager__link is-current" aria-current="page">' . (int) $page . '</span>'
            : '<a class="sik-pager__link" href="' . $link((int) $page) . '">' . (int) $page . '</a>';
    }

    $html .= $pagination['current'] < $pagination['last']
        ? '<a class="sik-pager__link" href="' . $link($pagination['current'] + 1) . '" rel="next">Next</a>'
        : '<span class="sik-pager__link is-disabled">Next</span>';

    return $html . '</nav>';
};

/** "Showing 21-40 of 3,631" - so a paged list still says how big it really is. */
$listRange = static function (string $key, string $singular, string $plural) use ($pagers): string {
    $p = $pagers[$key];
    if ($p['total'] === 0) {
        return 'None yet';
    }
    if ($p['last'] <= 1) {
        return number_format($p['total']) . ' ' . ($p['total'] === 1 ? $singular : $plural);
    }
    return 'Showing ' . number_format($p['from']) . '&ndash;' . number_format($p['to'])
        . ' of ' . number_format($p['total']) . ' ' . $plural;
};

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?php
    // Not "cancelled or returned": an order that is still pending has not
    // earned anything either, so it is excluded from spend and would otherwise
    // be mislabelled here as a cancellation the customer never made.
    ?>
    <?= admin_stat_card('Orders', number_format($orderCount), 'cart', 'blue',
        number_format($orderCount - $paidOrders) . ' not counted as revenue') ?>
    <?= admin_stat_card('Total Spent', money($lifetimeSpend), 'wallet', 'primary',
        'Across ' . number_format($paidOrders) . ' completed order' . ($paidOrders === 1 ? '' : 's')) ?>
    <?= admin_stat_card('Average Order', money($averageOrder), 'percent', 'green',
        $paidOrders > 0 ? 'Per completed order' : 'No completed orders yet') ?>
    <?= admin_stat_card('Last Order',
        $stats['last_order_at'] ? format_date($stats['last_order_at'], 'd M Y') : '—',
        'clock', 'violet',
        $stats['last_order_at'] ? time_ago($stats['last_order_at']) : 'Never ordered') ?>
</div>

<div class="ad-grid ad-grid--sidebar">
    <div>
        <!-- ============================ Orders ============================ -->
        <div class="ad-card" id="orders">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Order history</div>
                    <div class="ad-card__sub"><?= $listRange('op', 'order', 'orders') ?> on this account</div>
                </div>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($orders === []): ?>
                    <?= admin_empty('No orders yet', 'This customer has not placed an order.', null, null, 'cart') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Date</th>
                                    <th class="ad-table__num">Items</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="ad-table__num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>
                                            <?php if (admin_can('orders.view')): ?>
                                                <a class="ad-mono" style="font-weight:700"
                                                   href="<?= e(admin_url('orders/view.php?id=' . (int) $order['id'])) ?>">
                                                    <?= e($order['order_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="ad-mono"><?= e($order['order_number']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="ad-muted"><?= e(format_datetime($order['created_at'], 'd M Y, g:i A')) ?></td>
                                        <td class="ad-table__num"><?= number_format((int) $order['item_count']) ?></td>
                                        <td>
                                            <?= admin_state_badge((string) $order['payment_status']) ?>
                                            <div class="ad-cellflex__meta"><?= e(strtoupper((string) $order['payment_method'])) ?></div>
                                        </td>
                                        <td><?= admin_status_badge((string) $order['status']) ?></td>
                                        <td class="ad-table__num"><strong><?= e(money((float) $order['total_amount'])) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($pager = $listPager('op', 'orders')) !== ''): ?>
                <div class="ad-card__foot"><?= $pager ?></div>
            <?php endif; ?>
        </div>

        <!-- =========================== Addresses ========================== -->
        <div class="ad-card" id="addresses">
            <div class="ad-card__head">
                <div class="ad-card__title">Saved addresses</div>
                <span class="ad-muted" style="font-size:var(--ad-text-xs)"><?= $listRange('ap', 'address', 'addresses') ?></span>
            </div>
            <div class="ad-card__body<?= $addresses === [] ? ' ad-card__body--flush' : '' ?>">
                <?php if ($addresses === []): ?>
                    <?= admin_empty('No saved addresses', 'The customer has not stored a delivery address yet.', null, null, 'location') ?>
                <?php else: ?>
                    <div class="ad-grid ad-grid--2">
                        <?php foreach ($addresses as $address): ?>
                            <div style="border:1px solid var(--ad-border);border-radius:10px;padding:14px;font-size:13px;line-height:1.6">
                                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
                                    <strong><?= e($address['full_name']) ?></strong>
                                    <span class="sik-status sik-status--gray"><?= e(ucfirst((string) $address['address_type'])) ?></span>
                                    <?php if ((int) $address['is_default'] === 1): ?>
                                        <span class="sik-status sik-status--green">Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ad-muted">
                                    <?= e($address['address_line1']) ?><br>
                                    <?php if (!empty($address['address_line2'])): ?>
                                        <?= e($address['address_line2']) ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($address['landmark'])): ?>
                                        Near <?= e($address['landmark']) ?><br>
                                    <?php endif; ?>
                                    <?= e($address['city']) ?>, <?= e($address['state']) ?> <?= e($address['pincode']) ?><br>
                                    <?= e($address['country']) ?>
                                </div>
                                <div style="margin-top:8px">
                                    <?= icon('phone', 'w-3.5 h-3.5') ?>
                                    <span class="ad-mono"><?= e($address['phone']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($pager = $listPager('ap', 'addresses')) !== ''): ?>
                <div class="ad-card__foot"><?= $pager ?></div>
            <?php endif; ?>
        </div>

        <!-- =========================== Wishlist =========================== -->
        <div class="ad-card" id="wishlist">
            <div class="ad-card__head">
                <div class="ad-card__title">Wishlist</div>
                <span class="ad-muted" style="font-size:var(--ad-text-xs)"><?= $listRange('wp', 'product', 'products') ?></span>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($wishlist === []): ?>
                    <?= admin_empty('Wishlist is empty', 'Nothing has been saved for later.', null, null, 'heart') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="ad-table__num">Price</th>
                                    <th>Stock</th>
                                    <th>Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($wishlist as $item): ?>
                                    <?php $price = $item['sale_price'] !== null && (float) $item['sale_price'] > 0
                                        ? (float) $item['sale_price']
                                        : (float) $item['price']; ?>
                                    <tr>
                                        <td>
                                            <div class="ad-cellflex">
                                                <img class="ad-thumb" src="<?= e(img_url($item['main_image'])) ?>" alt="" loading="lazy">
                                                <div style="min-width:0">
                                                    <?php if (admin_can('products.edit')): ?>
                                                        <a class="ad-cellflex__name"
                                                           href="<?= e(admin_url('products/edit.php?id=' . (int) $item['id'])) ?>">
                                                            <?= e(str_limit($item['name'], 60)) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="ad-cellflex__name"><?= e(str_limit($item['name'], 60)) ?></span>
                                                    <?php endif; ?>
                                                    <div class="ad-cellflex__meta">
                                                        <?= e(ucfirst((string) $item['product_status'])) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="ad-table__num"><?= e(money($price)) ?></td>
                                        <td><?= admin_stock_badge((int) $item['stock'], (int) $item['low_stock_threshold']) ?></td>
                                        <td class="ad-muted"><?= e(time_ago($item['added_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($pager = $listPager('wp', 'wishlist')) !== ''): ?>
                <div class="ad-card__foot"><?= $pager ?></div>
            <?php endif; ?>
        </div>

        <!-- ============================ Reviews =========================== -->
        <div class="ad-card" id="reviews">
            <div class="ad-card__head">
                <div class="ad-card__title">Reviews written</div>
                <span class="ad-muted" style="font-size:var(--ad-text-xs)"><?= $listRange('rp', 'review', 'reviews') ?></span>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <?php if ($reviews === []): ?>
                    <?= admin_empty('No reviews', 'This customer has not reviewed a product yet.', null, null, 'star') ?>
                <?php else: ?>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Rating</th>
                                    <th>Review</th>
                                    <th>Status</th>
                                    <th>Written</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td>
                                            <?php if ($review['product_name'] === null): ?>
                                                <span class="ad-muted">Product removed</span>
                                            <?php elseif (admin_can('products.edit')): ?>
                                                <a href="<?= e(admin_url('products/edit.php?id=' . (int) $review['product_id'])) ?>">
                                                    <?= e(str_limit($review['product_name'], 44)) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= e(str_limit($review['product_name'], 44)) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space:nowrap"><?= rating_stars((float) $review['rating']) ?></td>
                                        <td>
                                            <?php if (!empty($review['title'])): ?>
                                                <strong><?= e(str_limit($review['title'], 50)) ?></strong><br>
                                            <?php endif; ?>
                                            <span class="ad-muted"><?= e(str_limit($review['comment'], 90)) ?></span>
                                            <?php if ((int) $review['verified_purchase'] === 1): ?>
                                                <div class="ad-cellflex__meta">Verified purchase</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= admin_state_badge((string) $review['status']) ?></td>
                                        <td class="ad-muted"><?= e(format_date($review['created_at'], 'd M Y')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($pager = $listPager('rp', 'reviews')) !== ''): ?>
                <div class="ad-card__foot"><?= $pager ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================== Sidebar ============================= -->
    <div>
        <div class="ad-card">
            <div class="ad-card__body" style="text-align:center">
                <span class="ad-avatar" style="width:64px;height:64px;font-size:22px;margin-inline:auto">
                    <?= e(initials($customerName)) ?>
                </span>
                <h3 style="font-size:16.5px;font-weight:700;margin-top:12px"><?= e($customerName) ?></h3>
                <div style="margin-top:8px"><?= admin_state_badge((string) $customer['status']) ?></div>
            </div>

            <div class="ad-card__body" style="border-top:1px solid var(--ad-border);display:grid;gap:11px;font-size:13px">
                <div style="display:flex;gap:9px;align-items:flex-start">
                    <span class="ad-muted"><?= icon('mail', 'w-4 h-4') ?></span>
                    <div style="min-width:0;word-break:break-word">
                        <a href="mailto:<?= e($customer['email']) ?>"><?= e($customer['email']) ?></a>
                        <div class="ad-cellflex__meta">
                            <?= $customer['email_verified_at']
                                ? 'Verified ' . e(format_date($customer['email_verified_at'], 'd M Y'))
                                : 'Not verified' ?>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:9px;align-items:flex-start">
                    <span class="ad-muted"><?= icon('phone', 'w-4 h-4') ?></span>
                    <div>
                        <?php if (!empty($customer['phone'])): ?>
                            <span class="ad-mono"><?= e($customer['phone']) ?></span>
                        <?php else: ?>
                            <span class="ad-muted">No phone on file</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;gap:9px;align-items:flex-start">
                    <span class="ad-muted"><?= icon('user', 'w-4 h-4') ?></span>
                    <div>
                        <?= $customer['gender'] !== null ? e(ucfirst((string) $customer['gender'])) : '<span class="ad-muted">Gender not set</span>' ?>
                        <div class="ad-cellflex__meta">
                            <?= $customer['date_of_birth']
                                ? 'Born ' . e(format_date($customer['date_of_birth'], 'd M Y'))
                                : 'Date of birth not set' ?>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:9px;align-items:flex-start">
                    <span class="ad-muted"><?= icon('calendar', 'w-4 h-4') ?></span>
                    <div>
                        Joined <?= e(format_date($customer['created_at'], 'd M Y')) ?>
                        <div class="ad-cellflex__meta"><?= e(time_ago($customer['created_at'])) ?></div>
                    </div>
                </div>

                <div style="display:flex;gap:9px;align-items:flex-start">
                    <span class="ad-muted"><?= icon('clock', 'w-4 h-4') ?></span>
                    <div>
                        <?php if ($customer['last_login_at']): ?>
                            Last signed in <?= e(time_ago($customer['last_login_at'])) ?>
                            <div class="ad-cellflex__meta">
                                <?= e(format_datetime($customer['last_login_at'])) ?>
                                <?= $customer['last_login_ip'] ? ' · ' . e($customer['last_login_ip']) : '' ?>
                            </div>
                        <?php else: ?>
                            <span class="ad-muted">Never signed in</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($customer['locked_until']) && strtotime((string) $customer['locked_until']) > time()): ?>
                <div class="ad-card__body" style="border-top:1px solid var(--ad-border)">
                    <div class="sik-alert sik-alert--warning" style="margin:0">
                        <?= icon('lock', 'w-5 h-5') ?>
                        <div>
                            Locked out after <?= (int) $customer['failed_logins'] ?> failed attempts until
                            <?= e(format_datetime($customer['locked_until'])) ?>.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (admin_can('customers.edit')): ?>
                <div class="ad-card__foot" style="flex-direction:column;align-items:stretch">
                    <a class="ad-btn ad-btn--block"
                       href="<?= e(admin_url('customers/edit.php?id=' . $customerId)) ?>">
                        <?= icon('edit', 'w-4 h-4') ?> Edit details
                    </a>

                    <form method="post" action="<?= e(admin_url('customers/view.php?id=' . $customerId)) ?>"
                          style="width:100%"
                          <?= admin_confirm_form_attrs(
                              'A reset link goes to ' . (string) $customer['email'] . '. It expires after an hour.',
                              [
                                  'title' => 'Send a password reset?',
                                  'label' => 'Send the link',
                                  'tone'  => 'warning',
                              ]
                          ) ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <button type="submit" class="ad-btn ad-btn--block">
                            <?= icon('lock', 'w-4 h-4') ?> Send password reset
                        </button>
                    </form>

                    <?php if ($customer['status'] === 'blocked' || $customer['status'] === 'inactive'): ?>
                        <form method="post" action="<?= e(admin_url('customers/view.php?id=' . $customerId)) ?>"
                              style="width:100%">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="activate">
                            <button type="submit" class="ad-btn ad-btn--success ad-btn--block">
                                <?= icon('check-circle', 'w-4 h-4') ?> Activate account
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(admin_url('customers/view.php?id=' . $customerId)) ?>"
                              style="width:100%"
                              <?= admin_confirm_form_attrs(
                                  $customerName . ' is signed out at once and cannot log in again until you unblock them.',
                                  [
                                      'title' => 'Block this customer?',
                                      'label' => 'Block account',
                                      'tone'  => 'warning',
                                  ]
                              ) ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="block">
                            <button type="submit" class="ad-btn ad-btn--danger ad-btn--block">
                                <?= icon('lock', 'w-4 h-4') ?> Block account
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (admin_can('customers.delete')): ?>
                        <?php if ($orderCount === 0): ?>
                            <?php
                            /* A named requireText case: a customer record and
                               everything hanging off it goes, and there is no
                               undo anywhere in the admin. Typing the name is
                               the difference between a slip and a decision. */
                            ?>
                            <?= admin_delete_form(
                                admin_url('customers/delete.php'),
                                $customerId,
                                'Their addresses, wishlist and cart go with them. This cannot be undone.',
                                'Delete customer',
                                'ad-btn--block',
                                [
                                    'title'   => 'Delete ' . $customerName . '?',
                                    'label'   => 'Delete customer',
                                    'require' => $customerName,
                                ]
                            ) ?>
                        <?php else: ?>
                            <p class="ad-muted" style="font-size:12px;text-align:center;margin-top:4px">
                                Cannot be deleted &mdash; <?= number_format($orderCount) ?> order<?= $orderCount === 1 ? '' : 's' ?>
                                must stay on record. Block the account instead.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================= Login history ======================== -->
        <div class="ad-card">
            <div class="ad-card__head">
                <div class="ad-card__title">Recent sign-ins</div>
                <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                    <?= $loginCount > count($logins)
                        ? 'Latest ' . count($logins) . ' of ' . number_format($loginCount)
                        : number_format($loginCount) . ' recorded' ?>
                </span>
            </div>
            <div class="ad-card__body<?= $logins === [] ? ' ad-card__body--flush' : '' ?>"
                 style="<?= $logins === [] ? '' : 'display:grid;gap:11px;font-size:var(--ad-text-sm)' ?>">
                <?php if ($logins === []): ?>
                    <?= admin_empty('No sign-in records', 'Nothing has been logged for this account.', null, null, 'clock') ?>
                <?php else: ?>
                    <?php foreach ($logins as $login): ?>
                        <div style="display:flex;gap:9px;align-items:flex-start">
                            <span style="margin-top:2px;color:<?= $login['status'] === 'success' ? '#16A34A' : '#DC2626' ?>">
                                <?= icon($login['status'] === 'success' ? 'check-circle' : 'alert', 'w-4 h-4') ?>
                            </span>
                            <div style="min-width:0">
                                <div>
                                    <?= $login['status'] === 'success' ? 'Signed in' : 'Failed attempt' ?>
                                    <?= $login['reason'] ? '<span class="ad-muted">· ' . e(str_replace('_', ' ', (string) $login['reason'])) . '</span>' : '' ?>
                                </div>
                                <div class="ad-cellflex__meta">
                                    <?= e(format_datetime($login['created_at'])) ?>
                                    <?= $login['ip_address'] ? ' · ' . e($login['ip_address']) : '' ?>
                                </div>
                                <?php if (!empty($login['user_agent'])): ?>
                                    <div class="ad-cellflex__meta"><?= e(str_limit($login['user_agent'], 52)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($loginCount > count($logins) && admin_can('logs.view')): ?>
                <div class="ad-card__foot">
                    <a class="ad-btn ad-btn--block" href="<?= e(admin_url('logs/login-history.php')
                        . '?' . http_build_query(['q' => (string) $customer['email'], 'user_type' => 'customer'])) ?>">
                        <?= icon('clock', 'w-4 h-4') ?> All <?= number_format($loginCount) ?> sign-in records
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
