<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

/**
 * SQLite Grammar Implementation
 * 
 * Compiles query builder components into SQLite-specific SQL syntax.
 * Handles SQLite's unique limitations and features, including its
 * limited ALTER TABLE support and specific data type handling.
 * 
 * @package DBLayer\Grammar
 * @author Hasan
 */
class SQLiteGrammar extends Grammar
{
    /**
     * The grammar specific operators
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'glob',
        'match', 'regexp',
    ];

    /**
     * Compile the lock into SQL
     */
    protected function compileLock(array $query, bool|string $value): string
    {
        return '';
    }

    /**
     * Compile an insert and get ID statement into SQL
     */
    public function compileInsertGetId(array $query, array $values, string $sequence = null): string
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * Compile a truncate table statement into SQL
     */
    public function compileTruncate(array $query): array
    {
        return [
            'DELETE FROM ' . $this->wrapTable($query['from']) => [],
            "DELETE FROM sqlite_sequence WHERE name = ?" => [$query['from']],
        ];
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
        $sql .= $this->modifyNullable($column);
        $sql .= $this->modifyDefault($column);
        $sql .= $this->modifyIncrement($column);

        return $sql;
    }

    /**
     * Get the SQL for the column data type
     */
    protected function getType(array $column): string
    {
        return match ($column['type']) {
            'char' => 'VARCHAR',
            'string' => 'VARCHAR',
            'text' => 'TEXT',
            'mediumText' => 'TEXT',
            'longText' => 'TEXT',
            'integer' => 'INTEGER',
            'tinyInteger' => 'INTEGER',
            'smallInteger' => 'INTEGER',
            'mediumInteger' => 'INTEGER',
            'bigInteger' => 'INTEGER',
            'float' => 'REAL',
            'double' => 'REAL',
            'decimal' => 'NUMERIC',
            'boolean' => 'INTEGER',
            'enum' => 'VARCHAR',
            'json' => 'TEXT',
            'jsonb' => 'TEXT',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'datetimeTz' => 'DATETIME',
            'time' => 'TIME',
            'timeTz' => 'TIME',
            'timestamp' => 'DATETIME',
            'timestampTz' => 'DATETIME',
            'year' => 'INTEGER',
            'binary' => 'BLOB',
            'uuid' => 'VARCHAR',
            'ipAddress' => 'VARCHAR',
            'macAddress' => 'VARCHAR',
            default => strtoupper($column['type']),
        };
    }

    /**
     * Get the SQL for a nullable column modifier
     */
    protected function modifyNullable(array $column): string
    {
        if (isset($column['nullable']) && $column['nullable'] === true) {
            return '';
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
                return ' DEFAULT ' . ($column['default'] ? '1' : '0');
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
            return ' PRIMARY KEY AUTOINCREMENT';
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
     * Note: SQLite doesn't support DROP COLUMN directly until version 3.35.0
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
     * Compile an add primary key command
     * Note: SQLite requires table recreation for adding primary keys
     */
    public function compileAddPrimary(string $table, array $columns): string
    {
        throw new \RuntimeException('SQLite does not support adding primary keys to existing tables. Table must be recreated.');
    }

    /**
     * Compile a drop primary key command
     */
    public function compileDropPrimary(string $table): string
    {
        throw new \RuntimeException('SQLite does not support dropping primary keys. Table must be recreated.');
    }

    /**
     * Compile an add unique key command
     */
    public function compileAddUnique(string $table, string $name, array $columns): string
    {
        return 'CREATE UNIQUE INDEX ' . $this->wrap($name) . ' ON ' . 
               $this->wrapTable($table) . ' (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop unique key command
     */
    public function compileDropUnique(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an add index command
     */
    public function compileAddIndex(string $table, string $name, array $columns, string $type = 'index'): string
    {
        $unique = strtolower($type) === 'unique' ? 'UNIQUE ' : '';
        return 'CREATE ' . $unique . 'INDEX ' . $this->wrap($name) . ' ON ' . 
               $this->wrapTable($table) . ' (' . $this->columnize($columns) . ')';
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
     * Note: SQLite requires FOREIGN KEY to be defined at table creation
     */
    public function compileAddForeign(string $table, string $name, array $columns, string $references, string $on, string $onDelete = null, string $onUpdate = null): string
    {
        throw new \RuntimeException('SQLite does not support adding foreign keys to existing tables. Foreign keys must be defined at table creation.');
    }

    /**
     * Compile a drop foreign key command
     */
    public function compileDropForeign(string $table, string $name): string
    {
        throw new \RuntimeException('SQLite does not support dropping foreign keys. Table must be recreated.');
    }

    /**
     * Compile an enable foreign key constraints statement
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = ON';
    }

    /**
     * Compile a disable foreign key constraints statement
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    /**
     * Compile an exists statement into SQL
     */
    public function compileTableExists(): string
    {
        return "SELECT * FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    /**
     * Compile a column listing query
     */
    public function compileColumnListing(string $table): string
    {
        return 'PRAGMA table_info(' . $this->wrapTable($table) . ')';
    }

    /**
     * Compile the SQL needed to retrieve all table names
     */
    public function compileGetAllTables(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    }

    /**
     * Compile the SQL needed to retrieve all view names
     */
    public function compileGetAllViews(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name";
    }

    /**
     * Wrap a single string in keyword identifiers
     */
    protected function wrapValue(string $value): string
    {
        if ($value !== '*') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
