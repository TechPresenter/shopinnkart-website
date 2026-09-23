<?php
/**
 * ShopInnKart - Time-based one-time passwords (RFC 6238), in plain PHP.
 *
 * No library, no composer, and deliberately nothing over the network. The
 * whole algorithm is nine lines of HMAC; the reason authenticator apps agree
 * on it is that everybody implements the same nine lines.
 *
 * The parameters are the ones every app assumes when the URI does not say
 * otherwise - SHA-1, 6 digits, 30-second steps. They are not a security
 * choice we get to make: Google Authenticator and Authy ignore algorithm=SHA256
 * in the otpauth URI, so "upgrading" the hash would simply produce codes that
 * never match. The secret is 160 bits of random_bytes(), which is what the
 * strength actually rests on.
 *
 * The QR code is drawn HERE, from the vendored TCPDF 2-D barcode encoder, and
 * rendered as inline SVG. The shared-hosting temptation is to point an <img>
 * at a QR web service - that hands the shared secret, in the query string, to
 * somebody else's access log. This file never leaves the building.
 */

declare(strict_types=1);

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

/** Base32 alphabet, RFC 4648. No padding is used: authenticator apps dislike it. */
const TOTP_B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * A fresh shared secret: 20 random bytes, base32 for the app to read.
 * 32 characters, which is what a 160-bit HMAC-SHA1 key encodes to.
 */
function totp_secret_new(): string
{
    return totp_base32_encode(random_bytes(20));
}

/** Is this a secret this implementation can use at all? */
function totp_secret_valid(string $secret): bool
{
    $secret = totp_secret_normalise($secret);

    return $secret !== '' && strlen($secret) >= 16 && totp_base32_decode($secret) !== null;
}

/** Strip the spaces and lower case a human may have typed or pasted back. */
function totp_secret_normalise(string $secret): string
{
    return strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
}

/** The secret in groups of four, which is how it is readable off a screen. */
function totp_secret_grouped(string $secret): string
{
    return trim(chunk_split(totp_secret_normalise($secret), 4, ' '));
}

function totp_base32_encode(string $bytes): string
{
    $bits = '';
    for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_B32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    return $out;
}

/** Decoded bytes, or null when the string is not base32. */
function totp_base32_decode(string $secret): ?string
{
    $secret = totp_secret_normalise($secret);
    if ($secret === '') {
        return null;
    }

    $bits = '';
    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $index = strpos(TOTP_B32_ALPHABET, $secret[$i]);
        if ($index === false) {
            return null;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    // Trailing bits that do not make a whole byte are padding, not data.
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }

    return $out === '' ? null : $out;
}

/** Which 30-second step a moment in time falls in. */
function totp_step(?int $timestamp = null, int $period = TOTP_PERIOD): int
{
    return intdiv($timestamp ?? time(), max(1, $period));
}

/**
 * The code for one step. RFC 6238 / RFC 4226 dynamic truncation.
 *
 * @return string Six digits, left-padded - "004213" is a real code.
 */
function totp_code_at(string $secret, int $step, int $digits = TOTP_DIGITS): string
{
    $key = totp_base32_decode($secret);
    if ($key === null) {
        return '';
    }

    // The counter is a 64-bit big-endian integer. pack('J') needs PHP 5.6+ and
    // is exact; building it by hand is where most implementations go wrong.
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);

    // Low four bits of the last byte choose where to read the 4-byte window.
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** The code for right now - what the app on the phone is showing. */
function totp_code(string $secret, ?int $timestamp = null, int $digits = TOTP_DIGITS): string
{
    return totp_code_at($secret, totp_step($timestamp), $digits);
}

/**
 * Check a submitted code and say which step it belonged to.
 *
 * One step of drift either way (so ±30 s), which covers a phone whose clock is
 * a little off and a human who starts typing as the code is about to roll. Any
 * wider and the code is valid for longer than it is displayed.
 *
 * $lastStep is the replay guard: a code that has already signed somebody in is
 * refused for the remainder of its life, so shoulder-surfing one code does not
 * buy a second sign-in with it.
 *
 * Compared with hash_equals: the code is a secret for thirty seconds, and a
 * timing-leaky comparison of it is still a timing leak.
 *
 * @return int|null the step that matched, or null
 */
