<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Generator;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Pagination\LengthAwarePaginator;
use Infocyph\DBLayer\Query\Concerns\QueryBuilderCaching;
use Infocyph\DBLayer\Query\Concerns\QueryBuilderCompilation;
use Infocyph\DBLayer\Query\Concerns\QueryBuilderInternals;
use Infocyph\DBLayer\Query\Concerns\QueryBuilderKeysetPagination;
use Infocyph\DBLayer\Query\Concerns\QueryBuilderResults;
use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Core\WindowExpression;

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
    use QueryBuilderCaching;
    use QueryBuilderCompilation;
    use QueryBuilderInternals;
    use QueryBuilderKeysetPagination;
    use QueryBuilderResults;

    /**
     * Allowed operators for where/having/join clauses.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<>',
        '<',
        '>',
        '<=',
        '>=',
        '<=>',
        'like',
        'not like',
        'ilike',
        'not ilike',
        'regexp',
        'not regexp',
        'rlike',
        'not rlike',
        '~',
        '~*',
        '!~',
        '!~*',
        'similar to',
        'not similar to',
        'is',
        'is not',
        'between',
        'not between',
        'in',
        'not in',
    ];

    /**
     * Aggregate function definition or null.
     *
     * @var array{function:string,column:string}|null
     */
    private ?array $aggregate = null;

    /**
     * Rare clause-specific bindings, populated only when those clauses use
     * placeholders.
     *
     * @var array{
     *   select?:list<mixed>,
     *   from?:list<mixed>,
     *   join?:list<mixed>,
     *   having?:list<mixed>,
     *   union?:list<mixed>
     * }
     */
    private array $bindingBuckets = [];

    /**
     * WHERE-clause bindings.
     *
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * SELECT columns.
     *
     * @var list<string|Expression|WindowExpression>
     */
    private array $columns = ['*'];

    /**
     * Whether a developer-controlled raw fragment entered this builder.
     */
    private bool $containsRawFragments = false;

    /**
     * Bindings from CTE definitions (must be emitted before main-query bindings).
     *
     * @var list<mixed>
     */
    private array $cteBindings = [];

    /**
     * Common table expressions.
     *
     * @var list<array{name:string,query:string|QueryBuilder,recursive:bool}>
     */
    private array $ctes = [];

    /**
     * DISTINCT flag.
     */
    private bool $distinct = false;

    /**
     * FROM table.
     */
    private ?string $from = null;

    /**
     * Structured alias for the FROM source.
     */
    private ?string $fromAlias = null;

    /**
     * Structured child payload for a builder-backed derived source.
     */
    private ?QueryBuilder $fromSubquery = null;

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
        /**
         * Database connection.
         */
        private readonly Connection $connection,
        /**
         * Query executor.
         */
        private readonly Executor $executor,
    ) {}

    /**
     * Add a select column (no duplicates).
     */
    public function addSelect(string|Expression $column): self
    {
        if (is_string($column)) {
            $this->validateColumnIdentifier($column, true);
        }

        if (!\in_array($column, $this->columns, true)) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Add a selected column with a separately validated output alias.
     */
    public function addSelectAs(string $column, string $alias): self
    {
        $this->validateColumnIdentifier($column, false);
        $this->validateColumnIdentifier($alias, false);

        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->type = 'select';
        $this->columns[] = $column . ' AS ' . $alias;

        return $this;
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
     * @param callable(list<array<string,mixed>>,int):bool $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count <= 0) {
            throw QueryException::invalidLimit($count);
        }

        foreach ($this->offsetChunks($count) as [$results, $page]) {
            if ($callback($results, $page) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process results in chunks using a key (typically primary key).
     *
     * Safer for large tables than OFFSET-based chunk() when rows are inserted/deleted.
     *
     * @param mixed $fromId Initial cursor value for the chunk column.
     * @param callable(list<array<string,mixed>>,int):bool $callback
     */
    public function chunkById(
        int $count,
        callable $callback,
        string $column = 'id',
        mixed $fromId = null,
        string $direction = 'asc',
    ): bool {
        foreach ($this->keysetChunks($count, $column, $fromId, $direction) as [$results, $page]) {
            if ($callback($results, $page) === false) {
                return false;
            }
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
        return $this->toInt($this->runAggregate('COUNT', $column, true), 0);
    }

    /**
     * Add a CROSS JOIN clause.
     */
    public function crossJoin(string $table): self
    {
        $this->validateTableIdentifier($table);

        $this->joins[] = [
            'type' => 'cross',
            'table' => $table,
        ];

        return $this;
    }

    /**
     * Add a structured CROSS JOIN alias.
     */
    public function crossJoinAs(string $table, string $alias): self
    {
        $this->validateTableIdentifier($table);
        $this->validateColumnIdentifier($alias, false);

        $this->joins[] = [
            'type' => 'cross',
            'table' => $table,
            'alias' => $alias,
        ];

        return $this;
    }

    /**
     * Delete records.
     */
    public function delete(): int
    {
        $affected = $this->executor->delete($this);

        if ($affected > 0) {
            $this->invalidateCachedTables();
        }

        return $affected;
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
     * Inspect this SELECT query's database-native execution plan.
     *
     * analyze=true executes the SELECT. Other options are driver-specific and
     * rejected when unsupported instead of being silently ignored.
     *
     * @return list<array<string,mixed>>
     */
    public function explain(
        bool $analyze = false,
        bool $buffers = false,
        bool $verbose = false,
    ): array {
        return $this->connection->explain(
            $this->toSelectSql(),
            $this->getBindings(),
            $analyze,
            $buffers,
            $verbose,
        );
    }

    /**
     * Find a row by primary key (default: "id").
     *
     * @return array<string,mixed>|null
     */
    public function find(mixed $id, string $column = 'id'): ?array
    {
        $clone = $this->cloneBuilder();

        return $clone->where($this->requireNonEmptyString($column, 'column'), '=', $id)->first();
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
     * @param callable(self):void|non-empty-string $column
     * @return array<string,mixed>|null
     */
    public function firstWhere(
        string|callable $column,
        mixed $operator = null,
        mixed $value = null,
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
        $this->validateTableIdentifier($table);

        $this->from = $table;
        $this->fromAlias = null;
        $this->fromSubquery = null;

        return $this;
    }

    /**
     * Set table source to a subquery.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    public function fromSub(QueryBuilder|callable|string $query, string $as, array $bindings = []): self
    {
        $this->validateColumnIdentifier($as, false);

        if (\is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        if ($query instanceof self) {
            $this->containsRawFragments = $this->containsRawFragments || $query->containsRawFragments;
            $this->from = null;
            $this->fromSubquery = $query;
            $this->fromAlias = $as;
            $this->appendBindingBucket('from', $query->getBindings());

            return $this;
        }

        $this->validateRawFragment($query, $bindings);
        $this->from = '(' . $query . ') as ' . $as;
        $this->fromSubquery = null;
        $this->fromAlias = null;
        $this->appendBindingBucket('from', $bindings);

        return $this;
    }

    /**
     * Get query bindings.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        if ($this->cteBindings === [] && $this->bindingBuckets === []) {
            return $this->bindings;
        }

        return \array_merge(
            $this->cteBindings,
            $this->bindingBuckets['select'] ?? [],
            $this->bindingBuckets['from'] ?? [],
            $this->bindingBuckets['join'] ?? [],
            $this->bindings,
            $this->bindingBuckets['having'] ?? [],
            $this->bindingBuckets['union'] ?? [],
        );
    }

    /**
     * Get a read-only snapshot of builder components.
     *
     * @return array{
     *   type:?string,
     *   ctes:list<array{name:string,query:string|QueryBuilder,recursive:bool}>,
     *   columns:list<string|Expression|WindowExpression>,
     *   distinct:bool,
     *   from:?string,
     *   fromAlias:?string,
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
            'type' => $this->type,
            'ctes' => $this->ctes,
            'columns' => $this->columns,
            'distinct' => $this->distinct,
            'from' => $this->from,
            'fromAlias' => $this->fromAlias,
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
        foreach ($groups as $group) {
            $this->validateColumnIdentifier($group, false);
        }

        foreach ($groups as $group) {
            $this->groups[] = $group;
        }

        return $this;
    }

    /**
     * Add a HAVING clause.
     */
    public function having(
        string $column,
        mixed $operator = null,
        mixed $value = null,
        string $boolean = 'and',
    ): self {
        $boolean = $this->normalizeBoolean($boolean);

        if (\func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (!is_string($operator)) {
            throw QueryException::invalidOperator($this->stringifyScalar($operator));
        }

        $operator = $this->assertValidOperator($operator);
        $this->validateColumnIdentifier($column, false);

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->bindingBuckets['having'][] = $value;

        return $this;
    }

    /**
     * Insert a new record (single or multiple rows).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insert(array $values): bool
    {
        $inserted = $this->executor->insert($this, $values);

        if ($inserted) {
            $this->invalidateCachedTables();
        }

        return $inserted;
    }

    /**
     * Insert and get the ID.
     *
     * Uses insertReturning() when supported to avoid extra round trips.
     * Uses one INSERT and reads the normalized generated identifier when the
     * driver does not expose RETURNING.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        $column = $sequence ?? 'id';
        $this->validateColumnIdentifier($column, false);
        $result = $this->executor->insertReturningResult($this, $values, $column);
        $this->invalidateCachedTables();
        $row = $result->rows[0] ?? null;

        if (is_array($row) && \array_key_exists($column, $row)) {
            return $this->stringifyScalar($row[$column]);
        }

        if ($result->lastInsertId !== null && $result->lastInsertId !== '') {
            return $result->lastInsertId;
        }

        throw QueryException::generatedIdUnavailable();
    }

    /**
     * Insert ignoring duplicate-key errors when the driver supports it.
     *
     * Throws when the active driver does not declare native support.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     */
    public function insertIgnore(array $values): bool
    {
        $inserted = $this->executor->insertIgnore($this, $values);

        if ($inserted) {
            $this->invalidateCachedTables();
        }

        return $inserted;
    }

    /**
     * Insert and return generated key/row when supported.
     *
     * Uses native RETURNING when declared by the driver. Otherwise the single
     * INSERT's normalized generated identifier is exposed as a synthesized row.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return array<string,mixed>|null
     */
    public function insertReturning(array $values, ?string $column = null): ?array
    {
        $column ??= 'id';
        $this->validateColumnIdentifier($column, false);
        $result = $this->executor->insertReturningResult($this, $values, $column);
        $this->invalidateCachedTables();
        $row = $result->rows[0] ?? null;

        if (is_array($row)) {
            return $row;
        }

        if ($result->lastInsertId !== null) {
            return [$column => $result->lastInsertId];
        }

        return null;
    }

    /**
     * Add a simple JOIN clause.
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner',
    ): self {
        $type = $this->normalizeJoinType($type);
        $this->validateTableIdentifier($table);
        $this->validateColumnIdentifier($first, false);
        $this->validateColumnIdentifier($second, false);
        $operator = $this->assertValidOperator($operator);

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
     * Add a simple JOIN with a separately validated table alias.
     */
    public function joinAs(
        string $table,
        string $alias,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner',
    ): self {
        $type = $this->normalizeJoinType($type);
        $this->validateTableIdentifier($table);
        $this->validateColumnIdentifier($alias, false);
        $this->validateColumnIdentifier($first, false);
        $this->validateColumnIdentifier($second, false);
        $operator = $this->assertValidOperator($operator);

        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'alias' => $alias,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
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
        $type = $this->normalizeJoinType($type);
        $this->validateTableIdentifier($table);

        $join = new JoinClause($table, $type);
        $callback($join);

        $this->joins[] = $join;

        if ($join->getBindings() !== []) {
            $this->appendBindingBucket('join', $join->getBindings());
        }

        return $this;
    }

    /**
     * Add a complex JOIN with a separately validated table alias.
     *
     * @param callable(JoinClause):void $callback
     */
    public function joinComplexAs(
        string $table,
        string $alias,
        callable $callback,
        string $type = 'inner',
    ): self {
        $type = $this->normalizeJoinType($type);
        $this->validateTableIdentifier($table);
        $this->validateColumnIdentifier($alias, false);

        $join = new JoinClause($table, $type, $alias);
        $callback($join);
        $this->joins[] = $join;

        if ($join->getBindings() !== []) {
            $this->appendBindingBucket('join', $join->getBindings());
        }

        return $this;
    }

    /**
     * Join a derived-table subquery.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings Bindings for a raw string subquery.
     */
    public function joinSub(
        QueryBuilder|callable|string $query,
        string $as,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner',
        array $bindings = [],
    ): self {
        $this->validateColumnIdentifier($as, false);
        $this->validateColumnIdentifier($first, false);
        $this->validateColumnIdentifier($second, false);
        $operator = $this->assertValidOperator($operator);
        $type = $this->normalizeJoinType($type, false);

        if (is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        if ($query instanceof self) {
            $this->containsRawFragments = $this->containsRawFragments || $query->containsRawFragments;
            $bindings = $query->getBindings();
            $this->joins[] = [
                'type' => $type,
                'query' => $query,
                'alias' => $as,
                'subquery' => true,
                'first' => $first,
                'operator' => $operator,
                'second' => $second,
            ];
            if ($bindings !== []) {
                $this->appendBindingBucket('join', $bindings);
            }

            return $this;
        } else {
            $this->validateRawFragment($query, $bindings);
            $sql = $query;
        }

        $this->joins[] = [
            'type' => $type,
            'table' => '(' . $sql . ') as ' . $as,
            'subquery' => true,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        if ($bindings !== []) {
            $this->appendBindingBucket('join', $bindings);
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
     * Lazy keyset iteration alias.
     *
     * @return Generator<array<string,mixed>>
     */
    public function lazy(
        int $chunkSize = 1000,
        string $column = 'id',
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        yield from $this->lazyById($chunkSize, $column, $fromId, $direction);
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function leftJoinAs(string $table, string $alias, string $first, string $operator, string $second): self
    {
        return $this->joinAs($table, $alias, $first, $operator, $second, 'left');
    }

    /**
     * Add a LEFT JOIN against a derived-table subquery.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    public function leftJoinSub(
        QueryBuilder|callable|string $query,
        string $as,
        string $first,
        string $operator,
        string $second,
        array $bindings = [],
    ): self {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left', $bindings);
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
        return new self($this->connection, $this->executor);
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
        $this->validateColumnIdentifier($column, false);

        $direction = \strtolower($direction);

        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw QueryException::invalidOrderDirection($direction);
        }

        $this->replaceOrAppendOrder($column, $direction);

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
     *
     * @param callable(QueryBuilder):void|non-empty-string $column
     */
    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (\func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (is_string($column)) {
            $column = $this->requireNonEmptyString($column, 'column');
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
        $clone = $this->cloneBuilder();
        $clone->aggregate = null;

        $clone->offset = ($page - 1) * $perPage;
        $clone->limit = $perPage;

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
        $values = [];

        foreach ($results as $row) {
            $value = $row[$column] ?? null;

            if ($key === null) {
                $values[] = $value;

                continue;
            }

            if (!\array_key_exists($key, $row)) {
                continue;
            }

            $resolvedKey = $row[$key];
            if (!is_int($resolvedKey) && !is_string($resolvedKey)) {
                continue;
            }

            $values[$resolvedKey] = $value;
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

    public function rightJoinAs(string $table, string $alias, string $first, string $operator, string $second): self
    {
        return $this->joinAs($table, $alias, $first, $operator, $second, 'right');
    }

    /**
     * Add a RIGHT JOIN against a derived-table subquery.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    public function rightJoinSub(
        QueryBuilder|callable|string $query,
        string $as,
        string $first,
        string $operator,
        string $second,
        array $bindings = [],
    ): self {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right', $bindings);
    }

    /**
     * Add a raw select expression.
     *
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->validateRawFragment($expression, $bindings);

        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->type = 'select';
        $this->columns[] = new Expression($expression);
        $this->appendBindingBucket('select', $bindings);

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
     * Stream rows lazily for this SELECT query.
     *
     * @return Generator<mixed>
     */
    public function stream(?int $fetchMode = null): Generator
    {
        $this->type ??= 'select';

        yield from $this->connection->stream(
            $this->toSelectSql(),
            $this->getBindings(),
            $fetchMode,
        );
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
     * Build a compiler payload for DELETE.
     */
    public function toDeletePayload(): QueryPayload
    {
        return $this->toPayload()->with([
            'type' => QueryType::DELETE,
        ]);
    }

    /**
     * Build a compiler payload for INSERT.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string> $upsertUpdate
     * @param list<string> $returning
     */
    public function toInsertPayload(
        array $values,
        string $mode = 'insert',
        array $uniqueBy = [],
        array $upsertUpdate = [],
        array $returning = [],
    ): QueryPayload {
        return $this->toPayload()->with([
            'type' => QueryType::INSERT,
            'insertRows' => $this->normalizePayloadInsertRows($values),
            'insertMode' => $mode,
            'uniqueBy' => $uniqueBy,
            'upsertUpdate' => $upsertUpdate,
            'returning' => $returning,
        ]);
    }

    /**
     * Explicit helper to get the SQL for the current SELECT query.
     */
    public function toSelectSql(): string
    {
        $this->type ??= 'select';

        return $this->connection->getCompiler()->compile($this->toPayload())->sql;
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
     * Build a compiler payload for TRUNCATE.
     */
    public function toTruncatePayload(): QueryPayload
    {
        return $this->toPayload()->with([
            'type' => QueryType::TRUNCATE,
        ]);
    }

    /**
     * Build a compiler payload for UPDATE.
     *
     * @param array<string,mixed> $values
     */
    public function toUpdatePayload(array $values): QueryPayload
    {
        return $this->toPayload()->with([
            'type' => QueryType::UPDATE,
            'updateValues' => $this->normalizePayloadUpdateValues($values),
        ]);
    }

    /**
     * Truncate the table.
     */
    public function truncate(): bool
    {
        $truncated = $this->executor->truncate($this);

        if ($truncated) {
            $this->invalidateCachedTables();
        }

        return $truncated;
    }

    /**
     * Stream rows with the driver's explicit bounded-memory strategy.
     *
     * PostgreSQL uses $fetchSize server-cursor batches. MySQL keeps its
     * connection occupied until this generator is consumed or closed.
     *
     * @return Generator<mixed>
     */
    public function unbufferedStream(?int $fetchMode = null, int $fetchSize = 1000): Generator
    {
        $this->type ??= 'select';

        yield from $this->connection->unbufferedStream(
            $this->toSelectSql(),
            $this->getBindings(),
            $fetchMode,
            $fetchSize,
        );
    }

    /**
     * Add a UNION query.
     *
     * @param QueryBuilder|callable(QueryBuilder):void $query
     */
    public function union(QueryBuilder|callable $query, bool $all = false): self
    {
        if (\is_callable($query)) {
            $builder = $this->newQuery();
            $query($builder);
            $query = $builder;
        }

        $this->containsRawFragments = $this->containsRawFragments || $query->containsRawFragments;

        $this->unions[] = [
            'query' => $query,
            'all' => $all,
        ];

        $this->appendBindingBucket('union', $query->getBindings());

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
     * Inverse of when(): apply callback only if the value is "falsey".
     *
     * @param callable(self,mixed):void $callback
     * @param callable(self,mixed):void|null $default
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): self
    {
        if (!$value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
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

        $affected = $this->executor->update($this, $values);

        if ($affected > 0) {
            $this->invalidateCachedTables();
        }

        return $affected;
    }

    /**
     * Upsert helper: INSERT ... ON DUPLICATE KEY UPDATE / ON CONFLICT.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): bool
    {
        $upserted = $this->executor->upsert($this, $values, $uniqueBy, $update);

        if ($upserted) {
            $this->invalidateCachedTables();
        }

        return $upserted;
    }

    /**
     * Upsert and return affected rows when possible.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     * @param list<string> $returning
     * @return list<array<string,mixed>>
     */
    public function upsertReturning(
        array $values,
        array $uniqueBy,
        ?array $update = null,
        array $returning = ['*'],
    ): array {
        $rows = $this->executor->upsertReturning($this, $values, $uniqueBy, $update, $returning);
        $this->invalidateCachedTables();

        return $rows;
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
     * @param callable(self,mixed):void $callback
     * @param callable(self,mixed):void|null $default
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
     * @param callable(QueryBuilder):void|non-empty-string $column
     */
    public function where(
        string|callable $column,
        mixed $operator = null,
        mixed $value = null,
        string $boolean = 'and',
    ): self {
        $boolean = $this->normalizeBoolean($boolean);

        // Handle closure for nested where.
        if (\is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Handle two arguments (column, value).
        if (\func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->validateColumnIdentifier($column, false);

        if (!is_string($operator)) {
            throw QueryException::invalidOperator($this->stringifyScalar($operator));
        }

        $operator = $this->assertValidOperator($operator);

        return $this->appendWhere(
            [
                'type' => 'basic',
                'column' => $column,
                'operator' => $operator,
                'value' => $value,
                'boolean' => $boolean,
            ],
            [$value],
        );
    }

    /**
     * Add a WHERE BETWEEN clause.
     *
     * @param array{0:mixed,1:mixed} $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column, false);

        return $this->appendWhere(
            [
                'type' => 'between',
                'column' => $column,
                'values' => $values,
                'boolean' => $boolean,
                'not' => $not,
            ],
            $values,
        );
    }

    /**
     * Add a WHERE EXISTS clause.
     *
     * @param callable(QueryBuilder):void $callback
     */
    public function whereExists(callable $callback, string $boolean = 'and', bool $not = false): self
    {
        $boolean = $this->normalizeBoolean($boolean);
        $query = $this->newQuery();
        $callback($query);
        $this->containsRawFragments = $this->containsRawFragments || $query->containsRawFragments;

        return $this->appendWhere(
            [
                'type' => 'exists',
                'query' => $query,
                'boolean' => $boolean,
                'not' => $not,
            ],
            $query->getBindings(),
        );
    }

    /**
     * Add a WHERE IN clause.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column, false);

        return $this->appendWhere(
            [
                'type' => 'in',
                'column' => $column,
                'values' => $values,
                'boolean' => $boolean,
                'not' => $not,
            ],
            $values,
        );
    }

    /**
     * Add a nested WHERE clause.
     *
     * @param callable(QueryBuilder):void $callback
     */
    public function whereNested(callable $callback, string $boolean = 'and'): self
    {
        $boolean = $this->normalizeBoolean($boolean);
        $query = $this->newQuery();
        $callback($query);
        $this->containsRawFragments = $this->containsRawFragments || $query->containsRawFragments;

        if ($query->wheres !== []) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean,
            ];

            $this->bindings = \array_merge($this->bindings, $query->getBindings());
        }

        return $this;
    }

    /**
     * Add a WHERE BETWEEN NOT clause.
     *
     * @param array{0:mixed,1:mixed} $values
     */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateColumnIdentifier($column, false);

        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
            'not' => $not,
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
        $boolean = $this->normalizeBoolean($boolean);
        $this->validateRawFragment($sql, $bindings);

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
        ];

        $this->bindings = \array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Add a common table expression.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    public function with(string $name, QueryBuilder|callable|string $query, array $bindings = []): self
    {
        return $this->addCte($name, $query, false, $bindings);
    }

    /**
     * Add a recursive common table expression.
     *
     * @param QueryBuilder|callable(QueryBuilder):void|string $query
     * @param list<mixed> $bindings
     */
    public function withRecursive(string $name, QueryBuilder|callable|string $query, array $bindings = []): self
    {
        return $this->addCte($name, $query, true, $bindings);
    }

    /**
     * Generator alias for stream().
     *
     * @return Generator<mixed>
     */
    public function yieldRows(?int $fetchMode = null): Generator
    {
        yield from $this->stream($fetchMode);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function appendBindingBucket(string $bucket, array $bindings): void
    {
        if ($bindings === []) {
            return;
        }

        $this->bindingBuckets[$bucket] = \array_merge(
            $this->bindingBuckets[$bucket] ?? [],
            $bindings,
        );
    }

    /**
     * @param array<string,mixed> $where
     * @param list<mixed> $bindings
     */
    private function appendWhere(array $where, array $bindings = []): self
    {
        $this->wheres[] = $where;

        if ($bindings === []) {
            return $this;
        }

        $this->bindings = \array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * @param array<string,mixed>|array<int,array<string,mixed>> $values
     * @return list<array<string,mixed>>
     */
    private function normalizePayloadInsertRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (!\is_array(\reset($values))) {
            /** @var array<string,mixed> $row */
            $row = $values;
            $values = [$row];
        }

        /** @var list<array<string,mixed>> $values */
        return $values;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function normalizePayloadUpdateValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $column => $value) {
            if ($column === '') {
                continue;
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /**
     * @return Generator<array{0:list<array<string,mixed>>,1:int}>
     */
    private function offsetChunks(int $chunkSize): Generator
    {
        for ($page = 1; ; $page++) {
            $clone = $this->cloneBuilder();
            $clone->withoutCache();
            $clone->offset = ($page - 1) * $chunkSize;
            $clone->limit = $chunkSize;

            $results = $clone->get();
            $count = \count($results);

            if ($count === 0) {
                break;
            }

            yield [$results, $page];

            if ($count < $chunkSize) {
                break;
            }
        }
    }

    private function resetAggregateWindow(self $builder): void
    {
        foreach (['limit', 'offset', 'lock'] as $key) {
            $builder->{$key} = null;
        }

        $builder->orders = [];
        $builder->unions = [];
        unset($builder->bindingBuckets['union']);
    }

    private function resetCursorWindow(self $builder): void
    {
        $builder->aggregate = null;
        $builder->orders = [];

        foreach (['limit', 'offset'] as $key) {
            $builder->{$key} = null;
        }
    }

    /**
     * @param list<array<string,mixed>> $results
     * @return array{0:list<array<string,mixed>>,1:bool}
     */
    private function resolvePaginatedItems(array $results, int $perPage): array
    {
        $hasMore = \count($results) > $perPage;

        if (!$hasMore) {
            return [$results, false];
        }

        return [\array_slice($results, 0, $perPage), true];
    }
}
