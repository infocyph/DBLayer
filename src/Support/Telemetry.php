<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionBeginning;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionCommitted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionRolledBack;
use Infocyph\DBLayer\Events\Events;

/**
 * Lightweight telemetry collector/exporter for DB query + transaction events.
 */
final class Telemetry
{
    private const int DEFAULT_MAX_QUERY_EVENTS = 2_000;

    private const int DEFAULT_MAX_TRANSACTION_EVENTS = 2_000;

    /**
     * Whether collection is enabled.
     */
    private static bool $enabled = false;

    /**
     * Optional exporter callback.
     *
     * @var null|callable(array<string,mixed>):void
     */
    private static $exporter;

    /**
     * Whether event listeners are already registered.
     */
    private static bool $hooked = false;

    /**
     * Maximum retained query events in memory.
     */
    private static int $maxQueryEvents = self::DEFAULT_MAX_QUERY_EVENTS;

    /**
     * Maximum retained transaction events in memory.
     */
    private static int $maxTransactionEvents = self::DEFAULT_MAX_TRANSACTION_EVENTS;

    /**
     * @var list<array<string,mixed>>
     */
    private static array $queries = [];

    /**
     * Sequence id for generated span ids.
     */
    private static int $sequence = 0;

    /**
     * @var list<array<string,mixed>>
     */
    private static array $transactions = [];

    /**
     * Prevent static-only class instantiation.
     */
    private function __construct() {}

    /**
     * Clear all collected telemetry buffers.
     */
    public static function clear(): void
    {
        self::$queries = [];
        self::$transactions = [];
        self::$sequence = 0;
    }

    /**
     * Disable telemetry collection.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Enable telemetry collection.
     */
    public static function enable(): void
    {
        self::$enabled = true;
        self::ensureHooked();
    }

    /**
     * Export telemetry payload and clear local buffers.
     *
     * @param null|callable(array<string,mixed>):void $exporter
     * @return array<string,mixed>
     */
    public static function flush(?callable $exporter = null): array
    {
        $payload = self::snapshot();
        $sink = $exporter ?? self::$exporter;

        if ($sink !== null) {
            $sink($payload);
        }

        self::clear();

        return $payload;
    }

    /**
     * Export OpenTelemetry-like payload and clear local buffers.
     *
     * @param null|callable(array<string,mixed>):void $exporter
     * @return array<string,mixed>
     */
    public static function flushOtel(?callable $exporter = null, string $serviceName = 'dblayer'): array
    {
        $payload = self::snapshotOtel($serviceName);

        if ($exporter !== null) {
            $exporter($payload);
        }

        self::clear();

        return $payload;
    }

    /**
     * Whether telemetry collection is currently enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Configure in-memory telemetry buffer limits.
     */
    public static function setBufferLimits(?int $queryEvents = null, ?int $transactionEvents = null): void
    {
        if ($queryEvents !== null) {
            self::$maxQueryEvents = max(1, $queryEvents);
            self::trimQueryBuffer();
        }

        if ($transactionEvents !== null) {
            self::$maxTransactionEvents = max(1, $transactionEvents);
            self::trimTransactionBuffer();
        }
    }

    /**
     * Configure a default exporter callback.
     *
     * @param null|callable(array<string,mixed>):void $exporter
     */
    public static function setExporter(?callable $exporter): void
    {
        self::$exporter = $exporter;
    }

    /**
     * Build percentile report for collected query durations.
     *
     * @param list<int|float> $percentiles
     * @return array<string,mixed>
     */
    public static function slowQueryReport(array $percentiles = [50, 90, 95, 99], ?float $minimumMs = null): array
    {
        $durations = array_map(
            static fn(array $query): float => Numeric::arrayFloat($query, 'duration_ms'),
            self::$queries,
        );

        if ($durations === []) {
            return [
                'count' => 0,
                'percentiles' => [],
                'summary' => [
                    'min_ms' => 0.0,
                    'max_ms' => 0.0,
                    'avg_ms' => 0.0,
                ],
                'slow_count' => 0,
                'threshold_ms' => $minimumMs,
            ];
        }

        sort($durations);
        $count = count($durations);
        $sum = array_sum($durations);

        $pct = [];
        foreach ($percentiles as $percentile) {
            $p = max(0.0, min(100.0, (float) $percentile));
            $pct[(string) $p] = round(Numeric::percentile($durations, $p), 4);
        }

        $slowCount = 0;

        if ($minimumMs !== null) {
            foreach ($durations as $duration) {
                if ($duration >= $minimumMs) {
                    $slowCount++;
                }
            }
        }

        return [
            'count' => $count,
            'percentiles' => $pct,
            'summary' => [
                'min_ms' => round($durations[0], 4),
                'max_ms' => round($durations[$count - 1], 4),
                'avg_ms' => round($sum / $count, 4),
            ],
            'slow_count' => $slowCount,
            'threshold_ms' => $minimumMs,
        ];
    }

