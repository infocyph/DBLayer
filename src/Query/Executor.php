<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\Concerns\ExecutorInternals;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\DriverResult;

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

    private const int DEFAULT_MAX_QUERY_LOG_ENTRIES = 2_000;

    /**
     * Whether to dispatch query events.
     */
    private bool $dispatchEvents = true;

    /**
     * Enable query logging.
     */
    private bool $logging = false;

    /**
     * Maximum number of query log entries to keep.
     */
    private int $maxLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;

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
     * @var array<int,array{
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
     * Compile one SELECT exactly once for cache identity and execution.
     */
    public function compileSelect(QueryBuilder $query): CompiledQuery
    {
        return $this->connection->getCompiler()->compile($query->toPayload());
    }

    /**
     * Execute a DELETE query.
     */
    public function delete(QueryBuilder $query): int
    {
        $compiled = $this->connection->getCompiler()->compile($query->toDeletePayload());

        return $this->runCompiledObserved($compiled)->rowCount;
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

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = (fn(): bool => array_all(array_chunk($rows, $batchSize), fn($batch) => $this->insert($query, $batch)));

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        $compiled = $this->connection->getCompiler()->compile($query->toInsertPayload($rows));

        return $this->runCompiledObserved($compiled)->rowCount > 0;
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

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = (fn(): bool => array_all(array_chunk($rows, $batchSize), fn($batch) => $this->insertIgnore($query, $batch)));

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        if (!$this->connection->capabilities()->supportsInsertIgnore) {
            throw QueryException::unsupportedCapability('insertIgnore', $this->connection->getDriverName());
        }

        $compiled = $this->connection->getCompiler()->compile(
            $query->toInsertPayload($rows, mode: 'ignore'),
        );

        return $this->runCompiledObserved($compiled)->rowCount > 0;
    }

    /**
     * Execute an INSERT with RETURNING semantics when supported.
     *
     * Uses INSERT ... RETURNING when declared by the driver. Otherwise one
     * INSERT is followed only by normalized generated-id retrieval.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @return DriverResult Returned rows and mutation outcome.
     */
    public function insertReturningResult(
        QueryBuilder $query,
        array $values,
        ?string $column = null,
    ): DriverResult {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return new DriverResult([], 0);
        }

        $column ??= 'id';

        if ($this->connection->capabilities()->supportsReturning) {
            $compiled = $this->connection->getCompiler()->compile(
                $query->toInsertPayload($rows, returning: [$column]),
            );

            return $this->runCompiledObserved($compiled);
        }

        if (count($rows) !== 1) {
            throw QueryException::invalidParameter(
                'insertReturning',
                'Bulk INSERT RETURNING requires native driver support.',
            );
        }

        $compiled = $this->connection->getCompiler()->compile($query->toInsertPayload($rows));

        return $this->runCompiledObserved($compiled);
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
            $results = $this->connection->withoutQueryEvents(
                fn(): array => $this->connection->select($sql, $bindings),
            );
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
                    'db.query.failed',
                    new QueryFailed($sql, $bindings, $elapsed * 1000, $this->connection, $e, 1),
                );
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query.
     *
     * Compile through the connection's canonical driver compiler.
     *
     * @return list<array<string,mixed>>
     */
    public function select(QueryBuilder $query): array
    {
        return $this->selectCompiled($this->compileSelect($query));
    }

    /** @return list<array<string,mixed>> */
    public function selectCompiled(CompiledQuery $compiled): array
    {
        $result = $this->runCompiledObserved($compiled);

        return $this->normalizeRows($result->rows ?? []);
    }

    /**
     * Set maximum number of query log entries to keep.
     *
     * Pass null to restore the safe default. Non-positive values retain one entry.
     */
    public function setMaxQueryLogEntries(?int $max): void
    {
        $this->maxLogEntries = max(1, $max ?? self::DEFAULT_MAX_QUERY_LOG_ENTRIES);
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
        $compiled = $this->connection->getCompiler()->compile($query->toTruncatePayload());
        $this->runCompiledObserved($compiled);

        return true;
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

        $compiled = $this->connection->getCompiler()->compile($query->toUpdatePayload($values));

        return $this->runCompiledObserved($compiled)->rowCount;
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

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = (fn(): bool => array_all(array_chunk($rows, $batchSize), fn($batch) => $this->upsert($query, $batch, $uniqueBy, $update)));

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        $updateAssoc = $this->resolveUpsertUpdateAssoc($rows[0], $uniqueBy, $update);

        if (!$this->connection->capabilities()->supportsUpsert) {
            throw QueryException::unsupportedCapability('upsert', $this->connection->getDriverName());
        }

        $compiled = $this->connection->getCompiler()->compile(
            $query->toInsertPayload(
                $rows,
                mode: 'upsert',
                uniqueBy: $uniqueBy,
                upsertUpdate: array_keys($updateAssoc),
            ),
        );
        $this->runCompiledObserved($compiled);

        return true;
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

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = function () use ($query, $rows, $uniqueBy, $update, $returning, $batchSize): array {
                $returned = [];

                foreach (array_chunk($rows, $batchSize) as $batch) {
                    array_push(
                        $returned,
                        ...$this->upsertReturning($query, $batch, $uniqueBy, $update, $returning),
                    );
                }

                return $returned;
            };

            if ($this->connection->inTransaction()) {
                return $operation();
            }

            return $this->normalizeReturnedRows(
                $this->connection->transaction($operation),
            );
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
