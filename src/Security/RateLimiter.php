<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Rate Limiter
 *
 * Lightweight, process-local rate limiting for database operations.
 * Uses integer time buckets for performance (no date() formatting).
 *
 * NOTE: This is per-PHP-process / worker; it is not a distributed limiter.
 */
final class RateLimiter
{
    private const int DEFAULT_MAX_ENTRIES = 10_000;

    /**
     * Expiration timestamps keyed identically to storage.
     *
     * @var array<string, int>
     */
    private array $expiresAt = [];

    /**
     * @var array<string, int>
     */
    private array $storage = [];

    public function __construct(private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES)
    {
        if ($this->maxEntries <= 0) {
            throw SecurityException::invalidConfiguration('Rate limiter capacity must be greater than zero.');
        }
    }

    /**
     * Check a rate limit for a key within a given time window.
     *
     * @param string $key Logical identifier (e.g. "db:merchant:123")
     * @param int $maxAttempts Maximum allowed attempts per window
     * @param int $ttlSeconds Window size in seconds
     *
     * @throws SecurityException
     */
    public function check(string $key, int $maxAttempts, int $ttlSeconds): void
    {
        if ($maxAttempts <= 0 || $ttlSeconds <= 0) {
            // Non-positive config means "rate limiting disabled".
            return;
        }

        $now = time();
        $bucket = intdiv($now, $ttlSeconds);
        $storageKey = $key . ':' . $ttlSeconds . ':' . $bucket;

        if (!isset($this->storage[$storageKey]) && \count($this->storage) >= $this->maxEntries) {
            $this->purgeExpired($now);

            if (\count($this->storage) >= $this->maxEntries) {
                throw SecurityException::rateLimitStorageExhausted($this->maxEntries);
            }
        }

        $count = ($this->storage[$storageKey] ?? 0) + 1;
        $this->storage[$storageKey] = $count;
        $this->expiresAt[$storageKey] = ($bucket + 1) * $ttlSeconds;

        if ($count > $maxAttempts) {
            throw SecurityException::rateLimitExceeded($key, $maxAttempts, $ttlSeconds);
        }
    }

    /**
     * Clear all rate limit data.
     */
    public function clear(): void
    {
        $this->storage = [];
        $this->expiresAt = [];
    }

    /**
     * Get current count for a key within the current window.
     */
    public function getCount(string $key, int $ttlSeconds): int
    {
        if ($ttlSeconds <= 0) {
            return 0;
        }

        $bucket = intdiv(time(), $ttlSeconds);
        $storageKey = $key . ':' . $ttlSeconds . ':' . $bucket;

        return $this->storage[$storageKey] ?? 0;
    }

    /**
     * Get storage statistics.
     *
     * @return array{total_keys:int,total_requests:int}
     */
    public function getStats(): array
    {
        $this->purgeExpired(time());

        return [
            'total_keys' => count($this->storage),
            'total_requests' => array_sum($this->storage),
        ];
    }

    /**
     * Reset rate limit for a key (across all windows / TTL buckets).
     */
    public function reset(string $key): void
    {
        $prefix = $key . ':';

        foreach (array_keys($this->storage) as $storageKey) {
            if (str_starts_with($storageKey, $prefix)) {
                unset($this->storage[$storageKey]);
                unset($this->expiresAt[$storageKey]);
            }
        }
    }

    /**
     * Remove buckets that can no longer affect rate-limit decisions.
     */
    private function purgeExpired(int $now): void
    {
        foreach ($this->expiresAt as $storageKey => $expiresAt) {
            if ($expiresAt > $now) {
                continue;
            }

            unset($this->expiresAt[$storageKey], $this->storage[$storageKey]);
        }
    }
}
