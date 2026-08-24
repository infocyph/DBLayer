<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Resolve through relations in two bounded repository-aware phases:
 * parent -> intermediate repository -> related repository.
 */
final class RepositoryThroughRelation
{
    public function __construct(
        private readonly Connection $parentConnection,
        private readonly int $batchSize = 500,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    public function load(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        if ($parents === []) {
            return $parents;
        }

        [$throughRows, $throughParentKey, $throughKey] = $this->throughRows($parents, $definition);
        if ($throughRows === []) {
            foreach ($parents as &$parent) {
                $parent[$as] = $this->isMany($definition) ? [] : null;
            }
            unset($parent);

            return $parents;
        }

        $relatedRows = $this->relatedRows($throughRows, $throughKey, $definition, $constraint);
        $parentByThrough = [];

        foreach ($throughRows as $throughRow) {
            $throughValue = $throughRow[$throughKey] ?? null;
            $parentValue = $throughRow[$throughParentKey] ?? null;
            if ($throughValue === null || $parentValue === null) {
                continue;
            }
            $parentByThrough[$this->key($throughValue)] = $this->key($parentValue);
        }

        $matches = [];
        foreach ($relatedRows as $relatedRow) {
            $throughIdentity = $this->key($relatedRow[$definition->relatedKey] ?? null);
            $parentIdentity = $parentByThrough[$throughIdentity] ?? null;
            if ($parentIdentity === null) {
                continue;
            }

            if ($this->isMany($definition)) {
                $matches[$parentIdentity][] = $relatedRow;
            } else {
                $matches[$parentIdentity] ??= $relatedRow;
            }
        }

        foreach ($parents as &$parent) {
            $parentIdentity = $this->key($parent[$definition->parentKey] ?? null);
            $parent[$as] = $matches[$parentIdentity]
                ?? ($this->isMany($definition) ? [] : null);
        }
        unset($parent);

        return $parents;
    }

    /**
     * Return parent keys whose through relation has at least one matching final
     * row under the related repository policy.
     *
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    public function matchingParentKeys(
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        $through = $this->requireThrough($definition);
        $throughParentKey = $this->requireThroughParentKey($definition);
        $throughKey = $this->requireThroughKey($definition);
        $related = $this->requireRelated($definition);

        $relatedQuery = $related::query();
        $this->applyRelatedScopes($relatedQuery, $definition, $constraint);
        $throughValues = $this->distinctValues($relatedQuery, $definition->relatedKey);
        if ($throughValues === []) {
            return [];
        }

        $values = [];
        $seen = [];
        $connection = $through::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($throughValues, $batchSize) as $chunk) {
            $query = $through::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($throughKey, $chunk),
            );

            foreach ($query->get([$throughParentKey]) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $value = $row[$throughParentKey] ?? null;
                if ($value === null) {
                    continue;
                }
                $identity = $this->key($value);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return array{0:list<array<string,mixed>>,1:string,2:string}
     */
    private function throughRows(array $parents, RelationDefinition $definition): array
    {
        $through = $this->requireThrough($definition);
        $throughParentKey = $this->requireThroughParentKey($definition);
        $throughKey = $this->requireThroughKey($definition);
        $parentValues = $this->values($parents, $definition->parentKey);
        $connection = $through::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $rows = [];

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $through::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($throughParentKey, $chunk),
            );
            array_push($rows, ...$this->normalizeRows($query->get([$throughParentKey, $throughKey])->toArray()));
        }

