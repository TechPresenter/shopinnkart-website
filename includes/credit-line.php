<?php
/**
 * The build credit, split so the name can be set apart from the sentence.
 *
 * "Design and developed by AppsGain Technologies" is ONE admin-owned setting
 * (credit_text), and it has to stay one: splitting it into two fields would
 * take the wording away from the owner, who may well want "Built by X" or a
 * bare name or nothing at all. So the split happens at render time, on the
 * last " by " in the string - the sentence goes in the UI face and the name it
 * ends on gets its own type.
 *
 * A credit with no " by " in it is treated as all name, which is exactly what
 * a bare "AppsGain Technologies" should look like, and an empty one prints
 * nothing at all rather than an empty element with a hover state.
 *
 * Both footers read this, so the two cannot drift apart.
 */

declare(strict_types=1);

/**
 * Split a credit line into its lead-in and the name it ends on.
 *
 * @return array{lead: string, name: string} lead may be '', name never is
 *                                           unless the whole credit is blank.
 */
function credit_line_parts(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        return ['lead' => '', 'name' => ''];
    }

    // The LAST " by ", not the first: "Designed and developed by X" has one,
    // but "Made by hand by X" has two and the name follows the second.
    // mb_strrpos, because a credit may be written in any script.
    $at = mb_strripos($text, ' by ');

    if ($at === false) {
        return ['lead' => '', 'name' => $text];
    }

    $lead = trim(mb_substr($text, 0, $at + 4));   // keep the "by"
    $name = trim(mb_substr($text, $at + 4));

    // "… by " with nothing after it is not a split, it is a whole credit that
    // happens to end in the word. Same for a name that swallowed everything.
    if ($name === '' || $lead === '') {
        return ['lead' => '', 'name' => $text];
    }

    return ['lead' => $lead, 'name' => $name];
}

/**
 * The credit as markup, or '' when the owner has cleared it.
 *
 * $class is the wrapper class, because the storefront and the admin place it
 * in different furniture. An empty credit_url prints the same two voices
 * without an anchor, rather than a dead link.
 */
function credit_line_html(string $class = 'sik-footer__credit'): string
{
    $text = trim((string) setting('credit_text', ''));

    if ($text === '') {
        return '';
    }

    $parts = credit_line_parts($text);
    $url   = trim((string) setting('credit_url', ''));

    $inner = '';
    if ($parts['lead'] !== '') {
        $inner .= '<span class="sik-credit__lead">' . e($parts['lead']) . '</span>';
    }
    $inner .= '<span class="sik-credit__name">' . e($parts['name']) . '</span>';

    if ($url === '') {
        return '<span class="' . e($class) . '"><span class="sik-credit__link">'
            . $inner . '</span></span>';
    }

    return '<span class="' . e($class) . '">'
        . '<a class="sik-credit__link" href="' . e($url) . '"'
        . ' target="_blank" rel="noopener noreferrer">'
        . $inner
        . '</a></span>';
}
