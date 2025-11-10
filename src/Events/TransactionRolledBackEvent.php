<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Transaction Rolled Back Event
 * 
 * Fired when a database transaction is rolled back.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class TransactionRolledBackEvent extends Event
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
     * Rollback reason
     */
    protected ?string $reason;

    /**
     * Create a new transaction rolled back event
     */
    public function __construct(
        string $connection = 'default',
        int $level = 1,
        float $duration = 0.0,
        ?string $reason = null
    ) {
        parent::__construct();
        
        $this->connection = $connection;
        $this->level = $level;
        $this->duration = $duration;
        $this->reason = $reason;
    }

    /**
     * Get event name
     */
    public function getName(): string
    {
        return 'transaction.rolled_back';
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
     * Get rollback reason
     */
    public function getReason(): ?string
    {
        return $this->reason;
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
            'reason' => $this->reason,
        ];
    }
}
