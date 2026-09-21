<?php
/**
 * ShopInnKart - Customer account shell.
 *
 * account_layout_open() draws the sidebar and opens the content column;
 * account_layout_close() closes it again. Every account page uses the pair so
 * the navigation exists in exactly one place.
 */

declare(strict_types=1);

require_once INCLUDES_PATH . '/account-menu.php';

/**
 * Open the account shell.
 *
 * @param string $current Route key of this page — the script basename without
 *                        .php, which is how account_menu() entries are keyed.
 * @param array  $options title, subtitle, action (raw HTML for the header right side)
 */
function account_layout_open(string $current, array $options = []): void
{
    $user = require_login();

    $title = (string) ($options['title'] ?? (account_menu_labels()[$current] ?? 'My Account'));
    $subtitle = (string) ($options['subtitle'] ?? '');
    $action = (string) ($options['action'] ?? '');

    $name = trim($user['first_name'] . ' ' . (string) $user['last_name']);
    $crumbs = [['label' => 'Home', 'url' => url()]];
    if ($current !== 'account') {
        $crumbs[] = ['label' => 'My Account', 'url' => url('account.php')];
    }
    $crumbs[] = ['label' => $title];
    ?>
    <div class="sik-container sik-section sik-section--sm">
        <?= breadcrumbs($crumbs) ?>

        <?php
        /**
         * Unverified-address prompt.
         *
         * Verification is not enforced at sign-in. Blocking an unverified login
         * would not stop the purchase — guest checkout is on by default — it
         * would only push the customer out of their account and lose them the
         * order history and the invoices. So the account works normally and
         * this asks nicely instead.
         */
        if (!user_email_verified($user) && setting_bool('email_verification_enabled', true)): ?>
            <div class="sik-alert sik-alert--warning" style="margin-top:var(--sp-4)">
                <?= icon('mail', 'w-5 h-5') ?>
                <div style="flex:1;min-width:0">
                    <strong>Confirm your email address.</strong>
                    We sent a link to <?= e(mask_email((string) $user['email'])) ?>. Confirming it makes
                    sure order updates and invoices actually reach you — everything else keeps working
                    in the meantime.
                    <form method="post" action="<?= e(url('api/auth/resend-verification.php')) ?>"
                          data-ajax-form="auth/resend-verification.php"
                          style="display:inline-block;margin-top:var(--sp-2)">
                        <?= csrf_field() ?>
                        <button type="submit" class="sik-btn sik-btn--outline sik-btn--sm">
                            <?= icon('send', 'w-4 h-4') ?>
                            <span class="sik-btn__label">Resend the link</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="sik-account" style="margin-top:var(--sp-5)">
            <aside class="sik-accountnav" aria-label="Account navigation">
                <div class="sik-accountnav__head">
                    <span class="sik-accountnav__avatar" aria-hidden="true"><?= e(initials($name)) ?></span>
                    <div style="min-width:0">
                        <strong style="display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($name) ?></strong>
                        <span style="display:block;font-size:11.5px;opacity:.78;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($user['email']) ?></span>
                    </div>
                </div>

                <?php // Same renderer as the header dropdown — one list, two surfaces. ?>
                <?php account_menu_nav($current); ?>
            </aside>

            <div>
                <div class="sik-heading sik-heading--row" style="margin-bottom:var(--sp-5)">
                    <div style="min-width:0">
                        <h1 class="sik-heading__title" style="font-size:clamp(19px,3vw,24px)"><?= e($title) ?></h1>
                        <?php if ($subtitle !== ''): ?>
                            <p class="sik-heading__sub" style="margin-inline:0"><?= e($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <?= $action ?>
                </div>
    <?php
}

/** Close the content column opened by account_layout_open(). */
function account_layout_close(): void
{
    ?>
            </div>
        </div>
    </div>
    <?php
}

/** Coloured status pill for an order status key. */
function account_status_pill(string $status): string
{
    $color = ORDER_STATUS_COLORS[$status] ?? 'gray';
    $label = ORDER_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));

    return '<span class="sik-status sik-status--' . e($color) . '">' . e($label) . '</span>';
}

