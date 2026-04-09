<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Query Executed Event
 *
 * Dispatched after a query has been executed.
 */
final readonly class QueryExecuted
{
    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        /**
         * Execution time in milliseconds.
         */
        public float $time,
        public Connection $connection,
        /**
         * Rows affected (for INSERT/UPDATE/DELETE); null for SELECT etc.
         */
        public ?int $rowsAffected = null,
    ) {}

    /**
     * Get event data as array.
     *
     * @return array{
     *   sql:string,
     *   bindings:array<int|string,mixed>,
     *   time:float,
     *   connection:string,
     *   rows_affected:int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'sql'           => $this->sql,
            'bindings'      => $this->bindings,
            'time'          => $this->time,
            'connection'    => $this->connection->getDriverName(),
            'rows_affected' => $this->rowsAffected,
        ];
    }
}
