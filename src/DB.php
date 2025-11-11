<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Transaction\Transaction;

/**
 * DB Static Facade
 *
 * Provides a convenient static interface for database operations.
 * Acts as a facade to the underlying Connection and QueryBuilder classes.
 *
 * @package Infocyph\DBLayer
 * @author Hasan
 *
 * @method static QueryBuilder table(string $table, ?string $connection = null)
 * @method static array select(string $query, array $bindings = [], ?string $connection = null)
 * @method static bool insert(string $query, array $bindings = [], ?string $connection = null)
 * @method static int update(string $query, array $bindings = [], ?string $connection = null)
 * @method static int delete(string $query, array $bindings = [], ?string $connection = null)
 * @method static bool statement(string $query, array $bindings = [], ?string $connection = null)
 * @method static bool unprepared(string $query, ?string $connection = null)
 * @method static mixed transaction(callable $callback, int $attempts = 1, ?string $connection = null)
 * @method static void beginTransaction(?string $connection = null)
 * @method static void commit(?string $connection = null)
 * @method static void rollBack(?string $connection = null)
 * @method static int transactionLevel(?string $connection = null)
 * @method static void listen(callable $callback)
 * @method static void enableQueryLog()
 * @method static void disableQueryLog()
 * @method static array getQueryLog()
 * @method static void flushQueryLog()
 */
class DB
{
    /**
     * The database connections
     */
    protected static array $connections = [];

    /**
     * The default connection name
     */
    protected static ?string $defaultConnection = 'default';

    /**
     * Query event listeners
     */
    protected static array $listeners = [];

    /**
     * Query logging enabled state
     */
    protected static bool $loggingQueries = false;

    /**
     * Query log
     */
    protected static array $queryLog = [];

    /**
     * Dynamically pass methods to the default connection
     *
     * @param string $method Method name
     * @param array $parameters Method parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->$method(...$parameters);
    }

    /**
     * Add a database connection
     *
     * @param array $config Connection configuration
     * @param string $name Connection name
     * @return Connection
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
     * Execute multiple queries in sequence
     *
     * @param array $queries Array of [query, bindings] pairs
     * @param string|null $connection Connection name
     * @return array Results from all queries
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
     * Begin a transaction
     *
     * @param string|null $connection Connection name
     * @return void
     */
    public static function beginTransaction(?string $connection = null): void
    {
        static::connection($connection)->beginTransaction();
    }

    /**
     * Commit the active transaction
     *
     * @param string|null $connection Connection name
     * @return void
     */
    public static function commit(?string $connection = null): void
    {
        static::connection($connection)->commit();
    }

    /**
     * Get a database connection instance
     *
     * @param string|null $name Connection name
     * @return Connection
     * @throws ConnectionException
     */
    public static function connection(?string $name = null): Connection
    {
        $name = $name ?? static::$defaultConnection;

        if (!isset(static::$connections[$name])) {
            throw ConnectionException::connectionNotFound($name);
        }

        return static::$connections[$name];
    }

