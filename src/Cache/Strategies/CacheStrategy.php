<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache\Strategies;

/**
 * Cache Strategy Interface
 *
 * Defines the contract for cache storage implementations.
 */
interface CacheStrategy
{
    /**
     * Decrement value.
     */
    public function decrement(string $key, int $value = 1): int;

    /**
     * Clear all items.
     */
    public function flush(): bool;

    /**
     * Delete item from cache.
     */
    public function forget(string $key): bool;

    /**
     * Get item from cache.
     */
    public function get(string $key): mixed;

    /**
     * Check if item exists.
     */
    public function has(string $key): bool;

    /**
     * Increment value.
     */
    public function increment(string $key, int $value = 1): int;

    /**
     * Store item in cache.
     */
    public function put(string $key, mixed $value, int $ttl): bool;
}
