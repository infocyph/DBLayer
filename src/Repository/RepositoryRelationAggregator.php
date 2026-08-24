<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/** Compute relation aggregates in bounded batches without hydrating full graphs. */
final class RepositoryRelationAggregator
{
    private const array FUNCTIONS = ['avg', 'count', 'max', 'min', 'sum'];

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
     * @return array<string,mixed>
     */
    public function aggregate(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column = '*',
        ?callable $constraint = null,
    ): array {
        if ($parents === []) {
            return [];
        }

        $function = strtolower(trim($function));
        if (!in_array($function, self::FUNCTIONS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported relation aggregate [%s].', $function));
        }
        if ($function !== 'count') {
            $this->assertColumn($column);
        }

        if ($definition->oneOfManyAggregate !== null) {
            return (new RepositoryOneOfManyAggregator($this->parentConnection, $this->batchSize))
                ->aggregate($parents, $definition, $function, $column, $constraint);
        }
        if ($definition->through !== null) {
            return (new RepositoryThroughRelationAggregator($this->batchSize))
                ->aggregate($parents, $definition, $function, $column, $constraint);
        }

        return match ($definition->type) {
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO_MANY => $this->aggregatePivot($parents, $definition, $function, $column, $constraint),
            RelationDefinition::MORPH_TO => $this->aggregateMorphTo($parents, $definition, $function, $column, $constraint),
            default => $this->aggregateDirect($parents, $definition, $function, $column, $constraint),
        };
    }

    /** @param array<string,mixed> $parent */
    public function parentIdentity(array $parent, RelationDefinition $definition): string
    {
        if ($definition->type !== RelationDefinition::MORPH_TO) {
            return RepositorySupport::key($parent[$definition->parentKey] ?? null);
        }

        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $idColumn = $definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');

        return 'morph:' . RepositorySupport::key($parent[$typeColumn] ?? null)
            . ':' . RepositorySupport::key($parent[$idColumn] ?? null);
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    private function aggregateDirect(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
    ): array {
        $related = $definition->related
            ?? throw new InvalidArgumentException('Direct relation requires a related repository.');
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $values = $this->values($parents, $definition->parentKey);
        if ($values === []) {
            return [];
        }

        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);
        $aggregates = [];
        $aggregateExpression = $this->aggregateExpression($function, $column);

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(static function (QueryBuilder $query) use ($definition, $relatedKey, $chunk): void {
                $query->whereIn($relatedKey, $chunk);
                if (
                    in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
                    && $definition->morphTypeColumn !== null
                    && $definition->morphAlias !== null
                ) {
                    $query->where(RepositorySupport::column($definition->morphTypeColumn), '=', $definition->morphAlias);
                }
            });
            $this->applyConstraints($query, $definition, $constraint);

            foreach ($query->raw()->select($relatedKey, $aggregateExpression)->groupBy($relatedKey)->get() as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null) {
                    $aggregates[RepositorySupport::key($row[$relatedKey] ?? null)] = $row['aggregate'] ?? null;
                }
            }
        }

