<?php
/**
 * ShopInnKart Admin - Custom code.
 *
 * Add, edit, order, switch off and delete the snippets the storefront injects:
 * tracking tags, site-verification meta tags, a third-party widget, a CSS fix.
 * It replaces the two flat textareas on Settings > Theme, which could hold any
 * number of snippets but could name, order, gate or switch off none of them.
 *
 * WHAT THIS SCREEN GUARDS
 * ----------------------
 * A snippet field is remote code execution by design. So:
 *   - reading the list needs settings.view; reading or writing a code BODY
 *     needs settings.scripts, which only a Super Admin can delegate;
 *   - adding, editing and deleting need the actor's own password again
 *     (ADMIN_REAUTH_WINDOW). Switching one off and reordering do not - killing
 *     a misbehaving tag must not wait on a password, and enabling can only
 *     enable code that already passed a re-authenticated save;
 *   - every change writes an activity_logs row and a critical security_events
 *     row with a sha256 of the code before and after.
 *
 * The "Runs" column is computed by the same functions the storefront calls, so
 * it cannot claim a snippet is live that the page would withhold - including
 * the case this screen exists to surface: enabled, saved, and waiting on a
 * consent category the banner never offers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';
require_once ADMIN_PATH . '/includes/rbac.php';       // admin_can_edit_scripts(), admin_reauth_*()
require_once INCLUDES_PATH . '/custom-scripts.php';

// The consent layer is normally pulled in by the storefront footer. This screen
// reports what it would allow, so it asks for it by name.
if (is_file(INCLUDES_PATH . '/consent.php')) {
    require_once INCLUDES_PATH . '/consent.php';
}

$canScripts = admin_can_edit_scripts();
$installed  = custom_scripts_installed();
$selfUrl    = admin_url('settings/scripts.php');

$errors = [];

// ---------------------------------------------------------------------------
//  Writes
// ---------------------------------------------------------------------------
if (is_post()) {
    // POST + CSRF + settings.edit before a single field is read.
    admin_require_action('settings.edit');

    $action = (string) input('action', '');

    if (!$installed) {
        flash('error', 'The snippet table is missing. Run the migration first.');
        redirect($selfUrl);
    }

    // One gate for all four actions. Anything arriving here from an operator
    // without settings.scripts was hand-crafted - the controls are not rendered
    // for them - so it is refused loudly and recorded.
    if (!$canScripts) {
        admin_deny_back(
            'Changing the code that runs on the storefront needs the settings.scripts permission. '
                . 'Nothing was saved.',
            $selfUrl,
            ['action' => $action]
        );
    }

    $id      = input_int('id');
    $current = $id > 0 ? custom_scripts_find($id) : null;

    if ($id > 0 && $current === null) {
        flash('error', 'That snippet no longer exists.');
        redirect($selfUrl);
    }

    // -- turn on / off ------------------------------------------------------
    if ($action === 'toggle') {
        $now = (int) $current['is_enabled'] === 1 ? 0 : 1;

        Database::update('custom_scripts', [
            'is_enabled'   => $now,
            'updated_by'   => (int) $admin['id'],
            'updated_name' => (string) $admin['name'],
        ], '`id` = :id', ['id' => $id]);

        custom_scripts_audit($now === 1 ? 'enabled' : 'disabled',
            ['is_enabled' => $now] + $current);
        admin_after_write();

        flash('success', '"' . (string) $current['name'] . '" is now ' . ($now === 1 ? 'on' : 'off') . '.');
        redirect($selfUrl);
    }

    // -- reorder -----------------------------------------------------------
    if ($action === 'move') {
        $direction = (string) input('dir', '') === 'up' ? -1 : 1;

        if (custom_scripts_move($id, $direction)) {
            custom_scripts_audit('reordered', $current);
            admin_after_write();
            flash('success', 'Order updated.');
        }

        redirect($selfUrl);
    }

    // -- delete -------------------------------------------------------------
    if ($action === 'delete') {
        if (!admin_reauth_ok()) {
            flash_errors(['reauth_password' => admin_reauth_error('delete a snippet')]);
            flash('error', 'Nothing was deleted. Confirm with your own password.');
            redirect($selfUrl . '?edit=' . $id);
        }

        Database::delete('custom_scripts', '`id` = :id', ['id' => $id]);

        custom_scripts_audit('deleted', $current, (string) $current['code'], '');
        admin_after_write();

        flash('success', '"' . (string) $current['name'] . '" was deleted.');
        redirect($selfUrl);
    }

    // -- add / edit ---------------------------------------------------------
    if ($action === 'save') {
        $name      = trim((string) input('name', ''));
        $type      = (string) input('type', 'js');
        $placement = (string) input('placement', 'body_end');
        $consent   = (string) input('consent', 'marketing');
        $notes     = trim((string) input('notes', ''));
        $enabled   = input_bool('is_enabled') ? 1 : 0;

        // NOT input(): the helpers trim and the raw body is the point. Leading
        // whitespace in a snippet is the operator's indentation, and this field
        // must give back exactly what was typed.
        $code = (string) ($_POST['code'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Give it a name you will recognise in six months.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Names are 120 characters or fewer.';
        }
        if (!isset(CUSTOM_SCRIPT_TYPES[$type])) {
            $type = 'js';
        }
        if (!isset(CUSTOM_SCRIPT_PLACEMENTS[$placement])) {
            $placement = 'body_end';
        }
        if (!isset(CUSTOM_SCRIPT_CONSENT[$consent])) {
            $consent = 'marketing';
        }
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Notes are 500 characters or fewer.';
        }

        $problem = custom_scripts_problem($type, $placement, $code);
        if ($problem !== null) {
            $errors['code'] = $problem;
        }

        // The password is checked last so a rejected snippet does not also
        // demand the password be retyped for a save that was never going to
        // happen.
        if ($errors === [] && !admin_reauth_ok()) {
            $errors['reauth_password'] = admin_reauth_error('save code that runs on the storefront');
        }

        if ($errors === []) {
            $row = [
                'name'         => $name,
                'type'         => $type,
                'placement'    => $placement,
                'consent'      => $consent,
                'code'         => $code,
                'notes'        => $notes !== '' ? $notes : null,
                'is_enabled'   => $enabled,
                'updated_by'   => (int) $admin['id'],
                'updated_name' => (string) $admin['name'],
            ];

            if ($current !== null) {
                // Moving a snippet to another placement takes it out of one
                // ordered list and into another, where its old position number
                // means nothing and can collide. Send it to the end of the new
                // one, which is where a newly-added snippet goes too.
                if ($placement !== (string) $current['placement']) {
                    $row['sort_order'] = custom_scripts_next_order($placement);
                }

                Database::update('custom_scripts', $row, '`id` = :id', ['id' => $id]);
                custom_scripts_audit('updated', ['id' => $id] + $row,
                    (string) $current['code'], $code);
            } else {
                // A new snippet goes last in its placement, so adding one never
                // changes the order of what is already running.
                $row['sort_order']   = custom_scripts_next_order($placement);
                $row['created_by']   = (int) $admin['id'];
                $row['created_name'] = (string) $admin['name'];

                $newId = Database::insert('custom_scripts', $row);
                custom_scripts_audit('created', ['id' => $newId] + $row, '', $code);
            }

            admin_after_write();
            flash('success', '"' . $name . '" saved.'
                . ($enabled === 1 ? '' : ' It is off, so nothing runs yet.'));
            redirect($selfUrl);
        }

        // Bounce back with the rejected input, minus the password.
        $keep = $_POST;
        unset($keep['reauth_password']);
        flash_errors($errors);
        flash_old($keep);
        flash('error', 'Nothing was saved. Please fix the highlighted field.');
        redirect($selfUrl . ($id > 0 ? '?edit=' . $id : ''));
    }

    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$errors  = errors_pull();
$snippets = custom_scripts_all();
$summary  = custom_scripts_summary();

/** The row being edited, or null for the add form. */
$editing = null;
if ($canScripts && ($editId = input_int('edit')) > 0) {
    $editing = custom_scripts_find($editId);
    if ($editing === null) {
        flash('error', 'That snippet no longer exists.');
        redirect($selfUrl);
    }
}

