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
 */
class QueryBuilder
{
    /**
     * Aggregate function definition or null.
     *
     * @var array{function:string,column:string}|null
     */
    private ?array $aggregate = null;

    /**
     * Query bindings.
     *
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * SELECT columns.
     *
     * @var list<string|Expression>
     */
    private array $columns = ['*'];

    /**
     * Database connection.
     */
    private Connection $connection;

    /**
     * DISTINCT flag.
     */
    private bool $distinct = false;

    /**
     * Query executor.
     */
    private Executor $executor;

    /**
     * FROM table.
     */
    private ?string $from = null;

    /**
     * SQL grammar compiler.
     */
    private Grammar $grammar;

    /**
     * GROUP BY columns.
     *
     * @var list<string>
     */
    private array $groups = [];

    /**
     * HAVING clauses.
     *
     * @var list<array<string,mixed>>
     */
    private array $havings = [];

    /**
     * JOIN clauses.
     *
     * @var list<array<string,mixed>|JoinClause>
     */
    private array $joins = [];

    /**
     * LIMIT value.
     */
    private ?int $limit = null;

    /**
     * Lock mode.
     */
    private ?string $lock = null;

    /**
     * OFFSET value.
     */
    private ?int $offset = null;

    /**
     * ORDER BY clauses.
     *
     * @var list<array{column:string,direction:string}>
     */
    private array $orders = [];

    /**
     * Query type (e.g. "select").
     */
    private ?string $type = null;

    /**
     * UNION queries.
     *
     * @var list<array{query:QueryBuilder,all:bool}>
     */
    private array $unions = [];

    /**
     * WHERE clauses.
     *
     * @var list<array<string,mixed>>
     */
    private array $wheres = [];

    /**
     * Create a new query builder instance.
     */
    public function __construct(
      Connection $connection,
      Grammar $grammar,
      Executor $executor
    ) {
        $this->connection = $connection;
        $this->grammar    = $grammar;
        $this->executor   = $executor;
    }

