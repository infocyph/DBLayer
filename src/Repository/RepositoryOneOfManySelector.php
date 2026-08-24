<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Select one persisted related-row identifier per parent without hydrating the
 * relation graph. Candidate ordering uses raw persisted values so direct,
 * polymorphic and through relations share one deterministic selection engine.
 */
final readonly class RepositoryOneOfManySelector
{
    public function __construct(private int $batchSize = 500)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param list<mixed>|null $parentValues null means all visible parents
     * @return array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}>
     */
    public function select(RelationDefinition $definition, ?array $parentValues = null): array
    {
        if ($definition->oneOfManyAggregate === null) {
            throw new InvalidArgumentException('One-of-many selector requires one-of-many relation metadata.');
        }

        return $definition->through === null
            ? $this->selectDirect($definition, $parentValues)
            : $this->selectThrough($definition, $parentValues);
    }

    private function applyCandidateScopes(RepositoryQuery $query, RelationDefinition $definition): void
    {
        if ($definition->scope !== null) {
            /** @var callable(QueryBuilder):void $scope */
            $scope = $definition->scope;
            $query->apply($scope);
        }
        if ($definition->oneOfManyScope !== null) {
            /** @var callable(QueryBuilder):void $scope */
            $scope = $definition->oneOfManyScope;
            $query->apply($scope);
        }
    }

    private function applyMorphConstraint(RepositoryQuery $query, RelationDefinition $definition): void
    {
        if (
            $definition->type !== RelationDefinition::MORPH_ONE
            || $definition->morphTypeColumn === null
            || $definition->morphAlias === null
        ) {
            return;
        }

        $column = RepositorySupport::column($definition->morphTypeColumn);
        $alias = $definition->morphAlias;
        $query->apply(static function (QueryBuilder $builder) use ($column, $alias): void {
            $builder->where($column, '=', $alias);
        });
    }

    /**
     * @param array{parent:mixed,id:mixed,row:array<string,mixed>} $candidate
     * @param array{parent:mixed,id:mixed,row:array<string,mixed>}|null $winner
     * @param array<string,'asc'|'desc'> $orders
     */
    private function isBetterCandidate(array $candidate, ?array $winner, array $orders): bool
    {
        return $winner === null
            || RepositoryOneOfManyOrder::compare($candidate['row'], $winner['row'], $orders) < 0;
    }

    /**
     * @param list<mixed>|null $parentValues
     * @return array{0:array<string,mixed>,1:list<mixed>}
     */
    private function loadThroughMap(RelationDefinition $definition, ?array $parentValues): array
    {
        $through = $definition->through
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate repository.');
        $parentKey = RepositorySupport::column($definition->throughParentKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate parent key.'));
        $throughKey = RepositorySupport::column($definition->throughKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate local key.'));
        $parentByThrough = [];
        $throughValues = [];

        if ($parentValues === null) {
            $this->scanThroughBatch($through, $parentKey, $throughKey, null, $parentByThrough, $throughValues);
        } else {
            $values = RepositorySupport::uniqueValues($parentValues);
            $batchSize = $through::connection()->safeBatchSize(requested: $this->batchSize);
            foreach (array_chunk($values, $batchSize) as $chunk) {
                $this->scanThroughBatch($through, $parentKey, $throughKey, $chunk, $parentByThrough, $throughValues);
            }
        }

        return [$parentByThrough, array_values($throughValues)];
    }

    /**
     * @param class-string<TableRepository> $related
     * @param list<non-empty-string> $columns
     * @param array<string,'asc'|'desc'> $orders
     * @param list<mixed>|null $parentValues
     * @param array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}> $winners
     */
    private function scanDirectBatch(
        RelationDefinition $definition,
        string $related,
        array $columns,
        array $orders,
        ?array $parentValues,
        array &$winners,
    ): void {
        $relatedDefinition = $related::definition();
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $query = $related::repositoryQuery();

        if ($parentValues !== null) {
            $query->apply(static function (QueryBuilder $builder) use ($relatedKey, $parentValues): void {
                $builder->whereIn($relatedKey, $parentValues);
            });
        }

        $this->applyMorphConstraint($query, $definition);
        $this->applyCandidateScopes($query, $definition);

        foreach ($query->raw()->select($columns)->cursor() as $candidateRow) {
            $row = RepositorySupport::row($candidateRow);
            if ($row === null) {
                continue;
            }

            $parent = $row[$relatedKey] ?? null;
            $id = $row[$relatedDefinition->primaryKey] ?? null;
            if ($parent === null || $id === null) {
                continue;
            }

            $identity = RepositorySupport::key($parent);
            $candidate = ['parent' => $parent, 'id' => $id, 'row' => $row];
            if ($this->isBetterCandidate($candidate, $winners[$identity] ?? null, $orders)) {
                $winners[$identity] = $candidate;
            }
        }
    }

    /**
     * @param class-string<TableRepository> $through
     * @param non-empty-string $throughParentKey
     * @param non-empty-string $throughKey
     * @param list<mixed>|null $parentValues
     * @param array<string,mixed> $parentByThrough
     * @param array<string,mixed> $throughValues
     */
    private function scanThroughBatch(
        string $through,
        string $throughParentKey,
        string $throughKey,
        ?array $parentValues,
        array &$parentByThrough,
        array &$throughValues,
    ): void {
        $query = $through::repositoryQuery();
        if ($parentValues !== null) {
            $query->apply(static function (QueryBuilder $builder) use ($throughParentKey, $parentValues): void {
                $builder->whereIn($throughParentKey, $parentValues);
            });
        }

        foreach ($query->raw()->select([$throughParentKey, $throughKey])->cursor() as $candidateRow) {
            $row = RepositorySupport::row($candidateRow);
            if ($row === null) {
                continue;
            }

            $parent = $row[$throughParentKey] ?? null;
            $throughValue = $row[$throughKey] ?? null;
            if ($parent === null || $throughValue === null) {
                continue;
            }

            $identity = RepositorySupport::key($throughValue);
            $parentByThrough[$identity] = $parent;
            $throughValues[$identity] = $throughValue;
        }
    }

    /**
     * @param class-string<TableRepository> $related
     * @param non-empty-string $relatedKey
     * @param list<non-empty-string> $columns
     * @param array<string,'asc'|'desc'> $orders
     * @param list<mixed> $values
     * @param array<string,mixed> $parentByThrough
     * @param array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}> $winners
     */
    private function scanThroughRelatedBatch(
        RelationDefinition $definition,
        string $related,
        string $relatedKey,
        array $columns,
        array $orders,
        array $values,
        array $parentByThrough,
        array &$winners,
    ): void {
        $relatedDefinition = $related::definition();
        $query = $related::repositoryQuery()->apply(static function (QueryBuilder $builder) use ($relatedKey, $values): void {
            $builder->whereIn($relatedKey, $values);
        });
        $this->applyCandidateScopes($query, $definition);

        foreach ($query->raw()->select($columns)->cursor() as $candidateRow) {
            $row = RepositorySupport::row($candidateRow);
            if ($row === null) {
                continue;
            }

            $parent = $parentByThrough[RepositorySupport::key($row[$relatedKey] ?? null)] ?? null;
            $id = $row[$relatedDefinition->primaryKey] ?? null;
            if ($parent === null || $id === null) {
                continue;
            }

            $identity = RepositorySupport::key($parent);
            $candidate = ['parent' => $parent, 'id' => $id, 'row' => $row];
            if ($this->isBetterCandidate($candidate, $winners[$identity] ?? null, $orders)) {
                $winners[$identity] = $candidate;
            }
        }
    }

    /**
     * @param list<mixed>|null $parentValues
     * @return array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}>
     */
    private function selectDirect(RelationDefinition $definition, ?array $parentValues): array
    {
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many relation requires a related repository.');
        $relatedDefinition = $related::definition();
        $orders = RepositoryOneOfManyOrder::resolve($definition, $relatedDefinition);
        $columns = RepositorySupport::uniqueColumns([
            $definition->relatedKey,
            $relatedDefinition->primaryKey,
            ...RepositoryOneOfManyOrder::columns($orders),
        ]);
        $winners = [];

        if ($parentValues === null) {
            $this->scanDirectBatch($definition, $related, $columns, $orders, null, $winners);

            return $winners;
        }

        $values = RepositorySupport::uniqueValues($parentValues);
        if ($values === []) {
            return [];
        }

        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);
        foreach (array_chunk($values, $batchSize) as $chunk) {
            $this->scanDirectBatch($definition, $related, $columns, $orders, $chunk, $winners);
        }

        return $winners;
    }

    /**
     * @param list<mixed>|null $parentValues
     * @return array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}>
     */
    private function selectThrough(RelationDefinition $definition, ?array $parentValues): array
    {
        [$parentByThrough, $throughValues] = $this->loadThroughMap($definition, $parentValues);
        if ($throughValues === []) {
            return [];
        }

        return $this->selectThroughRelated($definition, $parentByThrough, $throughValues);
    }

    /**
     * @param array<string,mixed> $parentByThrough
     * @param list<mixed> $throughValues
     * @return array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}>
     */
    private function selectThroughRelated(
        RelationDefinition $definition,
        array $parentByThrough,
        array $throughValues,
    ): array {
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many through relation requires a related repository.');
        $relatedDefinition = $related::definition();
        $relatedKey = RepositorySupport::column($definition->relatedKey);
        $orders = RepositoryOneOfManyOrder::resolve($definition, $relatedDefinition);
        $columns = RepositorySupport::uniqueColumns([
            $relatedKey,
            $relatedDefinition->primaryKey,
            ...RepositoryOneOfManyOrder::columns($orders),
        ]);
        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);
        $winners = [];

        foreach (array_chunk($throughValues, $batchSize) as $chunk) {
            $this->scanThroughRelatedBatch(
                $definition,
                $related,
                $relatedKey,
                $columns,
                $orders,
                $chunk,
                $parentByThrough,
                $winners,
            );
        }

        return $winners;
    }
}