/**
 * What the form shows: rejected input first, then the row being edited, then
 * the defaults for a new snippet.
 *
 * Materialised here rather than read during render because old_clear() empties
 * the session copy below - the same order admin/menus/index.php uses. The
 * checkbox has to come out of the same bag: an unticked box posts nothing, so
 * reading it with a fallback of "on" would silently re-tick it on every bounce.
 */
$hasOld = settings_has_old();
$form = [
    'name'      => (string) ($hasOld ? old('name') : ($editing['name'] ?? '')),
    'type'      => (string) ($hasOld ? old('type', 'js') : ($editing['type'] ?? 'js')),
    'placement' => (string) ($hasOld ? old('placement', 'body_end') : ($editing['placement'] ?? 'body_end')),
    'consent'   => (string) ($hasOld ? old('consent', 'marketing') : ($editing['consent'] ?? 'marketing')),
    'code'      => (string) ($hasOld ? old('code') : ($editing['code'] ?? '')),
    'notes'     => (string) ($hasOld ? old('notes') : ($editing['notes'] ?? '')),
    'enabled'   => $hasOld
        ? (string) old('is_enabled', '0') === '1'
        : ($editing !== null ? (int) $editing['is_enabled'] === 1 : true),
];
old_clear();

$reach = [
    'analytics' => custom_scripts_reach('analytics'),
    'marketing' => custom_scripts_reach('marketing'),
];

