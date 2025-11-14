<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Connection Pool Manager
 *
 * Manages a pool of database connections for efficient reuse:
 * - Connection pooling and recycling
 * - Maximum pool size limits
 * - Connection health checking
 * - Idle connection timeout
 * - Pool statistics
 *
 * @package Infocyph\DBLayer\Connection
 * @author Hasan
 */
class Pool
{
    /**
     * Default pool configuration
     */
    private const DEFAULTS = [
        'min_connections' => 1,
        'max_connections' => 10,
        'idle_timeout' => 60,
        'max_lifetime' => 3600,
        'health_check_interval' => 30,
    ];

    /**
     * Connection configurations
     */
    private array $configs = [];
    /**
     * Pool of active connections
     */
    private array $connections = [];

    /**
     * Pool of idle connections
     */
    private array $idle = [];

    /**
     * Last health check time
     */
    private ?float $lastHealthCheck = null;

    /**
     * Pool configuration
     */
    private array $poolConfig;

    /**
     * Pool statistics
     */
    private array $stats = [
        'created' => 0,
        'reused' => 0,
        'closed' => 0,
        'health_checks' => 0,
        'health_failures' => 0,
    ];

    /**
     * Create a new connection pool
     */
    public function __construct(array $poolConfig = [])
    {
        $this->poolConfig = array_merge(self::DEFAULTS, $poolConfig);
    }

    /**
     * Add a connection configuration to the pool
     */
    public function addConfig(string $name, ConnectionConfig $config): void
    {
        $this->configs[$name] = $config;
    }

    /**
     * Close all connections in the pool
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $name => $connections) {
            foreach ($connections as $data) {
                $data['connection']->disconnect();
            }
        }

        foreach ($this->idle as $name => $connections) {
            foreach ($connections as $data) {
                $data['connection']->disconnect();
            }
        }

        $this->connections = [];
        $this->idle = [];
    }

    /**
     * Get pool configuration
     */
    public function getConfig(): array
    {
        return $this->poolConfig;
    }

    /**
     * Get a connection from the pool
     */
    public function getConnection(string $name = 'default'): Connection
    {
        if (!isset($this->configs[$name])) {
            throw ConnectionException::configNotFound($name);
        }

        // Try to reuse an idle connection
        if ($connection = $this->getIdleConnection($name)) {
            $this->stats['reused']++;
            return $connection;
        }

        // Check if we can create a new connection
        if ($this->canCreateConnection($name)) {
            return $this->createConnection($name);
        }

        // Wait for an available connection
        throw ConnectionException::poolExhausted($this->poolConfig['max_connections']);
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        $activeCount = 0;
        $idleCount   = 0;

        foreach ($this->connections as $connections) {
            $activeCount += count($connections);
        }

        foreach ($this->idle as $connections) {
            $idleCount += count($connections);
        }

        $max = (int) $this->poolConfig['max_connections'];
        $utilization = $max > 0 ? ($activeCount / $max) * 100 : 0.0;

        return array_merge($this->stats, [
          'active_connections' => $activeCount,
          'idle_connections'   => $idleCount,
          'total_connections'  => $activeCount + $idleCount,
          'max_connections'    => $max,
          'pool_utilization'   => $utilization,
        ]);
    }


    /**
     * Perform health check on all connections
     */
    public function healthCheck(): void
    {
        $now = microtime(true);

        // Check if we need to perform health check
        if ($this->lastHealthCheck !== null) {
            $elapsed = $now - $this->lastHealthCheck;
            if ($elapsed < $this->poolConfig['health_check_interval']) {
                return;
            }
        }

        $this->lastHealthCheck = $now;
        $this->stats['health_checks']++;

        // Check all active connections
        foreach ($this->connections as $name => $connections) {
            foreach ($connections as $connectionId => $data) {
                if (!$data['connection']->isHealthy()) {
                    $this->removeConnection($name, $data['connection']);
                    $this->stats['health_failures']++;
                }
            }
        }

        // Check and remove stale idle connections
        $this->removeStaleIdleConnections();
    }

