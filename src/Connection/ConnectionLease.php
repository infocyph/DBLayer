<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Exclusive ownership token for one PoolManager checkout.
 *
 * A lease prevents a stale reference to a previously checked-out Connection
 * from releasing the same pooled object after it has been handed to another
 * execution.
 */
final class ConnectionLease
{
    private bool $released = false;

    /** @internal Created by PoolManager only. */
    public function __construct(
        private readonly PoolManager $manager,
        private readonly Connection $connection,
        private readonly string $name,
        private readonly int $token,
    ) {}

    public function connection(): Connection
    {
        if ($this->released) {
            throw ConnectionException::invalidConfiguration('Cannot access a released connection lease.');
        }

        return $this->connection;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function release(): void
    {
        if ($this->released) {
            throw ConnectionException::invalidConfiguration('Connection lease has already been released.');
        }

        $this->manager->releaseLease($this->connection, $this->name, $this->token);
        $this->released = true;
    }
}
