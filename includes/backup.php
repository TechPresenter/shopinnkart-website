<?php
/**
 * ShopInnKart - Database backup, verification and restore.
 *
 * A pure-PHP dump: SHOW TABLES, then SHOW CREATE TABLE, then chunked SELECTs
 * written out as escaped INSERTs. mysqldump is not assumed to exist, and
 * shell_exec() is not assumed to be enabled - plenty of shared hosts have
 * neither, and a backup button that only works on some servers is worse than
 * none because it is trusted until the day it is needed.
 *
 * A dump is the whole application in one file: every bcrypt hash to crack
 * offline, every address, every gateway secret. So it is never written in the
 * clear. Two ways to protect it, and the difference matters:
 *
 *   - the application key (default). Nothing to remember, nothing to leak.
 *     But the dump dies with config/app.key.php: lose the key file and the
 *     backups on disk are noise.
 *   - a passphrase. Survives losing the key - which is the case a backup
 *     exists for - at the cost of something a human has to keep. And if the
 *     passphrase is kept in the settings table so the nightly job can use it,
 *     then whoever steals the database steals the passphrase with it, so the
 *     environment variable SIK_BACKUP_PASSPHRASE is the version that is
 *     actually worth something.
 *
 * This file is the single implementation. admin/system/backup.php is the
 * screen, bin/backup.php is the cron job, and neither one re-implements any
 * of it - a dump format written twice is a dump format that diverges.
 */

declare(strict_types=1);

/** Rows fetched per SELECT. Small enough that a large table never has to fit in memory. */
const BACKUP_CHUNK_ROWS = 500;

/** Rows per INSERT statement in the dump file. */
const BACKUP_INSERT_ROWS = 100;

/** Plaintext bytes per encrypted frame. Bounds memory on both write and read. */
const BACKUP_CRYPT_CHUNK = 262144;

/** First line of a dump encrypted with the application key. */
const BACKUP_MAGIC = "SIKBAK1\n";

/** First line of a dump encrypted with a passphrase; a header follows it. */
const BACKUP_MAGIC_PASS = "SIKBAK2\n";

/**
 * PBKDF2 rounds for a passphrase-protected dump.
 *
 * Paid once per backup and once per restore, so it can be expensive: this is
 * roughly 100 ms on the kind of CPU a shared host gives you, and it is the
 * only thing standing between a stolen file and a dictionary attack.
 */
const BACKUP_KDF_ROUNDS = 210000;

/** Default number of dumps kept on disk before the oldest are removed. */
const BACKUP_KEEP_DEFAULT = 7;

/** How long a restore may run inside a web request before it stops itself. */
const BACKUP_RESTORE_WEB_SECONDS = 240;

// ===========================================================================
//  Where the files live
// ===========================================================================

/**
 * The folder dumps are written to, created and guarded.
 *
 * Shared hosts differ on whether anything outside public_html is writable, so
 * the owner can point `sec_backup_path` at a folder above the document root
 * and this returns that instead. When it cannot be used the default is taken
 * rather than failing: a backup in a guarded folder inside the web root beats
 * no backup at all, and backup_dir_status() is what tells the owner which of
 * the two they got.
 */
function backup_dir(): string
{
    $custom = trim((string) setting('sec_backup_path', ''));
    if ($custom !== '' && backup_dir_usable($custom)) {
        return backup_dir_guard(rtrim(str_replace('\\', '/', $custom), '/'));
    }

    return backup_dir_guard(STORAGE_PATH . '/backups');
}

/** Can this path be created and written to? Never throws - it is a probe. */
function backup_dir_usable(string $dir): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '' || !backup_path_absolute($dir)) {
        return false;   // a relative path means something different per SAPI
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return is_writable($dir);
}

/** Absolute on this platform? C:/x on Windows, /x everywhere else. */
function backup_path_absolute(string $path): bool
{
    return preg_match('#^(?:[A-Za-z]:/|/)#', str_replace('\\', '/', $path)) === 1;
}

/**
 * Make sure the folder exists and cannot be served or listed.
 *
 * Three guards, because one server's rule is another server's no-op: Apache
 * and LiteSpeed read .htaccess, IIS reads web.config, and an index.html
 * defeats a directory listing on anything that ignores both.
 *
 * The web.config used to be a no-op that looked like a guard. It listed a
 * hiddenSegment of "." - but IIS matches hiddenSegments against whole path
 * SEGMENTS, and a segment of "." is normalised away by the URL parser before
 * any rule sees it, so it could never match anything at all. It now denies by
 * file extension with an empty allow list, which is how request filtering
 * refuses every file in a folder, and which applies to static files rather
 * than only to requests ASP.NET handles.
 *
 * Reasoned from the rule rather than measured: there is no IIS on the machine
 * this was written on. On Apache, which IS measured, the .htaccess answers 403
 * (scratchpad/test_exposed_paths.php).
 *
 * None of the three helps if the folder is outside the web root, which is the
 * point - they are for the installs where it cannot be. Whether it actually is
 * outside is reported separately as backup_dir_status()['outside_web_root'],
 * because that is the one guard that does not need the server to co-operate.
 */
function backup_dir_guard(string $dir): string
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The backups folder could not be created: ' . $dir);
    }

    $guards = [
        '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
        'index.html' => '',
        'web.config' => "<?xml version=\"1.0\"?>\n"
            . "<configuration><system.webServer><security><requestFiltering>\n"
            . "    <!-- Empty allow list: every extension is refused, which is how request\n"
            . "         filtering denies a whole folder, static files included. -->\n"
            . "    <fileExtensions allowUnlisted=\"false\" />\n"
            . "</requestFiltering></security></system.webServer></configuration>\n",
    ];

    foreach ($guards as $name => $body) {
        if (!is_file($dir . '/' . $name)) {
            @file_put_contents($dir . '/' . $name, $body);
        }
    }

    return $dir;
}

