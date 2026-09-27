<?php
/**
 * ShopInnKart - is this deployment finished? (CLI)
 *
 * The gap nothing else covered. install.php checked the server's requirements,
 * but it is deleted the moment a store goes live and refuses to run on a
 * populated database anyway. bin/security-selftest.php checks the locks, but
 * only once the app already works. Between "the files are uploaded" and "the
 * app already works" there was nothing - so a white page, or "Access denied
 * for user 'root'@'localhost'", came with no way to find out which of a dozen
 * things was wrong.
 *
 * Run it over SSH from the project root, as often as you like:
 *
 *     /usr/bin/php bin/deploy-check.php
 *
 * It never stops at the first failure: the point is to hand over the whole
 * list rather than one item per SSH session.
 *
 * ---------------------------------------------------------------------------
 * THE RULE THIS FILE IS BUILT ON: NEVER GUESS, AND NEVER GUESS IN THE
 * REASSURING DIRECTION
 * ---------------------------------------------------------------------------
 * The first version of this script was reviewed by agents whose only job was
 * to make it lie, and it lied twenty-six different ways. Every one of them was
 * the same mistake: it measured something adjacent to the question and
 * reported the answer as if it had measured the question.
 *
 *   - It probed https://shopinnkart.com - the vendor's own hardened domain -
 *     because under CLI there is no Host header and SITE_URL falls back to the
 *     SITE_DOMAIN constant. It then reported "13 sensitive paths all refused"
 *     about a server the owner does not run. On a host with AllowOverride off,
 *     where the owner's own config/db.local.php really was being served, it
 *     printed that line and exited 0.
 *   - It read PHP_VERSION and extension_loaded() from the SSH binary. On
 *     Hostinger the web PHP is a different binary with a different php.ini, so
 *     "ok PHP 8.2" could be true while the website ran 7.4.
 *   - It called app_key_available(), which MINTS the key if it is missing. So
 *     the script that promised "it reads, it never writes" created the
 *     installation's master encryption key as a side effect, then reported it
 *     as present - and if it ran before the owner copied their real key up,
 *     every secret in the imported dump became unreadable.
 *   - It inferred a working cron from data that travels inside the imported
 *     dump, so a laptop's mail history vouched for a live server's schedule.
 *
 * So: anything that cannot be measured here is reported as not measured, and a
 * check that could not run is never a pass. Where the answer can only come
 * from the web server, this asks the web server.
 *
 * WHAT IT WRITES. Two things, both temporary, both removed before it exits:
 * a probe file under the document root (random name, deleted in a finally),
 * which is how it proves it is talking to THIS server and how it asks the web
 * PHP about itself; and nothing else. It never creates the application key, it
 * never saves a setting, and it starts no session.
 *
 * EXIT CODES, so a cron can choose:
 *   0  ready
 *   1  something must be fixed before the store works
 *   2  the store works, but something must be done before customers arrive
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$quiet = in_array('--quiet', $argv ?? [], true);

// Forward slashes throughout: every path this prints ends up pasted into a
// cron line on a Linux host, and a Windows-developed checker that hands over
// C:\a\b/c is one the owner has to fix by hand before it works.
$root  = rtrim(str_replace(chr(92), '/', dirname(__DIR__)), '/');

// ---------------------------------------------------------------------------
//  Read the two things that must be known BEFORE the app boots.
//
//  Once includes/init.php has run, app_key() may have created a key and the
//  session may have started. Both are observations this script must make from
//  the outside, not side effects it causes.
// ---------------------------------------------------------------------------
$keyFileExists = is_file($root . '/config/app.key.php');
$keyFromEnv    = (string) getenv('SIK_APP_KEY') !== '' && strlen((string) getenv('SIK_APP_KEY')) >= 32;

// The address the owner has pinned, read straight from the file rather than
// from the app, because the app is entitled to fall back and this is not.
$pinnedUrl = (string) (getenv('APP_URL') ?: getenv('SITE_URL') ?: '');
if ($pinnedUrl === '' && is_file($root . '/config/db.local.php')) {
    $local = @include $root . '/config/db.local.php';
    $pinnedUrl = is_array($local) ? trim((string) ($local['url'] ?? '')) : '';
    unset($local);
}

// A session cookie is meaningless in a terminal and starting one writes a file.
@ini_set('session.use_cookies', '0');

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

// Database::handleFailure() calls exit() on a connection failure in production
// rather than throwing, so a wrong password would end this process silently
// with status 0 - the checker disappearing at exactly the moment it is most
// needed. Anything printed by that path was captured above; if we are still
// here and $booted is false, the list below still gets printed.

// ===========================================================================
//  Reporting
// ===========================================================================

/** @var list<array{level:string,title:string,detail:string,fix:string}> */
$findings = [];
$okCount  = 0;
$printed  = false;

