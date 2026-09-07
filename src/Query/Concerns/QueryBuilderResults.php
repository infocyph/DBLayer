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

        $result = $this->connection->queryCache()->remember(
            $this->resultCacheKey($sql, $bindingFingerprint),
            fn(): array => $this->executor->selectCompiled($compiled),
            $this->cacheTtl,
            $this->resultCacheTags(),
        );

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
        foreach ($this->keysetChunks($chunkSize, $column, $fromId, $direction) as [$rows]) {
            foreach ($rows as $row) {
                yield $row;
            }
        }
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
        return LazyCollection::fromFactory(function () use ($chunkSize, $column, $fromId, $direction): Generator {
            $index = 0;

            foreach ($this->cloneBuilder()
              ->withoutCache()
              ->lazyById($chunkSize, $column, $fromId, $direction) as $row) {
                yield $index++ => $row;
            }
        });
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
