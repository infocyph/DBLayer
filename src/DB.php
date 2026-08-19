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
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Support\ArrayNormalizer;
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

    protected static ?CacheInterface $cache = null;

    /** @var array<string,ConnectionConfig> */
    protected static array $connectionConfigs = [];

    /** @var array<string,Connection> */
    protected static array $connections = [];

    protected static ?string $defaultConnection = 'default';
    protected static bool $eventsHooked = false;

    /** @var list<callable(array<string,mixed>):void> */
    protected static array $listeners = [];

    protected static ?Logger $logger = null;
    protected static bool $loggingQueries = false;
    protected static int $maxQueryLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;
    protected static ?Pool $pool = null;
    protected static ?PoolManager $poolManager = null;
    protected static ?Profiler $profiler = null;

    /** @var list<array<string,mixed>> */
    protected static array $queryLog = [];

    protected static int $queryLogCount = 0;
    protected static int $queryLogStart = 0;

    /** @var list<array{threshold_ms:float,cumulative_ms:float,fired:bool,callback:callable}> */
    protected static array $queryTimeMonitors = [];

    protected static ?ResultProcessor $resultProcessor = null;

    /** @var array<string,mixed>|null */
    protected static ?array $securityDefaults = null;

    private static ?Closure $queryExecutedEventBridge = null;
    private static ?Closure $queryFailedEventBridge = null;

    /** @param array<int,mixed> $parameters */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /** @param array<string,mixed>|ConnectionConfig $config */
    public static function addConnection(array|ConnectionConfig $config, string $name = 'default'): Connection
    {
        $configObject = static::normalizeConfig($config);
        $existing = static::$connections[$name] ?? null;

        if ($existing instanceof Connection) {
            if ($existing->inTransaction()) {
                throw ConnectionException::invalidConfiguration(
                    "Cannot replace connection [{$name}] while it has an active transaction.",
                );
            }
            $existing->disconnect();
        }

        static::$connectionConfigs[$name] = $configObject;
        static::$connections[$name] = new Connection($configObject, $name);
        static::$pool?->addConfig($name, $configObject);

        if (static::$defaultConnection === null) {
            static::$defaultConnection = $name;
        }

        return static::$connections[$name];
    }

    /** @param callable():void $callback */
    public static function afterCommit(callable $callback, ?string $connection = null): void
    {
        static::connection($connection)->afterCommit($callback);
    }

    public static function beginTransaction(?string $connection = null): void
    {
        static::connection($connection)->begin();
    }

    public static function cache(): CacheInterface
    {
        if (static::$cache === null) {
            static::$cache = Cache::memory('dblayer');
        }

        return static::$cache;
    }

    public static function capabilities(?string $connection = null): Capabilities
    {
        return static::connection($connection)->getCapabilities();
    }

    public static function commit(?string $connection = null): void
    {
        static::connection($connection)->commitTransaction();
    }

    public static function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = self::resolveConnectionName($name);

        if (!isset(static::$connectionConfigs[$name])) {
            throw ConnectionException::connectionNotFound($name);
        }

        $config = static::$connectionConfigs[$name];

        if ($fresh) {
            return new Connection($config, $name);
        }

        if (!isset(static::$connections[$name])) {
            static::$connections[$name] = new Connection($config, $name);
        }

        return static::$connections[$name];
    }

    /** @param array<int,mixed> $bindings */
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

    public static function disableLogger(): void
    {
        static::$logger?->disable();
    }

    public static function disableProfiler(): void
    {
        static::$profiler?->disable();
    }

    public static function disableQueryLog(): void
    {
        static::$loggingQueries = false;
    }

    public static function disableTelemetry(): void
    {
        Telemetry::disable();
    }

    public static function disconnect(string $name): void
    {
        if (isset(static::$connections[$name])) {
            static::$connections[$name]->disconnect();
        }

        unset(static::$connections[$name]);
    }

    public static function enableLogger(?string $logFile = null, ?PsrLoggerInterface $psrLogger = null): void
    {
        $logger = static::logger($logFile);
        if ($psrLogger !== null) {
            $logger->setPsrLogger($psrLogger);
        }

        $logger->enable();
        self::ensureEventsHooked();
    }

    public static function enableProfiler(): void
    {
        static::profiler()->enable();
        self::ensureEventsHooked();
    }

    public static function enableQueryLog(): void
    {
        static::$loggingQueries = true;
        self::ensureEventsHooked();
    }

    public static function enableTelemetry(): void
    {
        Telemetry::enable();
    }

    /**
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

    public static function flushQueryLog(): void
    {
        static::$queryLog = [];
        static::$queryLogCount = 0;
        static::$queryLogStart = 0;
    }

    /** @param null|callable(array<string,mixed>):void $exporter @return array<string,mixed> */
    public static function flushTelemetry(?callable $exporter = null): array
    {
        return Telemetry::flush($exporter);
    }

    /** @param null|callable(array<string,mixed>):void $exporter @return array<string,mixed> */
    public static function flushTelemetryOtel(?callable $exporter = null, string $serviceName = 'dblayer'): array
    {
        return Telemetry::flushOtel($exporter, $serviceName);
    }

    public static function freshConnection(?string $name = null): Connection
    {
        return static::connection($name, true);
    }

    /** @return array<string,Connection> */
    public static function getConnections(): array
    {
        return static::$connections;
    }

    public static function getDatabaseName(?string $connection = null): string
    {
        return static::connection($connection)->getDatabaseName();
    }

    public static function getDefaultConnection(): ?string
    {
        return static::$defaultConnection;
    }

    public static function getDriverName(?string $connection = null): string
    {
        return static::connection($connection)->getDriverName();
    }

    public static function getPdo(?string $connection = null): PDO
    {
        return static::connection($connection)->getPdo();
    }

    /** @return list<array<string,mixed>> */
    public static function getQueryLog(): array
    {
        return self::orderedQueryLog();
    }

    public static function getTablePrefix(?string $connection = null): string
    {
        return static::connection($connection)->getTablePrefix();
    }

    /** @param array<string,mixed> $securityOverrides */
    public static function hardenProduction(array $securityOverrides = [], bool $refreshExisting = true): void
    {
        $defaults = [
            'enabled' => true,
            'strict_identifiers' => true,
            'require_tls' => true,
            'raw_sql_policy' => 'deny',
        ];

        static::setSecurityDefaults(array_replace($defaults, $securityOverrides), $refreshExisting);
    }

    public static function hasConnection(string $name): bool
    {
        return isset(static::$connectionConfigs[$name]);
    }

    /** @return array<string,mixed> */
    public static function health(?string $connection = null): array
    {
        $conn = static::connection($connection);

        return $conn->getHealthCheck()->getReport();
    }

    /**
     * Create an explicit on-demand database-system monitor.
     * Monitoring queries run only after a monitor method is called.
     */
    public static function monitor(?string $connection = null): DatabaseMonitor
    {
        return new DatabaseMonitor(static::connection($connection));
    }

    /** @param array<int,mixed> $bindings */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $result = $conn->insert($query, $bindings);
        self::trackRawQueryDuration($conn, $query, $bindings, $result ? 1 : 0, $startedAt);

        return $result;
    }

    /** @param list<string> $tags */
    public static function invalidateCacheTagsAfterCommit(array $tags, ?string $connection = null): void
    {
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

    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
        self::ensureEventsHooked();
    }

    public static function logger(?string $logFile = null): Logger
    {
        if (static::$logger === null) {
            static::$logger = new Logger($logFile);
        } elseif ($logFile !== null) {
            static::$logger->setLogFile($logFile);
        }

        return static::$logger;
    }

    /** @param array<string,mixed> $options */
    public static function makePool(array $options = []): Pool
    {
        static::$pool = new Pool(static::$connectionConfigs, $options);

        return static::$pool;
    }

    public static function monitorQueryDuration(float $thresholdMs, callable $callback): void
    {
        self::whenQueryingForLongerThan($thresholdMs, $callback);
    }

    public static function pool(): Pool
    {
        return static::$pool ??= new Pool(static::$connectionConfigs);
    }

    public static function poolManager(): PoolManager
    {
        return static::$poolManager ??= new PoolManager(static::pool());
    }

    public static function profiler(): Profiler
    {
        return static::$profiler ??= new Profiler();
    }

    public static function purge(?string $name = null): void
    {
        $name = self::resolveConnectionName($name);
        static::disconnect($name);
        unset(static::$connectionConfigs[$name]);
        static::$pool?->removeConfig($name);
    }

    /** @param array<int|string,mixed> $bindings */
    public static function raw(string $value): Expression
    {
        return new Expression($value);
    }

    public static function reconnect(?string $connection = null): void
    {
        $conn = static::connection($connection);
        $conn->disconnect();
        $conn->getPdo();
    }

    public static function repository(string $table, ?string $connection = null): Repository
    {
        return new Repository(static::table($table, $connection), static::resultProcessor());
    }

    public static function reset(): void
    {
        foreach (static::$connections as $connection) {
            $connection->disconnect();
        }

        static::$connections = [];
        static::$connectionConfigs = [];
        static::$defaultConnection = 'default';
        static::$pool = null;
        static::$poolManager = null;
        static::$cache = null;
        static::$logger = null;
        static::$profiler = null;
        static::$resultProcessor = null;
        static::$securityDefaults = null;
        static::$listeners = [];
        static::$loggingQueries = false;
        static::$maxQueryLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;
        static::$queryLog = [];
        static::$queryLogCount = 0;
        static::$queryLogStart = 0;
        static::$queryTimeMonitors = [];
        Telemetry::reset();
    }

    public static function resultProcessor(): ResultProcessor
    {
        return static::$resultProcessor ??= new ResultProcessor();
    }

    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollbackTransaction();
    }

    /** @param array<int,mixed> $bindings @return array<int,array<string,mixed>> */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return self::executeTimedRaw(
            $query,
            $bindings,
            $connection,
            static fn(Connection $conn): array => $conn->select($query, $bindings),
            static fn(array $result): int => count($result),
        );
    }

    public static function setCache(CacheInterface $cache): void
    {
        static::$cache = $cache;
    }

    public static function setDefaultConnection(?string $name): void
    {
        static::$defaultConnection = $name;
    }

    public static function setLogger(Logger $logger): void
    {
        static::$logger = $logger;
    }

    public static function setMaxQueryLogEntries(?int $maxEntries): void
    {
        static::$maxQueryLogEntries = $maxEntries !== null && $maxEntries > 0
            ? $maxEntries
            : self::DEFAULT_MAX_QUERY_LOG_ENTRIES;
        self::trimQueryLog();
    }

    public static function setProfiler(Profiler $profiler): void
    {
        static::$profiler = $profiler;
    }

    public static function setPsrLogger(PsrLoggerInterface $logger): void
    {
        static::logger()->setPsrLogger($logger);
    }

    /** @param array<string,mixed>|null $defaults */
    public static function setSecurityDefaults(?array $defaults, bool $refreshExisting = true): void
    {
        static::$securityDefaults = $defaults;

        if (!$refreshExisting) {
            return;
        }

        foreach (array_keys(static::$connectionConfigs) as $name) {
            $config = static::$connectionConfigs[$name];
            $values = $config->all();
            unset($values['security']);

            $next = static::normalizeConfig($values);
            static::$connectionConfigs[$name] = $next;

            if (isset(static::$connections[$name])) {
                if (static::$connections[$name]->inTransaction()) {
                    throw ConnectionException::invalidConfiguration(
                        "Cannot refresh connection [{$name}] security while it has an active transaction.",
                    );
                }
                static::$connections[$name]->disconnect();
                static::$connections[$name] = new Connection($next, $name);
            }

            static::$pool?->addConfig($name, $next);
        }
    }

    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $result = $conn->statement($query, $bindings);
        self::trackRawQueryDuration($conn, $query, $bindings, $result ? 1 : 0, $startedAt);

        return $result;
    }

    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return static::connection($connection)->query()->from(TableNameNormalizer::normalize($table));
    }

    /** @return array<string,mixed> */
    public static function telemetry(): array
    {
        return Telemetry::snapshot();
    }

    /** @return array<string,mixed> */
    public static function telemetryOtel(string $serviceName = 'dblayer'): array
    {
        return Telemetry::snapshotOtel($serviceName);
    }

    /** @return array<string,mixed> */
    public static function slowQueryReport(array $percentiles = [50, 90, 95, 99], float $minimumMs = 0.0): array
    {
        return Telemetry::slowQueryReport($percentiles, $minimumMs);
    }

    /** @return array<string,mixed> */
    public static function queryShapeReport(array $percentiles = [50, 90, 95, 99], float $minimumMs = 0.0, int $limit = 50): array
    {
        return Telemetry::queryShapeReport($percentiles, $minimumMs, $limit);
    }

    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return static::connection($connection)->transaction($callback, $attempts);
    }

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

    public static function useConnection(string $name): void
    {
        if (!static::hasConnection($name)) {
            throw ConnectionException::connectionNotFound($name);
        }

        static::$defaultConnection = $name;
    }

    public static function whenQueryingForLongerThan(float $thresholdMs, callable $callback): void
    {
        static::$queryTimeMonitors[] = [
            'threshold_ms' => max(0.0, $thresholdMs),
            'cumulative_ms' => 0.0,
            'fired' => false,
            'callback' => $callback,
        ];
        self::ensureEventsHooked();
    }

    private static function ensureEventsHooked(): void
    {
        if (static::$eventsHooked) {
            return;
        }

        static::$queryExecutedEventBridge = static function (QueryExecuted $event): void {
            QueryExecutedBridge::handle($event, static::$listeners);
        };
        static::$queryFailedEventBridge = static function (QueryFailed $event): void {
            QueryFailureBridge::handle($event);
        };

        Events::on('db.query.executed', static::$queryExecutedEventBridge);
        Events::on('db.query.failed', static::$queryFailedEventBridge);
        static::$eventsHooked = true;
    }

    /** @param array<string,mixed>|ConnectionConfig $config */
    private static function normalizeConfig(array|ConnectionConfig $config): ConnectionConfig
    {
        $values = $config instanceof ConnectionConfig ? $config->all() : $config;
        if (static::$securityDefaults !== null) {
            $existing = $values['security'] ?? [];
            $values['security'] = array_replace(
                static::$securityDefaults,
                is_array($existing) ? $existing : [],
            );
        }

        ConnectionSecurityConfigValidator::validate($values);

        return ConnectionConfig::fromArray($values);
    }

    /** @return list<array<string,mixed>> */
    private static function orderedQueryLog(): array
    {
        if (static::$queryLogCount === 0) {
            return [];
        }

        if (static::$queryLogCount < static::$maxQueryLogEntries || static::$queryLogStart === 0) {
            return array_values(array_slice(static::$queryLog, 0, static::$queryLogCount));
        }

        return array_values([
            ...array_slice(static::$queryLog, static::$queryLogStart, static::$queryLogCount - static::$queryLogStart),
            ...array_slice(static::$queryLog, 0, static::$queryLogStart),
        ]);
    }

    private static function resolveConnectionName(?string $name): string
    {
        $resolved = $name ?? static::$defaultConnection;
        if ($resolved === null || $resolved === '') {
            throw ConnectionException::connectionNotFound('default');
        }

        return $resolved;
    }

    private static function trimQueryLog(): void
    {
        $ordered = self::orderedQueryLog();
        if (count($ordered) > static::$maxQueryLogEntries) {
            $ordered = array_slice($ordered, -static::$maxQueryLogEntries);
        }

        static::$queryLog = array_values($ordered);
        static::$queryLogCount = count(static::$queryLog);
        static::$queryLogStart = 0;
    }

    private static function executeTimedRaw(string $query, array $bindings, ?string $connection, callable $execute, callable $rows): mixed
    {
        $conn = static::connection($connection);
        $startedAt = microtime(true);
        $result = $execute($conn);
        self::trackRawQueryDuration($conn, $query, $bindings, $rows($result), $startedAt);

        return $result;
    }

    private static function trackRawQueryDuration(Connection $connection, string $sql, array $bindings, ?int $rowsAffected, float $startedAt): void
    {
        $elapsedMs = (microtime(true) - $startedAt) * 1_000;
        self::recordQuery(
            $connection,
            $sql,
            $bindings,
            $elapsedMs,
            $rowsAffected,
        );
    }

    private static function recordQuery(Connection $connection, string $sql, array $bindings, float $elapsedMs, ?int $rowsAffected): void
    {
        if (static::$loggingQueries) {
            $entry = [
                'query' => $sql,
                'bindings' => ArrayNormalizer::listValues($bindings),
                'time' => $elapsedMs,
                'connection' => $connection->getName(),
                'rows' => $rowsAffected,
            ];

            if (static::$queryLogCount < static::$maxQueryLogEntries) {
                static::$queryLog[] = $entry;
                static::$queryLogCount++;
            } else {
                static::$queryLog[static::$queryLogStart] = $entry;
                static::$queryLogStart = (static::$queryLogStart + 1) % static::$maxQueryLogEntries;
            }
        }

        static::$logger?->logQuery($sql, $bindings, $elapsedMs, $connection->getName(), $rowsAffected);
        static::$profiler?->record($sql, $bindings, $elapsedMs, $connection->getName(), $rowsAffected);

        self::observeQueryTime($elapsedMs, $sql, $bindings, $connection, $rowsAffected);
    }
}
