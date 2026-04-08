<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

/**
 * PoolManager
 *
 * Tiny DI-friendly facade over the low-level Pool:
 * - Tracks which connection name a Connection came from
 * - Lets you get / release safely without passing the name back
 * - Provides a simple "using" helper for scoped usage
 *
 * Typical DI wiring:
 *  - Register Pool as a shared service
 *  - Register PoolManager with Pool injected
 *  - Repositories / services depend on PoolManager
 */
final class PoolManager
{
    /**
     * Map of connection object id → pool name.
     *
     * @var array<int,string>
     */
    private array $connectionNames = [];
    /**
     * Underlying pool instance.
     */
    private Pool $pool;

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Get a pooled connection for the given name.
     *
     * This will:
     *  - Ask the Pool for a Connection
     *  - Remember which name it came from for later release()
     */
    public function get(string $name = 'default'): Connection
    {
        $connection = $this->pool->getConnection($name);

        $this->connectionNames[spl_object_id($connection)] = $name;

        return $connection;
    }

    /**
     * Expose the underlying Pool for advanced operations (stats, config).
     */
    public function getPool(): Pool
    {
        return $this->pool;
    }

    /**
     * Release a connection back into the pool.
     *
     * If the name is not provided, it will be inferred from the
     * internal map. If the connection was not obtained via this
     * PoolManager, the call becomes a no-op.
     */
    public function release(Connection $connection, ?string $name = null): void
    {
        $id = spl_object_id($connection);

        if ($name === null) {
            $name = $this->connectionNames[$id] ?? null;
        }

        if ($name === null) {
            // Unknown to this manager; nothing to release.
            return;
        }

        unset($this->connectionNames[$id]);

        $this->pool->releaseConnection($name, $connection);
    }

    /**
     * Execute a callback using a pooled connection.
     *
     * Usage:
     *   $result = $poolManager->using('default', function (Connection $conn) {
     *       return $conn->select('SELECT 1');
     *   });
     *
     * Connection is always released back to the pool.
     *
     * @template T
     * @param  callable(Connection):T  $callback
     * @return T
     */
    public function using(string $name, callable $callback): mixed
    {
        $connection = $this->get($name);

        try {
            return $callback($connection);
        } finally {
            $this->release($connection, $name);
        }
    }
}
