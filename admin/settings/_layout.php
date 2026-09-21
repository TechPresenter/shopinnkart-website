<?php
/**
 * ShopInnKart Admin - Settings shell.
 *
 * The nine settings screens share one tab strip and one save path, so adding
 * a field is a spec entry rather than another copy of
 * validate -> setting_save() -> log_activity() -> admin_after_write().
 *
 * A field spec entry looks like:
 *
 *   'store_email' => [
 *       'type'      => 'email',   // text email url tel password textarea code
 *                                 // number bool select color image json
 *       'label'     => 'Support email',
 *       'help'      => 'Shown in the footer and used as the reply-to address.',
 *       'required'  => true,
 *       'max'       => 190,       // characters, for the text-ish types
 *       'min_value' => 1,         // numeric bounds, for type number
 *       'max_value' => 100,
 *       'options'   => [...],     // for type select
 *       'folder'    => 'branding',// upload folder, for type image
 *       'group'     => 'order',   // overrides the screen's settings_group
 *       'attr'      => 'data-x="y"',
 *   ]
 */

declare(strict_types=1);

/** The tab strip, in the order an admin works through it. */
const SETTINGS_SCREENS = [
    'general'  => ['label' => 'General',  'file' => 'general.php'],
    'store'    => ['label' => 'Store',    'file' => 'store.php'],
    'payment'  => ['label' => 'Payment',  'file' => 'payment.php'],
    'shipping' => ['label' => 'Shipping', 'file' => 'shipping.php'],
    'tax'      => ['label' => 'Tax',      'file' => 'tax.php'],
    'invoice'  => ['label' => 'Invoice',  'file' => 'invoice.php'],
    'email'    => ['label' => 'Email',    'file' => 'email.php'],
    // The delivery log for everything email.php sends. Read-only, but it is the
    // first place an admin looks when a customer says "I never got it".
    'email-log' => ['label' => 'Email Log', 'file' => 'email-log.php'],
    'seo'      => ['label' => 'SEO',      'file' => 'seo.php'],
    // A read-only report rather than a settings form, but it belongs beside the
    // SEO settings it reports on, so it shares their tab strip.
    'seo-health' => ['label' => 'SEO Health', 'file' => 'seo-health.php'],
    'redirects'  => ['label' => 'Redirects',  'file' => 'redirects.php'],
    'theme'    => ['label' => 'Theme',    'file' => 'theme.php'],
    'social'   => ['label' => 'Social',   'file' => 'social.php'],
    // The `widgets` group - popup kill switches, the ticker, the floating
    // helpers. Every key here was already read by the storefront; until this
    // screen existed the only way to change one was an UPDATE on the table.
    'widgets'  => ['label' => 'Widgets',  'file' => 'widgets.php'],
];

/** Absolute URL of a settings screen. */
function settings_url(string $screen): string
{
    $file = SETTINGS_SCREENS[$screen]['file'] ?? 'general.php';
    return admin_url('settings/' . $file);
}

function settings_label(string $screen): string
{
    return (string) (SETTINGS_SCREENS[$screen]['label'] ?? 'Settings');
}

/** Breadcrumbs every screen shares. */
function settings_breadcrumbs(string $screen): array
{
    return [
        ['label' => 'Dashboard', 'url' => admin_url('dashboard.php')],
        ['label' => 'Settings',  'url' => settings_url('general')],
        ['label' => settings_label($screen)],
    ];
}

/** Horizontal tab strip linking the nine screens. */
function settings_tabs(string $current): string
{
    $html = '<div class="ad-card"><div class="ad-tabs">';
    foreach (SETTINGS_SCREENS as $key => $screen) {
        $html .= '<a class="ad-tab' . ($key === $current ? ' is-active' : '') . '"'
            . ' href="' . e(admin_url('settings/' . $screen['file'])) . '"'
            . ($key === $current ? ' aria-current="page"' : '') . '>'
            . e($screen['label']) . '</a>';
    }
    return $html . '</div></div>';
}

// ===========================================================================
//  READ
// ===========================================================================

/** True when the previous request bounced back with rejected input. */
function settings_has_old(): bool
{
    return isset($_SESSION['_old']);
}

