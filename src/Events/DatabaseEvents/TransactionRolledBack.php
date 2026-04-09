<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Rolled Back Event
 *
 * Dispatched when a transaction is rolled back.
 */
final readonly class TransactionRolledBack
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