/**
 * What the owner needs to know about the folder, for the UI and the self-test.
 *
 * @return array{path:string,exists:bool,writable:bool,outside_web_root:bool,guarded:bool,custom:bool,custom_requested:string}
 */
function backup_dir_status(): array
{
    $custom      = trim((string) setting('sec_backup_path', ''));
    $usingCustom = $custom !== '' && backup_dir_usable($custom);

    try {
        $dir = backup_dir();
    } catch (Throwable $e) {
        $dir = $usingCustom ? $custom : STORAGE_PATH . '/backups';
    }

    $root = rtrim(str_replace('\\', '/', (string) realpath(ROOT_PATH)), '/');
    $real = str_replace('\\', '/', (string) (realpath($dir) ?: $dir));

    return [
        'path'             => $dir,
        'exists'           => is_dir($dir),
        'writable'         => is_dir($dir) && is_writable($dir),
        'outside_web_root' => $root === '' || strpos($real . '/', $root . '/') !== 0,
        'guarded'          => is_file($dir . '/.htaccess'),
        'custom'           => $usingCustom,
        'custom_requested' => $custom,
    ];
}

/**
 * Resolve a stored filename to a real path inside the backups folder.
 * Returns null for anything that escapes the folder or does not exist.
 */
function backup_resolve_path(string $filename, ?string $dir = null): ?string
{
    $dir  = $dir ?? backup_dir();
    $name = basename(str_replace('\\', '/', $filename));

    // .sql is still accepted so dumps taken before encryption stay downloadable.
    if (preg_match('/^[A-Za-z0-9._-]+\.sql(\.enc)?$/', $name) !== 1) {
        return null;
    }

    $real    = realpath($dir . '/' . $name);
    $dirReal = realpath($dir);
    if ($real === false || $dirReal === false) {
        return null;
    }

    $real    = str_replace('\\', '/', $real);
    $dirReal = rtrim(str_replace('\\', '/', $dirReal), '/');

    return strpos($real, $dirReal . '/') === 0 && is_file($real) ? $real : null;
}

// ===========================================================================
//  The passphrase
// ===========================================================================

/**
 * The configured backup passphrase, or '' when dumps use the application key.
 *
 * The environment wins over the settings table on purpose. A passphrase in
 * `settings` is in every dump the passphrase protects, so an attacker who
 * already has the database has the passphrase too - it still defends the file
 * on disk against someone who only got the filesystem, but it is not the
 * "survives a stolen database" story, and the UI says so.
 */
function backup_passphrase(): string
{
    $env = getenv('SIK_BACKUP_PASSPHRASE');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $stored = (string) setting('sec_backup_passphrase', '');
    return $stored === '' ? '' : trim(secret_decrypt($stored));
}

/** Where the passphrase came from, for the UI: 'env' | 'settings' | 'none'. */
function backup_passphrase_source(): string
{
    $env = getenv('SIK_BACKUP_PASSPHRASE');
    if (is_string($env) && trim($env) !== '') {
        return 'env';
    }

    return (string) setting('sec_backup_passphrase', '') !== '' ? 'settings' : 'none';
}

// ===========================================================================
//  The cipher
// ===========================================================================

/**
 * Streaming AES-256-GCM reader/writer for a dump file.
 *
 * Frame format after the header: 4-byte big-endian ciphertext length, 12-byte
 * IV, 16-byte tag, ciphertext. Framing rather than one giant openssl_encrypt()
 * is what keeps a multi-gigabyte dump inside PHP's memory limit, and each
 * frame is authenticated on its own so a truncated or edited file fails loudly
 * instead of decrypting to garbage.
 *
 * Header: BACKUP_MAGIC on its own for an application-key dump, or
 * BACKUP_MAGIC_PASS followed by a 16-byte salt and a 4-byte round count for a
 * passphrase dump - so a file always says how to open it, and a restore never
 * has to be told.
 */
final class BackupCipher
{
    /** @var resource|null */
    private $handle;
    private string $buffer = '';
    private string $key;

    public function __construct(string $path, string $passphrase = '')
    {
        // The key is derived before the file is created: a missing application
        // key must not leave a zero-byte dump sitting in the folder looking
        // like a backup.
        $this->key = $passphrase !== ''
            ? ''   // filled below, once the salt exists
            : self::appKey();

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the backup file for writing: ' . $path);
        }
        $this->handle = $handle;

