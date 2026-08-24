<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Generator;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Repository\Concerns\RepositoryQueryAggregates;
use Infocyph\DBLayer\Repository\Concerns\RepositoryQueryMutations;
use Infocyph\DBLayer\Repository\Concerns\RepositoryQueryRelations;
use InvalidArgumentException;

/**
 * Repository-aware fluent query wrapper.
 *
 * Fluent QueryBuilder calls are recorded and replayed through Repository
 * terminal operations. Explicit relation projections preserve repository
 * policy without introducing implicit lazy queries or entity state.
 */
final class RepositoryQuery
{
    use RepositoryQueryAggregates;
    use RepositoryQueryMutations;
    use RepositoryQueryRelations;

    /** @var list<array{method:string,arguments:array<int,mixed>}> */
    private array $operations = [];

    /** @var list<callable(QueryBuilder):void> */
    private array $queryScopes = [];

    /**
     * @var array<string,array{
     *   relation:string,
     *   function:string,
     *   column:string,
     *   constraint:null|callable(QueryBuilder):void,
     *   exists:bool
     * }>
     */
    private array $requestedAggregates = [];

    /** @var array<string,null|callable(QueryBuilder):void> */
    private array $requestedRelations = [];

    public function __construct(
        private readonly Repository $repository,
        private QueryBuilder $builder,
        private readonly Connection $connection,
        private readonly RepositoryDefinition $definition,
    ) {}

    /** @param array<int,mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        if ($this->isBlockedBuilderMutation($method)) {
            throw new BadMethodCallException(sprintf(
                'Mutation %s::%s() has no repository-aware equivalent. Use raw() or builder() for an explicit policy bypass.',
                self::class,
                $method,
            ));
        }

        if (!method_exists($this->builder, $method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s() does not exist on the underlying query builder.',
                self::class,
                $method,
            ));
        }

        $result = $this->builder->$method(...$arguments);

        if ($result === $this->builder) {
            $this->operations[] = [
                'method' => $method,
                'arguments' => $arguments,
            ];

            return $this;
        }

        throw new BadMethodCallException(sprintf(
            'Terminal %s::%s() is not repository-aware. Use raw() or builder() for an explicit policy bypass.',
            self::class,
            $method,
        ));
    }

    /** @param list<Expression|string> $columns */
    public function all(array $columns = ['*']): Collection
    {
        return $this->get($columns);
    }

    /** @param callable(QueryBuilder):void $scope */
    public function apply(callable $scope): self
    {
        $scope($this->builder);
        $this->queryScopes[] = $scope;

        return $this;
    }

    /**
     * @param callable(list<array<string,mixed>>,int):bool $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->repository->chunk(
            $count,
            fn(array $rows, int $page): bool => $callback($this->projectRows($rows), $page),
            $this->scope(),
        );
    }

    /**
     * @param callable(list<array<string,mixed>>,int):bool $callback
     */
    public function chunkById(
        int $count,
        callable $callback,
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
    ): bool {
        return $this->repository->chunkById(
            $count,
            fn(array $rows, int $page): bool => $callback($this->projectRows($rows), $page),
            $column ?? $this->definition->primaryKey,
            $fromId,
            $direction,
            $this->scope(),
        );
    }

    public function count(): int
    {
        return $this->repository->count($this->scope());
    }

    /** @return Generator<mixed> */
    public function cursor(?int $fetchMode = null): Generator
    {
        return $this->projectStream($this->repository->cursor($this->scope(), $fetchMode));
    }

    public function cursorPaginate(
        ?int $perPage = null,
        ?string $cursor = null,
        ?string $uniqueColumn = null,
        ?string $direction = null,
    ): CursorPaginator {
        $paginator = $this->repository->cursorPaginate(
            $this->resolvePerPage($perPage),
            $cursor,
            $uniqueColumn,
            $direction,
            $this->scope(),
        );

        return new CursorPaginator(
            $this->projectRows($this->normalizeRows($paginator->items())),
            $paginator->perPage(),
            $paginator->cursor(),
            $paginator->nextCursor(),
            $paginator->hasMorePages(),
            $paginator->previousCursor(),
            $paginator->hasPreviousPage(),
        );
    }

    public function exists(): bool
    {
        return $this->repository->exists($this->scope());
    }

