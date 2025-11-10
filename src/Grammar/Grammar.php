<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

/**
 * Abstract Grammar Base Class
 * 
 * Provides the foundation for SQL compilation across different database drivers.
 * Each driver (MySQL, PostgreSQL, SQLite) extends this class to implement
 * driver-specific SQL syntax and features.
 * 
 * @package DBLayer\Grammar
 * @author Hasan
 */
abstract class Grammar
{
    /**
     * The grammar table prefix
     */
    protected string $tablePrefix = '';

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
     * All of the available clause operators
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     * Compile a select query into SQL
     */
    public function compileSelect(array $query): string
    {
        if (isset($query['unions']) && !empty($query['unions'])) {
            return $this->compileUnion($query);
        }

        $sql = $this->concatenate(
            $this->compileComponents($query)
        );

        return $sql;
    }

    /**
     * Compile the components necessary for a select clause
     */
    protected function compileComponents(array $query): array
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            if (isset($query[$component]) && !is_null($query[$component])) {
                $method = 'compile' . ucfirst($component);
                $sql[$component] = $this->$method($query, $query[$component]);
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause
     */
    protected function compileAggregate(array $query, array $aggregate): string
    {
        $column = $this->columnize($aggregate['columns']);

        if (isset($query['distinct']) && $query['distinct'] && $column !== '*') {
            $column = 'DISTINCT ' . $column;
        }

        return 'SELECT ' . $aggregate['function'] . '(' . $column . ') AS aggregate';
    }

    /**
     * Compile the "select *" portion of the query
     */
    protected function compileColumns(array $query, array $columns): string
    {
        if (!is_null($query['aggregate'] ?? null)) {
            return '';
        }

        $select = isset($query['distinct']) && $query['distinct'] ? 'SELECT DISTINCT ' : 'SELECT ';

        return $select . $this->columnize($columns);
    }

    /**
     * Compile the "from" portion of the query
     */
    protected function compileFrom(array $query, string $table): string
    {
        return 'FROM ' . $this->wrapTable($table);
    }

    /**
     * Compile the "join" portions of the query
     */
    protected function compileJoins(array $query, array $joins): string
    {
        return implode(' ', array_map(function ($join) {
            $table = $this->wrapTable($join['table']);
            $clauses = $this->compileJoinConstraints($join);
            return trim("{$join['type']} JOIN {$table} {$clauses}");
        }, $joins));
    }

    /**
     * Compile the join constraints
     */
    protected function compileJoinConstraints(array $join): string
    {
        $conditions = [];

        foreach ($join['clauses'] as $clause) {
            if ($clause['type'] === 'nested') {
                $conditions[] = $this->compileNestedJoinConstraint($clause);
            } else {
                $conditions[] = $this->compileBasicJoinConstraint($clause);
            }
        }

        return 'ON ' . implode(' ', $conditions);
    }

    /**
     * Compile a basic join constraint
     */
    protected function compileBasicJoinConstraint(array $clause): string
    {
        $first = $this->wrap($clause['first']);
        $second = $this->wrap($clause['second']);
        $boolean = $clause['boolean'] ?? 'AND';

        return "{$boolean} {$first} {$clause['operator']} {$second}";
    }

    /**
     * Compile a nested join constraint
     */
    protected function compileNestedJoinConstraint(array $clause): string
    {
        $conditions = [];
        foreach ($clause['join']['clauses'] as $nestedClause) {
            $conditions[] = $this->compileBasicJoinConstraint($nestedClause);
        }

        $boolean = isset($clause['boolean']) ? $clause['boolean'] : 'AND';
        return $boolean . ' (' . implode(' ', $conditions) . ')';
    }

    /**
     * Compile the "where" portions of the query
     */
    protected function compileWheres(array $query, array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $sql = $this->compileWhereClauses($query, $wheres);

        if (!empty($sql)) {
            return 'WHERE ' . $this->removeLeadingBoolean($sql);
        }

        return '';
    }

    /**
     * Compile the where clauses
     */
    protected function compileWhereClauses(array $query, array $wheres): string
    {
        return implode(' ', array_map(function ($where) use ($query) {
            $method = "compileWhere{$where['type']}";
            return $where['boolean'] . ' ' . $this->$method($query, $where);
        }, $wheres));
    }

    /**
     * Compile a basic where clause
     */
    protected function compileWhereBasic(array $query, array $where): string
    {
        $value = $this->parameter($where['value']);
        $operator = str_replace('?', '??', $where['operator']);

        return $this->wrap($where['column']) . ' ' . $operator . ' ' . $value;
    }

    /**
     * Compile a where in clause
     */
    protected function compileWhereIn(array $query, array $where): string
    {
        if (!empty($where['values'])) {
            return $this->wrap($where['column']) . ' IN (' . $this->parameterize($where['values']) . ')';
        }

        return '0 = 1';
    }

    /**
     * Compile a where not in clause
     */
    protected function compileWhereNotIn(array $query, array $where): string
    {
        if (!empty($where['values'])) {
            return $this->wrap($where['column']) . ' NOT IN (' . $this->parameterize($where['values']) . ')';
        }

        return '1 = 1';
    }

    /**
     * Compile a where null clause
     */
    protected function compileWhereNull(array $query, array $where): string
    {
        return $this->wrap($where['column']) . ' IS NULL';
    }

    /**
     * Compile a where not null clause
     */
    protected function compileWhereNotNull(array $query, array $where): string
    {
        return $this->wrap($where['column']) . ' IS NOT NULL';
    }

    /**
     * Compile a where between clause
     */
    protected function compileWhereBetween(array $query, array $where): string
    {
        $between = $where['not'] ? 'NOT BETWEEN' : 'BETWEEN';
        $min = $this->parameter(reset($where['values']));
        $max = $this->parameter(end($where['values']));

        return $this->wrap($where['column']) . ' ' . $between . ' ' . $min . ' AND ' . $max;
    }

    /**
     * Compile a where date clause
     */
    protected function compileWhereDate(array $query, array $where): string
    {
        return $this->dateBasedWhere('DATE', $query, $where);
    }

    /**
     * Compile a where time clause
     */
    protected function compileWhereTime(array $query, array $where): string
    {
        return $this->dateBasedWhere('TIME', $query, $where);
    }

    /**
     * Compile a where day clause
     */
    protected function compileWhereDay(array $query, array $where): string
    {
        return $this->dateBasedWhere('DAY', $query, $where);
    }

    /**
     * Compile a where month clause
     */
    protected function compileWhereMonth(array $query, array $where): string
    {
        return $this->dateBasedWhere('MONTH', $query, $where);
    }

    /**
     * Compile a where year clause
     */
    protected function compileWhereYear(array $query, array $where): string
    {
        return $this->dateBasedWhere('YEAR', $query, $where);
    }

    /**
     * Compile a date based where clause
     */
    protected function dateBasedWhere(string $type, array $query, array $where): string
    {
        $value = $this->parameter($where['value']);
        return $type . '(' . $this->wrap($where['column']) . ') ' . $where['operator'] . ' ' . $value;
    }

    /**
     * Compile a where column clause
     */
    protected function compileWhereColumn(array $query, array $where): string
    {
        return $this->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->wrap($where['second']);
    }

    /**
     * Compile a nested where clause
     */
    protected function compileWhereNested(array $query, array $where): string
    {
        $nested = $this->compileWheres($where['query'], $where['query']['wheres']);
        return '(' . substr($nested, 6) . ')';
    }

    /**
     * Compile a where exists clause
     */
    protected function compileWhereExists(array $query, array $where): string
    {
        return 'EXISTS (' . $this->compileSelect($where['query']) . ')';
    }

    /**
     * Compile a where not exists clause
     */
    protected function compileWhereNotExists(array $query, array $where): string
    {
        return 'NOT EXISTS (' . $this->compileSelect($where['query']) . ')';
    }

    /**
     * Compile a where raw clause
     */
    protected function compileWhereRaw(array $query, array $where): string
    {
        return $where['sql'];
    }

    /**
     * Compile the "group by" portion of the query
     */
    protected function compileGroups(array $query, array $groups): string
    {
        return 'GROUP BY ' . $this->columnize($groups);
    }

    /**
     * Compile the "having" portion of the query
     */
    protected function compileHavings(array $query, array $havings): string
    {
        $sql = implode(' ', array_map([$this, 'compileHaving'], $havings));

        return 'HAVING ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Compile a single having clause
     */
    protected function compileHaving(array $having): string
    {
        if ($having['type'] === 'Raw') {
            return $having['boolean'] . ' ' . $having['sql'];
        }

        return $having['boolean'] . ' ' . $this->wrap($having['column']) . ' ' . 
               $having['operator'] . ' ' . $this->parameter($having['value']);
    }

    /**
     * Compile the "order by" portion of the query
     */
    protected function compileOrders(array $query, array $orders): string
    {
        if (!empty($orders)) {
            return 'ORDER BY ' . implode(', ', array_map(function ($order) {
                return $order['sql'] ?? ($this->wrap($order['column']) . ' ' . $order['direction']);
            }, $orders));
        }

        return '';
    }

    /**
     * Compile the "limit" portion of the query
     */
    protected function compileLimit(array $query, int $limit): string
    {
        return 'LIMIT ' . (int) $limit;
    }

    /**
     * Compile the "offset" portion of the query
     */
    protected function compileOffset(array $query, int $offset): string
    {
        return 'OFFSET ' . (int) $offset;
    }

    /**
     * Compile the lock into SQL
     */
    protected function compileLock(array $query, bool|string $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Compile an insert statement into SQL
     */
    public function compileInsert(array $query, array $values): string
    {
        $table = $this->wrapTable($query['from']);
        
        if (empty($values)) {
            return "INSERT INTO {$table} DEFAULT VALUES";
        }

        $columns = $this->columnize(array_keys(reset($values)));

        $parameters = implode(', ', array_map(function ($record) {
            return '(' . $this->parameterize($record) . ')';
        }, $values));

        return "INSERT INTO {$table} ({$columns}) VALUES {$parameters}";
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

        $where = $this->compileWheres($query, $query['wheres'] ?? []);

        return trim("UPDATE {$table} SET {$columns} {$where}");
    }

    /**
     * Compile a delete statement into SQL
     */
    public function compileDelete(array $query): string
    {
        $table = $this->wrapTable($query['from']);
        $where = $this->compileWheres($query, $query['wheres'] ?? []);

        return trim("DELETE FROM {$table} {$where}");
    }

    /**
     * Compile a truncate table statement into SQL
     */
    public function compileTruncate(array $query): array
    {
        return ['TRUNCATE TABLE ' . $this->wrapTable($query['from']) => []];
    }

    /**
     * Compile the SQL statement to define a table
     */
    abstract public function compileCreateTable(string $table, array $columns, array $options = []): string;

    /**
     * Compile the SQL statement to drop a table
     */
    public function compileDropTable(string $table): string
    {
        return 'DROP TABLE ' . $this->wrapTable($table);
    }

    /**
     * Compile the SQL statement to drop a table if it exists
     */
    public function compileDropTableIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrapTable($table);
    }

    /**
     * Compile a union aggregate query into SQL
     */
    protected function compileUnionAggregate(array $query): string
    {
        $sql = $this->compileAggregate($query, $query['aggregate']);
        $sql .= ' FROM (' . $this->compileUnion($query) . ') AS ' . $this->wrapTable('temp_table');

        return $sql;
    }

    /**
     * Compile a union query into SQL
     */
    protected function compileUnion(array $query): string
    {
        $joiner = $query['unionAll'] ?? false ? ' UNION ALL ' : ' UNION ';

        return implode($joiner, array_map(function ($union) {
            return '(' . $union['query'] . ')';
        }, $query['unions']));
    }

    /**
     * Concatenate an array of segments, removing empties
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Remove the leading boolean from a statement
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return preg_replace('/AND |OR /i', '', $value, 1);
    }

    /**
     * Wrap a table in keyword identifiers
     */
    public function wrapTable(string $table): string
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        return $this->wrap($this->tablePrefix . $table, true);
    }

    /**
     * Wrap a value in keyword identifiers
     */
    public function wrap(string $value, bool $prefixAlias = false): string
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (stripos($value, ' as ') !== false) {
            return $this->wrapAliasedValue($value, $prefixAlias);
        }

        return $this->wrapSegments(explode('.', $value));
    }

