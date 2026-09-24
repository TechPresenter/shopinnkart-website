<?php
/**
 * ShopInnKart - is this deployment finished? (CLI)
 *
 * The one thing nothing else answered. install.php checked the server's
 * requirements, but it is deleted the moment a store goes live - and it
 * refuses to run on a populated database anyway. bin/security-selftest.php
 * checks the locks, but only once the app already works. Between "the files
 * are uploaded" and "the app already works" there was nothing, and that is
 * exactly the gap an owner gets stuck in: a white page, or "Access denied for
 * user 'root'@'localhost'", and no way to find out which of nine things is
 * wrong.
 *
 * So this runs from the top. It never stops at the first failure, because the
 * point is to hand over the WHOLE list of what is left rather than one item
 * at a time, and it is safe to run as often as you like: it reads, it never
 * writes.
 *
 * Run it over SSH from the project root, right after uploading:
 *
 *     /usr/bin/php bin/deploy-check.php
 *
 * Exit 0 means the store is ready to open. Exit 1 means something on the list
 * still has to be done, so it can go on a cron if you want to be told when
 * somebody changes something underneath you.
 *
 *     --quiet   print only what is wrong
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$quiet = in_array('--quiet', $argv ?? [], true);
$root  = dirname(__DIR__);

// ---------------------------------------------------------------------------
//  Boot first, print second.
//
//  includes/init.php starts a session, and a session cannot be named once
//  anything has been sent - so loading the app after the first line of output
//  fails for a reason that has nothing to do with the deployment. Nothing
//  below this point depends on the order, because the server and folder checks
//  do not need the app at all; only the reporting does.
//
//  Output from the boot itself is swallowed: a half-configured install can
//  print a warning here, and that belongs in the list rather than above it.
// ---------------------------------------------------------------------------
$booted    = false;
$bootError = '';
ob_start();
try {
    require_once $root . '/includes/init.php';
    $booted = true;
} catch (Throwable $e) {
    $bootError = $e->getMessage();
}
$bootNoise = trim((string) ob_get_clean());

// ---------------------------------------------------------------------------
//  Reporting
// ---------------------------------------------------------------------------

/** @var list<array{level:string,title:string,detail:string,fix:string}> */
$findings = [];
$okCount  = 0;

$ok = static function (string $title, string $detail = '') use (&$okCount, $quiet): void {
    $okCount++;
    if (!$quiet) {
        printf("  ok    %s%s\n", $title, $detail === '' ? '' : '  (' . $detail . ')');
    }
};

