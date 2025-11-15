<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Drivers;

use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\Column;
use Infocyph\DBLayer\Schema\ForeignKey;
use Infocyph\DBLayer\Schema\SchemaBuilder;

/**
 * PostgreSQL Schema Builder
 *
 * PostgreSQL-specific schema operations.
 *
 * @package Infocyph\DBLayer\Schema\Drivers
 * @author Hasan
 */
final class PostgreSQLSchemaBuilder extends SchemaBuilder
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

        if (!$hasCreate && $blueprint->getColumns() !== []) {
            $sql = $this->compileAdd($blueprint);
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }

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
    // Constraints toggling (transaction-level)
    // --------------------------------------------------------------------

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET CONSTRAINTS ALL DEFERRED');
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('SET CONSTRAINTS ALL IMMEDIATE');
    }

    // --------------------------------------------------------------------
    // Views
    // --------------------------------------------------------------------

    public function dropView(string $view): void
    {
        $this->connection->execute("DROP VIEW IF EXISTS \"{$view}\"");
    }

    // --------------------------------------------------------------------
    // Introspection
    // --------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function getAllTables(): array
    {
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'";

        $results = $this->connection->select($sql);

        return array_column($results, 'table_name');
    }

    /**
     * @return list<string>
     */
    public function getAllViews(): array
    {
        $sql = "SELECT table_name FROM information_schema.views WHERE table_schema = 'public'";

        $results = $this->connection->select($sql);

        return array_column($results, 'table_name');
    }

    /**
     * @return list<string>
     */
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
        $sql = "SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = ? AND column_name = ?
                ) AS exists";

        $result = $this->connection->select($sql, [$table, $column]);

        return (bool) ($result[0]['exists'] ?? false);
    }

    public function hasTable(string $table): bool
    {
        $sql = "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = 'public' AND table_name = ?
                ) AS exists";

        $result = $this->connection->select($sql, [$table]);

        return (bool) ($result[0]['exists'] ?? false);
    }

    // --------------------------------------------------------------------
    // Command compilers
    // --------------------------------------------------------------------

    protected function compileCreate(Blueprint $blueprint, array $command): string
    {
        $table   = $blueprint->getTable();
        $columns = $this->getColumnsForCreate($blueprint);

        return "CREATE TABLE \"{$table}\" (\n  {$columns}\n)";
    }

    protected function compileAdd(Blueprint $blueprint): string
    {
        $columns = $blueprint->getColumns();

        if ($columns === []) {
            return '';
        }

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = 'ADD COLUMN ' . $this->getColumnDefinition($blueprint->getTable(), $column);
        }

        $table = $blueprint->getTable();

        return 'ALTER TABLE "' . $table . '" ' . implode(', ', $parts);
    }

    protected function compileDrop(Blueprint $blueprint, array $command): string
    {
        return "DROP TABLE \"{$blueprint->getTable()}\"";
    }

    protected function compileDropIfExists(Blueprint $blueprint, array $command): string
    {
        return "DROP TABLE IF EXISTS \"{$blueprint->getTable()}\"";
    }

    protected function compileRename(Blueprint $blueprint, array $command): string
    {
        $from = $blueprint->getTable();
        $to   = $command['to'];

        return "ALTER TABLE \"{$from}\" RENAME TO \"{$to}\"";
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
            $parts[] = "DROP COLUMN \"{$col}\"";
        }

        $table = $blueprint->getTable();

        return 'ALTER TABLE "' . $table . '" ' . implode(', ', $parts);
    }

    protected function compileRenameColumn(Blueprint $blueprint, array $command): string
    {
        $from  = $command['from'];
        $to    = $command['to'];
        $table = $blueprint->getTable();

        return "ALTER TABLE \"{$table}\" RENAME COLUMN \"{$from}\" TO \"{$to}\"";
    }

    protected function compileIndex(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_index');

        $colsSql = '"' . implode('","', $columns) . '"';

        return "CREATE INDEX \"{$name}\" ON \"{$table}\" ({$colsSql})";
    }

    protected function compileUnique(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_unique');

        $colsSql = '"' . implode('","', $columns) . '"';

        return "CREATE UNIQUE INDEX \"{$name}\" ON \"{$table}\" ({$colsSql})";
    }

    protected function compilePrimary(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();

        $colsSql = '"' . implode('","', $columns) . '"';

        return "ALTER TABLE \"{$table}\" ADD PRIMARY KEY ({$colsSql})";
    }

    protected function compileDropIndex(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_index');

        return "DROP INDEX IF EXISTS \"{$name}\"";
    }

    protected function compileDropUnique(Blueprint $blueprint, array $command): string
    {
        /** @var list<string> $columns */
        $columns = $command['columns'] ?? [];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_unique');

        return "DROP INDEX IF EXISTS \"{$name}\"";
    }

    protected function compileDropPrimary(Blueprint $blueprint, array $command): string
    {
        $table = $blueprint->getTable();

        return "ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$table}_pkey\"";
        // Assumes default naming for primary key; if you want to support
        // custom names, extend the Blueprint/API accordingly.
    }

    protected function compileForeign(Blueprint $blueprint, array $command): string
    {
        /** @var ForeignKey $fk */
        $fk    = $command['foreignKey'];
        $table = $blueprint->getTable();

        if (!$fk->isValid()) {
            return '';
        }

        $localCols = '"' . implode('","', $fk->getColumns()) . '"';
        $refCols   = '"' . implode('","', $fk->getReferenceColumns()) . '"';
        $refTable  = $fk->getReferenceTable();

        $name = $fk->getName() ?? $table . '_' . implode('_', $fk->getColumns()) . '_foreign';

        $sql = "ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$name}\" FOREIGN KEY ({$localCols}) REFERENCES \"{$refTable}\" ({$refCols})";

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

        return "ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$name}\"";
    }

    protected function compileFulltext(Blueprint $blueprint, array $command): string
    {
        // Map "fulltext" to a GIN index over to_tsvector('simple', concatenated columns).
        /** @var list<string> $columns */
        $columns = $command['columns'];
        $table   = $blueprint->getTable();
        $name    = $command['name'] ?? ($table . '_' . implode('_', $columns) . '_fulltext');

        if ($columns === []) {
            return '';
        }

        // Build expression: COALESCE(col1, '') || ' ' || COALESCE(col2, '') ...
        $exprParts = [];
        foreach ($columns as $col) {
            $exprParts[] = "COALESCE(\"{$col}\", '')";
        }

        $expr = implode(" || ' ' || ", $exprParts);

        return "CREATE INDEX \"{$name}\" ON \"{$table}\" USING GIN (to_tsvector('simple', {$expr}))";
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    /**
     * Column list for CREATE TABLE.
     */
    private function getColumnsForCreate(Blueprint $blueprint): string
    {
        $definitions = [];

        foreach ($blueprint->getColumns() as $column) {
            $definitions[] = $this->getColumnDefinition($blueprint->getTable(), $column);
        }

        return implode(",\n  ", $definitions);
    }

    /**
     * Single column definition for PostgreSQL.
     */
    private function getColumnDefinition(string $table, Column $column): string
    {
        $name       = $column->getName();
        $typeSql    = $this->getTypeName($table, $column);
        $definition = "\"{$name}\" {$typeSql}";

        if ($column->isNullable()) {
            $definition .= ' NULL';
        } else {
            $definition .= ' NOT NULL';
        }

        if ($column->hasDefault()) {
            $default = $column->getDefault();

            if (is_string($default)) {
                $escaped   = str_replace("'", "''", $default);
                $definition .= " DEFAULT '{$escaped}'";
            } elseif ($default === null) {
                $definition .= ' DEFAULT NULL';
            } elseif (is_bool($default)) {
                $definition .= ' DEFAULT ' . ($default ? 'TRUE' : 'FALSE');
            } else {
                $definition .= ' DEFAULT ' . $default;
            }
        }

        return $definition;
    }

    /**
     * Map logical type to PostgreSQL type.
     */
    private function getTypeName(string $table, Column $column): string
    {
        $type   = $column->getType();
        $params = $column->getParameters();
        $name   = $column->getName();

        return match ($type) {
            'string'     => 'VARCHAR(' . ($params['length'] ?? 255) . ')',
            'char'       => 'CHAR(' . ($params['length'] ?? 255) . ')',
            'text', 'mediumText', 'longText' => 'TEXT',

            'integer'    => 'INTEGER',
            'increments' => 'SERIAL',
            'tinyInteger', 'smallInteger', 'smallIncrements' => 'SMALLINT',
            'mediumInteger' => 'INTEGER',
            'bigInteger'    => 'BIGINT',
            'bigIncrements' => 'BIGSERIAL',

            'float'   => 'REAL',
            'double'  => 'DOUBLE PRECISION',
            'decimal' => 'NUMERIC(' . ($params['precision'] ?? 10) . ',' . ($params['scale'] ?? 2) . ')',

            'boolean' => 'BOOLEAN',

            'enum' => 'TEXT CHECK ("' . $name . '" IN (' . $this->quoteEnumValues($params['allowed'] ?? []) . '))',

            'json'  => 'JSON',
            'jsonb' => 'JSONB',

            'date'      => 'DATE',
            'dateTime'  => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'time'      => 'TIME',
            'year'      => 'SMALLINT',

            'binary' => 'BYTEA',
            'uuid'   => 'UUID',

            default => 'VARCHAR(255)',
        };
    }

    /**
     * Quote enum allowed values.
     *
     * @param list<string> $values
     */
    private function quoteEnumValues(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            $parts[] = "'" . str_replace("'", "''", $value) . "'";
        }

        return implode(',', $parts);
    }
}
