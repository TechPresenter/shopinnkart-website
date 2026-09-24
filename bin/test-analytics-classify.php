<?php
/**
 * ShopInnKart - classifier test table for includes/analytics/classify.php.
 *
 * The bot regex, the UA parser and the traffic-source classifier decide what
 * every traffic report says. They are pure functions over strings, which makes
 * them cheap to test and easy to break silently: a regex edited to catch one
 * new crawler can start eating a browser, and nothing in the product would
 * complain - the numbers would just quietly drop.
 *
 * So these 49 cases live next to the code and run against the REAL file, not a
 * copy. They were written with the prototype
 * (analytics-data/proto/an-classify.php) before the collector existed and are
 * reproduced here unchanged, so the two can never drift: if a case fails, it
 * is classify.php that is wrong.
 *
 * The UA strings are real ones seen in Indian mobile traffic - Samsung
 * Internet, UC, Mi, the Instagram and Facebook in-app browsers, an iPad
 * pretending to be a Mac - because those are the ones a naive parser gets
 * wrong, and getting them wrong means reporting a third of a store's shoppers
 * as "Other".
 *
 * Run:  php bin/test-analytics-classify.php
 *       php bin/test-analytics-classify.php --bench    (10k iterations)
 *
 * Exit code 0 = every case passed, 1 = at least one failed.
 * It reads no database and writes nothing, so it is safe on any host.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/analytics/classify.php';

$bench = in_array('--bench', $argv, true);

/** ua, client hints, expected [device, browser, os] - or 'BOT'. */
$uaCases = [
    ['Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', [], ['mobile', 'Chrome', 'Android']],
    ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', [], ['mobile', 'Samsung Internet', 'Android']],
    ['Mozilla/5.0 (Linux; U; Android 12; en-US; RMX3471 Build/SP1A.210812.016) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/100.0.4896.58 UCBrowser/13.6.0.1315 Mobile Safari/537.36', [], ['mobile', 'UC Browser', 'Android']],
    ['Mozilla/5.0 (Linux; U; Android 13; en-in; 2201117TI Build/TKQ1.221114.001) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/112.0.5615.136 Mobile Safari/537.36 XiaoMi/MiuiBrowser/14.5.2-gn', [], ['mobile', 'Mi Browser', 'Android']],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 7 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/127.0.6533.103 Mobile Safari/537.36 Instagram 343.0.0.33.101 Android', [], ['mobile', 'Instagram', 'Android']],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBBV/620000000;FBDV/iPhone15,2]', [], ['mobile', 'Facebook', 'iOS']],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', [], ['mobile', 'Safari', 'iOS']],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', [], ['mobile', 'Chrome', 'iOS']],
    // iPadOS Safari sends a Macintosh UA. Only maxTouchPoints tells them apart,
    // which is why the beacon sends it - a tablet counted as a desktop makes
    // every "should we fix mobile?" decision from the wrong number.
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', ['touch' => 5], ['tablet', 'Safari', 'iPadOS']],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', ['touch' => 0], ['desktop', 'Safari', 'macOS']],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', [], ['desktop', 'Edge', 'Windows']],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', [], ['desktop', 'Firefox', 'Windows']],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', [], ['desktop', 'Opera', 'Windows']],
    ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', [], ['desktop', 'Chrome', 'ChromeOS']],
    ['Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', [], ['tablet', 'Chrome', 'Android']],
    ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', ['mobile' => true, 'platform' => 'Android'], ['mobile', 'Chrome', 'Android']],
    ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', [], 'BOT'],
    // Googlebot's mobile UA is a complete Android Chrome string with the bot
    // note at the end. A parser that stops at "Chrome" counts the crawler.
    ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.119 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', [], 'BOT'],
    ['WhatsApp/2.23.20.0', [], 'BOT'],
    ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', [], 'BOT'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0.0.0 Safari/537.36', [], 'BOT'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', [], 'BOT'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', [], 'BOT'],
    ['Mozilla/5.0 (Linux; Android 11; moto g(9) power) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse', [], 'BOT'],
    ['curl/8.4.0', [], 'BOT'],
    ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', [], 'BOT'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', [], ['mobile', 'Chrome', 'Android']],
];

