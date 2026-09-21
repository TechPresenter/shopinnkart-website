<?php
/**
 * ShopInnKart - Storefront font stacks.
 *
 * ONE list of the families Admin > Settings > Theme offers, shared by the
 * screen that offers them and the header that applies them.
 *
 * It lives here rather than in admin/settings/theme.php because that file is
 * admin-only: while the list stayed there the storefront had no way to read it,
 * so `font_family` was a select an operator could change, and preview inside
 * the admin, that never reached a single customer-facing page - app.css's own
 * `--sik-font` won on every request. Moving the list is what lets
 * includes/header.php print the chosen stack into the same :root block the rest
 * of the theme tokens already use.
 *
 * No webfont is fetched: every stack ends in a system family, so a visitor who
 * does not have the first name falls through to one they do. That is also why
 * this is an allowlist - the value reaches a <style> block, and only these
 * fixed strings can ever be printed.
 */

declare(strict_types=1);

/** Family name (as stored in `font_family`) => the CSS stack it renders as. */
function theme_font_stacks(): array
{
    // Only "Plus Jakarta Sans" is actually SHIPPED — app.css self-hosts it as a
    // variable woff2 (section 0). The rest resolve to whatever the visitor
    // happens to have installed, which for Inter, Roboto and Poppins is usually
    // nothing: this list has always offered three webfonts it never loaded, so
    // choosing one silently rendered the system stack instead. They are kept so
    // an existing stored value still resolves, and each now says so in the
    // admin label rather than pretending to be available.
    return [
        'Plus Jakarta Sans' => '"Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        'System'            => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        'Inter'             => '"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        'Segoe UI'          => '"Segoe UI", ui-sans-serif, system-ui, Roboto, Arial, sans-serif',
        'Roboto'            => '"Roboto", ui-sans-serif, system-ui, Arial, sans-serif',
        'Poppins'           => '"Poppins", ui-sans-serif, system-ui, Arial, sans-serif',
        'Georgia'           => 'Georgia, "Times New Roman", serif',
    ];
}

/**
 * Families the store actually ships a font file for.
 *
 * Everything else in theme_font_stacks() depends on the visitor already having
 * the family installed. The admin form uses this to mark the difference.
 */
function theme_font_bundled(): array
{
    return ['Plus Jakarta Sans'];
}

/**
 * The stack for a stored family name.
 *
 * An unknown name - a hand-edited row, a family retired from the list - falls
 * back to the shipped stack rather than being printed, so nothing outside the
 * allowlist above can reach the page.
 */
function theme_font_stack(?string $name): string
{
    // Falls back to the family the store actually ships, not to Inter — Inter
    // has no font file here, so an unknown or retired stored value used to
    // resolve to a stack that renders as the system font.
    $stacks = theme_font_stacks();
    return $stacks[(string) $name] ?? $stacks['Plus Jakarta Sans'];
}
