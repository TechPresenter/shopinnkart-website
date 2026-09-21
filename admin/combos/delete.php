<?php
/**
 * ShopInnKart Admin - Delete a combo.
 *
 * The foreign keys cascade, so combo_items and combo_images go with the row
 * (2026_09_21_combo_offers.php:141, :164). The FILES those rows named do not:
 * `combos`.`image`, `banner` and `og_image`, plus every `combo_images`.`image`,
 * are uploads this combo owns and nothing else references. Every other module
 * here unlinks its own on delete (banners:28-29, categories:43-44,
 * products:50-65), so this one does too rather than leaking them into
 * /uploads forever. The gallery paths have to be read BEFORE the delete,
 * because the cascade takes the rows that hold them.
 *
 * `cart_items`.`combo_id` is a plain nullable column with no foreign key
 * (migration lines 174-186) - `product_id` and `variant_id` beside it both
 * cascade - so nothing clears the tag unless this file does. It is cleared
 * below, because leaving it is not merely untidy: MariaDB recomputes
 * AUTO_INCREMENT as MAX(id)+1 at startup, so a combo created later can be
 * handed this same id and cart_combo_groups() would then price these stale
 * lines as an instance of a set they were never part of. The count is read
 * first and written into the activity log the way coupons/delete.php records
 * lost redemptions - it is the collateral an operator needs to see afterwards.
 * Order history is not read back through `combos` at all: order_items carries
 * its own `combo_name` column for exactly this reason.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require_action('coupons.delete');

$id    = input_int('id');
$combo = $id > 0
    ? Database::fetch(
        'SELECT `id`, `name`, `image`, `banner`, `og_image` FROM `combos` WHERE `id` = :id',
        ['id' => $id]
    )
    : null;

if ($combo === null) {
    flash('error', 'That combo no longer exists.');
    redirect(admin_url('combos/'));
}

// Every file this combo owns, collected while the rows that name them exist.
$files = [$combo['image'], $combo['banner'], $combo['og_image']];
$files = array_merge($files, Database::fetchColumnAll(
    'SELECT `image` FROM `combo_images` WHERE `combo_id` = :id',
    ['id' => $id]
));

$cartLines = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM `cart_items` WHERE `combo_id` = :id',
    ['id' => $id]
);

// combo_items and combo_images cascade from combos, so one delete is enough.
Database::delete('combos', '`id` = :id', ['id' => $id]);

// The one child the cascade cannot reach - see the header note on the id a
// later combo may inherit. The component lines stay; they stop being a set.
Database::update(
    'cart_items',
    ['combo_id' => null, 'combo_group' => null],
    '`combo_id` = :id',
    ['id' => $id]
);

foreach ($files as $file) {
    delete_upload($file === null ? null : (string) $file);
}

log_activity(
    'combo.deleted',
    'combo',
    $id,
    'Deleted combo "' . $combo['name'] . '"'
        . ($cartLines > 0 ? ' with ' . $cartLines . ' basket line(s) still tagged to it' : '')
);
admin_after_write();

flash('success', 'Combo "' . $combo['name'] . '" deleted.');
redirect(admin_url('combos/'));
