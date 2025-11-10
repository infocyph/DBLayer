<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection;

/**
 * Migrator
 * 
 * Handles running and rolling back migrations.
 * 
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class Migrator
{
    /**
     * The migration repository implementation
     */
    protected MigrationRepository $repository;

    /**
     * The connection instance
     */
    protected Connection $connection;

    /**
     * The filesystem path to the migrations
     */
    protected string $path;

    /**
     * The notes for the current operation
     */
    protected array $notes = [];

    /**
     * Create a new migrator instance
     */
    public function __construct(MigrationRepository $repository, Connection $connection, string $path)
    {
        $this->repository = $repository;
        $this->connection = $connection;
        $this->path = $path;
    }

    /**
     * Run the pending migrations
     */
    public function run(array $options = []): array
    {
        $this->notes = [];

        $files = $this->getMigrationFiles();
        $ran = $this->repository->getRan();

        $migrations = array_diff($files, $ran);

        $this->runMigrations($migrations, $options);

        return $migrations;
    }

    /**
     * Run an array of migrations
     */
    protected function runMigrations(array $migrations, array $options = []): void
    {
        if (empty($migrations)) {
            $this->note('Nothing to migrate.');
            return;
        }

        $batch = $this->repository->getNextBatchNumber();

        foreach ($migrations as $file) {
            $this->runUp($file, $batch);
        }
    }

    /**
     * Run "up" a migration instance
     */
    protected function runUp(string $file, int $batch): void
    {
        $migration = $this->resolve($file);

        $this->note("Migrating: {$file}");

        $this->runMigration($migration, 'up');

        $this->repository->log($file, $batch);

        $this->note("Migrated: {$file}");
    }

    /**
     * Rollback the last migration operation
     */
    public function rollback(int $steps = 1): array
    {
        $this->notes = [];

        $migrations = $this->repository->getLast();

        if (empty($migrations)) {
            $this->note('Nothing to rollback.');
            return [];
        }

        return $this->rollbackMigrations($migrations);
    }

    /**
     * Rollback the given migrations
     */
    protected function rollbackMigrations(array $migrations): array
    {
        $rolledBack = [];

        foreach ($migrations as $migration) {
            $rolledBack[] = $migration['migration'];

            $this->runDown($migration['migration']);
        }

        return $rolledBack;
    }

    /**
     * Run "down" a migration instance
     */
    protected function runDown(string $file): void
    {
        $migration = $this->resolve($file);

        $this->note("Rolling back: {$file}");

        $this->runMigration($migration, 'down');

        $this->repository->delete($file);

        $this->note("Rolled back: {$file}");
    }

    /**
     * Run a migration inside a transaction if possible
     */
    protected function runMigration(Migration $migration, string $method): void
    {
        try {
            $this->connection->beginTransaction();
            
            $migration->$method();
            
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Reset all migrations
     */
    public function reset(): array
    {
        $this->notes = [];

        $migrations = array_reverse($this->repository->getRan());

        if (empty($migrations)) {
            $this->note('Nothing to rollback.');
            return [];
        }

        return $this->resetMigrations($migrations);
    }

    /**
     * Reset the given migrations
     */
    protected function resetMigrations(array $migrations): array
    {
        $rolledBack = [];

        foreach ($migrations as $migration) {
            $rolledBack[] = $migration;
            $this->runDown($migration);
        }

        return $rolledBack;
    }

    /**
     * Get all migration files
     */
    public function getMigrationFiles(): array
    {
        $files = glob($this->path . '/*.php');

        if ($files === false) {
            return [];
        }

        $files = array_map(function ($file) {
            return str_replace('.php', '', basename($file));
        }, $files);

        sort($files);

        return $files;
    }

    /**
     * Resolve a migration instance from a file
     */
    protected function resolve(string $file): Migration
    {
        $class = $this->getMigrationClass($file);

        require_once $this->path . '/' . $file . '.php';

        return new $class($this->connection);
    }

    /**
     * Get the class name from a migration file name
     */
    protected function getMigrationClass(string $file): string
    {
        return $this->studlyCase(implode('_', array_slice(explode('_', $file), 4)));
    }

    /**
     * Convert string to StudlyCase
     */
    protected function studlyCase(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * Raise a note event for the migrator
     */
    protected function note(string $message): void
    {
        $this->notes[] = $message;
    }

    /**
     * Get the notes for the last operation
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * Get the migration repository instance
     */
    public function getRepository(): MigrationRepository
    {
        return $this->repository;
    }

    /**
     * Set the migration repository instance
     */
    public function setRepository(MigrationRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Get the connection instance
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
