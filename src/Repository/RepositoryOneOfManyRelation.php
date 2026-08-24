<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/** Hydrate already-selected one-of-many winners through the related repository. */
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
        if ($parents === []) {
            return $parents;
        }

        $related = $definition->related
            ?? throw new InvalidArgumentException('One-of-many relation requires a related repository.');
        $parentValues = $this->values($parents, $definition->parentKey);
        $winners = (new RepositoryOneOfManySelector($this->batchSize))->select($definition, $parentValues);

        if ($winners === []) {
            return $this->attachEmpty($parents, $as);
        }

        $primaryKey = $related::definition()->primaryKey;
        [$columns, $internalColumns] = $this->projection($definition->columns, $primaryKey);
        $rowsById = $this->winnerRows(
            $related,
            $primaryKey,
            array_values(array_map(
                static fn(array $winner): mixed => $winner['id'],
                $winners,
            )),
            $columns,
            $internalColumns,
            $constraint,
        );

        foreach ($parents as &$parent) {
            $identity = RepositorySupport::key($parent[$definition->parentKey] ?? null);
            $winner = $winners[$identity] ?? null;
            $parent[$as] = $winner === null
                ? null
                : ($rowsById[RepositorySupport::key($winner['id'])] ?? null);
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param class-string<TableRepository> $related
     * @param list<mixed> $ids
     * @param list<string> $columns
     * @param list<string> $internalColumns
     * @param null|callable(QueryBuilder):void $constraint
     * @return array<string,array<string,mixed>>
     */
    private function winnerRows(
        string $related,
        string $primaryKey,
        array $ids,
        array $columns,
        array $internalColumns,
        ?callable $constraint,
    ): array {
        $batchSize = $related::connection()->safeBatchSize(requested: $this->batchSize);
        $rows = [];

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $query = $related::query()->apply(
                static function (QueryBuilder $builder) use ($primaryKey, $chunk): void {
                    $builder->whereIn($primaryKey, $chunk);
                },
            );
            if ($constraint !== null) {
                $query->apply($constraint);
            }

            foreach ($query->get($columns) as $candidateRow) {
                $row = RepositorySupport::row($candidateRow);
                if ($row === null || !array_key_exists($primaryKey, $row)) {
                    continue;
                }

                $rows[RepositorySupport::key($row[$primaryKey])] = $this->withoutInternalColumns(
                    $row,
                    $internalColumns,
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $requested
     * @return array{0:list<string>,1:list<string>}
     */
    private function projection(array $requested, string $primaryKey): array
    {
        if ($requested === ['*'] || in_array('*', $requested, true) || in_array($primaryKey, $requested, true)) {
            return [$requested, []];
        }

        return [[...$requested, $primaryKey], [$primaryKey]];
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return list<array<string,mixed>>
     */
    private function attachEmpty(array $parents, string $as): array
    {
        foreach ($parents as &$parent) {
            $parent[$as] = null;
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

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<mixed>
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
}
