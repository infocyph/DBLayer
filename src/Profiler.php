<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

/**
 * Query profiler for performance monitoring
 */
class Profiler
{
    private array $queries = [];
    private bool $enabled = false;
    private float $slowQueryThreshold = 1.0;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function logQuery(string $sql, array $bindings, float $time, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => $time,
            'context' => $context,
            'timestamp' => microtime(true),
        ];
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getSlowQueries(?float $threshold = null): array
    {
        $threshold = $threshold ?? $this->slowQueryThreshold;
        return array_filter($this->queries, fn($query) => $query['time'] >= $threshold);
    }

    public function getTotalQueries(): int
    {
        return count($this->queries);
    }

    public function getTotalTime(): float
    {
        return array_sum(array_column($this->queries, 'time'));
    }

    public function getAverageTime(): float
    {
        $count = $this->getTotalQueries();
        return $count === 0 ? 0 : $this->getTotalTime() / $count;
    }

    public function getQueriesByTable(): array
    {
        $byTable = [];
        foreach ($this->queries as $query) {
            if (preg_match('/(?:FROM|JOIN|INTO|UPDATE)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query['sql'], $matches)) {
                $table = $matches[1];
                $byTable[$table][] = $query;
            }
        }
        return $byTable;
    }

    public function getQueriesByType(): array
    {
        $byType = [];
        foreach ($this->queries as $query) {
            if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)/i', $query['sql'], $matches)) {
                $type = strtoupper($matches[1]);
                $byType[$type][] = $query;
            }
        }
        return $byType;
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    public function setSlowQueryThreshold(float $seconds): void
    {
        $this->slowQueryThreshold = $seconds;
    }

    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function toArray(): array
    {
        return [
            'total_queries' => $this->getTotalQueries(),
            'total_time' => $this->getTotalTime(),
            'average_time' => $this->getAverageTime(),
            'slow_queries' => count($this->getSlowQueries()),
            'queries' => $this->queries,
        ];
    }

    public function dump(): void
    {
        echo "=== Query Profiler ===\n";
        echo "Total Queries: {$this->getTotalQueries()}\n";
        echo "Total Time: " . round($this->getTotalTime() * 1000, 2) . "ms\n";
        echo "Average Time: " . round($this->getAverageTime() * 1000, 2) . "ms\n";
        echo "Slow Queries: " . count($this->getSlowQueries()) . "\n\n";

        foreach ($this->queries as $i => $query) {
            echo "[" . ($i + 1) . "] " . round($query['time'] * 1000, 2) . "ms\n";
            echo $query['sql'] . "\n";
            if (!empty($query['bindings'])) {
                echo "Bindings: " . json_encode($query['bindings']) . "\n";
            }
            echo "\n";
        }
    }
}
