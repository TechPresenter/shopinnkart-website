<?php
/**
 * ShopInnKart Admin - Who redeemed a coupon.
 *
 * coupon_usage is written by create_order() inside the order transaction, so
 * a row here always corresponds to an order that was actually placed. The
 * order may since have been cancelled, which is why the order status is shown
 * next to the discount rather than the discount alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('coupons.view');

require_once ADMIN_PATH . '/includes/marketing.php';

$id = input_int('id');
$coupon = $id > 0
    ? Database::fetch('SELECT * FROM `coupons` WHERE `id` = :id', ['id' => $id])
    : null;

if ($coupon === null) {
    flash('error', 'That coupon no longer exists.');
    redirect(admin_url('coupons/'));
}

$page       = max(1, (int) ($_GET['page'] ?? 1));
$total      = (int) Database::fetchColumn('SELECT COUNT(*) FROM `coupon_usage` WHERE `coupon_id` = :id', ['id' => $id]);
$pagination = paginate($total, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit  = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$rows = Database::fetchAll(
    "SELECT u.`id`, u.`user_id`, u.`order_id`, u.`email`, u.`discount`, u.`created_at`,
            o.`order_number`, o.`total_amount`, o.`status` AS order_status,
            usr.`first_name`, usr.`last_name`, usr.`email` AS user_email
     FROM `coupon_usage` u
     LEFT JOIN `orders` o ON o.`id` = u.`order_id`
     LEFT JOIN `users` usr ON usr.`id` = u.`user_id`
     WHERE u.`coupon_id` = :id
     ORDER BY u.`id` DESC
     LIMIT {$limit} OFFSET {$offset}",
    ['id' => $id]
);

$given = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(`discount`), 0) FROM `coupon_usage` WHERE `coupon_id` = :id',
    ['id' => $id]
);
$uniqueCustomers = (int) Database::fetchColumn(
    'SELECT COUNT(DISTINCT `user_id`) FROM `coupon_usage` WHERE `coupon_id` = :id AND `user_id` IS NOT NULL',
    ['id' => $id]
);
$orderValue = (float) Database::fetchColumn(
    'SELECT COALESCE(SUM(o.`total_amount`), 0)
     FROM `coupon_usage` u INNER JOIN `orders` o ON o.`id` = u.`order_id`
     WHERE u.`coupon_id` = :id',
    ['id' => $id]
);

$state       = marketing_state($coupon['start_date'], $coupon['end_date'], (string) $coupon['status']);
$canSeeOrder = admin_can('orders.view');

$pageTitle    = 'Coupon Usage';
$pageSubtitle = $coupon['code'] . ' · ' . number_format($total) . ' redemption' . ($total === 1 ? '' : 's');
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Coupons',   'url' => admin_url('coupons/')],
    ['label' => (string) $coupon['code'], 'url' => admin_url('coupons/edit.php?id=' . $id)],
    ['label' => 'Usage'],
];

$pageActions = '';
if (admin_can('coupons.edit')) {
    $pageActions = '<a class="ad-btn" href="' . e(admin_url('coupons/edit.php?id=' . $id)) . '">'
        . icon('edit', 'w-4 h-4') . ' Edit coupon</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Redemptions', number_format($total), 'tag', 'primary',
        'Counter on the coupon: ' . number_format((int) $coupon['used_count'])) ?>
    <?= admin_stat_card('Discount given', money($given), 'wallet', 'red',
        'Taken off order totals') ?>
    <?= admin_stat_card('Order value', money($orderValue), 'cart', 'green',
        'Revenue these redemptions carried') ?>
    <?= admin_stat_card('Signed-in customers', number_format($uniqueCustomers), 'users', 'violet',
        'Distinct accounts; guests are not counted') ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title"><?= e($coupon['code']) ?></div>
            <div class="ad-card__sub">
                <?= e(marketing_window_text($coupon['start_date'], $coupon['end_date'])) ?>
                &middot; <?= marketing_state_badge($state) ?>
            </div>
        </div>
        <div style="min-width:150px">
            <?= marketing_usage_bar(
                (int) $coupon['used_count'],
                $coupon['usage_limit'] !== null ? (int) $coupon['usage_limit'] : null,
                'redeemed'
            ) ?>
        </div>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($rows === []): ?>
            <?= admin_empty(
                'Nobody has used this coupon yet',
                'Redemptions appear here the moment an order is placed with this code.',
                null,
                null,
                'tag'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Order</th>
                            <th>Order status</th>
                            <th class="ad-table__num">Discount</th>
                            <th class="ad-table__num">Order total</th>
                            <th>Redeemed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $userId = $row['user_id'] !== null ? (int) $row['user_id'] : 0;
                            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                            $email = (string) ($row['user_email'] ?? $row['email'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex">
                                        <span class="ad-avatar"><?= e(initials($name !== '' ? $name : ($email !== '' ? $email : 'Guest'))) ?></span>
                                        <span style="min-width:0">
                                            <span class="ad-cellflex__name" style="display:block">
                                                <?php if ($userId > 0 && admin_can('customers.view')): ?>
                                                    <a href="<?= e(admin_url('customers/view.php?id=' . $userId)) ?>">
                                                        <?= e($name !== '' ? $name : 'Customer #' . $userId) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($name !== '' ? $name : 'Guest checkout') ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="ad-cellflex__meta">
                                                <?= $email !== '' ? e($email) : '&mdash;' ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['order_number'] !== null && $canSeeOrder): ?>
                                        <a class="ad-mono" style="font-weight:700"
                                           href="<?= e(admin_url('orders/view.php?id=' . (int) $row['order_id'])) ?>">
                                            <?= e($row['order_number']) ?>
                                        </a>
                                    <?php elseif ($row['order_number'] !== null): ?>
                                        <span class="ad-mono"><?= e($row['order_number']) ?></span>
                                    <?php else: ?>
                                        <span class="ad-muted">Order removed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $row['order_status'] !== null
                                        ? admin_status_badge((string) $row['order_status'])
                                        : '<span class="ad-muted">&mdash;</span>' ?>
                                </td>
                                <td class="ad-table__num"><strong>&minus;<?= e(money((float) $row['discount'])) ?></strong></td>
                                <td class="ad-table__num">
                                    <?= $row['total_amount'] !== null
                                        ? e(money((float) $row['total_amount']))
                                        : '<span class="ad-muted">&mdash;</span>' ?>
                                </td>
                                <?php // Same as the list: the timestamp wraps rather than setting a floor
                                      // under the table's minimum width. ?>
                                <td class="ad-muted">
                                    <?= e(format_datetime($row['created_at'])) ?>
                                    <span class="ad-cellflex__meta" style="display:block"><?= e(time_ago($row['created_at'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['last'] > 1): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                of <?= (int) $pagination['total'] ?>
            </span>
            <?= admin_pagination($pagination, admin_url('coupons/usage.php')) ?>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
