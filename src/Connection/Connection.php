<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database Connection Manager
 *
 * Manages PDO connections with support for:
 * - Multiple database drivers (MySQL, PostgreSQL, SQLite)
 * - Connection pooling and reuse (via Connection\Pool)
 * - Automatic reconnection on connection loss (SQLSTATE 08* / driver errors)
 * - Health monitoring (via isHealthy / HealthCheck)
 * - Read/write splitting
 *
 * @package Infocyph\DBLayer\Connection
 * @author Hasan
 */
final class Connection
{
    /**
     * Connection timeout in seconds.
     */
    private const CONNECTION_TIMEOUT = 5;

    /**
     * Maximum reconnection attempts for a single failing operation.
     */
    private const MAX_RECONNECT_ATTEMPTS = 3;

    /**
     * Connection configuration.
     */
    private ConnectionConfig $config;

    /**
     * Write PDO connection.
     */
    private ?PDO $pdo = null;

    /**
     * Read replica PDO connection.
     */
    private ?PDO $readPdo = null;

    /**
     * Query statistics.
     *
     * @var array{queries:int,writes:int,reads:int,errors:int}
     */
    private array $stats = [
      'queries' => 0,
      'writes'  => 0,
      'reads'   => 0,
      'errors'  => 0,
    ];