/** Status pill for a review moderation status. */
function account_review_pill(string $status): string
{
    $map = [
        REVIEW_STATUS_APPROVED => ['green', 'Published'],
        REVIEW_STATUS_PENDING  => ['amber', 'Awaiting approval'],
        REVIEW_STATUS_REJECTED => ['red', 'Not published'],
    ];
    [$color, $label] = $map[$status] ?? ['gray', ucfirst($status)];

    return '<span class="sik-status sik-status--' . e($color) . '">' . e($label) . '</span>';
}

/** Pagination links that keep the current query string intact. */
function account_pager(array $pagination): string
{
    if ($pagination['last'] <= 1) {
        return '';
    }

    $link = static function ($page, string $label, bool $current = false, bool $disabled = false): string {
        $classes = 'sik-pager__link' . ($current ? ' is-current' : '') . ($disabled ? ' is-disabled' : '');
        $href = url_with(['page' => $page === 1 ? null : $page]);
        return '<a class="' . $classes . '" href="' . e($href) . '"'
            . ($current ? ' aria-current="page"' : '')
            . ($disabled ? ' tabindex="-1" aria-disabled="true"' : '')
            . '>' . $label . '</a>';
    };

    $current = (int) $pagination['current'];
    $html = '<nav class="sik-pager" aria-label="Pagination">';
    $html .= $link(max(1, $current - 1), icon('chevron-left', 'w-4 h-4'), false, $current <= 1);

    foreach ($pagination['pages'] as $page) {
        $html .= $page === '…'
            ? '<span class="sik-pager__gap">&hellip;</span>'
            : $link((int) $page, (string) (int) $page, (int) $page === $current);
    }

    $html .= $link(min((int) $pagination['last'], $current + 1), icon('chevron-right', 'w-4 h-4'), false, $current >= (int) $pagination['last']);

    return $html . '</nav>';
}

/** Standard empty state for the account lists. */
function account_empty(string $iconName, string $title, string $text, string $ctaLabel = '', string $ctaUrl = ''): string
{
    // .sik-empty sizes any svg inside it as the illustration, so the icon is
    // emitted bare rather than inside a badge wrapper.
    $html = '<div class="sik-empty" style="color:var(--sik-muted)">'
        . icon($iconName, 'w-16 h-16')
        . '<p class="sik-empty__title" style="color:var(--sik-text)">' . e($title) . '</p>'
        . '<p class="sik-empty__text">' . e($text) . '</p>';

    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $html .= '<a class="sik-btn sik-btn--primary" href="' . e($ctaUrl) . '">' . e($ctaLabel) . '</a>';
    }

    return $html . '</div>';
}

/**
 * The order timeline as a compact horizontal rail.
 *
 * order-details.php has room for the full vertical timeline; a list card does
 * not, and seven word labels side by side cannot fit a 320px screen. The rail
 * shows progress as dots and names the position in one caption underneath.
 * The dots are decorative — the <ol> carries the full labels for assistive
 * tech, which is why each step also holds a visually hidden sentence.
 */
function account_order_track(array $order): string
{
    $steps = order_timeline($order);
    if ($steps === []) {
        return '';
    }

    $doneCount = 0;
    $currentLabel = '';
    $nextLabel = '';
    $currentAt = null;

    foreach ($steps as $index => $step) {
        if (!empty($step['done'])) {
            $doneCount++;
        }
        if (!empty($step['current'])) {
            $currentLabel = (string) $step['label'];
            $currentAt = $step['at'];
            $nextLabel = (string) ($steps[$index + 1]['label'] ?? '');
        }
    }
    if ($currentLabel === '') {
        $currentLabel = (string) $steps[0]['label'];
    }

    $html = '<div class="sik-track"><ol class="sik-track__rail">';

    foreach ($steps as $step) {
        $state = '';
        if (!empty($step['done'])) {
            $state .= ' is-done';
        }
        if (!empty($step['current'])) {
            $state .= ' is-current';
        }

        $spoken = (string) $step['label'] . ($step['at'] !== null
            ? ', ' . format_datetime($step['at'])
            : ', not reached yet');

        $html .= '<li class="sik-track__step' . $state . '">'
            . '<span class="sik-track__dot" aria-hidden="true">' . icon('check', 'sik-track__tick') . '</span>'
            . '<span class="sik-sr">' . e($spoken) . '</span>'
            . '</li>';
    }

    $html .= '</ol><p class="sik-track__caption">'
        . '<strong>' . e($currentLabel) . '</strong>'
        . '<span class="sik-track__step-of sik-num">' . $doneCount . ' of ' . count($steps) . '</span>';

    if ($currentAt !== null) {
        $html .= '<span class="sik-track__at">' . e(format_datetime($currentAt)) . '</span>';
    } elseif ($nextLabel !== '') {
        $html .= '<span class="sik-track__at">Next: ' . e($nextLabel) . '</span>';
    }

    return $html . '</p></div>';
}

