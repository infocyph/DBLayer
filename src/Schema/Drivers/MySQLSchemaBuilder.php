<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Drivers;

use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\Column;
use Infocyph\DBLayer\Schema\ForeignKey;
use Infocyph\DBLayer\Schema\SchemaBuilder;

/**
 * MySQL Schema Builder
 *
 * MySQL-specific schema operations.
 *
 * @package Infocyph\DBLayer\Schema\Drivers
 * @author Hasan
 */
final class MySQLSchemaBuilder extends SchemaBuilder
{
    /**
     * Compile a Blueprint to a list of SQL statements.
     *
     * @return list<string>
     */
    public function compile(Blueprint $blueprint): array
    {
        $statements = [];
        $commands   = $blueprint->getCommands();

        $hasCreate = false;

        foreach ($commands as $command) {
            if (($command['name'] ?? null) === 'create') {
                $hasCreate   = true;
                $statements[] = $this->compileCreate($blueprint, $command);
                break;
            }
        }

        // If we are modifying an existing table (no create) and have new columns,
        // generate a single ALTER TABLE ... ADD COLUMN ... statement.
        if (!$hasCreate && $blueprint->getColumns() !== []) {
            $sql = $this->compileAdd($blueprint);
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }

        // Compile all other commands as ALTER / DROP / CREATE INDEX, etc.
        foreach ($commands as $command) {
            $name = $command['name'] ?? null;

            if ($name === null || $name === 'create') {
                continue;
            }

            $method = 'compile' . ucfirst($name);

            if (!method_exists($this, $method)) {
                continue;
            }

            $sql = $this->{$method}($blueprint, $command);

            if (is_string($sql) && $sql !== '') {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    // --------------------------------------------------------------------
    // Foreign key constraint toggling
    // --------------------------------------------------------------------

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET FOREIGN_KEY_CHECKS=0');
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET FOREIGN_KEY_CHECKS=1');
    }

    // --------------------------------------------------------------------
    // Views
    // --------------------------------------------------------------------

    public function dropView(string $view): void
    {
        $this->connection->execute("DROP VIEW IF EXISTS `{$view}`");
    }

    // --------------------------------------------------------------------
    // Introspection
    // --------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function getAllTables(): array
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'";

        $results = $this->connection->select($sql, [$database]);

        return array_column($results, 'table_name');
    }

    /**
     * @return list<string>
     */
    public function getAllViews(): array
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT table_name FROM information_schema.views WHERE table_schema = ?";

        $results = $this->connection->select($sql, [$database]);

        return array_column($results, 'table_name');
    }

    /**
     * @return list<string>
     */
    public function getColumnListing(string $table): array
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position";

        $results = $this->connection->select($sql, [$database, $table]);

