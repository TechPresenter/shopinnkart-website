<?php
/**
 * ShopInnKart Admin - Banner validation + persistence.
 *
 * create.php and edit.php both post into banner_form_save(). Uploads only
 * happen once the rest of the form has passed, so a rejected submit never
 * leaves an orphan file in /uploads/banners.
 */

declare(strict_types=1);

// Include-only: the parent page has already run the auth and permission checks.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once ADMIN_PATH . '/includes/marketing.php';

/** Where a banner appears on the storefront. */
function banner_positions(): array
{
    return [
        'hero'     => 'Hero slider',
        'promo'    => 'Promo strip',
        'category' => 'Category banner',
        'sidebar'  => 'Sidebar banner',
    ];
}

/** What each position is actually used for, shown as help on the list screen. */
function banner_position_notes(): array
{
    return [
        'hero'     => 'Full-width slides at the top of the homepage. Needs a desktop image.',
        'promo'    => 'A single promo block; the widget picks one active banner at random.',
        'category' => 'Artwork shown alongside category listings.',
        'sidebar'  => 'Narrow banners for the shop sidebar.',
    ];
}

/** Blank banner used by create.php. */
function banner_form_defaults(): array
{
    return [
        'id'            => 0,
        'position'      => 'hero',
        'title'         => '',
        'title_accent'  => '',
        'subtitle'      => '',
        'description'   => '',
        'badge'         => '',
        'desktop_image' => null,
        'mobile_image'  => null,
        'button_text'   => '',
        'button_url'    => '',
        'button2_text'  => '',
        'button2_url'   => '',
        'bg_color'      => '',
        'text_color'    => '',
        'sort_order'    => 0,
        'start_date'    => '',
        'end_date'      => '',
        'status'        => 'active',
    ];
}

/** Every scalar field the form posts. */
function banner_form_input(): array
{
    $text = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    return [
        'position'     => $text('position'),
        'title'        => $text('title'),
        'title_accent' => $text('title_accent'),
        'subtitle'     => $text('subtitle'),
        'description'  => $text('description'),
        'badge'        => $text('badge'),
        'button_text'  => $text('button_text'),
        'button_url'   => $text('button_url'),
        'button2_text' => $text('button2_text'),
        'button2_url'  => $text('button2_url'),
        'bg_color'     => $text('bg_color'),
        'text_color'   => $text('text_color'),
        'sort_order'   => $text('sort_order'),
        'start_date'   => $text('start_date'),
        'end_date'     => $text('end_date'),
        'status'       => $text('status'),
    ];
}

/** A colour the storefront can drop straight into a style attribute. */
function banner_is_color(string $value): bool
{
    return preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $value) === 1;
}

/**
 * The values the form should render: the stored row, overlaid with whatever
 * was just submitted so a failed save re-renders what the admin typed.
 */
function banner_form_state(?int $id): array
{
    $banner = $id !== null
        ? Database::fetch('SELECT * FROM `banners` WHERE `id` = :id', ['id' => $id])
        : banner_form_defaults();

    if ($banner === null) {
        return banner_form_defaults();
    }

    if ($id !== null) {
        $banner['start_date'] = marketing_dt_input($banner['start_date']);
        $banner['end_date']   = marketing_dt_input($banner['end_date']);
    }

    if (is_post()) {
        // The images are not re-postable, so the stored paths stay as they are.
        $banner = array_merge($banner, banner_form_input());
    }

    return $banner;
}

/**
 * Validate and write a banner.
 *
 * @param int|null $id null to insert, otherwise the row to update
 * @return array{ok:bool,id:int,errors:array}
 */