/**
 * Days left in the return window, or 0 when returning is not on the table.
 *
 * The window length is the administrator's setting. With returns switched off
 * (0, or absent) there is nothing truthful to tell the customer, so the caller
 * omits the whole block rather than quoting a made-up period.
 */
function account_return_days_left(array $order): int
{
    $days = setting_int('return_window_days', 0);
    if ($days <= 0
        || (string) $order['status'] !== ORDER_STATUS_DELIVERED
        || empty($order['delivered_at'])) {
        return 0;
    }

    $deliveredAt = strtotime((string) $order['delivered_at']);
    if ($deliveredAt === false) {
        return 0;
    }

    $left = $days - (int) floor((time() - $deliveredAt) / 86400);
    return max(0, $left);
}

/**
 * The explanatory line under an order's actions — why a button is missing, or
 * what to do next. Defined once so the list card and the order page say the
 * same thing.
 */
function account_order_help_html(array $order): string
{
    $status = (string) $order['status'];
    $returnLeft = account_return_days_left($order);

    if ($returnLeft > 0) {
        // There is no customer-initiated return transition in order-functions.php
        // — admin/orders/returns.php is where a return is actually recorded — so
        // this points at the real channel instead of implying self-service.
        return '<p class="sik-ordercard__hint">'
            . 'You can return this order for another <strong>' . $returnLeft . ' day'
            . ($returnLeft === 1 ? '' : 's') . '</strong>. Message support quoting order #'
            . e((string) $order['order_number']) . ' and we will arrange the pickup. '
            . '<a href="' . e(url('return-policy.php')) . '">Read the return policy</a>.</p>';
    }

    if (empty($order['can_cancel']) && in_array($status, CANCELLABLE_STATUSES, true)) {
        return '<p class="sik-ordercard__hint">'
            . 'The self-cancellation window for this order has closed. '
            . '<a href="' . e(url('contact.php')) . '">Contact support</a> and we will help.</p>';
    }

    return '';
}

/**
 * One order card. orders.php and purchase-history.php both render through this
 * so an action that exists on one screen cannot go missing from the other.
 *
 * @param array $order      A row from account_orders() — decorated there.
 * @param array $options    track (bool), items_limit (int), actions (bool)
 */
