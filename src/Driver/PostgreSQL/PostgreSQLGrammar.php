<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * PostgreSQL Grammar
 *
 * PostgreSQL-specific SQL compilation with support for:
 * - Double-quote identifier quoting
 * - RETURNING clause
 * - UPSERT (ON CONFLICT)
 */
final class PostgreSQLGrammar extends Grammar
{
    /**
     * Compile a delete statement with RETURNING.
     *
     * @param  array<int,string>  $returning
     */
    public function compileDeleteReturning(QueryBuilder $query, array $returning = ['*']): string
    {
        $delete  = $this->compileDelete($query);
        $columns = $this->columnize($returning);

        return "{$delete} returning {$columns}";
    }

    /**
     * Compile an insert statement with RETURNING.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     */
    public function compileInsertGetId(
      QueryBuilder $query,
      array $values,
      ?string $sequence = null
    ): string {
        $insert = $this->compileInsert($query, $values);
        $column = $sequence ?? 'id';

        return $insert.' returning '.$this->wrap($column);
    }

    /**
     * Compile a truncate table statement (PostgreSQL-specific).
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);

        return "truncate table {$table} restart identity cascade";
    }

    /**
     * Compile an update statement with RETURNING.
     *
     * @param  array<string,mixed>  $values
     * @param  array<int,string>    $returning
     */
    public function compileUpdateReturning(
      QueryBuilder $query,
      array $values,
      array $returning = ['*']
    ): string {
        $update  = $this->compileUpdate($query, $values);
        $columns = $this->columnize($returning);

        return "{$update} returning {$columns}";
    }

    /**
     * Compile an insert statement with ON CONFLICT (UPSERT).
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @param  array<int,string>                                   $uniqueBy
     * @param  array<string,mixed>|null                            $update
     */
    public function compileUpsert(
      QueryBuilder $query,
      array $values,
      array $uniqueBy,
      ?array $update = null
    ): string {
        $insert   = $this->compileInsert($query, $values);
        $conflict = $this->columnize($uniqueBy);

        if ($update === null) {
            return "{$insert} on conflict ({$conflict}) do nothing";
        }

        $updateColumns = implode(', ', array_map(
          function (string $key): string {
              return $this->wrap($key).' = excluded.'.$this->wrap($key);
          },
          array_keys($update)
        ));

        return "{$insert} on conflict ({$conflict}) do update set {$updateColumns}";
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }

    /**
     * Compile the "limit" portion.
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        unset($query);

        return 'limit '.(int) $limit;
    }

    /**
     * Compile the lock into SQL (PostgreSQL-specific).
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        unset($query);

        return match ($lock) {
            'update' => 'for update',
            'shared' => 'for share',
            default  => '',
        };
    }

    /**
     * Compile the "offset" portion.
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        unset($query);

        return 'offset '.(int) $offset;
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
