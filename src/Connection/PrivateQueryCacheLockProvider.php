<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use InvalidArgumentException;

/**
 * Instance-local coordination for a Connection's private ArrayCacheAdapter.
 *
 * The cache has no shared storage to coordinate through filesystem locks.
 * Contending/reentrant resolvers take CacheLayer's generation-checked fallback.
 * Caller-supplied cache backends retain their own lock providers.
 *
 * @internal
 */
final class PrivateQueryCacheLockProvider implements LockProviderInterface
{
    /** @var array<string,array{handle:LockHandle,expires:float}> */
    private array $locks = [];

    private int|false $pid;

    private int $sequence = 0;

    public function __construct()
    {
        $this->pid = getmypid();
    }

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        if ($waitSeconds < 0.0 || !is_finite($waitSeconds)) {
            throw new InvalidArgumentException('Lock wait duration must be finite and non-negative.');
        }
        $this->synchronizeProcess();
        $now = hrtime(true) / 1_000_000_000;
        $expires = $this->expiration($now, $leaseSeconds);
        if (($this->locks[$key]['expires'] ?? 0.0) > $now) {
            return null;
        }
        unset($this->locks[$key]);
        if (count($this->locks) >= 64) {
            $this->locks = array_filter($this->locks, static fn(array $lock): bool => $lock['expires'] > $now);
            if (count($this->locks) >= 64) {
                return null;
            }
        }
        $handle = new LockHandle($key, (string) ++$this->sequence, leaseSeconds: $leaseSeconds);
        $this->locks[$key] = ['handle' => $handle, 'expires' => $expires];

        return $handle;
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        $this->synchronizeProcess();
        $now = hrtime(true) / 1_000_000_000;
        $expires = $this->expiration($now, $leaseSeconds);
        if ($handle === null || ($this->locks[$handle->key]['handle'] ?? null) !== $handle
            || $this->locks[$handle->key]['expires'] <= $now) {
            return false;
        }
        $this->locks[$handle->key]['expires'] = $expires;

        return true;
    }

    public function release(?LockHandle $handle): void
    {
        $this->synchronizeProcess();
        if ($handle !== null && ($this->locks[$handle->key]['handle'] ?? null) === $handle) {
            unset($this->locks[$handle->key]);
        }
    }

    private function expiration(float $now, float $leaseSeconds): float
    {
        $expires = $now + $leaseSeconds;
        if ($leaseSeconds <= 0.0 || !is_finite($expires)) {
            throw new InvalidArgumentException('Lock lease duration must be finite and positive.');
        }

        return $expires;
    }

    private function synchronizeProcess(): void
    {
        $pid = getmypid();
        if ($this->pid !== $pid) {
            $this->locks = [];
            $this->pid = $pid;
        }
    }
}
