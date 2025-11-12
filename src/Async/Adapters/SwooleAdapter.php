<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Swoole Adapter
 *
 * Async database adapter for Swoole coroutines.
 * Provides non-blocking database operations using Swoole\Coroutine\MySQL.
 *
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class SwooleAdapter implements AdapterInterface
{
    protected bool $connected = false;
    protected mixed $connection = null;

    public function beginTransaction(): Promise
    {
        return $this->query('START TRANSACTION');
    }

    public function commit(): Promise
    {
        return $this->query('COMMIT');
    }

    public function connect(array $config): Promise
    {
        return new Promise(function ($resolve, $reject) use ($config) {
            if (!extension_loaded('swoole')) {
                $reject(ConnectionException::missingExtension('swoole'));
                return;
            }

            try {
                $this->connection = new \Swoole\Coroutine\MySQL();

                $result = $this->connection->connect([
                    'host' => $config['host'] ?? 'localhost',
                    'port' => $config['port'] ?? 3306,
                    'user' => $config['username'] ?? 'root',
                    'password' => $config['password'] ?? '',
                    'database' => $config['database'] ?? '',
                    'charset' => $config['charset'] ?? 'utf8mb4',
                ]);

                if ($result) {
                    $this->connected = true;
                    $resolve(true);
                } else {
                    $reject(ConnectionException::connectionFailed('mysql', $this->connection->error));
                }
            } catch (\Throwable $e) {
                $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
            }
        });
    }

    public function disconnect(): Promise
    {
        return new Promise(function ($resolve) {
            if ($this->connection) {
                $this->connection->close();
            }
            $this->connected = false;
            $resolve(true);
        });
    }

    public function getName(): string
    {
        return 'swoole';
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connection && $this->connection->connected;
    }

    public function query(string $sql, array $bindings = []): Promise
    {
        return new Promise(function ($resolve, $reject) use ($sql, $bindings) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            try {
                if (!empty($bindings)) {
                    $stmt = $this->connection->prepare($sql);
                    $result = $stmt->execute($bindings);
                } else {
                    $result = $this->connection->query($sql);
                }

                if ($result === false) {
                    $reject(new \RuntimeException($this->connection->error));
                    return;
                }

                $resolve($result);
            } catch (\Throwable $e) {
                $reject(new \RuntimeException($e->getMessage()));
            }
        });
    }

    public function rollBack(): Promise
    {
        return $this->query('ROLLBACK');
    }
}
