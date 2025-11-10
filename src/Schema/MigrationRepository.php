<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection;

/**
 * Migration Repository
 * 
 * Manages the migrations table that tracks which migrations have been run.
 * 
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class MigrationRepository
{
    /**
     * The database connection instance
     */
    protected Connection $connection;

    /**
     * The name of the migration table
     */
    protected string $table;

    /**
     * Create a new migration repository instance
     */
    public function __construct(Connection $connection, string $table = 'migrations')
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    /**
     * Get the ran migrations
     */
    public function getRan(): array
    {
        $query = "SELECT migration FROM {$this->table} ORDER BY batch, migration";
        
        return array_column(
            $this->connection->select($query),
            'migration'
        );
    }

    /**
     * Get list of migrations
     */
    public function getMigrations(int $steps = 0): array
    {
        $query = "SELECT * FROM {$this->table} ORDER BY batch DESC, migration DESC";
        
        if ($steps > 0) {
            $query .= " LIMIT {$steps}";
        }

        return $this->connection->select($query);
    }

    /**
     * Get the last migration batch
     */
    public function getLast(): array
    {
        $query = "SELECT * FROM {$this->table} WHERE batch = (SELECT MAX(batch) FROM {$this->table}) ORDER BY migration DESC";

        return $this->connection->select($query);
    }

    /**
     * Get the completed migrations with their batch numbers
     */
    public function getMigrationBatches(): array
    {
        $query = "SELECT migration, batch FROM {$this->table} ORDER BY batch ASC, migration ASC";
        
        return array_column(
            $this->connection->select($query),
            'batch',
            'migration'
        );
    }

    /**
     * Log that a migration was run
     */
    public function log(string $file, int $batch): void
    {
        $query = "INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)";
        
        $this->connection->insert($query, [$file, $batch]);
    }

    /**
     * Remove a migration from the log
     */
    public function delete(string $migration): void
    {
        $query = "DELETE FROM {$this->table} WHERE migration = ?";
        
        $this->connection->delete($query, [$migration]);
    }

    /**
     * Get the next migration batch number
     */
    public function getNextBatchNumber(): int
    {
        $query = "SELECT MAX(batch) as max_batch FROM {$this->table}";
        
        $result = $this->connection->select($query);
        
        return ((int) ($result[0]['max_batch'] ?? 0)) + 1;
    }

    /**
     * Get the last migration batch number
     */
    public function getLastBatchNumber(): int
    {
        $query = "SELECT MAX(batch) as max_batch FROM {$this->table}";
        
        $result = $this->connection->select($query);
        
        return (int) ($result[0]['max_batch'] ?? 0);
    }

    /**
     * Create the migration repository data store
     */
    public function createRepository(): void
    {
        $schema = new Schema($this->connection);

        $schema->create($this->table, function (Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
    }

    /**
     * Determine if the migration repository exists
     */
    public function repositoryExists(): bool
    {
        $schema = new Schema($this->connection);

        return $schema->hasTable($this->table);
    }

    /**
     * Delete the migration repository data store
     */
    public function deleteRepository(): void
    {
        $schema = new Schema($this->connection);

        $schema->drop($this->table);
    }

    /**
     * Get the connection instance
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Set the connection instance
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }
}
