<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

/**
 * PostgreSQL Grammar Implementation
 * 
 * Compiles query builder components into PostgreSQL-specific SQL syntax.
 * Handles PostgreSQL-specific features like RETURNING clauses, array operations,
 * and PostgreSQL's advanced data types.
 * 
 * @package DBLayer\Grammar
 * @author Hasan
 */
class PostgreSQLGrammar extends Grammar
{
    /**
     * The grammar specific operators
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike', 'not ilike',
        '~', '~*', '!~', '!~*',
        'similar to', 'not similar to',
        '&&', '||', '@>', '<@', '?', '?|', '?&',
    ];

    /**
     * Compile the lock into SQL
     */
    protected function compileLock(array $query, bool|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? 'FOR UPDATE' : 'FOR SHARE';
    }

    /**
     * Compile an insert and get ID statement into SQL
     */
    public function compileInsertGetId(array $query, array $values, string $sequence = null): string
    {
        $sql = $this->compileInsert($query, $values);

        if ($sequence === null) {
            $sequence = 'id';
        }

        return $sql . ' RETURNING ' . $this->wrap($sequence);
    }

    /**
     * Compile a truncate table statement into SQL
     */
    public function compileTruncate(array $query): array
    {
        return ['TRUNCATE TABLE ' . $this->wrapTable($query['from']) . ' RESTART IDENTITY CASCADE' => []];
    }

    /**
     * Compile a create table command
     */
    public function compileCreate(Blueprint $blueprint): string
    {
        $columns = $blueprint->getColumns();
        $table = $this->wrapTable($blueprint->getTable());
        
        $columnDefinitions = [];
        foreach ($columns as $column) {
            $columnDefinitions[] = $this->compileColumn($column->toArray());
        }
        
        $sql = 'CREATE TABLE ' . $table . ' (';
        $sql .= implode(', ', $columnDefinitions);
        $sql .= ')';

        return $sql;
    }

    /**
     * Compile the SQL statement to define a table
     */
    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $sql = 'CREATE TABLE ' . $this->wrapTable($table) . ' (';
        
        $columnDefinitions = [];
        foreach ($columns as $column) {
            $columnDefinitions[] = $this->compileColumn($column);
        }
        
        $sql .= implode(', ', $columnDefinitions);
        $sql .= ')';

