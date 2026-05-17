<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;

/**
 * Shared helpers for facade query-event bridge implementations.
 */
final class QueryBridgeSupport
{
    /**
     * @param array<string,Connection> $connections
     * @param array<int|string,mixed> $bindings
     * @return array{
     *   query:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   connection:string|null
     * }
     */
    public static function basePayload(string $sql, array $bindings, float $time, Connection $connection, array $connections): array
    {
        return [
            'query' => $sql,
            'bindings' => self::normalizeBindings($bindings),
            'time' => $time,
            'connection' => self::resolveConnectionName($connections, $connection),
        ];
    }

    /**
     * @param list<callable(array<string,mixed>):void> $listeners
     * @param callable(array<string,mixed>):void $appendQueryLog
     * @param array<string,mixed> $payload
     */
    public static function dispatchPayload(
        array $listeners,
        bool $loggingQueries,
        callable $appendQueryLog,
        array $payload,
    ): void {
        foreach ($listeners as $listener) {
            $listener($payload);
        }

        if ($loggingQueries) {
            $appendQueryLog($payload);
        }
    }

    /**
     * @param array<string,Connection> $connections
     * @param list<callable(array<string,mixed>):void> $listeners
     * @param callable(array<string,mixed>):void $appendQueryLog
     */
    public static function handleExecuted(
        QueryExecuted $event,
        array $connections,
        ?Profiler $profiler,
        ?Logger $logger,
        bool $loggingQueries,
        array $listeners,
        callable $appendQueryLog,
    ): void {
        $profilerEnabled = $profiler !== null && $profiler->isEnabled();
        $loggerEnabled = $logger !== null && $logger->isEnabled();

        if (!self::shouldHandle($loggingQueries, $profilerEnabled, $loggerEnabled, $listeners)) {
            return;
        }

        $payload = self::basePayload(
            $event->sql,
            $event->bindings,
            $event->time,
            $event->connection,
            $connections,
        ) + ['rows' => $event->rowsAffected];

        if ($profilerEnabled) {
            $profiler->finish($event->sql, $event->bindings);
        }

        if ($loggerEnabled) {
            $logger->query($event->sql, $event->bindings, $event->time);
        }

        self::dispatchPayload($listeners, $loggingQueries, $appendQueryLog, $payload);
    }

    /**
     * @param list<callable(array<string,mixed>):void> $listeners
     */
    public static function shouldHandle(
        bool $loggingQueries,
        bool $profilerEnabled,
        bool $loggerEnabled,
        array $listeners,
    ): bool {
        return $loggingQueries || $listeners !== [] || $profilerEnabled || $loggerEnabled;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return list<mixed>
     */
    private static function normalizeBindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            $normalized[] = $binding;
        }

        return $normalized;
    }

    /**
     * @param array<string,Connection> $connections
     */
    private static function resolveConnectionName(array $connections, Connection $connection): ?string
    {
        return array_find_key($connections, fn(Connection $candidate): bool => $candidate === $connection);
    }
}
