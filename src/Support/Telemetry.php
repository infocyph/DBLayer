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
    /**
     * Whether collection is enabled.
     */
    private static bool $enabled = false;

    /**
     * Optional exporter callback.
     *
     * @var null|callable(array<string,mixed>):void
     */
    private static $exporter = null;

    /**
     * Whether event listeners are already registered.
     */
    private static bool $hooked = false;
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
     * @param  null|callable(array<string,mixed>):void  $exporter
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
     * Whether telemetry collection is currently enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Configure a default exporter callback.
     *
     * @param  null|callable(array<string,mixed>):void  $exporter
     */
    public static function setExporter(?callable $exporter): void
    {
        self::$exporter = $exporter;
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
            $totalQueryTime += (float) ($query['duration_ms'] ?? 0.0);
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
     * Register event listeners once.
     */
    private static function ensureHooked(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;

        Events::listen('db.query.executed', static function (QueryExecuted $event): void {
            if (! self::$enabled) {
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
        });

        Events::listen('db.transaction.beginning', static function (TransactionBeginning $event): void {
            if (! self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'begin',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => 0.0,
                'timestamp' => $event->time,
            ];
        });

        Events::listen('db.transaction.committed', static function (TransactionCommitted $event): void {
            if (! self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'commit',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => $event->duration,
                'timestamp' => microtime(true),
            ];
        });

        Events::listen('db.transaction.rolled_back', static function (TransactionRolledBack $event): void {
            if (! self::$enabled) {
                return;
            }

            self::$transactions[] = [
                'event' => 'rollback',
                'connection' => $event->connection->getDriverName(),
                'duration_ms' => $event->duration,
                'timestamp' => microtime(true),
            ];
        });
    }
}
