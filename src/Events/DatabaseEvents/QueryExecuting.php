<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Query Executing Event
 *
 * Dispatched before a query is executed.
 */
final readonly class QueryExecuting
{
    /**
     * Event timestamp (microtime(true)).
     */
    public float $time;

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public Connection $connection,
        ?float $time = null,
    ) {
        $this->time       = $time ?? microtime(true);
    }

    /**
     * Get event data as array.
     *
     * @return array{
     *   sql:string,
     *   bindings:array<int|string,mixed>,
     *   connection:string,
     *   time:float
     * }
     */
    public function toArray(): array
    {
        return [
            'sql'        => $this->sql,
            'bindings'   => $this->bindings,
            'connection' => $this->connection->getDriverName(),
            'time'       => $this->time,
        ];
    }
}
