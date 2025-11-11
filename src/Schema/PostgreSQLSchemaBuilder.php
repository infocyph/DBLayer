<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * PostgreSQL Schema Builder
 *
 * PostgreSQL-specific schema operations.
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class PostgreSQLSchemaBuilder extends SchemaBuilder
{
    public function compile(Blueprint $blueprint): array
    {
        // Similar to MySQL but with PostgreSQL syntax
        return [];
    }

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET CONSTRAINTS ALL DEFERRED');
    }

    public function dropView(string $view): void
    {
        $this->connection->execute("DROP VIEW IF EXISTS \"{$view}\"");
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function getAllTables(): array
    {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'";
        $results = $this->connection->select($sql);
        return array_column($results, 'table_name');
    }

    public function getAllViews(): array
    {
        $sql = "SELECT table_name FROM information_schema.views WHERE table_schema = 'public'";
        $results = $this->connection->select($sql);
        return array_column($results, 'table_name');
    }

    public function getColumnListing(string $table): array
    {
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position";
        $results = $this->connection->select($sql, [$table]);
        return array_column($results, 'column_name');
    }

    public function getColumnType(string $table, string $column): string
    {
        $sql = "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?";
        $result = $this->connection->select($sql, [$table, $column]);
        return $result[0]['data_type'] ?? 'unknown';
    }

    public function hasColumn(string $table, string $column): bool
    {
        $sql = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?)";
        $result = $this->connection->select($sql, [$table, $column]);
        return $result[0]['exists'] ?? false;
    }
    public function hasTable(string $table): bool
    {
        $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?)";
        $result = $this->connection->select($sql, [$table]);
        return $result[0]['exists'] ?? false;
    }
}
