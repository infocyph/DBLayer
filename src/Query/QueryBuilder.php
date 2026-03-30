<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Generator;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Pagination\SimplePaginator;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;

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
 * - Conditional clauses (when / unless)
 * - Pagination helpers (forPage / chunk / cursor / paginate / simplePaginate / cursorPaginate)
 */
class QueryBuilder
{
    /**
     * Database connection.
     */
    private readonly Connection $connection;

    /**
     * Query executor.
     */
    private readonly Executor $executor;

    /**
     * SQL grammar compiler.
     */
    private readonly Grammar $grammar;

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
     * DISTINCT flag.
     */
    private bool $distinct = false;

    /**
     * FROM table.
     */
    private ?string $from = null;

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
        if (! \in_array($column, $this->columns, true)) {
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
        return $this->runAggregate($function, $column, false);
    }

    /**
     * Get the average of a column.
     */
    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Process the query results in chunks.
     *
     * The callback receives (list<row>, pageNumber) and may return false to stop.
     *
     * @param  callable(list<array<string,mixed>>,int):bool  $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count <= 0) {
            throw QueryException::invalidLimit($count);
        }

        $page = 1;

        do {
            $clone         = $this->cloneBuilder();
            $clone->offset = ($page - 1) * $count;
            $clone->limit  = $count;

            $results = $clone->get();
            $num     = \count($results);

            if ($num === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($num === $count);

        return true;
    }

    /**
     * Process results in chunks using a key (typically primary key).
     *
     * Safer for large tables than OFFSET-based chunk() when rows are inserted/deleted.
     *
     * @param  mixed  $fromId  Initial cursor value for the chunk column.
     * @param  callable(list<array<string,mixed>>,int):bool  $callback
     */
    public function chunkById(
      int $count,
      callable $callback,
      string $column = 'id',
      mixed $fromId = null
    ): bool {
        if ($count <= 0) {
            throw QueryException::invalidLimit($count);
        }

        $lastId = $fromId;
        $page   = 1;

        while (true) {
            $clone = $this->cloneBuilder();

            if ($lastId !== null) {
                $clone->where($column, '>', $lastId);
            }

            $clone->orderBy($column, 'asc');
            $clone->limit = $count;

            $results = $clone->get();
            $num     = \count($results);

            if ($num === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $lastRow = $results[$num - 1];
            $lastId  = $lastRow[$column] ?? $lastId;

            $page++;
        }

        return true;
    }

    /**
     * Clone the query builder (explicit helper).
     */
    public function cloneBuilder(): self
    {
        return clone $this;
    }

    /**
     * Get the count of results (ignores LIMIT/OFFSET for correctness in pagination).
     */
    public function count(string $column = '*'): int
    {
        return (int) ($this->runAggregate('COUNT', $column, true) ?? 0);
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
     * Iterate over the results using a simple cursor based on LIMIT/OFFSET.
     *
     * This keeps memory usage bounded by $chunkSize.
     *
     * @return Generator<array<string,mixed>>
     */
    public function cursor(int $chunkSize = 1000): Generator
    {
        if ($chunkSize <= 0) {
            throw QueryException::invalidLimit($chunkSize);
        }

        $page = 1;

        while (true) {
            $clone         = $this->cloneBuilder();
            $clone->offset = ($page - 1) * $chunkSize;
            $clone->limit  = $chunkSize;

            $results = $clone->get();
            $num     = \count($results);

            if ($num === 0) {
                break;
            }

            foreach ($results as $row) {
                yield $row;
            }

            if ($num < $chunkSize) {
                break;
            }

            $page++;
        }
    }

    /**
     * Cursor-based pagination.
     *
     * This assumes a stable ordering by $column. For large datasets this is
     * more efficient than OFFSET-based pagination.
     *
     * @param  int  $perPage  Items per page
     * @param  mixed  $cursor  Last seen value for $column (raw DB value)
     * @param  string  $column  Ordered column used as the cursor (default: "id")
     * @param  non-empty-string  $direction  "asc" or "desc"
     *
     * @throws QueryException
     */
    public function cursorPaginate(
      int $perPage = 15,
      mixed $cursor = null,
      string $column = 'id',
      string $direction = 'asc'
    ): CursorPaginator {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $direction = \strtolower($direction);

        if (! \in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::invalidOrderDirection($direction);
        }

        $operator = $direction === 'asc' ? '>' : '<';

        $clone            = $this->cloneBuilder();
        $clone->aggregate = null;
        $clone->limit     = null;
        $clone->offset    = null;
        $clone->orders    = [];

        if ($cursor !== null) {
            $clone->where($column, $operator, $cursor);
        }

        $clone->orderBy($column, $direction);
        $clone->limit = $perPage + 1;

        $results = $clone->get();
        $hasMore = \count($results) > $perPage;

        if ($hasMore) {
            $items = \array_slice($results, 0, $perPage);
        } else {
            $items = $results;
        }

        $nextCursor = null;

        if ($hasMore && $items !== []) {
            $lastRow       = $items[\count($items) - 1];
            $nextCursorVal = $lastRow[$column] ?? null;

            if ($nextCursorVal !== null) {
                $nextCursor = (string) $nextCursorVal;
            }
        }

        $currentCursor = $cursor !== null ? (string) $cursor : null;

        return new CursorPaginator(
          $items,
          $perPage,
          $currentCursor,
          $nextCursor,
          $hasMore
        );
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
     * Find a row by primary key (default: "id").
     *
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, string $column = 'id'): ?array
    {
        $clone = $this->cloneBuilder();

        return $clone->where($column, '=', $id)->first();
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
     * Execute the query and get the first result matching the constraint.
     *
     * @param  callable(self):void|non-empty-string  $column
     * @return array<string,mixed>|null
     */
    public function firstWhere(
      string|callable $column,
      mixed $operator = null,
      mixed $value = null
    ): ?array {
        return $this->where($column, $operator, $value)->first();
    }

    /**
     * Apply pagination offsets based on page and per-page values.
     *
     * This mutates the current builder (like skip/take) and returns it.
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $page = \max(1, $page);

        $this->offset(($page - 1) * $perPage);
        $this->limit($perPage);

        return $this;
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
     * Expose the connection (escape hatch for low-level usage).
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the current query type (e.g. "select") or null if not set.
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Add a GROUP BY clause.
     */
    public function groupBy(string ...$groups): self
    {
        $this->groups = \array_merge($this->groups, $groups);

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
        if (\func_num_args() === 2) {
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
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     */
    public function insert(array $values): bool
    {
        return $this->executor->insert($this, $values);
    }

    /**
     * Insert and get the ID.
     *
     * Uses insertReturning() when supported to avoid extra round trips.
     * Falls back to insert() + lastInsertId().
     *
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        $column = $sequence ?? 'id';

        $row = $this->insertReturning($values, $column);

        if ($row !== null && \array_key_exists($column, $row)) {
            return (string) $row[$column];
        }

        $this->insert($values);

        return $this->connection->lastInsertId($sequence);
    }

    /**
     * Insert ignoring duplicate-key errors when the driver supports it.
     *
     * Falls back to insert() on drivers without native support.
     *
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     */
    public function insertIgnore(array $values): bool
    {
        return $this->executor->insertIgnore($this, $values);
    }

    /**
     * Insert and return generated key/row when supported.
     *
     * On PostgreSQL, uses INSERT ... RETURNING.
     * On other drivers, falls back to lastInsertId() and synthesizes a row.
     *
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     * @return array<string,mixed>|null
     */
    public function insertReturning(array $values, ?string $column = null): ?array
    {
        return $this->executor->insertReturning($this, $values, $column);
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
     * @param  callable(JoinClause):void  $callback
     */
    public function joinComplex(string $table, callable $callback, string $type = 'inner'): self
    {
        $join = new JoinClause($table, $type);
        $callback($join);

        $this->joins[] = $join;

        if ($join->getBindings() !== []) {
            $this->bindings = \array_merge($this->bindings, $join->getBindings());
        }

        return $this;
    }

    /**
     * Convenience: order by "created_at" DESC.
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
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
     * Convenience: order by "created_at" ASC.
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = \strtolower($direction);

        if (! \in_array($direction, ['asc', 'desc'], true)) {
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
        if (\func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Paginate the results with a known total.
     *
     * @throws QueryException
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $page = \max(1, $page ?? 1);

        // Total (ignores LIMIT/OFFSET).
        $total = $this->count();

        // Page items.
        $clone            = $this->cloneBuilder();
        $clone->aggregate = null;

        $clone->offset = ($page - 1) * $perPage;
        $clone->limit  = $perPage;

        $items = $clone->get();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * Pluck a single column's values from the result set.
     *
     * @return array<int|string,mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $results = $this->get();
        $values  = [];

        foreach ($results as $row) {
            $value = $row[$column] ?? null;

            if ($key === null) {
                $values[] = $value;

                continue;
            }

            if (! \array_key_exists($key, $row)) {
                continue;
            }

            $values[$row[$key]] = $value;
        }

        return $values;
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
     * @param  array<int,string|Expression>|string|Expression  ...$columns
     */
    public function select(array|string|Expression ...$columns): self
    {
        if ($columns === []) {
            return $this;
        }

        $this->type    = 'select';
        $this->columns = \is_array($columns[0]) ? $columns[0] : $columns;

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
     * Simple pagination without a COUNT(*) query.
     *
     * @throws QueryException
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null): SimplePaginator
    {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $page = \max(1, $page ?? 1);

        $clone            = $this->cloneBuilder();
        $clone->aggregate = null;

        $offset        = ($page - 1) * $perPage;
        $clone->offset = $offset;
        $clone->limit  = $perPage + 1; // fetch one extra row

        $results = $clone->get();
        $hasMore = \count($results) > $perPage;

        if ($hasMore) {
            $items = \array_slice($results, 0, $perPage);
        } else {
            $items = $results;
        }

        return new SimplePaginator($items, $perPage, $page, $hasMore);
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
     * New pipeline: convert the current query into a QueryPayload
     * so the driver/compiler can generate SQL.
     *
     * NOTE: "distinct" is not yet represented in QueryPayload; the
     * Grammar path remains the canonical source of truth for now.
     */
    public function toPayload(): QueryPayload
    {
        $type = $this->mapTypeToEnum($this->type);

        $unionPayloads = [];

        foreach ($this->unions as $union) {
            /** @var QueryBuilder $unionQuery */
            $unionQuery = $union['query'];

            $unionPayloads[] = [
              'query' => $unionQuery->toPayload(),
              'all'   => (bool) $union['all'],
            ];
        }

        return new QueryPayload(
          type: $type,
          table: $this->from,
          columns: $this->columns,
          wheres: $this->wheres,
          joins: $this->joins,
          groups: $this->groups,
          havings: $this->havings,
          orders: $this->orders,
          limit: $this->limit,
          offset: $this->offset,
          unions: $unionPayloads,
          lock: $this->lock,
          aggregate: $this->aggregate,
          bindings: $this->bindings,
        );
    }

    /**
     * Explicit helper to get the SQL for the current SELECT query.
     */
    public function toSelectSql(): string
    {
        if ($this->type === null) {
            $this->type = 'select';
        }

        return $this->grammar->compileSelect($this);
    }

    /**
     * Get the SQL query string for the current SELECT query.
     *
     * This is primarily intended for debugging/logging SELECTs.
     * Non-SELECT operations (insert/update/delete) are executed via the Executor
     * and may require additional context (e.g. values) that is not tracked here.
     */
    public function toSql(): string
    {
        return $this->toSelectSql();
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
     * @param  QueryBuilder|callable(QueryBuilder):void  $query
     */
    public function union(QueryBuilder|callable $query, bool $all = false): self
    {
        if (\is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        $this->unions[] = [
          'query' => $query,
          'all'   => $all,
        ];

        $this->bindings = \array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a UNION ALL query.
     *
     * @param  QueryBuilder|callable(QueryBuilder):void  $query
     */
    public function unionAll(QueryBuilder|callable $query): self
    {
        return $this->union($query, true);
    }

    /**
     * Inverse of when(): apply callback only if the value is "falsey".
     *
     * @param  callable(self,mixed):void  $callback
     * @param  callable(self,mixed):void|null  $default
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): self
    {
        if (! $value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * Update records.
     *
     * @param  array<string,mixed>  $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->executor->update($this, $values);
    }

    /**
     * Upsert helper: INSERT ... ON DUPLICATE KEY UPDATE / ON CONFLICT.
     *
     * @param  array<string,mixed>|array<int,array<string,mixed>>  $values
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        return $this->executor->upsert($this, $values, $uniqueBy, $update);
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
     * Conditionally apply query modifications.
     *
     * Example:
     *  $builder
     *      ->when($search, fn ($q, $search) => $q->where('name', 'like', "%$search%"))
     *      ->when($active, fn ($q) => $q->where('active', 1));
     *
     * @param  callable(self,mixed):void  $callback
     * @param  callable(self,mixed):void|null  $default
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): self
    {
        if ($value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * Add a WHERE clause.
     *
     * @param  callable(QueryBuilder):void|non-empty-string  $column
     */
    public function where(
      string|callable $column,
      mixed $operator = null,
      mixed $value = null,
      string $boolean = 'and'
    ): self {
        // Handle closure for nested where.
        if (\is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Handle two arguments (column, value).
        if (\func_num_args() === 2) {
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
     * @param  array{0:mixed,1:mixed}  $values
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

        $this->bindings = \array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a WHERE EXISTS clause.
     *
     * @param  callable(QueryBuilder):void  $callback
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

        $this->bindings = \array_merge($this->bindings, $query->getBindings());

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     *
     * @param  list<mixed>  $values
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

        $this->bindings = \array_merge($this->bindings, $values);

        return $this;
    }

    /**
     * Add a nested WHERE clause.
     *
     * @param  callable(QueryBuilder):void  $callback
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

            $this->bindings = \array_merge($this->bindings, $query->getBindings());
        }

        return $this;
    }

    /**
     * Add a WHERE BETWEEN NOT clause.
     *
     * @param  array{0:mixed,1:mixed}  $values
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param  list<mixed>  $values
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
     * @param  list<mixed>  $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $this->wheres[] = [
          'type'    => 'raw',
          'sql'     => $sql,
          'boolean' => $boolean,
        ];

        $this->bindings = \array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Map legacy string type to QueryType enum.
     */
    private function mapTypeToEnum(?string $type): QueryType
    {
        $type = $type !== null ? \strtolower($type) : 'select';

        return match ($type) {
            'insert'   => QueryType::INSERT,
            'update'   => QueryType::UPDATE,
            'delete'   => QueryType::DELETE,
            'truncate' => QueryType::TRUNCATE,
            'select', '' => QueryType::SELECT,
            default    => QueryType::SELECT,
        };
    }

    /**
     * Internal helper to run aggregate queries.
     *
     * @param  non-empty-string  $function
     */
    private function runAggregate(string $function, string $column = '*', bool $ignoreLimitOffset = false): mixed
    {
        $clone = clone $this;

        if ($ignoreLimitOffset) {
            $clone->limit  = null;
            $clone->offset = null;
            $clone->orders = [];
            $clone->unions = [];
            $clone->lock   = null;
        }

        $clone->aggregate = [
          'function' => \strtoupper($function),
          'column'   => $column,
        ];

        $results = $this->executor->select($clone);

        if ($results === []) {
            return null;
        }

        $row = $results[0];

        return $row['aggregate'] ?? (\array_values($row)[0] ?? null);
    }
}
