<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Query Profiler
 *
 * Tracks and analyzes database query performance.
 * Provides timing, memory usage, and query statistics.
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Profiler
{
    /**
     * Whether profiling is enabled
     */
    private bool $enabled = false;

    /**
     * Collected query profiles
     *
     * @var array<int, array{sql: string, bindings: array<string, mixed>, time: float, memory: int}>
     */
    private array $profiles = [];

    /**
     * Start time for current query
     */
    private ?float $startTime = null;

    /**
     * Start memory for current query
     */
    private ?int $startMemory = null;

    /**
     * Clear all collected profiles
     *
     * @return void
     */
    public function clear(): void
    {
        $this->profiles = [];
    }

    /**
     * Disable profiling
     *
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable profiling
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Finish profiling current query
     *
     * @param string $sql SQL query
     * @param array<string, mixed> $bindings Query bindings
     * @return void
     */
    public function finish(string $sql, array $bindings = []): void
    {
        if (!$this->enabled || $this->startTime === null) {
            return;
        }

        $time = (microtime(true) - $this->startTime) * 1000; // Convert to milliseconds
        $memory = memory_get_usage() - ($this->startMemory ?? 0);

        $this->profiles[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => round($time, 2),
            'memory' => $memory,
        ];

        $this->startTime = null;
        $this->startMemory = null;
    }

    /**
     * Get the slowest query
     *
     * @return array{sql: string, bindings: array<string, mixed>, time: float, memory: int}|null
     */
    public function getSlowestQuery(): ?array
    {
        if (empty($this->profiles)) {
            return null;
        }

        return array_reduce(
            $this->profiles,
            fn ($carry, $profile) => ($carry === null || $profile['time'] > $carry['time']) ? $profile : $carry
        );
    }

    /**
     * Get statistics about collected profiles
     *
     * @return array{count: int, total_time: float, avg_time: float, total_memory: int}
     */
    public function getStats(): array
    {
        $count = count($this->profiles);
        
        if ($count === 0) {
            return [
                'count' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'total_memory' => 0,
            ];
        }

        $totalTime = array_sum(array_column($this->profiles, 'time'));
        $totalMemory = array_sum(array_column($this->profiles, 'memory'));

        return [
            'count' => $count,
            'total_time' => round($totalTime, 2),
            'avg_time' => round($totalTime / $count, 2),
            'total_memory' => $totalMemory,
        ];
    }

    /**
     * Check if profiling is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all collected profiles
     *
     * @return array<int, array{sql: string, bindings: array<string, mixed>, time: float, memory: int}>
     */
    public function profiles(): array
    {
        return $this->profiles;
    }

    /**
     * Start profiling a query
     *
     * @return void
     */
    public function start(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
    }
}
