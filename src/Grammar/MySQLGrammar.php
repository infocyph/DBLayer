<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * MySQL Grammar
 *
 * MySQL-specific SQL compilation with support for:
 * - Backtick identifier quoting
 * - INSERT IGNORE, REPLACE INTO
 * - ON DUPLICATE KEY UPDATE
 */
final class MySQLGrammar extends Grammar
{
    /**
     * Compile an INSERT IGNORE statement.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     */
    public function compileInsertIgnore(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('insert ignore', $query, $values);
    }

    /**
     * Compile an insert statement with ON DUPLICATE KEY UPDATE.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @param  array<string,mixed>  $update
     */
    public function compileInsertOnDuplicateKeyUpdate(
        QueryBuilder $query,
        array $values,
        array $update
    ): string {
        $insert = $this->compileInsert($query, $values);

        $updateColumns = implode(', ', array_map(
            function (string $key): string {
                $wrapped = $this->wrap($key);

                return "{$wrapped} = VALUES({$wrapped})";
            },
            array_keys($update)
        ));

        return "{$insert} on duplicate key update {$updateColumns}";
    }

    /**
     * Compile a REPLACE statement.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     */
    public function compileReplace(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('replace', $query, $values);
    }

    /**
     * Compile a truncate table statement (MySQL-specific).
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $components = $query->getComponents();

        return 'truncate table '.$this->wrapTable($components['from']);
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile the "limit" portion with offset support (MySQL-specific).
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        $components = $query->getComponents();
        $offset = $components['offset'];

        if ($offset !== null) {
            return 'limit '.(int) $offset.', '.(int) $limit;
        }

        return 'limit '.(int) $limit;
    }

    /**
     * Compile the lock into SQL (MySQL-specific).
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        unset($query);

        return match ($lock) {
            'update' => 'for update',
            'shared' => 'lock in share mode',
            default => '',
        };
    }

    /**
     * Compile the "offset" portion (handled by limit in MySQL).
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        unset($query, $offset);

        // In MySQL, offset is encoded into the LIMIT clause.
        return '';
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }
}
