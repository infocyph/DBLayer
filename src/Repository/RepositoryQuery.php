<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
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

        return $result;
    }

    /** @param callable(QueryBuilder):void $scope */
    public function apply(callable $scope): self
    {
        $scope($this->builder);
        $this->queryScopes[] = $scope;

        return $this;
    }

    public function count(): int
    {
        return $this->repository->count($this->scope());
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
    public function first(array $columns = ['*']): ?array
    {
        $row = $this->repository->first($this->scope(), $this->projectionColumns($columns));

        return $row === null ? null : $this->projectRows([$row])[0];
    }

    /** @param list<Expression|string> $columns */
    public function get(array $columns = ['*']): Collection
    {
        $rows = $this->normalizeRows($this->repository
            ->get($this->scope(), $this->projectionColumns($columns))
            ->toArray());

        return new Collection($this->projectRows($rows));
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
}
