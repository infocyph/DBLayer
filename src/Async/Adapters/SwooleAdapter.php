<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\AsyncException;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Swoole Adapter
 *
 * Async-ish adapter for Swoole coroutines using Swoole\Coroutine\MySQL.
 *
 * NOTE: Calls are non-blocking only when used inside a Swoole coroutine.
 */
final class SwooleAdapter implements AdapterInterface
{
    private bool $connected = false;

    /**
     * @var \Swoole\Coroutine\MySQL|null
     */
    private mixed $connection = null;

    public function connect(array $config): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($config): void {
            if (!extension_loaded('swoole')) {
                $reject(AsyncException::extensionMissing('swoole'));
                return;
            }

            try {
                $this->connection = new \Swoole\Coroutine\MySQL();

                $result = $this->connection->connect([
                  'host'     => $config['host'] ?? 'localhost',
                  'port'     => $config['port'] ?? 3306,
                  'user'     => $config['username'] ?? 'root',
                  'password' => $config['password'] ?? '',
                  'database' => $config['database'] ?? '',
                  'charset'  => $config['charset'] ?? 'utf8mb4',
                ]);

                if ($result) {
                    $this->connected = true;
                    $resolve(true);
                    return;
                }

                $reject(ConnectionException::connectionFailed('mysql', (string) $this->connection->error));
            } catch (Throwable $e) {
                $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
            }
        });
    }

    public function disconnect(): Promise
    {
        return new Promise(function (callable $resolve): void {
            if ($this->connection !== null) {
                try {
                    $this->connection->close();
                } catch (Throwable) {
                    // ignore
                }
            }

            $this->connected = false;
            $this->connection = null;

            $resolve(true);
        });
    }

    public function isConnected(): bool
    {
        return $this->connected
          && $this->connection !== null
          && $this->connection->connected;
    }

    public function query(string $sql, array $bindings = []): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($sql, $bindings): void {
            if (!$this->isConnected()) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            try {
                if ($bindings !== []) {
                    $stmt = $this->connection->prepare($sql);
                    $result = $stmt->execute($bindings);
                } else {
                    $result = $this->connection->query($sql);
                }

                if ($result === false) {
                    $reject(AsyncException::promiseRejected((string) $this->connection->error));
                    return;
                }

                $resolve($result);
            } catch (Throwable $e) {
                $reject(AsyncException::promiseRejected($e->getMessage()));
            }
        });
    }

    public function beginTransaction(): Promise
    {
        return $this->query('START TRANSACTION');
    }

    public function commit(): Promise
    {
        return $this->query('COMMIT');
    }

    public function rollBack(): Promise
    {
        return $this->query('ROLLBACK');
    }

    public function getName(): string
    {
        return 'swoole';
    }
}
