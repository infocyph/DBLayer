<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Closure;
use Infocyph\DBLayer\Connection\Connection;

/**
 * Context shared by a seed tree running inside one transaction boundary.
 */
final readonly class SeedContext
{
    /**
     * @param Closure(iterable<mixed>):int $caller
     */
    public function __construct(
        private Connection $connection,
        private Closure $caller,
    ) {}

    /**
     * Execute additional seed definitions in the current seed transaction.
     *
     * @param iterable<mixed> $seeders
     * @return int number of executed child seeders
     */
    public function call(iterable $seeders): int
    {
        return ($this->caller)($seeders);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }
}
