<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Rolled Back Event
 *
 * Dispatched when a transaction is rolled back.
 */
final class TransactionRolledBack
{
    public readonly Connection $connection;

    /**
     * Transaction duration in milliseconds.
     */
    public readonly float $duration;

    public function __construct(Connection $connection, float $duration = 0.0)
    {
        $this->connection = $connection;
        $this->duration   = $duration;
    }

    /**
     * @return array{connection:string,duration:float}
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection->getDriverName(),
            'duration'   => $this->duration,
        ];
    }
}