    /**
     * Release a connection back to the pool
     */
    public function releaseConnection(string $name, Connection $connection): void
    {
        // Check if connection is healthy
        if (!$connection->isHealthy()) {
            $this->removeConnection($name, $connection);
            return;
        }

        // Check connection lifetime
        $connectionId = spl_object_id($connection);
        if (isset($this->connections[$name][$connectionId])) {
            $createdAt = $this->connections[$name][$connectionId]['created_at'];
            $lifetime = microtime(true) - $createdAt;

            if ($lifetime > $this->poolConfig['max_lifetime']) {
                $this->removeConnection($name, $connection);
                return;
            }
        }

        // Add to idle pool
        if (!isset($this->idle[$name])) {
            $this->idle[$name] = [];
        }

        $this->idle[$name][spl_object_id($connection)] = [
            'connection' => $connection,
            'idle_since' => microtime(true),
        ];
    }

    /**
     * Remove a connection from the pool
     */
    public function removeConnection(string $name, Connection $connection): void
    {
        $connectionId = spl_object_id($connection);

        // Remove from active connections
        if (isset($this->connections[$name][$connectionId])) {
            unset($this->connections[$name][$connectionId]);
        }

        // Remove from idle connections
        if (isset($this->idle[$name][$connectionId])) {
            unset($this->idle[$name][$connectionId]);
        }

        // Disconnect
        $connection->disconnect();
        $this->stats['closed']++;
    }

    /**
     * Reset pool statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'created' => 0,
            'reused' => 0,
            'closed' => 0,
            'health_checks' => 0,
            'health_failures' => 0,
        ];
    }

    /**
     * Check if we can create a new connection
     */
    private function canCreateConnection(string $name): bool
    {
        $totalConnections = 0;

        foreach ($this->connections as $connections) {
            $totalConnections += count($connections);
        }

        foreach ($this->idle as $connections) {
            $totalConnections += count($connections);
        }

        return $totalConnections < $this->poolConfig['max_connections'];
    }

    /**
     * Create a new connection
     */
    private function createConnection(string $name): Connection
    {
        $config = $this->configs[$name];
        $connection = new Connection($config);

        // Register in active connections
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = [];
        }

        $this->connections[$name][spl_object_id($connection)] = [
            'connection' => $connection,
            'created_at' => microtime(true),
        ];

        $this->stats['created']++;

        return $connection;
    }

    /**
     * Get an idle connection from the pool
     */
    private function getIdleConnection(string $name): ?Connection
    {
        if (!isset($this->idle[$name]) || empty($this->idle[$name])) {
            return null;
        }

        // Get the first idle connection
        $connectionId = array_key_first($this->idle[$name]);
        $data = $this->idle[$name][$connectionId];

        // Check idle timeout
        $idleTime = microtime(true) - $data['idle_since'];
        if ($idleTime > $this->poolConfig['idle_timeout']) {
            $this->removeConnection($name, $data['connection']);
            return $this->getIdleConnection($name); // Try next one
        }

        // Remove from idle pool
        unset($this->idle[$name][$connectionId]);

        // Check health before returning
        if (!$data['connection']->isHealthy()) {
            $this->removeConnection($name, $data['connection']);
            return $this->getIdleConnection($name); // Try next one
        }

        return $data['connection'];
    }

    /**
     * Remove stale idle connections
     */
    private function removeStaleIdleConnections(): void
    {
        $now = microtime(true);

        foreach ($this->idle as $name => $connections) {
            foreach ($connections as $connectionId => $data) {
                $idleTime = $now - $data['idle_since'];

                if ($idleTime > $this->poolConfig['idle_timeout']) {
                    $this->removeConnection($name, $data['connection']);
                }
            }
        }
    }
}
