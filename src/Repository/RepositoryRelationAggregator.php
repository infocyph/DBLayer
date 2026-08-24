<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Compute relation aggregates in bounded batches without hydrating full
 * relation graphs. Results are keyed by normalized parent relation identity.
 */
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

        return match ($definition->type) {
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO_MANY => $this->aggregatePivot(
                $parents,
                $definition,
                $function,
                $column,
                $constraint,
            ),
            RelationDefinition::MORPH_TO => $this->aggregateMorphTo(
                $parents,
                $definition,
                $function,
                $column,
                $constraint,
            ),
            default => $this->aggregateDirect(
                $parents,
                $definition,
                $function,
                $column,
                $constraint,
            ),
        };
    }

    /** Build the identity key used for one parent row. */
    public function parentIdentity(array $parent, RelationDefinition $definition): string
    {
        if ($definition->type === RelationDefinition::MORPH_TO) {
            $typeColumn = $definition->morphTypeColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
            $idColumn = $definition->morphIdColumn
                ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');

            return 'morph:' . $this->key($parent[$typeColumn] ?? null) . ':' . $this->key($parent[$idColumn] ?? null);
        }

        return $this->key($parent[$definition->parentKey] ?? null);
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
        $values = $this->values($parents, $definition->parentKey);
        if ($values === []) {
            return [];
        }

        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $aggregates = [];
        $sqlFunction = strtoupper($function);
        $aggregateColumn = $function === 'count' ? '*' : $column;

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static function (QueryBuilder $query) use ($definition, $chunk): void {
                    $query->whereIn($definition->relatedKey, $chunk);
                    if (
                        in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
                        && $definition->morphTypeColumn !== null
                        && $definition->morphAlias !== null
                    ) {
                        $query->where($definition->morphTypeColumn, '=', $definition->morphAlias);
                    }
                },
            );
            $this->applyConstraints($query, $definition, $constraint);

            $rows = $query->raw()
                ->select($definition->relatedKey)
                ->selectRaw(sprintf('%s(%s) AS aggregate', $sqlFunction, $aggregateColumn))
                ->groupBy($definition->relatedKey)
                ->get();

            foreach ($rows as $row) {
                $aggregates[$this->key($row[$definition->relatedKey] ?? null)] = $row['aggregate'] ?? null;
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
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $idColumn = $definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');
        $grouped = [];

        foreach ($parents as $parent) {
            $type = $parent[$typeColumn] ?? null;
            $id = $parent[$idColumn] ?? null;
            if ($type === null || $id === null) {
                continue;
            }
            if (!is_string($type) || !isset($definition->morphMap[$type])) {
                throw new InvalidArgumentException('Morph-to aggregate encountered an unmapped discriminator.');
            }
            $grouped[$type][$this->key($id)] = $id;
        }

        $result = [];
        foreach ($grouped as $type => $values) {
            $related = $definition->morphMap[$type];
            $connection = $related::connection();
            $batchSize = $connection->safeBatchSize(requested: $this->batchSize);

            foreach (array_chunk(array_values($values), $batchSize) as $chunk) {
                $query = $related::query()->apply(
                    static fn(QueryBuilder $query): mixed => $query->whereIn($definition->relatedKey, $chunk),
                );
                if ($definition->scope !== null) {
                    $query->apply($definition->scope);
                }
                if ($constraint !== null) {
                    $query->apply($constraint);
                }

                $columns = [$definition->relatedKey];
                if ($function !== 'count') {
                    $columns[] = $column;
                }

                foreach ($query->get($columns) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $identity = 'morph:' . $this->key($type) . ':' . $this->key($row[$definition->relatedKey] ?? null);
                    $result[$identity] = $function === 'count'
                        ? 1
                        : $this->singleValueAggregate($function, $row[$column] ?? null);
                }
            }
        }

        return $result;
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
        $pivotTable = $definition->pivotTable
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.');
        $pivotParentKey = $definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.');
        $pivotRelatedKey = $definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.');
        $related = $definition->related
            ?? throw new InvalidArgumentException('Many-to-many relation requires a related repository.');

        $pivotRows = [];
        $parentValues = $this->values($parents, $definition->parentKey);
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $this->parentConnection
                ->table($pivotTable)
                ->select([$pivotParentKey, $pivotRelatedKey])
                ->whereIn($pivotParentKey, $chunk);

            if ($definition->type === RelationDefinition::MORPH_TO_MANY) {
                $query->where(
                    $definition->morphTypeColumn
                        ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a type column.'),
                    '=',
                    $definition->morphAlias
                        ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a morph alias.'),
                );
            }

            array_push($pivotRows, ...$query->get());
        }

        if ($pivotRows === []) {
            return [];
        }

        $relatedValues = [];
        $relatedIds = $this->values($pivotRows, $pivotRelatedKey);
        $relatedConnection = $related::connection();
        $relatedBatchSize = $relatedConnection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($relatedIds, $relatedBatchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $query): mixed => $query->whereIn($definition->relatedKey, $chunk),
            );
            $this->applyConstraints($query, $definition, $constraint);
            $columns = [$definition->relatedKey];
            if ($function !== 'count') {
                $columns[] = $column;
            }

            foreach ($query->get($columns) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $relatedValues[$this->key($row[$definition->relatedKey] ?? null)] = $function === 'count'
                    ? 1
                    : ($row[$column] ?? null);
            }
        }

        $states = [];
        foreach ($pivotRows as $pivot) {
            $relatedKey = $this->key($pivot[$pivotRelatedKey] ?? null);
            if (!array_key_exists($relatedKey, $relatedValues)) {
                continue;
            }

            $parentKey = $this->key($pivot[$pivotParentKey] ?? null);
            $states[$parentKey] = $this->accumulate(
                $states[$parentKey] ?? null,
                $function,
                $relatedValues[$relatedKey],
            );
        }

        foreach ($states as $parentKey => $state) {
            $states[$parentKey] = $this->finalize($state, $function);
        }

        return $states;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyConstraints(
        RepositoryQuery $query,
        RelationDefinition $definition,
        ?callable $constraint,
    ): void {
        if ($definition->scope !== null) {
            $query->apply($definition->scope);
        }
        if ($constraint !== null) {
            $query->apply($constraint);
        }
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
            'count' => (int) ($state ?? 0) + 1,
            'sum' => ($state ?? 0) + (is_numeric($value) ? $value + 0 : 0),
            'avg' => [
                'sum' => (float) (($state['sum'] ?? 0.0) + (is_numeric($value) ? (float) $value : 0.0)),
                'count' => (int) (($state['count'] ?? 0) + 1),
            ],
            'min' => $state === null || $value < $state ? $value : $state,
            'max' => $state === null || $value > $state ? $value : $state,
            default => $state,
        };
    }

    private function finalize(mixed $state, string $function): mixed
    {
        if ($function !== 'avg') {
            return $state;
        }

        if (!is_array($state) || ($state['count'] ?? 0) < 1) {
            return null;
        }

        return (float) $state['sum'] / (int) $state['count'];
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

    private function singleValueAggregate(string $function, mixed $value): mixed
    {
        return match ($function) {
            'sum', 'avg' => is_numeric($value) ? $value + 0 : 0,
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
        $seen = [];

        foreach ($rows as $row) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $key = $this->key($row[$column]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $row[$column];
        }

        return $values;
    }
}
