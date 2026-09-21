<?php
/**
 * ShopInnKart - Public order tracking.
 *
 * account.js posts this form to /api/orders/track.php and drops the response
 * into [data-track-result]. Posting the form back here does the same lookup
 * server-side, so tracking works without JavaScript. Both paths render the one
 * component, order_tracking_panel_html(), so the two can never drift.
 *
 * An order number on its own proves nothing, so the lookup always needs the
 * email address or the mobile number recorded on the order.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once ROOT_PATH . '/api/includes/order-handler.php';
require_once INCLUDES_PATH . '/order-tracking.php';

$order       = null;
$items       = [];
$trackError  = '';
$submitted   = false;

$formOrder = trim((string) input('order', ''));
$formEmail = '';
$formPhone = '';

if (is_post()) {
    csrf_require();
    $submitted = true;

    $formOrder = trim((string) input('order_number', ''));
    $formEmail = strtolower(trim((string) input('email', '')));
    $formPhone = trim((string) input('phone', ''));

    // Same fixed window as api_rate_limit('order_track'), shared through the
    // same session bucket - but this path has to answer in HTML, not JSON.
    $bucket = $_SESSION['_rate_order_track'] ?? ['count' => 0, 'reset' => time() + 300];
    if (time() > $bucket['reset']) {
        $bucket = ['count' => 0, 'reset' => time() + 300];
    }
    $bucket['count']++;
    $_SESSION['_rate_order_track'] = $bucket;

    $validator = new Validator($_POST, [
        'order_number' => 'Order number',
        'email'        => 'Email address',
        'phone'        => 'Mobile number',
    ]);
    $validator->required('order_number')->max('order_number', 40)
        ->requiredAny(['email', 'phone'], 'Enter the email address or mobile number used on the order.')
        ->email('email')
        ->phone('phone');

    // One message for "no such order" and "contact does not match", so the page
    // cannot be used to confirm that an order number exists.
    $notFound = 'We could not find an order matching those details. Please check the order number and try again.';

    if ($bucket['count'] > 12) {
        $trackError = 'Too many lookups. Please wait a few minutes and try again.';
    } elseif ($validator->fails()) {
        $trackError = (string) $validator->firstError();
    } else {
        $candidate = get_order_by_number($formOrder);
        $phone = normalize_phone($formPhone);
        $matched = false;

        if ($candidate !== null && $formEmail !== ''
            && hash_equals(strtolower((string) $candidate['customer_email']), $formEmail)) {
            $matched = true;
        }
        if (!$matched && $candidate !== null && $phone !== null) {
            foreach ([$candidate['customer_phone'], $candidate['shipping_phone']] as $stored) {
                if (normalize_phone((string) $stored) === $phone) {
                    $matched = true;
                    break;
                }
            }
        }

        if ($matched && $candidate !== null) {
            $order = $candidate;
            $items = get_order_items((int) $order['id']);
        } else {
            $trackError = $notFound;
        }
    }
}

// The invoice page has its own, stricter gate (owner session or staff). Linking
// to it for a guest who only proved the email address would be a button that
// always answers 403, so it is offered only when it will actually open.
$invoiceUrl = null;
if ($order !== null && order_has_invoice($order)) {
    $viewerId = current_user_id();
    $owns = $viewerId !== null && $order['user_id'] !== null && (int) $order['user_id'] === $viewerId;
    if ($owns || session_placed_order((string) $order['order_number'])) {
        $invoiceUrl = url('invoice.php?order=' . rawurlencode((string) $order['order_number']));
    }
}

seo_from_page('track-order');
seo_set(['canonical' => url('track-order.php')]);

require INCLUDES_PATH . '/header.php';
?>

<div class="sik-container sik-section sik-section--sm">
    <?= breadcrumbs([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'Track Order'],
    ]) ?>

    <div class="sik-heading" style="margin-top:var(--sp-4)">
        <h1 class="sik-heading__title">Track your <span class="sik-heading__accent">Order</span></h1>
        <p class="sik-heading__sub">
            <?= $order !== null
                ? 'Here is every stage we have recorded for this order, with the time each one happened.'
                : 'Enter your order number with the email address or mobile number you used at checkout.' ?>
        </p>
    </div>

    <!-- ============================ Result =============================
         Sits above the form so the answer is the first thing on screen,
         whether it arrived from the POST below or from account.js. -->
    <div data-track-result<?= $order === null ? ' hidden' : '' ?> style="margin-bottom:var(--sp-7)">
        <?php if ($order !== null): ?>
            <?= order_tracking_panel_html($order, $items, [
                'anchor'      => 'tracking-result',
                'invoice_url' => $invoiceUrl,
            ]) ?>
        <?php endif; ?>
    </div>

    <div class="sik-checkout">
        <div style="display:grid;gap:var(--sp-5)">

            <section class="sik-panel">
                <div class="sik-panel__head">
                    <span class="sik-panel__title"><?= $order !== null ? 'Track another order' : 'Order lookup' ?></span>
                </div>
                <div class="sik-panel__body">
                    <?php if ($trackError !== ''): ?>
                        <div class="sik-alert sik-alert--error" style="margin-bottom:var(--sp-4)">
                            <?= icon('alert', 'w-4 h-4') ?>
                            <span><?= e($trackError) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('track-order.php')) ?>" data-track-form>
                        <?= csrf_field() ?>

                        <label class="sik-field">
                            <span class="sik-label">Order number <span class="req">*</span></span>
                            <input type="text" class="sik-input" name="order_number" maxlength="40" required
                                   placeholder="<?= e((string) setting('order_prefix', ORDER_PREFIX)) ?>202608120001"
                                   autocomplete="off" value="<?= e($formOrder) ?>">
                            <span class="sik-help">It is in your confirmation email and SMS.</span>
                        </label>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="sik-field" style="margin:0">
                                <span class="sik-label">Email address</span>
                                <input type="email" class="sik-input" name="email" maxlength="190"
                                       autocomplete="email" value="<?= e($formEmail) ?>">
                            </label>
                            <label class="sik-field" style="margin:0">
                                <span class="sik-label">or Mobile number</span>
                                <input type="tel" class="sik-input" name="phone" maxlength="15"
                                       inputmode="numeric" autocomplete="tel" value="<?= e($formPhone) ?>">
                            </label>
                        </div>

                        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block" style="margin-top:var(--sp-2)">
                            <?= icon('search', 'w-4 h-4') ?><span class="sik-btn__label">Track Order</span>
                        </button>
                    </form>
                </div>
            </section>

            <?php if ($order === null && !$submitted): ?>
                <div class="sik-panel">
                    <div class="sik-empty">
                        <?= icon('truck', 'w-14 h-14') ?>
                        <p class="sik-empty__title">Nothing to show yet</p>
                        <p class="sik-empty__text">
                            Fill in the form above and your delivery timeline will appear right here.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================ Help ============================ -->
        <aside class="sik-checkout__aside" style="display:grid;gap:var(--sp-4)">
            <div class="sik-panel">
                <div class="sik-panel__head">
                    <span class="sik-panel__title">Where is my order number?</span>
                </div>
                <div class="sik-panel__body text-sm" style="line-height:1.75;color:var(--sik-muted)">
                    <p>
                        It looks like <strong><?= e((string) setting('order_prefix', ORDER_PREFIX)) ?>202608120001</strong> &mdash;
                        the store prefix, the date you ordered and a four digit counter.
                    </p>
                    <p style="margin-top:var(--sp-3)">
                        You will find it in the order confirmation email, in the SMS we sent, and on the
                        invoice inside the parcel.
                    </p>
                </div>
            </div>

            <div class="sik-panel">
                <div class="sik-panel__body">
                    <?php if (is_logged_in()): ?>
                        <p class="font-semibold text-sm">All your orders in one place</p>
                        <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.7">
                            You are signed in, so you can open any order without typing a number.
                        </p>
                        <a class="sik-btn sik-btn--outline sik-btn--sm sik-btn--block" style="margin-top:var(--sp-3)"
                           href="<?= e(url('account.php')) ?>">My account</a>
                    <?php else: ?>
                        <p class="font-semibold text-sm">Sign in for one-tap tracking</p>
                        <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.7">
                            With an account every order, invoice and return sits in one list.
                        </p>
                        <a class="sik-btn sik-btn--outline sik-btn--sm sik-btn--block" style="margin-top:var(--sp-3)"
                           href="<?= e(url('login.php')) ?>">Sign in</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sik-panel">
                <div class="sik-panel__body">
                    <p class="font-semibold text-sm">Still stuck?</p>
                    <p class="text-xs text-muted" style="margin-top:var(--sp-2);line-height:1.7">
                        Our support team can find an order from the registered email address alone.
                    </p>
                    <a class="sik-btn sik-btn--ghost sik-btn--sm sik-btn--block" style="margin-top:var(--sp-3)"
                       href="<?= e(url('contact.php')) ?>"><?= icon('headset', 'w-4 h-4') ?> Contact support</a>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
