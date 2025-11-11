<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * PostgreSQL Grammar
 *
 * PostgreSQL-specific SQL compilation with support for:
 * - Double-quote identifier quoting
 * - RETURNING clause
 * - PostgreSQL-specific functions
 * - Array operations
 * - JSON/JSONB operations
 *
 * @package Infocyph\DBLayer\Grammar
 * @author Hasan
 */
class PostgreSQLGrammar extends Grammar
{
    /**
     * Compile a delete statement with RETURNING
     */
    public function compileDeleteReturning(QueryBuilder $query, array $returning = ['*']): string
    {
        $delete = $this->compileDelete($query);
        $columns = $this->columnize($returning);

        return "{$delete} returning {$columns}";
    }

    /**
     * Compile an insert statement with RETURNING
     */
    public function compileInsertGetId(QueryBuilder $query, array $values, ?string $sequence = null): string
    {
        $insert = $this->compileInsert($query, $values);

        return $insert . ' returning ' . ($sequence ?? 'id');
    }

    /**
     * Compile a truncate table statement (PostgreSQL-specific)
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);

        return "truncate table {$table} restart identity cascade";
    }

    /**
     * Compile an update statement with RETURNING
     */
    public function compileUpdateReturning(QueryBuilder $query, array $values, array $returning = ['*']): string
    {
        $update = $this->compileUpdate($query, $values);
        $columns = $this->columnize($returning);

        return "{$update} returning {$columns}";
    }

    /**
     * Compile an insert statement with ON CONFLICT (UPSERT)
     */
    public function compileUpsert(QueryBuilder $query, array $values, array $uniqueBy, ?array $update = null): string
    {
        $insert = $this->compileInsert($query, $values);

        $conflict = $this->columnize($uniqueBy);

        if ($update === null) {
            return "{$insert} on conflict ({$conflict}) do nothing";
        }

        $updateColumns = implode(', ', array_map(function ($key) {
            return $this->wrap($key) . ' = excluded.' . $this->wrap($key);
        }, array_keys($update)));

        return "{$insert} on conflict ({$conflict}) do update set {$updateColumns}";
    }

    /**
     * Get the format for database stored dates
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }

    /**
     * Compile the "limit" portion
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        return "limit {$limit}";
    }

    /**
     * Compile the lock into SQL (PostgreSQL-specific)
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        return match ($lock) {
            'update' => 'for update',
            'shared' => 'for share',
            default => '',
        };
    }

    /**
     * Compile the "offset" portion
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        return "offset {$offset}";
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
