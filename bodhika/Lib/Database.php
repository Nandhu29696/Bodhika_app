<?php
/**
 * Database.php - PDO singleton
 *
 * Replaces all legacy mysql_connect() / mysqli_connect() calls.
 * Usage anywhere:
 *   $pdo = Database::getInstance()->getConnection();
 *   $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
 *   $stmt->execute([$id]);
 */
class Database
{
    /** @var Database|null */
    private static ?Database $instance = null;

    /** @var PDO */
    private PDO $pdo;

    private function __construct()
    {
        $port = defined('DB_PORT') && DB_PORT ? ';port=' . DB_PORT : '';
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET,
            $port
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error but never expose it to the browser
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('Service temporarily unavailable. Please try again later.');
        }
    }

    /** Prevent cloning */
    private function __clone() {}

    /** Get the singleton instance */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Return the PDO connection */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Convenience: prepare + execute in one call.
     * Returns the executed PDOStatement.
     *
     * @param string $sql
     * @param array  $params
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo  = self::getInstance()->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row.
     *
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * Fetch all rows.
     *
     * @param string $sql
     * @param array  $params
     * @return array
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Execute an INSERT/UPDATE/DELETE and return affected rows.
     *
     * @param string $sql
     * @param array  $params
     * @return int
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Return the last inserted auto-increment ID.
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->getConnection()->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Schema capability detection
    // -------------------------------------------------------------------------
    // Several pages support databases that are a few migrations behind (a
    // column or table added in migration_v33/v42/v43/etc. may not exist yet).
    // Historically that was handled by wrapping the *whole* query in a
    // try/catch and, on ANY PDOException, silently falling back to an older
    // query shape. That's fragile: an unrelated error (a genuinely-missing
    // optional column, a typo, a lock timeout) gets misdiagnosed as "old
    // schema" and silently routes to a fallback query with different JOIN
    // semantics — which can return the wrong (often empty) result set while
    // looking like success. These helpers let callers check for a specific
    // column/table once (memoized for the request) and build the exact SQL
    // they need, instead of catching-and-guessing.

    /** @var array<string,bool> */
    private static array $columnCache = [];

    /** @var array<string,bool> */
    private static array $tableCache = [];

    /**
     * Whether $table has a column named $column in the current database.
     * Result is memoized per request — safe to call repeatedly/in loops.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $key = strtolower($table) . '.' . strtolower($column);
        if (!array_key_exists($key, self::$columnCache)) {
            try {
                $row = self::fetchOne(
                    "SELECT 1 FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                      LIMIT 1",
                    [$table, $column]
                );
                self::$columnCache[$key] = (bool)$row;
            } catch (Exception $e) {
                self::$columnCache[$key] = false;
            }
        }
        return self::$columnCache[$key];
    }

    /**
     * Whether $table exists in the current database.
     * Result is memoized per request.
     */
    public static function tableExists(string $table): bool
    {
        $key = strtolower($table);
        if (!array_key_exists($key, self::$tableCache)) {
            try {
                $row = self::fetchOne(
                    "SELECT 1 FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = ?
                      LIMIT 1",
                    [$table]
                );
                self::$tableCache[$key] = (bool)$row;
            } catch (Exception $e) {
                self::$tableCache[$key] = false;
            }
        }
        return self::$tableCache[$key];
    }

    // -------------------------------------------------------------------------
    // Transaction helpers
    // -------------------------------------------------------------------------

    /**
     * Begin a PDO transaction.
     * Safe to call even if one is already open (no-op in that case).
     */
    public static function beginTransaction(): void
    {
        $pdo = self::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }

    /**
     * Commit the active transaction.
     */
    public static function commit(): void
    {
        $pdo = self::getInstance()->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Roll back the active transaction (safe to call even if no transaction is open).
     */
    public static function rollBack(): void
    {
        $pdo = self::getInstance()->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * Whether a transaction is currently active.
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->getConnection()->inTransaction();
    }
}
