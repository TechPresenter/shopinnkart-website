<?php
/**
 * ShopInnKart Admin - Injected code.
 *
 * The owner's answer to "what is running on my shop, and who put it there?".
 *
 * Three sources feed the storefront's <head> and <body>, and until this screen
 * existed you had to know where all three lived to find out:
 *   1. the store-wide custom CSS/JS in Settings > Theme;
 *   2. the third-party tag ids in Settings > Analytics, behind the consent layer;
 *   3. the per-record code the SEO panel added - which is the one this section
 *      owns, and the only one that could otherwise hide on any of six hundred
 *      records without ever appearing on a settings screen.
 *
 * The code BODIES are only shown to someone who could have written them
 * (settings.scripts, or Super Admin). Everyone with settings.view still sees
 * that code exists, where, how big it is and who last touched it - because
 * "there is a snippet on this page and you may not read it" is a useful
 * answer, and "there is nothing here" would be a false one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/includes/rbac.php';   // admin_can_edit_scripts(), admin_deny_back()
require_once __DIR__ . '/_layout.php';

// consent_tag_ids(): normally loaded by the storefront footer, so an admin
// screen that wants to report what the consent layer holds asks for it.
if (is_file(INCLUDES_PATH . '/consent.php')) {
    require_once INCLUDES_PATH . '/consent.php';
}

$canScripts = admin_can_edit_scripts();

if (is_post()) {
    admin_require_action('settings.edit');

    if ((string) input('action', '') === 'clear') {
        // Removing code is itself a code change, so it carries the same
        // permission as writing it. Otherwise settings.edit would be a way to
        // silently switch off a tag the owner relies on.
        if (!$canScripts) {
            admin_deny_back(
                'Removing code that runs on the storefront needs the settings.scripts permission.',
                seo_admin_url('scripts')
            );
        }

        $type = (string) input('entity_type', '');
        $id   = input_int('entity_id');

        if (seo_entity_type($type) !== null && $id > 0) {
            $before = seo_entity_meta($type, $id);

            seo_entity_meta_save($type, $id, [
                'custom_head' => '',
                'custom_body' => '',
                'custom_css'  => '',
                'custom_js'   => '',
            ]);

            security_event('seo.entity_code_cleared', 'critical', [
                'entity_type' => $type,
                'entity_id'   => $id,
                'sha256_head' => $before['custom_head'] === '' ? null : hash('sha256', (string) $before['custom_head']),
                'sha256_body' => $before['custom_body'] === '' ? null : hash('sha256', (string) $before['custom_body']),
                'sha256_css'  => $before['custom_css'] === '' ? null : hash('sha256', (string) $before['custom_css']),
                'sha256_js'   => $before['custom_js'] === '' ? null : hash('sha256', (string) $before['custom_js']),
            ], (int) $admin['id'], 'admin');

            log_activity('seo.code_cleared', $type, $id, 'Removed the custom code from this record');
            admin_after_write();
            flash('success', 'The custom code on that record was removed.');
        }

        redirect(seo_admin_url('scripts'));
    }
}

$entries = seo_injected_code_inventory(500);

// The two store-wide sources, so this screen is the whole picture rather than
// a third place to look. Neither is edited here - each links to its own screen.
$themeCss = trim((string) setting('custom_css', ''));
$themeJs  = trim((string) setting('custom_js', ''));
$tagIds   = function_exists('consent_tag_ids') ? array_filter(consent_tag_ids()) : [];

$pageTitle    = 'Injected code';
$pageSubtitle = count($entries) . ' record' . (count($entries) === 1 ? '' : 's')
    . ' with their own code · ' . ($canScripts ? 'you can read and remove it' : 'bodies hidden from your role');
$breadcrumbs  = seo_admin_breadcrumbs('Injected code');

require ADMIN_PATH . '/includes/header.php';
?>

<div class="ad-card">
    <?= seo_admin_tabs('scripts') ?>

    <div class="ad-card__body">
        <div class="sik-alert sik-alert--info">
            <?= icon('shield-check', 'w-5 h-5') ?>
            <div>
                None of this runs inside the admin &mdash; only on the storefront.
                Code under a consent category stays off until the visitor accepts.
            </div>
        </div>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">Store-wide</div>
        <div class="ad-card__sub">Runs on every page. Managed on its own screen.</div>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <div class="ad-tablewrap">
            <table class="ad-table">
                <thead><tr><th>What</th><th>Size</th><th>Gated by consent?</th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td>Theme custom CSS</td>
                        <td><?= $themeCss === '' ? '<span class="ad-muted">empty</span>'
                                : e(format_bytes(strlen($themeCss))) ?></td>
                        <td class="ad-muted">No &mdash; presentation only</td>
                        <td><a class="ad-btn ad-btn--sm" href="<?= e(admin_url('settings/theme.php')) ?>">Open</a></td>
                    </tr>
                    <tr>
                        <td>Theme custom JavaScript</td>
                        <td><?= $themeJs === '' ? '<span class="ad-muted">empty</span>'
                                : e(format_bytes(strlen($themeJs))) ?></td>
                        <td class="ad-muted">No &mdash; first-party by assumption</td>
                        <td><a class="ad-btn ad-btn--sm" href="<?= e(admin_url('settings/theme.php')) ?>">Open</a></td>
                    </tr>
                    <tr>
                        <td>
                            Third-party tags
                            <?php if ($tagIds !== []): ?>
                                <div class="ad-muted" style="font-size:12px">
                                    <?= e(implode(', ', array_keys($tagIds))) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $tagIds === [] ? '<span class="ad-muted">none configured</span>'
                                : count($tagIds) . ' configured' ?></td>
                        <td>Yes &mdash; Marketing</td>
                        <td><a class="ad-btn ad-btn--sm" href="<?= e(admin_url('settings/analytics.php')) ?>">Open</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div class="ad-card__title">Per-record</div>
        <div class="ad-card__sub">
            Added through a record&rsquo;s SEO panel. Runs on that page only.
        </div>
    </div>

    <div class="ad-card__body ad-card__body--flush">
        <?php if ($entries === []): ?>
            <?= admin_empty(
                'No record is injecting anything',
                'When somebody adds head, body, CSS or JavaScript to a record through its SEO panel, '
                    . 'it is listed here with their name against it.',
                null,
                null,
                'code'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Record</th>
                            <th>What it injects</th>
                            <th>Runs when</th>
                            <th>Last changed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $type  = (string) $entry['entity_type'];
                            $spec  = seo_entity_type($type);
                            $bytes = $entry['bytes'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) ($entry['record_name'] ?: '(deleted record)')) ?></strong>
                                    <div class="ad-muted" style="font-size:12px">
                                        <?= e((string) $entry['type_label']) ?>
                                        <?php if ($entry['record_url'] !== ''): ?>
                                            &middot;
                                            <a href="<?= e((string) $entry['record_url']) ?>"
                                               target="_blank" rel="noopener">view page</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php foreach ([
                                        'custom_head' => 'head',
                                        'custom_body' => 'body',
                                        'custom_css'  => 'CSS',
                                        'custom_js'   => 'JS',
                                    ] as $field => $label): ?>
                                        <?php if ((int) $bytes[$field] > 0): ?>
                                            <span class="ad-pill">
                                                <?= e($label) ?> · <?= e(format_bytes((int) $bytes[$field])) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php $consent = (string) $entry['code_consent']; ?>
                                    <?= $consent === 'none'
                                        ? 'Always'
                                        : 'Only with ' . e(ucfirst($consent)) . ' consent' ?>
                                </td>
                                <td class="ad-muted">
                                    <?= !empty($entry['code_updated_at'])
                                        ? e(format_datetime((string) $entry['code_updated_at']))
                                        : '&mdash;' ?>
                                    <?php if (!empty($entry['actor_name'])): ?>
                                        <div style="font-size:12px">by <?= e((string) $entry['actor_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($spec !== null && $entry['record_name'] !== ''): ?>
                                        <a class="ad-btn ad-btn--sm"
                                           href="<?= e(admin_url($spec['admin'] . '?id=' . (int) $entry['entity_id'])) ?>">
                                            Edit
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canScripts): ?>
                                        <form method="post" action="<?= e(seo_admin_url('scripts')) ?>"
                                              class="ad-inline-form"
                                              <?= admin_confirm_form_attrs(
                                                  'Every head, body and footer snippet on this record is cleared. This cannot be undone.',
                                                  ['title' => 'Remove all custom code?', 'label' => 'Remove code']
                                              ) ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="clear">
                                            <input type="hidden" name="entity_type" value="<?= e_attr($type) ?>">
                                            <input type="hidden" name="entity_id" value="<?= (int) $entry['entity_id'] ?>">
                                            <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger-ghost">
                                                Remove
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ($canScripts): ?>
                                <tr>
                                    <td colspan="5" style="background:var(--ad-bg)">
                                        <details>
                                            <summary class="ad-muted">Show what it runs</summary>
                                            <?php foreach ([
                                                'custom_head' => 'Head',
                                                'custom_body' => 'Body',
                                                'custom_css'  => 'CSS',
                                                'custom_js'   => 'JavaScript',
                                            ] as $field => $label): ?>
                                                <?php if (trim((string) $entry[$field]) === '') {
                                                    continue;
                                                } ?>
                                                <p class="sik-label" style="margin-top:8px"><?= e($label) ?></p>
                                                <?php
                                                /* e(), always. This is admin-typed code being shown as
                                                   TEXT, and the one thing this screen must never do is
                                                   execute the very snippet it is reporting on. */
                                                ?>
                                                <pre class="ad-code" style="white-space:pre-wrap;word-break:break-word"><?=
                                                    e(str_limit((string) $entry[$field], 4000)) ?></pre>
                                            <?php endforeach; ?>
                                        </details>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$canScripts): ?>
        <div class="ad-card__foot">
            <span class="ad-muted">
                Bodies hidden from your role. Reading or changing them needs
                <code>settings.scripts</code>, granted only by a Super Admin.
            </span>
        </div>
    <?php endif; ?>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