    /**
     * @param list<Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, array $columns = ['*']): ?array
    {
        $primaryKey = RepositorySupport::column($this->definition->primaryKey);
        $row = $this->repository->first(
            $this->scoped(static function (QueryBuilder $query) use ($id, $primaryKey): void {
                $query->where($primaryKey, '=', $id);
            }),
            $this->projectionColumns($columns),
        );

        return $row === null ? null : $this->projectRows([$row])[0];
    }

    /**
     * @param list<mixed> $ids
     * @param list<Expression|string> $columns
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        $ids = RepositorySupport::uniqueValues($ids);
        if ($ids === []) {
            return new Collection([]);
        }

        $primaryKey = $this->definition->primaryKey;
        $selected = $this->projectionColumns($columns);
        $internalPrimaryKey = !in_array('*', $selected, true) && !in_array($primaryKey, $selected, true);
        if ($internalPrimaryKey) {
            $selected[] = $primaryKey;
        }

        $rows = $this->normalizeRows($this->repository->get(
            $this->scoped(static function (QueryBuilder $query) use ($ids, $primaryKey): void {
                $query->whereIn($primaryKey, $ids);
            }),
            $selected,
        )->toArray());
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[RepositorySupport::key($row[$primaryKey] ?? null)] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            $row = $indexed[RepositorySupport::key($id)] ?? null;
            if ($row !== null) {
                if ($internalPrimaryKey) {
                    unset($row[$primaryKey]);
                }
                $ordered[] = $row;
            }
        }

        return new Collection($this->projectRows($ordered));
    }

    /**
     * @param list<Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function first(array $columns = ['*']): ?array
    {
        $row = $this->repository->first($this->scope(), $this->projectionColumns($columns));

        return $row === null ? null : $this->projectRows([$row])[0];
    }

    /**
     * @param class-string $className
     * @param list<Expression|string> $columns
     */
    public function firstInto(string $className, array $columns = ['*']): ?object
    {
        return $this->repository->firstInto($className, $this->scope(), $this->projectionColumns($columns));
    }

    /**
     * @param callable(array<string,mixed>):mixed $mapper
     * @param list<Expression|string> $columns
     */
    public function firstMap(callable $mapper, array $columns = ['*']): mixed
    {
        $row = $this->first($columns);

        return $row === null ? null : $mapper($row);
    }

    /** @param list<Expression|string> $columns */
    public function get(array $columns = ['*']): Collection
    {
        $rows = $this->normalizeRows($this->repository
            ->get($this->scope(), $this->projectionColumns($columns))
            ->toArray());

        return new Collection($this->projectRows($rows));
    }

    /** @return array<string|int,list<array<string,mixed>>> */
    public function groupByKey(string $column): array
    {
        return $this->repository->groupByKey($column, $this->scope());
    }

    /** @return Generator<mixed> */
    public function lazy(
        int $chunkSize = 1000,
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        return $this->projectStream($this->repository->lazy(
            $chunkSize,
            $this->scope(),
            $column ?? $this->definition->primaryKey,
            $fromId,
            $direction,
        ));
    }

    /** @return Generator<mixed> */
    public function lazyById(
        int $chunkSize = 1000,
        ?string $column = null,
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        return $this->projectStream($this->repository->lazyById(
            $chunkSize,
            $this->scope(),
            $column ?? $this->definition->primaryKey,
            $fromId,
            $direction,
        ));
    }

    /**
     * @param callable(array<string,mixed>):mixed $mapper
     * @param list<Expression|string> $columns
     */
    public function map(callable $mapper, array $columns = ['*']): Collection
    {
        $mapped = [];
        foreach ($this->get($columns) as $value) {
            $row = RepositorySupport::row($value);
            if ($row !== null) {
                $mapped[] = $mapper($row);
            }
        }

        return new Collection($mapped);
    }

    /**
     * @param class-string $className
     * @param list<Expression|string> $columns
     */
    public function mapInto(string $className, array $columns = ['*']): Collection
    {
        return $this->repository->mapInto($className, $this->scope(), $this->projectionColumns($columns));
    }

    public function paginate(?int $perPage = null, ?int $page = null): LengthAwarePaginator
    {
        $paginator = $this->repository->paginate(
            $this->resolvePerPage($perPage),
            $page,
            $this->scope(),
        );

        return new LengthAwarePaginator(
            $this->projectRows($this->normalizeRows($paginator->items())),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
        );
    }

