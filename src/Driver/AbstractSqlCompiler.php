<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver;

use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Expression;
use LogicException;

/**
 * Generic AST-based SQL compiler.
 *
 * Currently focuses on SELECT + aggregates to keep things safe:
 * - INSERT/UPDATE/DELETE/TRUNCATE intentionally unsupported here for now.
 * - Engine-specific subclasses handle identifier quoting.
 */
abstract class AbstractSqlCompiler implements QueryCompilerInterface
{
    /**
     * Quote an identifier for the current dialect.
     *
     * Implemented by concrete compilers (e.g. MySQL/PostgreSQL/SQLite).
     */
    abstract protected function wrapIdentifier(string $identifier): string;

    public function compile(QueryPayload $payload): CompiledQuery
    {
        $type = $payload->type;

        if ($type !== QueryType::SELECT) {
            throw new LogicException(
              sprintf(
                'Only SELECT queries are supported by %s currently (got %s).',
                static::class,
                $type->value
              )
            );
        }

        $sql = $this->compileSelect($payload);

        return new CompiledQuery($sql, $payload->bindings, $payload->type);
    }

    protected function compileFrom(QueryPayload $payload): string
    {
        if ($payload->table === null || $payload->table === '') {
            return '';
        }

        return 'FROM '.$this->wrapIdentifier($payload->table);
    }

    protected function compileGroupBy(QueryPayload $payload): string
    {
        if ($payload->groups === []) {
            return '';
        }

        $columns = [];

        foreach ($payload->groups as $column) {
            $columns[] = $this->wrapIdentifier((string) $column);
        }

        return 'GROUP BY '.implode(', ', $columns);
    }

    protected function compileHavings(QueryPayload $payload): string
    {
        if ($payload->havings === []) {
            return '';
        }

        $segments = [];
        $first    = true;

        foreach ($payload->havings as $having) {
            /** @var array<string,mixed> $having */
            $column   = (string) ($having['column'] ?? '');
            $operator = (string) ($having['operator'] ?? '=');
            $boolean  = strtolower((string) ($having['boolean'] ?? 'and'));
            $boolean  = $boolean === 'or' ? 'OR' : 'AND';

            if ($column === '') {
                continue;
            }

            $segment = sprintf(
              '%s %s ?',
              $this->wrapIdentifier($column),
              $operator
            );

            if (! $first) {
                $segments[] = $boolean.' '.$segment;
            } else {
                $segments[] = $segment;
                $first      = false;
            }
        }

        return implode(' ', $segments);
    }

    protected function compileJoins(QueryPayload $payload): string
    {
        if ($payload->joins === []) {
            return '';
        }

        $sql = '';

        foreach ($payload->joins as $join) {
            if (is_array($join)) {
                $type  = strtolower((string) ($join['type'] ?? 'inner'));
                $table = (string) ($join['table'] ?? '');

                $type = match ($type) {
                    'left'  => 'LEFT',
                    'right' => 'RIGHT',
                    'cross' => 'CROSS',
                    default => 'INNER',
                };

                $sql .= sprintf(' %s JOIN %s', $type, $this->wrapIdentifier($table));

                if (isset($join['first'], $join['operator'], $join['second'])) {
                    $sql .= sprintf(
                      ' ON %s %s %s',
                      (string) $join['first'],
                      (string) $join['operator'],
                      (string) $join['second'],
                    );
                }
            } elseif (is_object($join) && method_exists($join, '__toString')) {
                $sql .= ' '.(string) $join;
            } else {
                throw new LogicException('Unsupported JOIN representation in payload.');
            }
        }

        return ltrim($sql);
    }

    protected function compileLimitOffset(QueryPayload $payload): string
    {
        $limit  = $payload->limit;
        $offset = $payload->offset;

        if ($limit === null && $offset === null) {
            return '';
        }

        $sql = '';

        if ($limit !== null) {
            $sql .= 'LIMIT '.$limit;
        }

        if ($offset !== null) {
            if ($sql !== '') {
                $sql .= ' ';
            }

            $sql .= 'OFFSET '.$offset;
        }

        return $sql;
    }

    protected function compileOrderBy(QueryPayload $payload): string
    {
        if ($payload->orders === []) {
            return '';
        }

        $segments = [];

        foreach ($payload->orders as $order) {
            /** @var array{column:string,direction:string} $order */
            $column    = $order['column'];
            $direction = strtoupper($order['direction']);

            if ($direction !== 'ASC' && $direction !== 'DESC') {
                $direction = 'ASC';
            }

            $segments[] = sprintf(
              '%s %s',
              $this->wrapIdentifier($column),
              $direction
            );
        }

        return 'ORDER BY '.implode(', ', $segments);
    }