    /**
     * Create a new connection instance.
     */
    public function __construct(ConnectionConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    /**
     * Run a delete statement.
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Disconnect from database (write + read).
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->readPdo = null;
    }

    /**
     * Execute a statement with automatic reconnection on connection loss.
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        $isWrite = $this->isWriteQuery($sql);
        $pdo     = $isWrite ? $this->getPdo() : $this->getReadPdo();

        try {
            $statement = $this->runStatement($pdo, $sql, $bindings);
            $this->recordQuery($isWrite);

            return $statement;
        } catch (PDOException $e) {
            // Check if it looks like a connection-level error; if not, fail fast.
            if (!$this->isConnectionError($e)) {
                $this->stats['errors']++;
                throw ConnectionException::queryFailed($sql, $e->getMessage());
            }

            // Attempt reconnect (bounded backoff).
            $this->handleReconnectForPdo($pdo);

            try {
                $statement = $this->runStatement(
                  $isWrite ? $this->getPdo() : $this->getReadPdo(),
                  $sql,
                  $bindings
                );
                $this->recordQuery($isWrite);

                return $statement;
            } catch (PDOException $e2) {
                $this->stats['errors']++;
                throw ConnectionException::queryFailed($sql, $e2->getMessage());
            }
        }
    }

    /**
     * Get the connection configuration.
     */
    public function getConfig(): ConnectionConfig
    {
        return $this->config;
    }

    /**
     * Get database name.
     */
    public function getDatabaseName(): string
    {
        return $this->config->getDatabase();
    }

    /**
     * Get driver name.
     */
    public function getDriverName(): string
    {
        return $this->config->getDriver();
    }

    /**
     * Get the write PDO connection.
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        // No ping here – we trust PDO until it throws.
        return $this->pdo;
    }

    /**
     * Get read PDO connection (for read/write splitting).
     */
    public function getReadPdo(): PDO
    {
        // If no read config, use write connection.
        if (!$this->config->hasReadConfig()) {
            return $this->getPdo();
        }

        if ($this->readPdo === null) {
            $this->connectRead();
        }

        // Same rule: no ping; rely on exceptions + reconnect on demand.
        return $this->readPdo ?? $this->getPdo(); // fallback if read connect failed.
    }

    /**
     * Get connection statistics.
     *
     * @return array{queries:int,writes:int,reads:int,errors:int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Run an insert statement.
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->execute($sql, $bindings)->rowCount() > 0;
    }

    /**
     * Check if in transaction.
     */
    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    /**
     * Check if connection is healthy (lightweight ping).
     *
     * This is used by HealthCheck / Pool, not on every query.
     */
    public function isHealthy(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /**
     * Reset statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
          'queries' => 0,
          'writes'  => 0,
          'reads'   => 0,
          'errors'  => 0,
        ];
    }

    /**
     * Rollback a transaction.
     */
    public function rollBack(): bool
    {
        return $this->getPdo()->rollBack();
    }

    /**
     * Run a select statement.
     *
     * @return array<int, array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->execute($sql, $bindings);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run an update statement.
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Build DSN string for the configured driver.
     */
    private function buildDsn(?array $config = null): string
    {
        $config = $config ?? $this->config->toArray();
        $driver = $config['driver'] ?? $this->config->getDriver();

        return match ($driver) {
            'mysql'  => $this->buildMySqlDsn($config),
            'pgsql'  => $this->buildPostgreSqlDsn($config),
            'sqlite' => $this->buildSqliteDsn($config),
            default  => throw ConnectionException::unsupportedDriver($driver),
        };
    }

    /**
     * Build MySQL DSN.
     */
    private function buildMySqlDsn(array $config): string
    {
        if (!empty($config['unix_socket'])) {
            return "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}";
        }

        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        if (!empty($config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }

        return $dsn;
    }

    /**
     * Build PostgreSQL DSN.
     */
    private function buildPostgreSqlDsn(array $config): string
    {
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        if (!empty($config['schema'])) {
            $dsn .= ";options='--search_path={$config['schema']}'";
        }

        return $dsn;
    }

    /**
     * Build SQLite DSN.
     */
    private function buildSqliteDsn(array $config): string
    {
        return "sqlite:{$config['database']}";
    }

    /**
     * Establish write database connection.
     */
    private function connect(): void
    {
        try {
            $this->pdo = new PDO(
              $this->buildDsn(),
              $this->config->getUsername(),
              $this->config->getPassword(),
              $this->getConnectionOptions()
            );

            $this->runPostConnectCommands($this->pdo);
        } catch (PDOException $e) {
            throw ConnectionException::connectionFailed(
              $this->config->getDriver(),
              $e->getMessage()
            );
        }
    }

    /**
     * Establish read replica connection.
     */
    private function connectRead(): void
    {
        $readConfig = $this->config->getReadConfig();

        try {
            $this->readPdo = new PDO(
              $this->buildDsn($readConfig),
              $readConfig['username'] ?? $this->config->getUsername(),
              $readConfig['password'] ?? $this->config->getPassword(),
              $this->getConnectionOptions()
            );

            $this->runPostConnectCommands($this->readPdo);
        } catch (PDOException) {
            // Silent fallback to write connection; readPdo stays null.
            $this->readPdo = null;
        }
    }

    /**
     * Reconnect to database with exponential backoff (write or read).
     */
    private function reconnect(bool $isWrite): void
    {
        $attempt = 0;

        while ($attempt < self::MAX_RECONNECT_ATTEMPTS) {
            $attempt++;

            try {
                if ($isWrite) {
                    $this->pdo = null;
                    $this->connect();
                } else {
                    $this->readPdo = null;
                    $this->connectRead();
                }

                return;
            } catch (ConnectionException) {
                if ($attempt >= self::MAX_RECONNECT_ATTEMPTS) {
                    throw ConnectionException::maxReconnectAttemptsReached(self::MAX_RECONNECT_ATTEMPTS);
                }

                // Exponential-ish backoff: 100ms, 200ms, 300ms...
                usleep(100_000 * $attempt);
            }
        }
    }

    /**
     * Decide which connection to reconnect based on the PDO instance.
     */
    private function handleReconnectForPdo(PDO $pdo): void
    {
        // If we have a read connection and it matches, reconnect read;
        // otherwise reconnect write.
        $isRead = ($this->readPdo !== null && $pdo === $this->readPdo);

        $this->reconnect(!$isRead);
    }

    /**
     * Get PDO connection options.
     */
    private function getConnectionOptions(): array
    {
        $defaults = [
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES   => false,
          PDO::ATTR_STRINGIFY_FETCHES  => false,
          PDO::ATTR_TIMEOUT            => self::CONNECTION_TIMEOUT,
        ];

        // Let user-provided options override defaults.
        return array_replace($defaults, $this->config->getOptions());
    }

    /**
     * MySQL post-connect commands.
     *
     * @return string[]
     */
    private function getMySqlPostConnectCommands(): array
    {
        $commands = [];

        if ($timezone = $this->config->get('timezone')) {
            $commands[] = "SET time_zone = '{$timezone}'";
        }

        if ($this->config->get('strict', true)) {
            $commands[] = "SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'";
        }

        return $commands;
    }

    /**
     * PostgreSQL post-connect commands.
     *
     * @return string[]
     */
    private function getPostgreSqlPostConnectCommands(): array
    {
        $commands = [];

        if ($timezone = $this->config->get('timezone')) {
            $commands[] = "SET TIME ZONE '{$timezone}'";
        }

        if ($schema = $this->config->get('schema')) {
            $commands[] = "SET search_path TO {$schema}";
        }

        return $commands;
    }

    /**
     * SQLite post-connect commands.
     *
     * @return string[]
     */
    private function getSqlitePostConnectCommands(): array
    {
        return [
          'PRAGMA foreign_keys = ON',
          'PRAGMA journal_mode = WAL',
        ];
    }

    /**
     * Determine if query is a write operation.
     */
    private function isWriteQuery(string $sql): bool
    {
        $sql       = ltrim($sql);
        $firstWord = strtoupper(substr($sql, 0, strcspn($sql, " \t\n\r")));

        return in_array($firstWord, [
          'INSERT', 'UPDATE', 'DELETE',
          'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
        ], true);
    }

    /**
     * Classify PDOExceptions that look like connection errors.
     */
    private function isConnectionError(PDOException $e): bool
    {
        // Prefer SQLSTATE if available.
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[0]) && is_string($info[0])) {
            $sqlState = $info[0];

            // SQLSTATE "08xxx" = connection exception (per SQL standard).
            if (str_starts_with($sqlState, '08')) {
                return true;
            }
        }

        // Fallback on vendor-specific codes (as strings).
        $code = (string) $e->getCode();

        // Common MySQL connection errors.
        if (in_array($code, ['2002', '2006', '2013'], true)) {
            return true;
        }

        // PostgreSQL TCP / connection errors sometimes surface as 7 / 57P01 etc.
        if (in_array($code, ['7', '57P01', '57P02', '57P03'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Run post-connection commands for a given PDO.
     */
    private function runPostConnectCommands(PDO $pdo): void
    {
        $commands = match ($this->config->getDriver()) {
            'mysql'  => $this->getMySqlPostConnectCommands(),
            'pgsql'  => $this->getPostgreSqlPostConnectCommands(),
            'sqlite' => $this->getSqlitePostConnectCommands(),
            default  => [],
        };

        foreach ($commands as $command) {
            $pdo->exec($command);
        }
    }

    /**
     * Execute a prepared statement on a given PDO instance.
     */
    private function runStatement(PDO $pdo, string $sql, array $bindings): PDOStatement
    {
        $statement = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($parameter, $value, $this->getParameterType($value));
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Get PDO parameter type.
     */
    private function getParameterType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    /**
     * Record query statistics based on read/write classification.
     */
    private function recordQuery(bool $isWrite): void
    {
        $this->stats['queries']++;

        if ($isWrite) {
            $this->stats['writes']++;
        } else {
            $this->stats['reads']++;
        }
    }
}
