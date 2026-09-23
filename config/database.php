<?php
/**
 * ShopInnKart - PDO Database Layer
 *
 * Single shared connection. Every query in the application goes through the
 * helpers here so prepared statements are never bypassed.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    /** How many transaction() calls are open on the stack; >1 means nested. */
    private static int $txDepth = 0;

    private function __construct() {}

    /** Lazily open (and reuse) the single PDO connection. */
    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ];

        try {
            self::$connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            self::handleFailure($e);
        }

        return self::$connection;
    }

    /** True when the database is reachable — used by the installer check. */
    public static function isAvailable(): bool
    {
        try {
            self::connect();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function handleFailure(PDOException $e): void
    {
        $message = '[' . date('Y-m-d H:i:s') . '] DB CONNECTION FAILED: ' . $e->getMessage() . PHP_EOL;
        @file_put_contents(LOG_PATH . '/db-error.log', $message, FILE_APPEND);

        if (APP_DEBUG) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        // Installer not run yet / DB down: give the visitor a usable page.
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 120');
        }
        exit('<!doctype html><meta charset="utf-8"><title>Service Unavailable</title>'
            . '<div style="font-family:system-ui;max-width:520px;margin:14vh auto;text-align:center">'
            . '<h1 style="font-size:20px">Something went wrong. Please try again.</h1>'
            . '<p style="color:#6b7280">We are unable to reach the store database right now.</p></div>');
    }

    // -----------------------------------------------------------------------
    // Query helpers — all of them use prepared statements.
    // -----------------------------------------------------------------------

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row, or null. */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch all rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Fetch the first column of the first row. */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        $value = self::query($sql, $params)->fetchColumn($column);
        return $value === false ? null : $value;
    }

    /** Fetch a flat list of the first column across all rows. */
    public static function fetchColumnAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /** Fetch rows keyed by the first column, valued by the second. */
    public static function fetchPairs(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * A table or column name that is safe to put between backticks.
     *
     * Every other value in this layer goes through a bound parameter; an
     * identifier cannot, so it is checked instead. A backtick in a key would
     * close the quoting and turn an array key into SQL. No caller passes
     * request-controlled keys today - this is what keeps that true.
     */
    private static function identifier(string $name, string $what): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Refusing to build SQL from an unsafe %s name: %s', $what, $name)
            );
        }

        return $name;
    }

    /** INSERT from an associative array. Returns the new id. */
    public static function insert(string $table, array $data): int
    {
        $columns = array_map(
            static fn ($c): string => self::identifier((string) $c, 'column'),
            array_keys($data)
        );
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            self::identifier($table, 'table'),
            implode('`, `', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        self::query($sql, $data);
        return (int) self::connect()->lastInsertId();
    }

    /** UPDATE from an associative array. Returns affected row count. */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $column = self::identifier((string) $column, 'column');
            $sets[] = sprintf('`%s` = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', self::identifier($table, 'table'), implode(', ', $sets), $where);
        return self::query($sql, array_merge($params, $whereParams))->rowCount();
    }

    /** DELETE with a mandatory WHERE clause. */
    public static function delete(string $table, string $where, array $params = []): int
    {
        if (trim($where) === '') {
            throw new InvalidArgumentException('Refusing to DELETE without a WHERE clause.');
        }
        return self::query(sprintf('DELETE FROM `%s` WHERE %s', self::identifier($table, 'table'), $where), $params)->rowCount();
    }

    /** COUNT(*) helper. */
    public static function count(string $table, string $where = '1', array $params = []): int
    {
        return (int) self::fetchColumn(sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', self::identifier($table, 'table'), $where), $params);
    }

    public static function exists(string $table, string $where, array $params = []): bool
    {
        return self::count($table, $where, $params) > 0;
    }

    // -----------------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------------

    public static function beginTransaction(): void
    {
        $pdo = self::connect();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }

    public static function commit(): void
    {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public static function rollBack(): void
    {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * Run a callback inside a transaction, rolling back on any exception.
     * The callback receives the PDO instance.
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::connect();

        // Nested call (update_order_status() inside a shipment update, say): a
        // savepoint. Without it the inner commit() committed the OUTER work
        // half-way through, and an inner rollback took the outer work with it
        // while the outer code carried on as if nothing had happened.
        if (self::$txDepth > 0 && $pdo->inTransaction()) {
            $savepoint = 'sik_sp_' . self::$txDepth;
            $pdo->exec('SAVEPOINT ' . $savepoint);
            self::$txDepth++;
            try {
                $result = $callback($pdo);
            } catch (Throwable $e) {
                self::$txDepth--;
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                    // The outer rollback will undo it anyway.
                }
                throw $e;
            }
            self::$txDepth--;
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        }

        self::beginTransaction();
        self::$txDepth = 1;
        try {
            $result = $callback($pdo);
            self::$txDepth = 0;
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::$txDepth = 0;
            self::rollBack();
            throw $e;
        }
    }

    /** True while a transaction() callback is running. */
    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    /** Build "IN (?, ?, ?)" placeholders safely for a list of values. */
    public static function inPlaceholders(array $values, string $prefix = 'p'): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return [implode(', ', $placeholders), $params];
    }
}

/** Convenience accessor used across the app. */
function db(): PDO
{
    return Database::connect();
}
