<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\AsyncException;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * ReactPHP Adapter
 *
 * Async database adapter for ReactPHP using react/mysql.
 *
 * NOTE: This adapter assumes you are already running a React event loop.
 */
final class ReactPHPAdapter implements AdapterInterface
{
    private bool $connected = false;

    /**
     * @var mixed|null \React\MySQL\ConnectionInterface
     */
    private mixed $connection = null;

    /**
     * @var mixed|null \React\EventLoop\LoopInterface
     */
    private mixed $loop = null;

    public function connect(array $config): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($config): void {
            if (!class_exists('\React\MySQL\Factory')) {
                $reject(AsyncException::extensionMissing('react/mysql'));
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
                  function ($conn) use ($resolve): void {
                      $this->connection = $conn;
                      $this->connected = true;
                      $resolve(true);
                  },
                  function ($error) use ($reject): void {
                      $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
                      $reject(ConnectionException::connectionFailed('mysql', $message));
                  }
                );
            } catch (Throwable $e) {
                $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
            }
        });
    }

    public function disconnect(): Promise
    {
        return new Promise(function (callable $resolve): void {
            if ($this->connection !== null) {
                $this->connection->quit();
            }

            $this->connected = false;
            $this->connection = null;

            $resolve(true);
        });
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->connection !== null;
    }

    public function query(string $sql, array $bindings = []): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($sql, $bindings): void {
            if (!$this->isConnected()) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            try {
                $query = $this->connection->query($sql, $bindings);

                $query->then(
                  function ($command) use ($resolve): void {
                      $rows = $command->resultRows ?? [];
                      $resolve($rows);
                  },
                  function ($error) use ($reject): void {
                      $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
                      $reject(AsyncException::promiseRejected($message));
                  }
                );
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
        return 'reactphp';
    }
}
