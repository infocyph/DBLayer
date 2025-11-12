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
class QueryExecuting
{
    /**
     * Query bindings
     */
    public array $bindings;

    /**
     * Database connection
     */
    public Connection $connection;
    /**
     * SQL query
     */
    public string $sql;

    /**
     * Event timestamp
     */
    public float $time;

    /**
     * Create a new event instance
     */
    public function __construct(string $sql, array $bindings, Connection $connection): void
    {
        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->connection = $connection;
        $this->time = microtime(true);
    }

    /**
     * Get event data as array
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'connection' => $this->connection->getDriverName(),
            'time' => $this->time,
        ];
    }
}