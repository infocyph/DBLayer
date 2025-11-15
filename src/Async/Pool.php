<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Exceptions\AsyncException;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use RuntimeException;

/**
 * Async Connection Pool
 *
 * Simple in-process pool for AsyncConnection:
 * - Lazily grows up to max connections
 * - Tracks basic stats
 * - Supports queued acquire with optional wait timeout
 *
 * NOTE: This pool is per-process. It does NOT coordinate
 * across multiple FPM workers or processes.
 */
final class Pool
{
    /**
     * @var array{
     *   min:int,
     *   max:int,
     *   idle_timeout:int,
     *   max_lifetime:int,
     *   wait_timeout:int
     * }
     */
    private array $config;

    /**
     * @var array<int, array{connection:AsyncConnection,created_at:float}>
     */
    private array $connections = [];

    /**
     * @var array<int, array{connection:AsyncConnection,released_at:float}>
     */
    private array $available = [];

    /**
     * @var array<int, array{connection:AsyncConnection,acquired_at:float}>
     */
    private array $busy = [];

    /**
     * @var array<int, array{0:callable,1:callable,2:float}>
     */
    private array $pending = [];

    /**
     * @var array{
     *   created:int,
     *   destroyed:int,
     *   acquired:int,
     *   released:int,
     *   errors:int,
     *   pending:int
     * }
     */
    private array $stats = [
      'created'   => 0,
      'destroyed' => 0,
      'acquired'  => 0,
      'released'  => 0,
      'errors'    => 0,
      'pending'   => 0,
    ];

    /**
     * @var \Closure():AsyncConnection
     */
    private \Closure $factory;

    /**
     * @param callable():AsyncConnection $factory
     * @param array<string, int>         $config
     */
    public function __construct(callable $factory, array $config = [])
    {
        $defaults = [
          'min'          => 0,
          'max'          => 10,
          'idle_timeout' => 60,
          'max_lifetime' => 3600,
          'wait_timeout' => 30,
        ];

        $this->config = array_merge($defaults, $config);

        // Normalize to Closure so we can always call ($this->factory)()
        $this->factory = $factory instanceof \Closure
          ? $factory
          : \Closure::fromCallable($factory);
    }

    /**
     * Acquire a connection from the pool.
     */
    public function acquire(): Promise
    {
        // Reuse available connection if any
        if ($this->available !== []) {
            $id         = array_key_first($this->available);
            $entry      = $this->available[$id];
            $connection = $entry['connection'];

            unset($this->available[$id]);

            $this->busy[$id] = [
              'connection'  => $connection,
              'acquired_at' => microtime(true),
            ];

            $this->stats['acquired']++;

            return Promise::resolve($connection);
        }

        // If not at max, create a new one
        if (count($this->connections) < $this->config['max']) {
            return $this->createConnection();
        }

        // Otherwise, queue the request
        return $this->queueRequest();
    }

    /**
     * Release a connection back to the pool.
     */
    public function release(AsyncConnection $connection): Promise
    {
        $id = spl_object_id($connection);

        if (!isset($this->busy[$id])) {
            return Promise::reject(
              AsyncException::invalidConfiguration('Connection does not belong to this pool.')
            );
        }

        unset($this->busy[$id]);

        // Drop invalid connections
        if (!$connection->isConnected()) {
            $this->destroyConnection($connection);
            $this->processPending();

            return Promise::resolve(true);
        }

        // Drop expired connections
        if ($this->isExpired($id)) {
            $this->destroyConnection($connection);
            $this->processPending();

            return Promise::resolve(true);
        }

        // If there are pending requests, reuse this connection immediately
        if ($this->pending !== []) {
            $pending = array_shift($this->pending);
            if ($pending === null) {
                // Should not happen, but just in case
                $this->available[$id] = [
                  'connection'  => $connection,
                  'released_at' => microtime(true),
                ];
                $this->stats['released']++;

                return Promise::resolve(true);
            }

            [$resolve, $reject, $startTime] = $pending;

            if (microtime(true) - $startTime > $this->config['wait_timeout']) {
                $reject(ConnectionException::timeout($this->config['wait_timeout']));

                // Put the connection back and try next pending request
                $this->available[$id] = [
                  'connection'  => $connection,
                  'released_at' => microtime(true),
                ];

                $this->processPending();

                return Promise::resolve(true);
            }

            $this->busy[$id] = [
              'connection'  => $connection,
              'acquired_at' => microtime(true),
            ];

            $this->stats['acquired']++;
            $this->stats['pending']--;

            $resolve($connection);

            return Promise::resolve(true);
        }

        // No pending requests – mark as available
        $this->available[$id] = [
          'connection'  => $connection,
          'released_at' => microtime(true),
        ];

        $this->stats['released']++;

        return Promise::resolve(true);
    }