        return array_column($results, 'column_name');
    }

    public function getColumnType(string $table, string $column): string
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?";

        $result = $this->connection->select($sql, [$database, $table, $column]);

        return $result[0]['data_type'] ?? 'unknown';
    }

    public function hasColumn(string $table, string $column): bool
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT COUNT(*) AS aggregate FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?";

        $result = $this->connection->select($sql, [$database, $table, $column]);

        return (int) ($result[0]['aggregate'] ?? 0) > 0;
    }

    public function hasTable(string $table): bool
    {
        $database = $this->connection->getDatabaseName();
        $sql      = "SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";

        $result = $this->connection->select($sql, [$database, $table]);

        return (int) ($result[0]['aggregate'] ?? 0) > 0;
    }

    // --------------------------------------------------------------------
    // Command compilers
    // --------------------------------------------------------------------

    /**
     * CREATE TABLE.
     */
    protected function compileCreate(Blueprint $blueprint, array $command): string
    {
        $table   = $blueprint->getTable();
        $columns = $this->getColumnsForCreate($blueprint);

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

    /**
     * ALTER TABLE ... ADD COLUMN ...
     */
    protected function compileAdd(Blueprint $blueprint): string
    {
        $columns = $blueprint->getColumns();

        if ($columns === []) {
            return '';
        }

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = 'ADD COLUMN ' . $this->getColumnDefinition($column);
        }

        $table = $blueprint->getTable();

        return 'ALTER TABLE `' . $table . '` ' . implode(', ', $parts);
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

    protected function compileDropColumn(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];

        if ($columns === []) {
            return '';
        }

        $parts = [];

        foreach ($columns as $col) {
            $parts[] = "DROP COLUMN `{$col}`";
        }

        $table = $blueprint->getTable();

        return 'ALTER TABLE `' . $table . '` ' . implode(', ', $parts);
    }

    protected function compileRenameColumn(Blueprint $blueprint, array $command): string
    {
        $from = $command['from'];
        $to   = $command['to'];
        $table = $blueprint->getTable();

        // MySQL 8+ supports RENAME COLUMN.
        return "ALTER TABLE `{$table}` RENAME COLUMN `{$from}` TO `{$to}`";
    }

    protected function compileIndex(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_index');

        $colsSql = '`' . implode('`,`', $columns) . '`';

        return "ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$colsSql})";
    }

    protected function compileUnique(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_unique');

        $colsSql = '`' . implode('`,`', $columns) . '`';

        return "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$name}` ({$colsSql})";
    }

    protected function compilePrimary(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();

        $colsSql = '`' . implode('`,`', $columns) . '`';

        return "ALTER TABLE `{$table}` ADD PRIMARY KEY ({$colsSql})";
    }

    protected function compileDropIndex(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_index');

        return "DROP INDEX `{$name}` ON `{$table}`";
    }

    protected function compileDropUnique(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_unique');

        return "DROP INDEX `{$name}` ON `{$table}`";
    }

    protected function compileDropPrimary(Blueprint $blueprint, array $command): string
    {
        $table = $blueprint->getTable();

        return "ALTER TABLE `{$table}` DROP PRIMARY KEY";
    }

    protected function compileForeign(Blueprint $blueprint, array $command): string
    {
        /** @var ForeignKey $fk */
        $fk    = $command['foreignKey'];
        $table = $blueprint->getTable();

        if (!$fk->isValid()) {
            return '';
        }

        $localCols = '`' . implode('`,`', $fk->getColumns()) . '`';
        $refCols   = '`' . implode('`,`', $fk->getReferenceColumns()) . '`';
        $refTable  = $fk->getReferenceTable();

        $name = $fk->getName() ?? $table . '_' . implode('_', $fk->getColumns()) . '_foreign';

        $sql = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY ({$localCols}) REFERENCES `{$refTable}` ({$refCols})";

        if ($fk->getOnDelete()) {
            $sql .= ' ON DELETE ' . $fk->getOnDelete();
        }

        if ($fk->getOnUpdate()) {
            $sql .= ' ON UPDATE ' . $fk->getOnUpdate();
        }

        return $sql;
    }

    protected function compileDropForeign(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_foreign');

        return "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`";
    }

    protected function compileFulltext(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_fulltext');

        $colsSql = '`' . implode('`,`', $columns) . '`';

        return "ALTER TABLE `{$table}` ADD FULLTEXT `{$name}` ({$colsSql})";
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    /**
     * Column list (for CREATE TABLE).
     */
    private function getColumnsForCreate(Blueprint $blueprint): string
    {
        $definitions = [];

        foreach ($blueprint->getColumns() as $column) {
            $definitions[] = $this->getColumnDefinition($column);
        }

        return implode(",\n  ", $definitions);
    }

    /**
     * Single column definition for MySQL.
     */
    private function getColumnDefinition(Column $column): string
    {
        $definition = "`{$column->getName()}` {$this->getTypeName($column)}";

        if ($column->isUnsigned()) {
            $definition .= ' UNSIGNED';
        }

        if ($column->isNullable()) {
            $definition .= ' NULL';
        } else {
            $definition .= ' NOT NULL';
        }

        $useCurrent         = (bool) $column->getAttribute('useCurrent', false);
        $useCurrentOnUpdate = (bool) $column->getAttribute('useCurrentOnUpdate', false);

        if ($useCurrent) {
            $definition .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault()) {
            $default = $column->getDefault();

            if (is_string($default)) {
                $escaped   = str_replace("'", "''", $default);
                $definition .= " DEFAULT '{$escaped}'";
            } elseif ($default === null) {
                $definition .= ' DEFAULT NULL';
            } elseif (is_bool($default)) {
                $definition .= ' DEFAULT ' . ($default ? '1' : '0');
            } else {
                $definition .= ' DEFAULT ' . $default;
            }
        }

        if ($useCurrentOnUpdate) {
            $definition .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($column->isAutoIncrement()) {
            $definition .= ' AUTO_INCREMENT';
        }

        return $definition;
    }

    /**
     * Map logical column type to MySQL type.
     */
    private function getTypeName(Column $column): string
    {
        $type   = $column->getType();
        $params = $column->getParameters();

        return match ($type) {
            'string'     => 'VARCHAR(' . ($params['length'] ?? 255) . ')',
            'char'       => 'CHAR(' . ($params['length'] ?? 255) . ')',
            'text'       => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText'   => 'LONGTEXT',

            'integer', 'increments'           => 'INT',
            'tinyInteger'                     => 'TINYINT',
            'smallInteger', 'smallIncrements' => 'SMALLINT',
            'mediumInteger'                   => 'MEDIUMINT',
            'bigInteger', 'bigIncrements'     => 'BIGINT',

            'float'   => 'FLOAT(' . ($params['precision'] ?? 8) . ',' . ($params['scale'] ?? 2) . ')',
            'double'  => 'DOUBLE(' . ($params['precision'] ?? 15) . ',' . ($params['scale'] ?? 8) . ')',
            'decimal' => 'DECIMAL(' . ($params['precision'] ?? 10) . ',' . ($params['scale'] ?? 2) . ')',

            'boolean' => 'TINYINT(1)',
            'enum'    => "ENUM('" . implode("','", $params['allowed'] ?? []) . "')",
            'json'    => 'JSON',

            'date'      => 'DATE',
            'dateTime'  => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time'      => 'TIME',
            'year'      => 'YEAR',

            'binary' => 'BLOB',
            'uuid'   => 'CHAR(36)',

            default => 'VARCHAR(255)',
        };
    }
}
