<?php
/**
 * ShopInnKart - Country dial codes for the international phone field.
 *
 * `min`/`max` are the length of the NATIONAL number, i.e. after the dial code
 * and after any trunk prefix is stripped. They are deliberately ranges: most
 * countries issue several number lengths, and a single exact length would
 * reject real numbers.
 *
 * This is length-and-prefix validation, not carrier-grade parsing. It catches
 * a transposed digit count and a wrong country, which is what a contact form
 * needs; it does not know which operator ranges are actually allocated. Full
 * validation needs libphonenumber, which would mean a dependency this project
 * does not have.
 *
 * The flag is a Unicode regional-indicator pair. Windows renders those as the
 * two letters rather than a picture, which is why every option also carries
 * the ISO code and the dial code as plain text - the control stays readable
 * whatever the platform does with the emoji.
 */

declare(strict_types=1);

/**
 * Countries offered in the phone selector, keyed by ISO 3166-1 alpha-2.
 *
 * India first because that is the store's market; the rest alphabetical.
 *
 * @return array<string, array{name:string, dial:string, min:int, max:int}>
 */
function phone_countries(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    return $list = [
        'IN' => ['name' => 'India',                'dial' => '91',  'min' => 10, 'max' => 10],

        'AE' => ['name' => 'United Arab Emirates', 'dial' => '971', 'min' => 8,  'max' => 9],
        'AF' => ['name' => 'Afghanistan',          'dial' => '93',  'min' => 9,  'max' => 9],
        'AR' => ['name' => 'Argentina',            'dial' => '54',  'min' => 10, 'max' => 11],
        'AT' => ['name' => 'Austria',              'dial' => '43',  'min' => 7,  'max' => 13],
        'AU' => ['name' => 'Australia',            'dial' => '61',  'min' => 9,  'max' => 9],
        'BD' => ['name' => 'Bangladesh',           'dial' => '880', 'min' => 10, 'max' => 10],
        'BE' => ['name' => 'Belgium',              'dial' => '32',  'min' => 8,  'max' => 9],
        'BH' => ['name' => 'Bahrain',              'dial' => '973', 'min' => 8,  'max' => 8],
        'BR' => ['name' => 'Brazil',               'dial' => '55',  'min' => 10, 'max' => 11],
        'BT' => ['name' => 'Bhutan',               'dial' => '975', 'min' => 7,  'max' => 8],
        'CA' => ['name' => 'Canada',               'dial' => '1',   'min' => 10, 'max' => 10],
        'CH' => ['name' => 'Switzerland',          'dial' => '41',  'min' => 9,  'max' => 9],
        'CN' => ['name' => 'China',                'dial' => '86',  'min' => 11, 'max' => 11],
        'CZ' => ['name' => 'Czechia',              'dial' => '420', 'min' => 9,  'max' => 9],
        'DE' => ['name' => 'Germany',              'dial' => '49',  'min' => 10, 'max' => 11],
        'DK' => ['name' => 'Denmark',              'dial' => '45',  'min' => 8,  'max' => 8],
        'EG' => ['name' => 'Egypt',                'dial' => '20',  'min' => 10, 'max' => 10],
        'ES' => ['name' => 'Spain',                'dial' => '34',  'min' => 9,  'max' => 9],
        'FI' => ['name' => 'Finland',              'dial' => '358', 'min' => 9,  'max' => 10],
        'FR' => ['name' => 'France',               'dial' => '33',  'min' => 9,  'max' => 9],
        'GB' => ['name' => 'United Kingdom',       'dial' => '44',  'min' => 10, 'max' => 10],
        'GR' => ['name' => 'Greece',               'dial' => '30',  'min' => 10, 'max' => 10],
        'HK' => ['name' => 'Hong Kong',            'dial' => '852', 'min' => 8,  'max' => 8],
        'ID' => ['name' => 'Indonesia',            'dial' => '62',  'min' => 9,  'max' => 12],
        'IE' => ['name' => 'Ireland',              'dial' => '353', 'min' => 9,  'max' => 9],
        'IL' => ['name' => 'Israel',               'dial' => '972', 'min' => 9,  'max' => 9],
        'IT' => ['name' => 'Italy',                'dial' => '39',  'min' => 9,  'max' => 11],
        'JP' => ['name' => 'Japan',                'dial' => '81',  'min' => 10, 'max' => 10],
        'KE' => ['name' => 'Kenya',                'dial' => '254', 'min' => 9,  'max' => 9],
        'KR' => ['name' => 'South Korea',          'dial' => '82',  'min' => 9,  'max' => 10],
        'KW' => ['name' => 'Kuwait',               'dial' => '965', 'min' => 8,  'max' => 8],
        'LK' => ['name' => 'Sri Lanka',            'dial' => '94',  'min' => 9,  'max' => 9],
        'MU' => ['name' => 'Mauritius',            'dial' => '230', 'min' => 7,  'max' => 8],
        'MV' => ['name' => 'Maldives',             'dial' => '960', 'min' => 7,  'max' => 7],
        'MX' => ['name' => 'Mexico',               'dial' => '52',  'min' => 10, 'max' => 10],
        'MY' => ['name' => 'Malaysia',             'dial' => '60',  'min' => 9,  'max' => 10],
        'NG' => ['name' => 'Nigeria',              'dial' => '234', 'min' => 10, 'max' => 10],
        'NL' => ['name' => 'Netherlands',          'dial' => '31',  'min' => 9,  'max' => 9],
        'NO' => ['name' => 'Norway',               'dial' => '47',  'min' => 8,  'max' => 8],
        'NP' => ['name' => 'Nepal',                'dial' => '977', 'min' => 10, 'max' => 10],
        'NZ' => ['name' => 'New Zealand',          'dial' => '64',  'min' => 8,  'max' => 10],
        'OM' => ['name' => 'Oman',                 'dial' => '968', 'min' => 8,  'max' => 8],
        'PH' => ['name' => 'Philippines',          'dial' => '63',  'min' => 10, 'max' => 10],
        'PK' => ['name' => 'Pakistan',             'dial' => '92',  'min' => 10, 'max' => 10],
        'PL' => ['name' => 'Poland',               'dial' => '48',  'min' => 9,  'max' => 9],
        'PT' => ['name' => 'Portugal',             'dial' => '351', 'min' => 9,  'max' => 9],
        'QA' => ['name' => 'Qatar',                'dial' => '974', 'min' => 8,  'max' => 8],
        'RU' => ['name' => 'Russia',               'dial' => '7',   'min' => 10, 'max' => 10],
        'SA' => ['name' => 'Saudi Arabia',         'dial' => '966', 'min' => 9,  'max' => 9],
        'SE' => ['name' => 'Sweden',               'dial' => '46',  'min' => 7,  'max' => 9],
        'SG' => ['name' => 'Singapore',            'dial' => '65',  'min' => 8,  'max' => 8],
        'TH' => ['name' => 'Thailand',             'dial' => '66',  'min' => 9,  'max' => 9],
        'TR' => ['name' => 'Turkey',               'dial' => '90',  'min' => 10, 'max' => 10],
        'TZ' => ['name' => 'Tanzania',             'dial' => '255', 'min' => 9,  'max' => 9],
        'UA' => ['name' => 'Ukraine',              'dial' => '380', 'min' => 9,  'max' => 9],
        'UG' => ['name' => 'Uganda',               'dial' => '256', 'min' => 9,  'max' => 9],
        'US' => ['name' => 'United States',        'dial' => '1',   'min' => 10, 'max' => 10],
        'VN' => ['name' => 'Vietnam',              'dial' => '84',  'min' => 9,  'max' => 10],
        'ZA' => ['name' => 'South Africa',         'dial' => '27',  'min' => 9,  'max' => 9],
        'ZW' => ['name' => 'Zimbabwe',             'dial' => '263', 'min' => 9,  'max' => 9],
    ];
}