        return $sql;
    }

    /**
     * Compile a column definition
     */
    protected function compileColumn(array $column): string
    {
        $sql = $this->wrap($column['name']) . ' ' . $this->getType($column);

        // Add modifiers
        $sql .= $this->modifyDefault($column);
        $sql .= $this->modifyNullable($column);
        $sql .= $this->modifyIncrement($column);

        return $sql;
    }

    /**
     * Get the SQL for the column data type
     */
    protected function getType(array $column): string
    {
        return match ($column['type']) {
            'char' => 'CHAR(' . ($column['length'] ?? 255) . ')',
            'string' => 'VARCHAR(' . ($column['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'mediumText' => 'TEXT',
            'longText' => 'TEXT',
            'integer' => 'INTEGER',
            'tinyInteger' => 'SMALLINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'DECIMAL(' . ($column['precision'] ?? 8) . ',' . ($column['scale'] ?? 2) . ')',
            'boolean' => 'BOOLEAN',
            'enum' => 'VARCHAR(255)',
            'json' => 'JSON',
            'jsonb' => 'JSONB',
            'date' => 'DATE',
            'datetime' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'datetimeTz' => 'TIMESTAMP(0) WITH TIME ZONE',
            'time' => 'TIME(0) WITHOUT TIME ZONE',
            'timeTz' => 'TIME(0) WITH TIME ZONE',
            'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'timestampTz' => 'TIMESTAMP(0) WITH TIME ZONE',
            'year' => 'INTEGER',
            'binary' => 'BYTEA',
            'uuid' => 'UUID',
            'ipAddress' => 'INET',
            'macAddress' => 'MACADDR',
            'geometry' => 'GEOMETRY',
            'point' => 'POINT',
            'lineString' => 'LINESTRING',
            'polygon' => 'POLYGON',
            default => strtoupper($column['type']),
        };
    }

    /**
     * Get the SQL for a nullable column modifier
     */
    protected function modifyNullable(array $column): string
    {
        if (isset($column['nullable']) && $column['nullable'] === true) {
            return ' NULL';
        }

        return ' NOT NULL';
    }

    /**
     * Get the SQL for a default column modifier
     */
    protected function modifyDefault(array $column): string
    {
        if (isset($column['default'])) {
            if ($column['default'] === null) {
                return ' DEFAULT NULL';
            }

            if (is_bool($column['default'])) {
                return ' DEFAULT ' . ($column['default'] ? 'TRUE' : 'FALSE');
            }

            if (is_string($column['default'])) {
                return " DEFAULT '" . addslashes($column['default']) . "'";
            }

            return ' DEFAULT ' . $column['default'];
        }

        return '';
    }

    /**
     * Get the SQL for an auto increment column modifier
     */
    protected function modifyIncrement(array $column): string
    {
        if (isset($column['autoIncrement']) && $column['autoIncrement'] === true) {
            return ' PRIMARY KEY GENERATED ALWAYS AS IDENTITY';
        }

        if (isset($column['primary']) && $column['primary'] === true) {
            return ' PRIMARY KEY';
        }

        if (isset($column['unique']) && $column['unique'] === true) {
            return ' UNIQUE';
        }

        return '';
    }

    /**
     * Compile a rename table command
     */
    public function compileRenameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($from) . ' RENAME TO ' . $this->wrapTable($to);
    }

    /**
     * Compile an add column command
     */
    public function compileAddColumn(string $table, array $column): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD COLUMN ' . $this->compileColumn($column);
    }

    /**
     * Compile a drop column command
     */
    public function compileDropColumn(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP COLUMN ' . $this->wrap($column);
    }

    /**
     * Compile a rename column command
     */
    public function compileRenameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' RENAME COLUMN ' . 
               $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    /**
     * Compile a modify column command
     */
    public function compileModifyColumn(string $table, array $column): string
    {
        $changes = [];

        // Change type
        $changes[] = 'ALTER TABLE ' . $this->wrapTable($table) . ' ALTER COLUMN ' . 
                     $this->wrap($column['name']) . ' TYPE ' . $this->getType($column);

        // Change default
        if (isset($column['default'])) {
            $default = $this->modifyDefault($column);
            $changes[] = 'ALTER TABLE ' . $this->wrapTable($table) . ' ALTER COLUMN ' . 
                         $this->wrap($column['name']) . ' SET' . $default;
        }

        // Change nullable
        $nullable = isset($column['nullable']) && $column['nullable'] ? 'DROP NOT NULL' : 'SET NOT NULL';
        $changes[] = 'ALTER TABLE ' . $this->wrapTable($table) . ' ALTER COLUMN ' . 
                     $this->wrap($column['name']) . ' ' . $nullable;

        return implode('; ', $changes);
    }

    /**
     * Compile an add primary key command
     */
    public function compileAddPrimary(string $table, array $columns, string $name = null): string
    {
        $constraintName = $name ?: $table . '_pkey';
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD CONSTRAINT ' . 
               $this->wrap($constraintName) . ' PRIMARY KEY (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop primary key command
     */
    public function compileDropPrimary(string $table, string $name = null): string
    {
        $constraintName = $name ?: $table . '_pkey';
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP CONSTRAINT ' . $this->wrap($constraintName);
    }

    /**
     * Compile an add unique key command
     */
    public function compileAddUnique(string $table, string $name, array $columns): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD CONSTRAINT ' . 
               $this->wrap($name) . ' UNIQUE (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop unique key command
     */
    public function compileDropUnique(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP CONSTRAINT ' . $this->wrap($name);
    }

    /**
     * Compile an add index command
     */
    public function compileAddIndex(string $table, string $name, array $columns, string $type = 'btree'): string
    {
        return 'CREATE INDEX ' . $this->wrap($name) . ' ON ' . $this->wrapTable($table) . 
               ' USING ' . $type . ' (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop index command
     */
    public function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an add foreign key command
     */
    public function compileAddForeign(string $table, string $name, array $columns, string $references, string $on, string $onDelete = null, string $onUpdate = null): string
    {
        $sql = 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD CONSTRAINT ' . $this->wrap($name);
        $sql .= ' FOREIGN KEY (' . $this->columnize($columns) . ')';
        $sql .= ' REFERENCES ' . $this->wrapTable($references) . ' (' . $on . ')';

        if ($onDelete) {
            $sql .= ' ON DELETE ' . strtoupper($onDelete);
        }

        if ($onUpdate) {
            $sql .= ' ON UPDATE ' . strtoupper($onUpdate);
        }

        return $sql;
    }

    /**
     * Compile a drop foreign key command
     */
    public function compileDropForeign(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP CONSTRAINT ' . $this->wrap($name);
    }

    /**
     * Compile an add foreign key command from Blueprint
     */
    public function compileForeign(Blueprint $blueprint, array $command): string
    {
        $sql = 'ALTER TABLE ' . $this->wrapTable($blueprint->getTable());
        $sql .= ' ADD CONSTRAINT ' . $this->wrap($command['name']);
        $sql .= ' FOREIGN KEY (' . $this->columnize($command['columns']) . ')';
        $sql .= ' REFERENCES ' . $this->wrapTable($command['on']) . ' (' . $command['references'] . ')';

        if (isset($command['onDelete'])) {
            $sql .= ' ON DELETE ' . strtoupper($command['onDelete']);
        }

        if (isset($command['onUpdate'])) {
            $sql .= ' ON UPDATE ' . strtoupper($command['onUpdate']);
        }

        return $sql;
    }

    /**
     * Compile an enable foreign key constraints statement
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL IMMEDIATE';
    }

    /**
     * Compile a disable foreign key constraints statement
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL DEFERRED';
    }

    /**
     * Compile an exists statement into SQL
     */
    public function compileTableExists(): string
    {
        return "SELECT * FROM information_schema.tables WHERE table_schema = ? AND table_name = ? AND table_type = 'BASE TABLE'";
    }

    /**
     * Compile a column listing query
     */
    public function compileColumnListing(string $table): string
    {
        return 'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position';
    }

    /**
     * Compile the SQL needed to retrieve all table names
     */
    public function compileGetAllTables(): string
    {
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'";
    }

    /**
     * Compile the SQL needed to retrieve all view names
     */
    public function compileGetAllViews(): string
    {
        return "SELECT viewname FROM pg_catalog.pg_views WHERE schemaname = 'public'";
    }
}
