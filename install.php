<?php
/**
 * ShopInnKart - Installer
 *
 * Standalone on purpose: it must run before the database exists, so it never
 * loads includes/init.php. Once installation succeeds it writes a lock file
 * and refuses to run again.
 *
 * Steps: requirements -> database -> import schema -> admin account -> done.
 */

declare(strict_types=1);

session_start();

const INSTALL_LOCK = __DIR__ . '/storage/installed.lock';
const SCHEMA_FILE  = __DIR__ . '/database/schema.sql';
const CONFIG_FILE  = __DIR__ . '/config/config.php';

// ---------------------------------------------------------------------------
// Already installed? Refuse, unless the lock is deleted deliberately.
// ---------------------------------------------------------------------------
$isLocked = is_file(INSTALL_LOCK);

$step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
$errors = [];
$notices = [];

// ---------------------------------------------------------------------------
// Requirement checks
// ---------------------------------------------------------------------------
function check_requirements(): array
{
    $checks = [];

    $checks[] = [
        'label'    => 'PHP 8.0 or newer',
        'value'    => PHP_VERSION,
        'ok'       => version_compare(PHP_VERSION, '8.0.0', '>='),
        'required' => true,
    ];

    foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session'] as $extension) {
        $checks[] = [
            'label'    => 'Extension: ' . $extension,
            'value'    => extension_loaded($extension) ? 'loaded' : 'missing',
            'ok'       => extension_loaded($extension),
            'required' => true,
        ];
    }

    foreach (['gd', 'openssl'] as $extension) {
        $checks[] = [
            'label'    => 'Extension: ' . $extension . ' (recommended)',
            'value'    => extension_loaded($extension) ? 'loaded' : 'missing',
            'ok'       => extension_loaded($extension),
            'required' => false,
        ];
    }

    foreach ([
        'storage'        => __DIR__ . '/storage',
        'storage/logs'   => __DIR__ . '/storage/logs',
        'storage/cache'  => __DIR__ . '/storage/cache',
        'uploads'        => __DIR__ . '/uploads',
        'config'         => __DIR__ . '/config',
    ] as $label => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $checks[] = [
            'label'    => 'Writable: /' . $label,
            'value'    => is_writable($path) ? 'writable' : 'not writable',
            'ok'       => is_writable($path),
            'required' => true,
        ];
    }

    $checks[] = [
        'label'    => 'Schema file present',
        'value'    => is_file(SCHEMA_FILE) ? 'found' : 'missing',
        'ok'       => is_file(SCHEMA_FILE),
        'required' => true,
    ];

    return $checks;
}

/**
 * Split a .sql dump into individual statements.
 * Quote- and comment-aware, so a semicolon inside a product description does
 * not truncate the statement.
 */
