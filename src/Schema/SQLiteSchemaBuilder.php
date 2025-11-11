<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * SQLite Schema Builder
 *
 * SQLite-specific schema operations.
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class SQLiteSchemaBuilder extends SchemaBuilder
{
    public function compile(Blueprint $blueprint): array
    {
        // Similar to MySQL/PostgreSQL but with SQLite syntax
        return [];
    }

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('PRAGMA foreign_keys = OFF');
    }

    public function dropView(string $view): void
    {
        $this->connection->execute("DROP VIEW IF EXISTS \"{$view}\"");
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('PRAGMA foreign_keys = ON');
    }

    public function getAllTables(): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
        $results = $this->connection->select($sql);
        return array_column($results, 'name');
    }

    public function getAllViews(): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='view'";
        $results = $this->connection->select($sql);
        return array_column($results, 'name');
    }

    public function getColumnListing(string $table): array
    {
        $sql = "PRAGMA table_info({$table})";
        $results = $this->connection->select($sql);
        return array_column($results, 'name');
    }

    public function getColumnType(string $table, string $column): string
    {
        $sql = "PRAGMA table_info({$table})";
        $results = $this->connection->select($sql);

        foreach ($results as $row) {
            if ($row['name'] === $column) {
                return $row['type'];
            }
        }

        return 'unknown';
    }

    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->getColumnListing($table);
        return in_array($column, $columns);
    }
    public function hasTable(string $table): bool
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
        $result = $this->connection->select($sql, [$table]);
        return !empty($result);
    }
}