$ok = static function (string $title, string $detail = '') use (&$okCount, $quiet): void {
    $okCount++;
    if (!$quiet) {
        printf("  ok    %s%s\n", $title, $detail === '' ? '' : '  (' . $detail . ')');
    }
};

/** The store does not work until this is done. */
$stop = static function (string $title, string $detail, string $fix) use (&$findings): void {
    $findings[] = ['level' => 'STOP', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

/** The store works, but this must be done before customers arrive. */
$todo = static function (string $title, string $detail, string $fix) use (&$findings): void {
    $findings[] = ['level' => 'TODO', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

/** Worth knowing. Never in the way, and never the only thing said about a risk. */
$note = static function (string $title, string $detail, string $fix = '') use (&$findings): void {
    $findings[] = ['level' => 'note', 'title' => $title, 'detail' => $detail, 'fix' => $fix];
};

$heading = static function (string $text) use ($quiet): void {
    if (!$quiet) {
        printf("\n%s\n", $text);
    }
};

/**
 * Print the list.
 *
 * Hung off the shutdown as well as called at the end, so a crash anywhere
 * still hands over everything gathered up to that point. A checker that dies
 * on the half-imported database it exists to detect is the worst version of
 * this script.
 */
$report = static function () use (&$findings, &$okCount, &$printed): int {
    if ($printed) {
        return 0;
    }
    $printed = true;

    $stops = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'STOP'));
    $todos = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'TODO'));
    $notes = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'note'));

    echo "\n", str_repeat('=', 62), "\n";

    if ($stops === [] && $todos === []) {
        printf("READY. %d check(s) passed and nothing is outstanding.\n", $okCount);
        foreach ($notes as $n) {
            printf("\n  note  %s\n        %s\n", $n['title'], wordwrap($n['detail'], 70, "\n        "));
        }
        return 0;
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

    return $stops !== [] ? 1 : 2;
};

register_shutdown_function(static function () use ($report): void {
    $report();
});

echo "ShopInnKart deployment check\n";
echo str_repeat('-', 62), "\n";

// ===========================================================================
//  1. The machine this command is running on
// ===========================================================================

$heading('This terminal');

// 8.1, not 8.0: the storefront uses readonly properties and never-return
// types. README's table said 8.0 and was wrong.
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    $ok('SSH PHP ' . PHP_VERSION);
} else {
    $stop('The PHP running this command is too old',
        'Found ' . PHP_VERSION . '; this needs 8.1 or newer. Note that the website may be on a '
        . 'different version again - that is checked separately below.',
        'Change the CLI version in hPanel > Advanced > PHP Configuration.');
}

foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session'] as $extension) {
    if (!extension_loaded($extension)) {
        $stop('The SSH PHP has no ' . $extension,
            'Cron jobs run on this binary, so they will fail even if the website works.',
            'Enable it in hPanel > Advanced > PHP Configuration > PHP extensions.');
    }
}
$ok('SSH PHP extensions');

// ---------------------------------------------------------------------------
//  2. Folders
// ---------------------------------------------------------------------------

$heading('Folders');

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/invoices', 'storage/cron',
          'uploads', 'config'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_dir($path)) {
        // storage/cron is created by the first cron run; the rest ship.
        if ($rel === 'storage/cron') {
            continue;
        }
        $stop('/' . $rel . ' does not exist', 'The app writes here.',
            'Create it and make it writable (755, or 775 if the host insists).');
        continue;
    }
    if (!is_writable($path)) {
        $stop('/' . $rel . ' is not writable',
            $rel === 'config'
                ? 'The app cannot create its encryption key, so SMTP and courier passwords will not save.'
                : 'Uploads, logs and invoices are written here.',
            'chmod 755 ' . $rel . '   (or 775 if the host insists)');
    }
}
$ok('Folders are writable');

// ---------------------------------------------------------------------------
//  3. Database credentials - the most common first failure
// ---------------------------------------------------------------------------

$heading('Database');

if (!is_file($root . '/config/db.local.php')) {
    $stop('config/db.local.php is missing',
        'It is deliberately not in Git, so a `git pull` never brings it. Without it the app falls '
        . 'back to the user "root" with no password, and every visitor sees '
        . '"Access denied for user \'root\'@\'localhost\'".',
        "Create config/db.local.php on the server:\n"
        . "            <?php return ['host' => 'localhost', 'name' => 'DB', 'user' => 'USER',\n"
        . "                          'pass' => 'PASS', 'url' => 'https://your-domain.com'];");
} else {
    $ok('config/db.local.php is present');
}

