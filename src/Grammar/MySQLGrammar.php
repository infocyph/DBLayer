<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * MySQL Grammar
 *
 * MySQL-specific SQL compilation with support for:
 * - Backtick identifier quoting
 * - MySQL-specific functions
 * - INSERT IGNORE, REPLACE INTO
 * - ON DUPLICATE KEY UPDATE
 *
 * @package Infocyph\DBLayer\Grammar
 * @author Hasan
 */
class MySQLGrammar extends Grammar
{
    /**
     * Compile an insert ignore statement
     */
    public function compileInsertIgnore(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);
        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function ($record) {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "insert ignore into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile an insert statement with ON DUPLICATE KEY UPDATE
     */
    public function compileInsertOnDuplicateKeyUpdate(QueryBuilder $query, array $values, array $update): string
    {
        $insert = $this->compileInsert($query, $values);

        $updateColumns = implode(', ', array_map(function ($key) {
            return $this->wrap($key) . ' = VALUES(' . $this->wrap($key) . ')';
        }, array_keys($update)));

        return "{$insert} on duplicate key update {$updateColumns}";
    }

    /**
     * Compile a replace statement
     */
    public function compileReplace(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);
        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function ($record) {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "replace into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile a truncate table statement (MySQL-specific)
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        return 'truncate table ' . $this->wrapTable($query->getComponents()['from']);
    }

    /**
     * Get the format for database stored dates
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile the "limit" portion with offset support (MySQL-specific)
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        $offset = $query->getComponents()['offset'];

        if ($offset !== null) {
            return "limit {$offset}, {$limit}";
        }

        return "limit {$limit}";
    }

    /**
     * Compile the lock into SQL (MySQL-specific)
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        return match ($lock) {
            'update' => 'for update',
            'shared' => 'lock in share mode',
            default => '',
        };
    }

    /**
     * Compile the "offset" portion (handled by limit in MySQL)
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        // In MySQL, offset is handled in the limit clause
        return '';
    }
    /**
     * Wrap a single string in keyword identifiers
     */
    protected function wrapValue(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }
}
