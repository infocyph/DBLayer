<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

/**
 * Simple in-memory cache for query results
 */
class Cache
{
    private array $store = [];
    private array $tags = [];
    private int $defaultTtl = 3600;
    private array $expirations = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->has($key) && !$this->isExpired($key)) {
            return unserialize($this->store[$key]);
        }

        return $default;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $this->store[$key] = serialize($value);
        $this->expirations[$key] = time() + $ttl;

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->store[$key] = serialize($value);
        $this->expirations[$key] = PHP_INT_MAX;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->store[$key], $this->expirations[$key]);
        
        // Remove from tags
        foreach ($this->tags as $tag => $keys) {
            if (($index = array_search($key, $keys)) !== false) {
                unset($this->tags[$tag][$index]);
            }
        }

        return true;
    }

    public function flush(): bool
    {
        $this->store = [];
        $this->expirations = [];
        $this->tags = [];

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->forever($key, $value);

        return $value;
    }

    public function increment(string $key, int $value = 1): int
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $value;
        $this->put($key, $new);

        return $new;
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    public function tags(array|string $tags): self
    {
        $tags = is_array($tags) ? $tags : [$tags];
        
        $instance = clone $this;
        $instance->currentTags = $tags;
        
        return $instance;
    }

    public function taggedPut(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }
            $this->tags[$tag][] = $key;
        }

        return $this->put($key, $value, $ttl);
    }

    public function flushTags(array|string $tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];

        foreach ($tags as $tag) {
            if (isset($this->tags[$tag])) {
                foreach ($this->tags[$tag] as $key) {
                    $this->forget($key);
                }
                unset($this->tags[$tag]);
            }
        }

        return true;
    }

    public function generateKey(string $sql, array $bindings): string
    {
        return md5($sql . serialize($bindings));
    }

    private function isExpired(string $key): bool
    {
        if (!isset($this->expirations[$key])) {
            return false;
        }

        if ($this->expirations[$key] <= time()) {
            $this->forget($key);
            return true;
        }

        return false;
    }

    public function setDefaultTtl(int $seconds): void
    {
        $this->defaultTtl = $seconds;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }
}
