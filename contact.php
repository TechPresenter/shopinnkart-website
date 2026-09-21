<?php
/**
 * ShopInnKart - Contact Us.
 *
 * The copy comes from the `contact-us` CMS row, the details from settings.
 * The form posts to api/contact/send.php through [data-ajax-form]; without
 * JavaScript it posts back here and is handled by the block below.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

$page = cms_page('contact-us');

// ---------------------------------------------------------------------------
//  No-JavaScript fallback for the contact form.
//  Mirrors api/contact/send.php: same rules, same table, same rate window.
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    // Shared with the API endpoint so the two paths cannot be used to double
    // the quota.
    $bucket = '_rate_contact_send';
    $now    = time();
    $state  = $_SESSION[$bucket] ?? ['count' => 0, 'reset' => $now + 900];
    if ($now > $state['reset']) {
        $state = ['count' => 0, 'reset' => $now + 900];
    }
    $state['count']++;
    $_SESSION[$bucket] = $state;

    if ($state['count'] > 3) {
        flash('error', 'Too many messages from this session. Please try again in a few minutes.');
        redirect(url('contact.php') . '#sikContactForm');
    }

    $v = new Validator($_POST, [
        'name'    => 'Name',
        'email'   => 'Email address',
        'phone'   => 'Phone number',
        'subject' => 'Subject',
        'message' => 'Message',
    ]);
    $v->required('name')->min('name', 2)->max('name', 150)
      ->required('email')->email('email')->max('email', 190)
      ->phone('phone')
      ->required('subject')->min('subject', 3)->max('subject', 200)
      ->required('message')->min('message', 10)->max('message', 5000);

    if ($v->fails()) {
        flash_old($_POST);
        flash_errors($v->errors());
        flash('error', 'Please correct the highlighted fields.');
        redirect(url('contact.php') . '#sikContactForm');
    }

    Database::insert('contact_messages', [
        'name'       => (string) input('name', ''),
        'email'      => mb_strtolower((string) input('email', '')),
        'phone'      => normalize_phone((string) input('phone', '')),
        'subject'    => (string) input('subject', ''),
        'message'    => (string) input('message', ''),
        'status'     => 'new',
        'ip_address' => client_ip(),
    ]);

    old_clear();
    flash('success', 'Thanks for writing in. Our support team replies within one business day.');
    redirect(url('contact.php') . '#sikContactForm');
}

seo_from_page('contact');
seo_set(['canonical' => url('contact')]);
if ($page !== null) {
    seo_add_schema(seo_breadcrumb_schema([
        ['label' => 'Home', 'url' => url()],
        ['label' => (string) $page['title'], 'url' => url('contact')],
    ]));
}

$phone    = trim((string) setting('store_phone', ''));
$email    = trim((string) setting('store_email', ''));
$address  = trim((string) setting('store_address', ''));
$hours    = trim((string) setting('business_hours', ''));
$whatsapp = preg_replace('/\D/', '', (string) setting('store_whatsapp', ''));

$cards = [];
if ($phone !== '') {
    $cards[] = [
        'icon'  => 'phone',
        'title' => 'Call us',
        'value' => $phone,
        'note'  => $hours !== '' ? $hours : 'Our support line is open on working days.',
        'href'  => 'tel:' . preg_replace('/\s+/', '', $phone),
    ];
}
if ($email !== '') {
    $cards[] = [
        'icon'  => 'mail',
        'title' => 'Email us',
        'value' => $email,
        'note'  => 'We reply to every message within one business day.',
        'href'  => 'mailto:' . $email,
    ];
}
if ($address !== '') {
    $cards[] = [
        'icon'  => 'location',
        'title' => 'Visit us',
        'value' => $address,
        'note'  => 'Walk-in support and pickups by prior appointment.',
        'href'  => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address),
        'external' => true,
    ];
}
if ($hours !== '') {
    $cards[] = [
        'icon'  => 'clock',
        'title' => 'Business hours',
        'value' => $hours,
        'note'  => 'Orders placed outside these hours are picked up the next working day.',
        'href'  => null,
    ];
}

$errors   = errors_pull();
$subjects = [
    'Order status or delivery',
    'Returns and refunds',
    'Warranty or repair',
    'Product advice before buying',
    'Payment or invoice',
    'Bulk and corporate orders',
    'Something else',
];
$oldSubject = (string) old('subject', '');

require INCLUDES_PATH . '/header.php';

// The essentials come from settings, so the page still works if the CMS row
// has not been created yet.
echo cms_page_banner($page ?? [
    'title'        => 'Contact Us',
    'banner_image' => null,
    'updated_at'   => null,
], [
    'subtitle' => 'Questions before you buy, or help with an order you have already placed.',
    'updated'  => false,
]);
?>

<div class="sik-container sik-section sik-section--sm">

    <?php if ($cards !== []): ?>
        <div class="sik-grid" style="--cols-desktop:4;--cols-tablet:2;--cols-mobile:1">
            <?php foreach ($cards as $i => $card): ?>
                <div class="sik-card" style="padding:var(--sp-5)" data-anim="fade-up" data-anim-delay="<?= (int) $i * 80 ?>">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;
                                 border-radius:50%;background:var(--sik-primary-soft);color:var(--sik-primary);margin-bottom:var(--sp-3)">
                        <?= icon($card['icon'], 'w-5 h-5') ?>
                    </span>
                    <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--sik-muted)">
                        <?= e($card['title']) ?>
                    </h2>
                    <p style="font-size:14.5px;font-weight:600;margin-top:var(--sp-2);line-height:1.5;overflow-wrap:anywhere">
                        <?php if (!empty($card['href'])): ?>
                            <a href="<?= e($card['href']) ?>"
                               <?= !empty($card['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($card['value']) ?></a>
                        <?php else: ?>
                            <?= e($card['value']) ?>
                        <?php endif; ?>
                    </p>
                    <p style="font-size:12.5px;color:var(--sik-muted);margin-top:var(--sp-2);line-height:1.6"><?= e($card['note']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="sik-empty">
            <?= icon('headset', 'w-12 h-12') ?>
            <h2 class="sik-empty__title">No contact details published yet</h2>
            <p class="sik-empty__text">Add a phone number, email address, postal address and business hours in
                Admin &rsaquo; Settings &rsaquo; Store, and they will appear here.</p>
            <a class="sik-btn sik-btn--outline" href="<?= e(admin_url('settings/')) ?>">Open Store Settings</a>
        </div>
    <?php endif; ?>

    <?php if ($whatsapp !== ''): ?>
        <div style="margin-top:var(--sp-7);border-radius:var(--sik-radius-lg);background:var(--sik-navy);color:#fff;
                    padding:var(--sp-6);display:flex;flex-wrap:wrap;gap:var(--sp-4);align-items:center;justify-content:space-between">
            <div style="min-width:0">
                <h2 style="font-size:17px;font-weight:700;color:#fff;display:flex;align-items:center;gap:var(--sp-2)">
                    <?= icon('whatsapp', 'w-5 h-5') ?> Chat with us on WhatsApp
                </h2>
                <p style="font-size:13.5px;color:rgba(255,255,255,.76);margin-top:var(--sp-2);line-height:1.6;max-width:520px">
                    Send your order number and a photo of the issue &mdash; it is usually the fastest way to get a fix.
                </p>
            </div>
            <a class="sik-btn sik-btn--primary"
               href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener noreferrer">
                <?= icon('whatsapp', 'w-4 h-4') ?> Start a chat
            </a>
        </div>
    <?php endif; ?>

    <div style="display:grid;gap:var(--sp-7);margin-top:var(--sp-8)" class="lg:grid-cols-2">

        <div>
            <?php if ($page !== null): ?>
                <?= cms_page_body($page) ?>
            <?php else: ?>
                <div class="sik-empty sik-empty--sm">
                    <?= icon('edit', 'w-12 h-12') ?>
                    <h2 class="sik-empty__title">Contact copy is not published yet</h2>
                    <p class="sik-empty__text">Create a page with the slug <strong>contact-us</strong> to explain
                        how your support team works. The form beside this already works.</p>
                    <a class="sik-btn sik-btn--outline" href="<?= e(admin_url('pages/')) ?>">Manage Pages</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="sik-panel" id="sikContactForm" style="align-self:start">
            <div class="sik-panel__head">
                <h2 class="sik-panel__title">Send us a message</h2>
            </div>
            <div class="sik-panel__body">
                <form method="post" action="<?= e(url('contact.php')) ?>"
                      data-ajax-form="contact/send.php" data-reset-on-success="true" novalidate>
                    <?= csrf_field() ?>

                    <div class="sik-field">
                        <label class="sik-label" for="contactName">Your name <span class="req">*</span></label>
                        <input class="sik-input<?= error_for($errors, 'name') !== '' ? ' is-invalid' : '' ?>"
                               id="contactName" name="name" type="text" required maxlength="150"
                               autocomplete="name" value="<?= e(old('name')) ?>">
                        <?php if ($msg = error_for($errors, 'name')): ?>
                            <span class="sik-error"><?= e($msg) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="contactEmail">Email address <span class="req">*</span></label>
                        <input class="sik-input<?= error_for($errors, 'email') !== '' ? ' is-invalid' : '' ?>"
                               id="contactEmail" name="email" type="email" required maxlength="190"
                               autocomplete="email" value="<?= e(old('email')) ?>">
                        <?php if ($msg = error_for($errors, 'email')): ?>
                            <span class="sik-error"><?= e($msg) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="contactPhone">Phone number</label>
                        <input class="sik-input<?= error_for($errors, 'phone') !== '' ? ' is-invalid' : '' ?>"
                               id="contactPhone" name="phone" type="tel" inputmode="numeric" maxlength="20"
                               autocomplete="tel" value="<?= e(old('phone')) ?>">
                        <?php if ($msg = error_for($errors, 'phone')): ?>
                            <span class="sik-error"><?= e($msg) ?></span>
                        <?php else: ?>
                            <span class="sik-help">Optional. Helps us call you back on anything urgent.</span>
                        <?php endif; ?>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="contactSubject">What is this about? <span class="req">*</span></label>
                        <select class="sik-select<?= error_for($errors, 'subject') !== '' ? ' is-invalid' : '' ?>"
                                id="contactSubject" name="subject" required>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= e($subject) ?>" <?= $oldSubject === $subject ? 'selected' : '' ?>>
                                    <?= e($subject) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($msg = error_for($errors, 'subject')): ?>
                            <span class="sik-error"><?= e($msg) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="contactMessage">Message <span class="req">*</span></label>
                        <textarea class="sik-textarea<?= error_for($errors, 'message') !== '' ? ' is-invalid' : '' ?>"
                                  id="contactMessage" name="message" required minlength="10" maxlength="5000"
                                  placeholder="Include your order number if you have one."><?= e(old('message')) ?></textarea>
                        <?php if ($msg = error_for($errors, 'message')): ?>
                            <span class="sik-error"><?= e($msg) ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="sik-btn sik-btn--primary sik-btn--block" type="submit">
                        <span class="sik-btn__label">Send message</span>
                    </button>
                    <p class="sik-help" style="text-align:center;margin-top:var(--sp-3)">
                        We use your details only to answer this enquiry. See our
                        <a href="<?= e(url('privacy-policy.php')) ?>" style="color:var(--sik-primary-ink)">privacy policy</a>.
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
old_clear();
require INCLUDES_PATH . '/footer.php';