        return [$rows, $throughParentKey, $throughKey];
    }

    /**
     * @param list<array<string,mixed>> $throughRows
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function relatedRows(
        array $throughRows,
        string $throughKey,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->requireRelated($definition);
        $values = $this->values($throughRows, $throughKey);
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $columns = $this->ensureKeySelected($definition->columns, $definition->relatedKey);
        $rows = [];
        [$orderColumn, $direction] = $this->oneOfManyOrder($definition, $related);
        $primaryKey = $related::definition()->primaryKey;

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($definition->relatedKey, $chunk),
            );
            $this->applyRelatedScopes($query, $definition, $constraint);

            if ($orderColumn !== null && $direction !== null) {
                $query->apply(static function (QueryBuilder $builder) use ($orderColumn, $direction, $primaryKey): void {
                    $builder->orderBy($orderColumn, $direction);
                    if ($primaryKey !== $orderColumn) {
                        $builder->orderBy($primaryKey, $direction);
                    }
                });
            }

            array_push($rows, ...$this->normalizeRows($query->get($columns)->toArray()));
        }

        if ($orderColumn !== null && count($rows) > 1) {
            usort($rows, static function (array $left, array $right) use ($orderColumn, $direction, $primaryKey): int {
                $comparison = ($left[$orderColumn] ?? null) <=> ($right[$orderColumn] ?? null);
                if ($comparison === 0 && $primaryKey !== $orderColumn) {
                    $comparison = ($left[$primaryKey] ?? null) <=> ($right[$primaryKey] ?? null);
                }

                return $direction === 'desc' ? -$comparison : $comparison;
            });
        }

        return $rows;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyRelatedScopes(
        RepositoryQuery $query,
        RelationDefinition $definition,
        ?callable $constraint,
    ): void {
        if ($definition->scope !== null) {
            $query->apply($definition->scope);
        }
        if ($definition->oneOfManyScope !== null) {
            $query->apply($definition->oneOfManyScope);
        }
        if ($constraint !== null) {
            $query->apply($constraint);
        }
    }

    /**
     * @param class-string<TableRepository> $related
     * @return array{0:?string,1:?string}
     */
    private function oneOfManyOrder(RelationDefinition $definition, string $related): array
    {
        if ($definition->oneOfManyAggregate === null) {
            return [null, null];
        }

        $column = $definition->oneOfManyColumn ?? $related::definition()->primaryKey;

        return [$column, $definition->oneOfManyAggregate === 'max' ? 'desc' : 'asc'];
    }

    /** @return list<mixed> */
    private function distinctValues(RepositoryQuery $query, string $column): array
    {
        $values = [];
        $seen = [];

        foreach ($query->get([$column]) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = $row[$column] ?? null;
            if ($value === null) {
                continue;
            }
            $identity = $this->key($value);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $values[] = $value;
        }

        return $values;
    }

    /** @param list<string> $columns @return list<string> */
    private function ensureKeySelected(array $columns, string $key): array
    {
        if ($columns === ['*'] || in_array('*', $columns, true) || in_array($key, $columns, true)) {
            return $columns;
        }

        return [...$columns, $key];
    }

    /** @param array<mixed> $values @return list<array<string,mixed>> */
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

    /** @param list<array<string,mixed>> $rows @return list<mixed> */
    private function values(array $rows, string $column): array
    {
        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $identity = $this->key($row[$column]);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $values[] = $row[$column];
        }

        return $values;
    }

    /** @return class-string<TableRepository> */
    private function requireThrough(RelationDefinition $definition): string
    {
        return $definition->through
            ?? throw new InvalidArgumentException('Through relation requires an intermediate repository.');
    }

    private function requireThroughParentKey(RelationDefinition $definition): string
    {
        return $definition->throughParentKey
            ?? throw new InvalidArgumentException('Through relation requires an intermediate parent key.');
    }

    private function requireThroughKey(RelationDefinition $definition): string
    {
        return $definition->throughKey
            ?? throw new InvalidArgumentException('Through relation requires an intermediate local key.');
    }

    /** @return class-string<TableRepository> */
    private function requireRelated(RelationDefinition $definition): string
    {
        return $definition->related
            ?? throw new InvalidArgumentException('Through relation requires a related repository.');
    }

    private function isMany(RelationDefinition $definition): bool
    {
        return $definition->type === RelationDefinition::HAS_MANY;
    }

    private function key(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            $value === null => 'null:',
            default => throw new InvalidArgumentException('Relation keys must be scalar or null.'),
        };
    }
}
