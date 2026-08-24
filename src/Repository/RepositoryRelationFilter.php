<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\TablePrefixMapper;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/** Apply relation-existence constraints without loading relation graphs. */
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
        if ($definition->oneOfManyAggregate !== null) {
            $matching = (new RepositoryOneOfManyFilter($this->batchSize))->matchingParentKeys($definition, $constraint);
            $this->applyValues(
                $parentQuery,
                $definition->parentKey,
                RepositorySupport::uniqueValues($matching),
                $not,
            );
            return;
        }
        if ($definition->through !== null) {
            $matching = (new RepositoryThroughRelation($this->batchSize))
                ->matchingParentKeys($definition, $constraint);
            $this->applyValues($parentQuery, $definition->parentKey, $matching, $not);
            return;
        }
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
        $relatedBuilder = $this->relatedQuery($related, $definition, $constraint)->raw();
        $components = $parentQuery->raw()->getComponents();
        $parentSource = $components['from'] ?? null;
        if (!is_string($parentSource) || trim($parentSource) === '') {
            throw new InvalidArgumentException('Repository relation filters require a concrete parent table source.');
        }

        $parentAlias = $components['fromAlias'] ?? null;
        $outer = is_string($parentAlias) && $parentAlias !== ''
            ? $parentAlias
            : TablePrefixMapper::physicalTable($parentSource, $this->parentConnection->getTablePrefix());
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $parentKey = RepositorySupport::column($definition->parentKey);

        $parentQuery->apply(static function (QueryBuilder $parent) use ($relatedBuilder, $relatedKey, $parentKey, $outer, $not): void {
            $parent->whereExists(static function (QueryBuilder $exists) use ($relatedBuilder, $relatedKey, $parentKey, $outer): void {
                $alias = '__repo_relation';
                $exists->fromSub($relatedBuilder, $alias)
                    ->select($alias . '.' . $relatedKey)
                    ->whereColumn($alias . '.' . $relatedKey, '=', $outer . '.' . $parentKey);
            }, not: $not);
        });
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyMorphTo(
        RepositoryQuery $parentQuery,
        RelationDefinition $definition,
        ?callable $constraint,
        bool $not,
    ): void {
        $typeColumn = RepositorySupport::column($definition->morphTypeColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires a type column.'));
        $idColumn = RepositorySupport::column($definition->morphIdColumn
            ?? throw new InvalidArgumentException('Morph-to relation requires an id column.'));
        $groups = $this->morphGroups($definition, $constraint);
        $batchSize = max(1, $this->parentConnection->safeBatchSize(requested: $this->batchSize));

        if ($not) {
            $parentQuery->apply(function (QueryBuilder $query) use ($groups, $typeColumn, $idColumn, $batchSize): void {
                foreach ($groups as $alias => $ids) {
                    $this->applyNegatedMorphGroup($query, $typeColumn, $idColumn, $alias, $ids, $batchSize);
                }
            });
            return;
        }

        $parentQuery->apply(function (QueryBuilder $query) use ($groups, $typeColumn, $idColumn, $batchSize): void {
            $this->applyPositiveMorphGroups($query, $groups, $typeColumn, $idColumn, $batchSize);
        });
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,list<int|float|string|bool>>
     */
    private function morphGroups(RelationDefinition $definition, ?callable $constraint): array
    {
        $groups = [];
        foreach ($definition->morphMap as $alias => $related) {
            $query = $related::query();
            $this->applyRelatedScopes($query, $definition, $constraint);
            $groups[$alias] = $this->distinctColumnValues($query, $definition->relatedKey);
        }

        return $groups;
    }

    /**
     * @param array<string,list<int|float|string|bool>> $groups
     * @param positive-int $batchSize
     */
    private function applyPositiveMorphGroups(
        QueryBuilder $query,
        array $groups,
        string $typeColumn,
        string $idColumn,
        int $batchSize,
    ): void {
        $query->where(static function (QueryBuilder $nested) use ($groups, $typeColumn, $idColumn, $batchSize): void {
            $first = true;
            foreach ($groups as $alias => $ids) {
                if ($ids === []) {
                    continue;
                }
                $nested->where(static function (QueryBuilder $group) use ($typeColumn, $idColumn, $alias, $ids, $batchSize): void {
                    $group->where(RepositorySupport::column($typeColumn), '=', $alias);
                    self::applyChunkedIn($group, $idColumn, $ids, false, $batchSize);
                }, boolean: $first ? 'and' : 'or');
                $first = false;
            }
            if ($first) {
                $nested->whereIn($idColumn, []);
            }
        });
    }

    /**
     * @param list<int|float|string|bool> $ids
     * @param positive-int $batchSize
     */
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

        $query->where(static function (QueryBuilder $nested) use ($typeColumn, $idColumn, $alias, $ids, $batchSize): void {
            $nested->where(RepositorySupport::column($typeColumn), '!=', $alias);
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

        return $related !== null && $related::connection() === $this->parentConnection;
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<int|float|string|bool>
     */
    private function matchingDirectParentKeys(RelationDefinition $definition, ?callable $constraint): array
    {
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
            $column = RepositorySupport::column($definition->morphTypeColumn);
            $alias = $definition->morphAlias;
            $query->apply(static function (QueryBuilder $builder) use ($column, $alias): void {
                $builder->where($column, '=', $alias);
            });
        }
        $this->applyRelatedScopes($query, $definition, $constraint);

        return $query;
    }

    /** @param null|callable(QueryBuilder):void $constraint */
    private function applyRelatedScopes(
        RepositoryQuery $query,
        RelationDefinition $definition,
        ?callable $constraint,
    ): void {
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
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<int|float|string|bool>
     */
    private function matchingPivotParentKeys(RelationDefinition $definition, ?callable $constraint): array
    {
        $pivotTable = $definition->pivotTable
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot table.');
        $pivotParentKey = RepositorySupport::column($definition->pivotParentKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot parent key.'));
        $pivotRelatedKey = RepositorySupport::column($definition->pivotRelatedKey
            ?? throw new InvalidArgumentException('Many-to-many relation requires a pivot related key.'));
        $related = $definition->related
            ?? throw new InvalidArgumentException('Many-to-many relation requires a related repository.');
        $relatedQuery = $related::query();
        $this->applyRelatedScopes($relatedQuery, $definition, $constraint);
        $relatedIds = $this->distinctColumnValues($relatedQuery, $definition->relatedKey);
        if ($relatedIds === []) {
            return [];
        }

        $values = [];
        $batchSize = max(1, $this->parentConnection->safeBatchSize(requested: $this->batchSize));
        foreach (array_chunk($relatedIds, $batchSize) as $chunk) {
            $query = $this->parentConnection
                ->table($pivotTable)
                ->select($pivotParentKey)
                ->whereIn($pivotRelatedKey, $chunk);
            $this->applyPivotMorphConstraint($query, $definition);
            foreach ($query->get() as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null && ($row[$pivotParentKey] ?? null) !== null) {
                    $values[] = $row[$pivotParentKey];
                }
            }
        }

        return RepositorySupport::uniqueValues($values);
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

    /** @return list<int|float|string|bool> */
    private function distinctColumnValues(RepositoryQuery $query, string $column): array
    {
        $column = RepositorySupport::column($column);
        $values = [];
        foreach ($query->raw()->select($column)->distinct()->cursor() as $candidateRow) {
            $row = RepositorySupport::row($candidateRow);
            if ($row !== null && ($row[$column] ?? null) !== null) {
                $values[] = $row[$column];
            }
        }

        return RepositorySupport::uniqueValues($values);
    }

    /** @param list<int|float|string|bool> $values */
    private function applyValues(
        RepositoryQuery $query,
        string $column,
        array $values,
        bool $not,
    ): void {
        $column = RepositorySupport::column($column);
        $batchSize = max(1, $this->parentConnection->safeBatchSize(requested: $this->batchSize));
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

    /**
     * @param list<int|float|string|bool> $values
     * @param positive-int $batchSize
     */
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
            $boolean = $index === 0 ? $firstBoolean : ($not ? 'and' : 'or');
            $query->whereIn($column, $chunk, $boolean, $not);
        }
    }
}
