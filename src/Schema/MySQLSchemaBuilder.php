<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * MySQL Schema Builder
 *
 * MySQL-specific schema operations.
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
class MySQLSchemaBuilder extends SchemaBuilder
{
    public function compile(Blueprint $blueprint): array
    {
        $statements = [];
        $commands = $blueprint->getCommands();

        foreach ($commands as $command) {
            $method = 'compile' . ucfirst($command['name']);
            if (method_exists($this, $method)) {
                $sql = $this->$method($blueprint, $command);
                if ($sql) {
                    $statements[] = $sql;
                }
            }
        }

        return $statements;
    }

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET FOREIGN_KEY_CHECKS=0');
    }

    public function dropView(string $view): void
    {
        $this->connection->execute("DROP VIEW IF EXISTS `{$view}`");
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET FOREIGN_KEY_CHECKS=1');
    }

    public function getAllTables(): array
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'";
        $results = $this->connection->select($sql, [$database]);
        return array_column($results, 'table_name');
    }

    public function getAllViews(): array
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT table_name FROM information_schema.views WHERE table_schema = ?";
        $results = $this->connection->select($sql, [$database]);
        return array_column($results, 'table_name');
    }

    public function getColumnListing(string $table): array
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position";
        $results = $this->connection->select($sql, [$database, $table]);
        return array_column($results, 'column_name');
    }

    public function getColumnType(string $table, string $column): string
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?";
        $result = $this->connection->select($sql, [$database, $table, $column]);
        return $result[0]['data_type'] ?? 'unknown';
    }

    public function hasColumn(string $table, string $column): bool
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?";
        $result = $this->connection->select($sql, [$database, $table, $column]);
        return ($result[0]['COUNT(*)'] ?? 0) > 0;
    }
    public function hasTable(string $table): bool
    {
        $database = $this->connection->getDatabaseName();
        $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";
        $result = $this->connection->select($sql, [$database, $table]);
        return ($result[0]['COUNT(*)'] ?? 0) > 0;
    }

    protected function compileCreate(Blueprint $blueprint, array $command): string
    {
        $table = $blueprint->getTable();
        $columns = $this->getColumns($blueprint);

        $sql = "CREATE TABLE `{$table}` (\n  {$columns}\n)";

        if ($engine = $blueprint->getEngine()) {
            $sql .= " ENGINE={$engine}";
        }

        if ($charset = $blueprint->getCharset()) {
            $sql .= " DEFAULT CHARSET={$charset}";
        }

        if ($collation = $blueprint->getCollation()) {
            $sql .= " COLLATE={$collation}";
        }

        return $sql;
    }

    protected function compileDrop(Blueprint $blueprint, array $command): string
    {
        return "DROP TABLE `{$blueprint->getTable()}`";
    }

    protected function compileDropIfExists(Blueprint $blueprint, array $command): string
    {
        return "DROP TABLE IF EXISTS `{$blueprint->getTable()}`";
    }

    protected function compileRename(Blueprint $blueprint, array $command): string
    {
        return "RENAME TABLE `{$blueprint->getTable()}` TO `{$command['to']}`";
    }

    private function getColumnDefinition(Column $column): string
    {
        $definition = "`{$column->getName()}` {$this->getTypeName($column)}";

        if ($column->isUnsigned()) {
            $definition .= ' UNSIGNED';
        }

        if (!$column->isNullable()) {
            $definition .= ' NOT NULL';
        }

        if ($column->hasDefault()) {
            $default = $column->getDefault();
            $definition .= ' DEFAULT ' . (is_string($default) ? "'{$default}'" : $default);
        }

        if ($column->isAutoIncrement()) {
            $definition .= ' AUTO_INCREMENT';
        }

        return $definition;
    }

    private function getColumns(Blueprint $blueprint): string
    {
        $definitions = [];

        foreach ($blueprint->getColumns() as $column) {
            $definitions[] = $this->getColumnDefinition($column);
        }

        return implode(",\n  ", $definitions);
    }

    private function getTypeName(Column $column): string
    {
        $type = $column->getType();
        $params = $column->getParameters();

        return match ($type) {
            'string' => "VARCHAR({$params['length']})",
            'char' => "CHAR({$params['length']})",
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'integer', 'increments' => 'INT',
            'tinyInteger', 'smallIncrements' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => 'MEDIUMINT',
            'bigInteger', 'bigIncrements' => 'BIGINT',
            'float' => "FLOAT({$params['precision']},{$params['scale']})",
            'double' => "DOUBLE({$params['precision']},{$params['scale']})",
            'decimal' => "DECIMAL({$params['precision']},{$params['scale']})",
            'boolean' => 'TINYINT(1)',
            'enum' => "ENUM('" . implode("','", $params['allowed']) . "')",
            'json' => 'JSON',
            'date' => 'DATE',
            'dateTime', 'timestamp' => 'DATETIME',
            'time' => 'TIME',
            'year' => 'YEAR',
            'binary' => 'BLOB',
            'uuid' => 'CHAR(36)',
            default => 'VARCHAR(255)',
        };
    }
}
