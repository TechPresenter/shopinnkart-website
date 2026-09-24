<?php
/**
 * ShopInnKart - Inline SVG icon set.
 *
 * Original 24x24 stroke geometry drawn with currentColor so icons inherit
 * text colour. Inlining avoids an icon-font or sprite HTTP request.
 *
 * Usage:  echo icon('cart', 'w-5 h-5');
 */

declare(strict_types=1);

/** Raw path bodies, keyed by icon name. */
function icon_paths(): array
{
    static $paths = null;
    if ($paths !== null) {
        return $paths;
    }

    $paths = [
        // --- interface -----------------------------------------------------
        'search'        => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        'heart'         => '<path d="M12 20.3l-7.1-7a4.5 4.5 0 016.4-6.3l.7.7.7-.7a4.5 4.5 0 016.4 6.3z"/>',
        'cart'          => '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2 3h2.2l2.4 11.4a2 2 0 002 1.6h8.6a2 2 0 002-1.6L21 7H6"/>',
        'bag'           => '<path d="M5 8h14l-1.2 12.2a1.5 1.5 0 01-1.5 1.3H7.7a1.5 1.5 0 01-1.5-1.3z"/><path d="M9 8V6a3 3 0 016 0v2"/>',
        'compare'       => '<path d="M4 7h7M4 17h7M20 7h-7M20 17h-7"/><path d="M8 4l-4 3 4 3M16 14l4 3-4 3"/>',
        'menu'          => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close'         => '<path d="M6 6l12 12M18 6L6 18"/>',
        'plus'          => '<path d="M12 5v14M5 12h14"/>',
        'minus'         => '<path d="M5 12h14"/>',
        'check'         => '<path d="M4 12.5l5 5L20 6.5"/>',
        'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'chevron-down'  => '<path d="M6 9l6 6 6-6"/>',
        'chevron-up'    => '<path d="M6 15l6-6 6 6"/>',
        'chevron-left'  => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'arrow-right'   => '<path d="M4 12h15"/><path d="M13 6l6 6-6 6"/>',
        'arrow-left'    => '<path d="M20 12H5"/><path d="M11 6l-6 6 6 6"/>',
        'arrow-up'      => '<path d="M12 20V5"/><path d="M6 11l6-6 6 6"/>',
        'external'      => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/>',
        'filter'        => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'sort'          => '<path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/>',
        'grid'          => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'list'          => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'eye'           => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'trash'         => '<path d="M4 7h16"/><path d="M9 7V5.5A1.5 1.5 0 0110.5 4h3A1.5 1.5 0 0115 5.5V7"/><path d="M6 7l1 12.5a1.5 1.5 0 001.5 1.5h7a1.5 1.5 0 001.5-1.5L18 7"/><path d="M10 11v6M14 11v6"/>',
        'edit'          => '<path d="M4 20h4.5L20 8.5a2.1 2.1 0 00-3-3L5.5 17z"/><path d="M14.5 5.5l3 3"/>',
        'copy'          => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a1 1 0 01-1-1V4a1 1 0 011-1h10a1 1 0 011 1v1"/>',
        'download'      => '<path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/>',
        'upload'        => '<path d="M12 20V9"/><path d="M8 13l4-4 4 4"/><path d="M4 5h16"/>',
        'refresh'       => '<path d="M20 12a8 8 0 11-2.5-5.8"/><path d="M20 4v4.5h-4.5"/>',
        'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 14.5a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 01-2.8 2.8l-.1-.1a1.6 1.6 0 00-1.8-.3 1.6 1.6 0 00-1 1.5v.2a2 2 0 01-4 0v-.1a1.6 1.6 0 00-1-1.5 1.6 1.6 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00.3-1.8 1.6 1.6 0 00-1.5-1H3a2 2 0 010-4h.1a1.6 1.6 0 001.5-1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 001.8.3H9a1.6 1.6 0 001-1.5V3a2 2 0 014 0v.1a1.6 1.6 0 001 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8V9a1.6 1.6 0 001.5 1H21a2 2 0 010 4h-.1a1.6 1.6 0 00-1.5 1z"/>',
        'logout'        => '<path d="M9 20H5a1 1 0 01-1-1V5a1 1 0 011-1h4"/><path d="M15 16l4-4-4-4"/><path d="M19 12H9"/>',
        'bell'          => '<path d="M18 8a6 6 0 10-12 0c0 6-2 7-2 7h16s-2-1-2-7"/><path d="M10.5 20a1.8 1.8 0 003 0"/>',
        'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'alert'         => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
        'star'          => '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.7l5.9-.8z"/>',
        'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/>',
        'calendar'      => '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'dots'          => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'sun'           => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6L6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4L6 18M18 6l1.4-1.4"/>',
        'moon'          => '<path d="M20.2 14.6A8.3 8.3 0 019.4 3.8a8.3 8.3 0 1010.8 10.8z"/>',

        // --- commerce ------------------------------------------------------
        'truck'         => '<path d="M2 7.5A1.5 1.5 0 013.5 6H14v9H2z"/><path d="M14 9.5h3.6a2 2 0 011.7 1l1.7 2.8V15h-7z"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'tag'           => '<path d="M11.2 3H20a1 1 0 011 1v8.8a1 1 0 01-.3.7l-7.6 7.6a1.5 1.5 0 01-2.1 0l-7.4-7.4a1.5 1.5 0 010-2.1l7.9-7.3a1 1 0 01.7-.3z"/><circle cx="16.5" cy="7.5" r="1.4"/>',
        'percent'       => '<path d="M19 5L5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
        'gift'          => '<rect x="3" y="9" width="18" height="12" rx="1.5"/><path d="M3 13h18M12 9v12"/><path d="M12 9S9.5 3.5 7 5.2 9.4 9 12 9zM12 9s2.5-5.5 5-3.8S14.6 9 12 9z"/>',
        'shield'        => '<path d="M12 3l7.5 3v6c0 4.6-3.1 8.1-7.5 9.5C7.6 20.1 4.5 16.6 4.5 12V6z"/><path d="M9 12l2 2 4-4.2"/>',
        /* A quality/authenticity seal. `badge` is the plain seal (Premium
           Quality, the Brands menu entry); `verified` is the same seal with the
           tick, which is what "100% Genuine" means. The scalloped edge is what
           separates `verified` from the plain circle of `check-circle`. */
        'badge'         => '<path d="M12 2.6l2.2 1.5 2.6-.4 1.1 2.4 2.4 1.1-.4 2.6 1.5 2.2-1.5 2.2.4 2.6-2.4 1.1-1.1 2.4-2.6-.4L12 21.4l-2.2-1.5-2.6.4-1.1-2.4-2.4-1.1.4-2.6L2.6 12l1.5-2.2-.4-2.6 2.4-1.1 1.1-2.4 2.6.4z"/>',
        'verified'      => '<path d="M12 2.6l2.2 1.5 2.6-.4 1.1 2.4 2.4 1.1-.4 2.6 1.5 2.2-1.5 2.2.4 2.6-2.4 1.1-1.1 2.4-2.6-.4L12 21.4l-2.2-1.5-2.6.4-1.1-2.4-2.4-1.1.4-2.6L2.6 12l1.5-2.2-.4-2.6 2.4-1.1 1.1-2.4 2.6.4z"/><path d="M8.6 12.2l2.4 2.4 4.4-4.8"/>',
        /* A lidded carton, deliberately not the isometric cube of `package`:
           this one counts catalogue entries ("Products Listed"), it is not a
           shipment. */
        'box'           => '<rect x="2.4" y="4" width="19.2" height="4.4" rx="1"/><path d="M4 8.4h16v11a1.6 1.6 0 01-1.6 1.6H5.6A1.6 1.6 0 014 19.4z"/><path d="M10 12.4h4"/>',
        'lock'          => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.8a4 4 0 018 0v2.7"/>',
        'credit-card'   => '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
        'wallet'        => '<path d="M3 7.5A1.5 1.5 0 014.5 6h13A1.5 1.5 0 0119 7.5V9"/><rect x="3" y="7.5" width="18" height="12.5" rx="2"/><circle cx="16.5" cy="14" r="1.3"/>',
        'package'       => '<path d="M12 3l8.5 4.5v9L12 21l-8.5-4.5v-9z"/><path d="M3.5 7.5L12 12l8.5-4.5M12 12v9"/>',
        'headset'       => '<path d="M4 14v-2a8 8 0 0116 0v2"/><rect x="2.5" y="13.5" width="4.5" height="6.5" rx="2"/><rect x="17" y="13.5" width="4.5" height="6.5" rx="2"/><path d="M19 20v.5a2.5 2.5 0 01-2.5 2.5H13"/>',
        'award'         => '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7 21l5-2.5L17 21l-1.5-7.5"/>',
        'users'         => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/><path d="M16 5.2a3.5 3.5 0 010 6.6M17.5 14.8c2.4.6 4 2.4 4 5.2"/>',
        'chart'         => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'trending'      => '<path d="M3 17l6-6 4 4 7-7"/><path d="M15 8h5v5"/>',
        'zap'           => '<path d="M13 2L4 14h6l-1 8 9-12h-6z"/>',
        'fire'          => '<path d="M12 22c3.9 0 7-2.9 7-6.6 0-4.6-4.4-6.2-4-11.4-2.8 1-5 4-5 6.6 0 1.3-.9 2-1.7 1.3C7.4 11.1 7 9.9 7 8.6 5.7 10 5 12.2 5 15.4 5 19.1 8.1 22 12 22z"/>',
        'location'      => '<path d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
        'phone'         => '<path d="M6 3h3l1.6 4.2-2 1.4a12 12 0 005.8 5.8l1.4-2L20 15v3a2 2 0 01-2.2 2A16.5 16.5 0 014 6.2 2 2 0 016 4z"/>',
        'mail'          => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M3 6.5l9 6 9-6"/>',
        'home'          => '<path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>',
        'store'         => '<path d="M4 4h16l1.5 5a3 3 0 01-5.8 1 3 3 0 01-5.7 0 3 3 0 01-5.8-1z"/><path d="M5 11v9h14v-9"/><path d="M10 20v-5h4v5"/>',

        // --- device categories ---------------------------------------------
        'smartphone'    => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 5.5h3"/><path d="M10.5 18.5h3"/>',
        'laptop'        => '<rect x="4" y="5" width="16" height="10.5" rx="1.5"/><path d="M2 18.5h20"/>',
        'tablet'        => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
        'tv'            => '<rect x="2.5" y="4" width="19" height="12.5" rx="2"/><path d="M8 20h8M12 16.5V20"/>',
        'headphone'     => '<path d="M4 14v-2.5a8 8 0 0116 0V14"/><rect x="2.5" y="13" width="4.5" height="7" rx="2"/><rect x="17" y="13" width="4.5" height="7" rx="2"/>',
        'earbuds'       => '<path d="M8 4a3.5 3.5 0 013.5 3.5V15A3.5 3.5 0 118 11.5z"/><path d="M16 4a3.5 3.5 0 00-3.5 3.5V15A3.5 3.5 0 1016 11.5z"/>',
        'speaker'       => '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><circle cx="12" cy="15" r="3.5"/><circle cx="12" cy="6.5" r="1.3"/>',
        'soundbar'      => '<rect x="2" y="9" width="20" height="6" rx="2"/><circle cx="7" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="17" cy="12" r="1.6"/>',
        'watch'         => '<circle cx="12" cy="12" r="5.5"/><path d="M9 6.5L9.5 2.5h5L15 6.5M9 17.5l.5 4h5l.5-4"/><path d="M12 9.5V12l1.8 1.2"/>',
        'gamepad'       => '<path d="M7.5 8h9a5 5 0 014.9 5.9l-.5 2.6A2.6 2.6 0 0116.5 18l-1.7-2h-5.6L7.5 18a2.6 2.6 0 01-4.4-1.5l-.5-2.6A5 5 0 017.5 8z"/><path d="M7.5 11.5v2.5M6.2 12.7h2.5"/><circle cx="16" cy="12" r="1"/><circle cx="18" cy="14" r="1"/>',
        'camera'        => '<path d="M3 8.5A1.5 1.5 0 014.5 7h2.8l1.4-2.2h6.6L16.7 7h2.8A1.5 1.5 0 0121 8.5v9A1.5 1.5 0 0119.5 19h-15A1.5 1.5 0 013 17.5z"/><circle cx="12" cy="13" r="3.6"/>',
        'monitor'       => '<rect x="2.5" y="4" width="19" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'keyboard'      => '<rect x="2" y="6.5" width="20" height="11" rx="2"/><path d="M6 10h.01M9.5 10h.01M13 10h.01M16.5 10h.01M6 13.5h.01M9.5 13.5h5M18 13.5h.01"/>',
        'mouse'         => '<rect x="7" y="2.5" width="10" height="19" rx="5"/><path d="M12 6.5v3.5"/>',
        'router'        => '<rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 16.5h.01M10 16.5h.01"/><path d="M12 10.5V8M8.8 8.8a4.5 4.5 0 016.4 0M6.3 6.3a8 8 0 0111.4 0"/>',
        'ssd'           => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h6M7 14h4"/><circle cx="17.5" cy="14" r="1.3"/>',
        'battery'       => '<rect x="2.5" y="7" width="16" height="10" rx="2"/><path d="M21.5 10.5v3"/><path d="M6 10.5v3M9.5 10.5v3M13 10.5v3"/>',
        'printer'       => '<path d="M7 8V3.5h10V8"/><rect x="3" y="8" width="18" height="8" rx="2"/><path d="M7 13h10v7.5H7z"/>',
        'charger'       => '<path d="M9 3v5M15 3v5"/><rect x="6" y="8" width="12" height="7" rx="2"/><path d="M12 15v3.5a2.5 2.5 0 002.5 2.5H16"/>',
        'cpu'           => '<rect x="6.5" y="6.5" width="11" height="11" rx="2"/><rect x="10" y="10" width="4" height="4" rx="1"/><path d="M9.5 3v3.5M14.5 3v3.5M9.5 17.5V21M14.5 17.5V21M3 9.5h3.5M3 14.5h3.5M17.5 9.5H21M17.5 14.5H21"/>',

        // --- voice, locale and account -------------------------------------
        'mic'           => '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0013 0"/><path d="M12 17.5V21M8.5 21h7"/>',
        'mic-off'       => '<path d="M9 5.5A3 3 0 0115 5.5v3.2"/><path d="M15 12.4a3 3 0 01-6-.9V9"/><path d="M5.5 11a6.5 6.5 0 0010.4 5.2M18.5 11v.6"/><path d="M12 17.5V21M8.5 21h7"/><path d="M4 3l16 18"/>',
        'globe'         => '<circle cx="12" cy="12" r="9"/><path d="M3.5 9.5h17M3.5 14.5h17"/><path d="M12 3a15 15 0 010 18a15 15 0 010-18z"/>',
        'inbox'         => '<path d="M3.5 13.5h4l1.5 3h6l1.5-3h4"/><path d="M5.2 5.2h13.6l2.2 8.3v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4z"/>',
        'sliders'       => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2.2"/><circle cx="9" cy="17" r="2.2"/>',
        'sparkle'       => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
        'shield-check'  => '<path d="M12 3l7.5 3v6c0 4.6-3.1 8.1-7.5 9.5C7.6 20.1 4.5 16.6 4.5 12V6z"/><path d="M8.8 12l2.2 2.2 4.2-4.4"/>',
        'rotate'        => '<path d="M3.5 12a8.5 8.5 0 1114.9 5.6"/><path d="M18.5 12.5v5h-5"/>',
        /* A document with body copy — the invoice link on orders. `file-text`
           was being asked for by three templates and silently drew `info`. */
        'file-text'     => '<path d="M13.8 3H7a1.6 1.6 0 00-1.6 1.6v14.8A1.6 1.6 0 007 21h10a1.6 1.6 0 001.6-1.6V7.8z"/><path d="M13.8 3v4.8h4.8"/><path d="M8.6 13h6.8M8.6 16.4h6.8M8.6 9.6h2.4"/>',

        // --- social --------------------------------------------------------
        'facebook'      => '<path d="M14.5 8.5V6.8c0-.8.5-1 .9-1H17V3h-2.4c-2.6 0-3.2 1.9-3.2 3.2v2.3H9.5V11h1.9v10h3.1V11h2.2l.3-2.5z"/>',
        'instagram'     => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="3.8"/><circle cx="17.2" cy="6.8" r="1"/>',
        'twitter'       => '<path d="M4 4l6.8 9.1L4.4 20h1.9l5.4-5.8L16 20h4l-7.1-9.5L18.9 4H17l-4.9 5.3L8.1 4z"/>',
        'youtube'       => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10.5 9.5l5 2.5-5 2.5z"/>',
        'linkedin'      => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M8 10.5V17M8 7.5v.01M12 17v-3.6c0-1.1.8-1.9 1.8-1.9s1.7.8 1.7 1.9V17"/><path d="M12 10.5V17"/>',
        'pinterest'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5c-2.2 0-3.6 1.4-3.6 3.1 0 .8.4 1.7 1 2l-.4 1.6"/><path d="M10.6 16.8c.4.1.9.2 1.4.2 2.5 0 4.4-2.2 4.4-5 0-2.3-1.9-4-4.4-4"/>',
        'whatsapp'      => '<path d="M3 21l1.4-4.6A8.4 8.4 0 1112 20.4a8.5 8.5 0 01-4.2-1.1z"/><path d="M9 9.2c0 2.6 2.3 4.9 4.9 4.9l1-1.2 1.6.8-.4 1.4c-2 .6-6.6-2-7.6-5.6l1.3-.7z"/>',

        // --- admin control centre (A1) ---------------------------------------
        // Drawn to the same rules as everything above: a 24x24 box, 1.8 stroke
        // applied by icon(), currentColor, and geometry that still reads at
        // 16px. These are the names the admin components in A2-A13 ask for; a
        // name that is not here falls back to `info`, which is how "Easy EMI"
        // once rendered an information circle.
        'grip'          => '<circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/>',
        'image'         => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M4 17l4.5-4.5 3 3 3.5-3.5L20 16"/>',
        'link'          => '<path d="M10 13.5a3.8 3.8 0 005.6.3l2.6-2.6a3.8 3.8 0 00-5.4-5.4l-1.5 1.5"/><path d="M14 10.5a3.8 3.8 0 00-5.6-.3l-2.6 2.6a3.8 3.8 0 005.4 5.4l1.5-1.5"/>',
        'message'       => '<path d="M20.5 12.5A7.5 7.5 0 0113 20H4.5l2-3.2A7.5 7.5 0 1120.5 12.5z"/>',
        'send'          => '<path d="M21 3L10.5 13.5"/><path d="M21 3l-6.8 18-3.7-7.5L3 9.8z"/>',
        'pie'           => '<path d="M12 3v9h9a9 9 0 10-9-9z"/><path d="M20.4 15.5A9 9 0 1112 3"/>',
        'activity'      => '<path d="M3 12h4l3 7 4-15 3 8h4"/>',
        'eye-off'       => '<path d="M4 4l16 16"/><path d="M9.9 5.7A9.7 9.7 0 0112 5.5c6.4 0 10 6.5 10 6.5a17 17 0 01-3.3 4.1"/><path d="M6.5 7.6A16.6 16.6 0 002 12s3.6 6.5 10 6.5a9.9 9.9 0 004.1-.9"/><path d="M9.8 10a2.8 2.8 0 003.9 3.9"/>',
        'help'          => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 114 2.3c-.9.6-1.6 1.1-1.6 2.3"/><path d="M12 17.2h.01"/>',
        'x-circle'      => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'code'          => '<path d="M9 7.5L4.5 12 9 16.5"/><path d="M15 7.5L19.5 12 15 16.5"/><path d="M13.4 4.5l-2.8 15"/>',
        'database'      => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
        'megaphone'     => '<path d="M4 10v4a2 2 0 002 2h1l1.4 4.2a1 1 0 001 .8h.8a1 1 0 001-1.2L10.4 16H11l8 4V4l-8 4H6a2 2 0 00-2 2z"/>',
        'maximize'      => '<path d="M9 4H4v5"/><path d="M15 4h5v5"/><path d="M15 20h5v-5"/><path d="M9 20H4v-5"/>',
        'minimize'      => '<path d="M4 9h5V4"/><path d="M20 9h-5V4"/><path d="M20 15h-5v5"/><path d="M4 15h5v5"/>',
        'play'          => '<path d="M7 4.7l12 7.3-12 7.3z"/>',
        'pause'         => '<path d="M9 5v14M15 5v14"/>',
        'undo'          => '<path d="M4 9h9.5a5.5 5.5 0 010 11H8"/><path d="M8 5L4 9l4 4"/>',
        'panel-left'    => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M9.5 4.5v15"/>',
        'command'       => '<path d="M8.5 5.5a2.5 2.5 0 110 5h7a2.5 2.5 0 110-5v13a2.5 2.5 0 11 0-5h-7a2.5 2.5 0 11 0 5z"/>',
        'target'        => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.8"/><circle cx="12" cy="12" r="1.3"/>',
        'layers'        => '<path d="M12 3l9 4.8-9 4.8-9-4.8z"/><path d="M3 12.5l9 4.8 9-4.8"/><path d="M3 17l9 4.8 9-4.8"/>',
        'bookmark'      => '<path d="M6 4h12v17l-6-4.2L6 21z"/>',
        'wifi-off'      => '<path d="M4 4l16 16"/><path d="M2.5 9a16 16 0 015.2-3.2"/><path d="M21.5 9a16 16 0 00-8.4-3.4"/><path d="M6 12.6a11 11 0 013-1.8"/><path d="M18 12.6a11 11 0 00-2.7-1.7"/><path d="M9.2 16a6 6 0 013-1.2"/><path d="M12 19.5h.01"/>',
        'more-vertical' => '<circle cx="12" cy="5.5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="18.5" r="1.4"/>',
        'columns'       => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M9.5 4.5v15M14.5 4.5v15"/>',
        'funnel'        => '<path d="M3.5 5h17l-6.6 7.6V20l-3.8-2v-5.4z"/>',
        'map'           => '<path d="M3 6.5l6-2.5 6 2.5 6-2.5v13l-6 2.5-6-2.5-6 2.5z"/><path d="M9 4v13M15 6.5v13"/>',
        'table'         => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M9.5 9.5v10"/>',
        'arrow-down'    => '<path d="M12 4v15"/><path d="M6 13l6 6 6-6"/>',
        'rows'          => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9.5h17M3.5 14.5h17"/>',
        'pin'           => '<path d="M12 21v-6"/><path d="M8.5 3h7l-1 5.3 2.8 2.6a1 1 0 01-.7 1.7H7.4a1 1 0 01-.7-1.7l2.8-2.6z"/>',
    ];

    // Aliases resolve here rather than being drawn twice, so `card` can never
    // drift away from `credit-card`. They exist because seeded rows in
    // trust_features / menu_items ask for these names; an unknown name falls
    // back to `info`, which is why "Easy EMI" and every "View All …" menu entry
    // were rendering an information circle.
    foreach (icon_aliases() as $alias => $canonical) {
        $paths[$alias] = $paths[$canonical];
    }

    return $paths;
}