    /**
     * Compile a SELECT (including aggregates, where, group, order, limit).
     */
    protected function compileSelect(QueryPayload $payload): string
    {
        $sqlParts = [];

        $sqlParts[] = $this->compileSelectList($payload);
        $sqlParts[] = $this->compileFrom($payload);

        $joins = $this->compileJoins($payload);
        if ($joins !== '') {
            $sqlParts[] = $joins;
        }

        $where = $this->compileWheres($payload);
        if ($where !== '') {
            $sqlParts[] = 'WHERE '.$where;
        }

        $groupBy = $this->compileGroupBy($payload);
        if ($groupBy !== '') {
            $sqlParts[] = $groupBy;
        }

        $having = $this->compileHavings($payload);
        if ($having !== '') {
            $sqlParts[] = 'HAVING '.$having;
        }

        $orderBy = $this->compileOrderBy($payload);
        if ($orderBy !== '') {
            $sqlParts[] = $orderBy;
        }

        $limitOffset = $this->compileLimitOffset($payload);
        if ($limitOffset !== '') {
            $sqlParts[] = $limitOffset;
        }

        return implode(' ', array_filter($sqlParts));
    }

    /**
     * SELECT list (normal columns or aggregate).
     */
    protected function compileSelectList(QueryPayload $payload): string
    {
        $aggregate = $payload->aggregate;

        if ($aggregate !== null) {
            $function = strtoupper($aggregate['function'] ?? '');
            $column   = $aggregate['column'] ?? '*';

            if ($column !== '*') {
                $column = $this->wrapIdentifier((string) $column);
            }

            return sprintf('SELECT %s(%s) AS aggregate', $function, $column);
        }

        $columns = $payload->columns;

        if ($columns === []) {
            return 'SELECT *';
        }

        $parts = [];

        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                $parts[] = $this->expressionToSql($column);
            } elseif (is_string($column)) {
                // crude heuristic: avoid wrapping obviously raw expressions
                if ($column === '*' || str_contains($column, '(') || str_contains($column, ' ')) {
                    $parts[] = $column;
                } else {
                    $parts[] = $this->wrapIdentifier($column);
                }
            } else {
                $parts[] = (string) $column;
            }
        }

        return 'SELECT '.implode(', ', $parts);
    }

    /**
     * @param  array<string,mixed>  $where
     */
    protected function compileWhereBasic(array $where): string
    {
        $column   = (string) ($where['column'] ?? '');
        $operator = (string) ($where['operator'] ?? '=');

        if ($column === '') {
            return '';
        }

        return sprintf(
          '%s %s ?',
          $this->wrapIdentifier($column),
          $operator
        );
    }

    /**
     * @param  array<string,mixed>  $where
     */
    protected function compileWhereBetween(array $where): string
    {
        $column = (string) ($where['column'] ?? '');
        /** @var array{0:mixed,1:mixed}|list<mixed> $values */
        $values = $where['values'] ?? [];
        $not    = (bool) ($where['not'] ?? false);

        if ($column === '' || count($values) < 2) {
            return '';
        }

        return sprintf(
          '%s %sBETWEEN ? AND ?',
          $this->wrapIdentifier($column),
          $not ? 'NOT ' : ''
        );
    }

    /**
     * @param  array<string,mixed>  $where
     */
    protected function compileWhereIn(array $where): string
    {
        $column = (string) ($where['column'] ?? '');
        /** @var list<mixed> $values */
        $values = $where['values'] ?? [];
        $not    = (bool) ($where['not'] ?? false);

        if ($column === '' || $values === []) {
            return '';
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return sprintf(
          '%s %sIN (%s)',
          $this->wrapIdentifier($column),
          $not ? 'NOT ' : '',
          $placeholders
        );
    }

    /**
     * @param  array<string,mixed>  $where
     */
    protected function compileWhereNull(array $where): string
    {
        $column = (string) ($where['column'] ?? '');
        $not    = (bool) ($where['not'] ?? false);

        if ($column === '') {
            return '';
        }

        return sprintf(
          '%s IS %sNULL',
          $this->wrapIdentifier($column),
          $not ? 'NOT ' : ''
        );
    }

    /**
     * @param  array<string,mixed>  $where
     */
    protected function compileWhereRaw(array $where): string
    {
        return (string) ($where['sql'] ?? '');
    }

    protected function compileWheres(QueryPayload $payload): string
    {
        if ($payload->wheres === []) {
            return '';
        }

        $segments = [];
        $first    = true;

        foreach ($payload->wheres as $where) {
            /** @var array<string,mixed> $where */
            $type    = $where['type'] ?? 'basic';
            $boolean = strtolower((string) ($where['boolean'] ?? 'and'));

            $boolean = $boolean === 'or' ? 'OR' : 'AND';

            $segment = match ($type) {
                'basic'   => $this->compileWhereBasic($where),
                'in'      => $this->compileWhereIn($where),
                'between' => $this->compileWhereBetween($where),
                'null'    => $this->compileWhereNull($where),
                'raw'     => $this->compileWhereRaw($where),
                default   => throw new LogicException("Unsupported WHERE type: {$type}"),
            };

            if ($segment === '') {
                continue;
            }

            if (! $first) {
                $segments[] = $boolean.' '.$segment;
            } else {
                $segments[] = $segment;
                $first      = false;
            }
        }

        return implode(' ', $segments);
    }

    protected function expressionToSql(Expression $expression): string
    {
        // Prefer explicit accessor if available (your Expression has getValue()).
        if (method_exists($expression, 'getValue')) {
            /** @var mixed $value */
            $value = $expression->getValue();

            return (string) $value;
        }

        if (method_exists($expression, '__toString')) {
            return (string) $expression;
        }

        return '';
    }
}
