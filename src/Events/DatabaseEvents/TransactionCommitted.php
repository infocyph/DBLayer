<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Committed Event
 *
 * Dispatched when a transaction is committed.
 */
final readonly class TransactionCommitted
{
    public function __construct(
        public Connection $connection,
        /**
         * Transaction duration in milliseconds.
         */
        public float $duration = 0.0,
    ) {}

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
