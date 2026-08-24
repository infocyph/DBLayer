<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Resolve parent keys for one-of-many existence queries. Winner selection is
 * performed before the caller's whereHas/whereRelation constraint is applied.
 */
final class RepositoryOneOfManyFilter
{
    public function __construct(private readonly int $batchSize = 500)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    public function matchingParentKeys(
        RelationDefinition $definition,
        ?callable $constraint = null,
    ): array {
        return $definition->through !== null
            ? $this->matchingThrough($definition, $constraint)
            : $this->matchingDirect($definition, $constraint);
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    private function matchingDirect(RelationDefinition $definition, ?callable $constraint): array
    {
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many relation requires a related repository.');
        $relatedDefinition = $related::definition();
        $orderColumn = $definition->oneOfManyColumn ?? $relatedDefinition->primaryKey;
        $direction = $definition->oneOfManyAggregate === 'min' ? 'asc' : 'desc';
        $query = $related::query();
        $this->applyCandidateScopes($query, $definition);

        if (
            $definition->type === RelationDefinition::MORPH_ONE
            && $definition->morphTypeColumn !== null
            && $definition->morphAlias !== null
        ) {
            $query->apply(static fn(QueryBuilder $builder): mixed => $builder->where(
                $definition->morphTypeColumn,
                '=',
                $definition->morphAlias,
            ));
        }

        $query->apply(static function (QueryBuilder $builder) use (
            $definition,
            $orderColumn,
            $direction,
            $relatedDefinition,
        ): void {
            $builder->orderBy($definition->relatedKey, 'asc');
            $builder->orderBy($orderColumn, $direction);
            if ($relatedDefinition->primaryKey !== $orderColumn) {
                $builder->orderBy($relatedDefinition->primaryKey, $direction);
            }
        });

        $winners = [];
        foreach ($query->raw()->select([
            $definition->relatedKey,
            $relatedDefinition->primaryKey,
        ])->cursor() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentValue = $row[$definition->relatedKey] ?? null;
            $winnerId = $row[$relatedDefinition->primaryKey] ?? null;
            if ($parentValue === null || $winnerId === null) {
                continue;
            }
            $identity = $this->key($parentValue);
            $winners[$identity] ??= [
                'parent' => $parentValue,
                'id' => $winnerId,
            ];
        }

        return $this->filterWinners($related, $relatedDefinition->primaryKey, $winners, $constraint);
    }

    /**
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    private function matchingThrough(RelationDefinition $definition, ?callable $constraint): array
    {
        $through = $definition->through
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate repository.');
        $throughParentKey = $definition->throughParentKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate parent key.');
        $throughKey = $definition->throughKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate local key.');
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many through relation requires a related repository.');
        $relatedDefinition = $related::definition();
        $orderColumn = $definition->oneOfManyColumn ?? $relatedDefinition->primaryKey;
        $direction = $definition->oneOfManyAggregate === 'min' ? 'asc' : 'desc';
        $parentByThrough = [];
        $throughValues = [];

        foreach ($through::query()->raw()->select([$throughParentKey, $throughKey])->cursor() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentValue = $row[$throughParentKey] ?? null;
            $throughValue = $row[$throughKey] ?? null;
            if ($parentValue === null || $throughValue === null) {
                continue;
            }
            $identity = $this->key($throughValue);
            $parentByThrough[$identity] = $parentValue;
            $throughValues[$identity] = $throughValue;
        }

        if ($throughValues === []) {
            return [];
        }

        $winners = [];
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk(array_values($throughValues), $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $builder): mixed => $builder->whereIn($definition->relatedKey, $chunk),
            );
            $this->applyCandidateScopes($query, $definition);
            $query->apply(static function (QueryBuilder $builder) use (
                $orderColumn,
                $direction,
                $relatedDefinition,
            ): void {
                $builder->orderBy($orderColumn, $direction);
                if ($relatedDefinition->primaryKey !== $orderColumn) {
                    $builder->orderBy($relatedDefinition->primaryKey, $direction);
                }
            });

            foreach ($query->raw()->select([
                $definition->relatedKey,
                $relatedDefinition->primaryKey,
                $orderColumn,
            ])->cursor() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $throughIdentity = $this->key($row[$definition->relatedKey] ?? null);
                $parentValue = $parentByThrough[$throughIdentity] ?? null;
                $winnerId = $row[$relatedDefinition->primaryKey] ?? null;
                if ($parentValue === null || $winnerId === null) {
                    continue;
                }

                $parentIdentity = $this->key($parentValue);
                $candidate = [
                    'parent' => $parentValue,
                    'id' => $winnerId,
                    'order' => $row[$orderColumn] ?? null,
                ];

                if (!isset($winners[$parentIdentity]) || $this->isBetter(
                    $candidate,
                    $winners[$parentIdentity],
                    $direction,
                )) {
                    $winners[$parentIdentity] = $candidate;
                }
            }
        }

        return $this->filterWinners($related, $relatedDefinition->primaryKey, $winners, $constraint);
    }

    /**
     * @param class-string<TableRepository> $related
     * @param array<string,array{parent:mixed,id:mixed,order?:mixed}> $winners
     * @param null|callable(QueryBuilder):void $constraint
     * @return list<mixed>
     */
    private function filterWinners(
        string $related,
        string $primaryKey,
        array $winners,
        ?callable $constraint,
    ): array {
        if ($winners === []) {
            return [];
        }

        if ($constraint === null) {
            return array_values(array_map(
                static fn(array $winner): mixed => $winner['parent'],
                $winners,
            ));
        }

        $parentById = [];
        foreach ($winners as $winner) {
            $parentById[$this->key($winner['id'])] = $winner['parent'];
        }

        $ids = array_column($winners, 'id');
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $matches = [];
        $seen = [];

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $builder): mixed => $builder->whereIn($primaryKey, $chunk),
            );
            $query->apply($constraint);

            foreach ($query->get([$primaryKey]) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parent = $parentById[$this->key($row[$primaryKey] ?? null)] ?? null;
                if ($parent === null) {
                    continue;
                }
                $identity = $this->key($parent);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $matches[] = $parent;
            }
        }

        return $matches;
    }

    private function applyCandidateScopes(RepositoryQuery $query, RelationDefinition $definition): void
    {
        if ($definition->scope !== null) {
            $query->apply($definition->scope);
        }
        if ($definition->oneOfManyScope !== null) {
            $query->apply($definition->oneOfManyScope);
        }
    }

    /**
     * @param array{parent:mixed,id:mixed,order?:mixed} $candidate
     * @param array{parent:mixed,id:mixed,order?:mixed} $current
     */
    private function isBetter(array $candidate, array $current, string $direction): bool
    {
        $comparison = ($candidate['order'] ?? null) <=> ($current['order'] ?? null);
        if ($comparison === 0) {
            $comparison = $candidate['id'] <=> $current['id'];
        }

        return $direction === 'desc' ? $comparison > 0 : $comparison < 0;
    }

    private function key(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => 'scalar:' . $value,
            is_float($value) => 'float:' . serialize($value),
            is_bool($value) => 'bool:' . ($value ? '1' : '0'),
            $value === null => 'null:',
            default => serialize($value),
        };
    }
}
