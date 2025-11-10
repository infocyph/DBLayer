<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

use Infocyph\DBLayer\Async\Adapters\AdapterInterface;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Async Connection
 * 
 * Manages asynchronous database connections.
 * Provides non-blocking database operations.
 * 
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class AsyncConnection
{
    /**
     * The async adapter
     */
    protected AdapterInterface $adapter;

    /**
     * Connection configuration
     */
    protected array $config;

    /**
     * Whether connection is established
     */
    protected bool $connected = false;

    /**
     * Create a new async connection
     */
    public function __construct(AdapterInterface $adapter, array $config)
    {
        $this->adapter = $adapter;
        $this->config = $config;
    }

    /**
     * Connect to the database
     */
    public function connect(): Promise
    {
        return $this->adapter->connect($this->config)->then(function () {
            $this->connected = true;
            return $this;
        });
    }

    /**
     * Execute a query asynchronously
     */
    public function query(string $sql, array $bindings = []): Promise
    {
        if (!$this->connected) {
            return Promise::reject(
                ConnectionException::lostConnection()
            );
        }

        return $this->adapter->query($sql, $bindings);
    }

    /**
     * Execute multiple queries in parallel
     */
    public function parallel(array $queries): Promise
    {
        $promises = [];

        foreach ($queries as $key => $query) {
            $sql = is_array($query) ? $query[0] : $query;
            $bindings = is_array($query) && isset($query[1]) ? $query[1] : [];
            
            $promises[$key] = $this->query($sql, $bindings);
        }

        return Promise::all($promises);
    }

    /**
     * Begin transaction asynchronously
     */
    public function beginTransaction(): Promise
    {
        return $this->adapter->beginTransaction();
    }

    /**
     * Commit transaction asynchronously
     */
    public function commit(): Promise
    {
        return $this->adapter->commit();
    }

    /**
     * Rollback transaction asynchronously
     */
    public function rollBack(): Promise
    {
        return $this->adapter->rollBack();
    }

    /**
     * Execute callback within transaction
     */
    public function transaction(callable $callback): Promise
    {
        return $this->beginTransaction()
            ->then(fn() => $callback($this))
            ->then(function ($result) {
                return $this->commit()->then(fn() => $result);
            })
            ->catch(function ($error) {
                return $this->rollBack()->then(function () use ($error) {
                    throw $error;
                });
            });
    }

    /**
     * Close the connection
     */
    public function disconnect(): Promise
    {
        return $this->adapter->disconnect()->then(function () {
            $this->connected = false;
            return true;
        });
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get the adapter
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
     * Ping the connection
     */
    public function ping(): Promise
    {
        return $this->query('SELECT 1')->then(fn() => true);
    }
}
