<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Query Profiler
 *
 * Tracks and analyzes database query performance.
 * Provides timing, memory usage, and basic query statistics.
 *
 * Lightweight and disabled by default; enable only when needed.
 */
final class Profiler
{
    private const int DEFAULT_MAX_PROFILES = 2_000;

    private bool $enabled = false;

    /**
     * Maximum number of retained profiles.
     */
    private int $maxProfiles = self::DEFAULT_MAX_PROFILES;

    private int $profileCount = 0;

    /**
     * @var array<int, array{sql:string,bindings:array<array-key,mixed>,time:float,memory:int}>
     */
    private array $profiles = [];

    private int $profileStart = 0;

    private int $startMemory = 0;

    private float $startTime = 0.0;

    /**
     * Clear all profiling data.
     */
    public function clear(): void
    {
        $this->profiles = [];
        $this->profileStart = 0;
        $this->profileCount = 0;
        $this->startTime = 0.0;
        $this->startMemory = 0;
    }

    /**
     * Disable profiling.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable profiling.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Finish profiling a query.
     *
     * This should be called after start(), passing the executed SQL and bindings.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function finish(string $sql, array $bindings = []): void
    {
        if (!$this->enabled || $this->startTime <= 0.0) {
            return;
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $timeMs = ($endTime - $this->startTime) * 1000;
        $memory = $endMemory - $this->startMemory;

        $this->record($sql, $bindings, $timeMs, $memory);

        // Reset for next measurement.
        $this->startTime = 0.0;
        $this->startMemory = 0;
    }

    /**
     * Get the slowest recorded query profile.
     *
     * @return array{sql:string,bindings:array<array-key,mixed>,time:float,memory:int}|null
     */
    public function getSlowestQuery(): ?array
    {
        $profiles = $this->profiles();
        if ($profiles === []) {
            return null;
        }

        $slowest = null;

        foreach ($profiles as $profile) {
            if ($slowest === null || $profile['time'] > $slowest['time']) {
                $slowest = $profile;
            }
        }

        return $slowest;
    }

    /**
     * Get aggregated stats for all queries.
     *
     * @return array{
     *   count:int,
     *   total_time:float,
     *   avg_time:float,
     *   total_memory:int
     * }
     */
    public function getStats(): array
    {
        $profiles = $this->profiles();
        $count = count($profiles);

        if ($count === 0) {
            return [
                'count' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'total_memory' => 0,
            ];
        }

        $totalTime = 0.0;
        $totalMemory = 0;

        foreach ($profiles as $profile) {
            $totalTime += $profile['time'];
            $totalMemory += $profile['memory'];
        }

        return [
            'count' => $count,
            'total_time' => round($totalTime, 2),
            'avg_time' => round($totalTime / $count, 2),
            'total_memory' => $totalMemory,
        ];
    }

    /**
     * Check if profiling is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all query profiles.
     *
     * @return array<int, array{sql:string,bindings:array<array-key,mixed>,time:float,memory:int}>
     */
    public function profiles(): array
    {
        return RingBuffer::ordered($this->profiles, $this->profileStart, $this->profileCount, $this->maxProfiles);
    }

    /** @param array<array-key,mixed> $bindings */
    public function record(string $sql, array $bindings, float $timeMs, int $memory = 0): void
    {
        if (!$this->enabled) {
            return;
        }

        RingBuffer::append($this->profiles, $this->profileStart, $this->profileCount, $this->maxProfiles, [
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => round($timeMs, 2),
            'memory' => $memory,
        ]);
    }

    /**
     * Configure retained profile limit for long-running workers.
     */
    public function setMaxProfiles(?int $maxProfiles): void
    {
        $profiles = $this->profiles();
        $this->maxProfiles = max(1, $maxProfiles ?? self::DEFAULT_MAX_PROFILES);
        $profiles = array_slice($profiles, -$this->maxProfiles);
        $this->profiles = $profiles;
        $this->profileStart = 0;
        $this->profileCount = count($profiles);
    }

    /**
     * Start profiling a query.
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
