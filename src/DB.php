<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Closure;
use Generator;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Concerns\DBBatchOperations;
use Infocyph\DBLayer\Concerns\DBQueryTimeMonitors;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\ConnectionSecurityConfigValidator;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Support\ArrayNormalizer;
use Infocyph\DBLayer\Support\ConnectionReplacementGuard;
use Infocyph\DBLayer\Support\Logger;
use Infocyph\DBLayer\Support\Profiler;
use Infocyph\DBLayer\Support\QueryExecutedBridge;
use Infocyph\DBLayer\Support\QueryFailureBridge;
use Infocyph\DBLayer\Support\TableNameNormalizer;
use Infocyph\DBLayer\Support\Telemetry;
use PDO;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Throwable;

/**
 * DB Static Facade
 *
 * Provides a convenient static interface for database operations.
 * Acts as a facade to the underlying Connection and QueryBuilder classes.
 *
 * Design goals:
 * - One shared Connection instance per DB name for the whole process lifetime.
 * - Ability to opt-in to a fresh Connection instance on demand (no caching).
 * - Bridge query events into a simple query-log & listener system.
 * - Expose per-connection health reports.
 */
class DB
{
    use DBBatchOperations;
    use DBQueryTimeMonitors;

    private const int DEFAULT_MAX_QUERY_LOG_ENTRIES = 2_000;

    /**
     * Shared cache manager instance.
     */
    protected static ?CacheInterface $cache = null;

    /**
     * Original configuration objects keyed by connection name.
     *
     * Used to build fresh Connection instances when requested.
     *
     * @var array<string,ConnectionConfig>
     */
    protected static array $connectionConfigs = [];

    /**
     * The database connections keyed by name (shared singletons).
     *
     * @var array<string,Connection>
     */
    protected static array $connections = [];

    /**
     * The default connection name.
     */
    protected static ?string $defaultConnection = 'default';

    /**
     * Whether we've registered the global event listener bridge.
     */
    protected static bool $eventsHooked = false;

    /**
     * Query event listeners (facade-level).
     *
     * @var list<callable(array<string,mixed>):void>
     */
    protected static array $listeners = [];

    /**
     * Optional query logger instance.
     */
    protected static ?Logger $logger = null;

    /**
     * Query logging enabled state.
     */
    protected static bool $loggingQueries = false;

    /**
     * Maximum number of query log entries to retain.
     */
    protected static int $maxQueryLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;

    /**
     * Optional connection pool instance.
     */
    protected static ?Pool $pool = null;

    /**
     * Optional pool manager facade.
     */
    protected static ?PoolManager $poolManager = null;

    /**
     * Optional query profiler instance.
     */
    protected static ?Profiler $profiler = null;

    /**
     * Query log entries.
     *
     * Each entry:
     *  - query (string)
     *  - bindings (list<mixed>)
     *  - time (float, ms)
     *  - connection (string|null)
     *  - rows (int|null)
     *
     * @var list<array<string,mixed>>
     */
    protected static array $queryLog = [];

    /**
     * Number of retained log entries.
     */
    protected static int $queryLogCount = 0;

    /**
     * Ring-buffer start offset when bounded logging is enabled.
     */
    protected static int $queryLogStart = 0;

    /**
     * Cumulative query-time monitors (Laravel-like threshold callbacks).
     *
     * @var list<array{
     *   threshold_ms:float,
     *   cumulative_ms:float,
     *   fired:bool,
     *   callback:callable
     * }>
     */
    protected static array $queryTimeMonitors = [];

    /**
     * Shared result processor used by repository helpers.
     */
    protected static ?ResultProcessor $resultProcessor = null;

    /**
     * Global security defaults merged into every connection config.
     *
     * @var array<string,mixed>|null
     */
    protected static ?array $securityDefaults = null;

    private static ?Closure $queryExecutedEventBridge = null;

    private static ?Closure $queryFailedEventBridge = null;

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param array<int,mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /**
     * Add a database connection configuration (and instantiate shared Connection).
     *
     * @param array<string,mixed>|ConnectionConfig $config
     */
    public static function addConnection(array|ConnectionConfig $config, string $name = 'default'): Connection
    {
        $configObject = static::normalizeConfig($config);

        ConnectionReplacementGuard::disconnect(
            static::$connections[$name] ?? null,
            "Cannot replace connection [{$name}] while it has an active transaction.",
        );

        $connection = new Connection($configObject, $name)->setQueryCache(static::$cache);

        static::$connectionConfigs[$name] = $configObject;
        static::$connections[$name] = $connection;
        static::$pool?->addConfig($name, $configObject);

        static::$defaultConnection ??= $name;

        return static::$connections[$name];
    }

    /**
     * Register a callback that runs after the selected connection commits.
     *
     * @param callable():void $callback
     */
    public static function afterCommit(callable $callback, ?string $connection = null): void
    {
        static::connection($connection)->afterCommit($callback);
    }

