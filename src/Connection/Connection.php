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
 * - Connection pooling and reuse
 * - Automatic reconnection on connection loss
 * - Health monitoring
 * - Read/write splitting
 *
 * @package Infocyph\DBLayer\Connection
 * @author Hasan
 */
class Connection
{
    /**
     * Connection timeout in seconds
     */
    private const CONNECTION_TIMEOUT = 5;

    /**
     * Maximum reconnection attempts
     */
    private const MAX_RECONNECT_ATTEMPTS = 3;

    /**
     * Connection configuration
     */
    private ConnectionConfig $config;

    /**
     * Last connection time
     */
    private ?float $lastConnectTime = null;
    /**
     * Active PDO connection
     */
    private ?PDO $pdo = null;

    /**
     * Read replica PDO connection
     */
    private ?PDO $readPdo = null;

    /**
     * Connection attempts counter
     */
    private int $reconnectAttempts = 0;

    /**
     * Query statistics
     */
    private array $stats = [
        'queries' => 0,
        'writes' => 0,
        'reads' => 0,
        'errors' => 0,
    ];

    /**
     * Create a new connection instance
     */
    public function __construct(ConnectionConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    /**
     * Run a delete statement
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Disconnect from database
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->readPdo = null;
        $this->lastConnectTime = null;
        $this->reconnectAttempts = 0;
    }

    /**
     * Execute a statement
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        $pdo = $this->isWriteQuery($sql) ? $this->getPdo() : $this->getReadPdo();

        try {
            $statement = $pdo->prepare($sql);

            foreach ($bindings as $key => $value) {
                $parameter = is_int($key) ? $key + 1 : $key;
                $statement->bindValue(
                    $parameter,
                    $value,
                    $this->getParameterType($value)
                );
            }

            $statement->execute();

            $this->recordQuery($sql);

            return $statement;
        } catch (PDOException $e) {
            $this->stats['errors']++;
            throw ConnectionException::queryFailed($sql, $e->getMessage());
        }
    }

    /**
     * Get the connection configuration
     */
    public function getConfig(): ConnectionConfig
    {
        return $this->config;
    }

    /**
     * Get database name
     */
    public function getDatabaseName(): string
    {
        return $this->config->getDatabase();
    }

    /**
     * Get driver name
     */
    public function getDriverName(): string
    {
        return $this->config->getDriver();
    }

    /**
     * Get the PDO connection
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        if (!$this->isConnected()) {
            $this->reconnect();
        }

        return $this->pdo;
    }

    /**
     * Get read PDO connection (for read/write splitting)
     */
    public function getReadPdo(): PDO
    {
        // If no read config, use write connection
        if (!$this->config->hasReadConfig()) {
            return $this->getPdo();
        }

        if ($this->readPdo === null) {
            $this->connectRead();
        }

        if (!$this->isReadConnected()) {
            $this->reconnectRead();
        }

        return $this->readPdo;
    }

    /**
     * Get connection statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Run an insert statement
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->execute($sql, $bindings)->rowCount() > 0;
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    /**
     * Check if connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            if ($this->pdo === null) {
                return false;
            }

            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'queries' => 0,
            'writes' => 0,
            'reads' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): bool
    {
        return $this->getPdo()->rollBack();
    }

    /**
     * Run a select statement
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->execute($sql, $bindings);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run an update statement
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Build DSN string
     */
    private function buildDsn(?array $config = null): string
    {
        $config = $config ?? $this->config->toArray();
        $driver = $config['driver'] ?? $this->config->getDriver();

        return match ($driver) {
            'mysql' => $this->buildMySqlDsn($config),
            'pgsql' => $this->buildPostgreSqlDsn($config),
            'sqlite' => $this->buildSqliteDsn($config),
            default => throw ConnectionException::unsupportedDriver($driver),
        };
    }

    /**
     * Build MySQL DSN
     */
    private function buildMySqlDsn(array $config): string
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        if (!empty($config['charset'])) {
            $dsn .= ";charset={$config['charset']}";
        }

        if (!empty($config['unix_socket'])) {
            $dsn = "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}";
        }

        return $dsn;
    }

    /**
     * Build PostgreSQL DSN
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
     * Build SQLite DSN
     */
    private function buildSqliteDsn(array $config): string
    {
        return "sqlite:{$config['database']}";
    }

    /**
     * Establish database connection
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

            $this->lastConnectTime = microtime(true);
            $this->reconnectAttempts = 0;

            // Run post-connection commands
            $this->runPostConnectCommands($this->pdo);
        } catch (PDOException $e) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                $e->getMessage()
            );
        }
    }

    /**
     * Establish read replica connection
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

            // Run post-connection commands
            $this->runPostConnectCommands($this->readPdo);
        } catch (PDOException $e) {
            // Fall back to write connection on read connection failure
            $this->readPdo = null;
        }
    }

    /**
     * Get PDO connection options
     */
    private function getConnectionOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
        ];

        // Merge with custom options from config
        return array_merge($options, $this->config->getOptions());
    }

    /**
     * Get MySQL post-connect commands
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
     * Get PDO parameter type
     */
    private function getParameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Get PostgreSQL post-connect commands
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
     * Get SQLite post-connect commands
     */
    private function getSqlitePostConnectCommands(): array
    {
        return [
            'PRAGMA foreign_keys = ON',
            'PRAGMA journal_mode = WAL',
        ];
    }

    /**
     * Check if connected to database
     */
    private function isConnected(): bool
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
     * Check if read connection is active
     */
    private function isReadConnected(): bool
    {
        if ($this->readPdo === null) {
            return false;
        }

        try {
            $this->readPdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Determine if query is a write operation
     */
    private function isWriteQuery(string $sql): bool
    {
        $sql = ltrim($sql);
        $firstWord = strtoupper(substr($sql, 0, strcspn($sql, " \t\n\r")));

        return in_array($firstWord, ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE']);
    }

    /**
     * Reconnect to database
     */
    private function reconnect(): void
    {
        $this->reconnectAttempts++;

        if ($this->reconnectAttempts > self::MAX_RECONNECT_ATTEMPTS) {
            throw ConnectionException::maxReconnectAttemptsReached();
        }

        $this->disconnect();
        usleep(100000 * $this->reconnectAttempts); // Exponential backoff
        $this->connect();
    }

    /**
     * Reconnect read connection
     */
    private function reconnectRead(): void
    {
        $this->readPdo = null;
        $this->connectRead();
    }

    /**
     * Record query statistics
     */
    private function recordQuery(string $sql): void
    {
        $this->stats['queries']++;

        if ($this->isWriteQuery($sql)) {
            $this->stats['writes']++;
        } else {
            $this->stats['reads']++;
        }
    }

    /**
     * Run post-connection commands
     */
    private function runPostConnectCommands(PDO $pdo): void
    {
        $commands = match ($this->config->getDriver()) {
            'mysql' => $this->getMySqlPostConnectCommands(),
            'pgsql' => $this->getPostgreSqlPostConnectCommands(),
            'sqlite' => $this->getSqlitePostConnectCommands(),
            default => [],
        };

        foreach ($commands as $command) {
            $pdo->exec($command);
        }
    }
}
