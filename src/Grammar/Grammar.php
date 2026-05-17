<?php

// src/Grammar/Grammar.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Grammar;

use Infocyph\DBLayer\Grammar\Concerns\GrammarComponentNormalization;
use Infocyph\DBLayer\Query\Expression;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * SQL Grammar Base
 *
 * Abstract base class for database-specific SQL compilation.
 * Provides common methods and structure for grammar implementations.
 *
 * Note: in the new design, core no longer talks to Grammar directly.
 * Grammar is used by driver-specific compilers.
 */
abstract class Grammar
{
    use GrammarComponentNormalization;

    /**
     * The components that make up a select clause.
     *
     * Order matters for generated SQL.
     *
     * @var list<string>
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
     *
     * @param array<int,string|Expression> $columns
     */
    public function columnize(array $columns): string
    {
        return \implode(', ', \array_map($this->wrap(...), $columns));
    }

    /**
     * Compile a delete statement.
     */
    public function compileDelete(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $table = $this->wrapTable($this->requireFromTable($components));
        $where = $this->compileWheres($query, $this->normalizeWheres($components['wheres']));

        return \trim("delete from {$table} {$where}");
    }

    /**
     * Compile an insert statement.
     *
     * Supports both:
     *  - ['col' => 1, 'other' => 2]
     *  - [['col' => 1], ['col' => 2]]
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('insert', $query, $values);
    }

    /**
     * Compile a select query.
     */
    public function compileSelect(QueryBuilder $query): string
    {
        $components = $query->getComponents();
        $cteSql = '';

        $ctes = $this->normalizeCtes($components['ctes']);
        if ($ctes !== []) {
            $cteSql = $this->compileCtes($query, $ctes) . ' ';
        }

        if (is_array($components['aggregate'])) {
            return $cteSql . $this->compileAggregate($query);
        }

        $sql = $this->concatenate($this->compileComponents($query));

        $unions = $this->normalizeUnions($components['unions']);
        if ($unions !== []) {
            $sql .= ' ' . $this->compileUnions($query);
        }

        return $cteSql . $sql;
    }

    /**
     * Compile a truncate table statement.
     */
    public function compileTruncate(QueryBuilder $query): string
    {
        return $this->compileTruncateTable($query);
    }