function totp_verify(string $secret, string $code, int $drift = 1, int $lastStep = 0, ?int $timestamp = null): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TOTP_DIGITS || !totp_secret_valid($secret)) {
        return null;
    }

    $now = totp_step($timestamp);
    $drift = max(0, min(10, $drift));

    // Oldest first, so the step recorded is the earliest that matched - which
    // is the conservative choice for the replay guard.
    for ($offset = -$drift; $offset <= $drift; $offset++) {
        $step = $now + $offset;
        if ($step <= $lastStep) {
            continue;   // already used, or older than one that was
        }
        if (hash_equals(totp_code_at($secret, $step), $code)) {
            return $step;
        }
    }

    return null;
}

/**
 * The otpauth:// URI an authenticator app reads out of the QR code.
 *
 * The label is "Issuer:account" by convention and the issuer is repeated as a
 * parameter, because different apps read one or the other.
 */
function totp_uri(string $secret, string $account, string $issuer): string
{
    // A colon in either part would split the label in the wrong place.
    $account = str_replace([':', '/'], ' ', trim($account));
    $issuer  = str_replace([':', '/'], ' ', trim($issuer));
    if ($issuer === '') {
        $issuer = 'ShopInnKart';
    }

    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
        'secret'    => totp_secret_normalise($secret),
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => TOTP_DIGITS,
        'period'    => TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * The QR code as inline SVG, drawn from the vendored encoder.
 *
 * Painted on its own white plate with a four-module quiet zone: phone cameras
 * need the margin, and a QR rendered in "currentColor" on a dark admin theme
 * is unreadable to every scanner. Returns '' if the encoder is unavailable,
 * and every screen that calls this also shows the secret as text, so a failure
 * here is an inconvenience and never a dead end.
 */
function totp_qr_svg(string $uri, int $module = 5): string
{
    $file = ROOT_PATH . '/vendor/tcpdf/tcpdf_barcodes_2d.php';
    if (!is_file($file)) {
        return '';
    }

    try {
        require_once $file;
        $barcode = new TCPDF2DBarcode($uri, 'QRCODE,M');
        $grid    = $barcode->getBarcodeArray();
    } catch (Throwable $e) {
        ErrorHandler::log('warning', 'TOTP QR not drawn: ' . $e->getMessage());
        return '';
    }

    if (!is_array($grid) || empty($grid['bcode']) || (int) ($grid['num_cols'] ?? 0) < 1) {
        return '';
    }

    $module = max(2, min(12, $module));
    $quiet  = 4;
    $cols   = (int) $grid['num_cols'];
    $rows   = (int) $grid['num_rows'];
    $size   = ($cols + 2 * $quiet) * $module;

    // One <path> for every dark module rather than thousands of <rect>s: the
    // markup is a quarter of the size and renders the same.
    $path = '';
    foreach ($grid['bcode'] as $r => $row) {
        foreach ($row as $c => $on) {
            if ((int) $on === 1) {
                $path .= 'M' . (($c + $quiet) * $module) . ' ' . (($r + $quiet) * $module)
                    . 'h' . $module . 'v' . $module . 'h-' . $module . 'z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
        . ' viewBox="0 0 ' . $size . ' ' . $size . '" role="img"'
        . ' aria-label="QR code for your authenticator app" style="display:block;border-radius:8px">'
        . '<rect width="' . $size . '" height="' . $size . '" fill="#ffffff"/>'
        . '<path fill="#000000" d="' . $path . '"/>'
        . '</svg>';
}

/** How many seconds the code on screen has left - for the "expires in" hint. */
function totp_seconds_remaining(?int $timestamp = null): int
{
    $now = $timestamp ?? time();
    return TOTP_PERIOD - ($now % TOTP_PERIOD);
}
