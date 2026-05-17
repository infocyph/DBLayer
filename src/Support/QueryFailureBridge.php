<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;

/**
 * Facade bridge for failed-query observability handling.
 */
final class QueryFailureBridge
{
    /**
     * @param array<string,Connection> $connections
     * @param list<callable(array<string,mixed>):void> $listeners
     * @param callable(array<string,mixed>):void $appendQueryLog
     */
    public static function handle(
        QueryFailed $event,
        array $connections,
        ?Profiler $profiler,
        ?Logger $logger,
        bool $loggingQueries,
        array $listeners,
        callable $appendQueryLog,
    ): void {
        $profilerEnabled = $profiler !== null && $profiler->isEnabled();
        $loggerEnabled = $logger !== null && $logger->isEnabled();

        if (!QueryBridgeSupport::shouldHandle($loggingQueries, $profilerEnabled, $loggerEnabled, $listeners)) {
            return;
        }

        if ($profilerEnabled) {
            $profiler->finish($event->sql, $event->bindings);
        }

        if ($loggerEnabled) {
            $logger->error(
                sprintf(
                    'Query failed [statement: %s] [fingerprint: %s] [attempts: %d]',
                    $event->statement,
                    $event->fingerprint,
                    $event->attempts,
                ),
                $event->exception,
            );
        }

        $payload = self::payload($event, $connections);

        QueryBridgeSupport::dispatchPayload($listeners, $loggingQueries, $appendQueryLog, $payload);
    }

    /**
     * @param array<string,Connection> $connections
     * @return array{
     *   query:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   connection:string|null,
     *   rows:int|null,
     *   error:string,
     *   statement:string,
     *   fingerprint:string,
     *   attempts:int,
     *   exception:string
     * }
     */
    private static function payload(QueryFailed $event, array $connections): array
    {
        return QueryBridgeSupport::basePayload(
            $event->sql,
            $event->bindings,
            $event->time,
            $event->connection,
            $connections,
        ) + [
            'rows' => null,
            'error' => $event->error,
            'statement' => $event->statement,
            'fingerprint' => $event->fingerprint,
            'attempts' => $event->attempts,
            'exception' => $event->exceptionClass,
        ];
    }
}
