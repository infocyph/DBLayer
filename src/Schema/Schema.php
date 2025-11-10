<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection;
use Infocyph\DBLayer\Grammar\Grammar;

/**
 * Schema Builder
 * 
 * Provides a fluent interface for creating and modifying database schemas.
 * Supports multiple database drivers and provides a unified API for
 * schema operations across MySQL, PostgreSQL, and SQLite.
 * 
 * @package DBLayer\Schema
 * @author Hasan
 */
class Schema
{
    /**
     * The database connection instance
     */
    protected Connection $connection;

    /**
     * The schema grammar instance
     */
    protected Grammar $grammar;

    /**
     * The default string length for migrations
     */
    public static int $defaultStringLength = 255;

    /**
     * Create a new schema builder instance
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getGrammar();
    }

    /**
     * Determine if the given table exists
     */
    public function hasTable(string $table): bool
    {
        $table = $this->connection->getTablePrefix() . $table;
        
        $sql = $this->grammar->compileTableExists();
        
        $database = $this->connection->getDatabaseName();
        
        $result = $this->connection->select($sql, [$database, $table]);
        
        return count($result) > 0;
    }

    /**
     * Determine if the given table has a given column
     */
    public function hasColumn(string $table, string $column): bool
    {
        return in_array(
            strtolower($column),
            array_map('strtolower', $this->getColumnListing($table))
        );
    }

    /**
     * Determine if the given table has given columns
     */
    public function hasColumns(string $table, array $columns): bool
    {
        $tableColumns = array_map('strtolower', $this->getColumnListing($table));

        foreach ($columns as $column) {
            if (!in_array(strtolower($column), $tableColumns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the column listing for a given table
     */
    public function getColumnListing(string $table): array
    {
        $table = $this->connection->getTablePrefix() . $table;
        
        $results = $this->connection->select(
            $this->grammar->compileColumnListing($table),
            [$this->connection->getDatabaseName(), $table]
        );

        return array_map(function ($result) {
            return $result['column_name'] ?? $result['name'] ?? current($result);
        }, $results);
    }

    /**
     * Get the data type for the given column name
     */
    public function getColumnType(string $table, string $column): string
    {
        $table = $this->connection->getTablePrefix() . $table;

        $columns = $this->connection->select(
            "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
            [$table, $column]
        );

        return $columns[0]['data_type'] ?? 'string';
    }

    /**
     * Create a new table on the schema
     */
    public function create(string $table, \Closure $callback): void
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->create();

        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Create a new table on the schema if it doesn't exist
     */
    public function createIfNotExists(string $table, \Closure $callback): void
    {
        if (!$this->hasTable($table)) {
            $this->create($table, $callback);
        }
    }

    /**
     * Modify a table on the schema
     */
    public function table(string $table, \Closure $callback): void
    {
        $blueprint = $this->createBlueprint($table);

        $callback($blueprint);

        $this->build($blueprint);
    }

    /**
     * Rename a table on the schema
     */
    public function rename(string $from, string $to): void
    {
        $sql = $this->grammar->compileRenameTable($from, $to);

        $this->connection->statement($sql);
    }

    /**
     * Drop a table from the schema
     */
    public function drop(string $table): void
    {
        $sql = $this->grammar->compileDropTable($table);

        $this->connection->statement($sql);
    }

    /**
     * Drop a table from the schema if it exists
     */
    public function dropIfExists(string $table): void
    {
        $sql = $this->grammar->compileDropTableIfExists($table);

        $this->connection->statement($sql);
    }

    /**
     * Drop all tables from the database
     */
    public function dropAllTables(): void
    {
        $tables = $this->getAllTables();

        if (empty($tables)) {
            return;
        }

        $this->disableForeignKeyConstraints();

        foreach ($tables as $table) {
            $this->drop($table);
        }

        $this->enableForeignKeyConstraints();
    }

    /**
     * Drop all views from the database
     */
    public function dropAllViews(): void
    {
        $views = $this->getAllViews();

        if (empty($views)) {
            return;
        }

        foreach ($views as $view) {
            $this->connection->statement("DROP VIEW {$view}");
        }
    }

    /**
     * Get all of the table names for the database
     */
    public function getAllTables(): array
    {
        if (method_exists($this->grammar, 'compileGetAllTables')) {
            $results = $this->connection->select($this->grammar->compileGetAllTables());
            
            return array_map(function ($result) {
                return current($result);
            }, $results);
        }

        return [];
    }

    /**
     * Get all of the view names for the database
     */
    public function getAllViews(): array
    {
        if (method_exists($this->grammar, 'compileGetAllViews')) {
            $results = $this->connection->select($this->grammar->compileGetAllViews());
            
            return array_map(function ($result) {
                return current($result);
            }, $results);
        }

        return [];
    }

    /**
     * Enable foreign key constraints
     */
    public function enableForeignKeyConstraints(): void
    {
        if (method_exists($this->grammar, 'compileEnableForeignKeyConstraints')) {
            $this->connection->statement(
                $this->grammar->compileEnableForeignKeyConstraints()
            );
        }
    }

    /**
     * Disable foreign key constraints
     */
    public function disableForeignKeyConstraints(): void
    {
        if (method_exists($this->grammar, 'compileDisableForeignKeyConstraints')) {
            $this->connection->statement(
                $this->grammar->compileDisableForeignKeyConstraints()
            );
        }
    }

    /**
     * Execute the blueprint to build / modify the table
     */
    protected function build(Blueprint $blueprint): void
    {
        $blueprint->build($this->connection, $this->grammar);
    }

    /**
     * Create a new command set with a Closure
     */
    protected function createBlueprint(string $table, ?\Closure $callback = null): Blueprint
    {
        return new Blueprint($table, $callback);
    }

    /**
     * Get the database connection instance
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Set the default string length for migrations
     */
    public static function defaultStringLength(int $length): void
    {
        static::$defaultStringLength = $length;
    }

    /**
     * Get the default string length for migrations
     */
    public static function getDefaultStringLength(): int
    {
        return static::$defaultStringLength;
    }
}
