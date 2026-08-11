<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Support\SqlFingerprint;
use Throwable;

/**
 * Query Failed Event
 *
 * Dispatched after a query attempt fails permanently.
 */
final readonly class QueryFailed
{
    public string $error;

    public string $exceptionClass;

    public string $fingerprint;

    public string $statement;

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        /**
         * Execution time in milliseconds.
         */
        public float $time,
        public Connection $connection,
        public Throwable $exception,
        /**
         * Retry attempts consumed before failure.
         */
        public int $attempts = 1,
    ) {
        $this->error = $exception->getMessage();
        $this->exceptionClass = $exception::class;
        $this->statement = SqlFingerprint::statement($sql);
        $this->fingerprint = SqlFingerprint::hash($sql);
    }

    /**
     * Get event data as array.
     *
     * @return array{
     *   sql:string,
     *   bindings:array<int|string,mixed>,
     *   time:float,
     *   connection:string,
     *   driver:string,
     *   attempts:int,
     *   error:string,
     *   exception:string,
     *   statement:string,
     *   fingerprint:string
     * }
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'time' => $this->time,
            'connection' => $this->connection->getName(),
            'driver' => $this->connection->getDriverName(),
            'attempts' => $this->attempts,
            'error' => $this->error,
            'exception' => $this->exceptionClass,
            'statement' => $this->statement,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