/**
 * Current value for every field in the spec: submitted input that failed
 * validation wins, then the stored setting, then the spec default.
 */
function settings_values(array $spec, array $stored): array
{
    $hasOld = settings_has_old();
    $values = [];

    foreach ($spec as $key => $field) {
        $default = (string) ($field['default'] ?? '');

        // An unchecked switch posts nothing, so after a bounce its absence is
        // the answer - falling back to the stored value would flip it back on.
        if (($field['type'] ?? 'text') === 'bool' && $hasOld) {
            $values[$key] = old($key, null) === null ? '0' : '1';
            continue;
        }

        $submitted = $hasOld ? old($key, null) : null;
        $values[$key] = (string) ($submitted ?? $stored[$key] ?? $default);
    }

    old_clear();
    return $values;
}

// ===========================================================================
//  WRITE
// ===========================================================================

/** settings.setting_type for a spec entry. */
function settings_store_type(array $field): string
{
    $map = [
        'text' => 'text', 'email' => 'text', 'url' => 'text', 'tel' => 'text', 'password' => 'text',
        'textarea' => 'textarea', 'code' => 'textarea', 'json' => 'json',
        'number' => 'number', 'bool' => 'boolean', 'select' => 'select',
        'color' => 'color', 'image' => 'image',
    ];
    return $map[$field['type'] ?? 'text'] ?? 'text';
}

/**
 * Resolve an image field: an upload replaces the old file, the remove flag
 * clears it, and nothing submitted keeps what is stored.
 */
function settings_image_value(string $key, string $folder, string $current): string
{
    if (input_bool('remove_' . $key)) {
        // delete_upload() ignores anything outside /uploads, so the packaged
        // assets that ship as defaults survive being "removed".
        delete_upload($current);
        return '';
    }

    return (string) (admin_handle_image($key, $folder, $current !== '' ? $current : null) ?? '');
}

/**
 * Validate and persist a spec against POST, then redirect back to the screen.
 * Never returns.
 *
 * $options lets a screen that is NOT in the settings tab strip reuse this one
 * pipeline instead of copying the validate -> save -> log -> bust sequence:
 *
 *   'redirect' => absolute URL to return to   (default: settings_url($screen))
 *   'label'    => name used in the flash and the activity log
 *                                             (default: settings_label($screen))
 *
 * Admin > Appearance > Header is the first caller that needs them. Without
 * this, a second screen means a second copy of the validator, and the two
 * drift the first time a field type is added.
 */
