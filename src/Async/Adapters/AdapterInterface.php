<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;

/**
 * Adapter Interface
 *
 * Contract for async database adapters.
 * Implementations must integrate with a specific async runtime
 * (Amp, ReactPHP, Swoole, etc.) but expose a uniform Promise API.
 */
interface AdapterInterface
{
    /**
     * Connect to the database.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Promise;

    /**
     * Disconnect from the database.
     */
    public function disconnect(): Promise;

    /**
     * Check if the adapter has an active connection.
     */
    public function isConnected(): bool;

    /**
     * Execute a SQL query.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): Promise;

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): Promise;

    /**
     * Commit the current transaction.
     */
    public function commit(): Promise;

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): Promise;

    /**
     * Return a stable adapter name (e.g. "amp", "reactphp", "swoole").
     */
    public function getName(): string;
}
