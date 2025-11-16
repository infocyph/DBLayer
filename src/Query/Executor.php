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
class Executor
{
    /**
     * Database connection.
     */
    private Connection $connection;

    /**
     * SQL grammar compiler.
     */
    private Grammar $grammar;

    /**
     * Enable query logging.
     */
    private bool $logging = false;

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
     * Create a new executor instance.
     */
    public function __construct(Connection $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    /**
     * Clear the query log.
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Execute a DELETE query.
     */
    public function delete(QueryBuilder $query): int
    {
        $sql = $this->grammar->compileDelete($query);
        $bindings = $query->getBindings();

        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $affected = $this->connection->delete($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $affected),
            ]);

            return $affected;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
            ]);

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Disable query logging.
     */
    public function disableQueryLog(): void
    {
        $this->logging = false;
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
        /** @var list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error:string}> $failed */
        $failed = array_values(array_filter(
            $this->queryLog,
            static fn (array $log): bool => isset($log['error'])
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
        return $this->queryLog;
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
        if ($this->queryLog === []) {
            return [
                'total_queries' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'min_time' => 0.0,
                'max_time' => 0.0,
                'failed_queries' => 0,
            ];
        }

        $times = array_column($this->queryLog, 'time');
        $failed = $this->getFailedQueries();

        $totalTime = array_sum($times);
        $count = count($times);

        return [
            'total_queries' => count($this->queryLog),
            'total_time' => round($totalTime, 4),           // ms
            'avg_time' => round($totalTime / $count, 4),  // ms
            'min_time' => round(min($times), 4),          // ms
            'max_time' => round(max($times), 4),          // ms
            'failed_queries' => count($failed),
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
        $queries = $this->queryLog;

        usort(
            $queries,
            static fn (array $a, array $b): int => $b['time'] <=> $a['time']
        );

        return array_slice($queries, 0, $limit);
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

        $sql = $this->grammar->compileInsert($query, $rows);
        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $result = $this->connection->insert($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            $rowsCount = count($rows);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
            ]);

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
        if (method_exists($this->grammar, 'compileInsertIgnore')) {
            /** @phpstan-ignore-next-line */
            $sql = $this->grammar->compileInsertIgnore($query, $rows);
        } elseif (method_exists($this->grammar, 'compileInsertOrIgnore')) {
            /** @phpstan-ignore-next-line */
            $sql = $this->grammar->compileInsertOrIgnore($query, $rows);
        } else {
            // Graceful fallback to regular insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $result = $this->connection->insert($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            $rowsCount = count($rows);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
            ]);

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
        ?string $column = null
    ): ?array {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return null;
        }

        $column ??= 'id';

        if (method_exists($this->grammar, 'compileInsertGetId')) {
            /** @phpstan-ignore-next-line */
            $sql = $this->grammar->compileInsertGetId($query, $rows, $column);
            $bindings = $this->getInsertBindings($rows);

            $this->validateBindingCount($sql, $bindings);

            $startTime = microtime(true);

            Events::dispatch('db.query.executing', [
                new QueryExecuting($sql, $bindings, $this->connection),
            ]);

            try {
                $resultRows = $this->connection->select($sql, $bindings);
                $elapsed = microtime(true) - $startTime;

                $this->logQuery($sql, $bindings, $elapsed);

                Events::dispatch('db.query.executed', [
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, count($resultRows)),
                ]);

                return $resultRows[0] ?? null;
            } catch (\Throwable $e) {
                $elapsed = microtime(true) - $startTime;

                $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

                Events::dispatch('db.query.executed', [
                    new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
                ]);

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

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $results = $this->connection->select($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, null),
            ]);

            return $results;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, null),
            ]);

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query.
     *
     * @return list<array<string,mixed>>
     */
    public function select(QueryBuilder $query): array
    {
        $sql = $this->grammar->compileSelect($query);
        $bindings = $query->getBindings();

        $this->raw($sql, $bindings);
    }

    /**
     * Execute a raw statement (INSERT, UPDATE, DELETE, DDL, etc.)
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $this->connection->execute($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, null),
            ]);

            return true;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, null),
            ]);

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a TRUNCATE query.
     */
    public function truncate(QueryBuilder $query): bool
    {
        $sql = $this->grammar->compileTruncate($query);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, [], $this->connection),
        ]);

        try {
            $this->connection->execute($sql);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, [], $elapsed);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, [], $elapsed * 1000, $this->connection, null),
            ]);

            return true;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, [], $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, [], $elapsed * 1000, $this->connection, null),
            ]);

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
        $sql = $this->grammar->compileUpdate($query, $values);
        $bindings = array_merge(array_values($values), $query->getBindings());

        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $affected = $this->connection->update($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $affected),
            ]);

            return $affected;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
            ]);

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
        ?array $update = null
    ): bool {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        /** @var array<string,mixed> $firstRow */
        $firstRow = $rows[0];

        if ($update === null) {
            $allColumns = array_keys($firstRow);
            $updateCols = array_values(array_diff($allColumns, $uniqueBy));
        } else {
            $updateCols = $update;
        }

        // Convert list-of-cols to assoc; grammars only care about the keys.
        $updateAssoc = [];
        foreach ($updateCols as $col) {
            $updateAssoc[$col] = null;
        }

        if (method_exists($this->grammar, 'compileUpsert')) {
            /** @phpstan-ignore-next-line */
            $sql = $this->grammar->compileUpsert($query, $rows, $uniqueBy, $updateAssoc);
        } elseif (method_exists($this->grammar, 'compileInsertOnDuplicateKeyUpdate')) {
            /** @phpstan-ignore-next-line */
            $sql = $this->grammar->compileInsertOnDuplicateKeyUpdate($query, $rows, $updateAssoc);
        } else {
            // Graceful fallback: behave like insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        $this->validateBindingCount($sql, $bindings);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
            new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $result = $this->connection->insert($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            $rowsCount = count($rows);

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rowsCount),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            Events::dispatch('db.query.executed', [
                new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, 0),
            ]);

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
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
            'sql' => $sql,
            'bindings' => array_values($bindings),
            'time' => round($time * 1000, 2), // milliseconds
            'timestamp' => microtime(true),
        ];

        if ($error !== null) {
            $entry['error'] = $error;
        }

        $this->queryLog[] = $entry;
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

        if (! is_array(reset($values))) {
            /** @var array<string,mixed> $row */
            $row = $values;
            $values = [$row];
        }

        /** @var array<int,array<string,mixed>> $values */
        return $values;
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
        if ($bindings === []) {
            return;
        }

        $expected = substr_count($sql, '?');

        if ($expected === 0) {
            // Likely named parameters or no placeholders; skip.
            return;
        }

        $given = count($bindings);

        if ($expected !== $given) {
            throw QueryException::bindingCountMismatch($sql, $expected, $given);
        }
    }
}
