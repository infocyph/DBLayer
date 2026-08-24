<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Resolve one-of-many relations by ordering each bounded related-key batch and
 * keeping the first row for each parent relation key.
 */
final class RepositoryOneOfManyRelation
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
        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many relation requires a related repository.');
        $parentValues = $this->values($parents, $definition->parentKey);
        if ($parentValues === []) {
            return $this->attachEmpty($parents, $as);
        }

        $relatedDefinition = $related::definition();
        $orderColumn = $definition->oneOfManyColumn ?? $relatedDefinition->primaryKey;
        $direction = $definition->oneOfManyAggregate === 'min' ? 'asc' : 'desc';
        [$columns, $internalColumns] = $this->projection(
            $definition->columns,
            $definition->relatedKey,
            $orderColumn,
            $relatedDefinition->primaryKey,
        );
        $connection = $related::connection();
        $batchSize = $connection->safeBatchSize(requested: $this->batchSize);
        $indexed = [];

        foreach (array_chunk($parentValues, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static function (QueryBuilder $query) use ($definition, $chunk): void {
                    $query->whereIn($definition->relatedKey, $chunk);
                    if (
                        $definition->type === RelationDefinition::MORPH_ONE
                        && $definition->morphTypeColumn !== null
                        && $definition->morphAlias !== null
                    ) {
                        $query->where($definition->morphTypeColumn, '=', $definition->morphAlias);
                    }
                },
            );

            if ($definition->scope !== null) {
                $query->apply($definition->scope);
            }
            if ($definition->oneOfManyScope !== null) {
                $query->apply($definition->oneOfManyScope);
            }
            if ($constraint !== null) {
                $query->apply($constraint);
            }

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

            foreach ($query->get($columns) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $identity = $this->key($row[$definition->relatedKey] ?? null);
                if (!isset($indexed[$identity])) {
                    $indexed[$identity] = $this->withoutInternalColumns($row, $internalColumns);
                }
            }
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $indexed[$this->key($parent[$definition->parentKey] ?? null)] ?? null;
        }
        unset($parent);

        return $parents;
    }

    /** @param list<array<string,mixed>> $parents @return list<array<string,mixed>> */
    private function attachEmpty(array $parents, string $as): array
    {
        foreach ($parents as &$parent) {
            $parent[$as] = null;
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<string> $requested
     * @return array{0:list<string>,1:list<string>}
     */
    private function projection(
        array $requested,
        string $relatedKey,
        string $orderColumn,
        string $primaryKey,
    ): array {
        if ($requested === ['*'] || in_array('*', $requested, true)) {
            return [$requested, []];
        }

        $columns = $requested;
        $internal = [];

        foreach ([$relatedKey, $orderColumn, $primaryKey] as $column) {
            if (in_array($column, $columns, true)) {
                continue;
            }
            $columns[] = $column;
            $internal[] = $column;
        }

        return [$columns, $internal];
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

    /** @param list<array<string,mixed>> $rows @return list<mixed> */
    private function values(array $rows, string $column): array
    {
        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $identity = $this->key($row[$column]);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $values[] = $row[$column];
        }

        return $values;
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
