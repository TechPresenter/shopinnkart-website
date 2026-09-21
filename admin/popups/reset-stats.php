<?php
/**
 * ShopInnKart Admin - Zero the counters for one popup.
 *
 * Impressions and conversions are cumulative, so relaunching a popup with new
 * copy leaves the old numbers dragging the conversion rate around. This is the
 * only way to clear them, and the old values go into the activity log first.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('banners.edit');

require_once __DIR__ . '/_shared.php';

$id = input_int('id');
$popup = $id > 0
    ? Database::fetch(
        'SELECT `id`, `name`, `display_mode`, `impressions`, `conversions` FROM `popups` WHERE `id` = :id',
        ['id' => $id]
    )
    : null;

if ($popup === null) {
    flash('error', 'That popup no longer exists.');
    redirect(admin_url('popups/'));
}

$impressions = (int) $popup['impressions'];
$conversions = (int) $popup['conversions'];

Database::update('popups', ['impressions' => 0, 'conversions' => 0], '`id` = :id', ['id' => $id]);

log_activity(
    'popup.stats_reset',
    'popup',
    $id,
    'Reset stats for "' . $popup['name'] . '" — was ' . $impressions . ' impressions, '
        . $conversions . ' conversions ('
        . number_format(popup_conversion_rate($impressions, $conversions), 1) . '%)'
);
admin_after_write();

flash(
    'success',
    'Stats cleared for "' . $popup['name'] . '". Previous total: '
        . number_format($impressions) . ' impressions, ' . number_format($conversions) . ' conversions.'
);
redirect_back(admin_url('popups/edit.php?id=' . $id));