function account_order_card(array $order, array $options = []): void
{
    $orderId    = (int) $order['id'];
    $status     = (string) $order['status'];
    $detailsUrl = url('order-details.php?id=' . $orderId);
    $items      = $order['items'] ?? [];
    $limit      = (int) ($options['items_limit'] ?? 3);
    $showTrack  = (bool) ($options['track'] ?? true);
    $hidden     = max(0, count($items) - $limit);
    $canReturn  = account_return_days_left($order) > 0;
    ?>
    <article class="sik-panel sik-ordercard">
        <div class="sik-panel__head sik-ordercard__head">
            <div class="sik-ordercard__ident">
                <a class="sik-ordercard__number" href="<?= e($detailsUrl) ?>">
                    #<?= e((string) $order['order_number']) ?>
                </a>
                <p class="sik-ordercard__meta">
                    <span><?= e(format_date($order['created_at'])) ?></span>
                    <span aria-hidden="true">&middot;</span>
                    <span><?= (int) $order['item_count'] ?> item<?= (int) $order['item_count'] === 1 ? '' : 's' ?></span>
                    <span aria-hidden="true">&middot;</span>
                    <strong class="sik-num"><?= e((string) $order['total_display']) ?></strong>
                </p>
            </div>
            <?= account_status_pill($status) ?>
        </div>

        <div class="sik-panel__body sik-ordercard__body">
            <?php if ($showTrack): ?>
                <?= account_order_track($order) ?>
            <?php endif; ?>

            <ul class="sik-ordercard__items">
                <?php foreach (array_slice($items, 0, $limit) as $item): ?>
                    <li class="sik-ordercard__item">
                        <img class="sik-ordercard__thumb" src="<?= e((string) $item['image_url']) ?>"
                             alt="<?= e((string) $item['product_name']) ?>"
                             width="52" height="52" loading="lazy">
                        <span class="sik-ordercard__lines">
                            <?php if ($item['url'] !== null): ?>
                                <a class="sik-ordercard__name" href="<?= e((string) $item['url']) ?>">
                                    <?= e(str_limit((string) $item['product_name'], 70)) ?>
                                </a>
                            <?php else: ?>
                                <span class="sik-ordercard__name"><?= e(str_limit((string) $item['product_name'], 70)) ?></span>
                            <?php endif; ?>
                            <span class="sik-ordercard__sub">
                                <?php if (!empty($item['variant_name'])): ?><?= e((string) $item['variant_name']) ?> &middot; <?php endif; ?>
                                Qty <?= (int) $item['quantity'] ?>
                                <span aria-hidden="true">&middot;</span>
                                <span class="sik-num"><?= e((string) $item['price_display']) ?></span>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($hidden > 0): ?>
                <a class="sik-ordercard__more" href="<?= e($detailsUrl) ?>">
                    +<?= $hidden ?> more item<?= $hidden === 1 ? '' : 's' ?> in this order
                </a>
            <?php endif; ?>

            <?php if (!empty($order['tracking_number'])): ?>
                <p class="sik-ordercard__tracking">
                    <?= icon('truck', 'sik-ordercard__ticon') ?>
                    <span>
                        <?= e((string) ($order['courier_name'] ?: 'Courier')) ?> &middot;
                        tracking <strong><?= e((string) $order['tracking_number']) ?></strong>
                    </span>
                    <button type="button" class="sik-iconbtn sik-ordercard__copy"
                            data-copy="<?= e_attr((string) $order['tracking_number']) ?>"
                            aria-label="Copy tracking number"><?= icon('copy', 'w-4 h-4') ?></button>
                </p>
            <?php elseif (!empty($order['estimated_delivery']) && !empty($order['is_active'])): ?>
                <p class="sik-ordercard__tracking">
                    <?= icon('truck', 'sik-ordercard__ticon') ?>
                    <span>Expected by <strong><?= e(format_date($order['estimated_delivery'])) ?></strong></span>
                </p>
            <?php endif; ?>

            <?php if ($status === ORDER_STATUS_CANCELLED && !empty($order['cancel_reason'])): ?>
                <p class="sik-ordercard__note">Cancelled: <?= e(str_limit((string) $order['cancel_reason'], 120)) ?></p>
            <?php elseif ($status === ORDER_STATUS_RETURNED && !empty($order['return_reason'])): ?>
                <p class="sik-ordercard__note">Returned: <?= e(str_limit((string) $order['return_reason'], 120)) ?></p>
            <?php endif; ?>

            <?php if (($options['actions'] ?? true) !== false): ?>
                <div class="sik-ordercard__actions">
                    <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e($detailsUrl) ?>">
                        <?= icon('eye', 'w-4 h-4') ?> <span class="sik-btn__label">Details</span>
                    </a>

                    <?php // Only an order that reached confirmation has a document to bill. ?>
                    <?php if (!empty($order['has_invoice'])): ?>
                        <a class="sik-btn sik-btn--outline sik-btn--sm"
                           href="<?= e(url('invoice-download.php?order=' . rawurlencode((string) $order['order_number']))) ?>">
                            <?= icon('download', 'w-4 h-4') ?> <span class="sik-btn__label">Invoice PDF</span>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($order['is_active'])): ?>
                        <a class="sik-btn sik-btn--outline sik-btn--sm" href="<?= e($detailsUrl . '#track') ?>">
                            <?= icon('location', 'w-4 h-4') ?> <span class="sik-btn__label">Track</span>
                        </a>
                    <?php endif; ?>

                    <button type="button" class="sik-btn sik-btn--ghost sik-btn--sm" data-reorder="<?= $orderId ?>">
                        <?= icon('refresh', 'w-4 h-4') ?> <span class="sik-btn__label">Reorder</span>
                    </button>

                    <?php // can_cancel_order() is the real transition gate; the server re-checks it. ?>
                    <?php if (!empty($order['can_cancel'])): ?>
                        <button type="button" class="sik-btn sik-btn--danger sik-btn--sm" data-cancel-order="<?= $orderId ?>">
                            <?= icon('close', 'w-4 h-4') ?> <span class="sik-btn__label">Cancel</span>
                        </button>
                    <?php endif; ?>

                    <?php // Support is the real return channel — see account_order_help_html(). ?>
                    <?php if ($canReturn): ?>
                        <a class="sik-btn sik-btn--ghost sik-btn--sm" href="<?= e(url('contact.php')) ?>">
                            <?= icon('rotate', 'w-4 h-4') ?> <span class="sik-btn__label">Return or replace</span>
                        </a>
                    <?php endif; ?>
                </div>

                <?= account_order_help_html($order) ?>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/**
 * The per-row controls on the notification centre.
 *
 * Emitted twice: once per rendered row, and once empty inside a <template> that
 * account.js clones for rows it appends. That is why the id is optional — it
 * keeps the buttons defined in exactly one place instead of being rebuilt in
 * JavaScript with a second copy of the icon set.
 */
