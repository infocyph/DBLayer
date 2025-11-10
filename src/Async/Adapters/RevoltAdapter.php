<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Revolt Adapter
 * 
 * Async database adapter for Revolt event loop.
 * Provides non-blocking operations using Revolt fiber-based concurrency.
 * 
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class RevoltAdapter implements AdapterInterface
{
    /**
     * Database connection
     */
    protected ?\PDO $connection = null;

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
            if (!class_exists('\Revolt\EventLoop')) {
                $reject(ConnectionException::missingExtension('revolt/event-loop'));
                return;
            }

            \Revolt\EventLoop::queue(function () use ($config, $resolve, $reject) {
                try {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                        $config['host'] ?? 'localhost',
                        $config['port'] ?? 3306,
                        $config['database'] ?? '',
                        $config['charset'] ?? 'utf8mb4'
                    );

                    $this->connection = new \PDO(
                        $dsn,
                        $config['username'] ?? 'root',
                        $config['password'] ?? '',
                        [
                            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        ]
                    );

                    $this->connected = true;
                    $resolve(true);
                } catch (\Throwable $e) {
                    $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
                }
            });
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

            \Revolt\EventLoop::queue(function () use ($sql, $bindings, $resolve, $reject) {
                try {
                    $stmt = $this->connection->prepare($sql);
                    $stmt->execute($bindings);

                    // Determine query type
                    $queryType = strtoupper(substr(trim($sql), 0, 6));

                    if ($queryType === 'SELECT') {
                        $result = $stmt->fetchAll();
                    } else {
                        $result = $stmt->rowCount();
                    }

                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject(new \RuntimeException($e->getMessage()));
                }
            });
        });
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            \Revolt\EventLoop::queue(function () use ($resolve, $reject) {
                try {
                    $this->connection->beginTransaction();
                    $resolve(true);
                } catch (\Throwable $e) {
                    $reject(new \RuntimeException($e->getMessage()));
                }
            });
        });
    }

    /**
     * Commit a transaction
     */
    public function commit(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            \Revolt\EventLoop::queue(function () use ($resolve, $reject) {
                try {
                    $this->connection->commit();
                    $resolve(true);
                } catch (\Throwable $e) {
                    $reject(new \RuntimeException($e->getMessage()));
                }
            });
        });
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            \Revolt\EventLoop::queue(function () use ($resolve, $reject) {
                try {
                    $this->connection->rollBack();
                    $resolve(true);
                } catch (\Throwable $e) {
                    $reject(new \RuntimeException($e->getMessage()));
                }
            });
        });
    }

    /**
     * Disconnect from the database
     */
    public function disconnect(): Promise
    {
        return new Promise(function ($resolve, $reject) {
            \Revolt\EventLoop::queue(function () use ($resolve) {
                $this->connection = null;
                $this->connected = false;
                $resolve(true);
            });
        });
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->connection !== null;
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'revolt';
    }

    /**
     * Get PDO connection
     */
    public function getConnection(): ?\PDO
    {
        return $this->connection;
    }
}
