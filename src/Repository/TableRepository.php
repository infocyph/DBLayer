<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * TableRepository
 *
 * Repository-oriented static API on top of Repository + QueryBuilder + DB facade.
 *
 * This class is intentionally NOT an ORM:
 * - no identity map
 * - no dirty tracking
 * - no relationship loader
 *
 * It is a convenience delegation layer for table-centric repository workflows.
 */
abstract class TableRepository
{
    /**
     * Optional named connection.
     */
    protected static ?string $connection = null;
    /**
     * Backing table name.
     */
    protected static string $table = '';

    /**
     * Forward unknown static calls by priority:
     * 1) Repository API
     * 2) QueryBuilder API
     * 3) DB facade API (connection-aware forwarding)
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $repository = static::repository();
        if (method_exists($repository, $method)) {
            return $repository->$method(...$arguments);
        }

        $query = static::query();
        if (method_exists($query, $method)) {
            return $query->$method(...$arguments);
        }

        if (method_exists(DB::class, $method)) {
            return static::forwardToFacade($method, $arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s() does not exist on repository, query builder, or DB facade.',
            static::class,
            $method,
        ));
    }

    /**
     * Alias for query().
     */
    public static function builder(?string $connection = null): QueryBuilder
    {
        return static::query($connection);
    }

    /**
     * Get the connection instance used by this repository class.
     */
    public static function connection(?string $connection = null): Connection
    {
        return DB::connection(static::resolveConnectionName($connection));
    }

    /**
     * Build a query builder for this repository class.
     *
     * Uses repository->builder() so repository-level policy can be applied
     * before returning the builder instance.
     */
    public static function query(?string $connection = null): QueryBuilder
    {
        return static::configureQuery(static::repository($connection)->builder());
    }

    /**
     * Alias for repository() to match common naming preference.
     */
    public static function repo(?string $connection = null): QueryRepository
    {
        return static::repository($connection);
    }

    /**
     * Build a repository for this repository class.
     */
    public static function repository(?string $connection = null): QueryRepository
    {
        $repository = DB::repository(static::tableName(), static::resolveConnectionName($connection));

        return static::configureRepository($repository);
    }

    /**
     * Execute a raw scalar query on this repository class configured connection.
     *
     * @param array<int,mixed> $bindings
     */
    public static function sqlScalar(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        return DB::scalar($query, $bindings, static::resolveConnectionName($connection));
    }

    /**
     * Execute a raw select query on this repository class configured connection.
     *
     * @param array<int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function sqlSelect(string $query, array $bindings = [], ?string $connection = null): array
    {
        return DB::select($query, $bindings, static::resolveConnectionName($connection));
    }

    /**
     * Execute a raw statement on this repository class configured connection.
     *
     * @param array<int,mixed> $bindings
     */
    public static function sqlStatement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::statement($query, $bindings, static::resolveConnectionName($connection));
    }

    /**
     * Run a transaction on this repository class configured connection.
     */
    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return DB::transaction($callback, $attempts, static::resolveConnectionName($connection));
    }

    /**
     * Override in subclasses to apply reusable query defaults.
     */
    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query;
    }

    /**
     * Override in subclasses to apply reusable repository defaults.
     */
    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        return $repository;
    }

    /**
     * Resolve configured connection name.
     */
    protected static function connectionName(): ?string
    {
        return static::$connection;
    }

    /**
     * Resolve explicit connection override or repository-class default.
     */
    protected static function resolveConnectionName(?string $connection = null): ?string
    {
        return $connection ?? static::connectionName();
    }

    /**
     * Resolve and validate configured table name.
     */
    protected static function tableName(): string
    {
        $table = trim(static::$table);

        if ($table === '') {
            throw new InvalidArgumentException(sprintf(
                '%s must define a non-empty static $table value.',
                static::class,
            ));
        }

        return $table;
    }

    /**
     * Forward a call to DB facade while injecting repository connection argument
     * by parameter name when supported by the target method.
     *
     * @param array<int,mixed> $arguments
     */
    private static function forwardToFacade(string $method, array $arguments): mixed
    {
        /** @var array<string,ReflectionMethod> $reflections */
        static $reflections = [];

        $reflection = $reflections[$method] ??= new ReflectionMethod(DB::class, $method);
        $params     = $reflection->getParameters();
        $namedArgs  = [];

        foreach ($arguments as $index => $argument) {
            if (! isset($params[$index])) {
                $namedArgs[] = $argument;

                continue;
            }

            $namedArgs[$params[$index]->getName()] = $argument;
        }

        foreach ($params as $param) {
            if ($param->getName() !== 'connection') {
                continue;
            }

            if (! array_key_exists('connection', $namedArgs)) {
                $namedArgs['connection'] = static::resolveConnectionName();
            }

            break;
        }

        return DB::$method(...$namedArgs);
    }
}