        if ($passphrase !== '') {
            $salt      = random_bytes(16);
            $this->key = self::passphraseKey($passphrase, $salt, BACKUP_KDF_ROUNDS);
            fwrite($this->handle, BACKUP_MAGIC_PASS . $salt . pack('N', BACKUP_KDF_ROUNDS));
        } else {
            fwrite($this->handle, BACKUP_MAGIC);
        }
    }

    /**
     * The 32-byte key an application-key dump uses.
     *
     * Its own purpose label, so a backup is not readable with the key that
     * decrypts stored SMTP and gateway secrets, and vice versa.
     */
    private static function appKey(): string
    {
        $derived = app_key_derive('backup-dump');
        if ($derived === '') {
            throw new RuntimeException(
                'No application key is available, so a backup cannot be encrypted. '
                . 'Check that config/app.key.php exists and is readable, or set a backup passphrase.'
            );
        }

        return hash('sha256', $derived, true);
    }

    /** PBKDF2-HMAC-SHA256. Deliberately slow; see BACKUP_KDF_ROUNDS. */
    private static function passphraseKey(string $passphrase, string $salt, int $rounds): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, max(1000, $rounds), 32, true);
    }

    public function write(string $text): void
    {
        $this->buffer .= $text;
        while (strlen($this->buffer) >= BACKUP_CRYPT_CHUNK) {
            $this->seal(substr($this->buffer, 0, BACKUP_CRYPT_CHUNK));
            $this->buffer = substr($this->buffer, BACKUP_CRYPT_CHUNK);
        }
    }

    private function seal(string $plain): void
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        fwrite($this->handle, pack('N', strlen($cipher)) . $iv . $tag . $cipher);
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        if ($this->buffer !== '') {
            $this->seal($this->buffer);
            $this->buffer = '';
        }
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * How a file on disk is protected: 'app-key' | 'passphrase' | 'plaintext'.
     * Reads the header only, so it is cheap and never needs a key.
     */
    public static function protection(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 'unknown';
        }
        $magic = (string) fread($handle, strlen(BACKUP_MAGIC));
        fclose($handle);

        if ($magic === BACKUP_MAGIC) {
            return 'app-key';
        }
        if ($magic === BACKUP_MAGIC_PASS) {
            return 'passphrase';
        }

        return 'plaintext';
    }

    /**
     * Yield the plaintext of a dump, frame by frame.
     *
     * A file without a known magic line is a legacy plaintext dump and is
     * streamed as-is: dumps taken before encryption existed must stay
     * restorable, or this change would quietly orphan them.
     */
    public static function read(string $path, string $passphrase = ''): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened.');
        }

        try {
            $magic = (string) fread($handle, strlen(BACKUP_MAGIC));

            if ($magic === BACKUP_MAGIC_PASS) {
                if ($passphrase === '') {
                    throw new RuntimeException('This backup is protected by a passphrase. Enter it to read the file.');
                }
                $salt   = (string) fread($handle, 16);
                $rounds = (int) (unpack('N', (string) fread($handle, 4))[1] ?? BACKUP_KDF_ROUNDS);
                if (strlen($salt) < 16) {
                    throw new RuntimeException('The backup file header is truncated.');
                }
                $key = self::passphraseKey($passphrase, $salt, $rounds);
            } elseif ($magic === BACKUP_MAGIC) {
                $key = self::appKey();
            } else {
                yield $magic;
                while (!feof($handle)) {
                    yield (string) fread($handle, BACKUP_CRYPT_CHUNK);
                }
                return;
            }

            while (!feof($handle)) {
                $header = (string) fread($handle, 4);
                if (strlen($header) < 4) {
                    return;   // clean end of file
                }

                $length = (int) (unpack('N', $header)[1] ?? 0);
                $frame  = (string) fread($handle, 28 + $length);
                if (strlen($frame) < 28 + $length) {
                    throw new RuntimeException('The backup file is truncated.');
                }

                $plain = openssl_decrypt(
                    substr($frame, 28),
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    substr($frame, 0, 12),
                    substr($frame, 12, 16)
                );

                if ($plain === false) {
                    throw new RuntimeException($magic === BACKUP_MAGIC_PASS
                        ? 'That passphrase does not open this backup, or the file has been altered.'
                        : 'This backup cannot be decrypted with the current application key. '
                          . 'It was taken with a different config/app.key.php.');
                }

                yield $plain;
            }
        } finally {
            fclose($handle);
        }
    }
}

// ===========================================================================
//  Writing a dump
// ===========================================================================

/** Quote one column value for an INSERT statement. */
function backup_quote(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $string = (string) $value;

    // Binary columns cannot survive a quoted string literal, so they go out as
    // hex. an_sessions.vkey is BINARY(16), and a plugin table might add more.
    if (!mb_check_encoding($string, 'UTF-8')) {
        return '0x' . bin2hex($string);
    }

    return $pdo->quote($string);
}

/**
 * Write a dump of every base table to $filePath.
 *
 * @param array{passphrase?:string,tables?:string[]|null} $options
 *        `tables` restricts the dump to the named tables. Nothing in the UI
 *        passes it - a partial dump presented as "your backup" is a trap. It
 *        exists so backup_selftest() can prove the whole write/checksum/read
 *        chain works on this server in under a second, instead of asking the
 *        owner to dump a 2 GB catalogue to find out.
 *
 * @return array{tables:int,rows:int,bytes:int,checksum:string,protection:string}
 */
/**
 * ` ORDER BY \`pk\`` for a table, or '' when it has no usable key.
 *
 * Only a single-column primary key is used. A composite key would work too,
 * but the point here is a cheap, stable order for chunking, and the clustered
 * single-column case is every table in this schema. The name is read from the
 * server and backquoted, never interpolated from anything a caller supplied.
 */
function backup_table_order(string $table): string
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $order = '';
    try {
        $keys = Database::fetchAll(sprintf('SHOW KEYS FROM `%s` WHERE `Key_name` = %s', $table, "'PRIMARY'"));
        if (count($keys) === 1) {
            $column = (string) ($keys[0]['Column_name'] ?? '');
            if (preg_match('/^[A-Za-z0-9_$]+$/', $column) === 1) {
                $order = ' ORDER BY `' . $column . '`';
            }
        }
    } catch (Throwable $e) {
        $order = '';
    }

    return $cache[$table] = $order;
}

