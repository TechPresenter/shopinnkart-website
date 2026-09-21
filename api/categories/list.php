<?php
/**
 * ShopInnKart - Category tree endpoint.
 *
 * Powers the mega menu, the shop sidebar and any scripted category picker.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/init.php';

api_require_method(['GET']);

/** Shape one category row for the API, recursing into its children. */
function category_payload(array $category, bool $withChildren = true): array
{
    $payload = [
        'id'            => (int) $category['id'],
        'parent_id'     => $category['parent_id'] === null ? null : (int) $category['parent_id'],
        'name'          => (string) $category['name'],
        'slug'          => (string) $category['slug'],
        'url'           => category_url((string) $category['slug']),
        'image_url'     => img_url($category['image']),
        'icon'          => $category['icon'] !== null ? (string) $category['icon'] : null,
        'is_featured'   => (int) $category['is_featured'] === 1,
        'product_count' => (int) ($category['product_count'] ?? 0),
    ];

    if ($withChildren) {
        $payload['children'] = array_map(
            static fn (array $child): array => category_payload($child, false),
            $category['children'] ?? []
        );
    }

    return $payload;
}

$all = all_categories();

if (input_bool('featured')) {
    $items = array_map(
        static fn (array $category): array => category_payload($category, false),
        featured_categories(max(1, min(50, input_int('limit', 12))))
    );
} elseif (input('parent_id', null) !== null) {
    // parent_id=0 asks for the root level.
    $parentId = input_int('parent_id');
    $children = array_filter($all, static function (array $category) use ($parentId): bool {
        return (int) ($category['parent_id'] ?? 0) === $parentId;
    });
    $items = array_map(static fn (array $category): array => category_payload($category, false), array_values($children));
} else {
    $items = array_map(static fn (array $category): array => category_payload($category), category_tree());
}

json_success('OK', [
    'categories' => $items,
    'total'      => count($items),
]);
