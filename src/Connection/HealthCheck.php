<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Throwable;

/**
 * Connection Health Monitor
 *
 * Monitors database connection health and performance:
 * - Connection availability checks
 * - Latency monitoring
 * - Query performance tracking (duration samples in milliseconds)
 * - Error rate monitoring
 */
final class HealthCheck
{
    /**
     * Default configuration.
     *
     * @var array{check_interval:int,max_latency_ms:int,max_error_rate:float,sample_size:int}
     */
    private const DEFAULTS = [
      'check_interval' => 30,
      'max_latency_ms' => 1_000,
      'max_error_rate' => 0.1,
      'sample_size'    => 100,
    ];

    /**
     * Health check configuration.
     *
     * @var array{check_interval:int,max_latency_ms:int,max_error_rate:float,sample_size:int}
     */
    private array $config;

    /**
     * Connection to monitor.
     */
    private Connection $connection;

    /**
     * Health metrics.
     *
     * @var array{
     *   last_check:float|null,
     *   is_healthy:bool,
     *   latency_ms:float,
     *   error_rate:float,
     *   total_checks:int,
     *   failed_checks:int,
     *   last_error:string|null
     * }
     */
    private array $metrics = [
      'last_check'    => null,
      'is_healthy'    => true,
      'latency_ms'    => 0.0,
      'error_rate'    => 0.0,
      'total_checks'  => 0,
      'failed_checks' => 0,
      'last_error'    => null,
    ];

    /**
     * Query performance samples.
     *
     * @var array<int,array{duration:float,success:bool,timestamp:float}>
     */
    private array $samples = [];

    /**
     * Create a new health check instance.
     *
     * @param  array<string,mixed>  $config
     */
    public function __construct(Connection $connection, array $config = [])
    {
        $this->connection = $connection;

        $merged = array_merge(self::DEFAULTS, $config);

        $this->config = [
          'check_interval' => (int) $merged['check_interval'],
          'max_latency_ms' => (int) $merged['max_latency_ms'],
          'max_error_rate' => (float) $merged['max_error_rate'],
          'sample_size'    => (int) $merged['sample_size'],
        ];
    }

    /**
     * Perform a health check.
     */
    public function check(): bool
    {
        $this->metrics['total_checks']++;
        $this->metrics['last_check'] = microtime(true);

        try {
            // Measure latency.
            $startTime = microtime(true);
            $this->connection->getPdo()->query('SELECT 1');
            $latency = (microtime(true) - $startTime) * 1_000.0;

            $this->metrics['latency_ms'] = round($latency, 2);

            // Check latency threshold.
            if ($latency > $this->config['max_latency_ms']) {
                $this->metrics['is_healthy'] = false;
                $this->metrics['last_error'] = 'High latency: '.round($latency, 2).'ms';

                return false;
            }

            // Check error rate.
            $stats = $this->connection->getStats();
            if ($stats['queries'] > 0) {
                $errorRate = $stats['errors'] / $stats['queries'];
                $this->metrics['error_rate'] = round($errorRate, 4);

                if ($errorRate > $this->config['max_error_rate']) {
                    $this->metrics['is_healthy'] = false;
                    $this->metrics['last_error'] = 'High error rate: '.round($errorRate * 100, 2).'%';

                    return false;
                }
            }

            $this->metrics['is_healthy'] = true;
            $this->metrics['last_error'] = null;

            return true;
        } catch (Throwable $e) {
            $this->metrics['failed_checks']++;
            $this->metrics['is_healthy'] = false;
            $this->metrics['last_error']  = $e->getMessage();

            return false;
        }
    }

    /**
     * Get health metrics.
     *
     * @return array{
     *   last_check:float|null,
     *   is_healthy:bool,
     *   latency_ms:float,
     *   error_rate:float,
     *   total_checks:int,
     *   failed_checks:int,
     *   last_error:string|null
     * }
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get performance statistics from samples.
     *
     * @return array{
     *   avg_duration:float,
     *   min_duration:float,
     *   max_duration:float,
     *   p50_duration:float,
     *   p95_duration:float,
     *   p99_duration:float,
     *   success_rate:float
     * }
     */
    public function getPerformanceStats(): array
    {
        if ($this->samples === []) {
            return [
              'avg_duration' => 0.0,
              'min_duration' => 0.0,
              'max_duration' => 0.0,
              'p50_duration' => 0.0,
              'p95_duration' => 0.0,
              'p99_duration' => 0.0,
              'success_rate' => 0.0,
            ];
        }

        $durations = array_column($this->samples, 'duration');
        sort($durations);

        $count     = count($this->samples);
        $successes = 0;

        foreach ($this->samples as $sample) {
            if ($sample['success']) {
                $successes++;
            }
        }

        $avg = array_sum($durations) / count($durations);

        return [
          'avg_duration' => round($avg, 4),
          'min_duration' => round((float) min($durations), 4),
          'max_duration' => round((float) max($durations), 4),
          'p50_duration' => round($this->percentile($durations, 50.0), 4),
          'p95_duration' => round($this->percentile($durations, 95.0), 4),
          'p99_duration' => round($this->percentile($durations, 99.0), 4),
          'success_rate' => round($successes / $count, 4),
        ];
    }

    /**
     * Get detailed health report.
     *
     * @return array{
     *   status:string,
     *   metrics:array<string,mixed>,
     *   performance:array<string,float>,
     *   connection_stats:array<string,int>,
     *   config:array<string,mixed>
     * }
     */
    public function getReport(): array
    {
        return [
          'status'           => $this->getStatus(),
          'metrics'          => $this->getMetrics(),
          'performance'      => $this->getPerformanceStats(),
          'connection_stats' => $this->connection->getStats(),
          'config'           => $this->config,
        ];
    }

    /**
     * Get health status.
     */
    public function getStatus(): string
    {
        if (! $this->metrics['is_healthy']) {
            return 'unhealthy';
        }

        if ($this->metrics['latency_ms'] > ($this->config['max_latency_ms'] * 0.7)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Check if connection is healthy.
     */
    public function isHealthy(): bool
    {
        // Perform check if interval elapsed.
        if ($this->shouldPerformCheck()) {
            $this->check();
        }

        return $this->metrics['is_healthy'];
    }

    /**
     * Record query performance sample.
     *
     * @param  float  $duration  Duration in milliseconds.
     */
    public function recordSample(float $duration, bool $success): void
    {
        $this->samples[] = [
          'duration'  => $duration,
          'success'   => $success,
          'timestamp' => microtime(true),
        ];

        // Keep only recent samples.
        if (count($this->samples) > $this->config['sample_size']) {
            array_shift($this->samples);
        }
    }

    /**
     * Reset health metrics.
     */
    public function reset(): void
    {
        $this->metrics = [
          'last_check'    => null,
          'is_healthy'    => true,
          'latency_ms'    => 0.0,
          'error_rate'    => 0.0,
          'total_checks'  => 0,
          'failed_checks' => 0,
          'last_error'    => null,
        ];

        $this->samples = [];
    }

    /**
     * Calculate percentile value.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $index = ($percentile / 100.0) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        $lowerValue = (float) $sorted[$lower];
        $upperValue = (float) $sorted[$upper];
        $fraction   = $index - $lower;

        return $lowerValue + ($upperValue - $lowerValue) * $fraction;
    }

    /**
     * Check if health check should be performed.
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