function backup_write_dump(string $filePath, array $options = []): array
{
    $pdo        = Database::connect();
    $passphrase = (string) ($options['passphrase'] ?? '');

    $tables = array_values(array_filter(
        Database::fetchColumnAll("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'"),
        static fn ($name): bool => preg_match('/^[A-Za-z0-9_$]+$/', (string) $name) === 1
    ));

    $only = $options['tables'] ?? null;
    if (is_array($only)) {
        $wanted = array_map('strval', $only);
        $tables = array_values(array_filter($tables, static fn ($t): bool => in_array((string) $t, $wanted, true)));
    }

    // Written through the cipher: no plaintext copy of the database ever
    // touches the disk, not even briefly.
    $out      = new BackupCipher($filePath, $passphrase);
    $rowTotal = 0;

    // ONE point in time for the whole dump.
    //
    // Each table is read in chunks, and a backup runs while customers are
    // shopping. Without a snapshot, every chunk is a separate query against a
    // moving table: an order placed halfway through a 40-chunk table shifts
    // every later OFFSET by one and a row is skipped, a delete duplicates one,
    // and the row count read before the loop stops matching what is there. The
    // dump still finishes, still checksums, and still restores - as a database
    // missing rows nobody can name. This is the same thing mysqldump means by
    // --single-transaction.
    //
    // It is a read-only transaction over InnoDB. Anything non-transactional in
    // the schema is simply not covered by it, which is why the chunk query is
    // ALSO given a deterministic order below rather than relying on this alone.
    $snapshot = false;
    try {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $snapshot = true;
    } catch (Throwable $e) {
        // A host that refuses it still gets a backup - just not an
        // instantaneous one. Recorded so it is not a silent downgrade.
        security_event('backup.no_snapshot', 'low', ['error' => mb_substr($e->getMessage(), 0, 200)]);
    }

    try {
        $out->write('-- ' . SITE_NAME . " database backup\n");
        $out->write('-- Database: ' . DB_NAME . "\n");
        $out->write('-- Generated: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ")\n");
        $out->write('-- Server: ' . (string) Database::fetchColumn('SELECT VERSION()') . "\n");
        $out->write('-- Tables: ' . count($tables) . "\n\n");
        $out->write("SET NAMES utf8mb4;\n");
        $out->write("SET FOREIGN_KEY_CHECKS = 0;\n");
        $out->write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = Database::fetch(sprintf('SHOW CREATE TABLE `%s`', $table));
            $ddl    = (string) ($create['Create Table'] ?? '');
            if ($ddl === '') {
                continue;
            }

            $out->write("-- ---------------------------------------------------------------\n");
            $out->write("-- Table: {$table}\n");
            $out->write("-- ---------------------------------------------------------------\n");
            $out->write("DROP TABLE IF EXISTS `{$table}`;\n");
            $out->write($ddl . ";\n\n");

            $count = (int) Database::fetchColumn(sprintf('SELECT COUNT(*) FROM `%s`', $table));
            if ($count === 0) {
                continue;
            }

            $offset  = 0;
            $pending = [];
            $columns = null;
            // LIMIT/OFFSET across SEPARATE queries is only meaningful if the
            // rows come back in the same order every time, and SQL promises
            // nothing without ORDER BY. Ordering by the primary key costs
            // nothing (it is the clustered order InnoDB already scans in) and
            // makes the chunk boundaries mean what they say. A table with no
            // single-column key keeps the old behaviour, covered by the
            // snapshot above.
            $order = backup_table_order($table);

            while ($offset < $count) {
                // LIMIT/OFFSET are integers cast here: PDO cannot bind them
                // while emulated prepares are off.
                $chunk = Database::fetchAll(sprintf(
                    'SELECT * FROM `%s`%s LIMIT %d OFFSET %d',
                    $table,
                    $order,
                    BACKUP_CHUNK_ROWS,
                    $offset
                ));
                if ($chunk === []) {
                    break;
                }

                foreach ($chunk as $row) {
                    if ($columns === null) {
                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = backup_quote($pdo, $value);
                    }
                    $pending[] = '(' . implode(', ', $values) . ')';
                    $rowTotal++;

                    if (count($pending) >= BACKUP_INSERT_ROWS) {
                        $out->write("INSERT INTO `{$table}` ({$columns}) VALUES\n"
                            . implode(",\n", $pending) . ";\n");
                        $pending = [];
                    }
                }

                $offset += BACKUP_CHUNK_ROWS;
            }

            if ($pending !== [] && $columns !== null) {
                $out->write("INSERT INTO `{$table}` ({$columns}) VALUES\n"
                    . implode(",\n", $pending) . ";\n");
            }
            $out->write("\n");
        }

        $out->write("SET FOREIGN_KEY_CHECKS = 1;\n");
        $out->write("-- End of backup\n");
    } finally {
        $out->close();

        // The read view has to be released whether the dump finished or threw,
        // or this connection keeps an InnoDB history list alive behind it. A
        // COMMIT rather than a ROLLBACK: nothing was written, and committing a
        // read-only transaction is the cheaper of the two.
        if ($snapshot) {
            try {
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                security_event('backup.snapshot_release_failed', 'low',
                    ['error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }

    clearstatcache(true, $filePath);

    return [
        'tables'     => count($tables),
        'rows'       => $rowTotal,
        'bytes'      => (int) filesize($filePath),
        'checksum'   => backup_checksum($filePath),
        'protection' => $passphrase !== '' ? 'passphrase' : 'app-key',
    ];
}

/**
 * SHA-256 of the file exactly as it sits on disk.
 *
 * Of the ciphertext, not the plaintext, on purpose: checking it needs no key
 * and no passphrase, so "has this file rotted or been tampered with?" can be
 * answered on a schedule without ever decrypting a dump.
 */
function backup_checksum(string $path): string
{
    $hash = @hash_file('sha256', $path);
    return is_string($hash) ? $hash : '';
}

// ===========================================================================
//  Creating a backup (the whole flow)
// ===========================================================================

/**
 * Take a backup, record it, and enforce retention.
 *
 * @param array{note?:string,source?:string,admin_id?:int|null,admin_name?:string|null,passphrase?:string,tables?:string[]} $options
 * @return array{id:int,filename:string,path:string,tables:int,rows:int,bytes:int,checksum:string,protection:string,pruned:int}
 */
function backup_create(array $options = []): array
{
    $dir        = backup_dir();
    $passphrase = array_key_exists('passphrase', $options)
        ? (string) $options['passphrase']
        : backup_passphrase();

    // A catalogue-sized dump can outlast the default limits.
    @set_time_limit(600);

    $filename = 'shopinnkart-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.enc';
    $path     = $dir . '/' . $filename;

    $result = backup_write_dump($path, [
        'passphrase' => $passphrase,
        'tables'     => $options['tables'] ?? null,
    ]);

    $id = Database::insert('backups', [
        'filename'     => $filename,
        'size_bytes'   => $result['bytes'],
        'checksum'     => $result['checksum'],
        'protection'   => $result['protection'],
        'tables_count' => $result['tables'],
        'rows_count'   => $result['rows'],
        'source'       => mb_substr((string) ($options['source'] ?? 'manual'), 0, 20),
        'admin_id'     => isset($options['admin_id']) ? (int) $options['admin_id'] : null,
        'admin_name'   => isset($options['admin_name']) ? (string) $options['admin_name'] : null,
        'note'         => ((string) ($options['note'] ?? '')) !== ''
            ? mb_substr((string) $options['note'], 0, 255)
            : null,
        'verified_at'  => date('Y-m-d H:i:s'),
    ]);

    $pruned = backup_prune();

    return $result + ['id' => $id, 'filename' => $filename, 'path' => $path, 'pruned' => $pruned];
}

/** How many dumps are kept. 0 means "never delete any", which the UI spells out. */
function backup_keep(): int
{
    return max(0, min(365, setting_int('sec_backup_keep', BACKUP_KEEP_DEFAULT)));
}

/**
 * Delete the oldest dumps past the retention count.
 *
 * Retention is counted over rows that still have a file: a record whose file
 * has already gone is not "a backup you have", and counting it would quietly
 * shrink how many real ones are kept.
 *
 * @return int how many files were removed
 */
function backup_prune(?int $keep = null): int
{
    $keep = $keep ?? backup_keep();
    if ($keep <= 0) {
        return 0;
    }

    $dir  = backup_dir();
    $rows = Database::fetchAll('SELECT `id`, `filename` FROM `backups` ORDER BY `id` DESC');

    $seen    = 0;
    $removed = 0;
    foreach ($rows as $row) {
        $path = backup_resolve_path((string) $row['filename'], $dir);
        if ($path === null) {
            continue;
        }
        $seen++;
        if ($seen <= $keep) {
            continue;
        }

        @unlink($path);
        Database::delete('backups', '`id` = :id', ['id' => (int) $row['id']]);
        $removed++;
    }

    return $removed;
}

// ===========================================================================
//  Files in the folder that nothing points at
// ===========================================================================

/** The guards that protect the folder. They are never listed and never deleted. */
const BACKUP_GUARD_FILES = ['.htaccess', 'index.html', 'web.config', '.gitkeep'];

/**
 * Resolve a stray file inside the backups folder.
 *
 * Deliberately looser than backup_resolve_path(), which only admits the
 * .sql/.sql.enc names this code writes: a stray is by definition something
 * this code did not write, and a .zip somebody uploaded by FTP needs to be
 * removable too. Still a basename inside the one folder, still never one of
 * the guard files - an admin who deletes the .htaccess would be publishing
 * every dump in it.
 */
function backup_stray_path(string $name, ?string $dir = null): ?string
{
    $dir  = $dir ?? backup_dir();
    $base = basename(str_replace('\\', '/', $name));

    if ($base === '' || $base === '.' || $base === '..' || in_array($base, BACKUP_GUARD_FILES, true)) {
        return null;
    }

    $real    = realpath($dir . '/' . $base);
    $dirReal = realpath($dir);
    if ($real === false || $dirReal === false) {
        return null;
    }

    $real    = str_replace('\\', '/', $real);
    $dirReal = rtrim(str_replace('\\', '/', $dirReal), '/');

    return strpos($real, $dirReal . '/') === 0 && is_file($real) ? $real : null;
}

/**
 * Files in the backups folder that no record points at.
 *
 * Two kinds turn up and both matter. A dump taken before this screen existed -
 * by hand, over SSH, or by an older version of the store - is usually plain
 * SQL, which is every password hash and every address lying in a folder in
 * the clear. And a record deleted without its file leaves the file behind.
 * Neither appears in the list, so neither gets looked at, which is how a
 * plaintext copy of the whole shop survives a year on a shared host.
 *
 * @return array<int, array{name:string,bytes:int,modified:int,protection:string}>
 */
function backup_untracked_files(?string $dir = null): array
{
    try {
        $dir   = $dir ?? backup_dir();
        $known = [];
        foreach (Database::fetchColumnAll('SELECT `filename` FROM `backups`') as $name) {
            $known[basename((string) $name)] = true;
        }
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach (glob($dir . '/*') ?: [] as $path) {
        $name = basename($path);
        if (!is_file($path) || isset($known[$name]) || in_array($name, BACKUP_GUARD_FILES, true)) {
            continue;
        }

        $out[] = [
            'name'       => $name,
            'bytes'      => (int) filesize($path),
            'modified'   => (int) filemtime($path),
            'protection' => BackupCipher::protection($path),
        ];
    }

    usort($out, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

    return $out;
}

/**
 * Encrypt a stray dump in place and give it a record.
 *
 * The file is rewritten through the same cipher a new backup uses, checksummed
 * and listed, and only then is the plaintext removed - if anything throws, the
 * original is still there. The point is the plaintext: a dump somebody took
 * over SSH two years ago is the same liability as one taken today, and telling
 * the owner about it without offering to fix it is half a feature.
 *
 * @return array{filename:string,bytes:int,checksum:string,protection:string,id:int}
 */
function backup_secure_stray(string $name, array $options = []): array
{
    $path = backup_stray_path($name);
    if ($path === null) {
        throw new RuntimeException('That file is not in the backups folder.');
    }
    if (BackupCipher::protection($path) !== 'plaintext') {
        throw new RuntimeException('That file is already encrypted.');
    }

    $passphrase = (string) ($options['passphrase'] ?? backup_passphrase());
    $base       = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($path, '.sql'));
    $target     = dirname($path) . '/' . $base . '.sql.enc';
    if (is_file($target)) {
        $target = dirname($path) . '/' . $base . '-' . bin2hex(random_bytes(3)) . '.sql.enc';
    }

    $cipher = new BackupCipher($target, $passphrase);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        $cipher->close();
        @unlink($target);
        throw new RuntimeException('That file could not be read.');
    }

    try {
        while (!feof($handle)) {
            $cipher->write((string) fread($handle, BACKUP_CRYPT_CHUNK));
        }
    } finally {
        fclose($handle);
        $cipher->close();
    }

    clearstatcache(true, $target);
    $checksum = backup_checksum($target);

    $id = Database::insert('backups', [
        'filename'     => basename($target),
        'size_bytes'   => (int) filesize($target),
        'checksum'     => $checksum,
        'protection'   => $passphrase !== '' ? 'passphrase' : 'app-key',
        'tables_count' => 0,
        'rows_count'   => 0,
        'source'       => 'manual',
        'admin_id'     => isset($options['admin_id']) ? (int) $options['admin_id'] : null,
        'admin_name'   => isset($options['admin_name']) ? (string) $options['admin_name'] : null,
        'note'         => 'Encrypted in place; was plain SQL in the folder',
        'created_at'   => date('Y-m-d H:i:s', (int) filemtime($target)),
    ]);

    // Last, and only once the encrypted copy is on disk and recorded.
    @unlink($path);

    return ['filename' => basename($target), 'bytes' => (int) filesize($target),
            'checksum' => $checksum, 'protection' => $passphrase !== '' ? 'passphrase' : 'app-key',
            'id' => $id];
}

// ===========================================================================
//  Reading a dump back
// ===========================================================================

/**
 * Split a stream of SQL text into statements.
 *
 * Quoting state is tracked across lines and across chunk boundaries, so a
 * semicolon inside a customer's address, a backslash-escaped quote, a `--`
 * comment and a backticked identifier all survive. It matters more than it
 * looks: the naive explode(';') restore corrupts exactly the rows that carry
 * free text, which is the address book and the order notes.
 *
 * @param iterable<string> $chunks
 * @return Generator<string>
 */
function backup_sql_statements(iterable $chunks): Generator
{
    $state   = 0;    // 0 sql | 1 '..' | 2 ".." | 3 `..` | 5 block comment
    $current = '';
    $carry   = '';   // a partial line held back until its newline arrives

    foreach ($chunks as $chunk) {
        $carry .= $chunk;
        $nl = strrpos($carry, "\n");
        if ($nl === false) {
            continue;
        }

        $block = substr($carry, 0, $nl + 1);
        $carry = substr($carry, $nl + 1);

        foreach (explode("\n", rtrim($block, "\n")) as $line) {
            yield from backup_sql_feed($line . "\n", $state, $current);
        }
    }

    if ($carry !== '') {
        yield from backup_sql_feed($carry, $state, $current);
    }

    $tail = trim($current);
    if ($tail !== '') {
        yield $tail;   // a dump whose last statement lost its semicolon
    }
}

/**
 * Consume one line, updating the parser state. Yields whole statements.
 *
 * @return Generator<string>
 */
function backup_sql_feed(string $line, int &$state, string &$current): Generator
{
    $len = strlen($line);
    $p   = 0;

    while ($p < $len) {
        if ($state === 0) {
            $n = strcspn($line, "'\"`;-/#", $p);
            $current .= substr($line, $p, $n);
            $p += $n;
            if ($p >= $len) {
                return;
            }

            $c = $line[$p];

            if ($c === ';') {
                $sql     = trim($current);
                $current = '';
                $p++;
                if ($sql !== '') {
                    yield $sql;
                }
                continue;
            }

            // MySQL only treats `--` as a comment when whitespace follows it,
            // so `a--b` stays arithmetic rather than swallowing the line.
            if ($c === '-') {
                $next  = $line[$p + 1] ?? '';
                $after = $line[$p + 2] ?? "\n";
                if ($next === '-' && ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r")) {
                    $current .= "\n";
                    return;
                }
                $current .= '-';
                $p++;
                continue;
            }

            if ($c === '#') {
                $current .= "\n";
                return;
            }

            if ($c === '/') {
                if (($line[$p + 1] ?? '') === '*') {
                    $state = 5;
                    $p += 2;
                    continue;
                }
                $current .= '/';
                $p++;
                continue;
            }

            $state    = $c === "'" ? 1 : ($c === '"' ? 2 : 3);
            $current .= $c;
            $p++;
            continue;
        }

        if ($state === 5) {
            $end = strpos($line, '*/', $p);
            if ($end === false) {
                return;
            }
            $state = 0;
            $p     = $end + 2;
            continue;
        }

        $quote = $state === 1 ? "'" : ($state === 2 ? '"' : '`');
        $n     = strcspn($line, $quote . '\\', $p);
        $current .= substr($line, $p, $n);
        $p += $n;
        if ($p >= $len) {
            return;
        }

        $c = $line[$p];

        if ($c === '\\') {
            // Backticked identifiers take no backslash escapes; a backslash in
            // one is just a character.
            if ($state === 3) {
                $current .= '\\';
                $p++;
                continue;
            }
            $current .= substr($line, $p, 2);
            $p += 2;
            continue;
        }

        if (($line[$p + 1] ?? '') === $quote) {
            $current .= $quote . $quote;   // a doubled quote is an escaped one
            $p += 2;
            continue;
        }

        $current .= $quote;
        $p++;
        $state = 0;
    }
}

/** Which table a statement touches, or '' when it is not table-scoped. */
function backup_statement_table(string $sql): string
{
    $head = substr(ltrim($sql), 0, 200);
    $ok   = preg_match(
        '/^(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO'
        . '|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+`?([A-Za-z0-9_$]+)`?/i',
        $head,
        $m
    );

    return $ok === 1 ? (string) $m[1] : '';
}

/**
 * Read the `--` header of a dump without restoring it.
 *
 * @return array{database:string,generated:string,server:string,tables:int}
 */
function backup_headline(string $path, string $passphrase = ''): array
{
    $info = ['database' => '', 'generated' => '', 'server' => '', 'tables' => 0];

    $text = '';
    foreach (BackupCipher::read($path, $passphrase) as $chunk) {
        $text .= $chunk;
        if (strlen($text) > 2048) {
            break;   // the header is the first few lines; never read the whole file
        }
    }

    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^--\s*(Database|Generated|Server|Tables):\s*(.*)$/', trim($line), $m) === 1) {
            $key = strtolower($m[1]);
            if ($key === 'tables') {
                $info['tables'] = (int) $m[2];
            } else {
                $info[$key] = trim($m[2]);
            }
        }
    }

    return $info;
}

/**
 * Prove a dump is still readable.
 *
 * Two questions, answered separately, because the answers mean different
 * things: does the file still hash to what it hashed to when it was written
 * (rot, truncation, tampering), and does it still decrypt and parse (the key
 * or the passphrase). A dump that passes the first and fails the second has
 * not been damaged - the key has changed.
 *
 * @return array{ok:bool,checksum_ok:bool,readable:bool,statements:int,bytes:int,error:string}
 */
function backup_verify(array $record, string $passphrase = '', bool $deep = true): array
{
    $out = ['ok' => false, 'checksum_ok' => false, 'readable' => false,
            'statements' => 0, 'bytes' => 0, 'error' => ''];

    $path = backup_resolve_path((string) ($record['filename'] ?? ''));
    if ($path === null) {
        $out['error'] = 'The file is no longer in the backups folder.';
        return $out;
    }

    $out['bytes'] = (int) filesize($path);
    $stored       = (string) ($record['checksum'] ?? '');
    $actual       = backup_checksum($path);

    if ($stored === '') {
        // Taken before checksums existed: nothing to compare against, so say
        // so rather than reporting a pass that was never checked.
        $out['error'] = 'No checksum was recorded when this backup was taken.';
    } else {
        $out['checksum_ok'] = hash_equals($stored, $actual);
        if (!$out['checksum_ok']) {
            $out['error'] = 'The file has changed since it was taken - it may be damaged or altered.';
            return $out;
        }
    }

    if (!$deep) {
        $out['ok'] = $out['checksum_ok'];
        return $out;
    }

    try {
        foreach (backup_sql_statements(BackupCipher::read($path, $passphrase)) as $statement) {
            $out['statements']++;
        }
        $out['readable'] = $out['statements'] > 0;
        if (!$out['readable']) {
            $out['error'] = 'The file decrypted but contained no SQL.';
        }
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }

    $out['ok'] = $out['readable'] && ($out['checksum_ok'] || $stored === '');
    return $out;
}

// ===========================================================================
//  Restoring
// ===========================================================================

/**
 * What a restore can and cannot do here. Shown verbatim in the UI and printed
 * by the CLI, because the worst moment to discover a limit is mid-restore.
 *
 * @return string[]
 */
function backup_restore_caveats(): array
{
    return [
        'It replaces the tables in this database - ' . DB_NAME . '. Everything written since the '
            . 'backup was taken is gone, and there is no undo: MySQL cannot roll a DROP TABLE back.',
        'It restores the database only. Uploaded images, invoice PDFs and config/app.key.php are '
            . 'files on disk and are not in the dump.',
        'It needs DROP and CREATE rights for the database user. Most shared hosts grant them on '
            . 'the panel-created database; some do not.',
        'From the browser it runs inside one request, so a host time limit can stop it part-way and '
            . 'leave some tables restored and others not. The bigger the database, the likelier that '
            . 'is - run php bin/backup.php --restore=FILE from SSH or a cron job instead, where '
            . 'nothing is watching the clock.',
        'It cannot create or rename a database, change a user\'s grants, or restore into a server '
            . 'this installation has no credentials for. Moving to a new host means creating the '
            . 'empty database there first and pointing config/db.local.php at it.',
    ];
}

/**
 * Execute a dump against the current database.
 *
 * @param array{passphrase?:string,tables?:string[],max_seconds?:int,stop_on_error?:bool} $options
 *        `tables` restores only the named tables - the one-table recovery that
 *        is nearly always what someone actually wants after a bad edit, and
 *        what the round-trip self-test uses.
 *
 * @return array{complete:bool,statements:int,rows:int,tables:string[],skipped:int,errors:string[],seconds:float}
 */
function backup_restore(string $path, array $options = []): array
{
    $passphrase  = (string) ($options['passphrase'] ?? '');
    $only        = isset($options['tables']) && is_array($options['tables'])
        ? array_map('strval', $options['tables'])
        : null;
    $maxSeconds  = (int) ($options['max_seconds'] ?? (PHP_SAPI === 'cli' ? 0 : BACKUP_RESTORE_WEB_SECONDS));
    $stopOnError = (bool) ($options['stop_on_error'] ?? true);

    $pdo   = Database::connect();
    $began = microtime(true);
    $out   = ['complete' => false, 'statements' => 0, 'rows' => 0, 'tables' => [],
              'skipped' => 0, 'errors' => [], 'seconds' => 0.0];

    @set_time_limit($maxSeconds > 0 ? $maxSeconds + 60 : 0);
    ignore_user_abort(true);

    // Foreign keys off for the run: the dump writes tables in whatever order
    // SHOW TABLES gave, and a child table can legitimately land before its
    // parent. Switched back on in the finally, even when a statement throws.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        foreach (backup_sql_statements(BackupCipher::read($path, $passphrase)) as $statement) {
            $table = backup_statement_table($statement);

            if ($only !== null && $table !== '' && !in_array($table, $only, true)) {
                $out['skipped']++;
                continue;
            }

            try {
                // exec(), not a prepared statement: a single INSERT here can
                // carry 100 rows of escaped text, and DDL has no placeholders
                // to bind. The SQL comes from a dump this installation wrote
                // and authenticated frame by frame on the way in.
                $affected = $pdo->exec($statement);
                $out['statements']++;
                if ($affected !== false && stripos(ltrim($statement), 'INSERT') === 0) {
                    $out['rows'] += (int) $affected;
                }
                if ($table !== '' && !in_array($table, $out['tables'], true)) {
                    $out['tables'][] = $table;
                }
            } catch (Throwable $e) {
                $out['errors'][] = mb_substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 300)
                    . ' [' . mb_substr((string) preg_replace('/\s+/', ' ', $statement), 0, 120) . ']';
                if ($stopOnError) {
                    return $out;
                }
            }

            if ($maxSeconds > 0 && (microtime(true) - $began) > $maxSeconds) {
                $out['errors'][] = 'Stopped after ' . $maxSeconds . ' seconds so the request would not be '
                    . 'killed mid-statement. Some tables are restored and some are not. Finish the job from '
                    . 'the command line: php bin/backup.php --restore=' . basename($path);
                return $out;
            }
        }

        $out['complete'] = $out['errors'] === [];
    } finally {
        $out['seconds'] = round(microtime(true) - $began, 2);
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            // Connection-scoped; a failure here only affects this connection.
        }
    }

    return $out;
}

