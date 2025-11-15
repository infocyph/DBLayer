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
    /**
     * @var array<string, int>
     */
    private array $storage = [];

    /**
     * Check a rate limit for a key within a given time window.
     *
     * @param string $key         Logical identifier (e.g. "db:merchant:123")
     * @param int    $maxAttempts Maximum allowed attempts per window
     * @param int    $ttlSeconds  Window size in seconds
     *
     * @throws SecurityException
     */
    public function check(string $key, int $maxAttempts, int $ttlSeconds): void
    {
        if ($maxAttempts <= 0 || $ttlSeconds <= 0) {
            // Non-positive config means "rate limiting disabled".
            return;
        }

        $bucket     = intdiv(time(), $ttlSeconds);
        $storageKey = $key . ':' . $ttlSeconds . ':' . $bucket;

        $count = ($this->storage[$storageKey] ?? 0) + 1;
        $this->storage[$storageKey] = $count;

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
    }

    /**
     * Get current count for a key within the current window.
     */
    public function getCount(string $key, int $ttlSeconds): int
    {
        if ($ttlSeconds <= 0) {
            return 0;
        }

        $bucket     = intdiv(time(), $ttlSeconds);
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
        return [
          'total_keys'     => count($this->storage),
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
            }
        }
    }
}
