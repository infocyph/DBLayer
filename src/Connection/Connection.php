<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Generator;
use Infocyph\DBLayer\Connection\Concerns\ConnectionInternals;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\Events;
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
use Throwable;

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
     * Default fetch mode for reads and streaming.
     */
    private int $fetchMode = PDO::FETCH_ASSOC;

    /**
     * SQL grammar instance for this connection (legacy path).
     */
    private ?Grammar $grammar = null;

    /**
     * Optional health monitor for this connection.
     */
    private ?HealthCheck $healthCheck = null;

    /**
     * Cached best replica index for least-latency routing.
     */
    private ?int $leastLatencyReplicaIndex = null;

    /**
     * Unix timestamp of the last least-latency probe result.
     */
    private ?int $leastLatencyResolvedAt = null;

    /**
     * Lifecycle hooks around connect/reconnect/failure events.
     *
     * @var array{
     *   beforeConnect:list<callable(self,bool):void>,
     *   afterConnect:list<callable(self,bool):void>,
     *   beforeReconnect:list<callable(self,bool,int):void>,
     *   afterReconnect:list<callable(self,bool,int):void>,
     *   onConnectionFailure:list<callable(self,bool,int,Throwable):void>
     * }
     */
    private array $lifecycleHooks = [
        'beforeConnect' => [],
        'afterConnect' => [],
        'beforeReconnect' => [],
        'afterReconnect' => [],
        'onConnectionFailure' => [],
    ];

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
     * SQL query comment context.
     *
     * @var array<string,mixed>
     */
    private array $queryCommentContext = [];

    /**
     * Optional absolute query deadline (microtime(true) timestamp).
     */
    private ?float $queryDeadlineAt = null;

    /**
     * Whether connection-level query lifecycle events are emitted.
     */
    private bool $queryEventsEnabled = true;

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
     * Prepared statement cache buckets keyed by "write"/"read" and SQL fingerprint.
     *
     * @var array{write:array<string,PDOStatement>,read:array<string,PDOStatement>}
     */
    private array $statementCache = [
        'write' => [],
        'read' => [],
    ];

    /**
     * LRU order for prepared statement cache buckets.
     *
     * @var array{write:list<string>,read:list<string>}
     */
    private array $statementCacheLru = [
        'write' => [],
        'read' => [],
    ];

    /**
     * Active PDO object ids for statement cache buckets.
     *
     * @var array{write:int|null,read:int|null}
     */
    private array $statementCachePdoIds = [
        'write' => null,
        'read' => null,
    ];

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
        $this->queryCommentContext = $this->config->getQueryCommentContext();

        // Resolve driver and compiler up front; all engines go through DriverRegistry.
        $this->driver = DriverRegistry::resolve($this->config->getDriver());
        $this->compiler = $this->driver->createCompiler();
    }

    /**
     * Register hook executed right after connecting write/read PDO.
     *
     * Hook signature: fn(Connection $connection, bool $isWrite): void
     */
    public function afterConnect(callable $hook): self
    {
        $this->registerLifecycleHook('afterConnect', $hook);

        return $this;
    }

    /**
     * Register hook executed after a successful reconnect.
     *
     * Hook signature: fn(Connection $connection, bool $isWrite, int $attempt): void
     */
    public function afterReconnect(callable $hook): self
    {
        $this->registerLifecycleHook('afterReconnect', $hook);

        return $this;
    }

    /**
     * Attach an explicit HealthCheck monitor to this connection.
     */
    public function attachHealthCheck(HealthCheck $healthCheck): void
    {
        $this->healthCheck = $healthCheck;
    }

    /**
     * Register hook executed right before connecting write/read PDO.
     *
     * Hook signature: fn(Connection $connection, bool $isWrite): void
     */
    public function beforeConnect(callable $hook): self
    {
        $this->registerLifecycleHook('beforeConnect', $hook);

        return $this;
    }

    /**
     * Register hook executed before each reconnect attempt.
     *
     * Hook signature: fn(Connection $connection, bool $isWrite, int $attempt): void
     */
    public function beforeReconnect(callable $hook): self
    {
        $this->registerLifecycleHook('beforeReconnect', $hook);

        return $this;
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
     * Reset SQL query comment context map.
     */
    public function clearQueryCommentContext(): self
    {
        $this->queryCommentContext = [];

        return $this;
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
        $this->clearStatementCache();
        $this->readReplicaIndex = null;
        $this->leastLatencyReplicaIndex = null;
        $this->leastLatencyResolvedAt = null;
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
        $isWrite = $this->isWriteQuery($sql);

        return $this->executeTypedStatement($sql, $bindings, $isWrite);
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
     * Get active fetch mode for reads/streaming.
     */
    public function getFetchMode(): int
    {
        return $this->fetchMode;
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
     * Get active SQL query comment context.
     *
     * @return array<string,string>
     */
    public function getQueryCommentContext(): array
    {
        return $this->normalizeCommentContext($this->queryCommentContext);
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
     * Merge SQL query comment context map.
     *
     * @param array<string,mixed> $context
     */
    public function mergeQueryCommentContext(array $context): self
    {
        $this->queryCommentContext = array_replace(
            $this->queryCommentContext,
            $this->normalizeCommentContext($context),
        );

        return $this;
    }

    /**
     * Register hook executed when connect/reconnect attempt fails.
     *
     * Hook signature: fn(Connection $connection, bool $isWrite, int $attempt, Throwable $error): void
     */
    public function onConnectionFailure(callable $hook): self
    {
        $this->registerLifecycleHook('onConnectionFailure', $hook);

        return $this;
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
     * Execute a callback inside a read-only transaction when supported.
     *
     * @param callable(self):mixed $callback
     */
    public function readOnlyTransaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->transaction(
            function (self $connection) use ($callback): mixed {
                $connection->applyReadOnlyTransactionMode();

                return $callback($connection);
            },
            $attempts,
        );
    }

    /**
     * Reconnect to database with exponential backoff (write or read).
     */
    public function reconnect(bool $isWrite): void
    {
        $attempt = 0;

        while ($attempt < self::MAX_RECONNECT_ATTEMPTS) {
            $attempt++;
            $this->dispatchBeforeReconnect($isWrite, $attempt);

            try {
                if ($isWrite) {
                    $this->pdo = null;
                    $this->clearStatementCacheBucket(true);
                    $this->connect();
                } else {
                    $this->readPdo = null;
                    $this->clearStatementCacheBucket(false);
                    $this->connectRead();
                }
                $this->dispatchAfterReconnect($isWrite, $attempt);

                return;
            } catch (ConnectionException $e) {
                $this->dispatchConnectionFailure($isWrite, $attempt, $e);

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
     * Compiled queries use QueryType-aware typed execution so read/write routing
     * does not rely on SQL string reclassification. This still preserves the
     * centralized execution path for security checks, retry policy, lifecycle
     * events, telemetry/performance tracking, and pretend mode behavior.
     */
    public function runCompiled(CompiledQuery $query, bool $readOnly = false): DriverResult
    {
        unset($readOnly); // reserved for future use when bypassing SQL classification

        $type = $query->type;

        if ($type === QueryType::SELECT) {
            $rows = $this->fetchAllFromKnownTypeStatement(
                $query->sql,
                $query->bindings,
                QueryType::SELECT,
            );

            return new DriverResult(array_values($rows), count($rows));
        }

        if ($type === QueryType::INSERT) {
            $rowCount = $this->executeKnownType($query->sql, $query->bindings, QueryType::INSERT)->rowCount();
            $id = $this->lastInsertId();
            $lastId = $id !== '' ? $id : null;

            return new DriverResult(null, $rowCount, $lastId);
        }

        if ($type === QueryType::UPDATE) {
            $rowCount = $this->executeKnownType($query->sql, $query->bindings, QueryType::UPDATE)->rowCount();

            return new DriverResult(null, $rowCount);
        }

        if ($type === QueryType::DELETE) {
            $rowCount = $this->executeKnownType($query->sql, $query->bindings, QueryType::DELETE)->rowCount();

            return new DriverResult(null, $rowCount);
        }

        // TRUNCATE or anything else
        $this->executeKnownType($query->sql, $query->bindings, $type);

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
        $rows = $statement->fetchAll($this->fetchMode);

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
        $fetchMode = $this->fetchMode;

        while (true) {
            /** @var list<array<string,mixed>> $rows */
            $rows = $statement->fetchAll($fetchMode);
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
     * Set default fetch mode used by read helpers and streaming.
     */
    public function setFetchMode(int $fetchMode): self
    {
        $this->fetchMode = $fetchMode;

        return $this;
    }

    /**
     * Replace SQL query comment context map.
     *
     * @param array<string,mixed> $context
     */
    public function setQueryCommentContext(array $context): self
    {
        $this->queryCommentContext = $this->normalizeCommentContext($context);

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

    /**
     * Stream query rows lazily without buffering via fetchAll().
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    public function stream(string $sql, array $bindings = [], ?int $fetchMode = null): Generator
    {
        $statement = $this->execute($sql, $bindings);
        $mode = $fetchMode ?? $this->fetchMode;

        try {
            while (true) {
                $row = $statement->fetch($mode);

                if ($row === false) {
                    break;
                }

                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
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
        $finalSql = $this->applyQueryComment($sql);

        $isWrite = $this->isWriteQuery($sql);

        return $this->executeWithRetry(
            $finalSql,
            [],
            $isWrite,
            static function (PDO $pdo) use ($finalSql): bool {
                $pdo->exec($finalSql);

                return true;
            },
            static fn(): bool => true,
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
     * Execute callback while suppressing connection-level query events.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function withoutQueryEvents(callable $callback): mixed
    {
        $previous = $this->queryEventsEnabled;
        $this->queryEventsEnabled = false;

        try {
            return $callback();
        } finally {
            $this->queryEventsEnabled = $previous;
        }
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
     * Generator alias for stream().
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    public function yieldRows(string $sql, array $bindings = [], ?int $fetchMode = null): Generator
    {
        yield from $this->stream($sql, $bindings, $fetchMode);
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
     * @param array<int|string,mixed> $bindings
     */
    private function dispatchQueryExecutedEvent(
        string $sql,
        array $bindings,
        float $durationMs,
        ?int $rowsAffected,
    ): void {
        if (!$this->queryEventsEnabled) {
            return;
        }

        Events::dispatch(
            'db.query.executed',
            [new QueryExecuted($sql, $bindings, $durationMs, $this, $rowsAffected)],
        );
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    private function dispatchQueryExecutingEvent(string $sql, array $bindings): void
    {
        if (!$this->queryEventsEnabled) {
            return;
        }

        Events::dispatch(
            'db.query.executing',
            [new QueryExecuting($sql, $bindings, $this)],
        );
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    private function dispatchQueryFailedEvent(
        string $sql,
        array $bindings,
        float $durationMs,
        Throwable $exception,
        int $attempts,
    ): void {
        if (!$this->queryEventsEnabled) {
            return;
        }

        Events::dispatch(
            'db.query.failed',
            [new QueryFailed($sql, $bindings, $durationMs, $this, $exception, $attempts)],
        );
    }

    /**
     * Execute a query with explicit type, bypassing SQL write/read classification.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function executeKnownType(string $sql, array $bindings, QueryType $type): PDOStatement
    {
        return $this->executeTypedStatement($sql, $bindings, $type !== QueryType::SELECT);
    }

    /**
     * Execute statement using known read/write intent.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function executeTypedStatement(string $sql, array $bindings, bool $isWrite): PDOStatement
    {
        $securityConfig = $this->config->securityConfig();

        if ($this->securityChecks) {
            // Will be a no-op when SecurityMode::OFF is set globally.
            Security::validateQuery($sql, $bindings, $securityConfig);
        }

        $this->enforceRateLimitIfConfigured($securityConfig);
        $finalSql = $this->applyQueryComment($sql);

        return $this->executeWithRetry(
            $finalSql,
            $bindings,
            $isWrite,
            fn(PDO $pdo): PDOStatement => $this->runStatement($pdo, $finalSql, $bindings, $isWrite, $sql),
            fn(): PDOStatement => $this->createPretendStatement($isWrite),
        );
    }

    /**
     * Run a statement lifecycle with retry/pretend/security-budget handling.
     *
     * @template TResult
     * @param array<int|string,mixed> $bindings
     * @param callable(PDO):TResult $operation
     * @param callable():TResult $pretendResult
     * @return TResult
     */
    private function executeWithRetry(
        string $sql,
        array $bindings,
        bool $isWrite,
        callable $operation,
        callable $pretendResult,
    ): mixed {
        $start = microtime(true);
        $success = false;
        $rowsAffected = null;
        $attempts = 0;
        $failure = null;
        $this->dispatchQueryExecutingEvent($sql, $bindings);

        try {
            if ($this->pretending) {
                $this->assertNotCancelled();
                $this->markExecutionSuccess($start, $isWrite, $sql, $bindings);
                $result = $pretendResult();
                $rowsAffected = $this->resolveRowsAffectedFromExecutionResult($result, $isWrite);
                $attempts = 1;
                $success = true;

                return $result;
            }

            [$result, $rowsAffected] = $this->runRetryableOperation(
                $sql,
                $bindings,
                $isWrite,
                $operation,
                $start,
                $attempts,
            );
            $success = true;

            return $result;
        } catch (Throwable $e) {
            $failure = $e;

            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1_000.0;
            $this->recordPerformanceSample($durationMs, $success);

            if ($success) {
                $this->dispatchQueryExecutedEvent($sql, $bindings, $durationMs, $rowsAffected);
            } elseif ($failure instanceof Throwable) {
                $this->dispatchQueryFailedEvent(
                    $sql,
                    $bindings,
                    $durationMs,
                    $failure,
                    max(1, $attempts),
                );
            }
        }
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    private function fetchAllFromKnownTypeStatement(string $sql, array $bindings, QueryType $type): array
    {
        $statement = $this->executeKnownType($sql, $bindings, $type);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll($this->fetchMode);

        return $rows;
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

    /**
     * @param array<int|string,mixed> $bindings
     */
    private function recoverFromExecutionFailure(
        PDOException $exception,
        int $attempt,
        string $sql,
        array $bindings,
        PDO $pdo,
        bool $isWrite,
    ): PDO {
        if (!$this->shouldRetryQuery($exception, $attempt, $sql, $bindings)) {
            $this->stats['errors']++;
            $this->recordPretend($sql, $bindings);

            throw ConnectionException::queryFailed($sql, $exception->getMessage());
        }

        if (!$this->isConnectionError($exception)) {
            return $pdo;
        }

        $this->handleReconnectForPdo($pdo);

        return $isWrite ? $this->getPdo() : $this->getReadPdo();
    }

    private function resolveRowsAffectedFromExecutionResult(mixed $result, bool $isWrite): ?int
    {
        if (!$isWrite) {
            return null;
        }

        if ($result instanceof PDOStatement) {
            return max(0, $result->rowCount());
        }

        if (is_int($result)) {
            return max(0, $result);
        }

        return null;
    }

    /**
     * @template TResult
     * @param array<int|string,mixed> $bindings
     * @param callable(PDO):TResult $operation
     * @return array{0:TResult,1:int|null}
     */
    private function runRetryableOperation(
        string $sql,
        array $bindings,
        bool $isWrite,
        callable $operation,
        float $start,
        int &$attemptsUsed,
    ): array {
        $pdo = $isWrite ? $this->getPdo() : $this->getReadPdo();
        $attempt = 0;

        while (true) {
            $attempt++;
            $attemptsUsed = $attempt;

            $this->assertNotCancelled();
            $this->assertWithinQueryBudget($start);

            try {
                $result = $operation($pdo);

                $this->markExecutionSuccess($start, $isWrite, $sql, $bindings);
                $rowsAffected = $this->resolveRowsAffectedFromExecutionResult($result, $isWrite);

                return [$result, $rowsAffected];
            } catch (PDOException $e) {
                $pdo = $this->recoverFromExecutionFailure(
                    $e,
                    $attempt,
                    $sql,
                    $bindings,
                    $pdo,
                    $isWrite,
                );
            }
        }
    }
}
