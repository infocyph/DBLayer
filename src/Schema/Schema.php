<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Closure;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\SchemaException;

/**
 * Schema Builder
 *
 * Provides a fluent interface for database schema operations:
 * - Create, modify, drop tables
 * - Add, modify, drop columns
 * - Manage indexes and foreign keys
 * - Database-agnostic schema definitions
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class Schema
{
    /**
     * Schema builder for the specific driver
     */
    private SchemaBuilder $builder;
    /**
     * Database connection
     */
    private Connection $connection;

    /**
     * Create a new schema instance
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->builder = $this->resolveSchemaBuilder();
    }

    /**
     * Create a new table
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $blueprint->create();

        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Disable foreign key constraints
     */
    public function disableForeignKeyConstraints(): void
    {
        $this->builder->disableForeignKeyConstraints();
    }

    /**
     * Drop a table
     */
    public function drop(string $table): void
    {
        $blueprint = new Blueprint($table);
        $blueprint->drop();

        $this->build($blueprint);
    }

    /**
     * Drop all tables
     */
    public function dropAllTables(): void
    {
        $tables = $this->builder->getAllTables();

        foreach ($tables as $table) {
            $this->drop($table);
        }
    }

    /**
     * Drop all views
     */
    public function dropAllViews(): void
    {
        $views = $this->builder->getAllViews();

        foreach ($views as $view) {
            $this->builder->dropView($view);
        }
    }

    /**
     * Drop a table if it exists
     */
    public function dropIfExists(string $table): void
    {
        $blueprint = new Blueprint($table);
        $blueprint->dropIfExists();

        $this->build($blueprint);
    }

    /**
     * Enable foreign key constraints
     */
    public function enableForeignKeyConstraints(): void
    {
        $this->builder->enableForeignKeyConstraints();
    }

    /**
     * Get all tables
     */
    public function getAllTables(): array
    {
        return $this->builder->getAllTables();
    }

    /**
     * Get all views
     */
    public function getAllViews(): array
    {
        return $this->builder->getAllViews();
    }

    /**
     * Get column listing for a table
     */
    public function getColumnListing(string $table): array
    {
        return $this->builder->getColumnListing($table);
    }

    /**
     * Get column type
     */
    public function getColumnType(string $table, string $column): string
    {
        return $this->builder->getColumnType($table, $column);
    }

    /**
     * Get the connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Check if a column exists
     */
    public function hasColumn(string $table, string $column): bool
    {
        return $this->builder->hasColumn($table, $column);
    }

    /**
     * Check if columns exist
     */
    public function hasColumns(string $table, array $columns): bool
    {
        return array_all($columns, fn ($column) => $this->hasColumn($table, $column));
    }

    /**
     * Check if a table exists
     */
    public function hasTable(string $table): bool
    {
        return $this->builder->hasTable($table);
    }

    /**
     * Rename a table
     */
    public function rename(string $from, string $to): void
    {
        $blueprint = new Blueprint($from);
        $blueprint->rename($to);

        $this->build($blueprint);
    }

    /**
     * Set the connection
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
        $this->builder = $this->resolveSchemaBuilder();
    }

    /**
     * Modify an existing table
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);

        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Execute the blueprint to build / modify the table
     */
    protected function build(Blueprint $blueprint): void
    {
        $statements = $this->builder->compile($blueprint);

        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }

    /**
     * Resolve the schema builder for the connection driver
     */
    private function resolveSchemaBuilder(): SchemaBuilder
    {
        $driver = $this->connection->getDriverName();

        return match ($driver) {
            'mysql' => new MySQLSchemaBuilder($this->connection),
            'pgsql' => new PostgreSQLSchemaBuilder($this->connection),
            'sqlite' => new SQLiteSchemaBuilder($this->connection),
            default => throw SchemaException::unsupportedDriver($driver),
        };
    }
}