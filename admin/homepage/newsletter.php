<?php
/**
 * ShopInnKart Admin - Newsletter section.
 *
 * A focused editor for the newsletter band: the copy the widget actually
 * renders, where it sits, and the welcome mail a new subscriber receives.
 * Anything the newsletter widget ignores (columns, card styles, carousel
 * speed) is deliberately absent — the full section editor still has it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('homepage.edit');

require_once __DIR__ . '/_meta.php';

$sections = Database::fetchAll(
    "SELECT * FROM `homepage_sections` WHERE `widget_type` = 'newsletter' ORDER BY `zone` ASC, `sort_order` ASC, `id` ASC"
);

$requestedId = input_int('id');
$section = null;
foreach ($sections as $row) {
    if ($requestedId > 0 && (int) $row['id'] === $requestedId) {
        $section = $row;
        break;
    }
}
$section = $section ?? ($sections[0] ?? null);

$canEditTemplate = admin_can('newsletter.edit');
$template = Database::fetch(
    "SELECT * FROM `notification_templates` WHERE `template_key` = 'newsletter_welcome' AND `channel` = 'email' LIMIT 1"
);

$errors = [];

// ---------------------------------------------------------------------------
//  Writes
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    $intent = (string) input('intent', 'section');

    if ($intent === 'template') {
        if (!$canEditTemplate || $template === null) {
            flash('error', 'You do not have permission to edit the welcome email.');
            redirect(admin_url('homepage/newsletter.php'));
        }

        $submitted = [
            'subject' => (string) input('subject', ''),
            'body'    => sanitize_html((string) input('body', '')),
            'status'  => input_bool('template_status') ? 'active' : 'inactive',
        ];

        $v = new Validator($submitted, ['subject' => 'Subject', 'body' => 'Email body']);
        $v->required('subject')->max('subject', 255)->required('body');

        if ($v->fails()) {
            $errors = $v->errors();
            $template = array_merge($template, $submitted);
            flash('error', 'Please correct the highlighted fields.');
        } else {
            Database::update('notification_templates', $submitted, '`id` = :id', ['id' => (int) $template['id']]);

            log_activity('notification_template.updated', 'notification_template', (int) $template['id'],
                'Updated the newsletter welcome email');
            admin_after_write();

            flash('success', 'Welcome email saved.');
            redirect(admin_url('homepage/newsletter.php' . ($section !== null ? '?id=' . (int) $section['id'] : '')));
        }
    } else {
        if ($section === null) {
            flash('error', 'There is no newsletter section to edit yet.');
            redirect(admin_url('homepage/newsletter.php'));
        }

        $submitted = [
            'zone'              => (string) input('zone', 'home'),
            'title'             => (string) input('title', ''),
            'title_accent'      => (string) input('title_accent', ''),
            'subtitle'          => (string) input('subtitle', ''),
            'link_text'         => (string) input('link_text', ''),
            'link_url'          => (string) input('link_url', ''),
            'device_visibility' => (string) input('device_visibility', 'all'),
            'auth_visibility'   => (string) input('auth_visibility', 'all'),
            'start_date'        => homepage_datetime('start_date'),
            'end_date'          => homepage_datetime('end_date'),
            'sort_order'        => input_int('sort_order', 0),
            'status'            => (string) input('status', 'active'),
        ];

        $v = new Validator($submitted, ['sort_order' => 'Sort order']);
        $v->in('zone', array_keys(homepage_zones()))
          ->in('device_visibility', array_keys(homepage_device_visibility()))
          ->in('auth_visibility', array_keys(homepage_auth_visibility()))
          ->in('status', ['active', 'inactive'])
          ->between('sort_order', 0, 9999)
          ->max('title', 150)->max('title_accent', 150)->max('subtitle', 255)
          ->max('link_text', 60)->max('link_url', 255);

        if ($submitted['start_date'] !== null && $submitted['end_date'] !== null
            && strtotime($submitted['end_date']) <= strtotime($submitted['start_date'])) {
            $v->rule('end_date', false, 'The end date must be after the start date.');
        }

        if ($v->fails()) {
            $errors = $v->errors();
            $section = array_merge($section, $submitted);
            flash('error', 'Please correct the highlighted fields.');
        } else {
            foreach (['title', 'title_accent', 'subtitle', 'link_text', 'link_url'] as $field) {
                if (trim((string) $submitted[$field]) === '') {
                    $submitted[$field] = null;
                }
            }

            Database::update('homepage_sections', $submitted, '`id` = :id', ['id' => (int) $section['id']]);

            log_activity('homepage_section.updated', 'homepage_section', (int) $section['id'],
                'Updated the newsletter section "' . $section['section_key'] . '"');
            admin_after_write();

            flash('success', 'Newsletter section saved.');
            redirect(admin_url('homepage/newsletter.php?id=' . (int) $section['id']));
        }
    }
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$subscribers = [
    'active'       => Database::count('newsletter_subscribers', "`status` = 'active'"),
    'recent'       => (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `newsletter_subscribers`
         WHERE `status` = 'active' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    ),
    'unsubscribed' => Database::count('newsletter_subscribers', "`status` = 'unsubscribed'"),
];

$dtValue = static function ($value): string {
    return empty($value) ? '' : date('Y-m-d\TH:i', (int) strtotime((string) $value));
};

$pageTitle    = 'Newsletter Section';
$pageSubtitle = 'The email capture band and the mail a new subscriber gets.';
$breadcrumbs  = [
    ['label' => 'Dashboard',        'url' => admin_url('dashboard.php')],
    ['label' => 'Homepage Builder', 'url' => admin_url('homepage/')],
    ['label' => 'Newsletter'],
];
$pageActions = '';
if (admin_can('newsletter.view')) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('newsletter/')) . '">'
        . icon('users', 'w-4 h-4') . ' Subscribers</a>';
}
if ($section !== null) {
    $pageActions .= '<a class="ad-btn" href="' . e(admin_url('homepage/edit.php?id=' . (int) $section['id'])) . '">'
        . icon('settings', 'w-4 h-4') . ' Full section editor</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-grid ad-grid--3" style="margin-bottom:18px">
    <?= admin_stat_card('Active subscribers', number_format($subscribers['active']), 'mail', 'primary',
        '+' . number_format($subscribers['recent']) . ' in the last 30 days',
        admin_can('newsletter.view') ? admin_url('newsletter/') : null) ?>
    <?= admin_stat_card('Unsubscribed', number_format($subscribers['unsubscribed']), 'logout', 'amber',
        'Kept on file so they are not re-added silently') ?>
    <?= admin_stat_card('Welcome email',
        $template === null ? 'Missing' : ucfirst((string) $template['status']),
        'check-circle', $template !== null && $template['status'] === 'active' ? 'green' : 'red',
        $template === null ? 'No template row exists' : 'Queued the moment someone subscribes') ?>
</div>

<?php if ($sections === []): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <?= admin_empty(
                'No newsletter section yet',
                'Add a section with the "Newsletter band" widget type and its copy becomes editable here.',
                'Add Section',
                admin_url('homepage/create.php?zone=home'),
                'mail'
            ) ?>
        </div>
    </div>
<?php else: ?>

    <?php if (count($sections) > 1): ?>
        <div class="ad-card">
            <div class="ad-tabs">
                <?php foreach ($sections as $row): ?>
                    <a class="ad-tab <?= (int) $row['id'] === (int) $section['id'] ? 'is-active' : '' ?>"
                       href="<?= e(admin_url('homepage/newsletter.php?id=' . (int) $row['id'])) ?>">
                        <?= e(homepage_zones()[(string) $row['zone']]['label'] ?? $row['zone']) ?>
                        <span class="ad-tab__count"><?= e($row['section_key']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ad-grid ad-grid--sidebar">
        <div>
            <form class="ad-form" method="post" data-guard-unsaved>
                <?= csrf_field() ?>
                <input type="hidden" name="intent" value="section">

                <div class="ad-card" style="margin:0">
                    <div class="ad-card__head">
                        <div>
                            <div class="ad-card__title">Band copy</div>
                            <div class="ad-card__sub">
                                Section <span class="ad-mono"><?= e($section['section_key']) ?></span> —
                                these are the only fields the newsletter widget renders.
                            </div>
                        </div>
                    </div>
                    <div class="ad-card__body">
                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="nlTitle">Title</label>
                                <input class="sik-input" type="text" id="nlTitle" name="title" maxlength="150"
                                       value="<?= e($section['title'] ?? '') ?>" placeholder="STAY IN THE">
                                <span class="sik-help">Falls back to "Join the ShopInnKart Family" when left blank.</span>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlAccent">Accent words</label>
                                <input class="sik-input" type="text" id="nlAccent" name="title_accent" maxlength="150"
                                       value="<?= e($section['title_accent'] ?? '') ?>" placeholder="LOOP">
                            </div>
                        </div>

                        <div class="ad-field">
                            <label class="sik-label" for="nlSubtitle">Subtitle</label>
                            <input class="sik-input" type="text" id="nlSubtitle" name="subtitle" maxlength="255"
                                   value="<?= e($section['subtitle'] ?? '') ?>"
                                   placeholder="Get ₹500 off your first order plus early access to every flash sale">
                        </div>

                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="nlLinkText">Link text</label>
                                <input class="sik-input" type="text" id="nlLinkText" name="link_text" maxlength="60"
                                       value="<?= e($section['link_text'] ?? '') ?>" placeholder="SUBSCRIBE">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlLinkUrl">Link URL</label>
                                <input class="sik-input<?= isset($errors['link_url']) ? ' is-invalid' : '' ?>" type="text"
                                       id="nlLinkUrl" name="link_url" maxlength="255"
                                       value="<?= e($section['link_url'] ?? '') ?>" placeholder="#newsletter-form">
                                <?php if (isset($errors['link_url'])): ?>
                                    <span class="sik-error"><?= e($errors['link_url']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="ad-card__foot">
                        <a class="ad-btn" target="_blank" rel="noopener" href="<?= e(homepage_preview_url($section)) ?>">
                            <?= icon('external', 'w-4 h-4') ?> Preview
                        </a>
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save Section
                        </button>
                    </div>
                </div>

                <div class="ad-card">
                    <div class="ad-card__head"><div class="ad-card__title">Placement &amp; visibility</div></div>
                    <div class="ad-card__body">
                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="nlZone">Zone</label>
                                <select class="sik-select" id="nlZone" name="zone">
                                    <?php foreach (homepage_zones() as $key => $meta): ?>
                                        <option value="<?= e_attr($key) ?>"
                                            <?= (string) $section['zone'] === $key ? 'selected' : '' ?>>
                                            <?= e($meta['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlSort">Sort order</label>
                                <input class="sik-input<?= isset($errors['sort_order']) ? ' is-invalid' : '' ?>"
                                       type="number" id="nlSort" name="sort_order" min="0" max="9999" step="1"
                                       value="<?= (int) $section['sort_order'] ?>">
                                <?php if (isset($errors['sort_order'])): ?>
                                    <span class="sik-error"><?= e($errors['sort_order']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ad-row ad-row--2">
                            <div class="ad-field">
                                <label class="sik-label" for="nlDevice">Devices</label>
                                <select class="sik-select" id="nlDevice" name="device_visibility">
                                    <?= admin_options(homepage_device_visibility(), $section['device_visibility']) ?>
                                </select>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlAuth">Audience</label>
                                <select class="sik-select" id="nlAuth" name="auth_visibility">
                                    <?= admin_options(homepage_auth_visibility(), $section['auth_visibility']) ?>
                                </select>
                                <span class="sik-help">"Signed-out visitors" hides the band from customers who already have an account.</span>
                            </div>
                        </div>

                        <div class="ad-row ad-row--3">
                            <div class="ad-field">
                                <label class="sik-label" for="nlStart">Starts</label>
                                <input class="sik-input" type="datetime-local" id="nlStart" name="start_date"
                                       value="<?= e($dtValue($section['start_date'])) ?>">
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlEnd">Ends</label>
                                <input class="sik-input<?= isset($errors['end_date']) ? ' is-invalid' : '' ?>"
                                       type="datetime-local" id="nlEnd" name="end_date"
                                       value="<?= e($dtValue($section['end_date'])) ?>">
                                <?php if (isset($errors['end_date'])): ?>
                                    <span class="sik-error"><?= e($errors['end_date']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ad-field">
                                <label class="sik-label" for="nlStatus">Status</label>
                                <select class="sik-select" id="nlStatus" name="status">
                                    <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], $section['status']) ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="ad-card__foot">
                        <button type="submit" class="ad-btn ad-btn--primary">
                            <?= icon('check', 'w-4 h-4') ?> Save Section
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($template !== null && $canEditTemplate): ?>
                <form class="ad-form" method="post" data-guard-unsaved>
                    <?= csrf_field() ?>
                    <input type="hidden" name="intent" value="template">

                    <div class="ad-card">
                        <div class="ad-card__head">
                            <div>
                                <div class="ad-card__title">Welcome email</div>
                                <div class="ad-card__sub">Queued once, only for genuinely new subscribers.</div>
                            </div>
                        </div>
                        <div class="ad-card__body">
                            <div class="ad-field">
                                <label class="sik-label" for="nlSubject">Subject <span class="req">*</span></label>
                                <input class="sik-input<?= isset($errors['subject']) ? ' is-invalid' : '' ?>" type="text"
                                       id="nlSubject" name="subject" maxlength="255" required
                                       value="<?= e($template['subject'] ?? '') ?>">
                                <?php if (isset($errors['subject'])): ?>
                                    <span class="sik-error"><?= e($errors['subject']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="ad-field">
                                <label class="sik-label" for="nlBody">Body <span class="req">*</span></label>
                                <textarea class="sik-textarea<?= isset($errors['body']) ? ' is-invalid' : '' ?>"
                                          id="nlBody" name="body" rows="10" required
                                          style="min-height:210px"><?= e($template['body'] ?? '') ?></textarea>
                                <?php if (isset($errors['body'])): ?>
                                    <span class="sik-error"><?= e($errors['body']) ?></span>
                                <?php else: ?>
                                    <span class="sik-help">
                                        Placeholders: <span class="ad-mono"><?= e((string) ($template['variables'] ?? '')) ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <label class="ad-switch">
                                <input type="checkbox" name="template_status" value="1"
                                       <?= $template['status'] === 'active' ? 'checked' : '' ?>>
                                <span class="ad-switch__track"></span>
                                <span>Send the welcome email</span>
                            </label>
                        </div>
                        <div class="ad-card__foot">
                            <button type="submit" class="ad-btn ad-btn--primary">
                                <?= icon('check', 'w-4 h-4') ?> Save Email
                            </button>
                        </div>
                    </div>
                </form>
            <?php elseif ($template !== null): ?>
                <div class="ad-card">
                    <div class="ad-card__head"><div class="ad-card__title">Welcome email</div></div>
                    <div class="ad-card__body">
                        <p class="ad-muted" style="font-size:13px">
                            Subject: <strong><?= e((string) $template['subject']) ?></strong> ·
                            currently <?= admin_state_badge((string) $template['status']) ?>.
                            Editing it needs the <code>newsletter.edit</code> permission.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div style="display:grid;gap:16px;align-content:start">
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head"><div class="ad-card__title">How this band behaves</div></div>
                <div class="ad-card__body" style="font-size:12.8px;line-height:1.65;color:var(--ad-muted)">
                    <p style="margin-bottom:10px">
                        The form posts to the newsletter API, which rate-limits a visitor to six attempts
                        every five minutes and stores the address lowercased, so the same person cannot be
                        added twice under different casing.
                    </p>
                    <p style="margin-bottom:10px">
                        Someone who previously unsubscribed is reactivated instead of duplicated, and they
                        do not get the welcome mail a second time.
                    </p>
                    <p>
                        The footer and exit-popup capture forms post to that same endpoint but carry
                        their own copy, so editing this band does not change them.
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
