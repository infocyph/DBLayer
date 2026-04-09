<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Model;

use BadMethodCallException;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * TableModel
 *
 * Model-like static API on top of Repository + QueryBuilder + DB facade.
 *
 * This class is intentionally NOT an ORM:
 * - no identity map
 * - no dirty tracking
 * - no relationship loader
 *
 * It is a convenience delegation layer for repository-style workflows.
 */
abstract class TableModel
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
    public static function builder(): QueryBuilder
    {
        return static::query();
    }

    /**
     * Get the connection instance used by this model.
     */
    public static function connection(): Connection
    {
        return DB::connection(static::connectionName());
    }

    /**
     * Build a query builder for this model.
     *
     * Uses repository->builder() so repository-level policy can be applied
     * before returning the builder instance.
     */
    public static function query(): QueryBuilder
    {
        return static::configureQuery(static::repository()->builder());
    }

    /**
     * Alias for repository() to match common naming preference.
     */
    public static function repo(): Repository
    {
        return static::repository();
    }

    /**
     * Build a repository for this model.
     */
    public static function repository(): Repository
    {
        $repository = DB::repository(static::tableName(), static::connectionName());

        return static::configureRepository($repository);
    }

    /**
     * Execute a raw scalar query on this model's configured connection.
     *
     * @param array<int,mixed> $bindings
     */
    public static function sqlScalar(string $query, array $bindings = []): mixed
    {
        return DB::scalar($query, $bindings, static::connectionName());
    }

    /**
     * Execute a raw select query on this model's configured connection.
     *
     * @param array<int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function sqlSelect(string $query, array $bindings = []): array
    {
        return DB::select($query, $bindings, static::connectionName());
    }

    /**
     * Execute a raw statement on this model's configured connection.
     *
     * @param array<int,mixed> $bindings
     */
    public static function sqlStatement(string $query, array $bindings = []): bool
    {
        return DB::statement($query, $bindings, static::connectionName());
    }

    /**
     * Run a transaction on this model's configured connection.
     */
    public static function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts, static::connectionName());
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
    protected static function configureRepository(Repository $repository): Repository
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
     * Forward a call to DB facade while injecting model connection argument
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
                $namedArgs['connection'] = static::connectionName();
            }

            break;
        }

        return DB::$method(...$namedArgs);
    }
}
