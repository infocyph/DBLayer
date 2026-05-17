<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;

final class QueryExecutedBridge
{
    /**
     * @param array<string,\Infocyph\DBLayer\Connection\Connection> $connections
     * @param list<callable(array<string,mixed>):void> $listeners
     * @param callable(array<string,mixed>):void $appendQueryLog
     */
    public static function handle(
        QueryExecuted $queryEvent,
        array $connections,
        ?Profiler $profiler,
        ?Logger $logger,
        bool $loggingQueries,
        array $listeners,
        callable $appendQueryLog,
    ): void {
        QueryBridgeSupport::handleExecuted(
            $queryEvent,
            $connections,
            $profiler,
            $logger,
            $loggingQueries,
            $listeners,
            $appendQueryLog,
        );
    }
}
