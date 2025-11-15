<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Query\QueryBuilder;
use PDO;
use Throwable;

/**
 * DB Static Facade
 *
 * Thin static facade over Connection / QueryBuilder:
 * - Connection registry (named connections)
 * - Simple raw query helpers
 * - Basic query logging hooks (optional)
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
     * Query event listeners.
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
     *  - query       (string)
     *  - bindings    (list<mixed>)
     *  - time        (float ms)
     *  - connection  (?string)
     *
     * @var list<array{
     *   query:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   connection:?string
     * }>
     */
    protected static array $queryLog = [];

    /**
     * Dynamically proxy method calls to the default connection.
     *
     * @throws ConnectionException
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
     * Execute multiple select queries in sequence.
     *
     * @param list<array{0:string,1:array<int,mixed>|null}> $queries
     * @return list<list<array<string,mixed>>>
     *
     * @throws ConnectionException
     */
    public static function batch(array $queries, ?string $connection = null): array
    {
        $results = [];

        foreach ($queries as [$sql, $bindings]) {
            /** @var array<int,mixed> $bindings */
            $bindings   = $bindings ?? [];
            $results[] = static::select($sql, $bindings, $connection);
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
     * Get a database connection instance by name (or default).
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
     * Execute a DELETE statement.
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
     * Disable the query log (for DB facade's own log).
     */
    public static function disableQueryLog(): void
    {
        static::$loggingQueries = false;
    }

    /**
     * Remove a connection from the registry.
     *
     * Does not touch the default connection name.
     */
    public static function disconnect(string $name): void
    {
        unset(static::$connections[$name]);
    }

    /**
     * Enable the query log (for DB facade's own log).
     */
    public static function enableQueryLog(): void
    {
        static::$loggingQueries = true;
    }

    /**
     * Fire the query event for listeners.
     *
     * Intended to be called by Connection / Executor when desired.
     *
     * @param list<mixed> $bindings
     */
    public static function fireQueryEvent(
      string $query,
      array $bindings,
      float $time,
      ?string $connection = null
    ): void {
        $event = [
          'query'      => $query,
          'bindings'   => array_values($bindings),
          'time'       => $time,
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
     * Flush the DB facade query log.
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
     * Get the underlying PDO instance.
     *
     * @throws ConnectionException
     */
    public static function getPdo(?string $connection = null): PDO
    {
        return static::connection($connection)->getPdo();
    }

    /**
     * Get the DB facade query log.
     *
     * @return list<array{
     *   query:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   connection:?string
     * }>
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
     * Determine if a named connection has been registered.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(static::$connections[$name]);
    }

    /**
     * Execute an INSERT statement.
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
     * Get last insert ID from PDO.
     *
     * @throws ConnectionException
     */
    public static function lastInsertId(?string $name = null, ?string $connection = null): string|false
    {
        return static::connection($connection)->getPdo()->lastInsertId($name);
    }

    /**
     * Register a query event listener.
     *
     * @param callable(array<string,mixed>):void $callback
     */
    public static function listen(callable $callback): void
    {
        static::$listeners[] = $callback;
    }

    /**
     * Determine if DB facade query logging is enabled.
     */
    public static function logging(): bool
    {
        return static::$loggingQueries;
    }

    /**
     * Check if connection is alive using a cheap SELECT 1.
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
     * Purge all connections and reset facade state.
     */
    public static function purge(): void
    {
        static::$connections       = [];
        static::$defaultConnection = null;
        static::$queryLog          = [];
        static::$listeners         = [];
        static::$loggingQueries    = false;
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
     * Create a raw database expression through the current connection.
     *
     * @throws ConnectionException
     */
    public static function raw(mixed $value): mixed
    {
        return static::connection()->raw($value);
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
     * Roll back the active transaction.
     *
     * @throws ConnectionException
     */
    public static function rollBack(?string $connection = null): void
    {
        static::connection($connection)->rollBack();
    }

    /**
     * Execute a SELECT query.
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
     * Execute a SELECT query and return the first row (or null).
     *
     * @param array<int,mixed> $bindings
     *
     * @throws ConnectionException
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        $records = static::select($query, $bindings, $connection);

        /** @var array<string,mixed>|null $first */
        $first = $records[0] ?? null;

        return $first;
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
     * Execute a general statement (INSERT / UPDATE / DELETE / DDL).
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
     * Get simple connection statistics.
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
          'driver'            => $conn->getDriverName(),
          'database'          => $conn->getDatabaseName(),
          'prefix'            => $conn->getTablePrefix(),
          'transaction_level' => $conn->transactionLevel(),
          'total_queries'     => count(static::$queryLog),
        ];
    }

    /**
     * Get a QueryBuilder for a table.
     *
     * @throws ConnectionException
     */
    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return static::connection($connection)->table($table);
    }

    /**
     * Execute a callback within a transaction with deadlock retry.
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
     * Alias for execute() without bindings.
     *
     * @throws ConnectionException
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        static::connection($connection)->execute($query);

        return true;
    }

    /**
     * Execute an UPDATE statement.
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
     * Get server version from PDO.
     *
     * @throws ConnectionException
     */
    public static function version(?string $connection = null): string
    {
        return (string) static::connection($connection)
          ->getPdo()
          ->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
