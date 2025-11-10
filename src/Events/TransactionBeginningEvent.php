<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Transaction Beginning Event
 * 
 * Fired when a database transaction begins.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class TransactionBeginningEvent extends Event
{
    /**
     * The connection name
     */
    protected string $connection;

    /**
     * Transaction level
     */
    protected int $level;

    /**
     * Create a new transaction beginning event
     */
    public function __construct(string $connection = 'default', int $level = 1)
    {
        parent::__construct();
        
        $this->connection = $connection;
        $this->level = $level;
    }

    /**
     * Get event name
     */
    public function getName(): string
    {
        return 'transaction.beginning';
    }

    /**
     * Get the connection name
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * Get transaction level
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Get event data
     */
    public function getData(): array
    {
        return [
            'connection' => $this->connection,
            'level' => $this->level,
        ];
    }
}
