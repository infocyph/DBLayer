<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;

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
     * The database connections.
     *
     * @var array<string,Connection>
     */
    protected static array $connections = [];

    /**
     * The default connection name.
     */
    protected static ?string $defaultConnection = 'default';

    /**
     * Query event listeners.
     *
     * @var array<int,callable>
     */
    protected static array $listeners = [];

    /**
     * Query logging enabled state.
     */
    protected static bool $loggingQueries = false;

    /**
     * Query log.
     *
     * @var array<int,array<string,mixed>>
     */
    protected static array $queryLog = [];

    /**
     * Dynamically pass methods to the default connection.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /**
     * Add a database connection.
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
     * @param array<int,array{0:string,1:array|null}> $queries
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
     */
    public static function beginTransaction(?string $connection = null): void
    {
        static::connection($connection)->beginTransaction();
    }

    /**
     * Commit the active transaction.
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

        if ($name === null || !isset(static::$connections[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        return static::$connections[$name];
    }

    /**
     * Execute a delete statement.
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
    }

    /**
     * Fire the query event for listeners.
     *
     * Intended to be called by the Connection / Executor layer.
     */
    public static function fireQueryEvent(string $query, array $bindings, float $time, ?string $connection = null): void
    {
        $event = [
          'query' => $query,
          'bindings' => $bindings,
          'time' => $time,
          'connection' => $connection ?? static::$defaultConnection,
        ];

        foreach (static::$listeners as $listener) {
            $listener($event);
        }

        if (static::$loggingQueries) {
            static::$queryLog[] = $event;
        }
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
     */
    public static function getDriverName(?string $connection = null): string
    {
        return static::connection($connection)->getDriverName();
    }

    /**
     * Get the PDO instance.
     */
    public static function getPdo(?string $connection = null): \PDO
    {
        return static::connection($connection)->getPdo();
    }

    /**
     * Get the query log.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getQueryLog(): array
    {
        return static::$queryLog;
    }

    /**
     * Get the table prefix.
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
     */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->insert($query, $bindings);
    }

    /**
     * Get last insert ID.
     */
    public static function lastInsertId(?string $name = null, ?string $connection = null): string|false
    {
        return static::connection($connection)->getPdo()->lastInsertId($name);
    }

    /**
     * Register a query event listener.
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
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
     */
    public static function ping(?string $connection = null): bool
    {
        try {
            static::select('SELECT 1', [], $connection);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pretend to execute a query (for testing).
     */
    public static function pretend(callable $callback, ?string $connection = null): array
    {
        return static::connection($connection)->pretend($callback);
    }

    /**
     * Purge all connections.
     */
    public static function purge(): void
    {
        static::$connections = [];
        static::$defaultConnection = null;
        static::$queryLog = [];
    }

    /**
     * Quote a value for use in a query.
     */
    public static function quote(string $value, int $type = \PDO::PARAM_STR, ?string $connection = null): string
    {
        return static::connection($connection)->getPdo()->quote($value, $type);
    }

    /**
     * Create a raw database expression.
     */
    public static function raw(mixed $value): mixed
    {
        return static::connection()->raw($value);
    }

    /**
     * Reconnect to the given database.
     *
     * Delegates to the Connection implementation.
     */
    public static function reconnect(?string $name = null): Connection
    {
        $name = $name ?? static::$defaultConnection;

        if ($name === null || !isset(static::$connections[$name])) {
            throw ConnectionException::connectionNotFound($name ?? 'null');
        }

        static::$connections[$name]->reconnect();

        return static::$connections[$name];
    }

    /**
     * Rollback the active transaction.
     */
    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollBack();
    }

    /**
     * Execute a select statement.
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return static::connection($connection)->select($query, $bindings);
    }

    /**
     * Execute a select statement and return the first result.
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $records = static::select($query, $bindings, $connection);

        return array_shift($records);
    }

    /**
     * Set the database name.
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
     */
    public static function setTablePrefix(string $prefix, ?string $connection = null): Connection
    {
        return static::connection($connection)->setTablePrefix($prefix);
    }

    /**
     * Execute a statement.
     */
    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->statement($query, $bindings);
    }

    /**
     * Get connection statistics.
     */
    public static function stats(?string $connection = null): array
    {
        $conn = static::connection($connection);

        return [
          'driver' => $conn->getDriverName(),
          'database' => $conn->getDatabaseName(),
          'prefix' => $conn->getTablePrefix(),
          'transaction_level' => $conn->transactionLevel(),
          'total_queries' => count(static::$queryLog),
        ];
    }

    /**
     * Get a query builder for a table.
     */
    public static function table(string $table, ?string $connection = null): \Infocyph\DBLayer\Query\QueryBuilder
    {
        return static::connection($connection)->table($table);
    }

    /**
     * Execute a callback within a transaction.
     *
     * @throws \Throwable
     */
    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return static::connection($connection)->transaction($callback, $attempts);
    }

    /**
     * Get the transaction nesting level.
     */
    public static function transactionLevel(?string $connection = null): int
    {
        return static::connection($connection)->transactionLevel();
    }

    /**
     * Execute an unprepared statement.
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        return static::connection($connection)->unprepared($query);
    }

    /**
     * Execute an update statement.
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->update($query, $bindings);
    }

    /**
     * Get server version.
     */
    public static function version(?string $connection = null): string
    {
        return (string) static::connection($connection)->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }
}
