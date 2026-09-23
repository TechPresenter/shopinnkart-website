<?php
/**
 * ShopInnKart Admin - Act on one shipment.
 *
 * The one POST endpoint behind every shipment button. The booking screen and
 * the tracking screen both post here, so "Cancel shipment" does the same thing,
 * with the same checks and the same message, whichever screen it was pressed on.
 *
 * Deliberately thin. Which statuses can be cancelled, whether an AWB already
 * exists and what moves the order are rules of shipping-service.php. This file
 * turns a form into one service call and the call's answer into a flash
 * message. Re-checking those rules here would be a second copy to drift.
 *
 * What it does check is what only a form can get wrong: a malformed or
 * far-off pickup date, an oversized reason, and where the browser goes next.
 * That last one comes from the request (`return`), so it passes through
 * admin_safe_return() and anything outside this admin falls back to the
 * order's booking screen instead of becoming an open redirect.
 *
 * POST: csrf_token, action (awb|label|pickup|cancel|refresh), shipment_id,
 *       optional reason, pickup_date (Y-m-d), courier_id, return.
 *   or: csrf_token, action=manifest, ids[] (the shipments list's selection), return.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('orders.edit');   // POST + CSRF + permission

require_once INCLUDES_PATH . '/shipping-service.php';

/** A posted field as a string; an array smuggled in under the same name reads as empty. */
$text = static function (string $key): string {
    $value = input($key, '');
    return is_string($value) ? trim($value) : '';
};

/**
 * Where a label or manifest may send the admin's browser, or null.
 *
 * Two kinds qualify: a page of this admin (the hub renders its own documents)
 * and an https URL (a courier's PDF). Plain http, javascript: and anything
 * else a confused driver hands back are not followed - the admin is told
 * instead.
 */
$documentTarget = static function (string $url): ?string {
    // Whitespace, control bytes and backslashes have no place in a URL we
    // put in a Location header; parse_url() and browsers disagree about them.
    if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
        return null;
    }

    if ($url[0] === '/') {
        return admin_safe_return($url, '') !== '' ? $url : null;
    }

    $adminBase = admin_url();   // ADMIN_URL plus the trailing slash
    if (strncmp($url, $adminBase, strlen($adminBase)) === 0) {
        // Its path must still land in the admin once "/../" is resolved.
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $path  = (string) parse_url($url, PHP_URL_PATH) . ($query !== '' ? '?' . $query : '');
        return admin_safe_return($path, '') !== '' ? $url : null;
    }

    $parts = parse_url($url);
    return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host'])
        ? $url
        : null;
};

$action = $text('action');

// ---------------------------------------------------------------------------
// The one batch action: the courier's own pickup manifest for the shipments
// ticked on the shipments list. Every other action is on one shipment.
// ---------------------------------------------------------------------------
if ($action === 'manifest') {
    $back = admin_safe_return($text('return'), admin_url('shipping/shipments.php'));

    // ids[] from the bulk bar. Only whole positive numbers are read; anything
    // else is dropped rather than guessed at.
    $posted = input('ids', []);
    $ids    = [];
    foreach (is_array($posted) ? $posted : [] as $value) {
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 10 && (int) $value > 0) {
            $ids[(int) $value] = (int) $value;
        }
    }
    if ($ids === []) {
        flash('error', 'Tick the shipments for the courier manifest first.');
        redirect($back);
    }
    if (count($ids) > 100) {
        // The same ceiling as the printed manifest (SHIPPING_PRINT_MAX).
        flash('error', 'One manifest takes at most 100 shipments. Tick fewer and generate it in batches.');
        redirect($back);
    }
    $ids = array_values($ids);

    $result = shipping_generate_manifest($ids);
    if (empty($result['ok'])) {
        $message = trim((string) ($result['message'] ?? ''));
        flash('error', $message !== '' ? $message : 'The courier refused the manifest without saying why.');
        redirect($back);
    }

    // The service logs the manifest; the shipments now carry its link.
    admin_after_write();

    // No flash on the way to a document, for the reason given at 'label' below.
    $url = (string) ($result['url'] ?? '');
    if ($url === '') {
        // The courier keeps no manifest document: the hub's sheet is the one
        // the rider signs.
        $returnPath = admin_safe_return($text('return'), '');
        redirect(admin_url('shipping/manifest.php?shipments=' . implode(',', $ids)
            . ($returnPath !== '' ? '&return=' . rawurlencode($returnPath) : '')));
    }
    $target = $documentTarget($url);
    if ($target !== null) {
        redirect($target);
    }
    flash('warning', 'The courier returned a manifest link that is neither an admin page nor https, so it was not opened.');
    redirect($back);
}

