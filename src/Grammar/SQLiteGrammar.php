<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * SQLite Grammar
 *
 * SQLite-specific SQL compilation with support for:
 * - Double-quote identifier quoting
 * - SQLite-specific functions
 * - REPLACE INTO
 * - SQLite limitations (no RIGHT JOIN, etc.)
 *
 * @package Infocyph\DBLayer\Grammar
 * @author Hasan
 */
class SQLiteGrammar extends Grammar
{
    /**
     * Compile an insert or ignore statement
     */
    public function compileInsertOrIgnore(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table = $this->wrapTable($components['from']);
        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function (array $record): string {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "insert or ignore into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile an insert or replace statement
     */
    public function compileInsertOrReplace(QueryBuilder $query, array $values): string
    {
        return $this->compileReplace($query, $values);
    }

    /**
     * Compile a replace statement
     */
    public function compileReplace(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table = $this->wrapTable($components['from']);
        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function (array $record): string {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "replace into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile a truncate table statement (SQLite uses DELETE)
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $table = $this->wrapTable($components['from']);

        // SQLite doesn't have TRUNCATE, use DELETE instead
        return "delete from {$table}";
    }

    /**
     * Get the format for database stored dates
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile a date-based where clause
     */
    public function whereDate(QueryBuilder $query, array $where): string
    {
        return 'date(' . $this->wrap($where['column']) . ') '
          . $where['operator'] . ' date(?)';
    }

    /**
     * Compile a day-based where clause
     */
    public function whereDay(QueryBuilder $query, array $where): string
    {
        return "cast(strftime('%d', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile a month-based where clause
     */
    public function whereMonth(QueryBuilder $query, array $where): string
    {
        return "cast(strftime('%m', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile a year-based where clause
     */
    public function whereYear(QueryBuilder $query, array $where): string
    {
        return "cast(strftime('%Y', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile the "limit" portion
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        return 'limit ' . (int) $limit;
    }

    /**
     * Compile the lock into SQL (SQLite doesn't support locking)
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        // SQLite doesn't support SELECT ... FOR UPDATE
        return '';
    }

    /**
     * Compile the "offset" portion
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        return 'offset ' . (int) $offset;
    }

    /**
     * Wrap a single string in keyword identifiers
     */
    protected function wrapValue(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