if (!$booted) {
    $stop('The app could not start',
        $bootError !== '' ? $bootError : ($bootNoise !== '' ? strip_tags($bootNoise) : 'No reason was given.'),
        'Fix whatever is named above, then run this again. If it mentions "Access denied", the '
        . 'four values in config/db.local.php do not match the database, or the user is not '
        . 'attached to it in hPanel > Databases.');
    exit($report());
}

$dbUp = false;
try {
    Database::connect();
    $dbUp = true;
    $ok('Connected to the database', DB_NAME);
} catch (Throwable $e) {
    $stop('The database refused the connection', $e->getMessage(),
        'Check the four values in config/db.local.php, and that the user is attached to the '
        . 'database in hPanel > Databases.');
}

if ($bootNoise !== '') {
    $todo('The app printed something while starting',
        mb_substr(preg_replace('/\s+/', ' ', strip_tags($bootNoise)) ?? '', 0, 300),
        'A visitor would see this above the page. Usually a PHP notice from a missing extension '
        . 'or a file permission.');
}

// ===========================================================================
//  4. The address - everything over HTTP depends on knowing it
// ===========================================================================

$heading('This site\'s address');

/**
 * The domain this install is laid out under, when the host says so.
 *
 * There is no Host header in CLI. cPanel and Hostinger both put a site at
 * /home/<user>/domains/<the-domain>/public_html, which names it exactly, and
 * that is a fact about this machine rather than a guess.
 */
$pathDomain = '';
if (preg_match('~/domains/([a-z0-9.\-]+)/~i', str_replace('\\', '/', $root), $m) === 1) {
    $pathDomain = strtolower($m[1]);
}

$siteHost = strtolower((string) parse_url(SITE_URL, PHP_URL_HOST));
$baseUrl  = '';          // The address HTTP checks may use. '' means: do not guess.

if ($pinnedUrl !== '') {
    $baseUrl = rtrim($pinnedUrl, '/');
    $pinHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

    if ($pathDomain !== '' && $pinHost !== $pathDomain
        && $pinHost !== 'www.' . $pathDomain && preg_replace('/^www\./', '', $pinHost) !== $pathDomain) {
        $stop('The pinned address is not this site',
            'config/db.local.php pins ' . $baseUrl . ', but this install is laid out under "'
            . $pathDomain . '". Links, assets and emails will all point at the wrong place.',
            "Set 'url' => 'https://" . $pathDomain . "', in config/db.local.php.");
        $baseUrl = '';
    } else {
        $ok('Address is pinned', $baseUrl);
    }
} elseif ($pathDomain !== '') {
    $stop('The site is building its links for the wrong domain',
        'This install is at "' . $pathDomain . '", but nothing tells the app so, and it resolves '
        . 'its own address as ' . SITE_URL . '. Every stylesheet, script and image is requested '
        . 'from there, so the page loads with no styling and nothing works. The Host allow-list '
        . 'is doing its job - it just has not been told this domain is yours.',
        "Add one line to config/db.local.php and reload:\n"
        . "            'url' => 'https://" . $pathDomain . "',\n"
        . '            Use http:// until the certificate is installed.');
} else {
    $stop('I do not know this site\'s address',
        'There is no Host header in a terminal, nothing is pinned, and this layout does not name '
        . 'the domain. The app is falling back to ' . SITE_URL . ', which is the address built '
        . 'into config/config.php and almost certainly not yours. Every check below that needs '
        . 'the web has been skipped rather than run against the wrong server.',
        "Add to config/db.local.php:  'url' => 'https://your-domain.com',");
}

// ---------------------------------------------------------------------------
//  5. Ask the WEB SERVER about itself.
//
//  The web PHP and the SSH PHP are different binaries on most shared hosts,
//  with different versions, different extensions and different environments.
//  A temporary file under the document root is the only way to ask the one
//  that actually serves customers - and answering with a token we just wrote
//  is also what proves the reply came from THIS server rather than from
//  whatever else happens to answer on that name.
// ---------------------------------------------------------------------------

$heading('The web server');

$web      = null;   // The web SAPI's own answers, or null if it could not be asked.
$probeUrl = '';
$probeFile = '';

