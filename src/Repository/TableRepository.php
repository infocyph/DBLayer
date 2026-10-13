<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\RecordNotFoundException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/**
 * Repository-oriented static API for one database table.
 *
 * Static queries return arrays/DTOs. Bound instances expose attributes and
 * persist explicit changes through the same repository policies.
 *
 * @phpstan-consistent-constructor
 * @method static int|bool delete()
 * @method static int|bool update(array<string,mixed> $values)
 */
abstract class TableRepository implements JsonSerializable
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

    public bool $exists = false;

    /** @var array<string,mixed> */
    private array $attributes = [];

    /** @var array<string,mixed>|null */
    private ?array $original = null;

    /** @var array<string,string> */
    private array $originalValues = [];

    /** @param array<string,mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** @param array<int|string,mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return match ($method) {
            'delete' => $this->deleteRecord(),
            'update' => $this->updateRecord($arguments),
            default => throw new BadMethodCallException(sprintf('Instance method %s::%s() does not exist.', static::class, $method)),
        };
    }

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

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[RepositorySupport::column($name)] = $value;
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

    /** Convert a supplied identifier using the configured connection and scopes. */
    public static function fromBoundValue(mixed $value): static
    {
        if ($value instanceof static) {
            return $value;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Repository binding requires an integer or string identifier.');
        }
        $row = static::query()->find($value)
            ?? throw RecordNotFoundException::forRepository(static::class);
        $model = new static();
        $model->attributes = $row;
        $model->syncOriginal();
        $model->exists = true;

        return $model;
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

    /** @param array<string,mixed> $attributes */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $name => $value) {
            $this->__set($name, $value);
        }

        return $this;
    }

    /** @return array<string,mixed> */
    public function getDirty(): array
    {
        return array_filter(
            $this->attributes,
            fn(mixed $value, string $name): bool => $this->original === null
                || !array_key_exists($name, $this->original)
                || (isset($this->originalValues[$name])
                    ? serialize($value) !== $this->originalValues[$name]
                    : $value !== $this->original[$name]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function getKey(): mixed
    {
        return $this->attributes[static::primaryKeyName()] ?? null;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function refresh(): static
    {
        $row = static::query()->find($this->originalKey())
            ?? throw RecordNotFoundException::forRepository(static::class);
        $this->attributes = $row;
        $this->syncOriginal();
        $this->exists = true;

        return $this;
    }

    /** Persist only explicit changes, using the existing write policies and hooks. */
    public function save(): bool
    {
        $repository = static::repository();
        if ($this->original === null) {
            $this->attributes = $repository->create($this->attributes);
            $this->syncOriginal();
            $this->exists = true;

            return true;
        }
        if (!$this->exists) {
            return false;
        }
        $dirty = $this->getDirty();
        if ($dirty === []) {
            return true;
        }
        $id = $this->originalKey();
        if ($repository->updateById($id, $dirty) === 0 && $repository->find($id) === null) {
            return false;
        }
        $this->attributes = $repository->find($this->getKey()) ?? $this->attributes;
        $this->syncOriginal();

        return true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->attributes;
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

    private function deleteRecord(): bool
    {
        if (!$this->exists) {
            return false;
        }
        if (static::repository()->deleteById($this->originalKey()) === 0) {
            return false;
        }
        $this->attributes = static::query()->withTrashed()->find($this->originalKey()) ?? $this->attributes;
        $this->syncOriginal();
        $this->exists = false;

        return true;
    }

    private function originalKey(): mixed
    {
        return $this->original[static::primaryKeyName()]
            ?? throw new LogicException('This repository instance has no persisted primary key.');
    }

    private function syncOriginal(): void
    {
        $this->original = $this->attributes;
        $this->originalValues = [];
        foreach ($this->attributes as $name => $value) {
            if (is_array($value) || is_object($value)) {
                $this->originalValues[$name] = serialize($value);
            }
        }
    }

    /** @param array<int|string,mixed> $arguments */
    private function updateRecord(array $arguments): bool
    {
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('Instance update() requires one attribute array.');
        }
        $attributes = RepositorySupport::row($arguments['values'] ?? $arguments[0] ?? null);
        if ($attributes === null) {
            throw new InvalidArgumentException('Instance update() requires an attribute array with string keys.');
        }

        return $this->fill($attributes)->save();
    }
}
