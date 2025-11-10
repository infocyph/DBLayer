<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Transaction Committed Event
 * 
 * Fired when a database transaction is committed.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class TransactionCommittedEvent extends Event
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
     * Transaction duration in milliseconds
     */
    protected float $duration;

    /**
     * Create a new transaction committed event
     */
    public function __construct(string $connection = 'default', int $level = 1, float $duration = 0.0)
    {
        parent::__construct();
        
        $this->connection = $connection;
        $this->level = $level;
        $this->duration = $duration;
    }

    /**
     * Get event name
     */
    public function getName(): string
    {
        return 'transaction.committed';
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
     * Get transaction duration
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get event data
     */
    public function getData(): array
    {
        return [
            'connection' => $this->connection,
            'level' => $this->level,
            'duration' => $this->duration,
        ];
    }
}