function split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if (!$inString) {
            // Line comment
            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Block comment
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }
        } else {
            if ($char === '\\') {
                // Escaped character: take both and move on.
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $stringChar) {
                $inString = false;
            }
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

// ---------------------------------------------------------------------------
// Step handlers
// ---------------------------------------------------------------------------
if (!$isLocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedStep = (int) ($_POST['step'] ?? 1);

    // ---- Step 2: test the database connection -----------------------------
    if ($postedStep === 2) {
        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $port = trim((string) ($_POST['db_port'] ?? '3306'));
        $name = trim((string) ($_POST['db_name'] ?? 'shopinnkart'));
        $user = trim((string) ($_POST['db_user'] ?? 'root'));
        $pass = (string) ($_POST['db_pass'] ?? '');

        if ($name === '' || $user === '') {
            $errors[] = 'Database name and username are required.';
        } else {
            try {
                // Connect without a database first so we can create it.
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    $user,
                    $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdo->exec(
                    'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '`'
                    . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );

                $_SESSION['install_db'] = compact('host', 'port', 'name', 'user', 'pass');
                header('Location: install.php?step=3');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Could not connect: ' . $e->getMessage();
            }
        }
        $step = 2;
    }

    // ---- Step 3: import the schema ----------------------------------------
    if ($postedStep === 3) {
        $db = $_SESSION['install_db'] ?? null;

        if ($db === null) {
            $errors[] = 'Database details were lost. Please start again.';
            $step = 2;
        } else {
            try {
                $pdo = new PDO(
                    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                    $db['user'],
                    $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                $sql = (string) file_get_contents(SCHEMA_FILE);

                // The dump creates and selects its own database; the installer
                // has already picked one, so drop those statements.
                $statements = array_filter(split_sql($sql), static function (string $statement): bool {
                    return stripos($statement, 'CREATE DATABASE') !== 0
                        && stripos($statement, 'USE ') !== 0;
                });

                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $executed = 0;
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                    $executed++;
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

                $_SESSION['install_imported'] = $executed;
                header('Location: install.php?step=4');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Import failed: ' . $e->getMessage();
                $step = 3;
            }
        }
    }

    // ---- Step 4: create the admin account and finish ----------------------
    if ($postedStep === 4) {
        $db = $_SESSION['install_db'] ?? null;

        $storeName = trim((string) ($_POST['store_name'] ?? 'ShopInnKart'));
        $adminName = trim((string) ($_POST['admin_name'] ?? ''));
        $adminUser = trim((string) ($_POST['admin_username'] ?? ''));
        $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
        $adminPass = (string) ($_POST['admin_password'] ?? '');
        $adminPass2 = (string) ($_POST['admin_password_confirm'] ?? '');
        $keepDemo = !empty($_POST['keep_demo']);

        if ($db === null) {
            $errors[] = 'Database details were lost. Please start again.';
            $step = 2;
        } else {
            if ($adminName === '')                              { $errors[] = 'Enter the administrator name.'; }
            if ($adminUser === '')                              { $errors[] = 'Enter a username.'; }
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
            if (strlen($adminPass) < 8)                         { $errors[] = 'The password must be at least 8 characters.'; }
            if ($adminPass !== $adminPass2)                     { $errors[] = 'The passwords do not match.'; }

            if ($errors === []) {
                try {
                    $pdo = new PDO(
                        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                        $db['user'],
                        $db['pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    // Replace the seeded super admin rather than adding a second one.
                    $pdo->prepare(
                        'UPDATE `admins` SET `name` = ?, `username` = ?, `email` = ?, `password` = ?
                         WHERE `id` = 1'
                    )->execute([
                        $adminName,
                        $adminUser,
                        $adminEmail,
                        password_hash($adminPass, PASSWORD_DEFAULT),
                    ]);

                    $pdo->prepare('UPDATE `settings` SET `setting_value` = ? WHERE `setting_key` = ?')
                        ->execute([$storeName, 'store_name']);
                    $pdo->prepare('UPDATE `settings` SET `setting_value` = ? WHERE `setting_key` = ?')
                        ->execute([$adminEmail, 'store_email']);

                    if (!$keepDemo) {
                        // Clear the demo catalogue and transactions, keep the
                        // structural rows (settings, menus, widgets, pages).
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                        foreach ([
                            'order_status_history', 'payment_transactions', 'payments', 'order_items', 'orders',
                            'cart_items', 'carts', 'wishlist_items', 'wishlists', 'compare_items',
                            'recently_viewed', 'reviews', 'review_images', 'stock_movements', 'stock_alerts',
                            'coupon_usage', 'product_relations', 'product_tags', 'product_videos',
                            'product_variant_attributes', 'product_variants', 'product_features',
                            'product_specifications', 'product_images', 'products',
                            'deal_products', 'deals', 'flash_sale_products', 'flash_sales',
                            'newsletter_subscribers', 'contact_messages', 'search_logs',
                            'user_addresses', 'users', 'blog_posts', 'testimonials',
                        ] as $table) {
                            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
                        }
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    }

                    // Persist the credentials the app will use.
                    write_db_config($db);

                    @file_put_contents(INSTALL_LOCK, json_encode([
                        'installed_at' => date('c'),
                        'php'          => PHP_VERSION,
                        'demo_data'    => $keepDemo,
                    ], JSON_PRETTY_PRINT));

                    unset($_SESSION['install_db']);
                    $_SESSION['install_done'] = ['email' => $adminEmail, 'demo' => $keepDemo];

                    header('Location: install.php?step=5');
                    exit;
                } catch (PDOException $e) {
                    $errors[] = 'Setup failed: ' . $e->getMessage();
                }
            }
            $step = 4;
        }
    }
}

/**
 * Write the chosen credentials into config/db.local.php, which config.php
 * picks up. Editing config.php itself would be overwritten on upgrade.
 */
function write_db_config(array $db): bool
{
    $contents = "<?php\n"
        . "/**\n"
        . " * ShopInnKart - Local database credentials.\n"
        . " * Written by install.php. Keep this file out of version control.\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'host' => " . var_export($db['host'], true) . ",\n"
        . "    'port' => " . var_export($db['port'], true) . ",\n"
        . "    'name' => " . var_export($db['name'], true) . ",\n"
        . "    'user' => " . var_export($db['user'], true) . ",\n"
        . "    'pass' => " . var_export($db['pass'], true) . ",\n"
        . "];\n";

    return @file_put_contents(__DIR__ . '/config/db.local.php', $contents) !== false;
}

$requirements = check_requirements();
$requirementsPassed = !in_array(false, array_map(
    static fn ($check) => $check['required'] ? $check['ok'] : true,
    $requirements
), true);

$steps = [
    1 => 'Requirements',
    2 => 'Database',
    3 => 'Install',
    4 => 'Administrator',
    5 => 'Done',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Install ShopInnKart</title>
    <style>
        :root { --orange:#F4511E; --navy:#0F2143; --line:#E5E7EB; --muted:#6B7280; }
        * { box-sizing:border-box; }
        body {
            margin:0; padding:28px 16px; min-height:100vh;
            font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            background:radial-gradient(1000px 400px at 20% -10%, rgba(244,81,30,.14), transparent 60%), #0F2143;
            color:#111827;
        }
        .wrap { max-width:720px; margin:0 auto; }
        .card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.28); overflow:hidden; }
        .head { padding:26px 30px 20px; border-bottom:1px solid var(--line); text-align:center; }
        .head h1 { margin:0 0 4px; font-size:21px; }
        .head p { margin:0; color:var(--muted); font-size:14px; }
        .logo { font-size:24px; font-weight:800; letter-spacing:-.02em; margin-bottom:14px; }
        .logo span { color:var(--orange); }
        .steps { display:flex; gap:4px; padding:16px 30px; background:#F8F7F4; border-bottom:1px solid var(--line); overflow-x:auto; }
        .stepitem { flex:1; text-align:center; font-size:11.5px; font-weight:600; color:var(--muted); white-space:nowrap; }
        .stepitem b { display:block; width:26px; height:26px; line-height:26px; border-radius:50%; background:#E5E7EB; color:var(--muted); margin:0 auto 5px; font-size:12px; }
        .stepitem.is-active b { background:var(--orange); color:#fff; }
        .stepitem.is-done b { background:#16A34A; color:#fff; }
        .stepitem.is-active { color:var(--orange); }
        .body { padding:28px 30px; }
        table.req { width:100%; border-collapse:collapse; font-size:13.5px; }
        table.req td { padding:9px 4px; border-bottom:1px solid var(--line); }
        table.req td:last-child { text-align:right; font-weight:600; }
        .ok { color:#16A34A; } .bad { color:#DC2626; } .warn { color:#D97706; }
        label { display:block; font-size:13px; font-weight:600; margin:0 0 6px; }
        input[type=text], input[type=password], input[type=email], input[type=number] {
            width:100%; padding:11px 13px; border:1px solid var(--line); border-radius:9px;
            font-size:14px; font-family:inherit; outline:none;
        }
        input:focus { border-color:var(--orange); box-shadow:0 0 0 3px rgba(244,81,30,.12); }
        .field { margin-bottom:15px; }
        .row { display:grid; gap:14px; grid-template-columns:1fr; }
        @media (min-width:620px){ .row-2 { grid-template-columns:1fr 1fr; } }
        .btn {
            display:inline-flex; align-items:center; justify-content:center; gap:7px;
            background:var(--orange); color:#fff; border:0; border-radius:9px;
            padding:13px 24px; font-size:14.5px; font-weight:600; cursor:pointer;
            text-decoration:none; font-family:inherit;
        }
        .btn:hover { background:#D63F10; }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .btn--ghost { background:#fff; color:var(--navy); border:1px solid var(--line); }
        .alert { padding:12px 15px; border-radius:9px; font-size:13.5px; margin-bottom:16px; border:1px solid; }
        .alert--error { background:#FEF2F2; border-color:#FECACA; color:#991B1B; }
        .alert--info { background:#EFF6FF; border-color:#BFDBFE; color:#1E40AF; }
        .alert--success { background:#F0FDF4; border-color:#BBF7D0; color:#166534; }
        .alert ul { margin:6px 0 0 18px; padding:0; }
        .check { display:flex; gap:9px; align-items:flex-start; font-size:13.5px; margin-top:14px; }
        .muted { color:var(--muted); font-size:13px; line-height:1.65; }
        code { background:#F3F4F6; padding:2px 6px; border-radius:4px; font-size:12.5px; }
        .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:22px; flex-wrap:wrap; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div class="logo">Shop<span>InnKart</span></div>
            <h1>Installation</h1>
            <p>Set up your electronics store in a few steps.</p>
        </div>

        <?php if ($isLocked): ?>
            <div class="body">
                <div class="alert alert--success">
                    <strong>ShopInnKart is already installed.</strong>
                    The installer is locked to stop anyone re-running it and wiping your data.
                </div>
                <p class="muted">
                    If you genuinely need to reinstall, delete
                    <code>storage/installed.lock</code> from the server first. For security, you should
                    also delete <code>install.php</code> once your store is live.
                </p>
                <div class="actions">
                    <a class="btn btn--ghost" href="index.php">View Storefront</a>
                    <a class="btn" href="admin/login.php">Admin Login</a>
                </div>
            </div>

        <?php else: ?>
            <div class="steps">
                <?php foreach ($steps as $number => $label): ?>
                    <div class="stepitem <?= $number === $step ? 'is-active' : ($number < $step ? 'is-done' : '') ?>">
                        <b><?= $number < $step ? '&check;' : $number ?></b><?= htmlspecialchars($label, ENT_QUOTES) ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="body">
                <?php if ($errors !== []): ?>
                    <div class="alert alert--error">
                        <strong>Please fix the following:</strong>
                        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <h2 style="font-size:16px;margin:0 0 4px">Server requirements</h2>
                    <p class="muted" style="margin:0 0 16px">
                        Everything marked required must pass before you can continue.
                    </p>
                    <table class="req">
                        <?php foreach ($requirements as $check): ?>
                            <tr>
                                <td><?= htmlspecialchars($check['label'], ENT_QUOTES) ?></td>
                                <td class="<?= $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') ?>">
                                    <?= $check['ok'] ? '&check; ' : '&times; ' ?><?= htmlspecialchars($check['value'], ENT_QUOTES) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>

                    <div class="actions">
                        <?php if ($requirementsPassed): ?>
                            <a class="btn" href="install.php?step=2">Continue &rarr;</a>
                        <?php else: ?>
                            <button class="btn" disabled>Fix the failures above</button>
                            <a class="btn btn--ghost" href="install.php?step=1">Re-check</a>
                        <?php endif; ?>
                    </div>

                <?php elseif ($step === 2): ?>
                    <h2 style="font-size:16px;margin:0 0 4px">Database connection</h2>
                    <p class="muted" style="margin:0 0 16px">
                        The database is created automatically if it does not exist yet.
                    </p>
                    <form method="post" action="install.php?step=2">
                        <input type="hidden" name="step" value="2">
                        <div class="row row-2">
                            <div class="field">
                                <label for="db_host">Host</label>
                                <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars((string) ($_POST['db_host'] ?? 'localhost'), ENT_QUOTES) ?>" required>
                            </div>
                            <div class="field">
                                <label for="db_port">Port</label>
                                <input type="text" id="db_port" name="db_port" value="<?= htmlspecialchars((string) ($_POST['db_port'] ?? '3306'), ENT_QUOTES) ?>" required>
                            </div>
                        </div>
                        <div class="field">
                            <label for="db_name">Database name</label>
                            <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars((string) ($_POST['db_name'] ?? 'shopinnkart'), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="row row-2">
                            <div class="field">
                                <label for="db_user">Username</label>
                                <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars((string) ($_POST['db_user'] ?? 'root'), ENT_QUOTES) ?>" required>
                            </div>
                            <div class="field">
                                <label for="db_pass">Password</label>
                                <input type="password" id="db_pass" name="db_pass" value="" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="actions">
                            <a class="btn btn--ghost" href="install.php?step=1">&larr; Back</a>
                            <button class="btn" type="submit">Test &amp; Continue &rarr;</button>
                        </div>
                    </form>

                <?php elseif ($step === 3): ?>
                    <h2 style="font-size:16px;margin:0 0 4px">Create the tables</h2>
                    <p class="muted" style="margin:0 0 16px">
                        This imports <code>database/schema.sql</code>: 78 tables plus demo catalogue
                        data you can keep or clear in the next step.
                    </p>
                    <div class="alert alert--info">
                        <strong>This replaces any existing ShopInnKart tables</strong> in
                        <code><?= htmlspecialchars((string) ($_SESSION['install_db']['name'] ?? ''), ENT_QUOTES) ?></code>.
                        Make sure you picked an empty or disposable database.
                    </div>
                    <form method="post" action="install.php?step=3">
                        <input type="hidden" name="step" value="3">
                        <div class="actions">
                            <a class="btn btn--ghost" href="install.php?step=2">&larr; Back</a>
                            <button class="btn" type="submit">Import Schema &rarr;</button>
                        </div>
                    </form>

                <?php elseif ($step === 4): ?>
                    <h2 style="font-size:16px;margin:0 0 4px">Administrator account</h2>
                    <p class="muted" style="margin:0 0 16px">
                        <?= (int) ($_SESSION['install_imported'] ?? 0) ?> SQL statements ran successfully.
                        Now set up the account you will sign in with.
                    </p>
                    <form method="post" action="install.php?step=4">
                        <input type="hidden" name="step" value="4">
                        <div class="field">
                            <label for="store_name">Store name</label>
                            <input type="text" id="store_name" name="store_name" value="<?= htmlspecialchars((string) ($_POST['store_name'] ?? 'ShopInnKart'), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="row row-2">
                            <div class="field">
                                <label for="admin_name">Your name</label>
                                <input type="text" id="admin_name" name="admin_name" value="<?= htmlspecialchars((string) ($_POST['admin_name'] ?? ''), ENT_QUOTES) ?>" required>
                            </div>
                            <div class="field">
                                <label for="admin_username">Username</label>
                                <input type="text" id="admin_username" name="admin_username" value="<?= htmlspecialchars((string) ($_POST['admin_username'] ?? 'admin'), ENT_QUOTES) ?>" required>
                            </div>
                        </div>
                        <div class="field">
                            <label for="admin_email">Email address</label>
                            <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars((string) ($_POST['admin_email'] ?? ''), ENT_QUOTES) ?>" required>
                        </div>
                        <div class="row row-2">
                            <div class="field">
                                <label for="admin_password">Password</label>
                                <input type="password" id="admin_password" name="admin_password" minlength="8" required autocomplete="new-password">
                            </div>
                            <div class="field">
                                <label for="admin_password_confirm">Confirm password</label>
                                <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="8" required autocomplete="new-password">
                            </div>
                        </div>

                        <label class="check">
                            <input type="checkbox" name="keep_demo" value="1" <?= empty($_POST) || !empty($_POST['keep_demo']) ? 'checked' : '' ?>>
                            <span>
                                <strong>Keep the demo catalogue</strong><br>
                                <span class="muted">
                                    44 products, categories, brands, banners, coupons and sample orders so you
                                    can explore the admin straight away. Uncheck to start with an empty store
                                    (settings, menus, pages and homepage layout are kept either way).
                                </span>
                            </span>
                        </label>

                        <div class="actions">
                            <button class="btn" type="submit">Finish Installation &rarr;</button>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="alert alert--success">
                        <strong>ShopInnKart is installed.</strong> Your store is ready.
                    </div>

                    <p class="muted">
                        Sign in at <code>/admin</code> with
                        <strong><?= htmlspecialchars((string) ($_SESSION['install_done']['email'] ?? ''), ENT_QUOTES) ?></strong>
                        and the password you just chose.
                    </p>

                    <div class="alert alert--error" style="margin-top:16px">
                        <strong>Two things to do right now:</strong>
                        <ul>
                            <li>Delete <code>install.php</code> from the server.</li>
                            <li>Make sure <code>config/db.local.php</code> is not readable over the web.</li>
                        </ul>
                    </div>

                    <div class="actions">
                        <a class="btn btn--ghost" href="index.php">View Storefront</a>
                        <a class="btn" href="admin/login.php">Go to Admin &rarr;</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <p style="text-align:center;color:rgba(255,255,255,.5);font-size:12.5px;margin-top:20px">
        ShopInnKart &middot; Core PHP + MySQL &middot; no framework required
    </p>
</div>
</body>
</html>
