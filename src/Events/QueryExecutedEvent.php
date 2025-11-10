<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Query Executed Event
 * 
 * Fired when a database query is executed.
 * Contains query details and execution metrics.
 * 
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class QueryExecutedEvent extends Event
{
    /**
     * The SQL query
     */
    protected string $sql;

    /**
     * The query bindings
     */
    protected array $bindings;

    /**
     * The execution time in milliseconds
     */
    protected float $time;

    /**
     * The connection name
     */
    protected string $connection;

    /**
     * The number of affected rows
     */
    protected ?int $affectedRows;

    /**
     * Create a new query executed event
     */
    public function __construct(
        string $sql,
        array $bindings = [],
        float $time = 0.0,
        string $connection = 'default',
        ?int $affectedRows = null
    ) {
        parent::__construct();
        
        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->time = $time;
        $this->connection = $connection;
        $this->affectedRows = $affectedRows;
    }

    /**
     * Get event name
     */
    public function getName(): string
    {
        return 'query.executed';
    }

    /**
     * Get the SQL query
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get the query bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get the execution time
     */
    public function getTime(): float
    {
        return $this->time;
    }

    /**
     * Get the connection name
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * Get affected rows
     */
    public function getAffectedRows(): ?int
    {
        return $this->affectedRows;
    }

    /**
     * Get event data
     */
    public function getData(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'time' => $this->time,
            'connection' => $this->connection,
            'affected_rows' => $this->affectedRows,
        ];
    }

    /**
     * Check if query was slow
     */
    public function isSlow(float $threshold = 1000.0): bool
    {
        return $this->time > $threshold;
    }
}
