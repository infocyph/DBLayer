<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Beginning Event
 *
 * Dispatched when a transaction is starting.
 *
 * @package Infocyph\DBLayer\Events\DatabaseEvents
 * @author Hasan
 */
class TransactionBeginning
{
    /**
     * Database connection
     */
    public Connection $connection;

    /**
     * Event timestamp
     */
    public float $time;

    /**
     * Create a new event instance
     */
    public function __construct(Connection $connection): void
    {
        $this->connection = $connection;
        $this->time = microtime(true);
    }

    /**
     * Get event data as array
     */
    public function toArray(): array
    {
        return [
          'connection' => $this->connection->getDriverName(),
          'time' => $this->time,
        ];
    }
}