/** The store's own country, used as the field's default. */
function phone_default_country(): string
{
    $iso = strtoupper(trim((string) setting('store_country_code', 'IN')));
    return isset(phone_countries()[$iso]) ? $iso : 'IN';
}

/**
 * The flag emoji for an ISO code.
 *
 * Built from regional indicator symbols rather than shipped as images, so there
 * is nothing to download. Windows shows the letter pair instead of a picture;
 * the option text carries the country name and dial code regardless, so the
 * control never depends on the emoji rendering.
 */
function country_flag(string $iso): string
{
    $iso = strtoupper($iso);
    if (preg_match('/^[A-Z]{2}$/', $iso) !== 1) {
        return '';
    }

    $flag = '';
    foreach (str_split($iso) as $letter) {
        // 0x1F1E6 is REGIONAL INDICATOR SYMBOL LETTER A.
        $flag .= mb_chr(0x1F1E6 + (ord($letter) - ord('A')), 'UTF-8');
    }
    return $flag;
}

/**
 * Validate a national number against one country's rules.
 *
 * Returns the number in E.164 form (+<dial><national>) when it is acceptable,
 * or null when it is not. Accepting the number in several shapes and answering
 * in exactly one is what stops two records of the same person looking like two
 * people.
 *
 * Tolerated on input: spaces, dashes, brackets, a leading +, the dial code
 * with or without the +, and a national trunk '0'.
 */
function phone_to_e164(string $iso, string $input): ?string
{
    $iso = strtoupper(trim($iso));
    $country = phone_countries()[$iso] ?? null;
    if ($country === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $input) ?? '';
    if ($digits === '') {
        return null;
    }

    $dial = $country['dial'];

    // Strip the dial code when the caller typed it, but only when what remains
    // could still be a whole number - otherwise "91..." from a genuine Indian
    // number starting 91 would lose its first two digits.
    if (strncmp($digits, $dial, strlen($dial)) === 0) {
        $rest = substr($digits, strlen($dial));
        if (strlen($rest) >= $country['min'] && strlen($rest) <= $country['max']) {
            $digits = $rest;
        }
    }

    // National trunk prefix, used before the subscriber number in most of the
    // countries listed here.
    if (strlen($digits) > $country['max'] && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }

    $length = strlen($digits);
    if ($length < $country['min'] || $length > $country['max']) {
        return null;
    }

    // India additionally allocates mobile numbers only in the 6-9 range, and a
    // contact form asking for a mobile should say so rather than accept a
    // landline that will never receive a message.
    if ($iso === 'IN' && preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
        return null;
    }

    return '+' . $dial . $digits;
}

/** A human explanation of what this country expects, for the field's help text. */
function phone_country_hint(string $iso): string
{
    $country = phone_countries()[strtoupper($iso)] ?? null;
    if ($country === null) {
        return '';
    }
    $length = $country['min'] === $country['max']
        ? $country['min'] . ' digits'
        : $country['min'] . '-' . $country['max'] . ' digits';

    return $country['name'] . ' numbers are ' . $length
        . ($iso === 'IN' ? ', starting 6, 7, 8 or 9' : '') . '.';
}
