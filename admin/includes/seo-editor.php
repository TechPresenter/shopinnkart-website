<?php
/**
 * ShopInnKart Admin - the SEO editor.
 *
 * One component for every record that can be indexed. products, categories,
 * brands, blog_posts and pages all carry the same seven SEO columns, so they
 * all get the same editor: a Google result preview, a social card preview,
 * length counters that go amber then red at the real truncation points, and a
 * short checklist. Building it once is what stops five forms drifting apart.
 *
 * Usage, inside an existing <form>:
 *     seo_editor($row, ['url' => product_url($row['slug']), 'title_from' => 'name']);
 *
 * The field names match the column names exactly, so a save handler can take
 * them straight from the request with seo_editor_input().
 *
 * @param array<string,mixed> $row      current values (empty array when creating)
 * @param array<string,mixed> $options  url, title_from, description_from
 */

declare(strict_types=1);

// admin_can_edit_scripts(). The panel's four code boxes are the same class of
// field as Settings > Theme's script boxes and carry the same permission, and
// rbac.php is not part of the admin bootstrap - a screen that needs it asks for
// it. Without this the helper simply did not exist, every actor was treated as
// unable to write code, and a Super Admin's save was refused.
require_once ADMIN_PATH . '/includes/rbac.php';

/**
 * The columns this editor owns, in storage order. Every entity that can be
 * indexed carries exactly these, so a save handler that needs to know which
 * keys belong to the SEO editor asks here instead of restating the list and
 * drifting - which is how the products form ended up persisting only two of
 * the nine while rendering inputs for all nine.
 */
const SEO_EDITOR_FIELDS = [
    'meta_title', 'meta_description', 'focus_keyword', 'canonical_url',
    'robots', 'og_title', 'og_description', 'og_image', 'schema_json',
];

const SEO_EDITOR_ROBOTS = [
    ''                  => 'Site default',
    'index, follow'     => 'Index, follow (normal page)',
    'index, nofollow'   => 'Index, nofollow',
    'noindex, follow'   => 'Noindex, follow (hidden, links still crawled)',
    'noindex, nofollow' => 'Noindex, nofollow (hidden entirely)',
];

/**
 * The extended fields, and the request key each one arrives under.
 *
 * custom_css and custom_js are posted as seo_custom_css / seo_custom_js. The
 * storefront theme has settings of its own called custom_css and custom_js and
 * a product form is large enough that two different fields with one name is a
 * bug waiting to be written; the prefix makes the two impossible to confuse.
 *
 * @var array<string,string> column => request key
 */
const SEO_EDITOR_META_INPUTS = [
    'meta_keywords'       => 'meta_keywords',
    'twitter_title'       => 'twitter_title',
    'twitter_description' => 'twitter_description',
    'twitter_image'       => 'twitter_image',
    'breadcrumb_label'    => 'breadcrumb_label',
    'breadcrumb_hide'     => 'breadcrumb_hide',
    'custom_head'         => 'custom_head',
    'custom_body'         => 'custom_body',
    'custom_css'          => 'seo_custom_css',
    'custom_js'           => 'seo_custom_js',
    'code_consent'        => 'code_consent',
];

