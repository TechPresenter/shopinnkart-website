<?php
/**
 * ShopInnKart - API index.
 *
 * Developer documentation for the REST layer. It returns no store data: the
 * catalogue below is read back out of the endpoint sources in /api, so the
 * methods, guards and parameter names on this page cannot drift from the
 * code the way a hand-written README would.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';

/** Human window for a rate limit, e.g. 900 -> "15 min". */
function api_index_window(int $seconds): string
{
    if ($seconds % 3600 === 0) {
        $hours = intdiv($seconds, 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
    if ($seconds % 60 === 0) {
        return intdiv($seconds, 60) . ' min';
    }
    return $seconds . ' sec';
}

/**
 * Describe one endpoint by reading its source. Nothing is executed and no
 * query runs - this only looks for the guard calls and validator rules every
 * endpoint in this project is written with.
 */
function api_index_describe(string $file): array
{
    $src = (string) @file_get_contents($file);

    $summary = '';
    if (preg_match('#/\*\*\s*\R\s*\*\s*(.+?)\s*\R#', $src, $m) === 1) {
        $summary = (string) preg_replace('/^ShopInnKart\s*[-–]\s*/u', '', trim($m[1]));
    }

    $methods = [];
    if (preg_match('/api_require_method\(\s*\[(.*?)\]/s', $src, $m) === 1) {
        preg_match_all("/'([A-Za-z]+)'/", $m[1], $found);
        $methods = array_map('strtoupper', $found[1]);
    } elseif (preg_match("/api_require_method\(\s*'([A-Za-z]+)'/", $src, $m) === 1) {
        $methods = [strtoupper($m[1])];
    }

    $required = [];
    if (preg_match_all("/->required\(\s*'([A-Za-z0-9_]+)'/", $src, $found) > 0) {
        $required = array_values(array_unique($found[1]));
    }

    $optional = [];
    if (preg_match_all("/request_(?:input|int|float|bool|array)\(\s*'([A-Za-z0-9_]+)'/", $src, $found) > 0) {
        $optional = array_values(array_diff(array_unique($found[1]), $required, [CSRF_TOKEN_NAME]));
    }

    $rateLimit = '';
    if (preg_match("/api_rate_limit\(\s*'[A-Za-z0-9_]+'\s*,\s*(\d+)\s*,\s*(\d+)/", $src, $m) === 1) {
        $rateLimit = $m[1] . ' / ' . api_index_window((int) $m[2]);
    }

    return [
        'summary'    => $summary,
        // No api_require_method() call means the file accepts any verb.
        'methods'    => $methods === [] ? ['ANY'] : $methods,
        'required'   => $required,
        'optional'   => $optional,
        'csrf'       => strpos($src, 'api_require_csrf(') !== false,
        'login'      => strpos($src, 'api_require_login(') !== false,
        'admin'      => strpos($src, 'api_require_admin(') !== false,
        'rate_limit' => $rateLimit,
    ];
}

/** Preferred group order; anything else is appended alphabetically. */
$groupMeta = [
    'auth'       => 'Sign in, registration, sign out and password recovery.',
    'products'   => 'Catalogue listing, search, product detail and stock alerts.',
    'categories' => 'Category tree and category lookups.',
    'brands'     => 'Brand lookups.',
    'cart'       => 'Cart lines, quantities and live totals.',
    'wishlist'   => 'Saved products for signed-in customers.',
    'compare'    => 'The compare tray.',
    'coupons'    => 'Coupon validation and removal.',
    'checkout'   => 'Recalculated totals and order placement.',
    'orders'     => 'Order tracking, cancellation and reorder.',
    'account'    => 'Profile details, the address book and shopping preferences.',
    'notifications' => 'The in-app feed behind the header bell, and its unread badge.',
    'delivery'   => 'PIN code serviceability and delivery estimates.',
    'reviews'    => 'Customer product reviews.',
    'newsletter' => 'Newsletter subscriptions.',
    'contact'    => 'Contact form submissions.',
    'widgets'    => 'Lazy-loaded homepage widgets and popup tracking.',
];

$files = glob(__DIR__ . '/*/*.php') ?: [];
sort($files);

$groups = [];
foreach ($files as $file) {
    $group = basename(dirname($file));
    $name = basename($file);

    // Shared helpers (api/includes/..., _partial.php) are not routes.
    if ($group === 'includes' || $group[0] === '_' || $name[0] === '_') {
        continue;
    }

    $groups[$group][$name] = api_index_describe($file);
}

// Documented groups first, then any folder a later developer added.
$ordered = [];
foreach (array_keys($groupMeta) as $key) {
    if (isset($groups[$key])) {
        $ordered[$key] = $groups[$key];
    }
}
foreach ($groups as $key => $endpoints) {
    if (!isset($ordered[$key])) {
        $ordered[$key] = $endpoints;
    }
}

$endpointCount = array_sum(array_map('count', $ordered));

$statusCodes = [
    '200 OK'                   => 'Request handled; success is true.',
    '201 Created'              => 'A row was created - a review, subscriber, message or order.',
    '400 Bad Request'          => 'The request was understood but could not be completed.',
    '401 Unauthorized'         => 'Sign-in required: the customer is signed out or the session expired.',
    '403 Forbidden'            => 'Signed in, but not allowed to perform this action.',
    '404 Not Found'            => 'The product, order or record does not exist or is no longer visible.',
    '405 Method Not Allowed'   => 'Wrong HTTP verb. The Allow header lists the accepted ones.',
    '409 Conflict'             => 'The action clashes with existing data, such as a second review.',
    '422 Unprocessable Entity' => 'Validation failed; errors is keyed by field name.',
    '429 Too Many Requests'    => 'Rate limit reached. Retry after the Retry-After header.',
];

seo_set([
    'title'       => 'API Reference',
    'description' => 'Developer reference for the ShopInnKart storefront API.',
    'robots'      => 'noindex, nofollow',
    'canonical'   => api_url(''),
    'og_type'     => 'website',
]);

require INCLUDES_PATH . '/header.php';
?>

<div class="sik-container sik-section">

    <?= breadcrumbs([
        ['label' => 'Home', 'url' => url()],
        ['label' => 'API reference'],
    ]) ?>

    <div class="sik-heading sik-heading--row" style="margin-top:16px">
        <div>
            <h1 class="sik-heading__title">API <span class="sik-heading__accent">Reference</span></h1>
            <p class="sik-heading__sub" style="margin:10px 0 0">
                Every REST endpoint the storefront calls, with its method, its guards and the
                parameters it reads.
            </p>
        </div>
    </div>

    <div class="sik-alert sik-alert--info" style="margin-bottom:24px">
        <?= icon('info', 'w-5 h-5') ?>
        <div>
            <strong>Documentation only.</strong>
            This page returns no store data. The list below is built by reading the endpoint files
            in <code>/api</code>, so it stays true to the code without anyone maintaining it by hand.
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2" style="margin-bottom:24px">

        <div class="sik-panel">
            <div class="sik-panel__head">
                <h2 class="sik-panel__title">Calling convention</h2>
            </div>
            <div class="sik-panel__body">
                <ul class="sik-prose" style="margin:0 0 0 18px;list-style:disc">
                    <li>Base URL <code style="word-break:break-all"><?= e(BASE_PATH . '/api/') ?></code></li>
                    <li>Reads take <strong>GET</strong> query parameters. Writes take <strong>POST</strong>
                        with a JSON body (<code>Content-Type: application/json</code>) or ordinary form fields.</li>
                    <li>Every write must carry the CSRF token, either as the
                        <code><?= e(CSRF_HEADER_NAME) ?></code> header or a
                        <code><?= e(CSRF_TOKEN_NAME) ?></code> field. A stale token answers
                        <strong>403</strong> with <code>code: "csrf"</code>.</li>
                    <li>From the browser use <code>SIK.get(path, params)</code> and
                        <code>SIK.post(path, body)</code> - both attach the token and parse the envelope.</li>
                    <li>Responses are never cached and always <code>application/json; charset=utf-8</code>.</li>
                </ul>
            </div>
        </div>

        <div class="sik-panel">
            <div class="sik-panel__head">
                <h2 class="sik-panel__title">Response envelope</h2>
            </div>
            <div class="sik-panel__body">
                <p class="text-muted text-sm" style="margin-bottom:10px">
                    Success and failure share one shape, so the client checks <code>success</code> first.
                </p>
                <div class="sik-scroll-x">
<pre style="font-size:12.5px;line-height:1.6;background:var(--sik-soft);border:1px solid var(--sik-border);border-radius:var(--sik-radius-sm);padding:14px"><code>{ "success": true,  "message": "Done",     "data":   { } }
{ "success": false, "message": "Please...", "errors": { "email": "Enter a valid email address." } }</code></pre>
                </div>
                <p class="text-muted text-sm" style="margin-top:12px">
                    <code>data</code> is present on success, <code>errors</code> on failure. Both are
                    objects, never null.
                </p>
            </div>
        </div>
    </div>

    <?php if ($ordered === []): ?>
        <div class="sik-empty">
            <?= icon('package', 'w-16 h-16') ?>
            <h2 class="sik-empty__title">No endpoints are installed yet</h2>
            <p class="sik-empty__text">
                Endpoint files live in <code>/api/&lt;group&gt;/&lt;action&gt;.php</code>. Add one and it
                appears here automatically.
            </p>
            <a class="sik-btn sik-btn--primary" href="<?= e(url()) ?>">Back to the store</a>
        </div>
    <?php else: ?>

        <div class="sik-panel" style="margin-bottom:24px">
            <div class="sik-panel__head">
                <h2 class="sik-panel__title">Status codes</h2>
                <span class="sik-badge sik-badge--soft"><?= (int) $endpointCount ?> endpoints</span>
            </div>
            <div class="sik-panel__body" style="padding:0">
                <div class="sik-scroll-x">
                    <table class="sik-spectable" style="min-width:480px">
                        <caption class="sik-sr">HTTP status codes returned by the API</caption>
                        <tbody>
                        <?php foreach ($statusCodes as $code => $meaning): ?>
                            <tr>
                                <th scope="row" style="white-space:nowrap"><?= e($code) ?></th>
                                <td><?= e($meaning) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php foreach ($ordered as $group => $endpoints): ?>
            <section class="sik-panel" style="margin-bottom:20px">
                <div class="sik-panel__head">
                    <h2 class="sik-panel__title"><?= e(ucfirst(str_replace('-', ' ', $group))) ?></h2>
                    <span class="sik-badge sik-badge--grey"><?= count($endpoints) ?></span>
                </div>
                <div class="sik-panel__body">
                    <?php if (isset($groupMeta[$group])): ?>
                        <p class="text-muted text-sm" style="margin-bottom:14px"><?= e($groupMeta[$group]) ?></p>
                    <?php endif; ?>

                    <div class="grid gap-3">
                        <?php foreach ($endpoints as $fileName => $doc): ?>
                            <article class="border border-line rounded-lg bg-soft" style="padding:14px 16px">

                                <div class="flex items-center flex-wrap gap-2">
                                    <?php foreach ($doc['methods'] as $method): ?>
                                        <?php $methodClass = ['GET' => 'sik-badge--green', 'ANY' => 'sik-badge--grey'][$method] ?? 'sik-badge--navy'; ?>
                                        <span class="sik-badge <?= e($methodClass) ?>"><?= e($method) ?></span>
                                    <?php endforeach; ?>
                                    <code style="font-size:13px;word-break:break-all"><?= e(BASE_PATH . '/api/' . $group . '/' . $fileName) ?></code>
                                </div>

                                <?php if ($doc['summary'] !== ''): ?>
                                    <p class="text-sm" style="margin-top:8px"><?= e($doc['summary']) ?></p>
                                <?php endif; ?>

                                <dl class="text-sm" style="margin-top:10px;display:grid;gap:6px">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <dt class="text-muted" style="min-width:78px">Required</dt>
                                        <dd class="flex flex-wrap gap-1" style="margin:0">
                                            <?php if ($doc['required'] === []): ?>
                                                <span class="text-muted">none</span>
                                            <?php else: ?>
                                                <?php foreach ($doc['required'] as $param): ?>
                                                    <code style="font-size:12.5px"><?= e($param) ?></code>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </dd>
                                    </div>

                                    <?php if ($doc['optional'] !== []): ?>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <dt class="text-muted" style="min-width:78px">Optional</dt>
                                            <dd class="flex flex-wrap gap-1" style="margin:0">
                                                <?php foreach ($doc['optional'] as $param): ?>
                                                    <code style="font-size:12.5px"><?= e($param) ?></code>
                                                <?php endforeach; ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <dt class="text-muted" style="min-width:78px">Access</dt>
                                        <dd class="flex flex-wrap gap-1" style="margin:0">
                                            <?php if ($doc['admin']): ?>
                                                <span class="sik-status sik-status--red">Admin session</span>
                                            <?php elseif ($doc['login']): ?>
                                                <span class="sik-status sik-status--blue">Customer sign-in</span>
                                            <?php else: ?>
                                                <span class="sik-status sik-status--gray">Public</span>
                                            <?php endif; ?>

                                            <?php if ($doc['csrf']): ?>
                                                <span class="sik-status sik-status--violet">CSRF token</span>
                                            <?php endif; ?>

                                            <?php if ($doc['rate_limit'] !== ''): ?>
                                                <span class="sik-status sik-status--amber">Rate limit <?= e($doc['rate_limit']) ?></span>
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                </dl>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <p class="text-muted text-sm" style="margin-top:8px">
            Parameter names are lifted from each file's validator rules and request reads, so an endpoint
            that accepts something undocumented here is an endpoint that never reads it.
        </p>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
