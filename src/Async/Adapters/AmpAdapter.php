<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Amp Adapter
 * 
 * Async database adapter for Amp framework.
 * Provides non-blocking MySQL operations using amphp/mysql.
 * 
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class AmpAdapter implements AdapterInterface
{
    /**
     * Amp MySQL connection
     */
    protected $connection = null;

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
            if (!class_exists('\Amp\Mysql\MysqlConfig')) {
                $reject(ConnectionException::missingExtension('amphp/mysql'));
                return;
            }

            try {
                $ampConfig = \Amp\Mysql\MysqlConfig::fromString(sprintf(
                    'host=%s port=%d user=%s password=%s db=%s',
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['username'] ?? 'root',
                    $config['password'] ?? '',
                    $config['database'] ?? ''
                ));

                \Amp\async(function () use ($ampConfig, $resolve, $reject) {
                    try {
                        $this->connection = \Amp\Mysql\connect($ampConfig);
                        $this->connected = true;
                        $resolve(true);
                    } catch (\Throwable $e) {
                        $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
                    }
                });
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

            \Amp\async(function () use ($sql, $bindings, $resolve, $reject) {
                try {
                    if (!empty($bindings)) {
                        $statement = $this->connection->prepare($sql);
                        $result = $statement->execute($bindings);
                    } else {
                        $result = $this->connection->query($sql);
                    }

                    $rows = [];
                    foreach ($result as $row) {
                        $rows[] = $row;
                    }

                    $resolve($rows);
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

            \Amp\async(function () use ($resolve, $reject) {
                try {
                    $transaction = $this->connection->beginTransaction();
                    $resolve($transaction);
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
                \Amp\async(function () use ($resolve) {
                    try {
                        $this->connection->close();
                    } catch (\Throwable $e) {
                        // Ignore close errors
                    }
                    
                    $this->connection = null;
                    $this->connected = false;
                    $resolve(true);
                });
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
        return 'amp';
    }

    /**
     * Get Amp MySQL connection
     */
    public function getConnection()
    {
        return $this->connection;
    }
}
