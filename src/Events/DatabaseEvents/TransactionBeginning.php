<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Beginning Event
 *
 * Dispatched when a transaction is starting.
 */
final class TransactionBeginning
{
    public readonly Connection $connection;

    /**
     * Event timestamp (microtime(true)).
     */
    public readonly float $time;

    public function __construct(Connection $connection, ?float $time = null)
    {
        $this->connection = $connection;
        $this->time       = $time ?? microtime(true);
    }

    /**
     * @return array{connection:string,time:float}
     */
    public function toArray(): array
    {
        return [
          'connection' => $this->connection->getDriverName(),
          'time'       => $this->time,
        ];
    }
}
