<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

/**
 * Connection Health Monitor
 *
 * Monitors database connection health and performance:
 * - Connection availability checks
 * - Latency monitoring
 * - Query performance tracking
 * - Error rate monitoring
 * - Automatic recovery attempts
 *
 * @package Infocyph\DBLayer\Connection
 * @author Hasan
 */
class HealthCheck
{
    /**
     * Default configuration
     */
    private const DEFAULTS = [
        'check_interval' => 30,
        'max_latency_ms' => 1000,
        'max_error_rate' => 0.1,
        'sample_size' => 100,
    ];

    /**
     * Health check configuration
     */
    private array $config;
    /**
     * Connection to monitor
     */
    private Connection $connection;

    /**
     * Health metrics
     */
    private array $metrics = [
        'last_check' => null,
        'is_healthy' => true,
        'latency_ms' => 0,
        'error_rate' => 0,
        'total_checks' => 0,
        'failed_checks' => 0,
        'last_error' => null,
    ];

    /**
     * Query performance samples
     */
    private array $samples = [];

    /**
     * Create a new health check instance
     */
    public function __construct(Connection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->config = array_merge(self::DEFAULTS, $config);
    }

    /**
     * Perform a health check
     */
    public function check(): bool
    {
        $this->metrics['total_checks']++;
        $this->metrics['last_check'] = microtime(true);

        try {
            // Measure latency
            $startTime = microtime(true);
            $this->connection->getPdo()->query('SELECT 1');
            $latency = (microtime(true) - $startTime) * 1000;

            $this->metrics['latency_ms'] = round($latency, 2);

            // Check latency threshold
            if ($latency > $this->config['max_latency_ms']) {
                $this->metrics['is_healthy'] = false;
                $this->metrics['last_error'] = "High latency: {$latency}ms";
                return false;
            }

            // Check error rate
            $stats = $this->connection->getStats();
            if ($stats['queries'] > 0) {
                $errorRate = $stats['errors'] / $stats['queries'];
                $this->metrics['error_rate'] = round($errorRate, 4);

                if ($errorRate > $this->config['max_error_rate']) {
                    $this->metrics['is_healthy'] = false;
                    $this->metrics['last_error'] = "High error rate: " . ($errorRate * 100) . "%";
                    return false;
                }
            }

            $this->metrics['is_healthy'] = true;
            $this->metrics['last_error'] = null;

            return true;
        } catch (\Throwable $e) {
            $this->metrics['failed_checks']++;
            $this->metrics['is_healthy'] = false;
            $this->metrics['last_error'] = $e->getMessage();

            return false;
        }
    }

    /**
     * Get health metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get performance statistics from samples
     */
    public function getPerformanceStats(): array
    {
        if (empty($this->samples)) {
            return [
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
                'p50_duration' => 0,
                'p95_duration' => 0,
                'p99_duration' => 0,
                'success_rate' => 0,
            ];
        }

        $durations = array_column($this->samples, 'duration');
        $successes = array_filter($this->samples, fn ($s) => $s['success']);

        sort($durations);

        return [
            'avg_duration' => round(array_sum($durations) / count($durations), 4),
            'min_duration' => round(min($durations), 4),
            'max_duration' => round(max($durations), 4),
            'p50_duration' => round($this->percentile($durations, 50), 4),
            'p95_duration' => round($this->percentile($durations, 95), 4),
            'p99_duration' => round($this->percentile($durations, 99), 4),
            'success_rate' => round(count($successes) / count($this->samples), 4),
        ];
    }

    /**
     * Get detailed health report
     */
    public function getReport(): array
    {
        return [
            'status' => $this->getStatus(),
            'metrics' => $this->getMetrics(),
            'performance' => $this->getPerformanceStats(),
            'connection_stats' => $this->connection->getStats(),
            'config' => $this->config,
        ];
    }

    /**
     * Get health status
     */
    public function getStatus(): string
    {
        if (!$this->metrics['is_healthy']) {
            return 'unhealthy';
        }

        if ($this->metrics['latency_ms'] > ($this->config['max_latency_ms'] * 0.7)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Check if connection is healthy
     */
    public function isHealthy(): bool
    {
        // Perform check if interval elapsed
        if ($this->shouldPerformCheck()) {
            $this->check();
        }

        return $this->metrics['is_healthy'];
    }

    /**
     * Record query performance sample
     */
    public function recordSample(float $duration, bool $success): void
    {
        $this->samples[] = [
            'duration' => $duration,
            'success' => $success,
            'timestamp' => microtime(true),
        ];

        // Keep only recent samples
        if (count($this->samples) > $this->config['sample_size']) {
            array_shift($this->samples);
        }
    }

    /**
     * Reset health metrics
     */
    public function reset(): void
    {
        $this->metrics = [
            'last_check' => null,
            'is_healthy' => true,
            'latency_ms' => 0,
            'error_rate' => 0,
            'total_checks' => 0,
            'failed_checks' => 0,
            'last_error' => null,
        ];

        $this->samples = [];
    }

    /**
     * Calculate percentile value
     */
    private function percentile(array $sorted, float $percentile): float
    {
        $index = ($percentile / 100) * (count($sorted) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $sorted[(int) $index];
        }

        $lowerValue = $sorted[(int) $lower];
        $upperValue = $sorted[(int) $upper];
        $fraction = $index - $lower;

        return $lowerValue + ($upperValue - $lowerValue) * $fraction;
    }

    /**
     * Check if health check should be performed
     */
    private function shouldPerformCheck(): bool
    {
        if ($this->metrics['last_check'] === null) {
            return true;
        }

        $elapsed = microtime(true) - $this->metrics['last_check'];
        return $elapsed >= $this->config['check_interval'];
    }
}