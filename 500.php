<?php
/**
 * ShopInnKart - 500 Internal Server Error
 *
 * IMPORTANT: this file is rendered by the global error handler, which means
 * it may run when the database or the include chain has already failed. It
 * therefore renders standalone HTML and never calls a helper that could
 * throw a second time.
 */

declare(strict_types=1);

if (!headers_sent()) {
    http_response_code(500);
}

$homeUrl = defined('SITE_URL') ? SITE_URL : '/';
$storeName = defined('SITE_NAME') ? SITE_NAME : 'ShopInnKart';
$logoUrl = defined('ASSET_URL') ? ASSET_URL . '/images/logo/shopinnkart-logo.png' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Something went wrong | <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { --orange: #F4511E; --navy: #0F2143; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #F8F7F4;
            color: #111827;
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 24px;
        }
        .box {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 4px 28px rgba(15, 33, 67, .08);
            max-width: 520px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .logo { height: 40px; margin-bottom: 26px; }
        .code { font-size: 58px; font-weight: 800; color: var(--orange); line-height: 1; margin: 0 0 8px; }
        h1 { font-size: 20px; margin: 0 0 10px; color: var(--navy); }
        p { color: #6B7280; font-size: 14.5px; line-height: 1.65; margin: 0 0 24px; }
        .btn {
            display: inline-block;
            background: var(--orange);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 12px 26px;
            border-radius: 9px;
        }
        .btn:hover { background: #D63F10; }
        .btn--ghost { background: transparent; color: var(--navy); border: 1px solid #E5E7EB; margin-left: 8px; }
        .btn--ghost:hover { background: #F3F4F6; }
    </style>
</head>
<body>
    <div class="box">
        <?php if ($logoUrl !== ''): ?>
            <img class="logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <p class="code">500</p>
        <h1>Something went wrong. Please try again.</h1>
        <p>
            We hit an unexpected problem while loading this page. Our team has been notified
            automatically. Nothing is wrong with your account or your order.
        </p>
        <a class="btn" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Back to Home</a>
        <a class="btn btn--ghost" href="javascript:location.reload()">Try Again</a>
    </div>
</body>
</html>
