<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Exceptions\ConnectionException;

function dblayerLeasePoolManager(int $maxConnections = 2): PoolManager
{
    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => $maxConnections,
    ]);
    $pool->addConfig('default', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));

    return new PoolManager($pool);
}

it('keeps a tokenized checkout exclusively owned until its lease releases it', function (): void {
    $manager = dblayerLeasePoolManager(2);
    $first = $manager->checkout();
    $second = $manager->checkout();

    expect($first->connection())->not->toBe($second->connection())
        ->and($manager->getPool()->getStats()['active_connections'])->toBe(2);

    $first->release();
    $second->release();

    expect($manager->getPool()->getStats()['idle_connections'])->toBe(2);
});

it('rejects double release of the same lease', function (): void {
    $manager = dblayerLeasePoolManager(1);
    $lease = $manager->checkout();
    $lease->release();

    expect(fn() => $lease->release())
        ->toThrow(ConnectionException::class, 'already been released');
});

it('prevents a bare release from releasing an active tokenized checkout', function (): void {
    $manager = dblayerLeasePoolManager(1);
    $lease = $manager->checkout();
    $connection = $lease->connection();

    expect(fn() => $manager->release($connection))
        ->toThrow(ConnectionException::class, 'active connection lease');

    expect($manager->getPool()->getStats()['active_connections'])->toBe(1)
        ->and($manager->getPool()->getStats()['idle_connections'])->toBe(0);

    $lease->release();
});

it('rejects stale lease tokens without returning an active connection to idle state', function (): void {
    $manager = dblayerLeasePoolManager(1);
    $lease = $manager->checkout();
    $connection = $lease->connection();

    expect(fn() => $manager->releaseLease($connection, 'default', PHP_INT_MAX))
        ->toThrow(ConnectionException::class, 'stale');

    expect($manager->getPool()->getStats()['active_connections'])->toBe(1)
        ->and($manager->getPool()->getStats()['idle_connections'])->toBe(0);

    $lease->release();
});

it('uses lease ownership for callback-scoped pooled work', function (): void {
    $manager = dblayerLeasePoolManager(1);

    $result = $manager->using('default', static fn($connection): int => (int) $connection->scalar('select 42'));

    expect($result)->toBe(42)
        ->and($manager->getPool()->getStats()['active_connections'])->toBe(0)
        ->and($manager->getPool()->getStats()['idle_connections'])->toBe(1);
});