    /**
     * Compile an update statement.
     *
     * @param array<string,mixed> $values
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table = $this->wrapTable($this->requireFromTable($components));

        $columns = \implode(', ', \array_map(
            function (mixed $value, string $key): string {
                unset($value); // signature alignment only

                return $this->wrap($key) . ' = ?';
            },
            \array_values($values),
            \array_keys($values),
        ));

        $where = $this->compileWheres($query, $this->normalizeWheres($components['wheres']));

        return \trim("update {$table} set {$columns} {$where}");
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
     *
     * @param array<int|string,mixed> $values
     */
    public function parameterize(array $values): string
    {
        if ($values === []) {
            return '';
        }

        return \implode(', ', \array_fill(0, \count($values), '?'));
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

        // Handle table.column format.
        if (\str_contains($value, '.')) {
            return $this->wrapSegments(\explode('.', $value));
        }

        // Handle raw values with * or AS (already full SQL fragments).
        $lower = \strtolower($value);
        if (\str_contains($value, '*') || \str_contains($lower, ' as ')) {
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
        $aggregate = $components['aggregate'];
        if (!is_array($aggregate)) {
            throw new InvalidArgumentException('Aggregate payload is missing.');
        }

        $column = $aggregate['column'] === '*'
          ? '*'
          : $this->wrap($aggregate['column']);

        $sql = $this->compileColumns(
            $query,
            [new Expression("{$aggregate['function']}({$column}) as aggregate")],
        );

        if (is_string($components['from']) && $components['from'] !== '') {
            $sql .= ' ' . $this->compileFrom($query, $components['from']);
        }

        $joins = $this->normalizeJoins($components['joins']);
        if ($joins !== []) {
            $sql .= ' ' . $this->compileJoins($query, $joins);
        }

        $wheres = $this->normalizeWheres($components['wheres']);
        if ($wheres !== []) {
            $sql .= ' ' . $this->compileWheres($query, $wheres);
        }

        $groups = $this->normalizeColumns($components['groups']);
        if ($groups !== []) {
            $sql .= ' ' . $this->compileGroups($query, $groups);
        }

        $havings = $this->normalizeHavings($components['havings']);
        if ($havings !== []) {
            $sql .= ' ' . $this->compileHavings($query, $havings);
        }

        return $sql;
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param array<int,string|Expression> $columns
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
        $sql = [];
        $components = $query->getComponents();

        foreach ($this->selectComponents as $component) {
            if (!\array_key_exists($component, $components)) {
                continue;
            }

            $compiled = $this->compileComponent($query, $component, $components[$component]);

            if ($compiled !== '') {
                $sql[$component] = $compiled;
            }
        }

        return $sql;
    }

    /**
     * Compile common table expressions.
     *
     * @param list<array{name:string,query:string|QueryBuilder,recursive:bool}> $ctes
     */
    protected function compileCtes(QueryBuilder $query, array $ctes): string
    {
        unset($query);

        $parts = [];
        $recursive = false;

        foreach ($ctes as $cte) {
            $recursive = $recursive || $cte['recursive'];
            $name = $cte['name'];
            $cteQuery = $cte['query'];

            if ($cteQuery instanceof QueryBuilder) {
                $sql = $cteQuery->toSelectSql();
            } else {
                $sql = $cteQuery;
            }

            $parts[] = "{$name} as ({$sql})";
        }

        $prefix = $recursive ? 'with recursive' : 'with';

        return $prefix . ' ' . \implode(', ', $parts);
    }

    /**
     * Compile the "from" portion of the query.
     */
    protected function compileFrom(QueryBuilder $query, string $table): string
    {
        unset($query);

        return 'from ' . $this->wrapTable($table);
    }

    /**
     * Compile the "group by" portion of the query.
     *
     * @param array<int,string|Expression> $groups
     */
    protected function compileGroups(QueryBuilder $query, array $groups): string
    {
        unset($query);

        return 'group by ' . $this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     *
     * @param array<int,array{column:string,operator:string,value:mixed,boolean:string}> $havings
     */
    protected function compileHavings(QueryBuilder $query, array $havings): string
    {
        unset($query);

        $sql = \implode(' ', \array_map(
            function (array $having, int $i): string {
                $boolean = $i === 0 ? '' : $having['boolean'] . ' ';

                return $boolean
                  . $this->wrap($having['column'])
                  . ' ' . $having['operator'] . ' ?';
            },
            $havings,
            \array_keys($havings),
        ));

        return 'having ' . $this->removeLeadingBoolean($sql);
    }

    /**
     * Compile an INSERT-like statement with a custom verb.
     *
     * Shared helper for:
     *  - INSERT
     *  - INSERT IGNORE
     *  - INSERT OR IGNORE
     *  - INSERT OR REPLACE
     *  - REPLACE
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    protected function compileInsertWithVerb(
        string $verb,
        QueryBuilder $query,
        array $values,
    ): string {
        $rows = $this->normalizeInsertValues($values);

        $components = $query->getComponents();
        $table = $this->wrapTable($this->requireFromTable($components));
        $columns = $this->columnize(\array_keys($rows[0]));

        $parameters = \implode(', ', \array_map(
            fn(array $record): string => '(' . $this->parameterize($record) . ')',
            $rows,
        ));

        return "{$verb} into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Compile a single join clause.
     */
    protected function compileJoinClause(JoinClause $join): string
    {
        $table = $this->wrapTable($join->getTable());
        $type = \strtoupper($join->getType());
        $conditions = $join->getConditions();

        if ($conditions === []) {
            return "{$type} join {$table}";
        }

        $clauses = [];

        foreach ($conditions as $i => $condition) {
            $booleanToken = $this->stringValue($condition['boolean'] ?? 'and', 'and');
            $boolean = $i === 0 ? '' : ' ' . $booleanToken . ' ';
            $type = $this->stringValue($condition['type'] ?? '');

            switch ($type) {
                case 'basic':
                    $first = $this->stringValue($condition['first'] ?? '');
                    $operator = $this->stringValue($condition['operator'] ?? '=');
                    $second = $this->stringValue($condition['second'] ?? '');
                    $clauses[] = $boolean
                      . $this->wrap($first)
                      . ' ' . $operator . ' '
                      . $this->wrap($second);

                    break;

                case 'where':
                    $column = $this->stringValue($condition['column'] ?? '');
                    $operator = $this->stringValue($condition['operator'] ?? '=');
                    $clauses[] = $boolean
                      . $this->wrap($column)
                      . ' ' . $operator . ' ?';

                    break;

                case 'whereIn':
                    $values = $this->normalizeValues($condition['values'] ?? []);
                    $placeholders = $this->parameterize($values);
                    $column = $this->stringValue($condition['column'] ?? '');
                    $clauses[] = $boolean
                      . $this->wrap($column)
                      . ' in (' . $placeholders . ')';

                    break;

                case 'whereNull':
                    $column = $this->stringValue($condition['column'] ?? '');
                    $clauses[] = $boolean
                      . $this->wrap($column)
                      . ' is null';

                    break;

                case 'whereNotNull':
                    $column = $this->stringValue($condition['column'] ?? '');
                    $clauses[] = $boolean
                      . $this->wrap($column)
                      . ' is not null';

                    break;
            }
        }

        return "{$type} join {$table} on " . \implode('', $clauses);
    }

    /**
     * Compile the "join" portions of the query.
     *
     * @param array<int,JoinClause|array<string,mixed>> $joins
     */
    protected function compileJoins(QueryBuilder $query, array $joins): string
    {
        unset($query);

        return \implode(' ', \array_map(
            function (JoinClause|array $join): string {
                if ($join instanceof JoinClause) {
                    return $this->compileJoinClause($join);
                }

                $table = $this->wrapTable($this->stringValue($join['table'] ?? ''));
                $type = \strtoupper($this->stringValue($join['type'] ?? 'inner', 'inner'));

                if (isset($join['first'])) {
                    $first = $this->stringValue($join['first']);
                    $operator = $this->stringValue($join['operator'] ?? '=');
                    $second = $this->stringValue($join['second']);

                    return "{$type} join {$table} on {$this->wrap($first)} {$operator} {$this->wrap($second)}";
                }

                return "{$type} join {$table}";
            },
            $joins,
        ));
    }

    /**
     * Compile the "limit" portion of the query.
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        unset($query);

        return 'limit ' . $limit;
    }

    /**
     * Compile the lock into SQL.
     */
    protected function compileLock(QueryBuilder $query, string $lock): string
    {
        unset($query);

        return $lock === 'update' ? 'for update' : 'lock in share mode';
    }

    /**
     * Compile the "offset" portion of the query.
     */
    protected function compileOffset(QueryBuilder $query, int $offset): string
    {
        unset($query);

        return 'offset ' . $offset;
    }

    /**
     * Compile the "order by" portion of the query.
     *
     * @param array<int,array{column:string,direction:string}> $orders
     */
    protected function compileOrders(QueryBuilder $query, array $orders): string
    {
        unset($query);

        $sql = \implode(', ', \array_map(
            fn(array $order): string => $this->wrap($order['column']) . ' ' . \strtoupper($order['direction']),
            $orders,
        ));

        return 'order by ' . $sql;
    }

    /**
     * Compile a TRUNCATE statement with an optional dialect suffix.
     */
    protected function compileTruncateTable(QueryBuilder $query, string $suffix = ''): string
    {
        $table = $this->resolveMutationTable($query);

        return 'truncate table ' . $table . $suffix;
    }

    /**
     * Compile a single union statement.
     *
     * @param array{all:bool,query:QueryBuilder} $union
     */
    protected function compileUnion(array $union): string
    {
        $keyword = $union['all'] ? 'union all' : 'union';

        return $keyword . ' ' . $this->compileSelect($union['query']);
    }

    /**
     * Compile the union queries.
     */
    protected function compileUnions(QueryBuilder $query): string
    {
        $unions = $this->normalizeUnions($query->getComponents()['unions']);
        $segments = [];

        foreach ($unions as $union) {
            $segments[] = $this->compileUnion($union);
        }

        return \implode(' ', $segments);
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param array<int,array<string,mixed>> $wheres
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
     *
     * @param array<string,string> $segments
     */
    protected function concatenate(array $segments): string
    {
        return \implode(' ', \array_filter(
            $segments,
            static fn(string $value): bool => $value !== '',
        ));
    }

    /**
     * Concatenate where clauses.
     *
     * @param array<int,array<string,mixed>> $wheres
     */
    protected function concatenateWhereClauses(QueryBuilder $query, array $wheres): string
    {
        return \implode(' ', \array_map(
            function (array $where, int $i) use ($query): string {
                $booleanValue = $this->stringValue($where['boolean'] ?? 'and', 'and');
                $boolean = $i === 0 ? '' : $booleanValue . ' ';

                /** @var callable(QueryBuilder,array<string,mixed>):string $handler */
                $handler = [$this, 'where' . \ucfirst($this->stringValue($where['type'] ?? 'basic', 'basic'))];

                return $boolean . $handler($query, $where);
            },
            $wheres,
            \array_keys($wheres),
        ));
    }

    /**
     * Normalize INSERT values to a non-empty list of associative rows.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeInsertValues(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot compile INSERT with empty values.');
        }

        if (!\is_array(\reset($values))) {
            /** @var array<string,mixed> $row */
            $row = $values;
            $values = [$row];
        }

        /** @var array<int,array<string,mixed>> $values */
        return $values;
    }

    /**
     * Remove the leading boolean from a statement.
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return (string) \preg_replace('/^(and |or )/i', '', $value, 1);
    }

    /**
     * Resolve and wrap the target table name for write operations.
     */
    protected function resolveMutationTable(QueryBuilder $query): string
    {
        $components = $query->getComponents();

        return $this->wrapTable($this->requireFromTable($components));
    }

    /**
     * Compile a basic where clause.
     *
     * @param array{column:string,operator:string,value:mixed} $where
     */
    protected function whereBasic(QueryBuilder $query, array $where): string
    {
        unset($query);

        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    /**
     * Compile a "where between" clause.
     *
     * @param array{column:string,values:array{0:mixed,1:mixed},not:bool} $where
     */
    protected function whereBetween(QueryBuilder $query, array $where): string
    {
        unset($query);

        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'between ? and ?';
    }

    /**
     * Compile a "where exists" clause.
     *
     * @param array{not:bool,query:QueryBuilder} $where
     */
    protected function whereExists(QueryBuilder $query, array $where): string
    {
        unset($query);

        $not = $where['not'] ? 'not ' : '';

        return $not . 'exists (' . $this->compileSelect($where['query']) . ')';
    }

    /**
     * Compile a "where in" clause.
     *
     * @param array{column:string,values:list<mixed>,not:bool} $where
     */
    protected function whereIn(QueryBuilder $query, array $where): string
    {
        unset($query);

        $values = $this->parameterize($where['values']);
        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' ' . $not . 'in (' . $values . ')';
    }

    /**
     * Compile a nested where clause.
     *
     * @param array{query:QueryBuilder} $where
     */
    protected function whereNested(QueryBuilder $query, array $where): string
    {
        unset($query);

        $nested = $this->compileWheres(
            $where['query'],
            $where['query']->getComponents()['wheres'],
        );

        // strip leading "where "
        return '(' . \substr($nested, 6) . ')';
    }

    /**
     * Compile a "where null" clause.
     *
     * @param array{column:string,not:bool} $where
     */
    protected function whereNull(QueryBuilder $query, array $where): string
    {
        unset($query);

        $not = $where['not'] ? 'not ' : '';

        return $this->wrap($where['column']) . ' is ' . $not . 'null';
    }

    /**
     * Compile a raw where clause.
     *
     * @param array{sql:string} $where
     */
    protected function whereRaw(QueryBuilder $query, array $where): string
    {
        unset($query);

        return $where['sql'];
    }

    /**
     * Wrap the given value segments.
     *
     * @param array<int,string> $segments
     */
    protected function wrapSegments(array $segments): string
    {
        return \implode('.', \array_map(
            fn(string $segment): string => $segment === '*' ? $segment : $this->wrapValue($segment),
            $segments,
        ));
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
