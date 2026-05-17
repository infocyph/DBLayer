<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Connection\Concerns\ConnectionInternals;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\DriverResult;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Executor;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Security\Security;
use Infocyph\DBLayer\Transaction\TransactionManager;
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
    use ConnectionInternals;

    /**
     * Hard upper bound for retry-policy guided query retries.
     */
    private const int MAX_QUERY_RETRY_ATTEMPTS = 5;

    /**
     * Maximum reconnection attempts for a single failing operation.
     */
    private const int MAX_RECONNECT_ATTEMPTS = 3;

    /**
     * Shared in-memory PDO used to build empty statement handles in pretend mode.
     */
    private static ?PDO $pretendPdo = null;

    /**
     * Query compiler for this connection.
     */
    private readonly QueryCompilerInterface $compiler;

    /**
     * Driver for this connection.
     */
    private readonly DriverInterface $driver;

    /**
     * Query executor for this connection (legacy path).
     */
    private ?Executor $executor = null;

    /**
     * SQL grammar instance for this connection (legacy path).
     */
    private ?Grammar $grammar = null;

    /**
     * Optional health monitor for this connection.
     */
    private ?HealthCheck $healthCheck = null;

    /**
     * Write PDO connection.
     */
    private ?PDO $pdo = null;

    /**
     * Whether connection is in non-executing "pretend" mode.
     */
    private bool $pretending = false;

    /**
     * Optional cancellation checker called before query attempts.
     *
     * @var null|callable():bool
     */
    private $queryCancellationChecker;

    /**
     * Optional absolute query deadline (microtime(true) timestamp).
     */
    private ?float $queryDeadlineAt = null;

    /**
     * Optional query recorder for "pretend" mode.
     *
     * @var null|callable(string,array<int|string,mixed>):void
     */
    private $queryRecorder;

    /**
     * Optional retry policy callback for connection errors.
     *
     * @var null|callable(\Throwable,int,string,array<int|string,mixed>):bool
     */
    private $queryRetryPolicy;

    /**
     * Optional per-query timeout budget in milliseconds.
     */
    private ?int $queryTimeoutMs = null;

    /**
     * Read replica PDO connection.
     */
    private ?PDO $readPdo = null;

    /**
     * Round-robin cursor for read-replica selection.
     */
    private int $readReplicaCursor = 0;

    /**
     * Last selected read-replica index.
     */
    private ?int $readReplicaIndex = null;

    /**
     * Last measured read-replica latencies (milliseconds), keyed by replica index.
     *
     * @var array<int,float>
     */
    private array $readReplicaLatenciesMs = [];

    /**
     * Temporary read-replica suppression map: index => unix timestamp when retry is allowed.
     *
     * @var array<int,int>
     */
    private array $readReplicaUnavailableUntil = [];

    /**
     * Indicates whether a write has occurred on this connection instance.
     *
     * Used by sticky read-after-write behavior.
     */
    private bool $recordsModified = false;

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
        'writes' => 0,
        'reads' => 0,
        'errors' => 0,
    ];

    /**
     * Table prefix used by the grammar.
     */
    private string $tablePrefix = '';

    /**
     * Transaction manager for this connection.
     */
    private ?TransactionManager $transactionManager = null;

    /**
     * Create a new connection instance.
     */
    public function __construct(/**
     * Connection configuration.
     */
        private ConnectionConfig $config,
    ) {
        $prefix = $this->config->get('prefix');
        $this->tablePrefix = is_string($prefix) ? $prefix : '';
        $this->securityChecks = $this->config->isSecurityEnabled();

        // Resolve driver and compiler up front; all engines go through DriverRegistry.
        $this->driver = DriverRegistry::resolve($this->config->getDriver());
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
     * Begin transaction using the transaction manager (supports nesting/savepoints).
     */
    public function begin(): void
    {
        $this->getTransactionManager()->begin($this);
    }

    /**
     * Begin a transaction (raw PDO-level).
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * Alias for getCapabilities() to improve readability in higher-level code.
     */
    public function capabilities(): Capabilities
    {
        return $this->getCapabilities();
    }

    /**
     * Commit a transaction (raw PDO-level).
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    /**
     * Commit transaction using the transaction manager.
     */
    public function commitTransaction(): void
    {
        $this->getTransactionManager()->commit($this);
    }

    /**
     * Run a delete statement.
     *
     * @param array<int|string,mixed> $bindings
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
        if (!Security::isInsecureModeAllowed()) {
            throw SecurityException::unsafeOperation(
                'Disabling connection-level SQL security checks is blocked outside local/testing environments.',
            );
        }

        $this->securityChecks = false;
    }

    /**
     * Disconnect from database (write + read).
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->readPdo = null;
        $this->readReplicaIndex = null;
        $this->readReplicaLatenciesMs = [];
        $this->readReplicaUnavailableUntil = [];
        $this->recordsModified = false;
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
     * @param array<int|string,mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        $securityConfig = $this->config->securityConfig();

        if ($this->securityChecks) {
            // Will be a no-op when SecurityMode::OFF is set globally.
            Security::validateQuery($sql, $bindings, $securityConfig);
        }

        $this->enforceRateLimitIfConfigured($securityConfig);

        $isWrite = $this->isWriteQuery($sql);

        return $this->executeWithRetry(
            $sql,
            $bindings,
            $isWrite,
            fn(PDO $pdo): PDOStatement => $this->runStatement($pdo, $sql, $bindings),
            $this->createPretendStatement($isWrite),
        );
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
     * Expose the configured query executor instance.
     */
    public function getExecutorInstance(): Executor
    {
        return $this->getExecutor();
    }

    /**
     * Expose the configured grammar instance.
     */
    public function getGrammarInstance(): Grammar
    {
        return $this->getGrammar();
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
     * Get the write PDO connection.
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        if ($this->pdo === null) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                'Write PDO was not initialized.',
            );
        }

        return $this->pdo;
    }

    /**
     * Get active per-query timeout budget in milliseconds.
     */
    public function getQueryTimeoutMs(): ?int
    {
        return $this->queryTimeoutMs;
    }

    /**
     * Get read PDO connection (for read/write splitting).
     */
    public function getReadPdo(): PDO
    {
        if ($this->shouldUseWritePdoForRead()) {
            return $this->getPdo();
        }

        if (!$this->config->hasReadConfig()) {
            return $this->getPdo();
        }

        if ($this->readPdo === null) {
            $this->connectRead();
        }

        return $this->readPdo ?? $this->getPdo();
    }

    /**
     * Get read-replica selection telemetry for this connection.
     *
     * @return array{
     *   strategy:string,
     *   selected_index:int|null,
     *   latencies_ms:array<int,float>
     * }
     */
    public function getReadReplicaInfo(): array
    {
        return [
            'strategy' => $this->config->getReadStrategy(),
            'selected_index' => $this->readReplicaIndex,
            'latencies_ms' => $this->readReplicaLatenciesMs,
        ];
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
     * Whether this connection has an attached HealthCheck.
     */
    public function hasHealthCheck(): bool
    {
        return $this->healthCheck !== null;
    }

    /**
     * Run an insert statement.
     *
     * @param array<int|string,mixed> $bindings
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
        $id = $this->getPdo()->lastInsertId($name);

        return $id === false ? '' : $id;
    }

    /**
     * "Pretend" to execute queries and return the list of queries that would run.
     *
     * The callback runs in non-executing mode; queries are recorded but not sent
     * to the configured database connection.
     *
     * @param callable(self):void $callback
     * @return array<int,array{sql:string,bindings:array<int|string,mixed>}>
     */
    public function pretend(callable $callback): array
    {
        $logged = [];
        $previousRecorder = $this->queryRecorder;
        $previousPretending = $this->pretending;

        $this->queryRecorder = static function (string $sql, array $bindings) use (&$logged): void {
            $logged[] = [
                'sql' => $sql,
                'bindings' => $bindings,
            ];
        };
        $this->pretending = true;

        try {
            $callback($this);
        } finally {
            $this->pretending = $previousPretending;
            $this->queryRecorder = $previousRecorder;
        }

        return $logged;
    }

    /**
     * Create a fresh query builder bound to this connection.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this, $this->getGrammar(), $this->getExecutor());
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
            'writes' => 0,
            'reads' => 0,
            'errors' => 0,
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
     * Roll back transaction using the transaction manager.
     */
    public function rollbackTransaction(): void
    {
        $this->getTransactionManager()->rollback($this);
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

            return new DriverResult(array_values($rows), count($rows));
        }

        if ($type === QueryType::INSERT) {
            $success = $this->insert($query->sql, $query->bindings);
            $rowCount = $success ? 1 : 0;
            $id = $this->lastInsertId();
            $lastId = $id !== '' ? $id : null;

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
     * Run a query and return the first column from the first row.
     *
     * @param array<int|string,mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->select($sql, $bindings)[0] ?? null;

        if (!is_array($row) || $row === []) {
            return null;
        }

        return $row[array_key_first($row)];
    }

    /**
     * Run a select statement.
     *
     * @param array<int|string,mixed> $bindings
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
     * Run a query that may return multiple result sets.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<list<array<string,mixed>>>
     */
    public function selectResultSets(string $sql, array $bindings = []): array
    {
        $statement = $this->execute($sql, $bindings);
        $results = [];

        while (true) {
            /** @var list<array<string,mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $results[] = $rows;

            try {
                $hasMore = $statement->nextRowset();
            } catch (PDOException) {
                $hasMore = false;
            }

            if ($hasMore !== true) {
                break;
            }
        }

        return $results;
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
     * Set absolute query deadline timestamp (microtime(true) format).
     */
    public function setQueryDeadlineAt(?float $deadlineAt): self
    {
        $this->queryDeadlineAt = $deadlineAt;

        return $this;
    }

    /**
     * Set per-query timeout budget in milliseconds.
     */
    public function setQueryTimeoutMs(?int $timeoutMs): self
    {
        $next = $timeoutMs !== null && $timeoutMs > 0 ? $timeoutMs : null;

        if ($this->queryTimeoutMs === $next) {
            return $this;
        }

        $this->queryTimeoutMs = $next;
        $this->syncServerSideStatementTimeouts();

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
     * @param array<int|string,mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->execute($sql, $bindings);

        return true;
    }

    public function supportsInsertIgnore(): bool
    {
        return $this->driver->getCapabilities()->supportsInsertIgnore;
    }

    public function supportsJson(): bool
    {
        return $this->driver->getCapabilities()->supportsJson;
    }

    /**
     * Capability helpers – useful for QueryBuilder / grammar decisions.
     */
    public function supportsReturning(): bool
    {
        return $this->driver->getCapabilities()->supportsReturning;
    }

    public function supportsSavepoints(): bool
    {
        return $this->driver->getCapabilities()->supportsSavepoints;
    }

    public function supportsSchemas(): bool
    {
        return $this->driver->getCapabilities()->supportsSchemas;
    }

    public function supportsUpsert(): bool
    {
        return $this->driver->getCapabilities()->supportsUpsert;
    }

    public function supportsWindowFunctions(): bool
    {
        return $this->driver->getCapabilities()->supportsWindowFunctions;
    }

    /**
     * Get a query builder for the given table.
     */
    public function table(string $table): QueryBuilder
    {
        return $this->query()->from($table);
    }

    /**
     * Execute a callback within a transaction using the Transaction wrapper.
     *
     * @param callable(self):mixed $callback
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->getTransactionManager()->execute(
            $this,
            static fn(self $connection): mixed => $callback($connection),
            $attempts,
        );
    }

    /**
     * Get the transaction nesting level (via Transaction wrapper).
     */
    public function transactionLevel(): int
    {
        return $this->getTransactionManager()->level($this);
    }

    /**
     * Get transaction statistics for this connection.
     *
     * @return array{
     *   total:int,
     *   committed:int,
     *   rolled_back:int,
     *   deadlocks:int,
     *   timeouts:int,
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }|array{}
     */
    public function transactionStats(): array
    {
        return $this->getTransactionManager()->getStats($this);
    }

    /**
     * Execute an unprepared statement.
     */
    public function unprepared(string $sql): bool
    {
        $securityConfig = $this->config->securityConfig();

        if ($this->securityChecks) {
            Security::validateQuery($sql, [], $securityConfig);
        }

        $this->enforceRateLimitIfConfigured($securityConfig);

        $isWrite = $this->isWriteQuery($sql);

        return $this->executeWithRetry(
            $sql,
            [],
            $isWrite,
            static function (PDO $pdo) use ($sql): bool {
                $pdo->exec($sql);

                return true;
            },
            true,
        );
    }

    /**
     * Run an update statement.
     *
     * @param array<int|string,mixed> $bindings
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Execute callback with temporary cancellation checker.
     */
    public function withQueryCancellation(callable $checker, callable $callback): mixed
    {
        $previous = $this->queryCancellationChecker;
        $this->queryCancellationChecker = $checker;

        try {
            return $callback();
        } finally {
            $this->queryCancellationChecker = $previous;
        }
    }

    /**
     * Execute callback with an absolute deadline relative to now.
     */
    public function withQueryDeadline(float $seconds, callable $callback): mixed
    {
        $previous = $this->queryDeadlineAt;
        $deadlineAt = microtime(true) + max(0.0, $seconds);
        $this->queryDeadlineAt = $previous === null ? $deadlineAt : min($previous, $deadlineAt);

        try {
            return $callback();
        } finally {
            $this->queryDeadlineAt = $previous;
        }
    }

    /**
     * Execute callback with temporary retry policy.
     *
     * Retry policy signature:
     *  fn(Throwable $error, int $attempt, string $sql, array $bindings): bool
     */
    public function withQueryRetryPolicy(callable $policy, callable $callback): mixed
    {
        $previous = $this->queryRetryPolicy;
        $this->queryRetryPolicy = $policy;

        try {
            return $callback();
        } finally {
            $this->queryRetryPolicy = $previous;
        }
    }

    /**
     * Execute callback with temporary query timeout.
     */
    public function withQueryTimeoutMs(?int $timeoutMs, callable $callback): mixed
    {
        $previous = $this->queryTimeoutMs;
        $this->setQueryTimeoutMs($timeoutMs);

        try {
            return $callback();
        } finally {
            $this->setQueryTimeoutMs($previous);
        }
    }

    /**
     * Shared PDO used only to fabricate PDOStatement instances in pretend mode.
     */
    private static function pretendPdo(): PDO
    {
        if (self::$pretendPdo === null) {
            self::$pretendPdo = new PDO('sqlite::memory:', '', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return self::$pretendPdo;
    }

    /**
     * Run a statement lifecycle with retry/pretend/security-budget handling.
     *
     * @template TResult
     * @param array<int|string,mixed> $bindings
     * @param callable(PDO):TResult $operation
     * @param TResult $pretendResult
     * @return TResult
     */
    private function executeWithRetry(
        string $sql,
        array $bindings,
        bool $isWrite,
        callable $operation,
        mixed $pretendResult,
    ): mixed {
        $start = microtime(true);
        $success = false;

        try {
            if ($this->pretending) {
                $this->assertNotCancelled();
                $this->markExecutionSuccess($start, $isWrite, $sql, $bindings);
                $success = true;

                return $pretendResult;
            }

            $pdo = $isWrite ? $this->getPdo() : $this->getReadPdo();
            $attempt = 0;

            while (true) {
                $attempt++;

                $this->assertNotCancelled();
                $this->assertWithinQueryBudget($start);

                try {
                    $result = $operation($pdo);

                    $this->markExecutionSuccess($start, $isWrite, $sql, $bindings);
                    $success = true;

                    return $result;
                } catch (PDOException $e) {
                    if (!$this->isConnectionError($e) || !$this->shouldRetryQuery($e, $attempt, $sql, $bindings)) {
                        $this->stats['errors']++;
                        $this->recordPretend($sql, $bindings);

                        throw ConnectionException::queryFailed($sql, $e->getMessage());
                    }

                    $this->handleReconnectForPdo($pdo);
                    $pdo = $isWrite ? $this->getPdo() : $this->getReadPdo();
                }
            }
        } finally {
            $durationMs = (microtime(true) - $start) * 1_000.0;
            $this->recordPerformanceSample($durationMs, $success);
        }
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    private function markExecutionSuccess(float $start, bool $isWrite, string $sql, array $bindings): void
    {
        $this->assertWithinQueryBudget($start);
        $this->recordQuery($isWrite);
        $this->recordPretend($sql, $bindings);
    }
}
