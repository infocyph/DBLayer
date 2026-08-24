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
    /** @var array<class-string,RepositoryDefinition> */
    private static array $definitionCache = [];

    /** Optional named connection. */
    protected static ?string $connection = null;

    /** @var list<string> Attributes accepted from create callers. Empty means unrestricted. */
    protected static array $creatable = [];

    /** Created timestamp column. */
    protected static string $createdAt = 'created_at';

    /** @var array<string,mixed> Default attributes merged into create payloads. */
    protected static array $defaults = [];

    /** Maximum eager-relation path depth accepted by this repository. */
    protected static int $maxRelationDepth = 3;

    /** Default page size for repository pagination. */
    protected static int $perPage = 15;

    /** Primary-key column used by repository identity operations. */
    protected static string $primaryKey = 'id';

    /** Backing table name. */
    protected static string $table = '';

    /** Enable automatic created/updated timestamp injection for repository writes. */
    protected static bool $timestamps = false;

    /** @var list<string> Attributes accepted from update callers. Empty means unrestricted. */
    protected static array $updatable = [];

    /** Updated timestamp column. */
    protected static string $updatedAt = 'updated_at';

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

    /** Get the intentionally raw QueryBuilder escape hatch. */
    public static function builder(?string $connection = null): QueryBuilder
    {
        return static::query($connection)->raw();
    }

    /** Get the connection instance used by this repository class. */
    public static function connection(?string $connection = null): Connection
    {
        return DB::connection(static::resolveConnectionName($connection));
    }

    /** Get the immutable metadata definition compiled for this repository class. */
    public static function definition(): RepositoryDefinition
    {
        $class = static::class;

        if (isset(self::$definitionCache[$class])) {
            return self::$definitionCache[$class];
        }

        $scopes = static::globalScopes();
        $scopes['__configure_query'] = static function (QueryBuilder $query): void {
            static::configureQuery($query);
        };

        return self::$definitionCache[$class] = new RepositoryDefinition(
            repositoryClass: $class,
            table: static::tableName(),
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

    /**
     * Forget this class's compiled metadata definition.
     *
     * Normal applications should not need this. It exists for tests and for
     * intentionally dynamic repository metadata during bootstrap.
     */
    public static function flushDefinition(): void
    {
        unset(self::$definitionCache[static::class]);
    }

    /** Build a repository-aware fluent query. */
    public static function query(?string $connection = null): RepositoryQuery
    {
        $definition = static::definition();
        $repository = static::repository($connection);
        $resolvedConnection = static::connection($connection);

        return new RepositoryQuery(
            $repository,
            $repository->builder(),
            $resolvedConnection,
            $definition,
        );
    }

    /** Explicit alias for the raw QueryBuilder escape hatch. */
    public static function rawQuery(?string $connection = null): QueryBuilder
    {
        return static::builder($connection);
    }

    /** Alias for repository() to match common naming preference. */
    public static function repo(?string $connection = null): QueryRepository
    {
        return static::repository($connection);
    }

    /** Build a repository for this table definition. */
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

    /** Public table metadata for relation definitions and tooling. */
    public static function table(): string
    {
        return static::definition()->table;
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

    /** Run a transaction on this repository class configured connection. */
    public static function transaction(callable $callback, int $attempts = 1, ?string $connection = null): mixed
    {
        return DB::transaction($callback, $attempts, static::resolveConnectionName($connection));
    }

    /**
     * Declarative repository casts.
     *
     * Metadata returned by this method is compiled once per repository class.
     *
     * @return array<string,string|callable(mixed):mixed|\Infocyph\DBLayer\Repository\Casts\AttributeCast>
     */
    protected static function casts(): array
    {
        return [];
    }

    /**
     * Existing query-default hook. Defaults declared here are applied through
     * the repository constraint pipeline for every repository read path.
     */
    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query;
    }

    /** Override in subclasses to apply reusable runtime repository policies. */
    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        return $repository;
    }

    /** Resolve configured connection name. */
    protected static function connectionName(): ?string
    {
        return static::$connection;
    }

    /**
     * Declarative named global query scopes.
     *
     * Metadata returned by this method is compiled once per repository class.
     * Scope callbacks themselves execute for every fresh query.
     *
     * @return array<array-key,callable(QueryBuilder):void>
     */
    protected static function globalScopes(): array
    {
        return [];
    }

    /**
     * Declarative eager-loadable relations.
     *
     * Metadata returned by this method is compiled once per repository class.
     *
     * @return array<string,RelationDefinition>
     */
    protected static function relations(): array
    {
        return [];
    }

    /** Resolve and validate configured primary-key column. */
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

    /** Resolve explicit connection override or repository-class default. */
    protected static function resolveConnectionName(?string $connection = null): ?string
    {
        return $connection ?? static::connectionName();
    }

    /** Resolve and validate configured table name. */
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