/**
 * Alternate names that resolve onto a canonical glyph. Kept out of
 * icon_names() so the admin picker offers each drawing exactly once.
 *
 * @return array<string, string>
 */
function icon_aliases(): array
{
    return [
        'card'  => 'credit-card',
        'arrow' => 'arrow-right',
    ];
}

/**
 * Render an icon as inline SVG.
 *
 * @param string $name    Key from icon_paths()
 * @param string $class   CSS classes for the <svg>
 * @param bool   $filled  Fill with currentColor instead of stroking
 */
function icon(string $name, string $class = 'w-5 h-5', bool $filled = false): string
{
    $paths = icon_paths();
    $body = $paths[$name] ?? $paths['info'];

    // The stroke-width attribute is only the no-CSS fallback. `.sik-ico` in
    // app.css sets stroke-width from --icon-stroke and wins over a presentation
    // attribute, so the whole set has exactly one weight and retuning the token
    // retunes every glyph. The class also carries the icon-scale sizing rules.
    $presentation = $filled
        ? 'fill="currentColor" stroke="none"'
        : 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';

    $class = trim('sik-ico ' . trim($class));

    // Every glyph is decorative by default: an icon never carries the
    // accessible name, its control does (visible text, or aria-label when the
    // control is icon-only). That is why aria-hidden is unconditional here.
    return '<svg class="' . e_attr($class) . '" viewBox="0 0 24 24" ' . $presentation
        . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/**
 * Social brand marks.
 *
 * These are filled glyphs, not the stroked UI set above — a brand mark drawn
 * with a 1.7px stroke stops being recognisable at 16px. Used solely to label a
 * link to the store's own profile on that network, which is what every
 * retailer's footer does.
 *
 * Keys match the `social_*` settings, so adding a network is a settings row
 * plus one entry here.
 */
function social_icon_paths(): array
{
    return [
        'facebook'  => '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.5-3.91 3.77-3.91 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z"/>',
        'instagram' => '<path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06zm0 3.678a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm7.846-10.405a1.44 1.44 0 11-2.88 0 1.44 1.44 0 012.88 0z"/>',
        'twitter'   => '<path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.66l7.73-8.84L1.25 2.25h6.83l4.71 6.23zm-1.16 17.52h1.83L7.08 4.13H5.11z"/>',
        'youtube'   => '<path d="M23.5 6.19a3.02 3.02 0 00-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 00.5 6.19C0 8.08 0 12 0 12s0 3.92.5 5.81a3.02 3.02 0 002.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 002.12-2.14C24 15.92 24 12 24 12s0-3.92-.5-5.81zM9.55 15.57V8.43L15.82 12z"/>',
        'linkedin'  => '<path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05a3.74 3.74 0 013.37-1.85c3.6 0 4.27 2.37 4.27 5.46zM5.34 7.43a2.07 2.07 0 112.06-2.07 2.07 2.07 0 01-2.06 2.07zM7.12 20.45H3.55V9h3.57zM22.22 0H1.77A1.75 1.75 0 000 1.73v20.54A1.75 1.75 0 001.77 24h20.45A1.76 1.76 0 0024 22.27V1.73A1.76 1.76 0 0022.22 0z"/>',
        'pinterest' => '<path d="M12 0C5.37 0 0 5.37 0 12c0 5.08 3.16 9.43 7.63 11.18-.11-.95-.2-2.41.04-3.45.22-.94 1.4-5.96 1.4-5.96s-.36-.72-.36-1.78c0-1.67.97-2.91 2.17-2.91 1.02 0 1.52.77 1.52 1.69 0 1.03-.66 2.57-1 4-.28 1.2.6 2.18 1.79 2.18 2.15 0 3.8-2.27 3.8-5.54 0-2.9-2.08-4.92-5.05-4.92-3.44 0-5.46 2.58-5.46 5.25 0 1.04.4 2.15.9 2.76.1.12.11.22.08.34-.09.38-.3 1.2-.34 1.37-.05.22-.18.27-.41.16-1.53-.71-2.48-2.94-2.48-4.73 0-3.85 2.8-7.39 8.06-7.39 4.24 0 7.53 3.02 7.53 7.05 0 4.21-2.65 7.6-6.34 7.6-1.24 0-2.4-.64-2.8-1.4l-.76 2.9c-.28 1.06-1.02 2.39-1.52 3.2 1.15.36 2.36.55 3.63.55 6.63 0 12-5.37 12-12S18.63 0 12 0z"/>',
        'telegram'  => '<path d="M23.91 3.79 20.3 20.84c-.25 1.21-.98 1.5-1.96.94l-5.4-4-2.6 2.5c-.3.3-.54.54-1.1.54-.72 0-.6-.27-.84-.95L6.3 13.7l-5.45-1.7c-1.18-.35-1.19-1.16.26-1.75l21.26-8.2c.97-.43 1.9.24 1.53 1.73z"/>',
        'snapchat'  => '<path d="M12.206.793c.99 0 4.347.276 5.93 3.821.529 1.193.403 3.219.299 4.847l-.4.681c.017.076.093.283.32.35.283.083.68.11 1.127-.014.283-.077.566-.132.85-.132.425 0 .849.166 1.132.463.283.297.425.68.425 1.132 0 .566-.283 1.132-.849 1.415-.425.211-.99.354-1.415.495-.142.047-.283.094-.425.142-.283.094-.566.188-.566.472 0 .142.047.283.142.472.66 1.463 1.792 2.643 3.161 3.302.283.142.566.236.85.33.424.142.707.33.707.708 0 .283-.188.566-.566.802-.566.354-1.415.566-2.264.708-.142.024-.236.118-.283.33-.047.142-.094.33-.142.519-.94.377-.236.802-.708.802h-.094c-.236 0-.519-.047-.85-.094-.33-.047-.707-.094-1.132-.094-.283 0-.566.024-.85.071-.66.118-1.226.519-1.887.99-.943.66-2.005 1.415-3.585 1.415s-2.642-.755-3.585-1.415c-.66-.472-1.226-.873-1.887-.99a5.058 5.058 0 00-.85-.071c-.424 0-.801.047-1.132.094-.33.047-.613.094-.849.094h-.094c-.472 0-.613-.425-.708-.802-.047-.189-.094-.377-.141-.519-.047-.212-.142-.306-.283-.33-.85-.142-1.698-.354-2.264-.708-.378-.236-.566-.519-.566-.802 0-.377.283-.566.708-.708.283-.094.566-.188.849-.33 1.37-.66 2.5-1.84 3.161-3.302.094-.189.141-.33.141-.472 0-.284-.283-.378-.566-.472-.141-.048-.283-.095-.424-.142-.425-.141-.99-.284-1.415-.495-.566-.283-.85-.849-.85-1.415 0-.452.142-.835.425-1.132.283-.297.708-.463 1.132-.463.284 0 .567.055.85.132.447.124.844.097 1.127.014.227-.67.303-.274.32-.35l-.4-.681c-.104-1.628-.23-3.654.299-4.847C7.859 1.069 11.216.793 12.206.793z"/>',
        'whatsapp'  => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/>',
    ];
}

/**
 * Render a social brand mark. Filled, sized from the icon scale.
 */
function social_icon(string $network, string $class = 'w-5 h-5'): string
{
    $paths = social_icon_paths();
    if (!isset($paths[$network])) {
        return icon('external', $class);
    }

    // Brand marks are filled, so they take the icon-scale sizing from
    // `.sik-ico` but never the stroke — there is nothing to stroke.
    return '<svg class="' . e_attr(trim('sik-ico ' . trim($class))) . '" viewBox="0 0 24 24" fill="currentColor"'
        . ' aria-hidden="true" focusable="false">' . $paths[$network] . '</svg>';
}

/**
 * The store's configured social profiles, in display order.
 * A network with no URL in Admin > Settings > Social is omitted entirely
 * rather than rendered as a dead link.
 *
 * @return array<int, array{key:string, label:string, url:string}>
 */
function social_links(): array
{
    $networks = [
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'twitter'   => 'X',
        'youtube'   => 'YouTube',
        'linkedin'  => 'LinkedIn',
        'pinterest' => 'Pinterest',
        'whatsapp'  => 'WhatsApp',
        'telegram'  => 'Telegram',
        'snapchat'  => 'Snapchat',
    ];

    $links = [];
    foreach ($networks as $key => $label) {
        $url = trim((string) setting('social_' . $key, ''));

        // The store's WhatsApp number is already configured for the floating
        // button, so the social row builds its link from that rather than making
        // an operator type the same number into a second field as a wa.me URL.
        if ($key === 'whatsapp' && $url === '') {
            $number = preg_replace('/\D+/', '', (string) setting('store_whatsapp', ''));
            $url = $number !== '' ? 'https://wa.me/' . $number : '';
        }
        if ($url === '' || $url === '#') {
            continue;
        }
        $links[] = ['key' => $key, 'label' => $label, 'url' => $url];
    }
    return $links;
}

/** True when an icon key exists. */
function icon_exists(string $name): bool
{
    return array_key_exists($name, icon_paths());
}

/** All available icon keys — used by admin pickers. Aliases are excluded so
 *  the picker offers each drawing exactly once. */
function icon_names(): array
{
    return array_values(array_diff(array_keys(icon_paths()), array_keys(icon_aliases())));
}

/**
 * icon_names() split into the sections the set is actually drawn in.
 *
 * A flat grid of 90 glyphs is a wall — the admin picker needs headings to scan
 * and keywords to search, and neither can be inferred from the key alone
 * ("zap" is not findable by typing "fast"). The group lists below are the same
 * ones the `// --- ... ---` comments in icon_paths() mark out; anything added
 * to the set without being listed here still appears, under "Other", so a new
 * glyph can never go missing from the picker.
 *
 * @return array<string, array<int, string>> group heading => icon keys
 */
function icon_groups(): array
{
    $groups = [
        'Interface' => [
            'search', 'user', 'heart', 'cart', 'bag', 'compare', 'menu', 'close', 'plus', 'minus',
            'check', 'check-circle', 'chevron-down', 'chevron-up', 'chevron-left', 'chevron-right',
            'arrow-right', 'arrow-left', 'arrow-up', 'external', 'filter', 'sort', 'grid', 'list',
            'eye', 'trash', 'edit', 'copy', 'download', 'upload', 'refresh', 'settings', 'logout',
            'bell', 'info', 'alert', 'star', 'clock', 'calendar', 'dots',
        ],
        'Commerce' => [
            'truck', 'tag', 'percent', 'gift', 'shield', 'badge', 'verified', 'box', 'lock',
            'credit-card', 'wallet', 'package', 'headset', 'award', 'users', 'chart', 'trending',
            'zap', 'fire', 'location', 'phone', 'mail', 'home', 'store',
        ],
        'Devices' => [
            'smartphone', 'laptop', 'tablet', 'tv', 'headphone', 'earbuds', 'speaker', 'soundbar',
            'watch', 'gamepad', 'camera', 'monitor', 'keyboard', 'mouse', 'router', 'ssd',
            'battery', 'printer', 'charger', 'cpu',
        ],
        'Account & locale' => [
            'mic', 'mic-off', 'globe', 'inbox', 'sliders', 'sparkle', 'shield-check', 'rotate',
            'file-text',
        ],
        'Social' => ['facebook', 'instagram', 'twitter', 'youtube', 'linkedin', 'pinterest', 'whatsapp'],
    ];

    $known = [];
    foreach ($groups as $names) {
        foreach ($names as $name) {
            $known[$name] = true;
        }
    }

    $other = array_values(array_filter(icon_names(), static fn (string $n): bool => !isset($known[$n])));
    if ($other !== []) {
        $groups['Other'] = $other;
    }

    // A key listed above but since removed from the set would render icon()'s
    // fallback bubble in the picker, so every group is filtered against reality.
    foreach ($groups as $heading => $names) {
        $groups[$heading] = array_values(array_filter($names, 'icon_exists'));
        if ($groups[$heading] === []) {
            unset($groups[$heading]);
        }
    }

    return $groups;
}

/**
 * Extra words an icon should be findable by in the admin picker.
 *
 * Only where the key is not the word an operator would type. "phone" already
 * matches `smartphone` and `phone` on a plain substring test, so it is absent;
 * "discount" matching `percent` and `tag` is the whole point of this list.
 *
 * @return array<string, string> icon key => space-separated keywords
 */
function icon_keywords(): array
{
    return [
        'bag'          => 'shopping basket',
        'cart'         => 'basket trolley',
        'percent'      => 'discount offer sale off',
        'tag'          => 'discount price label deal',
        'fire'         => 'hot trending popular',
        'zap'          => 'fast flash lightning quick power',
        'sparkle'      => 'new shiny featured magic',
        'trending'     => 'growth popular rising chart',
        'gift'         => 'present hamper combo bundle',
        'truck'        => 'delivery shipping courier',
        'package'      => 'parcel shipment box order',
        'box'          => 'carton product inventory',
        'shield'       => 'warranty protection secure guarantee',
        'shield-check' => 'warranty protection secure guarantee',
        'verified'     => 'genuine authentic original seal',
        'badge'        => 'quality premium seal certified',
        'award'        => 'best seller top rated winner',
        'headset'      => 'support help service contact call',
        'credit-card'  => 'payment pay card emi',
        'wallet'       => 'payment money balance',
        'rotate'       => 'return exchange refund replace',
        'file-text'    => 'invoice document bill receipt page',
        'store'        => 'shop outlet retail',
        'home'         => 'house main start',
        'globe'        => 'language country international region',
        'sliders'      => 'filter settings options controls',
        'grid'         => 'category collection layout',
        'ssd'          => 'storage drive hard disk memory',
        'cpu'          => 'processor chip component',
        'charger'      => 'power adapter cable plug',
        'battery'      => 'power bank charge',
        'router'       => 'wifi network internet modem',
        'earbuds'      => 'tws wireless airpods audio',
        'headphone'    => 'audio music over ear',
        'soundbar'     => 'audio home theatre tv',
        'speaker'      => 'audio bluetooth music',
        'gamepad'      => 'gaming console controller playstation xbox',
        'watch'        => 'smartwatch wearable band fitness',
        'smartphone'   => 'mobile android iphone cell',
        'laptop'       => 'notebook computer macbook',
        'monitor'      => 'display screen',
        'tv'           => 'television screen smart',
        'camera'       => 'photography dslr lens',
        'mic'          => 'voice search microphone record',
        'mic-off'      => 'voice mute microphone',
        'users'        => 'customers people community team',
        'user'         => 'account profile customer sign in',
        'star'         => 'rating review favourite best',
        'heart'        => 'wishlist favourite save like',
        'compare'      => 'versus difference match',
        'bell'         => 'notification alert reminder',
        'inbox'        => 'messages mail orders',
        'clock'        => 'time schedule hours limited',
        'calendar'     => 'date schedule event',
        'location'     => 'address store pin map',
        'lock'         => 'secure privacy password safe',
        'chart'        => 'analytics stats report sales',
        'external'     => 'new tab link outbound',
        'list'         => 'menu lines items',
        'eye'          => 'view preview visible quick look',
    ];
}

/**
 * Does this stored icon value point at an uploaded file rather than a set key?
 *
 * The marker prefix is what keeps one column able to hold both. Without it a
 * path and a key are both just strings and icon_exists() would have to guess.
 */
function icon_is_upload(?string $value): bool
{
    return $value !== null && strncmp($value, 'upload:', 7) === 0;
}

/** The stored path behind an `upload:` icon value, or '' if it is not one. */
function icon_upload_path(?string $value): string
{
    if (!icon_is_upload($value)) {
        return '';
    }

    $path = ltrim(substr((string) $value, 7), '/');

    /**
     * The value came out of the database and is about to become an `src`, so it
     * is re-checked here rather than trusted. Only a file this application
     * wrote can be named: inside uploads/, no traversal, and an image
     * extension. A row hand-edited to `upload:../../config/config.php` gets ''.
     */
    if (strpos($path, '..') !== false || strncmp($path, 'uploads/', 8) !== 0) {
        return '';
    }
    if (preg_match('/\.(svg|png|webp|jpe?g|gif)$/i', $path) !== 1) {
        return '';
    }

    // A deleted file renders nothing, exactly like an unknown set key. Going
    // through img_url() instead would substitute the "no image" placeholder,
    // which is the same wrong answer the unknown-key bug used to give: a
    // stand-in glyph beside a label that was never meant to carry one.
    if (!is_file(ROOT_PATH . '/' . $path)) {
        return '';
    }

    return $path;
}

/**
 * Is this icon value renderable at all — a known set key or a usable upload?
 *
 * icon_exists() answers only the first half, and every caller that draws a menu
 * glyph needs both, so they ask this instead.
 */
function icon_value_renderable(?string $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }
    return icon_is_upload($value) ? icon_upload_path($value) !== '' : icon_exists($value);
}

/**
 * Render a menu glyph: an inline SVG for a set key, an <img> for an upload.
 *
 * An uploaded SVG is drawn through <img> on purpose. upload_image() already
 * rewrites the file from svg_sanitize()'s allowlist, but an <img> is a second,
 * structural guarantee: scripts, event handlers and external references are
 * inert inside an image context no matter what the file contains. Inlining the
 * same file would put its markup straight into the page's own DOM and throw
 * that guarantee away.
 *
 * Returns '' for anything unrenderable, so callers can test the string.
 */
function menu_glyph(?string $value, string $class = 'w-4 h-4'): string
{
    if (!icon_value_renderable($value)) {
        return '';
    }

    if (!icon_is_upload($value)) {
        return icon((string) $value, $class);
    }

    // currentColor cannot reach an <img>, so an uploaded glyph paints itself.
    // .sik-ico carries the sizing rules; stroke-width on an <img> is inert.
    return '<img class="' . e_attr(trim('sik-ico sik-ico--file ' . trim($class))) . '"'
        . ' src="' . e_attr(url(icon_upload_path($value))) . '" alt="" aria-hidden="true"'
        . ' loading="lazy" decoding="async">';
}
