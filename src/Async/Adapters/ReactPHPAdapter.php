<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * ReactPHP Adapter
 * 
 * Async database adapter for ReactPHP event loop.
 * Provides non-blocking MySQL operations using react/mysql.
 * 
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class ReactPHPAdapter implements AdapterInterface
{
    /**
     * ReactPHP MySQL connection
     */
    protected $connection = null;

    /**
     * Connection state
     */
    protected bool $connected = false;

    /**
     * Event loop
     */
    protected $loop = null;

    /**
     * Create adapter with event loop
     */
    public function __construct($loop = null)
    {
        $this->loop = $loop;
    }

    /**
     * Connect to the database
     */
    public function connect(array $config): Promise
    {
        return new Promise(function ($resolve, $reject) use ($config) {
            if (!class_exists('\React\MySQL\Factory')) {
                $reject(ConnectionException::missingExtension('react/mysql'));
                return;
            }

            try {
                $factory = new \React\MySQL\Factory($this->loop);

                $dsn = sprintf(
                    '%s:%s@%s:%d/%s',
                    $config['username'] ?? 'root',
                    $config['password'] ?? '',
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['database'] ?? ''
                );

                $factory->createConnection($dsn)->then(
                    function ($conn) use ($resolve) {
                        $this->connection = $conn;
                        $this->connected = true;
                        $resolve(true);
                    },
                    function ($error) use ($reject) {
                        $reject(ConnectionException::connectionFailed('mysql', $error->getMessage()));
                    }
                );
            } catch (\Throwable $e) {
                $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
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

            $this->connection->query($sql, $bindings)->then(
                function ($result) use ($resolve) {
                    $resolve($result);
                },
                function ($error) use ($reject) {
                    $reject(new \RuntimeException($error->getMessage()));
                }
            );
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
                $this->connection->quit()->then(
                    function () use ($resolve) {
                        $this->connection = null;
                        $this->connected = false;
                        $resolve(true);
                    },
                    function ($error) use ($resolve) {
                        // Still mark as disconnected even on error
                        $this->connection = null;
                        $this->connected = false;
                        $resolve(true);
                    }
                );
            } else {
                $resolve(true);
            }
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
        return 'reactphp';
    }

    /**
     * Get ReactPHP MySQL connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get event loop
     */
    public function getLoop()
    {
        return $this->loop;
    }
}