    /** @return array<int|string,mixed> */
    public function pluck(string $column, ?string $keyColumn = null): array
    {
        return $this->repository->pluck($column, $keyColumn, $this->scope());
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function project(array $rows): array
    {
        return $this->projectRows($rows);
    }

    public function raw(): QueryBuilder
    {
        return $this->builder;
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    public function simplePaginate(?int $perPage = null, ?int $page = null): SimplePaginator
    {
        $paginator = $this->repository->simplePaginate(
            $this->resolvePerPage($perPage),
            $page,
            $this->scope(),
        );

        return new SimplePaginator(
            $this->projectRows($this->normalizeRows($paginator->items())),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->hasMorePages(),
        );
    }

    /** @return Generator<mixed> */
    public function stream(?int $fetchMode = null): Generator
    {
        return $this->projectStream($this->repository->stream($this->scope(), $fetchMode));
    }

    /** @return Generator<mixed> */
    public function unbufferedStream(?int $fetchMode = null, int $fetchSize = 1000): Generator
    {
        return $this->projectStream($this->repository->unbufferedStream($this->scope(), $fetchMode, $fetchSize));
    }

    public function value(string $column): mixed
    {
        return $this->repository->value($column, $this->scope());
    }

    public function withoutGlobalScope(string $name): self
    {
        if (!$this->repository instanceof TableQueryRepository) {
            return $this;
        }

        $this->repository->disableNamedGlobalScope($name);
        $this->rebuildBuilder();

        return $this;
    }

    /** @param list<string>|null $names */
    public function withoutGlobalScopes(?array $names = null): self
    {
        if (!$this->repository instanceof TableQueryRepository) {
            return $this;
        }

        $this->repository->disableNamedGlobalScopes($names);
        $this->rebuildBuilder();

        return $this;
    }

    /**
     * @param array<mixed> $values
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            $row = RepositorySupport::row($value);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<Expression|string> $columns
     * @return list<Expression|string>
     */
    private function projectionColumns(array $columns): array
    {
        if ($columns === [] || in_array('*', $columns, true)) {
            return $columns;
        }

        $required = [];

        foreach (array_keys($this->relationGroups()) as $name) {
            foreach ($this->relationParentColumns($this->relationDefinition($name)) as $column) {
                $required[$column] = true;
            }
        }

        foreach ($this->requestedAggregates as $request) {
            foreach ($this->relationParentColumns($this->directRelationDefinition($request['relation'])) as $column) {
                $required[$column] = true;
            }
        }

        foreach (array_keys($required) as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $rows): array
    {
        return $this->loadAggregates($this->loadRelations($rows));
    }

    /**
     * @param iterable<mixed> $rows
     * @return Generator<mixed>
     */
    private function projectStream(iterable $rows): Generator
    {
        $batch = [];
        foreach ($rows as $value) {
            $row = RepositorySupport::row($value);
            if ($row === null) {
                yield $value;

                continue;
            }

            $batch[] = $row;
            if (count($batch) < 500) {
                continue;
            }

            yield from $this->projectRows($batch);
            $batch = [];
        }

        if ($batch !== []) {
            yield from $this->projectRows($batch);
        }
    }

    private function rebuildBuilder(): void
    {
        $this->builder = $this->repository->builder();

        foreach ($this->operations as $operation) {
            $this->builder->{$operation['method']}(...$operation['arguments']);
        }

        foreach ($this->queryScopes as $scope) {
            $scope($this->builder);
        }
    }

    private function resolvePerPage(?int $perPage): int
    {
        $perPage ??= $this->definition->perPage;

        if ($perPage < 1) {
            throw new InvalidArgumentException('Pagination size must be at least one.');
        }

        return $perPage;
    }

    /** @return callable(QueryBuilder):void */
    private function scope(): callable
    {
        $operations = $this->operations;
        $scopes = $this->queryScopes;

        return static function (QueryBuilder $query) use ($operations, $scopes): void {
            foreach ($operations as $operation) {
                $query->{$operation['method']}(...$operation['arguments']);
            }

            foreach ($scopes as $scope) {
                $scope($query);
            }
        };
    }

    /** @param callable(QueryBuilder):void $additional */
    private function scoped(callable $additional): callable
    {
        $scope = $this->scope();

        return static function (QueryBuilder $query) use ($additional, $scope): void {
            $scope($query);
            $additional($query);
        };
    }
}
