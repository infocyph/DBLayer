<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Swoole\Coroutine\MySQL;

/**
 * Swoole Adapter
 * 
 * Async database adapter for Swoole coroutines.
 * Provides non-blocking MySQL operations using Swoole.
 * 
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class SwooleAdapter implements AdapterInterface
{
    /**
     * Swoole MySQL connection
     */
    protected ?MySQL $connection = null;

    /**
     * Connection state
     */
    protected bool $connected = false;

    /**
     * Connect to the database
     */
    public function connect(array $config): Promise
    {
        return new Promise(function ($resolve, $reject) use ($config) {
            if (!extension_loaded('swoole')) {
                $reject(ConnectionException::missingExtension('swoole'));
                return;
            }

            $this->connection = new MySQL();

            $swooleConfig = [
                'host' => $config['host'] ?? 'localhost',
                'port' => $config['port'] ?? 3306,
                'user' => $config['username'] ?? 'root',
                'password' => $config['password'] ?? '',
                'database' => $config['database'] ?? '',
                'charset' => $config['charset'] ?? 'utf8mb4',
                'timeout' => $config['timeout'] ?? 5,
            ];

            $result = $this->connection->connect($swooleConfig);

            if ($result) {
                $this->connected = true;
                $resolve(true);
            } else {
                $reject(ConnectionException::connectionFailed(
                    'mysql',
                    $this->connection->connect_error ?? 'Unknown error'
                ));
            }
        });
    }

    /**
     * Execute a query
     */
    public function query(string $sql, array $bindings = []): Promise
    {
        return new Promise(function ($resolve, $reject) use ($sql, $bindings) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            // Prepare statement if bindings exist
            if (!empty($bindings)) {
                $stmt = $this->connection->prepare($sql);
                
                if ($stmt === false) {
                    $reject(new \RuntimeException($this->connection->error ?? 'Query preparation failed'));
                    return;
                }

                $result = $stmt->execute($bindings);
            } else {
                $result = $this->connection->query($sql);
            }

            if ($result === false) {
                $reject(new \RuntimeException($this->connection->error ?? 'Query execution failed'));
                return;
            }

            $resolve($result);
        });
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): Promise
    {
        return $this->query('BEGIN');
    }

    /**
     * Commit a transaction
     */
    public function commit(): Promise
    {
        return $this->query('COMMIT');
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): Promise
    {
        return $this->query('ROLLBACK');
    }

    /**
     * Disconnect from the database
     */
    public function disconnect(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            if ($this->connection) {
                $this->connection->close();
                $this->connection = null;
                $this->connected = false;
            }
            $resolve(true);
        });
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->connection && $this->connection->connected;
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'swoole';
    }

    /**
     * Get Swoole MySQL connection
     */
    public function getConnection(): ?MySQL
    {
        return $this->connection;
    }
}
