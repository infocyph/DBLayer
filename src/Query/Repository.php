<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Generator;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Support\Collection;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

/**
 * Base Repository
 *
 * Thin repository/model layer on top of the QueryBuilder:
 * - Centralizes use of ResultProcessor
 * - Provides table-aware helpers (find, all, pluck, aggregates)
 * - Allows flexible scoping via closures
 *
 * Extend this per "model".
 */
abstract class Repository
{
    /**
     * Attribute casts.
     *
     * @var array<string,string|callable(mixed):mixed>
     */
    protected array $casts = [];

    /**
     * Default ordering rules applied to every query().
     *
     * @var list<array{column:string,direction:string}>
     */
    protected array $defaultOrders = [];

    /**
     * Repository-level query scopes applied to every query().
     *
     * @var list<callable(QueryBuilder):void>
     */
    protected array $globalScopes = [];

    /**
     * Lifecycle hooks keyed by event name.
     *
     * @var array{
     *   beforeCreate:list<callable>,
     *   afterCreate:list<callable>,
     *   beforeUpdate:list<callable>,
     *   afterUpdate:list<callable>,
     *   beforeDelete:list<callable>,
     *   afterDelete:list<callable>
     * }
     */
    protected array $hooks = [
        'beforeCreate' => [],
        'afterCreate' => [],
        'beforeUpdate' => [],
        'afterUpdate' => [],
        'beforeDelete' => [],
        'afterDelete' => [],
    ];

    /**
     * Restrict query() results to only soft-deleted rows.
     */
    protected bool $onlyTrashed = false;

    /**
     * Optional optimistic lock column.
     */
    protected ?string $optimisticLockColumn = null;

    /**
     * Soft-delete timestamp column.
     */
    protected string $softDeleteColumn = 'deleted_at';

    /**
     * Whether soft deletes are enabled.
     */
    protected bool $softDeletes = false;

    /**
     * Tenant column name used when tenant scope is enabled.
     */
    protected string $tenantColumn = 'tenant_id';

    /**
     * Optional tenant scope: where $tenantColumn = $tenantId.
     */
    protected int|string|null $tenantId = null;

    /**
     * Include soft-deleted rows in query() results.
     */
    protected bool $withTrashed = false;

    /**
     * Create a new repository instance.
     */
    public function __construct(
        /**
         * Database connection.
         */
        protected Connection $connection,
        /**
         * SQL grammar compiler.
         */
        protected Grammar $grammar,
        /**
         * Query executor.
         */
        protected Executor $executor,
        /**
         * Result processor.
         */
        protected ResultProcessor $results,
    ) {}

    /**
     * The backing table name.
     *
     * Each concrete repository MUST define its table.
     */
    abstract protected function table(): string;

    /**
     * Add one default order. Applied on every query() call.
     */
    public function addDefaultOrder(string $column, string $direction = 'asc'): static
    {
        $normalized = $this->normalizeDirection($direction);

        $this->defaultOrders[] = [
            'column' => $column,
            'direction' => $normalized,
        ];

        return $this;
    }

    /**
     * Add a global scope callback applied on every query() call.
     *
     * @param callable(QueryBuilder):void $scope
     */
    public function addGlobalScope(callable $scope): static
    {
        $this->globalScopes[] = $scope;

        return $this;
    }

    /**
     * Register callback after create.
     */
    public function afterCreate(callable $callback): static
    {
        return $this->on('afterCreate', $callback);
    }

    /**
     * Register callback after delete.
     */
    public function afterDelete(callable $callback): static
    {
        return $this->on('afterDelete', $callback);
    }

    /**
     * Register callback after update.
     */
    public function afterUpdate(callable $callback): static
    {
        return $this->on('afterUpdate', $callback);
    }

    /**
     * Get all rows for this table as a Collection.
     */
    public function all(array $columns = ['*']): Collection
    {
        $rows = $this->query()
          ->select($columns)
          ->get();
        $rows = $this->applyReadCastsToRows($rows);

        return $this->results->process($rows);
    }

    /**
     * Register callback before create.
     */
    public function beforeCreate(callable $callback): static
    {
        return $this->on('beforeCreate', $callback);
    }

