<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use InvalidArgumentException;

/**
 * Repository-oriented static API for one database table.
 *
 * This remains intentionally non-ORM: rows are arrays/DTOs, there is no
 * identity map, dirty tracking, unit-of-work, or implicit lazy relationship
 * loading.
 */
abstract class TableRepository
{
    /**
     * Optional named connection.
     */
    protected static ?string $connection = null;

    /**
     * Primary-key column used by repository identity operations.
     */
    protected static string $primaryKey = 'id';

    /**
     * Backing table name.
     */
    protected static string $table = '';

    /**
     * Forward unknown static calls by priority:
     * 1) Repository API
     * 2) Repository-aware QueryBuilder API
     *
     * @param array<int,mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $repository = static::repository();
        if (method_exists($repository, $method)) {
            return $repository->$method(...$arguments);
        }

        $query = static::query();
        if (method_exists($query, $method) || method_exists($query->raw(), $method)) {
            return $query->$method(...$arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s() does not exist on the repository or query builder.',
            static::class,
            $method,
        ));
    }

    /**
     * Get the intentionally raw QueryBuilder escape hatch.
     */
    public static function builder(?string $connection = null): QueryBuilder
    {
        return static::query($connection)->raw();
    }

    /**
     * Get the connection instance used by this repository class.
     */
    public static function connection(?string $connection = null): Connection
    {
        return DB::connection(static::resolveConnectionName($connection));
    }

    /**
     * Build a repository-aware fluent query.
     */
    public static function query(?string $connection = null): RepositoryQuery
    {
        $repository = static::repository($connection);

        return new RepositoryQuery($repository, $repository->builder());
    }

    /**
     * Explicit alias for the raw QueryBuilder escape hatch.
     */
    public static function rawQuery(?string $connection = null): QueryBuilder
    {
        return static::builder($connection);
    }

    /**
     * Alias for repository() to match common naming preference.
     */
    public static function repo(?string $connection = null): QueryRepository
    {
        return static::repository($connection);
    }

    /**
     * Build a repository for this table definition.
     */
    public static function repository(?string $connection = null): QueryRepository
    {
        $repository = new TableQueryRepository(
            static::connection($connection),
            static::tableName(),
            static::primaryKeyName(),
            DB::resultProcessor(),
        );

        $casts = static::casts();
        if ($casts !== []) {
            $repository->setCasts($casts);
        }

        foreach (static::globalScopes() as $scope) {
            $repository->addGlobalScope($scope);
        }

        // Keep the existing customization hook, but apply it as a universal
        // repository scope so direct Repository terminals and fluent queries
        // cannot diverge.
        $repository->addGlobalScope(static function (QueryBuilder $query): void {
            static::configureQuery($query);
        });

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
     * Declarative repository casts.
     *
     * @return array<string,string|callable(mixed):mixed>
     */
    protected static function casts(): array
    {
        return [];
    }

    /**
     * Existing query-default hook. Defaults declared here are now applied as a
     * repository scope so every repository read path observes them.
     */
    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query;
    }

    /**
     * Override in subclasses to apply reusable repository policies.
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
     * Declarative global query scopes.
     *
     * @return array<array-key,callable(QueryBuilder):void>
     */
    protected static function globalScopes(): array
    {
        return [];
    }

    /**
     * Resolve and validate configured primary-key column.
     */
    protected static function primaryKeyName(): string
    {
        $primaryKey = trim(static::$primaryKey);

        if ($primaryKey === '') {
            throw new InvalidArgumentException(sprintf(
                '%s must define a non-empty static $primaryKey value.',
                static::class,
            ));
        }

        return $primaryKey;
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
}
