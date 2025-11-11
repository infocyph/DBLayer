<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Async Connection Pool
 *
 * Manages pool of async database connections with:
 * - Connection recycling
 * - Health monitoring
 * - Load balancing
 * - Auto-scaling
 *
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class Pool
{
    /**
     * Default configuration
     */
    private const DEFAULTS = [
        'min' => 2,
        'max' => 10,
        'idle_timeout' => 60,
        'max_lifetime' => 3600,
        'wait_timeout' => 30,
    ];

    /**
     * Available connections
     */
    private array $available = [];

    /**
     * Busy connections
     */
    private array $busy = [];

    /**
     * Pool configuration
     */
    private array $config;
    /**
     * Pool of connections
     */
    private array $connections = [];

    /**
     * Connection factory
     */
    private \Closure $factory;

    /**
     * Pool statistics
     */
    private array $stats = [
        'created' => 0,
        'destroyed' => 0,
        'acquired' => 0,
        'released' => 0,
        'errors' => 0,
    ];

    /**
     * Create a new connection pool
     */
    public function __construct(callable $factory, array $config = [])
    {
        $this->factory = $factory(...);
        $this->config = array_merge(self::DEFAULTS, $config);

        $this->initializePool();
    }

    /**
     * Acquire a connection from the pool
     */
    public function acquire(): Promise
    {
        // Try to get an available connection
        if (!empty($this->available)) {
            $id = array_key_first($this->available);
            $connection = $this->available[$id];
            unset($this->available[$id]);

            $this->busy[$id] = [
                'connection' => $connection,
                'acquired_at' => microtime(true),
            ];

            $this->stats['acquired']++;

            return Promise::resolve($connection);
        }

        // Create new connection if under max
        if (count($this->connections) < $this->config['max']) {
            return $this->createConnection();
        }

        // Wait for available connection
        return $this->waitForConnection();
    }

    /**
     * Cleanup expired connections
     */
    public function cleanup(): Promise
    {
        $now = microtime(true);
        $promises = [];

        foreach ($this->available as $id => $data) {
            $idle = $now - $data['released_at'];

            if ($idle > $this->config['idle_timeout'] || $this->isExpired($id)) {
                $promises[] = $this->destroyConnection($data['connection']);
            }
        }

        // Ensure minimum connections
        while (count($this->connections) < $this->config['min']) {
            $promises[] = $this->createConnection();
        }

        return Promise::all($promises);
    }

    /**
     * Close all connections
     */
    public function close(): Promise
    {
        $promises = [];

        foreach ($this->connections as $id => $data) {
            $promises[] = $data['connection']->disconnect();
        }

        return Promise::all($promises)
            ->then(function () {
                $this->connections = [];
                $this->available = [];
                $this->busy = [];
                return true;
            });
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'total' => count($this->connections),
            'available' => count($this->available),
            'busy' => count($this->busy),
            'utilization' => $this->getUtilization(),
        ]);
    }

    /**
     * Release a connection back to the pool
     */
    public function release(AsyncConnection $connection): Promise
    {
        $id = spl_object_id($connection);

        if (!isset($this->busy[$id])) {
            return Promise::reject(new \RuntimeException('Connection not from this pool'));
        }

        unset($this->busy[$id]);

        // Check if connection is healthy
        if (!$connection->isConnected()) {
            return $this->destroyConnection($connection);
        }

        // Check lifetime
        if ($this->isExpired($id)) {
            return $this->destroyConnection($connection);
        }

        $this->available[$id] = [
            'connection' => $connection,
            'released_at' => microtime(true),
        ];

        $this->stats['released']++;

        return Promise::resolve(true);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'created' => 0,
            'destroyed' => 0,
            'acquired' => 0,
            'released' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Execute with automatic release
     */
    public function withConnection(callable $callback): Promise
    {
        return $this->acquire()
            ->then(function ($connection) use ($callback) {
                return Promise::resolve($callback($connection))
                    ->finally(fn () => $this->release($connection));
            });
    }

    /**
     * Create a new connection
     */
    private function createConnection(): Promise
    {
        $connection = ($this->factory)();

        if (!$connection instanceof AsyncConnection) {
            return Promise::reject(new \RuntimeException('Factory must return AsyncConnection'));
        }

        return $connection->connect()
            ->then(function () use ($connection) {
                $id = spl_object_id($connection);

                $this->connections[$id] = [
                    'connection' => $connection,
                    'created_at' => microtime(true),
                ];

                $this->busy[$id] = [
                    'connection' => $connection,
                    'acquired_at' => microtime(true),
                ];

                $this->stats['created']++;
                $this->stats['acquired']++;

                return $connection;
            })
            ->catch(function ($error) {
                $this->stats['errors']++;
                throw $error;
            });
    }

    /**
     * Destroy a connection
     */
    private function destroyConnection(AsyncConnection $connection): Promise
    {
        $id = spl_object_id($connection);

        unset($this->connections[$id]);
        unset($this->available[$id]);
        unset($this->busy[$id]);

        $this->stats['destroyed']++;

        return $connection->disconnect();
    }

    /**
     * Get pool utilization percentage
     */
    private function getUtilization(): float
    {
        if ($this->config['max'] === 0) {
            return 0;
        }

        return (count($this->busy) / $this->config['max']) * 100;
    }

    /**
     * Initialize pool with minimum connections
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->config['min']; $i++) {
            $this->createConnection()->wait();
        }
    }

    /**
     * Check if connection is expired
     */
    private function isExpired(int $id): bool
    {
        if (!isset($this->connections[$id])) {
            return true;
        }

        $age = microtime(true) - $this->connections[$id]['created_at'];
        return $age > $this->config['max_lifetime'];
    }

    /**
     * Wait for an available connection
     */
    private function waitForConnection(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            $startTime = microtime(true);
            $timeout = $this->config['wait_timeout'];

            $check = function () use (&$check, $resolve, $reject, $startTime, $timeout) {
                if (microtime(true) - $startTime > $timeout) {
                    $reject(ConnectionException::poolExhausted($this->config['max']));
                    return;
                }

                if (!empty($this->available)) {
                    $this->acquire()->then($resolve, $reject);
                    return;
                }

                // Check again after 10ms
                usleep(10000);
                $check();
            };

            $check();
        });
    }
}
