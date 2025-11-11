<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache\Strategies;

/**
 * Memory Cache Strategy
 *
 * In-memory cache storage for single request lifecycle.
 * Fast but volatile - cleared between requests.
 *
 * @package Infocyph\DBLayer\Cache\Strategies
 * @author Hasan
 */
class MemoryStrategy implements CacheStrategy
{
    /**
     * Cache storage
     */
    private array $cache = [];

    /**
     * Expiration times
     */
    private array $expires = [];

    /**
     * Clean expired items
     */
    public function cleanExpired(): int
    {
        $cleaned = 0;
        $now = time();

        foreach ($this->expires as $key => $expireTime) {
            if ($expireTime > 0 && $now >= $expireTime) {
                $this->forget($key);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Decrement value
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * Clear all items
     */
    public function flush(): bool
    {
        $this->cache = [];
        $this->expires = [];
        return true;
    }

    /**
     * Delete item from cache
     */
    public function forget(string $key): bool
    {
        unset($this->cache[$key], $this->expires[$key]);
        return true;
    }

    /**
     * Get item from cache
     */
    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->cache[$key];
    }

    /**
     * Check if item exists and not expired
     */
    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        // Check expiration
        if (isset($this->expires[$key]) && $this->expires[$key] > 0) {
            if (time() >= $this->expires[$key]) {
                $this->forget($key);
                return false;
            }
        }

        return true;
    }

    /**
     * Increment value
     */
    public function increment(string $key, int $value = 1): int
    {
        $current = (int) $this->get($key);
        $new = $current + $value;
        $this->put($key, $new, 0);

        return $new;
    }

    /**
     * Get all cache keys
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Store item in cache
     */
    public function put(string $key, mixed $value, int $ttl): bool
    {
        $this->cache[$key] = $value;

        if ($ttl > 0) {
            $this->expires[$key] = time() + $ttl;
        } else {
            $this->expires[$key] = 0; // Forever
        }

        return true;
    }

    /**
     * Get cache size
     */
    public function size(): int
    {
        return count($this->cache);
    }
}
