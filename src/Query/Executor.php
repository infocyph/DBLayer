<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\Concerns\ExecutorInternals;

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
    use ExecutorInternals;

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
    public function __construct(
        /**
         * Database connection.
         */
        private readonly Connection $connection,
        /**
         * SQL grammar compiler (legacy path).
         */
        private readonly Grammar $grammar,
    ) {}

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
        $sql = $this->normalizeSql($this->grammar->compileDelete($query));
        $bindings = $this->normalizeBindings($query->getBindings());

        return $this->executeAffecting(
            $sql,
            $bindings,
            fn(): int => $this->connection->delete($sql, $bindings),
        );
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
        $failed = \array_filter(
            $logs,
            static fn(array $log): bool => isset($log['error']),
        );

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
                'total_queries' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'min_time' => 0.0,
                'max_time' => 0.0,
                'failed_queries' => 0,
            ];
        }

        $logs = $this->getQueryLog();
        $times = \array_column($logs, 'time');
        $failed = $this->getFailedQueries();

        if ($times === []) {
            return [
                'total_queries' => $this->queryLogCount,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'min_time' => 0.0,
                'max_time' => 0.0,
                'failed_queries' => \count($failed),
            ];
        }

        $totalTime = \array_sum($times);
        $count = \count($times);

        return [
            'total_queries' => $this->queryLogCount,
            'total_time' => \round($totalTime, 4),          // ms
            'avg_time' => \round($totalTime / $count, 4), // ms
            'min_time' => \round(\min($times), 4),
            'max_time' => \round(\max($times), 4),
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
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    public function insert(QueryBuilder $query, array $values): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        $sql = $this->normalizeSql($this->grammar->compileInsert($query, $rows));
        $bindings = $this->getInsertBindings($rows);

        return $this->executeInsertLike($sql, $bindings, \count($rows));
    }

    /**
     * Execute an INSERT IGNORE / INSERT OR IGNORE when supported.
     *
     * Falls back to normal insert() when the driver has no native support.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    public function insertIgnore(QueryBuilder $query, array $values): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        // Prefer driver-specific ignore semantics when available.
        if (\method_exists($this->grammar, 'compileInsertIgnore')) {
            $sql = $this->normalizeSql($this->grammar->compileInsertIgnore($query, $rows));
        } elseif (\method_exists($this->grammar, 'compileInsertOrIgnore')) {
            $sql = $this->normalizeSql($this->grammar->compileInsertOrIgnore($query, $rows));
        } else {
            // Graceful fallback to regular insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        return $this->executeInsertLike($sql, $bindings, \count($rows));
    }

    /**
     * Execute an INSERT with RETURNING semantics when supported.
     *
     * On PostgreSQL, uses INSERT ... RETURNING.
     * On other drivers, falls back to insert() + lastInsertId().
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
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
            $sql = $this->normalizeSql($this->grammar->compileInsertGetId($query, $rows, $column));
            $bindings = $this->getInsertBindings($rows);

            $resultRows = $this->raw($sql, $bindings);

            return $resultRows[0] ?? null;
        }

        // Fallback path: normal insert plus lastInsertId().
        $this->insert($query, $rows);
        $id = $this->connection->lastInsertId($column);

        if ($id === '') {
            return null;
        }

        return [$column => $id];
    }

    /**
     * Execute a raw SELECT query.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $bindings = $this->normalizeBindings($bindings);
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

            return $this->normalizeRows($results);
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
            $this->canUseDriverCompiler($query)
        ) {
            $payload = $query->toPayload();
            $compiler = $this->connection->getCompiler();
            $compiled = $compiler->compile($payload);

            return $this->raw(
                $this->normalizeSql($compiled->sql),
                $this->normalizeBindings($compiled->bindings),
            );
        }

        // Legacy path: Grammar-based compilation.
        $sql = $this->normalizeSql($this->grammar->compileSelect($query));
        $bindings = $this->normalizeBindings($query->getBindings());

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
     *
     * @param array<int|string,mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $bindings = $this->normalizeBindings($bindings);

        return $this->executeStatementLike(
            $sql,
            $bindings,
            function () use ($sql, $bindings): void {
                $this->connection->execute($sql, $bindings);
            },
        );
    }

    /**
     * Execute a TRUNCATE query.
     */
    public function truncate(QueryBuilder $query): bool
    {
        $sql = $this->normalizeSql($this->grammar->compileTruncate($query));

        return $this->executeStatementLike(
            $sql,
            [],
            function () use ($sql): void {
                $this->connection->execute($sql);
            },
        );
    }

    /**
     * Execute an UPDATE query.
     *
     * @param array<string,mixed> $values
     */
    public function update(QueryBuilder $query, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $sql = $this->normalizeSql($this->grammar->compileUpdate($query, $values));
        $bindings = $this->normalizeBindings(\array_merge(\array_values($values), $query->getBindings()));

        return $this->executeAffecting(
            $sql,
            $bindings,
            fn(): int => $this->connection->update($sql, $bindings),
        );
    }

    /**
     * Execute an UPSERT (ON CONFLICT / ON DUPLICATE KEY UPDATE) when supported.
     *
     * Falls back to plain insert() when the driver has no native support.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
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

        $updateAssoc = $this->resolveUpsertUpdateAssoc($rows[0], $uniqueBy, $update);

        if (\method_exists($this->grammar, 'compileUpsert')) {
            $sql = $this->normalizeSql($this->grammar->compileUpsert($query, $rows, $uniqueBy, $updateAssoc));
        } elseif (\method_exists($this->grammar, 'compileInsertOnDuplicateKeyUpdate')) {
            $sql = $this->normalizeSql($this->grammar->compileInsertOnDuplicateKeyUpdate($query, $rows, $updateAssoc));
        } else {
            // Graceful fallback: behave like insert().
            return $this->insert($query, $rows);
        }

        $bindings = $this->getInsertBindings($rows);

        return $this->executeInsertLike($sql, $bindings, \count($rows));
    }

    /**
     * Execute an UPSERT and return affected rows.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     * @param list<string> $returning
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
        $normalizedRows = $this->normalizeRows($rows);

        $native = $this->runNativeUpsertReturning($query, $normalizedRows, $uniqueBy, $updateAssoc, $returning);
        if ($native !== null) {
            return $native;
        }

        // Portable fallback: run UPSERT, then fetch rows back by unique keys.
        $this->upsert($query, $rows, $uniqueBy, $update);

        $table = $this->tableFromQuery($query);
        if ($table === null || $uniqueBy === []) {
            return [];
        }

        return $this->fetchRowsByUniqueKeys($table, $normalizedRows, $uniqueBy, $returning);
    }
}
