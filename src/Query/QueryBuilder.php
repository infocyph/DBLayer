<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Grammar\Grammar;

/**
 * SQL Query Builder
 *
 * Fluent interface for building SQL queries with:
 * - SELECT, INSERT, UPDATE, DELETE operations
 * - WHERE clauses with operators
 * - JOIN support (INNER, LEFT, RIGHT, CROSS)
 * - GROUP BY, HAVING, ORDER BY
 * - LIMIT, OFFSET
 * - UNION, UNION ALL
 * - Subqueries
 * - Aggregate functions
 *
 * @package Infocyph\DBLayer\Query
 * @author Hasan
 */
class QueryBuilder
{
    /**
     * Aggregate function
     */
    private ?array $aggregate = null;

    /**
     * Query bindings
     */
    private array $bindings = [];

    /**
     * SELECT columns
     */
    private array $columns = ['*'];
    /**
     * Database connection
     */
    private Connection $connection;

    /**
     * DISTINCT flag
     */
    private bool $distinct = false;

    /**
     * Query executor
     */
    private Executor $executor;

    /**
     * FROM table
     */
    private ?string $from = null;

    /**
     * SQL grammar compiler
     */
    private Grammar $grammar;

    /**
     * GROUP BY columns
     */
    private array $groups = [];

    /**
     * HAVING clauses
     */
    private array $havings = [];

    /**
     * JOIN clauses
     */
    private array $joins = [];

    /**
     * LIMIT value
     */
    private ?int $limit = null;

    /**
     * Lock mode
     */
    private ?string $lock = null;

    /**
     * OFFSET value
     */
    private ?int $offset = null;

    /**
     * ORDER BY clauses
     */
    private array $orders = [];

    /**
     * Query type
     */
    private ?string $type = null;

    /**
     * UNION queries
     */
    private array $unions = [];

    /**
     * WHERE clauses
     */
    private array $wheres = [];

    /**
     * Create a new query builder instance
     */
    public function __construct(
        Connection $connection,
        Grammar $grammar,
        Executor $executor
    ) {
        $this->connection = $connection;
        $this->grammar = $grammar;
        $this->executor = $executor;
    }

