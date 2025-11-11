<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * SQL Grammar Base
 *
 * Abstract base class for database-specific SQL compilation.
 * Provides common methods and structure for grammar implementations.
 *
 * @package Infocyph\DBLayer\Grammar
 * @author Hasan
 */
abstract class Grammar
{
    /**
     * The components that make up a select clause
     */
    protected array $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    ];
    /**
     * The grammar table prefix
     */
    protected string $tablePrefix = '';

    /**
     * Wrap a single string in keyword identifiers
     */
    abstract protected function wrapValue(string $value): string;

    /**
     * Convert an array of column names into a delimited string
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    /**
     * Compile a delete statement
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);
        $where = $this->compileWheres($query, $query->getComponents()['wheres']);

        return trim("delete from {$table} {$where}");
    }

    /**
     * Compile an insert statement
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);
        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function ($record) {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "insert into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile a select query
     */
    public function compileSelect(QueryBuilder $query): string
    {
        if ($query->getComponents()['aggregate'] !== null) {
            return $this->compileAggregate($query);
        }

        $sql = $this->concatenate($this->compileComponents($query));

        if ($unions = $query->getComponents()['unions']) {
            $sql = $this->wrapUnion($sql) . ' ' . $this->compileUnions($query);
        }

        return $sql;
    }

    /**
     * Compile a truncate table statement
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        return 'truncate table ' . $this->wrapTable($query->getComponents()['from']);
    }

    /**
     * Compile an update statement
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $table = $this->wrapTable($query->getComponents()['from']);

        $columns = implode(', ', array_map(function ($value, $key) {
            return $this->wrap($key) . ' = ?';
        }, array_values($values), array_keys($values)));

        $where = $this->compileWheres($query, $query->getComponents()['wheres']);

        return trim("update {$table} set {$columns} {$where}");
    }

    /**
     * Get the format for database stored dates
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the table prefix
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Create query parameter place-holders for an array
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map(fn ($value) => '?', $values));
    }

    /**
     * Set the table prefix
     */
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Wrap a value in keyword identifiers
     */
    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }

        // Handle table.column format
        if (str_contains($value, '.')) {
            return $this->wrapSegments(explode('.', $value));
        }

        // Handle raw values with * or AS
        if (str_contains($value, '*') || str_contains(strtolower($value), ' as ')) {
            return $value;
        }

        return $this->wrapValue($value);
    }

    /**
     * Compile an aggregated select clause
     */
    protected function compileAggregate(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $aggregate = $components['aggregate'];
        $column = $aggregate['column'] === '*' ? '*' : $this->wrap($aggregate['column']);

        $sql = $this->compileColumns($query, [new Expression("{$aggregate['function']}({$column}) as aggregate")]);

        if (isset($components['from'])) {
            $sql .= ' ' . $this->compileFrom($query, $components['from']);
        }

        if ($components['joins']) {
            $sql .= ' ' . $this->compileJoins($query, $components['joins']);
        }

        if ($components['wheres']) {
            $sql .= ' ' . $this->compileWheres($query, $components['wheres']);
        }

        if ($components['groups']) {
            $sql .= ' ' . $this->compileGroups($query, $components['groups']);
        }

        if ($components['havings']) {
            $sql .= ' ' . $this->compileHavings($query, $components['havings']);
        }

        return $sql;
    }

    /**
     * Compile the "select *" portion of the query
     */
    protected function compileColumns(QueryBuilder $query, array $columns): string
    {
        if ($query->getComponents()['distinct']) {
            $select = 'select distinct ';
        } else {
            $select = 'select ';
        }

        return $select . $this->columnize($columns);
    }

    /**
     * Compile the components necessary for a select clause
     */
    protected function compileComponents(QueryBuilder $query): array
    {
        $sql = [];
        $components = $query->getComponents();

        foreach ($this->selectComponents as $component) {
            if (isset($components[$component]) && $components[$component] !== null && $components[$component] !== []) {
                $method = 'compile' . ucfirst($component);

                if (method_exists($this, $method)) {
                    $sql[$component] = $this->$method($query, $components[$component]);
                }
            }
        }

        return $sql;
    }

    /**
     * Compile the "from" portion of the query
     */
    protected function compileFrom(QueryBuilder $query, string $table): string
    {
        return 'from ' . $this->wrapTable($table);
    }

    /**
     * Compile the "group by" portion of the query
     */
    protected function compileGroups(QueryBuilder $query, array $groups): string
    {
        return 'group by ' . $this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query
     */
    protected function compileHavings(QueryBuilder $query, array $havings): string
    {
        $sql = implode(' ', array_map(function ($having, $i) {
            $boolean = $i === 0 ? '' : $having['boolean'] . ' ';

            return $boolean . $this->wrap($having['column']) . ' ' . $having['operator'] . ' ?';
        }, $havings, array_keys($havings)));

        return 'having ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Compile a single join clause
     */
    protected function compileJoinClause(JoinClause $join): string
    {
        $table = $this->wrapTable($join->getTable());
        $type = strtoupper($join->getType());
        $conditions = $join->getConditions();

        if (empty($conditions)) {
            return "{$type} join {$table}";
        }

        $clauses = [];

        foreach ($conditions as $i => $condition) {
            $boolean = $i === 0 ? '' : " {$condition['boolean']} ";

            if ($condition['type'] === 'basic') {
                $clauses[] = $boolean . "{$this->wrap($condition['first'])} {$condition['operator']} {$this->wrap($condition['second'])}";
            } elseif ($condition['type'] === 'where') {
                $clauses[] = $boolean . "{$this->wrap($condition['column'])} {$condition['operator']} ?";
            }
        }

        return "{$type} join {$table} on " . implode('', $clauses);
    }

    /**
     * Compile the "join" portions of the query
     */
    protected function compileJoins(QueryBuilder $query, array $joins): string
    {
        return implode(' ', array_map(function ($join) {
            if ($join instanceof JoinClause) {
                return $this->compileJoinClause($join);
            }

            $table = $this->wrapTable($join['table']);
            $type = strtoupper($join['type']);

            if (isset($join['first'])) {
                return "{$type} join {$table} on {$this->wrap($join['first'])} {$join['operator']} {$this->wrap($join['second'])}";
            }

            return "{$type} join {$table}";
        }, $joins));
    }

    /**
     * Compile the "limit" portion of the query
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        return 'limit ' . (int) $limit;
    }

    /**
     * Compile the lock into SQL
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        return $lock === 'update' ? 'for update' : 'lock in share mode';
    }

    /**
     * Compile the "offset" portion of the query
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        return 'offset ' . (int) $offset;
    }

    /**
     * Compile the "order by" portion of the query
     */
    protected function compileOrders(QueryBuilder $query, array $orders): string
    {
        $sql = implode(', ', array_map(function ($order) {
            return $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
        }, $orders));

        return 'order by ' . $sql;
    }

    /**
     * Compile a single union statement
     */
    protected function compileUnion(array $union): string
    {
        $keyword = $union['all'] ? 'union all' : 'union';

        return $keyword . ' ' . $this->wrapUnion($this->compileSelect($union['query']));
    }

    /**
     * Compile the union queries
     */
    protected function compileUnions(QueryBuilder $query): string
    {
        $sql = '';

        foreach ($query->getComponents()['unions'] as $union) {
            $sql .= $this->compileUnion($union);
        }

        return ltrim($sql);
    }

    /**
     * Compile the "where" portions of the query
     */
    protected function compileWheres(QueryBuilder $query, array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $sql = $this->concatenateWhereClauses($query, $wheres);

        return 'where ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Concatenate an array of segments, removing empties
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, fn ($value) => $value !== ''));
    }

    /**
     * Concatenate where clauses
     */
    protected function concatenateWhereClauses(QueryBuilder $query, array $wheres): string
    {
        return implode(' ', array_map(function ($where, $i) use ($query) {
            $boolean = $i === 0 ? '' : $where['boolean'] . ' ';

            return $boolean . $this->{'where' . ucfirst($where['type'])}($query, $where);
        }, $wheres, array_keys($wheres)));
    }

    /**
     * Remove the leading boolean from a statement
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return preg_replace('/and |or /i', '', $value, 1);
    }

    /**
     * Compile a basic where clause
     */
    protected function whereBasic(QueryBuilder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    /**
     * Compile a "where between" clause
     */
    protected function whereBetween(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'between ? and ?';
    }

    /**
     * Compile a "where exists" clause
     */
    protected function whereExists(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not . 'exists (' . $this->compileSelect($where['query']) . ')';
    }

    /**
     * Compile a "where in" clause
     */
    protected function whereIn(QueryBuilder $query, array $where): string
    {
        $values = $this->parameterize($where['values']);
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'in (' . $values . ')';
    }

    /**
     * Compile a nested where clause
     */
    protected function whereNested(QueryBuilder $query, array $where): string
    {
        $nested = $this->compileWheres($where['query'], $where['query']->getComponents()['wheres']);

        return '(' . substr($nested, 6) . ')';
    }

    /**
     * Compile a "where null" clause
     */
    protected function whereNull(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' is ' . $not . 'null';
    }

    /**
     * Compile a raw where clause
     */
    protected function whereRaw(QueryBuilder $query, array $where): string
    {
        return $where['sql'];
    }

    /**
     * Wrap the given value segments
     */
    protected function wrapSegments(array $segments): string
    {
        return implode('.', array_map(function ($segment) {
            return $segment === '*' ? $segment : $this->wrapValue($segment);
        }, $segments));
    }

    /**
     * Wrap table name
     */
    protected function wrapTable(string $table): string
    {
        if ($table instanceof Expression) {
            return $table->getValue();
        }

        return $this->wrap($this->tablePrefix . $table);
    }

    /**
     * Wrap a union subquery in parentheses
     */
    protected function wrapUnion(string $sql): string
    {
        return '(' . $sql . ')';
    }
}
