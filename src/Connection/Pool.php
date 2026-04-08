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
 * - Max lifetime per connection
 * - Pool statistics
 */
final class Pool
{
    /**
     * Default pool configuration.
     *
     * @var array{
     *   min_connections:int,
     *   max_connections:int,
     *   idle_timeout:int,
     *   max_lifetime:int,
     *   health_check_interval:int
     * }
     */
    private const DEFAULTS = [
      'min_connections'       => 1,
      'max_connections'       => 10,
      'idle_timeout'          => 60,
      'max_lifetime'          => 3_600,
      'health_check_interval' => 30,
    ];

    /**
     * Max number of active connections to probe per health-check run.
     */
    private const HEALTH_CHECK_BATCH_SIZE = 5;

    /**
     * Connection configurations.
     *
     * @var array<string,ConnectionConfig>
     */
    private array $configs = [];

    /**
     * Pool of known connections (active + idle).
     *
     * @var array<string,array<int,array{connection:Connection,created_at:float}>>
     */
    private array $connections = [];

    /**
     * Rolling cursor used to probe active connections in batches.
     */
    private int $healthCursor = 0;

    /**
     * Pool of idle connections (subset of $connections).
     *
     * @var array<string,array<int,array{connection:Connection,idle_since:float}>>
     */
    private array $idle = [];

    /**
     * Last health check time.
     */
    private ?float $lastHealthCheck = null;

    /**
     * Pool configuration.
     *
     * @var array{
     *   min_connections:int,
     *   max_connections:int,
     *   idle_timeout:int,
     *   max_lifetime:int,
     *   health_check_interval:int
     * }
     */
    private array $poolConfig;

    /**
     * Pool statistics.
     *
     * @var array{
     *   created:int,
     *   reused:int,
     *   closed:int,
     *   health_checks:int,
     *   health_failures:int
     * }
     */
    private array $stats = [
      'created'         => 0,
      'reused'          => 0,
      'closed'          => 0,
      'health_checks'   => 0,
      'health_failures' => 0,
    ];

    /**
     * Create a new connection pool.
     *
     * @param  array<string,int>  $poolConfig
     */
    public function __construct(array $poolConfig = [])
    {
        $merged = array_merge(self::DEFAULTS, $poolConfig);

        $this->poolConfig = [
          'min_connections'       => (int) $merged['min_connections'],
          'max_connections'       => (int) $merged['max_connections'],
          'idle_timeout'          => (int) $merged['idle_timeout'],
          'max_lifetime'          => (int) $merged['max_lifetime'],
          'health_check_interval' => (int) $merged['health_check_interval'],
        ];
    }

    /**
     * Add a connection configuration to the pool.
     */
    public function addConfig(string $name, ConnectionConfig $config): void
    {
        $this->configs[$name] = $config;
    }

    /**
     * Close all connections in the pool.
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $connections) {
            foreach ($connections as $data) {
                $data['connection']->disconnect();
            }
        }

        foreach ($this->idle as $connections) {
            foreach ($connections as $data) {
                $data['connection']->disconnect();
            }
        }

        $this->connections = [];
        $this->idle        = [];
    }

    /**
     * Get pool configuration.
     *
     * @return array<string,int>
     */
    public function getConfig(): array
    {
        return $this->poolConfig;
    }

    /**
     * Get a connection from the pool.
     */
    public function getConnection(string $name = 'default'): Connection
    {
        if (! isset($this->configs[$name])) {
            throw ConnectionException::configNotFound($name);
        }

        // Try to reuse an idle connection.
        $connection = $this->getIdleConnection($name);
        if ($connection !== null) {
            $this->stats['reused']++;

            return $connection;
        }

        // Check if we can create a new connection.
        if ($this->canCreateConnection()) {
            return $this->createConnection($name);
        }

        // Pool is exhausted.
        throw ConnectionException::poolExhausted($this->poolConfig['max_connections']);
    }

    /**
     * Get pool statistics.
     *
     * @return array<string,float|int>
     */
    public function getStats(): array
    {
        $totalConnections = 0;
        $idleCount        = 0;

        foreach ($this->connections as $connections) {
            $totalConnections += count($connections);
        }

        foreach ($this->idle as $connections) {
            $idleCount += count($connections);
        }

        $activeCount = max(0, $totalConnections - $idleCount);
        $max         = $this->poolConfig['max_connections'];
        $utilization = $max > 0 ? ($activeCount / $max) * 100.0 : 0.0;

        return [
          'created'            => $this->stats['created'],
          'reused'             => $this->stats['reused'],
          'closed'             => $this->stats['closed'],
          'health_checks'      => $this->stats['health_checks'],
          'health_failures'    => $this->stats['health_failures'],
          'active_connections' => $activeCount,
          'idle_connections'   => $idleCount,
          'total_connections'  => $totalConnections,
          'max_connections'    => $max,
          'pool_utilization'   => $utilization,
        ];
    }

