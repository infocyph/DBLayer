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
     * Connect to the database
     */
    public function connect(array $config): Promise;

    /**
     * Execute a query
     */
    public function query(string $sql, array $bindings = []): Promise;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): Promise;

    /**
     * Commit a transaction
     */
    public function commit(): Promise;

    /**
     * Rollback a transaction
     */
    public function rollBack(): Promise;

    /**
     * Disconnect from the database
     */
    public function disconnect(): Promise;

    /**
     * Check if connected
     */
    public function isConnected(): bool;

    /**
     * Get adapter name
     */
    public function getName(): string;
}