/**
 * Prove the whole chain works on this server, in about a second.
 *
 * Dumps one small table to a temporary file, checksums it, reads it back and
 * parses it. It answers the question the owner actually has - "will the
 * nightly backup work here?" - without dumping the whole catalogue, and it
 * catches the three things that really break on shared hosting: no openssl,
 * an unwritable folder, and a missing application key.
 *
 * @return array{ok:bool,steps:array<int,array{label:string,ok:bool,detail:string}>}
 */
function backup_selftest(string $passphrase = ''): array
{
    $steps = [];
    $add   = static function (string $label, bool $ok, string $detail = '') use (&$steps): bool {
        $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        return $ok;
    };

    $temp = null;

    try {
        $status = backup_dir_status();
        if (!$add('The backups folder is writable', $status['writable'], $status['path'])) {
            return ['ok' => false, 'steps' => $steps];
        }

        $add(
            'The folder is out of the web\'s reach',
            $status['outside_web_root'] || $status['guarded'],
            $status['outside_web_root'] ? 'outside the document root' : 'inside the document root, denied by .htaccess'
        );

        if (!$add('AES-256-GCM is available', in_array('aes-256-gcm', openssl_get_cipher_methods(), true))) {
            return ['ok' => false, 'steps' => $steps];
        }

        if ($passphrase === '' && !$add(
            'An application key is available',
            app_key_available(),
            'config/app.key.php - or set a backup passphrase instead'
        )) {
            return ['ok' => false, 'steps' => $steps];
        }

        // `settings` is small, always present, and never empty.
        $temp   = backup_dir() . '/selftest-' . bin2hex(random_bytes(6)) . '.sql.enc';
        $result = backup_write_dump($temp, ['passphrase' => $passphrase, 'tables' => ['settings']]);

        $add(
            'A dump can be written and encrypted',
            $result['bytes'] > 0,
            format_bytes($result['bytes']) . ', ' . number_format($result['rows']) . ' rows'
        );

        $add('Its checksum can be taken', $result['checksum'] !== '', substr($result['checksum'], 0, 16) . '...');

        $statements = 0;
        foreach (backup_sql_statements(BackupCipher::read($temp, $passphrase)) as $statement) {
            $statements++;
        }
        $add('It decrypts and parses back into SQL', $statements > 0, number_format($statements) . ' statements');
    } catch (Throwable $e) {
        $add('The self-test finished', false, $e->getMessage());
    } finally {
        if ($temp !== null && is_file($temp)) {
            @unlink($temp);
        }
    }

    $ok = true;
    foreach ($steps as $step) {
        $ok = $ok && $step['ok'];
    }

    return ['ok' => $ok, 'steps' => $steps];
}