function settings_handle_save(string $screen, string $group, array $spec, array $stored, array $options = []): void
{
    admin_require_action('settings.edit');   // POST + CSRF + permission

    $errors = [];
    $values = [];

    foreach ($spec as $key => $field) {
        $type  = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key)));

        if ($type === 'bool') {
            $values[$key] = input_bool($key) ? '1' : '0';
            continue;
        }

        if ($type === 'image') {
            $values[$key] = settings_image_value($key, (string) ($field['folder'] ?? 'branding'), (string) ($stored[$key] ?? ''));
            continue;
        }

        $raw = trim((string) input($key, ''));

        if ($type === 'password') {
            // Blank means "keep the stored secret", which is what lets the page
            // avoid ever rendering it back into the HTML.
            if ($raw !== '') {
                $values[$key] = $raw;
            }
            continue;
        }

        if ($raw === '') {
            if (!empty($field['required'])) {
                $errors[$key] = $label . ' is required.';
                continue;
            }
            $values[$key] = '';
            continue;
        }

        if (isset($field['max']) && mb_strlen($raw) > (int) $field['max']) {
            $errors[$key] = $label . ' must not exceed ' . (int) $field['max'] . ' characters.';
            continue;
        }

        switch ($type) {
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $errors[$key] = $label . ' must be a valid email address.';
                }
                break;

            case 'url':
                if (!filter_var($raw, FILTER_VALIDATE_URL) || preg_match('#^https?://#i', $raw) !== 1) {
                    $errors[$key] = $label . ' must be a full URL starting with http:// or https://.';
                }
                break;

            case 'number':
                if (!is_numeric($raw)) {
                    $errors[$key] = $label . ' must be a number.';
                    break;
                }
                $number = (float) $raw;
                if (isset($field['min_value']) && $number < (float) $field['min_value']) {
                    $errors[$key] = $label . ' cannot be lower than ' . $field['min_value'] . '.';
                } elseif (isset($field['max_value']) && $number > (float) $field['max_value']) {
                    $errors[$key] = $label . ' cannot be higher than ' . $field['max_value'] . '.';
                }
                break;

            case 'select':
                if (!array_key_exists($raw, (array) ($field['options'] ?? []))) {
                    $errors[$key] = 'Choose a valid ' . mb_strtolower($label) . '.';
                }
                break;

            case 'color':
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) !== 1) {
                    $errors[$key] = $label . ' must be a 6-digit hex colour such as #F4511E.';
                }
                break;

            case 'json':
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[$key] = $label . ' must be valid JSON: ' . json_last_error_msg() . '.';
                }
                break;
        }

        if (!isset($errors[$key])) {
            $values[$key] = $raw;
        }
    }

    $back  = (string) ($options['redirect'] ?? settings_url($screen));
    $label = (string) ($options['label'] ?? settings_label($screen));

    if ($errors !== []) {
        flash_errors($errors);
        flash_old($_POST);
        flash('error', 'Nothing was saved. Please fix the highlighted fields.');
        redirect($back);
    }

    foreach ($values as $key => $value) {
        setting_save($key, $value, (string) ($spec[$key]['group'] ?? $group), settings_store_type($spec[$key]));
    }

    log_activity('settings.updated', 'settings', null,
        'Updated ' . $label . ' settings (' . count($values) . ' field(s))');
    admin_after_write();

    flash('success', $label . ' settings saved.');
    redirect($back);
}

// ===========================================================================
//  FIELD RENDERING
// ===========================================================================