    /**
     * Add a select column (no duplicates).
     */
    public function addSelect(string|Expression $column): self
    {
        if (! in_array($column, $this->columns, true)) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Execute an aggregate function on a cloned builder.
     *
     * This keeps the original query state untouched.
     */
    public function aggregate(string $function, string $column = '*'): mixed
    {
        $clone = clone $this;

        $clone->aggregate = [
          'function' => strtoupper($function),
          'column'   => $column,
        ];

        $results = $this->executor->select($clone);

        if ($results === []) {
            return null;
        }

        $row = $results[0];

        return $row['aggregate'] ?? (array_values($row)[0] ?? null);
    }

    /**
     * Get the average of a column.
     */
    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Clone the query builder (explicit helper).
     */
    public function cloneBuilder(): self
    {
        return clone $this;
    }

    /**
     * Get the count of results.
     */
    public function count(string $column = '*'): int
    {
        return (int) ($this->aggregate('COUNT', $column) ?? 0);
    }

    /**
     * Add a CROSS JOIN clause.
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = [
          'type'  => 'cross',
          'table' => $table,
        ];

        return $this;
    }

    /**
     * Delete records.
     */
    public function delete(): int
    {
        return $this->executor->delete($this);
    }

    /**
     * Set DISTINCT flag.
     */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Determine if any rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Execute the query and get the first result.
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * Set the table.
     */
    public function from(string $table): self
    {
        $this->from = $table;

        return $this;
    }

    /**
     * Execute the query and get all results.
     *
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        return $this->executor->select($this);
    }

    /**
     * Get query bindings.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Expose the connection (escape hatch for low-level usage).
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get all query components (for Grammar).
     *
     * @return array{
     *   type:?string,
     *   columns:list<string|Expression>,
     *   distinct:bool,
     *   from:?string,
     *   joins:list<array<string,mixed>|JoinClause>,
     *   wheres:list<array<string,mixed>>,
     *   groups:list<string>,
     *   havings:list<array<string,mixed>>,
     *   orders:list<array{column:string,direction:string}>,
     *   limit:?int,
     *   offset:?int,
     *   unions:list<array{query:QueryBuilder,all:bool}>,
     *   lock:?string,
     *   aggregate:array{function:string,column:string}|null
     * }
     */
    public function getComponents(): array
    {
        return [
          'type'      => $this->type,
          'columns'   => $this->columns,
          'distinct'  => $this->distinct,
          'from'      => $this->from,
          'joins'     => $this->joins,
          'wheres'    => $this->wheres,
          'groups'    => $this->groups,
          'havings'   => $this->havings,
          'orders'    => $this->orders,
          'limit'     => $this->limit,
          'offset'    => $this->offset,
          'unions'    => $this->unions,
          'lock'      => $this->lock,
          'aggregate' => $this->aggregate,
        ];
    }

    /**
     * Add a GROUP BY clause.
     */
    public function groupBy(string ...$groups): self
    {
        $this->groups = array_merge($this->groups, $groups);

        return $this;
    }

    /**
     * Add a HAVING clause.
     */
    public function having(
      string $column,
      mixed $operator = null,
      mixed $value = null,
      string $boolean = 'and'
    ): self {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->havings[] = [
          'type'     => 'basic',
          'column'   => $column,
          'operator' => $operator,
          'value'    => $value,
          'boolean'  => $boolean,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Insert a new record (single or multiple rows).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insert(array $values): bool
    {
        return $this->executor->insert($this, $values);
    }

    /**
     * Insert ignoring duplicate-key errors when the driver supports it.
     *
     * Falls back to insert() on drivers without native support.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insertIgnore(array $values): bool
    {
        return $this->executor->insertIgnore($this, $values);
    }

    /**
     * Insert and get the ID.
     *
     * Uses insertReturning() when supported to avoid extra round trips.
     * Falls back to insert() + lastInsertId().
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        $column = $sequence ?? 'id';

        $row = $this->insertReturning($values, $column);

        if ($row !== null && array_key_exists($column, $row)) {
            return (string) $row[$column];
        }

        $this->insert($values);

        return $this->connection->lastInsertId($sequence);
    }

    /**
     * Insert and return generated key/row when supported.
     *
     * On PostgreSQL, uses INSERT ... RETURNING.
     * On other drivers, falls back to lastInsertId() and synthesizes a row.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return array<string,mixed>|null
     */
    public function insertReturning(array $values, ?string $column = null): ?array
    {
        return $this->executor->insertReturning($this, $values, $column);
    }

    /**
     * Upsert helper: INSERT ... ON DUPLICATE KEY UPDATE / ON CONFLICT.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string>                                       $uniqueBy
     * @param list<string>|null                                  $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        return $this->executor->upsert($this, $values, $uniqueBy, $update);
    }

    /**
     * Add a simple JOIN clause.
     */
    public function join(
      string $table,
      string $first,
      string $operator,
      string $second,
      string $type = 'inner'
    ): self {
        $this->joins[] = [
          'type'     => $type,
          'table'    => $table,
          'first'    => $first,
          'operator' => $operator,
          'second'   => $second,
        ];

        return $this;
    }

    /**
     * Add a complex JOIN with closure.
     *
     * The callback receives a JoinClause instance.
     *
     * @param callable(JoinClause):void $callback
     */
    public function joinComplex(string $table, callable $callback, string $type = 'inner'): self
    {
        $join = new JoinClause($table, $type);
        $callback($join);

        $this->joins[] = $join;

        if ($join->getBindings() !== []) {
            $this->bindings = array_merge($this->bindings, $join->getBindings());
        }

        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Set the LIMIT.
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
     * Lock the selected rows for update.
     */
    public function lockForUpdate(): self
    {
        $this->lock = 'update';

        return $this;
    }

    /**
     * Get the max value.
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Get the min value.
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Create a new query instance sharing the same deps.
     */
    public function newQuery(): self
    {
        return new self($this->connection, $this->grammar, $this->executor);
    }

    /**
     * Set the OFFSET.
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
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::invalidOrderDirection($direction);
        }

        $this->orders[] = [
          'column'    => $column,
          'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Add ORDER BY DESC.
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an OR WHERE clause.
     */
    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a RIGHT JOIN clause.
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Set the columns to select.
     *
     * @param array<int,string|Expression>|string|Expression ...$columns
     */
    public function select(array|string|Expression ...$columns): self
    {
        if ($columns === []) {
            return $this;
        }

        $this->type    = 'select';
        $this->columns = is_array($columns[0]) ? $columns[0] : $columns;

        return $this;
    }

    /**
     * Lock the selected rows in shared mode.
     */
    public function sharedLock(): self
    {
        $this->lock = 'shared';

        return $this;
    }

    /**
     * Set OFFSET (alias for offset).
     */
    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    /**
     * Get the sum of a column.
     */
    public function sum(string $column): mixed
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Set the table (alias for from).
     */
    public function table(string $table): self
    {
        return $this->from($table);
    }

    /**
     * Set LIMIT (alias for limit, typically used for pagination).
     */
    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    /**
     * Get the SQL query string.
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Truncate the table.
     */
    public function truncate(): bool
    {
        return $this->executor->truncate($this);
    }

    /**
     * Add a UNION query.
     *
     * @param QueryBuilder|callable(QueryBuilder):void $query
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
          'all'   => $all,
        ];

        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a UNION ALL query.
     *
     * @param QueryBuilder|callable(QueryBuilder):void $query
     */
    public function unionAll(QueryBuilder|callable $query): self
    {
        return $this->union($query, true);
    }

    /**
     * Update records.
     *
     * @param array<string,mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->executor->update($this, $values);
    }

    /**
     * Get a single column value from the first result.
     */
    public function value(string $column): mixed
    {
        $result = $this->first();

        return $result[$column] ?? null;
    }

    /**
     * Add a WHERE clause.
     *
     * @param callable(QueryBuilder):void|non-empty-string $column
     */
    public function where(
      string|callable $column,
      mixed $operator = null,
      mixed $value = null,
      string $boolean = 'and'
    ): self {
        // Handle closure for nested where.
        if (is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Handle two arguments (column, value).
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
          'type'     => 'basic',
          'column'   => $column,
          'operator' => $operator,
          'value'    => $value,
          'boolean'  => $boolean,
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause.
     *
     * @param array{0:mixed,1:mixed} $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
          'type'    => 'between',
          'column'  => $column,
          'values'  => $values,
          'boolean' => $boolean,
          'not'     => $not,
        ];

        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a WHERE EXISTS clause.
     *
     * @param callable(QueryBuilder):void $callback
     */
    public function whereExists(callable $callback, string $boolean = 'and', bool $not = false): self
    {
        $query = $this->newQuery();
        $callback($query);

        $this->wheres[] = [
          'type'    => 'exists',
          'query'   => $query,
          'boolean' => $boolean,
          'not'     => $not,
        ];

        $this->bindings = array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
          'type'    => 'in',
          'column'  => $column,
          'values'  => $values,
          'boolean' => $boolean,
          'not'     => $not,
        ];

        $this->bindings = array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a nested WHERE clause.
     *
     * @param callable(QueryBuilder):void $callback
     */
    public function whereNested(callable $callback, string $boolean = 'and'): self
    {
        $query = $this->newQuery();
        $callback($query);

        if ($query->wheres !== []) {
            $this->wheres[] = [
              'type'    => 'nested',
              'query'   => $query,
              'boolean' => $boolean,
            ];

            $this->bindings = array_merge($this->bindings, $query->getBindings());
        }

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     *
     * @param array{0:mixed,1:mixed} $values
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilder):void $callback
     */
    public function whereNotExists(callable $callback, string $boolean = 'and'): self
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE NOT NULL clause.
     */
    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add a WHERE NULL clause.
     */
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
          'type'    => 'null',
          'column'  => $column,
          'boolean' => $boolean,
          'not'     => $not,
        ];

        return $this;
    }

    /**
     * Add a raw WHERE clause.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $this->wheres[] = [
          'type'    => 'raw',
          'sql'     => $sql,
          'boolean' => $boolean,
        ];

        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }
}
