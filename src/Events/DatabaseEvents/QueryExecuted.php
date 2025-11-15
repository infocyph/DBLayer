<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Query Executed Event
 *
 * Dispatched after a query has been executed.
 *
 * @package Infocyph\DBLayer\Events\DatabaseEvents
 * @author Hasan
 */
final class QueryExecuted
{
    public readonly string $sql;

    /** @var array<int|string, mixed> */
    public readonly array $bindings;

    /**
     * Execution time in milliseconds.
     */
    public readonly float $time;

    public readonly Connection $connection;

    /**
     * Rows affected (for INSERT/UPDATE/DELETE); null for SELECT etc.
     */
    public readonly ?int $rowsAffected;

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
