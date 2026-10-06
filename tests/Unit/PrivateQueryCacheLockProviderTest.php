<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\PrivateQueryCacheLockProvider;

it('coordinates one private cache instance and rejects reconstructed ownership handles', function (): void {
    $provider = new PrivateQueryCacheLockProvider();
    $other = new PrivateQueryCacheLockProvider();
    $handle = $provider->acquire('same', 0);
    expect($handle)->toBeInstanceOf(LockHandle::class)
        ->and($provider->acquire('same', 1))->toBeNull()
        ->and($other->acquire('same', 0))->toBeInstanceOf(LockHandle::class);
    $forged = new LockHandle($handle->key, $handle->token, leaseSeconds: $handle->leaseSeconds);
    expect($provider->refresh($forged, 30))->toBeFalse();
    $provider->release($forged);
    expect($provider->acquire('same', 0))->toBeNull()
        ->and($provider->refresh($handle, 30))->toBeTrue();
    $provider->release($handle);
    expect($provider->refresh($handle, 30))->toBeFalse()
        ->and($provider->acquire('same', 0))->toBeInstanceOf(LockHandle::class);
});

it('expires leases without allowing an older handle to release its replacement', function (): void {
    $provider = new PrivateQueryCacheLockProvider();
    $old = $provider->acquire('same', 0, 0.001);
    usleep(20_000);
    expect($provider->refresh($old, 30))->toBeFalse();
    $next = $provider->acquire('same', 0);
    $provider->release($old);
    expect($next)->toBeInstanceOf(LockHandle::class)
        ->and($provider->acquire('same', 0))->toBeNull()
        ->and($provider->refresh($next, 30))->toBeTrue();
});

it('bounds held coordination and reclaims expired entries at capacity', function (): void {
    $provider = new PrivateQueryCacheLockProvider();
    for ($index = 0; $index < 64; $index++) {
        expect($provider->acquire('key-' . $index, 0))->toBeInstanceOf(LockHandle::class);
    }
    expect($provider->acquire('overflow', 0))->toBeNull();
    $expiring = new PrivateQueryCacheLockProvider();
    for ($index = 0; $index < 64; $index++) {
        $expiring->acquire('key-' . $index, 0, 0.001);
    }
    usleep(20_000);
    expect($expiring->acquire('after-expiry', 0))->toBeInstanceOf(LockHandle::class);
});

it('rejects invalid private-cache lease durations', function (float $seconds): void {
    $provider = new PrivateQueryCacheLockProvider();
    expect(fn() => $provider->acquire('key', 0, $seconds))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $provider->refresh(null, $seconds))->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, INF, NAN]);

it('rejects invalid private-cache wait durations', function (float $seconds): void {
    $provider = new PrivateQueryCacheLockProvider();
    expect(fn() => $provider->acquire('key', $seconds))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, NAN]);

it('keeps private-cache generation fencing during reentrant computation', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']), 'private');
    $cache = $connection->queryCache();
    $tag = $connection->cacheTableTag('items');
    $outer = $cache->remember('query', function () use ($cache, $tag): string {
        $inner = $cache->remember('query', static fn(): string => 'inner', tags: [$tag]);
        $cache->invalidateTags([$tag]);

        return $inner;
    }, tags: [$tag]);
    expect($outer)->toBe('inner')->and($cache->get('query'))->toBeNull()
        ->and($cache->remember('query', static fn(): string => 'fresh', tags: [$tag]))->toBe('fresh')
        ->and($cache->get('query'))->toBe('fresh');
});

it('discards inherited active private-cache locks in forked children', function (): void {
    $provider = new PrivateQueryCacheLockProvider();
    $parentHandle = $provider->acquire('same', 0);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork ownership probe.');
    }
    if ($pid === 0) {
        $valid = !$provider->refresh($parentHandle, 30) && $provider->acquire('same', 0) instanceof LockHandle;
        pcntl_exec(PHP_BINARY, ['-r', $valid ? '' : 'throw new RuntimeException("Inherited cache lock remained active.");']);
        throw new RuntimeException('Unable to terminate ownership probe.');
    }
    $status = 0;
    pcntl_waitpid($pid, $status);
    expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0)
        ->and($provider->acquire('same', 0))->toBeNull()
        ->and($provider->refresh($parentHandle, 30))->toBeTrue();
});
