<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/** Aggregate through relations without hydrating final relation graphs. */
final class RepositoryThroughRelationAggregator
{
    public function __construct(private readonly int $batchSize = 500)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    public function aggregate(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint = null,
    ): array {
        [$parentByThrough, $relatedValues] = $this->throughMap($parents, $definition);
        if ($relatedValues === []) {
            return [];
        }

        return $function === 'avg'
            ? $this->aggregateAverage($definition, $column, $constraint, $parentByThrough, $relatedValues)
            : $this->aggregateScalar($definition, $function, $column, $constraint, $parentByThrough, $relatedValues);
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return array{0:array<string,string>,1:list<int|float|string|bool>}
     */
    private function throughMap(array $parents, RelationDefinition $definition): array
    {
        $through = $definition->through
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate repository.');
        $parentKey = RepositorySupport::column($definition->throughParentKey
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate parent key.'));
        $throughKey = RepositorySupport::column($definition->throughKey
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate local key.'));
        $parentValues = $this->values($parents, $definition->parentKey);
        if ($parentValues === []) {
            return [[], []];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = [];
        $batchSize = max(1, $through::connection()->safeBatchSize(requested: $this->batchSize));
        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $through::query()->apply(static function (QueryBuilder $builder) use ($parentKey, $chunk): void {
                $builder->whereIn($parentKey, $chunk);
            });
            foreach ($query->get([$parentKey, $throughKey]) as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        $parentByThrough = [];
        foreach ($rows as $row) {
            $throughValue = $row[$throughKey] ?? null;
            $parentValue = $row[$parentKey] ?? null;
            if ($throughValue !== null && $parentValue !== null) {
                $parentByThrough[RepositorySupport::key($throughValue)] = RepositorySupport::key($parentValue);
            }
        }

        return [$parentByThrough, $this->values($rows, $throughKey)];
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @param array<string,string> $parentByThrough
     * @param list<int|float|string|bool> $relatedValues
     * @return array<string,mixed>
     */
    private function aggregateAverage(
        RelationDefinition $definition,
        string $column,
        ?callable $constraint,
        array $parentByThrough,
        array $relatedValues,
    ): array {
        /** @var array<string,array{sum:float,count:int}> $states */
        $states = [];

        foreach ($this->aggregateRows($definition, 'avg', $column, $constraint, $relatedValues) as $row) {
            $parent = $this->parentIdentity($row, $definition, $parentByThrough);
            if ($parent === null) {
                continue;
            }

            $state = $states[$parent] ?? ['sum' => 0.0, 'count' => 0];
            $state['sum'] += $this->numericFloat($row['aggregate_sum'] ?? null);
            $state['count'] += $this->numericInt($row['aggregate_count'] ?? null);
            $states[$parent] = $state;
        }

        $result = [];
        foreach ($states as $parent => $state) {
            $result[$parent] = $state['count'] > 0 ? $state['sum'] / $state['count'] : null;
        }

        return $result;
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @param array<string,string> $parentByThrough
     * @param list<int|float|string|bool> $relatedValues
     * @return array<string,mixed>
     */
    private function aggregateScalar(
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
        array $parentByThrough,
        array $relatedValues,
    ): array {
        $states = [];

        foreach ($this->aggregateRows($definition, $function, $column, $constraint, $relatedValues) as $row) {
            $parent = $this->parentIdentity($row, $definition, $parentByThrough);
            if ($parent === null) {
                continue;
            }

            $value = $row['aggregate'] ?? null;
            $states[$parent] = $this->mergeScalar($function, $states[$parent] ?? null, $value);
        }

        return $states;
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @param list<int|float|string|bool> $relatedValues
     * @return iterable<array<string,mixed>>
     */
    private function aggregateRows(
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
        array $relatedValues,
    ): iterable {
        $related = $definition->related
            ?? throw new InvalidArgumentException('Through aggregate requires a related repository.');
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $batchSize = max(1, $related::connection()->safeBatchSize(requested: $this->batchSize));

        foreach (array_chunk($relatedValues, $batchSize) as $chunk) {
            $query = $related::query()->apply(static function (QueryBuilder $builder) use ($relatedKey, $chunk): void {
                $builder->whereIn($relatedKey, $chunk);
            });
            $this->applyScopes($query, $definition, $constraint);
            $builder = $this->aggregateBuilder($query->raw(), $relatedKey, $function, $column);

            foreach ($builder->groupBy($relatedKey)->get() as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null) {
                    yield $row;
                }
            }
        }
    }

    private function aggregateBuilder(QueryBuilder $builder, string $relatedKey, string $function, string $column): QueryBuilder
    {
        if ($function === 'avg') {
            return $builder->select(
                $relatedKey,
                Expression::make(sprintf('SUM(%s) AS aggregate_sum', $column)),
                Expression::make(sprintf('COUNT(%s) AS aggregate_count', $column)),
            );
        }

        $aggregateColumn = $function === 'count' ? '*' : $column;

        return $builder->select(
            $relatedKey,
            Expression::make(sprintf('%s(%s) AS aggregate', strtoupper($function), $aggregateColumn)),
        );
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyScopes(RepositoryQuery $query, RelationDefinition $definition, ?callable $constraint): void
    {
        if ($definition->scope !== null) {
            /** @var callable(QueryBuilder):void $scope */
            $scope = $definition->scope;
            $query->apply($scope);
        }
        if ($constraint !== null) {
            $query->apply($constraint);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $parentByThrough
     */
    private function parentIdentity(array $row, RelationDefinition $definition, array $parentByThrough): ?string
    {
        $throughIdentity = RepositorySupport::key($row[$definition->relatedKey] ?? null);

        return $parentByThrough[$throughIdentity] ?? null;
    }

    private function mergeScalar(string $function, mixed $current, mixed $value): mixed
    {
        return match ($function) {
            'count' => $this->numericInt($current) + $this->numericInt($value),
            'sum' => $this->numericFloat($current) + $this->numericFloat($value),
            'min' => $current === null || $this->compare($value, $current) < 0 ? $value : $current,
            'max' => $current === null || $this->compare($value, $current) > 0 ? $value : $current,
            default => $current,
        };
    }

    private function compare(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left <=> (string) $right;
        }

        return 0;
    }

    private function numericFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function numericInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<int|float|string|bool>
     */
    private function values(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $values[] = $row[$column];
            }
        }

        return RepositorySupport::uniqueValues($values);
    }
}
