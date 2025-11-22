<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Cache\Strategies;

/**
 * File Cache Strategy
 *
 * File-based cache storage for persistent caching.
 * Survives between requests but slower than memory.
 */
final class FileStrategy implements CacheStrategy
{
    /**
     * File extension.
     */
    private const EXTENSION = '.cache';

    /**
     * Cache directory.
     */
    private string $directory;

    /**
     * Create a new file cache strategy.
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory
          ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dblayer-cache');

        $this->ensureDirectoryExists();
    }

    /**
     * Clean expired cache files.
     */
    public function cleanExpired(): int
    {
        $cleaned = 0;
        $files   = (array) glob($this->directory . '/*' . self::EXTENSION);
        $now     = time();

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $content = @file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $data = @unserialize($content, ['allowed_classes' => true]);

            if (! is_array($data) || ! array_key_exists('expires', $data)) {
                // Invalid; treat as expired/corrupt.
                @unlink($file);
                $cleaned++;

                continue;
            }

            /** @var int $expires */
            $expires = (int) $data['expires'];

            if ($expires > 0 && $now >= $expires) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Decrement value.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * Clear all items.
     */
    public function flush(): bool
    {
        $files = (array) glob($this->directory . '/*' . self::EXTENSION);

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * Delete item from cache.
     */
    public function forget(string $key): bool
    {
        $path = $this->getPath($key);

        if (is_file($path)) {
            return @unlink($path);
        }

        return false;
    }

    /**
     * Get item from cache.
     */
    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (! is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $data = @unserialize($content, ['allowed_classes' => true]);

        if (! is_array($data)) {
            // Corrupt cache entry.
            $this->forget($key);

            return null;
        }

        $expires = isset($data['expires']) ? (int) $data['expires'] : 0;

        // Check expiration.
        if ($expires > 0 && time() >= $expires) {
            $this->forget($key);

            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Get cache directory.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Check if item exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Increment value.
     */
    public function increment(string $key, int $value = 1): int
    {
        $current = (int) $this->get($key);
        $new     = $current + $value;

        $this->put($key, $new, 0);

        return $new;
    }

    /**
     * Store item in cache.
     */
    public function put(string $key, mixed $value, int $ttl): bool
    {
        if ($ttl < 0) {
            $ttl = 0;
        }

        $path    = $this->getPath($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;

        $data = [
          'value'   => $value,
          'expires' => $expires,
          'created' => time(),
        ];

        $content = serialize($data);

        return @file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /**
     * Ensure cache directory exists.
     */
    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        @mkdir($this->directory, 0755, true);
    }

    /**
     * Get file path for key.
     */
    private function getPath(string $key): string
    {
        $hash = md5($key);

        return $this->directory . DIRECTORY_SEPARATOR . $hash . self::EXTENSION;
    }
}