if ($baseUrl !== '') {
    $token    = bin2hex(random_bytes(16));
    $probeFile = $root . '/sik-deploy-probe-' . $token . '.php';
    $probeUrl  = $baseUrl . '/' . basename($probeFile);

    // config/config.php, not includes/init.php: it is what defines APP_DEBUG,
    // and it touches no database, starts no session and renders nothing. An
    // earlier version of this probe reported APP_DEBUG as null on every host,
    // because a bare file in the document root has of course never heard of
    // it - so the one check that would have caught a live site printing stack
    // traces to visitors silently did nothing.
    //
    // Wrapped, because if config.php cannot load on the web then the site
    // cannot load either, and saying so is more use than a blank reply.
    $probeSrc = '<?php header("Content-Type: application/json");' . "\n"
        . '$debug = null; $err = null;' . "\n"
        . 'try { require ' . var_export($root . '/config/config.php', true) . ';' . "\n"
        . '      $debug = defined("APP_DEBUG") ? (bool) APP_DEBUG : null; }' . "\n"
        . 'catch (Throwable $e) { $err = $e->getMessage(); }' . "\n"
        . 'echo json_encode(['
        . '"token" => ' . var_export($token, true) . ','
        . '"php" => PHP_VERSION,'
        . '"debug" => $debug,'
        . '"config_error" => $err,'
        . '"ext" => ["gd" => extension_loaded("gd"), "openssl" => extension_loaded("openssl"),'
        . '          "curl" => extension_loaded("curl"), "zip" => extension_loaded("zip"),'
        . '          "pdo_mysql" => extension_loaded("pdo_mysql"), "mbstring" => extension_loaded("mbstring")],'
        . '"rewrite" => function_exists("apache_get_modules") ? in_array("mod_rewrite", apache_get_modules(), true) : null,'
        . ']);';

    @file_put_contents($probeFile, $probeSrc);
}

/** One GET. Returns [status, body, finalUrl]. */
$fetch = static function (string $url, bool $follow = true, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'ShopInnKart deploy-check',
        // A half-configured certificate is a finding, not a reason to be blind.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$code, $body, $final, $err];
};

