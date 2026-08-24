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
 * terminal operations. Explicit relation projections preserve repository
 * policy without introducing implicit lazy queries or entity state.
 */
final class RepositoryQuery
{
    /**
     * @var list<array{method:string,arguments:array<int,mixed>}>
     */
    private array $operations = [];

    /**
     * @var list<callable(QueryBuilder):void>
     */
    private array $queryScopes = [];

    /**
     * @var array<string,array{relation:string,constraint:null|callable(QueryBuilder):void}>
     */
    private array $requestedCounts = [];

    /**
     * @var array<string,null|callable(QueryBuilder):void>
     */
    private array $requestedRelations = [];

    /**
     * @param array<string,RelationDefinition> $relations
     */
    public function __construct(
        private readonly Repository $repository,
        private QueryBuilder $builder,
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

    /**
     * Apply an explicit QueryBuilder scope while retaining repository terminal
     * processing and raw-builder parity.
     *
     * @param callable(QueryBuilder):void $scope
     */
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

        if ($row === null) {
            return null;
        }

        return $this->projectRows([$row])[0] ?? $row;
    }

    /**
     * Get repository-processed rows as a Collection.
     *
     * @param list<\Infocyph\DBLayer\Query\Expression|string> $columns
     */
    public function get(array $columns = ['*']): Collection
    {
        $rows = $this->repository->get($this->scope(), $columns)->toArray();

        return new Collection($this->projectRows($rows));
    }

    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $paginator = $this->repository->paginate($perPage, $page, $this->scope());

        return new LengthAwarePaginator(
            $this->projectRows($paginator->items()),
            $paginator->total() ?? 0,
            $paginator->perPage(),
            $paginator->currentPage(),
        );
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
        $paginator = $this->repository->simplePaginate($perPage, $page, $this->scope());

        return new SimplePaginator(
            $this->projectRows($paginator->items()),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->hasMorePages(),
        );
    }

    public function value(string $column): mixed
    {
        return $this->repository->value($column, $this->scope());
    }

    /**
     * Explicitly eager-load one or more declared relations.
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
     * Add relation counts without hydrating related rows.
     *
     * Supports aliases such as:
     *   ->withCount('comments')
     *   ->withCount(['comments as pending_comments_count' => fn ($q) => ...])
     *
     * @param string|array<string|int,string|callable(QueryBuilder):void> ...$relations
     */
    public function withCount(string|array ...$relations): self
    {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->registerCount($relation, null);

                continue;
            }

            foreach ($relation as $expression => $constraint) {
                if (is_int($expression)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation-count entries must contain relation names.');
                    }

                    $this->registerCount($constraint, null);

                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation count constraint for [%s] must be callable.',
                        $expression,
                    ));
                }

                $this->registerCount($expression, $constraint);
            }
        }

        return $this;
    }

    /**
     * Disable one named global scope for this query only.
     */
    public function withoutGlobalScope(string $name): self
    {
        if (!$this->repository instanceof TableQueryRepository) {
            return $this;
        }

        $this->repository->withoutGlobalScope($name);
        $this->rebuildBuilder();

        return $this;
    }

    /**
     * Disable selected named global scopes, or all named scopes when omitted.
     *
     * @param list<string>|null $names
     */
    public function withoutGlobalScopes(?array $names = null): self
    {
        if (!$this->repository instanceof TableQueryRepository) {
            return $this;
        }

        $this->repository->withoutGlobalScopes($names);
        $this->rebuildBuilder();

        return $this;
    }

    /**
     * Attach requested relation counts to parent rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadCounts(array $rows): array
    {
        if ($rows === [] || $this->requestedCounts === []) {
            return $rows;
        }

        $counter = new RepositoryRelationCounter($this->connection);

        foreach ($this->requestedCounts as $alias => $request) {
            $definition = $this->relationDefinition($request['relation']);
            $counts = $counter->count($rows, $definition, $request['constraint']);

            foreach ($rows as &$row) {
                $row[$alias] = $counts[$this->relationKey($row[$definition->parentKey] ?? null)] ?? 0;
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadRelations(array $rows): array
    {
        if ($rows === [] || $this->requestedRelations === []) {
            return $rows;
        }

        $loader = new RepositoryRelationLoader($this->connection);

        foreach ($this->requestedRelations as $name => $constraint) {
            $rows = $loader->load(
                $rows,
                $name,
                $this->relationDefinition($name),
                $constraint,
            );
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function projectRows(array $rows): array
    {
        return $this->loadCounts($this->loadRelations($rows));
    }

    /**
     * Rebuild the raw builder after repository-scope state changes and replay
     * already-recorded fluent operations and explicit query scopes.
     */
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

    /**
     * @param null|callable(QueryBuilder):void $constraint
     */
    private function registerCount(string $expression, ?callable $constraint): void
    {
        $parts = preg_split('/\s+as\s+/i', trim($expression), 2);
        $relation = trim((string) ($parts[0] ?? ''));
        $alias = trim((string) ($parts[1] ?? ($relation . '_count')));

        if ($relation === '' || $alias === '') {
            throw new InvalidArgumentException('Relation count name and alias must be non-empty.');
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid relation count alias [%s].',
                $alias,
            ));
        }

        $this->relationDefinition($relation);
        $this->requestedCounts[$alias] = [
            'relation' => $relation,
            'constraint' => $constraint,
        ];
    }

    private function relationDefinition(string $name): RelationDefinition
    {
        $definition = $this->relations[$name] ?? null;

        if (!$definition instanceof RelationDefinition) {
            throw new InvalidArgumentException(sprintf(
                'Relation [%s] is not defined for this repository.',
                $name,
            ));
        }

        return $definition;
    }

    private function relationKey(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            $value === null => 'null:',
            default => throw new InvalidArgumentException('Relation keys must be scalar or null.'),
        };
    }

    /**
     * Build a replay closure for Repository terminal operations.
     */
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
