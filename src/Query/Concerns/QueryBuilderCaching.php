<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query\Concerns;

use DateInterval;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Support\SqlFingerprint;

trait QueryBuilderCaching
{
    private bool $cacheEnabled = false;

    private ?string $cacheKey = null;

    /** @var list<string> */
    private array $cacheTags = [];

    private DateInterval|int|null $cacheTtl = null;

    private bool $hasExplicitCacheTags = false;

    /**
     * Opt this query into CacheLayer-backed result caching.
     */
    public function cacheFor(DateInterval|int|null $ttl): self
    {
        if (is_int($ttl) && $ttl < 1) {
            throw QueryException::invalidParameter('cacheFor', 'TTL must be a positive integer or null.');
        }

        $this->cacheEnabled = true;
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * Set an optional explicit identity for this cached query.
     */
    public function cacheKey(?string $key): self
    {
        $key = $key === null ? null : trim($key);

        if ($key === '') {
            throw QueryException::invalidParameter('cacheKey', 'Cache key must not be empty.');
        }

        $this->cacheKey = $key;

        return $this;
    }

    /**
     * Add explicit CacheLayer tags to this query.
     *
     * @param array<string>|string ...$tags
     */
    public function cacheTags(array|string ...$tags): self
    {
        foreach ($tags as $tagGroup) {
            foreach ((array) $tagGroup as $tag) {
                $this->cacheTags[] = self::validateCallerCacheTag($tag);
            }
        }

        $this->cacheTags = array_values(array_unique($this->cacheTags));
        $this->hasExplicitCacheTags = $this->cacheTags !== [];

        return $this;
    }

    /**
     * Disable result caching on this builder and its clones.
     */
    public function withoutCache(): self
    {
        $this->cacheEnabled = false;

        return $this;
    }

    private static function validateCallerCacheTag(string $tag): string
    {
        $tag = trim($tag);

        if ($tag === '' || strlen($tag) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $tag) !== 1) {
            throw QueryException::invalidParameter(
                'cacheTags',
                'Cache tags must be 1-64 characters from [A-Za-z0-9_.-] and start with alphanumeric.',
            );
        }

        return $tag;
    }

    /** @return list<string> */
    private function automaticTableTags(): array
    {
        $tables = [];

        if ($this->from !== null && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $this->from) === 1) {
            $tables[] = $this->from;
        }

        foreach ($this->joins as $join) {
            $table = $join instanceof JoinClause ? $join->getTable() : ($join['table'] ?? null);

            if (is_string($table) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $table) === 1) {
                $tables[] = $table;
            }
        }

        return array_map(
            fn(string $table): string => $this->connection->cacheTableTag($table),
            array_values(array_unique($tables)),
        );
    }

    /** @param list<mixed> $bindings */
    private function cacheBindingFingerprint(array $bindings): ?string
    {
        $encoded = [];

        foreach ($bindings as $binding) {
            $value = match (true) {
                $binding === null => 'n',
                is_bool($binding) => 'b:' . (int) $binding,
                is_int($binding) => 'i:' . $binding,
                is_float($binding) => 'f:' . sprintf('%.17g', $binding),
                is_string($binding) => 's:' . strlen($binding) . ':' . $binding,
                $binding instanceof \Stringable => 's:' . strlen((string) $binding) . ':' . $binding,
                default => null,
            };

            if ($value === null) {
                return null;
            }

            $encoded[] = $value;
        }

        return hash('xxh3', implode("\0", $encoded));
    }

    private function canUseResultCache(): bool
    {
        if (!$this->cacheEnabled || $this->lock !== null || $this->connection->managedTransactionLevel() > 0) {
            return false;
        }

        if ($this->connection->hasStickyWrite()) {
            return false;
        }

        if (($this->containsRawFragments || $this->hasComplexDependencyGraph()) && !$this->hasExplicitCacheTags) {
            return false;
        }

        return true;
    }

    private function hasComplexDependencyGraph(): bool
    {
        if ($this->ctes !== [] || $this->unions !== [] || $this->fromSubquery !== null) {
            return true;
        }

        if ($this->from !== null && str_starts_with(ltrim($this->from), '(')) {
            return true;
        }

        foreach ($this->joins as $join) {
            if (is_array($join) && ($join['subquery'] ?? false) === true) {
                return true;
            }
        }

        return $this->wheresContainChildQueries($this->wheres);
    }

    private function invalidateCachedTables(): void
    {
        if (!$this->connection->hasQueryCache()) {
            return;
        }

        $tags = $this->automaticTableTags();
        if ($tags === []) {
            return;
        }

        $cache = $this->connection->queryCache();
        $this->connection->afterCommit(
            static function () use ($cache, $tags): void {
                $cache->invalidateTags($tags);
            },
        );
    }

    private function resultCacheKey(string $sql, string $bindingFingerprint): string
    {
        $connectionIdentity = implode("\0", [
            $this->connection->getName(),
            $this->connection->getDriverName(),
            $this->connection->getDatabaseName(),
        ]);

        return 'dblayer.query.' . hash('xxh3', implode("\0", [
            $connectionIdentity,
            'select-array',
            SqlFingerprint::hash($sql, 32),
            $bindingFingerprint,
            $this->cacheKey ?? '',
        ]));
    }

    /** @return list<string> */
    private function resultCacheTags(): array
    {
        return array_values(array_unique([
            ...$this->automaticTableTags(),
            ...$this->cacheTags,
        ]));
    }

    /** @param list<array<string,mixed>> $wheres */
    private function wheresContainChildQueries(array $wheres): bool
    {
        return array_any($wheres, fn($where) => in_array($where['type'] ?? null, ['exists', 'nested'], true));
    }
}
