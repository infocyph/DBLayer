<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async\Adapters;

use Infocyph\DBLayer\Async\Promise;

/**
 * Adapter Interface
 *
 * Defines the contract for async database adapters.
 * Implementations provide specific async runtime support.
 *
 * @package Infocyph\DBLayer\Async\Adapters
 * @author Hasan
 */
interface AdapterInterface
{
    /**
     * Begin a transaction
     */
    public function beginTransaction(): Promise;

    /**
     * Commit a transaction
     */
    public function commit(): Promise;
    /**
     * Connect to the database
     */
    public function connect(array $config): Promise;

    /**
     * Disconnect from the database
     */
    public function disconnect(): Promise;

    /**
     * Get adapter name
     */
    public function getName(): string;

    /**
     * Check if connected
     */
    public function isConnected(): bool;

    /**
     * Execute a query
     */
    public function query(string $sql, array $bindings = []): Promise;

    /**
     * Rollback a transaction
     */
    public function rollBack(): Promise;
}
