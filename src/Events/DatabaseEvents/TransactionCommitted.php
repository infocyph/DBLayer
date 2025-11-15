<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Committed Event
 *
 * Dispatched when a transaction is committed.
 *
 * @package Infocyph\DBLayer\Events\DatabaseEvents
 * @author Hasan
 */
final class TransactionCommitted
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

    public function toArray(): array
    {
        return [
          'connection' => $this->connection->getDriverName(),
          'duration'   => $this->duration,
        ];
    }
}
