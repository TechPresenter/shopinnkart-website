<?php
/**
 * ShopInnKart - Customer dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';

$user = require_login();
$userId = (int) $user['id'];

// "Pending" covers everything that has been placed but not yet delivered -
// a customer reads that tile as "orders still on their way".
$openStatuses = [
    ORDER_STATUS_PENDING,
    ORDER_STATUS_CONFIRMED,
    ORDER_STATUS_PROCESSING,
    ORDER_STATUS_PACKED,
    ORDER_STATUS_SHIPPED,
    ORDER_STATUS_OUT_FOR_DELIVERY,
];
[$openPlaceholders, $openParams] = Database::inPlaceholders($openStatuses, 'st');

$totalOrders = Database::count('orders', '`user_id` = :uid', ['uid' => $userId]);
$pendingOrders = Database::count(
    'orders',
    '`user_id` = :uid AND `status` IN (' . $openPlaceholders . ')',
    array_merge(['uid' => $userId], $openParams)
);
$deliveredOrders = Database::count(
    'orders',
    '`user_id` = :uid AND `status` = :status',
    ['uid' => $userId, 'status' => ORDER_STATUS_DELIVERED]
);
$wishlistCount = wishlist_count();

$lifetimeSpend = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(`total_amount`), 0) FROM `orders`
     WHERE `user_id` = :uid AND `status` NOT IN (:cancelled, :returned, :refunded)',
    [
        'uid'       => $userId,
        'cancelled' => ORDER_STATUS_CANCELLED,
        'returned'  => ORDER_STATUS_RETURNED,
        'refunded'  => ORDER_STATUS_REFUNDED,
    ]
);

$recent = customer_orders($userId, 1, 3);
$recentOrders = $recent['items'];

$defaultAddress = Database::fetch(
    'SELECT * FROM `user_addresses` WHERE `user_id` = :uid ORDER BY `is_default` DESC, `id` ASC LIMIT 1',
    ['uid' => $userId]
);

$reviewCount = Database::count('reviews', '`user_id` = :uid', ['uid' => $userId]);

// The pending tile spans several statuses, and the order list filters on one
// status at a time, so it links to the unfiltered list rather than a tab that
// would show fewer orders than the number on the tile.
$tiles = [
    ['label' => 'Total Orders', 'value' => (string) $totalOrders,     'icon' => 'package', 'url' => url('orders.php')],
    ['label' => 'Pending',      'value' => (string) $pendingOrders,   'icon' => 'truck',   'url' => url('orders.php')],
    ['label' => 'Delivered',    'value' => (string) $deliveredOrders, 'icon' => 'check-circle', 'url' => url('orders.php?status=' . ORDER_STATUS_DELIVERED)],
    ['label' => 'Wishlist',     'value' => (string) $wishlistCount,   'icon' => 'heart',   'url' => url('wishlist.php')],
];

$quickLinks = [
    ['label' => 'Track an order',  'text' => 'See where your parcel is right now', 'icon' => 'location',    'url' => url('orders.php')],
    ['label' => 'Saved addresses', 'text' => 'Manage delivery addresses',          'icon' => 'home',        'url' => url('addresses.php')],
    ['label' => 'My reviews',      'text' => $reviewCount === 1 ? '1 review written' : $reviewCount . ' reviews written', 'icon' => 'star', 'url' => url('my-reviews.php')],
    ['label' => 'Change password', 'text' => 'Keep your account secure',           'icon' => 'lock',        'url' => url('change-password.php')],
    ['label' => 'Continue shopping', 'text' => 'Browse the latest electronics',    'icon' => 'bag',         'url' => url('shop.php')],
    ['label' => 'Need help?',      'text' => 'Talk to our support team',           'icon' => 'headset',     'url' => url('contact.php')],
];

seo_set([
    'title'       => 'My Account',
    'description' => 'Your ShopInnKart dashboard: orders, wishlist, addresses and account settings.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('account', [
    'title'    => 'Hello, ' . trim($user['first_name'] . ' ' . (string) $user['last_name']),
    'subtitle' => $totalOrders > 0
        ? 'You have placed ' . $totalOrders . ' order' . ($totalOrders === 1 ? '' : 's') . ' with us so far.'
        : 'Welcome to ShopInnKart. Your orders will appear here once you place one.',
]);
?>

<div class="sik-grid" style="--cols-mobile:2;--cols-tablet:4;--cols-desktop:4;margin-bottom:var(--sp-6)">
    <?php foreach ($tiles as $tile): ?>
        <a class="sik-panel" href="<?= e($tile['url']) ?>" style="display:block">
            <div class="sik-panel__body" style="padding:var(--sp-4)">
                <span style="display:inline-flex;width:36px;height:36px;border-radius:10px;background:var(--sik-primary-soft);color:var(--sik-primary);align-items:center;justify-content:center;margin-bottom:var(--sp-3)">
                    <?= icon($tile['icon'], 'w-5 h-5') ?>
                </span>
                <div style="font-size:26px;font-weight:800;line-height:1;color:var(--sik-navy)"><?= e($tile['value']) ?></div>
                <div style="font-size:12.5px;color:var(--sik-muted);margin-top:var(--sp-1)"><?= e($tile['label']) ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($lifetimeSpend > 0): ?>
    <div class="sik-alert sik-alert--info" style="margin-bottom:var(--sp-6)">
        <?= icon('wallet', 'w-5 h-5') ?>
        <span>You have spent <strong><?= e(money($lifetimeSpend)) ?></strong> on ShopInnKart across <?= (int) ($totalOrders) ?> order<?= $totalOrders === 1 ? '' : 's' ?>.</span>
    </div>
<?php endif; ?>

<div class="sik-panel" style="margin-bottom:var(--sp-6)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Recent Orders</h2>
        <?php if ($recentOrders !== []): ?>
            <a class="sik-viewall" href="<?= e(url('orders.php')) ?>">View all <?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
        <?php endif; ?>
    </div>
    <div class="sik-panel__body">
        <?php if ($recentOrders === []): ?>
            <?= account_empty(
                'package',
                'No orders yet',
                'When you place your first order it will show up here with live tracking.',
                'Start shopping',
                url('shop.php')
            ) ?>
        <?php else: ?>
            <div style="display:grid;gap:var(--sp-4)">
                <?php foreach ($recentOrders as $order): ?>
                    <?php $detailsUrl = url('order-details.php?id=' . (int) $order['id']); ?>
                    <div style="border:1px solid var(--sik-border);border-radius:var(--sik-radius-sm);padding:var(--sp-4)">
                        <div style="display:flex;flex-wrap:wrap;gap:var(--sp-3);align-items:center;justify-content:space-between">
                            <div style="min-width:0">
                                <a href="<?= e($detailsUrl) ?>" style="font-weight:700;font-size:14px;color:var(--sik-navy)">
                                    #<?= e($order['order_number']) ?>
                                </a>
                                <div style="font-size:12px;color:var(--sik-muted);margin-top:2px">
                                    <?= e(format_date($order['created_at'])) ?> &middot;
                                    <?= (int) $order['item_count'] ?> item<?= (int) $order['item_count'] === 1 ? '' : 's' ?>
                                </div>
                            </div>
                            <?= account_status_pill((string) $order['status']) ?>
                        </div>

                        <div style="display:flex;align-items:center;gap:var(--sp-3);margin-top:var(--sp-3);flex-wrap:wrap">
                            <div style="display:flex;gap:var(--sp-2)">
                                <?php foreach (array_slice($order['items'], 0, 4) as $item): ?>
                                    <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['product_name']) ?>"
                                         width="42" height="42" loading="lazy"
                                         style="width:42px;height:42px;object-fit:contain;border:1px solid var(--sik-border);border-radius:8px;background:#fff;padding:3px">
                                <?php endforeach; ?>
                                <?php if (count($order['items']) > 4): ?>
                                    <span style="width:42px;height:42px;border:1px solid var(--sik-border);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--sik-muted)">
                                        +<?= count($order['items']) - 4 ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-left:auto;display:flex;align-items:center;gap:var(--sp-3)">
                                <strong style="font-size:15px"><?= e($order['total_display']) ?></strong>
                                <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e($detailsUrl) ?>">View</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="sik-panel" style="margin-bottom:var(--sp-6)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Default Delivery Address</h2>
        <a class="sik-viewall" href="<?= e(url('addresses.php')) ?>">Manage <?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
    </div>
    <div class="sik-panel__body">
        <?php if ($defaultAddress === null): ?>
            <?= account_empty(
                'location',
                'No address saved',
                'Add a delivery address now so checkout takes just a couple of taps.',
                'Add an address',
                url('addresses.php')
            ) ?>
        <?php else: ?>
            <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
                <span style="display:inline-flex;width:38px;height:38px;border-radius:10px;background:var(--sik-soft);color:var(--sik-primary);align-items:center;justify-content:center;flex:none">
                    <?= icon('location', 'w-5 h-5') ?>
                </span>
                <div style="min-width:0;font-size:13.5px;line-height:1.7">
                    <div style="display:flex;gap:var(--sp-2);align-items:center;flex-wrap:wrap;margin-bottom:2px">
                        <strong><?= e($defaultAddress['full_name']) ?></strong>
                        <span class="sik-badge sik-badge--soft"><?= e($defaultAddress['label']) ?></span>
                        <?php if ((int) $defaultAddress['is_default'] === 1): ?>
                            <span class="sik-badge sik-badge--green">Default</span>
                        <?php endif; ?>
                    </div>
                    <?php foreach (account_address_lines($defaultAddress) as $line): ?>
                        <div style="color:var(--sik-muted)"><?= e($line) ?></div>
                    <?php endforeach; ?>
                    <div style="margin-top:var(--sp-1)"><?= icon('phone', 'w-3.5 h-3.5') ?> <?= e($defaultAddress['phone']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="sik-panel">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Quick Links</h2>
    </div>
    <div class="sik-panel__body">
        <div class="sik-grid" style="--cols-mobile:1;--cols-tablet:2;--cols-desktop:3;gap:var(--sp-3)">
            <?php foreach ($quickLinks as $link): ?>
                <a href="<?= e($link['url']) ?>"
                   style="display:flex;gap:var(--sp-3);align-items:flex-start;border:1px solid var(--sik-border);border-radius:var(--sik-radius-sm);padding:var(--sp-3)">
                    <span style="display:inline-flex;width:34px;height:34px;border-radius:9px;background:var(--sik-soft);color:var(--sik-primary);align-items:center;justify-content:center;flex:none">
                        <?= icon($link['icon'], 'w-4 h-4') ?>
                    </span>
                    <span style="min-width:0">
                        <strong style="display:block;font-size:13.5px"><?= e($link['label']) ?></strong>
                        <span style="display:block;font-size:12px;color:var(--sik-muted)"><?= e($link['text']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
