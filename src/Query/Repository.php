<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use DateInterval;
use Generator;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\Concerns\RepositoryInternals;
use Infocyph\DBLayer\Repository\Casts\AttributeCast;
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
     * Whether repository reads opt in to query-result caching.
     */
    protected bool $cacheEnabled = false;

    /**
     * Optional repository-wide query-cache TTL.
     */
    protected DateInterval|int|null $cacheTtl = null;

    /**
     * Attribute casts.
     *
     * @var array<string,string|callable(mixed):mixed|AttributeCast>
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

    /** Register callback after create. */
    public function afterCreate(callable $callback): static
    {
        return $this->on('afterCreate', $callback);
    }

    /** Register callback after delete. */
    public function afterDelete(callable $callback): static
    {
        return $this->on('afterDelete', $callback);
    }

    /** Register callback after update. */
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

    /** Register callback before create. */
    public function beforeCreate(callable $callback): static
    {
        return $this->on('beforeCreate', $callback);
    }

    /** Register callback before delete. */
    public function beforeDelete(callable $callback): static
    {
        return $this->on('beforeDelete', $callback);
    }

    /** Register callback before update. */
    public function beforeUpdate(callable $callback): static
    {
        return $this->on('beforeUpdate', $callback);
    }

    /** Get a ready-to-use QueryBuilder for advanced usage. */
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

    /** Opt repository reads into CacheLayer-backed result caching. */
    public function cacheFor(DateInterval|int|null $ttl): static
    {
        if (is_int($ttl) && $ttl < 1) {
            throw new InvalidArgumentException('Repository cache TTL must be positive or null.');
        }

        $this->cacheEnabled = true;
        $this->cacheTtl = $ttl;

        return $this;
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

        return $query->chunk(
            $count,
            fn(array $rows, int $page): bool => $callback($this->applyReadCastsToRows($rows), $page),
        );
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
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
        ?callable $scope = null,
    ): bool {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->chunkById(
            $count,
            fn(array $rows, int $page): bool => $callback($this->applyReadCastsToRows($rows), $page),
            $this->normalizeColumnName($column ?? $this->primaryKey(), 'id'),
            $fromId,
            $this->normalizeDirection($direction),
        );
    }

    public function clearDefaultOrders(): static
    {
        $this->defaultOrders = [];

        return $this;
    }

    public function clearGlobalScopes(): static
    {
        $this->globalScopes = [];

        return $this;
    }

    /** @param callable(QueryBuilder):void|null $scope */
    public function count(?callable $scope = null): int
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->count();
    }

    /**
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
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<mixed>
     */
    public function cursor(?callable $scope = null, ?int $fetchMode = null): Generator
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $this->castRows($query->cursor($fetchMode));
    }

    /** @param callable(QueryBuilder):void|null $scope */
    public function cursorPaginate(
        ?int $perPage = null,
        ?string $cursor = null,
        ?string $uniqueColumn = null,
        ?string $direction = null,
        ?callable $scope = null,
    ): CursorPaginator {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->cursorPaginate(
            $this->resolvePerPage($perPage),
            $cursor,
            $this->normalizeColumnName($uniqueColumn ?? $this->primaryKey(), 'id'),
            $direction === null ? null : $this->normalizeDirection($direction),
        );
    }

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

    public function disableOptimisticLocking(): static
    {
        $this->optimisticLockColumn = null;

        return $this;
    }

    public function disableSoftDeletes(): static
    {
        $this->softDeletes = false;
        $this->withTrashed = false;
        $this->onlyTrashed = false;
        $this->softDeleteColumn = 'deleted_at';

        return $this;
    }

    public function enableOptimisticLocking(string $column = 'version'): static
    {
        $this->optimisticLockColumn = $column;

        return $this;
    }

    public function enableSoftDeletes(string $column = 'deleted_at'): static
    {
        $this->softDeletes = true;
        $this->softDeleteColumn = $column;
        $this->withTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    /** @param callable(QueryBuilder):void|null $scope */
    public function exists(?callable $scope = null): bool
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $query->exists();
    }

    /**
     * @param list<Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, array $columns = ['*']): ?array
    {
        $key = $this->normalizeColumnName($this->primaryKey(), 'id');
        $query = $this->applySelectedColumns(
            $this->query(),
            $columns,
        )->where($key, '=', $id);

        if ($this->cacheEnabled && (is_int($id) || is_string($id))) {
            $query->cacheTags($this->connection->cacheTableTag(
                $this->table(),
                $key . '.' . $this->typedCacheKey($id),
            ));
        }

        $row = $query->first();

        return $this->applyReadCastsToRow($row);
    }

    /**
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
        $requestedIds = $this->toList($ids);
        $uniqueIds = $this->uniqueFindManyIds($requestedIds);
        $rows = [];

        foreach (array_chunk($uniqueIds, $this->findManyBatchSize()) as $batch) {
            $batchRows = $this->applySelectedColumns(
                $this->query(),
                $columns,
            )
              ->whereIn($key, $batch)
              ->get();
            array_push($rows, ...$batchRows);
        }

        $rows = $this->applyReadCastsToRows($rows);
        $rows = $this->canOrderFindManyRows($columns, $key)
            ? $this->orderFindManyRows($rows, $requestedIds, $key)
            : $rows;

        return $this->results->process($rows);
    }

    /**
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

        try {
            $this->create($payload);
        } catch (\Infocyph\DBLayer\Exceptions\QueryException $error) {
            $raced = $this->firstByAttributes($attributes);
            if ($raced !== null) {
                return $raced;
            }

            throw $error;
        }

        $created = $this->firstByAttributes($attributes);
        if ($created !== null) {
            return $created;
        }

        $fallback = $this->firstByAttributes($payload);

        return $fallback ?? $this->applyTenantAttributes($payload);
    }

    public function forceDeleteById(mixed $id): int
    {
        $this->runVoidHooks('beforeDelete', ['id' => $id, 'force' => true]);

        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
          ->delete();

        $this->runVoidHooks('afterDelete', ['id' => $id, 'affected' => $affected, 'force' => true]);

        return $affected;
    }

    public function forTenant(int|string $tenantId, string $column = 'tenant_id'): static
    {
        $this->tenantId = $tenantId;
        $this->tenantColumn = $column;

        return $this;
    }

    /**
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
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<mixed>
     */
    public function lazy(
        int $chunkSize = 1000,
        ?callable $scope = null,
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        yield from $this->lazyById($chunkSize, $scope, $column, $fromId, $direction);
    }

    /**
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<array<string,mixed>>
     */
    public function lazyById(
        int $chunkSize = 1000,
        ?callable $scope = null,
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        foreach ($query->lazyById(
            $chunkSize,
            $this->normalizeColumnName($column ?? $this->primaryKey(), 'id'),
            $fromId,
            $this->normalizeDirection($direction),
        ) as $row) {
            yield $this->applyReadCastsToRow($row) ?? $row;
        }
    }

    /**
     * @param callable(array<string,mixed>):mixed $mapper
     * @param callable(QueryBuilder):void|null $scope
     * @param list<Expression|string> $columns
     * @return Collection<int|string,mixed>
     */
    public function map(callable $mapper, ?callable $scope = null, array $columns = ['*']): Collection
    {
        $rows = $this->get($scope, $columns);

        return new Collection(array_map(
            static function (mixed $row) use ($mapper): mixed {
                if (is_array($row)) {
                    return $mapper(self::normalizeAttributeArray($row));
                }

                if (is_object($row)) {
                    return $mapper(self::normalizeAttributeArray(get_object_vars($row)));
                }

                return $mapper([]);
            },
            $rows->all(),
        ));
    }

    /**
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

    public function onlyTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = true;

        return $this;
    }

    /** @param callable(QueryBuilder):void|null $scope */
    public function paginate(?int $perPage = null, ?int $page = null, ?callable $scope = null): LengthAwarePaginator
    {
        $perPage = $this->resolvePerPage($perPage);
        $query = $this->ensurePaginationOrder($this->applyScope(
            $this->query(),
            $scope,
        ));

        return $query->paginate($perPage, $page);
    }

    /**
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
     * @param array<string,string|callable(mixed):mixed|AttributeCast> $casts
     */
    public function setCasts(array $casts): static
    {
        $this->casts = $casts;

        return $this;
    }

    public function setDefaultOrder(string $column, string $direction = 'asc'): static
    {
        $this->defaultOrders = [];

        return $this->addDefaultOrder($column, $direction);
    }

    /** @param callable(QueryBuilder):void|null $scope */
    public function simplePaginate(?int $perPage = null, ?int $page = null, ?callable $scope = null): SimplePaginator
    {
        $perPage = $this->resolvePerPage($perPage);
        $query = $this->ensurePaginationOrder($this->applyScope(
            $this->query(),
            $scope,
        ));

        return $query->simplePaginate($perPage, $page);
    }

    /**
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<mixed>
     */
    public function stream(?callable $scope = null, ?int $fetchMode = null): Generator
    {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $this->castRows($query->stream($fetchMode));
    }

    /**
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<mixed>
     */
    public function unbufferedStream(
        ?callable $scope = null,
        ?int $fetchMode = null,
        int $fetchSize = 1000,
    ): Generator {
        $query = $this->applyScope(
            $this->query(),
            $scope,
        );

        return $this->castRows($query->unbufferedStream($fetchMode, $fetchSize));
    }

    /** @param array<string,mixed> $values */
    public function updateById(mixed $id, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $payload = $this->applyWriteCastsToAttributes($values);
        $payload = $this->runPayloadHooks('beforeUpdate', $payload);

        return $this->updatePreparedById($id, $payload);
    }

    /** @param array<string,mixed> $values */
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
            $this->updatePreparedById($existing[$primaryKey], $payload);

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
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        $payload = $this->applyWriteCastsToValues($this->applyTenantValues($values));

        return $this->query()->upsert($payload, $uniqueBy, $update);
    }

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

    public function withoutTenant(): static
    {
        $this->tenantId = null;
        $this->tenantColumn = 'tenant_id';

        return $this;
    }

    public function withoutTrashed(): static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;

        return $this;
    }

    /**
     * @param callable(QueryBuilder):void|null $scope
     * @return Generator<mixed>
     */
    public function yieldRows(?callable $scope = null, ?int $fetchMode = null): Generator
    {
        yield from $this->stream($scope, $fetchMode);
    }

    /** @param array<string,mixed> $attributes */
    protected function applyAttributes(QueryBuilder $query, array $attributes): QueryBuilder
    {
        foreach ($attributes as $column => $value) {
            $normalized = $this->normalizeColumnName((string) $column, 'id');
            $query->where($normalized, '=', $value);
        }

        return $query;
    }

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

    /** @param callable(QueryBuilder):void|null $scope */
    protected function applyScope(QueryBuilder $query, ?callable $scope): QueryBuilder
    {
        if ($scope !== null) {
            $scope($query);
        }

        return $query;
    }

    protected function defaultPerPage(): int
    {
        return 15;
    }

    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->connection, $this->executor);
    }

    protected function primaryKey(): string
    {
        return 'id';
    }

    protected function query(): QueryBuilder
    {
        $query = $this->applyRepositoryConstraints(
            $this->newQuery()->from($this->table()),
        );

        if (!$this->cacheEnabled) {
            return $query;
        }

        $tags = [$this->connection->cacheTableTag($this->table())];

        if ($this->tenantId !== null) {
            $tags[] = $this->connection->cacheTableTag(
                $this->table(),
                'tenant.' . $this->typedCacheKey($this->tenantId),
            );
        }

        return $query
          ->cacheFor($this->cacheTtl)
          ->cacheTags($tags);
    }

    /** @param list<Expression|string> $columns */
    private function canOrderFindManyRows(array $columns, string $key): bool
    {
        return in_array('*', $columns, true) || in_array($key, $columns, true);
    }

    /**
     * @param iterable<mixed> $rows
     * @return Generator<mixed>
     */
    private function castRows(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                yield $row;

                continue;
            }

            $normalized = self::normalizeAttributeArray($row);
            yield $this->applyReadCastsToRow($normalized) ?? $normalized;
        }
    }

    /** Ensure offset pagination is deterministic and valid on every driver. */
    private function ensurePaginationOrder(QueryBuilder $query): QueryBuilder
    {
        if ($query->getComponents()['orders'] === []) {
            $query->orderBy($this->normalizeColumnName($this->primaryKey(), 'id'));
        }

        return $query;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function findDatabaseEquivalentRow(array $rows, int|string $id, string $key): ?array
    {
        foreach ($rows as $row) {
            if (array_key_exists($key, $row) && $row[$key] == $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return positive-int */
    private function findManyBatchSize(): int
    {
        return $this->connection->safeBatchSize();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<mixed> $requestedIds
     * @return list<array<string,mixed>>
     */
    private function orderFindManyRows(array $rows, array $requestedIds, string $key): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $value = $row[$key] ?? null;

            if (is_int($value) || is_string($value)) {
                $indexed[$this->typedCacheKey($value)] = $row;
            }
        }

        $ordered = [];

        foreach ($requestedIds as $id) {
            if (!is_int($id) && !is_string($id)) {
                continue;
            }

            $row = $indexed[$this->typedCacheKey($id)] ?? $this->findDatabaseEquivalentRow($rows, $id, $key);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }

        return $ordered;
    }

    private function resolvePerPage(?int $perPage): int
    {
        $perPage ??= $this->defaultPerPage();

        if ($perPage < 1) {
            throw new InvalidArgumentException('Pagination size must be at least one.');
        }

        return $perPage;
    }

    private function typedCacheKey(int|string $value): string
    {
        return (is_int($value) ? 'i.' : 's.') . hash('xxh3', (string) $value);
    }

    /**
     * @param list<mixed> $ids
     * @return list<mixed>
     */
    private function uniqueFindManyIds(array $ids): array
    {
        $unique = [];
        $seen = [];

        foreach ($ids as $id) {
            $identity = get_debug_type($id) . ':' . serialize($id);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $unique[] = $id;
        }

        return $unique;
    }

    /** @param array<string,mixed> $payload */
    private function updatePreparedById(mixed $id, array $payload): int
    {
        $affected = $this->queryWithoutSoftDeletes()
          ->where($this->normalizeColumnName($this->primaryKey(), 'id'), '=', $id)
          ->update($payload);

        $this->runVoidHooks('afterUpdate', ['id' => $id, 'payload' => $payload, 'affected' => $affected]);

        return $affected;
    }
}
