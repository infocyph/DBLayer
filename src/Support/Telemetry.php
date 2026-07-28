<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Closure;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionBeginning;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionCommitted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionRolledBack;
use Infocyph\DBLayer\Events\Events;

/**
 * Lightweight telemetry collector/exporter for DB query + transaction events.
 *
 * @phpstan-type QueryShapeGroup array{
 *   fingerprint:string,
 *   statement:string,
 *   connection:string,
 *   sql:string,
 *   calls:int,
 *   success_count:int,
 *   failure_count:int,
 *   total_time_ms:float,
 *   min_time_ms:float,
 *   max_time_ms:float,
 *   rows_affected_total:int,
 *   rows_affected_samples:int,
 *   _durations:list<float>
 * }
 */
final class Telemetry
{
    private const int DEFAULT_MAX_QUERY_EVENTS = 2_000;

    private const int DEFAULT_MAX_TRANSACTION_EVENTS = 2_000;

    private const string REDACTED_VALUE = '[redacted]';

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
     * Stable listener identities used for idempotent re-registration.
     *
     * @var array<string,Closure>
     */
    private static array $listeners = [];

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
     * Aggregate buffered telemetry by normalized SQL fingerprint.
     *
     * Parameterized statements with the same SQL shape are grouped together.
     * Inline literal values remain part of the fingerprint by design.
     *
     * @param list<int|float> $percentiles
     * @return array{
     *   shape_count:int,
     *   query_count:int,
     *   threshold_ms:float|null,
     *   shapes:list<array<string,mixed>>
     * }
     */
    public static function queryShapeReport(
        array $percentiles = [50, 90, 95, 99],
        ?float $minimumMs = null,
        ?int $limit = 20,
    ): array {
        $groups = [];
        $queryCount = 0;

        foreach (self::$queries as $query) {
            $duration = Numeric::arrayFloat($query, 'duration_ms');

            if ($minimumMs !== null && $duration < $minimumMs) {
                continue;
            }

            [$fingerprint, $connection, $sql] = self::resolveShapeIdentity($query);
            $key = $connection . "\0" . $fingerprint;
            $success = ($query['success'] ?? false) === true;
            $rowsAffected = $query['rows_affected'] ?? null;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'fingerprint' => $fingerprint,
                    'statement' => self::queryString($query, 'statement', self::statementFromSql($sql)),
                    'connection' => $connection,
                    'sql' => $sql,
                    'calls' => 0,
                    'success_count' => 0,
                    'failure_count' => 0,
                    'total_time_ms' => 0.0,
                    'min_time_ms' => $duration,
                    'max_time_ms' => $duration,
                    'rows_affected_total' => 0,
                    'rows_affected_samples' => 0,
                    '_durations' => [],
                ];
            } elseif (
                $groups[$key]['sql'] === self::REDACTED_VALUE
                && $sql !== self::REDACTED_VALUE
            ) {
                $groups[$key]['sql'] = $sql;
            }

            $groups[$key]['calls']++;
            $groups[$key][$success ? 'success_count' : 'failure_count']++;
            $groups[$key]['total_time_ms'] += $duration;
            $groups[$key]['min_time_ms'] = min($groups[$key]['min_time_ms'], $duration);
            $groups[$key]['max_time_ms'] = max($groups[$key]['max_time_ms'], $duration);
            $groups[$key]['_durations'][] = $duration;

            if (is_int($rowsAffected)) {
                $groups[$key]['rows_affected_total'] += max(0, $rowsAffected);
                $groups[$key]['rows_affected_samples']++;
            }

