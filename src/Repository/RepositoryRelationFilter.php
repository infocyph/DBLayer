<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Apply relation-existence constraints without loading relation graphs.
 *
 * Matching keys are selected through related repositories so related scopes,
 * tenancy, soft-delete policy and other repository constraints remain active.
 */
final class RepositoryRelationFilter
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
     * @param null|callable(QueryBuilder):void $constraint
     */
    public function apply(
        RepositoryQuery $parentQuery,
        RelationDefinition $definition,
        ?callable $constraint = null,
        bool $not = false,
    ): void {
        if ($definition->type === RelationDefinition::MORPH_TO) {
            $this->applyMorphTo($parentQuery, $definition, $constraint, $not);

            return;
        }

        $matching = match ($definition->type) {
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO_MANY => $this->matchingPivotParentKeys($definition, $constraint),
            default => $this->matchingDirectParentKeys($definition, $constraint),
        };

        $this->applyValues($parentQuery, $definition->parentKey, $matching, $not);
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     */
    private function applyMorphTo(
        RepositoryQuery $parentQuery,
        RelationDefinition $definition,
        ?callable $constraint,
        bool $not,
    ): void {
        $typeColumn = $definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.');
        $idColumn = $definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.');
        $groups = [];

        foreach ($definition->morphMap as $alias => $related) {
            $query = $related::query();
            if ($definition->scope !== null) {
                $query->apply($definition->scope);
            }
            if ($constraint !== null) {
                $query->apply($constraint);
            }

            $groups[$alias] = $this->distinctColumnValues($query, $definition->relatedKey);
        }

        $parentQuery->apply(function (QueryBuilder $query) use ($groups, $typeColumn, $idColumn, $not): void {
            if ($not) {
                foreach ($groups as $alias => $ids) {
                    $this->applyNegatedMorphGroup($query, $typeColumn, $idColumn, $alias, $ids);
                }

                return;
            }

            $query->where(function (QueryBuilder $nested) use ($groups, $typeColumn, $idColumn): void {
                $first = true;
                foreach ($groups as $alias => $ids) {
                    if ($ids === []) {
                        continue;
                    }

                    $nested->where(
                        static function (QueryBuilder $group) use ($typeColumn, $idColumn, $alias, $ids): void {
                            $group->where($typeColumn, '=', $alias);
                            self::applyChunkedIn($group, $idColumn, $ids, false);
                        },
                        boolean: $first ? 'and' : 'or',
                    );
                    $first = false;
                }

                if ($first) {
                    $nested->whereIn($idColumn, []);
                }
            });
        });
    }

    /** @param list<mixed> $ids */
    private function applyNegatedMorphGroup(
        QueryBuilder $query,
        string $typeColumn,
        string $idColumn,
        string $alias,
        array $ids,
    ): void {
        if ($ids === []) {
            return;
        }

        $query->where(static function (QueryBuilder $nested) use ($typeColumn, $idColumn, $alias, $ids): void {
            $nested->where($typeColumn, '!=', $alias);
            self::applyChunkedIn($nested, $idColumn, $ids, true, 'or');
        });
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    private function matchingDirectParentKeys(
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $definition->related
            ?? throw new InvalidArgumentException('Direct relation requires a related repository.');
        $query = $related::query();

        if (
            in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
            && $definition->morphTypeColumn !== null
            && $definition->morphAlias !== null
        ) {
            $query->apply(
                static fn(QueryBuilder $builder): mixed => $builder->where(
                    $definition->morphTypeColumn,
                    '=',
                    $definition->morphAlias,
                ),
            );
        }

        if ($definition->scope !== null) {
            $query->apply($definition->scope);
        }
        if ($constraint !== null) {
            $query->apply($constraint);
        }

        return $this->distinctColumnValues($query, $definition->relatedKey);
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    private function matchingPivotParentKeys(
        RelationDefinition $definition,
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

        $relatedQuery = $related::query();
        if ($definition->scope !== null) {
            $relatedQuery->apply($definition->scope);
        }
        if ($constraint !== null) {
            $relatedQuery->apply($constraint);
        }

        $relatedIds = $this->distinctColumnValues($relatedQuery, $definition->relatedKey);
        if ($relatedIds === []) {
            return [];
        }

        $values = [];
        $seen = [];
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($relatedIds, $batchSize) as $chunk) {
            $query = $this->parentConnection
                ->table($pivotTable)
                ->select($pivotParentKey)
                ->whereIn($pivotRelatedKey, $chunk);

            if ($definition->type === RelationDefinition::MORPH_TO_MANY) {
                $query->where(
                    $definition->morphTypeColumn
                        ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a type column.'),
                    '=',
                    $definition->morphAlias
                        ?? throw new InvalidArgumentException('Polymorphic pivot relation requires a morph alias.'),
                );
            }

            foreach ($query->get() as $row) {
                $value = $row[$pivotParentKey] ?? null;
                if ($value === null) {
                    continue;
                }
                $key = $this->key($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<mixed>
     */
    private function distinctColumnValues(RepositoryQuery $query, string $column): array
    {
        $values = [];
        $seen = [];
        $builder = $query->raw()->select($column)->distinct();

        foreach ($builder->cursor() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = $row[$column] ?? null;
            if ($value === null) {
                continue;
            }

            $key = $this->key($value);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $value;
        }

        return $values;
    }

    /** @param list<mixed> $values */
    private function applyValues(
        RepositoryQuery $query,
        string $column,
        array $values,
        bool $not,
    ): void {
        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);
        $chunks = array_chunk($values, $batchSize);

        $query->apply(static function (QueryBuilder $builder) use ($column, $chunks, $not): void {
            if ($not) {
                if ($chunks === []) {
                    return;
                }
                foreach ($chunks as $chunk) {
                    $builder->whereNotIn($column, $chunk);
                }

                return;
            }

            if ($chunks === []) {
                $builder->whereIn($column, []);

                return;
            }

            $builder->where(static function (QueryBuilder $nested) use ($column, $chunks): void {
                foreach ($chunks as $index => $chunk) {
                    $nested->whereIn($column, $chunk, $index === 0 ? 'and' : 'or');
                }
            });
        });
    }

    /**
     * @param list<mixed> $values
     */
    private static function applyChunkedIn(
        QueryBuilder $query,
        string $column,
        array $values,
        bool $not,
        string $firstBoolean = 'and',
    ): void {
        if ($values === []) {
            $query->whereIn($column, [], $firstBoolean, $not);

            return;
        }

        // Nested morph groups are already bounded by the relation query's
        // distinct key projection. Keep one logical predicate here; the caller
        // uses the parent connection's batching for ordinary relations.
        $query->whereIn($column, $values, $firstBoolean, $not);
    }

    private function key(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            default => serialize($value),
        };
    }
}
