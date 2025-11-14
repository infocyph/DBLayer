<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Async;

/**
 * Async Query Executor
 *
 * Higher-level convenience around AsyncConnection:
 * - Parallel execution
 * - Batched execution
 * - Simple queued execution with max concurrency
 */
final class AsyncExecutor
{
    private AsyncConnection $connection;

    private int $maxConcurrent;

    /**
     * @var array<int, array{sql:string,bindings:array<int|string,mixed>,resolve:callable,reject:callable}>
     */
    private array $queue = [];

    private int $running = 0;

    /**
     * @var array{
     *   executed:int,
     *   queued:int,
     *   failed:int
     * }
     */
    private array $stats = [
      'executed' => 0,
      'queued'   => 0,
      'failed'   => 0,
    ];

    public function __construct(AsyncConnection $connection, int $maxConcurrent = 10)
    {
        $this->connection    = $connection;
        $this->maxConcurrent = max(1, $maxConcurrent);
    }

    /**
     * Execute a single query.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): Promise
    {
        $this->stats['executed']++;

        return $this->connection->query($sql, $bindings)
          ->catch(function (\Throwable $error): never {
              $this->stats['failed']++;
              throw $error;
          });
    }

    /**
     * Execute multiple queries in parallel.
     *
     * Each query can be:
     * - string SQL
     * - ['sql' => string, 'bindings' => array]
     * - [string, array]
     *
     * @param array<int, mixed> $queries
     */
    public function parallel(array $queries): Promise
    {
        $promises = [];

        foreach ($queries as $query) {
            [$sql, $bindings] = $this->parseQuery($query);
            $promises[]       = $this->execute($sql, $bindings);
        }

        return Promise::all($promises);
    }

    /**
     * Execute queries in ordered batches.
     *
     * @param array<int, mixed> $queries
     */
    public function batch(array $queries, int $batchSize = 10): Promise
    {
        $batchSize = max(1, $batchSize);
        $batches   = array_chunk($queries, $batchSize);
        $results   = [];

        $self = $this;

        $executeNext = function (int $index = 0) use (&$results, $batches, &$executeNext, $self): Promise {
            if (!isset($batches[$index])) {
                return Promise::resolve($results);
            }

            $batch    = $batches[$index];
            $promises = [];

            foreach ($batch as $query) {
                [$sql, $bindings] = $self->parseQuery($query);
                $promises[]       = $self->execute($sql, $bindings);
            }

            return Promise::all($promises)
              ->then(function (array $batchResults) use (&$results, $index, &$executeNext): Promise {
                  $results = array_merge($results, $batchResults);
                  return $executeNext($index + 1);
              });
        };

        return $executeNext();
    }

    /**
     * Queue a query to respect max concurrency.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function queue(string $sql, array $bindings = []): Promise
    {
        $this->stats['queued']++;

        return new Promise(function (callable $resolve, callable $reject) use ($sql, $bindings): void {
            $this->queue[] = [
              'sql'      => $sql,
              'bindings' => $bindings,
              'resolve'  => $resolve,
              'reject'   => $reject,
            ];

            $this->processQueue();
        });
    }

    public function processQueue(): void
    {
        while ($this->running < $this->maxConcurrent && $this->queue !== []) {
            $item = array_shift($this->queue);
            if ($item === null) {
                break;
            }

            $this->running++;

            $this->execute($item['sql'], $item['bindings'])
              ->then(function (mixed $result) use ($item): void {
                  $this->running--;
                  $item['resolve']($result);
                  $this->processQueue();
              })
              ->catch(function (\Throwable $error) use ($item): void {
                  $this->running--;
                  $item['reject']($error);
                  $this->processQueue();
              });
        }
    }

    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    public function setMaxConcurrent(int $max): void
    {
        $this->maxConcurrent = max(1, $max);
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
          'running'       => $this->running,
          'queued_count'  => count($this->queue),
        ]);
    }

    public function resetStats(): void
    {
        $this->stats = [
          'executed' => 0,
          'queued'   => 0,
          'failed'   => 0,
        ];
    }

    /**
     * @return array{0:string,1:array<int|string,mixed>}
     */
    private function parseQuery(mixed $query): array
    {
        if (is_string($query)) {
            return [$query, []];
        }

        if (is_array($query)) {
            $sql      = $query['sql']      ?? ($query[0] ?? '');
            $bindings = $query['bindings'] ?? ($query[1] ?? []);

            return [(string) $sql, is_array($bindings) ? $bindings : []];
        }

        return ['', []];
    }
}
