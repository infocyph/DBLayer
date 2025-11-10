<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection;

/**
 * Migration Base Class
 * 
 * Provides the base functionality for database migrations.
 * Migrations are versioned changes to your database schema.
 * 
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
abstract class Migration
{
    /**
     * The database connection instance
     */
    protected Connection $connection;

    /**
     * The schema builder instance
     */
    protected Schema $schema;

    /**
     * Create a new migration instance
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->schema = new Schema($connection);
    }

    /**
     * Run the migrations
     */
    abstract public function up(): void;

    /**
     * Reverse the migrations
     */
    abstract public function down(): void;

    /**
     * Get the migration connection name
     */
    public function getConnection(): ?string
    {
        return null;
    }

    /**
     * Get the schema builder instance
     */
    protected function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * Enable foreign key constraints
     */
    protected function enableForeignKeyConstraints(): void
    {
        $this->schema->enableForeignKeyConstraints();
    }

    /**
     * Disable foreign key constraints
     */
    protected function disableForeignKeyConstraints(): void
    {
        $this->schema->disableForeignKeyConstraints();
    }
}
