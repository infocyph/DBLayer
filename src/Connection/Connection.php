<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\DriverResult;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Executor;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Security\Security;
use Infocyph\DBLayer\Transaction\Transaction;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database Connection Manager
 *
 * Manages PDO connections with support for:
 * - Multiple database drivers (MySQL, PostgreSQL, SQLite, custom)
 * - Read/write splitting
 * - Automatic reconnection on connection loss
 * - Lightweight health checks
 * - Query statistics & performance sampling
 * - Query builder / grammar wiring (legacy path)
 * - Driver + compiler pipeline for structured queries
 * - Optional SQL security validation (config-driven)
 */
final class Connection
{
    /**
     * Connection timeout in seconds (reserved; configured via driver options).
     */
    private const CONNECTION_TIMEOUT = 5;

    /**
     * Maximum reconnection attempts for a single failing operation.
     */
    private const MAX_RECONNECT_ATTEMPTS = 3;

    /**
     * Query compiler for this connection.
     */
    private QueryCompilerInterface $compiler;

    /**
     * Connection configuration.
     */
    private ConnectionConfig $config;

    /**
     * Driver for this connection.
     */
    private DriverInterface $driver;

    /**
     * Query executor for this connection (legacy path).
     */
    private ?Executor $executor = null;

    /**
     * SQL grammar instance for this connection (legacy path).
     */
    private ?Grammar $grammar = null;

    /**
     * Write PDO connection.
     */
    private ?PDO $pdo = null;

    /**
     * Optional query recorder for "pretend" mode.
     *
     * @var null|callable(string,array<int|string,mixed>):void
     */
    private $queryRecorder = null;

    /**
     * Read replica PDO connection.
     */
    private ?PDO $readPdo = null;

    /**
     * Whether to run SQL security checks (heuristics, length, bindings).
     */
    private bool $securityChecks;

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
     * Table prefix used by the grammar.
     */
    private string $tablePrefix = '';

    /**
     * Transaction wrapper for this connection.
     */
    private ?Transaction $transaction = null;

    /**
     * Optional health monitor for this connection.
     */
    private ?HealthCheck $healthCheck = null;

    /**
     * Create a new connection instance.
     */
    public function __construct(ConnectionConfig $config)
    {
        $this->config         = $config;
        $this->tablePrefix    = (string) ($config->get('prefix') ?? '');
        $this->securityChecks = $config->isSecurityEnabled();

        // Resolve driver and compiler up front; all engines go through DriverRegistry.
        $this->driver   = DriverRegistry::resolve($config->getDriver());
        $this->compiler = $this->driver->createCompiler();
    }

    /**
     * Attach an explicit HealthCheck monitor to this connection.
     */
    public function attachHealthCheck(HealthCheck $healthCheck): void
    {
        $this->healthCheck = $healthCheck;
    }

    /**
     * Lazily create / get the HealthCheck monitor for this connection.
     */
    public function getHealthCheck(): HealthCheck
    {
        if ($this->healthCheck === null) {
            $this->healthCheck = new HealthCheck($this);
        }

        return $this->healthCheck;
    }

    /**
     * Whether this connection has an attached HealthCheck.
     */
    public function hasHealthCheck(): bool
    {
        return $this->healthCheck !== null;
    }

    /**
     * Begin a transaction (raw PDO-level).
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * Commit a transaction (raw PDO-level).
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    /**
     * Run a delete statement.
     *
     * @param  array<int|string,mixed>  $bindings
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Disable SQL security checks for this connection instance.
     */
    public function disableSecurityChecks(): void
    {
        $this->securityChecks = false;
    }

    /**
     * Disconnect from database (write + read).
     */
    public function disconnect(): void
    {
        $this->pdo     = null;
        $this->readPdo = null;
    }

    /**
     * Enable SQL security checks for this connection instance.
     */
    public function enableSecurityChecks(): void
    {
        $this->securityChecks = true;
    }

