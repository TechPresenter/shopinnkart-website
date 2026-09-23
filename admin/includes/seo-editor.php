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
