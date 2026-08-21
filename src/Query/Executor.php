<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
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

    private bool $dispatchEvents = true;

    private bool $logging = false;

    private int $maxLogEntries = self::DEFAULT_MAX_QUERY_LOG_ENTRIES;

    /**
     * @var array<int,array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error?:string}>
     */
    private array $queryLog = [];

    private int $queryLogCount = 0;

    private int $queryLogStart = 0;

    private bool $validateBindings = true;

    public function __construct(private readonly Connection $connection) {}

    public function clearQueryLog(): void
    {
        $this->queryLog = [];
        $this->queryLogCount = 0;
        $this->queryLogStart = 0;
    }

    public function compileSelect(QueryBuilder $query): CompiledQuery
    {
        return $this->connection->getCompiler()->compile($query->toPayload());
    }

    public function delete(QueryBuilder $query): int
    {
        $compiled = $this->connection->getCompiler()->compile($query->toDeletePayload());

        return $this->runCompiledObserved($compiled)->rowCount;
    }

    public function disableBindingValidation(): void
    {
        $this->validateBindings = false;
    }

    public function disableEvents(): void
    {
        $this->dispatchEvents = false;
    }

    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    public function enableBindingValidation(): void
    {
        $this->validateBindings = true;
    }

    public function enableEvents(): void
    {
        $this->dispatchEvents = true;
    }

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /** @return list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error:string}> */
    public function getFailedQueries(): array
    {
        /** @var list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error:string}> $failed */
        $failed = \array_filter($this->getQueryLog(), static fn(array $log): bool => isset($log['error']));

        return $failed;
    }

    /** @return list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error?:string}> */
    public function getQueryLog(): array
    {
        return $this->orderedQueryLog();
    }

    /** @return array{total_queries:int,total_time:float,avg_time:float,min_time:float,max_time:float,failed_queries:int} */
    public function getQueryStats(): array
    {
        if ($this->queryLogCount === 0) {
            return ['total_queries' => 0, 'total_time' => 0.0, 'avg_time' => 0.0, 'min_time' => 0.0, 'max_time' => 0.0, 'failed_queries' => 0];
        }

        $logs = $this->getQueryLog();
        $times = \array_column($logs, 'time');
        $failed = $this->getFailedQueries();

        if ($times === []) {
            return ['total_queries' => $this->queryLogCount, 'total_time' => 0.0, 'avg_time' => 0.0, 'min_time' => 0.0, 'max_time' => 0.0, 'failed_queries' => \count($failed)];
        }

        $totalTime = \array_sum($times);
        $count = \count($times);

        return [
            'total_queries' => $this->queryLogCount,
            'total_time' => \round($totalTime, 4),
            'avg_time' => \round($totalTime / $count, 4),
            'min_time' => \round(\min($times), 4),
            'max_time' => \round(\max($times), 4),
            'failed_queries' => \count($failed),
        ];
    }

    /** @return list<array{sql:string,bindings:list<mixed>,time:float,timestamp:float,error?:string}> */
    public function getSlowestQueries(int $limit = 10): array
    {
        $queries = $this->getQueryLog();
        \usort($queries, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);

        return \array_slice($queries, 0, $limit);
    }

    /** @param array<int,array<string,mixed>>|array<string,mixed> $values */
    public function insert(QueryBuilder $query, array $values): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = function () use ($query, $rows, $batchSize): bool {
                $successful = true;

                foreach (array_chunk($rows, $batchSize) as $batch) {
                    $batchSucceeded = $this->insert($query, $batch);
                    $successful = $successful && $batchSucceeded;
                }

                return $successful;
            };

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        $compiled = $this->connection->getCompiler()->compile($query->toInsertPayload($rows));

        return $this->runCompiledObserved($compiled)->rowCount > 0;
    }

    /**
     * Unsupported drivers fail explicitly; no plain INSERT fallback is used.
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
            $operation = function () use ($query, $rows, $batchSize): bool {
                $inserted = false;

                foreach (array_chunk($rows, $batchSize) as $batch) {
                    $inserted = $this->insertIgnore($query, $batch) || $inserted;
                }

                return $inserted;
            };

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        if (!$this->connection->capabilities()->supportsInsertIgnore) {
            throw QueryException::unsupportedCapability('insertIgnore', $this->connection->getDriverName());
        }

        $compiled = $this->connection->getCompiler()->compile($query->toInsertPayload($rows, mode: 'ignore'));

        return $this->runCompiledObserved($compiled)->rowCount > 0;
    }

    /** @param array<int,array<string,mixed>>|array<string,mixed> $values */
    public function insertReturningResult(QueryBuilder $query, array $values, ?string $column = null): DriverResult
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return new DriverResult([], 0);
        }

        $column ??= 'id';

        if ($this->connection->capabilities()->supportsReturning) {
            $compiled = $this->connection->getCompiler()->compile($query->toInsertPayload($rows, returning: [$column]));

            return $this->runCompiledObserved($compiled);
        }

        if (count($rows) !== 1) {
            throw QueryException::invalidParameter('insertReturning', 'Bulk INSERT RETURNING requires native driver support.');
        }

        $result = $this->runCompiledObserved(
            $this->connection->getCompiler()->compile($query->toInsertPayload($rows)),
        );
        $lastInsertId = $this->connection->lastInsertId();

        return new DriverResult(
            $result->rows,
            $result->rowCount,
            $lastInsertId !== '' ? $lastInsertId : null,
        );
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $bindings = $this->normalizeBindings($bindings);
        $this->validateBindingCount($sql, $bindings);
        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent('db.query.executing', new QueryExecuting($sql, $bindings, $this->connection));
        }

        try {
            $results = $this->connection->withoutQueryEvents(fn(): array => $this->connection->select($sql, $bindings));
            $elapsed = \microtime(true) - $startTime;
            $this->logQuery($sql, $bindings, $elapsed);

            if ($this->dispatchEvents) {
                $this->dispatchEvent('db.query.executed', new QueryExecuted($sql, $bindings, $elapsed * 1000, $this->connection));
            }

            return $this->normalizeRows($results);
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;
            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());

            if ($this->dispatchEvents) {
                $this->dispatchEvent('db.query.failed', new QueryFailed($sql, $bindings, $elapsed * 1000, $this->connection, $e, 1));
            }

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public function select(QueryBuilder $query): array
    {
        return $this->selectCompiled($this->compileSelect($query));
    }

    /** @return list<array<string,mixed>> */
    public function selectCompiled(CompiledQuery $compiled): array
    {
        return $this->normalizeRows($this->runCompiledObserved($compiled)->rows ?? []);
    }

    public function setMaxQueryLogEntries(?int $max): void
    {
        $this->maxLogEntries = max(1, $max ?? self::DEFAULT_MAX_QUERY_LOG_ENTRIES);
        $this->reconfigureQueryLogStorage();
    }

    /** @param array<int|string,mixed> $bindings */
    public function statement(string $sql, array $bindings = []): bool
    {
        $bindings = $this->normalizeBindings($bindings);

        return $this->executeStatementLike($sql, $bindings, function () use ($sql, $bindings): void {
            $this->connection->execute($sql, $bindings);
        });
    }

    public function truncate(QueryBuilder $query): bool
    {
        $compiled = $this->connection->getCompiler()->compile($query->toTruncatePayload());
        $this->runCompiledObserved($compiled);

        return true;
    }

    /** @param array<string,mixed> $values */
    public function update(QueryBuilder $query, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->runCompiledObserved($this->connection->getCompiler()->compile($query->toUpdatePayload($values)))->rowCount;
    }

    /**
     * Unsupported drivers fail explicitly; no plain INSERT fallback is used.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     */
    public function upsert(QueryBuilder $query, array $values, array $uniqueBy, ?array $update = null): bool
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return true;
        }

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = function () use ($query, $rows, $uniqueBy, $update, $batchSize): bool {
                foreach (array_chunk($rows, $batchSize) as $batch) {
                    $this->upsert($query, $batch, $uniqueBy, $update);
                }

                return true;
            };

            return $this->connection->inTransaction()
                ? $operation()
                : (bool) $this->connection->transaction($operation);
        }

        $updateAssoc = $this->resolveUpsertUpdateAssoc($rows[0], $uniqueBy, $update);

        if (!$this->connection->capabilities()->supportsUpsert) {
            throw QueryException::unsupportedCapability('upsert', $this->connection->getDriverName());
        }

        $compiled = $this->connection->getCompiler()->compile(
            $query->toInsertPayload($rows, mode: 'upsert', uniqueBy: $uniqueBy, upsertUpdate: array_keys($updateAssoc)),
        );
        $this->runCompiledObserved($compiled);

        return true;
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
     * @param list<string> $returning
     * @return list<array<string,mixed>>
     */
    public function upsertReturning(QueryBuilder $query, array $values, array $uniqueBy, ?array $update = null, array $returning = ['*']): array
    {
        $rows = $this->normalizeInsertValues($values);

        if ($rows === []) {
            return [];
        }

        $batchSize = $this->maxRowsPerBatch($rows[0]);

        if (count($rows) > $batchSize) {
            $operation = function () use ($query, $rows, $uniqueBy, $update, $returning, $batchSize): array {
                $returned = [];

                foreach (array_chunk($rows, $batchSize) as $batch) {
                    array_push($returned, ...$this->upsertReturning($query, $batch, $uniqueBy, $update, $returning));
                }

                return $returned;
            };

            if ($this->connection->inTransaction()) {
                return $operation();
            }

            return $this->normalizeReturnedRows($this->connection->transaction($operation));
        }

        $updateAssoc = $this->resolveUpsertUpdateAssoc($rows[0], $uniqueBy, $update);
        $normalizedRows = $this->normalizeRows($rows);
        $native = $this->runNativeUpsertReturning($query, $normalizedRows, $uniqueBy, $updateAssoc, $returning);

        if ($native !== null) {
            return $native;
        }

        $this->upsert($query, $rows, $uniqueBy, $update);
        $table = $this->tableFromQuery($query);

        if ($table === null || $uniqueBy === []) {
            return [];
        }

        return $this->fetchRowsByUniqueKeys($table, $normalizedRows, $uniqueBy, $returning);
    }
}