$pageTitle    = 'Custom code';
$pageSubtitle = $installed
    ? $summary['total'] . ' snippet' . ($summary['total'] === 1 ? '' : 's') . ' · '
        . $summary['live'] . ' live'
    : 'Not installed yet';
$breadcrumbs  = [
    ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
    ['label' => 'Settings',  'url' => settings_url('general')],
    ['label' => 'Custom code'],
];
$pageActions = '<a class="ad-btn" href="' . e(admin_url('seo/scripts.php')) . '">'
    . icon('code', 'w-4 h-4') . ' Everything injected</a>';

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('scripts') ?>

<?php if (!$installed): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <div class="sik-alert sik-alert--error" style="margin:0">
                <?= icon('alert', 'w-5 h-5') ?>
                <div>
                    The <code>custom_scripts</code> table is missing. Run
                    <code>database/migrations/2026_09_28_custom_scripts.php</code>. It also moves any
                    code already in Settings &gt; Theme across, so run it before adding anything here.
                </div>
            </div>
        </div>
    </div>
<?php else: ?>

<div class="ad-grid ad-grid--3" style="margin-bottom:20px">
    <?= admin_stat_card('Running now', (string) $summary['live'], 'zap',
        $summary['live'] > 0 ? 'primary' : 'navy', 'on every page') ?>
    <?= admin_stat_card('Waiting for consent', (string) $summary['gated'], 'shield-check',
        $summary['gated'] > 0 ? 'amber' : 'navy', 'run once the visitor agrees') ?>
    <?= admin_stat_card('Never run', (string) $summary['stuck'], 'alert',
        $summary['stuck'] > 0 ? 'red' : 'green',
        $summary['stuck'] > 0 ? 'gated on an unofferable choice' : 'nothing stuck') ?>
</div>