/** Something the store cannot open without. */
$stop = static function (string $title, string $detail, string $fix) use (&$findings): void {
    $findings[] = ['level' => 'STOP', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

/** Something that must be done before customers arrive, but not before the site loads. */
$todo = static function (string $title, string $detail, string $fix) use (&$findings): void {
    $findings[] = ['level' => 'TODO', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

/** Worth knowing. Not in the way. */
$note = static function (string $title, string $detail, string $fix = '') use (&$findings): void {
    $findings[] = ['level' => 'note', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

$heading = static function (string $text) use ($quiet): void {
    if (!$quiet) {
        printf("\n%s\n", $text);
    }
};

echo "ShopInnKart deployment check\n";
echo str_repeat('-', 62), "\n";

// ===========================================================================
//  1. The server itself - before anything is loaded, so a missing extension
//     reports as a missing extension rather than as a fatal error.
// ===========================================================================

$heading('The server');

if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    $ok('PHP ' . PHP_VERSION);
} else {
    $stop('PHP is too old', 'Found ' . PHP_VERSION . '; this needs 8.0 or newer.',
        'Change the PHP version in hPanel > Advanced > PHP Configuration.');
}

foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session'] as $extension) {
    if (extension_loaded($extension)) {
        $ok('Extension ' . $extension);
    } else {
        $stop('Extension ' . $extension . ' is missing',
            'The store cannot run without it.',
            'Enable it in hPanel > Advanced > PHP Configuration > PHP extensions.');
    }
}

foreach (['openssl' => 'encrypting backups, privacy exports and stored SMTP passwords',
          'gd'      => 'resizing uploaded product images',
          'zip'     => 'packaging a privacy export as a .zip rather than a bare .json',
          'curl'    => 'talking to couriers and payment gateways'] as $extension => $why) {
    if (extension_loaded($extension)) {
        $ok('Extension ' . $extension);
    } else {
        $todo('Extension ' . $extension . ' is missing', 'Needed for ' . $why . '.',
            'Enable it in hPanel > Advanced > PHP Configuration > PHP extensions.');
    }
}

// ---------------------------------------------------------------------------
//  2. Folders the app writes to
// ---------------------------------------------------------------------------

$heading('Folders');

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/invoices', 'uploads', 'config'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_dir($path)) {
        $stop('/' . $rel . ' does not exist', 'The app writes here.',
            'Create it and make it writable (755, or 775 if the host insists).');
        continue;
    }
    if (is_writable($path)) {
        $ok('/' . $rel . ' is writable');
    } else {
        $stop('/' . $rel . ' is not writable',
            $rel === 'config'
                ? 'Without this the app cannot create its encryption key, so SMTP and courier passwords will not save.'
                : 'Uploads, logs and invoices are written here.',
            'chmod 755 ' . $rel . '   (or 775 if the host insists)');
    }
}

// ---------------------------------------------------------------------------
//  3. The database credentials - THE most common first failure
// ---------------------------------------------------------------------------

$heading('Database');

$localDb = $root . '/config/db.local.php';
if (!is_file($localDb)) {
    $stop('config/db.local.php is missing',
        'It is deliberately not in Git, so a `git pull` never brings it. Without it the app falls '
        . 'back to the user "root" with no password, and every visitor sees '
        . '"Access denied for user \'root\'@\'localhost\'".',
        "Create config/db.local.php on the server:\n"
        . "            <?php return ['host' => 'localhost', 'name' => 'DB', 'user' => 'USER', 'pass' => 'PASS'];");
} else {
    $ok('config/db.local.php is present');
}

if (!$booted) {
    $stop('The app could not start', $bootError,
        'Fix whatever is named above, then run this again.');
} elseif ($bootNoise !== '') {
    $note('The app printed something while starting', mb_substr($bootNoise, 0, 300),
        'Harmless to this check, but a visitor would see it too.');
}

if ($booted) {
    try {
        Database::connect();
        $ok('Connected to the database', DB_NAME);
    } catch (Throwable $e) {
        $stop('The database refused the connection', $e->getMessage(),
            'Check the four values in config/db.local.php, and that the user is attached to the '
            . 'database in hPanel > Databases.');
    }
}

// ===========================================================================
//  Everything below needs a working database, so it is skipped without one.
// ===========================================================================

if ($booted && Database::isAvailable()) {
    // -----------------------------------------------------------------------
    //  4. Is the data actually imported, and is the schema current?
    // -----------------------------------------------------------------------

    $heading('Data');

    $tables = [];
    try {
        $tables = Database::fetchColumnAll("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'");
    } catch (Throwable $e) {
        $stop('The tables could not be listed', $e->getMessage(), 'Import database/shopinnkart-import.sql.');
    }

    if (count($tables) < 20) {
        $stop('The database is empty or half-imported',
            count($tables) . ' table(s) found; a complete store has well over a hundred.',
            'Import database/shopinnkart-import.sql through phpMyAdmin. Upload that file by hand - '
            . 'it is git-ignored, so a pull does not bring it either.');
    } else {
        $ok(count($tables) . ' tables present');

        $products = (int) Database::fetchColumn('SELECT COUNT(*) FROM `products`');
        $products > 0
            ? $ok($products . ' products in the catalogue')
            : $todo('The catalogue is empty', 'No product rows.',
                'Re-import, or add products in Catalogue > Products.');

        // A migration that has not been run shows up as a column that is not
        // there, which is a fatal on the screen that needs it rather than a
        // message anywhere. These are the ones added after the first release.
        $expect = [
            'admins'     => ['totp_secret', 'auth_version'],
            'orders'     => ['order_number'],
            'shipments'  => ['selected_by'],
            'seo_404_log' => ['last_seen_at'],
            'an_sessions' => ['id'],
            'security_events' => ['type'],
        ];
        $missing = [];
        foreach ($expect as $table => $columns) {
            if (!in_array($table, $tables, true)) {
                $missing[] = $table . ' (whole table)';
                continue;
            }
            $have = Database::fetchColumnAll(sprintf('SHOW COLUMNS FROM `%s`', $table));
            foreach ($columns as $column) {
                if (!in_array($column, $have, true)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        if ($missing === []) {
            $ok('The schema is up to date');
        } else {
            $stop('The schema is behind the code',
                'Not found: ' . implode(', ', $missing) . '. A screen that needs one of these will '
                . 'fail rather than explain itself.',
                'Run every file in database/migrations/ from the project root, oldest first:'
                . "\n            for f in database/migrations/*.php; do /usr/bin/php \"\$f\"; done"
                . "\n            They are idempotent, so running one twice changes nothing.");
        }
    }

    // -----------------------------------------------------------------------
    //  5. The locks
    // -----------------------------------------------------------------------

    $heading('Locks');

    if (is_file($root . '/install.php')) {
        $todo('install.php is still on the server',
            'It refuses to run on a populated database, but a wizard that can reset administrator #1 '
            . 'has no business in a public folder.',
            'rm install.php');
    } else {
        $ok('install.php has been deleted');
    }

    // On a development copy, development mode is the correct answer, and
    // calling it a blocker would teach whoever runs this here to skim the
    // list. On anything with a real hostname it is the most dangerous thing
    // this script can find.
    $onLocalhost = security_host_is_local((string) parse_url(SITE_URL, PHP_URL_HOST));

    if (!APP_DEBUG) {
        $ok('Production mode');
    } elseif ($onLocalhost) {
        $note('Development mode, on a local address',
            'Correct for a development copy. It would be the most serious thing on this list if '
            . 'this were the live server.',
            'Nothing to do here. Just never copy config/db.local.php up to the host.');
    } else {
        $stop('The site is in DEVELOPMENT mode',
            'Visitors are shown PHP errors, stack traces and the full server path. This is what '
            . 'published the database password on a live site once already.',
            "Remove \"'env' => 'development'\" from config/db.local.php, and make sure no APP_ENV "
            . 'is set to development on the host.');
    }

    require_once INCLUDES_PATH . '/deployment-checks.php';
    $seeded = deployment_seeded_logins_message();
    if ($seeded === null) {
        $ok('No account uses the password printed in the README');
    } else {
        $stop('An account still uses the shipped password', $seeded,
            'Change it in System > Admin Users before the domain is reachable.');
    }

    if (app_key_available()) {
        $ok('The application key can be stored');
    } else {
        $stop('There is nowhere to keep the encryption key',
            'config/ is not writable and SIK_APP_KEY is unset, so SMTP and courier passwords will '
            . 'not save.',
            'Make config/ writable once, load any page, then set it back.');
    }

    // -----------------------------------------------------------------------
    //  6. The outside world
    // -----------------------------------------------------------------------

    $heading('The outside world');

    // -----------------------------------------------------------------------
    //  The address the app thinks it is at.
    //
    //  This is the second thing that goes wrong after a missing db.local.php,
    //  and it is worse, because the site answers 200 and looks merely broken
    //  rather than erroring. The Host allow-list is built from SITE_DOMAIN in
    //  config/config.php, which ships as shopinnkart.com - so on any other
    //  domain an unrecognised Host falls back to THAT address and every
    //  stylesheet, script and image is requested from a domain the owner does
    //  not own. The page renders unstyled and nothing works.
    //
    //  There is no Host header in CLI, so the real domain is read from the
    //  path instead: cPanel and Hostinger both lay a site out under
    //  /home/<user>/domains/<the-domain>/public_html, which names it exactly.
    // -----------------------------------------------------------------------
    $siteHost   = strtolower((string) parse_url(SITE_URL, PHP_URL_HOST));
    $pinned     = SITE_URL_CANONICAL !== '';
    $pathDomain = '';
    if (preg_match('~/domains/([a-z0-9.\-]+)/~i', str_replace('\\', '/', $root), $m) === 1) {
        $pathDomain = strtolower($m[1]);
    }

    if ($pathDomain !== '' && $pathDomain !== $siteHost
        && $pathDomain !== preg_replace('/^www\./', '', $siteHost)) {
        $stop('The site is building its links for the wrong domain',
            'This install is at "' . $pathDomain . '", but the app resolves its own address as '
            . SITE_URL . '. Every stylesheet, script and image is being requested from there, so '
            . 'the page loads with no styling and nothing works.',
            "Add one line to config/db.local.php and reload:\n"
            . "            'url' => 'https://" . $pathDomain . "',\n"
            . '            Use http:// until the certificate is installed.');
    } elseif (!$pinned && $siteHost === strtolower(SITE_DOMAIN)) {
        $todo('The site address is not pinned',
            'Nothing sets it, so the app falls back to SITE_DOMAIN (' . SITE_DOMAIN . ') for any '
            . 'host it does not recognise. On your own domain that means assets are fetched from '
            . 'a domain you do not own.',
            "Add to config/db.local.php:  'url' => 'https://your-domain.com',");
    } else {
        $ok('Site address', SITE_URL . ($pinned ? ' (pinned)' : ''));
    }

    $host = (string) parse_url(SITE_URL, PHP_URL_HOST);
    if (security_host_is_local($host)) {
        $ok('Not applicable on ' . $host, 'local address');
    } elseif (strpos(SITE_URL, 'https://') === 0) {
        $ok('HTTPS', SITE_URL);
    } else {
        $todo('The site is not on HTTPS', 'SITE_URL resolves to ' . SITE_URL . '.',
            'Issue the free certificate in hPanel > SSL, then set Security > Settings > HTTPS to '
            . '"Always redirect".');
    }

    $smtp = trim((string) setting('smtp_host', ''));
    if ($smtp !== '') {
        $ok('SMTP is configured', $smtp);
    } else {
        $todo('No email will be sent',
            'Settings > Email has no SMTP host, so order confirmations, password resets and '
            . 'invoices queue instead of sending. The queue is kept, not lost.',
            'Fill it in from hPanel > Emails, then send yourself a test from that screen.');
    }

    foreach (['mail_from_email' => 'no-reply@shopinnkart.com',
              'admin_notify_email' => 'orders@shopinnkart.com'] as $key => $shipped) {
        $value = trim((string) setting($key, ''));
        if ($value !== '' && $value !== $shipped) {
            continue;
        }
        $todo('Email is being sent from a domain you do not own',
            $key . ' is still "' . $shipped . '". Mail sent from a domain you do not control fails '
            . 'SPF and DKIM at the receiving end, so order confirmations land in spam or are refused.',
            'Set it to an address on your own domain, in Settings > Email.');
        break;
    }

    // Cron leaves fingerprints. No fingerprint after a day of queued mail is
    // the single likeliest reason a live store's customers get no email.
    $pending = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `notification_queue` WHERE `status` = 'pending'");
    $everSent = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM `notification_queue` WHERE `sent_at` IS NOT NULL");
    if ($pending > 0 && $everSent === 0) {
        $todo('Mail is queueing and nothing is sending it',
            $pending . ' message(s) waiting, none ever sent.',
            'Add the every-minute cron: /usr/bin/php ' . $root . '/bin/send-queued-emails.php');
    } elseif ($everSent > 0) {
        $ok('The mail worker has run', $everSent . ' sent');
    } else {
        $note('Nothing has been emailed yet', 'Normal on a store that has taken no orders.',
            'Confirm the every-minute cron is installed before you open.');
    }

    $backups = 0;
    try {
        $backups = (int) Database::fetchColumn('SELECT COUNT(*) FROM `backups`');
    } catch (Throwable $e) {
        // The table arrives with a migration; a missing one is already reported.
    }
    $backups > 0
        ? $ok('A backup has been taken', $backups . ' on record')
        : $todo('There is no backup', 'Nothing has ever been dumped.',
            'Add the nightly cron: 15 3 * * * /usr/bin/php ' . $root . '/bin/backup.php --quiet');

    // -----------------------------------------------------------------------
    //  7. Can the web server be trusted with the folders it was told to hide?
    // -----------------------------------------------------------------------

    $heading('What the web serves');

    $probe = security_exposure_probe();
    if (($probe['checked'] ?? 0) === 0) {
        $note('The exposure check did not run',
            'The site could not reach itself over HTTP at ' . SITE_URL . '. That is normal on a '
            . 'single-worker server while this script holds the only slot.',
            'Open Security > Settings in the browser instead, which runs the same check.');
    } elseif (($probe['exposed'] ?? []) === []) {
        $ok($probe['checked'] . ' sensitive paths all refused over HTTP');
    } else {
        foreach ((array) $probe['exposed'] as $path) {
            $stop('The web server is serving ' . $path,
                'This host is ignoring .htaccess, so config, storage and the database folder are '
                . 'readable by anyone.',
                'Ask the host to enable AllowOverride, or move the project so only public/ is the '
                . 'document root.');
        }
    }
}

// ===========================================================================
//  The list
// ===========================================================================

$stops = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'STOP'));
$todos = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'TODO'));
$notes = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'note'));

