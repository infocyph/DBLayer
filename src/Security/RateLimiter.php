<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Rate Limiter
 *
 * Handles rate limiting for database operations.
 * Extracted from Security class for better testability.
 *
 * @package Infocyph\DBLayer\Security
 * @author Hasan
 */
class RateLimiter
{
    private array $storage = [];

    /**
     * Check rate limit
     */
    public function check(string $key, int $limit, string $period = 'minute'): void
    {
        $currentPeriod = $this->getCurrentPeriod($period);
        $storageKey = "{$key}:{$currentPeriod}";

        if (!isset($this->storage[$storageKey])) {
            $this->storage[$storageKey] = 0;
        }

        $this->storage[$storageKey]++;

        if ($this->storage[$storageKey] > $limit) {
            throw SecurityException::rateLimitExceeded($limit, $period);
        }
    }

    /**
     * Clear all rate limit data
     */
    public function clear(): void
    {
        $this->storage = [];
    }

    /**
     * Get current count for a key
     */
    public function getCount(string $key, string $period = 'minute'): int
    {
        $currentPeriod = $this->getCurrentPeriod($period);
        $storageKey = "{$key}:{$currentPeriod}";

        return $this->storage[$storageKey] ?? 0;
    }

    /**
     * Get storage statistics
     */
    public function getStats(): array
    {
        return [
            'total_keys' => count($this->storage),
            'total_requests' => array_sum($this->storage),
        ];
    }

    /**
     * Reset rate limit for a key
     */
    public function reset(string $key): void
    {
        foreach (array_keys($this->storage) as $storageKey) {
            if (str_starts_with($storageKey, $key . ':')) {
                unset($this->storage[$storageKey]);
            }
        }
    }

    /**
     * Get current period identifier
     */
    private function getCurrentPeriod(string $period): string
    {
        return match ($period) {
            'second' => date('Y-m-d H:i:s'),
            'minute' => date('Y-m-d H:i'),
            'hour' => date('Y-m-d H'),
            'day' => date('Y-m-d'),
            default => date('Y-m-d H:i'),
        };
    }
}