<?php
/**
 * ShopInnKart - Logo and favicon.
 *
 * One place decides which logo file a surface gets, because there are three of
 * them and the choice depends on the background:
 *
 *   store_logo        the wordmark lockup, for light backgrounds
 *   store_logo_light  the same lockup lightened, for dark backgrounds - the
 *                     dark theme, a dark footer, the admin sidebar
 *   the mark alone    square, for the collapsed admin sidebar and the favicon
 *
 * The first two are Admin > Settings > General fields, so an operator can
 * replace either without touching a template. The files shipped here are
 * derived from the owner's own artwork; the uploaded originals are kept
 * alongside them in assets/images/logo/ and are never read at runtime.
 *
 * The SVG logos these defaults replace were deleted with the rebrand, so every
 * path below points at a file that exists.
 */

declare(strict_types=1);

const BRAND_LOGO       = 'assets/images/logo/shopinnkart-logo.png';
const BRAND_LOGO_LIGHT = 'assets/images/logo/shopinnkart-logo-light.png';
const BRAND_MARK       = 'assets/images/logo/shopinnkart-mark.png';
const BRAND_FAVICON    = 'assets/images/logo/favicon-192.png';
const BRAND_FAVICON_SM = 'assets/images/logo/favicon-32.png';
const BRAND_TOUCH_ICON = 'assets/images/logo/apple-touch-icon.png';

/** The logo for a light background, as a path relative to the project root. */
function brand_logo_rel(): string
{
    $logo = trim((string) setting('store_logo', ''));
    return $logo !== '' ? $logo : BRAND_LOGO;
}

/** The logo for a dark background, falling back to the light-background one. */
function brand_logo_light_rel(): string
{
    $light = trim((string) setting('store_logo_light', ''));
    return $light !== '' ? $light : brand_logo_rel();
}

function brand_logo_src(): string
{
    return img_url(brand_logo_rel());
}

function brand_logo_light_src(): string
{
    return img_url(brand_logo_light_rel());
}

/** The square mark, for places too small for the full lockup. */
function brand_mark_src(): string
{
    return img_url(BRAND_MARK);
}

/**
 * The real pixel size of a logo file.
 *
 * width/height on the tag reserve the right space before the image arrives, so
 * the header does not jump. They have to be the file's own dimensions: a wrong
 * ratio here is what squashes a logo an operator uploads at a different shape.
 * Measured once per file per request; anything unreadable (or remote) falls
 * back to the shipped lockup's shape.
 */
function brand_img_dims(string $relative): array
{
    static $cache = [];

    if (isset($cache[$relative])) {
        return $cache[$relative];
    }

    $dims = [434, 120];
    if (stripos($relative, 'http') !== 0) {
        $path = ROOT_PATH . '/' . ltrim(strtr($relative, DIRECTORY_SEPARATOR, '/'), '/');
        if (is_file($path) && ($size = @getimagesize($path)) && $size[0] > 0 && $size[1] > 0) {
            $dims = [(int) $size[0], (int) $size[1]];
        }
    }

    return $cache[$relative] = $dims;
}

function brand_logo_tag(string $relative, string $class, string $alt, bool $eager): string
{
    [$w, $h] = brand_img_dims($relative);
    $name    = $alt !== '' ? ' alt="' . e_attr($alt) . '"' : ' alt="" aria-hidden="true"';

    return '<img class="' . e_attr($class) . '" src="' . e(img_url($relative)) . '"' . $name
        . ' width="' . $w . '" height="' . $h . '" decoding="async"'
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
}

/**
 * The logo, ready to drop inside a link.
 *
 * On a surface that follows the theme both files are rendered and CSS shows the
 * right one, so switching to the dark theme needs no round trip. On a surface
 * that is dark in BOTH themes - a dark footer, the admin sidebar - pass $onDark
 * and only the light-background file is used.
 *
 * @param string $alt   accessible name; the store name when left empty
 * @param bool   $eager true for the header logo, which is always above the fold
 */
function brand_logo(string $alt = '', bool $onDark = false, bool $eager = false): string
{
    $alt = $alt !== '' ? $alt : (string) setting('store_name', SITE_NAME);

    if ($onDark) {
        return brand_logo_tag(brand_logo_light_rel(), 'sik-logo__img', $alt, $eager);
    }

    return brand_logo_tag(brand_logo_rel(), 'sik-logo__img sik-logo__light', $alt, $eager)
        . brand_logo_tag(brand_logo_light_rel(), 'sik-logo__img sik-logo__dark', '', $eager);
}

/**
 * The icon links for <head>.
 *
 * A 32px icon for the browser tab, 180px for an iOS home screen and the 192px
 * master for everything else. An uploaded favicon replaces the tab icon; the
 * larger two stay with the shipped mark unless those files are replaced.
 */
function brand_favicon_links(): string
{
    $custom = trim((string) setting('store_favicon', ''));
    $tab    = $custom !== '' ? $custom : BRAND_FAVICON_SM;

    return '<link rel="icon" href="' . e(img_url($tab)) . '" sizes="32x32">' . "\n"
        . '    <link rel="icon" href="' . e(img_url(BRAND_FAVICON)) . '" sizes="192x192">' . "\n"
        . '    <link rel="apple-touch-icon" href="' . e(img_url(BRAND_TOUCH_ICON)) . '">';
}
