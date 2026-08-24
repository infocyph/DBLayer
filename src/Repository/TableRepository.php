<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use InvalidArgumentException;
use LogicException;

/**
 * Repository-oriented static API for one database table.
 *
 * This remains intentionally non-ORM: rows are arrays/DTOs, there is no
 * identity map, dirty tracking, unit-of-work, or implicit lazy relationship
 * loading.
 */
abstract class TableRepository
{
    protected static ?string $connection = null;

    /** @var list<string> */
    protected static array $creatable = [];

    protected static string $createdAt = 'created_at';

    /** @var array<string,mixed> */
    protected static array $defaults = [];

    protected static int $maxRelationDepth = 3;

    protected static int $perPage = 15;

    protected static string $primaryKey = 'id';

    protected static string $table = '';

    protected static bool $timestamps = false;

    /** @var list<string> */
    protected static array $updatable = [];

    protected static string $updatedAt = 'updated_at';

    /** @var array<class-string,RepositoryDefinition> */
    private static array $definitionCache = [];

    /** @param array<int,mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): mixed
    {
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

    public static function builder(?string $connection = null): QueryBuilder
    {
        return static::repository($connection)->builder();
    }

    public static function connection(?string $connection = null): Connection
    {
        return DB::connection(static::resolveConnectionName($connection));
    }

    public static function definition(): RepositoryDefinition
    {
        $class = static::class;

        if (isset(self::$definitionCache[$class])) {
            return self::$definitionCache[$class];
        }

        $scopes = static::globalScopes();
        $scopes['__configure_query'] = static function (QueryBuilder $query): void {
            if (static::configureQuery($query) !== $query) {
                throw new LogicException('configureQuery() must configure and return the provided QueryBuilder instance.');
            }
        };

        return self::$definitionCache[$class] = new RepositoryDefinition(
            repositoryClass: $class,
            table: static::tableName(),
            connection: static::connectionName(),
            primaryKey: static::primaryKeyName(),
            perPage: static::$perPage,
            maxRelationDepth: static::$maxRelationDepth,
            defaults: static::$defaults,
            creatable: static::$creatable,
            updatable: static::$updatable,
            timestamps: static::$timestamps,
            createdAt: self::timestampColumn(static::$createdAt, 'created_at'),
            updatedAt: self::timestampColumn(static::$updatedAt, 'updated_at'),
            casts: static::casts(),
            globalScopes: $scopes,
            relations: static::relations(),
        );
    }

    public static function flushDefinition(): void
    {
        unset(self::$definitionCache[static::class]);
    }

    public static function pruner(?string $connection = null): RepositoryPruner
    {
        return new RepositoryPruner(static::class, $connection);
    }

    public static function query(?string $connection = null): RepositoryQuery
    {
        $definition = static::definition();
        $repository = static::repository($connection);

        return new RepositoryQuery(
            $repository,
            $repository->builder(),
            static::connection($connection),
            $definition,
        );
    }

    public static function rawQuery(?string $connection = null): QueryBuilder
    {
        return static::builder($connection);
    }

    public static function repository(?string $connection = null): QueryRepository
    {
        $definition = static::definition();
        $repository = new TableQueryRepository(
            static::connection($connection),
            $definition,
            DB::resultProcessor(),
        );

        if ($definition->casts !== []) {
            $repository->setCasts($definition->casts);
        }

        return static::configureRepository($repository);
    }

    /** @param array<int,mixed> $bindings */
    public static function sqlScalar(string $query, array $bindings = [], ?string $connection = null): mixed
    {
        return DB::scalar($query, $bindings, static::resolveConnectionName($connection));
    }

    /**
     * @param array<int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function sqlSelect(string $query, array $bindings = [], ?string $connection = null): array
    {
        return DB::select($query, $bindings, static::resolveConnectionName($connection));
    }

    /** @param array<int,mixed> $bindings */
    public static function sqlStatement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return DB::statement($query, $bindings, static::resolveConnectionName($connection));
    }

    public static function table(): string
    {
        return static::definition()->table;
    }

    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return DB::transaction($callback, $attempts, static::resolveConnectionName($connection));
    }

    /** @return array<string,string|callable(mixed):mixed|\Infocyph\DBLayer\Repository\Casts\AttributeCast> */
    protected static function casts(): array
    {
        return [];
    }

    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query;
    }

    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        return $repository;
    }

    protected static function connectionName(): ?string
    {
        return static::$connection;
    }

    /** @return array<array-key,callable(QueryBuilder):void> */
    protected static function globalScopes(): array
    {
        return [];
    }

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

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [];
    }

    protected static function resolveConnectionName(?string $connection = null): ?string
    {
        return $connection ?? static::connectionName();
    }

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

    private static function timestampColumn(string $column, string $fallback): string
    {
        $column = trim($column);

        return $column === '' ? $fallback : $column;
    }
}