    /**
     * Register callback before delete.
     */
    public function beforeDelete(callable $callback): static
    {
        return $this->on('beforeDelete', $callback);
    }

    /**
     * Register callback before update.
     */
    public function beforeUpdate(callable $callback): static
    {
        return $this->on('beforeUpdate', $callback);
    }

    /**
     * Get a ready-to-use QueryBuilder for advanced usage.
     */
    public function builder(): QueryBuilder
    {
        return $this->query();
    }

    /**
     * Insert multiple rows.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function bulkInsert(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $payload = array_map(
            function (array $row): array {
                $prepared = $this->applyWriteCastsToAttributes($this->applyTenantAttributes($row));

                return $this->runPayloadHooks('beforeCreate', $prepared);
            },
            $rows,
        );

        $inserted = $this->query()->insert($payload);

        if ($inserted) {
            foreach ($payload as $row) {
                $this->runVoidHooks('afterCreate', ['payload' => $row, 'row' => $row, 'bulk' => true]);
            }
        }

        return $inserted;
    }

    /**
     * Process rows in OFFSET/LIMIT chunks.
     *
     * @param callable(list<array<string,mixed>>,int):bool $callback
     * @param callable(QueryBuilder):void|null $scope
     */
    public function chunk(int $count, callable $callback, ?callable $scope = null): bool
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->chunk($count, $callback);
    }

    /**
     * Process rows in keyset chunks using $column.
     *
     * @param callable(list<array<string,mixed>>,int):bool $callback
     * @param callable(QueryBuilder):void|null $scope
     */
    public function chunkById(
        int $count,
        callable $callback,
        string $column = 'id',
        mixed $fromId = null,
        ?callable $scope = null,
    ): bool {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->chunkById($count, $callback, $column, $fromId);
    }

    /**
     * Clear all default ordering rules.
     */
    public function clearDefaultOrders(): static
    {
        $this->defaultOrders = [];

        return $this;
    }

    /**
     * Clear all registered global scopes.
     */
    public function clearGlobalScopes(): static
    {
        $this->globalScopes = [];

        return $this;
    }

    /**
     * Count rows for an optional scoped query.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function count(?callable $scope = null): int
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->count();
    }

    /**
     * Create one row and return the freshly loaded row when possible.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function create(array $attributes): array
    {
        $payload = $this->applyWriteCastsToAttributes($this->applyTenantAttributes($attributes));
        $payload = $this->runPayloadHooks('beforeCreate', $payload);

        $this->query()->insert($payload);

        $created = $this->reloadCreatedRow($payload);
        $created = $created !== null ? $this->applyReadCastsToRow($created) : null;
        $final = $created ?? $payload;

        $this->runVoidHooks('afterCreate', ['payload' => $payload, 'row' => $final]);

        return $final;
    }

    /**
     * Iterate rows as a generator.
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<array<string,mixed>>
     */
    public function cursor(int $chunkSize = 1000, ?callable $scope = null): Generator
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->cursor($chunkSize);
    }

    /**
     * Cursor-based pagination.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function cursorPaginate(
        int $perPage = 15,
        mixed $cursor = null,
        string $column = 'id',
        string $direction = 'asc',
        ?callable $scope = null,
    ): CursorPaginator {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->cursorPaginate($perPage, $cursor, $column, $direction);
    }

    /**
     * Delete one row by primary key.
     */
    public function deleteById(mixed $id): int
    {
        $this->runVoidHooks('beforeDelete', ['id' => $id, 'soft' => $this->softDeletes]);

        if ($this->softDeletes) {
            $affected = $this->queryWithoutSoftDeletes()
              ->where($this->primaryKey(), '=', $id)
              ->update([$this->softDeleteColumn => $this->freshTimestamp()]);

            $this->runVoidHooks('afterDelete', ['id' => $id, 'affected' => $affected, 'soft' => true]);

            return $affected;
        }

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->primaryKey(), '=', $id)
          ->delete();

        $this->runVoidHooks('afterDelete', ['id' => $id, 'affected' => $affected, 'soft' => false]);

        return $affected;
    }

    /**
     * Disable optimistic locking.
     */
    public function disableOptimisticLocking(): static
    {
        $this->optimisticLockColumn = null;

        return $this;
    }

    /**
     * Disable soft deletes and clear related read modes.
     */
    public function disableSoftDeletes(): static
    {
        $this->softDeletes = false;
        $this->withTrashed = false;
        $this->onlyTrashed = false;
        $this->softDeleteColumn = 'deleted_at';

        return $this;
    }

    /**
     * Enable optimistic locking using a numeric version column.
     */
    public function enableOptimisticLocking(string $column = 'version'): static
    {
        $this->optimisticLockColumn = $column;

        return $this;
    }

    /**
     * Enable soft deletes on this repository.
     */
    public function enableSoftDeletes(string $column = 'deleted_at'): static
    {
        $this->softDeletes = true;
        $this->softDeleteColumn = $column;
        $this->withTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    /**
     * Check if any row exists for an optional scoped query.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function exists(?callable $scope = null): bool
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->exists();
    }

    /**
     * Find a row by primary key.
     *
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, array $columns = ['*']): ?array
    {
        $key = $this->primaryKey();
        $row = $this->query()
          ->select($columns)
          ->where($key, '=', $id)
          ->first();

        return $this->applyReadCastsToRow($row);
    }

    /**
     * Find multiple rows by primary key.
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        if ($ids === []) {
            return $this->results->process([]);
        }

        $key = $this->primaryKey();

        $rows = $this->query()
          ->select($columns)
          ->whereIn($key, $ids)
          ->get();
        $rows = $this->applyReadCastsToRows($rows);

        return $this->results->process($rows);
    }

    /**
     * Get the first row matching an optional scoped query.
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return array<string,mixed>|null
     */
    public function first(?callable $scope = null, array $columns = ['*']): ?array
    {
        $query = $this->applyScope(
            $this->query()->select($columns),
            $scope,
        );

        return $this->applyReadCastsToRow($query->first());
    }

    /**
     * Map first scoped row into a DTO object.
     *
     * @param class-string $className
     * @param callable(QueryBuilder):void|null $scope
     */
    public function firstInto(string $className, ?callable $scope = null, array $columns = ['*']): ?object
    {
        return $this->firstMap(
            fn(array $row): object => $this->mapRowIntoClass($className, $row),
            $scope,
            $columns,
        );
    }

    /**
     * Map first scoped row through a callback.
     *
     * @param callable(array<string,mixed>):mixed $mapper
     * @param callable(QueryBuilder):void|null $scope
     */
    public function firstMap(callable $mapper, ?callable $scope = null, array $columns = ['*']): mixed
    {
        $row = $this->first($scope, $columns);

        if ($row === null) {
            return null;
        }

        return $mapper($row);
    }

    /**
     * Find first row by attributes or create it.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function firstOrCreate(array $attributes, array $values = []): array
    {
        $existing = $this->firstByAttributes($attributes);

        if ($existing !== null) {
            return $existing;
        }

        $payload = array_merge($attributes, $values);
        $this->create($payload);

        $created = $this->firstByAttributes($attributes);
        if ($created !== null) {
            return $created;
        }

        $fallback = $this->firstByAttributes($payload);

        return $fallback ?? $this->applyTenantAttributes($payload);
    }

    /**
     * Permanently delete one row by primary key.
     */
    public function forceDeleteById(mixed $id): int
    {
        $this->runVoidHooks('beforeDelete', ['id' => $id, 'force' => true]);

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->primaryKey(), '=', $id)
          ->delete();

        $this->runVoidHooks('afterDelete', ['id' => $id, 'affected' => $affected, 'force' => true]);

        return $affected;
    }

    /**
     * Enable tenant filtering (column = tenant id) on every query().
     */
    public function forTenant(int|string $tenantId, string $column = 'tenant_id'): static
    {
        $this->tenantId = $tenantId;
        $this->tenantColumn = $column;

        return $this;
    }

    /**
     * Get rows using an optional scoped query as a Collection.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function get(?callable $scope = null, array $columns = ['*']): Collection
    {
        $query = $this->applyScope(
            $this->query()->select($columns),
            $scope,
        );

        $rows = $query->get();
        $rows = $this->applyReadCastsToRows($rows);

        return $this->results->process($rows);
    }

    /**
     * Group results by a column into an array keyed by that column.
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return array<string|int,list<array<string,mixed>>>
     */
    public function groupByKey(string $column, ?callable $scope = null): array
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        $rows = $query->get();
        $rows = $this->applyReadCastsToRows($rows);

        return $this->results->processGrouped($rows, $column);
    }

    /**
     * Lazy generator alias for cursor().
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<array<string,mixed>>
     */
    public function lazy(int $chunkSize = 1000, ?callable $scope = null): Generator
    {
        yield from $this->cursor($chunkSize, $scope);
    }

    /**
     * Map scoped rows through a callback and return as Collection.
     *
     * @param callable(array<string,mixed>):mixed $mapper
     * @param callable(QueryBuilder):void|null $scope
     */
    public function map(callable $mapper, ?callable $scope = null, array $columns = ['*']): Collection
    {
        $rows = $this->get($scope, $columns);

        return $rows->map(
            static fn(mixed $row): mixed => $mapper((array) $row),
        );
    }

    /**
     * Map scoped rows into DTO objects by constructor/property name.
     *
     * @param class-string $className
     * @param callable(QueryBuilder):void|null $scope
     * @return Collection<int|string,object>
     */
    public function mapInto(string $className, ?callable $scope = null, array $columns = ['*']): Collection
    {
        return $this->map(
            fn(array $row): object => $this->mapRowIntoClass($className, $row),
            $scope,
            $columns,
        );
    }

    /**
     * Register a lifecycle hook callback.
     */
    public function on(string $event, callable $callback): static
    {
        if (! array_key_exists($event, $this->hooks)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported repository hook [%s].',
                $event,
            ));
        }

        $this->hooks[$event][] = $callback;

        return $this;
    }

    /**
     * Restrict reads to soft-deleted rows only.
     */
    public function onlyTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = true;

        return $this;
    }

    /**
     * Paginate results with total count.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function paginate(int $perPage = 15, ?int $page = null, ?callable $scope = null): LengthAwarePaginator
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->paginate($perPage, $page);
    }

    /**
     * Pluck a single column into a flat array, optionally keyed by another column.
     *
     * @param callable(QueryBuilder):void|null $scope
     * @return array<int|string,mixed>
     */
    public function pluck(string $column, ?string $keyColumn = null, ?callable $scope = null): array
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        $rows = $query->get();
        $rows = $this->applyReadCastsToRows($rows);

        if ($keyColumn === null) {
            return $this->results->processColumn($rows, $column);
        }

        return $this->results->processKeyValue($rows, $keyColumn, $column);
    }

    /**
     * Restore one soft-deleted row by primary key.
     */
    public function restoreById(mixed $id): int
    {
        if (! $this->softDeletes) {
            return 0;
        }

        return $this->queryWithoutSoftDeletes()
          ->where($this->primaryKey(), '=', $id)
          ->whereNotNull($this->softDeleteColumn)
          ->update([$this->softDeleteColumn => null]);
    }

    /**
     * Configure attribute casts.
     *
     * Built-in casts:
     *  - int, integer
     *  - float, double, real
     *  - bool, boolean
     *  - string
     *  - json, array
     *  - datetime
     *
     * @param  array<string,string|callable(mixed):mixed>  $casts
     */
    public function setCasts(array $casts): static
    {
        $this->casts = $casts;

        return $this;
    }

    /**
     * Reset and set a single default order.
     */
    public function setDefaultOrder(string $column, string $direction = 'asc'): static
    {
        $this->defaultOrders = [];

        return $this->addDefaultOrder($column, $direction);
    }

    /**
     * Lightweight pagination without total count.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null, ?callable $scope = null): SimplePaginator
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->simplePaginate($perPage, $page);
    }

    /**
     * Update one row by primary key.
     *
     * @param array<string,mixed> $values
     */
    public function updateById(mixed $id, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $payload = $this->applyWriteCastsToAttributes($values);
        $payload = $this->runPayloadHooks('beforeUpdate', $payload);

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->primaryKey(), '=', $id)
          ->update($payload);

        $this->runVoidHooks('afterUpdate', ['id' => $id, 'payload' => $payload, 'affected' => $affected]);

        return $affected;
    }

    /**
     * Update row by id only if expected version matches current version.
     */
    public function updateByIdWithVersion(
        mixed $id,
        array $values,
        int|float|string $expectedVersion,
        ?string $versionColumn = null,
    ): bool {
        $column = $versionColumn ?? $this->optimisticLockColumn ?? 'version';

        $payload = $this->applyWriteCastsToAttributes($values);
        $payload[$column] = (int) $expectedVersion + 1;
        $payload = $this->runPayloadHooks('beforeUpdate', $payload);

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->primaryKey(), '=', $id)
          ->where($column, '=', $expectedVersion)
          ->update($payload);

        $this->runVoidHooks('afterUpdate', [
            'id' => $id,
            'payload' => $payload,
            'affected' => $affected,
            'optimistic' => true,
            'version_column' => $column,
        ]);

        return $affected > 0;
    }

    /**
     * Update an existing row matching attributes or create it.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function updateOrCreate(array $attributes, array $values = []): array
    {
        $existing = $this->firstByAttributes($attributes);

        if ($existing === null) {
            return $this->firstOrCreate($attributes, $values);
        }

        if ($values === []) {
            return $existing;
        }

        $payload = $this->applyWriteCastsToAttributes($values);
        $payload = $this->runPayloadHooks('beforeUpdate', $payload);

        $primaryKey = $this->primaryKey();
        if (array_key_exists($primaryKey, $existing)) {
            $this->updateById($existing[$primaryKey], $payload);

            $updated = $this->find($existing[$primaryKey]);

            return $updated ?? $existing;
        }

        $query = $this->applyAttributes($this->query(), $attributes);
        $affected = $query->update($payload);
        $this->runVoidHooks('afterUpdate', ['payload' => $payload, 'affected' => $affected]);

        $updated = $this->firstByAttributes($attributes);

        return $updated ?? $existing;
    }

    /**
     * Upsert one or many rows.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        $payload = $this->applyWriteCastsToValues($this->applyTenantValues($values));

        return $this->query()->upsert($payload, $uniqueBy, $update);
    }

    /**
     * Get a scalar value from the first row of a scoped query.
     *
     * Example:
     *   $total = $repo->value('amount', fn ($q) => $q->where('status', 'paid'));
     */
    public function value(string $column, ?callable $scope = null): mixed
    {
        $query = $this->applyScope(
            $this->query()->select([$column]),
            $scope,
        );

        $rows = $query->get();
        $value = $this->results->processAggregate($rows);

        return $this->applyCastValueForColumn($column, $value);
    }

    /**
     * Disable tenant filtering.
     */
    public function withoutTenant(): static
    {
        $this->tenantId = null;
        $this->tenantColumn = 'tenant_id';

        return $this;
    }

    /**
     * Exclude soft-deleted rows from reads.
     */
    public function withoutTrashed(): static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    /**
     * Include soft-deleted rows in reads.
     */
    public function withTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;

        return $this;
    }

    /**
     * Apply equality filters for provided attributes.
     *
     * @param array<string,mixed> $attributes
     */
    protected function applyAttributes(QueryBuilder $query, array $attributes): QueryBuilder
    {
        foreach ($attributes as $column => $value) {
            $query->where((string) $column, '=', $value);
        }

        return $query;
    }

    /**
     * Apply repository-level constraints (global scopes, tenant, default orders).
     */
    protected function applyRepositoryConstraints(QueryBuilder $query): QueryBuilder
    {
        foreach ($this->globalScopes as $scope) {
            $scope($query);
        }

        if ($this->tenantId !== null) {
            $query->where($this->tenantColumn, '=', $this->tenantId);
        }

        foreach ($this->defaultOrders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        if ($this->softDeletes) {
            if ($this->onlyTrashed) {
                $query->whereNotNull($this->softDeleteColumn);
            } elseif (! $this->withTrashed) {
                $query->whereNull($this->softDeleteColumn);
            }
        }

        return $query;
    }

    /**
     * Apply an optional scope closure to the query.
     *
     * @param callable(QueryBuilder):void|null $scope
     */
    protected function applyScope(QueryBuilder $query, ?callable $scope): QueryBuilder
    {
        if ($scope !== null) {
            $scope($query);
        }

        return $query;
    }

    /**
     * Create a fresh QueryBuilder instance.
     */
    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->connection, $this->grammar, $this->executor);
    }

    /**
     * Primary key column name.
     *
     * Override if the primary key is not "id".
     */
    protected function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Base query for this repository's table.
     */
    protected function query(): QueryBuilder
    {
        return $this->applyRepositoryConstraints(
            $this->newQuery()->from($this->table()),
        );
    }

    /**
     * Cast a single scalar through configured cast map when column is known.
     */
    private function applyCastValueForColumn(string $column, mixed $value): mixed
    {
        if (! array_key_exists($column, $this->casts)) {
            return $value;
        }

        return $this->castValue($value, $this->casts[$column], false);
    }

    /**
     * Apply configured read casts to one row.
     *
     * @param  array<string,mixed>|null  $row
     * @return array<string,mixed>|null
     */
    private function applyReadCastsToRow(?array $row): ?array
    {
        if ($row === null || $this->casts === []) {
            return $row;
        }

        foreach ($this->casts as $column => $cast) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = $this->castValue($row[$column], $cast, false);
        }

        return $row;
    }

    /**
     * Apply configured read casts to many rows.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function applyReadCastsToRows(array $rows): array
    {
        if ($this->casts === [] || $rows === []) {
            return $rows;
        }

        return array_map(
            fn(array $row): array => $this->applyReadCastsToRow($row) ?? $row,
            $rows,
        );
    }

    /**
     * Apply active tenant value to one row payload when column is absent.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function applyTenantAttributes(array $attributes): array
    {
        if ($this->tenantId === null) {
            return $attributes;
        }

        if (! array_key_exists($this->tenantColumn, $attributes)) {
            $attributes[$this->tenantColumn] = $this->tenantId;
        }

        return $attributes;
    }

    /**
     * Apply active tenant to single-row or multi-row write payloads.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function applyTenantValues(array $values): array
    {
        if ($values === []) {
            return $values;
        }

        $first = reset($values);
        if (is_array($first)) {
            return array_map(
                $this->applyTenantAttributes(...),
                $values,
            );
        }

        return $this->applyTenantAttributes($values);
    }

    /**
     * Apply write casts to one payload.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function applyWriteCastsToAttributes(array $attributes): array
    {
        if ($this->casts === []) {
            return $attributes;
        }

        foreach ($this->casts as $column => $cast) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $attributes[$column] = $this->castValue($attributes[$column], $cast, true);
        }

        return $attributes;
    }

    /**
     * Apply write casts to one or many payload rows.
     *
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    private function applyWriteCastsToValues(array $values): array
    {
        if ($values === []) {
            return $values;
        }

        $first = reset($values);

        if (is_array($first)) {
            return array_map(
                $this->applyWriteCastsToAttributes(...),
                $values,
            );
        }

        return $this->applyWriteCastsToAttributes($values);
    }

    /**
     * Resolve callable parameter count for lifecycle hook dispatching.
     */
    private function callableParameterCount(callable $callable): int
    {
        if (\is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], (string) $callable[1]);

            return $reflection->getNumberOfParameters();
        }

        if (\is_object($callable) && ! $callable instanceof \Closure) {
            $reflection = new \ReflectionMethod($callable, '__invoke');

            return $reflection->getNumberOfParameters();
        }

        $reflection = new \ReflectionFunction(\Closure::fromCallable($callable));

        return $reflection->getNumberOfParameters();
    }

    /**
     * Apply cast rules for one value.
     *
     * @param  string|callable(mixed):mixed  $cast
     */
    private function castValue(mixed $value, string|callable $cast, bool $forWrite): mixed
    {
        if (\is_callable($cast)) {
            return $cast($value);
        }

        $type = strtolower($cast);

        return match ($type) {
            'int', 'integer' => $value === null ? null : (int) $value,
            'float', 'double', 'real' => $value === null ? null : (float) $value,
            'bool', 'boolean' => $value === null ? null : (bool) $value,
            'string' => $value === null ? null : (string) $value,
            'json', 'array' => $forWrite
                ? (is_array($value) || is_object($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value)
                : (is_string($value) ? (json_decode($value, true) ?? $value) : $value),
            'datetime' => $forWrite
                ? $this->normalizeDateTimeForWrite($value)
                : $value,
            default => $value,
        };
    }

    /**
     * Find the first row that matches all given attributes.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>|null
     */
    private function firstByAttributes(array $attributes): ?array
    {
        $query = $this->applyAttributes($this->query(), $attributes);

        return $this->applyReadCastsToRow($query->first());
    }

    /**
     * Generate a DB date-time string for soft deletes.
     */
    private function freshTimestamp(): string
    {
        return new \DateTimeImmutable('now')->format($this->grammar->getDateFormat());
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string,mixed> $row
     */
    private function hydratePublicProperties(ReflectionClass $reflection, object $instance, array $row): void
    {
        foreach ($row as $key => $value) {
            if (! $reflection->hasProperty($key)) {
                continue;
            }

            $property = $reflection->getProperty($key);
            if (! $property->isPublic() || $property->isReadOnly()) {
                continue;
            }

            $property->setValue($instance, $value);
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function instantiateDto(ReflectionClass $reflection, string $className, array $row): object
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveDtoArgument($className, $parameter, $row);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Map one row into a DTO class by constructor/property names.
     *
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function mapRowIntoClass(string $className, array $row): object
    {
        $reflection = $this->resolveDtoReflection($className);
        $instance = $this->instantiateDto($reflection, $className, $row);

        $this->hydratePublicProperties($reflection, $instance, $row);

        return $instance;
    }

    /**
     * Convert DateTime values to grammar-aligned SQL date strings.
     */
    private function normalizeDateTimeForWrite(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->grammar->getDateFormat());
        }

        return $value;
    }

    /**
     * Normalize and validate SQL direction.
     */
    private function normalizeDirection(string $direction): string
    {
        $normalized = strtolower($direction);

        if (! in_array($normalized, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid order direction [%s]. Expected "asc" or "desc".',
                $direction,
            ));
        }

        return $normalized;
    }

    /**
     * Base query without soft-delete visibility constraints.
     */
    private function queryWithoutSoftDeletes(): QueryBuilder
    {
        $withTrashed = $this->withTrashed;
        $onlyTrashed = $this->onlyTrashed;

        $this->withTrashed = true;
        $this->onlyTrashed = false;

        try {
            return $this->query();
        } finally {
            $this->withTrashed = $withTrashed;
            $this->onlyTrashed = $onlyTrashed;
        }
    }

    /**
     * Reload a row after insert when possible.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function reloadCreatedRow(array $payload): ?array
    {
        $primaryKey = $this->primaryKey();
        if (array_key_exists($primaryKey, $payload)) {
            $found = $this->find($payload[$primaryKey]);
            if ($found !== null) {
                return $found;
            }
        }

        $lastInsertId = $this->connection->lastInsertId();

        if ($lastInsertId !== '') {
            $found = $this->find($lastInsertId);
            if ($found !== null) {
                return $found;
            }
        }

        return $this->firstByAttributes($payload);
    }

    /**
     * @param class-string $className
     * @param array<string,mixed> $row
     */
    private function resolveDtoArgument(string $className, ReflectionParameter $parameter, array $row): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $row)) {
            return $row[$name];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot map row into DTO [%s]: missing required field [%s].',
            $className,
            $name,
        ));
    }

    /**
     * @param class-string $className
     */
    private function resolveDtoReflection(string $className): ReflectionClass
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException(
                sprintf('DTO class [%s] does not exist.', $className),
                0,
                $e,
            );
        }

        if (! $reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                sprintf('DTO class [%s] is not instantiable.', $className),
            );
        }

        return $reflection;
    }

    /**
     * Execute payload hooks that can return a transformed payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function runPayloadHooks(string $event, array $payload): array
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $params = $this->callableParameterCount($hook);

            if ($params <= 0) {
                $result = $hook();
            } elseif ($params === 1) {
                $result = $hook($payload);
            } else {
                $result = $hook($payload, $this);
            }

            if (is_array($result)) {
                $payload = $result;
            }
        }

        return $payload;
    }

    /**
     * Execute fire-and-forget hooks with context payload.
     *
     * @param  array<string,mixed>  $context
     */
    private function runVoidHooks(string $event, array $context): void
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $params = $this->callableParameterCount($hook);

            if ($params <= 0) {
                $hook();
            } elseif ($params === 1) {
                $hook($context);
            } else {
                $hook($context, $this);
            }
        }
    }
}