    /**
     * Execute a statement with automatic reconnection on connection loss.
     *
     * @param  array<int|string,mixed>  $bindings
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        if ($this->securityChecks) {
            // Will be a no-op when SecurityMode::OFF is set globally.
            Security::validateQuery($sql, $bindings, $this->config->securityConfig());
        }

        $isWrite = $this->isWriteQuery($sql);
        $pdo     = $isWrite ? $this->getPdo() : $this->getReadPdo();

        $start   = microtime(true);
        $success = false;

        try {
            try {
                $statement = $this->runStatement($pdo, $sql, $bindings);
                $this->recordQuery($isWrite);
                $this->recordPretend($sql, $bindings);
                $success = true;

                return $statement;
            } catch (PDOException $e) {
                if (! $this->isConnectionError($e)) {
                    $this->stats['errors']++;
                    $this->recordPretend($sql, $bindings);

                    throw ConnectionException::queryFailed($sql, $e->getMessage());
                }

                // Attempt reconnect.
                $this->handleReconnectForPdo($pdo);

                try {
                    $statement = $this->runStatement(
                      $isWrite ? $this->getPdo() : $this->getReadPdo(),
                      $sql,
                      $bindings
                    );

                    $this->recordQuery($isWrite);
                    $this->recordPretend($sql, $bindings);
                    $success = true;

                    return $statement;
                } catch (PDOException $e2) {
                    $this->stats['errors']++;
                    $this->recordPretend($sql, $bindings);

                    throw ConnectionException::queryFailed($sql, $e2->getMessage());
                }
            }
        } finally {
            $durationMs = (microtime(true) - $start) * 1_000.0;
            $this->recordPerformanceSample($durationMs, $success);
        }
    }

    /**
     * Get declared capabilities for this driver.
     */
    public function getCapabilities(): Capabilities
    {
        return $this->driver->getCapabilities();
    }

    /**
     * Get the query compiler for this connection.
     */
    public function getCompiler(): QueryCompilerInterface
    {
        return $this->compiler;
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
     * Get the resolved driver instance.
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
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

        return $this->pdo;
    }