    /**
     * Add a select column
     */
    public function addSelect(string|Expression $column): self
    {
        if (!in_array($column, $this->columns, true)) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Execute an aggregate function
     */
    public function aggregate(string $function, string $column = '*'): mixed
    {
        $this->aggregate = ['function' => $function, 'column' => $column];

        $results = $this->get();

        return $results[0]['aggregate'] ?? null;
    }

    /**
     * Get the average
     */
    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Clone the query
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Get the count of results
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    /**
     * Add a CROSS JOIN clause
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = [
            'type' => 'cross',
            'table' => $table,
        ];

        return $this;
    }

    /**
     * Delete records
     */
    public function delete(): int
    {
        return $this->executor->delete($this);
    }

    /**
     * Set DISTINCT flag
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Determine if any rows exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Execute the query and get first result
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * Set the table
     */
    public function from(string $table): self
    {
        $this->from = $table;
        return $this;
    }

    /**
     * Execute the query and get all results
     */
    public function get(): array
    {
        return $this->executor->select($this);
    }

    /**
     * Get query bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get all query components
     */
    public function getComponents(): array
    {
        return [
            'type' => $this->type,
            'columns' => $this->columns,
            'distinct' => $this->distinct,
            'from' => $this->from,
            'joins' => $this->joins,
            'wheres' => $this->wheres,
            'groups' => $this->groups,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'unions' => $this->unions,
            'lock' => $this->lock,
            'aggregate' => $this->aggregate,
        ];
    }

    /**
     * Add a GROUP BY clause
     */
    public function groupBy(string ...$groups): self
    {
        $this->groups = array_merge($this->groups, $groups);
        return $this;
    }

    /**
     * Add a HAVING clause
     */
    public function having(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Insert a new record
     */
    public function insert(array $values): bool
    {
        if (empty($values)) {
            return true;
        }

        // Handle single row or multiple rows
        if (!is_array(reset($values))) {
            $values = [$values];
        }

        return $this->executor->insert($this, $values);
    }

    /**
     * Insert and get the ID
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        $this->insert($values);
        return $this->connection->lastInsertId($sequence);
    }

    /**
     * Add a JOIN clause
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner'
    ): self {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    /**
     * Add a complex JOIN with closure
     */
    public function joinComplex(string $table, callable $callback, string $type = 'inner'): self
    {
        $join = new JoinClause($table, $type);
        $callback($join);

        $this->joins[] = $join;

        return $this;
    }

    /**
     * Add a LEFT JOIN clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Set the LIMIT
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw QueryException::invalidLimit($limit);
        }

        $this->limit = $limit;
        return $this;
    }

    /**
     * Lock the selected rows for update
     */
    public function lockForUpdate(): self
    {
        $this->lock = 'update';
        return $this;
    }

    /**
     * Get the max value
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Get the min value
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Create a new query instance
     */
    public function newQuery(): self
    {
        return new self($this->connection, $this->grammar, $this->executor);
    }

    /**
     * Set the OFFSET
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw QueryException::invalidOffset($offset);
        }

        $this->offset = $offset;
        return $this;
    }

    /**
     * Add an ORDER BY clause
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'])) {
            throw QueryException::invalidOrderDirection($direction);
        }

        $this->orders[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Add ORDER BY DESC
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an OR WHERE clause
     */
    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a RIGHT JOIN clause
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Set the columns to select
     */
    public function select(array|string ...$columns): self
    {
        if (empty($columns)) {
            return $this;
        }

        $this->type = 'select';
        $this->columns = is_array($columns[0]) ? $columns[0] : $columns;

        return $this;
    }

    /**
     * Lock the selected rows in shared mode
     */
    public function sharedLock(): self
    {
        $this->lock = 'shared';
        return $this;
    }

    /**
     * Set OFFSET
     */
    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    /**
     * Get the sum
     */
    public function sum(string $column): mixed
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Set the table (alias for from)
     */
    public function table(string $table): self
    {
        return $this->from($table);
    }

    /**
     * Set LIMIT and OFFSET (pagination)
     */
    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    /**
     * Get the SQL query
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Truncate the table
     */
    public function truncate(): bool
    {
        return $this->executor->truncate($this);
    }

    /**
     * Add a UNION query
     */
    public function union(QueryBuilder|callable $query, bool $all = false): self
    {
        if (is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        $this->unions[] = [
            'query' => $query,
            'all' => $all,
        ];

        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a UNION ALL query
     */
    public function unionAll(QueryBuilder|callable $query): self
    {
        return $this->union($query, true);
    }

    /**
     * Update records
     */
    public function update(array $values): int
    {
        return $this->executor->update($this, $values);
    }

    /**
     * Get a single column value from the first result
     */
    public function value(string $column): mixed
    {
        $result = $this->first();
        return $result[$column] ?? null;
    }

    /**
     * Add a WHERE clause
     */
    public function where(string|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        // Handle closure for nested where
        if (is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Handle two arguments (column, value)
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a WHERE EXISTS clause
     */
    public function whereExists(callable $callback, string $boolean = 'and', bool $not = false): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
            'type' => 'exists',
            'query' => $query,
            'boolean' => $boolean,
            'not' => $not,
        ];

        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a WHERE IN clause
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a nested WHERE clause
     */
    public function whereNested(callable $callback, string $boolean = 'and'): self
    {
        $query = $this->newQuery();
        $callback($query);

        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean,
            ];

            $this->bindings = array_merge($this->bindings, $query->getBindings());
        }

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE NOT EXISTS clause
     */
    public function whereNotExists(callable $callback, string $boolean = 'and'): self
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add a WHERE NOT IN clause
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE NOT NULL clause
     */
    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add a WHERE NULL clause
     */
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * Add a raw WHERE clause
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
        ];

        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }
}
