<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Connection Pool
 * 
 * Manages a pool of async database connections.
 * Provides connection reuse and lifecycle management.
 * 
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class Pool
{
    /**
     * Pool configuration
     */
    protected array $config;

    /**
     * Available connections
     */
    protected array $available = [];

    /**
     * Active connections
     */
    protected array $active = [];

    /**
     * Connection factory
     */
    protected $factory;

    /**
     * Pool statistics
     */
    protected array $stats = [
        'created' => 0,
        'acquired' => 0,
        'released' => 0,
        'destroyed' => 0,
    ];

    /**
     * Create a new connection pool
     */
    public function __construct(callable $factory, array $config = [])
    {
        $this->factory = $factory;
        $this->config = array_merge([
            'min' => 2,
            'max' => 10,
            'idle_timeout' => 60,
            'max_lifetime' => 3600,
            'acquire_timeout' => 5,
        ], $config);

        // Pre-create minimum connections
        $this->initializePool();
    }

    /**
     * Initialize pool with minimum connections
     */
    protected function initializePool(): void
    {
        for ($i = 0; $i < $this->config['min']; $i++) {
            $this->createConnection();
        }
    }

    /**
     * Create a new connection
     */
    protected function createConnection(): PooledConnection
    {
        $connection = call_user_func($this->factory);
        $pooled = new PooledConnection($connection, $this);
        
        $this->available[] = $pooled;
        $this->stats['created']++;

        return $pooled;
    }

    /**
     * Acquire a connection from the pool
     */
    public function acquire(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            $startTime = microtime(true);

            while (true) {
                // Check for available connection
                if (!empty($this->available)) {
                    $connection = array_shift($this->available);
                    
                    // Check if connection is still valid
                    if ($this->isConnectionValid($connection)) {
                        $this->active[] = $connection;
                        $this->stats['acquired']++;
                        $resolve($connection);
                        return;
                    }

                    // Connection expired, destroy it
                    $this->destroyConnection($connection);
                    continue;
                }

                // Try to create new connection if under max
                if ($this->getTotalCount() < $this->config['max']) {
                    try {
                        $connection = $this->createConnection();
                        $this->active[] = $connection;
                        $this->stats['acquired']++;
                        $resolve($connection);
                        return;
                    } catch (\Throwable $e) {
                        $reject($e);
                        return;
                    }
                }

                // Check timeout
                $elapsed = microtime(true) - $startTime;
                if ($elapsed >= $this->config['acquire_timeout']) {
                    $reject(ConnectionException::timeout((int) $this->config['acquire_timeout']));
                    return;
                }

                // Wait a bit before retry
                usleep(10000); // 10ms
            }
        });
    }

    /**
     * Release a connection back to the pool
     */
    public function release(PooledConnection $connection): void
    {
        // Remove from active
        $key = array_search($connection, $this->active, true);
        if ($key !== false) {
            unset($this->active[$key]);
            $this->active = array_values($this->active);
        }

        // Check if connection is still valid
        if ($this->isConnectionValid($connection)) {
            $connection->resetState();
            $this->available[] = $connection;
            $this->stats['released']++;
        } else {
            $this->destroyConnection($connection);
        }

        // Maintain minimum connections
        while (count($this->available) < $this->config['min'] && 
               $this->getTotalCount() < $this->config['max']) {
            $this->createConnection();
        }
    }

    /**
     * Check if connection is valid
     */
    protected function isConnectionValid(PooledConnection $connection): bool
    {
        $age = microtime(true) - $connection->getCreatedAt();
        
        if ($age > $this->config['max_lifetime']) {
            return false;
        }

        $idleTime = microtime(true) - $connection->getLastUsedAt();
        
        if ($idleTime > $this->config['idle_timeout']) {
            return false;
        }

        return $connection->isActive();
    }

    /**
     * Destroy a connection
     */
    protected function destroyConnection(PooledConnection $connection): void
    {
        $connection->destroy();
        $this->stats['destroyed']++;
    }

    /**
     * Get total connection count
     */
    public function getTotalCount(): int
    {
        return count($this->available) + count($this->active);
    }

    /**
     * Get available connection count
     */
    public function getAvailableCount(): int
    {
        return count($this->available);
    }

    /**
     * Get active connection count
     */
    public function getActiveCount(): int
    {
        return count($this->active);
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'total' => $this->getTotalCount(),
            'available' => $this->getAvailableCount(),
            'active' => $this->getActiveCount(),
        ]);
    }

    /**
     * Close all connections and destroy pool
     */
    public function close(): Promise
    {
        $promises = [];

        foreach (array_merge($this->available, $this->active) as $connection) {
            $promises[] = $connection->disconnect();
        }

        $this->available = [];
        $this->active = [];

        return Promise::all($promises);
    }

    /**
     * Run maintenance tasks
     */
    public function maintain(): void
    {
        // Remove expired connections from available pool
        foreach ($this->available as $key => $connection) {
            if (!$this->isConnectionValid($connection)) {
                unset($this->available[$key]);
                $this->destroyConnection($connection);
            }
        }

        $this->available = array_values($this->available);

        // Ensure minimum connections
        while (count($this->available) < $this->config['min'] && 
               $this->getTotalCount() < $this->config['max']) {
            $this->createConnection();
        }
    }
}
