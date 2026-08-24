<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Repository-aware bounded relation projection.
 *
 * Parent and pivot reads stay explicit while related rows are fetched through
 * the related TableRepository so its scopes, casts, cache policy, and other
 * repository read semantics remain intact.
 */
final class RepositoryRelationLoader
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

        if (in_array($definition->type, [
            RelationDefinition::HAS_ONE_THROUGH,
            RelationDefinition::HAS_MANY_THROUGH,
        ], true)) {
            return (new RepositoryThroughRelation($this->parentConnection, $this->batchSize))
                ->load($parents, $as, $definition, $constraint);
        }

        if ($definition->oneOfManyAggregate !== null) {
            return (new RepositoryOneOfManyRelation($this->batchSize))
                ->load($parents, $as, $definition, $constraint);
        }

        return match ($definition->type) {
            RelationDefinition::BELONGS_TO,
            RelationDefinition::HAS_ONE,
            RelationDefinition::MORPH_ONE => $this->one($parents, $as, $definition, $constraint),
            RelationDefinition::HAS_MANY,
            RelationDefinition::MORPH_MANY => $this->many($parents, $as, $definition, $constraint),
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO_MANY => $this->manyToMany($parents, $as, $definition, $constraint),
            RelationDefinition::MORPH_TO => $this->morphTo($parents, $as, $definition, $constraint),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported relation type [%s].',
                $definition->type,
            )),
        };
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function many(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->fetchRelated($parents, $definition, $constraint);
        $grouped = [];

        foreach ($related as $row) {
            $grouped[$this->key($row[$definition->relatedKey] ?? null)][] = $row;
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $grouped[$this->key($parent[$definition->parentKey] ?? null)] ?? [];
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function manyToMany(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $pivotTable = $definition->pivotTable
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.');
        $pivotParentKey = $definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.');
        $pivotRelatedKey = $definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.');

        $parentValues = $this->values($parents, $definition->parentKey);
        $pivotRows = [];
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);
        $pivotColumns = $this->pivotProjection($definition, $pivotParentKey, $pivotRelatedKey);

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $this->parentConnection
                ->table($pivotTable)
                ->select($pivotColumns)
                ->whereIn($pivotParentKey, $chunk);

            if ($definition->type === RelationDefinition::MORPH_TO_MANY) {
                $typeColumn = $definition->morphTypeColumn
                    ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a type column.');
                $morphAlias = $definition->morphAlias
                    ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a morph alias.');
                $query->where($typeColumn, '=', $morphAlias);
            }

            array_push($pivotRows, ...$query->get());
        }

        $relatedRows = $this->fetchByValues(
            $this->values($pivotRows, $pivotRelatedKey),
            $definition,
            $constraint,
        );
        $relatedByKey = [];

        foreach ($relatedRows as $row) {
            $relatedByKey[$this->key($row[$definition->relatedKey] ?? null)] = $row;
        }

        $pivotsByParent = [];
        foreach ($pivotRows as $pivot) {
            $pivotsByParent[$this->key($pivot[$pivotParentKey] ?? null)][] = $pivot;
        }

        foreach ($parents as &$parent) {
            $matches = [];
            $parentKey = $this->key($parent[$definition->parentKey] ?? null);

            foreach ($pivotsByParent[$parentKey] ?? [] as $pivot) {
                $relatedKey = $this->key($pivot[$pivotRelatedKey] ?? null);
                if (!isset($relatedByKey[$relatedKey])) {
                    continue;
                }

                $row = $relatedByKey[$relatedKey];
                if ($definition->pivotColumns !== []) {
                    $row[$definition->pivotAccessor] = $this->pivotAttributes($pivot, $definition->pivotColumns);
                }
                $matches[] = $row;
            }

            $parent[$as] = $matches;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function morphTo(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $idColumn = $definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');
        $groupedValues = [];

        foreach ($parents as $parent) {
            $type = $parent[$typeColumn] ?? null;
            $id = $parent[$idColumn] ?? null;
            if ($type === null || $id === null) {
                continue;
            }
            if (!is_string($type) || !array_key_exists($type, $definition->morphMap)) {
                throw new InvalidArgumentException(sprintf(
                    'Unmapped morph discriminator [%s] for relation [%s].',
                    is_scalar($type) ? (string) $type : get_debug_type($type),
                    $as,
                ));
            }

            $groupedValues[$type][$this->key($id)] = $id;
        }

        $indexed = [];
        foreach ($groupedValues as $type => $valuesByKey) {
            $related = $definition->morphMap[$type];
            $rows = $this->fetchForClass(
                array_values($valuesByKey),
                $related,
                $definition->relatedKey,
                $definition->columns,
                $definition->scope,
                $constraint,
            );

            foreach ($rows as $row) {
                $indexed[$type][$this->key($row[$definition->relatedKey] ?? null)] = $row;
            }
        }

        foreach ($parents as &$parent) {
            $type = $parent[$typeColumn] ?? null;
            $id = $parent[$idColumn] ?? null;
            $parent[$as] = is_string($type) && $id !== null
                ? ($indexed[$type][$this->key($id)] ?? null)
                : null;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function one(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->fetchRelated($parents, $definition, $constraint);
        $indexed = [];

        foreach ($related as $row) {
            $key = $this->key($row[$definition->relatedKey] ?? null);
            $indexed[$key] ??= $row;
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $indexed[$this->key($parent[$definition->parentKey] ?? null)] ?? null;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<mixed> $values
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function fetchByValues(
        array $values,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $definition->related
            ?? throw new InvalidArgumentException('Relation does not have a single related repository class.');

        return $this->fetchForClass(
            $values,
            $related,
            $definition->relatedKey,
            $definition->columns,
            $definition->scope,
            $constraint,
            in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
                ? $definition->morphTypeColumn
                : null,
            in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
                ? $definition->morphAlias
                : null,
        );
    }

    /**
     * @param list<mixed> $values
     * @param class-string<TableRepository> $related
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function fetchForClass(
        array $values,
        string $related,
        string $relatedKey,
        array $columns,
        mixed $scope,
        ?callable $constraint,
        ?string $morphTypeColumn = null,
        ?string $morphAlias = null,
    ): array {
        if ($values === []) {
            return [];
        }

        $columns = $this->ensureKeySelected($columns, $relatedKey);
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $rows = [];

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static function (QueryBuilder $query) use ($relatedKey, $chunk, $morphTypeColumn, $morphAlias): void {
                    $query->whereIn($relatedKey, $chunk);
                    if ($morphTypeColumn !== null && $morphAlias !== null) {
                        $query->where($morphTypeColumn, '=', $morphAlias);
                    }
                },
            );

            if ($scope !== null) {
                $query->apply($scope);
            }
            if ($constraint !== null) {
                $query->apply($constraint);
            }

            array_push($rows, ...$this->normalizeRows($query->get($columns)->toArray()));
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<array<string,mixed>>
     */
    private function fetchRelated(
        array $parents,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        return $this->fetchByValues(
            $this->values($parents, $definition->parentKey),
            $definition,
            $constraint,
        );
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function ensureKeySelected(array $columns, string $key): array
    {
        if ($columns === ['*'] || in_array('*', $columns, true) || in_array($key, $columns, true)) {
            return $columns;
        }

        return [...$columns, $key];
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
     * @param array<string,mixed> $pivot
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function pivotAttributes(array $pivot, array $columns): array
    {
        $attributes = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $pivot)) {
                $attributes[$column] = $pivot[$column];
            }
        }

        return $attributes;
    }

    /** @return list<string> */
    private function pivotProjection(
        RelationDefinition $definition,
        string $pivotParentKey,
        string $pivotRelatedKey,
    ): array {
        $columns = [$pivotParentKey => true, $pivotRelatedKey => true];

        if ($definition->type === RelationDefinition::MORPH_TO_MANY && $definition->morphTypeColumn !== null) {
            $columns[$definition->morphTypeColumn] = true;
        }

        foreach ($definition->pivotColumns as $column) {
            $columns[$column] = true;
        }

        return array_keys($columns);
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