    /**
     * Wrap a value that has an alias
     */
    protected function wrapAliasedValue(string $value, bool $prefixAlias = false): string
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        if ($prefixAlias) {
            $segments[1] = $this->tablePrefix . $segments[1];
        }

        return $this->wrap($segments[0]) . ' AS ' . $this->wrapValue($segments[1]);
    }

    /**
     * Wrap the given value segments
     */
    protected function wrapSegments(array $segments): string
    {
        $wrapped = [];
        $count = count($segments);
        
        foreach ($segments as $key => $segment) {
            $wrapped[] = $key === 0 && $count > 1
                ? $this->wrapTable($segment)
                : $this->wrapValue($segment);
        }
        
        return implode('.', $wrapped);
    }

    /**
     * Wrap a single string in keyword identifiers
     */
    protected function wrapValue(string $value): string
    {
        if ($value !== '*') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Convert an array of column names into a delimited string
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    /**
     * Create query parameter place-holders for an array
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map([$this, 'parameter'], $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value
     */
    public function parameter(mixed $value): string
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    /**
     * Determine if the given value is a raw expression
     */
    public function isExpression(mixed $value): bool
    {
        return is_object($value) && method_exists($value, '__toString');
    }

    /**
     * Get the value of a raw expression
     */
    public function getValue(mixed $expression): string
    {
        return (string) $expression;
    }

    /**
     * Get the format for database stored dates
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the grammar's table prefix
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Set the grammar's table prefix
     */
    public function setTablePrefix(string $prefix): static
    {
        $this->tablePrefix = $prefix;
        return $this;
    }
}
