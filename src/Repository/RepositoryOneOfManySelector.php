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
final class RepositoryOneOfManySelector
{
    public function __construct(private readonly int $batchSize = 500)
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
        $columns = array_values(array_unique([
            $definition->relatedKey,
            $relatedDefinition->primaryKey,
            ...RepositoryOneOfManyOrder::columns($orders),
        ]));
        $winners = [];

        if ($parentValues === null) {
            $this->scanDirectBatch($definition, $related, $columns, $orders, null, $winners);

            return $winners;
        }

        $values = $this->uniqueValues($parentValues);
        if ($values === []) {
            return [];
        }

        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);

        foreach (array_chunk($values, $batchSize) as $chunk) {
            $this->scanDirectBatch($definition, $related, $columns, $orders, $chunk, $winners);
        }

        return $winners;
    }

    /**
     * @param class-string<TableRepository> $related
     * @param list<string> $columns
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
        $query = $related::query();

        if ($parentValues !== null) {
            $query->apply(
                static fn(QueryBuilder $builder): mixed => $builder->whereIn(
                    $definition->relatedKey,
                    $parentValues,
                ),
            );
        }

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

        $this->applyCandidateScopes($query, $definition);

        foreach ($query->raw()->select($columns)->cursor() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parent = $row[$definition->relatedKey] ?? null;
            $id = $row[$relatedDefinition->primaryKey] ?? null;
            if ($parent === null || $id === null) {
                continue;
            }

            $identity = $this->key($parent);
            $candidate = ['parent' => $parent, 'id' => $id, 'row' => $row];

            if (!isset($winners[$identity]) || RepositoryOneOfManyOrder::compare(
                $candidate['row'],
                $winners[$identity]['row'],
                $orders,
            ) < 0) {
                $winners[$identity] = $candidate;
            }
        }
    }

    /**
     * @param list<mixed>|null $parentValues
     * @return array<string,array{parent:mixed,id:mixed,row:array<string,mixed>}>
     */
    private function selectThrough(RelationDefinition $definition, ?array $parentValues): array
    {
        $through = $definition->through
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate repository.');
        $throughParentKey = $definition->throughParentKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate parent key.');
        $throughKey = $definition->throughKey
            ?? throw new InvalidArgumentException('One-of-many through relation requires an intermediate local key.');
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many through relation requires a related repository.');
        $parentByThrough = [];
        $throughValues = [];

        if ($parentValues === null) {
            $this->scanThroughBatch(
                $through,
                $throughParentKey,
                $throughKey,
                null,
                $parentByThrough,
                $throughValues,
            );
        } else {
            $values = $this->uniqueValues($parentValues);
            if ($values === []) {
                return [];
            }

            $connection = $through::connection();
            $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
            foreach (array_chunk($values, $batchSize) as $chunk) {
                $this->scanThroughBatch(
                    $through,
                    $throughParentKey,
                    $throughKey,
                    $chunk,
                    $parentByThrough,
                    $throughValues,
                );
            }
        }

        if ($throughValues === []) {
            return [];
        }

        $relatedDefinition = $related::definition();
        $orders = RepositoryOneOfManyOrder::resolve($definition, $relatedDefinition);
        $columns = array_values(array_unique([
            $definition->relatedKey,
            $relatedDefinition->primaryKey,
            ...RepositoryOneOfManyOrder::columns($orders),
        ]));
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $winners = [];

        foreach (array_chunk(array_values($throughValues), $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static fn(QueryBuilder $builder): mixed => $builder->whereIn($definition->relatedKey, $chunk),
            );
            $this->applyCandidateScopes($query, $definition);

            foreach ($query->raw()->select($columns)->cursor() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $throughIdentity = $this->key($row[$definition->relatedKey] ?? null);
                $parent = $parentByThrough[$throughIdentity] ?? null;
                $id = $row[$relatedDefinition->primaryKey] ?? null;
                if ($parent === null || $id === null) {
                    continue;
                }

                $parentIdentity = $this->key($parent);
                $candidate = ['parent' => $parent, 'id' => $id, 'row' => $row];

                if (!isset($winners[$parentIdentity]) || RepositoryOneOfManyOrder::compare(
                    $candidate['row'],
                    $winners[$parentIdentity]['row'],
                    $orders,
                ) < 0) {
                    $winners[$parentIdentity] = $candidate;
                }
            }
        }

        return $winners;
    }

    /**
     * @param class-string<TableRepository> $through
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
        $query = $through::query();
        if ($parentValues !== null) {
            $query->apply(
                static fn(QueryBuilder $builder): mixed => $builder->whereIn(
                    $throughParentKey,
                    $parentValues,
                ),
            );
        }

        foreach ($query->raw()->select([$throughParentKey, $throughKey])->cursor() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parent = $row[$throughParentKey] ?? null;
            $throughValue = $row[$throughKey] ?? null;
            if ($parent === null || $throughValue === null) {
                continue;
            }

            $identity = $this->key($throughValue);
            $parentByThrough[$identity] = $parent;
            $throughValues[$identity] = $throughValue;
        }
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

    /** @param list<mixed> $values @return list<mixed> */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $unique[$this->key($value)] = $value;
        }

        return array_values($unique);
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
