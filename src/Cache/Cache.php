<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache;

use Infocyph\DBLayer\Cache\Strategies\CacheStrategy;
use Infocyph\DBLayer\Cache\Strategies\MemoryStrategy;

/**
 * Cache Manager
 *
 * Manages query result caching with:
 * - Multiple cache strategies (Memory, File, Redis)
 * - TTL (time-to-live) support
 * - Cache tags for group invalidation
 * - Cache statistics and monitoring
 * - Automatic cache key generation
 *
 * @package Infocyph\DBLayer\Cache
 * @author Hasan
 */
class Cache
{
    /**
     * Default TTL in seconds
     */
    private int $defaultTtl = 3600;

    /**
     * Enable/disable caching
     */
    private bool $enabled = true;

    /**
     * Cache prefix
     */
    private string $prefix = 'dblayer:';

    /**
     * Cache statistics
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
    ];
    /**
     * Cache strategy instance
     */
    private CacheStrategy $strategy;

    /**
     * Create a new cache instance
     */
    public function __construct(?CacheStrategy $strategy = null)
    {
        $this->strategy = $strategy ?? new MemoryStrategy();
    }

    /**
     * Cache query result
     */
    public function cacheQuery(string $sql, array $bindings, mixed $result, ?int $ttl = null): bool
    {
        $key = $this->generateQueryKey($sql, $bindings);
        return $this->put($key, $result, $ttl);
    }

    /**
     * Decrement cache value
     */
    public function decrement(string $key, int $value = 1): int
    {
        $key = $this->makeKey($key);
        return $this->strategy->decrement($key, $value);
    }

    /**
     * Disable caching
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable caching
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Clear all cache items
     */
    public function flush(): bool
    {
        return $this->strategy->flush();
    }

    /**
     * Store item in cache forever
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Delete item from cache
     */
    public function forget(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->makeKey($key);

        try {
            $result = $this->strategy->forget($key);

            if ($result) {
                $this->stats['deletes']++;
            }

            return $result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Clear cached query result
     */
    public function forgetQuery(string $sql, array $bindings): bool
    {
        $key = $this->generateQueryKey($sql, $bindings);
        return $this->forget($key);
    }

    /**
     * Generate cache key from SQL and bindings
     */
    public function generateQueryKey(string $sql, array $bindings = []): string
    {
        $key = $sql . serialize($bindings);
        return md5($key);
    }

    /**
     * Get item from cache
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }

        $key = $this->makeKey($key);

        try {
            $value = $this->strategy->get($key);

            if ($value !== null) {
                $this->stats['hits']++;
                return $value;
            }

            $this->stats['misses']++;
            return $default;
        } catch (\Throwable $e) {
            $this->stats['misses']++;
            return $default;
        }
    }

    /**
     * Get cached query result
     */
    public function getCachedQuery(string $sql, array $bindings): mixed
    {
        $key = $this->generateQueryKey($sql, $bindings);
        return $this->get($key);
    }

    /**
     * Get default TTL
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Get cache prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;

        return array_merge($this->stats, [
            'total_requests' => $total,
            'hit_rate' => round($hitRate, 2),
            'miss_rate' => round(100 - $hitRate, 2),
        ]);
    }

    /**
     * Get cache strategy
     */
    public function getStrategy(): CacheStrategy
    {
        return $this->strategy;
    }

    /**
     * Check if item exists in cache
     */
    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->makeKey($key);

        return $this->strategy->has($key);
    }

    /**
     * Increment cache value
     */
    public function increment(string $key, int $value = 1): int
    {
        $key = $this->makeKey($key);
        return $this->strategy->increment($key, $value);
    }

    /**
     * Check if caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Store item in cache
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->makeKey($key);
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            $result = $this->strategy->put($key, $value, $ttl);

            if ($result) {
                $this->stats['writes']++;
            }

            return $result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get item or execute callback and cache result
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Get item or execute callback and cache forever
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, 0);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => 0,
        ];
    }

    /**
     * Set default TTL
     */
    public function setDefaultTtl(int $ttl): void
    {
        $this->defaultTtl = $ttl;
    }

    /**
     * Set cache prefix
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Set cache strategy
     */
    public function setStrategy(CacheStrategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * Tag cache items for group operations
     */
    public function tags(array|string $tags): TaggedCache
    {
        $tags = is_array($tags) ? $tags : [$tags];

        return new TaggedCache($this, $tags);
    }

    /**
     * Make full cache key with prefix
     */
    private function makeKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
