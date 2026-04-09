<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Grammar\Grammar;

/**
 * Query Executor
 *
 * Executes compiled SQL queries with:
 * - Parameter binding
 * - Result fetching
 * - Error handling
 * - Query logging
 * - Performance tracking
 * - Event dispatching
 */
final class Executor
{
    /**
     * Database connection.
     */
    private readonly Connection $connection;

    /**
     * SQL grammar compiler (legacy path).
     */
    private readonly Grammar $grammar;

    /**
     * Whether to dispatch query events.
     */
    private bool $dispatchEvents = true;

    /**
     * Enable query logging.
     */
    private bool $logging = false;

    /**
     * Maximum number of query log entries to keep (null = unbounded).
     */
    private ?int $maxLogEntries = null;

    /**
     * Query execution log.
     *
     * Each entry:
     *  - sql (string)
     *  - bindings (list<mixed>)
     *  - time (float, ms)
     *  - timestamp (float, seconds)
     *  - error (string|null)
     *
     * @var list<array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error?:string
     * }>
     */
    private array $queryLog = [];

    /**
     * Number of retained log entries.
     */
    private int $queryLogCount = 0;

    /**
     * Ring-buffer start offset when bounded logging is enabled.
     */
    private int $queryLogStart = 0;

    /**
     * Whether to perform binding count validation.
     */
    private bool $validateBindings = true;

    /**
     * Create a new executor instance.
     */
    public function __construct(Connection $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar    = $grammar;
    }