    /**
     * Get read PDO connection (for read/write splitting).
     */
    public function getReadPdo(): PDO
    {
        if (! $this->config->hasReadConfig()) {
            return $this->getPdo();
        }

        if ($this->readPdo === null) {
            $this->connectRead();
        }

        return $this->readPdo ?? $this->getPdo();
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
     * Get the table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Run an insert statement.
     *
     * @param  array<int|string,mixed>  $bindings
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
     * Check if connection is healthy.
     *
     * If a HealthCheck is attached, delegates to it; otherwise falls back to a
     * lightweight "SELECT 1" ping.
     */
    public function isHealthy(): bool
    {
        if ($this->healthCheck !== null) {
            return $this->healthCheck->isHealthy();
        }

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
     * "Pretend" to execute queries and return the list of queries that would run.
     *
     * Note: for now this still executes queries; it only records them.
     * It is primarily useful for inspecting generated SQL.
     *
     * @param  callable(self):void  $callback
     *
     * @return array<int,array{sql:string,bindings:array<int|string,mixed>}>
     */
    public function pretend(callable $callback): array
    {
        $logged           = [];
        $previousRecorder = $this->queryRecorder;

        $this->queryRecorder = static function (string $sql, array $bindings) use (&$logged): void {
            $logged[] = [
              'sql'      => $sql,
              'bindings' => $bindings,
            ];
        };

        try {
            $callback($this);
        } finally {
            $this->queryRecorder = $previousRecorder;
        }

        return $logged;
    }

    /**
     * Create a raw SQL expression.
     */
    public function raw(string $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Reconnect to database with exponential backoff (write or read).
     */
    public function reconnect(bool $isWrite): void
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

                // Linear backoff with mild scaling: 100ms, 200ms, 300ms...
                usleep(100_000 * $attempt);
            }
        }
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
     * Rollback a transaction (raw PDO-level).
     */
    public function rollBack(): bool
    {
        return $this->getPdo()->rollBack();
    }

    /**
     * Run a compiled query (new pipeline: QueryPayload → CompiledQuery → DriverResult).
     *
     * For now this method reuses the existing string-based helpers so that
     * reconnection logic, stats, security checks and pretend recording stay
     * centralized. Later we can optimize to bypass SQL classification.
     */
    public function runCompiled(CompiledQuery $query, bool $readOnly = false): DriverResult
    {
        unset($readOnly); // reserved for future use when bypassing SQL classification

        $type = $query->type;

        if ($type === QueryType::SELECT) {
            $rows = $this->select($query->sql, $query->bindings);

            return new DriverResult($rows, count($rows));
        }

        if ($type === QueryType::INSERT) {
            $success  = $this->insert($query->sql, $query->bindings);
            $rowCount = $success ? 1 : 0;
            $id       = $this->lastInsertId();
            $lastId   = $id !== '' ? $id : null;

            return new DriverResult(null, $rowCount, $lastId);
        }

        if ($type === QueryType::UPDATE) {
            $rowCount = $this->update($query->sql, $query->bindings);

            return new DriverResult(null, $rowCount);
        }

        if ($type === QueryType::DELETE) {
            $rowCount = $this->delete($query->sql, $query->bindings);

            return new DriverResult(null, $rowCount);
        }

        // TRUNCATE or anything else
        $this->statement($query->sql, $query->bindings);

        return new DriverResult(null, 0);
    }

    /**
     * Run a select statement.
     *
     * @param  array<int|string,mixed>  $bindings
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->execute($sql, $bindings);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Set database name (returns self for chaining).
     */
    public function setDatabaseName(string $database): self
    {
        $this->config = $this->config->with('database', $database);
        $this->disconnect();

        return $this;
    }

    /**
     * Set the table prefix (updates grammar if already created).
     */
    public function setTablePrefix(string $prefix): self
    {
        $this->tablePrefix = $prefix;

        if ($this->grammar !== null) {
            $this->grammar->setTablePrefix($prefix);
        }

        return $this;
    }

    /**
     * Execute a general statement (INSERT, UPDATE, DELETE, DDL).
     *
     * @param  array<int|string,mixed>  $bindings
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->execute($sql, $bindings);

        return true;
    }

    /**
     * Get a query builder for the given table.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder(
          $this,
          $this->getGrammar(),
          $this->getExecutor()
        )->from($table);
    }

    /**
     * Execute a callback within a transaction using the Transaction wrapper.
     *
     * @param  callable(self):mixed  $callback
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->getTransactionManager()->execute(
          static fn (self $connection): mixed => $callback($connection),
          $attempts
        );
    }

    /**
     * Get the transaction nesting level (via Transaction wrapper).
     */
    public function transactionLevel(): int
    {
        return $this->getTransactionManager()->level();
    }

    /**
     * Execute an unprepared statement.
     */
    public function unprepared(string $sql): bool
    {
        if ($this->securityChecks) {
            Security::validateQuery($sql, [], $this->config->securityConfig());
        }

        $isWrite = $this->isWriteQuery($sql);
        $pdo     = $isWrite ? $this->getPdo() : $this->getReadPdo();

        $start   = microtime(true);
        $success = false;

        try {
            try {
                $pdo->exec($sql);
                $this->recordQuery($isWrite);
                $this->recordPretend($sql, []);

                $success = true;

                return true;
            } catch (PDOException $e) {
                if (! $this->isConnectionError($e)) {
                    $this->stats['errors']++;
                    $this->recordPretend($sql, []);

                    throw ConnectionException::queryFailed($sql, $e->getMessage());
                }

                $this->handleReconnectForPdo($pdo);

                try {
                    $pdo = $isWrite ? $this->getPdo() : $this->getReadPdo();
                    $pdo->exec($sql);
                    $this->recordQuery($isWrite);
                    $this->recordPretend($sql, []);

                    $success = true;

                    return true;
                } catch (PDOException $e2) {
                    $this->stats['errors']++;
                    $this->recordPretend($sql, []);

                    throw ConnectionException::queryFailed($sql, $e2->getMessage());
                }
            }
        } finally {
            $durationMs = (microtime(true) - $start) * 1_000.0;
            $this->recordPerformanceSample($durationMs, $success);
        }
    }

    /**
     * Run an update statement.
     *
     * @param  array<int|string,mixed>  $bindings
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Establish write database connection via the driver.
     */
    private function connect(): void
    {
        try {
            $this->pdo = $this->driver->createPdo($this->config, false);
        } catch (PDOException $e) {
            throw ConnectionException::connectionFailed(
              $this->config->getDriver(),
              $e->getMessage()
            );
        }
    }

    /**
     * Establish read replica connection via the driver.
     */
    private function connectRead(): void
    {
        $readConfig = $this->config->getReadConfig();

        if ($readConfig === []) {
            $this->readPdo = null;

            return;
        }

        try {
            $merged = array_merge($this->config->toArray(), $readConfig);
            $config = ConnectionConfig::fromArray($merged);

            $this->readPdo = $this->driver->createPdo($config, true);
        } catch (PDOException | ConnectionException) {
            // Silent fallback to write connection; readPdo stays null.
            $this->readPdo = null;
        }
    }

    /**
     * Get the query executor for this connection (legacy).
     */
    private function getExecutor(): Executor
    {
        if ($this->executor === null) {
            $this->executor = new Executor($this, $this->getGrammar());
        }

        return $this->executor;
    }

    /**
     * Get the grammar instance for this connection (legacy).
     */
    private function getGrammar(): Grammar
    {
        if ($this->grammar !== null) {
            return $this->grammar;
        }

        $driverName = $this->config->getDriver();
        $grammar    = DriverProfile::createGrammar($driverName);

        if ($this->tablePrefix !== '') {
            $grammar->setTablePrefix($this->tablePrefix);
        }

        $this->grammar = $grammar;

        return $this->grammar;
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
     * Get the Transaction wrapper for this connection.
     */
    private function getTransactionManager(): Transaction
    {
        if ($this->transaction === null) {
            $this->transaction = new Transaction($this);
        }

        return $this->transaction;
    }

    /**
     * Decide which connection to reconnect based on the PDO instance.
     */
    private function handleReconnectForPdo(PDO $pdo): void
    {
        $isRead = ($this->readPdo !== null && $pdo === $this->readPdo);

        $this->reconnect(! $isRead);
    }

    /**
     * Classify PDOExceptions that look like connection errors.
     */
    private function isConnectionError(PDOException $e): bool
    {
        $info = $e->errorInfo;

        if (is_array($info) && isset($info[0]) && is_string($info[0])) {
            $sqlState = $info[0];

            if (str_starts_with($sqlState, '08')) {
                return true;
            }
        }

        $code = (string) $e->getCode();

        // MySQL connection-related errors.
        if (in_array($code, ['2002', '2006', '2013'], true)) {
            return true;
        }

        // PostgreSQL connection-related errors.
        if (in_array($code, ['7', '57P01', '57P02', '57P03'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if query is a write operation.
     */
    private function isWriteQuery(string $sql): bool
    {
        $sql       = ltrim($sql);
        $firstWord = strtoupper(substr($sql, 0, strcspn($sql, " \t\n\r")));

        return in_array(
          $firstWord,
          ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'REPLACE'],
          true
        );
    }

    /**
     * Record a query for pretend mode if enabled.
     *
     * @param  array<int|string,mixed>  $bindings
     */
    private function recordPretend(string $sql, array $bindings): void
    {
        if ($this->queryRecorder !== null) {
            ($this->queryRecorder)($sql, $bindings);
        }
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

    /**
     * Record a performance sample into HealthCheck, if attached.
     */
    private function recordPerformanceSample(float $durationMs, bool $success): void
    {
        if ($this->healthCheck !== null) {
            $this->healthCheck->recordSample($durationMs, $success);
        }
    }

    /**
     * Execute a prepared statement on a given PDO instance.
     *
     * @param  array<int|string,mixed>  $bindings
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
}
