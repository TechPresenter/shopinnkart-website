<?php
/**
 * ShopInnKart - Input validation.
 *
 * Usage:
 *   $v = new Validator(request_all());
 *   $v->required('email')->email('email')->required('password')->min('password', 8);
 *   if ($v->fails()) { json_validation_error($v->errors()); }
 *   $clean = $v->validated(['email', 'password']);
 */

declare(strict_types=1);

final class Validator
{
    private array $data;
    private array $errors = [];

    /** Friendly names used in messages, e.g. 'first_name' => 'First name'. */
    private array $labels = [];

    public function __construct(array $data, array $labels = [])
    {
        $this->data = $data;
        $this->labels = $labels;
    }

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    public function required(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        $empty = $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);

        if ($empty) {
            $this->addError($field, $message ?? $this->label($field) . ' is required.');
        }
        return $this;
    }

    /** At least one of the given fields must be filled. */
    public function requiredAny(array $fields, string $message): self
    {
        foreach ($fields as $field) {
            $value = $this->value($field);
            if ($value !== null && trim((string) $value) !== '') {
                return $this;
            }
        }
        $this->addError($fields[0], $message);
        return $this;
    }

    public function email(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, $message ?? 'Enter a valid email address.');
        }
        return $this;
    }

    /** Indian mobile number: 10 digits starting 6-9, optional +91 prefix. */
    public function phone(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value)) {
            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
            $digits = preg_replace('/^(0|91)/', '', $digits) ?? $digits;
            if (preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
                $this->addError($field, $message ?? 'Enter a valid 10-digit Indian mobile number.');
            }
        }
        return $this;
    }

    /** Indian PIN code: 6 digits, first digit 1-9. */
    public function pincode(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && preg_match('/^[1-9]\d{5}$/', (string) $value) !== 1) {
            $this->addError($field, $message ?? 'Enter a valid 6-digit PIN code.');
        }
        return $this;
    }

    public function min(string $field, int $length, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && mb_strlen((string) $value) < $length) {
            $this->addError($field, $message ?? $this->label($field) . " must be at least {$length} characters.");
        }
        return $this;
    }

    public function max(string $field, int $length, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && mb_strlen((string) $value) > $length) {
            $this->addError($field, $message ?? $this->label($field) . " must not exceed {$length} characters.");
        }
        return $this;
    }

    public function numeric(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && !is_numeric($value)) {
            $this->addError($field, $message ?? $this->label($field) . ' must be a number.');
        }
        return $this;
    }

    public function integer(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, $message ?? $this->label($field) . ' must be a whole number.');
        }
        return $this;
    }

    public function between(string $field, float $low, float $high, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && is_numeric($value)) {
            $number = (float) $value;
            if ($number < $low || $number > $high) {
                $this->addError($field, $message ?? $this->label($field) . " must be between {$low} and {$high}.");
            }
        }
        return $this;
    }

    public function in(string $field, array $allowed, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && !in_array((string) $value, array_map('strval', $allowed), true)) {
            $this->addError($field, $message ?? 'Select a valid ' . strtolower($this->label($field)) . '.');
        }
        return $this;
    }

    public function matches(string $field, string $otherField, ?string $message = null): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->addError($field, $message ?? $this->label($field) . ' does not match.');
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && preg_match($pattern, (string) $value) !== 1) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function url(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && !filter_var((string) $value, FILTER_VALIDATE_URL)) {
            $this->addError($field, $message ?? 'Enter a valid URL.');
        }
        return $this;
    }

    public function date(string $field, ?string $message = null): self
    {
        $value = $this->value($field);
        if ($this->filled($value) && strtotime((string) $value) === false) {
            $this->addError($field, $message ?? 'Enter a valid date.');
        }
        return $this;
    }

    /**
     * Password policy.
     *
     * Length, a letter-and-digit rule that a long passphrase is excused from,
     * a maximum (bcrypt ignores everything past 72 bytes, so a 200-character
     * passphrase was quietly weaker than the customer believed), the bundled
     * list of passwords every stuffing attack starts with, and a check that
     * the password is not simply the address or the store name.
     *
     * The rules themselves live in password_policy_error() so that the API,
     * the page handlers and the admin screens cannot drift apart.
     *
     * @param string $userType 'customer' or 'admin' - admins get a longer minimum
     * @param string $context  text the password must not repeat, e.g. the email
     */
    public function password(string $field, ?string $message = null, string $userType = 'customer', string $context = ''): self
    {
        $value = (string) $this->value($field);
        if ($value === '') {
            return $this;
        }

        if ($context === '') {
            // Default to the address being registered, which is the term
            // people reach for most often.
            $context = (string) ($this->value('email') ?? '');
        }

        $error = password_policy_error($value, $userType, $context);
        if ($error !== null) {
            $this->addError($field, $message ?? $error);
        }

        return $this;
    }

    /** Field value must not already exist in the given table/column. */
    public function unique(string $field, string $table, string $column, ?int $ignoreId = null, ?string $message = null): self
    {
        $value = $this->value($field);
        if (!$this->filled($value)) {
            return $this;
        }

        $allowed = ['users', 'admins', 'products', 'categories', 'brands', 'coupons', 'pages',
                    'newsletter_subscribers', 'blog_posts', 'product_variants', 'tags', 'vendors', 'pincodes'];
        if (!in_array($table, $allowed, true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Validator::unique - unsupported table/column.');
        }

        $sql = sprintf('SELECT `id` FROM `%s` WHERE `%s` = :v', $table, $column);
        $params = ['v' => $value];
        if ($ignoreId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $ignoreId;
        }

        if (Database::fetchColumn($sql . ' LIMIT 1', $params) !== null) {
            $this->addError($field, $message ?? 'That ' . strtolower($this->label($field)) . ' is already registered.');
        }
        return $this;
    }

    /** Referenced row must exist. */
    public function exists(string $field, string $table, string $column = 'id', ?string $message = null): self
    {
        $value = $this->value($field);
        if (!$this->filled($value)) {
            return $this;
        }

        $allowed = ['users', 'products', 'categories', 'brands', 'coupons', 'orders', 'admin_roles',
                    'attributes', 'attribute_values', 'product_variants', 'blog_categories', 'pages', 'vendors'];
        if (!in_array($table, $allowed, true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Validator::exists - unsupported table/column.');
        }

        $found = Database::fetchColumn(
            sprintf('SELECT `id` FROM `%s` WHERE `%s` = :v LIMIT 1', $table, $column),
            ['v' => $value]
        );
        if ($found === null) {
            $this->addError($field, $message ?? 'The selected ' . strtolower($this->label($field)) . ' is not valid.');
        }
        return $this;
    }

    /** Run an arbitrary check. */
    public function rule(string $field, bool $passes, string $message): self
    {
        if (!$passes) {
            $this->addError($field, $message);
        }
        return $this;
    }

    // -----------------------------------------------------------------------
    // Results
    // -----------------------------------------------------------------------

    /**
     * Record an error this class cannot express on its own.
     *
     * Some rules span two fields - a phone number is only valid against the
     * country chosen beside it - and a per-field rule method cannot see the
     * other field. Callers judge those themselves and report the result here,
     * so the message still travels with every other error rather than being
     * flashed separately and appearing somewhere else on the page.
     */
    public function fail(string $field, string $message): self
    {
        $this->addError($field, $message);
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }
        return null;
    }

    /** Only the requested keys, trimmed. */
    public function validated(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $value = $this->value($field);
            $clean[$field] = is_string($value) ? trim($value) : $value;
        }
        return $clean;
    }

    public function value(string $field)
    {
        return $this->data[$field] ?? null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function addError(string $field, string $message): void
    {
        // Keep the first message per field so forms show one error at a time.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function filled($value): bool
    {
        return $value !== null && (!is_string($value) || trim($value) !== '');
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }
        return ucfirst(str_replace('_', ' ', $field));
    }
}