    /**
     * Begin a transaction.
     *
     * @throws ConnectionException
     */
    public static function beginTransaction(?string $connection = null): void
    {
        static::connection($connection)->begin();
    }

    /**
     * Get shared cache manager instance.
     */
    public static function cache(): CacheInterface
    {
        return static::$cache ?? self::initializeCache();
    }

    /**
     * Get driver capabilities for the given connection.
     */
    public static function capabilities(?string $connection = null): Capabilities
    {
        return static::connection($connection)->getCapabilities();
    }

    /**
     * Commit the active transaction.
     *
     * @throws ConnectionException
     */
    public static function commit(?string $connection = null): void
    {
        static::connection($connection)->commitTransaction();
    }

    /**
     * Get a database connection instance.
     *
     * Default behavior:
     *  - Returns a shared singleton Connection per DB name for the process lifetime.
     *
     * When $fresh = true:
     *  - Returns a new Connection instance built from the stored config.
     *  - The new instance is NOT stored in the shared registry.
     *
     * @throws ConnectionException
     */
    public static function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = self::resolveConnectionName($name);

        if (!isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name);
        }

        $config = static::$connectionConfigs[$name];

        if ($fresh) {
            return new Connection($config, $name)->setQueryCache(static::$cache);
        }

        static::$connections[$name] ??= new Connection($config, $name)->setQueryCache(static::$cache);

        return static::$connections[$name];
    }

    /**
     * Execute a delete statement.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): int => $conn->delete($query, $bindings),
            static fn(int $result): int => $result,
        );
    }

    /**
     * Disable facade query logger integration.
     */
    public static function disableLogger(): void
    {
        static::$logger?->disable();
    }

    /**
     * Disable facade query profiler integration.
     */
    public static function disableProfiler(): void
    {
        static::$profiler?->disable();
    }

    /**
     * Disable the query log.
     */
    public static function disableQueryLog(): void
    {
        static::$loggingQueries = false;
    }

    /**
     * Disable telemetry collection/export.
     */
    public static function disableTelemetry(): void
    {
        Telemetry::disable();
    }

    /**
     * Remove a shared connection instance from the registry.
     *
     * The configuration is kept so the connection can be lazily re-created.
     */
    public static function disconnect(string $name): void
    {
        if (isset(static::$connections[$name])) {
            static::$connections[$name]->disconnect();
        }

        unset(static::$connections[$name]);
    }

    /**
     * Enable facade query logger integration.
     */
    public static function enableLogger(?string $logFile = null, ?PsrLoggerInterface $psrLogger = null): void
    {
        $logger = static::logger($logFile);

        if ($psrLogger !== null) {
            $logger->setPsrLogger($psrLogger);
        }

        $logger->enable();
        self::ensureEventsHooked();
    }

    /**
     * Enable facade query profiler integration.
     */
    public static function enableProfiler(): void
    {
        static::profiler()->enable();
        self::ensureEventsHooked();
    }

    /**
     * Enable the query log.
     */
    public static function enableQueryLog(): void
    {
        static::$loggingQueries = true;
        self::ensureEventsHooked();
    }

    /**
     * Enable telemetry collection from query/transaction events.
     */
    public static function enableTelemetry(): void
    {
        Telemetry::enable();
    }

    /**
     * Inspect the database-native execution plan for a raw SELECT statement.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function explain(
        string $query,
        array $bindings = [],
        bool $analyze = false,
        bool $buffers = false,
        bool $verbose = false,
        ?string $connection = null,
    ): array {
        return static::connection($connection)->explain(
            $query,
            $bindings,
            $analyze,
            $buffers,
            $verbose,
        );
    }

    /**
     * Flush the query log.
     */
    public static function flushQueryLog(): void
    {
        static::$queryLog = [];
        static::$queryLogCount = 0;
        static::$queryLogStart = 0;
    }

    /**
     * Export and clear telemetry buffers.
     *
     * @param null|callable(array<string,mixed>):void $exporter
     * @return array<string,mixed>
     */
    public static function flushTelemetry(?callable $exporter = null): array
    {
        return Telemetry::flush($exporter);
    }

    /**
     * Export and clear telemetry buffers as OpenTelemetry-like payload.
     *
     * @param null|callable(array<string,mixed>):void $exporter
     * @return array<string,mixed>
     */
    public static function flushTelemetryOtel(
        ?callable $exporter = null,
        string $serviceName = 'dblayer',
    ): array {
        return Telemetry::flushOtel($exporter, $serviceName);
    }

    /**
     * Convenience shortcut for an uncached Connection instance.
     *
     * Equivalent to connection($name, true).
     *
     * @throws ConnectionException
     */
    public static function freshConnection(?string $name = null): Connection
    {
        return static::connection($name, true);
    }

    /**
     * Get all shared connection instances.
     *
     * @return array<string,Connection>
     */
    public static function getConnections(): array
    {
        return static::$connections;
    }

    /**
     * Get the database name.
     *
     * @throws ConnectionException
     */
    public static function getDatabaseName(?string $connection = null): string
    {
        return static::connection($connection)->getDatabaseName();
    }

    /**
     * Get the default connection name.
     */
    public static function getDefaultConnection(): ?string
    {
        return static::$defaultConnection;
    }

    /**
     * Get the database driver name.
     *
     * @throws ConnectionException
     */
    public static function getDriverName(?string $connection = null): string
    {
        return static::connection($connection)->getDriverName();
    }

    /**
     * Get the PDO instance.
     *
     * @throws ConnectionException
     */
    public static function getPdo(?string $connection = null): PDO
    {
        return static::connection($connection)->getPdo();
    }

    /**
     * Get the query log.
     *
     * @return list<array<string,mixed>>
     */
    public static function getQueryLog(): array
    {
        return self::orderedQueryLog();
    }

    /**
     * Get the table prefix.
     *
     * @throws ConnectionException
     */
    public static function getTablePrefix(?string $connection = null): string
    {
        return static::connection($connection)->getTablePrefix();
    }

    /**
     * Apply production-safe security defaults quickly.
     *
     * @param array<string,mixed> $securityOverrides
     */
    public static function hardenProduction(array $securityOverrides = [], bool $refreshExisting = true): void
    {
        $defaults = [
            'enabled' => true,
            'strict_identifiers' => true,
            'require_tls' => true,
            'raw_sql_policy' => 'deny',
        ];

        static::setSecurityDefaults(
            array_replace($defaults, $securityOverrides),
            $refreshExisting,
        );
    }

    /**
     * Determine if a connection configuration has been registered.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(static::$connectionConfigs[$name]);
    }

    /**
     * Get a health report for the given connection.
     *
     * @return array<string,mixed>
     *
     * @throws ConnectionException
     */
    public static function health(?string $connection = null): array
    {
        $conn = static::connection($connection);

        return $conn->getHealthCheck()->getReport();
    }

    /**
     * Execute an insert statement.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $result = $conn->insert($query, $bindings);

        self::trackRawQueryDuration($conn, $query, $bindings, $result ? 1 : 0, $startedAt);

        return $result;
    }

    /**
     * Schedule cache-tag invalidation after the surrounding transaction commits.
     *
     * @param list<string> $tags
     */
    public static function invalidateCacheTagsAfterCommit(
        array $tags,
        ?string $connection = null,
    ): void {
        if (static::$cache === null || $tags === []) {
            return;
        }

        $cache = static::$cache;
        static::afterCommit(
            static function () use ($cache, $tags): void {
                $cache->invalidateTags($tags);
            },
            $connection,
        );
    }

    /**
     * Get last insert ID.
     *
     * @throws ConnectionException
     */
    public static function lastInsertId(?string $name = null, ?string $connection = null): string
    {
        return static::connection($connection)->lastInsertId($name);
    }

    /**
     * Register a query event listener on the facade.
     *
     * Listener receives:
     *  - query (string)
     *  - bindings (list<mixed>)
     *  - time (float, ms)
     *  - connection (string|null)
     *  - rows (int|null)
     *
     * @param callable(array<string,mixed>):void $callback
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
        self::ensureEventsHooked();
    }

    /**
     * Get shared logger instance.
     */
    public static function logger(?string $logFile = null): Logger
    {
        if ($logFile !== null || static::$logger === null) {
            static::$logger = new Logger($logFile);
        }

        return static::$logger;
    }

    /**
     * Determine if query logging is enabled.
     */
    public static function logging(): bool
    {
        return static::$loggingQueries;
    }

    /**
     * Check if connection is alive.
     *
     * @throws ConnectionException
     */
    public static function ping(?string $connection = null): bool
    {
        try {
            static::select('SELECT 1', [], $connection);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get shared connection pool instance.
     *
     * Primarily useful in long-running workers/daemons; in PHP-FPM a request-scoped
     * shared connection is typically sufficient.
     *
     * @param array<string,int> $poolConfig
     */
    public static function pool(array $poolConfig = []): Pool
    {
        if (static::$pool === null) {
            static::$pool = new Pool($poolConfig);

            foreach (static::$connectionConfigs as $name => $config) {
                static::$pool->addConfig($name, $config);
            }
        }

        return static::$pool;
    }

    /**
     * Get shared pool manager.
     *
     * @param array<string,int> $poolConfig
     */
    public static function poolManager(array $poolConfig = []): PoolManager
    {
        static::$poolManager ??= new PoolManager(static::pool($poolConfig));

        return static::$poolManager;
    }

    /**
     * Get shared profiler instance.
     */
    public static function profiler(): Profiler
    {
        static::$profiler ??= new Profiler();

        return static::$profiler;
    }

    /**
     * Purge all connections and facade state.
     */
    public static function purge(): void
    {
        static::$pool?->closeAll();

        foreach (static::$connections as $connection) {
            $connection->disconnect();
        }

        static::$connections = static::$connectionConfigs = [];
        static::$defaultConnection = static::$cache = static::$pool = static::$poolManager = null;
        static::$resultProcessor = static::$securityDefaults = null;
        self::resetFacadeQueryObservationState();
    }

    /**
     * Aggregate buffered telemetry by normalized, parameterized query shape.
     *
     * @param list<int|float> $percentiles
     * @return array<string,mixed>
     */
    public static function queryShapeReport(
        array $percentiles = [50, 90, 95, 99],
        ?float $minimumMs = null,
        ?int $limit = 20,
    ): array {
        return Telemetry::queryShapeReport($percentiles, $minimumMs, $limit);
    }

    /**
     * Quote a value for use in a query.
     *
     * @throws ConnectionException
     */
    public static function quote(
        string $value,
        int $type = PDO::PARAM_STR,
        ?string $connection = null,
    ): string {
        return static::connection($connection)->getPdo()->quote($value, $type);
    }

    /**
     * Create a raw database expression.
     *
     * @throws ConnectionException
     */
    public static function raw(string $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Execute a callback within a read-only transaction when supported.
     *
     * @throws Throwable
     * @throws ConnectionException
     */
    public static function readOnlyTransaction(
        callable $callback,
        int $attempts = 1,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->readOnlyTransaction($callback, $attempts);
    }

    /**
     * Reconnect the shared Connection for the given database.
     *
     * Delegates to the Connection implementation.
     *
     * @throws ConnectionException
     */
    public static function reconnect(?string $name = null): Connection
    {
        $name ??= static::$defaultConnection;

        if ($name === null || !isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        if (isset(static::$connections[$name])) {
            $connection = static::$connections[$name];
            $connection->disconnect();
        } else {
            $connection = new Connection(static::$connectionConfigs[$name], $name)->setQueryCache(static::$cache);
            static::$connections[$name] = $connection;
        }

        return $connection;
    }

    /**
     * Create an explicit, bounded relation loader for the selected connection.
     */
    public static function relations(
        ?string $connection = null,
        int $batchSize = 500,
    ): \Infocyph\DBLayer\Repository\RelationLoader {
        return new \Infocyph\DBLayer\Repository\RelationLoader(
            static::connection($connection),
            $batchSize,
        );
    }

    /**
     * Build a table-backed repository.
     *
     * The table name is normalized to snake_case.
     *
     * @throws ConnectionException
     */
    public static function repository(string $table, ?string $connection = null): Repository
    {
        $conn = static::connection($connection);
        $normalizedTable = self::normalizeTableName($table);

        return new class ($conn, $normalizedTable, static::resultProcessor()) extends Repository {
            public function __construct(Connection $connection, private readonly string $table, ResultProcessor $results)
            {
                parent::__construct(
                    $connection,
                    $connection->getExecutorInstance(),
                    $results,
                );
            }

            #[\Override]
            protected function table(): string
            {
                return $this->table;
            }
        };
    }

    /**
     * Reset mutable runtime state while preserving registered connection configs.
     *
     * Useful for long-running workers to avoid cross-request leakage.
     */
    public static function resetRuntimeState(bool $disconnectConnections = true): void
    {
        if ($disconnectConnections) {
            foreach (static::$connections as $connection) {
                $connection->disconnect();
            }

            static::$connections = [];
            static::$pool?->closeAll();
            static::$pool = null;
            static::$poolManager = null;
        } else {
            foreach (static::$connections as $connection) {
                if (!$connection->resetRuntimeStateForReuse()) {
                    $connection->disconnect();
                }
            }
        }

        self::resetFacadeQueryObservationState();
    }

    /**
     * Get shared result processor instance.
     */
    public static function resultProcessor(): ResultProcessor
    {
        static::$resultProcessor ??= new ResultProcessor();

        return static::$resultProcessor;
    }

    /**
     * Rollback the active transaction.
     *
     * @throws ConnectionException
     */
    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollbackTransaction();
    }

    /**
     * Execute a query and return the first scalar value.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function scalar(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        return self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): mixed => $conn->scalar($query, $bindings),
        );
    }

    /**
     * Create an opt-in schema manager for the selected connection.
     */
    public static function schema(?string $connection = null): \Infocyph\DBLayer\Schema\SchemaManager
    {
        return new \Infocyph\DBLayer\Schema\SchemaManager(static::connection($connection));
    }

    /**
     * Execute a select statement.
     *
     * @param array<int,mixed> $bindings
     * @return list<array<string,mixed>>
     *
     * @throws ConnectionException
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        $result = self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): array => $conn->select($query, $bindings),
        );

        return array_values($result);
    }

    /**
     * Execute a select statement and return the first result.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $records = static::select($query, $bindings, $connection);

        return array_shift($records);
    }

    /**
     * Execute a query and return all result sets.
     *
     * @param array<int,mixed> $bindings
     * @return list<list<array<string,mixed>>>
     *
     * @throws ConnectionException
     */
    public static function selectResultSets(string $query, array $bindings = [], ?string $connection = null): array
    {
        return self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): array => $conn->selectResultSets($query, $bindings),
        );
    }

    /**
     * Replace the CacheLayer instance used by opt-in query caching.
     */
    public static function setCache(CacheInterface $cache): void
    {
        static::$cache = $cache;

        foreach (static::$connections as $connection) {
            $connection->setQueryCache($cache);
        }
    }

    /**
     * Set the database name.
     *
     * @throws ConnectionException
     */
    public static function setDatabaseName(string $database, ?string $connection = null): Connection
    {
        return static::connection($connection)->setDatabaseName($database);
    }

    /**
     * Set the default connection name.
     */
    public static function setDefaultConnection(string $name): void
    {
        static::$defaultConnection = $name;
    }

    /**
     * Set maximum number of facade query log entries to retain.
     *
     * Pass null to restore the safe default. Non-positive values retain one entry.
     */
    public static function setMaxQueryLogEntries(?int $max): void
    {
        static::$maxQueryLogEntries = max(1, $max ?? self::DEFAULT_MAX_QUERY_LOG_ENTRIES);
        self::reconfigureQueryLogStorage();
    }

    /**
     * Set profiler buffer limit.
     */
    public static function setProfilerMaxProfiles(?int $maxProfiles): void
    {
        static::profiler()->setMaxProfiles($maxProfiles);
    }

    /**
     * Set or clear PSR-3 logger backend for facade query logging.
     */
    public static function setPsrLogger(?PsrLoggerInterface $logger): Logger
    {
        $instance = static::logger();
        $instance->setPsrLogger($logger);

        return $instance;
    }

    /**
     * Set global security policy values for current and future connections.
     *
     * Values set here are enforced over per-connection security settings.
     *
     * @param array<string,mixed> $security
     */
    public static function setSecurityDefaults(array $security, bool $refreshExisting = true): void
    {
        ConnectionSecurityConfigValidator::validate($security);

        static::$securityDefaults = $security;
        self::applySecurityDefaultsToRegisteredConnections($refreshExisting);
    }

    /**
     * Set the table prefix.
     *
     * @throws ConnectionException
     */
    public static function setTablePrefix(string $prefix, ?string $connection = null): Connection
    {
        return static::connection($connection)->setTablePrefix($prefix);
    }

    /**
     * Set in-memory telemetry buffer limits.
     */
    public static function setTelemetryBufferLimits(?int $queryEvents = null, ?int $transactionEvents = null): void
    {
        Telemetry::setBufferLimits($queryEvents, $transactionEvents);
    }

    /**
     * Get percentile report for query durations currently buffered in telemetry.
     *
     * @param list<int|float> $percentiles
     * @return array<string,mixed>
     */
    public static function slowQueryReport(array $percentiles = [50, 90, 95, 99], ?float $minimumMs = null): array
    {
        return Telemetry::slowQueryReport($percentiles, $minimumMs);
    }

    /**
     * Execute a statement (INSERT/UPDATE/DELETE/DDL).
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $conn->execute($query, $bindings);

        self::trackRawQueryDuration($conn, $query, $bindings, null, $startedAt);

        return true;
    }

    /**
     * Get connection statistics.
     *
     * @return array{
     *   driver:string,
     *   database:string,
     *   prefix:string,
     *   transaction_level:int,
     *   total_queries:int,
     *   query_log_entries:int
     * }
     *
     * @throws ConnectionException
     */
    public static function stats(?string $connection = null): array
    {
        $conn = static::connection($connection);
        $connectionStats = $conn->getStats();

        return [
            'driver' => $conn->getDriverName(),
            'database' => $conn->getDatabaseName(),
            'prefix' => $conn->getTablePrefix(),
            'transaction_level' => $conn->transactionLevel(),
            'total_queries' => $connectionStats['queries'],
            'query_log_entries' => static::$queryLogCount,
        ];
    }

    /**
     * Stream rows lazily for large reads without buffering all rows in memory.
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     *
     * @throws ConnectionException
     */
    public static function stream(
        string $query,
        array $bindings = [],
        ?string $connection = null,
        ?int $fetchMode = null,
    ): Generator {
        yield from static::connection($connection)->stream($query, $bindings, $fetchMode);
    }

    public static function supportsJson(?string $connection = null): bool
    {
        return static::connection($connection)->supportsJson();
    }

    public static function supportsReturning(?string $connection = null): bool
    {
        return static::connection($connection)->supportsReturning();
    }

    public static function supportsWindowFunctions(?string $connection = null): bool
    {
        return static::connection($connection)->supportsWindowFunctions();
    }

    /**
     * Get a query builder for a table.
     *
     * @throws ConnectionException
     */
    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return static::connection($connection)->table($table);
    }

    /**
     * Get telemetry snapshot without clearing buffers.
     *
     * @return array<string,mixed>
     */
    public static function telemetry(): array
    {
        return Telemetry::snapshot();
    }

    /**
     * Get telemetry as OpenTelemetry-like payload without clearing buffers.
     *
     * @return array<string,mixed>
     */
    public static function telemetryOtel(string $serviceName = 'dblayer'): array
    {
        return Telemetry::snapshotOtel($serviceName);
    }

    /**
     * Execute a callback within a transaction.
     *
     * @throws Throwable
     * @throws ConnectionException
     */
    public static function transaction(
        callable $callback,
        int $attempts = 1,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->transaction($callback, $attempts);
    }

    /**
     * Get the transaction nesting level.
     *
     * @throws ConnectionException
     */
    public static function transactionLevel(?string $connection = null): int
    {
        return static::connection($connection)->transactionLevel();
    }

    /**
     * Get transaction statistics for the selected connection.
     *
     * @return array{
     *   total:int,
     *   committed:int,
     *   rolled_back:int,
     *   deadlocks:int,
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }|array{}
     *
     * @throws ConnectionException
     */
    public static function transactionStats(?string $connection = null): array
    {
        return static::connection($connection)->transactionStats();
    }

    /**
     * Stream rows using the driver's explicit bounded-memory strategy.
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     *
     * @throws ConnectionException
     */
    public static function unbufferedStream(
        string $query,
        array $bindings = [],
        ?string $connection = null,
        ?int $fetchMode = null,
        int $fetchSize = 1000,
    ): Generator {
        yield from static::connection($connection)->unbufferedStream(
            $query,
            $bindings,
            $fetchMode,
            $fetchSize,
        );
    }

    /**
     * Execute an unprepared statement.
     *
     * This is an alias for execute() without bindings, kept for convenience.
     *
     * @throws ConnectionException
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        return self::executeTimedRaw(
            $query,
            [],
            $connection,
            static fn(Connection $conn): bool => $conn->unprepared($query),
        );
    }

    /**
     * Execute an update statement.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): int => $conn->update($query, $bindings),
            static fn(int $result): int => $result,
        );
    }

    /**
     * Get server version.
     *
     * @throws ConnectionException
     */
    public static function version(?string $connection = null): string
    {
        $version = static::connection($connection)
          ->getPdo()
          ->getAttribute(PDO::ATTR_SERVER_VERSION);

        return self::stringifyScalar($version);
    }

    /**
     * Register a callback that fires once cumulative query time crosses threshold.
     *
     * Callback signatures supported:
     *  - fn(): void
     *  - fn(QueryExecuted $event): void
     *  - fn(Connection $connection, QueryExecuted $event): void
     */
    public static function whenQueryingForLongerThan(float $milliseconds, callable $callback): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        static::$queryTimeMonitors[] = [
            'threshold_ms' => $milliseconds,
            'cumulative_ms' => 0.0,
            'fired' => false,
            'callback' => $callback,
        ];

        self::ensureEventsHooked();
    }

    /**
     * Execute a callback with a pooled connection and always release it.
     *
     * @throws ConnectionException
     */
    public static function withPooledConnection(
        callable $callback,
        ?string $connection = null,
    ): mixed {
        $name = self::resolveConnectionName($connection);

        return static::poolManager()->using(
            $name,
            static function (Connection $pooled) use ($callback): mixed {
                $pooled->setQueryCache(static::$cache);

                return $callback($pooled);
            },
        );
    }

    /**
     * Execute callback with temporary query cancellation checker.
     */
    public static function withQueryCancellation(
        callable $checker,
        callable $callback,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->withQueryCancellation($checker, $callback);
    }

    /**
     * Execute callback with temporary query deadline relative to now.
     */
    public static function withQueryDeadline(
        float $seconds,
        callable $callback,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->withQueryDeadline($seconds, $callback);
    }

    /**
     * Execute callback with temporary retry policy for connection errors.
     *
     * Policy signature: fn(Throwable $error, int $attempt, string $sql, array $bindings): bool
     */
    public static function withQueryRetryPolicy(
        callable $policy,
        callable $callback,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->withQueryRetryPolicy($policy, $callback);
    }

    /**
     * Execute callback with temporary query timeout budget.
     */
    public static function withQueryTimeout(
        ?int $milliseconds,
        callable $callback,
        ?string $connection = null,
    ): mixed {
        return static::connection($connection)->withQueryTimeoutMs($milliseconds, $callback);
    }

    /**
     * Generator alias for stream().
     *
     * @param array<int,mixed> $bindings
     * @return Generator<mixed>
     *
     * @throws ConnectionException
     */
    public static function yieldRows(
        string $query,
        array $bindings = [],
        ?string $connection = null,
        ?int $fetchMode = null,
    ): Generator {
        yield from static::stream($query, $bindings, $connection, $fetchMode);
    }

    /**
     * Normalize a connection configuration into a ConnectionConfig instance.
     *
     * @param array<string,mixed>|ConnectionConfig $config
     */
    protected static function normalizeConfig(array|ConnectionConfig $config): ConnectionConfig
    {
        if ($config instanceof ConnectionConfig) {
            if (static::$securityDefaults === null) {
                return $config;
            }

            return $config->with(
                'security',
                self::mergeSecurityDefaults($config->securityConfig(), $config->getDriver()),
            );
        }

        if (static::$securityDefaults !== null) {
            $security = self::normalizeStringKeyArray($config['security'] ?? []);
            $driver = is_string($config['driver'] ?? null) ? $config['driver'] : null;
            $config['security'] = self::mergeSecurityDefaults($security, $driver);
        }

        return ConnectionConfig::fromArray($config);
    }

    /**
     * Append one query-log entry (supports bounded ring-buffer mode).
     *
     * @param array<string,mixed> $entry
     */
    private static function appendQueryLogEntry(array $entry): void
    {
        $max = static::$maxQueryLogEntries;

        if (static::$queryLogCount < $max) {
            $index = (static::$queryLogStart + static::$queryLogCount) % $max;
            static::$queryLog[$index] = $entry;
            static::$queryLogCount++;

            return;
        }

        static::$queryLog[static::$queryLogStart] = $entry;
        static::$queryLogStart = (static::$queryLogStart + 1) % $max;
    }

    /**
     * Rebuild stored configs/connections so new security defaults take effect.
     */
    private static function applySecurityDefaultsToRegisteredConnections(bool $refreshExisting): void
    {
        foreach (static::$connectionConfigs as $name => $config) {
            $normalized = $config->with(
                'security',
                self::mergeSecurityDefaults($config->securityConfig(), $config->getDriver()),
            );
            $replaceConnection = $refreshExisting || !isset(static::$connections[$name]);

            if ($replaceConnection) {
                ConnectionReplacementGuard::disconnect(
                    static::$connections[$name] ?? null,
                    "Cannot refresh connection [{$name}] security while a transaction is active.",
                );
            }

            static::$connectionConfigs[$name] = $normalized;
            static::$pool?->addConfig($name, $normalized);

            if ($replaceConnection) {
                static::$connections[$name] = new Connection($normalized, $name)->setQueryCache(static::$cache);
            }
        }
    }

    /**
     * Ensure the global query event listener is registered.
     *
     * Bridges typed QueryExecuted events into the DB facade
     * listener list and query log.
     */
    private static function ensureEventsHooked(): void
    {
        if (static::$eventsHooked && self::hasQueryLifecycleEventListeners()) {
            return;
        }

        static::$eventsHooked = true;
        self::registerEventBridges();
    }

    /**
     * @template TResult
     * @param array<int,mixed> $bindings
     * @param callable(Connection):TResult $operation
     * @param null|callable(TResult):int $rowsAffectedResolver
     * @return TResult
     */
    private static function executeTimedRaw(
        string $query,
        array $bindings,
        ?string $connection,
        callable $operation,
        ?callable $rowsAffectedResolver = null,
    ): mixed {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $result = $operation($conn);

        self::trackRawQueryDuration(
            $conn,
            $query,
            $bindings,
            $rowsAffectedResolver !== null ? $rowsAffectedResolver($result) : null,
            $startedAt,
        );

        return $result;
    }

    /**
     * Handle post-execution query event.
     */
    private static function handleQueryExecuted(QueryExecuted $event): void
    {
        QueryExecutedBridge::handle(
            $event,
            static::$connections,
            static::$profiler,
            static::$logger,
            static::$loggingQueries,
            static::$listeners,
            self::appendQueryLogEntry(...),
        );

        self::evaluateQueryTimeMonitors($event);
    }

    /**
     * Determine whether query lifecycle events currently have listeners.
     */
    private static function hasQueryLifecycleEventListeners(): bool
    {
        if (
            self::$queryExecutedEventBridge === null
            || self::$queryFailedEventBridge === null
        ) {
            return false;
        }

        return \in_array(
            self::$queryExecutedEventBridge,
            Events::getListeners('db.query.executed'),
            true,
        ) && \in_array(
            self::$queryFailedEventBridge,
            Events::getListeners('db.query.failed'),
            true,
        );
    }

    private static function initializeCache(): CacheInterface
    {
        $cache = Cache::memory('dblayer');
        static::setCache($cache);

        return $cache;
    }

    /**
     * Invoke threshold callback with a supported argument shape.
     */
    private static function invokeQueryTimeMonitor(callable $callback, QueryExecuted $event): void
    {
        if (\is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            $params = $reflection->getNumberOfParameters();
        } elseif (\is_object($callback) && !$callback instanceof \Closure) {
            $reflection = new \ReflectionMethod($callback, '__invoke');
            $params = $reflection->getNumberOfParameters();
        } else {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
            $params = $reflection->getNumberOfParameters();
        }

        if ($params <= 0) {
            $callback();

            return;
        }

        if ($params === 1) {
            $callback($event);

            return;
        }

        $callback($event->connection, $event);
    }

    /**
     * Merge global security defaults with connection-level security settings.
     *
     * @param array<string,mixed> $security
     * @return array<string,mixed>
     */
    private static function mergeSecurityDefaults(array $security, ?string $driver = null): array
    {
        if (static::$securityDefaults === null) {
            return $security;
        }

        $defaults = static::$securityDefaults;
        if ($driver !== null && in_array(strtolower($driver), ['sqlite', 'sqlite3'], true)) {
            unset($defaults['require_tls']);
        }

        return array_replace($security, $defaults);
    }

    /**
     * @param array<mixed> $bindings
     * @return list<mixed>
     */
    private static function normalizeBatchBindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            $normalized[] = $binding;
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private static function normalizeStringKeyArray(mixed $value): array
    {
        return ArrayNormalizer::stringKeyArray($value);
    }

    /**
     * Normalize an arbitrary table identifier.
     */
    private static function normalizeTableName(string $table): string
    {
        $table = trim($table);

        if ($table === '') {
            return $table;
        }

        return TableNameNormalizer::normalize($table);
    }

    /**
     * Return query log ordered from oldest to newest.
     *
     * @return list<array<string,mixed>>
     */
    private static function orderedQueryLog(): array
    {
        if (static::$queryLogCount === 0) {
            return [];
        }

        $ordered = [];
        $max = static::$maxQueryLogEntries;

        for ($i = 0; $i < static::$queryLogCount; $i++) {
            $index = (static::$queryLogStart + $i) % $max;
            $ordered[] = static::$queryLog[$index];
        }

        return $ordered;
    }

    /**
     * Rebuild internal query-log storage after max-size changes.
     */
    private static function reconfigureQueryLogStorage(): void
    {
        $ordered = self::orderedQueryLog();
        $max = static::$maxQueryLogEntries;

        if (\count($ordered) > $max) {
            $ordered = \array_slice($ordered, -$max);
        }

        static::$queryLog = $ordered;
        static::$queryLogCount = \count(static::$queryLog);
        static::$queryLogStart = 0;
    }

    /**
     * Register facade bridges for query lifecycle events.
     */
    private static function registerEventBridges(): void
    {
        self::$queryExecutedEventBridge ??= static function (QueryExecuted $event): void {
            self::handleQueryExecuted($event);
        };
        self::$queryFailedEventBridge ??= static function (QueryFailed $event): void {
            QueryFailureBridge::handle(
                $event,
                static::$connections,
                static::$profiler,
                static::$logger,
                static::$loggingQueries,
                static::$listeners,
                self::appendQueryLogEntry(...),
            );
        };

        if (!\in_array(self::$queryExecutedEventBridge, Events::getListeners('db.query.executed'), true)) {
            Events::listen('db.query.executed', self::$queryExecutedEventBridge);
        }

        if (!\in_array(self::$queryFailedEventBridge, Events::getListeners('db.query.failed'), true)) {
            Events::listen('db.query.failed', self::$queryFailedEventBridge);
        }
    }

    /**
     * Reset facade-level query observability state.
     */
    private static function resetFacadeQueryObservationState(): void
    {
        static::$queryLog = static::$listeners = static::$queryTimeMonitors = [];
        static::$queryLogCount = static::$queryLogStart = 0;
        static::$loggingQueries = false;
        static::$maxQueryLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;
        static::$logger = static::$profiler = null;
        Telemetry::resetRuntimeState();
    }

    /**
     * Resolve and validate connection name against registered configs.
     *
     * @throws ConnectionException
     */
    private static function resolveConnectionName(?string $name): string
    {
        $name ??= static::$defaultConnection;

        if ($name === null || !isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        return $name;
    }

    private static function stringifyScalar(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Feed raw facade query timings into cumulative query-time monitors.
     *
     * @param array<int,mixed> $bindings
     */
    private static function trackRawQueryDuration(
        Connection $connection,
        string $sql,
        array $bindings,
        ?int $rowsAffected,
        float $startedAt,
    ): void {
        if (
            static::$queryTimeMonitors === []
            || (static::$eventsHooked && self::hasQueryLifecycleEventListeners())
        ) {
            return;
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1_000.0;

        self::evaluateQueryTimeMonitors(
            new QueryExecuted($sql, $bindings, $elapsedMs, $connection, $rowsAffected),
        );
    }
}
