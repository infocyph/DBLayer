<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;
use Infocyph\DBLayer\Exceptions\AsyncException;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Amp Adapter
 *
 * Async database adapter for the Amp framework.
 * Uses amphp/mysql (v3-style) underneath.
 *
 * NOTE: This adapter assumes it is used within an Amp runtime.
 */
final class AmpAdapter implements AdapterInterface
{
    private bool $connected = false;

    /**
     * @var mixed|null Underlying Amp MySQL connection
     */
    private mixed $connection = null;

    public function connect(array $config): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($config): void {
            if (!class_exists('\Amp\Mysql\MysqlConfig')) {
                $reject(AsyncException::extensionMissing('amphp/mysql'));
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

                \Amp\async(function () use ($ampConfig, $resolve, $reject): void {
                    try {
                        $this->connection = \Amp\Mysql\connect($ampConfig);
                        $this->connected = true;
                        $resolve(true);
                    } catch (Throwable $e) {
                        $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
                    }
                });
            } catch (Throwable $e) {
                $reject(ConnectionException::connectionFailed('mysql', $e->getMessage()));
            }
        });
    }

    public function disconnect(): Promise
    {
        return new Promise(function (callable $resolve): void {
            if ($this->connection !== null) {
                \Amp\async(function () use ($resolve): void {
                    try {
                        $this->connection->close();
                    } catch (Throwable) {
                        // Ignore close errors
                    }

                    $this->connected = false;
                    $this->connection = null;
                    $resolve(true);
                });
                return;
            }

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

            \Amp\async(function () use ($sql, $bindings, $resolve, $reject): void {
                try {
                    if ($bindings !== []) {
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
                } catch (Throwable $e) {
                    $reject(AsyncException::promiseRejected($e->getMessage()));
                }
            });
        });
    }

    public function beginTransaction(): Promise
    {
        return new Promise(function (callable $resolve, callable $reject): void {
            if (!$this->isConnected()) {
                $reject(ConnectionException::lostConnection());
                return;
            }

            \Amp\async(function () use ($resolve, $reject): void {
                try {
                    $result = $this->connection->beginTransaction();
                    $resolve($result);
                } catch (Throwable $e) {
                    $reject(AsyncException::promiseRejected($e->getMessage()));
                }
            });
        });
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
        return 'amp';
    }
}
