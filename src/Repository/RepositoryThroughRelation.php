<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Resolve ordinary through relations in two bounded repository-aware phases:
 * parent -> intermediate repository -> related repository.
 *
 * Through one-of-many relations delegate to RepositoryOneOfManyRelation so all
 * one-of-many cardinalities share the streaming winner selector.
 */
final class RepositoryThroughRelation
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
     * @return list<array<string,mixed>>
     */
    public function load(
        array $parents,
        string $as,
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        if ($definition->oneOfManyAggregate !== null) {
            return (new RepositoryOneOfManyRelation($this->batchSize))
                ->load($parents, $as, $definition, $constraint);
        }

        if ($parents === []) {
            return $parents;
        }

        $many = $this->isMany($definition);
        [$throughRows, $throughParentKey, $throughKey] = $this->throughRows($parents, $definition);
        if ($throughRows === []) {
            return $this->attachEmpty($parents, $as, $many);
        }

        [$relatedRows, $internalColumns] = $this->relatedRows(
            $throughRows,
            $throughKey,
            $definition,
            $constraint,
        );
        $parentByThrough = $this->parentByThrough($throughRows, $throughParentKey, $throughKey);
        $matches = $this->matchesByParent($relatedRows, $parentByThrough, $definition, $internalColumns, $many);

        return $this->attachMatches($parents, $as, $definition->parentKey, $matches, $many);
    }

    /**
     * Return parent keys whose through relation has at least one matching final
     * row under the related repository policy.
     *
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<int|float|string|bool>
     */
    public function matchingParentKeys(
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        $through = $this->requireThrough($definition);
        $throughParentKey = RepositorySupport::column($this->requireThroughParentKey($definition));
        $throughKey = RepositorySupport::column($this->requireThroughKey($definition));
        $related = $this->requireRelated($definition);

        $relatedQuery = $related::query();
        $this->applyRelatedScopes($relatedQuery, $definition, $constraint);
        $throughValues = $this->distinctValues($relatedQuery, $definition->relatedKey);
        if ($throughValues === []) {
            return [];
        }

        $values = [];
        $batchSize = max(1, $through::connection()->safeBatchSize(requested: $this->batchSize));
        foreach (array_chunk($throughValues, $batchSize) as $chunk) {
            $query = $through::query()->apply(static function (QueryBuilder $builder) use ($throughKey, $chunk): void {
                $builder->whereIn($throughKey, $chunk);
            });

            foreach ($query->get([$throughParentKey]) as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row !== null && ($row[$throughParentKey] ?? null) !== null) {
                    $values[] = $row[$throughParentKey];
                }
            }
        }

        return RepositorySupport::uniqueValues($values);
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return array{0:list<array<string,mixed>>,1:non-empty-string,2:non-empty-string}
     */
    private function throughRows(array $parents, RelationDefinition $definition): array
    {
        $through = $this->requireThrough($definition);
        $throughParentKey = RepositorySupport::column($this->requireThroughParentKey($definition));
        $throughKey = RepositorySupport::column($this->requireThroughKey($definition));
        $parentValues = $this->values($parents, $definition->parentKey);
        $batchSize = max(1, $through::connection()->safeBatchSize(requested: $this->batchSize));
        $rows = [];

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $through::query()->apply(static function (QueryBuilder $builder) use ($throughParentKey, $chunk): void {
                $builder->whereIn($throughParentKey, $chunk);
            });
            array_push($rows, ...$this->normalizeRows($query->get([$throughParentKey, $throughKey])->toArray()));
        }

        return [$rows, $throughParentKey, $throughKey];
    }

    /**
     * @param list<array<string,mixed>> $throughRows
     * @param null|callable(QueryBuilder):void $constraint
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function relatedRows(
        array $throughRows,
        string $throughKey,
        RelationDefinition $definition,
        ?callable $constraint,
    ): array {
        $related = $this->requireRelated($definition);
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $values = $this->values($throughRows, $throughKey);
        $batchSize = max(1, $related::connection()->safeBatchSize(requested: $this->batchSize));
        [$columns, $internalColumns] = $this->projection($definition->columns, $relatedKey);
        $rows = [];

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $query = $related::query()->apply(static function (QueryBuilder $builder) use ($relatedKey, $chunk): void {
                $builder->whereIn($relatedKey, $chunk);
            });
            $this->applyRelatedScopes($query, $definition, $constraint);
            array_push($rows, ...$this->normalizeRows($query->get($columns)->toArray()));
        }

        return [$rows, $internalColumns];
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
     * @param list<string> $requested
     * @return array{0:list<string>,1:list<string>}
     */
    private function projection(array $requested, string $relatedKey): array
    {
        if ($requested === ['*'] || in_array('*', $requested, true)) {
            return [$requested, []];
        }

        if (in_array($relatedKey, $requested, true)) {
            return [$requested, []];
        }

        return [[...$requested, $relatedKey], [$relatedKey]];
    }

    /** @return list<int|float|string|bool> */
    private function distinctValues(RepositoryQuery $query, string $column): array
    {
        $column = RepositorySupport::column($column);
        $values = [];

        foreach ($query->get([$column]) as $candidateRow) {
            $row = RepositorySupport::row($candidateRow);
            if ($row !== null && ($row[$column] ?? null) !== null) {
                $values[] = $row[$column];
            }
        }

        return RepositorySupport::uniqueValues($values);
    }

    /**
     * @param array<array-key,mixed> $values
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $values): array
    {
        $rows = [];
        foreach ($values as $value) {
            $row = RepositorySupport::row($value);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
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

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,string>
     */
    private function parentByThrough(array $rows, string $parentKey, string $throughKey): array
    {
        $map = [];
        foreach ($rows as $row) {
            $through = $row[$throughKey] ?? null;
            $parent = $row[$parentKey] ?? null;
            if ($through !== null && $parent !== null) {
                $map[RepositorySupport::key($through)] = RepositorySupport::key($parent);
            }
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $parentByThrough
     * @param list<string> $internalColumns
     * @return array<string,mixed>
     */
    private function matchesByParent(
        array $rows,
        array $parentByThrough,
        RelationDefinition $definition,
        array $internalColumns,
        bool $many,
    ): array {
        $matches = [];
        foreach ($rows as $row) {
            $throughIdentity = RepositorySupport::key($row[$definition->relatedKey] ?? null);
            $parentIdentity = $parentByThrough[$throughIdentity] ?? null;
            if ($parentIdentity === null) {
                continue;
            }

            $projected = $this->withoutInternalColumns($row, $internalColumns);
            if ($many) {
                $matches[$parentIdentity][] = $projected;
            } else {
                $matches[$parentIdentity] ??= $projected;
            }
        }

        return $matches;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param array<string,mixed> $matches
     * @return list<array<string,mixed>>
     */
    private function attachMatches(array $parents, string $as, string $parentKey, array $matches, bool $many): array
    {
        foreach ($parents as &$parent) {
            $identity = RepositorySupport::key($parent[$parentKey] ?? null);
            $parent[$as] = $matches[$identity] ?? ($many ? [] : null);
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return list<array<string,mixed>>
     */
    private function attachEmpty(array $parents, string $as, bool $many): array
    {
        foreach ($parents as &$parent) {
            $parent[$as] = $many ? [] : null;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function withoutInternalColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            unset($row[$column]);
        }

        return $row;
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
}