$shipmentId = input_int('shipment_id');
$shipment   = shipment_get($shipmentId);

if ($shipment === null) {
    flash('error', 'That shipment no longer exists.');
    redirect(admin_safe_return($text('return'), admin_url('shipping/shipments.php')));
}

$back = admin_safe_return(
    $text('return'),
    admin_url('shipping/book.php?order=' . (int) $shipment['order_id'])
);

switch ($action) {
    case 'awb':
        // Optional: the aggregator sub-courier to ask for. Without one the
        // courier picks by its own rules.
        $courierId = $text('courier_id');
        if ($courierId !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $courierId) !== 1) {
            flash('error', 'That courier id is not valid.');
            redirect($back);
        }
        $result = shipping_assign_awb($shipmentId, $courierId !== '' ? ['courier_id' => $courierId] : []);
        if (!empty($result['ok'])) {
            log_activity('shipment.awb_assigned', 'shipment', $shipmentId,
                'Requested AWB ' . (string) ($result['awb'] ?? '') . ' for shipment #' . $shipmentId);
        }
        break;

    case 'label':
        // The courier's own label when it hosts one (it carries the routing
        // and sort codes the hub cannot draw); the hub's label otherwise.
        $result = shipping_generate_label($shipmentId);
        if (!empty($result['ok'])) {
            // No flash either way: the label itself is the confirmation, and
            // a courier PDF would leave "Label ready" waiting on whatever
            // admin page is opened next.
            $url = (string) ($result['url'] ?? '');
            if ($url === '') {
                $returnPath = admin_safe_return($text('return'), '');
                redirect(admin_url('shipping/label.php?shipment=' . $shipmentId
                    . ($returnPath !== '' ? '&return=' . rawurlencode($returnPath) : '')));
            }
            $target = $documentTarget($url);
            if ($target !== null) {
                redirect($target);
            }
            flash('warning', 'The courier returned a label link that is neither an admin page nor https, so it was not opened.');
            redirect($back);
        }
        break;

    case 'pickup':
        $date = $text('pickup_date');
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                flash('error', 'The pickup date is not a valid date.');
                redirect($back);
            }
            if ($date < date('Y-m-d')) {
                flash('error', 'A pickup cannot be scheduled in the past.');
                redirect($back);
            }
            // Couriers book pickups a few days out, not years: 2062 typed for
            // 2026 would otherwise be sent, stored and reported as scheduled.
            // book.php's date box carries the same bound as its max.
            if ($date > date('Y-m-d', strtotime('+30 days'))) {
                flash('error', 'A pickup can be scheduled at most 30 days ahead.');
                redirect($back);
            }
        }
        // Blank means "the courier's next slot", which each driver decides.
        $result = shipping_schedule_pickup($shipmentId, $date !== '' ? ['pickup_date' => $date] : []);
        if (!empty($result['ok'])) {
            log_activity('shipment.pickup_scheduled', 'shipment', $shipmentId,
                'Scheduled pickup for shipment #' . $shipmentId
                    . ((string) ($result['pickup_date'] ?? '') !== '' ? ' on ' . $result['pickup_date'] : ''));
        }
        break;

    case 'cancel':
        // One line, bounded: it becomes the timeline message and status detail,
        // which the service cuts at 255 after prefixing "Cancelled: ".
        $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text('reason'))), 0, 200);
        $result = shipping_cancel($shipmentId, $reason);
        break;

    case 'refresh':
        $result = shipping_refresh_tracking($shipmentId);
        break;

    default:
        flash('error', 'Unknown shipment action.');
        redirect($back);
}

$ok = !empty($result['ok']);
if ($ok) {
    // A status change moves the order, and the storefront's order page and
    // sidebar counts read it.
    admin_after_write();
}

$message = trim((string) ($result['message'] ?? ''));
flash($ok ? 'success' : 'error', $message !== '' ? $message : ($ok ? 'Done.' : 'The courier refused without saying why.'));
redirect($back);