    /**
     * Perform health check on all connections.
     *
     * Uses Connection::isHealthy(), which delegates to HealthCheck when attached.
     */
    public function healthCheck(): void
    {
        $now = microtime(true);

        // Check if we need to perform health check.
        if ($this->lastHealthCheck !== null) {
            $elapsed = $now - $this->lastHealthCheck;

            if ($elapsed < $this->poolConfig['health_check_interval']) {
                return;
            }
        }

        $this->lastHealthCheck = $now;
        $this->stats['health_checks']++;

        // Probe active connections in bounded batches to avoid full scans.
        $candidates = [];

        foreach ($this->connections as $name => $connections) {
            foreach ($connections as $data) {
                $candidates[] = [
                  'name'       => $name,
                  'connection' => $data['connection'],
                ];
            }
        }

        $total = \count($candidates);

        if ($total > 0) {
            $batchSize = \min(self::HEALTH_CHECK_BATCH_SIZE, $total);

            for ($i = 0; $i < $batchSize; $i++) {
                $index = ($this->healthCursor + $i) % $total;
                $item  = $candidates[$index];

                if (! $item['connection']->isHealthy()) {
                    $this->removeConnection($item['name'], $item['connection']);
                    $this->stats['health_failures']++;
                }
            }

            $this->healthCursor = ($this->healthCursor + $batchSize) % $total;
        } else {
            $this->healthCursor = 0;
        }

        // Remove stale idle connections.
        $this->removeStaleIdleConnections();
    }

    /**
     * Release a connection back to the pool.
     */
    public function releaseConnection(string $name, Connection $connection): void
    {
        // Check if connection is healthy.
        if (! $connection->isHealthy()) {
            $this->removeConnection($name, $connection);

            return;
        }

        // Check connection lifetime.
        $connectionId = spl_object_id($connection);

        if (isset($this->connections[$name][$connectionId])) {
            $createdAt = $this->connections[$name][$connectionId]['created_at'];
            $lifetime  = microtime(true) - $createdAt;

            if ($lifetime > $this->poolConfig['max_lifetime']) {
                $this->removeConnection($name, $connection);

                return;
            }
        }

        // Add to idle pool.
        if (! isset($this->idle[$name])) {
            $this->idle[$name] = [];
        }

        $this->idle[$name][$connectionId] = [
          'connection' => $connection,
          'idle_since' => microtime(true),
        ];
    }

    /**
     * Remove a connection from the pool.
     */
    public function removeConnection(string $name, Connection $connection): void
    {
        $connectionId = spl_object_id($connection);

        // Remove from known connections.
        if (isset($this->connections[$name][$connectionId])) {
            unset($this->connections[$name][$connectionId]);
        }

        // Remove from idle connections.
        if (isset($this->idle[$name][$connectionId])) {
            unset($this->idle[$name][$connectionId]);
        }

        // Disconnect underlying connection.
        $connection->disconnect();
        $this->stats['closed']++;
    }

    /**
     * Reset pool statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
          'created'         => 0,
          'reused'          => 0,
          'closed'          => 0,
          'health_checks'   => 0,
          'health_failures' => 0,
        ];
    }

    /**
     * Check if we can create a new connection.
     */
    private function canCreateConnection(): bool
    {
        $totalConnections = 0;

        foreach ($this->connections as $connections) {
            $totalConnections += count($connections);
        }

        return $totalConnections < $this->poolConfig['max_connections'];
    }

    /**
     * Create a new connection.
     *
     * Each pooled connection gets its own HealthCheck instance
     * tuned with the pool's health_check_interval.
     */
    private function createConnection(string $name): Connection
    {
        $config     = $this->configs[$name];
        $connection = new Connection($config);

        // Attach HealthCheck monitor tuned with pool config.
        $connection->attachHealthCheck(
            new HealthCheck($connection, [
            'check_interval' => $this->poolConfig['health_check_interval'],
          ])
        );

        $connectionId = spl_object_id($connection);

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = [];
        }

        $this->connections[$name][$connectionId] = [
          'connection' => $connection,
          'created_at' => microtime(true),
        ];

        $this->stats['created']++;

        return $connection;
    }

    /**
     * Get an idle connection from the pool.
     */
    private function getIdleConnection(string $name): ?Connection
    {
        if (! isset($this->idle[$name]) || $this->idle[$name] === []) {
            return null;
        }

        // Get the first idle connection.
        $connectionId = array_key_first($this->idle[$name]);
        if ($connectionId === null) {
            return null;
        }

        $data = $this->idle[$name][$connectionId];

        // Check idle timeout.
        $idleTime = microtime(true) - $data['idle_since'];
        if ($idleTime > $this->poolConfig['idle_timeout']) {
            $this->removeConnection($name, $data['connection']);

            // Try next one.
            return $this->getIdleConnection($name);
        }

        // Remove from idle pool.
        unset($this->idle[$name][$connectionId]);

        // Check health before returning (uses HealthCheck if attached).
        if (! $data['connection']->isHealthy()) {
            $this->removeConnection($name, $data['connection']);

            // Try next one.
            return $this->getIdleConnection($name);
        }

        return $data['connection'];
    }

    /**
     * Remove stale idle connections.
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
