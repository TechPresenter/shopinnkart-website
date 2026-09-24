<?php
/**
 * ShopInnKart - Analytics classifiers: bot test, UA parse, traffic source.
 *
 * WHY THIS FILE IS A LIFT, NOT A REWRITE
 * --------------------------------------
 * These three functions were written and tested as a prototype before the
 * collector existed (analytics-data/proto/an-classify.php, 49 cases). They are
 * copied here with their behaviour unchanged - the test table moved to
 * bin/test-analytics-classify.php and runs against THIS file, so the two can
 * never drift. If a case ever fails, it is this file that is wrong.
 *
 * Everything here is pure: no database, no settings, no superglobals. That is
 * what lets the collector call it before it has decided to write anything, and
 * what lets the test script run them 10,000 times in a loop.
 *
 * THE ENUMS
 * ---------
 * Device, browser, OS, channel, page type, event name and vitals metric are
 * stored as TINYINTs. The id -> label tables live here, in code, rather than in
 * the database, so renaming "Affiliates" to "Partners" is an edit, not a
 * migration. Ids are permanent: append to the end, never renumber, or every
 * historical rollup row silently changes meaning.
 */

declare(strict_types=1);

// ===========================================================================
//  Bots
//
//  One regex, checked twice: at render (no tracking config is printed for a
//  bot, so most crawlers never learn the collector exists) and again in the
//  collector itself (a crawler that does run JS, or a script replaying a real
//  page's token, still gets nothing).
// ===========================================================================

const AN_BOT_RE = '~bot\b|bot/|crawl|spider|slurp|mediapartners|adsbot|apis-google|google-inspectiontool'
    . '|googleother|storebot|feedfetcher|lighthouse|pagespeed|chrome-lighthouse|gtmetrix|pingdom|uptime|statuscake'
    . '|headlesschrome|phantomjs|puppeteer|playwright|selenium|webdriver|electron/|python-|python/|curl/|wget'
    . '|go-http-client|okhttp|axios/|node-fetch|java/|libwww|httpclient|guzzle|scrapy|facebookexternalhit'
    . '|facebookcatalog|meta-externalagent|whatsapp/|telegrambot|twitterbot|linkedinbot|slackbot|discordbot'
    . '|skypeuripreview|embedly|quora link preview|redditbot|applebot|yandex|baiduspider|petalbot|bytespider'
    . '|semrush|ahrefs|mj12|dotbot|dataforseo|serpstat|seznam|sogou|exabot|ccbot|gptbot|chatgpt-user|oai-searchbot'
    . '|claudebot|claude-web|anthropic-ai|perplexitybot|perplexity-user|amazonbot|cohere-ai|diffbot|ia_archiver~i';

function an_is_bot(string $ua): bool
{
    // An empty or absurdly short UA is not a browser.
    if (strlen($ua) < 20) {
        return true;
    }
    return preg_match(AN_BOT_RE, $ua) === 1;
}

// ===========================================================================
//  UA -> device / browser / OS
//
//  Order matters: in-app and vendor browsers carry "Chrome" and "Safari"
//  tokens of their own, so they are tested first. $hints are the client hints
//  the beacon sends - ['mobile' => bool|null, 'platform' => string|null,
//  'touch' => int (maxTouchPoints)] - because the UA alone cannot tell an
//  iPad from a Mac any more.
// ===========================================================================

const AN_BROWSERS = [
    // label            => pattern
    'Instagram'        => '~Instagram~',
    'Facebook'         => '~FBAN|FBAV|FB_IAB~',
    'Snapchat'         => '~Snapchat~',
    'LinkedIn'         => '~LinkedInApp~',
    'Samsung Internet' => '~SamsungBrowser~',
    'UC Browser'       => '~UCBrowser|UCWEB~',
    'Mi Browser'       => '~MiuiBrowser|XiaoMi/MiuiBrowser~',
    'Opera'            => '~OPR/|Opera|OPT/|OPX/~',
    'Edge'             => '~Edg/|EdgA/|EdgiOS/~',
    'Brave'            => '~Brave~',
    'Vivaldi'          => '~Vivaldi~',
    'Yandex'           => '~YaBrowser~',
    'Firefox'          => '~Firefox/|FxiOS/~',
    'Chrome'           => '~Chrome/|CriOS/~',
    'Safari'           => '~Version/[\d.]+.*Safari/|Mobile/\w+ Safari~',
    'Android WebView'  => '~; wv\)~',
];