function seo_editor(array $row = [], array $options = []): void
{
    static $instance = 0;
    $instance++;

    $id  = 'seoEditor' . $instance;
    $val = static fn (string $k): string => (string) ($row[$k] ?? '');

    // The preview needs a URL to show. A record being created has no slug yet,
    // so it falls back to the site root rather than rendering a broken path.
    $previewUrl = (string) ($options['url'] ?? url());
    $robots     = $val('robots');

    // The entity this panel is editing. Without it the panel still renders and
    // still saves the nine base columns - a create form has no id yet - but the
    // extended fields and the checklist need to know which record they belong
    // to, so they only appear once there is one.
    $entityType = (string) ($options['type'] ?? '');
    $entityId   = (int) ($options['id'] ?? ($row['id'] ?? 0));
    $hasEntity  = $entityType !== '' && seo_entity_type($entityType) !== null;

    $meta = $hasEntity && $entityId > 0
        ? seo_entity_meta($entityType, $entityId)
        : SEO_ENTITY_META_FIELDS;

    $metaVal = static fn (string $k): string => (string) ($meta[$k] ?? '');

    // Custom head/body/CSS/JS run on every visitor's browser, on the store's
    // own origin. They are the same class of field as the theme's script boxes
    // and they carry the same permission: settings.scripts, or Super Admin.
    // Everyone else sees them, read-only, so they can tell what is running and
    // ask - which is the behaviour Settings > Theme already established.
    $canScripts = function_exists('admin_can_edit_scripts') && admin_can_edit_scripts();

    $checklist = $hasEntity && $entityId > 0 ? seo_score($entityType, $row, $meta) : null;
    ?>
    <div class="sik-seo" id="<?= e_attr($id) ?>" data-seo-editor
         data-preview-url="<?= e_attr($previewUrl) ?>"
         data-site-name="<?= e_attr((string) setting('store_name', SITE_NAME)) ?>"
         data-title-from="<?= e_attr((string) ($options['title_from'] ?? '')) ?>"
         data-description-from="<?= e_attr((string) ($options['description_from'] ?? '')) ?>">

        <div class="sik-seo__grid">
            <div class="sik-seo__fields">

                <div class="sik-field">
                    <label class="sik-label" for="<?= e_attr($id) ?>_title">Meta title</label>
                    <input type="text" class="sik-input" id="<?= e_attr($id) ?>_title"
                           name="meta_title" maxlength="255" data-seo="title"
                           value="<?= e($val('meta_title')) ?>"
                           placeholder="Leave blank to use the record name">
                    <p class="sik-help">
                        <span data-seo-count="title">0</span> characters.
                        Google shows about 60 before it truncates.
                    </p>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="<?= e_attr($id) ?>_desc">Meta description</label>
                    <textarea class="sik-input" id="<?= e_attr($id) ?>_desc" name="meta_description"
                              rows="3" maxlength="320" data-seo="description"
                              placeholder="Leave blank to use the record summary"><?= e($val('meta_description')) ?></textarea>
                    <p class="sik-help">
                        <span data-seo-count="description">0</span> characters.
                        Around 155 shows in full.
                    </p>
                </div>

                <div class="sik-seo__row">
                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_focus">Focus keyword</label>
                        <input type="text" class="sik-input" id="<?= e_attr($id) ?>_focus"
                               name="focus_keyword" maxlength="120" data-seo="focus"
                               value="<?= e($val('focus_keyword')) ?>"
                               placeholder="e.g. diwali curtain lights">
                        <p class="sik-help">Used only for the checklist below. It is never published.</p>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_robots">Search engine visibility</label>
                        <select class="sik-input" id="<?= e_attr($id) ?>_robots" name="robots">
                            <?php foreach (SEO_EDITOR_ROBOTS as $value => $label): ?>
                                <option value="<?= e_attr($value) ?>"<?= $robots === $value ? ' selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="<?= e_attr($id) ?>_keywords">Meta keywords</label>
                    <input type="text" class="sik-input" id="<?= e_attr($id) ?>_keywords"
                           name="meta_keywords" maxlength="500"
                           value="<?= e($metaVal('meta_keywords')) ?>"
                           placeholder="curtain lights, diwali lights, fairy lights">
                    <p class="sik-help">
                        Comma separated. Google has ignored this tag since 2009; some smaller engines and
                        several on-site search tools still read it, which is the only reason it is here.
                    </p>
                </div>

                <div class="sik-field">
                    <label class="sik-label" for="<?= e_attr($id) ?>_canonical">Canonical URL</label>
                    <input type="url" class="sik-input" id="<?= e_attr($id) ?>_canonical"
                           name="canonical_url" maxlength="255"
                           value="<?= e($val('canonical_url')) ?>"
                           placeholder="<?= e_attr($previewUrl) ?>">
                    <p class="sik-help">
                        Only set this when this record duplicates another page. Point it at the original.
                    </p>
                </div>

                <details class="sik-seo__more">
                    <summary>Social sharing and custom schema</summary>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_ogtitle">Social title</label>
                        <input type="text" class="sik-input" id="<?= e_attr($id) ?>_ogtitle"
                               name="og_title" maxlength="255" data-seo="ogtitle"
                               value="<?= e($val('og_title')) ?>"
                               placeholder="Falls back to the meta title">
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_ogdesc">Social description</label>
                        <textarea class="sik-input" id="<?= e_attr($id) ?>_ogdesc" name="og_description"
                                  rows="2" maxlength="320" data-seo="ogdesc"
                                  placeholder="Falls back to the meta description"><?= e($val('og_description')) ?></textarea>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_ogimage">Social image path</label>
                        <input type="text" class="sik-input" id="<?= e_attr($id) ?>_ogimage"
                               name="og_image" maxlength="255"
                               value="<?= e($val('og_image')) ?>"
                               placeholder="Falls back to the record's own image">
                    </div>

                    <div class="sik-seo__row">
                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_twtitle">X / Twitter title</label>
                            <input type="text" class="sik-input" id="<?= e_attr($id) ?>_twtitle"
                                   name="twitter_title" maxlength="255"
                                   value="<?= e($metaVal('twitter_title')) ?>"
                                   placeholder="Falls back to the social title">
                        </div>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_twimage">X / Twitter image path</label>
                            <input type="text" class="sik-input" id="<?= e_attr($id) ?>_twimage"
                                   name="twitter_image" maxlength="255"
                                   value="<?= e($metaVal('twitter_image')) ?>"
                                   placeholder="Falls back to the social image">
                        </div>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_twdesc">X / Twitter description</label>
                        <textarea class="sik-input" id="<?= e_attr($id) ?>_twdesc" name="twitter_description"
                                  rows="2" maxlength="500"
                                  placeholder="Falls back to the social description"><?= e($metaVal('twitter_description')) ?></textarea>
                        <p class="sik-help">
                            Leave all three blank and the card uses the social fields above, which in turn
                            fall back to the meta title and description. Three consistent cards beats one
                            filled in and two empty.
                        </p>
                    </div>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_schema">Custom JSON-LD</label>
                        <textarea class="sik-input sik-seo__code" id="<?= e_attr($id) ?>_schema"
                                  name="schema_json" rows="5" data-seo="schema"
                                  spellcheck="false"
                                  placeholder='{"@context":"https://schema.org","@type":"WebPage"}'><?= e($val('schema_json')) ?></textarea>
                        <p class="sik-help" data-seo-schema-status>
                            Added alongside the schema this page already generates. Invalid JSON is ignored.
                        </p>
                    </div>
                </details>

                <details class="sik-seo__more">
                    <summary>Breadcrumbs</summary>

                    <div class="sik-field">
                        <label class="sik-label" for="<?= e_attr($id) ?>_crumb">Breadcrumb label</label>
                        <input type="text" class="sik-input" id="<?= e_attr($id) ?>_crumb"
                               name="breadcrumb_label" maxlength="150"
                               value="<?= e($metaVal('breadcrumb_label')) ?>"
                               placeholder="Falls back to the record's name">
                        <p class="sik-help">
                            A shorter name for the trail only. The page title is unchanged, and the
                            BreadcrumbList structured data uses whatever is shown here, so the two cannot
                            disagree.
                        </p>
                    </div>

                    <div class="sik-field">
                        <label class="sik-check">
                            <input type="checkbox" name="breadcrumb_hide" value="1"
                                   <?= (int) ($meta['breadcrumb_hide'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span>Leave this record out of its own breadcrumb trail</span>
                        </label>
                        <p class="sik-help">
                            For a page whose name would only repeat the heading immediately below it.
                            The ancestors stay.
                        </p>
                    </div>
                </details>

                <?php if ($hasEntity): ?>
                    <details class="sik-seo__more">
                        <summary>Custom code for this page<?= $canScripts ? '' : ' (read-only)' ?></summary>

                        <?php if (!$canScripts): ?>
                            <div class="sik-alert sik-alert--info" style="margin-bottom:12px">
                                <div>
                                    These boxes run code in every visitor&rsquo;s browser, so changing them
                                    needs the <code>settings.scripts</code> permission (or Super Admin).
                                    They are shown so you can see what is running here and ask for a change;
                                    anything you type into them is discarded on save.
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="sik-help" style="margin-bottom:12px">
                                Everything here runs on the storefront only &mdash; never inside this admin
                                panel. Whatever you put here is listed, with your name against it, in
                                <a href="<?= e(admin_url('seo/scripts.php')) ?>">SEO &rsaquo; Injected code</a>.
                            </p>
                        <?php endif; ?>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_consent">When may this code run?</label>
                            <select class="sik-input" id="<?= e_attr($id) ?>_consent" name="code_consent"
                                    <?= $canScripts ? '' : 'disabled' ?>>
                                <?php $consent = (string) ($meta['code_consent'] ?? 'marketing'); ?>
                                <?php foreach (SEO_CODE_CONSENT as $value => $label): ?>
                                    <option value="<?= e_attr($value) ?>"<?= $consent === $value ? ' selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sik-help">
                                Third-party tags default to Marketing and stay off until the visitor accepts,
                                the same as every other tag on the store. Choose &ldquo;Run always&rdquo; only
                                for first-party code that contacts nobody else.
                            </p>
                        </div>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_head">Head code</label>
                            <textarea class="sik-input sik-seo__code" id="<?= e_attr($id) ?>_head"
                                      name="custom_head" rows="4" spellcheck="false"
                                      <?= $canScripts ? '' : 'disabled readonly' ?>
                                      placeholder="&lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt;"><?= e($metaVal('custom_head')) ?></textarea>
                            <p class="sik-help">
                                Goes in <code>&lt;head&gt;</code>. Allowed tags: script, style, link, meta,
                                noscript, template. Loose text and anything else is refused on save.
                            </p>
                        </div>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_body">Body code</label>
                            <textarea class="sik-input sik-seo__code" id="<?= e_attr($id) ?>_body"
                                      name="custom_body" rows="3" spellcheck="false"
                                      <?= $canScripts ? '' : 'disabled readonly' ?>
                                      placeholder="&lt;noscript&gt;&hellip;&lt;/noscript&gt;"><?= e($metaVal('custom_body')) ?></textarea>
                            <p class="sik-help">Emitted immediately after <code>&lt;body&gt;</code>.</p>
                        </div>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_css">Page CSS</label>
                            <textarea class="sik-input sik-seo__code" id="<?= e_attr($id) ?>_css"
                                      name="seo_custom_css" rows="3" spellcheck="false"
                                      <?= $canScripts ? '' : 'disabled readonly' ?>
                                      placeholder=".sik-hero { background: #101010 }"><?= e($metaVal('custom_css')) ?></textarea>
                            <p class="sik-help">CSS only &mdash; it is wrapped in a style element for you.</p>
                        </div>

                        <div class="sik-field">
                            <label class="sik-label" for="<?= e_attr($id) ?>_js">Page JavaScript</label>
                            <textarea class="sik-input sik-seo__code" id="<?= e_attr($id) ?>_js"
                                      name="seo_custom_js" rows="3" spellcheck="false"
                                      <?= $canScripts ? '' : 'disabled readonly' ?>
                                      placeholder="document.querySelector('.sik-hero')?.classList.add('is-lit');"><?= e($metaVal('custom_js')) ?></textarea>
                            <p class="sik-help">
                                JavaScript only &mdash; it is wrapped in a deferred script element, so it runs
                                after the page has been parsed, exactly like end-of-body code.
                            </p>
                        </div>

                        <?php if (!empty($meta['code_updated_at'])): ?>
                            <p class="sik-help">
                                Last changed <?= e(format_datetime((string) $meta['code_updated_at'])) ?>
                                <?php if (!empty($meta['code_updated_by'])): ?>
                                    by
                                    <?= e((string) Database::fetchColumn(
                                        'SELECT `name` FROM `admins` WHERE `id` = :id',
                                        ['id' => (int) $meta['code_updated_by']]
                                    ) ?: 'a deleted admin') ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>

            <aside class="sik-seo__side">
                <p class="sik-seo__legend">Google result preview</p>
                <div class="sik-seo__serp">
                    <span class="sik-seo__serp-url" data-seo-preview="url"></span>
                    <span class="sik-seo__serp-title" data-seo-preview="title"></span>
                    <span class="sik-seo__serp-desc" data-seo-preview="description"></span>
                </div>

                <p class="sik-seo__legend">Shared on social</p>
                <div class="sik-seo__card">
                    <div class="sik-seo__card-img" aria-hidden="true"></div>
                    <div class="sik-seo__card-body">
                        <span class="sik-seo__card-site" data-seo-preview="site"></span>
                        <span class="sik-seo__card-title" data-seo-preview="ogtitle"></span>
                        <span class="sik-seo__card-desc" data-seo-preview="ogdesc"></span>
                    </div>
                </div>

                <p class="sik-seo__legend">Checklist</p>
                <ul class="sik-seo__checks" data-seo-checks></ul>

                <?php if ($checklist !== null): ?>
                    <?php
                    /* The list above is the live one: it re-reads the title and
                       description boxes as you type, but it can only see what is
                       on this form. The list below was computed on the server
                       from the SAVED record, so it can do the things the browser
                       cannot - ask whether another record already uses this
                       title, count the images with no alt text, look inside the
                       body copy. It refreshes when the form is saved.

                       Deliberately "N of M checks pass" rather than a score out
                       of 100. Every line here is a fact this code verified; a
                       weighted number would imply a prediction nobody can make
                       from a database row. */
                    ?>
                    <p class="sik-seo__legend" data-seo-serverchecks>
                        Checked on the saved record
                        &mdash; <?= (int) $checklist['passed'] ?> of <?= (int) $checklist['applicable'] ?> pass
                    </p>
                    <ul class="sik-seo__checks">
                        <?php foreach ($checklist['items'] as $item): ?>
                            <li class="sik-seo__check <?= $item['status'] === 'pass'
                                    ? 'is-ok'
                                    : ($item['status'] === 'na' ? 'is-na' : 'is-todo') ?>">
                                <strong><?= e($item['label']) ?></strong>
                                <span class="sik-help" style="display:block"><?= e($item['advice']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="sik-help">
                        Nothing here predicts a ranking. Each line is something this code read off the
                        record and could show you.
                    </p>
                <?php endif; ?>
            </aside>
        </div>
    </div>
    <?php
}

/**
 * Pull the editor's fields out of the request, normalised and ready to store.
 *
 * Every value is trimmed, empty strings become NULL so "unset" is a single
 * state in the database, robots is validated against the allowlist, and the
 * custom schema is rejected unless it parses - storing broken JSON would push
 * the failure out to the crawler.
 *
 * @return array<string,string|null>
 */
function seo_editor_input(): array
{
    $take = static function (string $key): ?string {
        $value = trim((string) input($key, ''));
        return $value === '' ? null : $value;
    };

    $robots = $take('robots');
    if ($robots !== null && !array_key_exists($robots, SEO_EDITOR_ROBOTS)) {
        $robots = seo_normalise_robots($robots);
    }

    $schema = $take('schema_json');
    if ($schema !== null) {
        $decoded = json_decode($schema, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $schema = null;
        } else {
            // Re-encode so what is stored is exactly what will be emitted.
            $schema = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    return [
        'meta_title'       => $take('meta_title'),
        'meta_description' => $take('meta_description'),
        'focus_keyword'    => $take('focus_keyword'),
        'canonical_url'    => $take('canonical_url'),
        'robots'           => $robots,
        'og_title'         => $take('og_title'),
        'og_description'   => $take('og_description'),
        'og_image'         => $take('og_image'),
        'schema_json'      => $schema,
    ];
}
/**
 * The extended fields, pulled out of the request and ready to store.
 *
 * TWO CLASSES OF FIELD, AND WHY THEY ARE READ DIFFERENTLY
 * -------------------------------------------------------
 * Keywords, the Twitter trio and the breadcrumb controls are ordinary text:
 * they are trimmed, and a submitted blank clears them.
 *
 * The four code fields are not. They run in every visitor's browser on the
 * store's own origin, which makes them the same class of field as the theme's
 * script boxes, carrying the same permission (settings.scripts, or Super
 * Admin). For anyone else they are DROPPED here rather than saved - not
 * blanked, dropped - because seo_entity_meta_save() only writes the keys it is
 * given, so an editor saving a product cannot wipe the snippet a Super Admin
 * put on it, and cannot plant one either.
 *
 * A hand-crafted POST is the case that matters: the textareas are `disabled`
 * in the HTML, so a browser never sends them, and anything that arrives from
 * an actor without the permission was deliberate. That is recorded as a
 * security event and reported to the caller, which refuses the save.
 *
 * @param  array<string,string> $errors filled with per-field problems
 * @return array<string,mixed>          any subset of SEO_ENTITY_META_FIELDS
 */
function seo_editor_meta_input(array &$errors = []): array
{
    /**
     * One submitted value, or null when the field was not on the form.
     *
     * The scalar test is not paranoia: every one of these is a single input, so
     * a POST carrying `meta_keywords[]=x` is hand-crafted, and without the
     * guard `(string) $array` emits "Array to string conversion" and stores the
     * literal text "Array". A non-scalar is treated as "not submitted".
     */
    $submitted = static function (string $requestKey): ?string {
        $value = input($requestKey, null);

        return is_scalar($value) ? trim((string) $value) : null;
    };

    $values = [];

    foreach (['meta_keywords', 'twitter_title', 'twitter_description', 'twitter_image',
              'breadcrumb_label'] as $field) {
        // Only what was actually submitted. A form that does not render the
        // panel at all (a bulk edit, an import) must not blank it.
        $value = $submitted(SEO_EDITOR_META_INPUTS[$field]);
        if ($value === null) {
            continue;
        }
        $values[$field] = $value;
    }

    // A checkbox is absent when unticked, so it can only be read when the
    // panel that owns it was on the page. breadcrumb_label is rendered in the
    // same <details> block, so its presence is the reliable signal.
    if (input('breadcrumb_label', null) !== null) {
        $values['breadcrumb_hide'] = input_bool('breadcrumb_hide') ? 1 : 0;
    }

    $submittedCode = [];
    foreach (SEO_ENTITY_CODE_FIELDS as $field) {
        $value = $submitted(SEO_EDITOR_META_INPUTS[$field]);
        if ($value === null) {
            continue;
        }
        $submittedCode[$field] = $value;
    }
    if (($consent = $submitted('code_consent')) !== null) {
        $submittedCode['code_consent'] = $consent;
    }

    if ($submittedCode === []) {
        return $values;
    }

    if (!function_exists('admin_can_edit_scripts') || !admin_can_edit_scripts()) {
        // Never silently dropped: the caller is told, so it can refuse the whole
        // save rather than appear to succeed while discarding a field.
        $errors['custom_head'] = 'Changing the code that runs on this page needs the settings.scripts '
            . 'permission. Nothing was saved.';

        security_event('seo.entity_code_denied', 'high', [
            'fields' => array_keys($submittedCode),
            'bytes'  => array_sum(array_map('strlen', array_map('strval', $submittedCode))),
        ], function_exists('admin_user') && admin_user() !== null ? (int) admin_user()['id'] : null, 'admin');

        return $values;
    }

    // Markup is validated at the door, because escaping it on the way out would
    // make it useless. CSS and JS are not validated as languages - we are not a
    // linter - but they cannot escape their element when they are printed.
    foreach (['custom_head' => 'head', 'custom_body' => 'body'] as $field => $slot) {
        if (!isset($submittedCode[$field]) || $submittedCode[$field] === '') {
            continue;
        }
        $problem = seo_code_problem($submittedCode[$field], $slot);
        if ($problem !== null) {
            $errors[$field] = $problem;
            unset($submittedCode[$field]);
        }
    }

    foreach (['custom_css', 'custom_js'] as $field) {
        if (isset($submittedCode[$field]) && strlen($submittedCode[$field]) > 20000) {
            $errors[$field] = 'That is over 20,000 characters. Put anything that long in a file and link to it.';
            unset($submittedCode[$field]);
        }
    }

    return $values + $submittedCode;
}

/**
 * Read the panel and persist the extended fields for one record.
 *
 * The single call an entity's save handler makes. Everything the panel posts
 * that does not have a column on the entity's own table lands here; the nine
 * base columns are still read with seo_editor_input() and written by the
 * handler's own INSERT/UPDATE, because they belong to that row.
 *
 * Returns the field errors, so a handler can surface them. A code field that
 * was refused is an error the admin must see - a save that quietly discards
 * what somebody typed is the failure mode this whole panel was built to avoid.
 *
 * @return array<string,string>
 */
function seo_editor_save(string $entityType, int $entityId): array
{
    $errors = [];
    $values = seo_editor_meta_input($errors);

    if ($values !== [] && $entityId > 0) {
        seo_entity_meta_save($entityType, $entityId, $values);
    }

    return $errors;
}

/**
 * The slug to store, refusing a typed one that collides.
 *
 * The distinction this makes is the whole point of it:
 *
 *   - a slug the admin TYPED is a decision, so a collision is refused and
 *     explained. unique_slug()'s silent "here is winter-lights-2" is how a
 *     catalogue ends up with two records one digit apart and nobody knowing
 *     which one the campaign links to.
 *   - a slug DERIVED from the name is not a decision, so it keeps the old
 *     behaviour and is quietly made unique. Blocking a save because two
 *     products happen to share a name would be obstruction, not care.
 *
 * @param string $typed  what the slug field posted ('' when left blank)
 * @param string $from   the name/title to build one from when it was blank
 * @param array  $errors filled with ['slug' => why] when a typed slug is refused
 */
function seo_editor_slug(string $entityType, string $typed, string $from, ?int $id, array &$errors): string
{
    $spec  = seo_entity_type($entityType);
    $table = (string) ($spec['table'] ?? '');
    $typed = trim($typed);

    if ($typed === '' || $table === '') {
        return unique_slug($table !== '' ? $table : 'products', slugify($typed !== '' ? $typed : $from), $id);
    }

    // Fold it first, so "Winter Lights" is judged as the slug it would become
    // rather than refused for having capitals the form would have fixed anyway.
    $slug    = slugify($typed);
    $problem = seo_slug_problem($entityType, $slug, $id);

    if ($problem !== null) {
        $errors['slug'] = $problem;
        return $slug;                    // echoed back so the field keeps what was typed
    }

    return $slug;
}

/**
 * Record why a typed slug cannot be used, alongside the panel's own errors.
 *
 * Called in the staging block at the top of a save handler, so the refusal
 * joins the validator's errors and the existing "nothing was saved" branch
 * handles it. seo_editor_slug() below then only has to build the value, because
 * a save that reaches it has already been cleared.
 */
function seo_editor_slug_check(string $entityType, string $typed, ?int $id, array &$errors): void
{
    $typed = trim($typed);
    if ($typed === '') {
        return;                          // derived from the name; never blocks a save
    }

    $problem = seo_slug_problem($entityType, slugify($typed), $id);
    if ($problem !== null) {
        $errors['slug'] = $problem;
    }
}

/**
 * Handle a slug change on a record that is already published.
 *
 * Called by an entity's save handler with the slug it is about to write. When
 * the slug has really changed and the admin left "keep the old URL working"
 * ticked - it is ticked by default, because a silently broken link is worse
 * than a redirect nobody needed - a 301 is written through the same
 * `redirects` table 404.php already consults.
 *
 * @return bool whether a redirect was written
 */
function seo_editor_slug_change(string $entityType, string $oldSlug, string $newSlug): bool
{
    if ($oldSlug === '' || $oldSlug === $newSlug) {
        return false;
    }

    // Absent means ticked: a form that does not render the checkbox (an import,
    // a bulk action) should still preserve links rather than quietly break them.
    $keep = input('seo_keep_old_url', null) === null ? true : input_bool('seo_keep_old_url');

    $id = seo_slug_redirect($entityType, $oldSlug, $newSlug, $keep);
    if ($id === null) {
        return false;
    }

    log_activity(
        'seo.redirect_created',
        'redirect',
        $id,
        'Slug changed: ' . seo_entity_path($entityType, $oldSlug)
            . ' now redirects to ' . seo_entity_path($entityType, $newSlug)
    );

    return true;
}

/**
 * The "keep the old URL working" checkbox, for an entity form's slug field.
 *
 * Rendered next to the slug rather than in the SEO panel, because that is
 * where the decision is made: an admin editing a slug should see the
 * consequence in the same glance, not three tabs away.
 */
function seo_slug_keep_checkbox(string $entityType, string $currentSlug): void
{
    if ($currentSlug === '' || seo_entity_type($entityType) === null) {
        return;
    }
    ?>
    <label class="sik-check" style="margin-top:6px">
        <input type="checkbox" name="seo_keep_old_url" value="1" checked>
        <span>
            If I change this, keep
            <code><?= e(seo_entity_path($entityType, $currentSlug)) ?></code>
            working as a 301 redirect
        </span>
    </label>
    <?php
}
