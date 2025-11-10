<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

/**
 * Pooled Connection
 * 
 * Wraps an async connection for use in a connection pool.
 * Tracks connection state and lifecycle.
 * 
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class PooledConnection
{
    /**
     * The wrapped connection
     */
    protected AsyncConnection $connection;

    /**
     * The pool this connection belongs to
     */
    protected Pool $pool;

    /**
     * Connection creation time
     */
    protected float $createdAt;

    /**
     * Last used timestamp
     */
    protected float $lastUsedAt;

    /**
     * Whether connection is active
     */
    protected bool $active = true;

    /**
     * Create a new pooled connection
     */
    public function __construct(AsyncConnection $connection, Pool $pool)
    {
        $this->connection = $connection;
        $this->pool = $pool;
        $this->createdAt = microtime(true);
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Execute a query
     */
    public function query(string $sql, array $bindings = []): Promise
    {
        $this->updateLastUsed();
        return $this->connection->query($sql, $bindings);
    }

    /**
     * Execute multiple queries in parallel
     */
    public function parallel(array $queries): Promise
    {
        $this->updateLastUsed();
        return $this->connection->parallel($queries);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): Promise
    {
        $this->updateLastUsed();
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): Promise
    {
        $this->updateLastUsed();
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollBack(): Promise
    {
        $this->updateLastUsed();
        return $this->connection->rollBack();
    }

    /**
     * Execute transaction
     */
    public function transaction(callable $callback): Promise
    {
        $this->updateLastUsed();
        return $this->connection->transaction($callback);
    }

    /**
     * Release connection back to pool
     */
    public function release(): void
    {
        $this->pool->release($this);
    }

    /**
     * Disconnect and remove from pool
     */
    public function disconnect(): Promise
    {
        $this->active = false;
        return $this->connection->disconnect();
    }

    /**
     * Destroy the connection
     */
    public function destroy(): void
    {
        $this->active = false;
        $this->connection->disconnect();
    }

    /**
     * Reset connection state
     */
    public function resetState(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Update last used timestamp
     */
    protected function updateLastUsed(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Get creation time
     */
    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * Get last used time
     */
    public function getLastUsedAt(): float
    {
        return $this->lastUsedAt;
    }

    /**
     * Check if connection is active
     */
    public function isActive(): bool
    {
        return $this->active && $this->connection->isConnected();
    }

    /**
     * Get the underlying connection
     */
    public function getConnection(): AsyncConnection
    {
        return $this->connection;
    }

    /**
     * Ping the connection
     */
    public function ping(): Promise
    {
        $this->updateLastUsed();
        return $this->connection->ping();
    }

    /**
     * Get connection age in seconds
     */
    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Get idle time in seconds
     */
    public function getIdleTime(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }
}
