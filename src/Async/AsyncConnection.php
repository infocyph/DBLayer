<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Async\Adapters\AdapterInterface;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Async Connection Manager
 *
 * Manages asynchronous database connections with:
 * - Non-blocking query execution
 * - Multiple async runtime support
 * - Connection pooling
 * - Transaction management
 *
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class AsyncConnection
{
    /**
     * Async adapter
     */
    private AdapterInterface $adapter;

    /**
     * Connection configuration
     */
    private array $config;

    /**
     * Connection state
     */
    private bool $connected = false;

    /**
     * Query statistics
     */
    private array $stats = [
        'queries' => 0,
        'errors' => 0,
        'avg_time' => 0,
    ];

    /**
     * Active transactions
     */
    private int $transactionLevel = 0;

    /**
     * Create a new async connection
     */
    public function __construct(AdapterInterface $adapter, array $config)
    {
        $this->adapter = $adapter;
        $this->config = $config;
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): Promise
    {
        if (!$this->connected) {
            return Promise::reject(ConnectionException::lostConnection());
        }

        return $this->adapter->beginTransaction()
            ->then(function ($result) {
                $this->transactionLevel++;
                return $result;
            });
    }

    /**
     * Commit a transaction
     */
    public function commit(): Promise
    {
        if ($this->transactionLevel === 0) {
            return Promise::reject(new \RuntimeException('No active transaction'));
        }

        return $this->adapter->commit()
            ->then(function ($result) {
                $this->transactionLevel--;
                return $result;
            });
    }

    /**
     * Connect to database
     */
    public function connect(): Promise
    {
        if ($this->connected) {
            return Promise::resolve(true);
        }

        return $this->adapter->connect($this->config)
            ->then(function ($result) {
                $this->connected = true;
                return $result;
            });
    }

    /**
     * Disconnect from database
     */
    public function disconnect(): Promise
    {
        if (!$this->connected) {
            return Promise::resolve(true);
        }

        return $this->adapter->disconnect()
            ->then(function ($result) {
                $this->connected = false;
                $this->transactionLevel = 0;
                return $result;
            });
    }

    /**
     * Get adapter
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get transaction level
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->adapter->isConnected();
    }

    /**
     * Execute a query
     */
    public function query(string $sql, array $bindings = []): Promise
    {
        if (!$this->connected) {
            return Promise::reject(ConnectionException::lostConnection());
        }

        $startTime = microtime(true);
        $this->stats['queries']++;

        return $this->adapter->query($sql, $bindings)
            ->then(function ($result) use ($startTime) {
                $this->updateStats(microtime(true) - $startTime);
                return $result;
            })
            ->catch(function ($error) {
                $this->stats['errors']++;
                throw $error;
            });
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'queries' => 0,
            'errors' => 0,
            'avg_time' => 0,
        ];
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): Promise
    {
        if ($this->transactionLevel === 0) {
            return Promise::reject(new \RuntimeException('No active transaction'));
        }

        return $this->adapter->rollBack()
            ->then(function ($result) {
                $this->transactionLevel--;
                return $result;
            });
    }

    /**
     * Execute in transaction
     */
    public function transaction(callable $callback): Promise
    {
        return $this->beginTransaction()
            ->then(fn () => $callback($this))
            ->then(function ($result) {
                return $this->commit()->then(fn () => $result);
            })
            ->catch(function ($error) {
                return $this->rollBack()->then(fn () => throw $error);
            });
    }

    /**
     * Update query statistics
     */
    private function updateStats(float $time): void
    {
        $count = $this->stats['queries'];
        $currentAvg = $this->stats['avg_time'];

        $this->stats['avg_time'] = (($currentAvg * ($count - 1)) + $time) / $count;
    }
}
