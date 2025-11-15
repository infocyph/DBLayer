<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Query Executing Event
 *
 * Dispatched before a query is executed.
 *
 * @package Infocyph\DBLayer\Events\DatabaseEvents
 * @author Hasan
 */
final class QueryExecuting
{
    public readonly string $sql;

    /** @var array<int|string, mixed> */
    public readonly array $bindings;

    public readonly Connection $connection;

    /**
     * Event timestamp (microtime(true)).
     */
    public readonly float $time;

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(string $sql, array $bindings, Connection $connection, ?float $time = null)
    {
        $this->sql        = $sql;
        $this->bindings   = $bindings;
        $this->connection = $connection;
        $this->time       = $time ?? microtime(true);
    }

    /**
     * Get event data as array.
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