/**
 * @return array{device:string, browser:string, os:string}
 */
function an_parse_ua(string $ua, array $hints = []): array
{
    $browser = 'Other';
    foreach (AN_BROWSERS as $label => $re) {
        if (preg_match($re, $ua) === 1) {
            $browser = $label;
            break;
        }
    }
    // Chromium WebView inside an unknown app: "; wv)" + Chrome token.
    if ($browser === 'Chrome' && strpos($ua, '; wv)') !== false) {
        $browser = 'Android WebView';
    }

    $os = 'Other';
    if (preg_match('~iPhone|iPod~', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('~iPad~', $ua)) {
        $os = 'iPadOS';
    } elseif (preg_match('~Android~', $ua)) {
        $os = 'Android';
    } elseif (preg_match('~CrOS~', $ua)) {
        $os = 'ChromeOS';
    } elseif (preg_match('~Windows NT|Windows Phone~', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('~Mac OS X|Macintosh~', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('~Linux|X11~', $ua)) {
        $os = 'Linux';
    }
    if (!empty($hints['platform'])) {
        // Sec-CH-UA-Platform / navigator.userAgentData.platform beats the frozen UA.
        $map = ['Android' => 'Android', 'iOS' => 'iOS', 'Windows' => 'Windows', 'macOS' => 'macOS',
                'Chrome OS' => 'ChromeOS', 'Chromium OS' => 'ChromeOS', 'Linux' => 'Linux'];
        $os = $map[$hints['platform']] ?? $os;
    }

    // Device class. iPadOS 13+ Safari sends a *Macintosh* UA; the only tell is
    // touch support, so the beacon's maxTouchPoints decides it.
    $touch = (int) ($hints['touch'] ?? 0);
    if ($os === 'macOS' && $touch > 1) {
        $os = 'iPadOS';
    }

    if ($os === 'iPadOS' || preg_match('~Tablet|Tab\b|SM-T\d|Kindle|Silk/~', $ua)
        || ($os === 'Android' && strpos($ua, 'Mobile') === false)) {
        $device = 'tablet';
    } elseif (($hints['mobile'] ?? null) === true || preg_match('~Mobi|iPhone|iPod|Android.*Mobile|Windows Phone~', $ua)) {
        $device = 'mobile';
    } elseif (in_array($os, ['Windows', 'macOS', 'Linux', 'ChromeOS'], true)) {
        $device = 'desktop';
    } else {
        $device = 'other';
    }

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

// ===========================================================================
//  Traffic source + channel
//
//  Inputs: the landing URL's query params, the referrer HOST (the site's
//  Referrer-Policy and Google's own mean a host is all that usually arrives),
//  our own host, and the UA (in-app browsers send no referrer but name
//  themselves).
// ===========================================================================

const AN_SEARCH_HOSTS = ['google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'ecosia', 'search.brave',
                         'startpage', 'qwant', 'naver', 'seznam', 'sogou', 'ask'];
const AN_SOCIAL_HOSTS = ['facebook', 'fb.com', 'fb.me', 'instagram', 't.co', 'twitter', 'x.com', 'linkedin', 'lnkd.in',
                         'youtube', 'youtu.be', 'pinterest', 'pin.it', 'reddit', 'quora', 'threads', 'snapchat',
                         'tiktok', 'sharechat', 'moj', 'koo'];
const AN_MESSAGING_HOSTS = ['whatsapp', 'wa.me', 'l.wl.co', 'telegram', 't.me', 'messenger'];
const AN_AI_HOSTS = ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com',
                     'claude.ai', 'you.com', 'meta.ai', 'deepseek.com'];
const AN_EMAIL_HOSTS = ['mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.com', 'mail.zoho.in',
                        'mail.zoho.com', 'mail.rediff'];

function an_host_matches(string $host, array $needles): ?string
{
    foreach ($needles as $needle) {
        // label-boundary match: "google" matches www.google.co.in, not "notgoogle.com"
        if ($host === $needle || preg_match('~(^|\.)' . preg_quote($needle, '~') . '(\.|$)~', $host) === 1) {
            return $needle;
        }
    }
    return null;
}

/**
 * @return array{channel:string, source:string, medium:?string, campaign:?string,
 *               term:?string, content:?string, click_id:?string}
 */
function an_classify_source(array $query, string $refHost, string $ownHost, string $ua = ''): array
{
    $refHost = strtolower(preg_replace('~^www\.~', '', $refHost));
    $ownHost = strtolower(preg_replace('~^www\.~', '', $ownHost));
    if ($refHost !== '' && $refHost === $ownHost) {
        $refHost = ''; // internal navigation never starts a new source
    }

    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $v = isset($query[$k]) ? strtolower(trim(substr((string) $query[$k], 0, 100))) : '';
        $utm[$k] = $v !== '' ? $v : null;
    }
    $clickId = null;
    foreach (['gclid' => 'google', 'gbraid' => 'google', 'wbraid' => 'google', 'msclkid' => 'bing', 'fbclid' => 'facebook',
              'ttclid' => 'tiktok', 'li_fat_id' => 'linkedin'] as $param => $network) {
        if (!empty($query[$param])) {
            $clickId = $param;             // presence only - the id itself is an identifier and is never stored
            $utm['utm_source'] = $utm['utm_source'] ?? $network;
            break;
        }
    }

    $source = $utm['utm_source'] ?? ($refHost !== '' ? $refHost : null);
    $medium = $utm['utm_medium'];
    $srcForMatch = $source ?? '';

    $isSearch    = an_host_matches($srcForMatch, AN_SEARCH_HOSTS) !== null;
    $isSocial    = an_host_matches($srcForMatch, AN_SOCIAL_HOSTS) !== null;
    $isMessaging = an_host_matches($srcForMatch, AN_MESSAGING_HOSTS) !== null || in_array($srcForMatch, ['whatsapp', 'telegram', 'sms'], true);
    $isAi        = an_host_matches($srcForMatch, AN_AI_HOSTS) !== null;
    $isEmailHost = an_host_matches($srcForMatch, AN_EMAIL_HOSTS) !== null;

    // In-app browsers usually strip the referrer; the UA still names the app.
    if ($source === null && preg_match('~Instagram|FBAN|FBAV|FB_IAB|Snapchat|LinkedInApp~', $ua) === 1) {
        $source = preg_match('~Instagram~', $ua) ? 'instagram' : (preg_match('~Snapchat~', $ua) ? 'snapchat'
            : (preg_match('~LinkedInApp~', $ua) ? 'linkedin' : 'facebook'));
        $isSocial = true;
    }

    $paidMedium = $medium !== null && preg_match('~^(cpc|ppc|paid|paidsearch|paid_search|paid-search|sem|cpm|cpv|cpa|display|banner|paid_social|paidsocial|paid-social|social_paid|ads?)$~', $medium) === 1;

    if ($paidMedium || $clickId !== null && $clickId !== 'fbclid') {
        if ($medium !== null && preg_match('~^(display|banner|cpm)$~', $medium)) {
            $channel = 'Display';
        } elseif ($isSocial || ($medium !== null && strpos($medium, 'social') !== false) || $clickId === 'ttclid' || $clickId === 'li_fat_id') {
            $channel = 'Paid Social';
        } else {
            $channel = 'Paid Search';
        }
    } elseif ($medium !== null && preg_match('~^(e-?mail|newsletter)$~', $medium) || $isEmailHost || $srcForMatch === 'newsletter') {
        $channel = 'Email';
    } elseif ($medium === 'affiliate') {
        $channel = 'Affiliates';
    } elseif ($isMessaging || $medium === 'sms' || $medium === 'whatsapp') {
        $channel = 'Messaging';
    } elseif ($isAi) {
        $channel = 'AI Assistants';
    } elseif ($isSearch) {
        $channel = 'Organic Search';
    } elseif ($isSocial || ($medium !== null && strpos($medium, 'social') !== false)) {
        $channel = 'Organic Social';
    } elseif ($source === null && $medium === null) {
        $channel = 'Direct';
    } elseif ($refHost !== '' && $utm['utm_source'] === null) {
        $channel = 'Referral';
    } else {
        $channel = 'Unassigned';
    }

    // Normalise the displayed source to a registrable-ish name.
    if ($source !== null) {
        foreach ([AN_AI_HOSTS, AN_EMAIL_HOSTS, AN_MESSAGING_HOSTS, AN_SOCIAL_HOSTS, AN_SEARCH_HOSTS] as $list) {
            $hit = an_host_matches($source, $list);
            if ($hit !== null) {
                $source = $hit;
                break;
            }
        }
    }

    return [
        'channel'  => $channel,
        'source'   => $source ?? '(direct)',
        'medium'   => $medium ?? ($channel === 'Organic Search' ? 'organic' : ($channel === 'Referral' ? 'referral' : ($channel === 'Direct' ? '(none)' : null))),
        'campaign' => $utm['utm_campaign'],
        'term'     => $utm['utm_term'],
        'content'  => $utm['utm_content'],
        'click_id' => $clickId,
    ];
}

// ===========================================================================
//  Enums: label <-> stored id
//
//  APPEND ONLY. An id that changes meaning rewrites history, because the
//  rollup rows that B3 writes keep the number, not the word.
// ===========================================================================

/** device: 0 other, 1 desktop, 2 mobile, 3 tablet. */
const AN_DEVICES = [0 => 'other', 1 => 'desktop', 2 => 'mobile', 3 => 'tablet'];

const AN_BROWSER_IDS = [
    'Other' => 0, 'Chrome' => 1, 'Safari' => 2, 'Firefox' => 3, 'Edge' => 4, 'Opera' => 5,
    'Samsung Internet' => 6, 'UC Browser' => 7, 'Mi Browser' => 8, 'Brave' => 9, 'Vivaldi' => 10,
    'Yandex' => 11, 'Instagram' => 12, 'Facebook' => 13, 'Snapchat' => 14, 'LinkedIn' => 15,
    'Android WebView' => 16,
];

const AN_OS_IDS = [
    'Other' => 0, 'Android' => 1, 'iOS' => 2, 'iPadOS' => 3, 'Windows' => 4, 'macOS' => 5,
    'Linux' => 6, 'ChromeOS' => 7,
];

/** The channel order the reports show, which is also the classifier's rule order. */
const AN_CHANNEL_IDS = [
    'Unassigned' => 0, 'Direct' => 1, 'Organic Search' => 2, 'Paid Search' => 3, 'Organic Social' => 4,
    'Paid Social' => 5, 'Display' => 6, 'Email' => 7, 'Affiliates' => 8, 'Messaging' => 9,
    'AI Assistants' => 10, 'Referral' => 11,
];

/** Which click-id parameter was present. The value itself is never stored. */
const AN_CLICK_IDS = ['gclid' => 1, 'gbraid' => 2, 'wbraid' => 3, 'msclkid' => 4, 'fbclid' => 5, 'ttclid' => 6, 'li_fat_id' => 7];

/**
 * Page types. The id is baked into the signed page token, so the browser
 * cannot claim a page view happened somewhere it did not.
 */
const AN_PAGE_TYPES = [
    0 => 'other', 1 => 'home', 2 => 'category', 3 => 'product', 4 => 'combo', 5 => 'search',
    6 => 'cart', 7 => 'checkout', 8 => 'account', 9 => 'content', 10 => 'blog', 11 => 'listing',
    12 => 'order', 13 => 'auth',
    // 14 was appended after the first draft: a brand page was being counted as
    // a category, so brand 7's traffic was reported against category 7. Ids
    // are append-only for exactly this reason - renumbering would have changed
    // what every stored row means.
    14 => 'brand',
];

/**
 * Event names. Everything to do with money is written by the server (see
 * includes/analytics/events.php); the tail of this list is what the browser is
 * allowed to send, and nothing else is accepted from it.
 */
const AN_EVENT_IDS = [
    'add_to_cart' => 1, 'remove_from_cart' => 2, 'add_to_wishlist' => 3, 'begin_checkout' => 4,
    'purchase' => 5, 'coupon_apply' => 6, 'signup' => 7, 'login' => 8, 'newsletter_signup' => 9,
    'search' => 10, 'popup_view' => 11, 'popup_convert' => 12,
    // client-reportable:
    'outbound' => 13, 'download' => 14, 'share' => 15, 'video_play' => 16, 'filter_use' => 17,
];

/** The only names assets/js/analytics.js may send. Money is not on this list. */
const AN_CLIENT_EVENTS = ['outbound', 'download', 'share', 'video_play', 'filter_use', 'popup_view', 'popup_convert'];

/** Core Web Vitals metric ids, and the good/poor thresholds the reports split on. */
const AN_VITALS = [
    1 => ['lcp',  2500, 4000],
    2 => ['inp',   200,  500],
    3 => ['cls',   100,  250],   // stored as cls * 1000, so 0.1 -> 100
    4 => ['fcp',  1800, 3000],
    5 => ['ttfb',  800, 1800],
];

function an_device_id(string $device): int
{
    $flip = array_flip(AN_DEVICES);
    return $flip[$device] ?? 0;
}

function an_browser_id(string $browser): int
{
    return AN_BROWSER_IDS[$browser] ?? 0;
}

function an_os_id(string $os): int
{
    return AN_OS_IDS[$os] ?? 0;
}

function an_channel_id(string $channel): int
{
    return AN_CHANNEL_IDS[$channel] ?? 0;
}

function an_click_id(?string $param): int
{
    return $param === null ? 0 : (AN_CLICK_IDS[$param] ?? 0);
}

function an_event_id(string $name): int
{
    return AN_EVENT_IDS[$name] ?? 0;
}

/** id -> label, for the screens B3-B5 build. */
function an_label(string $enum, int $id): string
{
    switch ($enum) {
        case 'device':
            return AN_DEVICES[$id] ?? 'other';
        case 'browser':
            return (string) (array_flip(AN_BROWSER_IDS)[$id] ?? 'Other');
        case 'os':
            return (string) (array_flip(AN_OS_IDS)[$id] ?? 'Other');
        case 'channel':
            return (string) (array_flip(AN_CHANNEL_IDS)[$id] ?? 'Unassigned');
        case 'page_type':
            return AN_PAGE_TYPES[$id] ?? 'other';
        case 'event':
            return (string) (array_flip(AN_EVENT_IDS)[$id] ?? 'unknown');
        default:
            return '';
    }
}

// ===========================================================================
//  IP anonymisation
//
//  Used for the visitor hash and nothing else. The IP is anonymised BEFORE it
//  is hashed, not after: a hash of the full address is still a full address to
//  anyone who can try four billion of them, and four billion sha256 calls is
//  minutes of GPU time. /24 and /48 are the cuts the GDPR guidance names.
// ===========================================================================

function an_anonymise_ip(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return '';
    }

    if (strlen($packed) === 4) {
        // IPv4 -> /24: keep three octets.
        return (string) inet_ntop(substr($packed, 0, 3) . "\0");
    }

    // IPv6 -> /48: keep the first six bytes.
    return (string) inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));
}
