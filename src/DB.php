<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Query\QueryBuilder;
use PDO;
use Throwable;

/**
 * DB Static Facade
 *
 * Provides a convenient static interface for database operations.
 * Acts as a facade to the underlying Connection and QueryBuilder classes.
 *
 * @package Infocyph\DBLayer
 */
class DB
{
    /**
     * The database connections keyed by name.
     *
     * @var array<string,Connection>
     */
    protected static array $connections = [];

    /**
     * The default connection name.
     */
    protected static ?string $defaultConnection = 'default';

    /**
     * Query event listeners (facade-level).
     *
     * @var list<callable(array<string,mixed>):void>
     */
    protected static array $listeners = [];

    /**
     * Query logging enabled state.
     */
    protected static bool $loggingQueries = false;

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
     * Whether we've registered the global event listener bridge.
     */
    protected static bool $eventsHooked = false;

    /**
     * Dynamically pass methods to the default connection.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /**
     * Add a database connection.
     *
     * @param array<string,mixed> $config
     */
    public static function addConnection(array $config, string $name = 'default'): Connection
    {
        static::$connections[$name] = new Connection($config);

        if (static::$defaultConnection === null) {
            static::$defaultConnection = $name;
        }

        return static::$connections[$name];
    }

    /**
     * Execute multiple queries in sequence.
     *
     * @param list<array{0:string,1:array<int,mixed>|null}> $queries
     * @return list<array<int,mixed>>
     */
    public static function batch(array $queries, ?string $connection = null): array
    {
        $results = [];

        foreach ($queries as $query) {
            [$sql, $bindings] = $query;
            $results[] = static::select($sql, $bindings ?? [], $connection);
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
        static::connection($connection)->beginTransaction();
    }

    /**
     * Commit the active transaction.
     *
     * @throws ConnectionException
     */
    public static function commit(?string $connection = null): void
    {
        static::connection($connection)->commit();
    }

    /**
     * Get a database connection instance.
     *
     * @throws ConnectionException
     */
    public static function connection(?string $name = null): Connection
    {
        $name = $name ?? static::$defaultConnection;

        if ($name === null || ! isset(static::$connections[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

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
        return static::connection($connection)->delete($query, $bindings);
    }

    /**
     * Disable the query log.
     */
    public static function disableQueryLog(): void
    {
        static::$loggingQueries = false;
    }

    /**
     * Remove a connection from the registry.
     */
    public static function disconnect(string $name): void
    {
        unset(static::$connections[$name]);
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
     * Flush the query log.
     */
    public static function flushQueryLog(): void
    {
        static::$queryLog = [];
    }

    /**
     * Get all registered connections.
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
        return static::$queryLog;
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
     * Determine if a connection has been registered.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(static::$connections[$name]);
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
     * @param callable(array<string,mixed>):void $callback
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
        static::ensureEventsHooked();
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
     * Purge all connections and facade state.
     */
    public static function purge(): void
    {
        static::$connections        = [];
        static::$defaultConnection  = null;
        static::$queryLog           = [];
        static::$listeners          = [];
        static::$loggingQueries     = false;
        static::$eventsHooked       = false;
    }

    /**
     * Quote a value for use in a query.
     *
     * @throws ConnectionException
     */
    public static function quote(
      string $value,
      int $type = PDO::PARAM_STR,
      ?string $connection = null
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
     * Reconnect to the given database.
     *
     * Delegates to the Connection implementation.
     *
     * @throws ConnectionException
     */
    public static function reconnect(?string $name = null): Connection
    {
        $name = $name ?? static::$defaultConnection;

        if ($name === null || ! isset(static::$connections[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        static::$connections[$name]->reconnect();

        return static::$connections[$name];
    }

    /**
     * Rollback the active transaction.
     *
     * @throws ConnectionException
     */
    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollBack();
    }

    /**
     * Execute a select statement.
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     * @return list<array<string,mixed>>
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return static::connection($connection)->select($query, $bindings);
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
     * @param array<int,mixed> $bindings
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
     * @throws ConnectionException
     *
     * @return array{
     *   driver:string,
     *   database:string,
     *   prefix:string,
     *   transaction_level:int,
     *   total_queries:int
     * }
     */
    public static function stats(?string $connection = null): array
    {
        $conn = static::connection($connection);

        return [
          'driver'             => $conn->getDriverName(),
          'database'           => $conn->getDatabaseName(),
          'prefix'             => $conn->getTablePrefix(),
          'transaction_level'  => $conn->transactionLevel(),
          'total_queries'      => count(static::$queryLog),
        ];
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
     * Execute a callback within a transaction.
     *
     * @throws Throwable
     * @throws ConnectionException
     */
    public static function transaction(
      callable $callback,
      int $attempts = 1,
      ?string $connection = null
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
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->update($query, $bindings);
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

        Events::listen('db.query.executed', function (QueryExecuted $event): void {
            // Skip work if nothing is interested.
            if (! static::$loggingQueries && static::$listeners === []) {
                return;
            }

            // Try to infer the connection name from the facade registry.
            $connectionName = null;
            foreach (static::$connections as $name => $connection) {
                if ($connection === $event->connection) {
                    $connectionName = $name;
                    break;
                }
            }

            $payload = [
              'query'      => $event->sql,
              'bindings'   => $event->bindings,
              'time'       => $event->time,          // ms
              'connection' => $connectionName,
              'rows'       => $event->rowsAffected,
            ];

            foreach (static::$listeners as $listener) {
                $listener($payload);
            }

            if (static::$loggingQueries) {
                static::$queryLog[] = $payload;
            }
        });
    }
}
