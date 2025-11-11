<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Query Profiler
 *
 * Profiles database query performance:
 * - Execution time tracking
 * - Memory usage monitoring
 * - Query statistics
 * - Performance analysis
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Profiler
{
    private bool $enabled = false;
    private array $queries = [];

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function end(string $id): void
    {
        if (!$this->enabled || !isset($this->queries[$id])) {
            return;
        }

        $this->queries[$id]['end_time'] = microtime(true);
        $this->queries[$id]['end_memory'] = memory_get_usage();
        $this->queries[$id]['duration'] = $this->queries[$id]['end_time'] - $this->queries[$id]['start_time'];
        $this->queries[$id]['memory'] = $this->queries[$id]['end_memory'] - $this->queries[$id]['start_memory'];
    }

    public function getQueries(): array
    {
        return array_values($this->queries);
    }

    public function getSlowestQueries(int $limit = 10): array
    {
        $queries = $this->queries;
        usort($queries, fn ($a, $b) => ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0));
        return array_slice($queries, 0, $limit);
    }

    public function getStats(): array
    {
        $durations = array_column($this->queries, 'duration');
        $memory = array_column($this->queries, 'memory');

        return [
            'total_queries' => count($this->queries),
            'total_time' => array_sum($durations),
            'avg_time' => count($durations) > 0 ? array_sum($durations) / count($durations) : 0,
            'max_time' => count($durations) > 0 ? max($durations) : 0,
            'min_time' => count($durations) > 0 ? min($durations) : 0,
            'total_memory' => array_sum($memory),
            'avg_memory' => count($memory) > 0 ? array_sum($memory) / count($memory) : 0,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    public function start(string $sql, array $bindings = []): string
    {
        if (!$this->enabled) {
            return '';
        }

        $id = uniqid('query_', true);
        $this->queries[$id] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
        ];

        return $id;
    }
}
