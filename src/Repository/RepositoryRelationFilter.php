<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\TablePrefixMapper;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Apply relation-existence constraints without loading relation graphs.
 *
 * Same-connection direct relations compile to correlated EXISTS queries.
 * Cross-connection and pivot/polymorphic variants use bounded key projection
 * while preserving related repository scopes and policies.
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

    /** @param null|callable(QueryBuilder):void $constraint */
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

        if ($this->canUseCorrelatedExists($definition)) {
            $this->applyDirectExists($parentQuery, $definition, $constraint, $not);

            return;
        }

        $matching = match ($definition->type) {
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO_MANY => $this->matchingPivotParentKeys($definition, $constraint),
            default => $this->matchingDirectParentKeys($definition, $constraint),
        };

        $this->applyValues($parentQuery, $definition->parentKey, $matching, $not);
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyDirectExists(
        RepositoryQuery $parentQuery,
        RelationDefinition $definition,
        ?callable $constraint,
        bool $not,
    ): void {
        $related = $definition->related
            ?? throw new InvalidArgumentException('Direct relation requires a related repository.');
        $relatedQuery = $this->relatedQuery($related, $definition, $constraint);
        $relatedBuilder = $relatedQuery->raw();
        $components = $parentQuery->raw()->getComponents();
        $parentSource = $components['from'] ?? null;

        if (!is_string($parentSource) || trim($parentSource) === '') {
            throw new InvalidArgumentException('Repository relation filters require a concrete parent table source.');
        }

        $parentAlias = $components['fromAlias'] ?? null;
        $outer = is_string($parentAlias) && $parentAlias !== ''
            ? $parentAlias
            : TablePrefixMapper::physicalTable($parentSource, $this->parentConnection->getTablePrefix());

        $parentQuery->apply(static function (QueryBuilder $parent) use (
            $relatedBuilder,
            $definition,
            $outer,
            $not,
        ): void {
            $parent->whereExists(
                static function (QueryBuilder $exists) use ($relatedBuilder, $definition, $outer): void {
                    $alias = '__repo_relation';
                    $exists
                        ->fromSub($relatedBuilder, $alias)
                        ->select($alias . '.' . $definition->relatedKey)
                        ->whereColumn(
                            $alias . '.' . $definition->relatedKey,
                            '=',
                            $outer . '.' . $definition->parentKey,
                        );
                },
                not: $not,
            );
        });
    }

    /** @param null|callable(QueryBuilder):void $constraint */
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

        $batchSize = $this->parentConnection->safeBatchSize(requested: $this->batchSize);

        $parentQuery->apply(function (QueryBuilder $query) use (
            $groups,
            $typeColumn,
            $idColumn,
            $not,
            $batchSize,
        ): void {
            if ($not) {
                foreach ($groups as $alias => $ids) {
                    $this->applyNegatedMorphGroup($query, $typeColumn, $idColumn, $alias, $ids, $batchSize);
                }

                return;
            }

            $query->where(function (QueryBuilder $nested) use ($groups, $typeColumn, $idColumn, $batchSize): void {
                $first = true;
                foreach ($groups as $alias => $ids) {
                    if ($ids === []) {
                        continue;
                    }

                    $nested->where(
                        static function (QueryBuilder $group) use ($typeColumn, $idColumn, $alias, $ids, $batchSize): void {
                            $group->where($typeColumn, '=', $alias);
                            self::applyChunkedIn($group, $idColumn, $ids, false, $batchSize);
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
        int $batchSize,
    ): void {
        if ($ids === []) {
            return;
        }

        $query->where(static function (QueryBuilder $nested) use (
            $typeColumn,
            $idColumn,
            $alias,
            $ids,
            $batchSize,
        ): void {
            $nested->where($typeColumn, '!=', $alias);
            self::applyChunkedIn($nested, $idColumn, $ids, true, $batchSize, 'or');
        });
    }

    private function canUseCorrelatedExists(RelationDefinition $definition): bool
    {
        if (in_array($definition->type, [
            RelationDefinition::BELONGS_TO_MANY,
            RelationDefinition::MORPH_TO,
            RelationDefinition::MORPH_TO_MANY,
        ], true)) {
            return false;
        }

        $related = $definition->related;
        if ($related === null) {
            return false;
        }

        return $related::connection() === $this->parentConnection;
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

        return $this->distinctColumnValues(
            $this->relatedQuery($related, $definition, $constraint),
            $definition->relatedKey,
        );
    }

    /**
     * @param class-string<TableRepository> $related
     * @param null|callable(QueryBuilder):void $constraint
     */
    private function relatedQuery(
        string $related,
        RelationDefinition $definition,
        ?callable $constraint,
    ): RepositoryQuery {
        $query = $related::query();

        if (
            in_array($definition->type, [RelationDefinition::MORPH_ONE, RelationDefinition::MORPH_MANY], true)
            && $definition->morphTypeColumn !== null
            && $definition->morphAlias !== null
        ) {
            $query->apply(static function (QueryBuilder $builder) use ($definition): void {
                $builder->where(
                    $definition->morphTypeColumn,
                    '=',
                    $definition->morphAlias,
                );
            });
        }

        if ($definition->scope !== null) {
            $query->apply($definition->scope);
        }
        if ($constraint !== null) {
            $query->apply($constraint);
        }

        return $query;
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

    /** @return list<mixed> */
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

    /** @param list<mixed> $values */
    private static function applyChunkedIn(
        QueryBuilder $query,
        string $column,
        array $values,
        bool $not,
        int $batchSize,
        string $firstBoolean = 'and',
    ): void {
        $chunks = array_chunk($values, $batchSize);
        if ($chunks === []) {
            $query->whereIn($column, [], $firstBoolean, $not);

            return;
        }

        foreach ($chunks as $index => $chunk) {
            $boolean = $index === 0
                ? $firstBoolean
                : ($not ? 'and' : 'or');
            $query->whereIn($column, $chunk, $boolean, $not);
        }
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