            $queryCount++;
        }

        $shapes = self::finalizeShapeGroups($groups, $percentiles);
        $shapeCount = count($shapes);

        if ($limit !== null) {
            $shapes = array_slice($shapes, 0, max(1, $limit));
        }

        return [
            'shape_count' => $shapeCount,
            'query_count' => $queryCount,
            'threshold_ms' => $minimumMs,
            'shapes' => $shapes,
        ];
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
        self::$listeners['db.query.executed'] ??= static function (QueryExecuted $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$sequence++;
            self::$queries[] = [
                'span_id' => 'q-' . self::$sequence,
                'sql' => $event->sql,
                'statement' => self::statementFromSql($event->sql),
                'fingerprint' => self::queryFingerprint($event->sql),
                'bindings_count' => \count($event->bindings),
                'duration_ms' => $event->time,
                'success' => true,
                'rows_affected' => $event->rowsAffected,
                'connection' => $event->connection->getDriverName(),
                'timestamp' => microtime(true),
            ];
            self::trimQueryBuffer();
        };

        self::$listeners['db.query.failed'] ??= static function (QueryFailed $event): void {
            if (!self::$enabled) {
                return;
            }

            self::$sequence++;
            self::$queries[] = [
                'span_id' => 'q-' . self::$sequence,
                'sql' => self::REDACTED_VALUE,
                'bindings_count' => \count($event->bindings),
                'bindings_redacted' => true,
                'duration_ms' => $event->time,
                'success' => false,
                'rows_affected' => null,
                'connection' => $event->connection->getDriverName(),
                'timestamp' => microtime(true),
                'error' => self::REDACTED_VALUE,
                'statement' => self::statementFromSql($event->sql),
                'fingerprint' => self::queryFingerprint($event->sql),
                'attempts' => $event->attempts,
                'exception' => $event->exceptionClass,
            ];
            self::trimQueryBuffer();
        };

        self::$listeners['db.transaction.beginning'] ??= static function (TransactionBeginning $event): void {
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
        };

        self::$listeners['db.transaction.committed'] ??= static function (TransactionCommitted $event): void {
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
        };

        self::$listeners['db.transaction.rolled_back'] ??= static function (TransactionRolledBack $event): void {
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
        };

        foreach (self::$listeners as $event => $listener) {
            if (!in_array($listener, Events::getListeners($event), true)) {
                Events::listen($event, $listener);
            }
        }
    }

    /**
     * Convert working query-shape groups into sorted public report rows.
     *
     * @param array<string,QueryShapeGroup> $groups
     * @param list<int|float> $percentiles
     * @return list<array<string,mixed>>
     */
    private static function finalizeShapeGroups(array $groups, array $percentiles): array
    {
        $shapes = [];

        foreach ($groups as $group) {
            $durations = $group['_durations'];
            sort($durations);
            $shapePercentiles = [];

            foreach ($percentiles as $percentile) {
                $value = max(0.0, min(100.0, (float) $percentile));
                $shapePercentiles[(string) $value] = round(
                    Numeric::percentile($durations, $value),
                    4,
                );
            }

            $group['total_time_ms'] = round($group['total_time_ms'], 4);
            $group['mean_time_ms'] = round($group['total_time_ms'] / $group['calls'], 4);
            $group['min_time_ms'] = round($group['min_time_ms'], 4);
            $group['max_time_ms'] = round($group['max_time_ms'], 4);
            $group['percentiles'] = $shapePercentiles;
            unset($group['_durations']);
            $shapes[] = $group;
        }

        usort(
            $shapes,
            static fn(array $left, array $right): int => ($right['total_time_ms'] <=> $left['total_time_ms'])
                ?: ($right['max_time_ms'] <=> $left['max_time_ms'])
                ?: ($left['fingerprint'] <=> $right['fingerprint']),
        );

        return $shapes;
    }

    /**
     * Deterministic hex hash truncated to requested length.
     */
    private static function hexHash(string $input, int $length): string
    {
        return substr(hash('sha256', $input), 0, $length);
    }

    /**
     * Normalize whitespace and remove DBLayer's optional leading SQL comment.
     */
    private static function normalizeSqlShape(string $sql): string
    {
        $withoutComment = preg_replace('/\A\s*\/\*.*?\*\/\s*/s', '', $sql) ?? $sql;
        $normalized = preg_replace('/\s+/', ' ', trim($withoutComment)) ?? $withoutComment;

        return strtolower($normalized);
    }

    /**
     * Build a stable statement fingerprint without query-comment context.
     */
    private static function queryFingerprint(string $sql): string
    {
        $normalized = self::normalizeSqlShape($sql);

        return self::hexHash($normalized, 16);
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
     * Resolve stable grouping fields for one telemetry query event.
     *
     * @param array<string,mixed> $query
     * @return array{0:string,1:string,2:string}
     */
    private static function resolveShapeIdentity(array $query): array
    {
        $sql = self::queryString($query, 'sql', self::REDACTED_VALUE);
        $fingerprint = self::queryString($query, 'fingerprint');

        if ($fingerprint === '') {
            $fingerprint = self::queryFingerprint($sql);
        }

        return [
            $fingerprint,
            self::queryString($query, 'connection', 'unknown'),
            $sql,
        ];
    }

    /**
     * Resolve the first statement keyword after optional query comments.
     */
    private static function statementFromSql(string $sql): string
    {
        $normalized = self::normalizeSqlShape($sql);

        return strtoupper(substr($normalized, 0, strcspn($normalized, " \t\n\r")));
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
