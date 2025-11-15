<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Drivers;

use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\Column;
use Infocyph\DBLayer\Schema\ForeignKey;
use Infocyph\DBLayer\Schema\SchemaBuilder;

/**
 * SQLite Schema Builder
 *
 * SQLite-specific schema operations.
 *
 * NOTE: This assumes SQLite >= 3.25 for RENAME COLUMN and
 * SQLite >= 3.35 for DROP COLUMN. Older versions would need
 * the "create temp table → copy → drop → rename" dance, which
 * is intentionally not implemented here to keep things lean.
 *
 * @package Infocyph\DBLayer\Schema\Drivers
 * @author Hasan
 */
final class SQLiteSchemaBuilder extends SchemaBuilder
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
    // FK constraints toggling
    // --------------------------------------------------------------------

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->execute('PRAGMA foreign_keys = OFF');
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->execute('PRAGMA foreign_keys = ON');
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
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";

        $results = $this->connection->select($sql);

        return array_column($results, 'name');
    }

    /**
     * @return list<string>
     */
    public function getAllViews(): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='view'";

        $results = $this->connection->select($sql);

        return array_column($results, 'name');
    }

    /**
     * @return list<string>
     */
    public function getColumnListing(string $table): array
    {
        $sql = "PRAGMA table_info(\"{$table}\")";

        $results = $this->connection->select($sql);

        return array_column($results, 'name');
    }

    public function getColumnType(string $table, string $column): string
    {
        $sql = "PRAGMA table_info(\"{$table}\")";

        $results = $this->connection->select($sql);

        foreach ($results as $row) {
            if (($row['name'] ?? null) === $column) {
                return $row['type'] ?? 'unknown';
            }
        }

        return 'unknown';
    }

    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->getColumnListing($table);

        return in_array($column, $columns, true);
    }

    public function hasTable(string $table): bool
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";

        $result = $this->connection->select($sql, [$table]);

        return !empty($result);
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

        // SQLite supports only one ADD COLUMN per statement.
        // We therefore emit only the first here; if you want more,
        // call Schema::table in multiple steps.
        $column = reset($columns);

        if (!$column instanceof Column) {
            return '';
        }

        $table = $blueprint->getTable();

        return 'ALTER TABLE "' . $table . '" ADD COLUMN ' . $this->getColumnDefinition($column);
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

        // SQLite >= 3.35: DROP COLUMN <name>
        // For multiple columns, you need multiple calls.
        $col   = $columns[0];
        $table = $blueprint->getTable();

        return "ALTER TABLE \"{$table}\" DROP COLUMN \"{$col}\"";
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
        // SQLite cannot add PRIMARY KEY via ALTER TABLE in a simple way.
        // Proper support requires table recreation which we intentionally
        // do not implement here. Returning empty string is safer than
        // pretending.
        return '';
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
        // Same story as compilePrimary: non-trivial in SQLite.
        return '';
    }

    protected function compileForeign(Blueprint $blueprint, array $command): string
    {
        // SQLite cannot add/drop FK constraints with simple ALTER TABLE.
        // You need table recreation. We keep this unimplemented on purpose.
        /** @var ForeignKey $fk */
        $fk = $command['foreignKey'];

        if (!$fk->isValid()) {
            return '';
        }

        return '';
    }

    protected function compileDropForeign(Blueprint $blueprint, array $command): string
    {
        // Same story as compileForeign: requires table recreation.
        return '';
    }

    protected function compileFulltext(Blueprint $blueprint, array $command): string
    {
        // Proper FULLTEXT in SQLite requires FTS3/4/5 virtual tables;
        // we intentionally do not fake it here.
        return '';
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    private function getColumnsForCreate(Blueprint $blueprint): string
    {
        $definitions = [];

        foreach ($blueprint->getColumns() as $column) {
            $definitions[] = $this->getColumnDefinition($column);
        }

        return implode(",\n  ", $definitions);
    }

    private function getColumnDefinition(Column $column): string
    {
        $name       = $column->getName();
        $typeSql    = $this->getTypeName($column);
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
                $definition .= ' DEFAULT ' . ($default ? '1' : '0');
            } else {
                $definition .= ' DEFAULT ' . $default;
            }
        }

        if ($column->isAutoIncrement()) {
            // In SQLite, INTEGER PRIMARY KEY implies rowid. We do not
            // inject PRIMARY KEY here; you should define it explicitly
            // via Blueprint->primary() at table creation time.
        }

        return $definition;
    }

    private function getTypeName(Column $column): string
    {
        $type   = $column->getType();
        $params = $column->getParameters();

        // SQLite is very lax with types; we map to canonical ones.
        return match ($type) {
            'string'     => 'VARCHAR(' . ($params['length'] ?? 255) . ')',
            'char'       => 'CHAR(' . ($params['length'] ?? 255) . ')',
            'text', 'mediumText', 'longText' => 'TEXT',

            'integer', 'increments', 'tinyInteger', 'smallInteger', 'smallIncrements',
            'mediumInteger', 'bigInteger', 'bigIncrements' => 'INTEGER',

            'float', 'double', 'decimal' => 'REAL',

            'boolean' => 'INTEGER', // 0/1

            'json', 'jsonb' => 'TEXT',
            'date'         => 'DATE',
            'dateTime', 'timestamp' => 'DATETIME',
            'time'         => 'TIME',
            'year'         => 'INTEGER',

            'binary' => 'BLOB',
            'uuid'   => 'CHAR(36)',

            'enum' => 'TEXT',

            default => 'TEXT',
        };
    }
}