    /**
     * Return current telemetry snapshot without clearing buffers.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $totalQueryTime = 0.0;

        foreach (self::$queries as $query) {
            $totalQueryTime += Numeric::arrayFloat($query, 'duration_ms');
        }

        return [
            'queries' => self::$queries,
            'transactions' => self::$transactions,
            'summary' => [
                'query_count' => \count(self::$queries),
                'transaction_event_count' => \count(self::$transactions),
                'total_query_time_ms' => round($totalQueryTime, 4),
            ],
        ];
    }

    /**
     * Return OpenTelemetry-like spans without clearing buffers.
     *
     * @return array<string,mixed>
     */
    public static function snapshotOtel(string $serviceName = 'dblayer'): array
    {
        $spans = [];

        foreach (self::$queries as $query) {
            $durationMs = Numeric::arrayFloat($query, 'duration_ms');
            $end = Numeric::arrayFloat($query, 'timestamp', microtime(true));
            $start = max(0.0, $end - ($durationMs / 1_000.0));
            $spanId = self::queryString($query, 'span_id', 'q');
            $connection = self::queryString($query, 'connection', 'unknown');
            $sql = self::queryString($query, 'sql');
            $bindingsCount = Numeric::arrayInt($query, 'bindings_count');
            $rowsAffected = Numeric::arrayInt($query, 'rows_affected');

            $spans[] = [
                'traceId' => self::hexHash('trace-' . $spanId . '-' . $end, 32),
                'spanId' => self::hexHash('span-' . $spanId . '-' . $start, 16),
                'name' => 'db.query',
                'kind' => 3, // CLIENT
                'startTimeUnixNano' => (string) self::toUnixNano($start),
                'endTimeUnixNano' => (string) self::toUnixNano($end),
                'attributes' => [
                    ['key' => 'db.system', 'value' => ['stringValue' => $connection]],
                    ['key' => 'db.statement', 'value' => ['stringValue' => $sql]],
                    ['key' => 'db.bindings_count', 'value' => ['intValue' => $bindingsCount]],
                    ['key' => 'db.rows_affected', 'value' => ['intValue' => $rowsAffected]],
                    ['key' => 'db.duration_ms', 'value' => ['doubleValue' => $durationMs]],
                ],
            ];
        }

        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => $serviceName],
                    ]],
                ],
                'scopeSpans' => [[
                    'scope' => [
                        'name' => 'infocyph.dblayer',
                        'version' => '1.0.0',
                    ],
                    'spans' => $spans,
                ]],
            ]],
        ];
    }

    /**
     * Register event listeners once.
     */
    private static function ensureHooked(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;

        Events::listen('db.query.executed', static function (QueryExecuted $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$sequence++;
            self::$queries[] = [
                'span_id' => 'q-' . self::$sequence,
                'sql' => $event->sql,
                'bindings_count' => \count($event->bindings),
                'duration_ms' => $event->time,
                'rows_affected' => $event->rowsAffected,
                'connection' => $event->connection->getDriverName(),
                'timestamp' => microtime(true),
            ];
            self::trimQueryBuffer();
        });

        Events::listen('db.transaction.beginning', static function (TransactionBeginning $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'begin',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => 0.0,
                'timestamp' => $event->time,
            ];
            self::trimTransactionBuffer();
        });

        Events::listen('db.transaction.committed', static function (TransactionCommitted $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'commit',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => $event->duration,
                'timestamp' => microtime(true),
            ];
            self::trimTransactionBuffer();
        });

        Events::listen('db.transaction.rolled_back', static function (TransactionRolledBack $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'rollback',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => $event->duration,
                'timestamp' => microtime(true),
            ];
            self::trimTransactionBuffer();
        });
    }

    /**
     * Deterministic hex hash truncated to requested length.
     */
    private static function hexHash(string $input, int $length): string
    {
        return substr(hash('sha256', $input), 0, $length);
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function queryString(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Convert seconds-since-epoch float to unix-nano integer.
     */
    private static function toUnixNano(float $seconds): int
    {
        return (int) round($seconds * 1_000_000_000);
    }

    /**
     * Keep query buffer size within configured max.
     */
    private static function trimQueryBuffer(): void
    {
        $overflow = \count(self::$queries) - self::$maxQueryEvents;

        if ($overflow > 0) {
            array_splice(self::$queries, 0, $overflow);
        }
    }

    /**
     * Keep transaction buffer size within configured max.
     */
    private static function trimTransactionBuffer(): void
    {
        $overflow = \count(self::$transactions) - self::$maxTransactionEvents;

        if ($overflow > 0) {
            array_splice(self::$transactions, 0, $overflow);
        }
    }
}
