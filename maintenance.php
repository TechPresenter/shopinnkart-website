<?php
/**
 * ShopInnKart - Maintenance mode screen.
 * Rendered by includes/init.php when Admin > Settings > General has
 * maintenance mode switched on. Admins bypass it.
 */

declare(strict_types=1);

/** @var string $message set by init.php */
$message = $message ?? 'We will be back shortly.';
$storeName = defined('SITE_NAME') ? SITE_NAME : 'ShopInnKart';
$logoUrl = defined('ASSET_URL') ? ASSET_URL . '/images/logo/shopinnkart-logo.png' : '';
$supportEmail = function_exists('setting') ? (string) setting('store_email', '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Back soon | <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { --orange: #F4511E; --navy: #0F2143; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--navy);
            color: #fff;
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 24px;
            text-align: center;
        }
        .wrap { max-width: 520px; }
        .logo { height: 46px; margin-bottom: 30px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 22px;
        }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--orange); animation: pulse 1.6s ease infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1 } 50% { opacity: .3 } }
        h1 { font-size: 28px; margin: 0 0 12px; letter-spacing: -.02em; }
        p { color: rgba(255, 255, 255, .74); font-size: 15px; line-height: 1.7; margin: 0 0 26px; }
        a { color: var(--orange); font-weight: 600; }
        @media (prefers-reduced-motion: reduce) { .dot { animation: none } }
    </style>
</head>
<body>
    <div class="wrap">
        <?php if ($logoUrl !== ''): ?>
            <img class="logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="badge"><span class="dot"></span> Scheduled maintenance</div>
        <h1>We&rsquo;ll be back shortly</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($supportEmail !== ''): ?>
            <p style="font-size:14px">
                Need something urgently?
                <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?></a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
