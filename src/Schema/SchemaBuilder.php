<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Schema Builder Base
 *
 * Abstract base class for database-specific schema builders.
 * Provides common interface for schema introspection and manipulation.
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
abstract class SchemaBuilder
{
    /**
     * Database connection.
     */
    protected Connection $connection;

    /**
     * Create a new schema builder instance.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Compile the blueprint to SQL statements.
     *
     * @return list<string>
     */
    abstract public function compile(Blueprint $blueprint): array;

    /**
     * Disable foreign key constraints.
     */
    abstract public function disableForeignKeyConstraints(): void;

    /**
     * Drop a view.
     */
    abstract public function dropView(string $view): void;

    /**
     * Enable foreign key constraints.
     */
    abstract public function enableForeignKeyConstraints(): void;

    /**
     * Get all tables.
     *
     * @return list<string>
     */
    abstract public function getAllTables(): array;

    /**
     * Get all views.
     *
     * @return list<string>
     */
    abstract public function getAllViews(): array;

    /**
     * Get column listing for a table.
     *
     * @return list<string>
     */
    abstract public function getColumnListing(string $table): array;

    /**
     * Get column type.
     */
    abstract public function getColumnType(string $table, string $column): string;

    /**
     * Check if a column exists.
     */
    abstract public function hasColumn(string $table, string $column): bool;

    /**
     * Check if a table exists.
     */
    abstract public function hasTable(string $table): bool;

    /**
     * Get the connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
