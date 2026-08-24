<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Aggregate through relations without hydrating final relation graphs.
 */
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
        $through = $definition->through
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate repository.');
        $throughParentKey = $definition->throughParentKey
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate parent key.');
        $throughKey = $definition->throughKey
            ?? throw new InvalidArgumentException('Through aggregate requires an intermediate local key.');
        $related = $definition->related
            ?? throw new InvalidArgumentException('Through aggregate requires a related repository.');

        $parentValues = $this->values($parents, $definition->parentKey);
        if ($parentValues === []) {
            return [];
        }

        $throughRows = [];
        $throughConnection = $through::connection();
        $throughBatchSize = $throughConnection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($parentValues, $throughBatchSize) as $chunk) {
            $query = $through::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($throughParentKey, $chunk),
            );
            foreach ($query->get([$throughParentKey, $throughKey]) as $row) {
                if (is_array($row)) {
                    $throughRows[] = $row;
                }
            }
        }

        if ($throughRows === []) {
            return [];
        }

        $parentByThrough = [];
        foreach ($throughRows as $row) {
            $throughValue = $row[$throughKey] ?? null;
            $parentValue = $row[$throughParentKey] ?? null;
            if ($throughValue === null || $parentValue === null) {
                continue;
            }
            $parentByThrough[$this->key($throughValue)] = $this->key($parentValue);
        }

        $relatedValues = $this->values($throughRows, $throughKey);
        $relatedConnection = $related::connection();
        $relatedBatchSize = $relatedConnection->safeBatchSize(requested: $this->batchSize);
        $states = [];

        foreach (array_chunk($relatedValues, $relatedBatchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($definition->relatedKey, $chunk),
            );

            if ($definition->scope !== null) {
                $query->apply($definition->scope);
            }
            if ($constraint !== null) {
                $query->apply($constraint);
            }

            $builder = $query->raw();
            if ($function === 'avg') {
                $builder->select(
                    $definition->relatedKey,
                    Expression::make(sprintf('SUM(%s) AS aggregate_sum', $column)),
                    Expression::make(sprintf('COUNT(%s) AS aggregate_count', $column)),
                );
            } else {
                $aggregateColumn = $function === 'count' ? '*' : $column;
                $builder->select(
                    $definition->relatedKey,
                    Expression::make(sprintf('%s(%s) AS aggregate', strtoupper($function), $aggregateColumn)),
                );
            }

            foreach ($builder->groupBy($definition->relatedKey)->get() as $row) {
                $throughIdentity = $this->key($row[$definition->relatedKey] ?? null);
                $parentIdentity = $parentByThrough[$throughIdentity] ?? null;
                if ($parentIdentity === null) {
                    continue;
                }

                if ($function === 'avg') {
                    $states[$parentIdentity]['sum'] = (float) (($states[$parentIdentity]['sum'] ?? 0.0)
                        + (float) ($row['aggregate_sum'] ?? 0.0));
                    $states[$parentIdentity]['count'] = (int) (($states[$parentIdentity]['count'] ?? 0)
                        + (int) ($row['aggregate_count'] ?? 0));
                    continue;
                }

                $value = $row['aggregate'] ?? null;
                $states[$parentIdentity] = match ($function) {
                    'count' => (int) ($states[$parentIdentity] ?? 0) + (int) ($value ?? 0),
                    'sum' => (float) ($states[$parentIdentity] ?? 0.0) + (float) ($value ?? 0.0),
                    'min' => !array_key_exists($parentIdentity, $states) || $value < $states[$parentIdentity]
                        ? $value
                        : $states[$parentIdentity],
                    'max' => !array_key_exists($parentIdentity, $states) || $value > $states[$parentIdentity]
                        ? $value
                        : $states[$parentIdentity],
                    default => $states[$parentIdentity] ?? null,
                };
            }
        }

        if ($function !== 'avg') {
            return $states;
        }

        $result = [];
        foreach ($states as $parentIdentity => $state) {
            $count = (int) ($state['count'] ?? 0);
            $result[$parentIdentity] = $count > 0
                ? (float) $state['sum'] / $count
                : null;
        }

        return $result;
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
