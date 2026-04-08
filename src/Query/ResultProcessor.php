<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Support\Collection;

/**
 * Result Processor
 *
 * Processes and transforms query results.
 * Separated from QueryBuilder for single responsibility.
 */
class ResultProcessor
{
    /**
     * Filter results using callback (reindexed).
     *
     * @param list<array<string,mixed>>                $results
     * @param callable(array<string,mixed>):bool|mixed $callback
     * @return list<array<string,mixed>>
     */
    public function filter(array $results, callable $callback): array
    {
        return \array_values(\array_filter($results, $callback));
    }

    /**
     * Process raw results into collection.
     *
     * @param list<array<string,mixed>> $results
     */
    public function process(array $results): Collection
    {
        return new Collection($results);
    }

    /**
     * Process aggregate result.
     *
     * @param list<array<string,mixed>> $results
     */
    public function processAggregate(array $results): mixed
    {
        if ($results === []) {
            return null;
        }

        $result = $results[0];

        return $result['aggregate'] ?? ($result[\array_key_first($result)] ?? null);
    }

    /**
     * Process column values.
     *
     * @param list<array<string,mixed>> $results
     * @return list<mixed>
     */
    public function processColumn(array $results, string $column): array
    {
        return \array_column($results, $column);
    }

    /**
     * Process grouped results.
     *
     * @param list<array<string,mixed>> $results
     * @return array<string|int,list<array<string,mixed>>>
     */
    public function processGrouped(array $results, string $groupBy): array
    {
        $grouped = [];

        foreach ($results as $row) {
            $groupKey = $row[$groupBy] ?? null;

            if ($groupKey === null) {
                continue;
            }

            $grouped[$groupKey][] = $row;
        }

        return $grouped;
    }

    /**
     * Process key-value pairs.
     *
     * @param list<array<string,mixed>> $results
     * @return array<int|string,mixed>
     */
    public function processKeyValue(array $results, string $key, string $value): array
    {
        $processed = [];

        foreach ($results as $row) {
            if (! \array_key_exists($key, $row) || ! \array_key_exists($value, $row)) {
                continue;
            }

            $processed[$row[$key]] = $row[$value];
        }

        return $processed;
    }

    /**
     * Process single result row.
     *
     * @param list<array<string,mixed>> $results
     * @return array<string,mixed>|null
     */
    public function processSingle(array $results): mixed
    {
        return $results[0] ?? null;
    }

    /**
     * Transform results using callback.
     *
     * @param list<array<string,mixed>>                         $results
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return list<array<string,mixed>>
     */
    public function transform(array $results, callable $callback): array
    {
        return \array_map($callback, $results);
    }
}
