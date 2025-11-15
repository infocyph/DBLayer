<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Support\Collection;

/**
 * Result Processor
 *
 * Processes and transforms query results.
 * Separated from QueryBuilder for single responsibility.
 *
 * @package Infocyph\DBLayer\Query
 * @author Hasan
 */
class ResultProcessor
{
    /**
     * Filter results using callback
     */
    public function filter(array $results, callable $callback): array
    {
        return array_values(array_filter($results, $callback));
    }

    /**
     * Process raw results into collection
     */
    public function process(array $results): Collection
    {
        return new Collection($results);
    }

    /**
     * Process aggregate result
     */
    public function processAggregate(array $results): mixed
    {
        if ($results === []) {
            return null;
        }

        $result = $results[0];

        return $result['aggregate'] ?? ($result[array_key_first($result)] ?? null);
    }

    /**
     * Process column values
     */
    public function processColumn(array $results, string $column): array
    {
        return array_column($results, $column);
    }

    /**
     * Process grouped results
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
     * Process key-value pairs
     */
    public function processKeyValue(array $results, string $key, string $value): array
    {
        $processed = [];

        foreach ($results as $row) {
            if (!array_key_exists($key, $row) || !array_key_exists($value, $row)) {
                continue;
            }

            $processed[$row[$key]] = $row[$value];
        }

        return $processed;
    }

    /**
     * Process single result
     */
    public function processSingle(array $results): mixed
    {
        return $results[0] ?? null;
    }

    /**
     * Transform results using callback
     */
    public function transform(array $results, callable $callback): array
    {
        return array_map($callback, $results);
    }
}
