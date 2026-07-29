<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Explicit, bounded relation prefetching for array repositories.
 *
 * This loader never performs lazy property queries and owns no identity map or
 * dirty state. Query cost is deterministic from parent count and batch size.
 */
final class RelationLoader
{
    /** @var positive-int */
    private readonly int $batchSize;

    private int $lastQueryCount = 0;

    private int $lastRelatedRowCount = 0;

    public function __construct(
        private readonly Connection $connection,
        int $batchSize = 500,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relation batch size must be at least one.');
        }

        $this->batchSize = $batchSize;
    }

    public function lastQueryCount(): int
    {
        return $this->lastQueryCount;
    }

    public function lastRelatedRowCount(): int
    {
        return $this->lastRelatedRowCount;
    }

    /**
     * Attach all matching related rows to each parent.
     *
     * @param list<array<string,mixed>> $parents
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @return list<array<string,mixed>>
     */
    public function many(
        array $parents,
        string $parentKey,
        string $relatedTable,
        string $relatedKey,
        string $as,
        array $columns = ['*'],
        ?callable $scope = null,
    ): array {
        $started = $this->connection->getStats()['queries'];
        $rows = $this->fetchRelated($parents, $parentKey, $relatedTable, $relatedKey, $columns, $scope);
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$this->key($row[$relatedKey] ?? null)][] = $row;
        }

        $result = [];
        foreach ($parents as $parent) {
            $parent[$as] = $grouped[$this->key($parent[$parentKey] ?? null)] ?? [];
            $result[] = $parent;
        }

        $this->recordDiagnostics($started, count($rows));

        return $result;
    }

    /**
     * Attach related rows through a pivot table, preserving pivot row order.
     *
     * Two bounded query phases are used: pivot keys, then related rows. There
     * is no per-parent query.
     *
     * @param list<array<string,mixed>> $parents
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @return list<array<string,mixed>>
     */
    public function manyToMany(
        array $parents,
        string $parentKey,
        string $pivotTable,
        string $pivotParentKey,
        string $pivotRelatedKey,
        string $relatedTable,
        string $relatedKey,
        string $as,
        array $columns = ['*'],
        ?callable $scope = null,
    ): array {
        $started = $this->connection->getStats()['queries'];
        $parentValues = $this->values($parents, $parentKey);
        $pivotRows = [];

        foreach (array_chunk($parentValues, $this->batchSize) as $values) {
            $pivotRows = array_merge(
                $pivotRows,
                $this->connection
                    ->table($pivotTable)
                    ->select([$pivotParentKey, $pivotRelatedKey])
                    ->whereIn($pivotParentKey, $values)
                    ->get(),
            );
        }

        $relatedValues = $this->uniqueValues($pivotRows, $pivotRelatedKey);
        $relatedRows = $this->fetchByValues(
            $relatedValues,
            $relatedTable,
            $relatedKey,
            $columns,
            $scope,
        );
        $relatedByKey = [];

        foreach ($relatedRows as $row) {
            $relatedByKey[$this->key($row[$relatedKey] ?? null)] = $row;
        }

        $relatedKeysByParent = [];
        foreach ($pivotRows as $pivot) {
            $relatedKeysByParent[$this->key($pivot[$pivotParentKey] ?? null)][]
                = $this->key($pivot[$pivotRelatedKey] ?? null);
        }

        $result = [];
        foreach ($parents as $parent) {
            $matches = [];
            foreach ($relatedKeysByParent[$this->key($parent[$parentKey] ?? null)] ?? [] as $relatedValue) {
                if (isset($relatedByKey[$relatedValue])) {
                    $matches[] = $relatedByKey[$relatedValue];
                }
            }

            $parent[$as] = $matches;
            $result[] = $parent;
        }

        $this->recordDiagnostics($started, count($pivotRows) + count($relatedRows));

        return $result;
    }

    /**
     * Attach at most one related row to each parent.
     *
     * @param list<array<string,mixed>> $parents
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @return list<array<string,mixed>>
     */
    public function one(
        array $parents,
        string $parentKey,
        string $relatedTable,
        string $relatedKey,
        string $as,
        array $columns = ['*'],
        ?callable $scope = null,
    ): array {
        $started = $this->connection->getStats()['queries'];
        $rows = $this->fetchRelated($parents, $parentKey, $relatedTable, $relatedKey, $columns, $scope);
        $indexed = [];

        foreach ($rows as $row) {
            $key = $this->key($row[$relatedKey] ?? null);
            $indexed[$key] ??= $row;
        }

        $result = [];
        foreach ($parents as $parent) {
            $parent[$as] = $indexed[$this->key($parent[$parentKey] ?? null)] ?? null;
            $result[] = $parent;
        }

        $this->recordDiagnostics($started, count($rows));

        return $result;
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

        $columns[] = $key;

        return $columns;
    }

    /**
     * @param list<mixed> $values
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @return list<array<string,mixed>>
     */
    private function fetchByValues(
        array $values,
        string $table,
        string $key,
        array $columns,
        ?callable $scope,
    ): array {
        if ($values === []) {
            return [];
        }

        $columns = $this->ensureKeySelected($columns, $key);
        $rows = [];

        foreach (array_chunk($values, $this->batchSize) as $chunk) {
            $query = $this->connection->table($table)->select($columns)->whereIn($key, $chunk);
            if ($scope !== null) {
                $scope($query);
            }

            $rows = array_merge($rows, $query->get());
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @param list<string> $columns
     * @param null|callable(QueryBuilder):void $scope
     * @return list<array<string,mixed>>
     */
    private function fetchRelated(
        array $parents,
        string $parentKey,
        string $table,
        string $relatedKey,
        array $columns,
        ?callable $scope,
    ): array {
        return $this->fetchByValues(
            $this->values($parents, $parentKey),
            $table,
            $relatedKey,
            $columns,
            $scope,
        );
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

    private function recordDiagnostics(int $startedQueries, int $rowCount): void
    {
        $this->lastQueryCount = $this->connection->getStats()['queries'] - $startedQueries;
        $this->lastRelatedRowCount = $rowCount;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<mixed>
     */
    private function uniqueValues(array $rows, string $key): array
    {
        $values = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $normalized = $this->key($row[$key]);
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $values[] = $row[$key];
            }
        }

        return $values;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<mixed>
     */
    private function values(array $rows, string $key): array
    {
        return $this->uniqueValues($rows, $key);
    }
}