/** Label + control + help/error for one spec entry. */
function settings_field(string $key, array $spec, array $values, array $errors): string
{
    $field   = $spec[$key] ?? [];
    $type    = (string) ($field['type'] ?? 'text');
    $label   = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key)));
    $value   = (string) ($values[$key] ?? '');
    $error   = (string) ($errors[$key] ?? '');
    $help    = (string) ($field['help'] ?? '');
    $extra   = (string) ($field['attr'] ?? '');
    $id      = 'set_' . $key;
    $invalid = $error !== '' ? ' is-invalid' : '';
    $req     = !empty($field['required']);

    $foot = $error !== ''
        ? '<span class="sik-error">' . e($error) . '</span>'
        : ($help !== '' ? '<span class="sik-help">' . e($help) . '</span>' : '');

    $labelHtml = '<label class="sik-label" for="' . e_attr($id) . '">' . e($label)
        . ($req ? ' <span class="req">*</span>' : '') . '</label>';

    // A switch carries its own label inside the control.
    if ($type === 'bool') {
        return '<div class="ad-field">'
            . '<label class="ad-switch">'
            . '<input type="checkbox" id="' . e_attr($id) . '" name="' . e_attr($key) . '" value="1"'
            . ($value === '1' ? ' checked' : '') . ' ' . $extra . '>'
            . '<span class="ad-switch__track"></span>'
            . '<span>' . e($label) . '</span>'
            . '</label>'
            . $foot
            . '</div>';
    }

    if ($type === 'image') {
        $previewId = 'prev_' . $key;
        $removeId  = 'rm_' . $key;

        $preview = '';
        if ($value !== '') {
            $preview = '<div class="ad-preview__item">'
                . '<img src="' . e(img_url($value)) . '" alt="' . e_attr($label) . '">'
                . '<button type="button" class="ad-preview__remove" data-remove-image="#' . e_attr($removeId) . '"'
                . ' aria-label="Remove ' . e_attr($label) . '">&times;</button>'
                . '</div>';
        }

        return '<div class="ad-field">'
            . '<span class="sik-label">' . e($label) . '</span>'
            . '<div class="ad-drop" data-drop="#' . e_attr($previewId) . '">'
            . '<input type="file" name="' . e_attr($key) . '" accept="image/*">'
            . icon('upload', 'w-6 h-6')
            . '<div style="font-size:13px;margin-top:6px">Click or drop an image here</div>'
            . '</div>'
            . '<div class="ad-preview" id="' . e_attr($previewId) . '">' . $preview . '</div>'
            . '<input type="hidden" name="remove_' . e_attr($key) . '" id="' . e_attr($removeId) . '" value="0">'
            . $foot
            . '</div>';
    }

    if ($type === 'select') {
        return '<div class="ad-field">' . $labelHtml
            . '<select class="sik-select' . $invalid . '" id="' . e_attr($id) . '" name="' . e_attr($key) . '" ' . $extra . '>'
            . admin_options((array) ($field['options'] ?? []), $value)
            . '</select>' . $foot . '</div>';
    }

    if ($type === 'textarea' || $type === 'code' || $type === 'json') {
        $rows = (int) ($field['rows'] ?? ($type === 'textarea' ? 4 : 8));
        $mono = $type === 'textarea' ? '' : ' style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"';
        return '<div class="ad-field">' . $labelHtml
            . '<textarea class="sik-textarea' . $invalid . '" id="' . e_attr($id) . '" name="' . e_attr($key) . '"'
            . ' rows="' . $rows . '"' . $mono
            . ($req ? ' required' : '')
            . ' placeholder="' . e_attr((string) ($field['placeholder'] ?? '')) . '" ' . $extra . '>'
            . e($value) . '</textarea>' . $foot . '</div>';
    }

    if ($type === 'color') {
        // Only the text input posts, so a blank field stays blank instead of
        // being coerced to #000000 by the native picker.
        return '<div class="ad-field">' . $labelHtml
            . '<div class="ad-colorfield">'
            . '<input type="color" value="' . e_attr(preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : '#ffffff') . '"'
            . ' data-color-picker="' . e_attr($id) . '" aria-label="' . e_attr($label . ' colour picker') . '">'
            . '<input class="sik-input' . $invalid . '" type="text" id="' . e_attr($id) . '" name="' . e_attr($key) . '"'
            . ' value="' . e_attr($value) . '" maxlength="7" placeholder="#F4511E" spellcheck="false" ' . $extra . '>'
            . '</div>' . $foot . '</div>';
    }

    $inputType = [
        'email' => 'email', 'url' => 'url', 'tel' => 'tel',
        'password' => 'password', 'number' => 'number',
    ][$type] ?? 'text';

    $attributes = ' type="' . $inputType . '"';
    if ($type === 'number') {
        $attributes .= ' step="' . e_attr((string) ($field['step'] ?? '1')) . '"';
        if (isset($field['min_value'])) {
            $attributes .= ' min="' . e_attr((string) $field['min_value']) . '"';
        }
        if (isset($field['max_value'])) {
            $attributes .= ' max="' . e_attr((string) $field['max_value']) . '"';
        }
    } elseif (isset($field['max'])) {
        $attributes .= ' maxlength="' . (int) $field['max'] . '"';
    }
    if ($type === 'password') {
        $attributes .= ' autocomplete="new-password"';
        $value = '';   // a stored secret is never echoed back into the page
    }

    return '<div class="ad-field">' . $labelHtml
        . '<input class="sik-input' . $invalid . '" id="' . e_attr($id) . '" name="' . e_attr($key) . '"'
        . $attributes
        . ' value="' . e_attr($value) . '"'
        . ' placeholder="' . e_attr((string) ($field['placeholder'] ?? '')) . '"'
        . ($req ? ' required' : '') . ' ' . $extra . '>'
        . $foot . '</div>';
}

/** Several fields in one call, in the order given. */
function settings_fields(array $keys, array $spec, array $values, array $errors): string
{
    $html = '';
    foreach ($keys as $key) {
        $html .= settings_field($key, $spec, $values, $errors);
    }
    return $html;
}

// ===========================================================================
//  SPEC HARVEST
// ===========================================================================

