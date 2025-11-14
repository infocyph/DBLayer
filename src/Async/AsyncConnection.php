<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Async\Adapters\AdapterInterface;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Async Connection Manager
 *
 * Thin wrapper around an async adapter:
 * - Manages connection state
 * - Tracks basic query stats
 * - Provides simple nested-transaction semantics (ref-count)
 */
final class AsyncConnection
{
    /**
     * Underlying async adapter.
     */
    private readonly AdapterInterface $adapter;

    /**
     * @var array<string, mixed>
     */
    private readonly array $config;

    private bool $connected = false;

    /**
     * @var array{
     *   queries:int,
     *   errors:int,
     *   avg_time:float
     * }
     */
    private array $stats = [
      'queries'  => 0,
      'errors'   => 0,
      'avg_time' => 0.0,
    ];

    /**
     * Nesting level for transactions.
     * Level 0 => no transaction.
     */
    private int $transactionLevel = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(AdapterInterface $adapter, array $config)
    {
        $this->adapter = $adapter;
        $this->config  = $config;
    }

    public function connect(): Promise
    {
        if ($this->connected) {
            return Promise::resolve(true);
        }

        return $this->adapter->connect($this->config)
          ->then(function (mixed $result): mixed {
              $this->connected = true;
              return $result;
          });
    }

    public function disconnect(): Promise
    {
        if (!$this->connected) {
            return Promise::resolve(true);
        }

        return $this->adapter->disconnect()
          ->then(function (mixed $result): mixed {
              $this->connected        = false;
              $this->transactionLevel = 0;

              return $result;
          });
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->adapter->isConnected();
    }

    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return array{
     *   queries:int,
     *   errors:int,
     *   avg_time:float
     * }
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [
          'queries'  => 0,
          'errors'   => 0,
          'avg_time' => 0.0,
        ];
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Execute a query via the adapter and update timing stats.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): Promise
    {
        if (!$this->connected) {
            return Promise::reject(ConnectionException::lostConnection());
        }

        $start = microtime(true);
        $this->stats['queries']++;

        return $this->adapter->query($sql, $bindings)
          ->then(function (mixed $result) use ($start): mixed {
              $this->updateStats(microtime(true) - $start);
              return $result;
          })
          ->catch(function (Throwable $error): never {
              $this->stats['errors']++;
              throw $error;
          });
    }

    /**
     * Begin a transaction with simple nesting semantics.
     *
     * Level 0 -> begin on adapter.
     * Level >0 -> just increment counter (no extra BEGIN).
     */
    public function beginTransaction(): Promise
    {
        if (!$this->connected) {
            return Promise::reject(ConnectionException::lostConnection());
        }

        if ($this->transactionLevel === 0) {
            return $this->adapter->beginTransaction()
              ->then(function (mixed $result): mixed {
                  $this->transactionLevel = 1;
                  return $result;
              });
        }

        // nested: no new BEGIN on adapter, just bump ref-count
        $this->transactionLevel++;

        return Promise::resolve(true);
    }

    /**
     * Commit with simple nesting semantics.
     *
     * Level 0 -> error.
     * Level 1 -> COMMIT on adapter.
     * Level >1 -> just decrement counter.
     */
    public function commit(): Promise
    {
        if ($this->transactionLevel === 0) {
            return Promise::reject(new RuntimeException('No active transaction to commit.'));
        }

        if ($this->transactionLevel === 1) {
            return $this->adapter->commit()
              ->then(function (mixed $result): mixed {
                  $this->transactionLevel = 0;
                  return $result;
              });
        }

        $this->transactionLevel--;

        return Promise::resolve(true);
    }

    /**
     * Roll back current transaction.
     *
     * Any rollback resets nesting level to 0.
     */
    public function rollBack(): Promise
    {
        if ($this->transactionLevel === 0) {
            return Promise::reject(new RuntimeException('No active transaction to roll back.'));
        }

        return $this->adapter->rollBack()
          ->then(function (mixed $result): mixed {
              $this->transactionLevel = 0;
              return $result;
          });
    }

    /**
     * Execute the callback within a transaction boundary.
     *
     * @param callable(self):mixed $callback
     */
    public function transaction(callable $callback): Promise
    {
        return $this->beginTransaction()
          ->then(function () use ($callback): mixed {
              return $callback($this);
          })
          ->then(function (mixed $result): Promise {
              return $this->commit()
                ->then(static fn (): mixed => $result);
          })
          ->catch(function (Throwable $error): Promise {
              return $this->rollBack()
                ->then(function () use ($error): never {
                    throw $error;
                });
          });
    }

    private function updateStats(float $seconds): void
    {
        $count      = $this->stats['queries'];
        $currentAvg = $this->stats['avg_time'];

        if ($count <= 0) {
            return;
        }

        $this->stats['avg_time'] = (($currentAvg * ($count - 1)) + $seconds) / $count;
    }
}
