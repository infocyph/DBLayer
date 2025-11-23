<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Query Executed Event
 *
 * Dispatched after a query has been executed.
 */
final class QueryExecuted
{
    /**
     * @var array<int|string, mixed>
     */
    public readonly array $bindings;

    public readonly Connection $connection;

    /**
     * Rows affected (for INSERT/UPDATE/DELETE); null for SELECT etc.
     */
    public readonly ?int $rowsAffected;

    public readonly string $sql;

    /**
     * Execution time in milliseconds.
     */
    public readonly float $time;

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(
      string $sql,
      array $bindings,
      float $time,
      Connection $connection,
      ?int $rowsAffected = null
    ) {
        $this->sql          = $sql;
        $this->bindings     = $bindings;
        $this->time         = $time;
        $this->connection   = $connection;
        $this->rowsAffected = $rowsAffected;
    }

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
