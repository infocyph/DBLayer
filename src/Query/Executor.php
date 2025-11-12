<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
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

        try {
            $affected = $this->connection->delete($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return $affected;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
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
        return array_filter($this->queryLog, fn ($log) => isset($log['error']));
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
        if (empty($this->queryLog)) {
            return [
                'total_queries' => 0,
                'total_time' => 0,
                'avg_time' => 0,
                'min_time' => 0,
                'max_time' => 0,
                'failed_queries' => 0,
            ];
        }

        $times = array_column($this->queryLog, 'time');
        $failed = array_filter($this->queryLog, fn ($log) => isset($log['error']));

        return [
            'total_queries' => count($this->queryLog),
            'total_time' => round(array_sum($times), 4),
            'avg_time' => round(array_sum($times) / count($times), 4),
            'min_time' => round(min($times), 4),
            'max_time' => round(max($times), 4),
            'failed_queries' => count($failed),
        ];
    }

    /**
     * Get the slowest queries
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        $queries = $this->queryLog;

        usort($queries, fn ($a, $b) => $b['time'] <=> $a['time']);

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

        try {
            $result = $this->connection->insert($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return $result;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a raw query
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $startTime = microtime(true);

        try {
            $results = $this->connection->select($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return $results;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
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

        try {
            $results = $this->connection->select($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return $results;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a raw statement (INSERT, UPDATE, DELETE)
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $startTime = microtime(true);

        try {
            $this->connection->execute($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return true;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
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

        try {
            $this->connection->execute($sql);

            $this->logQuery($sql, [], microtime(true) - $startTime);

            return true;
        } catch (\Throwable $e) {
            $this->logQuery($sql, [], microtime(true) - $startTime, $e->getMessage());
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

        try {
            $affected = $this->connection->update($sql, $bindings);

            $this->logQuery($sql, $bindings, microtime(true) - $startTime);

            return $affected;
        } catch (\Throwable $e) {
            $this->logQuery($sql, $bindings, microtime(true) - $startTime, $e->getMessage());
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
     */
    private function logQuery(string $sql, array $bindings, float $time, ?string $error = null): void
    {
        if (!$this->logging) {
            return;
        }

        $log = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => round($time * 1000, 2), // Convert to milliseconds
            'timestamp' => microtime(true),
        ];

        if ($error !== null) {
            $log['error'] = $error;
        }

        $this->queryLog[] = $log;
    }
}