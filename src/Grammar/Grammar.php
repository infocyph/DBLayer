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
 */
abstract class Grammar
{
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
     * @param array<int, string|Expression> $columns
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
     *
     * Supports both:
     *  - ['col' => 1, 'other' => 2]
     *  - [['col' => 1], ['col' => 2]]
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     */
    public function compileInsert(QueryBuilder $query, array $values): string
    {
        return $this->compileInsertWithVerb('insert', $query, $values);
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
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     */
    protected function compileInsertWithVerb(
      string $verb,
      QueryBuilder $query,
      array $values
    ): string {
        $rows = $this->normalizeInsertValues($values);

        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);
        $columns    = $this->columnize(array_keys($rows[0]));

        $parameters = implode(', ', array_map(
          function (array $record): string {
              return '(' . $this->parameterize($record) . ')';
          },
          $rows
        ));

        return "{$verb} into {$table} ({$columns}) values {$parameters}";
    }

    /**
     * Normalize INSERT values to a non-empty list of associative rows.
     *
     * @param array<int, array<string, mixed>>|array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeInsertValues(array $values): array
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Cannot compile INSERT with empty values.');
        }

        if (! is_array(reset($values))) {
            /** @var array<string, mixed> $row */
            $row    = $values;
            $values = [$row];
        }

        /** @var array<int, array<string, mixed>> $values */
        return $values;
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
     *
     * @param array<string, mixed> $values
     */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $components = $query->getComponents();
        $table      = $this->wrapTable($components['from']);

        $columns = implode(', ', array_map(
          function (mixed $value, string $key): string {
              unset($value); // only used to match signatures
              return $this->wrap($key) . ' = ?';
          },
          array_values($values),
          array_keys($values)
        ));

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
     *
     * @param array<int|string, mixed> $values
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

        // Handle table.column format.
        if (str_contains($value, '.')) {
            return $this->wrapSegments(explode('.', $value));
        }

        // Handle raw values with * or AS (already full SQL fragments).
        $lower = strtolower($value);
        if (str_contains($value, '*') || str_contains($lower, ' as ')) {
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
     *
     * @param array<int, string|Expression> $columns
     */
    protected function compileColumns(QueryBuilder $query, array $columns): string
    {
        $components = $query->getComponents();
        $select     = $components['distinct'] ? 'select distinct ' : 'select ';

        return $select . $this->columnize($columns);
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @return array<string, string>
     */
    protected function compileComponents(QueryBuilder $query): array
    {
        $sql        = [];
        $components = $query->getComponents();

        foreach ($this->selectComponents as $component) {
            if (! array_key_exists($component, $components)) {
                continue;
            }

            $value = $components[$component];

            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $method = 'compile' . ucfirst($component);

            if (! method_exists($this, $method)) {
                continue;
            }

            /** @var string $compiled */
            $compiled = $this->$method($query, $value);

            if ($compiled !== '') {
                $sql[$component] = $compiled;
            }
        }

        return $sql;
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
     * @param array<int, string|Expression> $groups
     */
    protected function compileGroups(QueryBuilder $query, array $groups): string
    {
        unset($query);

        return 'group by ' . $this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     *
     * @param array<int, array{column:string,operator:string,boolean:string}> $havings
     */
    protected function compileHavings(QueryBuilder $query, array $havings): string
    {
        unset($query);

        $sql = implode(' ', array_map(
          function (array $having, int $i): string {
              $boolean = $i === 0 ? '' : $having['boolean'] . ' ';

              return $boolean
                . $this->wrap($having['column'])
                . ' ' . $having['operator'] . ' ?';
          },
          $havings,
          array_keys($havings)
        ));

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
     *
     * @param array<int, JoinClause|array<string, mixed>> $joins
     */
    protected function compileJoins(QueryBuilder $query, array $joins): string
    {
        unset($query);

        return implode(' ', array_map(
          function (JoinClause|array $join): string {
              if ($join instanceof JoinClause) {
                  return $this->compileJoinClause($join);
              }

              $table = $this->wrapTable($join['table']);
              $type  = strtoupper($join['type']);

              if (isset($join['first'])) {
                  return "{$type} join {$table} on {$this->wrap($join['first'])} {$join['operator']} {$this->wrap($join['second'])}";
              }

              return "{$type} join {$table}";
          },
          $joins
        ));
    }

    /**
     * Compile the "limit" portion of the query.
     */
    protected function compileLimit(QueryBuilder $query, int $limit): string
    {
        unset($query);

        return 'limit ' . (int) $limit;
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

        return 'offset ' . (int) $offset;
    }

    /**
     * Compile the "order by" portion of the query.
     *
     * @param array<int, array{column:string,direction:string}> $orders
     */
    protected function compileOrders(QueryBuilder $query, array $orders): string
    {
        unset($query);

        $sql = implode(', ', array_map(
          function (array $order): string {
              return $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
          },
          $orders
        ));

        return 'order by ' . $sql;
    }

    /**
     * Compile a single union statement.
     *
     * @param array{all:bool,query:QueryBuilder} $union
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
        $sql    = '';
        $unions = $query->getComponents()['unions'];

        foreach ($unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        return ltrim($sql);
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param array<int, array<string, mixed>> $wheres
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
     * @param array<string, string> $segments
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter(
          $segments,
          static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * Concatenate where clauses.
     *
     * @param array<int, array<string, mixed>> $wheres
     */
    protected function concatenateWhereClauses(QueryBuilder $query, array $wheres): string
    {
        return implode(' ', array_map(
          function (array $where, int $i) use ($query): string {
              $boolean = $i === 0 ? '' : $where['boolean'] . ' ';

              /** @var callable(QueryBuilder,array<string,mixed>):string $handler */
              $handler = [$this, 'where' . ucfirst($where['type'])];

              return $boolean . $handler($query, $where);
          },
          $wheres,
          array_keys($wheres)
        ));
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
     *
     * @param array{column:string,operator:string} $where
     */
    protected function whereBasic(QueryBuilder $query, array $where): string
    {
        unset($query);

        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    /**
     * Compile a "where between" clause.
     *
     * @param array{column:string,not:bool} $where
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
     * @param array{column:string,values:array<int,mixed>,not:bool} $where
     */
    protected function whereIn(QueryBuilder $query, array $where): string
    {
        unset($query);

        $values = $this->parameterize($where['values']);
        $not    = $where['not'] ? 'not ' : '';

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
          $where['query']->getComponents()['wheres']
        );

        // strip leading "where "
        return '(' . substr($nested, 6) . ')';
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
     * @param array<int, string> $segments
     */
    protected function wrapSegments(array $segments): string
    {
        return implode('.', array_map(
          function (string $segment): string {
              return $segment === '*' ? $segment : $this->wrapValue($segment);
          },
          $segments
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
