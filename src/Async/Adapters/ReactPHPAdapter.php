<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * ReactPHP Adapter
 *
 * Async database adapter for ReactPHP.
 * Provides non-blocking MySQL operations using react/mysql.
 *
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
class ReactPHPAdapter implements AdapterInterface
{
    protected bool $connected = false;
    protected $connection = null;
    protected $loop = null;

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
            if (!class_exists('\React\MySQL\Factory')) {
                $reject(ConnectionException::missingExtension('react/mysql'));
                return;
            }

            try {
                $this->loop = \React\EventLoop\Loop::get();
                $factory = new \React\MySQL\Factory($this->loop);

                $uri = sprintf(
                    '%s:%s@%s:%d/%s',
                    $config['username'] ?? 'root',
                    $config['password'] ?? '',
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['database'] ?? ''
                );

                $factory->createConnection($uri)->then(
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

    public function disconnect(): Promise
    {
        return new Promise(function ($resolve) {
            if ($this->connection) {
                $this->connection->quit();
            }
            $this->connected = false;
            $resolve(true);
        });
    }

    public function getName(): string
    {
        return 'reactphp';
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connection !== null;
    }

    public function query(string $sql, array $bindings = []): Promise
    {
        return new Promise(function ($resolve, $reject) use ($sql, $bindings) {
            if (!$this->connected || !$this->connection) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            $query = $this->connection->query($sql);

            $query->then(
                function ($command) use ($resolve) {
                    if ($command->resultRows) {
                        $resolve($command->resultRows);
                    } else {
                        $resolve([]);
                    }
                },
                function ($error) use ($reject) {
                    $reject(new \RuntimeException($error->getMessage()));
                }
            );
        });
    }

    public function rollBack(): Promise
    {
        return $this->query('ROLLBACK');
    }
}
