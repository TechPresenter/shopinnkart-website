<?php
/**
 * ShopInnKart Admin - redirect manager.
 *
 * Before this there was no way to preserve a URL: renaming a product slug turned
 * every existing link to it into a hard 404. Rules here are consulted by
 * seo_apply_redirect() from 404.php, which is the only place they can matter -
 * routing has already failed by then, so a rule can never shadow a real page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

const REDIRECT_CODES = [
    301 => '301 Moved Permanently (passes ranking)',
    302 => '302 Found (temporary)',
    307 => '307 Temporary Redirect',
    308 => '308 Permanent Redirect',
];

$errors = [];
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require('settings.edit');
    csrf_require();

    $action = (string) input('action', '');

    if ($action === 'delete') {
        Database::delete('redirects', '`id` = :id', ['id' => input_int('id')]);
        admin_after_write();
        flash('success', 'Redirect deleted.');
        redirect(admin_url('settings/redirects.php'));
    }

    if ($action === 'toggle') {
        Database::query(
            "UPDATE `redirects`
             SET `status` = IF(`status` = 'active', 'inactive', 'active')
             WHERE `id` = :id",
            ['id' => input_int('id')]
        );
        admin_after_write();
        flash('success', 'Redirect updated.');
        redirect(admin_url('settings/redirects.php'));
    }

    if ($action === 'save') {
        $id     = input_int('id');
        $source = trim((string) input('source_path', ''));
        $target = trim((string) input('target_path', ''));
        $isRegex = input_bool('is_regex') ? 1 : 0;
        $code   = input_int('status_code', 301);

        // Literal paths are normalised to a single leading slash so /old,
        // old and /old/ cannot become three rules that mean the same thing.
        if (!$isRegex) {
            $source = '/' . trim($source, '/');
            if ($target !== '' && !str_starts_with($target, 'http')) {
                $target = '/' . trim($target, '/');
            }
        }

        if ($source === '' || $source === '/') {
            $errors['source_path'] = 'A source path is required.';
        }
        if ($target === '') {
            $errors['target_path'] = 'A target is required.';
        }
        if ($source === $target) {
            $errors['target_path'] = 'A redirect cannot point at itself.';
        } elseif (!$isRegex && seo_redirect_would_loop($source, $target, $id)) {
            // Not just a self-pointing rule: this follows the chain the target
            // already sits on. /a -> /b is fine, and /b -> /a is fine, but
            // saving the second one closes a circle that bounces a visitor
            // until their browser gives up. Caught here because the pair is
            // only wrong together, and this is the moment it becomes a pair.
            $errors['target_path'] = 'That would create a redirect loop: '
                . $target . ' already leads back to ' . $source . '. '
                . 'Point one of the two somewhere else first.';
        }
        if (!array_key_exists($code, REDIRECT_CODES)) {
            $code = 301;
        }
        // An invalid pattern would silently never match, so it is rejected here
        // rather than discovered months later as a dead rule.
        if ($isRegex && @preg_match('~' . str_replace('~', '\~', $source) . '~', '') === false) {
            $errors['source_path'] = 'That is not a valid regular expression.';
        }

        if ($errors === []) {
            $row = [
                'source_path' => $source,
                'target_path' => $target,
                'status_code' => $code,
                'is_regex'    => $isRegex,
                'notes'       => trim((string) input('notes', '')) ?: null,
                'status'      => input('status') === 'inactive' ? 'inactive' : 'active',
            ];

            try {
                if ($id > 0) {
                    Database::update('redirects', $row, '`id` = :id', ['id' => $id]);
                    admin_after_write();
                } else {
                    Database::insert('redirects', $row);
                    admin_after_write();
                }
                flash('success', 'Redirect saved.');
                redirect(admin_url('settings/redirects.php'));
            } catch (Throwable $e) {
                // The unique index on source_path is the guard against two rules
                // fighting over the same URL.
                $errors['source_path'] = 'A redirect for that path already exists.';
            }
        }
    }
}

$editing = null;
if (($editId = input_int('edit')) > 0) {
    $editing = Database::fetch('SELECT * FROM `redirects` WHERE `id` = :id', ['id' => $editId]);
}

$redirects = Database::fetchAll('SELECT * FROM `redirects` ORDER BY `status`, `hits` DESC, `id` DESC');

$pageTitle = 'Redirects';

// Most rules should start life on the broken-link monitor rather than here:
// that screen knows which URLs are actually being asked for, so a rule written
// there is one somebody is waiting on, not a guess at a URL that may not exist.
$open404 = 0;
try {
    $open404 = (int) Database::fetchColumn("SELECT COUNT(*) FROM `seo_404_log` WHERE `status` = 'open'");
} catch (Throwable $e) {
    // The monitor is not installed yet; the button simply does not appear.
}

if ($open404 > 0) {
    $pageActions = '<a class="ad-btn ad-btn--primary" href="' . e(admin_url('seo/404s.php')) . '">'
        . icon('link', 'w-4 h-4') . ' ' . number_format($open404) . ' broken link'
        . ($open404 === 1 ? '' : 's') . ' waiting</a>';
}

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('redirects') ?>

<div class="ad-grid ad-grid--2" style="gap:20px;align-items:start">

    <div class="ad-card" style="margin:0">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title"><?= $editing ? 'Edit redirect' : 'Add a redirect' ?></div>
                <div class="ad-card__sub">Checked only when a URL would otherwise 404.</div>
            </div>
        </div>
        <form class="ad-form" method="post" action="<?= e(admin_url('settings/redirects.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="ad-card__body">
                <label class="ad-field">
                    <span class="sik-label">From (old path)</span>
                    <input type="text" class="sik-input" name="source_path" required maxlength="255"
                           value="<?= e((string) ($editing['source_path'] ?? '')) ?>"
                           placeholder="/old-product-name">
                    <?php if (isset($errors['source_path'])): ?>
                        <span class="sik-error"><?= e($errors['source_path']) ?></span>
                    <?php endif; ?>
                </label>

                <label class="ad-field">
                    <span class="sik-label">To (new path or full URL)</span>
                    <input type="text" class="sik-input" name="target_path" required maxlength="255"
                           value="<?= e((string) ($editing['target_path'] ?? '')) ?>"
                           placeholder="/product/new-product-name">
                    <?php if (isset($errors['target_path'])): ?>
                        <span class="sik-error"><?= e($errors['target_path']) ?></span>
                    <?php endif; ?>
                </label>

                <label class="ad-field">
                    <span class="sik-label">Type</span>
                    <select class="sik-input" name="status_code">
                        <?php foreach (REDIRECT_CODES as $code => $label): ?>
                            <option value="<?= (int) $code ?>"
                                <?= (int) ($editing['status_code'] ?? 301) === $code ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ad-field ad-field--check">
                    <input type="checkbox" name="is_regex" value="1"
                        <?= !empty($editing['is_regex']) ? 'checked' : '' ?>>
                    <span>
                        Treat the source as a regular expression
                        <span class="sik-help" style="display:block">
                            Captures land in the target as $1, $2:
                            <code>^/legacy/product/(.+)$</code> &rarr; <code>/product/$1</code>
                        </span>
                    </span>
                </label>

                <label class="ad-field">
                    <span class="sik-label">Note (optional)</span>
                    <input type="text" class="sik-input" name="notes" maxlength="255"
                           value="<?= e((string) ($editing['notes'] ?? '')) ?>"
                           placeholder="Why this rule exists">
                </label>

                <label class="ad-field">
                    <span class="sik-label">Status</span>
                    <select class="sik-input" name="status">
                        <option value="active"<?= ($editing['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="inactive"<?= ($editing['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
            </div>

            <div class="ad-card__foot" style="display:flex;gap:8px;justify-content:flex-end">
                <?php if ($editing): ?>
                    <a class="ad-btn" href="<?= e(admin_url('settings/redirects.php')) ?>">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="ad-btn ad-btn--primary">
                    <?= $editing ? 'Save changes' : 'Add redirect' ?>
                </button>
            </div>
        </form>
    </div>

    <div class="ad-card" style="margin:0">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title"><?= count($redirects) ?> redirect<?= count($redirects) === 1 ? '' : 's' ?></div>
                <div class="ad-card__sub">Exact paths are matched first, then patterns.</div>
            </div>
        </div>

        <?php if ($redirects === []): ?>
            <div class="ad-card__body">
                <p class="ad-muted" style="margin:0">
                    No redirects yet. Add one whenever you rename a slug.
                </p>
            </div>
        <?php else: ?>
            <?php /* .ad-tablewrap is the wrapper admin.js measures and the card
                     view's own rules are keyed to. `.ad-table-scroll`, which
                     this page and seo-health were the only two users of, is a
                     bare `overflow-x: auto` with none of that - and, unlike
                     .ad-tablewrap, no `min-width: 0`, so as a grid or flex item
                     it widens the PAGE instead of scrolling itself. The card
                     view saved it here, because admin.js stacks by measuring
                     the TABLE; the wrapper was a trap waiting for a layout that
                     put it in a flex track. The column classes are the real
                     ones too: `.ad-num` is defined in no stylesheet, so none of
                     these figures were ever right-aligned. */ ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>From &rarr; To</th>
                            <th class="ad-table__num">Code</th>
                            <th class="ad-table__num">Hits</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($redirects as $rule): ?>
                            <tr<?= $rule['status'] === 'inactive' ? ' class="is-muted"' : '' ?>>
                                <td>
                                    <code><?= e((string) $rule['source_path']) ?></code>
                                    <?php if ((int) $rule['is_regex'] === 1): ?>
                                        <span class="ad-badge">regex</span>
                                    <?php endif; ?>
                                    <br>
                                    <span class="ad-muted">&rarr; <?= e((string) $rule['target_path']) ?></span>
                                    <?php if (!empty($rule['notes'])): ?>
                                        <br><span class="ad-muted" style="font-size:12px"><?= e((string) $rule['notes']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="ad-table__num"><?= (int) $rule['status_code'] ?></td>
                                <td class="ad-table__num">
                                    <?= (int) $rule['hits'] ?>
                                    <?php if (!empty($rule['last_hit_at'])): ?>
                                        <br><span class="ad-muted" style="font-size:11px">
                                            <?= e(format_date((string) $rule['last_hit_at'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php /* No inline `white-space: nowrap`: three
                                         buttons held on one line are what push
                                         an actions column out of its table. The
                                         shared class right-aligns them and lets
                                         them wrap when the column is squeezed. */ ?>
                                <td class="ad-table__actions">
                                    <a class="ad-btn ad-btn--sm"
                                       href="<?= e(admin_url('settings/redirects.php?edit=' . (int) $rule['id'])) ?>">Edit</a>

                                    <form method="post" style="display:inline"
                                          action="<?= e(admin_url('settings/redirects.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                        <button type="submit" class="ad-btn ad-btn--sm">
                                            <?= $rule['status'] === 'active' ? 'Pause' : 'Resume' ?>
                                        </button>
                                    </form>

                                    <form method="post" style="display:inline"
                                          action="<?= e(admin_url('settings/redirects.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                        <button type="submit" class="ad-btn ad-btn--sm ad-btn--danger"
                                                <?= admin_confirm_attrs('Links using the old URL will 404 again.', ['title' => 'Delete this redirect?', 'label' => 'Delete redirect', 'tone' => 'danger']) ?>>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
