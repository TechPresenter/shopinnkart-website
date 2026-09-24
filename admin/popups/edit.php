<?php
/**
 * ShopInnKart Admin - Edit a popup or pop-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.edit');

require_once __DIR__ . '/_shared.php';

$id = input_int('id');
$popup = $id > 0
    ? Database::fetch('SELECT * FROM `popups` WHERE `id` = :id', ['id' => $id])
    : null;

if ($popup === null) {
    flash('error', 'That popup no longer exists.');
    redirect(admin_url('popups/'));
}

$errors = [];

if (is_post()) {
    csrf_require();

    $submitted = popup_form_input();
    $popup = array_merge($popup, $submitted);
    $errors = popup_validate_input($submitted);

    if ($errors !== []) {
        flash('error', 'Please correct the highlighted fields.');
    } else {
        // popup_form_input() carries no image keys, so $popup still holds the
        // paths that were loaded from the row. They only change when a new
        // file arrives or the admin explicitly cleared the old one.
        $image = $popup['image'];
        if ((string) input('remove_image', '0') === '1' && empty($_FILES['image']['name'])) {
            delete_upload($image);
            $image = null;
        }
        $image = admin_handle_image('image', 'popups', $image);

        $mobile = $popup['mobile_image'];
        if ((string) input('remove_mobile_image', '0') === '1' && empty($_FILES['mobile_image']['name'])) {
            delete_upload($mobile);
            $mobile = null;
        }
        $mobile = admin_handle_image('mobile_image', 'popups', $mobile);

        Database::update(
            'popups',
            popup_row_from_input($submitted, $image, $mobile),
            '`id` = :id',
            ['id' => $id]
        );

        log_activity(
            'popup.updated',
            'popup',
            $id,
            'Updated ' . $submitted['display_mode'] . ' "' . $submitted['name'] . '"'
        );
        admin_after_write();

        flash('success', 'Popup "' . $submitted['name'] . '" saved.');
        redirect(admin_url('popups/edit.php?id=' . $id));
    }
}

$impressions = (int) $popup['impressions'];
$conversions = (int) $popup['conversions'];
$rate        = popup_conversion_rate($impressions, $conversions);
$schedule    = popup_schedule_state($popup['start_date'] ?? null, $popup['end_date'] ?? null);
$mode        = (string) ($popup['display_mode'] ?? 'popup');

$isEdit       = true;
$pageTitle    = 'Edit ' . (popup_display_modes()[$mode] ?? 'Popup');
$pageSubtitle = $popup['name'] . ' · ' . popup_trigger_summary(
    (string) $popup['trigger_type'],
    (int) $popup['trigger_value']
) . ' · ' . popup_pages_label((string) $popup['display_pages']);
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Popups',    'url' => admin_url('popups/?mode=' . urlencode($mode))],
    ['label' => (string) $popup['name']],
];

$pageActions = '';
if ($impressions > 0 || $conversions > 0) {
    $pageActions .= '<form method="post" action="' . e(admin_url('popups/reset-stats.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs(
            'The impression and conversion numbers for "' . $popup['name'] . '" cannot be recovered.',
            ['title' => 'Reset the counters?', 'label' => 'Reset counters']
        ) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn">' . icon('refresh', 'w-4 h-4') . ' Reset Stats</button>'
        . '</form>';
}
if (admin_can('banners.delete')) {
    // admin_delete_form() renders a 34px icon button, which cannot hold a text
    // label, so the page-level action builds the same POST form by hand.
    $confirm = 'Delete "' . $popup['name'] . '"? Its stats and images go with it. This cannot be undone.';
    $pageActions .= '<form method="post" action="' . e(admin_url('popups/delete.php')) . '" class="ad-inline-form"'
        . admin_confirm_form_attrs($confirm, ['label' => 'Delete']) . '>'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-btn ad-btn--danger">' . icon('trash', 'w-4 h-4') . ' Delete</button>'
        . '</form>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Impressions', number_format($impressions), 'eye', 'violet', 'Times shown to visitors') ?>
    <?= admin_stat_card('Conversions', number_format($conversions), 'check-circle', 'green', 'Signups, copies and clicks') ?>
    <?= admin_stat_card('Conversion rate', $impressions > 0 ? number_format($rate, 1) . '%' : '—', 'percent', 'primary',
        $impressions > 0 ? 'Of everyone who saw it' : 'No impressions recorded yet') ?>
    <?= admin_stat_card('Schedule', $schedule['label'], 'calendar',
        $schedule['state'] === 'expired' ? 'red' : ($schedule['state'] === 'scheduled' ? 'blue' : 'navy'),
        $popup['status'] === 'active' ? 'Currently enabled' : 'Currently disabled') ?>
</div>

<?php if ($schedule['state'] === 'expired'): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            This popup ended on <?= e(format_datetime((string) $popup['end_date'])) ?> and is no longer
            served, even though its status is <?= e($popup['status']) ?>. Clear or extend the end date to bring it back.
        </div>
    </div>
<?php elseif ($schedule['state'] === 'scheduled'): ?>
    <div class="sik-alert sik-alert--info">
        <?= icon('clock', 'w-5 h-5') ?>
        <div>Scheduled to start on <?= e(format_datetime((string) $popup['start_date'])) ?>. Nothing is shown before then.</div>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/_form.php';
require ADMIN_PATH . '/includes/footer.php';
