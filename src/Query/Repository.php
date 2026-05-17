<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Generator;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\Concerns\RepositoryInternals;
use Infocyph\DBLayer\Support\Collection;
use InvalidArgumentException;

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
    use RepositoryInternals;

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
     *
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function all(array $columns = ['*']): Collection
    {
        $rows = $this->applySelectedColumns(
            $this->query(),
            $columns,
        )
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

        return $query->cursorPaginate(
            $perPage,
            $cursor,
            $this->normalizeColumnName($column, 'id'),
            $this->normalizeDirection($direction),
        );
    }

    /**
     * Delete one row by primary key.
     */
    public function deleteById(mixed $id): int
    {
        $this->runVoidHooks('beforeDelete', ['id' => $id, 'soft' => $this->softDeletes]);

        if ($this->softDeletes) {
            $affected = $this->queryWithoutSoftDeletes()
              ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
              ->update([$this->normalizeColumnName($this->softDeleteColumn, 'deleted_at') => $this->freshTimestamp()]);

            $this->runVoidHooks('afterDelete', ['id' => $id, 'affected' => $affected, 'soft' => true]);

            return $affected;
        }

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
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
     * @param list<Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, array $columns = ['*']): ?array
    {
        $key = $this->normalizeColumnName($this->primaryKey(), 'id');
        $row = $this->applySelectedColumns(
            $this->query(),
            $columns,
        )
          ->where($key, '=', $id)
          ->first();

        return $this->applyReadCastsToRow($row);
    }

    /**
     * Find multiple rows by primary key.
     *
     * @param list<mixed> $ids
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        if ($ids === []) {
            return $this->results->process([]);
        }

        $key = $this->normalizeColumnName($this->primaryKey(), 'id');

        $rows = $this->applySelectedColumns(
            $this->query(),
            $columns,
        )
          ->whereIn($key, $this->toList($ids))
          ->get();
        $rows = $this->applyReadCastsToRows($rows);

        return $this->results->process($rows);
    }

    /**
     * Get the first row matching an optional scoped query.
     *
     * @param callable(QueryBuilder):void|null $scope
     * @param list<Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function first(?callable $scope = null, array $columns = ['*']): ?array
    {
        $query = $this->applyScope(
            $this->applySelectedColumns($this->query(), $columns),
            $scope,
        );

        return $this->applyReadCastsToRow($query->first());
    }

    /**
     * Map first scoped row into a DTO object.
     *
     * @param class-string $className
     * @param callable(QueryBuilder):void|null $scope
     * @param list<Expression|string> $columns
     */
    public function firstInto(string $className, ?callable $scope = null, array $columns = ['*']): ?object
    {
        $row = $this->first($scope, $columns);

        if ($row === null) {
            return null;
        }

        return $this->mapRowIntoClass($className, $row);
    }

    /**
     * Map first scoped row through a callback.
     *
     * @param callable(array<string,mixed>):mixed $mapper
     * @param callable(QueryBuilder):void|null $scope
     * @param list<Expression|string> $columns
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
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
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
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function get(?callable $scope = null, array $columns = ['*']): Collection
    {
        $query = $this->applyScope(
            $this->applySelectedColumns($this->query(), $columns),
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
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function map(callable $mapper, ?callable $scope = null, array $columns = ['*']): Collection
    {
        $rows = $this->get($scope, $columns);

        return $rows->map(
            static function (mixed $row) use ($mapper): mixed {
                if (is_array($row)) {
                    return $mapper(self::normalizeAttributeArray($row));
                }

                if (is_object($row)) {
                    return $mapper(self::normalizeAttributeArray(get_object_vars($row)));
                }

                return $mapper([]);
            },
        );
    }

    /**
     * Map scoped rows into DTO objects by constructor/property name.
     *
     * @param class-string $className
     * @param callable(QueryBuilder):void|null $scope
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function mapInto(string $className, ?callable $scope = null, array $columns = ['*']): Collection
    {
        $mapped = [];

        foreach ($this->get($scope, $columns) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapped[] = $this->mapRowIntoClass($className, self::normalizeAttributeArray($row));
        }

        return new Collection($mapped);
    }

    /**
     * Register a lifecycle hook callback.
     */
    public function on(string $event, callable $callback): static
    {
        if (!array_key_exists($event, $this->hooks)) {
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
        if (!$this->softDeletes) {
            return 0;
        }

        return $this->queryWithoutSoftDeletes()
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
          ->whereNotNull($this->normalizeColumnName($this->softDeleteColumn, 'deleted_at'))
          ->update([$this->normalizeColumnName($this->softDeleteColumn, 'deleted_at') => null]);
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
     * @param array<string,string|callable(mixed):mixed> $casts
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
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
          ->update($payload);

        $this->runVoidHooks('afterUpdate', ['id' => $id, 'payload' => $payload, 'affected' => $affected]);

        return $affected;
    }

    /**
     * Update row by id only if expected version matches current version.
     *
     * @param array<string,mixed> $values
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
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
          ->where($this->normalizeColumnName($column, 'version'), '=', $expectedVersion)
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
            $this->query()->select($column),
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
            $normalized = $this->normalizeColumnName((string) $column, 'id');
            $query->where($normalized, '=', $value);
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
            $query->where($this->normalizeColumnName($this->tenantColumn, 'tenant_id'), '=', $this->tenantId);
        }

        foreach ($this->defaultOrders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        if ($this->softDeletes) {
            if ($this->onlyTrashed) {
                $query->whereNotNull($this->normalizeColumnName($this->softDeleteColumn, 'deleted_at'));
            } elseif (!$this->withTrashed) {
                $query->whereNull($this->normalizeColumnName($this->softDeleteColumn, 'deleted_at'));
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
}