/** Normalise an Indian phone number to 10 digits, or null if invalid. */
function normalize_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = preg_replace('/^(0|91)/', '', $digits) ?? $digits;
    return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? $digits : null;
}

/**
 * Strip dangerous markup from admin-authored HTML while keeping the tags
 * a CMS page or product description legitimately needs.
 *
 * This parses the markup and rebuilds it from an allowlist rather than pattern
 * matching over the raw string. A regex sanitiser has to guess where an
 * attribute starts and ends, and it guesses wrong: the previous version only
 * recognised a `javascript:` URL when it was wrapped in quotes, so the entirely
 * legal `<a href=javascript:alert(1)>` walked straight through. The parser has
 * no such blind spot — an attribute either survives the allowlist or it is gone,
 * however it was written.
 */
function sanitize_html(?string $html): string
{
    if ($html === null || trim($html) === '') {
        return '';
    }

    static $allowedElements = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'hr', 'span', 'div', 'figure', 'figcaption', 'small', 'sub', 'sup',
        'code', 'pre',
        // Survives only with a src on embed_host_allowed() - see the iframe
        // branch in $scrub below.
        'iframe',
    ];

    // Allowed on any element, plus the per-element extras below. `style` is
    // kept because editors emit it for alignment, but its value is screened.
    static $globalAttributes = ['class', 'id', 'style', 'title', 'dir', 'lang'];
    static $elementAttributes = [
        'a'     => ['href', 'target', 'rel', 'name'],
        'img'   => ['src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes'],
        'td'    => ['colspan', 'rowspan', 'headers', 'scope'],
        'th'    => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'ol'    => ['start', 'reversed', 'type'],
        'table' => ['summary'],
        'code'  => ['data-lang'],
        'pre'   => ['data-lang'],
        // Just the two the author supplies. loading/allow/referrerpolicy/
        // allowfullscreen are set below rather than accepted, and width/height
        // are deliberately absent so the ratio box governs the size.
        'iframe' => ['src', 'title'],
    ];

    // Elements whose text content is markup, not prose: unwrapping them would
    // spill "alert(1)" into the page as visible copy.
    static $dropWithContent = ['script', 'style', 'object', 'embed',
        'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea',
        'link', 'meta', 'base', 'svg', 'math'];

    // Same lock-down as svg_sanitize(): this parses admin-supplied markup, and
    // a null entity loader plus LIBXML_NONET means a DOCTYPE smuggled into the
    // fragment cannot pull a local file (file://, php://filter) or a remote one
    // into the output. LIBXML_NOENT is deliberately absent.
    // See svg_sanitize(): the setter returns bool before PHP 8.4, so the only
    // portable restore is null, libxml's own default loader.
    $previousLoader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
    libxml_set_external_entity_loader(static fn () => null);
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // The meta charset is what makes libxml read the fragment as UTF-8; without
    // it, any non-ASCII copy comes back mojibake. LIBXML_NONET stops the parser
    // reaching the network for anything the markup references.
    $loaded = $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . '</head><body><div id="sik-sanitize-root">' . $html . '</div></body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    libxml_set_external_entity_loader($previousLoader);

    if (!$loaded) {
        return '';
    }

    $root = $doc->getElementById('sik-sanitize-root');
    if ($root === null) {
        // getElementById needs a DTD-declared id on some libxml builds.
        $xpath = new DOMXPath($doc);
        $found = $xpath->query('//div[@id="sik-sanitize-root"]');
        $root  = ($found !== false && $found->length > 0) ? $found->item(0) : null;
    }
    if (!$root instanceof DOMElement) {
        return '';
    }

    /** A URL is kept only if its scheme is one we named. */
    $safeUrl = static function (string $value): bool {
        $flat = strtolower(trim($value));
        // Strip the control characters and entity padding used to hide a scheme
        // (e.g. "java\tscript:" or "java&#09;script:").
        $flat = preg_replace('/[\x00-\x20]|&#(x0*(9|a|d)|0*(9|10|13));?/i', '', $flat) ?? $flat;
        if ($flat === '') {
            return false;
        }
        // {{tokens}} are substituted after this point by content_apply_tokens(),
        // so the scheme test has to judge what the value becomes once they are
        // gone. href="{{x}}javascript:alert(1)" reads as a schemeless relative
        // path here, and an unknown token is replaced with nothing, so it would
        // reach the page as a live javascript: URL. libxml's own %7B%7B escaping
        // is undone first, otherwise a pre-encoded token hides the same trick.
        $flat = str_replace(['%7b%7b', '%7d%7d'], ['{{', '}}'], $flat);
        if (strpos($flat, '{{') !== false) {
            $flat = (string) preg_replace('/\{\{[#\/^]?[a-z0-9_]*(?::[a-z0-9\-_\/.]+)?\}\}/', '', $flat);
            // Nothing but tokens: resolves to a CMS page URL or a plain setting,
            // both of which are legitimate link targets.
            if (trim($flat) === '') {
                return true;
            }
        }
        // Relative paths, anchors and query-only links carry no scheme at all.
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $flat, $m)) {
            return true;
        }
        if (in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }
        // Base64 raster images are the one data: form worth keeping; data:image/svg+xml
        // is script-capable, so it stays out.
        return (bool) preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#', $flat);
    };

    $scrubAttributes = static function (DOMElement $element) use (
        $globalAttributes, $elementAttributes, $safeUrl
    ): void {
        $name    = strtolower($element->nodeName);
        $allowed = array_merge($globalAttributes, $elementAttributes[$name] ?? []);

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attrName = strtolower($attribute->nodeName);
            $value    = $attribute->nodeValue ?? '';

            // Every on* handler goes, whatever it is called, quoted or not.
            if (strpos($attrName, 'on') === 0 || !in_array($attrName, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }
            if (($attrName === 'href' || $attrName === 'src' || $attrName === 'srcset')
                && !$safeUrl($value)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }
            if ($attrName === 'style') {
                $flat = strtolower(preg_replace('/\s+/', '', $value) ?? '');
                if (strpos($flat, 'expression(') !== false
                    || strpos($flat, 'javascript:') !== false
                    || strpos($flat, 'vbscript:') !== false
                    || strpos($flat, 'behavior:') !== false
                    || strpos($flat, '@import') !== false) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        // A link that opens a new tab gets the opener severed for it.
        if ($name === 'a' && strtolower((string) $element->getAttribute('target')) === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    };

    $scrub = static function (DOMNode $node) use (
        &$scrub, $allowedElements, $dropWithContent, $scrubAttributes
    ): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;   // text nodes are escaped on serialisation
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, $dropWithContent, true)) {
                $node->removeChild($child);
                continue;
            }

            // Where it points is the whole safety test. An iframe aimed
            // anywhere else goes with its content: unwrapping would spill the
            // author's fallback markup onto the page as visible copy.
            if ($name === 'iframe') {
                if (!embed_host_allowed((string) $child->getAttribute('src'))) {
                    $node->removeChild($child);
                    continue;
                }

                $scrubAttributes($child);

                // A pasted style/class can re-impose a fixed width, which is
                // the one thing the wrapper cannot override.
                $child->removeAttribute('style');
                $child->removeAttribute('class');

                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $child->setAttribute('allowfullscreen', 'allowfullscreen');
                $child->setAttribute(
                    'allow',
                    'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture'
                );
                if (trim((string) $child->getAttribute('title')) === '') {
                    // Named, because a screen reader otherwise announces the
                    // frame with its URL.
                    $child->setAttribute('title', 'Embedded content');
                }

                // An iframe's children are fallback content for browsers that
                // have not supported it since 2001; keeping them would only
                // serialise as stray escaped text.
                while ($child->firstChild !== null) {
                    $child->removeChild($child->firstChild);
                }

                // The ratio box. Without the width the author pasted - which is
                // stripped above - a bare iframe falls back to 300x150, so this
                // is what gives the embed a size again, and the size it gives is
                // the width of whatever column it lands in.
                //
                // A span, not a div: editors habitually leave an embed inside a
                // <p>, and a div there closes the paragraph out from under it.
                if (!($node instanceof DOMElement)
                    || strpos((string) $node->getAttribute('class'), 'sik-embed') === false) {
                    $wrap = $child->ownerDocument->createElement('span');
                    $wrap->setAttribute(
                        'class',
                        'sik-embed' . (embed_is_map((string) $child->getAttribute('src')) ? ' sik-embed--map' : '')
                    );
                    $node->replaceChild($wrap, $child);
                    $wrap->appendChild($child);
                }
                continue;
            }

            if (!in_array($name, $allowedElements, true)) {
                // Unwrap: the tag goes, its readable content stays — the same
                // outcome strip_tags() gave for an unlisted tag.
                $scrub($child);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $scrubAttributes($child);
            $scrub($child);
        }
    };

    $scrub($root);

    $clean = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $clean .= $doc->saveHTML($child);
    }

    // libxml percent-encodes anything outside the URI character set when it
    // serialises href/src, and `{` `}` are outside it. That silently destroyed
    // every content token an author had put in a link: saving a CMS page turned
    // <a href="mailto:{{store_email}}"> into mailto:%7B%7Bstore_email%7D%7D,
    // which content_apply_tokens() no longer recognises, so the shopper was
    // shown a dead link. Only the three token shapes that function actually
    // resolves are decoded back, so nothing else the encoder did is undone.
    if (strpos($clean, '%7B%7B') !== false) {
        $clean = (string) preg_replace(
            '/%7B%7B((?:page|url):[a-z0-9\-_\/.]+|[a-z0-9_]+)%7D%7D/i',
            '{{$1}}',
            $clean
        );
    }

    return trim($clean);
}


/**
 * Is this embed URL one we are willing to frame?
 *
 * The host must match an entry exactly - never as a substring, or
 * `youtube.com.attacker.example` would pass. The scheme must be https: an
 * http embed inside an https page is blocked by the browser as mixed content,
 * so allowing it would only ever show the author an empty box.
 */
function embed_host_allowed(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    // Protocol-relative is how a lot of embed code still ships.
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
        return false;
    }

    $host = strtolower($parts['host']);
    $path = (string) ($parts['path'] ?? '');

    static $allowed = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'www.google.com',
        'maps.google.com',
        'www.openstreetmap.org',
    ];

    if (!in_array($host, $allowed, true)) {
        return false;
    }

    // google.com serves far more than maps, and youtube.com serves a whole
    // site; only the embed paths qualify.
    if ($host === 'www.google.com' || $host === 'maps.google.com') {
        return str_starts_with($path, '/maps/embed');
    }
    if (str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com')) {
        return str_starts_with($path, '/embed/');
    }

    return true;
}

/** Does this embed show a map rather than a video? A map wants a squarer box. */
function embed_is_map(string $url): bool
{
    return stripos($url, 'maps') !== false || stripos($url, 'openstreetmap') !== false;
}