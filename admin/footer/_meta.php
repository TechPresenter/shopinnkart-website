<?php
/**
 * ShopInnKart Admin - Footer column type metadata.
 *
 * One description of every column type, shared by the builder screen and by
 * column-save.php. Before this file the two carried separate lists - the
 * builder's selector and the saver's `$types` allowlist - which is how the
 * enum ended up with a value ('payment') the selector never offered.
 *
 * `content` and `links` say which inputs the storefront actually reads for a
 * type. The builder disables the ones a type ignores rather than leaving them
 * editable-but-discarded, and column-save.php uses the same answer to keep a
 * stored value instead of blanking it when its field was disabled.
 *
 * The renderer these describe is the column loop in includes/footer.php.
 * Changing one without the other is what this file exists to prevent.
 */

declare(strict_types=1);

// Include-only: the parent page already ran authentication and permissions.
// The .htaccess rule refuses /_*.php outright; this is the backstop for a
// host that does not read .htaccess at all.
if (!defined('SIK_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * type => label, help, and which inputs the storefront reads.
 *
 * @return array<string, array{label:string, help:string, content:bool, links:bool}>
 */
function footer_column_types(): array
{
    return [
        'links' => [
            'label'   => 'Link list',
            'help'    => 'Draws the links below, in their sort order.',
            'content' => false,
            'links'   => true,
        ],
        'about' => [
            'label'   => 'About text',
            'help'    => 'Draws the Content field as a paragraph in the footer\'s about style. '
                . 'Use it for a short blurb; use Custom HTML when you need real markup.',
            'content' => true,
            'links'   => false,
        ],
        'contact' => [
            'label'   => 'Contact details',
            'help'    => 'Draws the store email, phone, address and business hours from '
                . 'Settings → Store. Content and links are ignored.',
            'content' => false,
            'links'   => false,
        ],
        'payment' => [
            'label'   => 'Payment methods',
            'help'    => 'Draws a logo row of the payment methods checkout can actually offer — '
                . 'active in Settings → Payment and backed by a gateway. Content and links are ignored.',
            'content' => false,
            'links'   => false,
        ],
        'html' => [
            'label'   => 'Custom HTML',
            'help'    => 'Draws the Content field as sanitised HTML.',
            'content' => true,
            'links'   => false,
        ],
    ];
}

/** type => label, for a <select>. */
function footer_column_type_options(): array
{
    return array_map(static fn (array $meta): string => $meta['label'], footer_column_types());
}

/** The sentence under the type selector. */
function footer_column_type_help(string $type): string
{
    return (string) (footer_column_types()[$type]['help'] ?? '');
}

/** True when includes/footer.php reads footer_columns.content for this type. */
function footer_type_reads_content(string $type): bool
{
    return (bool) (footer_column_types()[$type]['content'] ?? false);
}

/** True when includes/footer.php draws this column's footer_links rows. */
function footer_type_reads_links(string $type): bool
{
    return (bool) (footer_column_types()[$type]['links'] ?? false);
}