function banner_form_save(?int $id): array
{
    $input = banner_form_input();

    $current = $id !== null
        ? Database::fetch('SELECT `desktop_image`, `mobile_image` FROM `banners` WHERE `id` = :id', ['id' => $id])
        : ['desktop_image' => null, 'mobile_image' => null];

    if ($current === null) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => ['title' => 'That banner no longer exists.']];
    }

    $start = marketing_dt_save($input['start_date']);
    $end   = marketing_dt_save($input['end_date']);

    $v = new Validator($input, [
        'title'        => 'Title',
        'title_accent' => 'Accent line',
        'button_url'   => 'Button link',
        'button2_url'  => 'Second button link',
        'bg_color'     => 'Background colour',
        'text_color'   => 'Text colour',
        'sort_order'   => 'Sort order',
    ]);

    $v->in('position', array_keys(banner_positions()))
      ->max('title', 200)->max('title_accent', 200)->max('subtitle', 255)->max('badge', 100)
      ->max('button_text', 60)->max('button_url', 255)
      ->max('button2_text', 60)->max('button2_url', 255)
      ->max('bg_color', 20)->max('text_color', 20)
      ->integer('sort_order')->between('sort_order', 0, 9999)
      ->in('status', [STATUS_ACTIVE, STATUS_INACTIVE])
      ->rule('start_date', $input['start_date'] === '' || $start !== null, 'Enter a valid start date.')
      ->rule('end_date', $input['end_date'] === '' || $end !== null, 'Enter a valid end date.')
      ->rule('end_date', $start === null || $end === null || $end > $start,
          'The end date must come after the start date.')
      ->rule('bg_color', $input['bg_color'] === '' || banner_is_color($input['bg_color']),
          'Use a hex colour such as #0F2143.')
      ->rule('text_color', $input['text_color'] === '' || banner_is_color($input['text_color']),
          'Use a hex colour such as #FFFFFF.');

    // A banner with neither words nor artwork renders as an empty box.
    $hasImage = !empty($current['desktop_image']) || !empty($current['mobile_image'])
        || !empty($_FILES['desktop_image']['name']) || !empty($_FILES['mobile_image']['name']);
    $v->rule('title', $input['title'] !== '' || $hasImage,
        'Give the banner a title, an image, or both — it cannot be empty.');

    // A button with a label but nowhere to go is a dead control on the storefront.
    $v->rule('button_url', $input['button_text'] === '' || $input['button_url'] !== '',
        'A button with a label needs a link.');
    $v->rule('button2_url', $input['button2_text'] === '' || $input['button2_url'] !== '',
        'The second button needs a link too.');

    if ($v->fails()) {
        return ['ok' => false, 'id' => (int) $id, 'errors' => $v->errors()];
    }

    // Images only move once the rest of the form is known good.
    $desktop = $current['desktop_image'];
    if ((string) input('remove_desktop_image', '0') === '1' && empty($_FILES['desktop_image']['name'])) {
        delete_upload($desktop);
        $desktop = null;
    }
    $desktop = admin_handle_image('desktop_image', 'banners', $desktop);

    $mobile = $current['mobile_image'];
    if ((string) input('remove_mobile_image', '0') === '1' && empty($_FILES['mobile_image']['name'])) {
        delete_upload($mobile);
        $mobile = null;
    }
    $mobile = admin_handle_image('mobile_image', 'banners', $mobile);

    $blankToNull = static fn (string $value): ?string => $value !== '' ? $value : null;

    $payload = [
        'position'      => $input['position'],
        'title'         => $blankToNull($input['title']),
        'title_accent'  => $blankToNull($input['title_accent']),
        'subtitle'      => $blankToNull($input['subtitle']),
        'description'   => $blankToNull($input['description']),
        'badge'         => $blankToNull($input['badge']),
        'desktop_image' => $desktop,
        'mobile_image'  => $mobile,
        'button_text'   => $blankToNull($input['button_text']),
        'button_url'    => $blankToNull($input['button_url']),
        'button2_text'  => $blankToNull($input['button2_text']),
        'button2_url'   => $blankToNull($input['button2_url']),
        'bg_color'      => $blankToNull($input['bg_color']),
        'text_color'    => $blankToNull($input['text_color']),
        'sort_order'    => (int) $input['sort_order'],
        'start_date'    => $start,
        'end_date'      => $end,
        'status'        => $input['status'],
    ];

    if ($id === null) {
        $bannerId = Database::insert('banners', $payload);
    } else {
        Database::update('banners', $payload, '`id` = :id', ['id' => $id]);
        $bannerId = $id;
    }

    $label = $payload['title'] ?? ('#' . $bannerId);
    log_activity(
        $id === null ? 'banner.created' : 'banner.updated',
        'banner',
        $bannerId,
        ($id === null ? 'Created' : 'Updated') . ' ' . $payload['position'] . ' banner "' . $label . '"'
    );
    admin_after_write();

    return ['ok' => true, 'id' => $bannerId, 'errors' => []];
}
