<?php
/**
 * ShopInnKart Admin - General settings.
 *
 * Store identity, the contact details the storefront prints in the header,
 * footer and invoices, and the maintenance switch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

$spec = [
    'store_name' => [
        'type' => 'text', 'label' => 'Store name', 'required' => true, 'max' => 100,
        'help' => 'Browser title, emails and the admin header.',
    ],
    'store_tagline' => [
        'type' => 'text', 'label' => 'Tagline', 'max' => 150,
        'placeholder' => 'Shop Smart. Live Better.',
    ],
    'store_description' => [
        'type' => 'textarea', 'label' => 'Store description', 'max' => 500, 'rows' => 4,
        'help' => 'Footer, and the fallback meta description.',
    ],
    'store_logo' => [
        'type' => 'image', 'label' => 'Primary logo', 'folder' => 'branding',
        'help' => 'For light backgrounds. SVG or transparent PNG.',
    ],
    'store_logo_light' => [
        'type' => 'image', 'label' => 'Light logo', 'folder' => 'branding',
        'help' => 'Dark footer and admin sidebar.',
    ],
    'store_favicon' => [
        'type' => 'image', 'label' => 'Favicon', 'folder' => 'branding',
        'help' => 'Browser tab icon. Square SVG or 32x32 PNG.',
    ],
    'store_email' => [
        'type' => 'email', 'label' => 'Support email', 'required' => true, 'max' => 190,
        'help' => 'Reply-to on every email, and in the footer.',
    ],
    'store_phone' => [
        'type' => 'tel', 'label' => 'Support phone', 'max' => 30,
        'placeholder' => '+91 98765 43210',
    ],
    'store_whatsapp' => [
        'type' => 'text', 'label' => 'WhatsApp number', 'max' => 20,
        'help' => 'Country code, no +, e.g. 919876543210. Blank hides the button.',
    ],
    'store_address' => [
        'type' => 'textarea', 'label' => 'Registered address', 'max' => 400, 'rows' => 3,
        'help' => 'Footer, and the foot of every email.',
    ],
    'business_hours' => [
        'type' => 'text', 'label' => 'Business hours', 'max' => 150,
        'placeholder' => 'Mon - Sat: 9:00 AM to 8:00 PM IST',
    ],
    'gst_number' => [
        'type' => 'text', 'label' => 'GSTIN', 'max' => 20,
        'help' => 'Printed on invoices. 15 characters.',
    ],
    'copyright_text' => [
        'type' => 'text', 'label' => 'Copyright line', 'max' => 200,
        'placeholder' => '© ' . date('Y') . ' ShopInnKart. All Rights Reserved.',
    ],
    'credit_text' => [
        'type' => 'text', 'label' => 'Credit line', 'max' => 160,
        'placeholder' => 'Design and developed by AppsGain Technologies',
        'help'  => 'Beside the copyright. Blank hides it.',
    ],
    'credit_url' => [
        'type' => 'url', 'label' => 'Credit link', 'max' => 255,
        'placeholder' => 'https://appsgain.in',
        'help'  => 'Blank prints it as plain text.',
    ],
    'maintenance_mode' => [
        'type' => 'bool', 'label' => 'Maintenance mode',
    ],
    'maintenance_message' => [
        'type' => 'textarea', 'label' => 'Maintenance message', 'max' => 500, 'rows' => 3,
        'help' => 'Shown on the holding page.',
    ],
];

if (is_post()) {
    settings_handle_save('general', 'general', $spec, settings_group('general'));
}

$stored = settings_group('general');
$errors = errors_pull();
$values = settings_values($spec, $stored);

$maintenanceOn = ($stored['maintenance_mode'] ?? '0') === '1';

$pageTitle    = 'General Settings';
$pageSubtitle = 'Store identity, contact details and the maintenance switch.';
$breadcrumbs  = settings_breadcrumbs('general');
$pageActions  = '<a class="ad-btn" href="' . e(url()) . '" target="_blank" rel="noopener">'
    . icon('external', 'w-4 h-4') . ' View storefront</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('general') ?>

<?php if ($maintenanceOn): ?>
    <div class="sik-alert sik-alert--warning">
        <?= icon('alert', 'w-5 h-5') ?>
        <div>
            <strong>The storefront is offline.</strong>
            Every visitor gets a 503. Admins already signed in can still browse.
        </div>
    </div>
<?php endif; ?>

<form class="ad-form" method="post" enctype="multipart/form-data" data-guard-unsaved>
    <?= csrf_field() ?>

    <div class="ad-grid ad-grid--sidebar">
        <div style="display:grid;gap:18px">

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Identity</div>
                        <div class="ad-card__sub">How the store introduces itself everywhere.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <?= settings_field('store_name', $spec, $values, $errors) ?>
                        <?= settings_field('store_tagline', $spec, $values, $errors) ?>
                    </div>
                    <?= settings_field('store_description', $spec, $values, $errors) ?>
                    <?= settings_field('copyright_text', $spec, $values, $errors) ?>
                    <div class="ad-grid ad-grid--2">
                        <?= settings_field('credit_text', $spec, $values, $errors) ?>
                        <?= settings_field('credit_url', $spec, $values, $errors) ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Logos &amp; favicon</div>
                        <div class="ad-card__sub">
                            Up to <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?> each. Removing one restores the default.
                        </div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--3">
                        <?= settings_field('store_logo', $spec, $values, $errors) ?>
                        <?= settings_field('store_logo_light', $spec, $values, $errors) ?>
                        <?= settings_field('store_favicon', $spec, $values, $errors) ?>
                    </div>
                </div>
            </div>

            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div>
                        <div class="ad-card__title">Contact &amp; legal</div>
                        <div class="ad-card__sub">Printed in the footer, on invoices and in every email.</div>
                    </div>
                </div>
                <div class="ad-card__body">
                    <div class="ad-row ad-row--2">
                        <?= settings_field('store_email', $spec, $values, $errors) ?>
                        <?= settings_field('store_phone', $spec, $values, $errors) ?>
                    </div>
                    <div class="ad-row ad-row--2">
                        <?= settings_field('store_whatsapp', $spec, $values, $errors) ?>
                        <?= settings_field('business_hours', $spec, $values, $errors) ?>
                    </div>
                    <?= settings_field('store_address', $spec, $values, $errors) ?>
                    <?= settings_field('gst_number', $spec, $values, $errors) ?>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:18px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Maintenance mode</div>
                </div>
                <div class="ad-card__body" style="display:grid;gap:14px">
                    <div class="sik-alert sik-alert--warning" style="margin:0">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <div><strong>Takes the whole storefront offline.</strong> Shoppers, crawlers and the API all get a 503.</div>
                    </div>

                    <?= settings_field('maintenance_mode', $spec, $values, $errors) ?>
                    <?= settings_field('maintenance_message', $spec, $values, $errors) ?>

                    <details>
                        <summary>What stays reachable</summary>
                        <p>
                            The 503 holding page carries the message above. Admins already signed in
                            keep browsing the shop, and the admin panel itself stays reachable.
                        </p>
                    </details>

                    <p class="ad-muted" style="font-size:var(--ad-text-xs)">
                        Current state:
                        <?= $maintenanceOn
                            ? '<span class="sik-status sik-status--red">Storefront offline</span>'
                            : '<span class="sik-status sik-status--green">Storefront live</span>' ?>
                    </p>
                </div>
                <?= settings_save_bar() ?>
            </div>
        </div>
    </div>
</form>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