    /**
     * Clear the query log.
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
        $this->queryLogCount = 0;
        $this->queryLogStart = 0;
    }

    /**
     * Execute a DELETE query.
     */
    public function delete(QueryBuilder $query): int
    {
        $sql      = $this->grammar->compileDelete($query);
        $bindings = $query->getBindings();

        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $affected = $this->connection->delete($sql, $bindings);
            $elapsed  = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $affected),
                );
            }

            return $affected;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Disable binding count validation.
     */
    public function disableBindingValidation(): void
    {
        $this->validateBindings = false;
    }

    /**
     * Disable query event dispatching.
     */
    public function disableEvents(): void
    {
        $this->dispatchEvents = false;
    }

    /**
     * Disable query logging.
     */
    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    /**
     * Enable binding count validation.
     */
    public function enableBindingValidation(): void
    {
        $this->validateBindings = true;
    }

    /**
     * Enable query event dispatching.
     */
    public function enableEvents(): void
    {
        $this->dispatchEvents = true;
    }

    /**
     * Enable query logging.
     */
    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /**
     * Get failed queries.
     *
     * @return list<array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error:string
     * }>
     */
    public function getFailedQueries(): array
    {
        $logs = $this->getQueryLog();

        /** @var list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error:string}> $failed */
        $failed = \array_values(\array_filter(
            $logs,
            static fn(array $log): bool => isset($log['error']),
        ));

        return $failed;
    }

    /**
     * Get the query log.
     *
     * @return list<array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error?:string
     * }>
     */
    public function getQueryLog(): array
    {
        return $this->orderedQueryLog();
    }

    /**
     * Get query log statistics.
     *
     * @return array{
     *   total_queries:int,
     *   total_time:float,
     *   avg_time:float,
     *   min_time:float,
     *   max_time:float,
     *   failed_queries:int
     * }
     */
    public function getQueryStats(): array
    {
        if ($this->queryLogCount === 0) {
            return [
                'total_queries'  => 0,
                'total_time'     => 0.0,
                'avg_time'       => 0.0,
                'min_time'       => 0.0,
                'max_time'       => 0.0,
                'failed_queries' => 0,
            ];
        }

        $logs   = $this->getQueryLog();
        $times  = \array_column($logs, 'time');
        $failed = $this->getFailedQueries();

        $totalTime = \array_sum($times);
        $count     = \count($times);

        return [
            'total_queries'  => $this->queryLogCount,
            'total_time'     => \round($totalTime, 4),          // ms
            'avg_time'       => \round($totalTime / $count, 4), // ms
            'min_time'       => \round(\min($times), 4),
            'max_time'       => \round(\max($times), 4),
            'failed_queries' => \count($failed),
        ];
    }

    /**
     * Get the slowest queries.
     *
     * @return list<array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error?:string
     * }>
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        $queries = $this->getQueryLog();

        \usort(
            $queries,
            static fn(array $a, array $b): int => $b['time'] <=> $a['time'],
        );

        return \array_slice($queries, 0, $limit);
    }

    /**
     * Execute an INSERT query.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     */
    public function insert(QueryBuilder $query, array $values): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        $sql      = $this->grammar->compileInsert($query, $rows);
        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $result  = $this->connection->insert($sql, $bindings);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $rowsCount = \count($rows);

                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute an INSERT IGNORE / INSERT OR IGNORE when supported.
     *
     * Falls back to normal insert() when the driver has no native support.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     */
    public function insertIgnore(QueryBuilder $query, array $values): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        // Prefer driver-specific ignore semantics when available.
        if (\method_exists($this->grammar, 'compileInsertIgnore')) {
            $sql = $this->grammar->compileInsertIgnore($query, $rows);
        } elseif (\method_exists($this->grammar, 'compileInsertOrIgnore')) {
            $sql = $this->grammar->compileInsertOrIgnore($query, $rows);
        } else {
            // Graceful fallback to regular insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $result  = $this->connection->insert($sql, $bindings);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $rowsCount = \count($rows);

                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute an INSERT with RETURNING semantics when supported.
     *
     * On PostgreSQL, uses INSERT ... RETURNING.
     * On other drivers, falls back to insert() + lastInsertId().
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @return array<string,mixed>|null First returned row or simulated row from lastInsertId()
     */
    public function insertReturning(
        QueryBuilder $query,
        array $values,
        ?string $column = null,
    ): ?array {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return null;
        }

        $column ??= 'id';

        if (\method_exists($this->grammar, 'compileInsertGetId')) {
            $sql      = $this->grammar->compileInsertGetId($query, $rows, $column);
            $bindings = $this->getInsertBindings($rows);

            $this->validateBindingCount($sql, $bindings);

            $startTime = \microtime(true);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executing',
                    new QueryExecuting($sql, $bindings, $this->connection),
                );
            }

            try {
                $resultRows = $this->connection->select($sql, $bindings);
                $elapsed    = \microtime(true) - $startTime;

                $this->logQuery($sql, $bindings, $elapsed);

                if ($this->dispatchEvents) {
                    $this->dispatchEvent(
                        'db.query.executed',
                        new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, \count($resultRows)),
                    );
                }

                return $resultRows[0] ?? null;
            } catch (\Throwable $e) {
                $elapsed = \microtime(true) - $startTime;

                $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

                if ($this->dispatchEvents) {
                    $this->dispatchEvent(
                        'db.query.executed',
                        new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                    );
                }

                throw QueryException::executionFailed($sql, $e->getMessage());
            }
        }

        // Fallback path: normal insert plus lastInsertId().
        $this->insert($query, $rows);
        $id = $this->connection->lastInsertId($column);

        if ($id === '' || $id === null) {
            return null;
        }

        return [$column => $id];
    }

    /**
     * Execute a raw SELECT query.
     *
     * @return list<array<string,mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $results = $this->connection->select($sql, $bindings);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection),
                );
            }

            return $results;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query.
     *
     * Prefer the driver compiler + payload pipeline when available,
     * and gracefully fall back to the legacy Grammar path otherwise.
     *
     * @return list<array<string,mixed>>
     */
    public function select(QueryBuilder $query): array
    {
        // Use compiler path only for query shapes known to be equivalent to grammar output.
        if (
            \method_exists($query, 'toPayload')
            && \method_exists($this->connection, 'getCompiler')
            && $this->canUseDriverCompiler($query)
        ) {
            $payload  = $query->toPayload();
            $compiler = $this->connection->getCompiler();
            $compiled = $compiler->compile($payload);

            return $this->raw($compiled->sql, $compiled->bindings);
        }

        // Legacy path: Grammar-based compilation.
        $sql      = $this->grammar->compileSelect($query);
        $bindings = $query->getBindings();

        return $this->raw($sql, $bindings);
    }

    /**
     * Set maximum number of query log entries to keep.
     *
     * Pass null or <= 0 for unbounded.
     */
    public function setMaxQueryLogEntries(?int $max): void
    {
        $this->maxLogEntries = $max !== null && $max > 0 ? $max : null;
        $this->reconfigureQueryLogStorage();
    }

    /**
     * Execute a raw statement (INSERT, UPDATE, DELETE, DDL, etc.)
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $this->connection->execute($sql, $bindings);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection),
                );
            }

            return true;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a TRUNCATE query.
     */
    public function truncate(QueryBuilder $query): bool
    {
        $sql = $this->grammar->compileTruncate($query);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, [], $this->connection),
            );
        }

        try {
            $this->connection->execute($sql);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, [], $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, [], $elapsed * 1000, $this->connection),
                );
            }

            return true;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, [], $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, [], $elapsed * 1000, $this->connection),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute an UPDATE query.
     *
     * @param  array<string,mixed>  $values
     */
    public function update(QueryBuilder $query, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $sql      = $this->grammar->compileUpdate($query, $values);
        $bindings = \array_merge(\array_values($values), $query->getBindings());

        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $affected = $this->connection->update($sql, $bindings);
            $elapsed  = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $affected),
                );
            }

            return $affected;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute an UPSERT (ON CONFLICT / ON DUPLICATE KEY UPDATE) when supported.
     *
     * Falls back to plain insert() when the driver has no native support.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     */
    public function upsert(
        QueryBuilder $query,
        array $values,
        array $uniqueBy,
        ?array $update = null,
    ): bool {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        $firstRow = $rows[0];

        if ($update === null) {
            $allColumns = \array_keys($firstRow);
            $updateCols = \array_values(\array_diff($allColumns, $uniqueBy));
        } else {
            $updateCols = $update;
        }

        // Convert list-of-cols to assoc; grammars only care about the keys.
        $updateAssoc = [];
        foreach ($updateCols as $col) {
            $updateAssoc[$col] = null;
        }

        if (\method_exists($this->grammar, 'compileUpsert')) {
            $sql = $this->grammar->compileUpsert($query, $rows, $uniqueBy, $updateAssoc);
        } elseif (\method_exists($this->grammar, 'compileInsertOnDuplicateKeyUpdate')) {
            $sql = $this->grammar->compileInsertOnDuplicateKeyUpdate($query, $rows, $updateAssoc);
        } else {
            // Graceful fallback: behave like insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $result  = $this->connection->insert($sql, $bindings);
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $rowsCount = \count($rows);

                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent(
                    'db.query.executed',
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute an UPSERT and return affected rows.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     * @param  list<string>  $returning
     * @return list<array<string,mixed>>
     */
    public function upsertReturning(
        QueryBuilder $query,
        array $values,
        array $uniqueBy,
        ?array $update = null,
        array $returning = ['*'],
    ): array {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return [];
        }

        $updateAssoc = $this->resolveUpsertUpdateAssoc($rows[0], $uniqueBy, $update);

        $native = $this->runNativeUpsertReturning($query, $rows, $uniqueBy, $updateAssoc, $returning);
        if ($native !== null) {
            return $native;
        }

        // Portable fallback: run UPSERT, then fetch rows back by unique keys.
        $this->upsert($query, $rows, $uniqueBy, $update);

        $table = $this->tableFromQuery($query);
        if ($table === null || $uniqueBy === []) {
            return [];
        }

        return $this->fetchRowsByUniqueKeys($table, $rows, $uniqueBy, $returning);
    }

    /**
     * Append one query-log entry (supports bounded ring-buffer mode).
     *
     * @param  array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error?:string
     * }  $entry
     */
    private function appendQueryLogEntry(array $entry): void
    {
        $max = $this->maxLogEntries;

        if ($max === null) {
            $this->queryLog[] = $entry;
            $this->queryLogCount++;

            return;
        }

        if ($max <= 0) {
            return;
        }

        if ($this->queryLogCount < $max) {
            $index = ($this->queryLogStart + $this->queryLogCount) % $max;
            $this->queryLog[$index] = $entry;
            $this->queryLogCount++;

            return;
        }

        $this->queryLog[$this->queryLogStart] = $entry;
        $this->queryLogStart = ($this->queryLogStart + 1) % $max;
    }

    /**
     * Determine whether a where clause shape is supported by AbstractSqlCompiler.
     *
     * @param  array<string,mixed>  $where
     */
    private function canCompileWhereClause(array $where): bool
    {
        $type = (string) ($where['type'] ?? 'basic');

        return \in_array($type, ['basic', 'in', 'between', 'null', 'raw'], true);
    }

    /**
     * Decide whether the driver compiler path can safely compile this query.
     */
    private function canUseDriverCompiler(QueryBuilder $query): bool
    {
        $components = $query->getComponents();

        // Keep grammar as the canonical path for currently unsupported components.
        if (
            ($components['ctes'] ?? []) !== []
            || $components['distinct']
            || $components['unions'] !== []
            || $components['lock'] !== null
        ) {
            return false;
        }

        foreach ($components['joins'] as $join) {
            if ($join instanceof JoinClause) {
                return false;
            }
        }

        foreach ($components['wheres'] as $where) {
            if (! $this->canCompileWhereClause($where)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dispatch an event if event dispatching is enabled.
     */
    private function dispatchEvent(string $event, object $payload): void
    {
        if (! $this->dispatchEvents) {
            return;
        }

        Events::dispatch($event, [$payload]);
    }

    /**
     * Fetch rows back using the unique key filters used by UPSERT fallback.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $returning
     * @return list<array<string,mixed>>
     */
    private function fetchRowsByUniqueKeys(
        string $table,
        array $rows,
        array $uniqueBy,
        array $returning,
    ): array {
        $fetch = new QueryBuilder($this->connection, $this->grammar, $this);
        $fetch->from($table)->select($returning);

        $hasAnyFilter = false;

        foreach ($rows as $row) {
            $subset = $this->uniqueSubsetFromRow($row, $uniqueBy);

            if ($subset === []) {
                continue;
            }

            $hasAnyFilter = true;
            $fetch->orWhere(static function (QueryBuilder $nested) use ($subset): void {
                foreach ($subset as $column => $value) {
                    $nested->where($column, '=', $value);
                }
            });
        }

        if (! $hasAnyFilter) {
            return [];
        }

        return $fetch->get();
    }

    /**
     * Get bindings for INSERT-like queries.
     *
     * @param  array<int,array<string,mixed>>  $values
     * @return list<mixed>
     */
    private function getInsertBindings(array $values): array
    {
        $bindings = [];

        foreach ($values as $row) {
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        return $bindings;
    }

    /**
     * Log a query.
     *
     * @param  list<mixed>  $bindings
     * @param  float  $time  Elapsed time in seconds
     * @param  string|null  $error  Error message if any
     */
    private function logQuery(string $sql, array $bindings, float $time, ?string $error = null): void
    {
        if (! $this->logging) {
            return;
        }

        $entry = [
            'sql'       => $sql,
            'bindings'  => \array_values($bindings),
            'time'      => \round($time * 1000, 2), // ms
            'timestamp' => \microtime(true),
        ];

        if ($error !== null) {
            $entry['error'] = $error;
        }

        $this->appendQueryLogEntry($entry);
    }

    /**
     * Normalize INSERT values (executor-side) to a list-of-rows.
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $values
     * @return array<int,array<string,mixed>>
     */
    private function normalizeInsertValues(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (! \is_array(\reset($values))) {
            /** @var array<string,mixed> $row */
            $row    = $values;
            $values = [$row];
        }

        /** @var array<int,array<string,mixed>> $values */
        return $values;
    }

    /**
     * Return query log ordered from oldest to newest.
     *
     * @return list<array{
     *   sql:string,
     *   bindings:list<mixed>,
     *   time:float,
     *   timestamp:float,
     *   error?:string
     * }>
     */
    private function orderedQueryLog(): array
    {
        if ($this->queryLogCount === 0) {
            return [];
        }

        if ($this->maxLogEntries === null) {
            return $this->queryLog;
        }

        $ordered = [];
        $max     = $this->maxLogEntries;

        for ($i = 0; $i < $this->queryLogCount; $i++) {
            $index = ($this->queryLogStart + $i) % $max;
            $ordered[] = $this->queryLog[$index];
        }

        return $ordered;
    }

    /**
     * Rebuild internal query-log storage after max-size changes.
     */
    private function reconfigureQueryLogStorage(): void
    {
        $ordered = $this->orderedQueryLog();
        $max     = $this->maxLogEntries;

        if ($max !== null && \count($ordered) > $max) {
            $ordered = \array_slice($ordered, -$max);
        }

        $this->queryLog = \array_values($ordered);
        $this->queryLogCount = \count($this->queryLog);
        $this->queryLogStart = 0;
    }

    /**
     * @param  array<string,mixed>  $firstRow
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     * @return array<string,mixed>
     */
    private function resolveUpsertUpdateAssoc(array $firstRow, array $uniqueBy, ?array $update): array
    {
        if ($update === null) {
            $allColumns = \array_keys($firstRow);
            $updateCols = \array_values(\array_diff($allColumns, $uniqueBy));
        } else {
            $updateCols = $update;
        }

        $updateAssoc = [];
        foreach ($updateCols as $col) {
            $updateAssoc[$col] = null;
        }

        return $updateAssoc;
    }

    /**
     * Try native UPSERT ... RETURNING when supported by grammar.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  array<string,mixed>  $updateAssoc
     * @param  list<string>  $returning
     * @return list<array<string,mixed>>|null
     */
    private function runNativeUpsertReturning(
        QueryBuilder $query,
        array $rows,
        array $uniqueBy,
        array $updateAssoc,
        array $returning,
    ): ?array {
        if (! \method_exists($this->grammar, 'compileUpsertReturning')) {
            return null;
        }

        $sql = $this->grammar->compileUpsertReturning($query, $rows, $uniqueBy, $updateAssoc, $returning);
        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        return $this->raw($sql, $bindings);
    }

    /**
     * Resolve table name from query components for fallback fetch.
     */
    private function tableFromQuery(QueryBuilder $query): ?string
    {
        $components = $query->getComponents();
        $table = $components['from'] ?? null;

        if (! \is_string($table) || $table === '') {
            return null;
        }

        return $table;
    }

    /**
     * Build unique-key subset for one row (or return empty when incomplete).
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $uniqueBy
     * @return array<string,mixed>
     */
    private function uniqueSubsetFromRow(array $row, array $uniqueBy): array
    {
        $subset = [];

        foreach ($uniqueBy as $key) {
            if (! \array_key_exists($key, $row)) {
                return [];
            }

            $subset[$key] = $row[$key];
        }

        return $subset;
    }

    /**
     * Basic validation for positional parameter binding counts.
     *
     * Only checks "?" placeholders (named parameters are left to PDO).
     *
     * @param  list<mixed>  $bindings
     */
    private function validateBindingCount(string $sql, array $bindings): void
    {
        if (! $this->validateBindings || $bindings === []) {
            return;
        }

        $expected = \substr_count($sql, '?');

        if ($expected === 0) {
            // Likely named parameters or no placeholders; skip.
            return;
        }

        $given = \count($bindings);

        if ($expected !== $given) {
            throw QueryException::bindingCountMismatch($sql, $expected, $given);
        }
    }
}