    /**
     * Execute a delete statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return int
     */
    public static function delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->delete($query, $bindings);
    }

    /**
     * Disable the query log
     *
     * @return void
     */
    public static function disableQueryLog(): void
    {
        static::$loggingQueries = false;
    }

    /**
     * Remove a connection from the registry
     *
     * @param string $name Connection name
     * @return void
     */
    public static function disconnect(string $name): void
    {
        if (isset(static::$connections[$name])) {
            unset(static::$connections[$name]);
        }
    }

    /**
     * Enable the query log
     *
     * @return void
     */
    public static function enableQueryLog(): void
    {
        static::$loggingQueries = true;
    }

    /**
     * Fire the query event for listeners
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param float $time Execution time
     * @return void
     */
    public static function fireQueryEvent(string $query, array $bindings, float $time): void
    {
        $event = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => $time,
            'connection' => static::$defaultConnection,
        ];

        foreach (static::$listeners as $listener) {
            $listener($event);
        }

        if (static::$loggingQueries) {
            static::$queryLog[] = $event;
        }
    }

    /**
     * Flush the query log
     *
     * @return void
     */
    public static function flushQueryLog(): void
    {
        static::$queryLog = [];
    }

    /**
     * Get all registered connections
     *
     * @return array
     */
    public static function getConnections(): array
    {
        return static::$connections;
    }

    /**
     * Get the database name
     *
     * @param string|null $connection Connection name
     * @return string
     */
    public static function getDatabaseName(?string $connection = null): string
    {
        return static::connection($connection)->getDatabaseName();
    }

    /**
     * Get the default connection name
     *
     * @return string|null
     */
    public static function getDefaultConnection(): ?string
    {
        return static::$defaultConnection;
    }

    /**
     * Get the database driver name
     *
     * @param string|null $connection Connection name
     * @return string
     */
    public static function getDriverName(?string $connection = null): string
    {
        return static::connection($connection)->getDriverName();
    }

    /**
     * Get the PDO instance
     *
     * @param string|null $connection Connection name
     * @return PDO
     */
    public static function getPdo(?string $connection = null): \PDO
    {
        return static::connection($connection)->getPdo();
    }

    /**
     * Get the query log
     *
     * @return array
     */
    public static function getQueryLog(): array
    {
        return static::$queryLog;
    }

    /**
     * Get the table prefix
     *
     * @param string|null $connection Connection name
     * @return string
     */
    public static function getTablePrefix(?string $connection = null): string
    {
        return static::connection($connection)->getTablePrefix();
    }

    /**
     * Determine if a connection has been registered
     *
     * @param string $name Connection name
     * @return bool
     */
    public static function hasConnection(string $name): bool
    {
        return isset(static::$connections[$name]);
    }

    /**
     * Execute an insert statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return bool
     */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->insert($query, $bindings);
    }

    /**
     * Get last insert ID
     *
     * @param string|null $name Sequence name (for PostgreSQL)
     * @param string|null $connection Connection name
     * @return string|false
     */
    public static function lastInsertId(?string $name = null, ?string $connection = null): string|false
    {
        return static::connection($connection)->getPdo()->lastInsertId($name);
    }

    /**
     * Register a query event listener
     *
     * @param callable $callback Event callback
     * @return void
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
    }

    /**
     * Determine if query logging is enabled
     *
     * @return bool
     */
    public static function logging(): bool
    {
        return static::$loggingQueries;
    }

    /**
     * Check if connection is alive
     *
     * @param string|null $connection Connection name
     * @return bool
     */
    public static function ping(?string $connection = null): bool
    {
        try {
            static::select('SELECT 1', [], $connection);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Pretend to execute a query (for testing)
     *
     * @param callable $callback Callback to execute
     * @param string|null $connection Connection name
     * @return array
     */
    public static function pretend(callable $callback, ?string $connection = null): array
    {
        return static::connection($connection)->pretend($callback);
    }

    /**
     * Purge all connections
     *
     * @return void
     */
    public static function purge(): void
    {
        static::$connections = [];
        static::$defaultConnection = null;
    }

    /**
     * Quote a value for use in a query
     *
     * @param string $value Value to quote
     * @param int $type PDO parameter type
     * @param string|null $connection Connection name
     * @return string
     */
    public static function quote(string $value, int $type = \PDO::PARAM_STR, ?string $connection = null): string
    {
        return static::connection($connection)->getPdo()->quote($value, $type);
    }

    /**
     * Create a raw database expression
     *
     * @param mixed $value Raw value
     * @return mixed
     */
    public static function raw(mixed $value): mixed
    {
        return static::connection()->raw($value);
    }

    /**
     * Reconnect to the given database
     *
     * @param string|null $name Connection name
     * @return Connection
     */
    public static function reconnect(?string $name = null): Connection
    {
        $name = $name ?? static::$defaultConnection;

        static::disconnect($name);

        if (!static::hasConnection($name)) {
            throw ConnectionException::connectionNotFound($name);
        }

        return static::connection($name);
    }

    /**
     * Rollback the active transaction
     *
     * @param string|null $connection Connection name
     * @return void
     */
    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollBack();
    }

    /**
     * Execute a select statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return array
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return static::connection($connection)->select($query, $bindings);
    }

    /**
     * Execute a select statement and return the first result
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return mixed
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $records = static::select($query, $bindings, $connection);

        return array_shift($records);
    }

    /**
     * Set the database name
     *
     * @param string $database Database name
     * @param string|null $connection Connection name
     * @return Connection
     */
    public static function setDatabaseName(string $database, ?string $connection = null): Connection
    {
        return static::connection($connection)->setDatabaseName($database);
    }

    /**
     * Set the default connection name
     *
     * @param string $name Connection name
     * @return void
     */
    public static function setDefaultConnection(string $name): void
    {
        static::$defaultConnection = $name;
    }

    /**
     * Set the table prefix
     *
     * @param string $prefix Table prefix
     * @param string|null $connection Connection name
     * @return Connection
     */
    public static function setTablePrefix(string $prefix, ?string $connection = null): Connection
    {
        return static::connection($connection)->setTablePrefix($prefix);
    }

    /**
     * Execute a statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return bool
     */
    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->statement($query, $bindings);
    }

    /**
     * Get connection statistics
     *
     * @param string|null $connection Connection name
     * @return array
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
     * Get a query builder for a table
     *
     * @param string $table Table name
     * @param string|null $connection Connection name
     * @return QueryBuilder
     */
    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return static::connection($connection)->table($table);
    }

    /**
     * Execute a callback within a transaction
     *
     * @param callable $callback Callback to execute
     * @param int $attempts Number of attempts
     * @param string|null $connection Connection name
     * @return mixed
     * @throws Throwable
     */
    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return static::connection($connection)->transaction($callback, $attempts);
    }

    /**
     * Get the transaction nesting level
     *
     * @param string|null $connection Connection name
     * @return int
     */
    public static function transactionLevel(?string $connection = null): int
    {
        return static::connection($connection)->transactionLevel();
    }

    /**
     * Execute an unprepared statement
     *
     * @param string $query SQL query
     * @param string|null $connection Connection name
     * @return bool
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        return static::connection($connection)->unprepared($query);
    }

    /**
     * Execute an update statement
     *
     * @param string $query SQL query
     * @param array $bindings Query bindings
     * @param string|null $connection Connection name
     * @return int
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->update($query, $bindings);
    }

    /**
     * Get server version
     *
     * @param string|null $connection Connection name
     * @return string
     */
    public static function version(?string $connection = null): string
    {
        return static::connection($connection)->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }
}