        return $aggregates;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    private function aggregateMorphTo(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
    ): array {
        $groups = $this->morphGroups($parents, $definition);
        $result = [];

        foreach ($groups as $type => $values) {
            $this->aggregateMorphGroup($result, $type, array_values($values), $definition, $function, $column, $constraint);
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return array<string,array<string,mixed>>
     */
    private function morphGroups(array $parents, RelationDefinition $definition): array
    {
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $idColumn = $definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');
        $groups = [];

        foreach ($parents as $parent) {
            $type = $parent[$typeColumn] ?? null;
            $id = $parent[$idColumn] ?? null;
            if ($type === null || $id === null) {
                continue;
            }
            if (!is_string($type) || !isset($definition->morphMap[$type])) {
                throw new InvalidArgumentException('Morph-to aggregate encountered an unmapped discriminator.');
            }
            $groups[$type][RepositorySupport::key($id)] = $id;
        }

        return $groups;
    }

    /**
     * @param array<string,mixed> $result
     * @param list<mixed> $values
     * @param null|callable(QueryBuilder):void $constraint
     */
    private function aggregateMorphGroup(
        array &$result,
        string $type,
        array $values,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
    ): void {
        $related = $definition->morphMap[$type];
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(static function (QueryBuilder $builder) use ($relatedKey, $chunk): void {
                $builder->whereIn($relatedKey, $chunk);
            });
            $this->applyConstraints($query, $definition, $constraint);
            $columns = $function === 'count' ? [$relatedKey] : [$relatedKey, RepositorySupport::column($column)];

            foreach ($query->get($columns) as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row === null) {
                    continue;
                }
                $identity = 'morph:' . RepositorySupport::key($type) . ':' . RepositorySupport::key($row[$relatedKey] ?? null);
                $result[$identity] = $function === 'count' ? 1 : $this->singleValueAggregate($function, $row[$column] ?? null);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    private function aggregatePivot(
        array $parents,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
    ): array {
        $pivotRows = $this->pivotRows($parents, $definition);
        if ($pivotRows === []) {
            return [];
        }

        $relatedValues = $this->pivotRelatedValues($pivotRows, $definition, $function, $column, $constraint);

        return $this->reducePivot($pivotRows, $relatedValues, $definition, $function);
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return list<array<string,mixed>>
     */
    private function pivotRows(array $parents, RelationDefinition $definition): array
    {
        $pivotTable = $definition->pivotTable
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.');
        $parentKey = RepositorySupport::column($definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.'));
        $relatedKey = RepositorySupport::column($definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.'));
        $parentValues = $this->values($parents, $definition->parentKey);
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);
        $rows = [];

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $this->parentConnection->table($pivotTable)->select([$parentKey, $relatedKey])->whereIn($parentKey, $chunk);
            $this->applyPivotMorphConstraint($query, $definition);

            foreach ($query->get() as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function applyPivotMorphConstraint(QueryBuilder $query, RelationDefinition $definition): void
    {
        if ($definition->type !== RelationDefinition::MORPH_TO_MANY) {
            return;
        }

        $query->where(
            RepositorySupport::column($definition->morphTypeColumn
                ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a type column.')),
            '=',
            $definition->morphAlias
                ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a morph alias.'),
        );
    }

    /**
     * @param list<array<string,mixed>> $pivotRows
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,mixed>
     */
    private function pivotRelatedValues(
        array $pivotRows,
        RelationDefinition $definition,
        string $function,
        string $column,
        ?callable $constraint,
    ): array {
        $pivotRelatedKey = $definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.');
        $related = $definition->related
            ?? throw new InvalidArgumentException('Many-to-many relation requires a related repository.');
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $relatedIds = $this->values($pivotRows, $pivotRelatedKey);
        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);
        $values = [];

        foreach (array_chunk($relatedIds, $batchSize) as $chunk) {
            $query = $related::query()->apply(static function (QueryBuilder $builder) use ($relatedKey, $chunk): void {
                $builder->whereIn($relatedKey, $chunk);
            });
            $this->applyConstraints($query, $definition, $constraint);
            $columns = $function === 'count' ? [$relatedKey] : [$relatedKey, RepositorySupport::column($column)];

            foreach ($query->get($columns) as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null) {
                    $values[RepositorySupport::key($row[$relatedKey] ?? null)] = $function === 'count' ? 1 : ($row[$column] ?? null);
                }
            }
        }

        return $values;
    }

    /**
     * @param list<array<string,mixed>> $pivotRows
     * @param array<string,mixed> $relatedValues
     * @return array<string,mixed>
     */
    private function reducePivot(
        array $pivotRows,
        array $relatedValues,
        RelationDefinition $definition,
        string $function,
    ): array {
        $pivotParentKey = $definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.');
        $pivotRelatedKey = $definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.');
        $states = [];

        foreach ($pivotRows as $pivot) {
            $relatedIdentity = RepositorySupport::key($pivot[$pivotRelatedKey] ?? null);
            if (!array_key_exists($relatedIdentity, $relatedValues)) {
                continue;
            }

            $parentIdentity = RepositorySupport::key($pivot[$pivotParentKey] ?? null);
            $states[$parentIdentity] = $this->accumulate($states[$parentIdentity] ?? null, $function, $relatedValues[$relatedIdentity]);
        }

        foreach ($states as $parentIdentity => $state) {
            $states[$parentIdentity] = $this->finalize($state, $function);
        }

        return $states;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyConstraints(RepositoryQuery $query, RelationDefinition $definition, ?callable $constraint): void
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

    private function aggregateExpression(string $function, string $column): Expression
    {
        $function = strtoupper($function);
        $column = $function === 'COUNT' ? '*' : $column;

        return Expression::make(sprintf('%s(%s) AS aggregate', $function, $column));
    }

    private function assertColumn(string $column): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', trim($column)) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid aggregate column [%s].', $column));
        }
    }

    private function accumulate(mixed $state, string $function, mixed $value): mixed
    {
        return match ($function) {
            'count' => $this->numericInt($state) + 1,
            'sum' => $this->numericFloat($state) + $this->numericFloat($value),
            'avg' => $this->accumulateAverage($state, $value),
            'min' => $state === null || $this->compare($value, $state) < 0 ? $value : $state,
            'max' => $state === null || $this->compare($value, $state) > 0 ? $value : $state,
            default => $state,
        };
    }

    /** @return array{sum:float,count:int} */
    private function accumulateAverage(mixed $state, mixed $value): array
    {
        $current = is_array($state) ? $state : [];
        $sum = $this->numericFloat($current['sum'] ?? null);
        $count = $this->numericInt($current['count'] ?? null);

        return ['sum' => $sum + $this->numericFloat($value), 'count' => $count + 1];
    }

    private function finalize(mixed $state, string $function): mixed
    {
        if ($function !== 'avg' || !is_array($state)) {
            return $function === 'avg' ? null : $state;
        }

        $count = $this->numericInt($state['count'] ?? null);

        return $count > 0 ? $this->numericFloat($state['sum'] ?? null) / $count : null;
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

    private function singleValueAggregate(string $function, mixed $value): mixed
    {
        return match ($function) {
            'sum', 'avg' => $this->numericFloat($value),
            'min', 'max' => $value,
            default => $value,
        };
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<mixed>
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
