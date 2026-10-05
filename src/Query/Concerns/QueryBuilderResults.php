<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use Generator;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Pagination\SimplePaginator;

trait QueryBuilderResults
{
    /**
     * Execute an aggregate on a cloned builder without mutating this query.
     */
    public function aggregate(string $function, string $column = '*'): mixed
    {
        $function = trim($function);
        if ($function === '') {
            throw QueryException::invalidParameter('function', 'Aggregate function must not be empty.');
        }

        return $this->runAggregate($function, $column, false);
    }

    /**
     * Materialize this result as ArrayKit's Collection.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function collect(): Collection
    {
        return new Collection($this->get());
    }

    /**
     * Iterate over rows lazily using the underlying PDO cursor.
     *
     * @return Generator<mixed>
     */
    public function cursor(?int $fetchMode = null): Generator
    {
        yield from $this->stream($fetchMode);
    }

    /**
     * Execute the query and get all results.
     *
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        $compiled = $this->executor->compileSelect($this);

        if (!$this->canUseResultCache()) {
            return $this->executor->selectCompiled($compiled);
        }

        $sql = $compiled->sql;
        $bindings = $compiled->bindings;
        $bindingFingerprint = $this->cacheBindingFingerprint($bindings);

        if ($bindingFingerprint === null) {
            return $this->executor->selectCompiled($compiled);
        }

        $cacheStartedAt = microtime(true);
        $this->connection->assertQueryCheckpoint($cacheStartedAt);

        $result = $this->connection->queryCache()->remember(
            $this->resultCacheKey($sql, $bindingFingerprint),
            fn(): array => $this->executor->selectCompiled($compiled),
            $this->cacheTtl,
            $this->resultCacheTags(),
        );

        $this->connection->assertQueryCheckpoint($cacheStartedAt);

        return $this->normalizeCachedRows($result);
    }

    /**
     * Iterate in bounded keyset batches, releasing the statement between batches.
     *
     * @return Generator<array<string,mixed>>
     */
    public function lazyById(
        int $chunkSize = 1000,
        string $column = 'id',
        mixed $fromId = null,
        string $direction = 'asc',
    ): Generator {
        return $this->lazyByIdGenerator(
            $chunkSize,
            $column,
            $fromId,
            $direction,
            $this->connection->runwireBinding(),
        );
    }

    /**
     * Adapt bounded keyset iteration to ArrayKit's lazy transformation API.
     *
     * @return LazyCollection<int, array<string,mixed>>
     */
    public function lazyCollection(
        int $chunkSize = 1000,
        string $column = 'id',
        mixed $fromId = null,
        string $direction = 'asc',
    ): LazyCollection {
        $runwireBinding = $this->connection->runwireBinding();
        $collection = LazyCollection::fromFactory(
            function () use ($chunkSize, $column, $fromId, $direction, $runwireBinding): Generator {
                $index = 0;
                $builder = $this->cloneBuilder()->withoutCache();

                foreach ($builder->lazyByIdGenerator(
                    $chunkSize,
                    $column,
                    $fromId,
                    $direction,
                    $runwireBinding,
                ) as $row) {
                    yield $index++ => $row;
                }
            },
        );

        if ($runwireBinding === null) {
            return $collection;
        }

        return $collection->withRunwire(
            $runwireBinding['runtime'],
            $runwireBinding['request'],
            $runwireBinding['scope'],
        );
    }

    /**
     * Paginate without a COUNT query.
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null): SimplePaginator
    {
        if ($perPage <= 0) {
            throw QueryException::invalidLimit($perPage);
        }

        $page = max(1, $page ?? 1);
        $clone = $this->cloneBuilder();
        $clone->aggregate = null;
        $clone->offset = ($page - 1) * $perPage;
        $clone->limit = $perPage + 1;
        [$items, $hasMore] = $this->resolvePaginatedItems($clone->get(), $perPage);

        return new SimplePaginator($items, $perPage, $page, $hasMore);
    }

    /**
     * @param array{
     *   runtime:\Infocyph\Runwire\RuntimeContext,
     *   request:?\Infocyph\Runwire\RequestContext,
     *   scope:?\Infocyph\Runwire\Coroutine\CoroutineScope
     * }|null $runwireBinding
     * @return Generator<array<string,mixed>>
     */
    private function lazyByIdGenerator(
        int $chunkSize,
        string $column,
        mixed $fromId,
        string $direction,
        ?array $runwireBinding,
    ): Generator {
        foreach ($this->keysetChunks(
            $chunkSize,
            $column,
            $fromId,
            $direction,
            $runwireBinding,
        ) as [$rows]) {
            foreach ($rows as $row) {
                yield $row;
            }
        }
    }

    /** @phpstan-assert list<array<string,mixed>> $result */
    private function assertCachedRows(mixed $result): void
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw QueryException::invalidParameter('cache', 'Cached query result must be a row list.');
        }

        foreach ($result as $row) {
            if (!is_array($row)) {
                throw QueryException::invalidParameter('cache', 'Cached query rows must be arrays.');
            }

            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    throw QueryException::invalidParameter('cache', 'Cached query row keys must be strings.');
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function normalizeCachedRows(mixed $result): array
    {
        $this->assertCachedRows($result);

        return $result;
    }
}