echo "\n", str_repeat('=', 62), "\n";

if ($stops === [] && $todos === []) {
    printf("READY. %d check(s) passed and nothing is outstanding.\n", $okCount);
    foreach ($notes as $n) {
        printf("\n  note  %s\n        %s\n", $n['title'], $n['detail']);
    }
    exit(0);
}

printf("%d check(s) passed. %d must be fixed, %d to do before you open.\n",
    $okCount, count($stops), count($todos));

foreach ([['STOP', $stops, 'THE STORE WILL NOT WORK UNTIL THESE ARE DONE'],
          ['TODO', $todos, 'DO THESE BEFORE CUSTOMERS ARRIVE'],
          ['note', $notes, 'WORTH KNOWING']] as [$level, $list, $caption]) {
    if ($list === []) {
        continue;
    }
    printf("\n%s\n%s\n", $caption, str_repeat('-', strlen($caption)));
    foreach ($list as $i => $f) {
        printf("\n%d. %s\n   %s\n", $i + 1, $f['title'], wordwrap($f['detail'], 74, "\n   "));
        if ($f['fix'] !== '') {
            printf("   -> %s\n", wordwrap($f['fix'], 74, "\n      "));
        }
    }
}

echo "\n", str_repeat('=', 62), "\n";
echo "Run this again after each fix. docs/GO-LIVE.md has the full checklist.\n";

exit($stops === [] ? 0 : 1);