/**
 * Read one settings screen's field spec without rendering or saving it.
 *
 * Admin > Appearance needs two things a hub cannot invent for itself: the list
 * of keys a section owns, and the value each one ships with. Copying either
 * into the hub would produce a second source of truth that is correct on the
 * day it is written and wrong the first time a field is added - which is
 * exactly the failure "reset to defaults" must never have.
 *
 * So the screens publish instead. A screen that wants to be harvestable ends
 * its spec with:
 *
 *     if (defined('SETTINGS_SPEC_ONLY')) {
 *         return ['spec' => $spec, 'group' => 'theme', 'defaults' => THEME_DEFAULTS];
 *     }
 *
 * `defaults` is optional: without it the shipped value is each entry's own
 * `default`. `group` is the screen's settings group, which a per-field `group`
 * override still beats - the same precedence settings_handle_save() applies.
 *
 * Three guarantees make including a whole admin page safe here:
 *
 *   1. The hook sits above every branch that writes, so the include returns
 *      before any save path is reached.
 *   2. The request is presented to the included file as a bodyless GET
 *      regardless of what the real request is, so even a screen that later
 *      grows a write above its hook cannot fire it from a harvest. The
 *      Appearance reset endpoint is itself a POST, which is what makes this
 *      belt as well as braces.
 *   3. Output is captured and discarded, so a screen that echoes before its
 *      hook cannot corrupt the calling page.
 *
 * The result is memoised because a `const` at a screen's file scope would warn
 * on a second include.
 */
function settings_spec_harvest(string $screen): array
{
    static $cache = [];

    if (isset($cache[$screen])) {
        return $cache[$screen];
    }

    $file = ADMIN_PATH . '/settings/' . (SETTINGS_SCREENS[$screen]['file'] ?? '');
    if (!isset(SETTINGS_SCREENS[$screen]) || !is_file($file)) {
        return $cache[$screen] = ['spec' => [], 'group' => '', 'defaults' => []];
    }

    if (!defined('SETTINGS_SPEC_ONLY')) {
        define('SETTINGS_SPEC_ONLY', true);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $post   = $_POST;
    $get    = $_GET;

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $_GET  = [];

    ob_start();
    try {
        // A closure scope keeps the screen's locals out of the caller's.
        $result = (static fn (string $path) => include $path)($file);
    } finally {
        ob_end_clean();
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        $_GET  = $get;
    }

    if (!is_array($result) || !isset($result['spec']) || !is_array($result['spec'])) {
        // The screen has no hook (or was edited past it). Say so with an empty
        // spec rather than guessing - a caller that needs defaults will report
        // the gap instead of silently resetting nothing.
        return $cache[$screen] = ['spec' => [], 'group' => '', 'defaults' => []];
    }

    $spec     = $result['spec'];
    $group    = (string) ($result['group'] ?? '');
    $defaults = (array) ($result['defaults'] ?? []);

    foreach ($spec as $key => $field) {
        if (!array_key_exists($key, $defaults)) {
            $defaults[$key] = (string) ($field['default'] ?? '');
        }
    }

    return $cache[$screen] = ['spec' => $spec, 'group' => $group, 'defaults' => $defaults];
}

/** The settings group one harvested field is stored in. */
function settings_spec_group(array $harvest, string $key): string
{
    return (string) ($harvest['spec'][$key]['group'] ?? $harvest['group']);
}

/** The save bar every settings form ends with. */
function settings_save_bar(string $note = ''): string
{
    // A read-only admin gets the explanation instead of a button that would
    // only bounce off admin_require_action().
    if (!admin_can('settings.edit')) {
        return '<div class="ad-card__foot">'
            . '<span class="ad-muted" style="margin-right:auto;font-size:12.5px">'
            . 'You have read-only access to settings. Ask a Super Admin for the '
            . '<code>settings.edit</code> permission to change anything here.</span>'
            . '</div>';
    }

    return '<div class="ad-card__foot">'
        . ($note !== '' ? '<span class="ad-muted" style="margin-right:auto;font-size:12.5px">' . e($note) . '</span>' : '')
        . '<button type="submit" class="ad-btn ad-btn--primary">' . icon('check', 'w-4 h-4') . ' Save Changes</button>'
        . '</div>';
}
