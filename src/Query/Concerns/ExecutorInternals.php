<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\Core\CompiledQuery;
use Infocyph\DBLayer\Query\Core\DriverResult;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Support\RingBuffer;

trait ExecutorInternals
{
    /**
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

        RingBuffer::append(
            $this->queryLog,
            $this->queryLogStart,
            $this->queryLogCount,
            $max,
            $entry,
        );
    }

    /**
     * Dispatch an event if event dispatching is enabled.
     */
    private function dispatchEvent(string $event, object $payload): void
    {
        if (!$this->dispatchEvents) {
            return;
        }

        Events::dispatch($event, [$payload]);
    }

    /**
     * Emit query-executed event preserving optional row-count payload semantics.
     *
     * @param list<mixed> $bindings
     */
    private function emitExecutedEvent(string $sql, array $bindings, float $elapsed, ?int $rowCount): void
    {
        if (!$this->dispatchEvents) {
            return;
        }

        if ($rowCount === null) {
            $this->dispatchEvent(
                'db.query.executed',
                new \Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted(
                    $sql,
                    $bindings,
                    $elapsed * 1000,
                    $this->connection,
                ),
            );

            return;
        }

        $this->dispatchEvent(
            'db.query.executed',
            new \Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted(
                $sql,
                $bindings,
                $elapsed * 1000,
                $this->connection,
                $rowCount,
            ),
        );
    }

    /**
     * Emit query-failed event preserving elapsed duration and attempts.
     *
     * @param list<mixed> $bindings
     */
    private function emitFailedEvent(
        string $sql,
        array $bindings,
        float $elapsed,
        \Throwable $error,
        int $attempts = 1,
    ): void {
        if (!$this->dispatchEvents) {
            return;
        }

        $this->dispatchEvent(
            'db.query.failed',
            new \Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed(
                $sql,
                $bindings,
                $elapsed * 1000,
                $this->connection,
                $error,
                $attempts,
            ),
        );
    }

    /**
     * Execute an affecting query (DELETE/UPDATE) with consistent instrumentation.
     *
     * @param list<mixed> $bindings
     * @param callable():int $operation
     */
    private function executeAffecting(string $sql, array $bindings, callable $operation): int
    {
        return $this->executeObserved(
            $sql,
            $bindings,
            $operation,
            static fn(int $affected): int => $affected,
        );
    }

    /**
     * Execute an INSERT-like statement with consistent logging/event behavior.
     *
     * @param list<mixed> $bindings
     */
    private function executeInsertLike(string $sql, array $bindings, int $eventRowCount): bool
    {
        return $this->executeObserved(
            $sql,
            $bindings,
            fn(): bool => $this->connection->insert($sql, $bindings),
            static fn(bool $result): int => $result ? $eventRowCount : 0,
        );
    }

    /**
     * Execute a tracked operation and emit consistent query telemetry/events.
     *
     * @template TResult
     * @param list<mixed> $bindings
     * @param callable():TResult $operation
     * @param callable(TResult):(int|null) $resolveRowCount
     * @return TResult
     */
    private function executeObserved(
        string $sql,
        array $bindings,
        callable $operation,
        callable $resolveRowCount,
    ): mixed {
        $this->validateBindingCount($sql, $bindings);
        $startTime = \microtime(true);

        if ($this->dispatchEvents) {
            $this->dispatchEvent(
                'db.query.executing',
                new \Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting($sql, $bindings, $this->connection),
            );
        }

        try {
            $result = $this->connection->withoutQueryEvents(
                static fn(): mixed => $operation(),
            );
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed);
            $this->emitExecutedEvent($sql, $bindings, $elapsed, $resolveRowCount($result));

            return $result;
        } catch (\Throwable $e) {
            $elapsed = \microtime(true) - $startTime;

            $this->logQuery($sql, $bindings, $elapsed, $e->getMessage());
            $this->emitFailedEvent($sql, $bindings, $elapsed, $e);

            throw QueryException::executionFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a statement-like query with consistent instrumentation.
     *
     * @param list<mixed> $bindings
     * @param callable():void $operation
     */
    private function executeStatementLike(string $sql, array $bindings, callable $operation): bool
    {
        $this->executeObserved(
            $sql,
            $bindings,
            static function () use ($operation): bool {
                $operation();

                return true;
            },
            static function (bool $ok): ?int {
                unset($ok);

                return null;
            },
        );

        return true;
    }

    /**
     * Fetch rows back using the unique key filters used by UPSERT fallback.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $uniqueBy
     * @param list<string> $returning
     * @return list<array<string,mixed>>
     */
    private function fetchRowsByUniqueKeys(
        string $table,
        array $rows,
        array $uniqueBy,
        array $returning,
    ): array {
        $fetch = new QueryBuilder($this->connection, $this);
        $fetch->from($table)->select(...$returning);

        $hasAnyFilter = false;

        foreach ($rows as $row) {
            $subset = $this->uniqueSubsetFromRow($row, $uniqueBy);

            if ($subset === []) {
                continue;
            }

            $hasAnyFilter = true;
            $fetch->orWhere(static function (QueryBuilder $nested) use ($subset): void {
                foreach ($subset as $column => $value) {
                    if ($column === '') {
                        continue;
                    }

                    $nested->where($column, '=', $value);
                }
            });
        }

        if (!$hasAnyFilter) {
            return [];
        }

        return $fetch->get();
    }

    /**
     * Log a query.
     *
     * @param list<mixed> $bindings
     * @param float $time Elapsed time in seconds
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
            'time' => \round($time * 1000, 2), // ms
            'timestamp' => \microtime(true),
        ];

        if ($error !== null) {
            $entry['error'] = $error;
        }

        $this->appendQueryLogEntry($entry);
    }

    /**
     * Calculate a safe number of rows for one parameterized mutation.
     *
     * @param array<string,mixed> $row
     * @return positive-int
     */
    private function maxRowsPerBatch(array $row, int $additionalBindings = 0): int
    {
        return $this->connection->safeBatchSize(max(1, count($row)), $additionalBindings);
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return list<mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            $normalized[] = $binding;
        }

        return $normalized;
    }

