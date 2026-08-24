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

        if ($row === null) {
            return null;
        }

        return $this->projectRows([$row])[0];
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
     * Project repository relations/aggregates onto already-loaded rows.
     *
     * This is intentionally public for bounded nested relation projection; it
     * does not execute a base-table query or create entity state.
     *
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

    /** @param string|array<string|int,string|callable(QueryBuilder):void> ...$relations */
    public function with(string|array ...$relations): self
    {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->registerRelation($relation, null);
                continue;
            }

            foreach ($relation as $name => $constraint) {
                if (is_int($name)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation entries must contain relation names.');
                    }

                    $this->registerRelation($constraint, null);
                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation constraint for [%s] must be callable.',
                        $name,
                    ));
                }

                $this->registerRelation($name, $constraint);
            }
        }

        return $this;
    }

    /** @param string|array<string|int,string|callable(QueryBuilder):void> ...$relations */
    public function withCount(string|array ...$relations): self
    {
        $this->registerAggregateList('count', '*', false, $relations);

        return $this;
    }

    /** @param string|array<string|int,string|callable(QueryBuilder):void> ...$relations */
    public function withExists(string|array ...$relations): self
    {
        $this->registerAggregateList('count', '*', true, $relations);

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withAggregate(
        string $relation,
        string $column,
        string $function,
        ?string $alias = null,
        ?callable $constraint = null,
    ): self {
        $function = strtolower(trim($function));
        if (!in_array($function, ['avg', 'count', 'max', 'min', 'sum'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported relation aggregate [%s].', $function));
        }

        $this->registerAggregate(
            $relation,
            $function,
            $function === 'count' ? '*' : $column,
            $alias,
            $constraint,
            false,
        );

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withSum(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'sum', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withAvg(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'avg', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withMin(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'min', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function withMax(string $relation, string $column, ?string $alias = null, ?callable $constraint = null): self
    {
        return $this->withAggregate($relation, $column, 'max', $alias, $constraint);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function whereHas(string $relation, ?callable $constraint = null): self
    {
        $definition = $this->directRelationDefinition($relation);
        (new RepositoryRelationFilter($this->connection))->apply($this, $definition, $constraint);

        return $this;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    public function whereDoesntHave(string $relation, ?callable $constraint = null): self
    {
        $definition = $this->directRelationDefinition($relation);
        (new RepositoryRelationFilter($this->connection))->apply($this, $definition, $constraint, true);

        return $this;
    }

    public function whereRelation(
        string $relation,
        string $column,
        mixed $operator = null,
        mixed $value = null,
    ): self {
        if (func_num_args() === 3) {
            $value = $operator;
            $operator = '=';
        }

        if (!is_string($operator) || trim($operator) === '') {
            throw new InvalidArgumentException('Relation operator must be a non-empty string.');
        }

        return $this->whereHas(
            $relation,
            static function (QueryBuilder $query) use ($column, $operator, $value): void {
                $query->where($column, $operator, $value);
            },
        );
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
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function loadAggregates(array $rows): array
    {
        if ($rows === [] || $this->requestedAggregates === []) {
            return $rows;
        }

        $aggregator = new RepositoryRelationAggregator($this->connection);

        foreach ($this->requestedAggregates as $alias => $request) {
            $definition = $this->directRelationDefinition($request['relation']);
            $values = $aggregator->aggregate(
                $rows,
                $definition,
                $request['function'],
                $request['column'],
                $request['constraint'],
            );

            foreach ($rows as &$row) {
                $value = $values[$aggregator->parentIdentity($row, $definition)] ?? null;
                $row[$alias] = match (true) {
                    $request['exists'] => (int) ($value ?? 0) > 0,
                    $request['function'] === 'count' => (int) ($value ?? 0),
                    default => $value,
                };
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

        foreach ($this->relationGroups() as $name => $request) {
            $definition = $this->relationDefinition($name);
            $rows = $loader->load(
                $rows,
                $name,
                $definition,
                $request['constraint'],
            );

            if ($request['nested'] !== []) {
                $rows = $this->projectNested($rows, $name, $definition, $request['nested']);
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $values
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $row = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $row[$key] = $item;
                }
            }

            $rows[] = $row;
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
     * @param list<array<string,mixed>> $rows
     * @param array<string,null|callable(QueryBuilder):void> $nested
     * @return list<array<string,mixed>>
     */
    private function projectNested(
        array $rows,
        string $name,
        RelationDefinition $definition,
        array $nested,
    ): array {
        if ($definition->type === RelationDefinition::MORPH_TO) {
            return $this->projectNestedMorphTo($rows, $name, $definition, $nested);
        }

        $related = $definition->related
            ?? throw new InvalidArgumentException('Nested relation requires a related repository.');
        $many = in_array($definition->type, [
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::HAS_MANY,
            RelationDefinition::MORPH_MANY,
            RelationDefinition::MORPH_TO_MANY,
        ], true);
        $flat = [];
        $sizes = [];

        foreach ($rows as $index => $row) {
            $value = $row[$name] ?? ($many ? [] : null);
            $items = $many
                ? (is_array($value) ? array_values(array_filter($value, 'is_array')) : [])
                : (is_array($value) ? [$value] : []);
            $sizes[$index] = count($items);
            array_push($flat, ...$items);
        }

        if ($flat === []) {
            return $rows;
        }

        $projected = $related::query()
            ->with($this->relationRequestArray($nested))
            ->project($this->normalizeRows($flat));
        $offset = 0;

        foreach ($rows as $index => &$row) {
            $size = $sizes[$index] ?? 0;
            $slice = array_slice($projected, $offset, $size);
            $row[$name] = $many ? $slice : ($slice[0] ?? null);
            $offset += $size;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,null|callable(QueryBuilder):void> $nested
     * @return list<array<string,mixed>>
     */
    private function projectNestedMorphTo(
        array $rows,
        string $name,
        RelationDefinition $definition,
        array $nested,
    ): array {
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $groups = [];

        foreach ($rows as $index => $row) {
            $type = $row[$typeColumn] ?? null;
            $relatedRow = $row[$name] ?? null;
            if (!is_string($type) || !is_array($relatedRow) || !isset($definition->morphMap[$type])) {
                continue;
            }
            $groups[$type][] = ['index' => $index, 'row' => $relatedRow];
        }

        foreach ($groups as $type => $entries) {
            $related = $definition->morphMap[$type];
            $projected = $related::query()
                ->with($this->relationRequestArray($nested))
                ->project(array_map(
                    static fn(array $entry): array => $entry['row'],
                    $entries,
                ));

            foreach ($entries as $offset => $entry) {
                $rows[$entry['index']][$name] = $projected[$offset] ?? null;
            }
        }

        return $rows;
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

    /**
     * @param array<int,string|array<string|int,string|callable(QueryBuilder):void>> $relations
     */
    private function registerAggregateList(
        string $function,
        string $column,
        bool $exists,
        array $relations,
    ): void {
        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $this->registerAggregate($relation, $function, $column, null, null, $exists);
                continue;
            }

            foreach ($relation as $expression => $constraint) {
                if (is_int($expression)) {
                    if (!is_string($constraint)) {
                        throw new InvalidArgumentException('Numeric relation aggregate entries must contain relation names.');
                    }
                    $this->registerAggregate($constraint, $function, $column, null, null, $exists);
                    continue;
                }

                if (!is_callable($constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Relation aggregate constraint for [%s] must be callable.',
                        $expression,
                    ));
                }
                $this->registerAggregate($expression, $function, $column, null, $constraint, $exists);
            }
        }
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function registerAggregate(
        string $expression,
        string $function,
        string $column,
        ?string $alias,
        ?callable $constraint,
        bool $exists,
    ): void {
        [$relation, $inlineAlias] = $this->parseAlias($expression);
        $this->directRelationDefinition($relation);
        $alias ??= $inlineAlias ?? $this->aggregateAlias($relation, $function, $column, $exists);
        $this->assertAlias($alias);

        $this->requestedAggregates[$alias] = [
            'relation' => $relation,
            'function' => $function,
            'column' => $column,
            'constraint' => $constraint,
            'exists' => $exists,
        ];
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function registerRelation(string $path, ?callable $constraint): void
    {
        $path = trim($path);
        $segments = $path === '' ? [] : explode('.', $path);

        if ($segments === [] || in_array('', $segments, true)) {
            throw new InvalidArgumentException('Relation path must not be empty.');
        }
        if (count($segments) > $this->definition->maxRelationDepth) {
            throw new InvalidArgumentException(sprintf(
                'Relation path [%s] exceeds the configured maximum depth of %d.',
                $path,
                $this->definition->maxRelationDepth,
            ));
        }

        $this->relationDefinition($segments[0]);
        $this->requestedRelations[$path] = $constraint;
    }

    /**
     * @return array<string,array{constraint:null|callable(QueryBuilder):void,nested:array<string,null|callable(QueryBuilder):void>}>
     */
    private function relationGroups(): array
    {
        $groups = [];

        foreach ($this->requestedRelations as $path => $constraint) {
            [$name, $nested] = array_pad(explode('.', $path, 2), 2, null);
            $groups[$name] ??= ['constraint' => null, 'nested' => []];

            if ($nested === null) {
                $groups[$name]['constraint'] = $constraint;
            } else {
                $groups[$name]['nested'][$nested] = $constraint;
            }
        }

        return $groups;
    }

    /**
     * @param array<string,null|callable(QueryBuilder):void> $relations
     * @return array<string|int,string|callable(QueryBuilder):void>
     */
    private function relationRequestArray(array $relations): array
    {
        $request = [];
        foreach ($relations as $path => $constraint) {
            if ($constraint === null) {
                $request[] = $path;
            } else {
                $request[$path] = $constraint;
            }
        }

        return $request;
    }

    private function directRelationDefinition(string $name): RelationDefinition
    {
        if (str_contains($name, '.')) {
            throw new InvalidArgumentException('Relation aggregates and existence filters currently require a direct relation name.');
        }

        return $this->relationDefinition($name);
    }

    private function relationDefinition(string $name): RelationDefinition
    {
        $definition = $this->definition->relations[$name] ?? null;

        if (!$definition instanceof RelationDefinition) {
            throw new InvalidArgumentException(sprintf(
                'Relation [%s] is not defined for this repository.',
                $name,
            ));
        }

        return $definition;
    }

    /** @return list<string> */
    private function relationParentColumns(RelationDefinition $definition): array
    {
        if ($definition->type !== RelationDefinition::MORPH_TO) {
            return [$definition->parentKey];
        }

        return array_values(array_unique([
            $definition->morphTypeColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires a type column.'),
            $definition->morphIdColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires an id column.'),
        ]));
    }

    /** @return array{0:string,1:?string} */
    private function parseAlias(string $expression): array
    {
        $parts = preg_split('/\s+as\s+/i', trim($expression), 2);
        $relation = trim((string) ($parts[0] ?? ''));
        $alias = isset($parts[1]) ? trim((string) $parts[1]) : null;

        if ($relation === '' || $alias === '') {
            throw new InvalidArgumentException('Relation name and optional alias must be non-empty.');
        }

        return [$relation, $alias];
    }

    private function aggregateAlias(string $relation, string $function, string $column, bool $exists): string
    {
        if ($exists) {
            return $relation . '_exists';
        }
        if ($function === 'count') {
            return $relation . '_count';
        }

        $column = preg_replace('/[^A-Za-z0-9_]+/', '_', $column) ?? $column;

        return sprintf('%s_%s_%s', $relation, $function, trim($column, '_'));
    }

    private function assertAlias(string $alias): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid relation projection alias [%s].', $alias));
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
