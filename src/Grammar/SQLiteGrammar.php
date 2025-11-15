<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * SQLite Grammar
 *
 * SQLite-specific SQL compilation with support for:
 * - Double-quote identifier quoting
 * - REPLACE INTO / INSERT OR IGNORE
 * - SQLite limitations (no TRUNCATE, limited locking)
 */
final class SQLiteGrammar extends Grammar
{
    /**
     * Compile an INSERT OR IGNORE statement.
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     */
    public function compileInsertOrIgnore(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('insert or ignore', $query, $values);
    }

    /**
     * Compile an INSERT OR REPLACE statement.
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     */
    public function compileInsertOrReplace(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('insert or replace', $query, $values);
    }

    /**
     * Compile a REPLACE statement.
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     */
    public function compileReplace(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('replace', $query, $values);
    }

    /**
     * Compile a truncate table statement (SQLite uses DELETE).
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);

        // SQLite doesn't have TRUNCATE, use DELETE instead.
        return "delete from {$table}";
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile a date-based where clause.
     *
     * @param array{column:string,operator:string} $where
     */
    public function whereDate(QueryBuilder $query, array $where): string
    {
        unset($query);

        return 'date(' . $this->wrap($where['column']) . ') '
          . $where['operator'] . ' date(?)';
    }

    /**
     * Compile a day-based where clause.
     *
     * @param array{column:string,operator:string} $where
     */
    public function whereDay(QueryBuilder $query, array $where): string
    {
        unset($query);

        return "cast(strftime('%d', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile a month-based where clause.
     *
     * @param array{column:string,operator:string} $where
     */
    public function whereMonth(QueryBuilder $query, array $where): string
    {
        unset($query);

        return "cast(strftime('%m', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile a year-based where clause.
     *
     * @param array{column:string,operator:string} $where
     */
    public function whereYear(QueryBuilder $query, array $where): string
    {
        unset($query);

        return "cast(strftime('%Y', {$this->wrap($where['column'])}) as integer) "
          . $where['operator'] . ' cast(? as integer)';
    }

    /**
     * Compile the "limit" portion.
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        unset($query);

        return 'limit ' . (int) $limit;
    }

    /**
     * Compile the lock into SQL (SQLite doesn't support locking).
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        unset($query, $lock);

        // SQLite doesn't support SELECT ... FOR UPDATE.
        return '';
    }

    /**
     * Compile the "offset" portion.
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        unset($query);

        return 'offset ' . (int) $offset;
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
