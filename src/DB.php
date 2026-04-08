<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Infocyph\DBLayer\Cache\Cache;
use Infocyph\DBLayer\Cache\Strategies\CacheStrategy;
use Infocyph\DBLayer\Cache\Strategies\FileStrategy;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Support\Logger;
use Infocyph\DBLayer\Support\Profiler;
use Infocyph\DBLayer\Support\Str;
use Infocyph\DBLayer\Support\Telemetry;
use PDO;
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
    /**
     * Shared cache manager instance.
     */
    protected static ?Cache $cache = null;
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
     * Maximum number of query log entries to retain (null = unbounded).
     */
    protected static ?int $maxQueryLogEntries = null;

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
     * Shared result processor used by repository helpers.
     */
    protected static ?ResultProcessor $resultProcessor = null;

    /**
     * Dynamically pass methods to the default connection.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /**
     * Add a database connection configuration (and instantiate shared Connection).
     *
     * @param  array<string,mixed>|ConnectionConfig  $config
     */
    public static function addConnection(array|ConnectionConfig $config, string $name = 'default'): Connection
    {
        $configObject = static::normalizeConfig($config);

        static::$connectionConfigs[$name] = $configObject;
        static::$connections[$name]       = new Connection($configObject);
        static::$pool?->addConfig($name, $configObject);

        if (static::$defaultConnection === null) {
            static::$defaultConnection = $name;
        }

        return static::$connections[$name];
    }

    /**
     * Execute multiple queries in sequence.
     *
     * @param  list<array{0:string,1:array<int,mixed>|null}>  $queries
     * @return list<array<int,mixed>>
     */
    public static function batch(array $queries, ?string $connection = null): array
    {
        $results = [];

        foreach ($queries as $query) {
            [$sql, $bindings] = $query;
            $results[]        = static::select($sql, $bindings ?? [], $connection);
        }

        return $results;
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
    public static function cache(?CacheStrategy $strategy = null): Cache
    {
        if (static::$cache === null) {
            static::$cache = new Cache($strategy);

            return static::$cache;
        }

        if ($strategy !== null) {
            static::$cache->setStrategy($strategy);
        }

        return static::$cache;
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
        $name = static::resolveConnectionName($name);

        $config = static::$connectionConfigs[$name];

        if ($fresh) {
            // Fresh, non-cached Connection for this DB config.
            return new Connection($config);
        }

        // Shared singleton: lazily (re)instantiate if missing.
        if (! isset(static::$connections[$name])) {
            static::$connections[$name] = new Connection($config);
        }

        return static::$connections[$name];
    }

    /**
     * Execute a delete statement.
     *
     * @param  array<int,mixed>  $bindings
     *
     * @throws ConnectionException
     */
    public static function delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->delete($query, $bindings);
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
        unset(static::$connections[$name]);
    }

    /**
     * Enable facade query logger integration.
     */
    public static function enableLogger(?string $logFile = null): void
    {
        static::logger($logFile)->enable();
        static::ensureEventsHooked();
    }

    /**
     * Enable facade query profiler integration.
     */
    public static function enableProfiler(): void
    {
        static::profiler()->enable();
        static::ensureEventsHooked();
    }

    /**
     * Enable the query log.
     */
    public static function enableQueryLog(): void
    {
        static::$loggingQueries = true;
        static::ensureEventsHooked();
    }

    /**
     * Enable telemetry collection from query/transaction events.
     */
    public static function enableTelemetry(): void
    {
        Telemetry::enable();
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
     * @param  null|callable(array<string,mixed>):void  $exporter
     * @return array<string,mixed>
     */
    public static function flushTelemetry(?callable $exporter = null): array
    {
        return Telemetry::flush($exporter);
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
        return static::orderedQueryLog();
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
     * @param  array<int,mixed>  $bindings
     *
     * @throws ConnectionException
     */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->insert($query, $bindings);
    }

    /**
     * Get last insert ID.
     *
     * @throws ConnectionException
     */
    public static function lastInsertId(?string $name = null, ?string $connection = null): string|false
    {
        return static::connection($connection)->getPdo()->lastInsertId($name);
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
     * @param  callable(array<string,mixed>):void  $callback
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
        static::ensureEventsHooked();
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
     * @param  array<string,int>  $poolConfig
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
     * @param  array<string,int>  $poolConfig
     */
    public static function poolManager(array $poolConfig = []): PoolManager
    {
        if (static::$poolManager === null) {
            static::$poolManager = new PoolManager(static::pool($poolConfig));
        }

        return static::$poolManager;
    }

    /**
     * Get shared profiler instance.
     */
    public static function profiler(): Profiler
    {
        if (static::$profiler === null) {
            static::$profiler = new Profiler();
        }

        return static::$profiler;
    }

    /**
     * Purge all connections and facade state.
     */
    public static function purge(): void
    {
        static::$pool?->closeAll();

        static::$connections        = [];
        static::$connectionConfigs  = [];
        static::$defaultConnection  = null;
        static::$queryLog           = [];
        static::$queryLogCount      = 0;
        static::$queryLogStart      = 0;
        static::$listeners          = [];
        static::$loggingQueries     = false;
        static::$eventsHooked       = false;
        static::$maxQueryLogEntries = null;
        static::$cache              = null;
        static::$logger             = null;
        static::$pool               = null;
        static::$poolManager        = null;
        static::$profiler           = null;
        static::$resultProcessor    = null;
        Telemetry::clear();
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
    public static function raw(mixed $value): mixed
    {
        return static::connection()->raw((string) $value);
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
        $name = $name ?? static::$defaultConnection;

        if ($name === null || ! isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        $config = static::$connectionConfigs[$name];

        // Drop existing shared instance (if any) and create a new one.
        static::$connections[$name] = new Connection($config);

        return static::$connections[$name];
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
        $normalizedTable = static::normalizeTableName($table);

        return new class ($conn, $normalizedTable, static::resultProcessor()) extends Repository {
            private string $table;

            public function __construct(Connection $connection, string $table, ResultProcessor $results)
            {
                parent::__construct(
                    $connection,
                    $connection->getGrammarInstance(),
                    $connection->getExecutorInstance(),
                    $results,
                );
                $this->table = $table;
            }

            protected function table(): string
            {
                return $this->table;
            }
        };
    }

    /**
     * Get shared result processor instance.
     */
    public static function resultProcessor(): ResultProcessor
    {
        if (static::$resultProcessor === null) {
            static::$resultProcessor = new ResultProcessor();
        }

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
     * Execute a select statement.
     *
     * @param  array<int,mixed>  $bindings
     * @return list<array<string,mixed>>
     *
     * @throws ConnectionException
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return static::connection($connection)->select($query, $bindings);
    }

    /**
     * Execute a select statement and return the first result.
     *
     * @param  array<int,mixed>  $bindings
     *
     * @throws ConnectionException
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $records = static::select($query, $bindings, $connection);

        return array_shift($records);
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
     * Pass null or <= 0 for unbounded.
     */
    public static function setMaxQueryLogEntries(?int $max): void
    {
        static::$maxQueryLogEntries = $max !== null && $max > 0 ? $max : null;
        static::reconfigureQueryLogStorage();
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
     * Execute a statement (INSERT/UPDATE/DELETE/DDL).
     *
     * @param  array<int,mixed>  $bindings
     *
     * @throws ConnectionException
     */
    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        static::connection($connection)->execute($query, $bindings);

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
     *   total_queries:int
     * }
     *
     * @throws ConnectionException
     */
    public static function stats(?string $connection = null): array
    {
        $conn = static::connection($connection);

        return [
            'driver'            => $conn->getDriverName(),
            'database'          => $conn->getDatabaseName(),
            'prefix'            => $conn->getTablePrefix(),
            'transaction_level' => $conn->transactionLevel(),
            'total_queries'     => static::$queryLogCount,
        ];
    }

    public static function supportsJson(?string $connection = null): bool
    {
        return static::connection($connection)->supportsJson();
    }

    public static function supportsReturning(?string $connection = null): bool
    {
        return static::connection($connection)->supportsReturning();
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
     *   timeouts:int,
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
     * Execute an unprepared statement.
     *
     * This is an alias for execute() without bindings, kept for convenience.
     *
     * @throws ConnectionException
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        static::connection($connection)->execute($query);

        return true;
    }

    /**
     * Execute an update statement.
     *
     * @param  array<int,mixed>  $bindings
     *
     * @throws ConnectionException
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->update($query, $bindings);
    }

    /**
     * Switch cache strategy to file-backed persistence.
     */
    public static function useFileCache(?string $directory = null): Cache
    {
        return static::cache(new FileStrategy($directory));
    }

    /**
     * Get server version.
     *
     * @throws ConnectionException
     */
    public static function version(?string $connection = null): string
    {
        return (string) static::connection($connection)
          ->getPdo()
          ->getAttribute(PDO::ATTR_SERVER_VERSION);
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
        $name = static::resolveConnectionName($connection);

        return static::poolManager()->using(
            $name,
            static fn(Connection $pooled): mixed => $callback($pooled),
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
     * Normalize a connection configuration into a ConnectionConfig instance.
     *
     * @param  array<string,mixed>|ConnectionConfig  $config
     */
    protected static function normalizeConfig(array|ConnectionConfig $config): ConnectionConfig
    {
        return $config instanceof ConnectionConfig
          ? $config
          : ConnectionConfig::fromArray($config);
    }

    /**
     * Append one query-log entry (supports bounded ring-buffer mode).
     *
     * @param  array<string,mixed>  $entry
     */
    private static function appendQueryLogEntry(array $entry): void
    {
        $max = static::$maxQueryLogEntries;

        if ($max === null) {
            static::$queryLog[] = $entry;
            static::$queryLogCount++;

            return;
        }

        if ($max <= 0) {
            return;
        }

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
     * Ensure the global query event listener is registered.
     *
     * Bridges typed QueryExecuted events into the DB facade
     * listener list and query log.
     */
    private static function ensureEventsHooked(): void
    {
        if (static::$eventsHooked) {
            return;
        }

        static::$eventsHooked = true;

        Events::listen('db.query.executing', static function (QueryExecuting $event): void {
            unset($event);

            if (static::$profiler !== null && static::$profiler->isEnabled()) {
                static::$profiler->start();
            }
        });

        Events::listen('db.query.executed', function (QueryExecuted $event): void {
            $profilerEnabled = static::$profiler !== null && static::$profiler->isEnabled();
            $loggerEnabled = static::$logger !== null && static::$logger->isEnabled();

            // Skip work if nothing is interested.
            if (! static::$loggingQueries && static::$listeners === [] && ! $profilerEnabled && ! $loggerEnabled) {
                return;
            }

            $connectionName = array_find_key(static::$connections, fn($connection) => $connection === $event->connection);

            $payload = [
                'query'      => $event->sql,
                'bindings'   => $event->bindings,
                'time'       => $event->time, // ms
                'connection' => $connectionName,
                'rows'       => $event->rowsAffected,
            ];

            if ($profilerEnabled) {
                static::$profiler?->finish($event->sql, $event->bindings);
            }

            if ($loggerEnabled) {
                static::$logger?->query($event->sql, $event->bindings, $event->time);
            }

            foreach (static::$listeners as $listener) {
                $listener($payload);
            }

            if (static::$loggingQueries) {
                static::appendQueryLogEntry($payload);
            }
        });
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

        return Str::snake($table);
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

        if (static::$maxQueryLogEntries === null) {
            return static::$queryLog;
        }

        $ordered = [];
        $max     = static::$maxQueryLogEntries;

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
        $ordered = static::orderedQueryLog();
        $max     = static::$maxQueryLogEntries;

        if ($max !== null && \count($ordered) > $max) {
            $ordered = \array_slice($ordered, -$max);
        }

        static::$queryLog = \array_values($ordered);
        static::$queryLogCount = \count(static::$queryLog);
        static::$queryLogStart = 0;
    }

    /**
     * Resolve and validate connection name against registered configs.
     *
     * @throws ConnectionException
     */
    private static function resolveConnectionName(?string $name): string
    {
        $name ??= static::$defaultConnection;

        if ($name === null || ! isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        return $name;
    }
}
