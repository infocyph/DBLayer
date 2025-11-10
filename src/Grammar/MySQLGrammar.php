<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

/**
 * MySQL Grammar Implementation
 * 
 * Compiles query builder components into MySQL-specific SQL syntax.
 * Handles MySQL-specific features like FORCE INDEX, backtick wrapping,
 * and MySQL locking mechanisms.
 * 
 * @package DBLayer\Grammar
 * @author Hasan
 */
class MySQLGrammar extends Grammar
{
    /**
     * The grammar specific operators
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'like binary', 'not like', 
        'between', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*',
    ];

    /**
     * Compile the lock into SQL
     */
    protected function compileLock(array $query, bool|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? 'FOR UPDATE' : 'LOCK IN SHARE MODE';
    }

    /**
     * Compile an insert and get ID statement into SQL
     */
    public function compileInsertGetId(array $query, array $values, string $sequence = null): string
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * Compile an update statement into SQL
     */
    public function compileUpdate(array $query, array $values): string
    {
        $table = $this->wrapTable($query['from']);

        $columns = [];
        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key) . ' = ' . $this->parameter($value);
        }
        $columns = implode(', ', $columns);

        $joins = '';
        if (isset($query['joins'])) {
            $joins = ' ' . $this->compileJoins($query, $query['joins']);
        }

        $where = $this->compileWheres($query, $query['wheres'] ?? []);

        $sql = rtrim("UPDATE {$table}{$joins} SET {$columns} {$where}");

        if (isset($query['orders'])) {
            $sql .= ' ' . $this->compileOrders($query, $query['orders']);
        }

        if (isset($query['limit'])) {
            $sql .= ' ' . $this->compileLimit($query, $query['limit']);
        }

        return rtrim($sql);
    }

    /**
     * Compile a delete statement into SQL
     */
    public function compileDelete(array $query): string
    {
        $table = $this->wrapTable($query['from']);

        $where = $this->compileWheres($query, $query['wheres'] ?? []);

        $sql = trim("DELETE FROM {$table} {$where}");

        if (isset($query['orders'])) {
            $sql .= ' ' . $this->compileOrders($query, $query['orders']);
        }

        if (isset($query['limit'])) {
            $sql .= ' ' . $this->compileLimit($query, $query['limit']);
        }

        return rtrim($sql);
    }

    /**
     * Compile a truncate table statement into SQL
     */
    public function compileTruncate(array $query): array
    {
        return [
            'SET FOREIGN_KEY_CHECKS=0' => [],
            'TRUNCATE TABLE ' . $this->wrapTable($query['from']) => [],
            'SET FOREIGN_KEY_CHECKS=1' => [],
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

        // Add table options
        if ($blueprint->engine) {
            $sql .= ' ENGINE=' . $blueprint->engine;
        }

        if ($blueprint->charset) {
            $sql .= ' DEFAULT CHARSET=' . $blueprint->charset;
        }

        if ($blueprint->collation) {
            $sql .= ' COLLATE=' . $blueprint->collation;
        }

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

        // Add table options
        if (isset($options['engine'])) {
            $sql .= ' ENGINE=' . $options['engine'];
        }

        if (isset($options['charset'])) {
            $sql .= ' DEFAULT CHARSET=' . $options['charset'];
        }

        if (isset($options['collation'])) {
            $sql .= ' COLLATE=' . $options['collation'];
        }

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
        $sql .= $this->modifyCharset($column);
        $sql .= $this->modifyCollate($column);
        $sql .= $this->modifyComment($column);

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
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'integer' => 'INT',
            'tinyInteger' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => 'MEDIUMINT',
            'bigInteger' => 'BIGINT',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . ($column['precision'] ?? 8) . ',' . ($column['scale'] ?? 2) . ')',
            'boolean' => 'TINYINT(1)',
            'enum' => 'ENUM(' . $this->quoteValues($column['allowed']) . ')',
            'json' => 'JSON',
            'jsonb' => 'JSON',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'datetimeTz' => 'DATETIME',
            'time' => 'TIME',
            'timeTz' => 'TIME',
            'timestamp' => 'TIMESTAMP',
            'timestampTz' => 'TIMESTAMP',
            'year' => 'YEAR',
            'binary' => 'BLOB',
            'uuid' => 'CHAR(36)',
            'ipAddress' => 'VARCHAR(45)',
            'macAddress' => 'VARCHAR(17)',
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
            return ' AUTO_INCREMENT PRIMARY KEY';
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
     * Get the SQL for a character set column modifier
     */
    protected function modifyCharset(array $column): string
    {
        if (isset($column['charset'])) {
            return ' CHARACTER SET ' . $column['charset'];
        }

        return '';
    }

    /**
     * Get the SQL for a collation column modifier
     */
    protected function modifyCollate(array $column): string
    {
        if (isset($column['collation'])) {
            return ' COLLATE ' . $column['collation'];
        }

        return '';
    }

    /**
     * Get the SQL for a comment column modifier
     */
    protected function modifyComment(array $column): string
    {
        if (isset($column['comment'])) {
            return " COMMENT '" . addslashes($column['comment']) . "'";
        }

        return '';
    }

    /**
     * Quote values for ENUM
     */
    protected function quoteValues(array $values): string
    {
        return implode(',', array_map(function ($value) {
            return "'" . addslashes($value) . "'";
        }, $values));
    }

    /**
     * Compile a rename table command
     */
    public function compileRenameTable(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->wrapTable($from) . ' TO ' . $this->wrapTable($to);
    }

    /**
     * Compile an add column command
     */
    public function compileAddColumn(string $table, array $column): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD ' . $this->compileColumn($column);
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
    public function compileRenameColumn(string $table, string $from, string $to, array $definition): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' CHANGE ' . 
               $this->wrap($from) . ' ' . $this->compileColumn(array_merge($definition, ['name' => $to]));
    }

    /**
     * Compile a modify column command
     */
    public function compileModifyColumn(string $table, array $column): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' MODIFY ' . $this->compileColumn($column);
    }

    /**
     * Compile an add primary key command
     */
    public function compileAddPrimary(string $table, array $columns): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD PRIMARY KEY (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop primary key command
     */
    public function compileDropPrimary(string $table): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP PRIMARY KEY';
    }

    /**
     * Compile an add unique key command
     */
    public function compileAddUnique(string $table, string $name, array $columns): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD UNIQUE ' . 
               $this->wrap($name) . ' (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop unique key command
     */
    public function compileDropUnique(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an add index command
     */
    public function compileAddIndex(string $table, string $name, array $columns, string $type = 'index'): string
    {
        $type = strtoupper($type);
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD ' . 
               ($type !== 'INDEX' ? $type . ' ' : '') . 'INDEX ' . 
               $this->wrap($name) . ' (' . $this->columnize($columns) . ')';
    }

    /**
     * Compile a drop index command
     */
    public function compileDropIndex(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP INDEX ' . $this->wrap($name);
    }

    /**
     * Compile an add foreign key command
     */
    public function compileAddForeign(string $table, string $name, array $columns, string $references, string $on, string $onDelete = null, string $onUpdate = null): string
    {
        $sql = 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD CONSTRAINT ' . $this->wrap($name);
        $sql .= ' FOREIGN KEY (' . $this->columnize($columns) . ')';
        $sql .= ' REFERENCES ' . $this->wrapTable($references);

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
        return 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP FOREIGN KEY ' . $this->wrap($name);
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
     * Wrap a single string in keyword identifiers
     */
    protected function wrapValue(string $value): string
    {
        if ($value !== '*') {
            return '`' . str_replace('`', '``', $value) . '`';
        }

        return $value;
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
        return 'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?';
    }
}