    /**
     * Normalize INSERT values (executor-side) to a list-of-rows.
     *
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @return array<int,array<string,mixed>>
     */
    private function normalizeInsertValues(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (!\is_array(\reset($values))) {
            /** @var array<string,mixed> $row */
            $row = $values;
            $values = [$row];
        }

        /** @var array<int,array<string,mixed>> $values */
        return $values;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeReturnedRows(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw QueryException::invalidParameter('upsertReturning', 'Transaction must return a row list.');
        }

        $rows = [];

        foreach ($result as $row) {
            if (!is_array($row)) {
                throw QueryException::invalidParameter('upsertReturning', 'Returned rows must be arrays.');
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    throw QueryException::invalidParameter(
                        'upsertReturning',
                        'Returned row keys must be strings.',
                    );
                }

                $normalized[$key] = $value;
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = $row;
        }

        return $normalized;
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

        $ordered = [];
        $max = $this->maxLogEntries;

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
        $max = $this->maxLogEntries;

        if (\count($ordered) > $max) {
            $ordered = \array_slice($ordered, -$max);
        }

        $this->queryLog = $ordered;
        $this->queryLogCount = \count($this->queryLog);
        $this->queryLogStart = 0;
    }

    /**
     * @param array<string,mixed> $firstRow
     * @param list<string> $uniqueBy
     * @param list<string>|null $update
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

    private function runCompiledObserved(CompiledQuery $compiled): DriverResult
    {
        try {
            return $this->connection->runCompiled($compiled);
        } catch (ConnectionException $exception) {
            throw QueryException::executionFailed($compiled->sql, $exception->getMessage());
        }
    }

    /**
     * Try native UPSERT ... RETURNING when supported by driver capabilities.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $uniqueBy
     * @param array<string,mixed> $updateAssoc
     * @param list<string> $returning
     * @return list<array<string,mixed>>|null
     */
    private function runNativeUpsertReturning(
        QueryBuilder $query,
        array $rows,
        array $uniqueBy,
        array $updateAssoc,
        array $returning,
    ): ?array {
        $capabilities = $this->connection->capabilities();
        if (!$capabilities->supportsUpsert || !$capabilities->supportsReturning) {
            return null;
        }

        $compiled = $this->connection->getCompiler()->compile(
            $query->toInsertPayload(
                $rows,
                mode: 'upsert',
                uniqueBy: $uniqueBy,
                upsertUpdate: array_keys($updateAssoc),
                returning: $returning,
            ),
        );

        return $this->normalizeRows($this->runCompiledObserved($compiled)->rows ?? []);
    }

    /**
     * Resolve table name from query components for fallback fetch.
     */
    private function tableFromQuery(QueryBuilder $query): ?string
    {
        $components = $query->getComponents();
        $table = $components['from'] ?? null;

        if (!\is_string($table) || $table === '') {
            return null;
        }

        return $table;
    }

    /**
     * Build unique-key subset for one row (or return empty when incomplete).
     *
     * @param array<string,mixed> $row
     * @param list<string> $uniqueBy
     * @return array<string,mixed>
     */
    private function uniqueSubsetFromRow(array $row, array $uniqueBy): array
    {
        $subset = [];

        foreach ($uniqueBy as $key) {
            if ($key === '') {
                return [];
            }

            if (!\array_key_exists($key, $row)) {
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
     * @param list<mixed> $bindings
     */
    private function validateBindingCount(string $sql, array $bindings): void
    {
        if (!$this->validateBindings || $bindings === []) {
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