    /**
     * Execute a callback with a pooled connection, auto-releasing it.
     *
     * @param callable(AsyncConnection):mixed $callback
     */
    public function withConnection(callable $callback): Promise
    {
        return $this->acquire()
          ->then(function (AsyncConnection $connection) use ($callback): Promise {
              return Promise::resolve($callback($connection))
                ->finally(function () use ($connection): void {
                    $this->release($connection);
                });
          });
    }

    /**
     * Close all connections and reject all pending acquires.
     */
    public function close(): Promise
    {
        $promises = [];

        foreach ($this->connections as $entry) {
            $promises[] = $entry['connection']->disconnect();
        }

        return Promise::all($promises)
          ->then(function (): bool {
              $this->connections = [];
              $this->available   = [];
              $this->busy        = [];

              foreach ($this->pending as [$resolve, $reject]) {
                  $reject(new RuntimeException('Async pool closed.'));
              }

              $this->pending = [];

              return true;
          });
    }

    /**
     * Clean up idle/expired connections and ensure min pool size.
     */
    public function cleanup(): Promise
    {
        $now      = microtime(true);
        $promises = [];

        foreach ($this->available as $id => $entry) {
            $idle = $now - $entry['released_at'];

            if ($idle > $this->config['idle_timeout'] || $this->isExpired($id)) {
                $promises[] = $this->destroyConnection($entry['connection']);
            }
        }

        while (count($this->connections) < $this->config['min']) {
            $promises[] = $this->createConnection();
        }

        if ($promises === []) {
            return Promise::resolve(true);
        }

        return Promise::all($promises)
          ->then(static fn (): bool => true);
    }

    /**
     * @return array<string, int|float>
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
          'total'       => count($this->connections),
          'available'   => count($this->available),
          'busy'        => count($this->busy),
          'pending'     => count($this->pending),
          'utilization' => $this->getUtilization(),
        ]);
    }

    public function resetStats(): void
    {
        $this->stats = [
          'created'   => 0,
          'destroyed' => 0,
          'acquired'  => 0,
          'released'  => 0,
          'errors'    => 0,
          'pending'   => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    private function queueRequest(): Promise
    {
        $this->stats['pending']++;

        return new Promise(function (callable $resolve, callable $reject): void {
            $this->pending[] = [$resolve, $reject, microtime(true)];
        });
    }

    /**
     * Try to satisfy pending requests by creating new connections.
     */
    private function processPending(): void
    {
        while ($this->pending !== [] && count($this->connections) < $this->config['max']) {
            $pending = array_shift($this->pending);

            if ($pending === null) {
                break;
            }

            [$resolve, $reject] = $pending;

            $this->createConnection()
              ->then(function (AsyncConnection $connection) use ($resolve): void {
                  $resolve($connection);
              })
              ->catch(function (\Throwable $error) use ($reject): void {
                  $this->stats['errors']++;
                  $reject($error);
              });

            $this->stats['pending']--;
        }
    }

    private function createConnection(): Promise
    {
        $connection = ($this->factory)();

        if (!$connection instanceof AsyncConnection) {
            return Promise::reject(
              AsyncException::invalidConfiguration('Factory must return AsyncConnection.')
            );
        }

        return $connection->connect()
          ->then(function () use ($connection): AsyncConnection {
              $id = spl_object_id($connection);

              $this->connections[$id] = [
                'connection' => $connection,
                'created_at' => microtime(true),
              ];

              $this->busy[$id] = [
                'connection'  => $connection,
                'acquired_at' => microtime(true),
              ];

              $this->stats['created']++;
              $this->stats['acquired']++;

              return $connection;
          })
          ->catch(function (\Throwable $error): never {
              $this->stats['errors']++;
              throw $error;
          });
    }

    private function destroyConnection(AsyncConnection $connection): Promise
    {
        $id = spl_object_id($connection);

        unset($this->connections[$id], $this->available[$id], $this->busy[$id]);

        $this->stats['destroyed']++;

        return $connection->disconnect();
    }

    private function isExpired(int $id): bool
    {
        if (!isset($this->connections[$id])) {
            return true;
        }

        $age = microtime(true) - $this->connections[$id]['created_at'];

        return $age > $this->config['max_lifetime'];
    }

    private function getUtilization(): float
    {
        if ($this->config['max'] <= 0) {
            return 0.0;
        }

        return (count($this->busy) / $this->config['max']) * 100.0;
    }
}