/** query, referrer host, ua, expected channel, expected source. */
$srcCases = [
    [[], '', '', 'Direct', '(direct)'],
    [[], 'www.google.co.in', '', 'Organic Search', 'google'],
    [[], 'www.bing.com', '', 'Organic Search', 'bing'],
    [['gclid' => 'abc'], 'www.google.com', '', 'Paid Search', 'google'],
    [['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'Diwali'], 'www.google.com', '', 'Paid Search', 'google'],
    [['utm_source' => 'instagram', 'utm_medium' => 'paid_social'], '', '', 'Paid Social', 'instagram'],
    // An fbclid on a link shared organically is not a paid click: Facebook
    // stamps one on every outbound link. Paid needs a paid medium as well.
    [['fbclid' => 'x'], 'l.facebook.com', '', 'Organic Social', 'facebook'],
    [[], 'l.instagram.com', '', 'Organic Social', 'instagram'],
    // The in-app browsers send no referrer at all, so without the UA rule
    // every Instagram shopper in the country lands in "Direct".
    [[], '', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Instagram 343.0', 'Organic Social', 'instagram'],
    [[], 'web.whatsapp.com', '', 'Messaging', 'whatsapp'],
    [['utm_source' => 'whatsapp', 'utm_medium' => 'broadcast'], '', '', 'Messaging', 'whatsapp'],
    [['utm_source' => 'newsletter', 'utm_medium' => 'email'], '', '', 'Email', 'newsletter'],
    [[], 'mail.google.com', '', 'Email', 'mail.google.com'],
    [[], 'chatgpt.com', '', 'AI Assistants', 'chatgpt.com'],
    [[], 'www.perplexity.ai', '', 'AI Assistants', 'perplexity.ai'],
    [[], 'somefestiveblog.in', '', 'Referral', 'somefestiveblog.in'],
    // Our own host is not a source: an internal link must not restart the
    // attribution and steal the credit from whoever actually sent them.
    [[], 'shopinnkart.com', '', 'Direct', '(direct)'],
    [['utm_source' => 'partnerx', 'utm_medium' => 'affiliate'], '', '', 'Affiliates', 'partnerx'],
    [['utm_source' => 'flyer', 'utm_medium' => 'qr'], '', '', 'Unassigned', 'flyer'],
    // "notgoogle.com" contains "google". A substring match would call this
    // organic search and hand a stranger's site Google's numbers.
    [[], 'notgoogle.com', '', 'Referral', 'notgoogle.com'],
    [[], 't.co', '', 'Organic Social', 't.co'],
    [[], 'gemini.google.com', '', 'AI Assistants', 'gemini.google.com'],
];

$fail = 0;

$t0 = hrtime(true);
foreach ($uaCases as [$ua, $hints, $expect]) {
    $got = an_is_bot($ua) ? 'BOT' : array_values(an_parse_ua($ua, $hints));
    if ($got !== $expect) {
        $fail++;
        echo 'UA FAIL: ', substr($ua, 0, 90), "\n   expected ", json_encode($expect), ' got ', json_encode($got), "\n";
    }
}
$uaUs = (hrtime(true) - $t0) / 1000 / count($uaCases);

$t0 = hrtime(true);
foreach ($srcCases as [$query, $ref, $ua, $expectChannel, $expectSource]) {
    $result = an_classify_source($query, $ref, 'shopinnkart.com', $ua);
    if ($result['channel'] !== $expectChannel || $result['source'] !== $expectSource) {
        $fail++;
        echo 'SRC FAIL: ', json_encode($query), ' ref=', $ref, "\n",
             '   expected ', $expectChannel, '/', $expectSource,
             ' got ', $result['channel'], '/', $result['source'], "\n";
    }
}
$srcUs = (hrtime(true) - $t0) / 1000 / count($srcCases);

// The enum ids are written into every rollup row, so renumbering one silently
// changes the meaning of history. These are the ids as shipped.
$frozen = [
    'device mobile'      => [AN_DEVICES[2], 'mobile'],
    'browser Chrome'     => [an_browser_id('Chrome'), 1],
    'os Android'         => [an_os_id('Android'), 1],
    'channel Direct'     => [an_channel_id('Direct'), 1],
    'channel Messaging'  => [an_channel_id('Messaging'), 9],
    'event purchase'     => [an_event_id('purchase'), 5],
    'event add_to_cart'  => [an_event_id('add_to_cart'), 1],
    'page_type product'  => [AN_PAGE_TYPES[3], 'product'],
];
foreach ($frozen as $label => [$got, $want]) {
    if ($got !== $want) {
        $fail++;
        echo 'ENUM FAIL: ', $label, ' is ', json_encode($got), ', history says ', json_encode($want), "\n";
    }
}

// Anonymisation is the promise the whole anonymous mode rests on: the IP is
// cut BEFORE it is hashed, because a hash of a full address is still a full
// address to anyone willing to try four billion of them.
$ipCases = [
    ['203.0.113.47', '203.0.113.0'],
    ['2404:6800:4009:82b::200e', '2404:6800:4009::'],
    ['not-an-ip', ''],
];
foreach ($ipCases as [$ip, $want]) {
    $got = an_anonymise_ip($ip);
    if ($got !== $want) {
        $fail++;
        echo 'IP FAIL: ', $ip, ' -> ', $got, ', expected ', $want, "\n";
    }
}

if ($bench) {
    $ua = $uaCases[0][0];
    $t0 = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        an_is_bot($ua);
        an_parse_ua($ua, []);
        an_classify_source(['utm_source' => 'google', 'utm_medium' => 'cpc'], 'www.google.com', 'shopinnkart.com', $ua);
    }
    printf("bench: %.1f us per session classification (bot + UA + source), 10k iterations\n", (hrtime(true) - $t0) / 1000 / 10000);
}

printf(
    "%d UA cases, %d source cases, %d enum ids, %d ip cases, %d failures. ~%.1f us per UA parse, ~%.1f us per source classify\n",
    count($uaCases),
    count($srcCases),
    count($frozen),
    count($ipCases),
    $fail,
    $uaUs,
    $srcUs
);

exit($fail > 0 ? 1 : 0);
