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
     *
     * Order matters for generated SQL.
     *
     * @var string[]
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
     * The grammar table prefix.
     */
    protected string $tablePrefix = '';

    /**
     * Wrap a single string in keyword identifiers.
     */
    abstract protected function wrapValue(string $value): string;

    /**
     * Convert an array of column names into a delimited string.
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    /**
     * Compile a delete statement.
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);
        $where      = $this->compileWheres($query, $components['wheres']);

        return trim("delete from {$table} {$where}");
    }

    /**
     * Compile an insert statement.
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);
        $columns    = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function (array $record): string {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "insert into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile a select query.
     */
    public function compileSelect(QueryBuilder $query): string
    {
        $components = $query->getComponents();

        if ($components['aggregate'] !== null) {
            return $this->compileAggregate($query);
        }

        $sql = $this->concatenate($this->compileComponents($query));

        if ($components['unions']) {
            $sql = $this->wrapUnion($sql) . ' ' . $this->compileUnions($query);
        }

        return $sql;
    }

    /**
     * Compile a truncate table statement.
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        $components = $query->getComponents();

        return 'truncate table ' . $this->wrapTable($components['from']);
    }

    /**
     * Compile an update statement.
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);

        $columns = implode(', ', array_map(function ($value, string $key): string {
            return $this->wrap($key) . ' = ?';
        }, array_values($values), array_keys($values)));

        $where = $this->compileWheres($query, $components['wheres']);

        return trim("update {$table} set {$columns} {$where}");
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Create query parameter placeholders for an array.
     */
    public function parameterize(array $values): string
    {
        if ($values === []) {
            return '';
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Set the table prefix.
     */
    public function setTablePrefix(string $prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Wrap a value in keyword identifiers.
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

        // Handle raw values with * or AS (already full SQL fragments)
        if (str_contains($value, '*') || str_contains(strtolower($value), ' as ')) {
            return $value;
        }

        return $this->wrapValue($value);
    }

    /**
     * Compile an aggregated select clause.
     */
    protected function compileAggregate(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $aggregate  = $components['aggregate'];

        $column = $aggregate['column'] === '*'
          ? '*'
          : $this->wrap($aggregate['column']);

        $sql = $this->compileColumns(
          $query,
          [new Expression("{$aggregate['function']}({$column}) as aggregate")]
        );

        if (isset($components['from']) && $components['from'] !== null) {
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
     * Compile the "select *" portion of the query.
     */
    protected function compileColumns(QueryBuilder $query, array $columns): string
    {
        $components = $query->getComponents();

        $select = $components['distinct'] ? 'select distinct ' : 'select ';

        return $select . $this->columnize($columns);
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @return array<string,string>
     */
    protected function compileComponents(QueryBuilder $query): array
    {
        $sql        = [];
        $components = $query->getComponents();

        foreach ($this->selectComponents as $component) {
            if (!array_key_exists($component, $components)) {
                continue;
            }

            $value = $components[$component];

            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $method = 'compile' . ucfirst($component);

            if (method_exists($this, $method)) {
                /** @var string $compiled */
                $compiled = $this->$method($query, $value);

                if ($compiled !== '') {
                    $sql[$component] = $compiled;
                }
            }
        }

        return $sql;
    }

    /**
     * Compile the "from" portion of the query.
     */
    protected function compileFrom(QueryBuilder $query, string $table): string
    {
        return 'from ' . $this->wrapTable($table);
    }

    /**
     * Compile the "group by" portion of the query.
     */
    protected function compileGroups(QueryBuilder $query, array $groups): string
    {
        return 'group by ' . $this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     */
    protected function compileHavings(QueryBuilder $query, array $havings): string
    {
        $sql = implode(' ', array_map(function (array $having, int $i): string {
            $boolean = $i === 0 ? '' : $having['boolean'] . ' ';

            return $boolean
              . $this->wrap($having['column'])
              . ' ' . $having['operator'] . ' ?';
        }, $havings, array_keys($havings)));

        return 'having ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Compile a single join clause.
     */
    protected function compileJoinClause(JoinClause $join): string
    {
        $table      = $this->wrapTable($join->getTable());
        $type       = strtoupper($join->getType());
        $conditions = $join->getConditions();

        if ($conditions === []) {
            return "{$type} join {$table}";
        }

        $clauses = [];

        foreach ($conditions as $i => $condition) {
            $boolean = $i === 0 ? '' : ' ' . $condition['boolean'] . ' ';

            switch ($condition['type']) {
                case 'basic':
                    $clauses[] = $boolean
                      . $this->wrap($condition['first'])
                      . ' ' . $condition['operator'] . ' '
                      . $this->wrap($condition['second']);
                    break;

                case 'where':
                    $clauses[] = $boolean
                      . $this->wrap($condition['column'])
                      . ' ' . $condition['operator'] . ' ?';
                    break;

                case 'whereIn':
                    $placeholders = $this->parameterize($condition['values']);
                    $clauses[]    = $boolean
                      . $this->wrap($condition['column'])
                      . ' in (' . $placeholders . ')';
                    break;

                case 'whereNull':
                    $clauses[] = $boolean
                      . $this->wrap($condition['column'])
                      . ' is null';
                    break;

                case 'whereNotNull':
                    $clauses[] = $boolean
                      . $this->wrap($condition['column'])
                      . ' is not null';
                    break;
            }
        }

        return "{$type} join {$table} on " . implode('', $clauses);
    }

    /**
     * Compile the "join" portions of the query.
     */
    protected function compileJoins(QueryBuilder $query, array $joins): string
    {
        return implode(' ', array_map(function ($join): string {
            if ($join instanceof JoinClause) {
                return $this->compileJoinClause($join);
            }

            $table = $this->wrapTable($join['table']);
            $type  = strtoupper($join['type']);

            if (isset($join['first'])) {
                return "{$type} join {$table} on {$this->wrap($join['first'])} {$join['operator']} {$this->wrap($join['second'])}";
            }

            return "{$type} join {$table}";
        }, $joins));
    }

    /**
     * Compile the "limit" portion of the query.
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        return 'limit ' . (int) $limit;
    }

    /**
     * Compile the lock into SQL.
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        return $lock === 'update' ? 'for update' : 'lock in share mode';
    }

    /**
     * Compile the "offset" portion of the query.
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        return 'offset ' . (int) $offset;
    }

    /**
     * Compile the "order by" portion of the query.
     */
    protected function compileOrders(QueryBuilder $query, array $orders): string
    {
        $sql = implode(', ', array_map(function (array $order): string {
            return $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
        }, $orders));

        return 'order by ' . $sql;
    }

    /**
     * Compile a single union statement.
     */
    protected function compileUnion(array $union): string
    {
        $keyword = $union['all'] ? 'union all' : 'union';

        return $keyword . ' ' . $this->wrapUnion($this->compileSelect($union['query']));
    }

    /**
     * Compile the union queries.
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
     * Compile the "where" portions of the query.
     */
    protected function compileWheres(QueryBuilder $query, array $wheres): string
    {
        if ($wheres === []) {
            return '';
        }

        $sql = $this->concatenateWhereClauses($query, $wheres);

        return 'where ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Concatenate an array of segments, removing empties.
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, static fn ($value) => $value !== ''));
    }

    /**
     * Concatenate where clauses.
     */
    protected function concatenateWhereClauses(QueryBuilder $query, array $wheres): string
    {
        return implode(' ', array_map(function (array $where, int $i) use ($query): string {
            $boolean = $i === 0 ? '' : $where['boolean'] . ' ';

            return $boolean . $this->{'where' . ucfirst($where['type'])}($query, $where);
        }, $wheres, array_keys($wheres)));
    }

    /**
     * Remove the leading boolean from a statement.
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return (string) preg_replace('/^(and |or )/i', '', $value, 1);
    }

    /**
     * Compile a basic where clause.
     */
    protected function whereBasic(QueryBuilder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    /**
     * Compile a "where between" clause.
     */
    protected function whereBetween(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'between ? and ?';
    }

    /**
     * Compile a "where exists" clause.
     */
    protected function whereExists(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not . 'exists (' . $this->compileSelect($where['query']) . ')';
    }

    /**
     * Compile a "where in" clause.
     */
    protected function whereIn(QueryBuilder $query, array $where): string
    {
        $values = $this->parameterize($where['values']);
        $not    = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'in (' . $values . ')';
    }

    /**
     * Compile a nested where clause.
     */
    protected function whereNested(QueryBuilder $query, array $where): string
    {
        $nested = $this->compileWheres($where['query'], $where['query']->getComponents()['wheres']);

        // strip leading "where "
        return '(' . substr($nested, 6) . ')';
    }

    /**
     * Compile a "where null" clause.
     */
    protected function whereNull(QueryBuilder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' is ' . $not . 'null';
    }

    /**
     * Compile a raw where clause.
     */
    protected function whereRaw(QueryBuilder $query, array $where): string
    {
        return $where['sql'];
    }

    /**
     * Wrap the given value segments.
     */
    protected function wrapSegments(array $segments): string
    {
        return implode('.', array_map(function (string $segment): string {
            return $segment === '*' ? $segment : $this->wrapValue($segment);
        }, $segments));
    }

    /**
     * Wrap table name.
     */
    protected function wrapTable(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return $table->getValue();
        }

        return $this->wrap($this->tablePrefix . $table);
    }

    /**
     * Wrap a union subquery in parentheses.
     */
    protected function wrapUnion(string $sql): string
    {
        return '(' . $sql . ')';
    }
}