try {
    if ($probeFile !== '' && is_file($probeFile)) {
        [$code, $body, , $err] = $fetch($probeUrl);
        $json = json_decode($body, true);

        if ($code === 200 && is_array($json) && ($json['token'] ?? '') === $token) {
            $web = $json;
            $ok('Reached this server and it answered', $baseUrl);
        } elseif ($code === 200 && is_array($json)) {
            $stop('Something else is answering on this address',
                'The reply did not carry the token just written into the document root, so '
                . $baseUrl . ' is not this server - a parked domain, a cache, or a different '
                . 'install.',
                'Check the domain points at this account before trusting anything else here.');
        } else {
            $todo('The web server could not be reached from here',
                'GET ' . $probeUrl . ' returned ' . ($code === 0 ? 'no answer' : 'HTTP ' . $code)
                . ($err !== '' ? ' (' . $err . ')' : '') . '. Some hosts block a site from '
                . 'calling itself. Everything that needs the web is unchecked, NOT passing.',
                'Open the site in a browser and check it yourself, or run this from a machine '
                . 'that can reach it.');
        }
    }

    // -----------------------------------------------------------------------
    //  The web PHP, which is the one that matters
    // -----------------------------------------------------------------------
    if ($web !== null) {
        $webPhp = (string) ($web['php'] ?? '');
        if ($webPhp !== '' && version_compare($webPhp, '8.1.0', '>=')) {
            $ok('Website PHP ' . $webPhp);
        } elseif ($webPhp !== '') {
            $stop('The website runs an older PHP than the terminal does',
                'This terminal has ' . PHP_VERSION . ', but the website answers on ' . $webPhp
                . '. The store needs 8.1 or newer and will fatal on pages this check never opens.',
                'hPanel > Advanced > PHP Configuration, and set the version for the DOMAIN, not '
                . 'only for the CLI.');
        }

        foreach (['openssl' => 'encrypting backups, privacy exports and stored SMTP passwords',
                  'gd'      => 'resizing uploaded product images',
                  'zip'     => 'packaging a privacy export as a .zip rather than a bare .json',
                  'curl'    => 'talking to couriers and payment gateways'] as $ext => $why) {
            if (($web['ext'][$ext] ?? false) !== true) {
                $todo('The website has no ' . $ext, 'Needed for ' . $why . '.',
                    'Enable it for the domain in hPanel > Advanced > PHP Configuration.');
            }
        }

        // On a local address this is a development copy behaving correctly, and
        // heading the list with an alarm about publishing database passwords
        // teaches whoever runs it here to skim the rest. On a real hostname it
        // is the most dangerous thing this script can find.
        $localSite = security_host_is_local(
            (string) parse_url($baseUrl, PHP_URL_HOST) ?: ''
        );

        if (($web['debug'] ?? null) === true && !$localSite) {
            $stop('The WEBSITE is in development mode',
                'Measured on the web server itself, not in this terminal: visitors are shown PHP '
                . 'errors, stack traces and the full server path. This is what published a '
                . 'database password on a live site once already.',
                "Remove \"'env' => 'development'\" from config/db.local.php, and check no APP_ENV "
                . 'is set to development for the domain.');
        } elseif (($web['debug'] ?? null) === true) {
            $note('Development mode, on a local address',
                'Correct for a development copy. It would be the most serious thing on this list '
                . 'if this were a real domain.',
                'Nothing to do. Just never copy config/db.local.php up to the host.');
        } elseif (($web['debug'] ?? null) === false) {
            $ok('Website is in production mode');
        }

        if (($web['config_error'] ?? null) !== null) {
            $stop('config/config.php cannot load on the web server',
                (string) $web['config_error'] . ' - the site cannot render a single page.',
                'Fix whatever that names. It is usually a missing file or a permission.');
        }
    }

    // -----------------------------------------------------------------------
    //  Does the host honour .htaccess? Measured on THIS server or not at all.
    // -----------------------------------------------------------------------
    if ($web !== null) {
        $exposed = [];
        $tested  = 0;
        foreach (['config/db.local.php', 'config/app.key.php', 'config/config.php',
                  'storage/logs/error.log', 'database/shopinnkart-import.sql',
                  '.git/config', '.env'] as $path) {
            [$code, $body] = $fetch($baseUrl . '/' . $path, false, 12);
            $tested++;
            // A 200 that returns real bytes. Some hosts answer 200 with a
            // friendly error page, which is not the same thing.
            if ($code === 200 && strlen($body) > 0) {
                $exposed[] = ['path' => $path, 'bytes' => strlen($body)];
            }
        }

        if ($exposed === []) {
            $ok($tested . ' sensitive paths all refused by this server');
        } else {
            foreach ($exposed as $hit) {
                $stop('The web server is serving ' . $hit['path'],
                    'It came down as ' . $hit['bytes'] . ' bytes over HTTP. This host is ignoring '
                    . '.htaccess, so your database password and encryption key are readable by '
                    . 'anyone who asks.',
                    'Ask the host to enable AllowOverride, or move the project so only a public/ '
                    . 'folder is the document root. Treat the credentials as compromised.');
            }
        }

        // mod_rewrite: a pretty URL and its underlying file should agree.
        [$prettyCode] = $fetch($baseUrl . '/shop', true, 12);
        [$plainCode]  = $fetch($baseUrl . '/shop.php', true, 12);
        if ($plainCode === 200 && $prettyCode === 404) {
            $stop('Pretty URLs are not working',
                '/shop.php answers 200 but /shop answers 404, so mod_rewrite is off or .htaccess '
                . 'is being ignored. Most links on the site will 404.',
                'Ask the host to enable mod_rewrite and AllowOverride All for this domain.');
        } elseif ($prettyCode === 200) {
            $ok('Pretty URLs work');
        }

        // HTTPS: what actually happens on http, not what scheme we assumed.
        $httpBase = 'http://' . preg_replace('~^https?://~', '', $baseUrl);
        [, , $finalUrl] = $fetch($httpBase . '/', true, 12);
        if (strpos($finalUrl, 'https://') === 0) {
            $ok('Plain http redirects to https');
        } else {
            $mode = (string) setting('sec_force_https', 'auto');
            $todo('The site answers on plain http',
                'A request to ' . $httpBase . ' stayed on http. The redirect setting is currently '
                . '"' . $mode . '".',
                'Issue the certificate in hPanel > SSL, then set Security > Settings > HTTPS to '
                . '"Always redirect". Raise HSTS from 300 seconds once it has run a day or two.');
        }
    }
} finally {
    if ($probeFile !== '' && is_file($probeFile)) {
        @unlink($probeFile);
    }
}

if ($web === null && $baseUrl === '') {
    $todo('Nothing about the web server was checked',
        'Without this site\'s address there is no way to ask it anything, so whether the host '
        . 'honours .htaccess, whether pretty URLs work, whether HTTPS redirects and what PHP the '
        . 'website runs are all UNKNOWN - not passing.',
        'Pin the address (above) and run this again.');
}

