<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use InvalidArgumentException;

/**
 * Repository-aware fluent query wrapper.
 *
 * Fluent QueryBuilder calls are recorded and replayed through Repository
 * terminal operations. Explicit eager relations are projected after the base
 * repository read through the existing bounded RelationLoader.
 */
final class RepositoryQuery
{
    /**
     * @var list<array{method:string,arguments:array<int,mixed>}>
     */
    private array $operations = [];

    /**
     * @var array<string,null|callable(QueryBuilder):void>
     */
    private array $requestedRelations = [];

    /**
     * @param array<string,RelationDefinition> $relations
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly QueryBuilder $builder,
        private readonly Connection $connection,
        private readonly array $relations = [],
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
     * @param list<\Infocyph\DBLayer\Query\Expression|string> $columns
     * @return array<string,mixed>|null
     */
    public function first(array $columns = ['*']): ?array
    {
        $row = $this->repository->first($this->scope(), $columns);

        if ($row === null || $this->requestedRelations === []) {
            return $row;
        }

        return $this->loadRelations([$row])[0] ?? $row;
    }

    /**
     * Get repository-processed rows as a Collection.
     *
     * @param list<\Infocyph\DBLayer\Query\Expression|string> $columns
     */
    public function get(array $columns = ['*']): Collection
    {
        $rows = $this->repository->get($this->scope(), $columns);

        if ($this->requestedRelations === []) {
            return $rows;
        }

        return new Collection($this->loadRelations($rows->toArray()));
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
     * Explicitly eager-load one or more declared relations.
     *
     * Examples:
     *   ->with('user', 'comments')
     *   ->with(['comments' => fn ($q) => $q->where('approved', 1)])
     *
     * @param string|array<string|int,string|callable(QueryBuilder):void> ...$relations
     */
    public function with(string|array ...$relations): self
    {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->requestedRelations[$relation] = null;

                continue;
            }

            foreach ($relation as $name => $constraint) {
                if (is_int($name)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation entries must contain relation names.');
                    }

                    $this->requestedRelations[$constraint] = null;

                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation constraint for [%s] must be callable.',
                        $name,
                    ));
                }

                $this->requestedRelations[$name] = $constraint;
            }
        }

        return $this;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadRelations(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $loader = new RelationLoader($this->connection);

        foreach ($this->requestedRelations as $name => $constraint) {
            $definition = $this->relations[$name] ?? null;
            if (!$definition instanceof RelationDefinition) {
                throw new InvalidArgumentException(sprintf(
                    'Relation [%s] is not defined for this repository.',
                    $name,
                ));
            }

            $related = $definition->related;
            if (!is_a($related, TableRepository::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Related repository [%s] must extend %s.',
                    $related,
                    TableRepository::class,
                ));
            }

            $scope = $this->relationScope($definition, $constraint);
            $table = $related::table();

            $rows = match ($definition->type) {
                RelationDefinition::BELONGS_TO,
                RelationDefinition::HAS_ONE => $loader->one(
                    $rows,
                    $definition->parentKey,
                    $table,
                    $definition->relatedKey,
                    $name,
                    $definition->columns,
                    $scope,
                ),
                RelationDefinition::HAS_MANY => $loader->many(
                    $rows,
                    $definition->parentKey,
                    $table,
                    $definition->relatedKey,
                    $name,
                    $definition->columns,
                    $scope,
                ),
                RelationDefinition::BELONGS_TO_MANY => $loader->manyToMany(
                    $rows,
                    $definition->parentKey,
                    $definition->pivotTable
                        ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.'),
                    $definition->pivotParentKey
                        ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.'),
                    $definition->pivotRelatedKey
                        ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.'),
                    $table,
                    $definition->relatedKey,
                    $name,
                    $definition->columns,
                    $scope,
                ),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported relation type [%s].',
                    $definition->type,
                )),
            };
        }

        return $rows;
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return null|callable(QueryBuilder):void
     */
    private function relationScope(RelationDefinition $definition, ?callable $constraint): ?callable
    {
        if ($definition->scope === null) {
            return $constraint;
        }

        if ($constraint === null) {
            return $definition->scope;
        }

        $base = $definition->scope;

        return static function (QueryBuilder $query) use ($base, $constraint): void {
            $base($query);
            $constraint($query);
        };
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
