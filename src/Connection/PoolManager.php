<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * DI-friendly facade over the low-level Pool.
 *
 * checkout() is the concurrency-safe ownership API for persistent runtimes.
 * get()/release() remain as a legacy convenience for strictly scoped callers.
 */
final class PoolManager
{
    /**
     * Active tokenized checkouts keyed by Connection object id.
     *
     * @var array<int,array{name:string,token:int}>
     */
    private array $activeLeases = [];

    /**
     * Legacy get()/release() ownership keyed by Connection object id.
     *
     * @var array<int,string>
     */
    private array $connectionNames = [];

    private int $leaseSequence = 0;

    public function __construct(
        private readonly Pool $pool,
    ) {}

    /**
     * Checkout a pooled connection with an exclusive ownership token.
     */
    public function checkout(string $name = 'default'): ConnectionLease
    {
        $connection = $this->pool->getConnection($name);
        $id = spl_object_id($connection);

        if (isset($this->activeLeases[$id]) || isset($this->connectionNames[$id])) {
            throw ConnectionException::invalidConfiguration(
                'Pool returned a connection that is already owned by this pool manager.',
            );
        }

        $token = ++$this->leaseSequence;
        $this->activeLeases[$id] = [
            'name' => $name,
            'token' => $token,
        ];

        return new ConnectionLease($this, $connection, $name, $token);
    }

    /**
     * Legacy direct checkout.
     *
     * Prefer checkout() in persistent or interleaved execution models because a
     * bare Connection reference cannot prove which reuse generation owns it.
     */
    public function get(string $name = 'default'): Connection
    {
        $connection = $this->pool->getConnection($name);
        $id = spl_object_id($connection);

        if (isset($this->activeLeases[$id]) || isset($this->connectionNames[$id])) {
            throw ConnectionException::invalidConfiguration(
                'Pool returned a connection that is already owned by this pool manager.',
            );
        }

        $this->connectionNames[$id] = $name;

        return $connection;
    }

    public function getPool(): Pool
    {
        return $this->pool;
    }

    /**
     * Release a legacy get() checkout.
     */
    public function release(Connection $connection, ?string $name = null): void
    {
        $id = spl_object_id($connection);

        if (isset($this->activeLeases[$id])) {
            throw ConnectionException::invalidConfiguration(
                'Cannot release a tokenized checkout without its active connection lease.',
            );
        }

        $ownedName = $this->connectionNames[$id] ?? null;
        if ($ownedName === null) {
            throw ConnectionException::invalidConfiguration(
                'Cannot release a connection that was not checked out by this pool manager.',
            );
        }

        if ($name !== null && $name !== $ownedName) {
            throw ConnectionException::invalidConfiguration(
                sprintf('Connection was checked out from pool [%s], not [%s].', $ownedName, $name),
            );
        }

        unset($this->connectionNames[$id]);
        $this->pool->releaseConnection($ownedName, $connection);
    }

    /**
     * Release one tokenized checkout.
     *
     * @internal Called by ConnectionLease.
     */
    public function releaseLease(Connection $connection, string $name, int $token): void
    {
        $id = spl_object_id($connection);
        $ownership = $this->activeLeases[$id] ?? null;

        if ($ownership === null || $ownership['name'] !== $name || $ownership['token'] !== $token) {
            throw ConnectionException::invalidConfiguration(
                'Connection lease is stale or does not own the active pooled checkout.',
            );
        }

        unset($this->activeLeases[$id]);
        $this->pool->releaseConnection($name, $connection);
    }

    /**
     * Execute a callback using an exclusive pooled connection lease.
     *
     * @template T
     * @param callable(Connection):T $callback
     * @return T
     */
    public function using(string $name, callable $callback): mixed
    {
        $lease = $this->checkout($name);

        try {
            return $callback($lease->connection());
        } finally {
            $lease->release();
        }
    }
}