// ===========================================================================
//  6. The data
// ===========================================================================

if ($dbUp) {
    $heading('Data');

    $tables = [];
    try {
        $tables = Database::fetchColumnAll("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'");
    } catch (Throwable $e) {
        $stop('The tables could not be listed', $e->getMessage(),
            'Import database/shopinnkart-import.sql.');
    }

    /** Every table the migrations create, read from the migrations themselves. */
    $expected = [];
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $file) {
        if (preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([a-z0-9_]+)`/i',
                (string) @file_get_contents($file), $found) > 0) {
            foreach ($found[1] as $name) {
                $expected[$name] = basename($file);
            }
        }
    }

    if (count($tables) < 100) {
        $stop('The database is empty or half-imported',
            count($tables) . ' table(s) found; a complete store has about 128.',
            'Import database/shopinnkart-import.sql through phpMyAdmin. Upload that file by hand '
            . '- it is git-ignored, so a pull does not bring it either.');
    } else {
        $ok(count($tables) . ' tables present');
    }

    $missing = [];
    foreach ($expected as $name => $from) {
        if (!in_array($name, $tables, true)) {
            $missing[$name] = $from;
        }
    }
    if ($missing === []) {
        $ok('Every table the migrations create is here', count($expected) . ' checked');
    } else {
        $stop('Migrations have not been run',
            count($missing) . ' table(s) are missing: ' . implode(', ', array_slice(array_keys($missing), 0, 8))
            . (count($missing) > 8 ? ' and more' : '') . '. A screen that needs one of them will '
            . 'fail rather than explain itself.',
            "Run every file in database/migrations/ from the project root:\n"
            . "            for f in database/migrations/*.php; do /usr/bin/php \"\$f\"; done\n"
            . '            They are idempotent, so running one twice changes nothing.');
    }

    /** A guarded count, so one missing table never takes the whole list down. */
    $count = static function (string $table, string $where = '1', array $params = []) use ($tables): ?int {
        if (!in_array($table, $tables, true)) {
            return null;
        }
        try {
            return (int) Database::fetchColumn(sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $where), $params);
        } catch (Throwable $e) {
            return null;
        }
    };

    $products = $count('products', "`status` = 'active'");
    if ($products === null || $products === 0) {
        $todo('The catalogue is empty', 'No active products.',
            'Re-import, or add products in Catalogue > Products.');
    } else {
        $ok($products . ' active products');

        // A Git deploy brings no images: uploads/ is git-ignored. A shop with
        // no pictures is not open, however healthy the row count looks.
        try {
            $images = Database::fetchColumnAll(
                "SELECT `main_image` FROM `products`
                  WHERE `status` = 'active' AND `main_image` <> '' LIMIT 40");
            $absent = 0;
            foreach ($images as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if ($rel !== '' && stripos($rel, 'http') !== 0 && !is_file($root . '/' . $rel)) {
                    $absent++;
                }
            }
            if ($absent > 0) {
                $stop($absent . ' of ' . count($images) . ' product images are not on this server',
                    'The rows are here but the files are not. uploads/ is git-ignored, so a Git '
                    . 'deploy never brings it and the shop renders with broken pictures.',
                    'Upload the uploads/ folder from your own machine by FTP or File Manager.');
            } elseif ($images !== []) {
                $ok('Product images are on disk', count($images) . ' sampled');
            }
        } catch (Throwable $e) {
            $note('Product images could not be checked', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    //  7. The locks
    // -----------------------------------------------------------------------

    $heading('Locks');

    if (is_file($root . '/install.php')) {
        $todo('install.php is still on the server',
            'It refuses to run on a populated database, but a wizard that can reset administrator '
            . '#1 has no business in a public folder.',
            'rm install.php');
    } else {
        $ok('install.php has been deleted');
    }

    if (setting('maintenance_mode', '0') === '1') {
        $stop('The store is closed to customers',
            'Maintenance mode is on. Every visitor gets the closed page; you only see the shop '
            . 'because you are signed in as an admin - and this check would never notice, because '
            . 'the guard exempts the command line.',
            'Turn it off in System > Maintenance when you are ready to open.');
    } else {
        $ok('The store is open');
    }

    // Read-only, and never app_key_available(), which CREATES the key.
    if ($keyFileExists || $keyFromEnv) {
        $ok('The application key is present', $keyFromEnv ? 'from the environment' : 'config/app.key.php');

        // A key that exists but does not match the data is worse than none:
        // every stored secret silently reads as empty.
        try {
            $encrypted = Database::fetchAll(
                "SELECT `setting_key`, `setting_value` FROM `settings`
                  WHERE `setting_value` LIKE 'enc:%' LIMIT 20");
            // secret_status(), not secret_decrypt(): the latter answers ''
            // both for "this key cannot read it" and for "the operator cleared
            // this password", and reporting the second as the first tells an
            // owner their install is broken because they once blanked a field.
            $wrongKey = [];
            $corrupt  = [];
            $readable = 0;
            foreach ($encrypted as $row) {
                switch (secret_status((string) $row['setting_value'])) {
                    case 'wrong-key': $wrongKey[] = (string) $row['setting_key']; break;
                    case 'corrupt':   $corrupt[]  = (string) $row['setting_key']; break;
                    case 'ok':
                    case 'empty':     $readable++; break;
                }
            }

            if ($wrongKey !== []) {
                $stop('The application key does not match the stored secrets',
                    count($wrongKey) . ' encrypted setting(s) fail their authentication check: '
                    . implode(', ', array_slice($wrongKey, 0, 5)) . '. The key on this server is '
                    . 'not the one they were encrypted with, so those passwords are unreadable.',
                    'Copy config/app.key.php from the machine the dump came from, or re-enter '
                    . 'each secret (Settings > Email, Shipping > Configure).');
            }
            if ($corrupt !== []) {
                $todo(count($corrupt) . ' stored secret(s) are not readable at all',
                    implode(', ', array_slice($corrupt, 0, 5)) . ' did not survive whatever copied '
                    . 'them here - truncated, or mangled by an editor.',
                    'Re-enter them on the screen that owns each one.');
            }
            if ($wrongKey === [] && $corrupt === [] && $encrypted !== []) {
                $ok('Stored secrets decrypt with this key', $readable . ' checked');
            }
        } catch (Throwable $e) {
            $note('Stored secrets could not be checked', $e->getMessage());
        }
    } elseif (is_writable($root . '/config')) {
        $todo('No application key yet',
            'config/ is writable, so the app will create one the first time a page loads. If you '
            . 'have a key from the machine this dump came from, copy it up FIRST - a new key makes '
            . 'every secret in the import unreadable.',
            'Copy config/app.key.php up now, or load any page to have one generated.');
    } else {
        $stop('There is nowhere to keep the encryption key',
            'config/ is not writable and SIK_APP_KEY is unset, so SMTP and courier passwords will '
            . 'not save.',
            'Make config/ writable once, load any page, then set it back.');
    }

    require_once INCLUDES_PATH . '/deployment-checks.php';
    $seeded = deployment_seeded_logins();
    if ($seeded['admins'] !== []) {
        $stop('An admin still uses the shipped password',
            (string) deployment_seeded_logins_message($seeded),
            'Change it in System > Admin Users before the domain is reachable.');
    } elseif ($seeded['customers'] > 0) {
        $todo('Demonstration customer accounts are still here',
            $seeded['customers'] . ' customer account(s) still use the seeded password.',
            'Remove them in Customers, or leave them if this store is not trading yet.');
    } else {
        $ok('No account uses the password printed in the README');
    }

    // -----------------------------------------------------------------------
    //  8. Email
    // -----------------------------------------------------------------------

    $heading('Email');

    $transport = 'smtp';
    $mailHost  = '';
    try {
        $config    = function_exists('mail_config') ? (array) mail_config() : [];
        $transport = strtolower((string) ($config['transport'] ?? setting('mail_driver', 'smtp')));
        $mailHost  = trim((string) ($config['host'] ?? setting('smtp_host', '')));
    } catch (Throwable $e) {
        $transport = strtolower((string) setting('mail_driver', 'smtp'));
        $mailHost  = trim((string) setting('smtp_host', ''));
    }

    if ($transport === 'disabled') {
        $stop('Email is switched off', 'The mail transport is "disabled". Nothing is sent at all.',
            'Settings > Email, set it back to SMTP.');
    } elseif ($transport === 'log') {
        $stop('Email is being written to a file instead of sent',
            'The mail transport is "log", which is a development setting. Order confirmations, '
            . 'password resets and invoices go to storage/logs and no customer ever gets one.',
            'Settings > Email, set the transport to SMTP and fill in the host.');
    } elseif ($mailHost === '') {
        $todo('No email will be sent',
            'The transport is SMTP but there is no host, so mail queues instead of sending. The '
            . 'queue is kept, not lost.',
            'Fill it in from hPanel > Emails, then send yourself a test from that screen.');
    } else {
        $ok('Mail transport', $transport . ' via ' . $mailHost);
    }

    foreach (['mail_from_email' => 'no-reply@shopinnkart.com',
              'admin_notify_email' => 'orders@shopinnkart.com'] as $key => $shipped) {
        $value = trim((string) setting($key, ''));
        if ($value === '' || $value === $shipped) {
            $todo('Email is being sent from a domain you do not own',
                $key . ' is still "' . $shipped . '". Mail from a domain you do not control fails '
                . 'SPF and DKIM at the receiving end, so order confirmations land in spam or are '
                . 'refused outright.',
                'Set it to an address on your own domain, in Settings > Email.');
            break;
        }
    }

    $pending = $count('notification_queue', "`status` = 'pending'");
    $failed  = $count('notification_queue', "`status` = 'failed'");
    if ($failed !== null && $failed > 0) {
        $todo($failed . ' email(s) have failed', 'They will not be retried on their own.',
            'Settings > Email log shows the reason for each.');
    }
    if ($pending !== null && $pending > 0) {
        $note($pending . ' email(s) waiting in the queue',
            'Normal if the worker has just been set up. The cron check below is what decides '
            . 'whether anything is sending them.');
    }

    // -----------------------------------------------------------------------
    //  9. The scheduled jobs - from stamps, never inferred from data
    // -----------------------------------------------------------------------

    $heading('Scheduled jobs');

    require_once INCLUDES_PATH . '/cron-heartbeat.php';
    $anyRan = false;
    foreach (cron_heartbeat_jobs() as $job => $spec) {
        $beat = cron_heartbeat_read($job);

        if ($beat === null) {
            $line = $spec['label'] . ' has never run on this server';
            $fix  = 'Add the cron in hPanel > Advanced > Cron Jobs, ' . $spec['every'] . ':'
                  . "\n            /usr/bin/php " . $root . '/bin/' . $job . '.php'
                  . ($job === 'send-queued-emails' ? '' : ' --quiet');
            $spec['required']
                ? $stop($line, 'No email leaves the site at all until this runs - not order '
                    . 'confirmations, not password resets, not invoices.', $fix)
                : $todo($line, 'Nothing is doing this work.', $fix);
            continue;
        }

        $anyRan = true;
        if ($beat['age'] > $spec['stale']) {
            $late = $beat['age'] > 172800
                ? round($beat['age'] / 86400) . ' days'
                : round($beat['age'] / 3600, 1) . ' hours';
            $line = $spec['label'] . ' last ran ' . $late . ' ago';
            $spec['required']
                ? $stop($line, 'It should run ' . $spec['every'] . '. The cron has stopped.',
                    'Check the cron line still exists and its path is still right.')
                : $todo($line, 'It should run ' . $spec['every'] . '.',
                    'Check the cron line still exists and its path is still right.');
            continue;
        }

        $ok($spec['label'], 'ran ' . ($beat['age'] < 120 ? 'just now' : round($beat['age'] / 60) . ' min ago'));
    }

    if (!$anyRan) {
        $note('No scheduled job has ever run here',
            'Expected on a deploy that is only minutes old - the stamps appear as each cron '
            . 'fires. Come back after a few minutes and run this again.');
    }

    // -----------------------------------------------------------------------
    //  10. Backups - the file on disk, not a row that travelled in the dump
    // -----------------------------------------------------------------------

    $heading('Backups');

    try {
        // Not loaded by init.php - the storefront never takes a backup.
        require_once INCLUDES_PATH . '/backup.php';
        $dir   = backup_dir();
        $files = array_values(array_filter(glob($dir . '/*.{sql,enc}', GLOB_BRACE) ?: [],
            static fn (string $f): bool => filesize($f) > 4096));

        if ($files === []) {
            $todo('There is no backup file on this server',
                'Rows in the `backups` table travel inside the imported dump, so they can be your '
                . 'own laptop\'s history. What matters is a file here.',
                'Add the nightly cron, or take one now from System > Backup.');
        } else {
            $newest = 0;
            foreach ($files as $f) {
                $newest = max($newest, (int) filemtime($f));
            }
            $ageDays = (time() - $newest) / 86400;
            if ($ageDays > 2) {
                $todo('The newest backup is ' . round($ageDays) . ' days old',
                    count($files) . ' file(s) in ' . $dir . '.',
                    'Check the nightly cron is running.');
            } else {
                $ok(count($files) . ' backup file(s)', 'newest ' . round($ageDays * 24) . 'h old');
            }
        }
    } catch (Throwable $e) {
        $note('Backups could not be checked', $e->getMessage());
    }
}

exit($report());
