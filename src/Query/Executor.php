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
 *
 * @package Infocyph\DBLayer\Query
 * @author Hasan
 */
class Executor
{
    /**
     * Database connection
     */
    private Connection $connection;

    /**
     * SQL grammar compiler
     */
    private Grammar $grammar;

    /**
     * Enable query logging
     */
    private bool $logging = false;

    /**
     * Query execution log
     *
     * Each entry:
     *  - sql (string)
     *  - bindings (array)
     *  - time (float, ms)
     *  - timestamp (float, seconds)
     *  - error (string|null)
     */
    private array $queryLog = [];

    /**
     * Create a new executor instance
     */
    public function __construct(Connection $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    /**
     * Clear the query log
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Execute a DELETE query
     */
    public function delete(QueryBuilder $query): int
    {
        $sql = $this->grammar->compileDelete($query);
        $bindings = $query->getBindings();

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
     * Disable query logging
     */
    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    /**
     * Enable query logging
     */
    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /**
     * Get failed queries
     */
    public function getFailedQueries(): array
    {
        return array_filter($this->queryLog, static fn (array $log): bool => isset($log['error']));
    }

    /**
     * Get the query log
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Get query log statistics
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
          'total_time' => round($totalTime, 4),          // ms
          'avg_time' => round($totalTime / $count, 4),   // ms
          'min_time' => round(min($times), 4),           // ms
          'max_time' => round(max($times), 4),           // ms
          'failed_queries' => count($failed),
        ];
    }

    /**
     * Get the slowest queries
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        $queries = $this->queryLog;

        usort($queries, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return array_slice($queries, 0, $limit);
    }

    /**
     * Execute an INSERT query
     */
    public function insert(QueryBuilder $query, array $values): bool
    {
        $sql = $this->grammar->compileInsert($query, $values);
        $bindings = $this->getInsertBindings($values);

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
          new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $result = $this->connection->insert($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            $rows = count($values);

            Events::dispatch('db.query.executed', [
              new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection, $rows),
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
     * Execute a raw query
     */
    public function raw(string $sql, array $bindings = []): array
    {
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
     * Execute a SELECT query
     */
    public function select(QueryBuilder $query): array
    {
        $sql = $this->grammar->compileSelect($query);
        $bindings = $query->getBindings();

        $startTime = microtime(true);

        Events::dispatch('db.query.executing', [
          new QueryExecuting($sql, $bindings, $this->connection),
        ]);

        try {
            $results = $this->connection->select($sql, $bindings);
            $elapsed = microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);

            // For SELECT, rowsAffected is not strictly meaningful; keep null.
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
     * Execute a raw statement (INSERT, UPDATE, DELETE, etc.)
     */
    public function statement(string $sql, array $bindings = []): bool
    {
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
     * Execute a TRUNCATE query
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
     * Execute an UPDATE query
     */
    public function update(QueryBuilder $query, array $values): int
    {
        $sql = $this->grammar->compileUpdate($query, $values);
        $bindings = array_merge(array_values($values), $query->getBindings());

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
     * Get bindings for INSERT query
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
     * Log a query
     *
     * @param float       $time  Elapsed time in seconds
     * @param string|null $error Error message if any
     */
    private function logQuery(string $sql, array $bindings, float $time, ?string $error = null): void
    {
        if (!$this->logging) {
            return;
        }

        $entry = [
          'sql' => $sql,
          'bindings' => $bindings,
          'time' => round($time * 1000, 2), // milliseconds
          'timestamp' => microtime(true),
        ];

        if ($error !== null) {
            $entry['error'] = $error;
        }

        $this->queryLog[] = $entry;
    }
}
