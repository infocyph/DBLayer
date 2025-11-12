<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache\Strategies;

/**
 * File Cache Strategy
 *
 * File-based cache storage for persistent caching.
 * Survives between requests but slower than memory.
 *
 * @package Infocyph\DBLayer\Cache\Strategies
 * @author Hasan
 */
class FileStrategy implements CacheStrategy
{
    /**
     * File extension
     */
    private const EXTENSION = '.cache';
    /**
     * Cache directory
     */
    private string $directory;

    /**
     * Create a new file cache strategy
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/dblayer-cache';
        $this->ensureDirectoryExists();
    }

    /**
     * Clean expired cache files
     */
    public function cleanExpired(): int
    {
        $cleaned = 0;
        $files = glob($this->directory . '/*' . self::EXTENSION);
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = file_get_contents($file);
            $data = unserialize($content);

            if ($data['expires'] > 0 && $now >= $data['expires']) {
                unlink($file);
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
        $files = glob($this->directory . '/*' . self::EXTENSION);

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * Delete item from cache
     */
    public function forget(string $key): bool
    {
        $path = $this->getPath($key);

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Get item from cache
     */
    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $data = unserialize($content);

        // Check expiration
        if ($data['expires'] > 0 && time() >= $data['expires']) {
            $this->forget($key);
            return null;
        }

        return $data['value'];
    }

    /**
     * Get cache directory
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Check if item exists
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
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
     * Store item in cache
     */
    public function put(string $key, mixed $value, int $ttl): bool
    {
        $path = $this->getPath($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;

        $data = [
            'value' => $value,
            'expires' => $expires,
            'created' => time(),
        ];

        $content = serialize($data);

        return file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /**
     * Ensure cache directory exists
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * Get file path for key
     */
    private function getPath(string $key): string
    {
        $hash = md5($key);
        return $this->directory . '/' . $hash . self::EXTENSION;
    }
}