function account_notification_tools_html(?int $id = null, bool $isUnread = true): string
{
    $idAttr = $id === null ? '' : (string) $id;

    $html = '<span class="sik-feeditem__tools">';

    if ($isUnread) {
        $html .= '<button type="button" class="sik-iconbtn sik-feeditem__btn"'
            . ' data-notif-read="' . e_attr($idAttr) . '" aria-label="Mark as read">'
            . icon('check', 'w-4 h-4') . '</button>';
    }

    return $html
        . '<button type="button" class="sik-iconbtn sik-feeditem__btn sik-feeditem__btn--danger"'
        . ' data-notif-delete="' . e_attr($idAttr) . '" aria-label="Delete this notification">'
        . icon('trash', 'w-4 h-4') . '</button>'
        . '</span>';
}

/**
 * One row of the notification centre: the shared row from header-actions.php
 * plus the controls that only make sense on the full screen.
 *
 * The row itself is never re-templated here — notification_row_html() stays the
 * single definition of what a notification looks like, so the bell and this
 * page can never drift apart.
 */
function account_notification_item_html(array $row): string
{
    require_once INCLUDES_PATH . '/header-actions.php';

    $id = (int) $row['id'];
    $isUnread = (int) ($row['is_read'] ?? 0) === 0;

    return '<li class="sik-feeditem' . ($isUnread ? ' is-unread' : '') . '" data-feed-item="' . $id . '">'
        . notification_row_html($row)
        . account_notification_tools_html($id, $isUnread)
        . '</li>';
}

/** One-line "Name, line1, city, state - pincode" for compact address display. */
function account_address_lines(array $address): array
{
    return array_values(array_filter([
        (string) $address['address_line1'],
        (string) ($address['address_line2'] ?? ''),
        !empty($address['landmark']) ? 'Near ' . $address['landmark'] : '',
        trim($address['city'] . ', ' . $address['state'] . ' - ' . $address['pincode']),
        (string) ($address['country'] ?? 'India'),
    ], static fn (string $line): bool => trim($line) !== ''));
}
