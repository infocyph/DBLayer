<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache;

/**
 * Tagged Cache
 *
 * Manages cache items with tags for group operations.
 * Allows invalidating multiple cache items by tag.
 *
 * @package Infocyph\DBLayer\Cache
 * @author Hasan
 */
class TaggedCache
{
    /**
     * Tag namespace separator
     */
    private const TAG_SEPARATOR = '|';
    /**
     * Cache instance
     */
    private Cache $cache;

    /**
     * Cache tags
     */
    private array $tags;

    /**
     * Create a new tagged cache instance
     */
    public function __construct(Cache $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    /**
     * Flush all items with these tags
     */
    public function flush(): bool
    {
        $this->flushTagTimestamps();
        return true;
    }

    /**
     * Store item forever
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
        return $this->cache->forget($this->taggedKey($key));
    }

    /**
     * Get item from cache
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->taggedKey($key), $default);
    }

    /**
     * Check if item exists
     */
    public function has(string $key): bool
    {
        return $this->cache->has($this->taggedKey($key));
    }

    /**
     * Store item in cache
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->updateTagTimestamps();
        return $this->cache->put($this->taggedKey($key), $value, $ttl);
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
     * Flush tag timestamps
     */
    private function flushTagTimestamps(): void
    {
        foreach ($this->tags as $tag) {
            $this->resetTagId($tag);
        }
    }

    /**
     * Get tag ID (timestamp)
     */
    private function getTagId(string $tag): string
    {
        $key = 'tag:' . $tag;
        $id = $this->cache->get($key);

        if ($id === null) {
            $id = $this->resetTagId($tag);
        }

        return $id;
    }

    /**
     * Reset tag ID
     */
    private function resetTagId(string $tag): string
    {
        $id = uniqid('', true);
        $key = 'tag:' . $tag;
        $this->cache->forever($key, $id);

        return $id;
    }

    /**
     * Get tag namespace
     */
    private function taggedKey(string $key): string
    {
        return implode(self::TAG_SEPARATOR, $this->tagIds()) . self::TAG_SEPARATOR . $key;
    }

    /**
     * Get tag IDs (timestamps)
     */
    private function tagIds(): array
    {
        $ids = [];

        foreach ($this->tags as $tag) {
            $ids[] = $this->getTagId($tag);
        }

        return $ids;
    }

    /**
     * Update tag timestamps
     */
    private function updateTagTimestamps(): void
    {
        foreach ($this->tags as $tag) {
            $this->getTagId($tag);
        }
    }
}
