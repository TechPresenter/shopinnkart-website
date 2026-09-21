<?php
/**
 * ShopInnKart Admin - Global search.
 *
 * Reached from the topbar box. One query, four groups. The page itself only
 * needs dashboard.view, but each group is queried only when the admin holds
 * the matching view permission — a support agent without customer access
 * must not learn a customer exists by searching for their email.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$admin = admin_require('dashboard.view');

const SEARCH_GROUP_LIMIT = 8;
const SEARCH_MIN_LENGTH  = 2;

$query = trim((string) ($_GET['q'] ?? ''));
$tooShort = $query !== '' && mb_strlen($query) < SEARCH_MIN_LENGTH;
$hasQuery = $query !== '' && !$tooShort;

// Escape the LIKE wildcards so a search for "50%" means what it says.
$needle = '%' . addcslashes($query, '%_\\') . '%';

$canOrders    = admin_can('orders.view');
$canProducts  = admin_can('products.view');
$canCustomers = admin_can('customers.view');
$canCoupons   = admin_can('coupons.view');

$orders = $products = $customers = $coupons = [];
$counts = ['orders' => 0, 'products' => 0, 'customers' => 0, 'coupons' => 0];

if ($hasQuery) {
    if ($canOrders) {
        // Native prepared statements cannot reuse one named placeholder, so
        // each column gets its own copy of the same value.
        $orderWhere = '(o.`order_number` LIKE :q_number OR o.`customer_name` LIKE :q_name'
            . ' OR o.`customer_email` LIKE :q_email OR o.`customer_phone` LIKE :q_phone)';
        $orderParams = [
            'q_number' => $needle, 'q_name' => $needle,
            'q_email'  => $needle, 'q_phone' => $needle,
        ];

        $counts['orders'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `orders` o WHERE {$orderWhere}",
            $orderParams
        );
        $orders = Database::fetchAll(
            "SELECT o.`id`, o.`order_number`, o.`customer_name`, o.`customer_email`, o.`customer_phone`,
                    o.`total_amount`, o.`status`, o.`created_at`
             FROM `orders` o
             WHERE {$orderWhere}
             ORDER BY o.`id` DESC
             LIMIT " . SEARCH_GROUP_LIMIT,
            $orderParams
        );
    }

    if ($canProducts) {
        $productWhere = '(p.`name` LIKE :q_name OR p.`sku` LIKE :q_sku)';
        $productParams = ['q_name' => $needle, 'q_sku' => $needle];

        $counts['products'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `products` p WHERE {$productWhere}",
            $productParams
        );
        $products = Database::fetchAll(
            "SELECT p.`id`, p.`name`, p.`sku`, p.`main_image`, p.`price`, p.`sale_price`,
                    p.`stock`, p.`low_stock_threshold`, p.`status`
             FROM `products` p
             WHERE {$productWhere}
             ORDER BY p.`sold_count` DESC, p.`id` DESC
             LIMIT " . SEARCH_GROUP_LIMIT,
            $productParams
        );
    }

    if ($canCustomers) {
        $customerWhere = "(u.`first_name` LIKE :q_first OR u.`last_name` LIKE :q_last"
            . " OR CONCAT_WS(' ', u.`first_name`, u.`last_name`) LIKE :q_full"
            . ' OR u.`email` LIKE :q_email OR u.`phone` LIKE :q_phone)';
        $customerParams = [
            'q_first' => $needle, 'q_last' => $needle, 'q_full' => $needle,
            'q_email' => $needle, 'q_phone' => $needle,
        ];

        $counts['customers'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `users` u WHERE {$customerWhere}",
            $customerParams
        );
        $customers = Database::fetchAll(
            "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`email`, u.`phone`, u.`status`, u.`created_at`,
                    (SELECT COUNT(*) FROM `orders` o WHERE o.`user_id` = u.`id`) AS order_count
             FROM `users` u
             WHERE {$customerWhere}
             ORDER BY u.`id` DESC
             LIMIT " . SEARCH_GROUP_LIMIT,
            $customerParams
        );
    }

    if ($canCoupons) {
        $couponWhere = '(c.`code` LIKE :q_code OR c.`description` LIKE :q_desc)';
        $couponParams = ['q_code' => $needle, 'q_desc' => $needle];

        $counts['coupons'] = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM `coupons` c WHERE {$couponWhere}",
            $couponParams
        );
        $coupons = Database::fetchAll(
            "SELECT c.`id`, c.`code`, c.`description`, c.`type`, c.`value`, c.`minimum_order`,
                    c.`used_count`, c.`usage_limit`, c.`end_date`, c.`status`
             FROM `coupons` c
             WHERE {$couponWhere}
             ORDER BY c.`status` ASC, c.`code` ASC
             LIMIT " . SEARCH_GROUP_LIMIT,
            $couponParams
        );
    }
}

$totalFound   = array_sum($counts);
$searchable   = array_filter([
    $canOrders    ? 'orders' : null,
    $canProducts  ? 'products' : null,
    $canCustomers ? 'customers' : null,
    $canCoupons   ? 'coupons' : null,
]);

$pageTitle    = 'Search';
$pageSubtitle = $hasQuery
    ? number_format($totalFound) . ' result' . ($totalFound === 1 ? '' : 's') . ' for "' . $query . '"'
    : 'One box for orders, products, customers and coupons.';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Search'],
];

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <div class="ad-card__body">
        <form method="get" action="<?= e(admin_url('search.php')) ?>" class="ad-filters" style="border:0;padding:0">
            <div class="ad-search" style="max-width:none">
                <?= icon('search', 'w-4 h-4') ?>
                <label class="sik-sr" for="adminSearchInput">Search the admin</label>
                <input class="sik-input" type="search" id="adminSearchInput" name="q" autofocus
                       value="<?= e($query) ?>" autocomplete="off"
                       placeholder="Order number, product name or SKU, customer email or phone, coupon code&hellip;">
            </div>
            <button type="submit" class="ad-btn ad-btn--primary"><?= icon('search', 'w-4 h-4') ?> Search</button>
        </form>

        <?php if ($searchable === []): ?>
            <p class="ad-muted" style="margin-top:12px;font-size:13px">
                Your role can open the dashboard but not the order, product, customer or coupon lists,
                so there is nothing for this search to look through.
            </p>
        <?php else: ?>
            <p class="ad-muted" style="margin-top:12px;font-size:13px">
                Searching <?= e(implode(', ', $searchable)) ?>.
                Partial matches count &mdash; &ldquo;9876&rdquo; finds a phone number as well as an order.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($tooShort): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('info', 'w-5 h-5') ?>
        <div>Type at least <?= SEARCH_MIN_LENGTH ?> characters &mdash; a single letter matches almost everything.</div>
    </div>
<?php endif; ?>

<?php if (!$hasQuery): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <?= admin_empty(
                'What are you looking for?',
                'Search by order number, customer name, email or phone, product name or SKU, or a coupon code.',
                null,
                null,
                'search'
            ) ?>
        </div>
    </div>

<?php elseif ($totalFound === 0): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <?= admin_empty(
                'Nothing matches "' . $query . '"',
                'Check the spelling, drop the prefix from an order number, or try just the last few digits of a phone number.',
                null,
                null,
                'search'
            ) ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
                <?php if ($canOrders): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('orders/')) ?>"><?= icon('cart', 'w-4 h-4') ?> Browse orders</a>
                <?php endif; ?>
                <?php if ($canProducts): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/')) ?>"><?= icon('package', 'w-4 h-4') ?> Browse products</a>
                <?php endif; ?>
                <?php if ($canCustomers): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('customers/')) ?>"><?= icon('users', 'w-4 h-4') ?> Browse customers</a>
                <?php endif; ?>
                <?php if ($canCoupons): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('coupons/')) ?>"><?= icon('percent', 'w-4 h-4') ?> Browse coupons</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php if ($orders !== []): ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('cart', 'w-4 h-4') ?> Orders</div>
                    <div class="ad-card__sub"><?= number_format($counts['orders']) ?> match<?= $counts['orders'] === 1 ? '' : 'es' ?></div>
                </div>
                <?php if ($counts['orders'] > count($orders)): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('orders/?q=' . urlencode($query))) ?>">
                        View all <?= number_format($counts['orders']) ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Placed</th>
                                <th>Status</th>
                                <th class="ad-table__num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <a class="ad-mono" style="font-weight:700"
                                           href="<?= e(admin_url('orders/view.php?id=' . (int) $order['id'])) ?>">
                                            <?= e($order['order_number']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="ad-cellflex__name" style="display:block"><?= e($order['customer_name']) ?></span>
                                        <span class="ad-cellflex__meta">
                                            <?= e($order['customer_email']) ?> &middot; <?= e($order['customer_phone']) ?>
                                        </span>
                                    </td>
                                    <td class="ad-muted"><?= e(format_date($order['created_at'], 'd M Y')) ?></td>
                                    <td><?= admin_status_badge((string) $order['status']) ?></td>
                                    <td class="ad-table__num"><strong><?= e(money((float) $order['total_amount'])) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($products !== []): ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('package', 'w-4 h-4') ?> Products</div>
                    <div class="ad-card__sub"><?= number_format($counts['products']) ?> match<?= $counts['products'] === 1 ? '' : 'es' ?></div>
                </div>
                <?php if ($counts['products'] > count($products)): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('products/?q=' . urlencode($query))) ?>">
                        View all <?= number_format($counts['products']) ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="ad-table__num">Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php $price = (float) ($product['sale_price'] > 0 ? $product['sale_price'] : $product['price']); ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <img class="ad-thumb" src="<?= e(img_url($product['main_image'])) ?>"
                                                 alt="" width="38" height="38" loading="lazy">
                                            <span class="ad-cellflex__name">
                                                <a href="<?= e(admin_url('products/view.php?id=' . (int) $product['id'])) ?>">
                                                    <?= e(str_limit((string) $product['name'], 60)) ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="ad-mono"><?= e($product['sku']) ?></td>
                                    <td class="ad-table__num"><?= e(money($price)) ?></td>
                                    <td><?= admin_stock_badge((int) $product['stock'], (int) $product['low_stock_threshold']) ?></td>
                                    <td><?= admin_state_badge((string) $product['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($customers !== []): ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('users', 'w-4 h-4') ?> Customers</div>
                    <div class="ad-card__sub"><?= number_format($counts['customers']) ?> match<?= $counts['customers'] === 1 ? '' : 'es' ?></div>
                </div>
                <?php if ($counts['customers'] > count($customers)): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('customers/?q=' . urlencode($query))) ?>">
                        View all <?= number_format($counts['customers']) ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th class="ad-table__num">Orders</th>
                                <th>Joined</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <?php $fullName = trim($customer['first_name'] . ' ' . (string) $customer['last_name']); ?>
                                <tr>
                                    <td>
                                        <div class="ad-cellflex">
                                            <span class="ad-avatar"><?= e(initials($fullName)) ?></span>
                                            <span style="min-width:0">
                                                <span class="ad-cellflex__name" style="display:block">
                                                    <a href="<?= e(admin_url('customers/view.php?id=' . (int) $customer['id'])) ?>">
                                                        <?= e($fullName) ?>
                                                    </a>
                                                </span>
                                                <span class="ad-cellflex__meta"><?= e($customer['email']) ?></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="ad-mono"><?= e($customer['phone'] ?: '—') ?></td>
                                    <td class="ad-table__num"><?= number_format((int) $customer['order_count']) ?></td>
                                    <td class="ad-muted"><?= e(format_date($customer['created_at'], 'd M Y')) ?></td>
                                    <td><?= admin_state_badge((string) $customer['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($coupons !== []): ?>
        <div class="ad-card">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title"><?= icon('percent', 'w-4 h-4') ?> Coupons</div>
                    <div class="ad-card__sub"><?= number_format($counts['coupons']) ?> match<?= $counts['coupons'] === 1 ? '' : 'es' ?></div>
                </div>
                <?php if ($counts['coupons'] > count($coupons)): ?>
                    <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('coupons/?q=' . urlencode($query))) ?>">
                        View all <?= number_format($counts['coupons']) ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="ad-card__body ad-card__body--flush">
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Discount</th>
                                <th class="ad-table__num">Used</th>
                                <th>Ends</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coupons as $coupon): ?>
                                <?php
                                $couponId = (int) $coupon['id'];
                                $discount = match ((string) $coupon['type']) {
                                    'percentage'    => rtrim(rtrim(number_format((float) $coupon['value'], 2, '.', ''), '0'), '.') . '% off',
                                    'fixed'         => money((float) $coupon['value']) . ' off',
                                    'free_shipping' => 'Free shipping',
                                    default         => (string) $coupon['type'],
                                };
                                ?>
                                <tr>
                                    <td>
                                        <?php if (admin_can('coupons.edit')): ?>
                                            <a class="ad-mono" style="font-weight:700"
                                               href="<?= e(admin_url('coupons/edit.php?id=' . $couponId)) ?>">
                                                <?= e($coupon['code']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="ad-mono" style="font-weight:700"><?= e($coupon['code']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($coupon['description'])): ?>
                                            <span class="ad-cellflex__meta" style="display:block">
                                                <?= e(str_limit((string) $coupon['description'], 60)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($discount) ?></td>
                                    <td class="ad-table__num">
                                        <?= number_format((int) $coupon['used_count']) ?><?php
                                        if ($coupon['usage_limit'] !== null) {
                                            echo ' / ' . number_format((int) $coupon['usage_limit']);
                                        }
                                        ?>
                                    </td>
                                    <td class="ad-muted">
                                        <?= $coupon['end_date'] !== null ? e(format_date((string) $coupon['end_date'], 'd M Y')) : 'No end date' ?>
                                    </td>
                                    <td><?= admin_state_badge((string) $coupon['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
