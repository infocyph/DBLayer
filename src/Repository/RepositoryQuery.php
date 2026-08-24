<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;

/**
 * Repository-aware fluent query wrapper.
 *
 * Fluent QueryBuilder calls are recorded and replayed through Repository
 * terminal operations. This preserves repository casts and result processing
 * without copying mutable QueryBuilder state or widening Repository internals.
 */
final class RepositoryQuery
{
    /**
     * @var list<array{method:string,arguments:array<int,mixed>}>
     */
    private array $operations = [];

    public function __construct(
        private readonly Repository $repository,
        private readonly QueryBuilder $builder,
    ) {}

    /**
     * Delegate QueryBuilder operations while retaining replayable fluent state.
     *
     * @param array<int,mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
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

    public function count(): int
    {
        return $this->repository->count($this->scope());
    }

    public function cursorPaginate(
        int $perPage = 15,
        ?string $cursor = null,
        ?string $uniqueColumn = null,
        ?string $direction = null,
    ): CursorPaginator {
        return $this->repository->cursorPaginate(
            $perPage,
            $cursor,
            $uniqueColumn,
            $direction,
            $this->scope(),
        );
    }

    public function exists(): bool
    {
        return $this->repository->exists($this->scope());
    }

    /**
     * Get the first repository-processed row.
     *
     * @return array<string,mixed>|null
     */
    public function first(array $columns = ['*']): ?array
    {
        return $this->repository->first($this->scope(), $columns);
    }

    /**
     * Get repository-processed rows as a Collection.
     *
     * @param list<\Infocyph\DBLayer\Query\Expression|string> $columns
     */
    public function get(array $columns = ['*']): Collection
    {
        return $this->repository->get($this->scope(), $columns);
    }

    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $page, $this->scope());
    }

    /**
     * Access the intentionally raw, already-shaped QueryBuilder.
     */
    public function raw(): QueryBuilder
    {
        return $this->builder;
    }

    /**
     * Access the underlying repository instance.
     */
    public function repository(): Repository
    {
        return $this->repository;
    }

    public function simplePaginate(int $perPage = 15, ?int $page = null): SimplePaginator
    {
        return $this->repository->simplePaginate($perPage, $page, $this->scope());
    }

    public function value(string $column): mixed
    {
        return $this->repository->value($column, $this->scope());
    }

    /**
     * Build a replay closure for Repository terminal operations.
     */
    private function scope(): callable
    {
        $operations = $this->operations;

        return static function (QueryBuilder $query) use ($operations): void {
            foreach ($operations as $operation) {
                $query->{$operation['method']}(...$operation['arguments']);
            }
        };
    }
}