<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Snippets</div>
            <div class="ad-card__sub">Head first, then body. Arrows reorder within a placement.</div>
        </div>
    </div>

    <?php if ($snippets === []): ?>
        <div class="ad-card__body ad-card__body--flush">
            <?= admin_empty(
                'Nothing is injected yet',
                'A tracking tag, a site-verification meta tag, a third-party widget or a CSS fix. '
                    . 'Each one gets its own row here, so you can switch one off without editing the rest.',
                null,
                null,
                'code'
            ) ?>
        </div>
    <?php else: ?>
        <div class="ad-tablewrap">
            <table class="ad-table ad-table--stack">
                <thead>
                    <tr>
                        <th scope="col">Snippet</th>
                        <th scope="col">Where</th>
                        <th scope="col">Runs</th>
                        <th scope="col">Last changed</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snippets as $row): ?>
                        <?php
                        $status = custom_scripts_status($row);
                        $rowId  = (int) $row['id'];
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600"><?= e((string) $row['name']) ?></div>
                                <div class="ad-muted" style="font-size:var(--ad-text-xs)">
                                    <?= e(CUSTOM_SCRIPT_TYPES[(string) $row['type']]) ?>
                                    &middot; <?= e(format_bytes(strlen((string) $row['code']))) ?>
                                    <?php if (!empty($row['notes'])): ?>
                                        <br><?= e(str_limit((string) $row['notes'], 120)) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= e(CUSTOM_SCRIPT_PLACEMENTS[(string) $row['placement']]) ?></td>
                            <td>
                                <span class="sik-status sik-status--<?= e_attr($status['tone']) ?>">
                                    <?= e($status['label']) ?>
                                </span>
                                <?php if ($status['note'] !== ''): ?>
                                    <div class="ad-muted" style="font-size:var(--ad-text-xs)">
                                        <?= e($status['note']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="ad-muted">
                                <?= e(format_datetime((string) $row['updated_at'])) ?>
                                <?php if (!empty($row['updated_name']) || !empty($row['created_name'])): ?>
                                    <div style="font-size:var(--ad-text-xs)">
                                        by <?= e((string) ($row['updated_name'] ?: $row['created_name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="ad-table__actions">
                                <?php if ($canScripts): ?>
                                    <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="move">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" name="dir" value="up" class="ad-btn ad-btn--sm"
                                                aria-label="Move <?= e_attr((string) $row['name']) ?> earlier">
                                            <?= icon('chevron-up', 'w-4 h-4') ?>
                                        </button>
                                        <button type="submit" name="dir" value="down" class="ad-btn ad-btn--sm"
                                                aria-label="Move <?= e_attr((string) $row['name']) ?> later">
                                            <?= icon('chevron-down', 'w-4 h-4') ?>
                                        </button>
                                    </form>
                                    <a class="ad-btn ad-btn--sm" href="<?= e($selfUrl . '?edit=' . $rowId) ?>#snippetForm">Edit</a>
                                    <form method="post" action="<?= e($selfUrl) ?>" class="ad-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="ad-btn ad-btn--sm">
                                            <?= (int) $row['is_enabled'] === 1 ? 'Turn off' : 'Turn on' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="ad-muted">read-only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($canScripts): ?>
                            <tr>
                                <td colspan="5" style="background:var(--ad-sunken)">
                                    <details>
                                        <summary class="ad-muted" style="cursor:pointer;font-size:var(--ad-text-sm)">
                                            Show the code
                                        </summary>
                                        <?php
                                        /* e(), always. This screen shows admin-typed code as TEXT;
                                           the one thing it must never do is execute the snippet it
                                           is reporting on. */
                                        ?>
                                        <pre class="ad-codearea" style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0"><?=
                                            e(str_limit((string) $row['code'], 4000)) ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="ad-card__foot">
        <span class="ad-muted" style="font-size:var(--ad-text-sm)">
            <?php if ($canScripts): ?>
                Nothing here runs inside the admin. You are signed in, so gated snippets do not render
                for you either &mdash; check them in a private window.
            <?php else: ?>
                Bodies and editing need <code>settings.scripts</code>, granted only by a Super Admin.
            <?php endif; ?>
        </span>
    </div>
</div>

<?php if ($summary['stuck'] > 0): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <div class="sik-alert sik-alert--warning" style="margin:0">
                <?= icon('alert', 'w-5 h-5') ?>
                <div>
                    <?= (int) $summary['stuck'] ?> snippet<?= $summary['stuck'] === 1 ? ' is' : 's are' ?>
                    on and will never run: the consent banner does not offer the category
                    <?= $summary['stuck'] === 1 ? 'it waits' : 'they wait' ?> on. Either mark
                    <?= $summary['stuck'] === 1 ? 'it' : 'them' ?> Essential (only if the code is genuinely
                    first-party) or give the banner a reason to ask &mdash;
                    <a href="<?= e(settings_url('integrations')) ?>">configure a tag id</a> for Advertising,
                    or <a href="<?= e(settings_url('analytics')) ?>">an analytics mode that needs opt-in</a>.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canScripts): ?>
<div class="ad-card" id="snippetForm">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title"><?= $editing !== null ? 'Edit snippet' : 'Add a snippet' ?></div>
            <div class="ad-card__sub">Saved exactly as typed. Nothing is stripped or reformatted.</div>
        </div>
    </div>

    <form class="ad-form" method="post" action="<?= e($selfUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

        <div class="ad-card__body">
            <div class="ad-field">
                <label class="sik-label" for="snName">Name <span class="req">*</span></label>
                <input class="sik-input<?= isset($errors['name']) ? ' is-invalid' : '' ?>" type="text"
                       id="snName" name="name" maxlength="120" required
                       value="<?= e($form['name']) ?>"
                       placeholder="Hotjar recording tag">
                <?php if (isset($errors['name'])): ?>
                    <span class="sik-error"><?= e((string) $errors['name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="ad-row ad-row--3">
                <div class="ad-field">
                    <label class="sik-label" for="snType">Type</label>
                    <select class="sik-select" id="snType" name="type">
                        <?php foreach (CUSTOM_SCRIPT_TYPES as $key => $label): ?>
                            <option value="<?= e_attr($key) ?>"<?= $form['type'] === $key ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sik-help">HTML for a verification &lt;meta&gt; or a &lt;noscript&gt;.</span>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="snPlacement">Placement</label>
                    <select class="sik-select" id="snPlacement" name="placement">
                        <?php foreach (CUSTOM_SCRIPT_PLACEMENTS as $key => $label): ?>
                            <option value="<?= e_attr($key) ?>"<?= $form['placement'] === $key ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sik-help">Head runs before the page paints. CSS must go there.</span>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="snConsent">Consent</label>
                    <select class="sik-select" id="snConsent" name="consent">
                        <?php foreach (CUSTOM_SCRIPT_CONSENT as $key => $label): ?>
                            <option value="<?= e_attr($key) ?>"<?= $form['consent'] === $key ? ' selected' : '' ?>>
                                <?= e($label) ?><?= isset($reach[$key]) && !$reach[$key]['ok'] ? ' — never granted' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sik-help">Only Essential runs before the visitor answers.</span>
                </div>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="snCode">Code <span class="req">*</span></label>
                <textarea class="sik-textarea ad-codearea<?= isset($errors['code']) ? ' is-invalid' : '' ?>"
                          id="snCode" name="code" rows="12" spellcheck="false" required
                          placeholder="(function () { /* ... */ })();"><?= e($form['code']) ?></textarea>
                <?php if (isset($errors['code'])): ?>
                    <span class="sik-error"><?= e((string) $errors['code']) ?></span>
                <?php else: ?>
                    <span class="sik-help">
                        JavaScript and CSS without their tags; HTML as whole elements. Up to
                        <?= e(number_format(CUSTOM_SCRIPT_MAX_CHARS)) ?> characters.
                    </span>
                <?php endif; ?>
            </div>

            <div class="ad-field">
                <label class="sik-label" for="snNotes">Notes</label>
                <input class="sik-input<?= isset($errors['notes']) ? ' is-invalid' : '' ?>" type="text"
                       id="snNotes" name="notes" maxlength="500"
                       value="<?= e($form['notes']) ?>"
                       placeholder="Why this exists, and who asked for it">
                <?php if (isset($errors['notes'])): ?>
                    <span class="sik-error"><?= e((string) $errors['notes']) ?></span>
                <?php endif; ?>
            </div>

            <label class="ad-switch">
                <input type="checkbox" name="is_enabled" value="1"
                       <?= $form['enabled'] ? 'checked' : '' ?>>
                <span class="ad-switch__track"></span>
                <span>On &mdash; inject this into the storefront</span>
            </label>

            <?= admin_reauth_field('save code that runs on the storefront',
                (string) ($errors['reauth_password'] ?? '')) ?>

            <div class="sik-alert sik-alert--warning" style="margin:0">
                <?= icon('alert', 'w-5 h-5') ?>
                <div>
                    <strong>This code can do anything a signed-in shopper can.</strong>
                    It runs on every storefront page, checkout included, and a syntax error breaks the
                    shop for real visitors. Every change is recorded against your name.
                </div>
            </div>
        </div>

        <div class="ad-card__foot" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
            <?php if ($editing !== null): ?>
                <a class="ad-btn" href="<?= e($selfUrl) ?>" style="margin-right:auto">Cancel</a>
            <?php endif; ?>
            <?php // First submit button, so a press of Enter in a text field saves. ?>
            <button type="submit" class="ad-btn ad-btn--primary" name="action" value="save">
                <?= $editing !== null ? 'Save changes' : 'Add snippet' ?>
            </button>
            <?php if ($editing !== null): ?>
                <?php // formnovalidate: deleting must not be blocked by the
                      // required Code box the operator may have just emptied. ?>
                <button type="submit" class="ad-btn ad-btn--danger" name="action" value="delete" formnovalidate
                        <?= admin_confirm_attrs(
                            'The snippet is removed from every storefront page. This cannot be undone.',
                            ['title' => 'Delete this snippet?', 'label' => 'Delete snippet']
                        ) ?>>
                    Delete
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php endif; /* $installed */ ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
