<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

/**
 * Async Query Executor
 *
 * Executes queries asynchronously with:
 * - Non-blocking execution
 * - Batch operations
 * - Parallel queries
 * - Query queueing
 *
 * @package Infocyph\DBLayer\Async
 * @author Hasan
 */
class AsyncExecutor
{
    /**
     * Async connection
     */
    private AsyncConnection $connection;

    /**
     * Max concurrent queries
     */
    private int $maxConcurrent = 10;

    /**
     * Query queue
     */
    private array $queue = [];

    /**
     * Currently running queries
     */
    private int $running = 0;

    /**
     * Execution statistics
     */
    private array $stats = [
        'executed' => 0,
        'queued' => 0,
        'failed' => 0,
    ];

    /**
     * Create a new async executor
     */
    public function __construct(AsyncConnection $connection, int $maxConcurrent = 10)
    {
        $this->connection = $connection;
        $this->maxConcurrent = $maxConcurrent;
    }

    /**
     * Execute queries in batch
     */
    public function batch(array $queries, int $batchSize = 10): Promise
    {
        $batches = array_chunk($queries, $batchSize);
        $results = [];

        $executeNext = function ($index = 0) use ($batches, &$results, &$executeNext) {
            if ($index >= count($batches)) {
                return Promise::resolve($results);
            }

            $batch = $batches[$index];
            $promises = [];

            foreach ($batch as $query) {
                [$sql, $bindings] = $this->parseQuery($query);
                $promises[] = $this->execute($sql, $bindings);
            }

            return Promise::all($promises)
                ->then(function ($batchResults) use (&$results, $index, $executeNext) {
                    $results = array_merge($results, $batchResults);
                    return $executeNext($index + 1);
                });
        };

        return $executeNext();
    }

    /**
     * Execute a query
     */
    public function execute(string $sql, array $bindings = []): Promise
    {
        $this->stats['executed']++;

        return $this->connection->query($sql, $bindings)
            ->catch(function ($error) {
                $this->stats['failed']++;
                throw $error;
            });
    }

    /**
     * Get max concurrent queries
     */
    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'running' => $this->running,
            'queued_count' => count($this->queue),
        ]);
    }

    /**
     * Execute multiple queries in parallel
     */
    public function parallel(array $queries): Promise
    {
        $promises = [];

        foreach ($queries as $query) {
            [$sql, $bindings] = $this->parseQuery($query);
            $promises[] = $this->execute($sql, $bindings);
        }

        return Promise::all($promises);
    }

    /**
     * Process queued queries
     */
    public function processQueue(): void
    {
        while ($this->running < $this->maxConcurrent && !empty($this->queue)) {
            $item = array_shift($this->queue);
            $this->running++;

            $this->execute($item['sql'], $item['bindings'])
                ->then(function ($result) use ($item) {
                    $this->running--;
                    $item['resolve']($result);
                    $this->processQueue();
                })
                ->catch(function ($error) use ($item) {
                    $this->running--;
                    $item['reject']($error);
                    $this->processQueue();
                });
        }
    }

    /**
     * Queue a query for execution
     */
    public function queue(string $sql, array $bindings = []): Promise
    {
        $this->stats['queued']++;

        return new Promise(function ($resolve, $reject) use ($sql, $bindings) {
            $this->queue[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'resolve' => $resolve,
                'reject' => $reject,
            ];

            $this->processQueue();
        });
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'executed' => 0,
            'queued' => 0,
            'failed' => 0,
        ];
    }

    /**
     * Set max concurrent queries
     */
    public function setMaxConcurrent(int $max): void
    {
        $this->maxConcurrent = $max;
    }

    /**
     * Parse query array
     */
    private function parseQuery(mixed $query): array
    {
        if (is_string($query)) {
            return [$query, []];
        }

        if (is_array($query)) {
            return [
                $query['sql'] ?? $query[0] ?? '',
                $query['bindings'] ?? $query[1] ?? [],
            ];
        }

        return ['', []];
    }
}