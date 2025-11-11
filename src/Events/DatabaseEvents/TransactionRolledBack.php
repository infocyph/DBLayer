<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Rolled Back Event
 *
 * Dispatched when a transaction is rolled back.
 *
 * @package Infocyph\DBLayer\Events\DatabaseEvents
 * @author Hasan
 */
class TransactionRolledBack
{
    /**
     * Database connection
     */
    public Connection $connection;

    /**
     * Transaction duration
     */
    public float $duration;

    /**
     * Create a new event instance
     */
    public function __construct(Connection $connection, float $duration = 0)
    {
        $this->connection = $connection;
        $this->duration = $duration;
    }

    /**
     * Get event data as array
     */
    public function toArray(): array
    {
        return [
          'connection' => $this->connection->getDriverName(),
          'duration' => $this->duration,
        ];
    }
}
